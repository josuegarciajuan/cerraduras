-- 0005_devices.sql (TSK-024)
-- Links a room to its physical devices: Raspberry Pi (RPI), electronic lock,
-- proximity sensor (door) and presence sensor (body inside the room).
--
-- Each (room_id, kind) is unique. RPI devices additionally link to an
-- api_clients row (the key used by that Raspberry).
--
-- See design.md §4.3.

CREATE TABLE `devices` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`         BIGINT UNSIGNED NOT NULL,
    `kind`            ENUM('RPI','LOCK','PROXIMITY','PRESENCE') NOT NULL,
    `external_id`     VARCHAR(128)    NOT NULL,
    `api_client_id`   BIGINT UNSIGNED NULL,
    `meta_json`       JSON            NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_devices_room_kind` (`room_id`, `kind`),
    UNIQUE KEY `uniq_devices_external`  (`kind`, `external_id`),
    KEY `idx_devices_api_client` (`api_client_id`),
    CONSTRAINT `fk_devices_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_devices_api_client`
        FOREIGN KEY (`api_client_id`) REFERENCES `api_clients`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
