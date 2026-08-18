-- 0006_time_slots.sql (TSK-025)
-- RENTABLE/FREE daily slots per room_type (decision P5 of requirements).
--
-- Coverage and non-overlap are enforced at application level (TimeSlotService):
--   - The union of slots for a given room_type MUST cover [00:00, 24:00).
--   - Slots MUST NOT overlap.
-- Cross-midnight slots are modeled by splitting them into two rows.
--
-- See design.md §4.4 and §7.

CREATE TABLE `time_slots` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_type_id`  BIGINT UNSIGNED NOT NULL,
    `starts_at`     TIME            NOT NULL,
    `ends_at`       TIME            NOT NULL,
    `kind`          ENUM('RENTABLE','FREE') NOT NULL,
    `created_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_time_slots_room_type` (`room_type_id`),
    CONSTRAINT `fk_time_slots_room_type`
        FOREIGN KEY (`room_type_id`) REFERENCES `room_types`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
