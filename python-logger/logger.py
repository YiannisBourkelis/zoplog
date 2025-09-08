#!/usr/bin/env python3

import scapy.all as scapy
from scapy.layers.http import HTTPRequest
from scapy.packet import bind_layers
from scapy.layers.inet import TCP
from datetime import datetime
from config import DB_CONFIG, DEFAULT_MONITOR_INTERFACE, SETTINGS_FILE, SCRIPTS_DIR
import subprocess
import json
import os
import struct

# --- DB driver fallback (mysql-connector or PyMySQL) ---
try:
    import pymysql as mariadb
except ImportError:
    import mysql.connector as mariadb

# --- Ensure HTTP dissector works on all ports ---
bind_layers(TCP, HTTPRequest)

# --- Global connection with better error handling ---
# Module-level connection placeholders to avoid NameError when checking/using
# the globals inside get_db_connection() before they've been initialized.
conn = None
cursor = None
def get_db_connection():
    """Get database connection with automatic reconnection"""
    global conn, cursor
    try:
        # If conn is falsy or closed, attempt to (re)connect.
        if not conn or (hasattr(conn, 'open') and not conn.open):
            conn = mariadb.connect(**DB_CONFIG)
            cursor = conn.cursor()
        return conn, cursor
    except Exception as e:
        # More explicit error for easier debugging
        print(f"Database connection error while connecting to {DB_CONFIG.get('host')}:{DB_CONFIG.get('database')}: {e}")
        try:
            conn = mariadb.connect(**DB_CONFIG)
            cursor = conn.cursor()
            return conn, cursor
        except Exception as e2:
            print(f"Failed to reconnect to database {DB_CONFIG.get('host')}/{DB_CONFIG.get('database')}: {e2}")
            raise

# Note: do not initialize the DB connection at import time. We use a
# lazy/deferred connection via get_db_connection() so the module can be
# imported for testing or for operations that don't need the DB.

# --- DB helpers ---
def get_or_insert(table, column, value, cursor=None):
    """Insert value into table.column and return lastrowid. Caller may
    supply a cursor to avoid repeated connection checks.
    """
    if not value:
        return None
    if cursor is None:
        conn, cursor = get_db_connection()
    try:
        cursor.execute(
            f"INSERT INTO {table} ({column}) VALUES (%s) "
            f"ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            (value,)
        )
        return cursor.lastrowid
    except Exception:
        # Let caller handle errors; return None for safety
        return None

def get_or_insert_hostname_with_ip(hostname, ip_id):
    """Insert hostname with IP relationship or get existing one, updating IP if needed"""
    if not hostname:
        return None
    
    conn, cursor = get_db_connection()
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

def get_or_insert_ip(ip_address, cursor=None):
    return get_or_insert("ip_addresses", "ip_address", ip_address, cursor=cursor)


def get_or_insert_mac(mac_address, cursor=None):
    return get_or_insert("mac_addresses", "mac_address", mac_address, cursor=cursor)

def insert_packet_log(packet_timestamp, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac, method, hostname, path, user_agent,
                      accept_language, pkt_type):
    """Insert normalized packet log; reconnect if server has gone away"""
    global conn, cursor
    try:
        # Ensure we have a valid connection
        conn, cursor = get_db_connection()

        # Reuse the same cursor for all helper operations to minimize
        # connection/cursor churn when recording a packet
        src_ip_id = get_or_insert_ip(src_ip, cursor=cursor) if src_ip else None
        dst_ip_id = get_or_insert_ip(dst_ip, cursor=cursor) if dst_ip else None
        src_mac_id = get_or_insert_mac(src_mac, cursor=cursor) if src_mac else None
        dst_mac_id = get_or_insert_mac(dst_mac, cursor=cursor) if dst_mac else None
        hostname_id = get_or_insert_hostname_with_ip(hostname, dst_ip_id) if hostname else None
        path_id = get_or_insert("paths", "path", path, cursor=cursor) if path else None
        user_agent_id = get_or_insert("user_agents", "user_agent", user_agent, cursor=cursor) if user_agent else None
        accept_language_id = get_or_insert("accept_languages", "accept_language", accept_language, cursor=cursor) if accept_language else None

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
            try:
                conn = mariadb.connect(**DB_CONFIG)
                cursor = conn.cursor()
                insert_packet_log(packet_timestamp, src_ip, src_port, dst_ip, dst_port,
                                  src_mac, dst_mac, method, hostname, path, user_agent,
                                  accept_language, pkt_type)
            except Exception as e2:
                print(f"Failed to insert packet log after reconnect: {e2}")
        else:
            print(f"Database error inserting packet log: {e}")
    except Exception as e:
        print(f"Unexpected error inserting packet log: {e}")

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
    # Use lazy connection
    global conn
    h = _normalize_hostname(host)
    if not h:
        return []
    try:
        conn, cur = get_db_connection()
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


