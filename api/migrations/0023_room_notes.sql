-- 0023_room_notes.sql — Añade columna notes a rooms
ALTER TABLE `rooms` ADD COLUMN `notes` TEXT NULL AFTER `simulated_override`;
