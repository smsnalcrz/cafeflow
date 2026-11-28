<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/predictive_engine.php';

$pdo = db();
$allPassed = true;
$totalTests = 0;
$passedTests = 0;

function assertTest(string $desc, bool $condition): void {
    global $totalTests, $passedTests, $allPassed;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] $desc" . PHP_EOL;
    } else {
        $allPassed = false;
        echo "  [FAIL] $desc" . PHP_EOL;
    }
}

echo "======================================================================" . PHP_EOL;
echo " CafeFlow POS — Predictive Machine Learning Analytics Test Suite" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

// 1. Model Calibration
echo PHP_EOL . "1. Testing Model Calibration Engine..." . PHP_EOL;
$cal = predictive_calibrate_model($pdo);
assertTest("Training days >= 14", $cal['training_days'] >= 14);
assertTest("Grand daily mean > 0", $cal['grand_daily_mean'] > 0);
assertTest("Grand AOV > 0", $cal['grand_aov'] > 0);
assertTest("Payday lift factor calculated", isset($cal['payday_lift']) && $cal['payday_lift'] >= 1.0);
assertTest("Hourly weights sum approx 1.0", abs(array_sum($cal['hourly_weights']) - 1.0) < 0.1);
assertTest("Evaluation metrics present (MAE, WAPE, Accuracy)",
    isset($cal['eval_metrics']['mae'], $cal['eval_metrics']['wape_pct'], $cal['eval_metrics']['accuracy_pct']));
assertTest("Accuracy % between 50% and 99%",
    $cal['eval_metrics']['accuracy_pct'] >= 50.0 && $cal['eval_metrics']['accuracy_pct'] <= 99.0);

