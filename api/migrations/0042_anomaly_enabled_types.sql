-- 0042_anomaly_enabled_types.sql
-- F35: Allow enabling/disabling anomaly types via system_settings.
--
-- The anomaly.enabled_types setting is a JSON array of anomaly type codes
-- (A1..A8) that controls which detectors are active. Types not in the array
-- are skipped by the anomaly pipeline.
--
-- This can be modified at runtime via PUT /api/v1/admin/settings.

INSERT IGNORE INTO system_settings (service, setting_key, value, description, category)
VALUES ('api', 'anomaly.enabled_types',
        '["A1","A2","A3","A4","A5","A6","A7","A8"]',
        'Tipos de anomalía habilitados (JSON array de códigos A1..A8)',
        'anomaly');
