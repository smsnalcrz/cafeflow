<?php
/**
 * CafeFlow - Manual Waste Entry API
 *
 * POST api/manual_waste.php
 *
 * Body: {
 *   "item_name": "Broken Glass",        // required, max 100 chars
 *   "unit": "pcs",                      // required: g | ml | pcs | pc
 *   "amount_wasted": 2.00,              // required, > 0
 *   "cost_php": 45.00,                  // required, > 0, manual financial loss
 *   "quantity": 1,                      // optional, default 1
 *   "reason": "Glass broke during cleanup" // optional, defaults to 'Manual waste entry'
 * }
 *
 * Records uncontrollable waste (expired ingredients, breakage, spills, etc.)
 * that is NOT tied to a cancelled customer order.
 *
 * RBAC: Accessible to Barista and Manager roles.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

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
$validUnits = ['g', 'ml', 'pcs', 'pc'];
if (!in_array($unit, $validUnits, true)) {
    fail(422, 'Invalid unit. Please use g, ml, or pcs.');
}
if ($unit === 'pc') {
    $unit = 'pcs';
}

$amount = as_decimal($body['amount_wasted'] ?? null, 0.01, 999999.99);
if ($amount === null) {
    fail(422, 'Please enter a valid amount wasted (must be greater than 0).');
}

$costPhp = as_decimal($body['cost_php'] ?? null, 0.01, 999999.99);
if ($costPhp === null) {
    fail(422, 'Please enter a valid cost in pesos (must be greater than 0).');
}

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

$amountFloat = (float) $amount;
$costFloat = (float) $costPhp;
$costCents = (int) round($costFloat * 100);

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('
        INSERT INTO waste_log (
            transaction_id, product_id, product_name, size_code, size_label,
            quantity, item_id, item_name, unit, amount_wasted,
            cost_cents, estimated_cost_php, wasted_by_user_id, reason, wasted_at
        ) VALUES (
            NULL, NULL, NULL, NULL, NULL,
            ?, NULL, ?, ?, ?,
            ?, ?, ?, ?, NOW()
        )
    ');
    $stmt->execute([
        $quantity,
        $itemName,
        $unit,
        $amountFloat,
        $costCents,
        round($costCents / 100, 2),
        $userId,
        $reason,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

send_json([
    'success' => true,
    'message' => 'Manual waste entry recorded successfully.',
    'waste_entry' => [
        'item_name'   => $itemName,
        'unit'        => $unit,
        'amount_wasted' => $amountFloat,
        'formatted_amount' => number_format($amountFloat, 2) . ' ' . $unit,
        'estimated_cost_php' => round($costCents / 100, 2),
        'formatted_cost' => '₱' . number_format(round($costCents / 100, 2), 2),
        'reason'      => $reason,
        'wasted_by'   => $userName,
        'wasted_at'   => date('M j, Y h:i A'),
    ],
]);
