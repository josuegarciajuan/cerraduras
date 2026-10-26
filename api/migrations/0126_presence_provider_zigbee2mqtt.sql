-- F90 / RF-136.7: admit the local Zigbee2MQTT push source in sensor audit.
-- Existing provider values and rows remain valid; access_events is unchanged.
ALTER TABLE `presence_events`
    MODIFY `provider` ENUM('TUYA','ESP32','LOCAL','SIMULATED','ZIGBEE2MQTT') NOT NULL;
