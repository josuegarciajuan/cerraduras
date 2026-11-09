-- 0145_anomaly_reopen_audit.sql — F120
-- Auditoría de reapertura de anomalías. Conserva el reconocimiento y el
-- descarte previos; la reapertura no sobrescribe esos campos.

ALTER TABLE `anomalies`
    ADD COLUMN IF NOT EXISTS `reopened_at`   DATETIME(3)  NULL AFTER `dismissed_by`,
    ADD COLUMN IF NOT EXISTS `reopened_by`   VARCHAR(64)  NULL AFTER `reopened_at`,
    ADD COLUMN IF NOT EXISTS `reopen_reason` VARCHAR(255) NULL AFTER `reopened_by`;

ALTER TABLE `anomalies`
    ADD INDEX IF NOT EXISTS `idx_anomalies_dismissed_by` (`dismissed_by`);
