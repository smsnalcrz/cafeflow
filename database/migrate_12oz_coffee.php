<?php
/**
 * migrate_12oz_coffee.php
 * Adds price_12oz column, restores missing Coffee products (Americano, Spanish Latte, Caramel Macchiato),
 * adds missing Butter Croissant and French Fries, removes 22oz from all Coffee products,
 * and configures 12oz Hot (exclusive to hot coffee) and 16oz Cold.
 */
require_once __DIR__ . '/../core/db.php';

$pdo = db();

echo "Running 12oz Hot & Coffee Migration...\n";

// 1. Add price_12oz column to menu_products if not present
$cols = $pdo->query("SHOW COLUMNS FROM menu_products")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('price_12oz', $cols, true)) {
    echo "Adding price_12oz column to menu_products...\n";
    $pdo->exec("ALTER TABLE menu_products ADD COLUMN price_12oz DECIMAL(8,2) NULL DEFAULT NULL AFTER has_sizes");
}

// 2. Add or update Coffee products
$coffeeProducts = [
    [
        'name'       => 'Americano',
        'category'   => 'Coffee',
        'price_12oz' => 100.00,
        'price_16oz' => 100.00,
    ],
    [
        'name'       => 'Caffé Latte',
        'category'   => 'Coffee',
        'price_12oz' => 100.00,
        'price_16oz' => 100.00,
    ],
    [
        'name'       => 'Spanish Latte',
        'category'   => 'Coffee',
        'price_12oz' => 145.00,
        'price_16oz' => 145.00,
    ],
    [
        'name'       => 'White Mocha',
        'category'   => 'Coffee',
        'price_12oz' => 145.00,
        'price_16oz' => 145.00,
    ],
    [
        'name'       => 'Caramel Macchiato',
        'category'   => 'Coffee',
        'price_12oz' => 150.00,
        'price_16oz' => 150.00,
    ],
    [
        'name'       => 'Salted Caramel',
        'category'   => 'Coffee',
        'price_12oz' => 150.00,
        'price_16oz' => 150.00,
    ],
    [
        'name'       => 'Biscoff Latte',
        'category'   => 'Coffee',
        'price_12oz' => 160.00,
        'price_16oz' => 160.00,
    ],
];

foreach ($coffeeProducts as $cp) {
    // Check if product exists by name or similar
    $stmt = $pdo->prepare("SELECT product_id FROM menu_products WHERE name = ? OR name = ?");
    $altName = str_replace('é', 'e', $cp['name']);
    $stmt->execute([$cp['name'], $altName]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pId = (int)$existing['product_id'];
        echo "Updating existing Coffee product ID {$pId} ({$cp['name']})...\n";
        $upd = $pdo->prepare("
            UPDATE menu_products
               SET name = ?, category = 'Coffee', price = ?, price_12oz = ?, price_16oz = ?, price_22oz = NULL, has_sizes = 1
             WHERE product_id = ?
        ");
        $upd->execute([$cp['name'], $cp['price_12oz'], $cp['price_12oz'], $cp['price_16oz'], $pId]);
    } else {
        echo "Inserting missing Coffee product ({$cp['name']})...\n";
        $ins = $pdo->prepare("
            INSERT INTO menu_products (name, category, price, price_12oz, price_16oz, price_22oz, has_sizes, stock_quantity, track_stock)
            VALUES (?, 'Coffee', ?, ?, ?, NULL, 1, 0, 1)
        ");
        $ins->execute([$cp['name'], $cp['price_12oz'], $cp['price_12oz'], $cp['price_16oz']]);
        $pId = (int)$pdo->lastInsertId();
    }

    // Clean and sync product_size_prices for this Coffee product
    // Coffee ONLY has 12oz Hot and 16oz Cold!
    $pdo->prepare("DELETE FROM product_size_prices WHERE product_id = ?")->execute([$pId]);

    $insSp = $pdo->prepare("INSERT INTO product_size_prices (product_id, size_code, size_label, size_oz, price) VALUES (?, ?, ?, ?, ?)");
    $insSp->execute([$pId, '12oz', '12oz Hot', 12, $cp['price_12oz']]);
    $insSp->execute([$pId, '16oz', '16oz Cold', 16, $cp['price_16oz']]);
}

// 3. Remove 22oz from ANY other product with category = 'Coffee'
$pdo->exec("
    DELETE psp FROM product_size_prices psp
    JOIN menu_products mp ON psp.product_id = mp.product_id
    WHERE mp.category = 'Coffee' AND (psp.size_code = '22oz' OR psp.size_oz = 22);

    UPDATE menu_products SET price_22oz = NULL WHERE category = 'Coffee';
");

// 4. Add missing reference non-coffee items if not present
$extraItems = [
    [
        'name'      => 'Butter Croissant',
        'category'  => 'Pastry',
        'price'     => 95.00,
        'has_sizes' => 0,
    ],
    [
        'name'      => 'French Fries',
        'category'  => 'Snacks',
        'price'     => 85.00,
        'has_sizes' => 0,
    ],
];

foreach ($extraItems as $ei) {
    $stmt = $pdo->prepare("SELECT product_id FROM menu_products WHERE name = ?");
    $stmt->execute([$ei['name']]);
    if (!$stmt->fetch()) {
        echo "Inserting missing item: {$ei['name']} ({$ei['category']})...\n";
        $ins = $pdo->prepare("
            INSERT INTO menu_products (name, category, price, has_sizes, stock_quantity, track_stock)
            VALUES (?, ?, ?, ?, 0, 1)
        ");
        $ins->execute([$ei['name'], $ei['category'], $ei['price'], $ei['has_sizes']]);
    }
}

// 5. Ensure inventory item 23 '12oz Hot cup & lid' exists
$stmtInv = $pdo->prepare("SELECT item_id FROM inventory WHERE item_id = 23 OR name LIKE '%12oz%'");
$stmtInv->execute();
if (!$stmtInv->fetch()) {
    echo "Inserting 12oz Hot cup & lid into inventory...\n";
    $pdo->exec("
        INSERT INTO inventory (name, current_stock, unit_measure, restock_threshold)
        VALUES ('12oz Hot cup & lid', 500, 'pcs', 50)
    ");
}

echo "Migration finished successfully!\n";
