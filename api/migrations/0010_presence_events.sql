-- 0010_presence_events.sql (TSK-029)
-- Raw sensor events (proximity = door open/closed; presence = body present/absent).
--
-- source_event_id is a UNIQUE NULLABLE column used for idempotency when Tuya
-- (or the simulator) re-sends an event. NULL means "no external id" and will
-- not participate in uniqueness.
--
-- See design.md §4.9.

CREATE TABLE `presence_events` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`           BIGINT UNSIGNED NOT NULL,
    `sensor`            ENUM('PROXIMITY','PRESENCE') NOT NULL,
    `value`             ENUM('OPEN','CLOSED','PRESENT','ABSENT') NOT NULL,
    `provider`          ENUM('TUYA','SIMULATED') NOT NULL,
    `occurred_at`       DATETIME(3)     NOT NULL,
    `received_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `source_event_id`   VARCHAR(128)    NULL,
    `meta_json`         JSON            NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_presence_source` (`source_event_id`),
    KEY `idx_presence_room_time`      (`room_id`, `occurred_at`),
    CONSTRAINT `fk_presence_events_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
