-- 0121_warehouse_exterior_margin_10.sql — F83/RF-106.3: margen exterior 10 s
-- Trazabilidad: RF-106.3 · design.md §47.4
-- La cámara EXTERIOR sigue grabando 10 s tras la salida para registrar cómo se va el individuo.
UPDATE `room_types` SET `warehouse_exterior_margin_seconds` = 10 WHERE `code` = 'ALMACEN_BEBIDAS';
