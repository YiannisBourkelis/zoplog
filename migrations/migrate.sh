#!/bin/bash
# ZopLog Database Migration Runner
# Standalone script to run migrations manually

set -euo pipefail

# Source configuration if available
if [ -f "/etc/zoplog/database.conf" ]; then
    DB_HOST=$(grep "^host" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
    DB_USER=$(grep "^user" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
    DB_PASS=$(grep "^password" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
    DB_NAME=$(grep "^name" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
else
    echo "Error: Database configuration not found at /etc/zoplog/database.conf"
    echo "Please run the full installer first or create the configuration file."
    exit 1
fi

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIGRATIONS_DIR="$SCRIPT_DIR"

run_migrations() {
    log_info "Running database migrations..."
    
    if [ ! -d "$MIGRATIONS_DIR" ]; then
        log_error "Migrations directory not found: $MIGRATIONS_DIR"
        return 1
    fi
    
    # Test database connection
    if ! mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;" >/dev/null 2>&1; then
        log_error "Cannot connect to database. Please check your configuration."
        return 1
    fi
    
    # First, create the migrations tracking table (find it by pattern)
    local migrations_table_file
    migrations_table_file=$(find "$MIGRATIONS_DIR" -name "*_create_migrations_table.sql" | head -n1)
    if [ -f "$migrations_table_file" ]; then
        log_info "Creating migrations tracking table..."
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migrations_table_file"
    fi
    
    # Get the current batch number (increment from last batch)
    local current_batch
    current_batch=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations;" 2>/dev/null || echo "1")
    
    # Run all migration files in chronological order (datetime prefix ensures this)
    local migrations_run=0
    for migration_file in "$MIGRATIONS_DIR"/*.sql; do
        if [ ! -f "$migration_file" ]; then
            continue
        fi
        
        local migration_name
        migration_name=$(basename "$migration_file" .sql)
        
        # Skip the migrations table creation file as it's already handled
        if [[ "$migration_name" == *"_create_migrations_table" ]]; then
            continue
        fi
        
        # Check if migration has already been run
        local already_run
        already_run=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COUNT(*) FROM migrations WHERE migration = '$migration_name';" 2>/dev/null || echo "0")
        
        if [ "$already_run" -eq "0" ]; then
            log_info "Running migration: $migration_name"
            
            # Start transaction for migration
            if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOF
START TRANSACTION;
$(cat "$migration_file")
INSERT INTO migrations (migration, batch) VALUES ('$migration_name', $current_batch);
COMMIT;
EOF
            then
                log_success "Migration completed: $migration_name"
                ((migrations_run++))
            else
                log_error "Migration failed: $migration_name"
                return 1
            fi
        else
            log_info "Migration already run: $migration_name (skipping)"
        fi
    done
    
    if [ $migrations_run -eq 0 ]; then
        log_info "No new migrations to run"
    else
        log_success "Ran $migrations_run new migration(s) in batch $current_batch"
    fi
}

show_migration_status() {
    log_info "Checking migration status..."
    
    # Test database connection
    if ! mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;" >/dev/null 2>&1; then
        log_error "Cannot connect to database. Please check your configuration."
        return 1
    fi
    
    # Check if migrations table exists
    local table_exists
    table_exists=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='migrations';" 2>/dev/null || echo "0")
    
    if [ "$table_exists" -eq "0" ]; then
        log_warning "Migrations table does not exist. Run migrations first."
        return 0
    fi
    
    echo -e "\n${BLUE}Executed Migrations:${NC}"
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT migration, batch, executed_at FROM migrations ORDER BY id;"
    
    echo -e "\n${BLUE}Available Migration Files:${NC}"
    for migration_file in "$MIGRATIONS_DIR"/*.sql; do
        if [ -f "$migration_file" ]; then
            local migration_name
            migration_name=$(basename "$migration_file" .sql)
            local status
            
            if [[ "$migration_name" == *"_create_migrations_table" ]]; then
                status="[SYSTEM]"
            else
                local already_run
                already_run=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COUNT(*) FROM migrations WHERE migration = '$migration_name';" 2>/dev/null || echo "0")
                if [ "$already_run" -eq "0" ]; then
                    status="[PENDING]"
                else
                    status="[EXECUTED]"
                fi
            fi
            
            echo "  $migration_name $status"
        fi
    done
}

# Main script logic
case "${1:-}" in
    "status")
        show_migration_status
        ;;
    "run"|"")
        run_migrations
        ;;
    *)
        echo "Usage: $0 [run|status]"
        echo ""
        echo "Commands:"
        echo "  run (default) - Run all pending migrations"
        echo "  status        - Show migration status"
        exit 1
        ;;
esac
