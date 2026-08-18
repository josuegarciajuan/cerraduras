-- Migration 0036: Presence check window configurable per room.
--
-- Adds presence_check_seconds column so each room can override the
-- default presence detection window (default 30s, NULL = inherit
-- from room_type.exit_presence_gap_seconds).
--
-- See RF-30, TSK-PRES-001.

ALTER TABLE rooms
  ADD COLUMN presence_check_seconds INT UNSIGNED NULL DEFAULT NULL
  AFTER simulated_override;
