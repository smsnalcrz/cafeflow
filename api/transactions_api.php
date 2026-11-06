<?php

/**

 * transactions_api.php - Daily transaction records & E-receipt details API

 *

 * GET ?date=YYYY-MM-DD           -> List transactions for selected day with line summaries

 * GET ?transaction_id=123        -> Detailed e-receipt data for a single transaction

 * GET ?all=1                     -> List all transactions

 */

require_once __DIR__ . '/../core/db.php';

require_once __DIR__ . '/../core/auth.php';



// RBAC: Requires authenticated user (both Barista and Manager can inspect transactions & e-receipts)

require_login();



require_method('GET');



$pdo = db();

$tz = new DateTimeZone('Asia/Manila');



// 1. Single Transaction E-Receipt

$txId = as_int($_GET['transaction_id'] ?? null, 1, 4294967295);

if ($txId !== null) {

    $st = $pdo->prepare(

        'SELECT transaction_id, cashier_user_id, total_amount, amount_paid, change_due,

                subtotal, tax, discount, discount_type, discount_rate, discount_id_no, discount_reason,

                status, payment_method, reference_number, cancelled_by_user_id, cancelled_at, cancellation_reason, `timestamp`

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

            'status'               => $tx['status'],

            'payment_method'       => $tx['payment_method'],

            'reference_number'     => $tx['reference_number'] ?? null,

            'discount_type'        => $tx['discount_type'] ?? 'none',

            'discount_rate'        => (float) ($tx['discount_rate'] ?? 0),

            'discount_id_no'       => $tx['discount_id_no'] ?? null,

            'discount_reason'      => $tx['discount_reason'] ?? null,

            'cancelled_by_user_id' => $tx['cancelled_by_user_id'] ? (int) $tx['cancelled_by_user_id'] : null,

            'cancelled_at'         => $tx['cancelled_at'] ?? null,

            'cancellation_reason'  => $tx['cancellation_reason'] ?? null,

            'items'                => $items,

            'subtotal'             => $computedSubtotal,

            'tax'                  => (float) ($tx['tax'] ?? 0),

            'discount'             => (float) ($tx['discount'] ?? 0),

            'total_amount'         => $totalAmount,

            'amount_paid'          => $amountPaid,

            'change_due'           => $changeDue,

        ],

    ]);

}



// 2. Daily / Period / Filtered Transactions List

$dateParam  = $_GET['date']  ?? null;
$startParam = $_GET['start'] ?? null;
$endParam   = $_GET['end']   ?? null;
$monthParam = $_GET['month'] ?? null;
$rangeParam = $_GET['range'] ?? null;
$allParam   = !empty($_GET['all']);

$where = '';
$params = [];
$startDateStr = '';
$endDateStr   = '';
$periodLabel  = '';

if ($allParam) {
    $where = 'WHERE 1=1';
    $periodLabel = 'All Available Transactions';
} elseif ($startParam && $endParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $startParam . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($endParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $startDateStr = $startParam;
    $endDateStr   = $endParam;
    $periodLabel  = ($startParam === $endParam) ? $startParam : "$startParam to $endParam";
} elseif ($dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $dateParam . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($dateParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $startDateStr = $dateParam;
    $endDateStr   = $dateParam;
    $periodLabel  = $dateParam;
} elseif ($monthParam && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $dtStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthParam . '-01', $tz);
    $dtEnd   = $dtStart->modify('+1 month');
    $lastDay = $dtEnd->modify('-1 day');
    $where   = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $dtStart->format('Y-m-d 00:00:00');
    $params[':end']   = $dtEnd->format('Y-m-d 00:00:00');
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
    $periodLabel  = $dtStart->format('F Y');
} elseif ($rangeParam === '7days' || $rangeParam === 'week') {
    $dtStart = ($startParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam))
        ? new DateTimeImmutable($startParam, $tz)
        : (new DateTimeImmutable('today', $tz))->modify('-6 days');
    $dtEnd   = $dtStart->modify('+7 days');
    $lastDay = $dtStart->modify('+6 days');
    $where   = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $dtStart->format('Y-m-d 00:00:00');
    $params[':end']   = $dtEnd->format('Y-m-d 00:00:00');
    $startDateStr = $dtStart->format('Y-m-d');
    $endDateStr   = $lastDay->format('Y-m-d');
    $periodLabel  = $dtStart->format('M j, Y') . ' – ' . $lastDay->format('M j, Y');
} else {
    // Default to today
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $today . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $startDateStr = $today;
    $endDateStr   = $today;
    $periodLabel  = $today;
}

// Optional search filter by receipt #, payment method, status, reference #, or item name
$searchParam = trim($_GET['search'] ?? ($_GET['q'] ?? ''));
if ($searchParam !== '') {
    $cleanId = ltrim($searchParam, '#');
    $like = '%' . $searchParam . '%';
    if (ctype_digit($cleanId)) {
        $where .= ' AND (transaction_id = :s_id OR reference_number LIKE :s_ref)';
        $params[':s_id'] = (int) $cleanId;
        $params[':s_ref'] = $like;
    } else {
        $where .= ' AND (payment_method LIKE :s_pay 
                     OR status LIKE :s_stat 
                     OR reference_number LIKE :s_ref 
                     OR discount_type LIKE :s_disc
                     OR transaction_id IN (
                         SELECT ti_sub.transaction_id FROM transaction_items ti_sub 
                         LEFT JOIN menu_products mp_sub ON mp_sub.product_id = ti_sub.product_id 
                         WHERE ti_sub.product_name LIKE :s_item1 OR mp_sub.name LIKE :s_item2
                     ))';
        $params[':s_pay']   = $like;
        $params[':s_stat']  = $like;
        $params[':s_ref']   = $like;
        $params[':s_disc']  = $like;
        $params[':s_item1'] = $like;
        $params[':s_item2'] = $like;
    }
}

$st = $pdo->prepare(

    "SELECT transaction_id, cashier_user_id, total_amount, amount_paid, change_due,

            subtotal, tax, discount, discount_type, discount_rate, discount_id_no,

            status, payment_method, reference_number, cancelled_at, cancellation_reason, `timestamp`

       FROM transactions

      $where

      ORDER BY `timestamp` DESC, transaction_id DESC"

);

$st->execute($params);

$txRows = $st->fetchAll();



if (empty($txRows)) {
    send_json([
        'success'      => true,
        'date'         => $dateParam ?? $startDateStr,
        'start_date'   => $startDateStr,
        'end_date'     => $endDateStr,
        'period'       => $periodLabel,
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

        'formatted_datetime' => $dt->format('M j, Y · g:i A'),

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
    'date'         => $dateParam ?? $startDateStr,
    'start_date'   => $startDateStr,
    'end_date'     => $endDateStr,
    'period'       => $periodLabel,
    'count'        => count($transactions),
    'total_sales'  => round($totalDaySales, 2),
    'transactions' => $transactions,
]);

