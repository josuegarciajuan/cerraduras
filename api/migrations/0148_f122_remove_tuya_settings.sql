-- 0148_f122_remove_tuya_settings.sql — F122
-- Elimina la configuración Tuya persistida y renombra el cliente de pruebas.
-- Evita que `admin/settings/sync-env` reescriba credenciales Tuya en api/.env.

DELETE FROM `system_settings`
 WHERE `setting_key` IN ('TUYA_ACCESS_ID', 'TUYA_ACCESS_SECRET', 'TUYA_BASE_URL');

-- Renombra el cliente de pruebas si aún existe (UPDATE IGNORE evita choque con
-- un SENSOR-BRIDGE ya creado).
UPDATE IGNORE `api_clients` SET `code` = 'SENSOR-BRIDGE' WHERE `code` = 'TUYA-BRIDGE';
