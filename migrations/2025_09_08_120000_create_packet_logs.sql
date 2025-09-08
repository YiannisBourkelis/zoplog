-- Migration: Create packet logging tables
-- Created: 2025-09-08
-- Description: Main packet logs table and blocked events table

-- Main packet logs table
CREATE TABLE IF NOT EXISTS `packet_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `packet_timestamp` datetime NOT NULL,
  `src_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `src_port` int(10) UNSIGNED NOT NULL,
  `dst_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dst_port` int(10) UNSIGNED NOT NULL,
  `method` enum('GET','POST','PUT','DELETE','HEAD','OPTIONS','PATCH','N/A','TLS_CLIENTHELLO') DEFAULT 'N/A',
  `hostname_id` bigint(20) UNSIGNED DEFAULT NULL,
  `path_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `accept_language_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` enum('HTTP','HTTPS') NOT NULL,
  `src_mac_id` int(11) DEFAULT NULL,
  `dst_mac_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_packet_time` (`packet_timestamp`),
  KEY `idx_src_ip` (`src_ip_id`),
  KEY `idx_dst_ip` (`dst_ip_id`),
  KEY `idx_hostname` (`hostname_id`),
  KEY `idx_path` (`path_id`),
  KEY `idx_user_agent` (`user_agent_id`),
  KEY `idx_accept_language` (`accept_language_id`),
  KEY `idx_method_type` (`method`,`type`),
  KEY `src_mac_id` (`src_mac_id`),
  KEY `dst_mac_id` (`dst_mac_id`),
  KEY `idx_packet_time_type_method` (`packet_timestamp` DESC,`type`,`method`),
  CONSTRAINT `packet_logs_ibfk_1` FOREIGN KEY (`src_ip_id`) REFERENCES `ip_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_2` FOREIGN KEY (`dst_ip_id`) REFERENCES `ip_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_3` FOREIGN KEY (`hostname_id`) REFERENCES `hostnames` (`id`),
  CONSTRAINT `packet_logs_ibfk_4` FOREIGN KEY (`path_id`) REFERENCES `paths` (`id`),
  CONSTRAINT `packet_logs_ibfk_5` FOREIGN KEY (`user_agent_id`) REFERENCES `user_agents` (`id`),
  CONSTRAINT `packet_logs_ibfk_6` FOREIGN KEY (`accept_language_id`) REFERENCES `accept_languages` (`id`),
  CONSTRAINT `packet_logs_ibfk_7` FOREIGN KEY (`src_mac_id`) REFERENCES `mac_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_8` FOREIGN KEY (`dst_mac_id`) REFERENCES `mac_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Blocked events table
CREATE TABLE IF NOT EXISTS `blocked_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_time` datetime NOT NULL DEFAULT current_timestamp(),
  `direction` enum('IN','OUT','FWD') NOT NULL,
  `src_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dst_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `src_port` int(11) DEFAULT NULL,
  `dst_port` int(11) DEFAULT NULL,
  `proto` varchar(8) DEFAULT NULL,
  `iface_in` varchar(32) DEFAULT NULL,
  `iface_out` varchar(32) DEFAULT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event_time` (`event_time`),
  KEY `idx_direction` (`direction`),
  KEY `idx_src_ip` (`src_ip_id`),
  KEY `idx_dst_ip` (`dst_ip_id`),
  CONSTRAINT `fk_blocked_events_src_ip` FOREIGN KEY (`src_ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_blocked_events_dst_ip` FOREIGN KEY (`dst_ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
