#!/usr/bin/env python3

import scapy.all as scapy
from scapy.layers.http import HTTPRequest
from scapy.packet import bind_layers
from scapy.layers.inet import TCP
from datetime import datetime
from config import DB_CONFIG

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
        hostname_id = get_or_insert("hostnames", "hostname", hostname) if hostname else None
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

# --- Packet logging ---
def log_http_request(packet):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')
    http_request = packet[HTTPRequest]

    src_ip, dst_ip = packet[scapy.IP].src, packet[scapy.IP].dst
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac, dst_mac = packet[scapy.Ether].src, packet[scapy.Ether].dst

    method = http_request.Method.decode()
    host = http_request.Host.decode() if http_request.Host else None
    path = http_request.Path.decode() if http_request.Path else None
    user_agent = http_request.User_Agent.decode() if http_request.User_Agent else None
    accept_language = http_request.Accept_Language.decode() if http_request.Accept_Language else None

    print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTP\t{method}\t{host}{path or ''}")
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      method, host, path, user_agent, accept_language, "HTTP")

def extract_tls_sni(packet):
    try:
        if not packet.haslayer(scapy.TCP) or not packet.haslayer(scapy.Raw):
            return None
        payload = packet[scapy.Raw].load
        if payload[0] != 0x16:  # TLS Handshake
            return None
        if payload[5] != 0x01:  # ClientHello
            return None
        sni_marker = b"\x00\x00"
        start = payload.find(sni_marker)
        if start == -1:
            return None
        server_name_len = payload[start+5]
        hostname = payload[start+6:start+6+server_name_len].decode(errors="ignore")
        return hostname
    except Exception:
        return None

def log_https_request(packet):
    ts = datetime.fromtimestamp(float(packet.time)).strftime('%Y-%m-%d %H:%M:%S')

    src_ip, dst_ip = packet[scapy.IP].src, packet[scapy.IP].dst
    src_port, dst_port = packet[scapy.TCP].sport, packet[scapy.TCP].dport
    src_mac, dst_mac = packet[scapy.Ether].src, packet[scapy.Ether].dst

    hostname = extract_tls_sni(packet)

    print(f"{ts}\t{src_ip}:{src_port} ({src_mac})\t{dst_ip}:{dst_port} ({dst_mac})\tHTTPS\t{hostname or 'N/A'}")
    insert_packet_log(ts, src_ip, src_port, dst_ip, dst_port,
                      src_mac, dst_mac,
                      "TLS_CLIENTHELLO", hostname, None, None, None, "HTTPS")

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

# --- Run ---
def get_default_interface():
    try:
        return scapy.conf.iface
    except:
        return 'eth0'

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