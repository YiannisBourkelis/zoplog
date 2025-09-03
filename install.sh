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
DB_USER="zoplog_db"
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
    
    # Generate random password if not set
    if [ -z "$DB_PASS" ]; then
        DB_PASS=$(openssl rand -base64 32)
        log_info "Generated database password"
    fi
    
    # Secure MariaDB installation
    mysql_secure_installation --use-default <<EOF
y
$DB_PASS
$DB_PASS
y
y
y
y
EOF

    # Create database and user
    mysql -u root -p"$DB_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    log_success "Database setup completed"
    
    # Save database credentials
    cat > "$ZOPLOG_HOME/.db_credentials" <<EOF
DB_HOST=localhost
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF
    chmod 600 "$ZOPLOG_HOME/.db_credentials"
    chown "$ZOPLOG_USER:$ZOPLOG_USER" "$ZOPLOG_HOME/.db_credentials"
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
    
    # Create config.py from template
    if [ ! -f config.py ]; then
        sudo -u "$ZOPLOG_USER" cp config.example.py config.py
        
        # Update config with database credentials
        sed -i "s/'localhost'/'localhost'/g" config.py
        sed -i "s/'logs_db'/'$DB_NAME'/g" config.py
        sed -i "s/'root'/'$DB_USER'/g" config.py
        sed -i "s/'8888'/'$DB_PASS'/g" config.py
    fi
    
    log_success "Python environment setup completed"
}

setup_web_interface() {
    log_info "Setting up web interface..."
    
    # Create web directory
    mkdir -p "$WEB_ROOT"
    cp -r "$ZOPLOG_HOME/zoplog/web-interface/"* "$WEB_ROOT/"
    
    # Update database configuration
    cat > "$WEB_ROOT/db.php" <<EOF
<?php
// db.php - MariaDB connection helper
\$DB_HOST = 'localhost';
\$DB_USER = '$DB_USER';
\$DB_PASS = '$DB_PASS';
\$DB_NAME = '$DB_NAME';

\$mysqli = @new mysqli(\$DB_HOST, \$DB_USER, \$DB_PASS, \$DB_NAME);
if (\$mysqli->connect_errno) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars(\$mysqli->connect_error));
}
\$mysqli->set_charset('utf8mb4');
?>
EOF
    
    # Set proper permissions
    chown -R www-data:www-data "$WEB_ROOT"
    chmod -R 755 "$WEB_ROOT"
    
    log_success "Web interface setup completed"
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
    
    log_success "Scripts installed successfully"
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
After=network.target systemd-networkd.service br-zoplog.service
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
    
    # Start services now (after network is configured)
    log_info "Starting ZopLog services..."
    systemctl start zoplog-logger.service || log_warning "Could not start zoplog-logger service (will auto-start on boot)"
    systemctl start zoplog-blockreader.service || log_warning "Could not start zoplog-blockreader service (will auto-start on boot)"
    
    log_success "Systemd services created and enabled for boot startup"
}

create_database_schema() {
    log_info "Creating database schema..."
    
    # Create database tables
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'EOF'
-- IP addresses table
CREATE TABLE IF NOT EXISTS ip_addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) UNIQUE NOT NULL,
    INDEX idx_ip (ip_address)
);

-- MAC addresses table
CREATE TABLE IF NOT EXISTS mac_addresses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    mac_address VARCHAR(17) UNIQUE NOT NULL,
    INDEX idx_mac (mac_address)
);

-- Hostnames table
CREATE TABLE IF NOT EXISTS hostnames (
    id INT PRIMARY KEY AUTO_INCREMENT,
    hostname VARCHAR(255) UNIQUE NOT NULL,
    ip_id INT,
    INDEX idx_hostname (hostname),
    FOREIGN KEY (ip_id) REFERENCES ip_addresses(id)
);

-- Paths table
CREATE TABLE IF NOT EXISTS paths (
    id INT PRIMARY KEY AUTO_INCREMENT,
    path TEXT NOT NULL,
    path_hash VARCHAR(64) UNIQUE NOT NULL,
    INDEX idx_path_hash (path_hash)
);

