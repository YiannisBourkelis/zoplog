#!/usr/bin/env python3
"""
ZopLog Database Log Cleanup Script
Automatically purges old logs when disk space is low or based on retention policies.
"""

import os
import sys
import time
import argparse
from datetime import datetime, timedelta
from typing import Optional, Tuple

# Add the parent directory to the path so we can import zoplog_config
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from zoplog_config import load_database_config

# Get database configuration
DB_CONFIG = load_database_config()

# DB driver
try:
    import pymysql as mariadb
except Exception:
    try:
        import mysql.connector as mariadb
    except Exception:
        sys.stderr.write("No MySQL driver found. Install 'pymysql' or 'mysql-connector-python'.\n")
        sys.exit(1)

# Systemd journal logging
try:
    from systemd import journal
    JOURNAL_AVAILABLE = True
except ImportError:
    JOURNAL_AVAILABLE = False

# Syslog logging for Debian/cron jobs
import syslog

def log_message(message: str, level: str = "info"):
    """Log message to both systemd journal (if available) and syslog."""
    # Log to systemd journal if available (for systemd services)
    if JOURNAL_AVAILABLE:
        log_to_journal(message, level)
    
    # Always log to syslog (works with cron jobs)
    syslog_level = {
        "info": syslog.LOG_INFO,
        "warning": syslog.LOG_WARNING,
        "err": syslog.LOG_ERR,
        "error": syslog.LOG_ERR,
        "debug": syslog.LOG_DEBUG
    }.get(level, syslog.LOG_INFO)
    
    syslog.syslog(syslog_level, f"ZopLog Cleanup: {message}")

def log_to_journal(message: str, priority: str = "info"):
    """Log message to systemd journal if available."""
    if not JOURNAL_AVAILABLE:
        return
    
    priority_map = {
        "emerg": journal.LOG_EMERG,
        "alert": journal.LOG_ALERT,
        "crit": journal.LOG_CRIT,
        "err": journal.LOG_ERR,
        "warning": journal.LOG_WARNING,
        "notice": journal.LOG_NOTICE,
        "info": journal.LOG_INFO,
        "debug": journal.LOG_DEBUG
    }
    
    journal.send(message, priority=priority_map.get(priority, journal.LOG_INFO), 
                SYSLOG_IDENTIFIER="zoplog-cleanup")

def get_disk_usage(path: str = "/var/lib/mysql") -> Tuple[float, float]:
    """Get disk usage percentage and available space in GB for the given path."""
    stat = os.statvfs(path)
    # Calculate percentage used
    usage_percent = (1 - (stat.f_bavail / stat.f_blocks)) * 100
    # Calculate available space in GB
    available_gb = (stat.f_bavail * stat.f_frsize) / (1024**3)
    return usage_percent, available_gb

def db_connect():
    """Connect to the database."""
    return mariadb.connect(**DB_CONFIG)

def get_table_sizes(cursor) -> dict:
    """Get the size of main log tables in MB."""
    sizes = {}
    tables = ['packet_logs', 'blocked_events', 'ip_addresses', 'hostnames', 'paths', 'user_agents']

    for table in tables:
        cursor.execute(f"""
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = '{table}'
        """)
        result = cursor.fetchone()
        sizes[table] = result[0] if result and result[0] else 0

    return sizes

