-- 0017_seed_test_room_devices.sql (TSK-221, F20)
-- Registra los 2 sensores Tuya de la habitación de pruebas (room_id=1)
-- para que el TuyaSensorIngress pueda resolverlos al recibir push events
-- desde el Pulsar consumer vía /api/v1/tuya/webhook.
--
-- Sensores:
--   MC400D (door magnet)      → kind=PROXIMITY, external_id=bf4c7e7d2cef28cea2nkwk
--   ZY-M100-5 (mmWave presence)→ kind=PRESENCE,  external_id=bf98d27d79685e38a2wbda
--
-- NOTA: El ESP32 RPI (lector QR) debe registrarse aparte con su chip ID real,
--       que depende del hardware físico. Ver Fase D (registro de dispositivo).
--
-- Trazabilidad: design §4.3, RF-5.

INSERT IGNORE INTO devices (room_id, kind, external_id, meta_json)
VALUES
  (1, 'PROXIMITY', 'bf4c7e7d2cef28cea2nkwk', JSON_OBJECT(
    'device_model', 'MC400D',
    'device_type', 'door_magnet',
    'registered_by', 'TSK-221 migration'
  )),
  (1, 'PRESENCE', 'bf98d27d79685e38a2wbda', JSON_OBJECT(
    'device_model', 'ZY-M100-5',
    'device_type', 'mmwave_presence',
    'registered_by', 'TSK-221 migration'
  ));
