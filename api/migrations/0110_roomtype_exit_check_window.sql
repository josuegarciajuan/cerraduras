-- 0110_roomtype_exit_check_window.sql — Ventana post-cierre configurable (F44)
--
-- Trazabilidad: RF-51.2, RF-51.4, RF-51.6 · design.md §4 (F44)
--
-- Añade `exit_check_seconds`: segundos que el poller de presencia sigue
-- muestreando DESPUÉS del cierre de puerta para decidir si el huésped salió.
-- Antes se derivaba fija como `exit_presence_gap_seconds + 10` (25 s), que se
-- quedaba corto para confirmar presencia con el radar 24G V3.
--
-- Política: ninguna ventana de verificación por debajo de 40 s.

ALTER TABLE room_types
  ADD COLUMN exit_check_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 40
  COMMENT 'F44: segundos de muestreo de presencia tras el cierre de puerta';

UPDATE room_types
   SET presence_entry_window_seconds = 40
 WHERE presence_entry_window_seconds < 40;
