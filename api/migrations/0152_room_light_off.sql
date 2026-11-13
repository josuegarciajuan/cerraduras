-- F137 — apagado diferido de luz en salas con política radar.
--
-- Columna aditiva: instante UTC en el que debe ejecutarse el OFF si la sala
-- sigue vacía. La escribe IotSessionService cuando se confirma la ausencia
-- después de un ciclo de puerta y la procesa exit-scan. Un PRESENT/OPEN
-- posterior limpia el plazo (cancelación).
--
-- Las salas de almacén conservan su propio plazo en warehouse_state.light_off_at.

ALTER TABLE iot_sessions
  ADD COLUMN light_off_at DATETIME(3) NULL DEFAULT NULL
    AFTER presence_episode_distance_marker;
