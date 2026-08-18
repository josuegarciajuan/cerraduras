-- 0037_pack_canonical_data.sql -- Saneamiento canónico pack
-- Causa raíz: rooms.pack_id=NULL, devices.room_id!=NULL (mezcla legacy)
-- Objetivo: rooms con pack, devices sin room_id directo

-- 1: Asignar el pack PRUEBAS a room 1 (la habitación de pruebas)
UPDATE rooms SET pack_id = 5 WHERE id = 1;
-- room 2 también usa el mismo pack en dev
UPDATE rooms SET pack_id = 5 WHERE id = 2;

-- 2: Device SWITCH huérfano (id=26): asignarlo al pack PRUEBAS
UPDATE devices SET pack_id = 5 WHERE id = 26 AND pack_id IS NULL;

-- 3: Limpiar room_id legacy en todos los devices con pack asignado
UPDATE devices SET room_id = NULL WHERE pack_id IS NOT NULL;
