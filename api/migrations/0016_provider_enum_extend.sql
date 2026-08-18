-- 0016_provider_enum_extend.sql (TSK-220, F20)
-- Extiende los ENUMs de provider en access_events y presence_events
-- para incluir ESP32 y LOCAL (cerradura local sin comando de red).
--
-- LOCAL: el ESP32 abre su relé tras recibir HTTP 200 en /qr/validate;
--        la API no emite comando de red al actuador físico.
-- ESP32: reservado para futuro uso con servidor HTTP en el ESP32 (remote open).
--
-- Trazabilidad: contracts §9.1, design §2.8, RF-4.2.

ALTER TABLE `access_events`
    MODIFY `provider` ENUM('TUYA','ESP32','LOCAL','SIMULATED') NOT NULL;

ALTER TABLE `presence_events`
    MODIFY `provider` ENUM('TUYA','ESP32','LOCAL','SIMULATED') NOT NULL;
