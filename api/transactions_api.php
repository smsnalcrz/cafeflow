<?php
/**
 * transactions_api.php - Daily transaction records & E-receipt details API
 *
 * GET ?date=YYYY-MM-DD           -> List transactions for selected day with line summaries
 * GET ?transaction_id=123        -> Detailed e-receipt data for a single transaction
 * GET ?all=1                     -> List all transactions
 */
require_once __DIR__ . '/../core/db.php';

require_method('GET');

$pdo = db();
$tz = new DateTimeZone('Asia/Manila');

// 1. Single Transaction E-Receipt
$txId = as_int($_GET['transaction_id'] ?? null, 1, 4294967295);
if ($txId !== null) {
    $st = $pdo->prepare(
        'SELECT transaction_id, total_amount, amount_paid, change_due,
                subtotal, tax, discount, status, payment_method, `timestamp`
           FROM transactions
          WHERE transaction_id = ?'
    );
    $st->execute([$txId]);
    $tx = $st->fetch();
    if (!$tx) {
        fail(404, 'Transaction not found.');
    }

    $itemStmt = $pdo->prepare(
        'SELECT ti.id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
                mp.name AS current_name, mp.price AS current_price, mp.category
           FROM transaction_items ti
           LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
          WHERE ti.transaction_id = ?
          ORDER BY ti.id ASC'
    );
    $itemStmt->execute([$txId]);
    $rawItems = $itemStmt->fetchAll();

    $items = [];
    $computedSubtotal = 0;
    foreach ($rawItems as $it) {
        $name = !empty($it['product_name']) ? $it['product_name'] : (!empty($it['current_name']) ? $it['current_name'] : 'Item #' . $it['product_id']);
        $unitPrice = (float) $it['unit_price'] > 0 ? (float) $it['unit_price'] : (float) ($it['current_price'] ?? 0);
        $qty = (int) $it['quantity'];
        $lineTotal = round($unitPrice * $qty, 2);
        $computedSubtotal += $lineTotal;

        $items[] = [
            'product_id' => (int) $it['product_id'],
            'name'       => $name,
            'category'   => $it['category'] ?? 'General',
            'quantity'   => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    $dt = new DateTimeImmutable($tx['timestamp'], $tz);
    $totalAmount = (float) $tx['total_amount'];
    $amountPaid = $tx['amount_paid'] !== null ? (float) $tx['amount_paid'] : $totalAmount;
    $changeDue = $tx['change_due'] !== null ? (float) $tx['change_due'] : max(0, $amountPaid - $totalAmount);

    send_json([
        'success'  => true,
        'business' => [
            'name'     => 'DK Cafe',
            'system'   => 'CafeFlow POS & Inventory',
            'address'  => 'Matabungkay, Lian, Batangas, Philippines',
            'contact'  => 'Tel: (+63) 9088-888-5061 · @dkmatabungkay',
        ],
        'receipt'  => [
            'transaction_id' => (int) $tx['transaction_id'],
            'receipt_no'     => sprintf('#%05d', $tx['transaction_id']),
            'timestamp'      => $dt->format('Y-m-d H:i:s'),
            'formatted_date' => $dt->format('l, F j, Y'),
            'formatted_time' => $dt->format('g:i:s A'),
            'status'         => $tx['status'],
            'payment_method' => $tx['payment_method'],
            'items'          => $items,
            'subtotal'       => $computedSubtotal,
            'tax'            => (float) ($tx['tax'] ?? 0),
            'discount'       => (float) ($tx['discount'] ?? 0),
            'total_amount'   => $totalAmount,
            'amount_paid'    => $amountPaid,
            'change_due'     => $changeDue,
        ],
    ]);
}

// 2. Daily / Filtered Transactions List
$dateParam = $_GET['date'] ?? null;
$where = '';
$params = [];

if (!empty($_GET['all'])) {
    $where = 'WHERE 1=1';
} elseif ($dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $dateParam . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($dateParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
} else {
    // Default to today
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $today . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $dateParam = $today;
}

$st = $pdo->prepare(
    "SELECT transaction_id, total_amount, amount_paid, change_due,
            status, payment_method, `timestamp`
       FROM transactions
      $where
      ORDER BY `timestamp` DESC, transaction_id DESC"
);
$st->execute($params);
$txRows = $st->fetchAll();

if (empty($txRows)) {
    send_json([
        'success'      => true,
        'date'         => $dateParam,
        'count'        => 0,
        'total_sales'  => 0.00,
        'transactions' => [],
    ]);
}

// Fetch items for all these transactions in one query for efficiency
$txIds = array_column($txRows, 'transaction_id');
$inMarks = implode(',', array_fill(0, count($txIds), '?'));

$itemsStmt = $pdo->prepare(
    "SELECT ti.transaction_id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
            mp.name AS current_name, mp.price AS current_price
       FROM transaction_items ti
       LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
      WHERE ti.transaction_id IN ($inMarks)
      ORDER BY ti.id ASC"
);
$itemsStmt->execute($txIds);
$allItems = $itemsStmt->fetchAll();

$itemsByTx = [];
foreach ($allItems as $it) {
    $tId = (int) $it['transaction_id'];
    $name = !empty($it['product_name']) ? $it['product_name'] : (!empty($it['current_name']) ? $it['current_name'] : 'Item #' . $it['product_id']);
    $price = (float) $it['unit_price'] > 0 ? (float) $it['unit_price'] : (float) ($it['current_price'] ?? 0);
    $qty = (int) $it['quantity'];

    $itemsByTx[$tId][] = [
        'product_id' => (int) $it['product_id'],
        'name'       => $name,
        'quantity'   => $qty,
        'unit_price' => $price,
        'line_total' => round($price * $qty, 2),
    ];
}

$totalDaySales = 0;
$transactions = [];

foreach ($txRows as $tx) {
    $tId = (int) $tx['transaction_id'];
    $tItems = $itemsByTx[$tId] ?? [];
    $total = (float) $tx['total_amount'];
    $totalDaySales += $total;

    $summaryParts = [];
    $itemCount = 0;
    foreach ($tItems as $item) {
        $summaryParts[] = $item['quantity'] . '× ' . $item['name'];
        $itemCount += $item['quantity'];
    }

    $dt = new DateTimeImmutable($tx['timestamp'], $tz);
    $paid = $tx['amount_paid'] !== null ? (float) $tx['amount_paid'] : $total;
    $change = $tx['change_due'] !== null ? (float) $tx['change_due'] : max(0, $paid - $total);

    $transactions[] = [
        'transaction_id' => $tId,
        'receipt_no'     => sprintf('#%05d', $tId),
        'timestamp'      => $dt->format('Y-m-d H:i:s'),
        'formatted_time' => $dt->format('g:i A'),
        'formatted_date' => $dt->format('M j, Y'),
        'total_amount'   => $total,
        'amount_paid'    => $paid,
        'change_due'     => $change,
        'payment_method' => $tx['payment_method'],
        'status'         => $tx['status'],
        'items_count'    => $itemCount,
        'items_summary'  => implode(', ', $summaryParts),
        'items'          => $tItems,
    ];
}

send_json([
    'success'      => true,
    'date'         => $dateParam,
    'count'        => count($transactions),
    'total_sales'  => round($totalDaySales, 2),
    'transactions' => $transactions,
]);
