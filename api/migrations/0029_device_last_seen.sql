-- 0029_device_last_seen.sql — Track device liveness for dashboard status
ALTER TABLE `devices`
  ADD COLUMN IF NOT EXISTS `last_seen_at` DATETIME(3) NULL AFTER `identified_at`;
