-- 0000_bootstrap.sql
-- Placeholder migration used to validate the migration mechanism (TSK-006).
-- Real domain tables start at 0001 (TSK-020 onwards).
--
-- This migration is intentionally a no-op: it creates a harmless info table
-- that subsequent migrations may remove or ignore.

CREATE TABLE IF NOT EXISTS `_bootstrap_marker` (
    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `note` VARCHAR(64) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `_bootstrap_marker` (`id`, `note`) VALUES (1, 'api schema bootstrap ok');
