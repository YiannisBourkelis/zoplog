# ZopLog Quick Start Guide

## ⚡ Ultra-Quick Install

**For impatient users who want ZopLog running in 5 minutes:**

```bash
# 1. Run installer (requires 2 ethernet interfaces)
curl -sSL https://raw.githubusercontent.com/YiannisBourkelis/zoplog/main/install.sh | sudo bash

# 2. Reboot to activate network bridge
sudo reboot

# 3. Start services after reboot  
sudo systemctl start zoplog-logger zoplog-blockreader

# 4. Access dashboard
# Open browser: http://YOUR_IP_ADDRESS/
```

## 🔧 Post-Install Configuration

### 1. Add Your First Blocklist

```bash
# Navigate to: http://YOUR_IP/blocklists.php
# Click "Add New Blocklist"
# Example URLs to try:
# - https://someonewhocares.org/hosts/zero/hosts
# - https://raw.githubusercontent.com/StevenBlack/hosts/master/hosts
```

### 2. Verify Everything Works

```bash
# Check services are running
sudo systemctl status zoplog-logger
sudo systemctl status zoplog-blockreader

# View live logs
sudo journalctl -u zoplog-logger -f

# Check network bridge
ip addr show br-zoplog
```

### 3. Test Blocking

```bash
# From a device on your network, try to visit a blocked domain
# Check the dashboard for blocked requests
# View logs at: http://YOUR_IP/logger.php
```

## 🚨 Common Issues

### "No network interfaces found"
- You need exactly 2 ethernet interfaces
- One connects to your router/modem
- One connects to your internal switch

### "Services won't start"
- Check permissions: `sudo chown -R zoplog:zoplog /opt/zoplog`
- Check config: `cat /opt/zoplog/zoplog/python-logger/config.py`
- Check database: `sudo mysql -u root -p logs_db`

### "Dashboard shows no data"
- Wait 30 seconds for data to populate
- Generate traffic from connected devices
- Check Python services are capturing packets

### "Can't access web interface"
- Check nginx: `sudo systemctl status nginx`
- Verify IP: `ip addr show`
- Check firewall: `sudo ufw status`

## 🔥 Pro Tips

### Network Setup
```
Internet Router (192.168.1.1)
    ↓ eth0
ZopLog Device
    ↓ eth1  
Internal Switch → Your Devices (192.168.1.x)
```

### Performance Tuning
```bash
# For high-traffic networks
echo 'net.core.rmem_max = 67108864' >> /etc/sysctl.conf
echo 'net.core.wmem_max = 67108864' >> /etc/sysctl.conf
sysctl -p
```

### Security Hardening
```bash
# Change default database password
sudo mysql -u root -p
# ALTER USER 'zoplog_db'@'localhost' IDENTIFIED BY 'new_secure_password';

# Update config files with new password
sudo nano /opt/zoplog/zoplog/python-logger/config.py
sudo nano /var/www/zoplog/db.php
```

## 🎯 Advanced Usage

### API Integration
```bash
# Get real-time data programmatically
curl http://YOUR_IP/api/realtime_data.php | jq '.'

# Example response includes:
# - Traffic statistics
# - System metrics  
# - Recent activity
# - Timeline data
```

### Custom Blocklists
```bash
# Add your own blocklist format
# Supports:
# - Hosts file format (127.0.0.1 badsite.com)
# - Domain-only format (badsite.com)
# - AdBlock Plus format (||badsite.com^)
```

### Firewall Integration
```bash
# Manual firewall control
sudo /usr/local/sbin/zoplog-firewall-apply 1    # Apply blocklist ID 1
sudo /usr/local/sbin/zoplog-firewall-toggle 1 inactive  # Disable
sudo /usr/local/sbin/zoplog-firewall-remove 1   # Remove completely
```

---

**Need help?** Open an issue at: https://github.com/YiannisBourkelis/zoplog/issues
