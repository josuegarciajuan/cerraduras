-- 0027_device_label.sql — Etiqueta/nombre interno para dispositivos
ALTER TABLE `devices` ADD COLUMN `label` VARCHAR(64) NULL AFTER `external_id`;
