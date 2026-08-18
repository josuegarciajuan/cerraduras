-- 0007_stays.sql (TSK-026)
-- Rental sessions. Each stay tracks its lifecycle and the VB6 references
-- necessary for the WS-VB6 bridge to insert into `deudas` and `log_usuarios`.
--
-- VB6 refs are nullable because VB6 sometimes issues the QR before the ticket
-- number (codtic) is finalized. A later PATCH /stays/{id}/vb6-refs completes
-- them and triggers debt sync retries.
--
-- See design.md §4.6 and §19.

CREATE TABLE `stays` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `room_id`              BIGINT UNSIGNED NOT NULL,
    `status`               ENUM('RESERVED','OCCUPIED','OVERSTAY','EXITED','CLOSED','CANCELED')
                            NOT NULL DEFAULT 'RESERVED',
    `duracion_minutos`     INT UNSIGNED    NOT NULL,
    `reserved_at`          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `first_entry_at`       DATETIME(3)     NULL,
    `exit_detected_at`     DATETIME(3)     NULL,
    `closed_at`            DATETIME(3)     NULL,
    `vb6_codalq`           BIGINT          NULL,
    `vb6_codtic`           BIGINT          NULL,
    `vb6_codcli`           INT             NULL,
    `vb6_codart`           INT             NULL,
    `vb6_codlot`           INT             NULL,
    `vb6_codhab_raw`       VARCHAR(16)     NULL,
    `vb6_temporada`        CHAR(4)         NULL,
    `vb6_empresa`          TINYINT         NULL,
    `vb6_departamento`     TINYINT         NULL,
    `created_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at`           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_stays_room_status`      (`room_id`, `status`),
    KEY `idx_stays_vb6_tic_temp`     (`vb6_codtic`, `vb6_temporada`),
    KEY `idx_stays_vb6_codalq`       (`vb6_codalq`),
    CONSTRAINT `fk_stays_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
