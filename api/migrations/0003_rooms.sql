-- 0003_rooms.sql (TSK-022)
-- Hotel rooms. Each room belongs to a room_type and has an evolving status
-- plus an optional cooldown window used by the anti-reentry policy.
--
-- See design.md §4.2 and §6.1.

CREATE TABLE `rooms` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`                  VARCHAR(32)     NOT NULL,
    `room_type_id`          BIGINT UNSIGNED NOT NULL,
    `status`                ENUM('FREE','RESERVED','OCCUPIED','OVERSTAY','EXITED','CLEANING','OUT_OF_SERVICE')
                            NOT NULL DEFAULT 'FREE',
    `simulated_override`    TINYINT(1)      NULL,
    `cooldown_until`        DATETIME(3)     NULL,
    `created_at`            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_rooms_code`            (`code`),
    KEY         `idx_rooms_status`          (`status`),
    KEY         `idx_rooms_room_type_id`    (`room_type_id`),
    CONSTRAINT `fk_rooms_room_type`
        FOREIGN KEY (`room_type_id`) REFERENCES `room_types`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
