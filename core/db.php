<?php
/**
 * CafeFlow - shared bootstrap: PDO connection, JSON helpers, input validation.
 * Change the constants below only if your MySQL differs from the XAMPP defaults.
 */

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'cafeflow');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');              // XAMPP default: empty password

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
            if (!in_array('price_12oz', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN price_12oz DECIMAL(8,2) NULL DEFAULT NULL AFTER has_sizes");
            }
            if (!in_array('price_16oz', $cols, true)) {
                $pdo->exec("ALTER TABLE menu_products ADD COLUMN price_16oz DECIMAL(8,2) NULL DEFAULT NULL AFTER price_12oz");
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
                $prodIdCol = $pdo->query("SHOW COLUMNS FROM transaction_items LIKE 'product_id'")->fetch(PDO::FETCH_ASSOC);
                if ($prodIdCol && ($prodIdCol['Null'] ?? 'NO') === 'NO') {
                    $pdo->exec("ALTER TABLE transaction_items MODIFY COLUMN product_id INT UNSIGNED NULL DEFAULT NULL");
                }
            } catch (Throwable $e) {
                // Ignore if check fails
            }

            $txCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('cashier_user_id', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN cashier_user_id INT UNSIGNED NULL DEFAULT NULL AFTER transaction_id");
                try { $pdo->exec("ALTER TABLE transactions ADD INDEX idx_cashier (cashier_user_id)"); } catch (Throwable $e) {}
            }
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
            if (!in_array('reference_number', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN reference_number VARCHAR(100) NULL DEFAULT NULL AFTER payment_method");
                try { $pdo->exec("ALTER TABLE transactions ADD INDEX idx_tx_ref (reference_number)"); } catch (Throwable $e) {}
            }
            if (!in_array('is_mock', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER reference_number");
                try { $pdo->exec("ALTER TABLE transactions ADD INDEX idx_tx_is_mock (is_mock)"); } catch (Throwable $e) {}
            }
            if (!in_array('discount_type', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN discount_type VARCHAR(50) NOT NULL DEFAULT 'none' AFTER discount");
            }
            if (!in_array('discount_rate', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_type");
            }
            if (!in_array('discount_id_no', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN discount_id_no VARCHAR(100) NULL DEFAULT NULL AFTER discount_rate");
            }
            if (!in_array('discount_reason', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN discount_reason VARCHAR(255) NULL DEFAULT NULL AFTER discount_id_no");
            }
            if (!in_array('cancelled_by_user_id', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN cancelled_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER status");
            }
            if (!in_array('cancelled_at', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER cancelled_by_user_id");
            }
            if (!in_array('cancellation_reason', $txCols, true)) {
                $pdo->exec("ALTER TABLE transactions ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL AFTER cancelled_at");
            }
            // Ensure status ENUM supports 'Cancelled' and 'Voided'
            try {
                $statusCol = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
                if ($statusCol && !str_contains($statusCol['Type'] ?? '', 'Voided')) {
                    $pdo->exec("ALTER TABLE transactions MODIFY COLUMN status ENUM('Pending','Completed','Cancelled','Voided') NOT NULL DEFAULT 'Pending'");
                }
            } catch (Throwable $e) {}

            $invCols = $pdo->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_active', $invCols, true)) {
                $pdo->exec("ALTER TABLE inventory ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER restock_threshold");
                try { $pdo->exec("ALTER TABLE inventory ADD INDEX idx_inv_active (is_active)"); } catch (Throwable $e) {}
            }

            // Harden product_ingredients foreign key from dangerous CASCADE to RESTRICT
            try {
                $fkRule = $pdo->query("
                    SELECT DELETE_RULE 
                      FROM information_schema.REFERENTIAL_CONSTRAINTS 
                     WHERE CONSTRAINT_SCHEMA = 'cafeflow' 
                       AND CONSTRAINT_NAME = 'fk_pi_item'
                ")->fetchColumn();
                if ($fkRule && strtoupper($fkRule) === 'CASCADE') {
                    $pdo->exec("ALTER TABLE product_ingredients DROP FOREIGN KEY fk_pi_item");
                    $pdo->exec("ALTER TABLE product_ingredients ADD CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE RESTRICT");
                }
            } catch (Throwable $e) {}

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
                        UNIQUE KEY uq_prod_size (product_id, size_code, size_label),
                        KEY idx_psp_prod (product_id),
                        CONSTRAINT fk_psp_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('users', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        full_name VARCHAR(100) NOT NULL,
                        username VARCHAR(50) NOT NULL,
                        email VARCHAR(100) NULL DEFAULT NULL,
                        password_hash VARCHAR(255) NOT NULL,
                        role ENUM('barista', 'manager') NOT NULL DEFAULT 'barista',
                        status ENUM('active', 'inactive', 'pending') NOT NULL DEFAULT 'active',
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        last_login DATETIME NULL DEFAULT NULL,
                        two_factor_secret VARCHAR(255) NULL DEFAULT NULL,
                        two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
                        two_factor_confirmed_at DATETIME NULL DEFAULT NULL,
                        two_factor_recovery_codes TEXT NULL DEFAULT NULL,
                        two_factor_last_used_step BIGINT NULL DEFAULT NULL,
                        two_factor_reenroll_required TINYINT(1) NOT NULL DEFAULT 0,
                        PRIMARY KEY (user_id),
                        UNIQUE KEY uq_users_username (username),
                        UNIQUE KEY uq_users_email (email),
                        KEY idx_users_role (role),
                        KEY idx_users_status (status),
                        KEY idx_users_2fa (two_factor_enabled)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } else {
                $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('two_factor_secret', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(255) NULL DEFAULT NULL AFTER last_login");
                }
                if (!in_array('two_factor_enabled', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret");
                    try { $pdo->exec("ALTER TABLE users ADD INDEX idx_users_2fa (two_factor_enabled)"); } catch (Throwable $e) {}
                }
                if (!in_array('two_factor_confirmed_at', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_confirmed_at DATETIME NULL DEFAULT NULL AFTER two_factor_enabled");
                }
                if (!in_array('two_factor_recovery_codes', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_recovery_codes TEXT NULL DEFAULT NULL AFTER two_factor_confirmed_at");
                }
                if (!in_array('two_factor_last_used_step', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_last_used_step BIGINT NULL DEFAULT NULL AFTER two_factor_recovery_codes");
                }
                if (!in_array('two_factor_reenroll_required', $userCols, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_reenroll_required TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_last_used_step");
                }
            }

            if (!in_array('login_attempts', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS login_attempts (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        ip_address VARCHAR(45) NOT NULL,
                        username VARCHAR(100) NOT NULL,
                        attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        success TINYINT(1) NOT NULL DEFAULT 0,
                        PRIMARY KEY (id),
                        KEY idx_attempt_ip (ip_address, attempt_time),
                        KEY idx_attempt_user (username, attempt_time)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('two_factor_attempts', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS two_factor_attempts (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        user_id INT UNSIGNED NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        attempt_type ENUM('totp', 'recovery') NOT NULL DEFAULT 'totp',
                        success TINYINT(1) NOT NULL DEFAULT 0,
                        attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_2fa_attempts (user_id, ip_address, attempt_time, success)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('security_audit_log', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS security_audit_log (
                        audit_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        actor_user_id INT UNSIGNED NULL DEFAULT NULL,
                        target_user_id INT UNSIGNED NULL DEFAULT NULL,
                        action VARCHAR(50) NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        user_agent VARCHAR(255) NULL DEFAULT NULL,
                        metadata TEXT NULL DEFAULT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (audit_id),
                        KEY idx_audit_actor (actor_user_id),
                        KEY idx_audit_target (target_user_id),
                        KEY idx_audit_action (action, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('user_sessions', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS user_sessions (
                        session_id VARCHAR(64) NOT NULL PRIMARY KEY,
                        user_id INT UNSIGNED NULL DEFAULT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        user_agent VARCHAR(255) NOT NULL,
                        auth_method ENUM('password', 'password_2fa', 'direct_2fa') NOT NULL DEFAULT 'password',
                        login_at DATETIME(3) NOT NULL,
                        last_heartbeat_at DATETIME(3) NOT NULL,
                        logout_at DATETIME(3) NULL DEFAULT NULL,
                        duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                        logout_reason ENUM('user_logout', 'timeout', 'revoked', 'session_expired') NULL DEFAULT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_sessions_user_login (user_id, login_at),
                        INDEX idx_sessions_last_heartbeat (last_heartbeat_at),
                        CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('audit_logs', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS audit_logs (
                        log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        session_id VARCHAR(64) NULL,
                        user_id INT UNSIGNED NULL DEFAULT NULL,
                        username_snapshot VARCHAR(50) NOT NULL,
                        user_role_snapshot VARCHAR(20) NOT NULL,
                        category ENUM('navigation', 'pos_order', 'inventory', 'catalog', 'account', 'security', 'system') NOT NULL,
                        action VARCHAR(64) NOT NULL,
                        description VARCHAR(255) NOT NULL,
                        target_type VARCHAR(50) NULL DEFAULT NULL,
                        target_id VARCHAR(64) NULL DEFAULT NULL,
                        metadata_json JSON NULL DEFAULT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                        INDEX idx_audit_created (created_at DESC),
                        INDEX idx_audit_user_created (user_id, created_at DESC),
                        INDEX idx_audit_category_created (category, created_at DESC),
                        INDEX idx_audit_action (action),
                        INDEX idx_audit_session (session_id),
                        CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            if (!in_array('waste_log', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS waste_log (
                        waste_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        transaction_id INT UNSIGNED NULL DEFAULT NULL,
                        product_id INT UNSIGNED NULL DEFAULT NULL,
                        product_name VARCHAR(100) NULL DEFAULT NULL,
                        size_code VARCHAR(30) NULL DEFAULT NULL,
                        size_label VARCHAR(50) NULL DEFAULT NULL,
                        quantity INT UNSIGNED NOT NULL DEFAULT 1,
                        item_id INT UNSIGNED NULL DEFAULT NULL,
                        item_name VARCHAR(100) NOT NULL,
                        unit VARCHAR(20) NOT NULL,
                        amount_wasted DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        cost_cents INT NOT NULL DEFAULT 0,
                        estimated_cost_php DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        wasted_by_user_id INT UNSIGNED NULL DEFAULT NULL,
                        reason VARCHAR(255) NOT NULL DEFAULT '',
                        wasted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (waste_id),
                        KEY idx_wl_tx (transaction_id),
                        KEY idx_wl_product (product_id),
                        KEY idx_wl_item (item_id),
                        KEY idx_wl_user (wasted_by_user_id),
                        KEY idx_wl_time (wasted_at),
                        CONSTRAINT fk_wl_tx FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id) ON DELETE CASCADE,
                        CONSTRAINT fk_wl_user FOREIGN KEY (wasted_by_user_id) REFERENCES users (user_id) ON DELETE SET NULL,
                        CONSTRAINT fk_wl_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            // Allow manual waste entries not tied to a transaction or inventory item
            try {
                $txCol = $pdo->query("SHOW COLUMNS FROM waste_log LIKE 'transaction_id'")->fetch(PDO::FETCH_ASSOC);
                if ($txCol && $txCol['Null'] === 'NO') {
                    $pdo->exec("ALTER TABLE waste_log MODIFY COLUMN transaction_id INT UNSIGNED NULL DEFAULT NULL");
                }
            } catch (Throwable $e) {}

            try {
                $itemCol = $pdo->query("SHOW COLUMNS FROM waste_log LIKE 'item_id'")->fetch(PDO::FETCH_ASSOC);
                if ($itemCol && $itemCol['Null'] === 'NO') {
                    $pdo->exec("ALTER TABLE waste_log MODIFY COLUMN item_id INT UNSIGNED NULL DEFAULT NULL");
                }
            } catch (Throwable $e) {}

            if (!in_array('waste_report_summary', $tables, true)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS waste_report_summary (
                        summary_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        summary_date DATE NOT NULL,
                        summary_hour TINYINT UNSIGNED NOT NULL DEFAULT 255,
                        period_type ENUM('hourly', 'daily') NOT NULL DEFAULT 'daily',
                        total_events INT UNSIGNED NOT NULL DEFAULT 0,
                        total_items_wasted DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                        total_cost_cents BIGINT NOT NULL DEFAULT 0,
                        total_income_loss_cents BIGINT NOT NULL DEFAULT 0,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (summary_id),
                        UNIQUE KEY uq_period_summary (summary_date, summary_hour, period_type),
                        KEY idx_summary_date (summary_date)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } else {
                try {
                    $hourCol = $pdo->query("SHOW COLUMNS FROM waste_report_summary LIKE 'summary_hour'")->fetch(PDO::FETCH_ASSOC);
                    if ($hourCol && $hourCol['Null'] === 'YES') {
                        // Consolidate duplicate daily rows before enforcing NOT NULL unique constraint
                        $pdo->exec("
                            CREATE TEMPORARY TABLE IF NOT EXISTS tmp_daily_summary AS
                            SELECT summary_date,
                                   SUM(total_events) AS total_events,
                                   SUM(total_items_wasted) AS total_items_wasted,
                                   SUM(total_cost_cents) AS total_cost_cents,
                                   SUM(total_income_loss_cents) AS total_income_loss_cents
                              FROM waste_report_summary
                             WHERE period_type = 'daily'
                             GROUP BY summary_date;
                        ");
                        $pdo->exec("DELETE FROM waste_report_summary WHERE period_type = 'daily'");
                        $pdo->exec("ALTER TABLE waste_report_summary MODIFY COLUMN summary_hour TINYINT UNSIGNED NOT NULL DEFAULT 255");
                        $pdo->exec("
                            INSERT INTO waste_report_summary (
                                summary_date, summary_hour, period_type, total_events,
                                total_items_wasted, total_cost_cents, total_income_loss_cents
                            )
                            SELECT summary_date, 255, 'daily', total_events,
                                   total_items_wasted, total_cost_cents, total_income_loss_cents
                              FROM tmp_daily_summary;
                        ");
                        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_daily_summary;");
                    }
                } catch (Throwable $e) {}
            }

            // Seed default authorized accounts if users table is empty
            $userCount = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($userCount === 0) {
                $baristaHash = password_hash('barista123', PASSWORD_DEFAULT);
                $managerHash = password_hash('manager123', PASSWORD_DEFAULT);
                $seedStmt = $pdo->prepare("
                    INSERT INTO users (full_name, username, email, password_hash, role, status)
                    VALUES 
                    ('DK Cafe Barista', 'barista', 'barista@cafeflow.com', ?, 'barista', 'active'),
                    ('Manager', 'manager', 'manager@cafeflow.com', ?, 'manager', 'active')
                ");
                $seedStmt->execute([$baristaHash, $managerHash]);
            }

            // Ensure uq_prod_size includes size_label for flexible multi-temp options
            try {
                $idxInfo = $pdo->query("SHOW INDEX FROM product_size_prices WHERE Key_name = 'uq_prod_size'")->fetchAll();
                $idxCols = array_column($idxInfo, 'Column_name');
                if (!in_array('size_label', $idxCols, true)) {
                    $pdo->exec("ALTER TABLE product_size_prices DROP INDEX uq_prod_size, ADD UNIQUE KEY uq_prod_size (product_id, size_code, size_label)");
                }
            } catch (Throwable $e) {
                // Table might not exist yet or index already updated
            }

            // Populate essential raw supplies from reference inventory if not present
            $pdo->exec("
                INSERT IGNORE INTO inventory (item_id, name, current_stock, unit_measure, restock_threshold) VALUES
                (18, '16oz cup', 500, 'pcs', 50),
                (19, '22oz cup', 500, 'pcs', 50),
                (20, 'Flat lid', 1000, 'pcs', 100),
                (16, 'Bendable straw', 1000, 'pcs', 100),
                (17, 'Boba straw', 500, 'pcs', 50),
                (23, '12oz Hot cup & lid', 500, 'pcs', 50),
                (61, 'Full Cream Milk', 10000, 'ml', 2000),
                (6, 'Caramel syrup', 2000, 'ml', 300),
                (8, 'Vanilla syrup', 2000, 'ml', 300),
                (10, 'Chocolate sauce', 2000, 'ml', 300)
            ");

            // Link Butter Croissant and French Fries to inventory if not already linked
            $pdo->exec("
                UPDATE menu_products SET linked_inventory_id = 21, deduction_amount = 1.00 WHERE name = 'Butter Croissant' AND linked_inventory_id IS NULL;
                UPDATE menu_products SET linked_inventory_id = 22, deduction_amount = 150.00 WHERE name = 'French Fries' AND linked_inventory_id IS NULL;
                INSERT IGNORE INTO product_ingredients (product_id, item_id, deduction_amount)
                SELECT product_id, 21, 1.00 FROM menu_products WHERE name = 'Butter Croissant';
                INSERT IGNORE INTO product_ingredients (product_id, item_id, deduction_amount)
                SELECT product_id, 22, 150.00 FROM menu_products WHERE name = 'French Fries';
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
            $configuredPort = defined('DB_PORT') ? (int) DB_PORT : 3306;
            $portsToTry = array_values(array_unique(array_filter([$configuredPort, 3306, 3307])));

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $lastException = null;
            foreach ($portsToTry as $port) {
                try {
                    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                    // If connection refused (code 2002 / wrong port), try next candidate port
                    if ($e->getCode() === 2002 || str_contains($e->getMessage(), '2002')) {
                        continue;
                    }
                    throw $e;
                }
            }

            if ($pdo === null) {
                throw $lastException;
            }

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
        $raw  = $GLOBALS['MOCK_INPUT_JSON'] ?? file_get_contents('php://input');
        $data = json_decode($raw === false || $raw === null ? '' : $raw, true);
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
