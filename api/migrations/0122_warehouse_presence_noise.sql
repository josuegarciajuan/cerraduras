-- 0122_warehouse_presence_noise.sql — F84/RF-107: antiruido del radar del almacén
--
-- Trazabilidad: RF-107.3/107.5 · design.md §48.4
--
-- 1. `warehouse_visits.outcome` admite `NOISE` (episodio de radar sin evidencia:
--    parpadeo `move`->`presence`->`none`). Las filas se conservan como auditoría
--    pero se ocultan del listado por defecto.
-- 2. Umbrales configurables por tipo de sala para confirmar un episodio iniciado
--    solo por presencia (RF-107.2).

ALTER TABLE `warehouse_visits`
    MODIFY COLUMN `outcome` ENUM('ENTERED','NO_SHOW','ANONYMOUS','DENIED','NOISE')
    NOT NULL DEFAULT 'NO_SHOW';

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `warehouse_presence_min_moves` TINYINT UNSIGNED NOT NULL DEFAULT 2;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `warehouse_presence_min_events` TINYINT UNSIGNED NOT NULL DEFAULT 3;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `warehouse_presence_static_seconds` INT UNSIGNED NOT NULL DEFAULT 300;

UPDATE `room_types`
   SET `warehouse_presence_min_moves` = 2,
       `warehouse_presence_min_events` = 3,
       `warehouse_presence_static_seconds` = 300
 WHERE `code` = 'ALMACEN_BEBIDAS';
