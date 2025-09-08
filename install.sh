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

# --- New: optional early interactive interface selection ---
ask_user_interfaces() {
    # Skip if non-interactive
    if ! [ -t 0 ]; then
        return 0
    fi

    echo
    echo "Detected network interfaces (all types):"
    mapfile -t ALL_IFACES < <(ip -o link show | awk -F': ' '{print $2}' | cut -d'@' -f1 | grep -v '^lo$' | sort -u)
    if ((${#ALL_IFACES[@]}==0)); then
        log_warning "No non-loopback interfaces detected at this early stage. Falling back to automatic detection later."
        return 0
    fi

    local idx=0
    for ifc in "${ALL_IFACES[@]}"; do
        echo "  [$idx] $ifc"
        ((idx++))
    done

    if ((${#ALL_IFACES[@]}==1)); then
        log_warning "Only one interface (${ALL_IFACES[0]}) available; bridge mode will NOT be created."
        OVERRIDE_INTERNET_IF="${ALL_IFACES[0]}"
        OVERRIDE_INTERNAL_IF=""  # single interface
        return 0
    fi

    read -rp "Select internet-facing (WAN) interface index: " WAN_IDX
    if ! [[ $WAN_IDX =~ ^[0-9]+$ ]] || (( WAN_IDX < 0 || WAN_IDX >= ${#ALL_IFACES[@]} )); then
        log_warning "Invalid selection; using automatic detection instead."
        return 0
    fi
    read -rp "Select local-network (LAN) interface index (different from WAN): " LAN_IDX
    if ! [[ $LAN_IDX =~ ^[0-9]+$ ]] || (( LAN_IDX < 0 || LAN_IDX >= ${#ALL_IFACES[@]} )) || [[ $LAN_IDX == $WAN_IDX ]]; then
        log_warning "Invalid or same selection; will treat as single-interface (no bridge)."
        OVERRIDE_INTERNET_IF="${ALL_IFACES[$WAN_IDX]}"
        OVERRIDE_INTERNAL_IF=""
        return 0
    fi

    OVERRIDE_INTERNET_IF="${ALL_IFACES[$WAN_IDX]}"
    OVERRIDE_INTERNAL_IF="${ALL_IFACES[$LAN_IDX]}"
    log_info "User selected WAN=${OVERRIDE_INTERNET_IF} LAN=${OVERRIDE_INTERNAL_IF}"
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

    # If user pre-selected via ask_user_interfaces() keep those
    if [ -n "${OVERRIDE_INTERNET_IF:-}" ]; then
        INTERNET_IF="$OVERRIDE_INTERNET_IF"
        if [ -n "${OVERRIDE_INTERNAL_IF:-}" ]; then
            INTERNAL_IF="$OVERRIDE_INTERNAL_IF"
            BRIDGE_MODE="dual"
            log_info "Using user-selected interfaces (dual): INTERNET_IF=$INTERNET_IF INTERNAL_IF=$INTERNAL_IF"
        else
            INTERNAL_IF=""
            BRIDGE_MODE="single"
            log_info "Using user-selected interface (single): INTERNET_IF=$INTERNET_IF"
        fi
        # Confirmation pause only in interactive terminal
        if [ -t 0 ]; then
            read -p "Press Enter to continue or Ctrl+C to abort" _
        fi
        return 0
    fi
    
    # Get all ethernet interfaces (excluding loopback) - include USB adapters universally
    INTERFACES=($(ip link show | grep -E "^[0-9]+: (eth|enp|ens|usb)" | cut -d: -f2 | sed 's/ //g'))
    
    if [ ${#INTERFACES[@]} -lt 1 ]; then
        log_error "At least 1 ethernet interface is required for ZopLog"
        log_error "Found interfaces: ${INTERFACES[*]}"
        exit 1
    fi
    
    log_info "Found ${#INTERFACES[@]} network interfaces: ${INTERFACES[*]}"
    
    # Try to detect which interface has internet connectivity for monitoring
    INTERNET_IF=""
    INTERNAL_IF=""
    
    for iface in "${INTERFACES[@]}"; do
        if ip route | grep -q "default.*$iface"; then
            INTERNET_IF="$iface"
            break
        fi
    done
    
    # If no default route found, use first interface
    if [ -z "$INTERNET_IF" ]; then
        log_warning "Could not automatically detect internet interface, using first available"
        INTERNET_IF="${INTERFACES[0]}"
    fi
    
    # Set internal interface only if we have more than one interface
    if [ ${#INTERFACES[@]} -gt 1 ]; then
        for iface in "${INTERFACES[@]}"; do
            if [ "$iface" != "$INTERNET_IF" ]; then
                INTERNAL_IF="$iface"
                break
            fi
        done
        BRIDGE_MODE="dual"
        log_info "Dual interface mode - will create bridge"
    else
        BRIDGE_MODE="single"
        log_info "Single interface mode - no bridge needed"
    fi
    
    log_info "Internet interface (for monitoring): $INTERNET_IF"
    if [ -n "$INTERNAL_IF" ]; then
        log_info "Internal interface: $INTERNAL_IF"
    fi
    
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
        apt-transport-https \
        ca-certificates \
        gnupg \
        nftables \
        bridge-utils \
        iptables \
        netfilter-persistent \
        iptables-persistent
    
    # Install PHP - try newer version first, fallback to system default
    log_info "Installing PHP..."
    
    # Try to install PHP 8.4 from Ondrej's repository for better compatibility
    if curl -sSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/php-archive-keyring.gpg 2>/dev/null; then
        echo "deb [signed-by=/usr/share/keyrings/php-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        
        if apt-get update -qq && apt-get install -y \
            php8.4 \
            php8.4-fpm \
            php8.4-mysql \
            php8.4-cli \
            php8.4-common \
            php8.4-curl \
            php8.4-mbstring \
            php8.4-xml \
            php8.4-zip 2>/dev/null; then
            PHP_VERSION="8.4"
            log_info "Installed PHP 8.4 from Ondrej's repository"
        else
            log_warning "PHP 8.4 installation failed, falling back to system packages"
            apt-get install -y \
                php \
                php-fpm \
                php-mysql \
                php-cli \
                php-common \
                php-curl \
                php-mbstring \
                php-xml \
                php-zip
            PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
        fi
    else
        log_info "Using system PHP packages"
        apt-get install -y \
            php \
            php-fpm \
            php-mysql \
            php-cli \
            php-common \
            php-curl \
            php-mbstring \
            php-xml \
            php-zip
        PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
    fi
    
    log_info "PHP version: $PHP_VERSION"
    
    log_success "Dependencies installed successfully"
}

create_zoplog_user() {
    log_info "Creating ZopLog system user..."
    
    if id "$ZOPLOG_USER" &>/dev/null; then
        log_warning "User $ZOPLOG_USER already exists"
    else
        useradd --system --home-dir "$ZOPLOG_HOME" --create-home --shell /bin/bash "$ZOPLOG_USER"
        usermod -aG sudo "$ZOPLOG_USER"
        usermod -aG systemd-journal "$ZOPLOG_USER"
        log_success "Created user: $ZOPLOG_USER"
    fi
}

setup_database() {
    log_info "Setting up MariaDB database..."
    
    # Fast path: if centralized config exists and connects, skip DB setup
    if [ -f "/etc/zoplog/database.conf" ]; then
        log_info "Found existing centralized database configuration, testing connection..."
        # Extract credentials from INI file, removing quotes and whitespace
        DB_HOST=$(grep "^host" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
        DB_USER=$(grep "^user" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
        DB_PASS=$(grep "^password" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
        DB_NAME=$(grep "^name" /etc/zoplog/database.conf | cut -d'=' -f2 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//;s/^"//;s/"$//')
        
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
password = "$DB_PASS"
name = $DB_NAME
port = 3306

[logging]
level = INFO
EOF

    # Set secure permissions
    chown root:www-data /etc/zoplog/database.conf
    chmod 640 /etc/zoplog/database.conf
    
    # Create centralized system settings configuration with proper monitoring interface
    if [ "$BRIDGE_MODE" = "dual" ]; then
        MONITOR_INTERFACE="br-zoplog"  # Use bridge when available
    else
        MONITOR_INTERFACE="$INTERNET_IF"  # Monitor internet interface directly in single mode
    fi
    
    cat > /etc/zoplog/zoplog.conf <<EOF
# ZopLog System Configuration
# This file contains system settings for monitoring and firewall

[monitoring]
interface = $MONITOR_INTERFACE
capture_mode = promiscuous
log_level = INFO

[firewall]
apply_to_interface = $MONITOR_INTERFACE
block_mode = immediate
log_blocked = true

[system]
bridge_mode = $BRIDGE_MODE
internet_interface = $INTERNET_IF
internal_interface = $INTERNAL_IF
update_interval = 30
max_log_entries = 10000
last_updated = $(date '+%Y-%m-%d %H:%M:%S')
EOF

    # Set secure permissions for system config
    chown root:www-data /etc/zoplog/zoplog.conf
    chmod 660 /etc/zoplog/zoplog.conf
    
    # Allow zoplog user to read the config by adding to www-data group
    usermod -a -G www-data "$ZOPLOG_USER" 2>/dev/null || true
    
    # Save database credentials for backup/reference
    cat > "$ZOPLOG_HOME/.db_credentials" <<EOF
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF
    chown "$ZOPLOG_USER:$ZOPLOG_USER" "$ZOPLOG_HOME/.db_credentials"
    chmod 600 "$ZOPLOG_HOME/.db_credentials"
    
    # Ensure proper ownership of downloaded files (only if directory exists)
    if [ -d "$ZOPLOG_HOME/zoplog" ]; then
        chown -R "$ZOPLOG_USER:$ZOPLOG_USER" "$ZOPLOG_HOME/zoplog"
    fi
    
    log_success "Centralized configuration setup complete"
}

setup_python_environment() {
    log_info "Setting up Python virtual environment..."
    
    cd "$ZOPLOG_HOME/zoplog/python-logger"
    
    # Ensure Python development headers are available for package compilation
    apt-get install -y python3-dev build-essential || log_warning "Could not install development packages"
    
    # Create virtual environment
    sudo -u "$ZOPLOG_USER" python3 -m venv venv
    
    # Upgrade pip first
    sudo -u "$ZOPLOG_USER" ./venv/bin/pip install --upgrade pip
    
    # Install Python dependencies with fallbacks
    log_info "Installing Python dependencies..."
    if sudo -u "$ZOPLOG_USER" ./venv/bin/pip install \
        scapy \
        PyMySQL \
        mysql-connector-python; then
        log_success "Python dependencies installed via pip"
    else
        log_warning "Pip installation failed, trying with system packages..."
        # Fallback - use system packages if pip fails
        apt-get install -y python3-scapy python3-pymysql || log_warning "System packages installation failed"
        # Create symlinks in venv for system packages
        sudo -u "$ZOPLOG_USER" bash -c 'for pkg in /usr/lib/python3/dist-packages/{scapy*,pymysql*}; do [ -e "$pkg" ] && ln -sf "$pkg" ./venv/lib/python*/site-packages/ 2>/dev/null || true; done'
    fi
    
    # Install systemd-python separately (may not be available on all systems)
    sudo -u "$ZOPLOG_USER" ./venv/bin/pip install systemd-python || {
        log_warning "systemd-python not available via pip, trying system package..."
        apt-get install -y python3-systemd || log_warning "systemd-python not available"
    }
    
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
    
    # Set proper permissions
    chown -R www-data:www-data "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"
    
    log_success "Web interface setup completed"
}

setup_sudoers() {
    log_info "Setting up sudoers permissions for web interface and services..."
    
    # Remove any existing zoplog sudoers files to avoid conflicts
    rm -f /etc/sudoers.d/zoplog /etc/sudoers.d/zoplog-web
    
    # Create consolidated sudoers entry for ZopLog
    cat > /etc/sudoers.d/zoplog <<EOF
# ZopLog sudoers configuration

# ZopLog firewall management for logger service
zoplog ALL=(root) NOPASSWD: $ZOPLOG_HOME/zoplog/scripts/zoplog-firewall-ipset-add

# ZopLog firewall management for web interface
www-data ALL=(root) NOPASSWD: $ZOPLOG_HOME/zoplog/scripts/zoplog-firewall-*
www-data ALL=(root) NOPASSWD: $ZOPLOG_HOME/zoplog/scripts/zoplog-nft-*

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

# Allow www-data to fix configuration permissions
www-data ALL=(ALL) NOPASSWD: /bin/chmod 660 /etc/zoplog/zoplog.conf
EOF
    
    # Set proper permissions for sudoers file
    chmod 440 /etc/sudoers.d/zoplog
    
    # Validate sudoers syntax
    if ! visudo -c > /dev/null 2>&1; then
        log_error "Sudoers configuration is invalid. Removing file."
        rm -f /etc/sudoers.d/zoplog
        return 1
    fi
    
    log_success "Sudoers permissions configured successfully"
}

setup_nginx() {
    log_info "Configuring Nginx..."
    
    # Detect PHP-FPM socket path
    if [ -n "${PHP_VERSION:-}" ]; then
        PHP_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
    else
        # Fallback detection
        PHP_SOCKET=$(find /run/php/ -name "php*-fpm.sock" | head -n1)
        if [ -z "$PHP_SOCKET" ]; then
            PHP_SOCKET="/run/php/php-fpm.sock"  # Generic fallback
        fi
    fi
    
    log_info "Using PHP-FPM socket: $PHP_SOCKET"
    
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
        fastcgi_pass unix:$PHP_SOCKET;
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
    log_info "Setting up ZopLog scripts..."
    
    # Ensure scripts directory exists and has proper permissions
    SCRIPTS_DIR="$ZOPLOG_HOME/zoplog/scripts"
    if [ ! -d "$SCRIPTS_DIR" ]; then
        log_error "Scripts directory not found: $SCRIPTS_DIR"
        return 1
    fi
    
    # Make scripts executable
    chmod +x "$SCRIPTS_DIR/zoplog-"*
    
    # Set setuid bit on firewall scripts for proper privilege escalation
    chmod u+s "$SCRIPTS_DIR/zoplog-firewall-"*
    
    log_success "Scripts configured successfully"
    cat > /etc/systemd/system/zoplog-nftables.service <<EOF
[Unit]
Description=ZopLog NFTables Rules Persistence
After=network.target nftables.service
Wants=nftables.service

[Service]
Type=oneshot
ExecStart=$ZOPLOG_HOME/zoplog/scripts/zoplog-nft-restore
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
ExecStart=$ZOPLOG_HOME/zoplog/scripts/zoplog-restore-blocklists
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
    
    # Only create bridge if we have multiple interfaces
    if [ "$BRIDGE_MODE" = "dual" ]; then
        log_info "Configuring bridge for dual-interface mode"
        
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
        
        # Configure both interfaces to use bridge
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
        
        # Configure br_netfilter module for bridge firewall support
        log_info "Configuring br_netfilter module for bridge firewall support..."
        
        # Add br_netfilter to /etc/modules for automatic loading on boot
        if ! grep -q "^br_netfilter$" /etc/modules; then
            echo "br_netfilter" >> /etc/modules
            log_info "Added br_netfilter to /etc/modules"
        else
            log_info "br_netfilter already in /etc/modules"
        fi
        
        # Add bridge netfilter sysctl settings to /etc/sysctl.conf
        if ! grep -q "^net.bridge.bridge-nf-call-iptables=" /etc/sysctl.conf; then
            echo "net.bridge.bridge-nf-call-iptables=1" >> /etc/sysctl.conf
            echo "net.bridge.bridge-nf-call-ip6tables=1" >> /etc/sysctl.conf
            log_info "Added bridge netfilter sysctl settings to /etc/sysctl.conf"
        else
            log_info "Bridge netfilter sysctl settings already configured"
        fi
        
        # Load br_netfilter module immediately
        if ! lsmod | grep -q br_netfilter; then
            modprobe br_netfilter
            log_info "Loaded br_netfilter kernel module"
        else
            log_info "br_netfilter kernel module already loaded"
        fi
        
        # Apply sysctl settings
        sysctl -p
        
        log_success "Bridge configuration completed for dual-interface mode"
    else
        log_info "Single interface mode - no bridge configuration needed"
        log_info "ZopLog will monitor traffic on $INTERNET_IF directly"
    fi
    
    log_success "Transparent proxy configuration completed"
}

setup_systemd_services() {
    log_info "Creating systemd services..."
    
    # ZopLog packet logger service with capabilities for packet capture
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

# Security settings with necessary capabilities for packet capture
NoNewPrivileges=no
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
ReadWritePaths=$ZOPLOG_HOME
AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN
CapabilityBoundingSet=CAP_NET_RAW CAP_NET_ADMIN

[Install]
WantedBy=multi-user.target
EOF
    
    # Create systemd override for logger service (alternative approach)
    mkdir -p /etc/systemd/system/zoplog-logger.service.d
    cat > /etc/systemd/system/zoplog-logger.service.d/override.conf <<EOF
[Service]
# Additional capability configuration for Raspberry Pi compatibility
AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN
CapabilityBoundingSet=CAP_NET_RAW CAP_NET_ADMIN
NoNewPrivileges=no
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

run_migrations() {
    log_info "Running database migrations..."
    
    local migrations_dir="$ZOPLOG_HOME/zoplog/migrations"
    
    if [ ! -d "$migrations_dir" ]; then
        log_error "Migrations directory not found: $migrations_dir"
        return 1
    fi
    
    # First, create the migrations tracking table (find it by pattern)
    local migrations_table_file
    migrations_table_file=$(find "$migrations_dir" -name "*_create_migrations_table.sql" | head -n1)
    if [ -f "$migrations_table_file" ]; then
        log_info "Creating migrations tracking table..."
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migrations_table_file"
    fi
    
    # Get the current batch number (increment from last batch)
    local current_batch
    current_batch=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations;" 2>/dev/null || echo "1")
    
    # Run all migration files in chronological order (datetime prefix ensures this)
    local migrations_run=0
    for migration_file in "$migrations_dir"/*.sql; do
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
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<EOF
START TRANSACTION;
$(cat "$migration_file")
INSERT INTO migrations (migration, batch) VALUES ('$migration_name', $current_batch);
COMMIT;
EOF
            
            if [ $? -eq 0 ]; then
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

create_database_schema() {
    log_info "Creating database schema using migrations..."
    
    # Run the migration system
    run_migrations
    
    log_success "Database schema created with migration system"
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
    # New interactive selection before automatic detection
    ask_user_interfaces
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
