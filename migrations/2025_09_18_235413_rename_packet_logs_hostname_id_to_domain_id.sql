-- Migration: Rename Packet Logs Hostname Id To Domain Id
-- Created: 2025-09-18 23:54:13
-- Description: Rename hostname_id column to domain_id in packet_logs table

-- Rename hostname_id column to domain_id in packet_logs table
ALTER TABLE `packet_logs` CHANGE `hostname_id` `domain_id` bigint(20) unsigned DEFAULT NULL;

