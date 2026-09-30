-- 0118_worker_room_overrides.sql — Excepción de permiso por empleado (F62, RF-69)
--
-- Trazabilidad: RF-69.2 · design.md §26.1
--
-- Permiso base = worker_role_room_types (rol → tipo de habitación). Esta tabla
-- añade una EXCEPCIÓN por empleado sobre un tipo concreto:
--   effect = ALLOW  → concede aunque el rol no lo haga.
--   effect = DENY   → deniega aunque el rol lo conceda.
--
-- La excepción SIEMPRE manda sobre el rol (WarehouseAccessPolicy::resolve).

CREATE TABLE IF NOT EXISTS `worker_room_overrides` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `worker_id`    INT          NOT NULL,
    `room_type_id` BIGINT UNSIGNED NOT NULL,
    `effect`       ENUM('ALLOW','DENY') NOT NULL,
    `created_at`   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wro_worker_roomtype` (`worker_id`, `room_type_id`),
    KEY `idx_wro_room_type` (`room_type_id`),
    CONSTRAINT `fk_wro_worker`
        FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_wro_room_type`
        FOREIGN KEY (`room_type_id`) REFERENCES `room_types`(`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
