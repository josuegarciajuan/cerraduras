-- 0115_outbox_dead_letter.sql — Cola muerta (dead-letter) para outbox_vb6 (N6 / RF-60)
--
-- Problema: mensajes veneno (4xx de WS-VB6, p. ej. ApiException(client_error)
-- ... HTTP 422) acababan con status='FAILED' y attempts>=20. HealthController::deep
-- los contaba como `outbox_failed` y el sistema quedaba `degraded` para siempre,
-- aunque esos mensajes nunca serán aceptados por el bridge.
--
-- Solución: nuevo estado terminal 'DEAD'. Los DEAD NO se reintentan
-- automáticamente (fetchDue solo toma 'PENDING'), se exponen en /health/deep
-- como `outbox_dead` con status informativo (`warning`, nunca degrada) y pueden
-- reencolarse manualmente desde el panel de administración.
--
-- Idempotencia:
--   - El MODIFY del enum es seguro de repetir.
--   - El UPDATE de reclasificación solo toca filas aún en 'FAILED' que cumplen
--     el patrón; tras la primera ejecución no vuelve a afectar a ninguna fila.

ALTER TABLE `outbox_vb6`
    MODIFY `status` ENUM('PENDING','SENDING','SENT','FAILED','DEAD') NOT NULL DEFAULT 'PENDING';

-- Reclasifica los venenos ya existentes (client_error + agotados) a DEAD.
UPDATE `outbox_vb6`
   SET `status` = 'DEAD'
 WHERE `status` = 'FAILED'
   AND `attempts` >= 20
   AND `last_error` LIKE '%client_error%';
