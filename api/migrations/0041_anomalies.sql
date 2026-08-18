-- 0041_anomalies.sql
-- F35: Detection and tracking of sensor flow anomalies.
--
-- An anomaly is an event where sensor readings violate the expected physical
-- flow for a room (e.g., presence detected without prior door open, door open
-- without QR scan, etc.).
--
-- See design.md §1.1 (F35), requirements.md RF-35.*.

CREATE TABLE IF NOT EXISTS `anomalies` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`           BIGINT UNSIGNED NOT NULL,
    `stay_id`           BIGINT UNSIGNED NULL,
    `anomaly_type`      VARCHAR(50)     NOT NULL COMMENT 'A1..A8 — catalog code',
    `severity`          ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
    `status`            ENUM('OPEN','ACKNOWLEDGED','DISMISSED') NOT NULL DEFAULT 'OPEN',
    `context_data`      JSON            NOT NULL COMMENT 'IoT snapshot at detection time',
    `detected_at`       DATETIME(3)     NOT NULL,
    `acknowledged_at`   DATETIME(3)     NULL,
    `acknowledged_by`   VARCHAR(100)    NULL,
    `dismissed_at`      DATETIME(3)     NULL,
    `dismissed_by`      VARCHAR(100)    NULL,
    `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    INDEX `idx_anomalies_room_status` (`room_id`, `status`),
    INDEX `idx_anomalies_type` (`anomaly_type`),
    INDEX `idx_anomalies_severity` (`severity`),
    INDEX `idx_anomalies_detected` (`detected_at`),
    CONSTRAINT `fk_anomalies_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_anomalies_stay` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
