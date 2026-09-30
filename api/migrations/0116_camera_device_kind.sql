-- 0116_camera_device_kind.sql — Dispositivo cámara IP con subtipo (F60, RF-68)
--
-- Trazabilidad: RF-68.1/68.2/68.4 · design.md §24.1
--
-- Añade el kind CAMERA al ENUM de devices y una columna `subtype` que, para las
-- cámaras, indica su posición física y por tanto cuándo se activa:
--   EXTERIOR  cámara del pasillo / puerta (capta llegada y salida)
--   INTERIOR  cámara dentro del almacén (prueba que se entró)
-- Para el resto de kinds `subtype` es NULL.
--
-- Un mismo pack puede tener varias cámaras: la unicidad vigente es
-- uniq_devices_external(kind, external_id), que admite múltiples CAMERA.

ALTER TABLE `devices`
  MODIFY COLUMN `kind`
    ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH','SCANNER','CAMERA') NOT NULL;

ALTER TABLE `devices`
  ADD COLUMN `subtype` VARCHAR(32) NULL AFTER `kind`,
  ADD KEY `idx_devices_kind_subtype` (`kind`, `subtype`);
