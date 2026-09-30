-- 0117_roomtype_warehouse_windows.sql — Ventanas de grabación del almacén (F60, RF-67.2)
--
-- Trazabilidad: RF-67.2 · design.md §24.1
--
-- Dos ventanas por tipo de habitación para el motor de grabación del almacén:
--   warehouse_confirm_seconds          X = segundos para confirmar presencia tras
--                                      un disparo (QR/puerta) antes de descartar.
--   warehouse_exterior_margin_seconds  M = margen extra que sigue grabando la
--                                      cámara EXTERIOR tras perderse la presencia.
--
-- Valores por defecto aprobados: X = 40 s, M = 5 s. No afectan a tipos no-almacén.

ALTER TABLE `room_types`
  ADD COLUMN `warehouse_confirm_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 40 AFTER `exit_check_seconds`,
  ADD COLUMN `warehouse_exterior_margin_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `warehouse_confirm_seconds`;
