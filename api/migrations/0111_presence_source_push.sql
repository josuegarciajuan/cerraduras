-- 0111_presence_source_push.sql — Presencia por push de Tuya (sin poller de nube)
--
-- El 24G V3 (bf9a278e76e2c3f01ay0cs) ya reporta en TIEMPO REAL vía el Message
-- Service de Tuya (Pulsar), tras ampliar la regla de mensajes para incluir su
-- device id (ver design.md §13.9). Marcar `meta_json.presence_source='push'`
-- hace que `presence-poller-manager.sh` NO lance su poller de nube: la presencia
-- llega por el consumer (como la puerta) y no consume cuota IoT Core.
--
-- La puerta (PROXIMITY) no se toca. El poller de nube sigue disponible para
-- otros sensores de presencia (p. ej. el ZY-M100) o si un device se deja sin
-- el flag `push`.
--
-- Idempotente: JSON_SET con el mismo valor no altera el resto del meta.

UPDATE devices
   SET meta_json = JSON_SET(COALESCE(meta_json, JSON_OBJECT()), '$.presence_source', 'push')
 WHERE kind = 'PRESENCE'
   AND external_id = 'bf9a278e76e2c3f01ay0cs';
