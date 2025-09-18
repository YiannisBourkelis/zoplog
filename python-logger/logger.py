#!/usr/bin/env python3

"""
ZopLog Network Packet Logger

This script monitors network traffic and logs HTTP/HTTPS requests for security analysis.
It is designed to be completely stateless - settings are loaded once at startup and
remain static throughout execution. No caching, no reloading, no external state.

Key Features:
- Monitors HTTP traffic on any TCP port
- Extracts SNI from HTTPS/TLS handshakes on any TCP port
- Logs to database with normalized schema
- Integrates with firewall for automatic IP blocking
- Supports whitelist/blacklist functionality

Settings are loaded once at startup from /etc/zoplog/zoplog.conf
To change settings, modify the config file and restart the service.
"""

import scapy.all as scapy
from scapy.layers.http import HTTPRequest
from scapy.packet import bind_layers
from scapy.layers.inet import TCP, UDP
from datetime import datetime
from config import DB_CONFIG, DEFAULT_MONITOR_INTERFACE, SETTINGS_FILE, SCRIPTS_DIR
import subprocess
import json
import os
import struct
import time

# --- DB driver: use PyMySQL ---
import pymysql as mariadb

# --- Ensure HTTP dissector works on all ports ---

bind_layers(TCP, HTTPRequest)

# --- Lightweight TCP flow buffer for TLS ClientHello reassembly ---
# This helps extract SNI when ClientHello spans multiple TCP segments.
FLOW_BUFFER_TTL = 3.0  # seconds to keep partial flows
FLOW_BUFFER_MAXLEN = 8192  # clamp per-flow bytes to avoid memory bloat
_flow_buffers: dict = {}
_flow_last_cleanup = 0.0

def _flow_key(packet):
    try:
        if packet.haslayer(scapy.IP):
            return (packet[scapy.IP].src, packet[scapy.TCP].sport,
                    packet[scapy.IP].dst, packet[scapy.TCP].dport)
        if packet.haslayer(scapy.IPv6):
            return (packet[scapy.IPv6].src, packet[scapy.TCP].sport,
                    packet[scapy.IPv6].dst, packet[scapy.TCP].dport)
    except Exception:
        return None
    return None

def _flow_buffer_append(key, data: bytes):
    now = time.time()
    entry = _flow_buffers.get(key)
    if entry is None:
        entry = {"data": bytearray(), "ts": now}
        _flow_buffers[key] = entry
    entry["data"].extend(data)
    # Clamp to maxlen (keep latest tail)
    if len(entry["data"]) > FLOW_BUFFER_MAXLEN:
        entry["data"] = entry["data"][-FLOW_BUFFER_MAXLEN:]
    entry["ts"] = now

def _flow_buffer_get(key) -> bytes | None:
    entry = _flow_buffers.get(key)
    if not entry:
        return None
    return bytes(entry["data"])

def _flow_buffer_clear(key):
    if key in _flow_buffers:
        try:
            del _flow_buffers[key]
        except Exception:
            pass

def _flow_buffer_cleanup():
    global _flow_last_cleanup
    now = time.time()
    # avoid doing it too often
    if now - _flow_last_cleanup < 1.0:
        return
    _flow_last_cleanup = now
    stale = [k for k, v in list(_flow_buffers.items()) if now - v.get("ts", 0) > FLOW_BUFFER_TTL]
    for k in stale:
        try:
            del _flow_buffers[k]
        except Exception:
            pass

# --- DNS cache for QUIC hostname inference ---
DNS_CACHE_TTL = 120.0
_dns_cache = {}  # key: (client_ip, server_ip) -> {host, ts}
_dns_last_cleanup = 0.0
_seen_quic_flows = {}   # key: (client_ip, sport, server_ip, dport) -> ts

def _dns_put(client_ip: str, server_ip: str, host: str):
    if not client_ip or not server_ip or not host:
        return
    _dns_cache[(client_ip, server_ip)] = {"host": host.lower().rstrip('.'), "ts": time.time()}

def _dns_get(client_ip: str, server_ip: str) -> str | None:
    rec = _dns_cache.get((client_ip, server_ip))
    if not rec:
        return None
    if time.time() - rec.get("ts", 0) > DNS_CACHE_TTL:
        try:
            del _dns_cache[(client_ip, server_ip)]
        except Exception:
            pass
        return None
    return rec.get("host")

