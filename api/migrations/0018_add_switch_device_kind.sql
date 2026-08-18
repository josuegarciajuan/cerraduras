-- 0018_add_switch_device_kind.sql (TSK-SW1)
-- Adds SWITCH kind to the devices ENUM for Smart Electricity Protector
-- EAWCBT-J devices controlled via Tuya IoT Platform (RF-16).
--
-- A SWITCH device represents a WiFi-controlled relay that takes 220V input
-- and outputs 220V, used to turn room lights on/off automatically.
-- One switch per room (enforced by existing UNIQUE(room_id, kind)).
--
-- See design.md §2.4b, §4.3.

ALTER TABLE `devices`
    MODIFY COLUMN `kind` ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH') NOT NULL;
