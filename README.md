# ZopLog - Network Traffic Monitor & Blocker

ZopLog is a comprehensive network monitoring and content blocking solution that acts as a transparent proxy between your internet connection and internal network. It captures, analyzes, and blocks network traffic based on user-defined blocklists while providing real-time monitoring through an intuitive web dashboard.

## 🚀 Quick Install

**Requirements:** Fresh Debian-based Linux system with **two ethernet interfaces**

Run this single command to install ZopLog:

```bash
curl -sSL https://raw.githubusercontent.com/YiannisBourkelis/zoplog/main/install.sh | sudo bash
```

The installer will:
- ✅ Set up transparent proxy between your router and internal network
- ✅ Install and configure all dependencies (Nginx, PHP 8.4, MariaDB, Python)
- ✅ Create system user and services
- ✅ Configure database and web interface
- ✅ Set up firewall integration with nftables

**After installation:** Reboot your system, then access the dashboard at `http://your-server-ip/`

## 🏗️ Architecture

ZopLog works as a network bridge with two ethernet interfaces:
- **Interface 1**: Connected to your internet router
- **Interface 2**: Connected to your internal network switch
- **Traffic Flow**: Internet ← Router ← ZopLog ← Switch ← Your Devices

## Project Structure

```
zoplog/
├── python-logger/
│   ├── logger.py                    # HTTP/HTTPS packet capture
│   ├── nft_blocklog_reader.py       # NFTables log processor
│   └── config.py                    # Database configuration
├── web-interface/
│   ├── index.php                    # Real-time dashboard
│   ├── logger.php                   # Traffic logs viewer
│   ├── blocklists.php               # Blocklist management
│   └── api/realtime_data.php        # Dashboard API
├── scripts/
│   ├── zoplog-firewall-*            # Firewall management scripts
│   └── zoplog-nft-*                 # NFTables integration
└── install.sh                       # Automated installer
```

## Manual Setup Instructions

*Only needed if you prefer manual installation over the automated installer*

### System Requirements

- Debian-based Linux distribution (Ubuntu, Debian, etc.)
- Two ethernet interfaces
- Root access
- Internet connectivity

### Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install system dependencies
sudo apt install -y curl wget git python3 python3-pip python3-venv \
    python3-systemd python3-scapy nginx mariadb-server php8.4 \
    php8.4-fpm php8.4-mysql php8.4-cli nftables bridge-utils

# Install Python packages
pip3 install PyMySQL mysql-connector-python systemd-python scapy
```

### Database Setup

```bash
# Secure MariaDB
sudo mysql_secure_installation

# Create database
sudo mysql -u root -p
```

```sql
CREATE DATABASE logs_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'zoplog_db'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON logs_db.* TO 'zoplog_db'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Application Setup

```bash
# Clone repository
git clone https://github.com/YiannisBourkelis/zoplog.git
cd zoplog

# Setup Python environment
cd python-logger
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Configure database connection
cp config.example.py config.py
# Edit config.py with your database credentials

# Setup web interface
sudo cp -r web-interface/* /var/www/html/
sudo chown -R www-data:www-data /var/www/html/
```

## 🖥️ Usage

### Web Dashboard

After installation, access the web interface:

- **Dashboard**: `http://your-server-ip/` - Real-time network monitoring
- **Traffic Logs**: `http://your-server-ip/logger.php` - Detailed request logs  
- **Blocklist Management**: `http://your-server-ip/blocklists.php` - Manage content filters
- **API Endpoint**: `http://your-server-ip/api/realtime_data.php` - JSON data API

### Command Line Management

```bash
# Start/stop services
sudo systemctl start zoplog-logger
sudo systemctl start zoplog-blockreader
sudo systemctl stop zoplog-logger

# View logs
sudo journalctl -u zoplog-logger -f
sudo journalctl -u zoplog-blockreader -f

# Firewall management
sudo /opt/zoplog/zoplog/scripts/zoplog-firewall-apply <blocklist_id>
sudo /opt/zoplog/zoplog/scripts/zoplog-firewall-toggle <blocklist_id> active
sudo /opt/zoplog/zoplog/scripts/zoplog-firewall-remove <blocklist_id>
```

### Adding Blocklists

1. Navigate to the Blocklists page in the web interface
2. Add a blocklist URL (e.g., hosts file format)
3. Select category and add description
4. Toggle active/inactive status
5. Firewall rules are automatically applied

## ⚡ Features

- **🔄 Real-time Network Monitoring**: Live dashboard with 2-second updates
- **📊 Traffic Analysis**: Detailed breakdown of allowed vs blocked requests
- **💻 System Resource Monitoring**: CPU, memory, disk, and network usage tracking
- **📈 Interactive Charts**: Visual representation of traffic patterns and system metrics
- **🛡️ Blocklist Management**: Web interface for managing content filters
- **🔥 NFTables Integration**: Advanced firewall rule management with automatic IP blocking
- **🌉 Transparent Proxy**: Bridge mode operation between router and internal network
- **🔗 RESTful API**: Centralized data access through `/api/realtime_data.php`
- **⚡ Auto-blocking**: Automatic IP blocking when domains are resolved
- **📝 Comprehensive Logging**: HTTP/HTTPS request logging with detailed metadata

## 🔧 System Architecture

```
Internet ← Router ← [ZopLog Bridge] ← Switch ← Your Devices
                         │
                    ┌────┴────┐
                    │ Packet  │
                    │ Capture │
                    └────┬────┘
                         │
                    ┌────┴────┐
                    │ Analysis│
                    │   &     │
                    │Blocking │
                    └────┬────┘
                         │
                    ┌────┴────┐
                    │   Web   │
                    │Dashboard│
                    └─────────┘
```

## 🚨 Security & Performance

- **Minimal Attack Surface**: System user separation and privilege isolation
- **High Performance**: Optimized packet processing with minimal latency
- **Resource Efficient**: Low CPU and memory usage
- **Scalable**: Handles high traffic volumes with database optimization
- **Secure**: Sandboxed execution and proper input validation

## 🤝 Contributing

We welcome contributions to ZopLog! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details on:

- How to submit issues and pull requests
- Code standards and best practices
- Copyright assignment requirements
- Security vulnerability reporting

## 📄 License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

### Copyright Notice

Copyright 2025 Yiannis. All contributors assign their rights to the project maintainer.

## 📞 Support

- **🐛 Bug Reports**: [GitHub Issues](https://github.com/YiannisBourkelis/zoplog/issues)
- **💡 Feature Requests**: [GitHub Issues](https://github.com/YiannisBourkelis/zoplog/issues)
- **🔒 Security Issues**: Contact maintainer directly (do not create public issues)
- **📖 Documentation**: Project README and inline code comments
- **💬 Community**: GitHub Discussions (coming soon)

## 🙏 Acknowledgments

- Built with modern web technologies (PHP 8.4, Python 3, MariaDB)
- Uses NFTables for high-performance packet filtering
- Inspired by network security best practices
- Community-driven blocklist integration

---

**⭐ Star this project on GitHub if you find it useful!**

**🔗 Repository**: https://github.com/YiannisBourkelis/zoplog