-- F132 — estado de transición de presencia y resultado operativo final.
--
-- Columnas aditivas. No se reescriben filas existentes:
--   presence_pending_state      PRESENT|ABSENT en espera de confirmación.
--   presence_pending_since      instante UTC del reporte auténtico que la abrió.
--   presence_pending_due_at     instante UTC en el que puede confirmarse.
--   presence_pending_marker     marcador DP1 de la evidencia (deduplicación).
--   presence_pending_config_key huella de configuración del radar.
--   presence_outcome_json       último resultado operativo final publicado.
--
-- El resultado final permite que el panel distinga entre:
--   - presencia aplicada,
--   - transición pendiente,
--   - detección bloqueada por política,
--   - eco no verificado o fuera de alcance.

ALTER TABLE iot_sessions
  ADD COLUMN presence_pending_state      VARCHAR(8)  NULL DEFAULT NULL AFTER last_presence_value,
  ADD COLUMN presence_pending_since      DATETIME(3) NULL DEFAULT NULL AFTER presence_pending_state,
  ADD COLUMN presence_pending_due_at     DATETIME(3) NULL DEFAULT NULL AFTER presence_pending_since,
  ADD COLUMN presence_pending_marker     VARCHAR(64) NULL DEFAULT NULL AFTER presence_pending_due_at,
  ADD COLUMN presence_pending_config_key VARCHAR(64) NULL DEFAULT NULL AFTER presence_pending_marker,
  ADD COLUMN presence_outcome_json       JSON        NULL DEFAULT NULL AFTER presence_pending_config_key;
