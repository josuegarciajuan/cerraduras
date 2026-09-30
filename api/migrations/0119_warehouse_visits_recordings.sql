-- 0119_warehouse_visits_recordings.sql — Visitas, grabaciones y estado del motor (F63, RF-70/71)
--
-- Trazabilidad: RF-70.1/70.2, RF-71 · design.md §27.1
--
-- warehouse_visits    un ciclo de acceso (con o sin QR) y su resultado.
-- camera_recordings   una grabación por cámara/episodio, orquestada por el motor.
-- warehouse_state     estado mutable del motor por habitación (una fila).

CREATE TABLE IF NOT EXISTS `warehouse_visits` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`           BIGINT UNSIGNED NOT NULL,
    `worker_id`         INT NULL,
    `worker_session_id` BIGINT UNSIGNED NULL,
    `entry_trigger`     ENUM('QR','DOOR','PRESENCE') NOT NULL,
    `outcome`           ENUM('ENTERED','NO_SHOW','ANONYMOUS','DENIED') NOT NULL DEFAULT 'NO_SHOW',
    `denied_reason`     VARCHAR(64) NULL,
    `qr_at`             DATETIME(3) NULL,
    `entered_at`        DATETIME(3) NULL,
    `exited_at`         DATETIME(3) NULL,
    `created_at`        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_wv_room_created` (`room_id`, `created_at`),
    KEY `idx_wv_worker` (`worker_id`),
    CONSTRAINT `fk_wv_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wv_worker`
        FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_wv_worker_session`
        FOREIGN KEY (`worker_session_id`) REFERENCES `worker_sessions`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `camera_recordings` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `visit_id`       BIGINT UNSIGNED NULL,
    `room_id`        BIGINT UNSIGNED NOT NULL,
    `device_id`      BIGINT UNSIGNED NOT NULL,
    `position`       ENUM('EXTERIOR','INTERIOR') NOT NULL,
    `episode`        ENUM('ENTRY','EXIT','PRESENCE') NOT NULL,
    `trigger`        ENUM('QR','DOOR','PRESENCE') NOT NULL,
    `status`         ENUM('PENDING','RECORDING','SAVED','DISCARDED','FAILED') NOT NULL DEFAULT 'PENDING',
    `requested_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `started_at`     DATETIME(3) NULL,
    `stopped_at`     DATETIME(3) NULL,
    `duration_s`     INT UNSIGNED NULL,
    `file_path`      VARCHAR(255) NULL,
    `poster_path`    VARCHAR(255) NULL,
    `size_bytes`     BIGINT UNSIGNED NULL,
    `pid`            INT UNSIGNED NULL,
    `stop_requested` TINYINT(1) NOT NULL DEFAULT 0,
    `discard_requested` TINYINT(1) NOT NULL DEFAULT 0,
    `error`          VARCHAR(255) NULL,
    `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_cr_visit` (`visit_id`),
    KEY `idx_cr_status` (`status`),
    KEY `idx_cr_started` (`started_at`),
    CONSTRAINT `fk_cr_visit`
        FOREIGN KEY (`visit_id`) REFERENCES `warehouse_visits`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_cr_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_cr_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_state` (
    `room_id`            BIGINT UNSIGNED NOT NULL,
    `state`              ENUM('IDLE','QR_PENDING','RECORDING_INSIDE','EXTERIOR_ONLY','EXIT_PENDING') NOT NULL DEFAULT 'IDLE',
    `current_visit_id`   BIGINT UNSIGNED NULL,
    `entry_trigger`      ENUM('QR','DOOR','PRESENCE') NULL,
    `deadline_x`         DATETIME(3) NULL,
    `deadline_m`         DATETIME(3) NULL,
    `presence_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`room_id`),
    CONSTRAINT `fk_ws_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_ws_visit`
        FOREIGN KEY (`current_visit_id`) REFERENCES `warehouse_visits`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
