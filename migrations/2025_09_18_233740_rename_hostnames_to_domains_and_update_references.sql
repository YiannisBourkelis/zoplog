-- Migration: Rename Hostnames To Domains And Update References
-- Created: 2025-09-18 23:37:40
-- Description: Rename hostnames table to domains, rename hostname column to domain, rename hostname_ip_addresses to domain_ip_addresses

-- Create the domain_ip_addresses table 
CREATE TABLE IF NOT EXISTS `domain_ip_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain_id` bigint(20) UNSIGNED NOT NULL,
  `ip_address_id` bigint(20) UNSIGNED NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blocked_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Counter for blocked/malicious traffic',
  `allowed_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Counter for allowed/normal traffic',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_domain_ip` (`domain_id`, `ip_address_id`),
  KEY `idx_domain_ip_domain` (`domain_id`),
  KEY `idx_domain_ip_ip` (`ip_address_id`),
  KEY `idx_domain_ip_last_seen` (`last_seen`),
  CONSTRAINT `fk_domain_ip_domain` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_domain_ip_ip` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

