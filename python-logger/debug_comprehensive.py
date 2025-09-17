#!/usr/bin/env python3

"""
Comprehensive debug script to test the exact whitelist logic as it would run in the logger
"""

import sys
import os
sys.path.append(os.path.dirname(__file__))

from config import DB_CONFIG
from zoplog_config import load_settings_config
import pymysql as mariadb

def _normalize_hostname(host: str) -> str:
    """Copy of the normalization function from logger.py"""
    if not host:
        return ""
    # Strip port if present (Host header may include it)
    host = host.strip()
    if ':' in host:
        host = host.split(':', 1)[0]
    # Lowercase and remove trailing dot
    return host.lower().rstrip('.')

def is_host_whitelisted(host: str, settings: dict):
    """Copy of the whitelist function from logger.py"""
    h = _normalize_hostname(host)
    if not h:
        return False

    try:
        conn = mariadb.connect(**DB_CONFIG)
        cur = conn.cursor()
        query = (
            "SELECT 1 "
            "FROM whitelist_domains wd "
            "JOIN whitelists wl ON wl.id = wd.whitelist_id "
            "WHERE wl.active = 'active' AND wd.domain = %s "
            "LIMIT 1"
        )
        cur.execute(query, (h,))
        row = cur.fetchone()
        cur.close()
        conn.close()
        return row is not None
    except Exception as e:
        print(f"Warning: Whitelist lookup failed for {h}: {e}")
        return False

def find_matching_blocklist_domains(host: str, settings: dict):
    """Copy of the blocklist function from logger.py"""
    h = _normalize_hostname(host)
    if not h:
        return []

    try:
        conn = mariadb.connect(**DB_CONFIG)
        cur = conn.cursor()
        query = (
            "SELECT bd.blocklist_id, bd.id AS blocklist_domain_id "
            "FROM blocklist_domains bd "
            "JOIN blocklists bl ON bl.id = bd.blocklist_id "
            "WHERE bl.active = 'active' AND bd.domain = %s"
        )
        cur.execute(query, (h,))
        rows = cur.fetchall()
        cur.close()
        conn.close()
        return [(row[0], row[1]) for row in rows]
    except Exception as e:
        print(f"Warning: Blocklist lookup failed for {h}: {e}")
        return []

def simulate_http_blocking_logic(host, dst_ip, settings):
    """Simulate the exact HTTP blocking logic from logger.py"""
    print(f"\n=== Simulating HTTP blocking logic for host: {host} ===")

    # Whitelist overrides blacklist: if host is whitelisted, do nothing
    whitelist_result = is_host_whitelisted(host, settings)
    print(f"Whitelist check result: {whitelist_result}")

    if host and whitelist_result:
        print(f"DEBUG: HTTP host {host} is whitelisted, skipping blocking")
        return "WHITELISTED - NO BLOCKING"

    # If host matches any active blocklist and is not whitelisted, add destination IP to corresponding set(s) and record it
    if host and dst_ip:
        matching_domains = find_matching_blocklist_domains(host, settings)
        print(f"Blocklist matches: {matching_domains}")

        if matching_domains:
            print(f"DEBUG: HTTP host {host} matches {len(matching_domains)} blocklist(s), blocking IP {dst_ip}")
            return f"BLOCKING IP {dst_ip} due to {len(matching_domains)} blocklist matches"
        else:
            print(f"DEBUG: HTTP host {host} does not match any active blocklists")
            return "NO BLOCKLIST MATCH - NO BLOCKING"

    return "NO ACTION"

def test_various_hostname_formats():
    """Test various hostname formats that might appear in HTTP requests"""

    settings = load_settings_config()
    test_ip = "192.168.1.100"

    test_cases = [
        "a.thumbs.redditmedia.com",
        "a.thumbs.redditmedia.com:443",
        "a.thumbs.redditmedia.com:80",
        "A.THUMBS.REDDITMEDIA.COM",  # uppercase
        "a.thumbs.redditmedia.com.",  # trailing dot
        "a.thumbs.redditmedia.com:443.",  # port and trailing dot
    ]

    for host in test_cases:
        result = simulate_http_blocking_logic(host, test_ip, settings)
        print(f"Result for '{host}': {result}")

if __name__ == "__main__":
    test_various_hostname_formats()