// 2. Daily Horizon
echo PHP_EOL . "2. Testing Daily Horizon (Unrecorded Future, Recorded Date & Past Day)..." . PHP_EOL;
$tomorrowStr = (new DateTimeImmutable('+1 day', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$latestRecordedDt = new DateTimeImmutable((string) ($cal['latest_tx_date'] ?? 'today'), new DateTimeZone('Asia/Manila'));
$futureUnrecordedStr = max($tomorrowStr, $latestRecordedDt->modify('+2 days')->format('Y-m-d'));
$dailyFuture = predictive_generate_report($pdo, 'daily', ['date' => $futureUnrecordedStr]);

assertTest("Daily future returns success", $dailyFuture['success'] === true);
assertTest("Daily future chart points count == 17 (6:00 to 22:00)", count($dailyFuture['sales_forecast']['chart_points']) === 17);
assertTest("Daily future has Expected sales > 0", $dailyFuture['sales_forecast']['expected_sales'] > 0);
assertTest("Daily future Actual sales is NULL (never fabricated)", $dailyFuture['sales_forecast']['actual_sales'] === null);
assertTest("Daily future chart points actual values are ALL NULL",
    empty(array_filter($dailyFuture['sales_forecast']['chart_points'], fn($p) => $p['actual'] !== null)));
assertTest("Daily future Peak Hour identified with top 5 ranking",
    isset($dailyFuture['peak_demand']['peak_hour']['hour']) && count($dailyFuture['peak_demand']['peak_hour']['ranking']) === 5);

// Recorded Transaction Day inside dataset (e.g. 2026-11-30)
$dailyRecorded = predictive_generate_report($pdo, 'daily', ['date' => '2026-11-30']);
assertTest("Daily recorded transaction date returns numeric actual_sales > 0",
    is_numeric($dailyRecorded['sales_forecast']['actual_sales']) && $dailyRecorded['sales_forecast']['actual_sales'] > 0);

// Historical Day
$yesterdayStr = (new DateTimeImmutable('-1 day', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$dailyPast = predictive_generate_report($pdo, 'daily', ['date' => $yesterdayStr]);
assertTest("Daily past returns success", $dailyPast['success'] === true);
assertTest("Daily past has Expected sales", $dailyPast['sales_forecast']['expected_sales'] > 0);
assertTest("Daily past has recorded Actual sales (numeric)", is_numeric($dailyPast['sales_forecast']['actual_sales']));

// 3. 7-Day Horizon
echo PHP_EOL . "3. Testing 7-Day Horizon..." . PHP_EOL;
$sevenDay = predictive_generate_report($pdo, '7days', ['start_date' => $tomorrowStr]);
assertTest("7-day returns success", $sevenDay['success'] === true);
assertTest("7-day chart points count == 7", count($sevenDay['sales_forecast']['chart_points']) === 7);
assertTest("7-day Expected sales > 0", $sevenDay['sales_forecast']['expected_sales'] > 0);
assertTest("7-day Peak Day identified", isset($sevenDay['peak_demand']['peak_day']['day_name']));
assertTest("7-day Peak Hour dynamically ranked (5 items) and scaled to daily avg",
    count($sevenDay['peak_demand']['peak_hour']['ranking']) === 5 &&
    $sevenDay['peak_demand']['peak_hour']['expected_sales'] < ($sevenDay['sales_forecast']['expected_sales'] / 7.0));

// 4. Specific Month Horizon
echo PHP_EOL . "4. Testing Specific Month Horizon..." . PHP_EOL;
$monthNext = (new DateTimeImmutable('+1 month', new DateTimeZone('Asia/Manila')))->format('Y-m');
$monthRep = predictive_generate_report($pdo, 'month', ['month' => $monthNext]);
assertTest("Month returns success", $monthRep['success'] === true);
$daysInTargetMonth = (int) (new DateTimeImmutable($monthNext . '-01'))->format('t');
assertTest("Month chart points count matches calendar days ($daysInTargetMonth)",
    count($monthRep['sales_forecast']['chart_points']) === $daysInTargetMonth);
assertTest("Month Expected sales > 0", $monthRep['sales_forecast']['expected_sales'] > 0);
assertTest("Month Peak Day identified", isset($monthRep['peak_demand']['peak_day']['expected_sales']));
assertTest("Month Peak Hour dynamically ranked (5 items)",
    count($monthRep['peak_demand']['peak_hour']['ranking']) === 5);

// 5. Yearly Horizon
echo PHP_EOL . "5. Testing Yearly Horizon..." . PHP_EOL;
$yearRep = predictive_generate_report($pdo, 'year', ['year' => 2026]);
assertTest("Year returns success", $yearRep['success'] === true);
assertTest("Year chart points count == 12", count($yearRep['sales_forecast']['chart_points']) === 12);
assertTest("Year Expected sales > 0", $yearRep['sales_forecast']['expected_sales'] > 0);
assertTest("Year has Peak Month identified", isset($yearRep['peak_demand']['peak_month']['month_name']));
assertTest("Year has dynamic Peak Day identified", isset($yearRep['peak_demand']['peak_day']['date']));
assertTest("Year Peak Hour dynamically ranked (5 items)", count($yearRep['peak_demand']['peak_hour']['ranking']) === 5);

// 6. Products Sold & Recipe Materials
echo PHP_EOL . "6. Testing Products Sold & Recipe-Driven Ingredients..." . PHP_EOL;
$prods = $monthRep['products_sold']['items'] ?? [];
$mats  = $monthRep['materials_consumed']['items'] ?? [];
assertTest("Products sold count >= 30", count($prods) >= 30);
assertTest("Products have rank (#1 to #N)", isset($prods[0]['rank']) && $prods[0]['rank'] === 1);
assertTest("Products have expected_units, expected_revenue, share_pct",
    isset($prods[0]['expected_units'], $prods[0]['expected_revenue'], $prods[0]['share_pct']));

assertTest("Materials consumed count >= 20", count($mats) >= 20);
assertTest("Materials have real recipe units (g, ml, pcs)",
    in_array($mats[0]['unit_measure'], ['g', 'ml', 'pcs', 'bags', 'shots'], true));
assertTest("Materials have daily_burn_rate and days_until_stockout",
    isset($mats[0]['daily_burn_rate'], $mats[0]['days_until_stockout']));
assertTest("Materials summary has espresso beans (g) and milk (ml)",
    isset($monthRep['materials_consumed']['summary']['total_beans_g'],
          $monthRep['materials_consumed']['summary']['total_milk_ml']));

// 7. Complete Removal of Staffing Subsystem
echo PHP_EOL . "7. Verifying Complete Removal of Staffing Subsystem..." . PHP_EOL;
$forbiddenStaffingKeys = [
    'staffing',
    'staffing_recommendations',
    'recommended_shifts',
    'barista_headcount',
    'shift_capacity',
    'orders_per_shift'
];
foreach (['daily' => $dailyFuture, '7days' => $sevenDay, 'month' => $monthRep, 'year' => $yearRep] as $name => $res) {
    foreach ($forbiddenStaffingKeys as $k) {
        assertTest("[$name] Zero staffing key '$k' in API response", !isset($res[$k]));
    }
}

// 8. Edge Cases & Boundary Inputs
echo PHP_EOL . "8. Testing Edge Cases & Malformed Inputs..." . PHP_EOL;
$edgeDaily1 = predictive_generate_report($pdo, 'daily', ['date' => '2026-99-99']);
assertTest("Malformed date '2026-99-99' gracefully falls back to tomorrow without crashing",
    $edgeDaily1['success'] === true && $edgeDaily1['period']['start_date'] === $tomorrowStr);

$edgeDaily2 = predictive_generate_report($pdo, 'daily', ['date' => '2026-02-31']);
assertTest("Non-existent calendar date '2026-02-31' gracefully falls back without overflowing",
    $edgeDaily2['success'] === true && $edgeDaily2['period']['start_date'] === $tomorrowStr);

$edgeDaily3 = predictive_generate_report($pdo, 'daily', ['date' => str_repeat('X', 5000)]);
assertTest("Massive 5000-char string input gracefully falls back",
    $edgeDaily3['success'] === true && $edgeDaily3['period']['start_date'] === $tomorrowStr);

$edge7Day = predictive_generate_report($pdo, '7days', ['start_date' => '0000-00-00']);
assertTest("Invalid '0000-00-00' in 7days gracefully falls back",
    $edge7Day['success'] === true && count($edge7Day['sales_forecast']['chart_points']) === 7);

$edgeMonth1 = predictive_generate_report($pdo, 'month', ['month' => '2026-99']);
assertTest("Invalid month '2026-99' gracefully falls back without throwing exception",
    $edgeMonth1['success'] === true && $edgeMonth1['period']['start_date'] === $monthNext . '-01');

$edgeLeapMonth = predictive_generate_report($pdo, 'month', ['month' => '2028-02']);
assertTest("Leap year month '2028-02' accurately generates 29 daily chart points",
    $edgeLeapMonth['success'] === true && count($edgeLeapMonth['sales_forecast']['chart_points']) === 29);

$edgeLeapYear = predictive_generate_report($pdo, 'year', ['year' => 2028]);
assertTest("Leap year 2028 accurately computes 366 days_count",
    $edgeLeapYear['success'] === true && $edgeLeapYear['period']['days_count'] === 366);

$edgeInvalidYear = predictive_generate_report($pdo, 'year', ['year' => -99999]);
assertTest("Negative year -99999 clamps safely to current year",
    $edgeInvalidYear['success'] === true && count($edgeInvalidYear['sales_forecast']['chart_points']) === 12);

// 9. UI, CSS, Race Conditions & RBAC Security Audit
echo PHP_EOL . "9. Testing UI CSS Tokens, Race Condition Guards & RBAC Loopholes..." . PHP_EOL;
$varsCss = (string) file_get_contents(__DIR__ . '/../assets/css/variables.css');
assertTest("variables.css defines --surface-sub token", str_contains($varsCss, '--surface-sub:'));

$predCss = (string) file_get_contents(__DIR__ . '/../assets/css/predictive.css');
assertTest("predictive.css defines .chart-panel-title-wrap flex column layout", str_contains($predCss, '.chart-panel-title-wrap'));
assertTest("predictive.css defines .pred-table-scroll horizontal/vertical scroll", str_contains($predCss, '.pred-table-scroll'));
assertTest("predictive.css has zero .pred-shift-card staffing styles", !str_contains($predCss, '.pred-shift-card'));

$predJs = (string) file_get_contents(__DIR__ . '/../assets/js/modules/predictive.js');
assertTest("predictive.js implements _predReqSeq race-condition protection", str_contains($predJs, '_predReqSeq'));
assertTest("predictive.js implements _exportInFlight double-click protection", str_contains($predJs, '_exportInFlight'));
assertTest("predictive.js has zero renderStaffingSection references", !str_contains($predJs, 'renderStaffingSection'));

$exportPhp = (string) file_get_contents(__DIR__ . '/../api/export_excel.php');
assertTest("export_excel.php enforces manager-only RBAC for type=predictive",
    str_contains($exportPhp, "\$typeParam === 'predictive'") && str_contains($exportPhp, '!== \'manager\''));
assertTest("export_excel.php validates real calendar dates via checkdate",
    str_contains($exportPhp, 'checkdate('));

// Summary
echo PHP_EOL . "======================================================================" . PHP_EOL;
echo " Predictive Test Results: $passedTests / $totalTests Passed (" . round(($passedTests / $totalTests) * 100, 1) . "%)" . PHP_EOL;
echo "======================================================================" . PHP_EOL;

if (!$allPassed) {
    exit(1);
}
