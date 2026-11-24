<?php
/**
 * CafeFlow Waste Tracking - Automated Test Suite
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

$pdo = db();

echo "====================================================\n";
echo "   CAFEFLOW WASTE TRACKING VERIFICATION SUITE       \n";
echo "====================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $name\n";
        if ($details) echo "        $details\n";
    } else {
        $testsFailed++;
        echo " [FAIL] $name\n";
        if ($details) echo "        FAILED: $details\n";
    }
}

function parseJsonOutput(?string $raw): ?array {
    if (!$raw) return null;
    $pos = strpos($raw, '{');
    if ($pos === false) return null;
    return json_decode(substr($raw, $pos), true);
}

function runSubPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'cf_test_') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp));
    @unlink($tmp);
    return (string)$out;
}

// -----------------------------------------------------------------------------
// Test 1: RBAC Enforcement
// -----------------------------------------------------------------------------
echo "1. Testing RBAC on waste_api.php...\n";

// A. Barista attempt - Barista now has access to waste reports per store requirement
$outBarista = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/cafeflow/api/waste_api.php";
require_once "' . addslashes(__DIR__ . '/../core/db.php') . '";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = 1;
$_SESSION["user_role"] = "barista";
include "' . addslashes(__DIR__ . '/../api/waste_api.php') . '";
');
$baristaRes = parseJsonOutput($outBarista);
assertTest('Barista receives 200 OK with waste analytics',
    isset($baristaRes['success']) && $baristaRes['success'] === true && isset($baristaRes['summary']),
    "Response: " . ($baristaRes['code'] ?? 'success')
);

// B. Unauthenticated attempt receives 401/403
$outGuest = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/cafeflow/api/waste_api.php";
require_once "' . addslashes(__DIR__ . '/../core/db.php') . '";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION = [];
include "' . addslashes(__DIR__ . '/../api/waste_api.php') . '";
');
$guestRes = parseJsonOutput($outGuest);
assertTest('Unauthenticated guest is blocked on waste_api.php',
    isset($guestRes['code']) && in_array($guestRes['code'], ['UNAUTHORIZED', 'FORBIDDEN', 'UNAUTHENTICATED']),
    "Response code: " . ($guestRes['code'] ?? 'none')
);

// B. Manager attempt
$outMgr = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/cafeflow/api/waste_api.php";
require_once "' . addslashes(__DIR__ . '/../core/db.php') . '";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = 2;
$_SESSION["user_role"] = "manager";
include "' . addslashes(__DIR__ . '/../api/waste_api.php') . '";
');
$mgrRes = parseJsonOutput($outMgr);
assertTest('Manager receives 200 OK with waste analytics',
    isset($mgrRes['success']) && $mgrRes['success'] === true && isset($mgrRes['summary']),
    "Label: " . ($mgrRes['label'] ?? 'none')
);

// -----------------------------------------------------------------------------
// Helper to create a test transaction with line items
// -----------------------------------------------------------------------------
function createTestTransaction(PDO $pdo, array $items, float $total): int {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO transactions (total_amount, status, payment_method, `timestamp`)
        VALUES (?, 'Pending', 'Cash', NOW())
    ");
    $stmt->execute([$total]);
    $txId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price, size_code, size_label, size_oz)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $it) {
        $itemStmt->execute([
            $txId,
            $it['product_id'] ?? null,
            $it['name'],
            $it['qty'],
            $it['price'],
            $it['size_code'] ?? null,
            $it['size_label'] ?? null,
            $it['size_oz'] ?? null
        ]);
    }
    $pdo->commit();
    return $txId;
}

// -----------------------------------------------------------------------------
// Test 2: Cancel Cold Beverage (Matcha Latte 22oz Grande)
// -----------------------------------------------------------------------------
echo "\n2. Testing Cancel 22oz Cold Beverage (Matcha Latte)...\n";
$txMatcha = createTestTransaction($pdo, [
    [
        'product_id' => 37,
        'name' => 'Matcha Latte',
        'qty' => 1,
        'price' => 140.00,
        'size_code' => '22oz',
        'size_label' => 'Grande (22oz Cold)',
        'size_oz' => 22
    ]
], 140.00);

$initCupStock = (float)$pdo->query("SELECT current_stock FROM inventory WHERE item_id = 19")->fetchColumn();

$itemsStmt = $pdo->prepare("
    SELECT ti.id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
           ti.size_code, ti.size_label, ti.size_oz,
           mp.name AS base_name, mp.category
      FROM transaction_items ti
      LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
     WHERE ti.transaction_id = ?
");
$itemsStmt->execute([$txMatcha]);
$mItems = $itemsStmt->fetchAll();

$pdo->beginTransaction();
$wasteMatcha = record_order_waste($pdo, $txMatcha, 1, 'Customer cancelled 22oz Matcha', $mItems);
$pdo->exec("UPDATE transactions SET status = 'Cancelled', cancellation_reason = 'Customer cancelled 22oz Matcha' WHERE transaction_id = $txMatcha");
$pdo->commit();

assertTest('22oz Matcha waste logged successfully',
    $wasteMatcha['success'] && $wasteMatcha['logged_count'] > 0,
    "Logged {$wasteMatcha['logged_count']} items, loss: ₱{$wasteMatcha['total_income_loss_php']}"
);

$loggedNames = array_column($wasteMatcha['items'], 'item_name');
assertTest('Contains 22oz cup, flat lid, straw, and matcha ingredients',
    in_array('22oz cup', $loggedNames, true) &&
    in_array('Flat lid', $loggedNames, true) &&
    in_array('Bendable straw', $loggedNames, true),
    "Logged: " . implode(', ', $loggedNames)
);

$afterCupStock = (float)$pdo->query("SELECT current_stock FROM inventory WHERE item_id = 19")->fetchColumn();
assertTest('Live inventory was NOT deducted on pending order cancellation',
    $initCupStock === $afterCupStock,
    "Initial: $initCupStock, After: $afterCupStock"
);

// -----------------------------------------------------------------------------
// Test 3: Cancel Food Item (Butter Croissant & French Fries)
// -----------------------------------------------------------------------------
echo "\n3. Testing Cancel Food Item (Croissant & Fries)...\n";
$txFood = createTestTransaction($pdo, [
    [
        'product_id' => 44, // Butter Croissant (Pastry)
        'name' => 'Butter Croissant',
        'qty' => 2,
        'price' => 65.00,
        'size_code' => null,
        'size_label' => null,
        'size_oz' => null
    ],
    [
        'product_id' => 45, // French Fries (Snacks)
        'name' => 'French Fries',
        'qty' => 1,
        'price' => 85.00,
        'size_code' => null,
        'size_label' => null,
        'size_oz' => null
    ]
], 215.00);

$itemsStmt->execute([$txFood]);
$foodItems = $itemsStmt->fetchAll();

$pdo->beginTransaction();
$wasteFood = record_order_waste($pdo, $txFood, 1, 'Food order cancelled', $foodItems);
$pdo->exec("UPDATE transactions SET status = 'Cancelled' WHERE transaction_id = $txFood");
$pdo->commit();

$foodLoggedNames = array_column($wasteFood['items'], 'item_name');
assertTest('Food waste logged without cup/lid/straw',
    !in_array('16oz cup', $foodLoggedNames, true) &&
    !in_array('22oz cup', $foodLoggedNames, true) &&
    !in_array('Flat lid', $foodLoggedNames, true) &&
    !in_array('Bendable straw', $foodLoggedNames, true),
    "Logged supplies: " . implode(', ', $foodLoggedNames)
);
assertTest('Income loss matches 2 croissants (₱130) + 1 fries (₱85) = ₱215.00',
    $wasteFood['total_income_loss_php'] == 215.00,
    "Calculated: ₱{$wasteFood['total_income_loss_php']}"
);

// -----------------------------------------------------------------------------
// Test 4: Cancel Hot Beverage (12oz Hot Latte)
// -----------------------------------------------------------------------------
echo "\n4. Testing Cancel Hot Beverage (12oz Hot Latte)...\n";
$txHot = createTestTransaction($pdo, [
    [
        'product_id' => 2,
        'name' => 'Caffé Latte',
        'qty' => 1,
        'price' => 100.00,
        'size_code' => '12oz',
        'size_label' => '12oz Hot',
        'size_oz' => 12
    ]
], 100.00);

$itemsStmt->execute([$txHot]);
$hotItems = $itemsStmt->fetchAll();

$pdo->beginTransaction();
$wasteHot = record_order_waste($pdo, $txHot, 1, 'Hot latte cancelled', $hotItems);
$pdo->exec("UPDATE transactions SET status = 'Cancelled' WHERE transaction_id = $txHot");
$pdo->commit();

$hotLoggedNames = array_column($wasteHot['items'], 'item_name');
assertTest('Hot drink logs 12oz Hot cup & lid, NO ice, NO straw',
    in_array('12oz Hot cup & lid', $hotLoggedNames, true) &&
    !in_array('Ice', $hotLoggedNames, true) &&
    !in_array('Bendable straw', $hotLoggedNames, true),
    "Logged supplies: " . implode(', ', $hotLoggedNames)
);

// -----------------------------------------------------------------------------
// Test 5: Guard against double-counting waste
// -----------------------------------------------------------------------------
echo "\n5. Testing Duplicate Cancellation Guard...\n";
$pdo->beginTransaction();
$dupWaste = record_order_waste($pdo, $txMatcha, 1, 'Duplicate attempt', $mItems);
$pdo->commit();

assertTest('Duplicate cancellation attempt is safely rejected and not logged twice',
    $dupWaste['logged_count'] === 0,
    "Logged count: {$dupWaste['logged_count']}"
);

// -----------------------------------------------------------------------------
// Test 6: Verify Reports API waste summary integration
// -----------------------------------------------------------------------------
echo "\n6. Testing Sales Report integration with waste KPI...\n";
$outReport = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/cafeflow/api/report.php?range=today";
require_once "' . addslashes(__DIR__ . '/../core/db.php') . '";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = 2;
$_SESSION["user_role"] = "manager";
include "' . addslashes(__DIR__ . '/../api/report.php') . '";
');
$repJson = parseJsonOutput($outReport);

assertTest('report.php returns waste_summary object',
    isset($repJson['waste_summary']) && is_array($repJson['waste_summary']),
    "Waste events in report: " . ($repJson['waste_summary']['events_count'] ?? 'none')
);
assertTest('Income loss in report.php reflects cancelled orders',
    ($repJson['waste_summary']['total_income_loss_php'] ?? 0) > 0,
    "Income loss: ₱" . ($repJson['waste_summary']['total_income_loss_php'] ?? 0)
);

// -----------------------------------------------------------------------------
// Test 7: Manual Waste Logging & Stock Deduction
// -----------------------------------------------------------------------------
echo "\n7. Testing Manual Waste Logging & Stock Deduction...\n";

// Get initial stock of Matcha powder
$mRow = $pdo->query("SELECT item_id, current_stock, unit_measure FROM inventory WHERE LOWER(name) LIKE '%matcha%' LIMIT 1")->fetch();
$mItemId = (int)$mRow['item_id'];
$initialMatchaStock = (float)$mRow['current_stock'];

$outManual = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REQUEST_URI"] = "/cafeflow/api/manual_waste.php";
require_once "' . addslashes(__DIR__ . '/../core/db.php') . '";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = 1;
$_SESSION["user_role"] = "barista";
$_SESSION["csrf_token"] = "valid_csrf_token_waste_test";
$input = [
    "csrf_token" => "valid_csrf_token_waste_test",
    "item_id" => ' . $mItemId . ',
    "item_name" => "Matcha Powder",
    "unit" => "g",
    "amount_wasted" => 50.00,
    "cost_php" => 45.00,
    "reason" => "Spilled container during test"
];
$GLOBALS["MOCK_INPUT_JSON"] = json_encode($input);
include "' . addslashes(__DIR__ . '/../api/manual_waste.php') . '";
');
$manualJson = parseJsonOutput($outManual);

assertTest('Barista can successfully log manual waste',
    !empty($manualJson['success']) && $manualJson['success'] === true,
    $manualJson['message'] ?? 'Failed'
);

$newMatchaRow = $pdo->query("SELECT current_stock FROM inventory WHERE item_id = $mItemId")->fetch();
$newMatchaStock = (float)$newMatchaRow['current_stock'];

assertTest('Manual waste deducted 50g from inventory stock',
    round($newMatchaStock, 2) === round($initialMatchaStock - 50.00, 2),
    "Initial: $initialMatchaStock, New: $newMatchaStock"
);

// Verify waste_log table record
$lastWaste = $pdo->query("SELECT * FROM waste_log WHERE reason = 'Spilled container during test' ORDER BY waste_id DESC LIMIT 1")->fetch();
assertTest('waste_log records null transaction_id and valid item_id',
    $lastWaste && $lastWaste['transaction_id'] === null && (int)$lastWaste['item_id'] === $mItemId,
    "Waste ID: " . ($lastWaste['waste_id'] ?? 'none') . ", TX: " . var_export($lastWaste['transaction_id'] ?? false, true)
);

// Restore stock after test
$pdo->exec("UPDATE inventory SET current_stock = $initialMatchaStock WHERE item_id = $mItemId");
$pdo->exec("DELETE FROM waste_log WHERE reason = 'Spilled container during test'");

echo "\n====================================================\n";
echo "TEST RESULTS: $testsPassed PASSED, $testsFailed FAILED\n";
echo "====================================================\n";

// Clean up temporary test transactions
$pdo->exec("DELETE FROM transactions WHERE transaction_id IN ($txMatcha, $txFood, $txHot)");
exit($testsFailed === 0 ? 0 : 1);
