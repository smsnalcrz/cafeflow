<?php
/**
 * tools/verify_mock_distribution.php
 * Verifies monthly distribution for 2025 and 2026.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
$pdo = db();

echo "=================================================================\n";
echo " CafeFlow POS - 2025 & 2026 Monthly Distribution Audit\n";
echo "=================================================================\n\n";

$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(`timestamp`, '%Y-%m') AS `month_key`,
        COUNT(*) AS `total_orders`,
        SUM(CASE WHEN `status` = 'Completed' THEN 1 ELSE 0 END) AS `completed_orders`,
        SUM(CASE WHEN `status` = 'Cancelled' THEN 1 ELSE 0 END) AS `cancelled_orders`,
        SUM(CASE WHEN `status` = 'Completed' THEN `total_amount` ELSE 0 END) AS `gross_revenue`,
        ROUND(AVG(CASE WHEN `status` = 'Completed' THEN `total_amount` ELSE NULL END), 2) AS `avg_order_value`
    FROM `transactions`
    WHERE `timestamp` BETWEEN '2025-01-01 00:00:00' AND '2026-12-31 23:59:59'
    GROUP BY `month_key`
    ORDER BY `month_key` ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("%-10s | %-12s | %-10s | %-10s | %-14s | %-10s\n",
    "Month", "Total Orders", "Completed", "Cancelled", "Gross Sales", "Avg Order");
echo str_repeat("-", 76) . "\n";

$byYear = ['2025' => ['orders' => 0, 'gross' => 0.0, 'months' => 0], '2026' => ['orders' => 0, 'gross' => 0.0, 'months' => 0]];

foreach ($rows as $r) {
    $yr = substr($r['month_key'], 0, 4);
    if (isset($byYear[$yr])) {
        $byYear[$yr]['orders'] += (int)$r['completed_orders'];
        $byYear[$yr]['gross'] += (float)$r['gross_revenue'];
        $byYear[$yr]['months']++;
    }

    printf("%-10s | %12d | %10d | %10d | PHP %10.2f | PHP %7.2f\n",
        $r['month_key'],
        $r['total_orders'],
        $r['completed_orders'],
        $r['cancelled_orders'],
        $r['gross_revenue'],
        $r['avg_order_value']
    );
}

echo str_repeat("=", 76) . "\n";
foreach ($byYear as $yr => $data) {
    $avgMo = $data['months'] > 0 ? $data['gross'] / $data['months'] : 0;
    printf("%s: %d/12 months populated | %d completed orders | PHP %.2f gross (Avg: PHP %.2f/mo)\n",
        $yr, $data['months'], $data['orders'], $data['gross'], $avgMo);
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
