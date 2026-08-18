-- 0004_idempotency_keys.sql (TSK-023)
-- Idempotency registry for POST endpoints with side effects.
-- Key semantics (contracts.md §1.5):
--   - Same (scope, key, client_id) + same request_hash -> replay stored response.
--   - Same (scope, key, client_id) + different request_hash -> 409 idempotency_conflict.
-- Entries expire (TTL 24h by default) and are purged by a maintenance job.
--
-- See design.md §4.13.

CREATE TABLE `idempotency_keys` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope`            VARCHAR(64)     NOT NULL,
    `key_value`        VARCHAR(128)    NOT NULL,
    `request_hash`     CHAR(64)        NOT NULL,
    `response_status`  SMALLINT UNSIGNED NOT NULL,
    `response_body`    MEDIUMTEXT      NULL,
    `client_id`        BIGINT UNSIGNED NOT NULL,
    `created_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at`       DATETIME(3)     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_idem_scope_key_client` (`scope`, `key_value`, `client_id`),
    KEY         `idx_idem_expires`           (`expires_at`),
    CONSTRAINT `fk_idem_client`
        FOREIGN KEY (`client_id`) REFERENCES `api_clients`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
