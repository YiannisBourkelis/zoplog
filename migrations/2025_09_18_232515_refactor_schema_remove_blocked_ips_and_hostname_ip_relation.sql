-- Migration: Refactor Schema Remove Blocked Ips And Hostname Ip Relation
-- Created: 2025-09-18 23:25:15
-- Description: Remove blocked_ips table, remove ip_id from hostnames, create hostname_ip_addresses pivot table

-- Drop the blocked_ips table as it's no longer needed
DROP TABLE IF EXISTS `blocked_ips`;

-- Remove the ip_id column and its foreign key from hostnames table
ALTER TABLE `hostnames` DROP FOREIGN KEY `hostnames_ibfk_1`;
ALTER TABLE `hostnames` DROP COLUMN `ip_id`;

-- Create pivot table for hostname-IP address relationships
-- This allows a hostname to have multiple IP addresses and vice versa
CREATE TABLE IF NOT EXISTS `hostname_ip_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hostname_id` bigint(20) UNSIGNED NOT NULL,
  `ip_address_id` bigint(20) UNSIGNED NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blocked_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Counter for blocked/malicious traffic',
  `allowed_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Counter for allowed/normal traffic',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_hostname_ip` (`hostname_id`, `ip_address_id`),
  KEY `idx_hostname_ip_hostname` (`hostname_id`),
  KEY `idx_hostname_ip_ip` (`ip_address_id`),
  KEY `idx_hostname_ip_last_seen` (`last_seen`),
  CONSTRAINT `fk_hostname_ip_hostname` FOREIGN KEY (`hostname_id`) REFERENCES `hostnames` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hostname_ip_ip` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

