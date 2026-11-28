<?php
/**
 * CafeFlow - Predictive Forecast CSV Fallback Exporter
 * Used automatically when Python / openpyxl is unavailable on the host.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/predictive_engine.php';

$currentUser = require_role('manager');
$pdo = isset($pdo) && ($pdo instanceof PDO) ? $pdo : db();

$mode = strtolower(trim((string) ($_GET['mode'] ?? '')));
if (!in_array($mode, ['daily', '7days', 'month', 'year'], true)) {
    if (!empty($_GET['date'])) {
        $mode = 'daily';
    } elseif (!empty($_GET['start']) && !empty($_GET['end'])) {
        $diffDays = (int) (new DateTimeImmutable($_GET['start']))->diff(new DateTimeImmutable($_GET['end']))->format('%a') + 1;
        if ($diffDays <= 7) {
            $mode = '7days';
        } elseif ($diffDays <= 31) {
            $mode = 'month';
        } else {
            $mode = 'year';
        }
    } else {
        $mode = 'daily';
    }
}

$params = $_GET;
if ($mode === '7days' && empty($params['start_date']) && !empty($params['start'])) {
    $params['start_date'] = $params['start'];
}
if ($mode === 'month' && empty($params['month']) && !empty($params['start'])) {
    $params['month'] = substr((string) $params['start'], 0, 7);
}
if ($mode === 'year' && empty($params['year']) && !empty($params['start'])) {
    $params['year'] = (int) substr((string) $params['start'], 0, 4);
}

$report = predictive_generate_report($pdo, $mode, $params);
$period = $report['period'] ?? [];
$salesF = $report['sales_forecast'] ?? [];
$eval   = $report['model_evaluation'] ?? [];
$peak   = $report['peak_demand'] ?? [];

$filename = 'DK_Cafe_Predictive_Forecast_' . preg_replace('/[^0-9A-Za-z_-]/', '_', (string) ($period['start_date'] ?? date('Y-m-d'))) . '.csv';

if (!headers_sent()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    header('Pragma: public');
}

$out = fopen('php://output', 'wb');
// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

$put = static function (array $row) use ($out): void {
    write_spreadsheet_csv_row($out, $row);
};

$put(['DK Cafe — Predictive Machine Learning Demand Forecast']);
$put(['Horizon', $period['label'] ?? $mode, 'Generated At', $report['generated_at'] ?? date('Y-m-d H:i:s')]);
$put(['Algorithm', $eval['algorithm'] ?? 'Multi-Seasonal Decomposition', 'Accuracy', ($eval['accuracy_pct'] ?? 0) . '% (WAPE: ' . ($eval['wape_pct'] ?? 0) . '%)']);
$put([]);

$put(['=== FORECAST SUMMARY ===']);
$put(['Expected Sales (PHP)', 'Expected Orders', 'Expected AOV (PHP)', 'Actual Sales (PHP)', 'Actual Orders', 'Variance (PHP)', 'Variance (%)']);
$put([
    $salesF['expected_sales'] ?? 0,
    $salesF['expected_orders'] ?? 0,
    $salesF['expected_aov'] ?? 0,
    $salesF['actual_sales'] ?? 'Pending',
    $salesF['actual_orders'] ?? 'Pending',
    $salesF['variance_amount'] ?? 'N/A',
    $salesF['variance_pct'] ?? 'N/A',
]);
$put([]);

$put(['=== PEAK DEMAND INTELLIGENCE ===']);
$put(['Peak Rush Hour', $peak['peak_hour']['label'] ?? '', 'Expected Sales', $peak['peak_hour']['expected_sales'] ?? 0]);
$put(['Peak Operating Day', $peak['peak_day']['label'] ?? '', 'Expected Sales', $peak['peak_day']['expected_sales'] ?? 0]);
$put(['Peak Seasonal Month', $peak['peak_month']['month_name'] ?? '', 'Expected Sales', $peak['peak_month']['expected_sales'] ?? 0]);
$put([]);

$put(['=== HORIZON BREAKDOWN (EXPECTED VS ACTUAL) ===']);
$put(['Interval', 'Expected Sales (PHP)', 'Expected Orders', 'Actual Sales (PHP)', 'Actual Orders']);
foreach (($salesF['chart_points'] ?? []) as $pt) {
    $put([
        $pt['label'] ?? '',
        $pt['expected'] ?? 0,
        $pt['expected_orders'] ?? 0,
        $pt['actual'] ?? 'Pending',
        $pt['actual_orders'] ?? 'Pending',
    ]);
}
$put([]);

$put(['=== PRODUCTS DEMAND PROJECTION ===']);
$put(['Rank', 'Product Name', 'Category', 'Unit Price (PHP)', 'Expected Units', 'Projected Revenue (PHP)', 'Share (%)', 'Actual Sold']);
foreach (($report['products_sold']['items'] ?? []) as $p) {
    $put([
        $p['rank'] ?? '',
        $p['name'] ?? '',
        $p['category'] ?? '',
        $p['unit_price'] ?? 0,
        $p['expected_units'] ?? 0,
        $p['expected_revenue'] ?? 0,
        $p['share_pct'] ?? 0,
        $p['actual_units'] ?? 'N/A',
    ]);
}
$put([]);

$put(['=== MATERIALS & INGREDIENTS CONSUMPTION ===']);
$put(['Ingredient', 'Unit', 'Expected Burn', 'Actual Used', 'In-Stock', 'Daily Burn Rate', 'Days Until Stockout', 'Status']);
foreach (($report['materials_consumed']['items'] ?? []) as $m) {
    $put([
        $m['name'] ?? '',
        $m['unit_measure'] ?? '',
        $m['expected_amount'] ?? 0,
        $m['actual_amount'] ?? 'N/A',
        $m['current_stock'] ?? 0,
        $m['daily_burn_rate'] ?? 0,
        $m['days_until_stockout'] ?? 999,
        $m['status'] ?? '',
    ]);
}

fclose($out);
