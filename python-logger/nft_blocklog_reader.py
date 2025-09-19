#!/usr/bin/env python3
"""
Realtime reader for nftables LOG entries with prefixes:
  - ZOPLOG-BLOCKLIST-IN
  - ZOPLOG-BLOCKLIST-OUT

Uses systemd.journal.Reader (no sleep loops). Prints each matched log to stdout
and stores events into the database. No schema creation here.
"""

import re
import sys
from typing import Dict, Optional, Tuple

from zoplog_config import load_database_config, DEFAULT_MONITOR_INTERFACE

# Get database configuration
DB_CONFIG = load_database_config()

# DB driver (PyMySQL preferred; fallback to mysql-connector)
try:
    import pymysql as mariadb
except Exception:
    try:
        import mysql.connector as mariadb
    except Exception:
        sys.stderr.write("No MySQL driver found. Install 'pymysql' or 'mysql-connector-python'.\n")
        sys.exit(1)

# systemd journal reader
try:
    from systemd import journal as sd_journal
except Exception:
    sys.stderr.write("python3-systemd is required. Install with: sudo apt install python3-systemd\n")
    sys.exit(1)

PREFIX_IN = "ZOPLOG-BLOCKLIST-IN"
PREFIX_OUT = "ZOPLOG-BLOCKLIST-OUT"
PREFIX_FWD = "ZOPLOG-BLOCKLIST-FWD"

# Also handle cases where nftables appends interface direction
PREFIX_IN_IN = "ZOPLOG-BLOCKLIST-ININ"
PREFIX_IN_OUT = "ZOPLOG-BLOCKLIST-INOUT"
PREFIX_OUT_IN = "ZOPLOG-BLOCKLIST-OUTIN"
PREFIX_OUT_OUT = "ZOPLOG-BLOCKLIST-OUTOUT"
PREFIX_FWD_IN = "ZOPLOG-BLOCKLIST-FWDIN"
PREFIX_FWD_OUT = "ZOPLOG-BLOCKLIST-FWDOUT"

def db_connect():
    conn = mariadb.connect(**DB_CONFIG)
    cur = conn.cursor()
    return conn, cur

def get_or_insert_ip(cursor, ip: Optional[str]) -> Optional[int]:
    if not ip:
        return None
    cursor.execute(
        "INSERT INTO ip_addresses (ip_address) VALUES (%s) "
        "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
        (ip,),
    )
    return cursor.lastrowid

def get_wan_ip_id(direction: str, src_ip_id: Optional[int], dst_ip_id: Optional[int], iface_in: Optional[str], iface_out: Optional[str], monitoring_interface: str) -> Optional[int]:
    """
    Determine the WAN IP ID based on direction, interface information, and monitoring interface.
    The monitoring interface is the WAN-facing interface.
    """
    if direction == 'IN' and iface_in == monitoring_interface:
        return src_ip_id
    elif direction == 'OUT' and iface_out == monitoring_interface:
        return dst_ip_id
    elif direction == 'FWD' and iface_out == monitoring_interface:
        return dst_ip_id
    # Fallback to old logic if interface doesn't match
    if direction == 'IN':
        return src_ip_id
    elif direction in ('OUT', 'FWD'):
        return dst_ip_id
    return None

# Inject a space right after the prefix token if glued (e.g., ...-OUTIN=)
def _normalize_prefix_spacing(msg: str) -> str:
    all_prefixes = (
        PREFIX_IN, PREFIX_OUT, PREFIX_FWD,
        PREFIX_IN_IN, PREFIX_IN_OUT, PREFIX_OUT_IN, PREFIX_OUT_OUT,
        PREFIX_FWD_IN, PREFIX_FWD_OUT
    )
    for pref in all_prefixes:
        i = msg.find(pref)
        if i != -1:
            j = i + len(pref)
            if j < len(msg) and msg[j] != ' ':
                msg = msg[:j] + ' ' + msg[j:]
    return msg

kv_re = re.compile(r"\b([A-Z]+)=([^\s]+)")