def find_matching_blocklist_domains(host: str, settings: dict = None):
    """Return list of tuples (blocklist_id, blocklist_domain_id) for exact host matches in active blocklists."""
    if settings is None:
        settings = load_system_settings()
        
    h = _normalize_hostname(host)
    if not h:
        return []
    
    try:
        conn, cur = get_db_connection()
        query = (
            "SELECT bd.blocklist_id, bd.id AS blocklist_domain_id "
            "FROM blocklist_domains bd "
            "JOIN blocklists bl ON bl.id = bd.blocklist_id "
            "WHERE bl.active = 'active' AND bd.domain = %s"
        )
        cur.execute(query, (h,))
        rows = cur.fetchall()
        return [(row[0], row[1]) for row in rows]
    except Exception as e:
        log_level = settings.get("log_level", "INFO").upper()
        if log_level in ("DEBUG", "ALL"):
            print(f"Warning: Blocklist lookup failed for {h}: {e}")
        return []


def is_host_whitelisted(host: str, settings: dict = None):
    """Return True if host is in any active whitelist."""
    if settings is None:
        settings = load_system_settings()
        
    h = _normalize_hostname(host)
    if not h:
        return False
    
    try:
        conn, cur = get_db_connection()
        query = (
            "SELECT 1 "
            "FROM whitelist_domains wd "
            "JOIN whitelists wl ON wl.id = wd.whitelist_id "
            "WHERE wl.active = 'active' AND wd.domain = %s "
            "LIMIT 1"
        )
        cur.execute(query, (h,))
        row = cur.fetchone()
        return row is not None
    except Exception as e:
        log_level = settings.get("log_level", "INFO").upper()
        if log_level in ("DEBUG", "ALL"):
            print(f"Warning: Whitelist lookup failed for {h}: {e}")
        return False


def _record_blocked_ip(blocklist_domain_id: int, ip: str):
    """Insert or update the blocked IP record linked to a specific blocklist_domain row."""
    global conn, cursor
    if not ip or not blocklist_domain_id:
        return
    try:
        conn, cursor = get_db_connection()
        ip_id = get_or_insert_ip(ip, cursor=cursor)
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
                conn, cursor = get_db_connection()
                _record_blocked_ip(blocklist_domain_id, ip)
            except Exception as e2:
                print(f"blocked_ips insert failed after reconnect: {e2}")
        else:
            print(f"blocked_ips insert error: {e}")


