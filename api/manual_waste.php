<?php
/**
 * CafeFlow - Manual Waste Entry API
 *
 * POST api/manual_waste.php
 *
 * Body: {
 *   "item_id": 14,                      // optional, if selecting an existing raw inventory supply
 *   "item_name": "Full Cream Milk",     // required, max 100 chars
 *   "unit": "ml",                       // required: g | ml | pcs | pc
 *   "amount_wasted": 500.00,            // required, > 0
 *   "cost_php": 45.00,                  // optional, if omitted or 0 estimated automatically
 *   "deduct_inventory": true,           // optional, default true if item_id/match exists
 *   "quantity": 1,                      // optional, default 1
 *   "reason": "Spilled during rush"     // optional, defaults to 'Manual waste entry'
 * }
 *
 * Records uncontrollable waste (expired ingredients, breakage, spills, etc.)
 * that is NOT tied to a customer transaction. If matched to a stock item,
 * automatically deducts inventory stock and maintains live audit consistency.
 *
 * RBAC: Accessible to Barista and Manager roles.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/recipe_engine.php';

// RBAC: Both baristas and managers can log manual waste
$user = require_role(['barista', 'manager']);
$userId = (int) $user['user_id'];
$userName = $user['full_name'] ?? $user['username'] ?? 'Staff';

require_method('POST');

$body = read_json();

$csrf = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrf)) {
    fail(403, 'CSRF security token invalid or missing.');
}

$itemName = trim((string)($body['item_name'] ?? ''));
if ($itemName === '' || strlen($itemName) > 100) {
    fail(422, 'Please enter the material or supply name (maximum 100 characters).');
}

$unit = strtolower(trim((string)($body['unit'] ?? 'pcs')));
$validUnits = ['g', 'ml', 'pcs', 'pc', 'shots', 'shot', 'kg', 'l', 'pack', 'packs', 'bottle', 'bottles', 'can', 'cans', 'cup', 'cups', 'box', 'boxes'];
if (!in_array($unit, $validUnits, true) && !preg_match('/^[a-z0-9\/\.\-_]{1,20}$/i', $unit)) {
    fail(422, 'Invalid unit. Please enter a valid unit measure (e.g. g, ml, pcs, shots).');
}
if ($unit === 'pc') {
    $unit = 'pcs';
} elseif ($unit === 'shot') {
    $unit = 'shots';
} elseif ($unit === 'bottle') {
    $unit = 'bottles';
} elseif ($unit === 'can') {
    $unit = 'cans';
} elseif ($unit === 'pack') {
    $unit = 'packs';
}

$amount = as_decimal($body['amount_wasted'] ?? null, 0.01, 999999.99);
if ($amount === null) {
    fail(422, 'Please enter a valid amount wasted (must be greater than 0).');
}

$amountFloat = (float) $amount;

$costInput = as_decimal($body['cost_php'] ?? null, 0.00, 999999.99);
$costFloat = ($costInput !== null && (float)$costInput > 0.0) ? (float)$costInput : 0.0;

// Auto-estimate ingredient cost if not explicitly provided
if ($costFloat <= 0.0) {
    $estCents = estimate_ingredient_cost_cents($itemName, $unit, $amountFloat);
    $costFloat = round($estCents / 100, 2);
}
if ($costFloat <= 0.0) {
    $costFloat = 1.00; // Minimum fallback ₱1.00 for material loss accounting
}

$costCents = (int) round($costFloat * 100);

$reason = trim((string)($body['reason'] ?? ''));
if ($reason === '') {
    $reason = 'Manual waste entry';
}
if (strlen($reason) > 255) {
    $reason = substr($reason, 0, 255);
}

$quantity = as_int($body['quantity'] ?? 1, 1, 999);
if ($quantity === null) {
    $quantity = 1;
}

$reqItemId = as_int($body['item_id'] ?? null, 1, 4294967295);
$deductStock = isset($body['deduct_inventory']) ? (bool)$body['deduct_inventory'] : true;

$pdo = db();
$pdo->beginTransaction();
try {
    // Check if the item corresponds to an inventory stock supply
    $matchedItem = null;
    if ($reqItemId !== null) {
        $invCheck = $pdo->prepare('SELECT item_id, name, unit_measure, current_stock FROM inventory WHERE item_id = ? FOR UPDATE');
        $invCheck->execute([$reqItemId]);
        $matchedItem = $invCheck->fetch();
    }
    if (!$matchedItem) {
        $invCheck = $pdo->prepare('SELECT item_id, name, unit_measure, current_stock FROM inventory WHERE LOWER(name) = LOWER(?) LIMIT 1 FOR UPDATE');
        $invCheck->execute([$itemName]);
        $matchedItem = $invCheck->fetch();
    }

    $matchedItemId = null;
    $inventoryDeducted = false;
    $newStock = null;

    if ($matchedItem) {
        $matchedItemId = (int) $matchedItem['item_id'];
        $itemName = $matchedItem['name'];
        $invUnit = strtolower(trim((string)$matchedItem['unit_measure']));
        $inputUnit = strtolower(trim((string)$unit));

        // Unit compatibility and conversion safeguard (BUG-9)
        if ($inputUnit !== $invUnit) {
            if ($invUnit === 'g' && $inputUnit === 'kg') {
                $amountFloat = $amountFloat * 1000.0;
                $unit = $invUnit;
            } elseif ($invUnit === 'kg' && $inputUnit === 'g') {
                $amountFloat = $amountFloat / 1000.0;
                $unit = $invUnit;
            } elseif ($invUnit === 'ml' && in_array($inputUnit, ['l', 'liter', 'liters'], true)) {
                $amountFloat = $amountFloat * 1000.0;
                $unit = $invUnit;
            } elseif (in_array($invUnit, ['l', 'liter', 'liters'], true) && $inputUnit === 'ml') {
                $amountFloat = $amountFloat / 1000.0;
                $unit = $invUnit;
            } elseif ($invUnit === 'pcs' && $inputUnit === 'pc') {
                $unit = 'pcs';
            } elseif ($invUnit === 'shots' && $inputUnit === 'shot') {
                $unit = 'shots';
            } else {
                fail(422, "Unit mismatch for supply \"$itemName\": Inventory is tracked in '$invUnit', but '$inputUnit' was entered. Please enter quantity in $invUnit.");
            }
        } else {
            $unit = $matchedItem['unit_measure'];
        }

        if ($deductStock) {
            $deductStmt = $pdo->prepare('
                UPDATE inventory
                   SET current_stock = GREATEST(0, current_stock - ?)
                 WHERE item_id = ?
            ');
            $deductStmt->execute([$amountFloat, $matchedItemId]);
            $inventoryDeducted = true;
            $newStock = max(0.0, (float)$matchedItem['current_stock'] - $amountFloat);
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO waste_log (
            transaction_id, product_id, product_name, size_code, size_label,
            quantity, item_id, item_name, unit, amount_wasted,
            cost_cents, estimated_cost_php, wasted_by_user_id, reason, wasted_at
        ) VALUES (
            NULL, NULL, NULL, NULL, NULL,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NOW()
        )
    ');
    $stmt->execute([
        $quantity,
        $matchedItemId,
        $itemName,
        $unit,
        $amountFloat,
        $costCents,
        round($costCents / 100, 2),
        $userId,
        $reason,
    ]);

    // Update hourly and daily pre-aggregated summary
    try {
        $dailySummaryStmt = $pdo->prepare('
            INSERT INTO waste_report_summary (
                summary_date, summary_hour, period_type, total_events,
                total_items_wasted, total_cost_cents, total_income_loss_cents, updated_at
            ) VALUES (
                CURDATE(), 255, "daily", 1,
                ?, ?, 0, NOW()
            )
            ON DUPLICATE KEY UPDATE
                total_events = total_events + 1,
                total_items_wasted = total_items_wasted + VALUES(total_items_wasted),
                total_cost_cents = total_cost_cents + VALUES(total_cost_cents),
                updated_at = NOW()
        ');
        $dailySummaryStmt->execute([$amountFloat, $costCents]);

        $hourlySummaryStmt = $pdo->prepare('
            INSERT INTO waste_report_summary (
                summary_date, summary_hour, period_type, total_events,
                total_items_wasted, total_cost_cents, total_income_loss_cents, updated_at
            ) VALUES (
                CURDATE(), HOUR(NOW()), "hourly", 1,
                ?, ?, 0, NOW()
            )
            ON DUPLICATE KEY UPDATE
                total_events = total_events + 1,
                total_items_wasted = total_items_wasted + VALUES(total_items_wasted),
                total_cost_cents = total_cost_cents + VALUES(total_cost_cents),
                updated_at = NOW()
        ');
        $hourlySummaryStmt->execute([$amountFloat, $costCents]);
    } catch (Throwable $summaryErr) {
        error_log('[CafeFlow Waste Summary] ' . $summaryErr->getMessage());
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

send_json([
    'success'            => true,
    'message'            => $inventoryDeducted
        ? sprintf('Manual waste recorded: %s %s deducted from "%s" stock.', number_format($amountFloat, 2), $unit, $itemName)
        : 'Manual waste entry recorded successfully.',
    'inventory_deducted' => $inventoryDeducted,
    'remaining_stock'    => $newStock !== null ? round($newStock, 2) : null,
    'waste_entry'        => [
        'item_id'            => $matchedItemId,
        'item_name'          => $itemName,
        'unit'               => $unit,
        'amount_wasted'      => $amountFloat,
        'formatted_amount'   => number_format($amountFloat, 2) . ' ' . $unit,
        'estimated_cost_php' => round($costCents / 100, 2),
        'formatted_cost'     => '₱' . number_format(round($costCents / 100, 2), 2),
        'reason'             => $reason,
        'wasted_by'          => $userName,
        'wasted_at'          => date('M j, Y h:i A'),
    ],
]);
