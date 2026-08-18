-- 0008_qr_credentials.sql (TSK-027)
-- QR tokens issued for a stay. We store:
--   - jti (unique id embedded in the token)
--   - token_hash (SHA-256 of the full token; the raw token is NEVER persisted)
--   - expires_at, consumed_at, revoked_at
--
-- See design.md §4.7 and §5.

CREATE TABLE `qr_credentials` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stay_id`       BIGINT UNSIGNED NOT NULL,
    `room_id`       BIGINT UNSIGNED NOT NULL,
    `jti`           CHAR(36)        NOT NULL,
    `token_hash`    CHAR(64)        NOT NULL,
    `issued_at`     DATETIME(3)     NOT NULL,
    `expires_at`    DATETIME(3)     NOT NULL,
    `consumed_at`   DATETIME(3)     NULL,
    `revoked_at`    DATETIME(3)     NULL,
    `created_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_qr_jti`      (`jti`),
    UNIQUE KEY `uniq_qr_token`    (`token_hash`),
    KEY         `idx_qr_stay`     (`stay_id`),
    KEY         `idx_qr_expires`  (`expires_at`),
    CONSTRAINT `fk_qr_stay`
        FOREIGN KEY (`stay_id`) REFERENCES `stays`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_qr_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