def _dns_cleanup():
    global _dns_last_cleanup
    now = time.time()
    if now - _dns_last_cleanup < 5.0:
        return
    _dns_last_cleanup = now
    stale = [k for k, v in list(_dns_cache.items()) if now - v.get("ts", 0) > DNS_CACHE_TTL]
    for k in stale:
        try:
            del _dns_cache[k]
        except Exception:
            pass
    stale2 = [k for k, ts in list(_seen_quic_flows.items()) if now - ts > DNS_CACHE_TTL]
    for k in stale2:
        try:
            del _seen_quic_flows[k]
        except Exception:
            pass

def _process_dns_packet(packet, settings):
    try:
        if not packet.haslayer(scapy.DNS):
            return
        dns = packet[scapy.DNS]
        if dns.qr != 1:
            return
        cip, sip = _get_ips(packet)
        # Iterate answer RRs
        for i in range(dns.ancount):
            rr = dns.an[i]
            if rr.type in (1, 28):  # A or AAAA
                host = rr.rrname.decode('utf-8', errors='ignore') if isinstance(rr.rrname, bytes) else str(rr.rrname)
                ip = rr.rdata if isinstance(rr.rdata, str) else None
                if host and ip and cip:
                    _dns_put(cip, ip, host)
    except Exception as e:
        log_level = settings.get("log_level", "INFO").upper()
        if log_level in ("DEBUG", "ALL"):
            print(f"DNS parse error: {e}")

# (DNS cache removed by user request)

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

def get_or_insert_domain_with_ip(domain, ip_id):
    """Insert domain with IP relationship or get existing one, updating relationship if needed"""
    if not domain:
        return None

    conn, cursor = get_db_connection()
    # First check if domain exists
    cursor.execute("SELECT id FROM domains WHERE domain = %s", (domain,))
    result = cursor.fetchone()

    if result:
        domain_id = result[0]
        # Check if IP relationship exists in pivot table
        cursor.execute("SELECT id FROM domain_ip_addresses WHERE domain_id = %s AND ip_address_id = %s", (domain_id, ip_id))
        if not cursor.fetchone() and ip_id:
            # Create relationship if it doesn't exist
            cursor.execute(
                "INSERT INTO domain_ip_addresses (domain_id, ip_address_id) VALUES (%s, %s)",
                (domain_id, ip_id)
            )
        conn.commit()
        return domain_id
    else:
        # Insert new domain
        cursor.execute(
            "INSERT INTO domains (domain) VALUES (%s)",
            (domain,)
        )
        domain_id = cursor.lastrowid

        # Create IP relationship if IP provided
        if ip_id:
            cursor.execute(
                "INSERT INTO domain_ip_addresses (domain_id, ip_address_id) VALUES (%s, %s)",
                (domain_id, ip_id)
            )

        conn.commit()
        return domain_id

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
        domain_id = get_or_insert_domain_with_ip(hostname, dst_ip_id) if hostname else None
        path_id = get_or_insert("paths", "path", path, cursor=cursor) if path else None
        user_agent_id = get_or_insert("user_agents", "user_agent", user_agent, cursor=cursor) if user_agent else None
        accept_language_id = get_or_insert("accept_languages", "accept_language", accept_language, cursor=cursor) if accept_language else None

        cursor.execute("""
            INSERT INTO packet_logs
            (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
             src_mac_id, dst_mac_id,
             method, domain_id, path_id, user_agent_id, accept_language_id, type)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """, (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
              src_mac_id, dst_mac_id,
              method, domain_id, path_id, user_agent_id, accept_language_id, pkt_type))

        # Increment allowed_count in domain_ip_addresses table if we have both domain_id and dst_ip_id
        if domain_id and dst_ip_id:
            cursor.execute("""
                UPDATE domain_ip_addresses
                SET allowed_count = allowed_count + 1, last_seen = NOW()
                WHERE domain_id = %s AND ip_address_id = %s
            """, (domain_id, dst_ip_id))

        conn.commit()

    except mariadb.Error as e:
        if "MySQL server has gone away" in str(e):
            print("DB connection lost, reconnecting...")
            try:
                conn = mariadb.connect(**DB_CONFIG)
                cursor = conn.cursor()
                # Retry the insert with fresh connection
                cursor.execute("""
                    INSERT INTO packet_logs
                    (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
                     src_mac_id, dst_mac_id,
                     method, domain_id, path_id, user_agent_id, accept_language_id, type)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """, (packet_timestamp, src_ip_id, src_port, dst_ip_id, dst_port,
                      src_mac_id, dst_mac_id,
                      method, domain_id, path_id, user_agent_id, accept_language_id, pkt_type))

                # Increment allowed_count in domain_ip_addresses table if we have both domain_id and dst_ip_id
                if domain_id and dst_ip_id:
                    cursor.execute("""
                        UPDATE domain_ip_addresses
                        SET allowed_count = allowed_count + 1, last_seen = NOW()
                        WHERE domain_id = %s AND ip_address_id = %s
                    """, (domain_id, dst_ip_id))

                conn.commit()
            except Exception as e2:
                print(f"Packet log insert failed after reconnect: {e2}")
        else:
            print(f"Packet log insert error: {e}")
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


