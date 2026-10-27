-- 0128_room_type_presence_modes.sql — F91/RF-148: modos y ventanas de presencia
--
-- Trazabilidad: RF-148.1 / RF-148.4 · design.md §F91
--
-- La presencia por radar introduce un algoritmo con evidencia temporal. Los
-- valores por defecto conservan el comportamiento actual (`camera_fusion`) y
-- no cambian ninguna sala existente. Una sala solo cambia de modo cuando se
-- actualiza explícitamente o la provisiona F91.

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_mode` VARCHAR(24) NOT NULL DEFAULT 'camera_fusion';

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_confirm_reports` TINYINT UNSIGNED NOT NULL DEFAULT 3;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_confirm_window_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 12;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_min_span_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 6;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_hold_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 20;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_absent_reports` TINYINT UNSIGNED NOT NULL DEFAULT 3;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_absent_window_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 30;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_door_window_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 90;

ALTER TABLE `room_types`
    ADD COLUMN IF NOT EXISTS `presence_stale_seconds` INT UNSIGNED NOT NULL DEFAULT 180;
