-- ============================================================
--  CafeFlow  |  POS & Inventory for DK Cafe
--  Import with phpMyAdmin (Import tab) or:  mysql -u root < schema.sql
--  Safe to re-run: tables are only created if missing and the
--  starter data uses INSERT IGNORE, so existing data is kept.
-- ============================================================

CREATE DATABASE IF NOT EXISTS cafeflow
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE cafeflow;

-- ------------------------------------------------------------
--  Raw supplies
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory (
  item_id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100)  NOT NULL,
  current_stock     DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_measure      VARCHAR(20)   NOT NULL,              -- g, ml, pcs ...
  restock_threshold DECIMAL(10,2) NOT NULL DEFAULT 0,    -- flag as "Low" at or below this
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_inventory_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Items sold at the counter
--  linked_inventory_id may be NULL for items you don't track.
-- ------------------------------------------------------------
--  Items sold at the counter (manually managed by user)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_products (
  product_id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name                VARCHAR(100)  NOT NULL,
  category            VARCHAR(50)   NOT NULL DEFAULT 'Coffee',
  price               DECIMAL(8,2)  NOT NULL,
  image_url           VARCHAR(255)  NULL DEFAULT NULL,
  stock_quantity      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  track_stock         TINYINT(1)    NOT NULL DEFAULT 1,
  linked_inventory_id INT UNSIGNED  NULL,
  deduction_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,  -- taken from stock per unit sold
  PRIMARY KEY (product_id),
  KEY idx_menu_inventory (linked_inventory_id),
  CONSTRAINT fk_menu_inventory
    FOREIGN KEY (linked_inventory_id) REFERENCES inventory (item_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Product ingredients (recipe linkage for raw supplies)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_ingredients (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id       INT UNSIGNED  NOT NULL,
  item_id          INT UNSIGNED  NOT NULL,
  deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prod_item (product_id, item_id),
  KEY idx_pi_product (product_id),
  KEY idx_pi_item (item_id),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES menu_products (product_id) ON DELETE CASCADE,
  CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  One row per order.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
  transaction_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  total_amount   DECIMAL(10,2) NOT NULL,
  amount_paid    DECIMAL(10,2) NULL DEFAULT NULL,
  change_due     DECIMAL(10,2) NULL DEFAULT 0.00,
  subtotal       DECIMAL(10,2) NULL DEFAULT NULL,
  tax            DECIMAL(10,2) NULL DEFAULT 0.00,
  discount       DECIMAL(10,2) NULL DEFAULT 0.00,
  status         ENUM('Pending','Completed') NOT NULL DEFAULT 'Pending',
  payment_method ENUM('Cash','GCash')        NOT NULL DEFAULT 'Cash',
  `timestamp`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  KEY idx_status_time (status, `timestamp`),
  KEY idx_time (`timestamp`)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Receipt lines with snapshot price and product name
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transaction_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NULL DEFAULT NULL,
  product_name   VARCHAR(100) NULL DEFAULT NULL,
  quantity       INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_items_txn (transaction_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_txn
    FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_items_product
    FOREIGN KEY (product_id) REFERENCES menu_products (product_id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  Starter raw supplies (optional)
-- ============================================================
INSERT IGNORE INTO inventory (item_id, name, current_stock, unit_measure, restock_threshold) VALUES
  (1, 'Espresso Beans',   2000, 'g',   500),
  (2, 'Matcha Powder',     300, 'g',   100),
  (3, 'Chocolate Powder', 1000, 'g',   300),
  (4, 'Butter Croissant',   20, 'pcs',   5),
  (5, 'Bottled Water',      48, 'pcs',  12);

