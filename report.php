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
require_once __DIR__ . '/db.php';

require_method('GET');

// Ensure Manila timezone matches db.php
$tz = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $tz);

$rangeParam = $_GET['range'] ?? null;
$dateParam  = $_GET['date']  ?? null;
$monthParam = $_GET['month'] ?? null;
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
// 1. Specific Month (e.g. ?month=2026-10 or ?range=month&month=2026-10)
// -----------------------------------------------------------------------------
if ($monthParam || ($rangeParam === 'month' && ($monthParam || ($startParam && preg_match('/^\d{4}-\d{2}$/', $startParam))))) {
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
    } else {
        fail(422, 'Unknown report range.');
    }
}

$startStr = $dtStart->format('Y-m-d 00:00:00');
$endStr   = $dtEnd->format('Y-m-d 00:00:00');

$pdo = db();

// -----------------------------------------------------------------------------
// Summary (orders, total, cash, gcash, pending)
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT COUNT(*)                                                           AS orders,
            COALESCE(SUM(total_amount), 0)                                     AS total,
            COALESCE(SUM(CASE WHEN payment_method = 'Cash'  THEN total_amount END), 0) AS cash,
            COALESCE(SUM(CASE WHEN payment_method = 'GCash' THEN total_amount END), 0) AS gcash,
            COALESCE(SUM(CASE WHEN status = 'Pending'       THEN 1 ELSE 0 END), 0)     AS pending
       FROM transactions
      WHERE `timestamp` >= :start AND `timestamp` < :end"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$s = $stmt->fetch() ?: ['orders' => 0, 'total' => 0, 'cash' => 0, 'gcash' => 0, 'pending' => 0];

// -----------------------------------------------------------------------------
// Top-selling items (top 10)
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT p.name, SUM(ti.quantity) AS qty
       FROM transaction_items ti
       JOIN transactions t   ON t.transaction_id = ti.transaction_id
       JOIN menu_products p  ON p.product_id = ti.product_id
      WHERE t.`timestamp` >= :start AND t.`timestamp` < :end
      GROUP BY p.product_id, p.name
      ORDER BY qty DESC, p.name ASC
      LIMIT 10"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$top = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Daily breakdown
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT DATE(`timestamp`) AS sale_date,
            COUNT(*)          AS orders,
            COALESCE(SUM(total_amount), 0) AS total,
            COALESCE(SUM(CASE WHEN payment_method = 'Cash'  THEN total_amount END), 0) AS cash,
            COALESCE(SUM(CASE WHEN payment_method = 'GCash' THEN total_amount END), 0) AS gcash
       FROM transactions
      WHERE `timestamp` >= :start AND `timestamp` < :end
      GROUP BY sale_date
      ORDER BY sale_date DESC"
);
$stmt->execute([':start' => $startStr, ':end' => $endStr]);
$days = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Hourly breakdown (for single date or today)
// -----------------------------------------------------------------------------
$hours = [];
if ($rangeType === 'date' || $rangeType === 'today' || $startDateStr === $endDateStr) {
    $stmt = $pdo->prepare(
        "SELECT HOUR(`timestamp`) AS sale_hour,
                COUNT(*)          AS orders,
                COALESCE(SUM(total_amount), 0) AS total
           FROM transactions
          WHERE `timestamp` >= :start AND `timestamp` < :end
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
        <a class="print-btn" style="text-decoration:none; display:inline-block;" href="export_excel.php?start=<?= urlencode($startDateStr) ?>&end=<?= urlencode($endDateStr) ?>">📥 Export to Excel (.xlsx)</a>
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
              <?php foreach ($top as $t): ?>
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
    'summary'    => [
        'orders'  => (int) $s['orders'],
        'total'   => (float) $s['total'],
        'cash'    => (float) $s['cash'],
        'gcash'   => (float) $s['gcash'],
        'pending' => (int) $s['pending'],
    ],
    'top_items'  => array_map(static fn(array $r): array => [
        'name' => $r['name'],
        'qty'  => (int) $r['qty'],
    ], $top),
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
]);
