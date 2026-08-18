-- 0033_remove_qr_test_enum.sql — Elimina QR_TEST del ENUM de devices.kind
-- RF-20: Rediseño panel QR de pruebas. Se elimina el concepto de QR_TEST.
-- Primero eliminar los dispositivos QR_TEST existentes, luego modificar el ENUM.
DELETE FROM `devices` WHERE `kind` = 'QR_TEST';
ALTER TABLE `devices` MODIFY COLUMN `kind` ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH','SCANNER') NOT NULL;
