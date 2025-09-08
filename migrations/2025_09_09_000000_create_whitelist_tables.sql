-- Migration: Create whitelist management tables
-- Created: 2025-09-09
-- Description: Tables for managing whitelists and whitelist domains

-- Whitelists table
CREATE TABLE IF NOT EXISTS `whitelists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` enum('adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other') NOT NULL,
  `active` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Whitelist domains table
CREATE TABLE IF NOT EXISTS `whitelist_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `whitelist_id` int(11) NOT NULL,
  `domain` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_whitelist_domain` (`whitelist_id`,`domain`),
  KEY `idx_whitelist_id` (`whitelist_id`),
  CONSTRAINT `fk_whitelist_domains_whitelists` FOREIGN KEY (`whitelist_id`) REFERENCES `whitelists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
