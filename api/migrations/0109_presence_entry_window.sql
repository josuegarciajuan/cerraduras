-- 0109_presence_entry_window.sql — Ventana de muestreo de entrada del poller (F44)
--
-- Trazabilidad: RF-51.2, RF-51.6 · design.md §4 (F44)
--
-- Añade la ventana configurable de muestreo de presencia TRAS LA APERTURA de
-- puerta (`presence_entry_window_seconds`, por defecto 90 s). El poller de
-- presencia la lee de `/live.entry_window_seconds`: muestrea a 2 s hasta
-- detectar presencia o agotar la ventana, evitando llamadas indefinidas a Tuya.
--
-- La ventana post-cierre (`exit_check_seconds`) se deriva del gap ya existente
-- (`exit_presence_gap_seconds` + margen de retención del radar); no requiere
-- columna nueva.

ALTER TABLE room_types
  ADD COLUMN presence_entry_window_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 90
  COMMENT 'F44/RF-51.2: max seconds sampling presence after a door OPEN until detected';
