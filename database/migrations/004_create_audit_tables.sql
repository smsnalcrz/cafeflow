-- ================================================================================
-- Migration 004: Create Audit Tables (user_sessions & audit_logs)
-- CafeFlow Audit Log & User Activity Tracking Subsystem
-- ================================================================================

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `session_id` VARCHAR(64) NOT NULL PRIMARY KEY,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) NOT NULL,
    `auth_method` ENUM('password', 'password_2fa', 'direct_2fa') NOT NULL DEFAULT 'password',
    `login_at` DATETIME(3) NOT NULL,
    `last_heartbeat_at` DATETIME(3) NOT NULL,
    `logout_at` DATETIME(3) NULL DEFAULT NULL,
    `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `logout_reason` ENUM('user_logout', 'timeout', 'revoked', 'session_expired') NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sessions_user_login` (`user_id`, `login_at`),
    INDEX `idx_sessions_last_heartbeat` (`last_heartbeat_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(64) NULL,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `username_snapshot` VARCHAR(50) NOT NULL,
    `user_role_snapshot` VARCHAR(20) NOT NULL,
    `category` ENUM('navigation', 'pos_order', 'inventory', 'catalog', 'account', 'security', 'system') NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `target_type` VARCHAR(50) NULL DEFAULT NULL,
    `target_id` VARCHAR(64) NULL DEFAULT NULL,
    `metadata_json` JSON NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX `idx_audit_created` (`created_at` DESC),
    INDEX `idx_audit_user_created` (`user_id`, `created_at` DESC),
    INDEX `idx_audit_category_created` (`category`, `created_at` DESC),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_session` (`session_id`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) 
        REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
