-- 0014_outbox_vb6.sql (TSK-033)
-- Transactional outbox for events heading to WS-VB6.
-- Topics (decision P3):
--   - debt.created   -> POST /ws-vb6/v1/debts
--   - stay.overstay  -> POST /ws-vb6/v1/stays/events
--   - stay.closed    -> POST /ws-vb6/v1/stays/events
--
-- See design.md §4.14 and §13.

CREATE TABLE `outbox_vb6` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `topic`             VARCHAR(64)     NOT NULL,
    `payload_json`      JSON            NOT NULL,
    `idempotency_key`   VARCHAR(128)    NOT NULL,
    `status`            ENUM('PENDING','SENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
    `attempts`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at`   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `last_error`        VARCHAR(255)    NULL,
    `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_outbox_idem_key`        (`idempotency_key`),
    KEY         `idx_outbox_due`             (`status`, `next_attempt_at`),
    KEY         `idx_outbox_topic`           (`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
