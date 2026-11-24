<?php
/**
 * CafeFlow - Sales Report API & Standalone View
 *
 * GET report.php?range=today|week|month|date|7days&start=YYYY-MM-DD&end=YYYY-MM-DD&date=YYYY-MM-DD&month=YYYY-MM
 *
 * Supported query parameters:
 * - `range`: 'today', 'week', 'month', 'date', '7days' (or 'custom')
 * - `date`: YYYY-MM-DD for specific single date report
 * - `start`: YYYY-MM-DD start date (or single date)
 * - `end`: YYYY-MM-DD end date
 * - `month`: YYYY-MM for specific month report
 * - `format`: 'json' (default) or 'html' for printable standalone report
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// RBAC: Both Barista and Manager can view sales reports (e.g. for shift closing or when manager is not present)
require_role(['barista', 'manager']);

require_method('GET');

// Ensure Manila timezone matches db.php
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);

$rangeParam = $_GET['range'] ?? null;
$dateParam  = $_GET['date']  ?? null;
$monthParam = $_GET['month'] ?? null;
$yearParam  = $_GET['year']  ?? null;
$startParam = $_GET['start'] ?? null;
$endParam   = $_GET['end']   ?? null;
$format     = $_GET['format'] ?? 'json';

// Helper: strictly parse YYYY-MM-DD
$parseDate = static function (?string $str, DateTimeZone $tz): ?DateTimeImmutable {
    if (!$str || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $str, $tz);
    return ($dt && $dt->format('Y-m-d') === $str) ? $dt : null;
};

$rangeType = 'today';
$label = '';
$startDateStr = '';
$endDateStr = '';

// -----------------------------------------------------------------------------
// 1. Specific Year (e.g. ?range=year&year=2025 or ?year=2025)
// -----------------------------------------------------------------------------
if ($rangeParam === 'year' || ($yearParam && preg_match('/^\d{4}$/', (string)$yearParam) && !$monthParam)) {
    $rawYear = (int) ($yearParam ?? ($rangeParam === 'year' ? $now->format('Y') : $yearParam));
    if ($rawYear < 2000 || $rawYear > 2100) {
        fail(400, 'Invalid year. Must be between 2000 and 2100.');
    }
    $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-01-01', $rawYear), $tz);
    $dtEnd   = $dtStart->modify('+1 year'); // Jan 1 of next year 00:00:00 (exclusive)
    $lastDay = $dtEnd->modify('-1 day');    // Dec 31

    $rangeType    = 'year';
    $label        = sprintf('Year %04d (Annual Analytics)', $rawYear);
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
}
// -----------------------------------------------------------------------------
// 2. Specific Month (e.g. ?month=2026-10 or ?range=month&month=2026-10)
// -----------------------------------------------------------------------------
elseif ($monthParam || ($rangeParam === 'month' && ($monthParam || ($startParam && preg_match('/^\d{4}-\d{2}$/', $startParam))))) {
    $rawMonth = $monthParam ?? $startParam;
    if (!preg_match('/^(\d{4})-(\d{2})$/', $rawMonth, $m)) {
        fail(400, 'Invalid month format. Use YYYY-MM.');
    }
    $year = (int)$m[1];
    $month = (int)$m[2];
    if ($month < 1 || $month > 12) {
        fail(400, 'Invalid month. Must be between 01 and 12.');
    }

    $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month), $tz);
    $dtEnd   = $dtStart->modify('+1 month'); // first day of next month 00:00:00 (exclusive)
    $lastDay = $dtEnd->modify('-1 day');

    $rangeType    = 'month';
    $label        = $dtStart->format('F Y');
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
}
// -----------------------------------------------------------------------------
// 2. Specific 7-Day Report (e.g. ?range=7days&start=YYYY-MM-DD)
// -----------------------------------------------------------------------------
elseif ($rangeParam === '7days' || $rangeParam === '7day' || $rangeParam === 'week_custom' || ($rangeParam === 'week' && ($startParam || $dateParam))) {
    $rawStart = $startParam ?? $dateParam;
    if ($rawStart) {
        $dtStart = $parseDate($rawStart, $tz);
        if (!$dtStart) {
            fail(400, 'Invalid start date format. Use YYYY-MM-DD.');
        }
    } else {
        // Default to trailing 7 days ending today
        $dtStart = (new DateTimeImmutable('today', $tz))->modify('-6 days');
    }
    $dtEnd   = $dtStart->modify('+7 days'); // 7 days window (exclusive)
    $lastDay = $dtStart->modify('+6 days');

    $rangeType    = '7days';
    $label        = $dtStart->format('M j, Y') . ' – ' . $lastDay->format('M j, Y') . ' (7 Days)';
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
}
// -----------------------------------------------------------------------------
// 3. Specific Single Date (e.g. ?range=date&date=YYYY-MM-DD or ?start=YYYY-MM-DD)
// -----------------------------------------------------------------------------
elseif ($rangeParam === 'date' || $dateParam || ($startParam && !$endParam)) {
    $rawDate = $dateParam ?? $startParam;
    $dtStart = $parseDate($rawDate, $tz);
    if (!$dtStart) {
        fail(400, 'Invalid date format. Use YYYY-MM-DD.');
    }
    $dtEnd = $dtStart->modify('+1 day'); // tomorrow 00:00:00 (exclusive)

    $rangeType    = 'date';
    $label        = $dtStart->format('l, F j, Y');
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $dtStart->format('Y-m-d');
}
// -----------------------------------------------------------------------------
// 4. Custom Range (both start and end supplied)
// -----------------------------------------------------------------------------
elseif ($startParam && $endParam) {
    $dtStart   = $parseDate($startParam, $tz);
    $dtEndUser = $parseDate($endParam, $tz);
    if (!$dtStart || !$dtEndUser) {
        fail(400, 'Invalid date format. Use YYYY-MM-DD.');
    }
    if ($dtEndUser < $dtStart) {
        fail(400, 'End date cannot be before start date.');
    }
    $dtEnd = $dtEndUser->modify('+1 day'); // exclusive

    $rangeType    = 'custom';
    $label        = $dtStart->format('M j, Y') . ' – ' . $dtEndUser->format('M j, Y');
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $dtEndUser->format('Y-m-d');
}
// -----------------------------------------------------------------------------
// 5. Predefined Presets ('today', 'week', 'month' without custom dates)
// -----------------------------------------------------------------------------
else {
    $preset = $rangeParam ?? 'today';
    if ($preset === 'today') {
        $dtStart      = new DateTimeImmutable('today', $tz);
        $dtEnd        = $dtStart->modify('+1 day');
        $rangeType    = 'today';
        $label        = 'Today (' . $dtStart->format('M j, Y') . ')';
        $startDateStr = $dtStart->format('Y-m-d');
        $endDateStr   = $dtStart->format('Y-m-d');
    } elseif ($preset === 'week') {
        $dtEnd        = (new DateTimeImmutable('today', $tz))->modify('+1 day');
        $dtStart      = (new DateTimeImmutable('today', $tz))->modify('-6 days');
        $rangeType    = 'week';
        $label        = 'Last 7 days (' . $dtStart->format('M j') . ' – ' . (new DateTimeImmutable('today', $tz))->format('M j, Y') . ')';
        $startDateStr = $dtStart->format('Y-m-d');
        $endDateStr   = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    } elseif ($preset === 'month') {
        $dtStart      = new DateTimeImmutable('first day of this month 00:00:00', $tz);
        $dtEnd        = $dtStart->modify('+1 month');
        $lastDay      = $dtEnd->modify('-1 day');
        $rangeType    = 'month';
        $label        = 'This month (' . $dtStart->format('F Y') . ')';
        $startDateStr = $dtStart->format('Y-m-d');
        $endDateStr   = $lastDay->format('Y-m-d');
    } elseif ($preset === 'year') {
        $rawYear      = (int) $now->format('Y');
        $dtStart      = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-01-01', $rawYear), $tz);
        $dtEnd        = $dtStart->modify('+1 year');
        $lastDay      = $dtEnd->modify('-1 day');
        $rangeType    = 'year';
        $label        = sprintf('Year %04d (Annual Analytics)', $rawYear);
        $startDateStr = $dtStart->format('Y-m-d');
        $endDateStr   = $lastDay->format('Y-m-d');
    } else {
        fail(422, 'Unknown report range.');
    }
}

$startStr = $dtStart->format('Y-m-d 00:00:00');
$endStr   = $dtEnd->format('Y-m-d 00:00:00');

$includeMockParam = $_GET['include_mock'] ?? null;
// Task 19: All official sales reports and accounting audits default strictly to live records (is_mock = 0 or NULL)
// Only include synthetic mock data if explicitly requested via include_mock=1 or include_mock=true
$includeMock = ($includeMockParam === '1' || $includeMockParam === 'true');
$filterOnlyReal = !$includeMock;
$mockFilterSql  = $filterOnlyReal ? " AND (is_mock = 0 OR is_mock IS NULL)" : "";
$mockFilterTql  = $filterOnlyReal ? " AND (t.is_mock = 0 OR t.is_mock IS NULL)" : "";

$pdo = db();

// -----------------------------------------------------------------------------
// Summary (orders, total, cash, gcash, pending, mock/real splits)
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(CASE WHEN status = 'Completed' {$mockFilterSql} THEN 1 END) AS orders,
            COALESCE(SUM(CASE WHEN status = 'Completed' {$mockFilterSql} THEN total_amount ELSE 0 END), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'Cash' {$mockFilterSql} THEN total_amount ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'GCash' {$mockFilterSql} THEN total_amount ELSE 0 END), 0) AS gcash,
            COALESCE(SUM(CASE WHEN status = 'Pending' {$mockFilterSql} THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'Cancelled' {$mockFilterSql} THEN 1 ELSE 0 END), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN status = 'Voided' {$mockFilterSql} THEN 1 ELSE 0 END), 0) AS voided,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND is_mock = 1 THEN 1 ELSE 0 END), 0) AS mock_orders,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND (is_mock = 0 OR is_mock IS NULL) THEN 1 ELSE 0 END), 0) AS real_orders,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND is_mock = 1 THEN total_amount ELSE 0 END), 0) AS mock_total,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND (is_mock = 0 OR is_mock IS NULL) THEN total_amount ELSE 0 END), 0) AS real_total
       FROM transactions
      WHERE `timestamp` >= :start AND `timestamp` < :end"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$s = $stmt->fetch() ?: ['orders' => 0, 'total' => 0, 'cash' => 0, 'gcash' => 0, 'pending' => 0, 'cancelled' => 0, 'voided' => 0, 'mock_orders' => 0, 'real_orders' => 0, 'mock_total' => 0, 'real_total' => 0];

$mockOrders = (int) ($s['mock_orders'] ?? 0);
$realOrders = (int) ($s['real_orders'] ?? 0);
$datasetType = ($mockOrders > 0 && $realOrders === 0) ? 'synthetic' : (($realOrders > 0 && $mockOrders === 0) ? 'actual' : ($mockOrders > 0 && $realOrders > 0 ? 'mixed' : 'empty'));

// -----------------------------------------------------------------------------
// Top-selling items (classified by macro_category: drinks vs snacks, with revenue)
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COALESCE(p.name, ti.product_name) AS name,
            COALESCE(p.category, 'Other')    AS category,
            CASE 
                WHEN LOWER(COALESCE(p.category, '')) IN ('coffee', 'milk series', 'milk tea', 'fruit tea / lemonade') 
                     OR LOWER(COALESCE(p.category, '')) LIKE '%drink%'
                     OR LOWER(COALESCE(p.category, '')) LIKE '%coffee%'
                     OR LOWER(COALESCE(p.category, '')) LIKE '%tea%'
                     OR LOWER(COALESCE(p.category, '')) LIKE '%beverage%'
                     OR LOWER(COALESCE(p.category, '')) LIKE '%lemonade%'
                     OR LOWER(COALESCE(p.category, '')) LIKE '%milk%'
                THEN 'drinks'
                ELSE 'snacks'
            END AS macro_category,
            SUM(ti.quantity) AS qty,
            COALESCE(SUM(ti.unit_price * ti.quantity), 0) AS revenue
       FROM transaction_items ti
       JOIN transactions t   ON t.transaction_id = ti.transaction_id
       LEFT JOIN menu_products p ON p.product_id = ti.product_id
      WHERE t.`timestamp` >= :start AND t.`timestamp` < :end AND t.status = 'Completed' {$mockFilterTql}
      GROUP BY name, category, macro_category
      ORDER BY qty DESC, revenue DESC, name ASC
      LIMIT 100"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$top = $stmt->fetchAll();

// Category roll-up metrics for rapid frontend summary generation
$drinksQty = 0; $drinksRev = 0.0;
$snacksQty = 0; $snacksRev = 0.0;
$totalItemsQty = 0; $totalItemsRev = 0.0;
foreach ($top as $tRow) {
    $q = (int) $tRow['qty'];
    $rev = (float) $tRow['revenue'];
    $totalItemsQty += $q;
    $totalItemsRev += $rev;
    if ($tRow['macro_category'] === 'drinks') {
        $drinksQty += $q;
        $drinksRev += $rev;
    } else {
        $snacksQty += $q;
        $snacksRev += $rev;
    }
}

// -----------------------------------------------------------------------------
// Daily breakdown
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT DATE(`timestamp`) AS sale_date,
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS orders,
            COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'Cash'  THEN total_amount ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'GCash' THEN total_amount ELSE 0 END), 0) AS gcash
       FROM transactions
      WHERE `timestamp` >= :start AND `timestamp` < :end {$mockFilterSql}
      GROUP BY sale_date
      ORDER BY sale_date DESC"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$days = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Category breakdown (all ranges)
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COALESCE(p.category, 'Other') AS category,
            SUM(ti.quantity)              AS qty,
            COALESCE(SUM(ti.unit_price * ti.quantity), 0) AS revenue
       FROM transaction_items ti
       JOIN transactions t ON t.transaction_id = ti.transaction_id
       LEFT JOIN menu_products p ON p.product_id = ti.product_id
      WHERE t.`timestamp` >= :start AND t.`timestamp` < :end AND t.status = 'Completed' {$mockFilterTql}
      GROUP BY category
      ORDER BY revenue DESC"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$categories = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Available completed sales years (for Year selector)
// -----------------------------------------------------------------------------
$availableYears = [];
try {
    $yStmt = $pdo->query("SELECT DISTINCT YEAR(`timestamp`) AS yr FROM transactions WHERE status = 'Completed' ORDER BY yr DESC");
    $availableYears = array_map(static fn($r) => (int)$r['yr'], $yStmt->fetchAll());
} catch (\Throwable $e) {}
if (empty($availableYears)) {
    $availableYears = [(int)$now->format('Y')];
}

// -----------------------------------------------------------------------------
// Waste & Loss Tracking for reporting window
// -----------------------------------------------------------------------------
$wasteStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT wl.waste_id)        AS total_waste_events,
        COUNT(DISTINCT wl.transaction_id) AS distinct_cancelled_orders,
        COALESCE(SUM(wl.cost_cents), 0)    AS total_material_cost_cents,
        COALESCE(SUM(wl.estimated_cost_php), 0.00) AS total_material_cost_php,
        COALESCE(SUM(CASE WHEN wl.unit = 'g' THEN wl.amount_wasted ELSE 0 END), 0.00)   AS total_wasted_g,
        COALESCE(SUM(CASE WHEN wl.unit = 'ml' THEN wl.amount_wasted ELSE 0 END), 0.00)  AS total_wasted_ml,
        COALESCE(SUM(CASE WHEN wl.unit = 'pcs' THEN wl.amount_wasted ELSE 0 END), 0.00) AS total_wasted_pcs
    FROM waste_log wl
    WHERE wl.wasted_at >= :start AND wl.wasted_at < :end
");
$wasteStmt->execute([':start' => $startStr, ':end' => $endStr]);
$wSum = $wasteStmt->fetch() ?: [
    'total_waste_events' => 0,
    'distinct_cancelled_orders' => 0,
    'total_material_cost_cents' => 0,
    'total_material_cost_php' => 0.0,
    'total_wasted_g' => 0.0,
    'total_wasted_ml' => 0.0,
    'total_wasted_pcs' => 0.0,
];

// Cancelled sales income loss in this report window
$cancelledLossStmt = $pdo->prepare("
    SELECT
        COUNT(transaction_id) AS cancelled_orders,
        COALESCE(SUM(total_amount), 0.00) AS income_loss_php
    FROM transactions
    WHERE `timestamp` >= :start AND `timestamp` < :end
      AND status IN ('Cancelled', 'Voided')
");
$cancelledLossStmt->execute([':start' => $startStr, ':end' => $endStr]);
$cLoss = $cancelledLossStmt->fetch() ?: ['cancelled_orders' => 0, 'income_loss_php' => 0.0];

$wasteTrendStmt = $pdo->prepare("
    SELECT
        DATE(wasted_at) AS waste_date,
        COUNT(DISTINCT waste_id) AS events,
        COALESCE(SUM(estimated_cost_php), 0.00) AS cost_php
    FROM waste_log
    WHERE wasted_at >= :start AND wasted_at < :end
    GROUP BY waste_date
    ORDER BY waste_date ASC
");
$wasteTrendStmt->execute([':start' => $startStr, ':end' => $endStr]);
$wasteTrend = $wasteTrendStmt->fetchAll();

// -----------------------------------------------------------------------------
// Yearly Monthly Breakdown & Enriched Analytics
// -----------------------------------------------------------------------------
$monthlyBreakdown = [];
$bestMonth = null;
$lowestMonth = null;
$bestDay = null;

if ($rangeType === 'year') {
    $mStmt = $pdo->prepare(
        "SELECT MONTH(`timestamp`) AS m_num,
                DATE_FORMAT(`timestamp`, '%Y-%m') AS m_key,
                DATE_FORMAT(`timestamp`, '%b') AS m_name,
                DATE_FORMAT(`timestamp`, '%M') AS m_full_name,
                COUNT(CASE WHEN status = 'Completed' THEN 1 END) AS orders,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END), 0) AS total,
                COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'Cash' THEN total_amount ELSE 0 END), 0) AS cash,
                COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_method = 'GCash' THEN total_amount ELSE 0 END), 0) AS gcash,
                COUNT(DISTINCT CASE WHEN status = 'Completed' THEN DATE(`timestamp`) END) AS active_days
           FROM transactions
          WHERE `timestamp` >= :start AND `timestamp` < :end {$mockFilterSql}
          GROUP BY m_num, m_key, m_name, m_full_name
          ORDER BY m_num ASC"
    );
    $mStmt->execute([':start' => $startStr, ':end' => $endStr]);
    $monthRows = [];
    foreach ($mStmt->fetchAll() as $row) {
        $monthRows[(int)$row['m_num']] = $row;
    }

    $prevRevenue = null;
    $maxMonthRev = -1.0;
    $minMonthRev = PHP_FLOAT_MAX;

    for ($m = 1; $m <= 12; $m++) {
        $dtM = DateTimeImmutable::createFromFormat('!Y-n', sprintf('%04d-%d', $rawYear, $m), $tz);
        $mKey = sprintf('%04d-%02d', $rawYear, $m);
        $mName = $dtM->format('M');
        $mFullName = $dtM->format('F');
        $daysInMonth = (int) $dtM->format('t');

        $row = $monthRows[$m] ?? null;
        $orders = $row ? (int)$row['orders'] : 0;
        $rev = $row ? (float)$row['total'] : 0.0;
        $cash = $row ? (float)$row['cash'] : 0.0;
        $gcash = $row ? (float)$row['gcash'] : 0.0;
        $activeDays = $row ? (int)$row['active_days'] : 0;

        $momGrowth = null;
        if ($prevRevenue !== null && $prevRevenue > 0) {
            $momGrowth = round((($rev - $prevRevenue) / $prevRevenue) * 100, 1);
        }

        $mEntry = [
            'month_num'          => $m,
            'month'              => $mKey,
            'month_name'         => $mName,
            'month_full'         => $mFullName,
            'days_in_month'      => $daysInMonth,
            'operating_days'     => $activeDays,
            'orders'             => $orders,
            'total'              => $rev,
            'cash'               => $cash,
            'gcash'              => $gcash,
            'avg_daily_revenue'  => $activeDays > 0 ? round($rev / $activeDays, 2) : 0.0,
            'aov'                => $orders > 0 ? round($rev / $orders, 2) : 0.0,
            'mom_growth_pct'     => $momGrowth,
        ];
        $monthlyBreakdown[] = $mEntry;

        if ($orders > 0) {
            if ($rev > $maxMonthRev) {
                $maxMonthRev = $rev;
                $bestMonth = ['month' => $mKey, 'name' => $mFullName, 'total' => $rev, 'orders' => $orders];
            }
            if ($rev < $minMonthRev) {
                $minMonthRev = $rev;
                $lowestMonth = ['month' => $mKey, 'name' => $mFullName, 'total' => $rev, 'orders' => $orders];
            }
        }
        $prevRevenue = $rev;
    }

    // Best day from $days
    if (!empty($days)) {
        $bestDayVal = 0.0;
        foreach ($days as $d) {
            $dTot = (float)$d['total'];
            if ($dTot > $bestDayVal) {
                $bestDayVal = $dTot;
                $bestDay = [
                    'date'   => $d['sale_date'],
                    'total'  => $dTot,
                    'orders' => (int)$d['orders'],
                ];
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Product & Cup Size Variant Performance (Yearly)
// -----------------------------------------------------------------------------
$productPerformance = [];
if ($rangeType === 'year') {
    $pStmt = $pdo->prepare(
        "SELECT COALESCE(p.name, ti.product_name) AS name,
                COALESCE(p.category, 'Other')    AS category,
                CASE 
                    WHEN LOWER(COALESCE(p.category, '')) IN ('coffee', 'milk series', 'milk tea', 'fruit tea / lemonade') 
                         OR LOWER(COALESCE(p.category, '')) LIKE '%drink%'
                         OR LOWER(COALESCE(p.category, '')) LIKE '%coffee%'
                         OR LOWER(COALESCE(p.category, '')) LIKE '%tea%'
                         OR LOWER(COALESCE(p.category, '')) LIKE '%beverage%'
                         OR LOWER(COALESCE(p.category, '')) LIKE '%lemonade%'
                         OR LOWER(COALESCE(p.category, '')) LIKE '%milk%'
                    THEN 'drinks'
                    ELSE 'snacks'
                END AS macro_category,
                COALESCE(NULLIF(ti.size_code, ''), 'Standard') AS size_code,
                COALESCE(NULLIF(ti.size_label, ''), 'Standard') AS size_label,
                COALESCE(ti.size_oz, 0) AS size_oz,
                SUM(ti.quantity) AS qty,
                COALESCE(SUM(ti.unit_price * ti.quantity), 0) AS revenue,
                ROUND(AVG(ti.unit_price), 2) AS avg_unit_price
           FROM transaction_items ti
           JOIN transactions t   ON t.transaction_id = ti.transaction_id
           LEFT JOIN menu_products p ON p.product_id = ti.product_id
          WHERE t.`timestamp` >= :start AND t.`timestamp` < :end AND t.status = 'Completed' {$mockFilterTql}
          GROUP BY name, category, macro_category, size_code, size_label, size_oz
          ORDER BY qty DESC, revenue DESC, name ASC"
    );
    $pStmt->execute([':start' => $startStr, ':end' => $endStr]);
    $totalYearItemsRevenue = max(1.0, (float)($s['total'] ?? 1.0));
    foreach ($pStmt->fetchAll() as $row) {
        $pRev = (float)$row['revenue'];
        $productPerformance[] = [
            'name'             => $row['name'],
            'category'         => $row['category'],
            'macro_category'   => $row['macro_category'],
            'size_code'        => $row['size_code'],
            'size_label'       => $row['size_label'],
            'size_oz'          => (int)$row['size_oz'],
            'qty'              => (int)$row['qty'],
            'revenue'          => $pRev,
            'avg_unit_price'   => (float)$row['avg_unit_price'],
            'revenue_share_pct'=> round(($pRev / $totalYearItemsRevenue) * 100, 2),
        ];
    }
}

// -----------------------------------------------------------------------------
// Data Health & Completeness
// -----------------------------------------------------------------------------
$operatingDays = count($days);
$totalCalendarDays = ($rangeType === 'year') ? ((int)$dtStart->format('L') ? 366 : 365) : max(1, count($days));
$firstDate = !empty($days) ? end($days)['sale_date'] : null;
$lastDate  = !empty($days) ? $days[0]['sale_date'] : null;

$dataHealth = [
    'dataset_type'        => $datasetType,
    'is_mock'             => $mockOrders > 0,
    'mock_orders'         => $mockOrders,
    'real_orders'         => $realOrders,
    'operating_days'      => $operatingDays,
    'total_calendar_days' => $totalCalendarDays,
    'coverage_pct'        => round(($operatingDays / max(1, $totalCalendarDays)) * 100, 1),
    'first_date'          => $firstDate,
    'last_date'           => $lastDate,
];

$totSales    = (float) $s['total'];
$totOrders   = (int) $s['orders'];
$aov         = $totOrders > 0 ? round($totSales / $totOrders, 2) : 0.0;
$avgDailyRev = $operatingDays > 0 ? round($totSales / $operatingDays, 2) : 0.0;

// -----------------------------------------------------------------------------
// Hourly breakdown

$hours = [];
if ($rangeType === 'date' || $rangeType === 'today' || $startDateStr === $endDateStr) {
    $stmt = $pdo->prepare(
        "SELECT HOUR(`timestamp`) AS sale_hour,
                COUNT(*)          AS orders,
                COALESCE(SUM(total_amount), 0) AS total
           FROM transactions
          WHERE `timestamp` >= :start AND `timestamp` < :end AND status = 'Completed' {$mockFilterSql}
          GROUP BY sale_hour
          ORDER BY sale_hour ASC"
    );
    $stmt->execute([':start' => $startStr, ':end' => $endStr]);
    $hours = $stmt->fetchAll();
}

// -----------------------------------------------------------------------------
// Output HTML (Printable view) or JSON (Standard API)
// -----------------------------------------------------------------------------
if ($format === 'html') {
    $moneyFmt = static fn(float $n): string => '₱' . number_format($n, 2);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Sales Report · <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> · DK Cafe</title>
      <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 30px; color: #2c1a0e; background: #faf6f0; }
        .wrap { max-width: 800px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        h1 { margin: 0 0 6px; font-size: 24px; }
        .meta { color: #7e6a59; margin-bottom: 20px; font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .card { padding: 12px; background: #fdf3e5; border-radius: 8px; border: 1px solid #e8dece; }
        .card.big { background: #2c1a0e; color: #fff; }
        .card span { font-size: 12px; display: block; opacity: .8; text-transform: uppercase; font-weight: 700; }
        .card strong { font-size: 22px; display: block; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #e6ddd2; text-align: left; }
        th { font-size: 12px; text-transform: uppercase; color: #7e6a59; }
        .num { text-align: right; }
        .print-btn { padding: 8px 16px; background: #c07c2e; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-weight: 600; margin-bottom: 16px; }
        @media print { .print-btn { display: none; } body { margin: 0; background: #fff; } .wrap { box-shadow: none; border: 0; } }
      </style>
    </head>
    <body>
      <div class="wrap">
        <div style="display: flex; gap: 8px; margin-bottom: 16px;">
          <a class="print-btn" style="text-decoration:none; margin-bottom: 0; background: #c07c2e;" href="export_excel.php?start=<?= urlencode($startDateStr) ?>&end=<?= urlencode($endDateStr) ?>">📥 Export Excel (.xlsx)</a>
        </div>
        <h1>DK Cafe · Sales Report</h1>
        <div class="meta"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($startDateStr, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($endDateStr, ENT_QUOTES, 'UTF-8') ?>)</div>
        
        <div class="grid">
          <div class="card big"><span>Total Sales</span><strong><?= $moneyFmt((float)$s['total']) ?></strong></div>
          <div class="card"><span>Orders</span><strong><?= (int)$s['orders'] ?></strong></div>
          <div class="card"><span>Average Order</span><strong><?= $s['orders'] ? $moneyFmt((float)$s['total'] / (int)$s['orders']) : '₱0.00' ?></strong></div>
          <div class="card"><span>Cash</span><strong><?= $moneyFmt((float)$s['cash']) ?></strong></div>
          <div class="card"><span>GCash</span><strong><?= $moneyFmt((float)$s['gcash']) ?></strong></div>
          <div class="card"><span>Pending Orders</span><strong><?= (int)$s['pending'] ?></strong></div>
        </div>

        <h2>Top Selling Items</h2>
        <table>
          <thead><tr><th>Item</th><th class="num">Quantity</th></tr></thead>
          <tbody>
            <?php if (empty($top)): ?>
              <tr><td colspan="2">No sales in this period.</td></tr>
            <?php else: ?>
              <?php foreach (array_slice($top, 0, 15) as $t): ?>
                <tr><td><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></td><td class="num"><?= (int)$t['qty'] ?></td></tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <?php if (!empty($days)): ?>
          <h2 style="margin-top: 24px;">Daily Sales</h2>
          <table>
            <thead><tr><th>Date</th><th class="num">Orders</th><th class="num">Cash</th><th class="num">GCash</th><th class="num">Total</th></tr></thead>
            <tbody>
              <?php foreach ($days as $d): ?>
                <tr>
                  <td><?= htmlspecialchars($d['sale_date'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="num"><?= (int)$d['orders'] ?></td>
                  <td class="num"><?= $moneyFmt((float)$d['cash']) ?></td>
                  <td class="num"><?= $moneyFmt((float)$d['gcash']) ?></td>
                  <td class="num"><?= $moneyFmt((float)$d['total']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </body>
    </html>
    <?php
    exit;
}

send_json([
    'success'    => true,
    'range'      => $rangeType,
    'label'      => $label,
    'start_date' => $startDateStr,
    'end_date'   => $endDateStr,
    'year'       => ($rangeType === 'year' ? $rawYear : null),
    'available_years' => $availableYears,
    'summary'    => [
        'orders'            => $totOrders,
        'total'             => $totSales,
        'cash'              => (float) $s['cash'],
        'gcash'             => (float) $s['gcash'],
        'pending'           => (int) $s['pending'],
        'cancelled'         => (int) $s['cancelled'],
        'voided'            => (int) $s['voided'],
        'real_orders'       => $realOrders,
        'mock_orders'       => $mockOrders,
        'real_total'        => (float) ($s['real_total'] ?? 0),
        'mock_total'        => (float) ($s['mock_total'] ?? 0),
        'include_mock'      => !$filterOnlyReal,
        'aov'               => $aov,
        'avg_daily_revenue' => $avgDailyRev,
        'best_month'        => $bestMonth,
        'lowest_month'      => $lowestMonth,
        'best_day'          => $bestDay,
    ],
    'data_health' => $dataHealth,
    'monthly_breakdown' => $monthlyBreakdown,
    'product_performance' => $productPerformance,
    'top_items'  => array_map(static fn(array $r): array => [
        'name'           => $r['name'],
        'category'       => $r['category'],
        'macro_category' => $r['macro_category'],
        'qty'            => (int) $r['qty'],
        'revenue'        => (float) $r['revenue'],
    ], $top),
    'category_summary' => [
        'drinks_qty'      => $drinksQty,
        'drinks_revenue'  => $drinksRev,
        'snacks_qty'      => $snacksQty,
        'snacks_revenue'  => $snacksRev,
        'total_qty'       => $totalItemsQty,
        'total_revenue'   => $totalItemsRev,
    ],
    'by_day'     => array_map(static fn(array $r): array => [
        'day'    => $r['sale_date'],
        'orders' => (int) $r['orders'],
        'total'  => (float) $r['total'],
        'cash'   => (float) ($r['cash'] ?? 0),
        'gcash'  => (float) ($r['gcash'] ?? 0),
    ], $days),
    'by_hour'    => array_map(static fn(array $r): array => [
        'hour'   => (int) $r['sale_hour'],
        'orders' => (int) $r['orders'],
        'total'  => (float) $r['total'],
    ], $hours),
    'by_category' => array_map(static fn(array $r): array => [
        'category' => $r['category'],
        'qty'      => (int) $r['qty'],
        'revenue'  => (float) $r['revenue'],
    ], $categories),
    'waste_summary' => [
        'events_count'            => (int)($wSum['total_waste_events'] ?? 0),
        'cancelled_orders'        => (int)($cLoss['cancelled_orders'] ?? 0),
        'total_income_loss_php'   => (float)($cLoss['income_loss_php'] ?? 0.0),
        'total_material_cost_php' => (float)($wSum['total_material_cost_php'] ?? 0.0),
        'total_material_cost_cents' => (int)($wSum['total_material_cost_cents'] ?? 0),
        'total_wasted_g'          => (float)($wSum['total_wasted_g'] ?? 0.0),
        'total_wasted_kg'         => round(((float)($wSum['total_wasted_g'] ?? 0.0)) / 1000, 2),
        'total_wasted_ml'         => (float)($wSum['total_wasted_ml'] ?? 0.0),
        'total_wasted_pcs'        => (float)($wSum['total_wasted_pcs'] ?? 0.0),
        'trend'                   => $wasteTrend,
    ],
]);
