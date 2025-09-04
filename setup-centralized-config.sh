#!/bin/bash
# setup-centralized-config.sh
# Script to deploy centralized ZopLog configuration

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Setting up centralized ZopLog configuration...${NC}"

# 1. Create /etc/zoplog directory
echo "Creating /etc/zoplog directory..."
sudo mkdir -p /etc/zoplog

# 2. Check if database config already exists
if [ -f "/etc/zoplog/database.conf" ]; then
    echo -e "${YELLOW}Database config already exists at /etc/zoplog/database.conf${NC}"
    echo "Current config:"
    sudo cat /etc/zoplog/database.conf
    echo ""
    read -p "Do you want to overwrite it? (y/N): " -r
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Keeping existing config..."
        SKIP_DB_CONFIG=1
    fi
fi

# 3. Create database configuration if needed
if [ "${SKIP_DB_CONFIG:-0}" != "1" ]; then
    echo "Creating database configuration..."
    
    # Try to read existing credentials from legacy locations
    DB_USER="zoplog_db"
    DB_PASS=""
    DB_NAME="logs_db"
    DB_HOST="localhost"
    DB_PORT="3306"
    
    if [ -f "/opt/zoplog/.db_credentials" ]; then
        echo "Found legacy credentials, migrating..."
        while IFS='=' read -r key value; do
            case "$key" in
                "DB_USER"|"USER") DB_USER="$value" ;;
                "DB_PASS"|"PASSWORD"|"PASS") DB_PASS="$value" ;;
                "DB_NAME"|"DATABASE"|"NAME") DB_NAME="$value" ;;
                "DB_HOST"|"HOST") DB_HOST="$value" ;;
                "DB_PORT"|"PORT") DB_PORT="$value" ;;
            esac
        done < /opt/zoplog/.db_credentials
    fi
    
    # Prompt for database password if not found
    if [ -z "$DB_PASS" ]; then
        echo -e "${YELLOW}Database password not found in legacy config.${NC}"
        read -s -p "Enter database password for user $DB_USER: " DB_PASS
        echo ""
    fi
    
    # Create the centralized config
    sudo tee /etc/zoplog/database.conf >/dev/null <<EOF
# ZopLog Database Configuration
# This file contains sensitive information - keep secure!

[database]
host = $DB_HOST
user = $DB_USER
password = $DB_PASS
name = $DB_NAME
port = $DB_PORT

[logging]
level = INFO
EOF

    echo -e "${GREEN}Database configuration created at /etc/zoplog/database.conf${NC}"
fi

# 4. Set proper permissions
echo "Setting secure permissions..."
sudo chown root:www-data /etc/zoplog/database.conf
sudo chmod 640 /etc/zoplog/database.conf

# Allow zoplog user to read the config
if getent passwd zoplog >/dev/null; then
    sudo usermod -a -G www-data zoplog
    echo "Added zoplog user to www-data group"
fi

# 5. Copy Python configuration module
echo "Installing Python configuration module..."
sudo cp python-logger/zoplog_config.py /opt/zoplog/zoplog/python-logger/
sudo chown zoplog:zoplog /opt/zoplog/zoplog/python-logger/zoplog_config.py

# 6. Copy PHP configuration module  
echo "Installing PHP configuration module..."
sudo cp web-interface/zoplog_config.php /var/www/zoplog/
sudo chown www-data:www-data /var/www/zoplog/zoplog_config.php

# 7. Test database connections
echo "Testing database connections..."

# Test Python connection
echo "Testing Python connection..."
cd /opt/zoplog/zoplog/python-logger
if sudo -u zoplog ./venv/bin/python3 -c "
from zoplog_config import load_database_config
import pymysql
try:
    config = load_database_config()
    conn = pymysql.connect(**config)
    print('Python database connection: SUCCESS')
    conn.close()
except Exception as e:
    print(f'Python database connection: FAILED - {e}')
"; then
    echo -e "${GREEN}Python database connection test passed${NC}"
else
    echo -e "${RED}Python database connection test failed${NC}"
fi

# Test PHP connection
echo "Testing PHP connection..."
if sudo -u www-data php -r "
require '/var/www/zoplog/zoplog_config.php';
if (\$mysqli->ping()) {
    echo 'PHP database connection: SUCCESS' . PHP_EOL;
} else {
    echo 'PHP database connection: FAILED - ' . \$mysqli->error . PHP_EOL;
}
"; then
    echo -e "${GREEN}PHP database connection test passed${NC}"
else
    echo -e "${RED}PHP database connection test failed${NC}"
fi

# 8. Restart services
echo "Restarting ZopLog services..."
sudo systemctl restart zoplog-logger zoplog-blockreader

echo -e "${GREEN}Centralized configuration setup complete!${NC}"
echo ""
echo "Configuration file locations:"
echo "  Database config: /etc/zoplog/database.conf"
echo "  Python module: /opt/zoplog/zoplog/python-logger/zoplog_config.py"
echo "  PHP module: /var/www/zoplog/zoplog_config.php"
echo ""
echo "To update database credentials in the future, edit:"
echo "  sudo nano /etc/zoplog/database.conf"
echo "Then restart services:"
echo "  sudo systemctl restart zoplog-logger zoplog-blockreader"
