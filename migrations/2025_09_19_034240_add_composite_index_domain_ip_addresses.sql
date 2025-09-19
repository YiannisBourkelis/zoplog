-- Migration: Add Composite Index For Domain Ip Addresses Query Optimization
-- Created: 2025-09-19 03:42:40
-- Description: Add composite index on domain_ip_addresses (ip_address_id, last_seen) to optimize complex JOIN queries in blocked traffic interface

-- Add composite index for domain_ip_addresses to optimize the complex JOIN condition
-- This index will improve performance for queries that filter by IP address and recent activity
-- Only create if it doesn't already exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
     AND table_name = 'domain_ip_addresses'
     AND index_name = 'idx_domain_ip_ip_last_seen') = 0,
    'CREATE INDEX `idx_domain_ip_ip_last_seen` ON `domain_ip_addresses` (`ip_address_id`, `last_seen`)',
    'SELECT "Index idx_domain_ip_ip_last_seen already exists"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;