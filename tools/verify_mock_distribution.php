<?php
/**
 * tools/verify_mock_distribution.php
 * Verifies monthly distribution for 2025 and 2026, including open/closed days
 * and Task 19 mock vs real data isolation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
$pdo = db();

echo "========================================================================================\n";
echo " CafeFlow POS - 2025 & 2026 Monthly Distribution & Operational Audit\n";
echo "========================================================================================\n\n";

$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(`timestamp`, '%Y-%m') AS `month_key`,
        COUNT(DISTINCT DATE(`timestamp`)) AS `active_days`,
        COUNT(*) AS `total_orders`,
        SUM(CASE WHEN `status` = 'Completed' THEN 1 ELSE 0 END) AS `completed_orders`,
        SUM(CASE WHEN `status` = 'Cancelled' THEN 1 ELSE 0 END) AS `cancelled_orders`,
        SUM(CASE WHEN `status` = 'Completed' THEN `total_amount` ELSE 0 END) AS `gross_revenue`,
        ROUND(AVG(CASE WHEN `status` = 'Completed' THEN `total_amount` ELSE NULL END), 2) AS `avg_order_value`
    FROM `transactions`
    WHERE `timestamp` BETWEEN '2025-01-01 00:00:00' AND '2026-12-31 23:59:59'
      AND `is_mock` = 1
    GROUP BY `month_key`
    ORDER BY `month_key` ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("%-8s | %-9s | %-10s | %-10s | %-10s | %-14s | %-10s\n",
    "Month", "Open/Days", "Closed", "Completed", "Cancelled", "Gross Sales", "Avg Order");
echo str_repeat("-", 88) . "\n";

$byYear = ['2025' => ['orders' => 0, 'gross' => 0.0, 'months' => 0, 'open_days' => 0, 'closed_days' => 0], '2026' => ['orders' => 0, 'gross' => 0.0, 'months' => 0, 'open_days' => 0, 'closed_days' => 0]];

foreach ($rows as $r) {
    [$yr, $mo] = explode('-', $r['month_key']);
    $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, (int)$mo, (int)$yr);
    $openDays = (int)$r['active_days'];
    $closedDays = $daysInMonth - $openDays;

    if (isset($byYear[$yr])) {
        $byYear[$yr]['orders'] += (int)$r['completed_orders'];
        $byYear[$yr]['gross'] += (float)$r['gross_revenue'];
        $byYear[$yr]['months']++;
        $byYear[$yr]['open_days'] += $openDays;
        $byYear[$yr]['closed_days'] += $closedDays;
    }

    printf("%-8s | %2d/%2d days | %2d closed  | %10d | %10d | PHP %10.2f | PHP %7.2f\n",
        $r['month_key'],
        $openDays,
        $daysInMonth,
        $closedDays,
        $r['completed_orders'],
        $r['cancelled_orders'],
        $r['gross_revenue'],
        $r['avg_order_value']
    );
}

echo str_repeat("=", 88) . "\n";
foreach ($byYear as $yr => $data) {
    $avgMo = $data['months'] > 0 ? $data['gross'] / $data['months'] : 0;
    printf("%s: %d/12 months | %d open days (%d closed) | %d orders | PHP %.2f gross (Avg: PHP %.2f/mo)\n",
        $yr, $data['months'], $data['open_days'], $data['closed_days'], $data['orders'], $data['gross'], $avgMo);
}

// Waste log audit
$wasteStmt = $pdo->query("
    SELECT 
        YEAR(wasted_at) AS `yr`,
        COUNT(*) AS `waste_events`,
        COALESCE(SUM(estimated_cost_php), 0) AS `material_loss_php`
    FROM `waste_log`
    WHERE `wasted_at` BETWEEN '2025-01-01 00:00:00' AND '2026-12-31 23:59:59'
    GROUP BY `yr`
    ORDER BY `yr` ASC
");
$wasteRows = $wasteStmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- Waste & Loss Audit ---\n";
foreach ($wasteRows as $wr) {
    printf("Year %s: %d waste events | PHP %.2f estimated material cost\n",
        $wr['yr'], $wr['waste_events'], $wr['material_loss_php']);
}

// Isolation audit: Real vs Mock
$isoStmt = $pdo->query("
    SELECT 
        COALESCE(is_mock, 0) AS is_mock_val,
        COUNT(*) AS total_tx,
        SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END) AS completed_total,
        MIN(transaction_id) AS min_id,
        MAX(transaction_id) AS max_id
    FROM `transactions`
    GROUP BY is_mock_val
    ORDER BY is_mock_val ASC
");
$isoRows = $isoStmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- Task 19 Isolation Audit (Live vs Mock) ---\n";
foreach ($isoRows as $ir) {
    $type = ((int)$ir['is_mock_val'] === 1) ? 'SYNTHETIC MOCK' : 'GENUINE LIVE';
    printf("  [%s]: %d transactions | Range ID: %d - %d | Sales: PHP %.2f\n",
        $type, $ir['total_tx'], $ir['min_id'], $ir['max_id'], $ir['completed_total']);
}
