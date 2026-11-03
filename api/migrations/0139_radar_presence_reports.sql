-- 0139_radar_presence_reports.sql — F111: evidencia de presencia DP 1
--
-- Trazabilidad: F111 fase 1 · design.md §F111
--
-- Tabla aditiva. Solo guarda reportes explicitos del DP 1 del radar. El campo
-- `marker` lo genera el conversor externo y es unico por dispositivo: si
-- Zigbee2MQTT republica el estado combinado (iluminancia, LQI, distancia) el
-- marcador no cambia y no se crea una presencia nueva.

CREATE TABLE IF NOT EXISTS `radar_presence_reports` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`       BIGINT UNSIGNED NOT NULL,
    `external_id`     VARCHAR(128)    NOT NULL,
    `room_id`         BIGINT UNSIGNED NOT NULL,
    `marker`          VARCHAR(160)    NOT NULL,
    `session_id`      VARCHAR(64)     NOT NULL,
    `report_counter`  BIGINT UNSIGNED NOT NULL,
    `value`           VARCHAR(8)      NOT NULL,
    `origin`          VARCHAR(16)     NOT NULL DEFAULT 'push',
    `config_key`      VARCHAR(64)     NULL,
    `received_at`     DATETIME(3)     NOT NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_radar_presence_marker` (`device_id`, `marker`),
    KEY `idx_radar_presence_room_time` (`room_id`, `received_at`),
    KEY `idx_radar_presence_device_time` (`device_id`, `received_at`),
    KEY `idx_radar_presence_config` (`room_id`, `config_key`, `received_at`),
    CONSTRAINT `fk_radar_presence_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
