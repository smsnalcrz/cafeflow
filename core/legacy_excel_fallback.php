<?php
/**
 * CafeFlow - Legacy Spreadsheet / CSV Fallback Engine
 * -----------------------------------------------------------------------------
 * Provides an immediate, clean, dependency-free CSV/Excel spreadsheet export
 * when requested via `?legacy=1` or when the Python/openpyxl engine is unavailable.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csv.php';
require_role(['barista', 'manager']);

$pdo = isset($pdo) && ($pdo instanceof PDO) ? $pdo : db();
$tz = isset($tz) && ($tz instanceof DateTimeZone) ? $tz : new DateTimeZone('Asia/Manila');

// 1. Resolve Date Range
$startStr = null;
$endStr = null;

if (!empty($allParam)) {
    $whereSql = "WHERE 1=1";
    $params = [];
    $rangeLabel = "All Available Transactions";
    $fileSuffix = "All_" . date('Ymd_His');
} elseif (!empty($startParam) && !empty($endParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
    $whereSql = "WHERE `timestamp` >= :start AND `timestamp` < :end";
    $params = [
        ':start' => $startParam . ' 00:00:00',
        ':end'   => (new DateTimeImmutable($endParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00'),
    ];
    $rangeLabel = "$startParam to $endParam";
    $fileSuffix = $startParam . '_to_' . $endParam;
} elseif (!empty($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $whereSql = "WHERE `timestamp` >= :start AND `timestamp` < :end";
    $params = [
        ':start' => $dateParam . ' 00:00:00',
        ':end'   => (new DateTimeImmutable($dateParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00'),
    ];
    $rangeLabel = $dateParam;
    $fileSuffix = $dateParam;
} else {
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $whereSql = "WHERE `timestamp` >= :start AND `timestamp` < :end";
    $params = [
        ':start' => $today . ' 00:00:00',
        ':end'   => (new DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d 00:00:00'),
    ];
    $rangeLabel = $today;
    $fileSuffix = $today;
}

// 2. Fetch Transactions & Items
$txStmt = $pdo->prepare("
    SELECT t.transaction_id, t.timestamp, t.status, t.payment_method, t.reference_number,
           t.subtotal, t.discount_type, t.discount, t.tax, t.total_amount, t.amount_paid, t.change_due,
           COALESCE(u.full_name, u.username, 'System') AS cashier_name,
           t.is_mock
      FROM transactions t
      LEFT JOIN users u ON u.user_id = t.cashier_user_id
     {$whereSql}
     ORDER BY t.transaction_id ASC
");
$txStmt->execute($params);
$transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

// Map items by transaction_id
$itemsByTx = [];
if (!empty($transactions)) {
    $txIds = array_column($transactions, 'transaction_id');
    $marks = implode(',', array_fill(0, count($txIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT transaction_id, product_name, size_label, quantity, unit_price
          FROM transaction_items
         WHERE transaction_id IN ($marks)
         ORDER BY id ASC
    ");
    $itemStmt->execute($txIds);
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $nameWithQty = $row['product_name'] . ($row['size_label'] ? " ({$row['size_label']})" : '') . " x" . $row['quantity'];
        $itemsByTx[$row['transaction_id']][] = $nameWithQty;
    }
}

// 3. Output CSV with UTF-8 BOM (Opens automatically in Excel with symbols preserved)
$filename = "CafeFlow_Transactions_" . $fileSuffix . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, must-revalidate');
header('Pragma: public');

$output = fopen('php://output', 'w');
// UTF-8 BOM
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Title metadata
fputcsv($output, ['DK Cafe - CafeFlow POS Transaction Export']);
fputcsv($output, ['Reporting Period:', $rangeLabel]);
fputcsv($output, ['Generated At:', date('Y-m-d H:i:s')]);
fputcsv($output, []); // Empty row

// Header row
fputcsv($output, [
    'Receipt No',
    'Date & Time',
    'Status',
    'Cashier',
    'Payment Method',
    'Reference No',
    'Items Summary',
    'Subtotal (PHP)',
    'Discount Type',
    'Discount (PHP)',
    'VAT (PHP)',
    'Total Amount (PHP)',
    'Amount Paid (PHP)',
    'Change Due (PHP)',
    'Record Type'
]);

$sumTotal = 0.0;
$sumOrders = 0;

foreach ($transactions as $tx) {
    $txId = (int)$tx['transaction_id'];
    $receiptNo = sprintf('#%05d', $txId);
    $itemsSummary = isset($itemsByTx[$txId]) ? implode('; ', $itemsByTx[$txId]) : '—';
    $isMock = !empty($tx['is_mock']) ? 'Synthetic / Mock' : 'Live Order';

    if ($tx['status'] === 'Completed') {
        $sumTotal += (float)$tx['total_amount'];
        $sumOrders++;
    }

    write_spreadsheet_csv_row($output, [
        $receiptNo,
        $tx['timestamp'],
        $tx['status'],
        $tx['cashier_name'],
        $tx['payment_method'],
        $tx['reference_number'] ?: '—',
        $itemsSummary,
        number_format((float)$tx['subtotal'], 2, '.', ''),
        $tx['discount_type'] ?: 'none',
        number_format((float)$tx['discount'], 2, '.', ''),
        number_format((float)$tx['tax'], 2, '.', ''),
        number_format((float)$tx['total_amount'], 2, '.', ''),
        number_format((float)$tx['amount_paid'], 2, '.', ''),
        number_format((float)$tx['change_due'], 2, '.', ''),
        $isMock
    ]);
}

// Summary Row
fputcsv($output, []);
fputcsv($output, [
    'TOTAL COMPLETED SALES',
    '',
    '',
    '',
    '',
    '',
    $sumOrders . ' Completed Orders',
    '',
    '',
    '',
    '',
    number_format($sumTotal, 2, '.', ''),
    '',
    '',
    ''
]);

fclose($output);
exit;
