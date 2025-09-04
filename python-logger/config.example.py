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
# Use local settings.json in development, centralized path in production
import os
if os.path.exists("/etc/zoplog/settings.json"):
    SETTINGS_FILE = "/etc/zoplog/settings.json"
else:
    # Development fallback - use project root settings.json
    SETTINGS_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), "settings.json")