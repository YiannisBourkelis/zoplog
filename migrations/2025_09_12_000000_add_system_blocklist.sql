-- Migration: Add system blocklist and blocklist types
-- Created: 2025-09-12
-- Description: Add system blocklist support and blocklist types (url/manual)

-- Add type column to blocklists table (only if it doesn't exist)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blocklists' AND COLUMN_NAME = 'type') = 0,
    'ALTER TABLE `blocklists` ADD COLUMN `type` enum(\'url\',\'manual\',\'system\') NOT NULL DEFAULT \'url\' AFTER `category`;',
    'SELECT "Type column already exists";'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create system blocklist for user-added blocked domains (only if it doesn't exist)
INSERT IGNORE INTO `blocklists` (`url`, `description`, `category`, `active`, `created_at`, `updated_at`, `type`) VALUES
('', 'System blocklist for user-blocked domains', 'other', 'active', NOW(), NOW(), 'system');

-- Update existing blocklists to have type 'url' (only if they don't have a type set)
UPDATE `blocklists` SET `type` = 'url' WHERE `type` = '' OR `type` IS NULL;
