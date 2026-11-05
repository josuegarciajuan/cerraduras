-- 0141_presence_dp1_edge.sql — auditoría de motivos DP1 sin truncamiento
-- Trazabilidad: F114+ (salida/presencia robusta del radar Zigbee).
--
-- `discard_reason` era VARCHAR(16). El motivo `absent_edge_pending` (19)
-- provocaba SQLSTATE[22001] al auditar, dejaba la fila con applied=NULL y el
-- reintento la marcaba como duplicate, perdiendo la transición. Se amplía para
-- que cualquier motivo del borde DP1 quepa sin romper la auditoría.

ALTER TABLE presence_events
  MODIFY COLUMN discard_reason VARCHAR(32) NULL DEFAULT NULL;
