-- Migration: Refactor Schema Remove Blocked Ips And Hostname Ip Relation
-- Created: 2025-09-18 23:25:15
-- Description: Remove blocked_ips table, remove ip_id from hostnames

-- Drop the blocked_ips table as it's no longer needed
DROP TABLE IF EXISTS `blocked_ips`;

-- Remove the ip_id column and its foreign key from hostnames table
ALTER TABLE `hostnames` DROP FOREIGN KEY `hostnames_ibfk_1`;
ALTER TABLE `hostnames` DROP COLUMN `ip_id`;

