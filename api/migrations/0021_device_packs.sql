-- 0021_device_packs.sql (F25: Device Packs — RF-17)
-- Plantillas predefinidas de dispositivos para asignar en bloque a una habitación.
-- See design.md §4.17-4.18, contracts.md §2.12.

-- ============================================================
-- TABLE: device_packs
-- ============================================================
CREATE TABLE IF NOT EXISTS `device_packs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`        VARCHAR(32)     NOT NULL,
    `name`        VARCHAR(128)    NOT NULL,
    `created_at`  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_device_packs_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: device_pack_items
-- ============================================================
CREATE TABLE IF NOT EXISTS `device_pack_items` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pack_id`             BIGINT UNSIGNED NOT NULL,
    `kind`                ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH') NOT NULL,
    `external_id_prefix`  VARCHAR(64)     NOT NULL DEFAULT '',
    `meta_json`           JSON            NULL,
    `position`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_pack_items_kind` (`pack_id`, `kind`),
    KEY `idx_pack_items_pack` (`pack_id`),
    CONSTRAINT `fk_pack_items_pack` FOREIGN KEY (`pack_id`) REFERENCES `device_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED: 4 packs predefinidos (TSK-DP6)
-- ============================================================
INSERT IGNORE INTO `device_packs` (`code`, `name`) VALUES ('BASIC', 'Pack Básico');
INSERT IGNORE INTO `device_packs` (`code`, `name`) VALUES ('PREMIUM', 'Pack Completo');
INSERT IGNORE INTO `device_packs` (`code`, `name`) VALUES ('LOCK_ONLY', 'Solo Cerradura');
INSERT IGNORE INTO `device_packs` (`code`, `name`) VALUES ('SENSORS_ONLY', 'Solo Sensores');

-- Pack Básico: RPI + LOCK + PROXIMITY
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'RPI',        'ESP32-',      1 FROM `device_packs` WHERE code = 'BASIC';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'LOCK',       'TUYA-LOCK-',  2 FROM `device_packs` WHERE code = 'BASIC';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'PROXIMITY',  'TUYA-DOOR-',  3 FROM `device_packs` WHERE code = 'BASIC';

-- Pack Completo: RPI + LOCK + PROXIMITY + PRESENCE + SWITCH
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'RPI',        'ESP32-',       1 FROM `device_packs` WHERE code = 'PREMIUM';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'LOCK',       'TUYA-LOCK-',   2 FROM `device_packs` WHERE code = 'PREMIUM';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'PROXIMITY',  'TUYA-DOOR-',   3 FROM `device_packs` WHERE code = 'PREMIUM';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'PRESENCE',   'TUYA-PRES-',   4 FROM `device_packs` WHERE code = 'PREMIUM';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'SWITCH',     'TUYA-SW-',     5 FROM `device_packs` WHERE code = 'PREMIUM';

-- Solo Cerradura: LOCK
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'LOCK',       'TUYA-LOCK-',   1 FROM `device_packs` WHERE code = 'LOCK_ONLY';

-- Solo Sensores: PROXIMITY + PRESENCE
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'PROXIMITY',  'TUYA-DOOR-',   1 FROM `device_packs` WHERE code = 'SENSORS_ONLY';
INSERT IGNORE INTO `device_pack_items` (`pack_id`, `kind`, `external_id_prefix`, `position`)
SELECT id, 'PRESENCE',   'TUYA-PRES-',   2 FROM `device_packs` WHERE code = 'SENSORS_ONLY';
