-- F138 — todas las STANDARD comparten el mismo motor de ocupación.
--
-- `rooms.presence_mode_override` deja de gobernar las STANDARD. Se limpia para
-- que ninguna UI o servicio antiguo presente una política distinta. Las salas
-- especiales (ALMACEN_BEBIDAS/TIMBRADO) conservan su configuración.

UPDATE rooms r
  JOIN room_types rt ON rt.id = r.room_type_id
   SET r.presence_mode_override = NULL
 WHERE rt.code = 'STANDARD';
