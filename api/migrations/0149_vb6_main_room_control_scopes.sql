-- 0149_vb6_main_room_control_scopes.sql — F126/F128: control de sala para VB6-MAIN
--
-- Trazabilidad: F126 (RF-360..RF-372), F128 (RF-381..RF-385) · contracts.md §F126/F128
--
-- El TPV/VB6 usa `VB6-MAIN` para emitir QR y ahora también para el control de
-- sala (luz, apertura y reset). `rooms:read` y `rooms:state` ya estaban
-- presentes; faltaban los scopes de luz y apertura.
--
-- Idempotente: cada scope se añade solo si falta (FIND_IN_SET = 0). No modifica
-- claves, no toca otros clientes y no concede `locks:override` ni `sim:*`.

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'locks:open')
 WHERE `code` = 'VB6-MAIN'
   AND FIND_IN_SET('locks:open', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'switches:write')
 WHERE `code` = 'VB6-MAIN'
   AND FIND_IN_SET('switches:write', `scopes_csv`) = 0;
