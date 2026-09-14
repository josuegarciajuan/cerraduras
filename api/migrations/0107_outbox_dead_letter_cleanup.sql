-- 0107_outbox_dead_letter_cleanup.sql — Limpieza de items de outbox no sincronizables
--
-- Los `debt.created` cuyo stay no tiene refs VB6 (codtic/codcli) no pueden ser
-- aceptados por WS-VB6 (422 missing_vb6_refs). A partir de ahora no se encolan
-- (ver DebtsService), pero los ya encolados se marcan FAILED para que el worker
-- deje de reintentarlos y no haga fallar la cola.
--
-- Idempotente: solo actúa sobre PENDING con codtic nulo.

UPDATE outbox_vb6
   SET status          = 'FAILED',
       attempts        = GREATEST(attempts, 20),
       last_error      = COALESCE(last_error, 'permanent: unsupported by WS-VB6 (missing vb6 refs)'),
       next_attempt_at = UTC_TIMESTAMP(3)
 WHERE topic = 'debt.created'
   AND status = 'PENDING'
   AND (payload_json IS NULL OR JSON_VALUE(payload_json, '$.vb6_refs.codtic') IS NULL);