def ipset_add_ip(blocklist_id: int, ip: str, blocklist_domain_id: int | None = None, settings: dict = None):
    """Add IP to nft set for blocklist and record it in DB linked to the specific domain when provided."""
    if settings is None:
        settings = load_system_settings()
        
    debug_print(f"DEBUG: ipset_add_ip called with blocklist_id={blocklist_id}, ip={ip}, domain_id={blocklist_domain_id}", settings=settings)
    
    # First try to apply firewall change
    try:
        # Use scripts from relative path instead of /usr/local/sbin/
        script_path = os.path.join(SCRIPTS_DIR, "zoplog-firewall-ipset-add")
        # Don't use sudo since the script has setuid bit
        cmd = [script_path, str(blocklist_id), ip]
        debug_print(f"DEBUG: Executing command: {' '.join(cmd)}", settings=settings)
        
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=2,
        )
        
        debug_print(f"DEBUG: Command completed - returncode={result.returncode}", settings=settings)
        debug_print(f"DEBUG: stdout: {repr(result.stdout)}", settings=settings)
        debug_print(f"DEBUG: stderr: {repr(result.stderr)}", settings=settings)
        
        if result.returncode != 0:
            err = (result.stderr or '').strip()
            print(f"ERROR: ipset add failed rc={result.returncode} id={blocklist_id} ip={ip} stderr={err}")
        else:
            debug_print(f"SUCCESS: ipset add completed successfully for id={blocklist_id} ip={ip}", settings=settings)
            
    except subprocess.TimeoutExpired:
        print(f"ERROR: ipset add timed out id={blocklist_id} ip={ip}")
    except Exception as e:
        print(f"ERROR: ipset add exception id={blocklist_id} ip={ip} err={e}")

    # Independently record in DB (even if firewall fails, for observability)
    if blocklist_domain_id:
        try:
            debug_print(f"DEBUG: Recording blocked IP in database for domain_id={blocklist_domain_id}", settings=settings)
            _record_blocked_ip(blocklist_domain_id, ip)
            debug_print(f"DEBUG: Successfully recorded blocked IP in database", settings=settings)
        except Exception as e:
            print(f"ERROR: record blocked_ip error domain_id={blocklist_domain_id} ip={ip} err={e}")
    else:
        debug_print(f"DEBUG: No blocklist_domain_id provided, skipping database record", settings=settings)

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


def log_http_request(packet, settings: dict = None):
    if settings is None:
        settings = load_system_settings()
        
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

    # Only print if INFO level or higher
    log_level = settings.get("log_level", "INFO").upper()
    if log_level in ("DEBUG", "ALL"):
        print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTP\t{method}\t{host}{path or ''}")
    
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      method, host, path, user_agent, accept_language, "HTTP")

    # If host matches any active blocklist and is not whitelisted, add destination IP to corresponding set(s) and record it
    try:
        if host and dst_ip and not is_host_whitelisted(host, settings):
            for bl_id, bd_id in find_matching_blocklist_domains(host, settings):
                ipset_add_ip(bl_id, dst_ip, bd_id, settings)
    except Exception as e:
        print(f"error during ipset add for HTTP host={host} ip={dst_ip}: {e}")

