#!/usr/bin/env bash
# ZopLog Bridge Mode Setup Script
# Places the Raspberry Pi transparently between router (WAN) and switch (LAN) using a Layer-2 bridge.
# - No DHCP server on Pi (router keeps serving addresses)
# - Pi gets its own IP via DHCP from router (on br0)
# - br_netfilter enabled so nftables forward chain sees traffic
# - Updates /etc/zoplog/zoplog.conf to monitor & enforce on br0

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info(){ echo -e "${BLUE}[INFO]${NC} $*"; }
warn(){ echo -e "${YELLOW}[WARN]${NC} $*"; }
err(){ echo -e "${RED}[ERR ]${NC} $*" >&2; }
ok(){ echo -e "${GREEN}[OK ]${NC} $*"; }

require_root(){ [[ $EUID -eq 0 ]] || { err "Run as root"; exit 1; }; }

require_root

if ! command -v nft >/dev/null 2>&1; then
  info "Installing required packages (nftables bridge-utils)"
  apt-get update -qq
  apt-get install -y nftables bridge-utils
fi

ENABLE_SYSTEMD_NETWORKD=1
if systemctl is-active --quiet NetworkManager 2>/dev/null; then
  warn "NetworkManager detected. This script will configure systemd-networkd; NetworkManager may need disabling."
fi
if systemctl is-active --quiet dhcpcd 2>/dev/null; then
  warn "dhcpcd service detected; it will be disabled to avoid conflicts." 
fi

info "Enumerating candidate Ethernet interfaces"
# Collect all non-loopback Layer-2 interfaces, then filter typical wired patterns
mapfile -t ALL_LINKS < <(ip -o link show | awk -F': ' '{print $2}' | cut -d'@' -f1 | grep -Ev '^lo$' | sort -u)

# Accept names matching common wired conventions: eth*, enp*, ens*, eno*, enx* (USB MAC-based), usb* (rare)
mapfile -t IFACES < <(printf '%s\n' "${ALL_LINKS[@]}" | grep -E '^(eth|enp|ens|eno|enx|usb)' || true)

