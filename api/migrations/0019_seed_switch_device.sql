-- 0019_seed_switch_device.sql (TSK-SW11)
-- Inserts a SWITCH device for room 1 (for dev/testing with SIMULATED_MODE=true).
--
-- The Tuya device ID is a placeholder; replace with the real EAWCBT-J device ID
-- when deploying to production (SIMULATED_MODE=false).
--
-- See RF-16.6, design.md §4.3.

INSERT IGNORE INTO `devices` (`room_id`, `kind`, `external_id`, `meta_json`)
VALUES (1, 'SWITCH', 'bf00000000000000000000',
        '{"model": "EAWCBT-J", "dp_code": "switch_1", "note": "dev placeholder"}');
