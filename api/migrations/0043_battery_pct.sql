-- 0043_battery_pct.sql — Track battery level for battery-powered devices
-- F36: Monitoreo de batería del sensor de puerta MC400D
--
-- Adds battery_pct column to devices table. Only battery-powered Tuya
-- devices (MC400D door sensor) report battery_percentage via the
-- GET /v1.0/iot-03/devices/{id}/status API or push webhooks.
--
-- See design.md F36 §2.1, RF-36.1.1.

ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `battery_pct` TINYINT UNSIGNED NULL AFTER `meta_json`;
