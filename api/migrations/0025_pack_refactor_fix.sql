-- 0025_pack_refactor_fix.sql — Second attempt, idempotent

-- rooms: add pack_id (safe if already exists in 0024)
ALTER TABLE `rooms` ADD COLUMN IF NOT EXISTS `pack_id` BIGINT UNSIGNED NULL AFTER `room_type_id`;

-- devices: add pack_id, make room_id nullable  
ALTER TABLE `devices` ADD COLUMN IF NOT EXISTS `pack_id` BIGINT UNSIGNED NULL AFTER `room_id`;
ALTER TABLE `devices` MODIFY COLUMN `room_id` BIGINT UNSIGNED NULL;

-- Drop old items table
DROP TABLE IF EXISTS `device_pack_items`;

-- Delete old sample packs
DELETE FROM `device_packs`;

-- Create single test pack
INSERT INTO `device_packs` (`code`, `name`) VALUES ('PRUEBAS', 'Equipo de Pruebas');
SET @pack_id = LAST_INSERT_ID();

-- Assign existing room 1 devices to this pack
UPDATE `devices` SET `pack_id` = @pack_id WHERE `room_id` = 1 AND `pack_id` IS NULL;

-- Insert missing devices, skip if already exist
INSERT IGNORE INTO `devices` (`room_id`, `pack_id`, `kind`, `external_id`, `meta_json`)
VALUES
  (NULL, @pack_id, 'RPI',        '92f57630',                      '{"chip":"ESP32-S3"}'),
  (NULL, @pack_id, 'LOCK',       'bfafd3f2013c4b1876f5g5',        '{"model":"WBR3/jtmspro"}'),
  (NULL, @pack_id, 'PROXIMITY',  'bf4c7e7d2cef28cea2nkwk',        '{"model":"MC400D"}'),
  (NULL, @pack_id, 'PRESENCE',   'bf98d27d79685e38a2wbda',        '{"model":"ZY-M100-5"}'),
  (NULL, @pack_id, 'SWITCH',     'bf00000000000000000000',        '{"model":"EAWCBT-J"}');

-- Assign pack to room 1
UPDATE `rooms` SET `pack_id` = @pack_id WHERE `id` = 1;

-- Assign existing room 2 device to same pack (for testing)
UPDATE `devices` SET `pack_id` = @pack_id WHERE `room_id` = 2 AND `pack_id` IS NULL;
UPDATE `rooms` SET `pack_id` = @pack_id WHERE `id` = 2;
