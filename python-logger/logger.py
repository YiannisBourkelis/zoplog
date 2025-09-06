#!/usr/bin/env python3

import scapy.all as scapy
from scapy.layers.http import HTTPRequest
from scapy.packet import bind_layers
from scapy.layers.inet import TCP
from datetime import datetime
from config import DB_CONFIG, DEFAULT_MONITOR_INTERFACE, SETTINGS_FILE
import subprocess
import json
import os

# --- DB driver fallback (mysql-connector or PyMySQL) ---
try:
    import pymysql as mariadb
except ImportError:
    import mysql.connector as mariadb

# --- Ensure HTTP dissector works on all ports ---
bind_layers(TCP, HTTPRequest)

# --- Global connection ---
conn = mariadb.connect(**DB_CONFIG)
cursor = conn.cursor()

# --- DB helpers ---
def get_or_insert(table, column, value):
    if not value:
        return None
    cursor.execute(
        f"INSERT INTO {table} ({column}) VALUES (%s) "
        f"ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
        (value,)
    )
    return cursor.lastrowid

def get_or_insert_hostname_with_ip(hostname, ip_id):
    """Insert hostname with IP relationship or get existing one, updating IP if needed"""
    if not hostname:
        return None
    
    # First check if hostname exists
    cursor.execute("SELECT id, ip_id FROM hostnames WHERE hostname = %s", (hostname,))
    result = cursor.fetchone()
    
    if result:
        existing_id, existing_ip_id = result
        # If IP relationship doesn't exist but we have one, update it
        if not existing_ip_id and ip_id:
            cursor.execute("UPDATE hostnames SET ip_id = %s WHERE id = %s", (ip_id, existing_id))
            conn.commit()
        return existing_id
    else:
        # Insert new hostname with IP relationship
        cursor.execute(
            "INSERT INTO hostnames (hostname, ip_id) VALUES (%s, %s)",
            (hostname, ip_id)
        )
        return cursor.lastrowid

def get_or_insert_ip(ip_address):
    return get_or_insert("ip_addresses", "ip_address", ip_address)

def get_or_insert_mac(mac_address):
    return get_or_insert("mac_addresses", "mac_address", mac_address)

