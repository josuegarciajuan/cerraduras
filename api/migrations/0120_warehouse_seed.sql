-- 0120_warehouse_seed.sql — Tipo AlmacenBebidas y pack de almacén (F60, RF-67)
--
-- Trazabilidad: RF-67.1/67.3/67.4 · design.md §24.1
--
-- Idempotente (INSERT IGNORE). No fija RTSP ni secretos: las cámaras se añaden
-- desde el panel /almacen o vía API una vez conocidas las URLs RTSP.

INSERT IGNORE INTO `room_types`
  (`code`, `name`, `grace_minutes`, `exit_presence_gap_seconds`,
   `reentry_cooldown_seconds`, `qr_usage_window_minutes`,
   `presence_entry_window_seconds`, `exit_check_seconds`,
   `warehouse_confirm_seconds`, `warehouse_exterior_margin_seconds`)
VALUES
  ('ALMACEN_BEBIDAS', 'Almacén de bebidas', 5, 15, 20, 30, 90, 40, 40, 5);

INSERT IGNORE INTO `device_packs` (`code`, `name`)
VALUES ('ALMACEN_BEBIDAS', 'Pack Almacén de bebidas');
