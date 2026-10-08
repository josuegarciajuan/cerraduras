-- 0124_warehouse_evidence_retention.sql — F87/RF-117/RF-120
--
-- Trazabilidad: RF-117.3 · RF-120.3 · design.md §51.3/§51.6
--
-- 1. Endurece la evidencia de las visitas iniciadas solo por presencia:
--    `static_seconds` pasa de 300 a 1800 s. El radar (24G) mantiene `PRESENT`
--    más de 5 min con la sala vacía y el umbral de 300 s confirmaba fantasmas.
--    `min_events` queda vestigial (RF-117.2): deja de participar en la decisión.
-- 2. Declara la retención de grabaciones del almacén (1 día en pruebas; 0 = sin
--    borrado automático). `INSERT IGNORE` no pisa un valor ya fijado en prod.

ALTER TABLE `room_types`
    MODIFY COLUMN `warehouse_presence_static_seconds` INT UNSIGNED NOT NULL DEFAULT 1800;

UPDATE `room_types`
   SET `warehouse_presence_static_seconds` = 1800
 WHERE `code` = 'ALMACEN_BEBIDAS'
   AND `warehouse_presence_static_seconds` < 1800;

INSERT IGNORE INTO `system_settings` (service, setting_key, value, description, category, is_sensitive)
VALUES ('api', 'warehouse.retention_days', '1',
        'Retención de grabaciones del almacén en días (0 = sin borrado automático)',
        'warehouse', 0);
