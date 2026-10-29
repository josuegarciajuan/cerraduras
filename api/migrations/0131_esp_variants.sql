-- 0131_esp_variants.sql — F97/RF-174: variantes ESP-cerradura y ESP-imán
--
-- Trazabilidad: RF-174..RF-176 · design.md §F97 · contracts.md §F97
--
-- El ESP sigue siendo `kind=RPI`. `subtype` declara el actuador físico:
--   CERRADURA → sketch ESP-cerradura (reposo sin corriente, pulso al abrir)
--   IMAN      → sketch ESP-imán (corriente pulsada en reposo, corte al abrir)
--
-- Los RPI existentes se consideran ESP-cerradura: es el firmware y el
-- comportamiento que ya tenían. El backfill es determinista y no toca otros
-- kinds (las cámaras conservan EXTERIOR/INTERIOR).

ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `firmware_variant` VARCHAR(16) NULL AFTER `transport`,
    ADD COLUMN IF NOT EXISTS `firmware_version` VARCHAR(32) NULL AFTER `firmware_variant`;

UPDATE `devices`
   SET `subtype` = 'CERRADURA'
 WHERE `kind` = 'RPI'
   AND (`subtype` IS NULL OR `subtype` = '');
