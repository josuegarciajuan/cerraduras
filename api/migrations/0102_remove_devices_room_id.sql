-- 0102_remove_devices_room_id.sql — F30: modelo canónico pack
-- Objetivo: eliminar la asociación directa dispositivo → habitación.
-- Modelo canónico resultante: device → pack → room
--   (devices.pack_id + rooms.pack_id). La habitación de un dispositivo se
--   resuelve SIEMPRE a través del pack; ya no existe devices.room_id.

-- 1) Sanear cualquier residuo legacy antes de droppear la columna.
--    Bajo el modelo canónico ningún dispositivo se asocia a una room directa;
--    si un device conservaba un room_id heredado, es un dato huérfano y su
--    habitación real se sigue obteniendo por pack.
UPDATE devices SET room_id = NULL WHERE room_id IS NOT NULL;

-- 2) Eliminar FK e índice que dependen de la columna (orden obligatorio).
ALTER TABLE devices DROP FOREIGN KEY fk_devices_room;
ALTER TABLE devices DROP INDEX uniq_devices_room_kind;

-- 3) Eliminar la columna.
ALTER TABLE devices DROP COLUMN room_id;
