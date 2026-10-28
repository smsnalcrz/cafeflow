<?php
/**
 * CafeFlow - shared bootstrap: PDO connection, JSON helpers, input validation.
 * Change the constants below only if your MySQL differs from the XAMPP defaults.
 */

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'cafeflow');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');              // XAMPP default: empty password

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');  // PHP warnings must never corrupt a JSON reply
ini_set('log_errors', '1');

if (!class_exists('ApiError')) {
    /** An error that is safe to show to the cashier (message + HTTP status). */
    class ApiError extends RuntimeException
    {
    }
}

if (!function_exists('ensure_schema')) {
    function ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM menu_products")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'Coffee' AFTER name");
            }
            if (!in_array('image_url', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL AFTER price");
            }
            if (!in_array('stock_quantity', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER image_url");
            }
            if (!in_array('track_stock', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER stock_quantity");
            }
            if (!in_array('has_sizes', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN has_sizes TINYINT(1) NOT NULL DEFAULT 1 AFTER track_stock");
            }
            if (!in_array('price_16oz', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN price_16oz DECIMAL(8,2) NULL DEFAULT NULL AFTER has_sizes");
            }
            if (!in_array('price_22oz', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN price_22oz DECIMAL(8,2) NULL DEFAULT NULL AFTER price_16oz");
            }

            $itemCols = $pdo->query("SHOW COLUMNS FROM transaction_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('unit_price', $itemCols, true)) {
                $pdo->exec("ALTER TABLE transaction_items ADD COLUMN unit_price DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER quantity");
            }
            if (!in_array('product_name', $itemCols, true)) {
                $pdo->exec("ALTER TABLE transaction_items ADD COLUMN product_name VARCHAR(100) NULL DEFAULT NULL AFTER product_id");
            }
            if (!in_array('size_code', $itemCols, true)) {
                $pdo->exec("ALTER TABLE transaction_items ADD COLUMN size_code VARCHAR(30) NULL DEFAULT NULL AFTER unit_price");
            }
            if (!in_array('size_label', $itemCols, true)) {
                $pdo->exec("ALTER TABLE transaction_items ADD COLUMN size_label VARCHAR(50) NULL DEFAULT NULL AFTER size_code");
            }
            if (!in_array('size_oz', $itemCols, true)) {
                $pdo->exec("ALTER TABLE transaction_items ADD COLUMN size_oz INT UNSIGNED NULL DEFAULT NULL AFTER size_label");
            }
            try {
                $pdo->exec("ALTER TABLE transaction_items MODIFY COLUMN product_id INT UNSIGNED NULL DEFAULT NULL");
            } catch (Throwable $e) {
                // Ignore if already nullable
            }

            $txCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('amount_paid', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN amount_paid DECIMAL(10,2) NULL DEFAULT NULL AFTER total_amount");
            }
            if (!in_array('change_due', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN change_due DECIMAL(10,2) NULL DEFAULT 0.00 AFTER amount_paid");
            }
            if (!in_array('subtotal', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN subtotal DECIMAL(10,2) NULL DEFAULT NULL AFTER change_due");
            }
            if (!in_array('tax', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN tax DECIMAL(10,2) NULL DEFAULT 0.00 AFTER subtotal");
            }
            if (!in_array('discount', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN discount DECIMAL(10,2) NULL DEFAULT 0.00 AFTER tax");
            }

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('product_ingredients', $tables, true)) {
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

            if (!in_array('product_size_prices', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS product_size_prices (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        product_id INT UNSIGNED NOT NULL,
                        size_code VARCHAR(30) NOT NULL,
                        size_label VARCHAR(50) NOT NULL,
                        size_oz INT UNSIGNED NOT NULL DEFAULT 16,
                        price DECIMAL(8,2) NOT NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_prod_size (product_id, size_code),
                        KEY idx_psp_prod (product_id),
                        CONSTRAINT fk_psp_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            // Populate essential raw supplies from reference inventory if not present
            $pdo->exec("
                INSERT IGNORE INTO inventory (name, current_stock, unit_measure, restock_threshold) VALUES
                ('16oz cup', 500, 'pcs', 50),
                ('22oz cup', 500, 'pcs', 50),
                ('Flat lid', 1000, 'pcs', 100),
                ('Bendable straw', 1000, 'pcs', 100),
                ('Boba straw', 500, 'pcs', 50),
                ('Full Cream Milk', 10000, 'ml', 2000),
                ('Caramel syrup', 2000, 'ml', 300),
                ('Vanilla syrup', 2000, 'ml', 300),
                ('Chocolate sauce', 2000, 'ml', 300)
            ");

            // Initialize size pricing for any existing beverage that has no size price configured
            $pdo->exec("
                UPDATE menu_products
                   SET price_16oz = COALESCE(price_16oz, CASE WHEN price >= 140 THEN price - 20 ELSE price END),
                       price_22oz = COALESCE(price_22oz, CASE WHEN price >= 140 THEN price ELSE price + 20 END)
                 WHERE has_sizes = 1 AND (price_16oz IS NULL OR price_22oz IS NULL)
            ");

            // Sync size prices into product_size_prices table with 'Tall' (16oz) and 'Grande' (22oz)
            $pdo->exec("
                UPDATE product_size_prices SET size_label = 'Tall' WHERE size_code = '16oz';
                UPDATE transaction_items SET size_label = 'Tall' WHERE size_code = '16oz';
                UPDATE transaction_items SET product_name = REPLACE(product_name, '(Large', '(Tall') WHERE product_name LIKE '%(Large%';

                INSERT INTO product_size_prices (product_id, size_code, size_label, size_oz, price)
                SELECT product_id, '16oz', 'Tall', 16, price_16oz
                  FROM menu_products
                 WHERE has_sizes = 1 AND price_16oz IS NOT NULL
                ON DUPLICATE KEY UPDATE price = VALUES(price), size_label = VALUES(size_label);

                INSERT INTO product_size_prices (product_id, size_code, size_label, size_oz, price)
                SELECT product_id, '22oz', 'Grande', 22, price_22oz
                  FROM menu_products
                 WHERE has_sizes = 1 AND price_22oz IS NOT NULL
                ON DUPLICATE KEY UPDATE price = VALUES(price), size_label = VALUES(size_label);
            ");
        } catch (Throwable $e) {
            error_log('[CafeFlow Migration] ' . $e->getMessage());
        }
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
            ensure_schema($pdo);
        }
        return $pdo;
    }
}

if (!function_exists('send_json')) {
    function send_json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('fail')) {
    function fail(int $status, string $message): void
    {
        throw new ApiError($message, $status);
    }
}

if (!function_exists('require_method')) {
    function require_method(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            if (!headers_sent()) {
                header('Allow: ' . $method);
            }
            fail(405, 'Method not allowed.');
        }
    }
}

if (!function_exists('read_json')) {
    function read_json(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($data)) {
            fail(400, 'Invalid request.');
        }
        return $data;
    }
}

if (!function_exists('as_int')) {
    function as_int(mixed $value, int $min, int $max): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $n === false ? null : $n;
    }
}

if (!function_exists('as_decimal')) {
    function as_decimal(mixed $value, float $min, float $max): ?string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if (!is_finite($f) || $f < $min || $f > $max) {
            return null;
        }
        return number_format($f, 2, '.', '');
    }
}

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiError) {
        $status  = $e->getCode() ?: 400;
        $message = $e->getMessage();
    } else {
        error_log('[CafeFlow] ' . $e);
        $status  = 500;
        $message = 'Server error. Please try again.';
    }
    send_json(['success' => false, 'error' => $message], $status);
});
