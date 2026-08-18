-- 0012_debts.sql (TSK-031)
-- Local mirror of overstay debts before they are synced to the VB6 DB.
-- The API never computes the amount itself: the WS-VB6 DebtPricing function
-- computes it from `articulos` (30/60 min tiers). `amount_eur_snapshot` stores
-- the integer euros value returned by WS-VB6, for traceability.
--
-- See design.md §4.11, §18 and contracts.md §3.2.

CREATE TABLE `debts` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stay_id`              BIGINT UNSIGNED NOT NULL,
    `room_id`              BIGINT UNSIGNED NOT NULL,
    `exceso_minutos`       INT UNSIGNED    NOT NULL,
    `amount_eur_snapshot`  SMALLINT UNSIGNED NULL,
    `status`               ENUM('PENDING_SYNC','SYNCING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING_SYNC',
    `attempts`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`           VARCHAR(255)    NULL,
    `vb6_ack_ref`          VARCHAR(64)     NULL,
    `created_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_debts_status_updated` (`status`, `updated_at`),
    KEY `idx_debts_stay`           (`stay_id`),
    CONSTRAINT `fk_debts_stay`
        FOREIGN KEY (`stay_id`) REFERENCES `stays`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_debts_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
