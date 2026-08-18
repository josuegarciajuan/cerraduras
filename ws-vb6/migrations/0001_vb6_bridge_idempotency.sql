-- 0001_vb6_bridge_idempotency.sql (TSK-121)
-- Idempotency table for the WS-VB6 bridge.
-- Lives in the AUXILIARY schema (ws_vb6_aux), never in bs2026.
--
-- Ensures that POST /debts and POST /stays/events are idempotent:
-- the same Idempotency-Key is only processed once; subsequent requests
-- return the cached response without re-writing to the VB6 database.
--
-- See design.md §4.16 and §2.5.

CREATE TABLE `vb6_bridge_idempotency` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope`           VARCHAR(64)     NOT NULL,
    `key`             VARCHAR(128)    NOT NULL,
    `request_hash`    CHAR(64)        NOT NULL,
    `response_status` SMALLINT        NOT NULL,
    `response_body`   MEDIUMTEXT      NOT NULL,
    `vb6_write_summary` JSON          NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at`      DATETIME(3)     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_scope_key` (`scope`, `key`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