-- User agents table
CREATE TABLE IF NOT EXISTS user_agents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_agent TEXT NOT NULL,
    ua_hash VARCHAR(64) UNIQUE NOT NULL,
    INDEX idx_ua_hash (ua_hash)
);

-- Accept languages table
CREATE TABLE IF NOT EXISTS accept_languages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    accept_language VARCHAR(255) UNIQUE NOT NULL,
    INDEX idx_lang (accept_language)
);

-- Main packet logs table
CREATE TABLE IF NOT EXISTS packet_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    packet_timestamp TIMESTAMP(3) NOT NULL,
    src_ip_id INT NOT NULL,
    src_port SMALLINT UNSIGNED,
    dst_ip_id INT NOT NULL,
    dst_port SMALLINT UNSIGNED,
    src_mac_id INT,
    dst_mac_id INT,
    method VARCHAR(10),
    hostname_id INT,
    path_id INT,
    user_agent_id INT,
    accept_language_id INT,
    type ENUM('HTTP', 'HTTPS') NOT NULL,
    INDEX idx_timestamp (packet_timestamp),
    INDEX idx_src_ip (src_ip_id),
    INDEX idx_dst_ip (dst_ip_id),
    INDEX idx_hostname (hostname_id),
    INDEX idx_type (type),
    FOREIGN KEY (src_ip_id) REFERENCES ip_addresses(id),
    FOREIGN KEY (dst_ip_id) REFERENCES ip_addresses(id),
    FOREIGN KEY (src_mac_id) REFERENCES mac_addresses(id),
    FOREIGN KEY (dst_mac_id) REFERENCES mac_addresses(id),
    FOREIGN KEY (hostname_id) REFERENCES hostnames(id),
    FOREIGN KEY (path_id) REFERENCES paths(id),
    FOREIGN KEY (user_agent_id) REFERENCES user_agents(id),
    FOREIGN KEY (accept_language_id) REFERENCES accept_languages(id)
);

-- Blocklists table
CREATE TABLE IF NOT EXISTS blocklists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    url VARCHAR(2048) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,
    active ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active),
    INDEX idx_category (category)
);

-- Blocklist domains table
CREATE TABLE IF NOT EXISTS blocklist_domains (
    id INT PRIMARY KEY AUTO_INCREMENT,
    blocklist_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_blocklist_domain (blocklist_id, domain),
    INDEX idx_domain (domain),
    FOREIGN KEY (blocklist_id) REFERENCES blocklists(id) ON DELETE CASCADE
);

-- Blocked events table
CREATE TABLE IF NOT EXISTS blocked_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    timestamp TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
    direction ENUM('IN', 'OUT') NOT NULL,
    src_ip_id INT,
    dst_ip_id INT,
    src_port SMALLINT UNSIGNED,
    dst_port SMALLINT UNSIGNED,
    protocol VARCHAR(10),
    interface_in VARCHAR(20),
    interface_out VARCHAR(20),
    raw_log TEXT,
    INDEX idx_timestamp (timestamp),
    INDEX idx_direction (direction),
    INDEX idx_src_ip (src_ip_id),
    INDEX idx_dst_ip (dst_ip_id),
    FOREIGN KEY (src_ip_id) REFERENCES ip_addresses(id),
    FOREIGN KEY (dst_ip_id) REFERENCES ip_addresses(id)
);

-- Blocked IPs tracking table
CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT PRIMARY KEY AUTO_INCREMENT,
    blocklist_domain_id INT NOT NULL,
    ip_id INT NOT NULL,
    blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain_ip (blocklist_domain_id, ip_id),
    FOREIGN KEY (blocklist_domain_id) REFERENCES blocklist_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (ip_id) REFERENCES ip_addresses(id)
);
EOF
    
    log_success "Database schema created"
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
    setup_nginx
    setup_scripts
    setup_transparent_proxy
    setup_systemd_services
    create_database_schema
    
    show_completion_message
}

# Run main function
main "$@"
