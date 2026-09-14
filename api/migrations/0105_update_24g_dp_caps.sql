-- 0105_update_24g_dp_caps.sql — Escala de distancia del 24G-Presence Sensor V3
--
-- El 24G V3 reporta `target_dis_closest` con scale=1 (decímetros: 0-100 ⇒ 0.0-10.0 m),
-- mientras el ZY-M100 usa scale=2 (centímetros). Se declara en meta_json.dp_caps
-- para que el dashboard muestre la distancia real al objetivo en metros.
--
-- Idempotente: JSON_SET con el mismo valor no altera el resto del meta.

UPDATE devices
   SET meta_json = JSON_SET(meta_json, '$.dp_caps.target_scale', 10)
 WHERE kind = 'PRESENCE'
   AND external_id = 'bf9a278e76e2c3f01ay0cs';
