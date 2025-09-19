-- Migration: Add Composite Index Blocked Events Event Time Wan Ip
-- Created: 2025-09-19 21:34:14
-- Description: Add composite index on blocked_events (event_time, wan_ip_id) for faster queries filtering by time and joining on wan_ip_id

-- Add composite index for better performance on queries that filter by event_time and join on wan_ip_id
CREATE INDEX `idx_blocked_events_event_time_wan_ip` ON `blocked_events` (`event_time`, `wan_ip_id`);

