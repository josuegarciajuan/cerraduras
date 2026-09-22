-- 0114_qr_arrival_window.sql — Ciclo de vida del QR de huésped en dos ventanas (Bug 4)
--
-- Trazabilidad: RF-59 · design.md §16 · contracts.md Fase 51
--
-- El QR de huésped pasa a tener DOS ventanas consecutivas:
--   1. LLEGADA: de la emisión al PRIMER escaneo, con una ventana global
--      `QR_ARRIVAL_WINDOW_MINUTES` (default 15 min), común a todas las salas.
--   2. USO: del primer escaneo a `first_used_at + stays.duracion_minutos`
--      (multi-uso / reentrada durante la estancia).
--
-- `consumed_at` se conserva como marca del PRIMER USO (compatibilidad y
-- auditoría) y queda sincronizada con `first_used_at`.
--
-- Idempotente: columnas con `IF NOT EXISTS` y backfill solo para filas que
-- todavía no tienen `first_used_at`.

ALTER TABLE `qr_credentials`
  ADD COLUMN IF NOT EXISTS `first_used_at` DATETIME(3) NULL AFTER `consumed_at`;

ALTER TABLE `qr_credentials`
  ADD COLUMN IF NOT EXISTS `valid_until` DATETIME(3) NULL AFTER `first_used_at`;

-- Backfill: las credenciales ya consumidas heredan su primer uso y su ventana
-- de uso calculada con la duración real de la estancia.
UPDATE `qr_credentials` qc
  JOIN `stays` s ON s.id = qc.stay_id
   SET qc.first_used_at = qc.consumed_at,
       qc.valid_until   = DATE_ADD(qc.consumed_at, INTERVAL s.duracion_minutos MINUTE)
 WHERE qc.consumed_at IS NOT NULL
   AND qc.first_used_at IS NULL;
