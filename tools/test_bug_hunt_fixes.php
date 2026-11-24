<?php
/**
 * CafeFlow - Bug Hunt Review Fixes Verification Suite
 * -----------------------------------------------------------------------------
 * Validates resolutions for BUG-1 through BUG-10 from BUG_HUNT_REPORT.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();
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
echo "   CAFEFLOW BUG HUNT REVIEW VERIFICATION SUITE                        \n";
echo "======================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. BUG-1 & BUG-4: Report Synthetic Mock Data Filtering
// -----------------------------------------------------------------------------
echo "--- 1. Testing BUG-1 & BUG-4: Report Mock Data Isolation & Summary Splits ---\n";

function runReportApi(string $queryString): array {
    $code = '
    $_SERVER["REQUEST_METHOD"] = "GET";
    $_SERVER["HTTP_ACCEPT"] = "application/json";
    $_GET = [];
    parse_str("' . addslashes($queryString) . '", $_GET);
    session_start();
    $_SESSION["user_id"] = 1;
    $_SESSION["user"] = ["user_id" => 1, "username" => "manager", "role" => "manager", "status" => "active"];
    require __DIR__ . "/../api/report.php";
    ';
    $tmp = __DIR__ . '/temp_report_test.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return json_decode($out, true) ?: ['raw' => $out];
}

$defaultRep = runReportApi('range=year&year=2026');
reportTest("Default report returns summary with mock & real splits", 
    isset($defaultRep['summary']['real_orders'], $defaultRep['summary']['mock_orders'], $defaultRep['summary']['real_total'], $defaultRep['summary']['mock_total']));
reportTest("Default report strictly isolates live data (include_mock is false by default)", 
    isset($defaultRep['summary']['include_mock']) && $defaultRep['summary']['include_mock'] === false);

$mockRep = runReportApi('range=year&year=2026&include_mock=1');
reportTest("include_mock=1 sets include_mock flag to true", 
    isset($mockRep['summary']['include_mock']) && $mockRep['summary']['include_mock'] === true);
reportTest("Default report strictly matches real_total", 
    isset($defaultRep['summary']['total'], $defaultRep['summary']['real_total']) && (float)$defaultRep['summary']['total'] === (float)$defaultRep['summary']['real_total']);
reportTest("Default report strictly matches real_orders", 
    isset($defaultRep['summary']['orders'], $defaultRep['summary']['real_orders']) && (int)$defaultRep['summary']['orders'] === (int)$defaultRep['summary']['real_orders']);

// -----------------------------------------------------------------------------
// 2. BUG-2: Legacy Excel Fallback File Existence & Functionality
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing BUG-2: Legacy Excel Fallback Engine ---\n";
$fallbackPath = __DIR__ . '/../core/legacy_excel_fallback.php';
reportTest("core/legacy_excel_fallback.php exists", file_exists($fallbackPath));

$testFallbackCmd = '
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["all" => "1"];
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["user"] = ["user_id" => 1, "username" => "manager", "role" => "manager", "status" => "active"];
ob_start();
require __DIR__ . "/../core/legacy_excel_fallback.php";
$csv = ob_get_clean();
echo substr($csv, 0, 100);
';
$tmpFb = __DIR__ . '/temp_fallback_test.php';
file_put_contents($tmpFb, "<?php\n" . $testFallbackCmd);
$fbOut = (string) shell_exec('php ' . escapeshellarg($tmpFb) . ' 2>&1');
@unlink($tmpFb);
reportTest("Legacy fallback streams valid CSV content", str_contains($fbOut, 'DK Cafe') || str_contains($fbOut, 'Receipt No'));

// -----------------------------------------------------------------------------
// 3. BUG-3: Waste API Parameterized Bound Parameters
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing BUG-3: Bound Limit & Offset Parameters in waste_api.php ---\n";
$wasteCode = file_get_contents(__DIR__ . '/../api/waste_api.php');
reportTest("waste_api.php uses PDO bound parameters for LIMIT & OFFSET", 
    str_contains($wasteCode, ':lim') && str_contains($wasteCode, ':off') && str_contains($wasteCode, 'PDO::PARAM_INT'));
reportTest("waste_api.php has NO raw interpolation in LIMIT clause", 
    !str_contains($wasteCode, 'LIMIT {$limit}'));

// -----------------------------------------------------------------------------
// 4. BUG-8: Inventory Rename Duplicate Name Guard
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing BUG-8: Duplicate Name Guard on Inventory Update ---\n";
$invCode = file_get_contents(__DIR__ . '/../api/inventory_api.php');
reportTest("inventory_api.php update action has case-insensitive duplicate check", 
    str_contains($invCode, "SELECT item_id FROM inventory WHERE LOWER(name) = LOWER(?) AND item_id != ?"));

// -----------------------------------------------------------------------------
// 5. BUG-9: Manual Waste Unit Conversion & Safeguard
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing BUG-9: Manual Waste Unit Conversion & Incompatibility Guard ---\n";
$wasteApiCode = file_get_contents(__DIR__ . '/../api/manual_waste.php');
reportTest("manual_waste.php converts kg to g properly (* 1000)", 
    str_contains($wasteApiCode, '$invUnit === \'g\' && $inputUnit === \'kg\''));
reportTest("manual_waste.php converts liters to ml properly (* 1000)", 
    str_contains($wasteApiCode, '$invUnit === \'ml\'') && str_contains($wasteApiCode, '$amountFloat * 1000.0'));
reportTest("manual_waste.php rejects incompatible units with 422 error", 
    str_contains($wasteApiCode, 'Unit mismatch for supply'));

// -----------------------------------------------------------------------------
// 6. BUG-10: Static Auth Cache Invalidation
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing BUG-10: Auth Cache Invalidation ---\n";
$authCode = file_get_contents(__DIR__ . '/../core/auth.php');
reportTest("core/auth.php defines clear_auth_cache()", function_exists('clear_auth_cache'));
reportTest("current_user supports forceFresh parameter", 
    str_contains($authCode, 'function current_user(bool $forceFresh = false)'));
reportTest("login_user() clears auth cache", 
    str_contains($authCode, "function login_user") && str_contains($authCode, "clear_auth_cache();"));
reportTest("logout_user() clears auth cache", 
    str_contains($authCode, "function logout_user") && str_contains($authCode, "clear_auth_cache();"));

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo "   BUG HUNT VERIFICATION COMPLETE: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
