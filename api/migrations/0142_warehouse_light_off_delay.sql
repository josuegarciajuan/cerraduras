-- 0142_warehouse_light_off_delay.sql — apagado diferido de la luz del almacén
-- Trazabilidad: F114+.
--
-- El estado de presencia se aplica al instante, pero la luz se apaga tras un
-- pequeño retardo cancelable. `exit-scan` (tick 1 s) revisa los vencimientos y
-- solo apaga si `iot_sessions.presence_state` sigue en ABSENT.

ALTER TABLE warehouse_state
  ADD COLUMN light_off_at DATETIME(3) NULL DEFAULT NULL AFTER presence_confirmed;
