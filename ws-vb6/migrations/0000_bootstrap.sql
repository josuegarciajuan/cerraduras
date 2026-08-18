-- 0000_bootstrap.sql (WS-VB6 auxiliary database)
-- Placeholder migration to validate the migration mechanism (TSK-006).
-- Real tables (e.g. vb6_bridge_idempotency) come in TSK-121.
--
-- This migration must only touch the auxiliary schema, never bs2026.

CREATE TABLE IF NOT EXISTS `_bootstrap_marker` (
    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `note` VARCHAR(64) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `_bootstrap_marker` (`id`, `note`) VALUES (1, 'ws-vb6 aux schema bootstrap ok');
