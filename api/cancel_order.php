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
    $lockStmt = $pdo->prepare('SELECT transaction_id, status, stock_deducted, total_amount, payment_method FROM transactions WHERE transaction_id = ? FOR UPDATE');
    $lockStmt->execute([$transactionId]);
    $tx = $lockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        fail(404, 'Transaction not found.');
    }

    $currentStatus = $tx['status'];
    if ($currentStatus === 'Cancelled' || $currentStatus === 'Voided') {
        fail(409, "Transaction #{$transactionId} is already {$currentStatus}.");
    }

    $stockDeducted = (int) ($tx['stock_deducted'] ?? 0);
    $newStatus = 'Cancelled';
    $inventoryReversed = false;
    $reversedItems = [];
    $wasteResult = [
        'logged_count'            => 0,
        'items'                   => [],
        'total_cost_php'          => 0.00,
        'total_income_loss_php'   => 0.00,
    ];

    // Fetch transaction items with snapshot details (for inventory reversal and waste logging)
    $itemsStmt = $pdo->prepare('
        SELECT ti.id, ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
               ti.size_code, ti.size_label, ti.size_oz,
               mp.name AS base_name, mp.category
          FROM transaction_items ti
          LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
         WHERE ti.transaction_id = ?
    ');
    $itemsStmt->execute([$transactionId]);
    $items = $itemsStmt->fetchAll();

    if ($currentStatus === 'Completed') {
        // Only managers are authorized to void completed sales
        if ($userRole !== 'manager') {
            fail(403, 'Access denied: Only Managers are authorized to void completed transactions and issue inventory reversals.');
        }

        $newStatus = 'Voided';

        // Prepared orders: do not automatically restore consumed ingredients (Task 06)
        // Liquid coffee and prepared beverages cannot be returned to raw inventory.
        $inventoryReversed = false;
        $reversedItems = [];

        // Atomically log raw ingredient and packaging waste to waste_log
        $wasteResult = record_order_waste($pdo, $transactionId, $userId, $reason, $items);

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

    } elseif ($currentStatus === 'Pending') {
        // Pending order: check if cancellation was due to kitchen spillage/waste or clean cancellation
        $newStatus = 'Cancelled';

        $isWasteParam = $body['is_waste'] ?? ($body['waste_incurred'] ?? null);
        $wasteKeywords = ['spill', 'spillage', 'waste', 'dropped', 'ruined', 'burned', 'burnt', 'spoiled', 'curdled', 'tapon'];
        $wasteIncurred = false;
        if ($isWasteParam !== null) {
            $wasteIncurred = (bool) $isWasteParam;
        } else {
            $lowerReason = strtolower($reason);
            foreach ($wasteKeywords as $kw) {
                if (str_contains($lowerReason, $kw)) {
                    $wasteIncurred = true;
                    break;
                }
            }
        }

        if ($wasteIncurred) {
            // Physical ingredients were consumed and ruined/spilled during preparation
            // Stock was deducted at checkout; do NOT restore it to inventory
            $inventoryReversed = false;
            $reversedItems = [];

            // Atomically log raw ingredient and packaging waste to waste_log
            $wasteResult = record_order_waste($pdo, $transactionId, $userId, $reason, $items);

            // Update transaction record and keep stock_deducted = 1 (stock remains consumed as waste)
            $updateStmt = $pdo->prepare('
                UPDATE transactions
                   SET status = ?,
                       stock_deducted = 1,
                       cancelled_by_user_id = ?,
                       cancelled_at = NOW(),
                       cancellation_reason = ?
                 WHERE transaction_id = ?
            ');
            $updateStmt->execute([$newStatus, $userId, $reason, $transactionId]);

        } else {
            // Unprepared orders: customer changed mind, cashier error, etc.
            // Release reserved stock using immutable order_ingredients snapshot (Tasks 04, 06, 17)
            if ($stockDeducted === 1) {
                $oiStmt = $pdo->prepare('SELECT item_id, item_name, unit, amount_used FROM order_ingredients WHERE transaction_id = ?');
                $oiStmt->execute([$transactionId]);
                $snapItems = $oiStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($snapItems)) {
                    $restoreStmt = $pdo->prepare('UPDATE inventory SET current_stock = current_stock + ? WHERE item_id = ?');
                    foreach ($snapItems as $r) {
                        $restoreStmt->execute([$r['amount_used'], $r['item_id']]);
                        $reversedItems[] = sprintf('+%s %s of %s', number_format($r['amount_used'], 2), $r['unit'], $r['item_name']);
                    }
                    $inventoryReversed = true;
                }
            }

            // Unprepared orders do not incur physical ingredient waste
            $wasteResult = [
                'logged_count'            => 0,
                'items'                   => [],
                'total_cost_php'          => 0.00,
                'total_income_loss_php'   => 0.00,
            ];

            // Update transaction record and clear stock_deducted flag
            $updateStmt = $pdo->prepare('
                UPDATE transactions
                   SET status = ?,
                       stock_deducted = 0,
                       cancelled_by_user_id = ?,
                       cancelled_at = NOW(),
                       cancellation_reason = ?
                 WHERE transaction_id = ?
            ');
            $updateStmt->execute([$newStatus, $userId, $reason, $transactionId]);
        }

    } else {
        fail(422, "Cannot cancel transaction with status '{$currentStatus}'.");
    }

    $pdo->commit();

    $actionKey = $newStatus === 'Voided' ? 'order_void' : 'order_cancel';
    $desc = $newStatus === 'Voided'
        ? "Voided completed Order #{$transactionId} ({$reason})"
        : "Cancelled pending Order #{$transactionId} ({$reason})";

    audit_log(
        'pos_order',
        $actionKey,
        $desc,
        'transaction',
        (string) $transactionId,
        [
            'tx_id'              => $transactionId,
            'reason'             => $reason,
            'previous_status'    => $currentStatus,
            'new_status'         => $newStatus,
            'inventory_reversed' => $inventoryReversed
        ],
        $pdo,
        $user
    );
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
    'waste_logged'       => !empty($wasteResult['items']),
    'waste_summary'      => [
        'events_count'       => $wasteResult['logged_count'] ?? 0,
        'cost_loss_php'      => $wasteResult['total_cost_php'] ?? 0.00,
        'income_loss_php'    => $wasteResult['total_income_loss_php'] ?? 0.00,
    ],
    'message'            => $newStatus === 'Voided'
        ? "Transaction #{$transactionId} has been successfully voided and recorded as waste."
        : ($wasteIncurred
            ? "Order #{$transactionId} cancelled and recorded as kitchen spillage/waste (stock was consumed)."
            : "Order #{$transactionId} has been cancelled and reserved inventory restored."),
]);