def find_matching_blocklist_domains(host: str, settings: dict):
    """Return list of tuples (blocklist_id, blocklist_domain_id) for exact host matches in active blocklists."""
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


def is_host_whitelisted(host: str, settings: dict):
    """Return True if host is in any active whitelist."""
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
    # This function is no longer needed since blocked_ips table was removed
    pass


def ipset_add_ip(blocklist_id: int, ip: str, blocklist_domain_id: int | None = None, settings: dict = None):
    """Add IP to nft set for blocklist and record it in DB linked to the specific domain when provided."""
    if settings is None:
        settings = load_system_settings()
        
    debug_print(f"DEBUG: ipset_add_ip called with blocklist_id={blocklist_id}, ip={ip}, domain_id={blocklist_domain_id}", settings=settings)
    
    # First try to apply firewall change
    try:
        # Resolve script path (prefer installed scripts dir, fallback to production path)
        candidate_path = os.path.join(SCRIPTS_DIR, "zoplog-firewall-ipset-add")
        script_path = candidate_path if os.path.exists(candidate_path) else "/opt/zoplog/zoplog/scripts/zoplog-firewall-ipset-add"

        # 1) Try direct execution first (service typically has CAP_NET_ADMIN)
        direct_cmd = [script_path, str(blocklist_id), ip]
        debug_print(f"DEBUG: Executing (direct): {' '.join(direct_cmd)}", settings=settings)
        result = subprocess.run(
            direct_cmd,
            capture_output=True,
            text=True,
            timeout=3,
        )

        debug_print(f"DEBUG: Direct command completed - returncode={result.returncode}", settings=settings)
        debug_print(f"DEBUG: stdout: {repr(result.stdout)}", settings=settings)
        debug_print(f"DEBUG: stderr: {repr(result.stderr)}", settings=settings)

        if result.returncode == 0:
            debug_print(f"SUCCESS: ipset add (direct) completed for id={blocklist_id} ip={ip}", settings=settings)
        else:
            # 2) Fall back to sudo -n if direct execution failed (e.g., missing capability)
            sudo_cmd = ["/usr/bin/sudo", "-n", script_path, str(blocklist_id), ip]
            debug_print(f"DEBUG: Direct failed (rc={result.returncode}). Falling back to sudo: {' '.join(sudo_cmd)}", settings=settings)
            result2 = subprocess.run(
                sudo_cmd,
                capture_output=True,
                text=True,
                timeout=3,
            )

            debug_print(f"DEBUG: Sudo command completed - returncode={result2.returncode}", settings=settings)
            debug_print(f"DEBUG: sudo stdout: {repr(result2.stdout)}", settings=settings)
            debug_print(f"DEBUG: sudo stderr: {repr(result2.stderr)}", settings=settings)

            if result2.returncode != 0:
                err = (result2.stderr or '').strip()
                print(f"ERROR: ipset add failed (sudo) rc={result2.returncode} id={blocklist_id} ip={ip} stderr={err}")
            else:
                debug_print(f"SUCCESS: ipset add (sudo) completed for id={blocklist_id} ip={ip}", settings=settings)
            
    except subprocess.TimeoutExpired:
        print(f"ERROR: ipset add timed out id={blocklist_id} ip={ip}")
    except Exception as e:
        print(f"ERROR: ipset add exception id={blocklist_id} ip={ip} err={e}")

    # Independently record in DB (even if firewall fails, for observability)
    if blocklist_domain_id:
        debug_print(f"DEBUG: Database recording skipped (blocked_ips table removed) for domain_id={blocklist_domain_id}", settings=settings)
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


