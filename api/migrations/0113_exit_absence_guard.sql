-- 0113_exit_absence_guard.sql — Guarda de ausencia corta para la regla de SALIDA (Bug 1)
--
-- Trazabilidad: RF-47.2.4, RF-47.2.5 · design.md §3.4 · contracts.md §3.3
--
-- Hasta ahora `rooms.presence_check_seconds` (override de sala) gobernaba a la
-- vez la ventana de consolidación de ENTRADA y la guarda de SALIDA. Un override
-- legacy de ~15-30 s retrasaba ~30 s la confirmación de salida.
--
-- Se DESACOPLAN:
--   - ENTRADA: `room_types.presence_entry_window_seconds` (default 90 s).
--   - SALIDA:  `EXIT_ABSENCE_GUARD_SECONDS` (global, default 3 s), con override
--              por sala `rooms.presence_check_seconds`.
--
-- Esta migración limpia los overrides legacy >= 10 s para que no mantengan la
-- tolerancia antigua. Tras ella aplica el default global (3 s) salvo un override
-- explícito y corto (< 10 s) del operador. `gap_seconds` sigue expuesto en
-- /live como campos legacy (override ?? room_type.exit_presence_gap_seconds ?? 15)
-- pero ya no gobierna la regla de salida.
--
-- Idempotente (segunda ejecución no afecta a más filas).

UPDATE rooms
   SET presence_check_seconds = NULL
 WHERE presence_check_seconds IS NOT NULL
   AND presence_check_seconds >= 10;
