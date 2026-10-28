<?php
/**
 * migrate.php - Database migration for CafeFlow feature enhancements.
 * Safe to run multiple times (idempotent).
 */
require __DIR__ . '/../core/db.php';

$pdo = db();

echo "Running migrations...\n";

// 1. Add columns to menu_products if not present
$cols = $pdo->query("SHOW COLUMNS FROM menu_products")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('category', $cols, true)) {
    echo "Adding category to menu_products...\n";
    $pdo->exec("ALTER TABLE menu_products ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'Coffee' AFTER name");
}
if (!in_array('image_url', $cols, true)) {
    echo "Adding image_url to menu_products...\n";
    $pdo->exec("ALTER TABLE menu_products ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL AFTER price");
}
if (!in_array('stock_quantity', $cols, true)) {
    echo "Adding stock_quantity to menu_products...\n";
    $pdo->exec("ALTER TABLE menu_products ADD COLUMN stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER image_url");
}
if (!in_array('track_stock', $cols, true)) {
    echo "Adding track_stock to menu_products...\n";
    $pdo->exec("ALTER TABLE menu_products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_quantity");
}

// 2. Add columns to transaction_items if not present
$itemCols = $pdo->query("SHOW COLUMNS FROM transaction_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('unit_price', $itemCols, true)) {
    echo "Adding unit_price to transaction_items...\n";
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN unit_price DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER quantity");
}
if (!in_array('product_name', $itemCols, true)) {
    echo "Adding product_name to transaction_items...\n";
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN product_name VARCHAR(100) NULL DEFAULT NULL AFTER product_id");
}

// 3. Add columns to transactions if not present
$txCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('amount_paid', $txCols, true)) {
    echo "Adding amount_paid to transactions...\n";
    $pdo->exec("ALTER TABLE transactions ADD COLUMN amount_paid DECIMAL(10,2) NULL DEFAULT NULL AFTER total_amount");
}
if (!in_array('change_due', $txCols, true)) {
    echo "Adding change_due to transactions...\n";
    $pdo->exec("ALTER TABLE transactions ADD COLUMN change_due DECIMAL(10,2) NULL DEFAULT 0.00 AFTER amount_paid");
}
if (!in_array('subtotal', $txCols, true)) {
    echo "Adding subtotal to transactions...\n";
    $pdo->exec("ALTER TABLE transactions ADD COLUMN subtotal DECIMAL(10,2) NULL DEFAULT NULL AFTER change_due");
}
if (!in_array('tax', $txCols, true)) {
    echo "Adding tax to transactions...\n";
    $pdo->exec("ALTER TABLE transactions ADD COLUMN tax DECIMAL(10,2) NULL DEFAULT 0.00 AFTER subtotal");
}
if (!in_array('discount', $txCols, true)) {
    echo "Adding discount to transactions...\n";
    $pdo->exec("ALTER TABLE transactions ADD COLUMN discount DECIMAL(10,2) NULL DEFAULT 0.00 AFTER tax");
}

// 4. Product ingredients table for recipe linkage
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('product_ingredients', $tables, true)) {
    echo "Creating product_ingredients table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_ingredients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            PRIMARY KEY (id),
            UNIQUE KEY uq_prod_item (product_id, item_id),
            KEY idx_pi_product (product_id),
            KEY idx_pi_item (item_id),
            CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE,
            CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// 5. Clean up obsolete starter duplicates if any, without affecting actual DK Cafe menu items
echo "Migration completed successfully!\n";
