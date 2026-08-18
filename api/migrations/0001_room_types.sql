-- 0001_room_types.sql (TSK-020)
-- Types of rooms used to scope time_slots, pricing defaults and operational
-- parameters (grace, exit-detection gap, reentry cooldown, QR window).
--
-- See design.md §4.1.

CREATE TABLE `room_types` (
    `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`                        VARCHAR(32)     NOT NULL,
    `name`                        VARCHAR(128)    NOT NULL,
    `grace_minutes`               SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `exit_presence_gap_seconds`   SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    `reentry_cooldown_seconds`    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    `qr_usage_window_minutes`     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `created_at`                  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`                  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_room_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
