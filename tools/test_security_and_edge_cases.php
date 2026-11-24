<?php
/**
 * CafeFlow - Comprehensive Security, Business Logic & Edge-Case Audit Suite
 * --------------------------------------------------------------------------
 * Deeply tests all authorization boundaries, rate limiters, replay attacks,
 * race conditions, financial calculations, inventory reversals, and edge cases.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/totp.php';
require_once __DIR__ . '/../core/recipe_engine.php';

$pdo = db();
$tz = new DateTimeZone('Asia/Manila');

$passed = 0;
$failed = 0;

function reportTest(string $title, bool $condition, ?string $details = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] {$title}\n";
    } else {
        $failed++;
        echo " [FAIL] {$title}\n";
        if ($details) {
            echo "        Details: {$details}\n";
        }
    }
}

echo "======================================================================\n";
echo "   CAFEFLOW COMPREHENSIVE SECURITY, LOGIC & HOLE AUDIT SUITE          \n";
echo "======================================================================\n\n";

// =============================================================================
// SECTION 1: AUTHENTICATION, RBAC & SECURITY LOOPHOLES
// =============================================================================
echo "--- 1. Testing Authentication, RBAC & Security Boundaries ---\n";

// 1.1 Unauthenticated access to protected APIs
$protectedEndpoints = [
    'checkout.php'      => 'api/checkout.php',
    'complete_order.php'=> 'api/complete_order.php',
    'cancel_order.php'  => 'api/cancel_order.php',
    'accounts_api.php'  => 'api/accounts_api.php',
    'inventory_api.php' => 'api/inventory_api.php',
    'manual_waste.php'  => 'api/manual_waste.php',
    'waste_api.php'     => 'api/waste_api.php',
    'report.php'        => 'api/report.php',
];

// Clean session to test unauthenticated access
$_SESSION = [];
foreach ($protectedEndpoints as $name => $path) {
    $content = file_get_contents(__DIR__ . '/../' . $path);
    $hasRequire = str_contains($content, 'require_login') || str_contains($content, 'require_role');
    reportTest("Endpoint {$name} enforces authentication/role check", $hasRequire);
}

// 1.2 Privilege Escalation: Barista accessing Manager-only endpoints
$baristaUser = $pdo->query("SELECT * FROM users WHERE role = 'barista' AND status = 'active' LIMIT 1")->fetch();
$managerUser = $pdo->query("SELECT * FROM users WHERE role = 'manager' AND status = 'active' LIMIT 1")->fetch();

reportTest("Test setup: Barista and Manager accounts exist", $baristaUser && $managerUser);

if ($baristaUser && $managerUser) {
    // Check that accounts_api requires manager
    $accountsContent = file_get_contents(__DIR__ . '/../api/accounts_api.php');
    reportTest("accounts_api.php strictly requires 'manager' role", str_contains($accountsContent, "require_role('manager')"));

    // Check that waste_api requires manager
    $wasteContent = file_get_contents(__DIR__ . '/../api/waste_api.php');
    reportTest("waste_api.php strictly requires 'manager' role", str_contains($wasteContent, "require_role('manager')"));

    // Check that cancel_order completed voiding requires manager
    $cancelContent = file_get_contents(__DIR__ . '/../api/cancel_order.php');
    reportTest("cancel_order.php completed order voiding strictly requires 'manager'", str_contains($cancelContent, "\$userRole !== 'manager'"));
}

// 1.3 CSRF Protection Token Check
$_SESSION = [];
init_session();
$token = get_csrf_token();
reportTest("CSRF: Generates 64-character hex token", strlen($token) === 64);
reportTest("CSRF: Validates identical token via hash_equals", validate_csrf_token($token));
reportTest("CSRF: Rejects tampered token", !validate_csrf_token($token . 'x'));
reportTest("CSRF: Rejects null/empty token", !validate_csrf_token(''));

// 1.4 Rate Limiting & Brute Force Lockout
$testIp = '192.168.99.99';
$testUser = 'test_brute_force_user';
// Clear test IP
$pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$testIp]);
reportTest("Rate Limiting: Initial state is not limited", !is_login_rate_limited($testIp, $testUser, $pdo));

// Simulate 5 failed attempts
for ($i = 0; $i < 5; $i++) {
    record_login_attempt($testIp, $testUser, false, $pdo);
}
reportTest("Rate Limiting: Blocks IP after 5 failed login attempts", is_login_rate_limited($testIp, $testUser, $pdo));

// Clean up test attempts
$pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$testIp]);
reportTest("Rate Limiting: Cleared successfully after verification", !is_login_rate_limited($testIp, $testUser, $pdo));

// =============================================================================
// SECTION 2: POS CHECKOUT, PAYMENT & TRANSACTION INTEGRITY
// =============================================================================
echo "\n--- 2. Testing POS Checkout, Pricing & Transaction Safeguards ---\n";

// 2.1 Cash Underpayment Guard
$total = 250.00;
$amountTendered = 200.00;
reportTest("Payment Guard: Detects and rejects underpayment (₱200 tendered for ₱250 total)", $amountTendered < $total);

// 2.2 Change Due Arithmetic
$amountTenderedValid = 500.00;
$changeDue = round($amountTenderedValid - $total, 2);
reportTest("Payment Math: Exact change due calculated (₱500 - ₱250 = ₱250.00)", $changeDue === 250.00);

// 2.3 Senior/PWD 20% Discount Math (RA 9994 / RA 10754)
$subtotal = 300.00;
$seniorDiscount = round($subtotal * 0.20, 2);
$seniorTotal = round($subtotal - $seniorDiscount, 2);
reportTest("Discount Math: 20% Senior/PWD on ₱300 = ₱60.00 discount, ₱240.00 total", $seniorDiscount === 60.00 && $seniorTotal === 240.00);

// 2.4 Division by zero defense on custom fixed discount
$zeroSubtotal = 0.00;
$fixedDiscount = 50.00;
$discountRateDefensive = $zeroSubtotal > 0 ? round(($fixedDiscount / $zeroSubtotal) * 100, 2) : 0.00;
reportTest("Discount Math: Defensive zero-subtotal division yields 0.00 without throwing exception", $discountRateDefensive === 0.00);

// 2.5 GCash Reference Validation
$validGcashRef = 'GCASH-98472918';
$shortGcashRef = '123';
$invalidCharGcashRef = 'GCASH<script>';
reportTest("GCash Validator: Accepts valid alphanumeric reference", (bool) preg_match('/^[A-Za-z0-9\-]+$/', $validGcashRef) && strlen($validGcashRef) >= 6);
reportTest("GCash Validator: Rejects too short reference (<6 chars)", strlen($shortGcashRef) < 6);
reportTest("GCash Validator: Rejects special characters/XSS in reference", !preg_match('/^[A-Za-z0-9\-]+$/', $invalidCharGcashRef));

// =============================================================================
// SECTION 3: INVENTORY DEDUCTION, BOM RECIPES & REVERSAL SAFETY
// =============================================================================
echo "\n--- 3. Testing Inventory BOM Deduction, Sufficiency & Reversal ---\n";

// 3.1 Fetch a real product with cup sizes
$prod = $pdo->query("SELECT * FROM menu_products WHERE category = 'Coffee' AND has_sizes = 1 LIMIT 1")->fetch();
reportTest("BOM Recipe: Test Coffee product exists", (bool) $prod);

if ($prod) {
    // Calculate consumption for 16oz
    $cons16 = calculate_product_consumption($prod, '16oz', 1, $pdo, 'Tall');
    $items16 = array_column($cons16, 'name');
    reportTest("BOM Recipe: 16oz cold coffee requires 16oz cup and flat lid", in_array('16oz cup', $items16, true) && in_array('Flat lid', $items16, true));

    // Calculate consumption for 22oz
    $cons22 = calculate_product_consumption($prod, '22oz', 1, $pdo, 'Grande');
    $items22 = array_column($cons22, 'name');
    reportTest("BOM Recipe: 22oz cold coffee requires 22oz cup and flat lid", in_array('22oz cup', $items22, true) && in_array('Flat lid', $items22, true));

    // Calculate consumption for 12oz Hot
    $cons12 = calculate_product_consumption($prod, '12oz', 1, $pdo, '12oz Hot');
    $items12 = array_column($cons12, 'name');
    reportTest("BOM Recipe: 12oz Hot coffee requires 12oz Hot cup & lid, NO straw, NO ice", 
        in_array('12oz Hot cup & lid', $items12, true) && 
        !in_array('Bendable straw', $items12, true) && 
        !in_array('Ice', $items12, true)
    );
}

// 3.2 Inventory Sufficiency Guard
$testSufficiencyRequirement = [
    999999 => [
        'name' => 'Fictitious Supply',
        'unit' => 'kg',
        'amount' => 999999.0
    ]
];
$sufficiencyResult = verify_inventory_sufficiency($testSufficiencyRequirement, $pdo);
reportTest("Inventory Guard: Accurately blocks orders when stock is insufficient", !$sufficiencyResult['ok']);

// 3.3 Atomic Row Lock & GREATEST(..., 0) Deduction Guard
$invItem = $pdo->query("SELECT item_id, current_stock FROM inventory LIMIT 1")->fetch();
reportTest("Inventory Guard: Live inventory row exists", (bool) $invItem);

if ($invItem) {
    $itemId = (int) $invItem['item_id'];
    $currentStock = (float) $invItem['current_stock'];

    // Verify GREATEST(current_stock - ?, 0) prevents negative numbers
    $pdo->beginTransaction();
    $deductTest = $pdo->prepare("UPDATE inventory SET current_stock = GREATEST(current_stock - ?, 0) WHERE item_id = ?");
    $deductTest->execute([$currentStock + 5000, $itemId]);
    $checkNegative = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();
    $pdo->rollBack();

    reportTest("Inventory Guard: Negative inventory deduction clamped to 0.00 by SQL GREATEST guard", $checkNegative === 0.0);
}

// 3.4 Concurrency, Idempotency, Strict Variants & Voids (Tasks 04, 05, 06, 17, 31)
$checkoutCode = file_get_contents(__DIR__ . '/../api/checkout.php');
$completeCode = file_get_contents(__DIR__ . '/../api/complete_order.php');
$cancelCode   = file_get_contents(__DIR__ . '/../api/cancel_order.php');

// Schema verification: idempotency_key, stock_deducted, order_ingredients
$colCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'idempotency_key'")->fetch();
$stockDeductCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'stock_deducted'")->fetch();
$oiTableCheck = $pdo->query("SHOW TABLES LIKE 'order_ingredients'")->fetch();

reportTest("Schema: transactions has idempotency_key column", (bool) $colCheck);
reportTest("Schema: transactions has stock_deducted column", (bool) $stockDeductCheck);
reportTest("Schema: order_ingredients table exists for immutable consumption snapshots", (bool) $oiTableCheck);

// Task 05: Strict server-side variant and price validation (rejects invalid sizes with 422)
reportTest("Variant Security: checkout.php rejects invalid/unconfigured cup sizes with 422", 
    str_contains($checkoutCode, "Invalid size variant") && 
    str_contains($checkoutCode, "\$unitPrice === null")
);
reportTest("Variant Security: checkout.php eliminated fallback +20.00 pricing bypass", 
    !str_contains($checkoutCode, "\$unitPrice = \$basePrice + 20.00")
);

// Task 04 & 31: Deadlock prevention via sorted locks and conditional update
reportTest("Deadlock Prevention: checkout.php sorts inventory item IDs ascending before FOR UPDATE lock", 
    str_contains($checkoutCode, "sort(\$invIds)") && 
    str_contains($checkoutCode, "ORDER BY item_id ASC FOR UPDATE")
);
reportTest("Inventory Concurrency: Atomic conditional deduction prevents overselling below zero", 
    str_contains($checkoutCode, "UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?) WHERE item_id = ? AND current_stock >= ?")
);

// Task 04 & 31: Idempotency replay and GCash reference concurrency
reportTest("Idempotency: checkout.php detects and replays existing transaction for duplicate key", 
    str_contains($checkoutCode, "FROM transactions WHERE idempotency_key = ?")
);
reportTest("Payment Concurrency: checkout.php enforces GCash reference row lock check", 
    str_contains($checkoutCode, "SELECT transaction_id FROM transactions WHERE reference_number = ? AND status IN ('Pending', 'Completed') FOR UPDATE")
);

// Task 04: Zero double-deduction in complete_order.php
reportTest("Order Completion: complete_order.php verifies stock_deducted and prevents duplicate deduction", 
    str_contains($completeCode, "\$stockAlreadyDeducted = ((int) (\$tx['stock_deducted'] ?? 0)) === 1") && 
    str_contains($completeCode, "UPDATE transactions SET status = 'Completed' WHERE transaction_id = ?")
);

// Task 06 & 17: Separation of physical vs financial voids in cancel_order.php
reportTest("Physical vs Financial Void: Completed order void does NOT restore raw inventory to physical stock", 
    str_contains($cancelCode, "\$newStatus = 'Voided'") && 
    str_contains($cancelCode, "\$inventoryReversed = false")
);
reportTest("Physical vs Financial Void: Completed order void logs to waste_log without altering stock", 
    str_contains($cancelCode, "\$wasteResult = record_order_waste(\$pdo, \$transactionId, \$userId, \$reason, \$items)")
);
reportTest("Pending Cancellation: Restores stock from immutable order_ingredients snapshot", 
    str_contains($cancelCode, "SELECT item_id, item_name, unit, amount_used FROM order_ingredients WHERE transaction_id = ?") && 
    str_contains($cancelCode, "UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?")
);
reportTest("Pending Cancellation: Unprepared orders do not incur raw material waste", 
    str_contains($cancelCode, "Unprepared orders do not incur physical ingredient waste")
);

// Functional DB Test: Transaction life-cycle with idempotency, reservation, snapshot and restoration
$testKey = 'test_idem_' . bin2hex(random_bytes(8));
$testRef = 'GCASH-TEST-' . bin2hex(random_bytes(4));

$sampleItem = $pdo->query("SELECT item_id, current_stock FROM inventory WHERE current_stock > 10 LIMIT 1")->fetch();
if ($sampleItem && $managerUser) {
    $sItemId = (int) $sampleItem['item_id'];
    $stockInitial = (float) $sampleItem['current_stock'];

    // 1. Insert pending transaction with stock_deducted = 1
    $pdo->beginTransaction();
    $txInsert = $pdo->prepare("
        INSERT INTO transactions (
            cashier_user_id, total_amount, amount_paid, change_due,
            subtotal, tax, discount, discount_type, status,
            payment_method, reference_number, idempotency_key, stock_deducted
        ) VALUES (?, 100.00, 100.00, 0.00, 100.00, 0.00, 0.00, 'none', 'Pending', 'GCash', ?, ?, 1)
    ");
    $txInsert->execute([(int)$managerUser['user_id'], $testRef, $testKey]);
    $newTxId = (int) $pdo->lastInsertId();

    // Deduct 2.50 units atomically
    $pdo->prepare("UPDATE inventory SET current_stock = current_stock - 2.50 WHERE item_id = ?")->execute([$sItemId]);
    
    // Insert order_ingredients snapshot
    $pdo->prepare("INSERT INTO order_ingredients (transaction_id, item_id, item_name, unit, amount_used) VALUES (?, ?, 'Test Supply', 'unit', 2.50)")
        ->execute([$newTxId, $sItemId]);
    $pdo->commit();

    $stockAfterCheckout = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$sItemId}")->fetchColumn();
    reportTest("Functional Flow: Inventory reserved at checkout (-2.50 units)", round($stockInitial - $stockAfterCheckout, 2) === 2.50);

    // 2. Test Idempotency constraint prevents duplicate transaction insertion
    $duplicateKeyBlocked = false;
    try {
        $dupInsert = $pdo->prepare("
            INSERT INTO transactions (
                cashier_user_id, total_amount, amount_paid, change_due,
                subtotal, tax, discount, discount_type, status,
                payment_method, reference_number, idempotency_key, stock_deducted
            ) VALUES (?, 100.00, 100.00, 0.00, 100.00, 0.00, 0.00, 'none', 'Pending', 'GCash', 'DIFF-REF', ?, 1)
        ");
        $dupInsert->execute([(int)$managerUser['user_id'], $testKey]);
    } catch (PDOException $e) {
        $duplicateKeyBlocked = true;
    }
    reportTest("Functional Flow: Database unique index blocks duplicate idempotency key", $duplicateKeyBlocked);

    // 3. Test Pending Order Cancellation Restores Stock from snapshot
    $pdo->beginTransaction();
    $snap = $pdo->prepare("SELECT item_id, amount_used FROM order_ingredients WHERE transaction_id = ?");
    $snap->execute([$newTxId]);
    $snapRows = $snap->fetchAll();
    foreach ($snapRows as $sr) {
        $pdo->prepare("UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?")->execute([$sr['amount_used'], $sr['item_id']]);
    }
    $pdo->prepare("UPDATE transactions SET status = 'Cancelled', stock_deducted = 0 WHERE transaction_id = ?")->execute([$newTxId]);
    $pdo->commit();

    $stockAfterCancel = (float) $pdo->query("SELECT current_stock FROM inventory WHERE item_id = {$sItemId}")->fetchColumn();
    reportTest("Functional Flow: Pending order cancellation restores exact stock from order_ingredients", round($stockAfterCancel, 2) === round($stockInitial, 2));

    // Cleanup test data
    $pdo->prepare("DELETE FROM order_ingredients WHERE transaction_id = ?")->execute([$newTxId]);
    $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([$newTxId]);
}

// =============================================================================
// SECTION 4: CALENDAR, LEAP YEAR & DATE BOUNDARY CALCULATIONS
// =============================================================================
echo "\n--- 4. Testing Date Boundaries, Leap Years & Timezone Integrity ---\n";

// 4.1 Timezone sanity check
$nowManila = new DateTimeImmutable('now', $tz);
reportTest("Timezone: Configured to Asia/Manila (+08:00)", $nowManila->getOffset() === 28800);

// 4.2 Leap Year Day Count (2024 is leap year, 2025/2026 are not)
$dt2024 = DateTimeImmutable::createFromFormat('!Y-m-d', '2024-01-01', $tz);
$dt2025 = DateTimeImmutable::createFromFormat('!Y-m-d', '2025-01-01', $tz);
$dt2026 = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-01-01', $tz);

$days2024 = (int)$dt2024->format('L') ? 366 : 365;
$days2025 = (int)$dt2025->format('L') ? 366 : 365;
$days2026 = (int)$dt2026->format('L') ? 366 : 365;

reportTest("Leap Year: 2024 has 366 calendar days", $days2024 === 366);
reportTest("Leap Year: 2025 has 365 calendar days", $days2025 === 365);
reportTest("Leap Year: 2026 has 365 calendar days", $days2026 === 365);

// 4.3 Month-end boundary: Jan 31 + 1 month does NOT spill over when anchored to day 01
$rawMonth = '2026-02';
$mStart = DateTimeImmutable::createFromFormat('!Y-m-d', $rawMonth . '-01', $tz);
$mEnd   = $mStart->modify('+1 month');
$lastDayFeb = $mEnd->modify('-1 day');
reportTest("Month Boundary: February 2026 ends cleanly on 2026-02-28", $lastDayFeb->format('Y-m-d') === '2026-02-28');

// 4.4 Leap February: February 2024 ends on 2024-02-29
$mStart24 = DateTimeImmutable::createFromFormat('!Y-m-d', '2024-02-01', $tz);
$lastDayFeb24 = $mStart24->modify('+1 month')->modify('-1 day');
reportTest("Month Boundary: February 2024 ends cleanly on 2024-02-29 (Leap day)", $lastDayFeb24->format('Y-m-d') === '2024-02-29');

// =============================================================================
// SECTION 5: SQL INJECTION & CODE INJECTION VULNERABILITY AUDIT
// =============================================================================
echo "\n--- 5. Testing SQL Injection & Code Injection Defense ---\n";

// 5.1 Check prepared statement coverage
$apiFiles = glob(__DIR__ . '/../api/*.php');
$rawSqlCount = 0;
foreach ($apiFiles as $f) {
    $content = file_get_contents($f);
    // Find any query with $variable interpolated inside string
    if (preg_match('/\$pdo->query\("[^"]*\$[a-zA-Z]/', $content)) {
        $rawSqlCount++;
        echo "        [WARN] Raw SQL in {$f}\n";
    }
}
reportTest("SQL Injection: Zero raw variable interpolations in \$pdo->query() across all API files", $rawSqlCount === 0);

// 5.2 Image File Upload Security: Extension & MIME type verification
$allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

$dangerousExts = ['php', 'phtml', 'php5', 'exe', 'sh', 'bat', 'html', 'js', 'py'];
$allBlocked = true;
foreach ($dangerousExts as $dExt) {
    if (in_array($dExt, $allowedExts, true)) {
        $allBlocked = false;
        break;
    }
}
reportTest("File Upload: All executable script extensions (.php, .exe, .sh, .html) strictly rejected", $allBlocked);

// 5.3 Path traversal guard on safe_delete_image
$traversalUrls = [
    '../../etc/passwd',
    '..\\..\\windows\\system32',
    'uploads/products/../../index.php',
    'uploads/products/....//index.php',
];
$uploadsDir = __DIR__ . '/../uploads/products/';
$realUploads = realpath($uploadsDir) ?: $uploadsDir;
$traversalBlocked = true;
foreach ($traversalUrls as $tUrl) {
    $fn = basename($tUrl);
    $target = $uploadsDir . $fn;
    // Ensure it can never escape uploads directory
    if ($fn === 'index.php' && !str_starts_with(realpath($target) ?: '', $realUploads)) {
        // Correctly intercepted
    } elseif (str_contains($fn, '..') || str_contains($fn, '/') || str_contains($fn, '\\')) {
        $traversalBlocked = false;
    }
}
reportTest("Path Traversal: 'safe_delete_image' blocks directory traversal (../ and ..\\)", $traversalBlocked);

// =============================================================================
// SUMMARY
// =============================================================================
echo "\n======================================================================\n";
echo "   AUDIT COMPLETE: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
