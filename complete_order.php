<?php
/**
 * POST complete_order.php
 * Body: { "transaction_id": 12 }
 *
 * In ONE database transaction:
 *   1. marks the order Completed (removing it from the barista queue)
 *   2. deducts quantity x deduction_amount from each linked inventory item
 *
 * The row is locked and its status re-checked, so a double tap (or two devices)
 * can never deduct the same order's ingredients twice.
 */
require_once __DIR__ . '/db.php';

require_method('POST');
$body = read_json();

$transactionId = as_int($body['transaction_id'] ?? null, 1, 4294967295);
if ($transactionId === null) {
    fail(422, 'Invalid order.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT status FROM transactions WHERE transaction_id = ? FOR UPDATE');
    $lock->execute([$transactionId]);
    $status = $lock->fetchColumn();

    if ($status === false) {
        fail(404, 'Order not found.');
    }
    if ($status !== 'Pending') {
        fail(409, 'That order was already completed.');
    }

    $pdo->prepare("UPDATE transactions SET status = 'Completed' WHERE transaction_id = ?")
        ->execute([$transactionId]);

    // Total each ingredient used across the whole order, then deduct it.
    // GREATEST(..., 0) stops stock from going negative if the count was off.
    // 1. Deduct all ingredients linked via product_ingredients (recipes)
    $pdo->prepare(
        'UPDATE inventory AS i
           JOIN (
                 SELECT pi.item_id,
                        SUM(ti.quantity * pi.deduction_amount) AS used
                   FROM transaction_items ti
                   JOIN product_ingredients pi ON pi.product_id = ti.product_id
                  WHERE ti.transaction_id = ?
                  GROUP BY pi.item_id
                ) AS u ON u.item_id = i.item_id
            SET i.current_stock = GREATEST(i.current_stock - u.used, 0)'
    )->execute([$transactionId]);

    // 2. Deduct legacy single-linked products that do not have entries in product_ingredients
    $pdo->prepare(
        'UPDATE inventory AS i
           JOIN (
                 SELECT p.linked_inventory_id AS item_id,
                        SUM(ti.quantity * p.deduction_amount) AS used
                   FROM transaction_items ti
                   JOIN menu_products p ON p.product_id = ti.product_id
                   LEFT JOIN product_ingredients pi ON pi.product_id = p.product_id
                  WHERE ti.transaction_id = ?
                    AND p.linked_inventory_id IS NOT NULL
                    AND pi.product_id IS NULL
                  GROUP BY p.linked_inventory_id
                ) AS u ON u.item_id = i.item_id
            SET i.current_stock = GREATEST(i.current_stock - u.used, 0)'
    )->execute([$transactionId]);

    // 3. Also deduct directly tracked products (not linked to any raw inventory supply)
    $pdo->prepare(
        'UPDATE menu_products AS p
           JOIN (
                 SELECT ti.product_id, SUM(ti.quantity) AS sold
                   FROM transaction_items ti
                   JOIN menu_products mp ON mp.product_id = ti.product_id
                   LEFT JOIN product_ingredients pi ON pi.product_id = mp.product_id
                  WHERE ti.transaction_id = ?
                    AND mp.linked_inventory_id IS NULL
                    AND pi.product_id IS NULL
                    AND mp.track_stock = 1
                  GROUP BY ti.product_id
                ) AS s ON s.product_id = p.product_id
            SET p.stock_quantity = GREATEST(p.stock_quantity - s.sold, 0)'
    )->execute([$transactionId]);

    // Which of the items touched by this order are now running low?
    $low = $pdo->prepare(
        'SELECT DISTINCT i.name
           FROM transaction_items ti
           JOIN (
                SELECT product_id, item_id FROM product_ingredients
                UNION
                SELECT product_id, linked_inventory_id AS item_id FROM menu_products WHERE linked_inventory_id IS NOT NULL
           ) AS links ON links.product_id = ti.product_id
           JOIN inventory i ON i.item_id = links.item_id
          WHERE ti.transaction_id = ?
            AND i.current_stock <= i.restock_threshold
          ORDER BY i.name'
    );
    $low->execute([$transactionId]);
    $lowStock = $low->fetchAll(PDO::FETCH_COLUMN);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

send_json([
    'success'        => true,
    'transaction_id' => $transactionId,
    'low_stock'      => $lowStock,
]);
