-- Migration: Create blocklist management tables
-- Created: 2025-09-08
-- Description: Tables for managing blocklists, domains, and blocked IPs

-- Blocklists table
CREATE TABLE IF NOT EXISTS `blocklists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` text NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other') NOT NULL,
  `active` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Blocklist domains table
CREATE TABLE IF NOT EXISTS `blocklist_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blocklist_id` int(11) NOT NULL,
  `domain` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_blocklist_domain` (`blocklist_id`,`domain`),
  KEY `idx_blocklist_id` (`blocklist_id`),
  CONSTRAINT `fk_blocklist_domains_blocklists` FOREIGN KEY (`blocklist_id`) REFERENCES `blocklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Blocked IPs table
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocklist_domain_id` int(11) NOT NULL,
  `ip_id` bigint(20) UNSIGNED NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `hit_count` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_blocked_domain_ip` (`blocklist_domain_id`,`ip_id`),
  KEY `idx_blocked_ips_last_seen` (`last_seen`),
  KEY `idx_blocked_ips_domain` (`blocklist_domain_id`),
  KEY `idx_blocked_ips_ip` (`ip_id`),
  CONSTRAINT `fk_blocked_ips_domain` FOREIGN KEY (`blocklist_domain_id`) REFERENCES `blocklist_domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_blocked_ips_ip` FOREIGN KEY (`ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

