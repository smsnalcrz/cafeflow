<?php
/**
 * GET fetch_menu.php
 * Menu items for the cashier grid, each with category, image, price, and stock_state.
 */
require_once __DIR__ . '/db.php';

require_method('GET');

$pdo = db();

$rows = $pdo->query(
    'SELECT p.product_id, p.name, p.category, p.price, p.image_url,
            p.stock_quantity, p.track_stock, p.deduction_amount, p.linked_inventory_id,
            i.current_stock, i.restock_threshold
       FROM menu_products p
       LEFT JOIN inventory i ON i.item_id = p.linked_inventory_id
      ORDER BY p.category ASC, p.name ASC'
)->fetchAll();

// Fetch multi-ingredient linkages if present
$allIngs = $pdo->query(
    'SELECT pi.product_id, pi.item_id, pi.deduction_amount,
            i.current_stock, i.restock_threshold
       FROM product_ingredients pi
       JOIN inventory i ON i.item_id = pi.item_id'
)->fetchAll();
$ingsByProd = [];
foreach ($allIngs as $ing) {
    $ingsByProd[$ing['product_id']][] = $ing;
}

$products = [];
$categorySet = [];

foreach ($rows as $r) {
    $state = null;
    $category = trim($r['category'] ?? '') !== '' ? trim($r['category']) : 'General';
    $categorySet[$category] = true;

    $pId = (int) $r['product_id'];
    $linkedIngredients = $ingsByProd[$pId] ?? [];

    if ((int)$r['track_stock'] === 1) {
        if (!empty($linkedIngredients)) {
            $hasOut = false;
            $hasLow = false;
            foreach ($linkedIngredients as $ing) {
                $stock = (float) $ing['current_stock'];
                $deduct = (float) $ing['deduction_amount'];
                if ($stock <= 0 || ($deduct > 0 && $stock < $deduct)) {
                    $hasOut = true;
                    break;
                } elseif ($stock <= (float) $ing['restock_threshold']) {
                    $hasLow = true;
                }
            }
            if ($hasOut) {
                $state = 'out';
            } elseif ($hasLow) {
                $state = 'low';
            } else {
                $state = 'ok';
            }
        } elseif ($r['linked_inventory_id'] !== null && $r['current_stock'] !== null) {
            $stock = (float) $r['current_stock'];
            $deduct = (float) $r['deduction_amount'];
            if ($stock <= 0 || ($deduct > 0 && $stock < $deduct)) {
                $state = 'out';
            } elseif ($stock <= (float) $r['restock_threshold']) {
                $state = 'low';
            } else {
                $state = 'ok';
            }
        } else {
            $directStock = (float) ($r['stock_quantity'] ?? 0);
            if ($directStock <= 0) {
                $state = 'out';
            } elseif ($directStock <= 5) {
                $state = 'low';
            } else {
                $state = 'ok';
            }
        }
    }

    $products[] = [
        'product_id'     => (int) $r['product_id'],
        'name'           => $r['name'],
        'category'       => $category,
        'price'          => (float) $r['price'],
        'image_url'      => $r['image_url'],
        'stock_quantity' => (float) ($r['stock_quantity'] ?? 0),
        'track_stock'    => (int) ($r['track_stock'] ?? 1),
        'stock_state'    => $state,
    ];
}

send_json([
    'success'    => true,
    'products'   => $products,
    'categories' => array_values(array_keys($categorySet)),
]);
