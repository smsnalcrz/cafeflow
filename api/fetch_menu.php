<?php
/**
 * GET fetch_menu.php
 * Menu items for the cashier grid, each with category, image, prices per cup size, and raw-inventory stock_state.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/recipe_engine.php';

require_method('GET');

$pdo = db();

$rows = $pdo->query(
    'SELECT p.product_id, p.name, p.category, p.price, p.image_url,
            p.stock_quantity, p.has_sizes, p.price_12oz, p.price_16oz, p.price_22oz,
            p.linked_inventory_id, p.deduction_amount
       FROM menu_products p
      ORDER BY p.category ASC, p.name ASC'
)->fetchAll();

// Fetch size-specific prices from product_size_prices
$spRows = $pdo->query('SELECT product_id, size_code, size_label, size_oz, price FROM product_size_prices ORDER BY size_oz ASC, id ASC')->fetchAll();
$sizesByProd = [];
foreach ($spRows as $sp) {
    $label = $sp['size_label'];
    $isHot = str_contains(strtolower($label ?? ''), 'hot') || str_contains(strtolower($sp['size_code'] ?? ''), 'hot') || (int)$sp['size_oz'] === 12;
    $temp = $isHot ? 'Hot' : 'Cold';
    $sizesByProd[(int) $sp['product_id']][] = [
        'code'  => $sp['size_code'],
        'label' => $label,
        'oz'    => (int) $sp['size_oz'],
        'temp'  => $temp,
        'price' => (float) $sp['price'],
    ];
}

$products = [];
$categorySet = [];

foreach ($rows as $r) {
    $category = trim($r['category'] ?? '') !== '' ? trim($r['category']) : 'General';
    $categorySet[$category] = true;

    $pId = (int) $r['product_id'];
    $sizes = $sizesByProd[$pId] ?? [];
    $isBeverage = !in_array(strtolower($category), ['pastry', 'meals', 'sandwich', 'snacks', 'croffle', 'coffle'], true);
    $hasSizes = !empty($sizes) ? true : (bool) ((int) ($r['has_sizes'] ?? ($isBeverage ? 1 : 0)));

    $isCoffee = (strtolower($category) === 'coffee');

    if (empty($sizes) && $hasSizes) {
        if ($isCoffee) {
            $p12 = $r['price_12oz'] !== null ? (float) $r['price_12oz'] : (float) $r['price'];
            $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
            $sizes = [
                ['code' => '12oz', 'label' => '12oz Hot', 'oz' => 12, 'temp' => 'Hot', 'price' => $p12],
                ['code' => '16oz', 'label' => '16oz Cold', 'oz' => 16, 'temp' => 'Cold', 'price' => $p16],
            ];
        } else {
            $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
            $p22 = $r['price_22oz'] !== null ? (float) $r['price_22oz'] : ($p16 + 20.0);
            $sizes = [
                ['code' => '16oz', 'label' => '16oz Cold', 'oz' => 16, 'temp' => 'Cold', 'price' => $p16],
                ['code' => '22oz', 'label' => '22oz Cold', 'oz' => 22, 'temp' => 'Cold', 'price' => $p22],
            ];
        }
    }

    // Evaluate stock state per size and overall
    $partialLabel = null;
    if ($hasSizes && !empty($sizes)) {
        $availableSizes = [];
        $outSizes = [];
        $hasLow = false;

        foreach ($sizes as &$s) {
            $sCode = $s['code'] ?? ($s['oz'] . 'oz');
            if (empty($s['label'])) {
                $s['label'] = $sCode . (!empty($s['temp']) ? ' ' . $s['temp'] : '');
            }

            $sStock = evaluate_single_size_stock($r, $sCode, $pdo, null, $s['label'] ?? null);
            $s['stock_state'] = $sStock;
            $s['available'] = ($sStock !== 'out');
            if ($sStock === 'out') {
                $outSizes[] = $s;
            } else {
                $availableSizes[] = $s;
                if ($sStock === 'low') {
                    $hasLow = true;
                }
            }
        }
        unset($s);

        if (empty($availableSizes)) {
            $stockState = 'out';
        } elseif (!empty($outSizes)) {
            $stockState = 'partial';
            $availLabels = array_column($availableSizes, 'label');
            $partialLabel = implode('/', $availLabels) . ' only';
        } else {
            $stockState = $hasLow ? 'low' : 'ok';
        }
    } else {
        $stockState = evaluate_single_size_stock($r, null, $pdo);
    }

    $products[] = [
        'product_id'     => $pId,
        'name'           => $r['name'],
        'category'       => $category,
        'price'          => (float) $r['price'],
        'image_url'      => $r['image_url'],
        'has_sizes'      => $hasSizes,
        'price_12oz'     => $r['price_12oz'] !== null ? (float) $r['price_12oz'] : null,
        'price_16oz'     => $r['price_16oz'] !== null ? (float) $r['price_16oz'] : ($sizes[0]['price'] ?? (float) $r['price']),
        'price_22oz'     => $isCoffee ? null : ($r['price_22oz'] !== null ? (float) $r['price_22oz'] : ($sizes[1]['price'] ?? null)),
        'sizes'          => $sizes,
        'stock_state'    => $stockState,
        'partial_label'  => $partialLabel,
    ];
}

send_json([
    'success'    => true,
    'products'   => $products,
    'categories' => array_values(array_keys($categorySet)),
]);
