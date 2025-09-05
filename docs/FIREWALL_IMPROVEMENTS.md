# Firewall Script Improvements

## Problem Solved
The original `zoplog-firewall-apply` script had a major logic flaw where it monitored **both** the internet-facing interface AND any bridge interfaces. This meant it would block internal network traffic that never goes to the internet, which was not the intended behavior.

## Key Changes Made

### 1. Removed Bridge Monitoring Logic
**Before:**
- Script detected if the internet interface was part of a bridge
- Applied rules to BOTH the main interface AND the bridge interface
- This monitored internal bridge traffic (e.g., local file sharing, printers)

**After:**
- Only applies rules to the configured internet-facing interface
- Internal bridge traffic is ignored completely
- Only internet-bound traffic is monitored and blocked

### 2. Fixed NFT Path Issues
**Before:**
- Used `nft` command without full path
- Failed on systems where nft is not in PATH

**After:**
- Uses full path `/usr/sbin/nft` for all commands
- Works reliably across different system configurations

### 3. Simplified and Cleaned Logic
**Before:**
- Complex `add_rules_for_interfaces()` function
- Duplicate rules for main and bridge interfaces
- Confusing bridge detection logic

**After:**
- Simple, direct rule addition
- Clear, focused logic
- Easy to understand and maintain

### 4. Removed Redundant Script
**Before:**
- Had both `zoplog-firewall-apply` and `zoplog-firewall-apply-simple`
- Confusing which one to use
- Maintenance overhead

**After:**
- Only `zoplog-firewall-apply` exists
- Single source of truth
- No confusion about which script to use

## What the Script Does Now

1. **Internet-Only Monitoring**: Only monitors traffic going to/from the internet via the configured interface
2. **Complete Coverage**: Handles INPUT, OUTPUT, and FORWARD chains
3. **IPv4/IPv6 Support**: Creates both IPv4 and IPv6 blocking sets
4. **Proper Logging**: Logs blocked attempts before dropping packets
5. **Auto-Save**: Automatically saves NFT rules after applying

## Network Traffic Flow

```
Internal Device → Bridge → Internet Interface → Internet
     ↑              ↑              ↑
  Not monitored  Not monitored   MONITORED ✅
```

Only the final hop to the internet is monitored, not internal bridge communications.

## Configuration

The script reads the internet-facing interface from `/etc/zoplog/zoplog.conf`:
```ini
apply_to_interface=eth0
```

If not configured, defaults to `eth0`.
