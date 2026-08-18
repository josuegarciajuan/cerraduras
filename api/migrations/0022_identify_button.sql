-- 0022_identify_button.sql (F26: Botón "Mírame" — RF-18)
-- Añade columnas para marcar un dispositivo como "mírame"
-- desde el ESP32 cuando se presiona el pulsador físico en GPIO4.
-- See design.md §4.20, contracts.md §2.13.

ALTER TABLE `devices`
    ADD COLUMN `is_identified` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `identified_at`  DATETIME(3) NULL;
