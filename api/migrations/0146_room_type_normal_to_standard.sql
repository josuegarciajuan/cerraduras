-- 0146_room_type_normal_to_standard.sql — F120
-- Unifica el tipo antiguo "Normal/TIPO_NORMAL" en STANDARD.
--
-- Orden seguro:
--   1. Copiar permisos de rol, excepciones por empleado y permisos de llave.
--   2. Copiar franjas horarias solo si STANDARD no tiene ninguna.
--   3. Reasignar salas y eventos de acceso.
--   4. Borrar el tipo antiguo (las FKs restantes caen en cascada).
--
-- Idempotente: si el tipo ya no existe, todas las sentencias son no-op.

INSERT IGNORE INTO `worker_role_room_types` (`role_id`, `room_type_id`)
SELECT rrt.`role_id`, s.`id`
FROM `worker_role_room_types` rrt
JOIN `room_types` n ON n.`id` = rrt.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL');

INSERT IGNORE INTO `access_key_room_types` (`key_id`, `room_type_id`)
SELECT akrt.`key_id`, s.`id`
FROM `access_key_room_types` akrt
JOIN `room_types` n ON n.`id` = akrt.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL');

INSERT IGNORE INTO `worker_room_overrides` (`worker_id`, `room_type_id`, `effect`)
SELECT wro.`worker_id`, s.`id`, wro.`effect`
FROM `worker_room_overrides` wro
JOIN `room_types` n ON n.`id` = wro.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL');

INSERT INTO `time_slots` (`room_type_id`, `starts_at`, `ends_at`, `kind`)
SELECT s.`id`, ts.`starts_at`, ts.`ends_at`, ts.`kind`
FROM `time_slots` ts
JOIN `room_types` n ON n.`id` = ts.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL')
  AND NOT EXISTS (
      SELECT 1 FROM `time_slots` existing WHERE existing.`room_type_id` = s.`id`
  );

UPDATE `key_access_events` kae
JOIN `room_types` n ON n.`id` = kae.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
SET kae.`room_type_id` = s.`id`
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL');

UPDATE `rooms` r
JOIN `room_types` n ON n.`id` = r.`room_type_id`
JOIN `room_types` s ON s.`code` = 'STANDARD'
SET r.`room_type_id` = s.`id`
WHERE UPPER(n.`code`) IN ('TIPO_NORMAL', 'NORMAL');

DELETE FROM `room_types`
WHERE UPPER(`code`) IN ('TIPO_NORMAL', 'NORMAL')
  AND `code` <> 'STANDARD';
