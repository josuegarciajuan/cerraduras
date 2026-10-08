-- 0125_device_last_motion.sql — F88/RF-123.1: último movimiento de cámara
--
-- Trazabilidad: RF-123.1 · design.md §52.3/§52.4
--
-- El worker `bin/camera-motion-worker.js` publica movimiento por cámara a
-- `POST /almacen-api/motion`; aquí se persiste el instante del último movimiento
-- para que el pipeline de presencia lo pueda corroborar (fusión de sensores).
-- Columna idempotente, sin backfill.

ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `last_motion_at` DATETIME(3) NULL;
