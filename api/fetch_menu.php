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
$spRows = $pdo->query('SELECT product_id, size_code, size_label, size_oz, price FROM product_size_prices ORDER BY size_oz ASC')->fetchAll();
$sizesByProd = [];
foreach ($spRows as $sp) {
    $sizesByProd[(int) $sp['product_id']][] = [
        'code'  => $sp['size_code'],
        'label' => $sp['size_label'],
        'oz'    => (int) $sp['size_oz'],
        'price' => (float) $sp['price'],
    ];
}

$products = [];
$categorySet = [];

foreach ($rows as $r) {
    $category = trim($r['category'] ?? '') !== '' ? trim($r['category']) : 'General';
    $categorySet[$category] = true;

    $pId = (int) $r['product_id'];
    $hasSizes = (bool) ((int) ($r['has_sizes'] ?? 1));
    $isBeverage = !in_array(strtolower($category), ['pastry', 'meals', 'sandwich', 'snacks', 'croffle', 'coffle'], true);
    if (!$isBeverage) {
        $hasSizes = false;
    }

    $isCoffee = (strtolower($category) === 'coffee');
    $sizes = $sizesByProd[$pId] ?? [];
    if ($isCoffee && !empty($sizes)) {
        // Exclude 22oz from Coffee products
        $sizes = array_values(array_filter($sizes, fn($s) => ($s['code'] ?? '') !== '22oz' && ($s['oz'] ?? 0) !== 22));
    }

    if (empty($sizes) && $hasSizes) {
        if ($isCoffee) {
            $p12 = $r['price_12oz'] !== null ? (float) $r['price_12oz'] : (float) $r['price'];
            $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
            $sizes = [
                ['code' => '12oz', 'label' => '12oz Hot', 'oz' => 12, 'price' => $p12],
                ['code' => '16oz', 'label' => '16oz Cold', 'oz' => 16, 'price' => $p16],
            ];
        } else {
            $p16 = $r['price_16oz'] !== null ? (float) $r['price_16oz'] : (float) $r['price'];
            $p22 = $r['price_22oz'] !== null ? (float) $r['price_22oz'] : ($p16 + 20.0);
            $sizes = [
                ['code' => '16oz', 'label' => 'Tall', 'oz' => 16, 'price' => $p16],
                ['code' => '22oz', 'label' => 'Grande', 'oz' => 22, 'price' => $p22],
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
            if ($isCoffee) {
                if ($sCode === '12oz' || ($s['oz'] ?? 0) === 12) {
                    $s['label'] = '12oz Hot';
                } elseif ($sCode === '16oz' || ($s['oz'] ?? 0) === 16) {
                    $s['label'] = '16oz Cold';
                }
            } else {
                if ($sCode === '16oz' && ($s['label'] === 'Large' || empty($s['label']))) {
                    $s['label'] = 'Tall';
                } elseif ($sCode === '22oz' && empty($s['label'])) {
                    $s['label'] = 'Grande';
                }
            }

            $sStock = evaluate_single_size_stock($r, $sCode, $pdo);
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