def log_http_request(packet, settings: dict):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')
    http_request = packet[HTTPRequest]

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    method = http_request.Method.decode()
    # Validate method against known HTTP methods, map unknown ones to N/A
    known_methods = {'GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH', 'CONNECT', 'TRACE', 'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK', 'N/A', 'TLS_CLIENTHELLO'}
    if method not in known_methods:
        method = 'N/A'
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

    # Whitelist overrides blacklist: if host is whitelisted, do nothing
    if host and is_host_whitelisted(host, settings):
        debug_print(f"DEBUG: HTTP host {host} is whitelisted, skipping blocking", settings=settings)
        return

    # If host matches any active blocklist and is not whitelisted, add destination IP to corresponding set(s) and record it
    try:
        if host and dst_ip:
            matching_domains = find_matching_blocklist_domains(host, settings)
            if matching_domains:
                debug_print(f"DEBUG: HTTP host {host} matches {len(matching_domains)} blocklist(s), blocking IP {dst_ip}", settings=settings)
                for bl_id, bd_id in matching_domains:
                    ipset_add_ip(bl_id, dst_ip, bd_id, settings)
            else:
                debug_print(f"DEBUG: HTTP host {host} does not match any active blocklists", settings=settings)
    except Exception as e:
        print(f"error during ipset add for HTTP host={host} ip={dst_ip}: {e}")

def parse_sni_from_bytes(payload: bytes) -> str | None:
    """Parse SNI hostname from a TLS ClientHello given raw bytes.
    Returns lowercase hostname or None if not found/invalid.
    """
    try:
        TLS_HANDSHAKE = 0x16
        CLIENT_HELLO = 0x01
        SNI_EXTENSION = 0x0000

        if not payload or len(payload) < 60:
            return None

        # TLS record header and handshake type
        if payload[0] != TLS_HANDSHAKE or payload[5] != CLIENT_HELLO:
            return None

        idx = 9  # after record(5) + hs hdr(4)
        idx += 34  # version + random

        if idx >= len(payload):
            return None
        sid_len = payload[idx]
        idx += 1 + sid_len

        if idx + 2 > len(payload):
            return None
        cs_len = int.from_bytes(payload[idx:idx+2], 'big')
        idx += 2 + cs_len

        if idx >= len(payload):
            return None
        comp_len = payload[idx]
        idx += 1 + comp_len

        if idx + 2 > len(payload):
            return None
        ext_total_len = int.from_bytes(payload[idx:idx+2], 'big')
        idx += 2
        ext_end = idx + ext_total_len
        if ext_end > len(payload):
            return None

        while idx + 4 <= ext_end:
            ext_type = int.from_bytes(payload[idx:idx+2], 'big')
            ext_len = int.from_bytes(payload[idx+2:idx+4], 'big')
            ext_data_start = idx + 4
            ext_data_end = ext_data_start + ext_len
            if ext_data_end > ext_end:
                break
            if ext_type == SNI_EXTENSION:
                sni_idx = ext_data_start
                if ext_len < 5:
                    break
                sni_idx += 2  # name list len
                if sni_idx + 3 > ext_data_end:
                    break
                name_type = payload[sni_idx]
                if name_type == 0x00:
                    name_len = int.from_bytes(payload[sni_idx+1:sni_idx+3], 'big')
                    name_start = sni_idx + 3
                    if name_start + name_len <= ext_data_end:
                        hostname_bytes = payload[name_start:name_start+name_len]
                        try:
                            hostname = hostname_bytes.decode('utf-8', errors='ignore').strip()
                            if hostname and '.' in hostname and len(hostname) <= 253:
                                return hostname.lower().rstrip('.')
                        except UnicodeDecodeError:
                            return None
                break
            idx = ext_data_end
        return None
    except Exception:
        return None


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
        return parse_sni_from_bytes(payload)
    except Exception:
        # Handle malformed packets gracefully
        return None

