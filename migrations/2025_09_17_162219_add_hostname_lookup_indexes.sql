-- Migration: Add Hostname Lookup Indexes
-- Created: 2025-09-17 16:22:19
-- Description: Add composite indexes to optimize hostname lookup queries for blocked traffic interface

-- Add composite index for source IP hostname lookups with time filtering
-- This optimizes queries that find hostnames for source IPs within time windows
-- Only create if it doesn't already exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
     AND table_name = 'packet_logs'
     AND index_name = 'idx_packet_logs_src_hostname_time') = 0,
    'CREATE INDEX `idx_packet_logs_src_hostname_time` ON `packet_logs` (`src_ip_id`, `hostname_id`, `packet_timestamp`)',
    'SELECT "Index idx_packet_logs_src_hostname_time already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add composite index for destination IP hostname lookups with time filtering
-- This optimizes queries that find hostnames for destination IPs within time windows
-- Only create if it doesn't already exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
     AND table_name = 'packet_logs'
     AND index_name = 'idx_packet_logs_dst_hostname_time') = 0,
    'CREATE INDEX `idx_packet_logs_dst_hostname_time` ON `packet_logs` (`dst_ip_id`, `hostname_id`, `packet_timestamp`)',
    'SELECT "Index idx_packet_logs_dst_hostname_time already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on blocked_events for IP and time lookups
-- This optimizes queries that correlate blocked events with hostname data
CREATE INDEX `idx_blocked_events_ip_time`
ON `blocked_events` (`src_ip_id`, `dst_ip_id`, `event_time`);

