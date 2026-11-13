-- F138 — ventana de entrada STANDARD.
--
-- El flujo único de ocupación verifica la entrada durante 20 s, no los 90 s
-- heredados del poller antiguo. Se conserva la columna para que sea ajustable.

UPDATE room_types
   SET presence_entry_window_seconds = 20
 WHERE code = 'STANDARD'
   AND presence_entry_window_seconds > 20;
