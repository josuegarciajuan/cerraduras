-- 0132_room_sensor_state.sql — F97.3: estado físico del sensor independiente del ciclo
--
-- Trazabilidad: RF-178 · design.md §F97.3 · contracts.md §F97.3
--
-- `iot_sessions` sigue siendo el estado del ciclo de acceso/estancia y se
-- reinicia al crear/limpiar un QR. Ese reset NO debe apagar el imán ni borrar
-- la última posición física conocida del contacto.
--
-- Esta tabla guarda la última lectura APLICADA por sensor/transporte. Solo la
-- escribe el procesador de sensores (o una confirmación manual de polaridad);
-- RoomCycleResetter no la toca.

CREATE TABLE IF NOT EXISTS `room_sensor_state` (
    `room_id`         BIGINT UNSIGNED NOT NULL,
    `sensor`          VARCHAR(16)     NOT NULL,
    `provider`        VARCHAR(24)     NOT NULL,
    `external_id`     VARCHAR(128)    NULL,
    `state_value`     VARCHAR(16)     NULL,       -- NULL = sin estado fiable
    `last_event_id`   BIGINT UNSIGNED NULL,       -- barrera de eventos
    `state_source`    VARCHAR(24)     NOT NULL DEFAULT 'event',
    `occurred_at`     DATETIME(3)     NULL,
    `received_at`     DATETIME(3)     NULL,
    `updated_at`      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
                                      ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`room_id`, `sensor`, `provider`),
    KEY `idx_room_sensor_state_value` (`sensor`, `provider`, `state_value`),
    CONSTRAINT `fk_room_sensor_state_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill conservador: solo se adopta el último evento aplicado si es más
-- reciente que el último reset de la sesión. Si el reset es posterior, no se
-- restaura nada: se exigirá una transición o una confirmación manual.
INSERT INTO `room_sensor_state`
    (`room_id`, `sensor`, `provider`, `external_id`, `state_value`,
     `last_event_id`, `state_source`, `occurred_at`, `received_at`)
SELECT e.`room_id`, 'PROXIMITY', 'ZIGBEE2MQTT', NULL, e.`value`,
       e.`id`, 'event', e.`occurred_at`, e.`received_at`
  FROM `presence_events` e
  JOIN (
        SELECT `room_id`, MAX(`id`) AS `id`
          FROM `presence_events`
         WHERE `sensor` = 'PROXIMITY'
           AND `provider` = 'ZIGBEE2MQTT'
           AND `applied` = 1
         GROUP BY `room_id`
       ) latest ON latest.`id` = e.`id`
  LEFT JOIN `iot_sessions` s ON s.`room_id` = e.`room_id`
 WHERE s.`id` IS NULL OR e.`received_at` >= s.`updated_at`
;
