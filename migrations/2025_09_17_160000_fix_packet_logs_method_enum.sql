-- Migration: Update packet_logs method enum to include more HTTP methods (FIXED)
-- Created: 2025-09-17
-- Description: Expand the method enum to include additional HTTP methods
-- Note: This migration fixes the previous failed migration by ensuring proper recording

-- Check if the enum already has the correct values
SET @current_enum = (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'packet_logs'
    AND COLUMN_NAME = 'method'
);

-- Only run the ALTER if the enum doesn't already include the new values
SET @alter_sql = IF(
    @current_enum NOT LIKE '%PROPFIND%',
    'ALTER TABLE packet_logs MODIFY COLUMN method enum(''GET'',''POST'',''PUT'',''DELETE'',''HEAD'',''OPTIONS'',''PATCH'',''CONNECT'',''TRACE'',''PROPFIND'',''PROPPATCH'',''MKCOL'',''COPY'',''MOVE'',''LOCK'',''UNLOCK'',''N/A'',''TLS_CLIENTHELLO'') DEFAULT ''N/A'';',
    'SELECT ''Enum already updated'' as status'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;