def extract_tls_sni(packet):
    """
    Parse a TLS ClientHello packet to extract the Server Name Indication (SNI)
    hostname with correct TLS structure parsing.

    This function properly handles the TLS ClientHello structure and SNI extension format.
    
        SNI Extension:
        ├── Extension Type (2 bytes): 0x0000
        ├── Extension Length (2 bytes)
        └── SNI Data:
            ├── Server Name List Length (2 bytes) ← This was missing!
            ├── Server Name Type (1 byte): 0x00
            ├── Server Name Length (2 bytes)
            └── Server Name (variable)
    """
    try:
        # --- 1. Initial Validation ---
        if not packet.haslayer(scapy.TCP) or not packet.haslayer(scapy.Raw):
            return None

        payload = bytes(packet[scapy.Raw].load)

        # Constants for TLS parsing
        TLS_HANDSHAKE = 0x16
        CLIENT_HELLO = 0x01
        SNI_EXTENSION = 0x0000

        # Minimum ClientHello size check
        if len(payload) < 80:  # Realistic minimum for ClientHello with SNI
            return None

        # Check for TLS Handshake (ContentType 22) and ClientHello (type 1)
        if payload[0] != TLS_HANDSHAKE or payload[5] != CLIENT_HELLO:
            return None

        # --- 2. Parse TLS Record and Handshake Headers ---
        # TLS Record Header: ContentType (1) + Version (2) + Length (2) = 5 bytes
        # Handshake Header: Type (1) + Length (3) = 4 bytes
        # Total fixed header: 9 bytes

        # ClientHello structure:
        # - Version (2 bytes)
        # - Random (32 bytes)
        # - Session ID Length (1 byte)
        # - Session ID (variable)
        # - Cipher Suites Length (2 bytes)
        # - Cipher Suites (variable)
        # - Compression Methods Length (1 byte)
        # - Compression Methods (variable)
        # - Extensions Length (2 bytes)
        # - Extensions (variable)

        idx = 9  # Start after fixed headers

        # Skip Version (2) + Random (32) = 34 bytes total from start
        idx += 34

        # Skip Session ID (1-byte length + variable data)
        if idx >= len(payload):
            return None
        session_id_len = payload[idx]
        idx += 1 + session_id_len

        # Skip Cipher Suites (2-byte length + variable data)
        if idx + 2 > len(payload):
            return None
        cipher_suites_len = int.from_bytes(payload[idx:idx+2], 'big')
        idx += 2 + cipher_suites_len

        # Skip Compression Methods (1-byte length + variable data)
        if idx >= len(payload):
            return None
        compression_len = payload[idx]
        idx += 1 + compression_len

        # --- 3. Parse Extensions ---
        if idx + 2 > len(payload):
            return None

        extensions_len = int.from_bytes(payload[idx:idx+2], 'big')
        idx += 2
        extensions_end = idx + extensions_len

        if extensions_end > len(payload):
            return None

        # --- 4. Find and Parse SNI Extension ---
        while idx + 4 <= extensions_end:
            ext_type = int.from_bytes(payload[idx:idx+2], 'big')
            ext_len = int.from_bytes(payload[idx+2:idx+4], 'big')
            ext_data_start = idx + 4
            ext_data_end = ext_data_start + ext_len

            if ext_data_end > extensions_end:
                break

            if ext_type == SNI_EXTENSION:
                # Parse SNI extension data
                sni_idx = ext_data_start

                # SNI Extension Structure:
                # - Server Name List Length (2 bytes)
                # - Server Name Type (1 byte) - should be 0x00 for hostname
                # - Server Name Length (2 bytes)
                # - Server Name (variable)

                if ext_len < 5:  # Minimum SNI structure
                    break

                # Skip Server Name List Length (2 bytes) - usually just the length of the first name entry
                sni_idx += 2

                if sni_idx + 3 > ext_data_end:
                    break

                name_type = payload[sni_idx]
                if name_type == 0x00:  # Type 0 = hostname
                    name_len = int.from_bytes(payload[sni_idx+1:sni_idx+3], 'big')
                    name_start = sni_idx + 3

                    if name_start + name_len <= ext_data_end:
                        hostname_bytes = payload[name_start:name_start+name_len]
                        try:
                            hostname = hostname_bytes.decode('utf-8', errors='ignore').strip()
                            if hostname:
                                return hostname.lower().rstrip('.')
                        except UnicodeDecodeError:
                            pass
                break  # Found SNI extension, no need to continue

            # Move to next extension
            idx = ext_data_end

        return None

    except (IndexError, ValueError):
        # Handle malformed packets gracefully
        return None
    except Exception:
        # Catch any other unexpected errors
        return None

def log_https_request(packet, settings: dict = None):
    if settings is None:
        settings = load_system_settings()
        
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    hostname = extract_tls_sni(packet)

    # Only print if INFO level or higher
    log_level = settings.get("log_level", "INFO").upper()
    if log_level in ("DEBUG", "ALL"):
        print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTPS\t{hostname or 'N/A'}")
    
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      "TLS_CLIENTHELLO", hostname, None, None, None, "HTTPS")

    # If SNI matches any active blocklist and is not whitelisted, add destination IP to corresponding set(s) and record it
    try:
        if hostname and dst_ip and not is_host_whitelisted(hostname, settings):
            for bl_id, bd_id in find_matching_blocklist_domains(hostname, settings):
                ipset_add_ip(bl_id, dst_ip, bd_id, settings)
    except Exception as e:
        print(f"error during ipset add for HTTPS host={hostname} ip={dst_ip}: {e}")

def tcp_packet_handler(packet):
    try:
        # Load settings once per packet batch for performance
        settings = load_system_settings()
        
        if packet.haslayer(HTTPRequest):
            log_http_request(packet, settings)
        else:
            hostname = extract_tls_sni(packet)
            if hostname:
                log_https_request(packet, settings)
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

def debug_print(*args, settings: dict = None, **kwargs):
    """Conditional debug print - only prints if debug logging is enabled"""
    if settings is None:
        settings = load_system_settings()
    
    log_level = settings.get("log_level", "INFO").upper()
    if log_level in ("DEBUG", "ALL"):
        print(*args, **kwargs)

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