if ((${#IFACES[@]} < 2)); then
  warn "Auto-detected <2 wired interfaces (found: ${IFACES[*]:-none})." 
  warn "Full device list: ${ALL_LINKS[*]:-none}" 
  echo
  read -rp "Enter WAN (router-facing) interface name manually (leave blank to abort): " MAN_WAN
  read -rp "Enter LAN (switch-facing) interface name manually (leave blank to abort): " MAN_LAN
  if [[ -n $MAN_WAN && -n $MAN_LAN && $MAN_WAN != $MAN_LAN ]]; then
    IFACES=($MAN_WAN $MAN_LAN)
    ok "Using manual interfaces: ${IFACES[*]}"
  else
    err "Insufficient interfaces and no valid manual override provided. Abort."
    exit 1
  fi
fi

echo ""; echo "Available interfaces:"; i=0; for f in "${IFACES[@]}"; do echo "  [$i] $f"; ((i++)); done

read -rp "Select WAN (router-facing) index: " WAN_IDX
read -rp "Select LAN (switch-facing) index: " LAN_IDX
[[ $WAN_IDX =~ ^[0-9]+$ && $LAN_IDX =~ ^[0-9]+$ ]] || { err "Invalid indices"; exit 1; }
[[ $WAN_IDX -ne $LAN_IDX ]] || { err "WAN and LAN must differ"; exit 1; }
WAN_IF=${IFACES[$WAN_IDX]:-}
LAN_IF=${IFACES[$LAN_IDX]:-}
[[ -n $WAN_IF && -n $LAN_IF ]] || { err "Interface resolution failed"; exit 1; }

echo
info "Selected: WAN=$WAN_IF  LAN=$LAN_IF"
read -rp "Proceed with bridge creation (y/N)? " CONFIRM
[[ ${CONFIRM,,} == y || ${CONFIRM,,} == yes ]] || { err "Aborted by user"; exit 1; }

BR_NAME=br0

# Stop potential conflicting services
systemctl stop dhcpcd 2>/dev/null || true
systemctl disable dhcpcd 2>/dev/null || true

# Enable modules & sysctls
info "Enabling br_netfilter & forwarding"
echo br_netfilter > /etc/modules-load.d/br_netfilter.conf
modprobe br_netfilter || true
cat > /etc/sysctl.d/99-zoplog-bridge.conf <<EOF
net.bridge.bridge-nf-call-iptables=1
net.bridge.bridge-nf-call-ip6tables=1
net.ipv4.ip_forward=1
net.ipv6.conf.all.forwarding=1
EOF
sysctl --system >/dev/null

# systemd-networkd config
info "Configuring systemd-networkd bridge"
mkdir -p /etc/systemd/network

# Remove any prior configs we might overwrite (safe pattern)
rm -f /etc/systemd/network/10-${BR_NAME}.netdev /etc/systemd/network/20-${BR_NAME}.network /etc/systemd/network/30-${WAN_IF}.network /etc/systemd/network/31-${LAN_IF}.network

cat > /etc/systemd/network/10-${BR_NAME}.netdev <<EOF
[NetDev]
Name=${BR_NAME}
Kind=bridge
EOF

cat > /etc/systemd/network/20-${BR_NAME}.network <<EOF
[Match]
Name=${BR_NAME}

[Network]
DHCP=yes
LLMNR=no
MulticastDNS=no
EOF

for IF in $WAN_IF $LAN_IF; do
cat > /etc/systemd/network/30-${IF}.network <<EOF
[Match]
Name=${IF}

[Network]
Bridge=${BR_NAME}
EOF
done

systemctl enable --now systemd-networkd 2>/dev/null || true
systemctl restart systemd-networkd

info "Flushing existing IP config on selected interfaces"
ip addr flush dev "$WAN_IF" || true
ip addr flush dev "$LAN_IF" || true

sleep 3

if ! ip link show ${BR_NAME} >/dev/null 2>&1; then
  err "Bridge ${BR_NAME} not created"
  exit 1
fi

info "Waiting for DHCP lease on ${BR_NAME}"
for i in {1..15}; do
  BR_IP=$(ip -4 -o addr show ${BR_NAME} | awk '{print $4}' | cut -d/ -f1 || true)
  [[ -n $BR_IP ]] && break
  sleep 1
done
if [[ -z ${BR_IP:-} ]]; then
  warn "No IPv4 lease yet; continuing anyway"
else
  ok "Bridge has IP ${BR_IP}"
fi

# Update ZopLog config
ZCFG=/etc/zoplog/zoplog.conf
if [[ -f $ZCFG ]]; then
  info "Updating ${ZCFG} for bridge monitoring"
  # Create backup
  cp -a "$ZCFG" "${ZCFG}.bak.$(date +%s)"
else
  warn "${ZCFG} missing; creating new one"
  mkdir -p /etc/zoplog
fi

cat > "$ZCFG" <<EOF
# Auto-generated by setup-bridge-mode.sh
[monitoring]
interface = ${BR_NAME}
capture_mode = promiscuous
log_level = INFO

[firewall]
apply_to_interface = ${BR_NAME}
block_mode = immediate
log_blocked = true

[system]
bridge_mode = dual
internet_interface = ${WAN_IF}
internal_interface = ${LAN_IF}
update_interval = 30
max_log_entries = 10000
last_updated = $(date '+%Y-%m-%d %H:%M:%S')
EOF

chown root:www-data "$ZCFG" 2>/dev/null || true
chmod 640 "$ZCFG" 2>/dev/null || true

# Restart logger if present
if systemctl is-enabled --quiet zoplog-logger 2>/dev/null; then
  info "Restarting zoplog-logger"
  systemctl restart zoplog-logger || warn "Failed to restart zoplog-logger"
fi

info "Verifying nftables forward chain visibility (br_netfilter)"
if [[ $(cat /proc/sys/net/bridge/bridge-nf-call-iptables) -ne 1 ]]; then
  warn "bridge-nf-call-iptables not active; forwarding may bypass layer3 rules"
fi

cat <<SUMMARY
---------------------------------------------
Bridge setup complete
  Bridge      : ${BR_NAME}
  WAN (router): ${WAN_IF}
  LAN (switch): ${LAN_IF}
  IP (if any) : ${BR_IP:-<pending>}
ZopLog now monitoring & enforcing on br0.

Test steps (from a LAN client):
  1. Ensure cable path: Router <-> Pi(${WAN_IF}) Pi(${LAN_IF}) <-> Switch <-> Clients
  2. Open blocked domain (e.g., facebook.com)
  3. On Pi: nft list set inet zoplog zoplog-blocklist-1-v4 | grep <IP>
  4. journalctl -u zoplog-logger -n 50 -o cat

Rollback:
  systemctl stop systemd-networkd
  rm /etc/systemd/network/10-${BR_NAME}.netdev /etc/systemd/network/20-${BR_NAME}.network /etc/systemd/network/30-${WAN_IF}.network /etc/systemd/network/31-${LAN_IF}.network
  ip link set ${WAN_IF} nomaster 2>/dev/null || true
  ip link set ${LAN_IF} nomaster 2>/dev/null || true
  ip link delete ${BR_NAME} 2>/dev/null || true
  systemctl restart systemd-networkd
---------------------------------------------
SUMMARY

ok "Done"