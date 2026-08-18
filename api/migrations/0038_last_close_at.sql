-- 0038: iot_sessions.last_close_at (F31)
-- Anchors the exit rule evaluation window to door-close time
-- instead of door-open time, tolerating long-open doors and
-- presence radars with slow retention clearing (ZY-M100-5).
ALTER TABLE iot_sessions
  ADD COLUMN last_close_at DATETIME(3) NULL AFTER last_open_at;
