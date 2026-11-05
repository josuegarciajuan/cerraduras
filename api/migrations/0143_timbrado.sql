-- 0143_timbrado.sql — F116: sala de tipo TIMBRADO (oficina)
--
-- Trazabilidad: F116 · RF-293…RF-298 · design.md §F116 · contracts.md §F116
--
-- Añade:
--   - columnas de temporización del timbrado en room_types;
--   - el tipo TIMBRADO;
--   - tablas timbre_movements y timbre_state;
--   - enlace camera_recordings.timbre_movement_id;
--   - permisos de rol para TIMBRADO (los mismos que ya tenían ALMACEN_BEBIDAS);
--   - retención configurable de vídeos del timbrado.

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `timbre_pre_open_presence_seconds` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 10 AFTER `presence_stale_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_scan_open_timeout_seconds` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 20 AFTER `timbre_pre_open_presence_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_presence_end_grace_seconds` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 3 AFTER `timbre_scan_open_timeout_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_door_close_grace_seconds` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 3 AFTER `timbre_presence_end_grace_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_max_recording_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 120 AFTER `timbre_door_close_grace_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_door_open_max_seconds` INT(10) UNSIGNED NOT NULL DEFAULT 60 AFTER `timbre_max_recording_seconds`,
    ADD COLUMN IF NOT EXISTS `timbre_light_off_delay_seconds` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 3 AFTER `timbre_door_open_max_seconds`;

INSERT INTO `room_types`
    (`code`, `name`, `grace_minutes`, `exit_presence_gap_seconds`, `reentry_cooldown_seconds`,
     `qr_usage_window_minutes`, `presence_entry_window_seconds`, `exit_check_seconds`,
     `warehouse_confirm_seconds`, `warehouse_exterior_margin_seconds`,
     `presence_mode`, `presence_confirm_reports`, `presence_confirm_window_seconds`,
     `presence_min_span_seconds`, `presence_hold_seconds`, `presence_absent_reports`,
     `presence_absent_window_seconds`, `presence_door_window_seconds`, `presence_stale_seconds`,
     `timbre_pre_open_presence_seconds`, `timbre_scan_open_timeout_seconds`,
     `timbre_presence_end_grace_seconds`, `timbre_door_close_grace_seconds`,
     `timbre_max_recording_seconds`, `timbre_door_open_max_seconds`,
     `timbre_light_off_delay_seconds`)
SELECT 'TIMBRADO', 'Timbrado', 5, 15, 20,
       30, 90, 40,
       40, 5,
       'radar_only', 1, 1,
       0, 0, 1,
       1, 90, 180,
       10, 20,
       3, 3,
       120, 60,
       3
WHERE NOT EXISTS (SELECT 1 FROM `room_types` WHERE `code` = 'TIMBRADO');

INSERT IGNORE INTO `worker_role_room_types` (`role_id`, `room_type_id`)
SELECT DISTINCT rrt.`role_id`, timbre.`id`
FROM `worker_role_room_types` rrt
JOIN `room_types` almacen ON almacen.`id` = rrt.`room_type_id` AND almacen.`code` = 'ALMACEN_BEBIDAS'
JOIN `room_types` timbre ON timbre.`code` = 'TIMBRADO';

CREATE TABLE IF NOT EXISTS `timbre_movements` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`            BIGINT UNSIGNED NOT NULL,
    `status`             ENUM('RECORDING','COMPLETED','NO_DOOR','NO_PRESENCE','LEFT_OPEN','MAX_REACHED','CANCELLED') NOT NULL DEFAULT 'RECORDING',
    `trigger`            ENUM('SCAN','DOOR','RADAR') NOT NULL,
    `credential_type`    ENUM('QR','NFC') NULL,
    `key_id`             INT NULL,
    `worker_id`          INT NULL,
    `worker_session_id`  BIGINT UNSIGNED NULL,
    `direction`          ENUM('ENTRY','EXIT','CROSSING','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    `presence_before`    TINYINT(1) NOT NULL DEFAULT 0,
    `presence_before_at` DATETIME(3) NULL,
    `presence_seen`      TINYINT(1) NOT NULL DEFAULT 0,
    `scan_at`            DATETIME(3) NULL,
    `door_opened_at`     DATETIME(3) NULL,
    `door_closed_at`     DATETIME(3) NULL,
    `ended_at`           DATETIME(3) NULL,
    `door_left_open`     TINYINT(1) NOT NULL DEFAULT 0,
    `door_open_seconds`  INT(10) UNSIGNED NULL,
    `meta_json`          LONGTEXT NULL,
    `created_at`         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_tm_room_created` (`room_id`, `created_at`),
    KEY `idx_tm_key` (`key_id`),
    KEY `idx_tm_worker` (`worker_id`),
    CONSTRAINT `fk_tm_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_tm_key`
        FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tm_worker`
        FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_tm_worker_session`
        FOREIGN KEY (`worker_session_id`) REFERENCES `worker_sessions`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timbre_state` (
    `room_id`              BIGINT UNSIGNED NOT NULL,
    `state`                VARCHAR(24) NOT NULL DEFAULT 'IDLE',
    `current_movement_id`  BIGINT UNSIGNED NULL,
    `scan_deadline`        DATETIME(3) NULL,
    `stop_deadline`        DATETIME(3) NULL,
    `max_deadline`         DATETIME(3) NULL,
    `door_open_deadline`   DATETIME(3) NULL,
    `door_open_since`      DATETIME(3) NULL,
    `light_off_at`         DATETIME(3) NULL,
    `light_on`             TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`room_id`),
    CONSTRAINT `fk_ts_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ts_movement`
        FOREIGN KEY (`current_movement_id`) REFERENCES `timbre_movements`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `camera_recordings`
    ADD COLUMN IF NOT EXISTS `timbre_movement_id` BIGINT UNSIGNED NULL AFTER `visit_id`,
    ADD KEY `idx_cr_timbre_movement` (`timbre_movement_id`),
    ADD CONSTRAINT `fk_cr_timbre_movement`
        FOREIGN KEY (`timbre_movement_id`) REFERENCES `timbre_movements`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

INSERT IGNORE INTO `system_settings`
    (`service`, `setting_key`, `value`, `description`, `category`, `is_sensitive`)
VALUES
    ('api', 'timbre.retention_days', '30',
     'Días de retención de los vídeos del timbrado (0 = no borrar)',
     'timbre', 0);
