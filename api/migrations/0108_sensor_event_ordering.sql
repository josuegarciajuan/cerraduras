-- 0108_sensor_event_ordering.sql — Orden temporal, idempotencia y consolidación de entrada
-- Trazabilidad: RF-43, RF-44, RF-46, RF-47.

ALTER TABLE iot_sessions
  ADD COLUMN last_door_event_at     DATETIME(3) NULL DEFAULT NULL AFTER last_absent_since,
  ADD COLUMN last_presence_event_at DATETIME(3) NULL DEFAULT NULL AFTER last_door_event_at,
  ADD COLUMN last_door_value        VARCHAR(8)  NULL DEFAULT NULL AFTER last_presence_event_at,
  ADD COLUMN last_presence_value    VARCHAR(8)  NULL DEFAULT NULL AFTER last_door_value;

ALTER TABLE presence_events
  ADD COLUMN event_fingerprint CHAR(40)    NULL DEFAULT NULL AFTER source_event_id,
  ADD COLUMN applied           TINYINT(1)  NULL DEFAULT NULL AFTER event_fingerprint,
  ADD COLUMN discard_reason    VARCHAR(16) NULL DEFAULT NULL AFTER applied,
  ADD UNIQUE KEY uq_presence_fingerprint (event_fingerprint),
  ADD KEY idx_presence_room_occurred (room_id, occurred_at);

ALTER TABLE stays
  ADD COLUMN entry_confirmed_at DATETIME(3) NULL DEFAULT NULL AFTER first_entry_at;
