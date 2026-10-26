<?php
/**
 * POST checkout.php
 * Body: { "payment_method": "Cash"|"GCash", "items": [ { "product_id": 3, "quantity": 2 }, ... ] }
 *
 * Creates a Pending transaction plus its receipt lines. The total is always
 * recalculated here from the database prices - the browser's total is ignored.
 */
require_once __DIR__ . '/db.php';

require_method('POST');
$body = read_json();

$method = $body['payment_method'] ?? null;
if (!in_array($method, ['Cash', 'GCash'], true)) {
    fail(422, 'Choose Cash or GCash.');
}

$rawItems = $body['items'] ?? null;
if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 50) {
    fail(422, 'The cart is empty.');
}

// Merge duplicate lines and validate every value.
$wanted = [];                                   // product_id => quantity
foreach ($rawItems as $row) {
    if (!is_array($row)) {
        fail(422, 'Invalid cart item.');
    }
    $productId = as_int($row['product_id'] ?? null, 1, 4294967295);
    $quantity  = as_int($row['quantity'] ?? null, 1, 99);
    if ($productId === null || $quantity === null) {
        fail(422, 'Invalid cart item.');
    }
    $wanted[$productId] = ($wanted[$productId] ?? 0) + $quantity;
    if ($wanted[$productId] > 99) {
        fail(422, 'Quantity too large for one item.');
    }
}

$pdo   = db();
$ids   = array_keys($wanted);
$marks = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("SELECT product_id, name, price FROM menu_products WHERE product_id IN ($marks)");
$stmt->execute($ids);
$productRows = $stmt->fetchAll();
$productsMap = [];
foreach ($productRows as $pr) {
    $productsMap[$pr['product_id']] = $pr;
}

if (count($productsMap) !== count($ids)) {
    fail(422, 'An item in the cart is no longer on the menu. Please refresh.');
}

// Work in centavos so floating-point rounding can never creep into the total.
$totalCents = 0;
foreach ($wanted as $productId => $quantity) {
    $totalCents += (int) round((float) $productsMap[$productId]['price'] * 100) * $quantity;
}
$total = number_format($totalCents / 100, 2, '.', '');

$amountPaid = as_decimal($body['amount_paid'] ?? null, 0, 999999.99);
if ($amountPaid === null || (float)$amountPaid < (float)$total) {
    $amountPaid = $total;
}
$changeDue = number_format(max(0, (float)$amountPaid - (float)$total), 2, '.', '');

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO transactions (total_amount, amount_paid, change_due, subtotal, tax, discount, status, payment_method)
         VALUES (?, ?, ?, ?, 0.00, 0.00, 'Pending', ?)"
    )->execute([$total, $amountPaid, $changeDue, $total, $method]);
    $transactionId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare('INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price) VALUES (?, ?, ?, ?, ?)');
    foreach ($wanted as $productId => $quantity) {
        $p = $productsMap[$productId];
        $insert->execute([$transactionId, $productId, $p['name'], $quantity, $p['price']]);
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
    'total'          => $total,
    'amount_paid'    => $amountPaid,
    'change_due'     => $changeDue,
    'message'        => 'Order Sent to Queue',
]);
