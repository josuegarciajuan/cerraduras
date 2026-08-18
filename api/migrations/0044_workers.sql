-- 0044_workers.sql — Hotel workers with master QR (F38)
-- RF-W1, RF-W2, RF-W5, RF-W6
--
-- Adds worker_roles, worker_role_room_types, workers, worker_sessions tables.
-- Extends access_events with worker_session_id and WORKER_EXIT kind.
-- Seeds 3 default roles and 3 test workers.
--
-- See design.md F38 §1, contracts.md F38.

-- ============================================================================
-- 1. Worker roles
-- ============================================================================
CREATE TABLE `worker_roles` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at`  DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    `updated_at`  DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. Pivot: role → room_types
-- ============================================================================
CREATE TABLE `worker_role_room_types` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `role_id`      INT NOT NULL,
    `room_type_id` BIGINT UNSIGNED NOT NULL,
    `created_at`   DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY `uq_role_roomtype` (`role_id`, `room_type_id`),
    CONSTRAINT `fk_wrrt_role` FOREIGN KEY (`role_id`)
        REFERENCES `worker_roles`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_wrrt_roomtype` FOREIGN KEY (`room_type_id`)
        REFERENCES `room_types`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Workers
-- ============================================================================
CREATE TABLE `workers` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(100) NOT NULL,
    `role_id`       INT NOT NULL,
    `qr_token_hash` VARCHAR(64) NOT NULL,
    `qr_jti`        VARCHAR(36) NOT NULL UNIQUE,
    `active`        BOOLEAN NOT NULL DEFAULT TRUE,
    `notes`         TEXT NULL,
    `created_at`    DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    `updated_at`    DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    CONSTRAINT `fk_workers_role` FOREIGN KEY (`role_id`)
        REFERENCES `worker_roles`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. Worker sessions (entry/exit tracking)
-- ============================================================================
CREATE TABLE `worker_sessions` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `worker_id`      INT NOT NULL,
    `room_id`        BIGINT UNSIGNED NOT NULL,
    `entered_at`     DATETIME(3) NOT NULL,
    `exited_at`      DATETIME(3) NULL,
    `exit_kind`      VARCHAR(16) NULL COMMENT 'QR_SCAN|EXIT_RULE|DOOR_EVENT|AUTO',
    `correlation_id` VARCHAR(64) NULL,
    `created_at`     DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    `updated_at`     DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    CONSTRAINT `fk_ws_worker` FOREIGN KEY (`worker_id`)
        REFERENCES `workers`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_ws_room` FOREIGN KEY (`room_id`)
        REFERENCES `rooms`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    KEY `idx_ws_worker` (`worker_id`),
    KEY `idx_ws_room` (`room_id`),
    KEY `idx_ws_active` (`room_id`, `exited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. Extend access_events for worker sessions
-- ============================================================================
ALTER TABLE `access_events`
    ADD COLUMN IF NOT EXISTS `worker_session_id` BIGINT UNSIGNED NULL AFTER `stay_id`;

ALTER TABLE `access_events`
    MODIFY `kind` ENUM('QR_VALIDATE','OPEN','AUTO_LOCK','MANUAL_LOCK','DENIED','WORKER_EXIT') NOT NULL;

ALTER TABLE `access_events`
    ADD CONSTRAINT `fk_access_events_ws`
        FOREIGN KEY (`worker_session_id`) REFERENCES `worker_sessions`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================================
-- 6. Seed: default roles
-- ============================================================================
INSERT INTO `worker_roles` (`id`, `name`, `description`) VALUES
    (1, 'Limpieza', 'Personal de limpieza de habitaciones'),
    (2, 'Mantenimiento', 'Personal de mantenimiento'),
    (3, 'Recepción', 'Personal de recepción');

-- Assign all existing room_types to all default roles
INSERT INTO `worker_role_room_types` (`role_id`, `room_type_id`)
SELECT wr.id, rt.id FROM `worker_roles` wr CROSS JOIN `room_types` rt;

-- ============================================================================
-- 7. Seed: test workers (QR must be regenerated from panel before use)
-- ============================================================================
INSERT INTO `workers` (`id`, `name`, `role_id`, `qr_token_hash`, `qr_jti`, `active`, `notes`) VALUES
    (1, 'María García', 1,
     'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
     '550e8400-e29b-41d4-a716-446655440001', 1, 'Turno mañana — limpieza'),
    (2, 'Carlos López', 2,
     'b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3',
     '550e8400-e29b-41d4-a716-446655440002', 1, 'Turno tarde — mantenimiento'),
    (3, 'Ana Martínez', 3,
     'c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4',
     '550e8400-e29b-41d4-a716-446655440003', 1, 'Turno partido — recepción');
