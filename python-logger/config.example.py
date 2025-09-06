# Rename this file to config.py and edit the values as needed.

DB_CONFIG = {
    'host': 'localhost',
    'user': 'your_user',
    'password': 'your_password',
    'database': 'your_database'
}

# Default monitoring interface (will be overridden by centralized config)
DEFAULT_MONITOR_INTERFACE = "eth0"

# Settings file path for system configuration
# Use centralized zoplog.conf configuration
SETTINGS_FILE = "/etc/zoplog/zoplog.conf"