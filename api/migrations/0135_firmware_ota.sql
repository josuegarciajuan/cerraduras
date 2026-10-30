-- 0135_firmware_ota.sql — F100: actualización remota de firmware (OTA)
--
-- Trazabilidad: RF-180..RF-190 · design.md §F100 · contracts.md §F100
--
-- Dos tablas nuevas, estrictamente aditivas:
--   firmware_releases             catálogo inmutable de binarios publicados
--   device_firmware_operations    una operación OTA por dispositivo y su historial
--
-- El dispositivo sigue siendo `kind=RPI`; `devices.subtype` es la variante
-- EFECTIVA (la que ha confirmado el firmware). La variante solicitada vive en
-- la operación hasta que el ESP confirma el arranque de la nueva imagen.

CREATE TABLE IF NOT EXISTS `firmware_releases` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `variant`          VARCHAR(16)     NOT NULL COMMENT 'CERRADURA | IMAN',
    `version`          VARCHAR(48)     NOT NULL,
    `build_id`         VARCHAR(96)     NULL,
    `commit_ref`       VARCHAR(64)     NULL,
    `board`            VARCHAR(64)     NOT NULL DEFAULT 'esp32s3usbotg',
    `partition_scheme` VARCHAR(64)     NOT NULL DEFAULT 'ota-2app',
    `protocol`         VARCHAR(16)     NOT NULL DEFAULT 'ota-v1',
    `sha256`           CHAR(64)        NOT NULL,
    `size_bytes`       BIGINT UNSIGNED NOT NULL,
    `signature`        VARCHAR(512)    NULL,
    `binary_path`      VARCHAR(255)    NOT NULL,
    `notes`            TEXT            NULL,
    `published`        TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `published_at`     DATETIME(3)     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fw_variant_version` (`variant`, `version`),
    UNIQUE KEY `uq_fw_sha256` (`sha256`),
    KEY `idx_fw_published` (`variant`, `published`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_firmware_operations` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`       BIGINT UNSIGNED NOT NULL,
    `external_id`     VARCHAR(128)    NOT NULL,
    `release_id`      BIGINT UNSIGNED NOT NULL,
    `requested_by`    VARCHAR(96)     NULL,
    `from_variant`    VARCHAR(16)     NULL,
    `from_version`    VARCHAR(48)     NULL,
    `to_variant`      VARCHAR(16)     NOT NULL,
    `to_version`      VARCHAR(48)     NOT NULL,
    `status`          VARCHAR(24)     NOT NULL DEFAULT 'WAITING',
    `progress_pct`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `error_code`      VARCHAR(48)     NULL,
    `error_message`   VARCHAR(255)    NULL,
    `accepted_at`     DATETIME(3)     NULL,
    `last_report_at`  DATETIME(3)     NULL,
    `finished_at`     DATETIME(3)     NULL,
    `expires_at`      DATETIME(3)     NULL,
    `created_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_fwop_device_status` (`device_id`, `status`),
    KEY `idx_fwop_external_status` (`external_id`, `status`),
    KEY `idx_fwop_created` (`created_at`),
    CONSTRAINT `fk_fwop_release` FOREIGN KEY (`release_id`) REFERENCES `firmware_releases` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
