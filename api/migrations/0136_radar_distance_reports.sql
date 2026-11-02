-- 0136_radar_distance_reports.sql — F107: evidencia de distancia del radar
--
-- Trazabilidad: RF-230..RF-234 · design.md §107 · contracts.md §F107
--
-- Tabla aditiva. Solo guarda reportes explícitos del DP 9 del radar
-- (target_distance), no el estado combinado de Zigbee2MQTT. La identidad
-- `marker` la genera el conversor externo y es única por dispositivo, de modo
-- que una retransmisión no crea una muestra nueva.

CREATE TABLE IF NOT EXISTS `radar_distance_reports` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`       BIGINT UNSIGNED NOT NULL,
    `external_id`     VARCHAR(128)    NOT NULL,
    `room_id`         BIGINT UNSIGNED NOT NULL,
    `marker`          VARCHAR(160)    NOT NULL,
    `session_id`      VARCHAR(64)     NOT NULL,
    `report_counter`  BIGINT UNSIGNED NOT NULL,
    `distance_m`      DECIMAL(5,3)    NOT NULL,
    `origin`          VARCHAR(16)     NOT NULL DEFAULT 'push',
    `received_at`     DATETIME(3)     NOT NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_radar_distance_marker` (`device_id`, `marker`),
    KEY `idx_radar_distance_room_time` (`room_id`, `received_at`),
    KEY `idx_radar_distance_device_time` (`device_id`, `received_at`),
    CONSTRAINT `fk_radar_distance_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
