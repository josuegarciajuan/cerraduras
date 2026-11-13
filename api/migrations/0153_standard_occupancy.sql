-- F138 — motor único de ocupación para habitaciones STANDARD.
--
-- Estado operativo persistido por sala. Es aditivo: las salas especiales
-- (ALMACEN_BEBIDAS/TIMBRADO) conservan su flujo y dejan estas columnas NULL.
--
-- occupancy_state: VACANT_CONFIRMED|ACCESS_PENDING|DOOR_OPEN|ENTRY_VERIFY|
--                  OCCUPIED|EXIT_VERIFY|OCCUPANCY_UNCERTAIN
-- occupancy_json:  detalle (ciclo, evidencia, motivos, últimas lecturas).
-- entry_deadline_at / vacancy_deadline_at: vencimientos que resuelve exit-scan.
-- light_desired / light_last_result: intención de luz y resultado de la última
--   publicación; permiten recuperar una orden fallida sin depender de la UI.
-- radar_health: ok|stale|gap|uNAVAILABLE; nunca implica ausencia.
-- uncertain_since: desde cuándo la ocupación está protegida por duda.

ALTER TABLE iot_sessions
  ADD COLUMN occupancy_state VARCHAR(32) NULL DEFAULT NULL
    AFTER light_off_at,
  ADD COLUMN occupancy_json JSON NULL DEFAULT NULL
    AFTER occupancy_state,
  ADD COLUMN entry_deadline_at DATETIME(3) NULL DEFAULT NULL
    AFTER occupancy_json,
  ADD COLUMN vacancy_deadline_at DATETIME(3) NULL DEFAULT NULL
    AFTER entry_deadline_at,
  ADD COLUMN light_desired VARCHAR(8) NULL DEFAULT NULL
    AFTER vacancy_deadline_at,
  ADD COLUMN light_last_result VARCHAR(32) NULL DEFAULT NULL
    AFTER light_desired,
  ADD COLUMN radar_health VARCHAR(24) NULL DEFAULT NULL
    AFTER light_last_result,
  ADD COLUMN uncertain_since DATETIME(3) NULL DEFAULT NULL
    AFTER radar_health;

CREATE INDEX idx_iot_sessions_occupancy_due
  ON iot_sessions (occupancy_state, entry_deadline_at, vacancy_deadline_at);
