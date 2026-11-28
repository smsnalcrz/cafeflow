<?php
/**
 * CafeFlow - Waste & Loss Tracking API
 *
 * GET api/waste_api.php
 *
 * Supported query parameters:
 * - `period`: 'hourly', 'daily', 'weekly', 'monthly', 'yearly' (default 'daily')
 * - `date`: YYYY-MM-DD for specific date (hourly/daily)
 * - `start`: YYYY-MM-DD
 * - `end`: YYYY-MM-DD
 * - `week`: YYYY-MM-DD (start of 7-day period)
 * - `month`: YYYY-MM (specific month)
 * - `year`: YYYY (e.g. 2026)
 * - `product_id`: filter by product ID
 * - `item_id`: filter by raw inventory item ID
 * - `user_id`: filter by cashier/user ID who cancelled
 * - `search`: text query across product, item, reason, receipt
 * - `limit`: maximum waste log events returned (default 100, max 500)
 *
 * RBAC: Accessible to Manager and Barista roles (HTTP 401 for unauthenticated, HTTP 403 for unauthorized).
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/recipe_engine.php';

// RBAC: Waste reports accessible to Manager and Barista roles
$user = require_role(['barista', 'manager']);
release_session_lock();

require_method('GET');

$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);

$period     = strtolower(trim((string)($_GET['period'] ?? 'daily')));
$dateParam  = $_GET['date']  ?? null;
$weekParam  = $_GET['week']  ?? null;
$monthParam = $_GET['month'] ?? null;
$yearParam  = $_GET['year']  ?? null;
$startParam = $_GET['start'] ?? null;
$endParam   = $_GET['end']   ?? null;
$productId  = as_int($_GET['product_id'] ?? null, 1, 4294967295);
$itemId     = as_int($_GET['item_id'] ?? null, 1, 4294967295);
$userId     = as_int($_GET['user_id'] ?? null, 1, 4294967295);
$search     = trim((string)($_GET['search'] ?? ''));
$limit      = as_int($_GET['limit'] ?? 100, 1, 500) ?? 100;
$offset     = as_int($_GET['offset'] ?? 0, 0, 100000) ?? 0;

$parseDate = static function (?string $str, DateTimeZone $tz): ?DateTimeImmutable {
    if (!$str || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $str, $tz);
    return ($dt && $dt->format('Y-m-d') === $str) ? $dt : null;
};

// -----------------------------------------------------------------------------
// Resolve Date Window based on Period
// -----------------------------------------------------------------------------
$periodLabel = '';
$startDateStr = '';
$endDateStr = '';

if ($period === 'yearly' || ($yearParam && preg_match('/^\d{4}$/', (string)$yearParam) && !$monthParam && $period !== 'monthly')) {
    $period = 'yearly';
    $rawYear = (int) ($yearParam ?? $now->format('Y'));
    if ($rawYear < 2000 || $rawYear > 2100) {
        fail(400, 'Invalid year. Must be between 2000 and 2100.');
    }
    $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-01-01', $rawYear), $tz);
    $dtEnd   = $dtStart->modify('+1 year');
    $lastDay = $dtEnd->modify('-1 day');

    $periodLabel  = sprintf('Year %04d (Annual Waste Analytics)', $rawYear);
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
} elseif ($period === 'monthly' || $monthParam) {
    $period = 'monthly';
    $rawMonth = $monthParam ?? $dateParam ?? $startParam;
    if ($rawMonth && preg_match('/^(\d{4})-(\d{2})/', (string)$rawMonth, $m)) {
        $rawYear = (int)$m[1];
        $rawMonthNum = (int)$m[2];
        if ($rawYear < 2000 || $rawYear > 2100 || $rawMonthNum < 1 || $rawMonthNum > 12) {
            fail(400, 'Invalid month. Format must be YYYY-MM.');
        }
        $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $rawYear, $rawMonthNum), $tz);
    } else {
        $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', $now->format('Y-m-01'), $tz);
    }
    $dtEnd   = $dtStart->modify('+1 month');
    $lastDay = $dtEnd->modify('-1 day');

    $periodLabel  = $dtStart->format('F Y') . ' (Monthly Waste Audit)';
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
} elseif ($period === 'weekly' || $weekParam) {
    $period = 'weekly';
    $rawWeek = $weekParam ?? $startParam ?? $dateParam;
    if ($rawWeek) {
        $dtStart = $parseDate($rawWeek, $tz);
        if (!$dtStart) {
            fail(400, 'Invalid week start date format. Use YYYY-MM-DD.');
        }
    } else {
        $dtStart = (new DateTimeImmutable('today', $tz))->modify('-6 days');
    }
    $dtEnd   = $dtStart->modify('+7 days');
    $lastDay = $dtStart->modify('+6 days');

    $periodLabel  = $dtStart->format('M j, Y') . ' – ' . $lastDay->format('M j, Y') . ' (7-Day Waste Window)';
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
} elseif ($period === 'hourly') {
    $rawDate = $dateParam ?? $startParam;
    if ($rawDate) {
        $dtStart = $parseDate($rawDate, $tz);
        if (!$dtStart) {
            fail(400, 'Invalid date format for hourly breakdown. Use YYYY-MM-DD.');
        }
    } else {
        $dtStart = new DateTimeImmutable('today', $tz);
    }
    $dtEnd   = $dtStart->modify('+1 day');
    $lastDay = $dtStart;

    $periodLabel  = $dtStart->format('F j, Y') . ' (Hourly Waste Distribution)';
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
} else {
    // Default: daily view
    $period = 'daily';
    $rawDate = $dateParam ?? $startParam;
    if ($rawDate) {
        $dtStart = $parseDate($rawDate, $tz);
        if (!$dtStart) {
            fail(400, 'Invalid date format. Use YYYY-MM-DD.');
        }
    } else {
        $dtStart = new DateTimeImmutable('today', $tz);
    }
    $dtEnd   = $dtStart->modify('+1 day');
    $lastDay = $dtStart;

    $periodLabel  = $dtStart->format('F j, Y') . ' (Daily Waste Summary)';
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
}

$startStr = $dtStart->format('Y-m-d 00:00:00');
$endStr   = $dtEnd->format('Y-m-d 00:00:00');

$pdo = db();

// -----------------------------------------------------------------------------
// Base WHERE filters for waste_log
// -----------------------------------------------------------------------------
$wasteWhere = ['wl.wasted_at >= :start', 'wl.wasted_at < :end'];
$wasteParams = [':start' => $startStr, ':end' => $endStr];

if ($productId !== null) {
    $wasteWhere[] = 'wl.product_id = :product_id';
    $wasteParams[':product_id'] = $productId;
}
if ($itemId !== null) {
    $wasteWhere[] = 'wl.item_id = :item_id';
    $wasteParams[':item_id'] = $itemId;
}
if ($userId !== null) {
    $wasteWhere[] = 'wl.wasted_by_user_id = :user_id';
    $wasteParams[':user_id'] = $userId;
}
if ($search !== '') {
    $exactId = (int) preg_replace('/\D/', '', $search);
    if ($exactId > 0) {
        $wasteWhere[] = '(wl.product_name LIKE :search OR wl.item_name LIKE :search OR wl.reason LIKE :search OR wl.transaction_id = :search_exact)';
        $wasteParams[':search_exact'] = $exactId;
    } else {
        $wasteWhere[] = '(wl.product_name LIKE :search OR wl.item_name LIKE :search OR wl.reason LIKE :search)';
    }
    $wasteParams[':search'] = '%' . $search . '%';
}

$wasteWhereSql = implode(' AND ', $wasteWhere);

// -----------------------------------------------------------------------------
// 1. Overall Summary Totals (Material Cost & Units Wasted)
// -----------------------------------------------------------------------------
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT wl.waste_id)        AS total_waste_events,
        COUNT(DISTINCT wl.transaction_id) AS distinct_cancelled_orders,
        COALESCE(SUM(wl.cost_cents), 0)    AS total_material_cost_cents,
        COALESCE(SUM(wl.estimated_cost_php), 0.00) AS total_material_cost_php,
        COALESCE(SUM(CASE WHEN wl.unit = 'g' THEN wl.amount_wasted ELSE 0 END), 0.00)   AS total_wasted_g,
        COALESCE(SUM(CASE WHEN wl.unit = 'ml' THEN wl.amount_wasted ELSE 0 END), 0.00)  AS total_wasted_ml,
        COALESCE(SUM(CASE WHEN wl.unit = 'pcs' THEN wl.amount_wasted ELSE 0 END), 0.00) AS total_wasted_pcs
    FROM waste_log wl
    WHERE {$wasteWhereSql}
");
$summaryStmt->execute($wasteParams);
$wasteSum = $summaryStmt->fetch() ?: [
    'total_waste_events' => 0,
    'distinct_cancelled_orders' => 0,
    'total_material_cost_cents' => 0,
    'total_material_cost_php' => 0.0,
    'total_wasted_g' => 0.0,
    'total_wasted_ml' => 0.0,
    'total_wasted_pcs' => 0.0,
];

// -----------------------------------------------------------------------------
// 2. Income Loss Totals (Lost Gross Sales from Cancelled Transactions)
// -----------------------------------------------------------------------------
$includeMockParam = $_GET['include_mock'] ?? null;
$includeMock = ($includeMockParam !== '0' && $includeMockParam !== 'false');

$txWhere = ['t.`timestamp` >= :start', 't.`timestamp` < :end', "t.status IN ('Cancelled', 'Voided')"];
if (!$includeMock) {
    $txWhere[] = '(t.is_mock = 0 OR t.is_mock IS NULL)';
}
$txParams = [':start' => $startStr, ':end' => $endStr];

if ($userId !== null) {
    $txWhere[] = 't.cancelled_by_user_id = :tx_user_id';
    $txParams[':tx_user_id'] = $userId;
}
$txWhereSql = implode(' AND ', $txWhere);

$incomeStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT t.transaction_id) AS total_cancelled_tx,
        COALESCE(SUM(t.total_amount), 0.00) AS total_income_loss_php,
        COALESCE(CAST(ROUND(SUM(t.total_amount * 100)) AS SIGNED), 0) AS total_income_loss_cents
    FROM transactions t
    WHERE {$txWhereSql}
");
$incomeStmt->execute($txParams);
$incomeSum = $incomeStmt->fetch() ?: [
    'total_cancelled_tx' => 0,
    'total_income_loss_php' => 0.0,
    'total_income_loss_cents' => 0,
];

// -----------------------------------------------------------------------------
// 3. Material Waste Summary (Grouped by Raw Inventory Supply Item)
// -----------------------------------------------------------------------------
$itemsStmt = $pdo->prepare("
    SELECT
        wl.item_id,
        wl.item_name,
        wl.unit,
        ROUND(SUM(wl.amount_wasted), 2) AS total_amount_wasted,
        COUNT(DISTINCT wl.waste_id)    AS events_count,
        COUNT(DISTINCT wl.transaction_id) AS orders_count,
        COALESCE(SUM(wl.cost_cents), 0) AS cost_cents,
        COALESCE(SUM(wl.estimated_cost_php), 0.00) AS estimated_cost_php
    FROM waste_log wl
    WHERE {$wasteWhereSql}
    GROUP BY wl.item_id, wl.item_name, wl.unit
    ORDER BY estimated_cost_php DESC, total_amount_wasted DESC
");
$itemsStmt->execute($wasteParams);
$itemsSummary = $itemsStmt->fetchAll();

// -----------------------------------------------------------------------------
// 4. Income Loss by Product (Which products were cancelled the most)
// -----------------------------------------------------------------------------
$prodLossStmt = $pdo->prepare("
    SELECT
        ti.product_id,
        COALESCE(mp.name, ti.product_name, 'Unknown') AS product_name,
        COALESCE(mp.category, 'General')               AS category,
        SUM(ti.quantity)                               AS qty_cancelled,
        COUNT(DISTINCT t.transaction_id)               AS orders_count,
        COALESCE(SUM(ti.unit_price * ti.quantity), 0.00) AS income_loss_php,
        COALESCE(CAST(ROUND(SUM(ti.unit_price * ti.quantity * 100)) AS SIGNED), 0) AS income_loss_cents
    FROM transaction_items ti
    JOIN transactions t ON t.transaction_id = ti.transaction_id
    LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
    WHERE {$txWhereSql}
    GROUP BY ti.product_id, product_name, category
    ORDER BY income_loss_php DESC, qty_cancelled DESC
");
$prodLossStmt->execute($txParams);
$productLoss = $prodLossStmt->fetchAll();

// -----------------------------------------------------------------------------
// 5. Chart Time-Series Datasets (Trend over Time)
// -----------------------------------------------------------------------------
$chartLabels = [];
$chartMaterialCosts = [];
$chartIncomeLosses = [];
$chartWasteEvents = [];

if ($period === 'hourly') {
    // 24-hour breakdown (00:00 to 23:00)
    $wasteHourlyStmt = $pdo->prepare("
        SELECT
            HOUR(wasted_at) AS hr,
            COUNT(DISTINCT waste_id) AS events,
            COALESCE(SUM(estimated_cost_php), 0.00) AS cost_php
        FROM waste_log wl
        WHERE {$wasteWhereSql}
        GROUP BY hr
    ");
    $wasteHourlyStmt->execute($wasteParams);
    $wHourRows = $wasteHourlyStmt->fetchAll();
    $wHourMap = [];
    foreach ($wHourRows as $r) {
        $wHourMap[(int)$r['hr']] = $r;
    }

    $lossHourlyStmt = $pdo->prepare("
        SELECT
            HOUR(`timestamp`) AS hr,
            COALESCE(SUM(total_amount), 0.00) AS income_loss_php
        FROM transactions t
        WHERE {$txWhereSql}
        GROUP BY hr
    ");
    $lossHourlyStmt->execute($txParams);
    $lHourRows = $lossHourlyStmt->fetchAll();
    $lHourMap = [];
    foreach ($lHourRows as $r) {
        $lHourMap[(int)$r['hr']] = $r;
    }

    for ($h = 0; $h < 24; $h++) {
        $chartLabels[] = sprintf('%02d:00', $h);
        $chartWasteEvents[] = isset($wHourMap[$h]) ? (int)$wHourMap[$h]['events'] : 0;
        $chartMaterialCosts[] = isset($wHourMap[$h]) ? (float)$wHourMap[$h]['cost_php'] : 0.0;
        $chartIncomeLosses[] = isset($lHourMap[$h]) ? (float)$lHourMap[$h]['income_loss_php'] : 0.0;
    }
} elseif ($period === 'yearly') {
    // 12-month breakdown (Jan to Dec)
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $wasteMonthlyStmt = $pdo->prepare("
        SELECT
            MONTH(wasted_at) AS mo,
            COUNT(DISTINCT waste_id) AS events,
            COALESCE(SUM(estimated_cost_php), 0.00) AS cost_php
        FROM waste_log wl
        WHERE {$wasteWhereSql}
        GROUP BY mo
    ");
    $wasteMonthlyStmt->execute($wasteParams);
    $wMoRows = $wasteMonthlyStmt->fetchAll();
    $wMoMap = [];
    foreach ($wMoRows as $r) {
        $wMoMap[(int)$r['mo']] = $r;
    }

    $lossMonthlyStmt = $pdo->prepare("
        SELECT
            MONTH(`timestamp`) AS mo,
            COALESCE(SUM(total_amount), 0.00) AS income_loss_php
        FROM transactions t
        WHERE {$txWhereSql}
        GROUP BY mo
    ");
    $lossMonthlyStmt->execute($txParams);
    $lMoRows = $lossMonthlyStmt->fetchAll();
    $lMoMap = [];
    foreach ($lMoRows as $r) {
        $lMoMap[(int)$r['mo']] = $r;
    }

    for ($m = 1; $m <= 12; $m++) {
        $chartLabels[] = $monthNames[$m - 1];
        $chartWasteEvents[] = isset($wMoMap[$m]) ? (int)$wMoMap[$m]['events'] : 0;
        $chartMaterialCosts[] = isset($wMoMap[$m]) ? (float)$wMoMap[$m]['cost_php'] : 0.0;
        $chartIncomeLosses[] = isset($lMoMap[$m]) ? (float)$lMoMap[$m]['income_loss_php'] : 0.0;
    }
} else {
    // Daily or Weekly breakdown (Day-by-day)
    $wasteDailyStmt = $pdo->prepare("
        SELECT
            DATE(wasted_at) AS dt,
            COUNT(DISTINCT waste_id) AS events,
            COALESCE(SUM(estimated_cost_php), 0.00) AS cost_php
        FROM waste_log wl
        WHERE {$wasteWhereSql}
        GROUP BY dt
    ");
    $wasteDailyStmt->execute($wasteParams);
    $wDayRows = $wasteDailyStmt->fetchAll();
    $wDayMap = [];
    foreach ($wDayRows as $r) {
        $wDayMap[$r['dt']] = $r;
    }

    $lossDailyStmt = $pdo->prepare("
        SELECT
            DATE(`timestamp`) AS dt,
            COALESCE(SUM(total_amount), 0.00) AS income_loss_php
        FROM transactions t
        WHERE {$txWhereSql}
        GROUP BY dt
    ");
    $lossDailyStmt->execute($txParams);
    $lDayRows = $lossDailyStmt->fetchAll();
    $lDayMap = [];
    foreach ($lDayRows as $r) {
        $lDayMap[$r['dt']] = $r;
    }

    // Iterate through all calendar days in range
    $cur = $dtStart;
    while ($cur < $dtEnd) {
        $dKey = $cur->format('Y-m-d');
        $chartLabels[] = $cur->format('M j');
        $chartWasteEvents[] = isset($wDayMap[$dKey]) ? (int)$wDayMap[$dKey]['events'] : 0;
        $chartMaterialCosts[] = isset($wDayMap[$dKey]) ? (float)$wDayMap[$dKey]['cost_php'] : 0.0;
        $chartIncomeLosses[] = isset($lDayMap[$dKey]) ? (float)$lDayMap[$dKey]['income_loss_php'] : 0.0;
        $cur = $cur->modify('+1 day');
    }
}

// -----------------------------------------------------------------------------
// 6. Granular Waste Log Events (with pagination & user details)
// -----------------------------------------------------------------------------
$logsCountStmt = $pdo->prepare("
    SELECT COUNT(*) FROM waste_log wl WHERE {$wasteWhereSql}
");
$logsCountStmt->execute($wasteParams);
$totalLogsCount = (int) $logsCountStmt->fetchColumn();

$logsStmt = $pdo->prepare("
    SELECT
        wl.waste_id,
        wl.transaction_id,
        wl.product_id,
        wl.product_name,
        wl.size_code,
        wl.size_label,
        wl.quantity,
        wl.item_id,
        wl.item_name,
        wl.unit,
        wl.amount_wasted,
        wl.cost_cents,
        wl.estimated_cost_php,
        wl.wasted_by_user_id,
        COALESCE(u.full_name, u.username, 'System') AS wasted_by_name,
        u.role AS wasted_by_role,
        wl.reason,
        wl.wasted_at
    FROM waste_log wl
    LEFT JOIN users u ON u.user_id = wl.wasted_by_user_id
    WHERE {$wasteWhereSql}
    ORDER BY wl.wasted_at DESC, wl.waste_id DESC
    LIMIT :lim OFFSET :off
");
foreach ($wasteParams as $paramK => $paramV) {
    $logsStmt->bindValue($paramK, $paramV);
}
$logsStmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
$logsStmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

// Format logs for frontend presentation
$formattedLogs = array_map(static function(array $row): array {
    $ts = strtotime($row['wasted_at']);
    $hasTx = ($row['transaction_id'] !== null && (int)$row['transaction_id'] > 0);
    $txId = $hasTx ? (int)$row['transaction_id'] : null;
    $receiptNo = $hasTx ? ('#' . str_pad((string)$txId, 5, '0', STR_PAD_LEFT)) : 'Manual';
    $productName = $row['product_name'] ?: ($hasTx ? 'Product' : '—');
    $sizeLabel = $row['size_label'] ?: ($hasTx ? 'Standard' : '—');

    return [
        'waste_id'           => (int)$row['waste_id'],
        'transaction_id'     => $txId,
        'receipt_no'         => $receiptNo,
        'is_manual'          => !$hasTx,
        'product_id'         => $row['product_id'] !== null ? (int)$row['product_id'] : null,
        'product_name'       => $productName,
        'size_code'          => $row['size_code'],
        'size_label'         => $sizeLabel,
        'quantity'           => (int)$row['quantity'],
        'item_id'            => $row['item_id'] !== null ? (int)$row['item_id'] : null,
        'item_name'          => $row['item_name'],
        'unit'               => $row['unit'],
        'amount_wasted'      => (float)$row['amount_wasted'],
        'formatted_amount'   => number_format((float)$row['amount_wasted'], 2) . ' ' . $row['unit'],
        'cost_cents'         => (int)$row['cost_cents'],
        'estimated_cost_php' => (float)$row['estimated_cost_php'],
        'formatted_cost'     => '₱' . number_format((float)$row['estimated_cost_php'], 2),
        'wasted_by_user_id'  => $row['wasted_by_user_id'] !== null ? (int)$row['wasted_by_user_id'] : null,
        'wasted_by_name'     => $row['wasted_by_name'],
        'wasted_by_role'     => $row['wasted_by_role'] ? ucfirst($row['wasted_by_role']) : 'Staff',
        'reason'             => $row['reason'],
        'wasted_at'          => $row['wasted_at'],
        'formatted_time'     => date('h:i A', $ts),
        'formatted_date'     => date('M j, Y', $ts),
        'formatted_datetime' => date('M j, Y h:i A', $ts),
    ];
}, $logs);

// Top 5 materials for quick bar/donut charts
$topMaterials = array_slice($itemsSummary, 0, 7);

// Available recorded waste years for year picker
$yearsStmt = $pdo->query("
    SELECT DISTINCT YEAR(wasted_at) AS yr FROM waste_log ORDER BY yr DESC
");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [(int)$now->format('Y')];

send_json([
    'success' => true,
    'period'  => $period,
    'label'   => $periodLabel,
    'month'   => $dtStart->format('Y-m'),
    'start_date' => $startDateStr,
    'end_date'   => $endDateStr,
    'summary' => [
        'total_waste_events'         => (int)$wasteSum['total_waste_events'],
        'total_cancelled_orders'     => (int)$incomeSum['total_cancelled_tx'],
        'total_income_loss_php'      => (float)$incomeSum['total_income_loss_php'],
        'total_income_loss_cents'    => (int)$incomeSum['total_income_loss_cents'],
        'total_material_cost_php'    => (float)$wasteSum['total_material_cost_php'],
        'total_material_cost_cents'  => (int)$wasteSum['total_material_cost_cents'],
        'total_wasted_g'             => (float)$wasteSum['total_wasted_g'],
        'total_wasted_kg'            => round(((float)$wasteSum['total_wasted_g']) / 1000, 2),
        'total_wasted_ml'            => (float)$wasteSum['total_wasted_ml'],
        'total_wasted_l'             => round(((float)$wasteSum['total_wasted_ml']) / 1000, 2),
        'total_wasted_pcs'           => (float)$wasteSum['total_wasted_pcs'],
    ],
    'materials_summary' => array_map(static fn($m): array => [
        'item_id'            => (int)$m['item_id'],
        'item_name'          => $m['item_name'],
        'unit'               => $m['unit'],
        'total_amount'       => (float)$m['total_amount_wasted'],
        'formatted_amount'   => number_format((float)$m['total_amount_wasted'], 2) . ' ' . $m['unit'],
        'events_count'       => (int)$m['events_count'],
        'orders_count'       => (int)$m['orders_count'],
        'cost_cents'         => (int)$m['cost_cents'],
        'estimated_cost_php' => (float)$m['estimated_cost_php'],
        'formatted_cost'     => '₱' . number_format((float)$m['estimated_cost_php'], 2),
    ], $itemsSummary),
    'income_loss_by_product' => array_map(static fn($p): array => [
        'product_id'         => $p['product_id'] !== null ? (int)$p['product_id'] : null,
        'product_name'       => $p['product_name'],
        'category'           => $p['category'],
        'qty_cancelled'      => (int)$p['qty_cancelled'],
        'orders_count'       => (int)$p['orders_count'],
        'income_loss_php'    => (float)$p['income_loss_php'],
        'income_loss_cents'  => (int)$p['income_loss_cents'],
        'formatted_loss'     => '₱' . number_format((float)$p['income_loss_php'], 2),
    ], $productLoss),
    'charts' => [
        'trend' => [
            'labels'         => $chartLabels,
            'income_losses'  => $chartIncomeLosses,
            'material_costs' => $chartMaterialCosts,
            'waste_events'   => $chartWasteEvents,
        ],
        'top_materials' => [
            'labels'  => array_column($topMaterials, 'item_name'),
            'amounts' => array_map(static fn($x) => (float)$x['total_amount_wasted'], $topMaterials),
            'units'   => array_column($topMaterials, 'unit'),
            'costs'   => array_map(static fn($x) => (float)$x['estimated_cost_php'], $topMaterials),
        ],
        'top_products_loss' => [
            'labels' => array_slice(array_column($productLoss, 'product_name'), 0, 7),
            'losses' => array_slice(array_map(static fn($x) => (float)$x['income_loss_php'], $productLoss), 0, 7),
        ],
    ],
    'logs' => $formattedLogs,
    'pagination' => [
        'total'  => $totalLogsCount,
        'limit'  => $limit,
        'offset' => $offset,
    ],
    'available_years' => array_map('intval', $availableYears),
]);