def purge_by_disk_space(cursor, min_free_percent: float = 8.0, dry_run: bool = False) -> dict:
    """Purge oldest logs one day at a time until disk space is above minimum threshold."""
    usage_percent, available_gb = get_disk_usage()

    if usage_percent < (100 - min_free_percent):
        return {'message': f'Disk usage {usage_percent:.1f}% is above threshold, no cleanup needed'}

    stats = {'initial_usage': usage_percent, 'target_percent': 100 - min_free_percent}
    total_deleted = 0
    days_deleted = 0

    # Delete one day at a time until we reach the target
    while usage_percent >= (100 - min_free_percent) and days_deleted < 365:
        cutoff_date = datetime.now() - timedelta(days=days_deleted + 1)

        # Count records that would be deleted for this specific day
        cursor.execute("SELECT COUNT(*) FROM packet_logs WHERE DATE(packet_timestamp) = DATE(%s)",
                      (cutoff_date.strftime('%Y-%m-%d'),))
        packet_count = cursor.fetchone()[0]

        cursor.execute("SELECT COUNT(*) FROM blocked_events WHERE DATE(event_time) = DATE(%s)",
                      (cutoff_date.strftime('%Y-%m-%d'),))
        blocked_count = cursor.fetchone()[0]

        if packet_count + blocked_count == 0:
            days_deleted += 1
            if not dry_run:
                print(f"ℹ️  {cutoff_date.strftime('%Y-%m-%d')}: No records to delete")
            continue  # No records for this day, try the next day

        day_total = packet_count + blocked_count
        
        if not dry_run:
            print(f"🗑️  {cutoff_date.strftime('%Y-%m-%d')}: Deleting {day_total} records ({packet_count} packets, {blocked_count} blocked)")
            log_message(f"Deleting {day_total} records from {cutoff_date.strftime('%Y-%m-%d')} ({packet_count} packets, {blocked_count} blocked)")
            
            # Delete the records for this specific day
            cursor.execute("DELETE FROM packet_logs WHERE DATE(packet_timestamp) = DATE(%s)",
                          (cutoff_date.strftime('%Y-%m-%d'),))
            cursor.execute("DELETE FROM blocked_events WHERE DATE(event_time) = DATE(%s)",
                          (cutoff_date.strftime('%Y-%m-%d'),))

            # Commit to free up space
            cursor.connection.commit()
        else:
            print(f"🔍 {cutoff_date.strftime('%Y-%m-%d')}: Would delete {day_total} records ({packet_count} packets, {blocked_count} blocked)")

        total_deleted += day_total
        days_deleted += 1

        # Check disk usage again
        prev_usage = usage_percent
        usage_percent, available_gb = get_disk_usage()
        
        if not dry_run:
            improvement = prev_usage - usage_percent
            print(f"📊 Disk usage: {usage_percent:.1f}% ({improvement:+.1f}%), Available: {available_gb:.1f}GB")
            log_message(f"Disk usage after day {days_deleted}: {usage_percent:.1f}% ({improvement:+.1f}%), Available: {available_gb:.1f}GB")

        if days_deleted >= 365:
            print("⚠️  Reached 365-day limit, stopping cleanup")
            log_message("Reached 365-day cleanup limit, stopping to prevent excessive data loss")
            break  # Don't delete more than a year of data

    stats.update({
        'final_usage': usage_percent,
        'available_gb': available_gb,
        'total_deleted': total_deleted,
        'days_processed': days_deleted
    })

    return stats

def cleanup_orphaned_records(cursor, dry_run: bool = False) -> dict:
    """Clean up orphaned records in lookup tables."""
    stats = {}

    # Clean up orphaned IP addresses
    if not dry_run:
        cursor.execute("""
            DELETE ia FROM ip_addresses ia
            LEFT JOIN packet_logs pl1 ON ia.id = pl1.src_ip_id
            LEFT JOIN packet_logs pl2 ON ia.id = pl2.dst_ip_id
            LEFT JOIN blocked_events be1 ON ia.id = be1.src_ip_id
            LEFT JOIN blocked_events be2 ON ia.id = be2.dst_ip_id
            WHERE pl1.id IS NULL AND pl2.id IS NULL AND be1.id IS NULL AND be2.id IS NULL
        """)
        stats['orphaned_ips'] = cursor.rowcount
    else:
        cursor.execute("""
            SELECT COUNT(*) FROM ip_addresses ia
            LEFT JOIN packet_logs pl1 ON ia.id = pl1.src_ip_id
            LEFT JOIN packet_logs pl2 ON ia.id = pl2.dst_ip_id
            LEFT JOIN blocked_events be1 ON ia.id = be1.src_ip_id
            LEFT JOIN blocked_events be2 ON ia.id = be2.dst_ip_id
            WHERE pl1.id IS NULL AND pl2.id IS NULL AND be1.id IS NULL AND be2.id IS NULL
        """)
        stats['orphaned_ips'] = cursor.fetchone()[0]

    # Similar cleanup for other lookup tables would go here
    # (hostnames, paths, user_agents, etc.)

    return stats

def optimize_tables(cursor, dry_run: bool = False) -> dict:
    """Optimize tables after cleanup."""
    tables = ['packet_logs', 'blocked_events', 'ip_addresses', 'hostnames', 'paths', 'user_agents']
    stats = {}

    for table in tables:
        if not dry_run:
            cursor.execute(f"OPTIMIZE TABLE {table}")
            stats[table] = "optimized"
        else:
            stats[table] = "would optimize"

    return stats

