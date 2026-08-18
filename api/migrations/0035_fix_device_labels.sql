-- Migration 0035: Fix device labels — use searched CASE to avoid ENUM index comparison bug
-- Migration 0034 used "CASE col WHEN val" which compares ENUM index, not string value.

-- Reset labels set by 0034
UPDATE devices SET label = NULL WHERE label IS NOT NULL AND label REGEXP '^[0-9]+$';

-- Re-populate with correct searched CASE
UPDATE devices d
SET d.label = CONCAT(
    CASE
        WHEN d.kind = 'RPI'       THEN 'ESP32'
        WHEN d.kind = 'LOCK'      THEN 'Cerradura'
        WHEN d.kind = 'PROXIMITY' THEN 'Sensor Puerta'
        WHEN d.kind = 'PRESENCE'  THEN 'Sensor Presencia'
        WHEN d.kind = 'SWITCH'    THEN 'Luz'
        WHEN d.kind = 'SCANNER'   THEN 'Lector QR'
        ELSE d.kind
    END,
    IFNULL(
        CONCAT(' Hab.', (
            SELECT r.code FROM rooms r
            WHERE (r.pack_id = d.pack_id AND d.pack_id IS NOT NULL)
               OR (r.id = d.room_id AND d.pack_id IS NULL)
            LIMIT 1
        )),
        ''
    )
)
WHERE d.label IS NULL OR d.label = '';