def parse_log_line(line: str) -> Optional[Tuple[str, Dict[str, str]]]:
    all_prefixes = (
        PREFIX_IN, PREFIX_OUT, PREFIX_FWD,
        PREFIX_IN_IN, PREFIX_IN_OUT, PREFIX_OUT_IN, PREFIX_OUT_OUT,
        PREFIX_FWD_IN, PREFIX_FWD_OUT
    )
    
    # Check if any of our prefixes are in the line
    found_prefix = None
    for pref in all_prefixes:
        if pref in line:
            found_prefix = pref
            break
    
    if not found_prefix:
        return None
        
    line = _normalize_prefix_spacing(line)
    
    # Map the found prefix to the correct direction
    if found_prefix in (PREFIX_IN, PREFIX_IN_IN, PREFIX_IN_OUT, PREFIX_OUT_IN):
        direction = "IN"
    elif found_prefix in (PREFIX_OUT, PREFIX_OUT_OUT):
        direction = "OUT"
    elif found_prefix in (PREFIX_FWD, PREFIX_FWD_IN, PREFIX_FWD_OUT):
        direction = "FWD"
    else:
        return None
    
    fields = {m.group(1): m.group(2) for m in kv_re.finditer(line)}
    return direction, fields

def insert_block_event(conn, cursor, direction: str, fields: Dict[str, str], raw: str):
    iface_in = fields.get("IN")
    iface_out = fields.get("OUT")
    proto = fields.get("PROTO")
    src_ip = fields.get("SRC")
    dst_ip = fields.get("DST")
    spt = fields.get("SPT")
    dpt = fields.get("DPT")

    try:
        src_port = int(spt) if spt and spt.isdigit() else None
    except Exception:
        src_port = None
    try:
        dst_port = int(dpt) if dpt and dpt.isdigit() else None
    except Exception:
        dst_port = None

    src_ip_id = get_or_insert_ip(cursor, src_ip)
    dst_ip_id = get_or_insert_ip(cursor, dst_ip)

    wan_ip_id = get_wan_ip_id(direction, src_ip_id, dst_ip_id, iface_in, iface_out, DEFAULT_MONITOR_INTERFACE)

    cursor.execute(
        """
        INSERT INTO blocked_events
          (event_time, direction, src_ip_id, dst_ip_id, wan_ip_id, src_port, dst_port, proto, iface_in, iface_out, message)
        VALUES (NOW(), %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (direction, src_ip_id, dst_ip_id, wan_ip_id, src_port, dst_port, proto, iface_in, iface_out, raw[:65535]),
    )

def journal_reader():
    r = sd_journal.Reader()
    try:
        r.this_boot()
    except Exception:
        pass
    # Kernel transport - correct way to filter kernel messages
    r.add_match(_TRANSPORT="kernel")
    # Start from tail to only get new entries
    r.seek_tail()
    r.get_previous()
    return r

def main():
    print("Starting nft block log reader (systemd-journal)…", flush=True)
    print("Tip: run with sudo or add your user to 'systemd-journal' group.", flush=True)

    conn, cursor = db_connect()
    r = journal_reader()

    try:
        while True:
            # Wait for new journal entries
            r.wait()
            
            # Process all new entries
            for entry in r:
                msg = entry.get('MESSAGE', '')
                if not msg:
                    continue

                # Fast path: only care for our prefixes
                if "ZOPLOG-BLOCKLIST-" not in msg:
                    continue

                print(f"[RAW JOURNAL] {msg}", flush=True)

                # Normalize glued prefix (…-OUTIN= / …-ININ=)
                raw = _normalize_prefix_spacing(msg)
                parsed = parse_log_line(raw)
                if not parsed:
                    # Show raw for troubleshooting
                    print(f"[DEBUG] Unparsed: {raw}", flush=True)
                    continue

                direction, fields = parsed

                # Print summary + raw line for troubleshooting
                proto = fields.get('PROTO') or ''
                src = fields.get('SRC') or ''
                dst = fields.get('DST') or ''
                spt = fields.get('SPT') or ''
                dpt = fields.get('DPT') or ''
                inif = fields.get('IN') or ''
                outif = fields.get('OUT') or ''
                print(f"[{direction}] {proto} {src}:{spt} -> {dst}:{dpt} IN={inif} OUT={outif}", flush=True)

                # DB insert
                try:
                    insert_block_event(conn, cursor, direction, fields, raw)
                    conn.commit()
                    print(f"[DB] Inserted {direction} event", flush=True)
                except mariadb.Error as e:
                    emsg = str(e)
                    if "gone away" in emsg or "Lost connection" in emsg:
                        try:
                            cursor.close()
                        except Exception:
                            pass
                        try:
                            conn.close()
                        except Exception:
                            pass
                        conn, cursor = db_connect()
                        sys.stderr.write("Reconnected to DB after 'gone away'.\n")
                    else:
                        sys.stderr.write(f"DB error: {e}\n")
                except Exception as e:
                    sys.stderr.write(f"Unexpected error: {e}\n")

    except KeyboardInterrupt:
        print("Stopping…")
    finally:
        try:
            cursor.close()
            conn.close()
        except Exception:
            pass

if __name__ == "__main__":
    main()