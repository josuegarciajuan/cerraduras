-- =============================================================================
-- Migración 0100: Corrección de zona horaria — CEST → UTC
-- =============================================================================
-- Contexto: El sistema almacenaba timestamps en mixed zones:
--   - UTC_TIMESTAMP(3) en la API de producción (correcto)
--   - NOW(3) = CEST (UTC+2) en endpoints del dashboard, device pings, etc.
--
-- Este script corrige las filas que se insertaron con NOW(3) restando 2 horas.
-- Las filas que ya estaban en UTC (ej: stays con vb6_codalq no nulo) NO se tocan.
--
-- PRECAUCIÓN: Ejecutar SOLO cuando el servidor está en CEST (verano, UTC+2).
-- En invierno (CET, UTC+1), el offset sería 1 hora.
-- =============================================================================

-- ═══════════════════════════════════════════════════════════════════
-- 1. DEVICES — last_seen_at, identified_at (siempre se insertó con NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE devices
   SET last_seen_at = DATE_SUB(last_seen_at, INTERVAL 2 HOUR)
 WHERE last_seen_at IS NOT NULL;

UPDATE devices
   SET identified_at = DATE_SUB(identified_at, INTERVAL 2 HOUR)
 WHERE identified_at IS NOT NULL;

-- ═══════════════════════════════════════════════════════════════════
-- 2. DEVICE_COMMANDS — created_at, updated_at (siempre NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE device_commands
   SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR);

UPDATE device_commands
   SET updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

-- ═══════════════════════════════════════════════════════════════════
-- 3. IOT_SESSIONS — todos los timestamps (siempre NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE iot_sessions
   SET updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

UPDATE iot_sessions
   SET last_open_at = DATE_SUB(last_open_at, INTERVAL 2 HOUR)
 WHERE last_open_at IS NOT NULL;

UPDATE iot_sessions
   SET last_close_at = DATE_SUB(last_close_at, INTERVAL 2 HOUR)
 WHERE last_close_at IS NOT NULL;

UPDATE iot_sessions
   SET last_absent_since = DATE_SUB(last_absent_since, INTERVAL 2 HOUR)
 WHERE last_absent_since IS NOT NULL;

UPDATE iot_sessions
   SET exit_evaluated_at = DATE_SUB(exit_evaluated_at, INTERVAL 2 HOUR)
 WHERE exit_evaluated_at IS NOT NULL;

-- ═══════════════════════════════════════════════════════════════════
-- 4. STAYS — solo las creadas por QrTestController (sin vb6_codalq)
--    Las de producción (con vb6_codalq) ya están en UTC → no se tocan.
--    closed_at se corrige en todas las CLOSED sin vb6_codalq.
-- ═══════════════════════════════════════════════════════════════════
UPDATE stays
   SET reserved_at = DATE_SUB(reserved_at, INTERVAL 2 HOUR),
       created_at  = DATE_SUB(created_at, INTERVAL 2 HOUR),
       updated_at  = DATE_SUB(updated_at, INTERVAL 2 HOUR)
 WHERE vb6_codalq IS NULL;

UPDATE stays
   SET closed_at = DATE_SUB(closed_at, INTERVAL 2 HOUR)
 WHERE closed_at IS NOT NULL
   AND vb6_codalq IS NULL;

-- ═══════════════════════════════════════════════════════════════════
-- 5. QR_CREDENTIALS — las vinculadas a stays del QrTestController
-- ═══════════════════════════════════════════════════════════════════
UPDATE qr_credentials
   SET issued_at  = DATE_SUB(issued_at, INTERVAL 2 HOUR),
       expires_at = DATE_SUB(expires_at, INTERVAL 2 HOUR),
       created_at = DATE_SUB(created_at, INTERVAL 2 HOUR),
       updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR)
 WHERE stay_id IN (SELECT id FROM stays WHERE vb6_codalq IS NULL);

UPDATE qr_credentials
   SET revoked_at = DATE_SUB(revoked_at, INTERVAL 2 HOUR)
 WHERE revoked_at IS NOT NULL
   AND stay_id IN (SELECT id FROM stays WHERE vb6_codalq IS NULL);

-- ═══════════════════════════════════════════════════════════════════
-- 6. CRM_SESSIONS — expires_at, created_at (siempre NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE crm_sessions
   SET expires_at = DATE_SUB(expires_at, INTERVAL 2 HOUR),
       created_at = DATE_SUB(created_at, INTERVAL 2 HOUR);

-- ═══════════════════════════════════════════════════════════════════
-- 7. CRM_USERS — created_at, updated_at, last_login_at (siempre NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE crm_users
   SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR),
       updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

UPDATE crm_users
   SET last_login_at = DATE_SUB(last_login_at, INTERVAL 2 HOUR)
 WHERE last_login_at IS NOT NULL;

-- ═══════════════════════════════════════════════════════════════════
-- 8. DEVICE_PACKS — created_at, updated_at (siempre NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE device_packs
   SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR),
       updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

-- ═══════════════════════════════════════════════════════════════════
-- 9. SYSTEM_SETTINGS — updated_at (insertado con NOW(3) en seed/AdminSettingsController)
-- ═══════════════════════════════════════════════════════════════════
UPDATE system_settings
   SET updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

-- ═══════════════════════════════════════════════════════════════════
-- 10. API_CLIENTS — created_at, updated_at (insertado con NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE api_clients
   SET created_at = DATE_SUB(created_at, INTERVAL 2 HOUR),
       updated_at = DATE_SUB(updated_at, INTERVAL 2 HOUR);

-- ═══════════════════════════════════════════════════════════════════
-- 11. OUTBOX_VB6 — next_attempt_at (retry usaba NOW(3))
-- ═══════════════════════════════════════════════════════════════════
UPDATE outbox_vb6
   SET next_attempt_at = DATE_SUB(next_attempt_at, INTERVAL 2 HOUR);

-- =============================================================================
-- VERIFICACIÓN: mostrar timestamps corregidos
-- =============================================================================
SELECT 'Migration 0100 complete — all CEST timestamps shifted to UTC' AS status;
