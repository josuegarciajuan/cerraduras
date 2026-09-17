-- 0112_presence_source_disabled.sql — Desactivación FUERTE del ZY-M100 (banco de pruebas)
--
-- El ZY-M100 (bf98d27d79685e38a2wbda) NO se va a usar. Se marca
-- `meta_json.presence_source='disabled'` para que NADA lo active:
--   - `presence-poller-manager.sh` NO lanza su poller de nube (0 cuota IoT Core).
--   - El consumer Pulsar no lo rastrea (no reenvía sus mensajes).
--   - `probeTuyaOnlineOnce()` no lo sondea (0 cuota).
--   - `resolvePresenceDeviceForRoom()` lo ignora → la calibración responde
--     "sin sensor" y no llama a Tuya.
--
-- NO se borra el dispositivo ni el código del poller: para reutilizarlo en el
-- futuro basta con quitar el flag (`presence_source=NULL`) y reiniciar el manager.
--
-- Valores de `presence_source`: `push` (push de Tuya), `disabled` (apagado fuerte),
-- ausente/NULL (poller de nube). Ver design.md §13.9.
--
-- Idempotente.

UPDATE devices
   SET meta_json = JSON_SET(COALESCE(meta_json, JSON_OBJECT()), '$.presence_source', 'disabled')
 WHERE kind = 'PRESENCE'
   AND external_id = 'bf98d27d79685e38a2wbda';
