<?php
/**
 * POST complete_order.php
 * Body: { "transaction_id": 12 }
 *
 * In ONE database transaction:
 *   1. Marks the order Completed (removing it from the barista queue).
 *   2. Deducts estimated raw materials consumed based on product & cup size.
 *   3. Enforces that deductions occur exactly once per completed transaction.
 *   4. Prevents negative inventory balances and provides clear feedback if stock is insufficient.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

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

    // Fetch items with their cup size and product details
    $itemsStmt = $pdo->prepare(
        'SELECT ti.id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
                ti.size_code, ti.size_label, ti.size_oz,
                mp.name AS base_name, mp.category, mp.has_sizes
           FROM transaction_items ti
           LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
          WHERE ti.transaction_id = ?'
    );
    $itemsStmt->execute([$transactionId]);
    $items = $itemsStmt->fetchAll();

    if (empty($items)) {
        fail(422, 'Cannot complete an order with no items.');
    }

    // Aggregate consumption of all raw inventory items across the order
    $aggregatedConsumption = []; // item_id => ['name' => ..., 'amount' => float, 'unit' => ...]

    foreach ($items as $item) {
        $product = [
            'product_id' => $item['product_id'],
            'name'       => !empty($item['base_name']) ? $item['base_name'] : $item['product_name'],
            'category'   => $item['category'] ?? 'Coffee',
        ];
        $sizeCode = $item['size_code'] ?? null;
        $sizeLabel = $item['size_label'] ?? null;
        $qty = (int) $item['quantity'];

        $itemCons = calculate_product_consumption($product, $sizeCode, $qty, $pdo, $sizeLabel);
        foreach ($itemCons as $c) {
            $cId = (int) $c['item_id'];
            if (!isset($aggregatedConsumption[$cId])) {
                $aggregatedConsumption[$cId] = [
                    'item_id' => $cId,
                    'name'    => $c['name'],
                    'unit'    => $c['unit'],
                    'amount'  => 0.0,
                ];
            }
            $aggregatedConsumption[$cId]['amount'] += (float) $c['amount'];
        }
    }

    // Lock and verify inventory rows FOR UPDATE to guarantee consistency
    if (!empty($aggregatedConsumption)) {
        $invIds = array_keys($aggregatedConsumption);
        $marks = implode(',', array_fill(0, count($invIds), '?'));
        $invLockStmt = $pdo->prepare(
            "SELECT item_id, name, current_stock, restock_threshold, unit_measure
               FROM inventory
              WHERE item_id IN ($marks)
                FOR UPDATE"
        );
        $invLockStmt->execute($invIds);
        $lockedInventory = [];
        foreach ($invLockStmt->fetchAll() as $invRow) {
            $lockedInventory[(int) $invRow['item_id']] = $invRow;
        }

        // Check for insufficient stock before applying any deduction
        foreach ($aggregatedConsumption as $cId => $req) {
            $invItem = $lockedInventory[$cId] ?? null;
            $avail = $invItem ? (float) $invItem['current_stock'] : 0.0;
            $needed = (float) $req['amount'];

            if ($avail < $needed) {
                $shortage = round($needed - $avail, 2);
                fail(
                    422,
                    sprintf(
                        'Cannot complete order: Insufficient stock for "%s". Order requires %s %s, but only %s %s available (short by %s %s).',
                        $req['name'],
                        number_format($needed, 2),
                        $req['unit'],
                        number_format(max(0, $avail), 2),
                        $req['unit'],
                        number_format($shortage, 2),
                        $req['unit']
                    )
                );
            }
        }

        // Deduct accurately from current_stock, using GREATEST(..., 0) as a safeguard
        $deductStmt = $pdo->prepare('UPDATE inventory SET current_stock = GREATEST(current_stock - ?, 0) WHERE item_id = ?');
        foreach ($aggregatedConsumption as $cId => $req) {
            $deductStmt->execute([$req['amount'], $cId]);
        }
    }

    // Mark order Completed
    $pdo->prepare("UPDATE transactions SET status = 'Completed' WHERE transaction_id = ?")
        ->execute([$transactionId]);

    // Check which items are now running low
    $lowStock = [];
    if (!empty($aggregatedConsumption)) {
        $invIds = array_keys($aggregatedConsumption);
        $marks = implode(',', array_fill(0, count($invIds), '?'));
        $lowStmt = $pdo->prepare(
            "SELECT name FROM inventory WHERE item_id IN ($marks) AND current_stock <= restock_threshold ORDER BY name ASC"
        );
        $lowStmt->execute($invIds);
        $lowStock = $lowStmt->fetchAll(PDO::FETCH_COLUMN);
    }

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
