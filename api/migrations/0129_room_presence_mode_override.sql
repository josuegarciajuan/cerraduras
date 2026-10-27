-- 0129_room_presence_mode_override.sql — F91/TSK-91.04
--
-- Trazabilidad: RF-148.1/RF-148.2 · design.md §F91
--
-- `room_types.presence_mode` es compartido por todas las salas del mismo tipo.
-- `almacenZigbee` comparte ALMACEN_BEBIDAS con el almacén actual, así que no se
-- puede cambiar el modo del tipo sin alterar el almacén existente. Esta columna
-- permite que una sala concreta sobrescriba el modo de su tipo.
--
-- NULL = hereda `room_types.presence_mode` (comportamiento actual).

ALTER TABLE `rooms`
    ADD COLUMN IF NOT EXISTS `presence_mode_override` VARCHAR(24) NULL AFTER `presence_check_seconds`;