def main():
    # Initialize syslog
    syslog.openlog(ident="zoplog-cleanup", logoption=syslog.LOG_PID, facility=syslog.LOG_USER)
    
    parser = argparse.ArgumentParser(description='ZopLog Database Disk Space Cleanup - Only runs when disk usage >= 95%')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be done without making changes')
    parser.add_argument('--force-disk-cleanup', action='store_true', help='Force disk space cleanup regardless of current usage (normally only runs when >= 95% usage)')
    parser.add_argument('--cleanup-orphaned', action='store_true', help='Clean up orphaned records in lookup tables')
    parser.add_argument('--optimize', action='store_true', help='Optimize tables after cleanup')

    args = parser.parse_args()

    print(f"ZopLog Database Cleanup - {'DRY RUN' if args.dry_run else 'LIVE RUN'}")
    print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")

    # Log to journal and file
    log_message(f"ZopLog Database Cleanup started - {'DRY RUN' if args.dry_run else 'LIVE RUN'}")

    # Check disk usage
    usage_percent, available_gb = get_disk_usage()
    print(".1f")
    log_message(f"Initial disk usage: {usage_percent:.1f}%, Available: {available_gb:.1f}GB")

    try:
        conn = db_connect()
        cursor = conn.cursor()

        # Get initial table sizes
        initial_sizes = get_table_sizes(cursor)
        print("Initial table sizes (MB):")
        for table, size in initial_sizes.items():
            print(f"  {table}: {size}")

        total_stats = {}

        # Disk space based cleanup - only when disk usage >= 95% (less than 5% free)
        if args.force_disk_cleanup or usage_percent >= 95.0:
            print(f"\n--- Disk space cleanup (target: 8.0% free) ---")
            disk_stats = purge_by_disk_space(cursor, 8.0, args.dry_run)  # Target 8% free
            total_stats.update(disk_stats)
            
            if 'message' in disk_stats:
                print(f"ℹ️  {disk_stats['message']}")
                log_message(f"Disk space cleanup: {disk_stats['message']}")
            else:
                final_usage, final_available = get_disk_usage()
                improvement = usage_percent - final_usage
                print(f"✅ Final disk usage: {final_usage:.1f}% ({improvement:+.1f}% improvement), Available: {final_available:.1f}GB")
                print(f"✅ Deleted {disk_stats['total_deleted']} total records over {disk_stats['days_processed']} days")
                
                if disk_stats['total_deleted'] > 0:
                    log_message(f"Disk space cleanup: SUCCESS - usage {usage_percent:.1f}% → {final_usage:.1f}% ({improvement:+.1f}%), deleted {disk_stats['total_deleted']} records")
                else:
                    log_message(f"Disk space cleanup: No deletions needed - usage {final_usage:.1f}% is above 92.0% threshold")

        # Cleanup orphaned records
        if args.cleanup_orphaned:
            print("\n--- Orphaned records cleanup ---")
            orphan_stats = cleanup_orphaned_records(cursor, args.dry_run)
            total_stats.update(orphan_stats)
            
            if orphan_stats['orphaned_ips'] > 0:
                print(f"✅ Deleted {orphan_stats['orphaned_ips']} orphaned IP addresses")
                log_message(f"Orphaned records cleanup: deleted {orphan_stats['orphaned_ips']} orphaned IP addresses")
            else:
                print(f"ℹ️  No orphaned records found")
                log_message("Orphaned records cleanup: no orphaned records found")

        # Optimize tables
        if args.optimize and not args.dry_run:
            print("\n--- Table optimization ---")
            optimize_stats = optimize_tables(cursor, args.dry_run)
            print(f"✅ Optimized {len(optimize_stats)} tables")
            log_message(f"Table optimization completed for {len(optimize_stats)} tables")

        if not args.dry_run:
            conn.commit()

        # Get final disk usage for summary
        final_usage_percent, final_available_gb = get_disk_usage()
        disk_improvement = usage_percent - final_usage_percent
        
        # Calculate total deletions (only disk cleanup and orphaned records)
        total_disk_deletions = total_stats.get('total_deleted', 0)
        total_orphaned = total_stats.get('orphaned_ips', 0)
        
        grand_total = total_disk_deletions + total_orphaned

        # Get final table sizes
        final_sizes = get_table_sizes(cursor)
        print("\nFinal table sizes (MB):")
        for table, size in final_sizes.items():
            print(f"  {table}: {size}")

        # Comprehensive summary
        print(f"\n{'='*60}")
        print("CLEANUP SUMMARY")
        print(f"{'='*60}")
        print(f"Initial disk usage: {usage_percent:.1f}%")
        print(f"Final disk usage:   {final_usage_percent:.1f}% ({disk_improvement:+.1f}%)")
        print(f"Available space:    {final_available_gb:.1f}GB")
        print()
        
        if grand_total > 0:
            print(f"✅ TOTAL RECORDS DELETED: {grand_total:,}")
            if total_disk_deletions > 0:
                print(f"   • Disk cleanup: {total_disk_deletions:,}")
            if total_orphaned > 0:
                print(f"   • Orphaned records: {total_orphaned:,}")
        else:
            print("ℹ️  No records were deleted during this cleanup run")
        
        print(f"\nCleanup completed at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        
        # Log comprehensive summary to journal
        if grand_total > 0:
            log_message(f"CLEANUP COMPLETE: {grand_total:,} records deleted, disk usage {usage_percent:.1f}% → {final_usage_percent:.1f}% ({disk_improvement:+.1f}%)")
        else:
            log_message(f"CLEANUP COMPLETE: No deletions needed, disk usage {final_usage_percent:.1f}%")

        cursor.close()
        conn.close()

    except Exception as e:
        error_msg = f"Error during cleanup: {e}"
        print(error_msg)
        log_message(error_msg, "error")
        sys.exit(1)

if __name__ == '__main__':
    main()
