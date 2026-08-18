-- =============================================================================
-- Migración 0101: Corrección de exit_detected_at — CEST → UTC
-- =============================================================================
-- Contexto: La migración 0100 corrigió reserved_at, created_at, updated_at, y
-- closed_at para stays creadas por QrTestController (vb6_codalq IS NULL), pero
-- NO corrigió exit_detected_at.
--
-- exit_detected_at fue almacenado con NOW(3) (CEST) en la mayoría de los casos.
-- Esto causa que exit_detected_at aparezca ANTES de first_entry_at (que sí está
-- en UTC porque StayStateMachine::exitDetected() usa Clock::nowUtc()).
--
-- CORRECCIÓN: Para stays donde exit_detected_at < first_entry_at (cronología
-- imposible), sumar 2 horas a exit_detected_at para alinearlo a UTC.
-- =============================================================================

-- Verificar cuántas filas están afectadas
SELECT 'Antes de corrección' AS etapa, COUNT(*) AS filas_afectadas
FROM stays
WHERE first_entry_at IS NOT NULL
  AND exit_detected_at IS NOT NULL
  AND exit_detected_at < first_entry_at;

-- Corregir exit_detected_at: sumar 2h donde exit < entry (cronología imposible)
UPDATE stays
   SET exit_detected_at = DATE_ADD(exit_detected_at, INTERVAL 2 HOUR)
 WHERE first_entry_at IS NOT NULL
   AND exit_detected_at IS NOT NULL
   AND exit_detected_at < first_entry_at;

-- Verificar que ya no queden filas con cronología imposible
SELECT 'Después de corrección' AS etapa, COUNT(*) AS filas_restantes
FROM stays
WHERE first_entry_at IS NOT NULL
  AND exit_detected_at IS NOT NULL
  AND exit_detected_at < first_entry_at;

-- =============================================================================
-- También corregir access_events.occurred_at que usa DEFAULT CURRENT_TIMESTAMP(3)
-- (siempre CEST). Todas las filas previas a este fix necesitan -2h.
-- =============================================================================
UPDATE access_events
   SET occurred_at = DATE_SUB(occurred_at, INTERVAL 2 HOUR);

-- =============================================================================
-- Corregir presence_events.received_at que usa DEFAULT CURRENT_TIMESTAMP(3)
-- (siempre CEST). occurred_at viene de toMysqlUtc() que ya está en UTC.
-- =============================================================================
UPDATE presence_events
   SET received_at = DATE_SUB(received_at, INTERVAL 2 HOUR)
 WHERE received_at IS NOT NULL;

-- =============================================================================
-- Corregir anomalies.detected_at, acknowledged_at, dismissed_at (usaban NOW(3))
-- =============================================================================
UPDATE anomalies
   SET detected_at = DATE_SUB(detected_at, INTERVAL 2 HOUR);

UPDATE anomalies
   SET acknowledged_at = DATE_SUB(acknowledged_at, INTERVAL 2 HOUR)
 WHERE acknowledged_at IS NOT NULL;

UPDATE anomalies
   SET dismissed_at = DATE_SUB(dismissed_at, INTERVAL 2 HOUR)
 WHERE dismissed_at IS NOT NULL;

-- =============================================================================
-- Corregir idempotency_keys.created_at y expires_at
-- =============================================================================
UPDATE idempotency_keys
   SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR);

UPDATE idempotency_keys
   SET expires_at = DATE_SUB(expires_at, INTERVAL 2 HOUR);

-- =============================================================================
-- VERIFICACIÓN FINAL
-- =============================================================================
SELECT 'Migration 0101 complete' AS status;
