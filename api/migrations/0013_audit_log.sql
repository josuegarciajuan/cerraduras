-- 0013_audit_log.sql (TSK-032)
-- Append-only audit trail. Services write here alongside domain mutations.
--
-- See design.md §4.12.

CREATE TABLE `audit_log` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_client_id`   BIGINT UNSIGNED NULL,
    `scope`             VARCHAR(64)     NOT NULL,
    `action`            VARCHAR(64)     NOT NULL,
    `entity`            VARCHAR(64)     NOT NULL,
    `entity_id`         VARCHAR(64)     NULL,
    `correlation_id`    CHAR(26)        NOT NULL,
    `ip`                VARCHAR(45)     NULL,
    `payload_json`      JSON            NULL,
    `occurred_at`       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_audit_entity`      (`entity`, `entity_id`),
    KEY `idx_audit_occurred_at` (`occurred_at`),
    KEY `idx_audit_corr`        (`correlation_id`),
    KEY `idx_audit_actor`       (`actor_client_id`),
    CONSTRAINT `fk_audit_actor`
        FOREIGN KEY (`actor_client_id`) REFERENCES `api_clients`(`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
