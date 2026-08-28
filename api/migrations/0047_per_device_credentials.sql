-- F40: per-device enrollment credentials and api-client binding.
ALTER TABLE factory_devices
    ADD COLUMN enrollment_key_hash CHAR(64) NULL AFTER chip_id,
    ADD UNIQUE KEY uniq_factory_devices_enrollment_hash (enrollment_key_hash);

ALTER TABLE api_clients
    ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER api_key_hash,
    ADD UNIQUE KEY uniq_api_clients_device (device_id),
    ADD CONSTRAINT fk_api_clients_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE SET NULL;
