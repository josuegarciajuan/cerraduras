-- 0147_retire_tuya_devices.sql — F122: retirada lógica de dispositivos Tuya
--
-- Contexto: la plataforma deja de usar Tuya (sensores, switch y cerradura).
-- Las filas se conservan para auditoría y tests; NO se borran ni se mueven de
-- pack. Solo se marcan como retiradas y se fuerza transport='TUYA' para que
-- ninguna ruta nueva las trate como operativas.
--
-- Idempotente: JSON_SET sobreescribe retired_at si se ejecuta de nuevo.
-- No modifica enums históricos de presence_events/access_events.
--
-- Rollback manual (si hiciera falta):
--   UPDATE devices
--      SET transport = NULL,
--          meta_json = JSON_REMOVE(meta_json, '$.retired_at', '$.retired_reason')
--    WHERE external_id IN (
--      'bf98d27d79685e38a2wbda','bf4c7e7d2cef28cea2nkwk','bfc8730a715c56c8d1paby',
--      'bf9a278e76e2c3f01ay0cs','bfafd3f2013c4b1876f5g5'
--    );

UPDATE `devices`
   SET `transport`        = 'TUYA',
       `online_state`     = NULL,
       `online_probed_at` = NULL,
       `meta_json`        = JSON_SET(
           COALESCE(`meta_json`, '{}'),
           '$.retired_at', DATE_FORMAT(UTC_TIMESTAMP(3), '%Y-%m-%dT%H:%i:%s.%fZ'),
           '$.retired_reason', 'f122_tuya_removed'
       )
 WHERE `external_id` IN (
     'bf98d27d79685e38a2wbda',  -- PRESENCE ZY-M100-5 (pruebas)
     'bf4c7e7d2cef28cea2nkwk',  -- PROXIMITY MC400D
     'bfc8730a715c56c8d1paby',  -- SWITCH EAWCBT-J
     'bf9a278e76e2c3f01ay0cs',  -- PRESENCE 24G V3
     'bfafd3f2013c4b1876f5g5'   -- LOCK WBR3
 );

-- El sensor de presencia WiFi del banco queda además deshabilitado para que
-- cualquier herramienta heredada lo ignore explícitamente.
UPDATE `devices`
   SET `meta_json` = JSON_SET(
           COALESCE(`meta_json`, '{}'),
           '$.presence_source', 'disabled'
       )
 WHERE `external_id` IN (
     'bf98d27d79685e38a2wbda',
     'bf9a278e76e2c3f01ay0cs'
 );
