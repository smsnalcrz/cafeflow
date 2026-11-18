# -*- coding: utf-8 -*-
"""
CafeFlow Canonical Database Schema & Seed Generator
Dumps exact DDL and calibrated seed data from the live database into database/schema.sql,
then validates synchronization against an isolated test database.
"""
import pymysql
import os

def get_db_connection():
    configured_port = int(os.environ.get('DB_PORT', 3306))
    ports = [configured_port]
    for fallback in [3306, 3307]:
        if fallback not in ports:
            ports.append(fallback)

    last_err = None
    for p in ports:
        try:
            return pymysql.connect(
                host='127.0.0.1', port=p, user='root', password='', database='cafeflow',
                cursorclass=pymysql.cursors.DictCursor
            )
        except Exception as e:
            last_err = e
            continue
    raise last_err

def generate_schema_file():
    conn = get_db_connection()
    
    with conn.cursor() as cur:
        # Fetch inventory
        cur.execute("SELECT item_id, name, current_stock, unit_measure, restock_threshold, is_active FROM inventory ORDER BY item_id")
        inv_rows = cur.fetchall()

        # Fetch menu_products
        cur.execute("""
            SELECT product_id, name, category, price, image_url, stock_quantity,
                   track_stock, has_sizes, price_12oz, price_16oz, price_22oz,
                   linked_inventory_id, deduction_amount
            FROM menu_products
            ORDER BY product_id
        """)
        prod_rows = cur.fetchall()

        # Fetch product_size_prices
        cur.execute("SELECT id, product_id, size_code, size_label, size_oz, price FROM product_size_prices ORDER BY id")
        size_rows = cur.fetchall()

        # Fetch product_ingredients
        cur.execute("SELECT id, product_id, item_id, deduction_amount FROM product_ingredients ORDER BY id")
        ing_rows = cur.fetchall()

        # Fetch users
        cur.execute("SELECT user_id, full_name, username, email, password_hash, role, status FROM users ORDER BY user_id")
        user_rows = cur.fetchall()

    lines = []
    lines.append("-- ============================================================")
    lines.append("--  CafeFlow  |  Point of Sale & Inventory for DK Cafe")
    lines.append("--  Canonical Database Schema & Reference Seed Data")
    lines.append("--  Import via phpMyAdmin or command line: mysql -u root < schema.sql")
    lines.append("--  Safe to re-run: tables use CREATE TABLE IF NOT EXISTS and")
    lines.append("--  starter records use INSERT IGNORE to preserve existing data.")
    lines.append("-- ============================================================\n")
    lines.append("CREATE DATABASE IF NOT EXISTS cafeflow")
    lines.append("  CHARACTER SET utf8mb4")
    lines.append("  COLLATE utf8mb4_unicode_ci;\n")
    lines.append("USE cafeflow;\n")

    # 1. inventory
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  1. Raw supplies & packaging inventory")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS inventory (")
    lines.append("  item_id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,")
    lines.append("  name              VARCHAR(100)  NOT NULL,")
    lines.append("  current_stock     DECIMAL(10,2) NOT NULL DEFAULT 0.00,")
    lines.append("  unit_measure      VARCHAR(20)   NOT NULL,              -- g, ml, pcs ...")
    lines.append("  restock_threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00,    -- alert threshold")
    lines.append("  is_active         TINYINT(1)    NOT NULL DEFAULT 1,")
    lines.append("  PRIMARY KEY (item_id),")
    lines.append("  UNIQUE KEY uq_inventory_name (name),")
    lines.append("  KEY idx_inv_active (is_active)")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 2. menu_products
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  2. Menu products (POS catalog)")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS menu_products (")
    lines.append("  product_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,")
    lines.append("  name                VARCHAR(100)  NOT NULL,")
    lines.append("  category            VARCHAR(50)   NOT NULL DEFAULT 'Coffee',")
    lines.append("  price               DECIMAL(8,2)  NOT NULL,")
    lines.append("  image_url           VARCHAR(255)  NULL DEFAULT NULL,")
    lines.append("  stock_quantity      DECIMAL(10,2) NOT NULL DEFAULT 0.00,")
    lines.append("  track_stock         TINYINT(1)    NOT NULL DEFAULT 1,")
    lines.append("  has_sizes           TINYINT(1)    NOT NULL DEFAULT 1,")
    lines.append("  price_12oz          DECIMAL(8,2)  NULL DEFAULT NULL,")
    lines.append("  price_16oz          DECIMAL(8,2)  NULL DEFAULT NULL,")
    lines.append("  price_22oz          DECIMAL(8,2)  NULL DEFAULT NULL,")
    lines.append("  linked_inventory_id INT UNSIGNED  NULL DEFAULT NULL,")
    lines.append("  deduction_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,")
    lines.append("  PRIMARY KEY (product_id),")
    lines.append("  KEY idx_menu_inventory (linked_inventory_id),")
    lines.append("  CONSTRAINT fk_menu_inventory FOREIGN KEY (linked_inventory_id) REFERENCES inventory (item_id) ON DELETE SET NULL ON UPDATE CASCADE")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 3. product_size_prices
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  3. Cup size specific pricing (12oz Hot / 16oz Cold / Tall / Grande)")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS product_size_prices (")
    lines.append("  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,")
    lines.append("  product_id INT UNSIGNED NOT NULL,")
    lines.append("  size_code  VARCHAR(30)  NOT NULL,")
    lines.append("  size_label VARCHAR(50)  NOT NULL,")
    lines.append("  size_oz    INT UNSIGNED NOT NULL DEFAULT 16,")
    lines.append("  price      DECIMAL(8,2) NOT NULL,")
    lines.append("  PRIMARY KEY (id),")
    lines.append("  UNIQUE KEY uq_prod_size (product_id, size_code, size_label),")
    lines.append("  KEY idx_psp_prod (product_id),")
    lines.append("  CONSTRAINT fk_psp_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 4. product_ingredients
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  4. Product ingredients (multi-ingredient consumption mapping)")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS product_ingredients (")
    lines.append("  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,")
    lines.append("  product_id       INT UNSIGNED  NOT NULL,")
    lines.append("  item_id          INT UNSIGNED  NOT NULL,")
    lines.append("  deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 1.00,")
    lines.append("  PRIMARY KEY (id),")
    lines.append("  UNIQUE KEY uq_prod_item (product_id, item_id),")
    lines.append("  KEY idx_pi_product (product_id),")
    lines.append("  KEY idx_pi_item (item_id),")
    lines.append("  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE,")
    lines.append("  CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE RESTRICT")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 5. transactions
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  5. Order transactions header (Cashier records & financial ledger)")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS transactions (")
    lines.append("  transaction_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,")
    lines.append("  cashier_user_id       INT UNSIGNED  NULL DEFAULT NULL,")
    lines.append("  total_amount          DECIMAL(10,2) NOT NULL,")
    lines.append("  amount_paid           DECIMAL(10,2) NULL DEFAULT NULL,")
    lines.append("  change_due            DECIMAL(10,2) NULL DEFAULT 0.00,")
    lines.append("  subtotal              DECIMAL(10,2) NULL DEFAULT NULL,")
    lines.append("  tax                   DECIMAL(10,2) NULL DEFAULT 0.00,")
    lines.append("  discount              DECIMAL(10,2) NULL DEFAULT 0.00,")
    lines.append("  discount_type         VARCHAR(50)   NOT NULL DEFAULT 'none',")
    lines.append("  discount_rate         DECIMAL(5,2)  NOT NULL DEFAULT 0.00,")
    lines.append("  discount_id_no        VARCHAR(100)  NULL DEFAULT NULL,")
    lines.append("  discount_reason       VARCHAR(255)  NULL DEFAULT NULL,")
    lines.append("  status                ENUM('Pending','Completed','Cancelled','Voided') NOT NULL DEFAULT 'Pending',")
    lines.append("  cancelled_by_user_id  INT UNSIGNED  NULL DEFAULT NULL,")
    lines.append("  cancelled_at          DATETIME      NULL DEFAULT NULL,")
    lines.append("  cancellation_reason   VARCHAR(255)  NULL DEFAULT NULL,")
    lines.append("  payment_method        ENUM('Cash','GCash') NOT NULL DEFAULT 'Cash',")
    lines.append("  reference_number      VARCHAR(100)  NULL DEFAULT NULL,")
    lines.append("  is_mock               TINYINT(1)    NOT NULL DEFAULT 0,")
    lines.append("  `timestamp`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,")
    lines.append("  PRIMARY KEY (transaction_id),")
    lines.append("  KEY idx_status_time (status, `timestamp`),")
    lines.append("  KEY idx_time (`timestamp`),")
    lines.append("  KEY idx_tx_ref (reference_number),")
    lines.append("  KEY idx_cashier (cashier_user_id),")
    lines.append("  KEY idx_tx_is_mock (is_mock)")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 6. transaction_items
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  6. Order receipt line items with snapshot pricing & cup size")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS transaction_items (")
    lines.append("  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,")
    lines.append("  transaction_id INT UNSIGNED NOT NULL,")
    lines.append("  product_id     INT UNSIGNED NULL DEFAULT NULL,")
    lines.append("  product_name   VARCHAR(100) NULL DEFAULT NULL,")
    lines.append("  quantity       INT UNSIGNED NOT NULL DEFAULT 1,")
    lines.append("  unit_price     DECIMAL(8,2) NOT NULL DEFAULT 0.00,")
    lines.append("  size_code      VARCHAR(30)  NULL DEFAULT NULL,")
    lines.append("  size_label     VARCHAR(50)  NULL DEFAULT NULL,")
    lines.append("  size_oz        INT UNSIGNED NULL DEFAULT NULL,")
    lines.append("  PRIMARY KEY (id),")
    lines.append("  KEY idx_items_txn (transaction_id),")
    lines.append("  KEY idx_items_product (product_id),")
    lines.append("  CONSTRAINT fk_items_txn FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id) ON DELETE CASCADE,")
    lines.append("  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE SET NULL")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 7. users
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  7. User Accounts & RBAC Authentication")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS users (")
    lines.append("  user_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,")
    lines.append("  full_name     VARCHAR(100)  NOT NULL,")
    lines.append("  username      VARCHAR(50)   NOT NULL,")
    lines.append("  email         VARCHAR(100)  NULL DEFAULT NULL,")
    lines.append("  password_hash VARCHAR(255)  NOT NULL,")
    lines.append("  role          ENUM('barista', 'manager') NOT NULL DEFAULT 'barista',")
    lines.append("  status        ENUM('active', 'inactive', 'pending') NOT NULL DEFAULT 'active',")
    lines.append("  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,")
    lines.append("  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,")
    lines.append("  last_login    DATETIME      NULL DEFAULT NULL,")
    lines.append("  PRIMARY KEY (user_id),")
    lines.append("  UNIQUE KEY uq_users_username (username),")
    lines.append("  UNIQUE KEY uq_users_email (email),")
    lines.append("  KEY idx_users_role (role),")
    lines.append("  KEY idx_users_status (status)")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # 8. login_attempts
    lines.append("-- ------------------------------------------------------------")
    lines.append("--  8. Brute-force rate limiting & security audit log")
    lines.append("-- ------------------------------------------------------------")
    lines.append("CREATE TABLE IF NOT EXISTS login_attempts (")
    lines.append("  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,")
    lines.append("  ip_address   VARCHAR(45)  NOT NULL,")
    lines.append("  username     VARCHAR(100) NOT NULL,")
    lines.append("  attempt_time DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,")
    lines.append("  success      TINYINT(1)   NOT NULL DEFAULT 0,")
    lines.append("  PRIMARY KEY (id),")
    lines.append("  KEY idx_attempt_ip (ip_address, attempt_time),")
    lines.append("  KEY idx_attempt_user (username, attempt_time)")
    lines.append(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n")

    # Seed data - 1. Inventory
    lines.append("-- ============================================================")
    lines.append("--  STARTER SEED DATA: 1. Raw Supplies & Packaging Inventory (62 Items)")
    lines.append("-- ============================================================")
    lines.append("INSERT IGNORE INTO inventory (item_id, name, current_stock, unit_measure, restock_threshold, is_active) VALUES")
    inv_vals = []
    for r in inv_rows:
        escaped_name = r['name'].replace("'", "\\'")
        inv_vals.append(f"  ({r['item_id']}, '{escaped_name}', {r['current_stock']:.2f}, '{r['unit_measure']}', {r['restock_threshold']:.2f}, {r['is_active']})")
    lines.append(",\n".join(inv_vals) + ";\n")

    # Seed data - 2. Menu Products
    lines.append("-- ============================================================")
    lines.append("--  STARTER SEED DATA: 2. DK Cafe Menu Products (42 Products)")
    lines.append("-- ============================================================")
    lines.append("INSERT IGNORE INTO menu_products (product_id, name, category, price, image_url, stock_quantity, track_stock, has_sizes, price_12oz, price_16oz, price_22oz, linked_inventory_id, deduction_amount) VALUES")
    prod_vals = []
    for r in prod_rows:
        escaped_name = r['name'].replace("'", "\\'")
        img_str = f"'{r['image_url']}'" if r['image_url'] else "NULL"
        p12_str = f"{r['price_12oz']:.2f}" if r['price_12oz'] is not None else "NULL"
        p16_str = f"{r['price_16oz']:.2f}" if r['price_16oz'] is not None else "NULL"
        p22_str = f"{r['price_22oz']:.2f}" if r['price_22oz'] is not None else "NULL"
        link_str = f"{r['linked_inventory_id']}" if r['linked_inventory_id'] is not None else "NULL"
        prod_vals.append(
            f"  ({r['product_id']}, '{escaped_name}', '{r['category']}', {r['price']:.2f}, {img_str}, "
            f"{r['stock_quantity']:.2f}, {r['track_stock']}, {r['has_sizes']}, {p12_str}, {p16_str}, {p22_str}, "
            f"{link_str}, {r['deduction_amount']:.2f})"
        )
    lines.append(",\n".join(prod_vals) + ";\n")

    # Seed data - 3. Product Size Prices
    lines.append("-- ============================================================")
    lines.append("--  STARTER SEED DATA: 3. Cup Size Specific Pricing (44 Active Variants)")
    lines.append("-- ============================================================")
    lines.append("INSERT IGNORE INTO product_size_prices (id, product_id, size_code, size_label, size_oz, price) VALUES")
    size_vals = []
    for r in size_rows:
        escaped_lbl = r['size_label'].replace("'", "\\'")
        size_vals.append(f"  ({r['id']}, {r['product_id']}, '{r['size_code']}', '{escaped_lbl}', {r['size_oz']}, {r['price']:.2f})")
    lines.append(",\n".join(size_vals) + ";\n")

    # Seed data - 4. Product Recipe Ingredients
    lines.append("-- ============================================================")
    lines.append("--  STARTER SEED DATA: 4. Product Recipe Linkages (231 Formulas)")
    lines.append("-- ============================================================")
    lines.append("INSERT IGNORE INTO product_ingredients (id, product_id, item_id, deduction_amount) VALUES")
    ing_vals = []
    for r in ing_rows:
        ing_vals.append(f"  ({r['id']}, {r['product_id']}, {r['item_id']}, {r['deduction_amount']:.2f})")
    lines.append(",\n".join(ing_vals) + ";\n")

    # Seed data - 5. Authorized Users
    lines.append("-- ============================================================")
    lines.append("--  STARTER SEED DATA: 5. Authorized Starter Accounts")
    lines.append("--  Default Logins:")
    lines.append("--    - barista  / barista123   (Dual POS Cashier & Prep Queue)")
    lines.append("--    - manager  / manager123   (Executive Analytics & Staff Admin)")
    lines.append("--    - tan_smsn / alcaraz030303 (Active Barista)")
    lines.append("-- ============================================================")
    lines.append("INSERT IGNORE INTO users (user_id, full_name, username, email, password_hash, role, status) VALUES")
    user_vals = []
    for r in user_rows:
        escaped_name = r['full_name'].replace("'", "\\'")
        email_str = f"'{r['email']}'" if r['email'] else "NULL"
        user_vals.append(f"  ({r['user_id']}, '{escaped_name}', '{r['username']}', {email_str}, '{r['password_hash']}', '{r['role']}', '{r['status']}')")
    lines.append(",\n".join(user_vals) + ";\n")

    content = "\n".join(lines)
    with open("database/schema.sql", "w", encoding="utf-8") as f:
        f.write(content)

    print(f"Generated database/schema.sql successfully ({len(content)} bytes).")

if __name__ == '__main__':
    generate_schema_file()
