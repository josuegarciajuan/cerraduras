-- 0127_device_transport.sql — F91/RF-144.4: transporte explícito del dispositivo
--
-- Trazabilidad: RF-144.4 / RF-144.5 · design.md §F91
--
-- `transport` declara el origen real del dispositivo (Tuya, Zigbee2MQTT,
-- local, simulado). Es nullable a propósito: NULL = sin clasificar y la API
-- lo presenta como UNKNOWN. No se hace backfill heurístico por kind,
-- external_id, etiqueta o pack.

ALTER TABLE `devices`
    ADD COLUMN IF NOT EXISTS `transport` VARCHAR(16) NULL AFTER `subtype`;