def insert_packet_log(packet_timestamp, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac, method, hostname, path, user_agent,
                      accept_language, pkt_type):
    """Insert normalized packet log; reconnect if server has gone away"""
    global conn, cursor
    try:
        src_ip_id = get_or_insert_ip(src_ip)
        dst_ip_id = get_or_insert_ip(dst_ip)
        src_mac_id = get_or_insert_mac(src_mac)
        dst_mac_id = get_or_insert_mac(dst_mac)
        hostname_id = get_or_insert_hostname_with_ip(hostname, dst_ip_id) if hostname else None
        path_id = get_or_insert("paths", "path", path) if path else None
        user_agent_id = get_or_insert("user_agents", "user_agent", user_agent) if user_agent else None
        accept_language_id = get_or_insert("accept_languages", "accept_language", accept_language) if accept_language else None

        cursor.execute("""
            INSERT INTO packet_logs
            (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
             src_mac_id, dst_mac_id,
             method, hostname_id, path_id, user_agent_id, accept_language_id, type)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
              src_mac_id, dst_mac_id,
              method, hostname_id, path_id, user_agent_id, accept_language_id, pkt_type))
        conn.commit()

    except mariadb.Error as e:
        if "MySQL server has gone away" in str(e):
            print("DB connection lost, reconnecting...")
            conn = mariadb.connect(**DB_CONFIG)
            cursor = conn.cursor()
            insert_packet_log(packet_timestamp, src_ip, src_port, dst_ip, dst_port,
                              src_mac, dst_mac, method, hostname, path, user_agent,
                              accept_language, pkt_type)
        else:
            raise

# --- Blocklist matching & firewall helper ---
def _normalize_hostname(host: str) -> str:
    if not host:
        return ""
    # Strip port if present (Host header may include it)
    host = host.strip()
    if ':' in host:
        host = host.split(':', 1)[0]
    # Lowercase and remove trailing dot
    return host.lower().rstrip('.')


def find_matching_blocklists_for_host(host: str):
    """Return blocklist IDs where active blocklists contain an exact match of `host`."""
    global conn
    h = _normalize_hostname(host)
    if not h:
        return []
    try:
        cur = conn.cursor()
        query = (
            "SELECT DISTINCT bd.blocklist_id "
            "FROM blocklist_domains bd "
            "JOIN blocklists bl ON bl.id = bd.blocklist_id "
            "WHERE bl.active = 'active' AND bd.domain = %s"
        )
        cur.execute(query, (h,))
        rows = cur.fetchall()
        return [row[0] for row in rows]
    except mariadb.Error as e:
        # Attempt a single reconnect on connection loss
        if "MySQL server has gone away" in str(e):
            try:
                conn = mariadb.connect(**DB_CONFIG)
                cur = conn.cursor()
                query = (
                    "SELECT DISTINCT bd.blocklist_id "
                    "FROM blocklist_domains bd "
                    "JOIN blocklists bl ON bl.id = bd.blocklist_id "
                    "WHERE bl.active = 'active' AND bd.domain = %s"
                )
                cur.execute(query, (h,))
                rows = cur.fetchall()
                return [row[0] for row in rows]
            except Exception as e2:
                print(f"Blocklist lookup failed after reconnect: {e2}")
                return []
        else:
            print(f"Blocklist lookup error: {e}")
            return []
    except Exception as e:
        print(f"Blocklist lookup unexpected error: {e}")
        return []
    finally:
        try:
            cur.close()
        except Exception:
            pass


def find_matching_blocklist_domains(host: str):
    """Return list of tuples (blocklist_id, blocklist_domain_id) for exact host matches in active blocklists."""
    global conn
    h = _normalize_hostname(host)
    if not h:
        return []
    try:
        cur = conn.cursor()
        query = (
            "SELECT bd.blocklist_id, bd.id AS blocklist_domain_id "
            "FROM blocklist_domains bd "
            "JOIN blocklists bl ON bl.id = bd.blocklist_id "
            "WHERE bl.active = 'active' AND bd.domain = %s"
        )
        cur.execute(query, (h,))
        rows = cur.fetchall()
        return [(row[0], row[1]) for row in rows]
    except mariadb.Error as e:
        if "MySQL server has gone away" in str(e):
            try:
                conn = mariadb.connect(**DB_CONFIG)
                cur = conn.cursor()
                cur.execute(query, (h,))
                rows = cur.fetchall()
                return [(row[0], row[1]) for row in rows]
            except Exception as e2:
                print(f"Blocklist domain lookup failed after reconnect: {e2}")
                return []
        else:
            print(f"Blocklist domain lookup error: {e}")
            return []
    except Exception as e:
        print(f"Blocklist domain lookup unexpected error: {e}")
        return []
    finally:
        try:
            cur.close()
        except Exception:
            pass


def _record_blocked_ip(blocklist_domain_id: int, ip: str):
    """Insert or update the blocked IP record linked to a specific blocklist_domain row."""
    global conn, cursor
    if not ip or not blocklist_domain_id:
        return
    try:
        ip_id = get_or_insert_ip(ip)
        if not ip_id:
            return
        # Upsert to track first/last_seen and hit_count
        cursor.execute(
            """
            INSERT INTO blocked_ips (blocklist_domain_id, ip_id, first_seen, last_seen, hit_count)
            VALUES (%s, %s, NOW(), NOW(), 1)
            ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), hit_count = hit_count + 1
            """,
            (blocklist_domain_id, ip_id),
        )
        conn.commit()
    except mariadb.Error as e:
        if "MySQL server has gone away" in str(e):
            try:
                conn = mariadb.connect(**DB_CONFIG)
                cursor = conn.cursor()
                _record_blocked_ip(blocklist_domain_id, ip)
            except Exception as e2:
                print(f"blocked_ips insert failed after reconnect: {e2}")
        else:
            print(f"blocked_ips insert error: {e}")


def ipset_add_ip(blocklist_id: int, ip: str, blocklist_domain_id: int | None = None):
    """Add IP to nft set for blocklist and record it in DB linked to the specific domain when provided."""
    print(f"DEBUG: ipset_add_ip called with blocklist_id={blocklist_id}, ip={ip}, domain_id={blocklist_domain_id}")
    
    # First try to apply firewall change
    try:
        # Use setuid script directly (no sudo needed)
        cmd = ["/usr/local/sbin/zoplog-firewall-ipset-add", str(blocklist_id), ip]
        print(f"DEBUG: Executing command: {' '.join(cmd)}")
        
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=2,
        )
        
        print(f"DEBUG: Command completed - returncode={result.returncode}")
        print(f"DEBUG: stdout: {repr(result.stdout)}")
        print(f"DEBUG: stderr: {repr(result.stderr)}")
        
        if result.returncode != 0:
            err = (result.stderr or '').strip()
            print(f"ERROR: ipset add failed rc={result.returncode} id={blocklist_id} ip={ip} stderr={err}")
        else:
            print(f"SUCCESS: ipset add completed successfully for id={blocklist_id} ip={ip}")
            
    except subprocess.TimeoutExpired:
        print(f"ERROR: ipset add timed out id={blocklist_id} ip={ip}")
    except Exception as e:
        print(f"ERROR: ipset add exception id={blocklist_id} ip={ip} err={e}")

    # Independently record in DB (even if firewall fails, for observability)
    if blocklist_domain_id:
        try:
            print(f"DEBUG: Recording blocked IP in database for domain_id={blocklist_domain_id}")
            _record_blocked_ip(blocklist_domain_id, ip)
            print(f"DEBUG: Successfully recorded blocked IP in database")
        except Exception as e:
            print(f"ERROR: record blocked_ip error domain_id={blocklist_domain_id} ip={ip} err={e}")
    else:
        print(f"DEBUG: No blocklist_domain_id provided, skipping database record")

# --- Packet logging ---
def _get_ips(packet):
    try:
        if packet.haslayer(scapy.IP):
            return packet[scapy.IP].src, packet[scapy.IP].dst
        if packet.haslayer(scapy.IPv6):
            return packet[scapy.IPv6].src, packet[scapy.IPv6].dst
    except Exception:
        pass
    return None, None


def log_http_request(packet):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')
    http_request = packet[HTTPRequest]

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    method = http_request.Method.decode()
    host = http_request.Host.decode() if http_request.Host else None
    path = http_request.Path.decode() if http_request.Path else None
    user_agent = http_request.User_Agent.decode() if http_request.User_Agent else None
    accept_language = http_request.Accept_Language.decode() if http_request.Accept_Language else None

    print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTP\t{method}\t{host}{path or ''}")
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      method, host, path, user_agent, accept_language, "HTTP")

    # If host matches any active blocklist, add destination IP to corresponding set(s) and record it
    try:
        if host and dst_ip:
            for bl_id, bd_id in find_matching_blocklist_domains(host):
                ipset_add_ip(bl_id, dst_ip, bd_id)
    except Exception as e:
        print(f"error during ipset add for HTTP host={host} ip={dst_ip}: {e}")

def extract_tls_sni(packet):
    """Parse TLS ClientHello to extract SNI hostname cleanly."""
    try:
        if not packet.haslayer(scapy.TCP) or not packet.haslayer(scapy.Raw):
            return None
        data = bytes(packet[scapy.Raw].load)
        # TLS record header: 5 bytes
        if len(data) < 5 or data[0] != 0x16:  # ContentType 22 = handshake
            return None
        # Record length (not strictly needed for this quick parse)
        # rec_len = int.from_bytes(data[3:5], 'big')
        # Handshake must be ClientHello
        if len(data) < 6 or data[5] != 0x01:
            return None

        idx = 5
        if len(data) < 9:
            return None
        hs_len = int.from_bytes(data[6:9], 'big')  # noqa: F841
        idx = 9

        # Client version (2) + Random (32)
        if len(data) < idx + 2 + 32:
            return None
        idx += 2 + 32

        # Session ID
        if len(data) < idx + 1:
            return None
        sid_len = data[idx]
        idx += 1
        if len(data) < idx + sid_len:
            return None
        idx += sid_len

        # Cipher Suites
        if len(data) < idx + 2:
            return None
        cs_len = int.from_bytes(data[idx:idx+2], 'big')
        idx += 2
        if len(data) < idx + cs_len:
            return None
        idx += cs_len

        # Compression Methods
        if len(data) < idx + 1:
            return None
        comp_len = data[idx]
        idx += 1
        if len(data) < idx + comp_len:
            return None
        idx += comp_len

        # Extensions
        if len(data) < idx + 2:
            return None
        ext_total_len = int.from_bytes(data[idx:idx+2], 'big')
        idx += 2
        end = idx + ext_total_len
        if end > len(data):
            end = len(data)

        while idx + 4 <= end:
            ext_type = int.from_bytes(data[idx:idx+2], 'big')
            ext_len = int.from_bytes(data[idx+2:idx+4], 'big')
            ext_data_start = idx + 4
            ext_data_end = ext_data_start + ext_len
            if ext_data_end > end:
                break

            # SNI extension type = 0x0000
            if ext_type == 0x0000:
                if ext_len < 2:
                    break
                list_len = int.from_bytes(data[ext_data_start:ext_data_start+2], 'big')
                p = ext_data_start + 2
                list_end = min(ext_data_end, p + list_len)
                while p + 3 <= list_end:
                    name_type = data[p]
                    name_len = int.from_bytes(data[p+1:p+3], 'big')
                    p += 3
                    if p + name_len > list_end:
                        break
                    if name_type == 0x00:  # host_name
                        host_bytes = data[p:p+name_len]
                        host = host_bytes.decode('utf-8', errors='ignore').strip().lower().rstrip('.')
                        return host
                    p += name_len

            idx = ext_data_end

        return None
    except Exception:
        return None

def log_https_request(packet):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    hostname = extract_tls_sni(packet)

    print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTPS\t{hostname or 'N/A'}")
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      "TLS_CLIENTHELLO", hostname, None, None, None, "HTTPS")

    # If SNI matches any active blocklist, add destination IP to corresponding set(s) and record it
    try:
        if hostname and dst_ip:
            for bl_id, bd_id in find_matching_blocklist_domains(hostname):
                ipset_add_ip(bl_id, dst_ip, bd_id)
    except Exception as e:
        print(f"error during ipset add for HTTPS host={hostname} ip={dst_ip}: {e}")

def tcp_packet_handler(packet):
    try:
        if packet.haslayer(HTTPRequest):
            log_http_request(packet)
        else:
            hostname = extract_tls_sni(packet)
            if hostname:
                log_https_request(packet)
    except Exception:
        pass

def load_system_settings():
    """Load system settings from centralized config file, return defaults if not found"""
    try:
        if os.path.exists(SETTINGS_FILE):
            # Load from INI format (/etc/zoplog/zoplog.conf)
            from zoplog_config import load_settings_config
            return load_settings_config()
    except Exception as e:
        print(f"Warning: Could not load settings file: {e}")
    
    # Return defaults
    return {
        "monitor_interface": DEFAULT_MONITOR_INTERFACE,
        "last_updated": None
    }

def get_available_interfaces():
    """Get list of available network interfaces"""
    try:
        interfaces = []
        result = subprocess.run(['ip', 'link', 'show'], capture_output=True, text=True)
        for line in result.stdout.split('\n'):
            if ': ' in line and 'state' in line.lower():
                interface = line.split(':')[1].strip().split('@')[0]
                if interface not in ['lo']:  # Skip loopback
                    interfaces.append(interface)
        return interfaces
    except Exception:
        return ['eth0', 'eth1', 'br-zoplog']  # Fallback defaults

# --- Run ---
def get_default_interface():
    """Get the configured monitoring interface from settings"""
    settings = load_system_settings()
    interface = settings.get("monitor_interface", DEFAULT_MONITOR_INTERFACE)
    
    # Verify interface exists
    available = get_available_interfaces()
    if interface not in available:
        print(f"Warning: Configured interface '{interface}' not found. Available: {available}")
        # Try bridge interface first, then first available
        if "br-zoplog" in available:
            interface = "br-zoplog"
        elif available:
            interface = available[0]
        else:
            interface = "eth0"  # Last resort
    
    return interface

def main():
    interface = get_default_interface()
    print(f"Monitoring HTTP/HTTPS traffic on {interface}...")
    print("Time\tSource\tDestination\tType\tMethod/Host")

    try:
        scapy.sniff(iface=interface, filter="tcp", prn=tcp_packet_handler, store=False)
    except KeyboardInterrupt:
        print("\nMonitoring stopped")
        cursor.close()
        conn.close()
    except Exception as e:
        print(f"Error: {e}")
        cursor.close()
        conn.close()

if __name__ == "__main__":
    main()