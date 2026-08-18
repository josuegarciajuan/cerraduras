-- 0026_qr_test_scanner.sql — Añade SCANNER y QR_TEST al ENUM de devices.kind
ALTER TABLE `devices` MODIFY COLUMN `kind` ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH','SCANNER','QR_TEST') NOT NULL;

-- Insertar SCANNER (Lector QR GM65) en el pack de pruebas
SET @pack_id = (SELECT id FROM device_packs WHERE code = 'PRUEBAS' LIMIT 1);
INSERT IGNORE INTO `devices` (`pack_id`, `kind`, `external_id`, `meta_json`)
VALUES (@pack_id, 'SCANNER', 'gm65-uart-1', '{"model":"GM65","interface":"UART-RX2/TX2","note":"Conectado al ESP32 por UART"}');

-- Insertar QR_TEST placeholder (el QR real se genera al aplicar el pack a una habitación)
INSERT IGNORE INTO `devices` (`pack_id`, `kind`, `external_id`, `meta_json`)
VALUES (@pack_id, 'QR_TEST', 'pending', '{"note":"QR permanente de pruebas. Se genera al asignar el pack a una habitación."}');
