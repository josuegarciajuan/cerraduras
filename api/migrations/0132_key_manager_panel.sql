-- 0132_key_manager_panel.sql — F98/RF-178..RF-184: panel de llaves
--
-- Trazabilidad: RF-178, RF-179 · design.md §98.2/98.5 · contracts.md §F98.3/98.7
--
-- 1) Rol CRM `key_manager`.
--    No existe una tabla de roles CRM: el rol es un valor admitido en la
--    columna `crm_users.role` (admin | operator | viewer | key_manager).
--    La validación vive en CrmUser::ROLES; esta migración NO crea usuarios ni
--    contraseñas por defecto.
--
-- 2) Scopes para clientes API existentes.
--    Los `api_clients` que ya podían operar (tienen `rooms:write`) reciben los
--    scopes necesarios para la gestión de llaves, trabajadores y fichas.
--    Es idempotente: cada ámbito se añade solo si falta (FIND_IN_SET = 0) y
--    nunca se eliminan scopes ni se modifican llaves, trabajadores o fichas.
--
-- No crea tablas, no toca el pipeline de sensores/Tuya ni /almacen.

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'keys:read')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('keys:read', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'keys:write')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('keys:write', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'nfc-cards:read')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('nfc-cards:read', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'nfc-cards:write')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('nfc-cards:write', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'workers:read')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('workers:read', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'workers:write')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('workers:write', `scopes_csv`) = 0;

UPDATE `api_clients`
   SET `scopes_csv` = CONCAT_WS(',', `scopes_csv`, 'worker-roles:read')
 WHERE FIND_IN_SET('rooms:write', `scopes_csv`) > 0
   AND FIND_IN_SET('worker-roles:read', `scopes_csv`) = 0;
