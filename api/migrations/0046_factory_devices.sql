CREATE TABLE `factory_devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `chip_id` VARCHAR(32) NOT NULL,
    `status` ENUM('PENDING','CLAIMED') NOT NULL DEFAULT 'PENDING',
    `first_announced_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `last_announced_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `claimed_at` DATETIME(3) NULL,
    `claimed_by` VARCHAR(128) NULL,
    `device_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`), UNIQUE KEY `uniq_factory_devices_chip_id` (`chip_id`),
     KEY `idx_factory_devices_status` (`status`),
     KEY `idx_factory_devices_device` (`device_id`),
     CONSTRAINT `fk_factory_devices_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
