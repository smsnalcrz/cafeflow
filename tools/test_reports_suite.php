<?php
/**
 * CafeFlow - Sales Report, Waste Audit & Pristine Light Mode Automated Verification Suite
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();

echo "======================================================================\n";
echo "   CAFEFLOW SALES REPORT, WASTE AUDIT & LIGHT-MODE SUITE              \n";
echo "======================================================================\n\n";

$passed = 0;
$failed = 0;

function reportTest(string $desc, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo " [PASS] " . $desc . "\n";
    } else {
        $failed++;
        echo " [FAIL] " . $desc . ($detail ? " ($detail)" : "") . "\n";
    }
}

// -----------------------------------------------------------------------------
// 1. Dark Mode Complete Removal Verification
// -----------------------------------------------------------------------------
echo "--- 1. Testing Complete Removal of Dark Mode & Preservation of Light Theme ---\n";
$variablesCss = file_get_contents(__DIR__ . '/../assets/css/variables.css');
reportTest("variables.css DOES NOT contain :root[data-theme=\"dark\"]", !str_contains($variablesCss, ':root[data-theme="dark"]'));
reportTest("Brand tokens preserve --bg: var(--parchment)", str_contains($variablesCss, 'var(--parchment)'));
reportTest("Brand tokens preserve --caramel: #e8623a", str_contains($variablesCss, '#e8623a') || str_contains($variablesCss, '#E8623A'));
reportTest("Brand tokens preserve --green: #2a9b52", str_contains($variablesCss, '#2a9b52') || str_contains($variablesCss, '#2A9B52'));

$indexPhp = file_get_contents(__DIR__ . '/../index.php');
reportTest("index.php DOES NOT have #themeToggleBtn", !str_contains($indexPhp, 'id="themeToggleBtn"'));
reportTest("index.php cleans up legacy theme storage", str_contains($indexPhp, "localStorage.removeItem('cafeflow_theme')"));
reportTest("index.php removes any data-theme attribute", str_contains($indexPhp, "removeAttribute('data-theme')"));

$topbarCss = file_get_contents(__DIR__ . '/../assets/css/topbar.css');
reportTest("topbar.css DOES NOT have .theme-toggle-btn styling", !str_contains($topbarCss, '.theme-toggle-btn'));
reportTest("topbar.css DOES NOT have dark mode rules", !str_contains($topbarCss, 'data-theme="dark"'));

$utilsJs = file_get_contents(__DIR__ . '/../assets/js/core/utils.js');
reportTest("utils.js DOES NOT have toggleTheme", !str_contains($utilsJs, 'toggleTheme'));
reportTest("utils.js DOES NOT have isDarkMode", !str_contains($utilsJs, 'isDarkMode'));

// Check all css files in assets/css for any data-theme
$cssFiles = glob(__DIR__ . '/../assets/css/*.css');
$foundDataThemeInCss = false;
foreach ($cssFiles as $file) {
    $content = file_get_contents($file);
    if (str_contains($content, 'data-theme="dark"')) {
        $foundDataThemeInCss = true;
        break;
    }
}
reportTest("All stylesheet files have zero data-theme=\"dark\" rules", !$foundDataThemeInCss);

// Check all js files in assets/js for cafeflow-theme-change
$jsFiles = glob(__DIR__ . '/../assets/js/modules/*.js');
$foundThemeListener = false;
foreach ($jsFiles as $file) {
    $content = file_get_contents($file);
    if (str_contains($content, 'cafeflow-theme-change')) {
        $foundThemeListener = true;
        break;
    }
}
reportTest("All JavaScript modules have zero cafeflow-theme-change listeners", !$foundThemeListener);

// -----------------------------------------------------------------------------
// 2. Sales Report API Response Contract Verification
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Sales Report API Response Contracts ---\n";

function runSubApi(string $url, string $role = 'manager'): array {
    global $pdo;
    $targetUser = $pdo->query("SELECT user_id, username, role, full_name, status FROM users WHERE role = '{$role}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $uId = $targetUser ? (int)$targetUser['user_id'] : 1;
    $uName = $targetUser ? $targetUser['username'] : $role;
    $fName = $targetUser ? $targetUser['full_name'] : ucfirst($role);

    $code = '
    $_SERVER["REQUEST_METHOD"] = "GET";
    $_SERVER["HTTP_ACCEPT"] = "application/json";
    $_GET = [];
    $parts = parse_url("' . addslashes($url) . '");
    if (isset($parts["query"])) {
        parse_str($parts["query"], $_GET);
    }
    session_start();
    $_SESSION["user_id"] = ' . $uId . ';
    $_SESSION["user"] = [
        "user_id" => ' . $uId . ',
        "username" => "' . addslashes($uName) . '",
        "role" => "' . $role . '",
        "full_name" => "' . addslashes($fName) . '",
        "status" => "active"
    ];
    require __DIR__ . "/../" . $parts["path"];
    ';
    $tmpFile = __DIR__ . '/temp_api_test.php';
    file_put_contents($tmpFile, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
    @unlink($tmpFile);
    $json = json_decode((string)$out, true);
    return is_array($json) ? $json : ['raw' => $out];
}

// Test Today report
$todayRep = runSubApi('api/report.php?range=today');
reportTest("GET report.php?range=today returns valid JSON", isset($todayRep['summary']));
reportTest("Today report includes total, orders, cash, gcash", isset($todayRep['summary']['total'], $todayRep['summary']['orders'], $todayRep['summary']['cash'], $todayRep['summary']['gcash']));
reportTest("Today report includes by_hour breakdown", isset($todayRep['by_hour']) && is_array($todayRep['by_hour']));
reportTest("Today report includes category_summary", isset($todayRep['category_summary']));
reportTest("Today report includes waste_summary", isset($todayRep['waste_summary']));

// Test 7-Day report
$sevenDayRep = runSubApi('api/report.php?range=7days');
reportTest("GET report.php?range=7days returns valid JSON", isset($sevenDayRep['summary']));
reportTest("7-Day report includes by_day array", isset($sevenDayRep['by_day']) && is_array($sevenDayRep['by_day']));

// Test Year report
$yearRep = runSubApi('api/report.php?range=year&year=2026');
reportTest("GET report.php?range=year returns valid JSON", isset($yearRep['summary']));
reportTest("Year report includes monthly_breakdown (12 months)", isset($yearRep['monthly_breakdown']) && count($yearRep['monthly_breakdown']) === 12);
reportTest("Year report includes product_performance", isset($yearRep['product_performance']));

// -----------------------------------------------------------------------------
// 3. Waste & Loss Tracking API Response Contract Verification
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Waste & Loss Tracking API Response Contracts ---\n";

$dailyWaste = runSubApi('api/waste_api.php?period=daily');
reportTest("GET waste_api.php?period=daily returns valid JSON", isset($dailyWaste['summary']));
reportTest("Waste API summary contains total_material_cost_php", isset($dailyWaste['summary']['total_material_cost_php']));
reportTest("Waste API summary contains total_income_loss_php", isset($dailyWaste['summary']['total_income_loss_php']));
reportTest("Waste API contains materials_summary", isset($dailyWaste['materials_summary']) && is_array($dailyWaste['materials_summary']));
reportTest("Waste API contains income_loss_by_product", isset($dailyWaste['income_loss_by_product']) && is_array($dailyWaste['income_loss_by_product']));
reportTest("Waste API contains charts with trend and top_materials", isset($dailyWaste['charts']['trend'], $dailyWaste['charts']['top_materials']));
reportTest("Waste API contains logs array", isset($dailyWaste['logs']) && is_array($dailyWaste['logs']));

// Test Weekly waste
$weeklyWaste = runSubApi('api/waste_api.php?period=weekly');
reportTest("GET waste_api.php?period=weekly returns valid JSON", isset($weeklyWaste['summary']));

// Test Monthly waste
$monthlyWaste = runSubApi('api/waste_api.php?period=monthly&month=2026-11');
reportTest("GET waste_api.php?period=monthly returns valid JSON", isset($monthlyWaste['summary']));
reportTest("Monthly Waste API resolves period as monthly", ($monthlyWaste['period'] ?? '') === 'monthly');
reportTest("Monthly Waste API resolves start_date 2026-11-01", ($monthlyWaste['start_date'] ?? '') === '2026-11-01');
reportTest("Monthly Waste API resolves end_date 2026-11-30", ($monthlyWaste['end_date'] ?? '') === '2026-11-30');
reportTest("Monthly Waste API trend chart has 30 daily points", isset($monthlyWaste['charts']['trend']['labels']) && count($monthlyWaste['charts']['trend']['labels']) === 30);

// -----------------------------------------------------------------------------
// 4. Sales & Waste Presentation Elements
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Frontend Presentation Elements (Light Mode) ---\n";
$reportsJs = file_get_contents(__DIR__ . '/../assets/js/modules/reports.js');
reportTest("reports.js has renderExecutiveKpiGrid()", str_contains($reportsJs, 'renderExecutiveKpiGrid'));
reportTest("reports.js renders Top 5 Bestselling Leaderboard", str_contains($reportsJs, 'top-sellers-leaderboard'));
reportTest("reports.js has Gold/Silver/Bronze medal ranks", str_contains($reportsJs, 'rank-gold') && str_contains($reportsJs, 'rank-silver') && str_contains($reportsJs, 'rank-bronze'));

$reportsCss = file_get_contents(__DIR__ . '/../assets/css/reports.css');
reportTest("reports.css has .sales-kpi-grid layout", str_contains($reportsCss, '.sales-kpi-grid'));
reportTest("reports.css uses solid semantic background", str_contains($reportsCss, 'var(--surface)'));

$wasteJs = file_get_contents(__DIR__ . '/../assets/js/modules/waste.js');
reportTest("waste.js supports monthly waste period", str_contains($wasteJs, "_wastePeriod === 'monthly'"));
reportTest("waste.js binds to #wasteMonthPicker", str_contains($wasteJs, '#wasteMonthPicker'));
reportTest("waste.js binds to #wasteJumpThisMonthBtn", str_contains($wasteJs, '#wasteJumpThisMonthBtn'));
reportTest("waste.js renders severity badges (critical, moderate, low)", str_contains($wasteJs, 'severity-badge') && str_contains($wasteJs, 'critical') && str_contains($wasteJs, 'moderate'));
reportTest("waste.js has reason filter selector", str_contains($wasteJs, 'waste-select-filter'));

$indexPhpWaste = file_get_contents(__DIR__ . '/../index.php');
reportTest("index.php contains Monthly waste period button", str_contains($indexPhpWaste, 'data-waste-period="monthly"'));
reportTest("index.php contains #wasteMonthlyPickerGroup", str_contains($indexPhpWaste, 'id="wasteMonthlyPickerGroup"'));

$wasteCss = file_get_contents(__DIR__ . '/../assets/css/waste.css');
reportTest("waste.css has .severity-badge styles", str_contains($wasteCss, '.severity-badge.critical'));

$printCss = file_get_contents(__DIR__ . '/../assets/css/print.css');
reportTest("print.css has sales & waste kpi print rules", str_contains($printCss, '.sales-kpi-grid, .waste-kpi-grid'));
reportTest("print.css does NOT reference theme-toggle-btn", !str_contains($printCss, 'theme-toggle-btn'));

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo "   TEST RUN COMPLETE: {$passed} PASSED, {$failed} FAILED         \n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
