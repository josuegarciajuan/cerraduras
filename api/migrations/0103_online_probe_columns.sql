-- 0103_online_probe_columns.sql — Estado verídico de dispositivos Tuya cloud
-- Objetivo: separar la "actividad reactiva" (last_seen_at, alimentada por eventos)
-- de la "conectividad real verificada" para dispositivos Tuya cloud
-- (PRESENCE, PROXIMITY, SWITCH), cuya verificación consume cuota de la API Tuya
-- y por tanto solo se hace al cargar el panel o pulsando "Comprobar dispositivos"
-- (NUNCA en el poll periódico).
--
-- Modelo resultante:
--   - online_state     : último resultado de una SONDA real de Tuya (result.online).
--                        1 = online · 0 = offline · NULL = nunca verificado.
--   - online_probed_at : cuándo se ejecutó esa última sonda.
--   - last_seen_at     : sigue registrando actividad (eventos/heartbeat), ya no se
--                        usa como prueba de conexión para dispositivos Tuya cloud.
--
-- Columnas nullable y aditivas: seguras para aplicar en caliente sobre producción.

ALTER TABLE devices
  ADD COLUMN online_state     TINYINT(1)  NULL DEFAULT NULL AFTER last_seen_at,
  ADD COLUMN online_probed_at DATETIME(3) NULL DEFAULT NULL AFTER online_state;
