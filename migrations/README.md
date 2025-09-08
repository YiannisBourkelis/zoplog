# ZopLog Database Migrations

This directory contains database migration files for ZopLog

## Migration File Naming Convention

Migration files use datetime prefixes followed by descriptive names:
- `2025_09_08_000000_create_migrations_table.sql` - Creates the migration tracking table (must be first)
- `2025_09_08_100000_create_base_tables.sql` - Creates basic lookup tables
- `2025_09_08_110000_create_blocklist_tables.sql` - Creates blocklist management tables
- `2025_09_08_120000_create_packet_logs.sql` - Creates packet logging tables

**Format**: `YYYY_MM_DD_HHMMSS_descriptive_name.sql`

### Benefits of Datetime Naming:
- **Chronological ordering**: Files are automatically sorted by creation time
- **No conflicts**: Multiple developers can create migrations without numbering conflicts
- **Clear history**: Easy to see when each migration was created

## Migration Structure

Each migration file should:
1. Start with a comment describing the migration
2. Include the creation date and description
3. Contain SQL statements to create/modify tables
4. Use `IF NOT EXISTS` for table creation to avoid errors on re-runs

## Migration Tracking

The `migrations` table tracks which migrations have been executed:
- `migration`: The filename (without .sql extension)
- `batch`: Batch number for grouping migrations
- `executed_at`: Timestamp when the migration was run

## How Migrations Work

1. The installer creates the `migrations` tracking table first
2. It scans all `.sql` files in this directory
3. For each file, it checks if the migration has already been run
4. New migrations are executed in a transaction with tracking
5. Failed migrations cause a rollback and stop the process

## Adding New Migrations

To add a new migration:

1. Create a new SQL file with datetime prefix: `2025_09_08_130000_add_new_feature.sql`
   - Use current date/time in `YYYY_MM_DD_HHMMSS` format
   - Add descriptive name after the timestamp
2. Include a descriptive comment at the top
3. Write your SQL statements (CREATE TABLE, ALTER TABLE, etc.)
4. Test the migration in a development environment first

### Generate Timestamp
You can generate the timestamp prefix using:
```bash
# Linux/Mac
date '+%Y_%m_%d_%H%M%S'

# Or use this helper:
echo "$(date '+%Y_%m_%d_%H%M%S')_your_migration_name.sql"
```

Example migration file:
```sql
-- Migration: Add user sessions table
-- Created: 2025-09-08 13:00:00
-- Description: Adds table to track user login sessions

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Manual Migration Execution

To run migrations manually:
```bash
# Run all pending migrations
sudo /opt/zoplog/zoplog/install.sh run_migrations_only

# Or run the migration function directly in the installer
```

## Migration Best Practices

1. **Always use transactions**: The migration system wraps each migration in a transaction
2. **Use IF NOT EXISTS**: Prevents errors if migration is run multiple times
3. **Foreign key constraints**: Add them after all tables are created
4. **Index creation**: Include appropriate indexes for performance
5. **Test thoroughly**: Always test migrations in development first
6. **Backup first**: Take database backups before running migrations in production

## Rollback Strategy

Currently, migrations don't include automatic rollback. To rollback:
1. Manually create reverse SQL statements
2. Remove the migration record from the `migrations` table
3. Execute the reverse statements

Future versions may include formal rollback support.
