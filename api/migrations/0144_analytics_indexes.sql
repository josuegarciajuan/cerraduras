-- 0144_analytics_indexes.sql — F119
-- Índices de rango para la analítica del panel. Solo añaden índices; no
-- modifican datos ni columnas. Seguros de aplicar en caliente.

ALTER TABLE `stays`
    ADD INDEX IF NOT EXISTS `idx_stays_first_entry` (`first_entry_at`),
    ADD INDEX IF NOT EXISTS `idx_stays_closed` (`closed_at`);

ALTER TABLE `worker_sessions`
    ADD INDEX IF NOT EXISTS `idx_ws_entered` (`entered_at`),
    ADD INDEX IF NOT EXISTS `idx_ws_exited` (`exited_at`);

ALTER TABLE `access_events`
    ADD INDEX IF NOT EXISTS `idx_ae_time` (`occurred_at`),
    ADD INDEX IF NOT EXISTS `idx_ae_kind_time` (`kind`, `occurred_at`);

ALTER TABLE `key_access_events`
    ADD INDEX IF NOT EXISTS `idx_kae_time` (`occurred_at`),
    ADD INDEX IF NOT EXISTS `idx_kae_result_time` (`result`, `occurred_at`);

ALTER TABLE `warehouse_visits`
    ADD INDEX IF NOT EXISTS `idx_wv_created` (`created_at`),
    ADD INDEX IF NOT EXISTS `idx_wv_entered` (`entered_at`);

ALTER TABLE `camera_recordings`
    ADD INDEX IF NOT EXISTS `idx_cr_requested` (`requested_at`);

ALTER TABLE `anomalies`
    ADD INDEX IF NOT EXISTS `idx_anomalies_status_detected` (`status`, `detected_at`);

ALTER TABLE `workers`
    ADD INDEX IF NOT EXISTS `idx_workers_active` (`active`);
