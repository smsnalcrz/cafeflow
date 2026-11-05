<?php
/**
 * POST cancel_order.php
 * Body: { "transaction_id": 12, "reason": "Customer changed mind" }
 *
 * Handles order cancellation and voiding:
 * - Pending Orders: Can be cancelled by Barista or Manager. No inventory reversal needed.
 * - Completed Orders: Can only be voided by Manager. Safely restores consumed raw inventory.
 * - Records cancellation timestamp, reason, and responsible user ID.
 * - Excluded from active sales reports and revenue calculations.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/recipe_engine.php';

// RBAC: Requires authenticated user
$user = require_login();
$userRole = strtolower($user['role'] ?? '');
$userId = (int) $user['user_id'];

require_method('POST');
$body = read_json();

$csrf = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrf)) {
    fail(403, 'CSRF security token invalid or missing.');
}

$transactionId = as_int($body['transaction_id'] ?? null, 1, 4294967295);
if ($transactionId === null) {
    fail(422, 'Invalid transaction ID.');
}

$reason = trim((string)($body['reason'] ?? ''));
if ($reason === '' || strlen($reason) < 3 || strlen($reason) > 255) {
    fail(422, 'Please provide a valid cancellation or void reason (3 to 255 characters).');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $lockStmt = $pdo->prepare('SELECT transaction_id, status, total_amount, payment_method FROM transactions WHERE transaction_id = ? FOR UPDATE');
    $lockStmt->execute([$transactionId]);
    $tx = $lockStmt->fetch();

    if (!$tx) {
        fail(404, 'Transaction not found.');
    }

    $currentStatus = $tx['status'];
    if ($currentStatus === 'Cancelled' || $currentStatus === 'Voided') {
        fail(409, "Transaction #{$transactionId} is already {$currentStatus}.");
    }

    $newStatus = 'Cancelled';
    $inventoryReversed = false;
    $reversedItems = [];

    if ($currentStatus === 'Completed') {
        // Only managers are authorized to void completed sales
        if ($userRole !== 'manager') {
            fail(403, 'Access denied: Only Managers are authorized to void completed transactions and issue inventory reversals.');
        }

        $newStatus = 'Voided';

        // Fetch transaction items with snapshot details
        $itemsStmt = $pdo->prepare('
            SELECT ti.product_id, ti.product_name, ti.quantity, ti.size_code, ti.size_label,
                   mp.name AS base_name, mp.category
              FROM transaction_items ti
              LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
             WHERE ti.transaction_id = ?
        ');
        $itemsStmt->execute([$transactionId]);
        $items = $itemsStmt->fetchAll();

        // Calculate and restore consumed raw ingredients
        $aggregatedRestoration = []; // item_id => amount
        foreach ($items as $item) {
            $product = [
                'product_id' => $item['product_id'],
                'name'       => !empty($item['base_name']) ? $item['base_name'] : $item['product_name'],
                'category'   => $item['category'] ?? 'Coffee',
            ];
            $sizeCode = $item['size_code'] ?? null;
            $sizeLabel = $item['size_label'] ?? null;
            $qty = (int) $item['quantity'];

            $cons = calculate_product_consumption($product, $sizeCode, $qty, $pdo, $sizeLabel);
            foreach ($cons as $c) {
                $cId = (int) $c['item_id'];
                if (!isset($aggregatedRestoration[$cId])) {
                    $aggregatedRestoration[$cId] = [
                        'item_id' => $cId,
                        'name'    => $c['name'],
                        'unit'    => $c['unit'],
                        'amount'  => 0.0,
                    ];
                }
                $aggregatedRestoration[$cId]['amount'] += (float) $c['amount'];
            }
        }

        if (!empty($aggregatedRestoration)) {
            $restoreStmt = $pdo->prepare('UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?');
            foreach ($aggregatedRestoration as $cId => $r) {
                $restoreStmt->execute([$r['amount'], $cId]);
                $reversedItems[] = sprintf('+%s %s of %s', number_format($r['amount'], 2), $r['unit'], $r['name']);
            }
            $inventoryReversed = true;
        }
    } elseif ($currentStatus === 'Pending') {
        // Pending order: cancelled before kitchen preparation / inventory deduction
        $newStatus = 'Cancelled';
    } else {
        fail(422, "Cannot cancel transaction with status '{$currentStatus}'.");
    }

    // Update transaction record with audit trail
    $updateStmt = $pdo->prepare('
        UPDATE transactions
           SET status = ?,
               cancelled_by_user_id = ?,
               cancelled_at = NOW(),
               cancellation_reason = ?
         WHERE transaction_id = ?
    ');
    $updateStmt->execute([$newStatus, $userId, $reason, $transactionId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

send_json([
    'success'            => true,
    'transaction_id'     => $transactionId,
    'status'             => $newStatus,
    'previous_status'    => $currentStatus,
    'inventory_reversed' => $inventoryReversed,
    'restoration_log'    => $reversedItems,
    'message'            => $newStatus === 'Voided'
        ? "Transaction #{$transactionId} has been successfully voided and inventory reversed."
        : "Order #{$transactionId} has been cancelled.",
]);
