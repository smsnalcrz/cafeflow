-- Migration 006: User Remember Me Persistent Authentication Tokens
CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
    `token_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `selector` CHAR(16) NOT NULL,
    `hashed_validator` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_remember_selector` (`selector`),
    INDEX `idx_remember_user` (`user_id`),
    INDEX `idx_remember_expires` (`expires_at`),
    CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
