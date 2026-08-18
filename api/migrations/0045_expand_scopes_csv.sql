-- 0045_expand_scopes_csv.sql — Expand scopes_csv for worker scopes
-- F38: worker-roles:*, workers:* scopes need more room.
ALTER TABLE `api_clients`
    MODIFY `scopes_csv` VARCHAR(512) NOT NULL DEFAULT '';
