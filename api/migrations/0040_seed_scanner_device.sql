-- 0040_seed_scanner_device.sql
-- F33: Ensure SCANNER device exists in the PRUEBAS pack.
-- The PRUEBAS pack (migration 0024) contains RPI, LOCK, PROXIMITY, PRESENCE, SWITCH
-- but NOT SCANNER. Migration 0026 tried to add it with INSERT IGNORE which could
-- fail silently if the pack doesn't exist. This migration ensures SCANNER is present.
-- Idempotent: INSERT IGNORE won't duplicate if the device already exists.

INSERT IGNORE INTO `devices` (`pack_id`, `kind`, `external_id`, `label`, `meta_json`)
SELECT id, 'SCANNER', 'gm65-uart-1', 'Lector QR', '{"model":"QR-2D-USB","interface":"USB-HID"}'
FROM device_packs
WHERE code = 'PRUEBAS';
