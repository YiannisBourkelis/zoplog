#!/bin/bash
# ZopLog Migration Generator
# Creates a new migration file with proper datetime prefix

set -euo pipefail

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ $# -eq 0 ]; then
    echo "Usage: $0 <migration_name>"
    echo ""
    echo "Examples:"
    echo "  $0 add_user_sessions_table"
    echo "  $0 modify_blocklist_categories"
    echo "  $0 add_indexes_to_packet_logs"
    echo ""
    echo "This will create a new migration file with datetime prefix in format:"
    echo "  YYYY_MM_DD_HHMMSS_<migration_name>.sql"
    exit 1
fi

migration_name="$1"

# Validate migration name (only allow letters, numbers, underscores)
if [[ ! "$migration_name" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo -e "${YELLOW}Warning:${NC} Migration name should only contain letters, numbers, and underscores"
    echo "Invalid characters will be replaced with underscores"
    migration_name=$(echo "$migration_name" | sed 's/[^a-zA-Z0-9_]/_/g')
fi

# Generate timestamp
timestamp=$(date '+%Y_%m_%d_%H%M%S')
filename="${timestamp}_${migration_name}.sql"
filepath="$SCRIPT_DIR/$filename"

# Check if file already exists (unlikely but possible)
if [ -f "$filepath" ]; then
    echo -e "${YELLOW}Warning:${NC} File already exists: $filename"
    echo "Waiting 1 second to generate unique timestamp..."
    sleep 1
    timestamp=$(date '+%Y_%m_%d_%H%M%S')
    filename="${timestamp}_${migration_name}.sql"
    filepath="$SCRIPT_DIR/$filename"
fi

# Create migration file with template
cat > "$filepath" <<EOF
-- Migration: $(echo "$migration_name" | sed 's/_/ /g' | sed 's/\b\w/\U&/g')
-- Created: $(date '+%Y-%m-%d %H:%M:%S')
-- Description: Add description of what this migration does

-- Example table creation:
-- CREATE TABLE IF NOT EXISTS \`example_table\` (
--   \`id\` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
--   \`name\` varchar(255) NOT NULL,
--   \`created_at\` timestamp NOT NULL DEFAULT current_timestamp(),
--   PRIMARY KEY (\`id\`),
--   UNIQUE KEY \`name\` (\`name\`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example index addition:
-- CREATE INDEX \`idx_example_column\` ON \`existing_table\` (\`column_name\`);

-- Example column addition:
-- ALTER TABLE \`existing_table\` ADD COLUMN \`new_column\` varchar(255) NULL AFTER \`existing_column\`;

-- Your migration SQL goes here:

EOF

echo -e "${GREEN}✓${NC} Created migration file: ${BLUE}$filename${NC}"
echo -e "${GREEN}✓${NC} Location: $filepath"
echo ""
echo "Next steps:"
echo "1. Edit the migration file and add your SQL statements"
echo "2. Test the migration in development first"
echo "3. Run migrations with: ./migrate.sh"
echo ""
echo "Opening file for editing..."

# Try to open in common editors
if command -v code >/dev/null 2>&1; then
    code "$filepath"
elif command -v nano >/dev/null 2>&1; then
    nano "$filepath"
elif command -v vim >/dev/null 2>&1; then
    vim "$filepath"
else
    echo "Please edit the file manually: $filepath"
fi
