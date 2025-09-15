-- Migration: Add Performance Indexes
-- Created: 2025-09-15 16:45:29
-- Description: Add indexes to improve query performance for blocked traffic

-- Add index on blocklists.type for better performance in SUM CASE queries
ALTER TABLE blocklists ADD INDEX idx_type (type);

-- Add composite index on blocked_events for ORDER BY and JOIN optimization
ALTER TABLE blocked_events ADD INDEX idx_event_time_ip (event_time, src_ip_id, dst_ip_id);

