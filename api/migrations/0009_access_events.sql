-- 0009_access_events.sql (TSK-028)
-- Every access attempt/action (QR validation, open, auto-lock, manual-lock,
-- denied). Both successful and failed outcomes are persisted for audit.
--
-- See design.md §4.8.

CREATE TABLE `access_events` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`           BIGINT UNSIGNED NOT NULL,
    `stay_id`           BIGINT UNSIGNED NULL,
    `kind`              ENUM('QR_VALIDATE','OPEN','AUTO_LOCK','MANUAL_LOCK','DENIED') NOT NULL,
    `result`            ENUM('OK','FAIL') NOT NULL,
    `reason`            VARCHAR(64)     NULL,
    `provider`          ENUM('TUYA','SIMULATED') NOT NULL,
    `correlation_id`    CHAR(26)        NOT NULL,
    `meta_json`         JSON            NULL,
    `occurred_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_access_events_room_time` (`room_id`, `occurred_at`),
    KEY `idx_access_events_stay`      (`stay_id`),
    KEY `idx_access_events_corr`      (`correlation_id`),
    CONSTRAINT `fk_access_events_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_access_events_stay`
        FOREIGN KEY (`stay_id`) REFERENCES `stays`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
