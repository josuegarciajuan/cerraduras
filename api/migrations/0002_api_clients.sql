-- 0002_api_clients.sql (TSK-021)
-- Authenticated clients (VB6, Raspberry Pi, Admin CLI, SIM, VB6-Bridge).
-- Each client has:
--   - a SHA-256 hash of its API key (never the plaintext)
--   - a CSV of scopes
--   - an optional CSV of allowed IPs (whitelist)
--
-- See design.md §4.5 and contracts.md §5.

CREATE TABLE `api_clients` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`              VARCHAR(64)     NOT NULL,
    `kind`              ENUM('VB6','RPI','ADMIN','SIM','VB6_BRIDGE','TUYA_BRIDGE') NOT NULL,
    `api_key_hash`      CHAR(64)        NOT NULL,
    `scopes_csv`        VARCHAR(255)    NOT NULL DEFAULT '',
    `ip_whitelist_csv`  VARCHAR(255)    NULL,
    `active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_api_clients_code`    (`code`),
    UNIQUE KEY `uniq_api_clients_keyhash` (`api_key_hash`),
    KEY `idx_api_clients_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
