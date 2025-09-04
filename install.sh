#!/bin/bash
# ZopLog Installation Script
# Copyright 2025 Yiannis - Licensed under Apache License 2.0
# 
# This script installs ZopLog on a fresh Debian-based system
# Requirements: Two ethernet interfaces (one for internet, one for internal network)

set -euo pipefail

ZOPLOG_USER="zoplog"
ZOPLOG_HOME="/opt/zoplog"
ZOPLOG_REPO="https://github.com/YiannisBourkelis/zoplog.git"
WEB_ROOT="/var/www/zoplog"
DB_NAME="logs_db"
DB_USER="root"
DB_PASS=""

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

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root. Use: curl -sSL https://raw.githubusercontent.com/YiannisBourkelis/zoplog/main/install.sh | sudo bash"
        exit 1
    fi
}

check_debian() {
    if ! command -v apt-get &> /dev/null; then
        log_error "This script requires a Debian-based system (Ubuntu, Debian, etc.)"
        exit 1
    fi
    
    . /etc/os-release
    log_info "Detected system: $PRETTY_NAME"
}

detect_interfaces() {
    log_info "Detecting network interfaces..."
    
    # Get all ethernet interfaces (excluding loopback)
    INTERFACES=($(ip link show | grep -E "^[0-9]+: (eth|enp|ens)" | cut -d: -f2 | sed 's/ //g'))
    
    if [ ${#INTERFACES[@]} -lt 2 ]; then
        log_error "At least 2 ethernet interfaces are required for ZopLog to work as a transparent proxy"
        log_error "Found interfaces: ${INTERFACES[*]}"
        exit 1
    fi
    
    log_info "Found ${#INTERFACES[@]} ethernet interfaces: ${INTERFACES[*]}"
    
    # Try to detect which interface has internet connectivity
    INTERNET_IF=""
    INTERNAL_IF=""
    
    for iface in "${INTERFACES[@]}"; do
        if ip route | grep -q "default.*$iface"; then
            INTERNET_IF="$iface"
            break
        fi
    done
    
    # Set the other interface as internal
    for iface in "${INTERFACES[@]}"; do
        if [ "$iface" != "$INTERNET_IF" ]; then
            INTERNAL_IF="$iface"
            break
        fi
    done
    
    if [ -z "$INTERNET_IF" ]; then
        log_warning "Could not automatically detect internet interface"
        INTERNET_IF="${INTERFACES[0]}"
        INTERNAL_IF="${INTERFACES[1]}"
    fi
    
    log_info "Internet interface: $INTERNET_IF"
    log_info "Internal interface: $INTERNAL_IF"
    
    # Auto-continue after 5 seconds if running non-interactively
    if [ -t 0 ]; then
        # Interactive terminal - ask for confirmation
        read -p "Press Enter to continue or Ctrl+C to abort and configure manually"
    else
        # Non-interactive (piped from curl) - auto-continue
        log_info "Auto-continuing in 3 seconds... (Ctrl+C to abort)"
        sleep 3
    fi
}

install_dependencies() {
    log_info "Updating package lists..."
    apt-get update -qq
    
    log_info "Installing system dependencies..."
    
    # Set environment variables to avoid interactive prompts
    export DEBIAN_FRONTEND=noninteractive
    
    # Pre-configure iptables-persistent to avoid dialog
    echo iptables-persistent iptables-persistent/autosave_v4 boolean true | debconf-set-selections
    echo iptables-persistent iptables-persistent/autosave_v6 boolean true | debconf-set-selections
    
    apt-get install -y \
        curl \
        wget \
        git \
        python3 \
        python3-pip \
        python3-venv \
        python3-systemd \
        python3-scapy \
        nginx \
        mariadb-server \
        mariadb-client \
        software-properties-common \
        apt-transport-https \
        ca-certificates \
        gnupg2 \
        nftables \
        bridge-utils \
        iptables \
        netfilter-persistent \
        iptables-persistent
    
    # Install PHP 8.4 from Ondrej's PPA
    log_info "Installing PHP 8.4..."
    curl -sSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/php-archive-keyring.gpg
    echo "deb [signed-by=/usr/share/keyrings/php-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
    
    apt-get update -qq
    apt-get install -y \
        php8.4 \
        php8.4-fpm \
        php8.4-mysql \
        php8.4-cli \
        php8.4-common \
        php8.4-curl \
        php8.4-mbstring \
        php8.4-xml \
        php8.4-zip
    
    log_success "Dependencies installed successfully"
}

create_zoplog_user() {
    log_info "Creating ZopLog system user..."
    
    if id "$ZOPLOG_USER" &>/dev/null; then
        log_warning "User $ZOPLOG_USER already exists"
    else
        useradd --system --home-dir "$ZOPLOG_HOME" --create-home --shell /bin/bash "$ZOPLOG_USER"
        usermod -aG sudo "$ZOPLOG_USER"
        log_success "Created user: $ZOPLOG_USER"
    fi
}

setup_database() {
    log_info "Setting up MariaDB database..."
    
    # Fast path: if centralized config exists and connects, skip DB setup
    if [ -f "/etc/zoplog/database.conf" ]; then
        log_info "Found existing centralized database configuration, testing connection..."
        # Extract credentials from INI file
        DB_HOST=$(grep "^host" /etc/zoplog/database.conf | cut -d'=' -f2 | xargs)
        DB_USER=$(grep "^user" /etc/zoplog/database.conf | cut -d'=' -f2 | xargs)
        DB_PASS=$(grep "^password" /etc/zoplog/database.conf | cut -d'=' -f2 | xargs)
        DB_NAME=$(grep "^name" /etc/zoplog/database.conf | cut -d'=' -f2 | xargs)
        
        if mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;" >/dev/null 2>&1; then
            log_success "Database already configured; skipping database setup"
            return 0
        else
            log_warning "Existing centralized config failed; proceeding with database setup"
        fi
    fi
    
    # Generate random password if not set
    if [ -z "$DB_PASS" ]; then
        DB_PASS=$(openssl rand -base64 32)
        log_info "Generated database password for root user"
    fi
    
    # Start MariaDB service
    systemctl start mariadb
    systemctl enable mariadb
    
    # Wait for MariaDB to be ready
    log_info "Waiting for MariaDB to start..."
    sleep 3
    
    # Check if we can connect without password (fresh installation)
    log_info "Checking MariaDB configuration..."
    if mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
        # No password set - configure security
        log_info "Configuring MariaDB security with new root password..."
        mysql -u root <<EOF
-- Set root password
ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_PASS';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF
        log_success "MariaDB security configured with new root password"
        ROOT_DB_PASS="$DB_PASS"
    else
        # Root password already exists - try to get it from existing credentials
        if [ -f "$ZOPLOG_HOME/.db_credentials" ]; then
            log_info "Found existing database credentials, using them..."
            source "$ZOPLOG_HOME/.db_credentials"
            ROOT_DB_PASS="$DB_PASS"
        else
            # For non-interactive installation, try common approaches
            log_warning "MariaDB root password is already set."
            
            # Try using sudo to access MariaDB (works on many systems)
            if sudo mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
                log_info "Using sudo authentication to access MariaDB..."
                log_info "Setting up database and configuring root user password..."
                
                # Set root password and create database using sudo mysql
                sudo mysql -u root <<EOF
-- Set root password if not already set
ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
EOF
                log_success "Database setup completed using sudo authentication"
                ROOT_DB_PASS="$DB_PASS"
                
                # Create centralized configuration
                setup_centralized_config
                return 0
            fi
            
            # If sudo doesn't work, provide instructions
            log_error "Cannot access MariaDB. Please either:"
            log_error "1. Run: sudo mysql_secure_installation (and set a root password)"
            log_error "2. Or run: sudo mysql -u root"
            log_error "   Then manually create the database and user:"
            log_error "   CREATE DATABASE $DB_NAME;"
            log_error "   Then set root password and use the centralized configuration"
            exit 1
        fi
    fi

    # Create database using existing root password
    log_info "Creating ZopLog database..."
    mysql -u root -p"$ROOT_DB_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
EOF
    
    log_success "Database setup completed"
    
    # Use the existing root password for our configuration
    DB_PASS="$ROOT_DB_PASS"
    
    # Create centralized configuration
    setup_centralized_config
}

download_zoplog() {
    log_info "Downloading ZopLog from GitHub..."
    
    if [ -d "$ZOPLOG_HOME/zoplog" ]; then
        log_info "Updating existing installation..."
        cd "$ZOPLOG_HOME/zoplog"
        sudo -u "$ZOPLOG_USER" git pull
    else
        cd "$ZOPLOG_HOME"
        sudo -u "$ZOPLOG_USER" git clone "$ZOPLOG_REPO" zoplog
    fi
    
    chown -R "$ZOPLOG_USER:$ZOPLOG_USER" "$ZOPLOG_HOME/zoplog"
    log_success "ZopLog downloaded successfully"
}

setup_centralized_config() {
    log_info "Setting up centralized configuration..."
    
    # Create /etc/zoplog directory
    mkdir -p /etc/zoplog
    
    # Create centralized database configuration
    cat > /etc/zoplog/database.conf <<EOF
# ZopLog Database Configuration
# This file contains sensitive information - keep secure

[database]
host = localhost
user = $DB_USER
password = $DB_PASS
name = $DB_NAME
port = 3306

[logging]
level = INFO
EOF

    # Set secure permissions
    chown root:www-data /etc/zoplog/database.conf
    chmod 640 /etc/zoplog/database.conf
    
    # Allow zoplog user to read the config by adding to www-data group
    usermod -a -G www-data "$ZOPLOG_USER" 2>/dev/null || true
    
    # Copy centralized config modules to installation directories
    log_info "Installing centralized configuration modules..."
    
    # Install Python config module
    if [ -f "$ZOPLOG_HOME/zoplog/python-logger/zoplog_config.py" ]; then
        cp "$ZOPLOG_HOME/zoplog/python-logger/zoplog_config.py" "$ZOPLOG_HOME/zoplog/python-logger/"
        chown "$ZOPLOG_USER:$ZOPLOG_USER" "$ZOPLOG_HOME/zoplog/python-logger/zoplog_config.py"
    fi
    
    # Install PHP config module  
    if [ -f "$ZOPLOG_HOME/zoplog/web-interface/zoplog_config.php" ]; then
        cp "$ZOPLOG_HOME/zoplog/web-interface/zoplog_config.php" "$WEB_ROOT/"
        chown www-data:www-data "$WEB_ROOT/zoplog_config.php"
    fi
    
    log_success "Centralized configuration setup complete"
}

setup_python_environment() {
    log_info "Setting up Python virtual environment..."
    
    cd "$ZOPLOG_HOME/zoplog/python-logger"
    
    # Create virtual environment
    sudo -u "$ZOPLOG_USER" python3 -m venv venv
    
    # Install Python dependencies
    sudo -u "$ZOPLOG_USER" ./venv/bin/pip install --upgrade pip
    sudo -u "$ZOPLOG_USER" ./venv/bin/pip install \
        scapy \
        PyMySQL \
        mysql-connector-python \
        systemd-python
    
    # Create updated config.py with system settings support
    cat > config.py <<EOF
# --- DB connection settings ---
DB_CONFIG = {
    "host": "localhost",
    "user": "$DB_USER",
    "password": "$DB_PASS",
    "database": "$DB_NAME"
}

# --- System settings ---
# Default monitoring interface (will be overridden by web settings)
DEFAULT_MONITOR_INTERFACE = "br-zoplog"

# Settings file path
SETTINGS_FILE = "/opt/zoplog/settings.json"
EOF
    
    chown "$ZOPLOG_USER:$ZOPLOG_USER" config.py
    
    log_success "Python environment setup completed"
}

setup_web_interface() {
    log_info "Setting up web interface..."
    
    # Create web directory
    mkdir -p "$WEB_ROOT"
    cp -r "$ZOPLOG_HOME/zoplog/web-interface/"* "$WEB_ROOT/"
    
    # Remove any old configuration files that might have been copied
    rm -f "$WEB_ROOT/db.php" "$WEB_ROOT/config.php" "$WEB_ROOT/config.php.old"
    
    # The centralized configuration is already handled by setup_centralized_config()
    
    # Create initial system settings file
    cat > "$ZOPLOG_HOME/settings.json" <<EOF
{
    "monitor_interface": "br-zoplog",
    "last_updated": "$(date '+%Y-%m-%d %H:%M:%S')"
}
EOF
    
    # Set proper permissions
    chown -R www-data:www-data "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"
    
    # Set permissions for settings file
    chown "$ZOPLOG_USER:www-data" "$ZOPLOG_HOME/settings.json"
    chmod 664 "$ZOPLOG_HOME/settings.json"
    
    log_success "Web interface setup completed"
}

setup_sudoers() {
    log_info "Setting up sudoers permissions for web interface..."
    
    # Create sudoers entry for ZopLog web interface
    cat > /etc/sudoers.d/zoplog-web <<'EOF'
# Allow www-data to manage ZopLog services without password
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart zoplog-blockreader  
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start zoplog-blockreader
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop zoplog-blockreader
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status zoplog-blockreader
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-active zoplog-blockreader
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-enabled zoplog-logger
www-data ALL=(ALL) NOPASSWD: /bin/systemctl is-enabled zoplog-blockreader
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u zoplog-logger*
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl -u zoplog-blockreader*
EOF
    
    # Set proper permissions for sudoers file
    chmod 440 /etc/sudoers.d/zoplog-web
    
    # Validate sudoers syntax
    if ! visudo -c > /dev/null 2>&1; then
        log_error "Sudoers configuration is invalid. Removing file."
        rm -f /etc/sudoers.d/zoplog-web
        return 1
    fi
    
    log_success "Sudoers permissions configured for web service management"
}

setup_nginx() {
    log_info "Configuring Nginx..."
    
    cat > /etc/nginx/sites-available/zoplog <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    
    root $WEB_ROOT;
    index index.php index.html index.htm;
    
    server_name _;
    
    location / {
        try_files \$uri \$uri/ =404;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    
    location ~ /\.ht {
        deny all;
    }
    
    # API endpoints
    location /api/ {
        try_files \$uri \$uri/ =404;
    }
}
EOF
    
    # Enable site
    rm -f /etc/nginx/sites-enabled/default
    ln -sf /etc/nginx/sites-available/zoplog /etc/nginx/sites-enabled/
    
    # Test and reload nginx
    nginx -t
    systemctl reload nginx
    systemctl enable nginx
    
    log_success "Nginx configured successfully"
}

setup_scripts() {
    log_info "Installing ZopLog scripts..."
    
    mkdir -p /usr/local/sbin
    
    # Copy scripts
    cp "$ZOPLOG_HOME/zoplog/scripts/"* /usr/local/sbin/
    chmod +x /usr/local/sbin/zoplog-*
    
    # Setup sudoers for www-data to execute firewall scripts
    cat > /etc/sudoers.d/zoplog <<EOF
# ZopLog firewall management
www-data ALL=(root) NOPASSWD: /usr/local/sbin/zoplog-firewall-*
www-data ALL=(root) NOPASSWD: /usr/local/sbin/zoplog-nft-*
$ZOPLOG_USER ALL=(root) NOPASSWD: /usr/local/sbin/zoplog-*
EOF

    # Create NFTables persistence systemd service
    cat > /etc/systemd/system/zoplog-nftables.service <<EOF
[Unit]
Description=ZopLog NFTables Rules Persistence
After=network.target nftables.service
Wants=nftables.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/zoplog-nft-restore
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    # Create blocklist restoration service
    cat > /etc/systemd/system/zoplog-restore.service <<EOF
[Unit]
Description=ZopLog Active Blocklists Restoration
After=network.target mariadb.service zoplog-nftables.service
Wants=mariadb.service
Requires=zoplog-nftables.service

[Service]
Type=oneshot
User=root
ExecStart=/usr/local/sbin/zoplog-restore-blocklists
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    # Enable the persistence services
    systemctl daemon-reload
    systemctl enable zoplog-nftables.service
    systemctl enable zoplog-restore.service
    
    log_success "Scripts installed and NFTables persistence configured"
}

setup_transparent_proxy() {
    log_info "Setting up transparent proxy configuration..."
    
    # Enable IP forwarding
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
    echo 'net.ipv6.conf.all.forwarding=1' >> /etc/sysctl.conf
    sysctl -p
    
    # Create bridge interface for transparent proxy
    cat > /etc/systemd/network/br-zoplog.netdev <<EOF
[NetDev]
Name=br-zoplog
Kind=bridge
EOF
    
    cat > /etc/systemd/network/br-zoplog.network <<EOF
[Match]
Name=br-zoplog

[Network]
DHCP=yes
IPForward=yes
EOF
    
    # Configure bridge interfaces
    cat > "/etc/systemd/network/10-$INTERNET_IF.network" <<EOF
[Match]
Name=$INTERNET_IF

[Network]
Bridge=br-zoplog
EOF
    
    cat > "/etc/systemd/network/10-$INTERNAL_IF.network" <<EOF
[Match]
Name=$INTERNAL_IF

[Network]
Bridge=br-zoplog
EOF
    
    # Enable systemd-networkd
    systemctl enable systemd-networkd
    
    log_success "Transparent proxy configuration completed"
}

setup_systemd_services() {
    log_info "Creating systemd services..."
    
    # ZopLog packet logger service
    cat > /etc/systemd/system/zoplog-logger.service <<EOF
[Unit]
Description=ZopLog Network Packet Logger
After=network.target systemd-networkd.service
Wants=systemd-networkd.service
Requires=network.target

[Service]
Type=simple
User=$ZOPLOG_USER
Group=$ZOPLOG_USER
WorkingDirectory=$ZOPLOG_HOME/zoplog/python-logger
ExecStart=$ZOPLOG_HOME/zoplog/python-logger/venv/bin/python logger.py
Restart=always
RestartSec=10
TimeoutStartSec=60

# Security settings
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
ReadWritePaths=$ZOPLOG_HOME

[Install]
WantedBy=multi-user.target
EOF
    
    # ZopLog block log reader service
    cat > /etc/systemd/system/zoplog-blockreader.service <<EOF
[Unit]
Description=ZopLog NFTables Block Log Reader
After=network.target systemd-networkd.service systemd-journald.service
Wants=systemd-networkd.service systemd-journald.service
Requires=network.target

[Service]
Type=simple
User=$ZOPLOG_USER
Group=$ZOPLOG_USER
WorkingDirectory=$ZOPLOG_HOME/zoplog/python-logger
ExecStart=$ZOPLOG_HOME/zoplog/python-logger/venv/bin/python nft_blocklog_reader.py
Restart=always
RestartSec=10
TimeoutStartSec=60

# Security settings
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
ReadWritePaths=$ZOPLOG_HOME

[Install]
WantedBy=multi-user.target
EOF
    
    # Enable and start services
    systemctl daemon-reload
    systemctl enable zoplog-logger.service
    systemctl enable zoplog-blockreader.service
    
    # Start services non-blocking to avoid hangs during install; they are also enabled for boot
    log_info "Starting ZopLog services (non-blocking)..."
    systemctl start --no-block zoplog-logger.service || log_warning "Could not start zoplog-logger service (will auto-start on boot)"
    systemctl start --no-block zoplog-blockreader.service || log_warning "Could not start zoplog-blockreader service (will auto-start on boot)"
    
    log_success "Systemd services created and enabled for boot startup"
}

create_database_schema() {
    log_info "Creating database schema..."
    
    # Create database tables with proper schema matching the application
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Accept languages table
CREATE TABLE IF NOT EXISTS `accept_languages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `accept_language` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accept_language` (`accept_language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- IP addresses table
CREATE TABLE IF NOT EXISTS `ip_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Blocked events table
CREATE TABLE IF NOT EXISTS `blocked_events` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_time` datetime NOT NULL DEFAULT current_timestamp(),
  `direction` enum('IN','OUT') NOT NULL,
  `src_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dst_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `src_port` int(11) DEFAULT NULL,
  `dst_port` int(11) DEFAULT NULL,
  `proto` varchar(8) DEFAULT NULL,
  `iface_in` varchar(32) DEFAULT NULL,
  `iface_out` varchar(32) DEFAULT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_event_time` (`event_time`),
  KEY `idx_direction` (`direction`),
  KEY `idx_src_ip` (`src_ip_id`),
  KEY `idx_dst_ip` (`dst_ip_id`),
  CONSTRAINT `fk_blocked_events_src_ip` FOREIGN KEY (`src_ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_blocked_events_dst_ip` FOREIGN KEY (`dst_ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Blocklists table
CREATE TABLE IF NOT EXISTS `blocklists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` text NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other') NOT NULL,
  `active` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Blocklist domains table
CREATE TABLE IF NOT EXISTS `blocklist_domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blocklist_id` int(11) NOT NULL,
  `domain` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_blocklist_domain` (`blocklist_id`,`domain`),
  KEY `idx_blocklist_id` (`blocklist_id`),
  CONSTRAINT `fk_blocklist_domains_blocklists` FOREIGN KEY (`blocklist_id`) REFERENCES `blocklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Blocked IPs table
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocklist_domain_id` int(11) NOT NULL,
  `ip_id` bigint(20) UNSIGNED NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `hit_count` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_blocked_domain_ip` (`blocklist_domain_id`,`ip_id`),
  KEY `idx_blocked_ips_last_seen` (`last_seen`),
  KEY `idx_blocked_ips_domain` (`blocklist_domain_id`),
  KEY `idx_blocked_ips_ip` (`ip_id`),
  CONSTRAINT `fk_blocked_ips_domain` FOREIGN KEY (`blocklist_domain_id`) REFERENCES `blocklist_domains` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_blocked_ips_ip` FOREIGN KEY (`ip_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Hostnames table
CREATE TABLE IF NOT EXISTS `hostnames` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hostname` varchar(255) NOT NULL,
  `ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`),
  KEY `idx_hostname` (`hostname`),
  KEY `ip_id` (`ip_id`),
  CONSTRAINT `hostnames_ibfk_1` FOREIGN KEY (`ip_id`) REFERENCES `ip_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- MAC addresses table
CREATE TABLE IF NOT EXISTS `mac_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mac_address` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mac_address` (`mac_address`),
  KEY `idx_mac_address` (`mac_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Paths table
CREATE TABLE IF NOT EXISTS `paths` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `path` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_path` (`path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User agents table
CREATE TABLE IF NOT EXISTS `user_agents` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_agent` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_agent` (`user_agent`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Main packet logs table
CREATE TABLE IF NOT EXISTS `packet_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `packet_timestamp` datetime NOT NULL,
  `src_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `src_port` int(10) UNSIGNED NOT NULL,
  `dst_ip_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dst_port` int(10) UNSIGNED NOT NULL,
  `method` enum('GET','POST','PUT','DELETE','HEAD','OPTIONS','PATCH','N/A','TLS_CLIENTHELLO') DEFAULT 'N/A',
  `hostname_id` bigint(20) UNSIGNED DEFAULT NULL,
  `path_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `accept_language_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` enum('HTTP','HTTPS') NOT NULL,
  `src_mac_id` int(11) DEFAULT NULL,
  `dst_mac_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_packet_time` (`packet_timestamp`),
  KEY `idx_src_ip` (`src_ip_id`),
  KEY `idx_dst_ip` (`dst_ip_id`),
  KEY `idx_hostname` (`hostname_id`),
  KEY `idx_path` (`path_id`),
  KEY `idx_user_agent` (`user_agent_id`),
  KEY `idx_accept_language` (`accept_language_id`),
  KEY `idx_method_type` (`method`,`type`),
  KEY `src_mac_id` (`src_mac_id`),
  KEY `dst_mac_id` (`dst_mac_id`),
  KEY `idx_packet_time_type_method` (`packet_timestamp` DESC,`type`,`method`),
  CONSTRAINT `packet_logs_ibfk_1` FOREIGN KEY (`src_ip_id`) REFERENCES `ip_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_2` FOREIGN KEY (`dst_ip_id`) REFERENCES `ip_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_3` FOREIGN KEY (`hostname_id`) REFERENCES `hostnames` (`id`),
  CONSTRAINT `packet_logs_ibfk_4` FOREIGN KEY (`path_id`) REFERENCES `paths` (`id`),
  CONSTRAINT `packet_logs_ibfk_5` FOREIGN KEY (`user_agent_id`) REFERENCES `user_agents` (`id`),
  CONSTRAINT `packet_logs_ibfk_6` FOREIGN KEY (`accept_language_id`) REFERENCES `accept_languages` (`id`),
  CONSTRAINT `packet_logs_ibfk_7` FOREIGN KEY (`src_mac_id`) REFERENCES `mac_addresses` (`id`),
  CONSTRAINT `packet_logs_ibfk_8` FOREIGN KEY (`dst_mac_id`) REFERENCES `mac_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
EOF
    
    log_success "Database schema created with proper structure"
}

show_completion_message() {
    local_ip=$(ip route get 1 | sed -n 's/.*src \([0-9.]*\).*/\1/p')
    
    log_success "ZopLog installation completed successfully!"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}🎉 ZopLog is now installed and ready to use!${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    echo -e "${BLUE}📋 Installation Summary:${NC}"
    echo "  • System user created: $ZOPLOG_USER"
    echo "  • Installation directory: $ZOPLOG_HOME/zoplog"
    echo "  • Web interface: $WEB_ROOT"
    echo "  • Database: $DB_NAME (user: $DB_USER)"
    echo "  • Internet interface: $INTERNET_IF"
    echo "  • Internal interface: $INTERNAL_IF"
    echo
    echo -e "${BLUE}🌐 Web Access:${NC}"
    echo "  • Dashboard: http://$local_ip/"
    echo "  • Logs: http://$local_ip/logger.php"
    echo "  • Blocklists: http://$local_ip/blocklists.php"
    echo
    echo -e "${BLUE}🔧 Next Steps:${NC}"
    echo "  1. Reboot your system to fully activate network bridge:"
    echo -e "     ${YELLOW}sudo reboot${NC}"
    echo
    echo "  2. After reboot, ZopLog services will start automatically!"
    echo "     Services are already enabled for boot startup."
    echo
    echo "  3. Check service status after reboot:"
    echo -e "     ${YELLOW}sudo systemctl status zoplog-logger${NC}"
    echo -e "     ${YELLOW}sudo systemctl status zoplog-blockreader${NC}"
    echo
    echo "  4. If services need manual restart:"
    echo -e "     ${YELLOW}sudo systemctl restart zoplog-logger zoplog-blockreader${NC}"
    echo
    echo -e "${BLUE}📚 Documentation:${NC}"
    echo "  • GitHub: https://github.com/YiannisBourkelis/zoplog"
    echo "  • Configuration files: $ZOPLOG_HOME/zoplog/"
    echo
    echo -e "${YELLOW}⚠️  Important Security Notes:${NC}"
    echo "  • Database password saved in: $ZOPLOG_HOME/.db_credentials"
    echo "  • Change default passwords after installation"
    echo "  • Configure firewall rules according to your network setup"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
}

main() {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}🚀 ZopLog Installation Script${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${BLUE}Network Traffic Monitor & Blocker${NC}"
    echo -e "${BLUE}Copyright 2025 Yiannis - Apache License 2.0${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    
    check_root
    check_debian
    detect_interfaces
    
    log_info "Starting ZopLog installation..."
    
    install_dependencies
    create_zoplog_user
    setup_database
    download_zoplog
    setup_python_environment
    setup_web_interface
    setup_sudoers
    setup_nginx
    setup_scripts
    setup_transparent_proxy
    setup_systemd_services
    create_database_schema
    
    show_completion_message
}

# Run main function
main "$@"
