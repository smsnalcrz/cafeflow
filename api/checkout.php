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

require_once __DIR__ . '/../core/auth.php';

require_once __DIR__ . '/../core/recipe_engine.php';



// RBAC: Only Baristas are authorized to process orders and POS transactions

$user = require_role('barista');

$cashierId = (int) $user['user_id'];



require_method('POST');

$body = read_json();



$csrf = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

if (!validate_csrf_token($csrf)) {

    fail(403, 'CSRF security token invalid or missing.');

}



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



$stmt = $pdo->prepare("SELECT product_id, name, category, price, has_sizes, price_12oz, price_16oz, price_22oz FROM menu_products WHERE product_id IN ($marks)");

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

    } elseif ($sOz && isset($sizePriceMap[$pId][$sOz . 'oz'])) {

        $unitPrice = $sizePriceMap[$pId][$sOz . 'oz'];

    } elseif (($sCode === '12oz' || $sOz === 12) && !empty($prod['price_12oz'])) {

        $unitPrice = (float) $prod['price_12oz'];

    } elseif (($sCode === '16oz' || $sOz === 16) && !empty($prod['price_16oz'])) {

        $unitPrice = (float) $prod['price_16oz'];

    } elseif (($sCode === '22oz' || $sOz === 22) && !empty($prod['price_22oz'])) {

        $unitPrice = (float) $prod['price_22oz'];

    } elseif ($sCode && !empty($prod['has_sizes'])) {

        // Fallback size differential if not explicitly configured

        if ($sCode === '22oz' || $sOz === 22) {

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



// -----------------------------------------------------------------------------

// Financial Calculation Engine: Subtotal, VAT, and Philippine Senior/PWD Discounts

// -----------------------------------------------------------------------------

$subtotal = round($totalCents / 100, 2);

$discountType = strtolower(trim((string)($body['discount_type'] ?? 'none')));

$discountIdNo = null;

$discountReason = null;

$discountRate = 0.00;

$discount = 0.00;

$tax = 0.00;

$total = $subtotal;



if ($discountType === 'senior_pwd') {
    // Senior Citizen (RA 9994) & PWD (RA 10754) 20% Discount
    $idNum = trim((string)($body['discount_id_no'] ?? ''));
    if ($idNum === '' || strlen($idNum) < 3) {
        fail(422, 'Senior Citizen / PWD ID or Booklet Number is required to avail the 20% discount.');
    }
    $discountIdNo = substr($idNum, 0, 100);
    $discountReason = 'Senior Citizen / PWD Discount (20%)';
    $discountRate = 20.00;

    // Direct 20% discount on gross subtotal (No VAT)
    $discount = round($subtotal * 0.20, 2);
    $tax = 0.00; // No VAT
    $total = round($subtotal - $discount, 2);
} elseif ($discountType === 'custom_percent') {
    $rate = as_decimal($body['discount_rate'] ?? null, 1.0, 50.0);
    if ($rate === null) {
        fail(422, 'Promotional discount percentage must be between 1% and 50%.');
    }
    $discountRate = (float)$rate;
    $discount = round($subtotal * ($discountRate / 100), 2);
    $discountedBase = max(0, $subtotal - $discount);
    $tax = 0.00; // No VAT
    $total = round($discountedBase, 2);
    $discountReason = trim((string)($body['discount_reason'] ?? 'Promotional Discount'));
} elseif ($discountType === 'custom_fixed') {
    $fixedAmt = as_decimal($body['discount_amount'] ?? null, 0.01, $subtotal);
    if ($fixedAmt === null) {
        fail(422, 'Promotional discount amount must be greater than 0 and not exceed order subtotal.');
    }
    $discount = (float)$fixedAmt;
    $discountRate = round(($discount / $subtotal) * 100, 2);
    $discountedBase = max(0, $subtotal - $discount);
    $tax = 0.00; // No VAT
    $total = round($discountedBase, 2);
    $discountReason = trim((string)($body['discount_reason'] ?? 'Fixed Discount Voucher'));
} else {
    $discountType = 'none';
    $discount = 0.00;
    $discountRate = 0.00;
    $tax = 0.00; // No VAT
    $total = $subtotal;
}



// -----------------------------------------------------------------------------

// Payment Validation & Anti-Underpayment Safeguards

// -----------------------------------------------------------------------------

$referenceNumber = null;

if ($method === 'Cash') {

    $amountPaidInput = as_decimal($body['amount_paid'] ?? null, 0, 999999.99);

    if ($amountPaidInput === null) {

        fail(422, 'Please enter the cash amount tendered.');

    }

    $amountPaid = (float)$amountPaidInput;

    if ($amountPaid < (float)$total) {

        fail(422, sprintf(

            'Insufficient payment amount: Order total is ₱%s, but only ₱%s was tendered (short by ₱%s).',

            number_format($total, 2),

            number_format($amountPaid, 2),

            number_format($total - $amountPaid, 2)

        ));

    }

    $changeDue = round($amountPaid - (float)$total, 2);

} elseif ($method === 'GCash') {

    $ref = trim((string)($body['reference_number'] ?? ''));

    if ($ref === '' || strlen($ref) < 6 || strlen($ref) > 30 || !preg_match('/^[A-Za-z0-9\-]+$/', $ref)) {

        fail(422, 'A valid GCash Reference Number (6–30 alphanumeric characters) is required for GCash transactions.');

    }

    // Prevent duplicate GCash reference within recent completed or pending orders

    $dupCheck = $pdo->prepare("SELECT transaction_id FROM transactions WHERE reference_number = ? AND status IN ('Pending', 'Completed') LIMIT 1");

    $dupCheck->execute([$ref]);

    $dupTx = $dupCheck->fetchColumn();

    if ($dupTx) {

        fail(409, "GCash reference number '{$ref}' has already been recorded for Transaction #{$dupTx}.");

    }

    $referenceNumber = $ref;

    $amountPaid = (float)$total;

    $changeDue = 0.00;

} else {

    fail(422, 'Unsupported payment method.');

}



$pdo->beginTransaction();

try {

    $pdo->prepare(

        "INSERT INTO transactions (

            cashier_user_id, total_amount, amount_paid, change_due,

            subtotal, tax, discount, discount_type, discount_rate, discount_id_no, discount_reason,

            status, payment_method, reference_number

         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)"

    )->execute([

        $cashierId,

        number_format($total, 2, '.', ''),

        number_format($amountPaid, 2, '.', ''),

        number_format($changeDue, 2, '.', ''),

        number_format($subtotal, 2, '.', ''),

        number_format($tax, 2, '.', ''),

        number_format($discount, 2, '.', ''),

        $discountType,

        number_format($discountRate, 2, '.', ''),

        $discountIdNo,

        $discountReason,

        $method,

        $referenceNumber

    ]);

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

    'success'          => true,

    'transaction_id'   => $transactionId,

    'subtotal'         => number_format($subtotal, 2, '.', ''),

    'discount'         => number_format($discount, 2, '.', ''),

    'discount_type'    => $discountType,

    'tax'              => number_format($tax, 2, '.', ''),

    'total'            => number_format($total, 2, '.', ''),

    'amount_paid'      => number_format($amountPaid, 2, '.', ''),

    'change_due'       => number_format($changeDue, 2, '.', ''),

    'payment_method'   => $method,

    'reference_number' => $referenceNumber,

    'message'          => 'Order Sent to Queue',

]);



