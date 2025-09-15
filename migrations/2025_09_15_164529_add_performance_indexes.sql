-- Migration: Add Performance Indexes
-- Created: 2025-09-15 16:45:29
-- Description: Add indexes to improve query performance for blocked traffic

-- Add index on blocklists.type for better performance in SUM CASE queries
ALTER TABLE blocklists ADD INDEX idx_type (type);

-- Drop old composite index and create optimized version with dst_port
ALTER TABLE blocked_events DROP INDEX idx_event_time_ip;
ALTER TABLE blocked_events ADD INDEX idx_event_time_ip_port (event_time, src_ip_id, dst_ip_id, dst_port);

-- Add index for dst_ip_id and event_time to optimize JOINs
ALTER TABLE blocked_events ADD INDEX idx_dst_ip_event_time (dst_ip_id, event_time);

