-- 0011_iot_sessions.sql (TSK-030)
-- Derived state per room (one row per room) computed from the latest sensor
-- events. Used by ExitRuleEvaluator.
--
-- See design.md §4.10 and §8.2.

CREATE TABLE `iot_sessions` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`             BIGINT UNSIGNED NOT NULL,
    `stay_id`             BIGINT UNSIGNED NULL,
    `door_state`          ENUM('OPEN','CLOSED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    `presence_state`      ENUM('PRESENT','ABSENT','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    `last_open_at`        DATETIME(3)   NULL,
    `last_absent_since`   DATETIME(3)   NULL,
    `exit_evaluated_at`   DATETIME(3)   NULL,
    `updated_at`          DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_iot_room` (`room_id`),
    KEY         `idx_iot_stay` (`stay_id`),
    CONSTRAINT `fk_iot_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_iot_stay`
        FOREIGN KEY (`stay_id`) REFERENCES `stays`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
