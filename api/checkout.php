<?php
/**
 * POST checkout.php
 * Body: {
 *   "payment_method": "Cash"|"GCash",
 *   "items": [
 *     { "product_id": 1, "quantity": 1, "size_code": "16oz", "size_label": "Large", "size_oz": 16 },
 *     { "product_id": 1, "quantity": 2, "size_code": "22oz", "size_label": "Grande", "size_oz": 22 }
 *   ],
 *   "amount_paid": 430.00
 * }
 *
 * Creates a Pending transaction plus its receipt lines with cup-size-specific pricing.
 * Checks raw inventory sufficiency before order entry.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

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

// Group cart items by product_id + size_code so multiple cup sizes of the same product are preserved.
$wanted = []; // key => [ 'product_id' => int, 'quantity' => int, 'size_code' => ?string, 'size_label' => ?string, 'size_oz' => ?int ]
foreach ($rawItems as $row) {
    if (!is_array($row)) {
        fail(422, 'Invalid cart item.');
    }
    $productId = as_int($row['product_id'] ?? null, 1, 4294967295);
    $quantity  = as_int($row['quantity'] ?? null, 1, 99);
    if ($productId === null || $quantity === null) {
        fail(422, 'Invalid cart item.');
    }

    $sizeCode = isset($row['size_code']) && is_string($row['size_code']) ? trim($row['size_code']) : null;
    $sizeLabel = isset($row['size_label']) && is_string($row['size_label']) ? trim($row['size_label']) : null;
    $sizeOz = as_int($row['size_oz'] ?? null, 1, 128);

    $key = $productId . ':' . ($sizeCode ?? 'default');
    if (!isset($wanted[$key])) {
        $wanted[$key] = [
            'product_id' => $productId,
            'quantity'   => $quantity,
            'size_code'  => $sizeCode,
            'size_label' => $sizeLabel,
            'size_oz'    => $sizeOz,
        ];
    } else {
        $wanted[$key]['quantity'] += $quantity;
    }

    if ($wanted[$key]['quantity'] > 99) {
        fail(422, 'Quantity too large for one item.');
    }
}

$pdo = db();
$productIds = array_values(array_unique(array_column($wanted, 'product_id')));
$marks = implode(',', array_fill(0, count($productIds), '?'));

$stmt = $pdo->prepare("SELECT product_id, name, category, price, has_sizes, price_16oz, price_22oz FROM menu_products WHERE product_id IN ($marks)");
$stmt->execute($productIds);
$productRows = $stmt->fetchAll();
$productsMap = [];
foreach ($productRows as $pr) {
    $productsMap[$pr['product_id']] = $pr;
}

if (count($productsMap) !== count($productIds)) {
    fail(422, 'An item in the cart is no longer on the menu. Please refresh.');
}

// Ensure none of the ordered products are out of stock
foreach ($productRows as $pr) {
    if (evaluate_product_stock_state($pr, $pdo) === 'out') {
        fail(422, "Cannot place order: \"{$pr['name']}\" is out of stock.");
    }
}

// Fetch any size-specific prices defined in product_size_prices
$spStmt = $pdo->prepare("SELECT product_id, size_code, size_label, size_oz, price FROM product_size_prices WHERE product_id IN ($marks)");
$spStmt->execute($productIds);
$sizePrices = $spStmt->fetchAll();
$sizePriceMap = []; // [product_id][size_code] => price and [product_id][size_code|size_label] => price
foreach ($sizePrices as $sp) {
    $pId = (int) $sp['product_id'];
    $sizePriceMap[$pId][$sp['size_code']] = (float) $sp['price'];
    $sizePriceMap[$pId][$sp['size_code'] . '|' . ($sp['size_label'] ?? '')] = (float) $sp['price'];
}

// Calculate total cents and verify inventory availability
$totalCents = 0;
$orderLines = [];
$aggregatedConsumption = []; // item_id => ['name' => ..., 'amount' => ..., 'unit' => ...]

foreach ($wanted as $item) {
    $pId = $item['product_id'];
    $prod = $productsMap[$pId];
    $qty = $item['quantity'];
    $sCode = $item['size_code'];
    $sLabel = $item['size_label'];
    $sOz = $item['size_oz'];

    // Determine unit price dynamically based on cup size
    $unitPrice = (float) $prod['price'];
    $spKeyWithLabel = ($sCode ?? '') . '|' . ($sLabel ?? '');
    if ($sCode && isset($sizePriceMap[$pId][$spKeyWithLabel])) {
        $unitPrice = $sizePriceMap[$pId][$spKeyWithLabel];
    } elseif ($sCode && isset($sizePriceMap[$pId][$sCode])) {
        $unitPrice = $sizePriceMap[$pId][$sCode];
    } elseif ($sCode === '16oz' && $prod['price_16oz'] !== null) {
        $unitPrice = (float) $prod['price_16oz'];
    } elseif ($sCode === '22oz' && $prod['price_22oz'] !== null) {
        $unitPrice = (float) $prod['price_22oz'];
    } elseif ($sCode && !empty($prod['has_sizes'])) {
        // Fallback size differential if not explicitly configured
        if ($sCode === '22oz') {
            $unitPrice = (float) $prod['price'] + 20.00;
        }
    }

    $lineCents = (int) round($unitPrice * 100) * $qty;
    $totalCents += $lineCents;

    // Build friendly item name with cup size
    $displayName = $prod['name'];
    if ($sLabel && $sOz) {
        $displayName .= " ($sLabel - {$sOz}oz)";
    } elseif ($sLabel) {
        $displayName .= " ($sLabel)";
    }

    $orderLines[] = [
        'product_id'   => $pId,
        'product_name' => $displayName,
        'quantity'     => $qty,
        'unit_price'   => number_format($unitPrice, 2, '.', ''),
        'size_code'    => $sCode,
        'size_label'   => $sLabel,
        'size_oz'      => $sOz,
    ];

    // Compute required raw materials for this item
    $itemCons = calculate_product_consumption($prod, $sCode, $qty, $pdo, $sLabel);
    foreach ($itemCons as $c) {
        $cId = $c['item_id'];
        if (!isset($aggregatedConsumption[$cId])) {
            $aggregatedConsumption[$cId] = [
                'name'   => $c['name'],
                'unit'   => $c['unit'],
                'amount' => 0.0,
            ];
        }
        $aggregatedConsumption[$cId]['amount'] += (float) $c['amount'];
    }
}

// Check if raw inventory has sufficient stock before accepting order
$sufficiency = verify_inventory_sufficiency($aggregatedConsumption, $pdo);
if (!$sufficiency['ok']) {
    fail(422, 'Cannot place order: ' . $sufficiency['error']);
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

    $insert = $pdo->prepare(
        'INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price, size_code, size_label, size_oz)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($orderLines as $line) {
        $insert->execute([
            $transactionId,
            $line['product_id'],
            $line['product_name'],
            $line['quantity'],
            $line['unit_price'],
            $line['size_code'],
            $line['size_label'],
            $line['size_oz'],
        ]);
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

