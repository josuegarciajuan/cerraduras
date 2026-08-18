-- Migration 0034: Populate device labels with human-readable defaults
-- Devices without a label get a sensible Spanish name based on kind + room context

UPDATE devices d
SET d.label = CONCAT(
    CASE d.kind
        WHEN 'RPI'       THEN 'ESP32'
        WHEN 'LOCK'      THEN 'Cerradura'
        WHEN 'PROXIMITY' THEN 'Sensor Puerta'
        WHEN 'PRESENCE'  THEN 'Sensor Presencia'
        WHEN 'SWITCH'    THEN 'Luz'
        WHEN 'SCANNER'   THEN 'Lector QR'
        ELSE d.kind
    END,
    IFNULL(
        CONCAT(' ', (
            SELECT r.code FROM rooms r
            WHERE (r.pack_id = d.pack_id AND d.pack_id IS NOT NULL)
               OR (r.id = d.room_id AND d.pack_id IS NULL)
            LIMIT 1
        )),
        ''
    )
)
WHERE d.label IS NULL OR d.label = '';
