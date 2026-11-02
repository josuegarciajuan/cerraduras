-- 0137_radar_distance_config.sql — F108: configuración de la evidencia DP 9
--
-- Trazabilidad: RF-242 · design.md §108 Fase 4
--
-- Aditivo. Permite descartar muestras de una configuración distinta a la de la
-- ventana de calibración. Las filas históricas quedan con config_key NULL.

ALTER TABLE `radar_distance_reports`
    ADD COLUMN `config_key` VARCHAR(64) NULL AFTER `origin`;

CREATE INDEX `idx_radar_distance_config`
    ON `radar_distance_reports` (`room_id`, `config_key`, `received_at`);
