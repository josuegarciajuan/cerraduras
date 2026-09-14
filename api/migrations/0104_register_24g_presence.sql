-- 0104_register_24g_presence.sql — Alta del sensor 24G-Presence Sensor V3 (proto2)
-- y reasignación del ZY-M100 al banco de pruebas.
--
-- Modelo canónico F30: device → pack → room (devices.pack_id es la única
-- asignación de pack). Los packs se resuelven por `code`, no por id.
--
-- Objetivo:
--   1) El sensor PRESENCE ZY-M100 (bf98d27d79685e38a2wbda) deja el pack proto2
--      y pasa al pack PRUEBAS ("Equipo de Pruebas"), con etiqueta de banco.
--   2) El nuevo 24G-Presence Sensor V3 (bf9a278e76e2c3f01ay0cs) se registra como
--      PRESENCE en el pack proto2, con sus capacidades DP reales (rangos distintos
--      a los del ZY-M100: far 75-900 step 75, sensibilidad 1-10).
--
-- Idempotente: el alta usa la clave única (kind, external_id) con
-- ON DUPLICATE KEY UPDATE; el UPDATE del viejo es no-op si ya se movió.

-- 1) ZY-M100 → PRUEBAS (banco de pruebas)
UPDATE devices d
  JOIN device_packs p ON p.code = 'PRUEBAS'
   SET d.pack_id   = p.id,
       d.label     = 'Sensor Presencia ZY-M100 (pruebas)',
       d.meta_json = JSON_OBJECT(
           'model',    'ZY-M100-5',
           'provider', 'tuya',
           'category', 'hps'
       )
 WHERE d.kind = 'PRESENCE'
   AND d.external_id = 'bf98d27d79685e38a2wbda';

-- 2) 24G-Presence Sensor V3 → proto2
INSERT INTO devices (pack_id, kind, external_id, label, api_client_id, meta_json)
SELECT p.id,
       'PRESENCE',
       'bf9a278e76e2c3f01ay0cs',
       'Sensor Presencia 24G V3',
       NULL,
       JSON_OBJECT(
           'model',      '24G-Presence Sensor V3',
           'provider',   'tuya',
           'category',   'hps',
           'product_id', '5lld8pgsoynvctqa',
           'dp_caps', JSON_OBJECT(
               'far_min',  75,
               'far_max',  900,
               'far_step', 75,
               'sens_min', 1,
               'sens_max', 10
           )
       )
  FROM device_packs p
 WHERE p.code = 'proto2'
ON DUPLICATE KEY UPDATE
    pack_id   = VALUES(pack_id),
    label     = VALUES(label),
    meta_json = VALUES(meta_json);
