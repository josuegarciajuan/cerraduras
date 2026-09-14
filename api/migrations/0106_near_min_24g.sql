-- 0106_near_min_24g.sql — Zona muerta mínima del 24G-Presence Sensor V3
--
-- El calibrador gestiona SOLO el rango máximo; la distancia mínima (near_detection)
-- se fuerza siempre a su valor más bajo para que el sensor detecte desde pegado a él.
-- Se declara near_min en dp_caps (0 para el 24G; 0 también para el ZY-M100).
--
-- Idempotente: JSON_SET con el mismo valor no altera el resto del meta.

UPDATE devices
   SET meta_json = JSON_SET(meta_json, '$.dp_caps.near_min', 0)
 WHERE kind = 'PRESENCE'
   AND external_id = 'bf9a278e76e2c3f01ay0cs';
