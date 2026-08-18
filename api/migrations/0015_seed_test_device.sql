-- 0015_seed_test_device.sql (TSK-DEV2)
-- Registra el ESP32 de pruebas (chip ID 92f57630) en la habitación 1 (101).
-- Este dispositivo se usa para development/testing en modo real.
--
-- kind=RPI: aunque el hardware es un ESP32, a efectos del sistema es un
-- dispositivo RPI (Raspberry Pi Interface) — lector QR en la puerta.
--
-- El external_id almacena el chip ID del ESP32 en hex (sin separadores),
-- tal como lo devuelve ESP.getEfuseMac() y como se envía en device_id.

INSERT IGNORE INTO devices (room_id, kind, external_id, meta_json)
VALUES (1, 'RPI', '92f57630', JSON_OBJECT(
    'chip', 'ESP32-D0WD-V3',
    'wifi_mac', '30:76:f5:92:d7:2c',
    'registered_by', 'TSK-DEV2 migration'
));
