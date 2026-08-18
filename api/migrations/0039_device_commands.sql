-- 0038_device_commands.sql
-- F33: Command queue for pull-based sub-device verification (SCANNER + LOCK)
-- The API enqueues commands; the ESP32 picks them up on its existing heartbeat cycle.
-- No new outbound HTTP calls from the ESP32.

CREATE TABLE IF NOT EXISTS `device_commands` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_id`   VARCHAR(128)    NOT NULL COMMENT 'chipId del ESP32 (RPI external_id)',
    `command`       VARCHAR(64)     NOT NULL DEFAULT 'check' COMMENT 'check | identify',
    `payload_json`  JSON            NULL,
    `status`        ENUM('pending','picked_up','done','timeout') NOT NULL DEFAULT 'pending',
    `result_json`   JSON            NULL,
    `created_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    INDEX `idx_cmds_ext_status` (`external_id`, `status`),
    INDEX `idx_cmds_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
