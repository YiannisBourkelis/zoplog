-- Migration: Create base tables for ZopLog
-- Created: 2025-09-08
-- Description: Initial database schema with core tables for packet logging and blocklist management

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Accept languages table
CREATE TABLE IF NOT EXISTS `accept_languages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `accept_language` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accept_language` (`accept_language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- IP addresses table
CREATE TABLE IF NOT EXISTS `ip_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- MAC addresses table
CREATE TABLE IF NOT EXISTS `mac_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mac_address` (`mac_address`),
  KEY `idx_mac_address` (`mac_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Paths table
CREATE TABLE IF NOT EXISTS `paths` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_path` (`path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- User agents table
CREATE TABLE IF NOT EXISTS `user_agents` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_agent` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_agent` (`user_agent`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Hostnames table
CREATE TABLE IF NOT EXISTS `domains` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `idx_domain` (`domain`),
  KEY `ip_id` (`ip_id`),
  CONSTRAINT `domains_ibfk_1` FOREIGN KEY (`ip_id`) REFERENCES `ip_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
