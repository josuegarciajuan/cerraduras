-- 0138_room_automation_pause.sql — F109: pausa manual de automatizaciones
--
-- Trazabilidad: RF-249 · design.md §F109
--
-- La pausa es manual e indefinida: no caduca y no se reanuda automáticamente.

CREATE TABLE IF NOT EXISTS `room_automation_pause` (
    `room_id`     BIGINT UNSIGNED NOT NULL,
    `paused`      TINYINT(1)      NOT NULL DEFAULT 0,
    `run_id`      VARCHAR(64)     NULL,
    `paused_at`   DATETIME(3)     NULL,
    `resumed_at`  DATETIME(3)     NULL,
    `updated_at`  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