def log_https_request(packet, settings: dict, hostname: str | None = None):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    if hostname is None:
        hostname = extract_tls_sni(packet)

    # Debug logging for SNI extraction issues
    log_level = settings.get("log_level", "INFO").upper()
    if log_level in ("DEBUG", "ALL"):
        if not hostname and dst_port == 443:
            debug_print(f"DEBUG: Failed to extract SNI from HTTPS packet {src_ip}:{src_port} -> {dst_ip}:{dst_port}, payload size: {len(bytes(packet[scapy.Raw].load)) if packet.haslayer(scapy.Raw) else 0}", settings=settings)
        print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTPS\t{hostname or 'N/A'}")
    
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      "TLS_CLIENTHELLO", hostname, None, None, None, "HTTPS")

    # Whitelist overrides blacklist: if host is whitelisted, do nothing
    if hostname and is_host_whitelisted(hostname, settings):
        debug_print(f"DEBUG: HTTPS hostname {hostname} is whitelisted, skipping blocking", settings=settings)
        return

    # If SNI matches any active blocklist and is not whitelisted, add destination IP to corresponding set(s) and record it
    try:
        if hostname and dst_ip:
            matching_domains = find_matching_blocklist_domains(hostname, settings)
            if matching_domains:
                debug_print(f"DEBUG: HTTPS hostname {hostname} matches {len(matching_domains)} blocklist(s), blocking IP {dst_ip}", settings=settings)
                for bl_id, bd_id in matching_domains:
                    ipset_add_ip(bl_id, dst_ip, bd_id, settings)
            else:
                debug_print(f"DEBUG: HTTPS hostname {hostname} does not match any active blocklists", settings=settings)
    except Exception as e:
        print(f"error during ipset add for HTTPS host={hostname} ip={dst_ip}: {e}")

# QUIC logging using DNS-inferred hostname
def log_https_quic_request(packet, settings: dict, hostname: str | None = None):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')

    src_ip, dst_ip = _get_ips(packet)
    src_port, dst_port = (packet[scapy.UDP].sport if packet.haslayer(scapy.UDP) else None,
                          packet[scapy.UDP].dport if packet.haslayer(scapy.UDP) else None)
    src_mac = packet[scapy.Ether].src if packet.haslayer(scapy.Ether) else None
    dst_mac = packet[scapy.Ether].dst if packet.haslayer(scapy.Ether) else None

    log_level = settings.get("log_level", "INFO").upper()
    if log_level in ("DEBUG", "ALL"):
        print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTPS_QUIC\t{hostname or 'N/A'}")

    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      "QUIC", hostname, None, None, None, "HTTPS")

    # Whitelist overrides blacklist: if host is whitelisted, do nothing
    if hostname and is_host_whitelisted(hostname, settings):
        debug_print(f"DEBUG: QUIC hostname {hostname} is whitelisted, skipping blocking", settings=settings)
        return

    try:
        if hostname and dst_ip:
            matching_domains = find_matching_blocklist_domains(hostname, settings)
            if matching_domains:
                debug_print(f"DEBUG: QUIC hostname {hostname} matches {len(matching_domains)} blocklist(s), blocking IP {dst_ip}", settings=settings)
                for bl_id, bd_id in matching_domains:
                    ipset_add_ip(bl_id, dst_ip, bd_id, settings)
            else:
                debug_print(f"DEBUG: QUIC hostname {hostname} does not match any active blocklists", settings=settings)
    except Exception as e:
        print(f"error during ipset add for HTTPS_QUIC host={hostname} ip={dst_ip}: {e}")

# (QUIC logging removed by user request)

