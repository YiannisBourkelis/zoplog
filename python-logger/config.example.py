# --- DB connection settings ---
# Use centralized configuration loader
from zoplog_config import load_database_config

DB_CONFIG = load_database_config()

# --- System settings ---
# Use centralized configuration from /etc/zoplog/zoplog.conf
from zoplog_config import load_settings_config

# Load settings from centralized config
_settings = load_settings_config()
DEFAULT_MONITOR_INTERFACE = _settings.get("monitor_interface", "eth0")

# For backwards compatibility, some components may expect SETTINGS_FILE
# Point to the centralized zoplog.conf file
SETTINGS_FILE = "/etc/zoplog/zoplog.conf"

# --- Script paths ---
# Path to ZopLog scripts directory (relative to python-logger)
import os
SCRIPTS_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "scripts")