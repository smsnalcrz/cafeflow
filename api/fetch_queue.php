<?php
/**
 * GET fetch_queue.php  (polled by script.js every 3 seconds)
 * Returns every Pending order with its items, oldest first, plus a count of
 * inventory items at or below their restock threshold for the nav badge.
 */
require_once __DIR__ . '/../core/db.php';

require_method('GET');
$pdo = db();

$orders = $pdo->query(
    "SELECT transaction_id, total_amount, payment_method,
            TIMESTAMPDIFF(SECOND, `timestamp`, NOW()) AS age_sec
       FROM transactions
      WHERE status = 'Pending'
      ORDER BY `timestamp` ASC, transaction_id ASC"
)->fetchAll();

$itemsByOrder = [];
if ($orders) {
    $ids   = array_column($orders, 'transaction_id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $pdo->prepare(
        "SELECT ti.transaction_id,
                COALESCE(ti.product_name, p.name) AS name,
                ti.quantity,
                ti.size_label,
                ti.size_oz
           FROM transaction_items ti
           LEFT JOIN menu_products p ON p.product_id = ti.product_id
          WHERE ti.transaction_id IN ($marks)
          ORDER BY ti.id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $itemsByOrder[$row['transaction_id']][] = [
            'name'       => $row['name'],
            'quantity'   => (int) $row['quantity'],
            'size_label' => $row['size_label'] ?? null,
            'size_oz'    => $row['size_oz'] ? (int) $row['size_oz'] : null,
        ];
    }
}

$out = [];
foreach ($orders as $o) {
    $out[] = [
        'transaction_id' => (int) $o['transaction_id'],
        'total'          => (float) $o['total_amount'],
        'payment_method' => $o['payment_method'],
        'age_sec'        => max(0, (int) $o['age_sec']),
        'items'          => $itemsByOrder[$o['transaction_id']] ?? [],
    ];
}

$lowStock = (int) $pdo->query('SELECT COUNT(*) FROM inventory WHERE current_stock <= restock_threshold')->fetchColumn();

send_json(['success' => true, 'orders' => $out, 'low_stock' => $lowStock]);
