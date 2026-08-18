-- 0022_system_settings.sql (F26)
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service`      ENUM('api','ws-vb6') NOT NULL,
    `setting_key`  VARCHAR(128) NOT NULL,
    `value`        TEXT NULL,
    `description`  VARCHAR(255) NULL,
    `category`     VARCHAR(64) NOT NULL,
    `is_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_settings_key` (`service`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
