-- =====================================================================
-- CafeFlow Database Migration: 005_order_concurrency_and_inventory.sql
-- Enforces checkout idempotency, atomic stock deduction tracking,
-- and immutable order ingredient consumption snapshots (Tasks 04, 05, 06, 17, 31).
-- =====================================================================

-- 1. Add idempotency_key and stock_deducted to transactions table
ALTER TABLE transactions 
  ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL DEFAULT NULL AFTER reference_number,
  ADD COLUMN IF NOT EXISTS stock_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER idempotency_key;

-- Add index on idempotency_key if not exists
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics 
               WHERE table_schema = DATABASE() 
                 AND table_name = 'transactions' 
                 AND index_name = 'uq_tx_idempotency_key');
SET @sql := IF(@exist = 0, 'ALTER TABLE transactions ADD UNIQUE KEY uq_tx_idempotency_key (idempotency_key)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create order_ingredients table for immutable consumption snapshots
CREATE TABLE IF NOT EXISTS order_ingredients (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  transaction_id INT UNSIGNED  NOT NULL,
  item_id        INT UNSIGNED  NOT NULL,
  item_name      VARCHAR(100)  NOT NULL,
  unit           VARCHAR(30)   NOT NULL,
  amount_used    DECIMAL(10,2) NOT NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_oi_tx (transaction_id),
  KEY idx_oi_item (item_id),
  CONSTRAINT fk_oi_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (transaction_id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_item FOREIGN KEY (item_id) REFERENCES inventory (item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
