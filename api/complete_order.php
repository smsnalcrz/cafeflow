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
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/recipe_engine.php';

// RBAC: Only Baristas can manage and complete the Barista queue
require_role('barista');

require_method('POST');
$body = read_json();

$csrf = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrf)) {
    fail(403, 'CSRF security token invalid or missing.');
}

$transactionId = as_int($body['transaction_id'] ?? null, 1, 4294967295);
if ($transactionId === null) {
    fail(422, 'Invalid order.');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT transaction_id, status, stock_deducted FROM transactions WHERE transaction_id = ? FOR UPDATE');
    $lock->execute([$transactionId]);
    $tx = $lock->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        fail(404, 'Order not found.');
    }
    if ($tx['status'] !== 'Pending') {
        fail(409, 'That order was already completed.');
    }

    $stockAlreadyDeducted = ((int) ($tx['stock_deducted'] ?? 0)) === 1;
    $deductedCount = 0;
    $lowStock = [];

    if ($stockAlreadyDeducted) {
        // Inventory was already reserved/deducted at checkout (Tasks 04 & 31)
        // Mark order Completed directly without duplicate deduction
        $pdo->prepare("UPDATE transactions SET status = 'Completed' WHERE transaction_id = ?")
            ->execute([$transactionId]);

        // Fetch items and low-stock alerts from immutable order_ingredients snapshot
        $oiStmt = $pdo->prepare('SELECT DISTINCT item_id, item_name FROM order_ingredients WHERE transaction_id = ?');
        $oiStmt->execute([$transactionId]);
        $oiRows = $oiStmt->fetchAll(PDO::FETCH_ASSOC);
        $deductedCount = count($oiRows);

        if (!empty($oiRows)) {
            $invIds = array_column($oiRows, 'item_id');
            $marks = implode(',', array_fill(0, count($invIds), '?'));
            $lowStmt = $pdo->prepare(
                "SELECT name FROM inventory WHERE item_id IN ($marks) AND current_stock <= restock_threshold ORDER BY name ASC"
            );
            $lowStmt->execute($invIds);
            $lowStock = $lowStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } else {
        // Fallback for legacy pending orders created prior to checkout-time stock reservation
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

        // Lock and verify inventory rows FOR UPDATE in ASC order (Deadlock Prevention)
        if (!empty($aggregatedConsumption)) {
            $invIds = array_keys($aggregatedConsumption);
            sort($invIds);
            $marks = implode(',', array_fill(0, count($invIds), '?'));
            $invLockStmt = $pdo->prepare(
                "SELECT item_id, name, current_stock, restock_threshold, unit_measure
                   FROM inventory
                  WHERE item_id IN ($marks)
                  ORDER BY item_id ASC
                    FOR UPDATE"
            );
            $invLockStmt->execute($invIds);
            $lockedInventory = [];
            foreach ($invLockStmt->fetchAll(PDO::FETCH_ASSOC) as $invRow) {
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

            // Record snapshot in order_ingredients for legacy transaction
            $oiStmt = $pdo->prepare('INSERT INTO order_ingredients (transaction_id, item_id, item_name, unit, amount_used) VALUES (?, ?, ?, ?, ?)');
            foreach ($aggregatedConsumption as $cId => $req) {
                $oiStmt->execute([$transactionId, $cId, $req['name'], $req['unit'], $req['amount']]);
            }
        }

        // Mark order Completed and record stock_deducted = 1
        $pdo->prepare("UPDATE transactions SET status = 'Completed', stock_deducted = 1 WHERE transaction_id = ?")
            ->execute([$transactionId]);

        $deductedCount = count($aggregatedConsumption);

        // Check which items are now running low
        if (!empty($aggregatedConsumption)) {
            $invIds = array_keys($aggregatedConsumption);
            $marks = implode(',', array_fill(0, count($invIds), '?'));
            $lowStmt = $pdo->prepare(
                "SELECT name FROM inventory WHERE item_id IN ($marks) AND current_stock <= restock_threshold ORDER BY name ASC"
            );
            $lowStmt->execute($invIds);
            $lowStock = $lowStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $pdo->commit();

    audit_log(
        'pos_order',
        'order_complete',
        "Completed Order #{$transactionId} in Barista Queue",
        'transaction',
        (string) $transactionId,
        [
            'tx_id'             => $transactionId,
            'deducted_supplies' => $deductedCount,
            'low_stock_alerts'  => count($lowStock)
        ],
        $pdo
    );
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
