-- 0020_crm_panel.sql (F22: CRM Login + Auth)
-- Creates tables for CRM panel authentication (session-based, separate from API keys).
-- See requirements.md RF panel, design.md §4 (CRM additions).

-- ============================================================
-- TABLE: crm_users — Panel de control users
-- ============================================================
CREATE TABLE IF NOT EXISTS `crm_users` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`        VARCHAR(64)     NOT NULL,
    `password_hash`   VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash (PHP password_hash)',
    `role`            ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
    `display_name`    VARCHAR(128)    NULL,
    `active`          TINYINT(1)      NOT NULL DEFAULT 1,
    `last_login_at`   DATETIME(3)     NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_crm_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: crm_sessions — Active panel sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `crm_sessions` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED NOT NULL,
    `token`           CHAR(64)        NOT NULL COMMENT 'SHA-256 of random 32 bytes',
    `ip_address`      VARCHAR(45)     NULL,
    `expires_at`      DATETIME(3)     NOT NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_crm_sessions_token` (`token`),
    KEY `idx_crm_sessions_user` (`user_id`),
    KEY `idx_crm_sessions_expires` (`expires_at`),
    CONSTRAINT `fk_crm_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `crm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: Default admin user
-- Password: admin123 (bcrypt hash below)
-- CHANGE IMMEDIATELY ON FIRST LOGIN
-- ============================================================
INSERT IGNORE INTO `crm_users` (`username`, `password_hash`, `role`, `display_name`, `active`)
VALUES ('admin', '$2y$10$Y89j0PLyMShhTrWioni5lOjwgT7FyV12Db2QQWuatgjflvZDQ8Fea', 'admin', 'Administrador', 1);
