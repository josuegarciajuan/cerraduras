-- 0123_warehouse_reentry_context.sql — F85/RF-112: contexto de re-entrada
--
-- Trazabilidad: RF-112.3 · design.md §49.10
--
-- Ventana (segundos) en la que una visita iniciada por PRESENCE justo después de
-- una visita real (DOOR/QR, ENTERED) cerrada en la misma sala se considera una
-- re-entrada real (no un falso positivo del radar). Configurable sin desplegar.
-- Un `ABSENT` seguido de `PRESENT` crea siempre visita nueva (RF-109.4); este
-- umbral decide si esa visita nueva se confirma o se marca NOISE.

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `warehouse_reentry_context_seconds` INT UNSIGNED NOT NULL DEFAULT 300;

UPDATE `room_types`
   SET `warehouse_reentry_context_seconds` = 300
 WHERE `code` = 'ALMACEN_BEBIDAS';
