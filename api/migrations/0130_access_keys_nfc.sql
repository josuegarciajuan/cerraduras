-- 0130_access_keys_nfc.sql — F96: fichas NFC y llaves de acceso
--
-- Trazabilidad: RF-168..RF-175 · design.md §F96 · contracts.md §F96
--
-- Añade:
--   1. worker_roles: los cargos operativos pedidos por el hotel
--      (Limpieza ya existe desde 0044).
--   2. nfc_cards: fichas físicas (número + código protegido).
--   3. access_keys: llaves QR o ficha, con titular trabajador/rol y vigencia.
--   4. access_key_room_types: permisos por tipo de sala.
--   5. key_access_events: auditoría de intentos de llave (OK/FAIL).
--
-- No modifica workers, worker_roles existentes, worker_sessions ni access_events.

-- ============================================================================
-- 1. Roles operativos solicitados (idempotente)
-- ============================================================================
INSERT INTO `worker_roles` (`name`, `description`)
SELECT 'Encargado', 'Encargado de turno'
WHERE NOT EXISTS (SELECT 1 FROM `worker_roles` WHERE `name` = 'Encargado');

INSERT INTO `worker_roles` (`name`, `description`)
SELECT 'Limpieza', 'Personal de limpieza de habitaciones'
WHERE NOT EXISTS (SELECT 1 FROM `worker_roles` WHERE `name` = 'Limpieza');

INSERT INTO `worker_roles` (`name`, `description`)
SELECT 'Camarero', 'Personal de sala y barra'
WHERE NOT EXISTS (SELECT 1 FROM `worker_roles` WHERE `name` = 'Camarero');

INSERT INTO `worker_roles` (`name`, `description`)
SELECT 'Dirección', 'Dirección del establecimiento'
WHERE NOT EXISTS (SELECT 1 FROM `worker_roles` WHERE `name` = 'Dirección');

-- Los nuevos roles heredan todos los tipos de sala existentes. El operador
-- puede ajustarlos después desde el panel (Roles).
INSERT IGNORE INTO `worker_role_room_types` (`role_id`, `room_type_id`)
SELECT wr.id, rt.id
FROM `worker_roles` wr
CROSS JOIN `room_types` rt
WHERE wr.name IN ('Encargado', 'Camarero', 'Dirección');

-- ============================================================================
-- 2. Fichas NFC
-- ============================================================================
CREATE TABLE IF NOT EXISTS `nfc_cards` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `number`         VARCHAR(32)  NOT NULL,
    `code_hash`      CHAR(64)     NOT NULL COMMENT 'SHA-256 del código de la ficha',
    `code_last4`     VARCHAR(4)   NULL,
    `status`         ENUM('AVAILABLE','ASSIGNED','DISABLED') NOT NULL DEFAULT 'AVAILABLE',
    `current_key_id` INT          NULL,
    `notes`          VARCHAR(255) NULL,
    `created_at`     DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    `updated_at`     DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY `uq_nfc_cards_number` (`number`),
    UNIQUE KEY `uq_nfc_cards_code` (`code_hash`),
    UNIQUE KEY `uq_nfc_cards_current_key` (`current_key_id`),
    KEY `idx_nfc_cards_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Llaves de acceso
-- ============================================================================
CREATE TABLE IF NOT EXISTS `access_keys` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `label`          VARCHAR(120) NOT NULL,
    `owner_type`     ENUM('WORKER','ROLE') NOT NULL,
    `worker_id`      INT          NULL,
    `role_id`        INT          NULL,
    `key_type`       ENUM('QR','NFC') NOT NULL,
    `token_hash`     CHAR(64)     NULL,
    `token_jti`      CHAR(36)     NULL,
    `all_room_types` TINYINT(1)   NOT NULL DEFAULT 0,
    `nfc_card_id`    INT          NULL,
    `valid_until`    DATETIME(3)  NOT NULL,
    `status`         ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    `revoked_at`     DATETIME(3)  NULL,
    `notes`          VARCHAR(255) NULL,
    `created_at`     DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    `updated_at`     DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY `uq_access_keys_jti` (`token_jti`),
    UNIQUE KEY `uq_access_keys_token_hash` (`token_hash`),
    KEY `idx_access_keys_worker` (`worker_id`),
    KEY `idx_access_keys_role` (`role_id`),
    KEY `idx_access_keys_status` (`status`, `valid_until`),
    KEY `idx_access_keys_type` (`key_type`),
    CONSTRAINT `fk_access_keys_worker` FOREIGN KEY (`worker_id`)
        REFERENCES `workers`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_access_keys_role` FOREIGN KEY (`role_id`)
        REFERENCES `worker_roles`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    KEY `idx_access_keys_card` (`nfc_card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `nfc_cards`
    ADD CONSTRAINT `fk_nfc_cards_current_key`
        FOREIGN KEY (`current_key_id`) REFERENCES `access_keys`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================================
-- 4. Permisos por tipo de sala
-- ============================================================================
CREATE TABLE IF NOT EXISTS `access_key_room_types` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `key_id`       INT NOT NULL,
    `room_type_id` BIGINT UNSIGNED NOT NULL,
    `created_at`   DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY `uq_access_key_roomtype` (`key_id`, `room_type_id`),
    CONSTRAINT `fk_akrt_key` FOREIGN KEY (`key_id`)
        REFERENCES `access_keys`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_akrt_roomtype` FOREIGN KEY (`room_type_id`)
        REFERENCES `room_types`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. Auditoría de accesos con llave
-- ============================================================================
CREATE TABLE IF NOT EXISTS `key_access_events` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key_id`          INT          NULL COMMENT 'NULL si el token/ficha no identifica ninguna llave',
    `owner_type`      ENUM('WORKER','ROLE') NULL,
    `worker_id`       INT          NULL COMMENT 'NULL en llaves compartidas de rol',
    `role_id`         INT          NULL,
    `room_id`         BIGINT UNSIGNED NULL,
    `room_type_id`    BIGINT UNSIGNED NULL,
    `device_id`       VARCHAR(128) NULL,
    `credential_type` ENUM('QR','NFC') NOT NULL,
    `result`          ENUM('OK','FAIL') NOT NULL,
    `reason`          VARCHAR(64)  NULL,
    `provider`        VARCHAR(16)  NULL,
    `correlation_id`  CHAR(26)     NULL,
    `worker_session_id` BIGINT UNSIGNED NULL,
    `occurred_at`     DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    KEY `idx_kae_key_time` (`key_id`, `occurred_at`),
    KEY `idx_kae_worker_time` (`worker_id`, `occurred_at`),
    KEY `idx_kae_role_time` (`role_id`, `occurred_at`),
    KEY `idx_kae_room_time` (`room_id`, `occurred_at`),
    KEY `idx_kae_result` (`result`),
    CONSTRAINT `fk_kae_key` FOREIGN KEY (`key_id`)
        REFERENCES `access_keys`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_kae_worker` FOREIGN KEY (`worker_id`)
        REFERENCES `workers`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_kae_role` FOREIGN KEY (`role_id`)
        REFERENCES `worker_roles`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_kae_room` FOREIGN KEY (`room_id`)
        REFERENCES `rooms`(`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_kae_room_type` FOREIGN KEY (`room_type_id`)
        REFERENCES `room_types`(`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
