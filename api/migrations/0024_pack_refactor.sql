-- 0024_pack_refactor.sql — Devices vinculados a packs, no a rooms directamente
-- Fase 1: añadir columnas, sin romper existente

-- rooms: ahora pueden tener un pack asignado
ALTER TABLE `rooms`
  ADD COLUMN `pack_id` BIGINT UNSIGNED NULL AFTER `room_type_id`,
  ADD KEY `idx_rooms_pack` (`pack_id`);

-- devices: añadir pack_id (nullable, se rellenará en migración futura)
ALTER TABLE `devices`
  ADD COLUMN `pack_id` BIGINT UNSIGNED NULL AFTER `room_id`,
  ADD KEY `idx_devices_pack` (`pack_id`),
  MODIFY COLUMN `room_id` BIGINT UNSIGNED NULL;

-- device_pack_items ya no se usa — los items SON los devices
DROP TABLE IF EXISTS `device_pack_items`;

-- Limpiar packs de ejemplo antiguos (tenían items en device_pack_items que ya no existen)
DELETE FROM `device_packs`;

-- Crear 1 solo pack de pruebas con dispositivos reales
INSERT INTO `device_packs` (`code`, `name`) VALUES ('PRUEBAS', 'Equipo de Pruebas');
SET @pack_id = LAST_INSERT_ID();

-- Insertar los 5 dispositivos reales de pruebas en el pack
INSERT INTO `devices` (`room_id`, `pack_id`, `kind`, `external_id`, `meta_json`)
VALUES
  (NULL, @pack_id, 'RPI',        '92f57630',                      '{"chip":"ESP32-S3","note":"Lector QR + relé GPIO16"}'),
  (NULL, @pack_id, 'LOCK',       'bfafd3f2013c4b1876f5g5',        '{"model":"WBR3/jtmspro","note":"Cerradura Tuya"}'),
  (NULL, @pack_id, 'PROXIMITY',  'bf4c7e7d2cef28cea2nkwk',        '{"model":"MC400D","note":"Sensor magnético de puerta"}'),
  (NULL, @pack_id, 'PRESENCE',   'bf98d27d79685e38a2wbda',        '{"model":"ZY-M100-5","note":"Sensor presencia mmWave"}'),
  (NULL, @pack_id, 'SWITCH',     'bf00000000000000000000',        '{"model":"EAWCBT-J","dp_code":"switch_1","note":"Interruptor luz"}');

-- Asignar el pack de pruebas a la habitación 101 (room 1)
UPDATE `rooms` SET `pack_id` = @pack_id WHERE `id` = 1;

-- Las FKs se añadirán en una migración futura cuando todos los datos estén migrados
-- ALTER TABLE `rooms` ADD CONSTRAINT `fk_rooms_pack` FOREIGN KEY (`pack_id`) REFERENCES `device_packs`(`id`) ON DELETE SET NULL;
-- ALTER TABLE `devices` ADD CONSTRAINT `fk_devices_pack` FOREIGN KEY (`pack_id`) REFERENCES `device_packs`(`id`) ON DELETE CASCADE;
