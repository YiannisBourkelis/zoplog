#!/usr/bin/env python3
"""
Centralized configuration loader for ZopLog
Reads from /etc/zoplog/database.conf with fallbacks
"""
import os
import json
import configparser
from typing import Dict, Any

DEFAULT_CONFIG_PATHS = [
    "/etc/zoplog/database.conf",
]

def load_database_config() -> Dict[str, Any]:
    """Load database configuration from centralized location"""
    config = {
        "host": os.getenv("ZOPLOG_DB_HOST", "localhost"),
        "user": os.getenv("ZOPLOG_DB_USER", "zoplog_db"),
        "password": os.getenv("ZOPLOG_DB_PASS", ""),
        "database": os.getenv("ZOPLOG_DB_NAME", "logs_db"),
        "port": int(os.getenv("ZOPLOG_DB_PORT", "3306")),
    }
    
    # Try to read from config files
    for config_path in DEFAULT_CONFIG_PATHS:
        if os.path.exists(config_path) and os.path.isfile(config_path):
            try:
                if config_path.endswith('.conf'):
                    # INI-style config file
                    parser = configparser.ConfigParser()
                    parser.read(config_path)
                    if 'database' in parser:
                        db_section = parser['database']
                        config.update({
                            "host": db_section.get('host', config['host']),
                            "user": db_section.get('user', config['user']),
                            "password": db_section.get('password', config['password']),
                            "database": db_section.get('name', config['database']),
                            "port": int(db_section.get('port', config['port'])),
                        })
                        break
                else:
                    # Legacy key=value format
                    with open(config_path, 'r') as f:
                        for line in f:
                            line = line.strip()
                            if not line or line.startswith('#'):
                                continue
                            if '=' in line:
                                key, value = line.split('=', 1)
                                key = key.strip().upper()
                                value = value.strip()
                                if key in ('DB_HOST', 'HOST'):
                                    config['host'] = value
                                elif key in ('DB_USER', 'USER'):
                                    config['user'] = value
                                elif key in ('DB_PASS', 'PASSWORD', 'PASS'):
                                    config['password'] = value
                                elif key in ('DB_NAME', 'DATABASE', 'NAME'):
                                    config['database'] = value
                                elif key in ('DB_PORT', 'PORT'):
                                    config['port'] = int(value)
                    break
            except Exception as e:
                print(f"Warning: Could not read config from {config_path}: {e}")
                continue
    
    # Final fallback for password if still empty
    if not config['password']:
        config['password'] = 'uxaz0F0XMDAG7X1pVRF8yGCTo5sT7dnYNAVwZukags4='
    
    return config

def load_settings_config() -> Dict[str, Any]:
    """Load system settings from centralized config"""
    config_paths = [
        "/etc/zoplog/zoplog.conf",      # Centralized location
    ]
    
    # Default settings with eth0 as preferred interface for internet monitoring
    defaults = {
        "monitor_interface": "eth0",    # WAN interface for better internet traffic capture
        "firewall_interface": "eth0",   # Apply firewall to internet-facing interface
        "capture_mode": "promiscuous",
        "log_level": "INFO",
        "block_mode": "immediate",
        "log_blocked": True,
        "update_interval": 30,
        "max_log_entries": 10000
    }
    
    for config_path in config_paths:
        try:
            if os.path.exists(config_path):
                if config_path.endswith('.conf'):
                    # INI format - new centralized config
                    import configparser
                    parser = configparser.ConfigParser()
                    parser.read(config_path)
                    
                    config = defaults.copy()
                    if parser.has_section('monitoring'):
                        monitoring = parser['monitoring']
                        config['monitor_interface'] = monitoring.get('interface', config['monitor_interface'])
                        config['capture_mode'] = monitoring.get('capture_mode', config['capture_mode'])
                        config['log_level'] = monitoring.get('log_level', config['log_level'])
                    
                    if parser.has_section('firewall'):
                        firewall = parser['firewall']
                        config['firewall_interface'] = firewall.get('apply_to_interface', config['firewall_interface'])
                        config['block_mode'] = firewall.get('block_mode', config['block_mode'])
                        config['log_blocked'] = firewall.getboolean('log_blocked', config['log_blocked'])
                    
                    if parser.has_section('system'):
                        system = parser['system']
                        config['update_interval'] = system.getint('update_interval', config['update_interval'])
                        config['max_log_entries'] = system.getint('max_log_entries', config['max_log_entries'])
                    
                    return config
                else:
                    # JSON format - legacy
                    with open(config_path, 'r') as f:
                        settings = json.load(f)
                        return {**defaults, **settings}
        except Exception as e:
            print(f"Warning: Could not read settings from {config_path}: {e}")
            continue
    
    return defaults

# Backwards compatibility
DB_CONFIG = load_database_config()
DEFAULT_MONITOR_INTERFACE = load_settings_config().get("monitor_interface", "eth0")