def tcp_packet_handler(packet, settings):
    """
    Process TCP packets and extract HTTP/HTTPS traffic on ANY port.
    This function checks for HTTP requests and HTTPS SNI on all ports,
    not just standard ports (80, 443, etc.).
    """
    try:
        # Check for HTTP traffic on any port
        if packet.haslayer(HTTPRequest):
            debug_print(f"DEBUG: HTTP packet detected", settings=settings)
            log_http_request(packet, settings)
            return

        # Check for HTTPS traffic (TLS with SNI) on any port
        if settings.get("enable_sni_extraction", True):
            hostname = extract_tls_sni(packet)
            if hostname:
                debug_print(f"DEBUG: HTTPS packet detected with SNI: {hostname}", settings=settings)
                log_https_request(packet, settings, hostname)
                return

            # Attempt simple reassembly using flow buffer
            if packet.haslayer(scapy.Raw) and packet.haslayer(scapy.TCP):
                key = _flow_key(packet)
                if key:
                    _flow_buffer_append(key, bytes(packet[scapy.Raw].load))
                    buf = _flow_buffer_get(key)
                    host2 = parse_sni_from_bytes(buf) if buf else None
                    if host2:
                        debug_print(f"DEBUG: HTTPS packet detected via reassembly with SNI: {host2}", settings=settings)
                        log_https_request(packet, settings, host2)
                        _flow_buffer_clear(key)
                        return

        # Cleanup old flow buffers occasionally
        _flow_buffer_cleanup()

        # Optional: Log other TCP traffic in debug mode for analysis
        log_level = settings.get("log_level", "INFO").upper()
        if log_level in ("DEBUG", "ALL") and packet.haslayer(scapy.TCP):
            dst_port = packet[scapy.TCP].dport
            src_port = packet[scapy.TCP].sport
            src_ip, dst_ip = _get_ips(packet)
            # debug_print(f"DEBUG: Non-HTTP/HTTPS TCP packet: {src_ip}:{src_port} -> {dst_ip}:{dst_port}", settings=settings)

    except Exception as e:
        # Log errors in debug mode only to avoid spam
        log_level = settings.get("log_level", "INFO").upper()
        if log_level in ("DEBUG", "ALL"):
            print(f"Packet processing error: {e}")

def load_system_settings():
    """Load system settings from centralized config file - called once at startup"""
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
        "last_updated": None,
        "enable_sni_extraction": True,
        "enable_non_standard_ports": True,
        "log_level": "INFO"
    }

def debug_print(*args, settings: dict = None, **kwargs):
    """Conditional debug print - only prints if debug logging is enabled"""
    if settings is None:
        # Fallback for functions that don't have settings passed
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
    """Main function - settings are loaded once at startup and remain static"""
    # Load settings once at startup - no caching, no reloading
    settings = load_system_settings()
    
    interface = get_default_interface()
    print(f"Monitoring HTTP/HTTPS traffic on {interface}...")
    print("Time\tSource\tDestination\tType\tMethod/Host")

    # Create a packet handler that captures the settings
    def packet_handler_with_settings(packet):
        try:
            # Update DNS cache from responses
            if packet.haslayer(scapy.DNS):
                _process_dns_packet(packet, settings)
                _dns_cleanup()
            # TCP handling (HTTP/HTTPS)
            if packet.haslayer(scapy.TCP):
                return tcp_packet_handler(packet, settings)
            # QUIC handling via DNS inference
            if packet.haslayer(scapy.UDP):
                udp = packet[scapy.UDP]
                if udp.dport == 443 or udp.sport == 443:
                    src_ip, dst_ip = _get_ips(packet)
                    sport, dport = udp.sport, udp.dport
                    flow = (src_ip, sport, dst_ip, dport)
                    if flow not in _seen_quic_flows:
                        host = _dns_get(src_ip, dst_ip)
                        if host:
                            log_https_quic_request(packet, settings, host)
                            _seen_quic_flows[flow] = time.time()
                            return
        except Exception as e:
            log_level = settings.get("log_level", "INFO").upper()
            if log_level in ("DEBUG", "ALL"):
                print(f"handler error: {e}")

    try:
        # Capture TCP for HTTP/HTTPS, UDP:53 for DNS, UDP:443 for QUIC
        scapy.sniff(iface=interface, filter="tcp or udp port 53 or udp port 443", prn=packet_handler_with_settings, store=False)
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