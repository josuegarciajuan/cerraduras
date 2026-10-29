-- 0133_crm_user_role_key_manager.sql — F98: rol CRM para el panel de llaves
--
-- Trazabilidad: RF-178 · design.md §98.2 · contracts.md §F98.3
--
-- `crm_users.role` nació como ENUM('admin','operator','viewer'). El rol
-- `key_manager` se valida en CrmUser::ROLES, pero la columna debe admitirlo
-- para poder crear la cuenta del encargado desde /panel.
--
-- No crea usuarios, no modifica contraseñas y no toca otras tablas.

ALTER TABLE `crm_users`
    MODIFY COLUMN `role` ENUM('admin','operator','viewer','key_manager')
        NOT NULL DEFAULT 'operator';
