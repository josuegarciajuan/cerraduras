-- 0149_event_log_indexes.sql — F129
-- Índices de rango para el registro de eventos unificado del panel.
-- Solo añaden índices; no modifican datos ni columnas. Seguros en caliente.
--
-- presence_events y switch_command_evidence no tenían un índice de tiempo
-- independiente de room_id; el registro permite filtrar por rango global.

ALTER TABLE `presence_events`
    ADD INDEX IF NOT EXISTS `idx_pe_time` (`occurred_at`);

ALTER TABLE `switch_command_evidence`
    ADD INDEX IF NOT EXISTS `idx_sce_time` (`requested_at`);
