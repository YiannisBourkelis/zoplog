<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top 200 blocked hosts (30 days) - optimized with normalization
$topBlocked30 = $mysqli->query("
    SELECT
        COALESCE(d.domain, dst_ip.ip_address, 'Unknown') as blocked_host,
        COUNT(DISTINCT CONCAT(
            COALESCE(be.src_ip_id, ''), '-',
            COALESCE(be.dst_ip_id, ''), '-',
            COALESCE(be.dst_port, ''), '-',
            be.time_bucket
        )) as cnt
    FROM (
        SELECT DISTINCT
            dst_ip_id,
            src_ip_id,
            dst_port,
            FLOOR(UNIX_TIMESTAMP(event_time) / 30) as time_bucket,
            event_time
        FROM blocked_events
        WHERE event_time >= NOW() - INTERVAL 30 DAY
    ) be
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN domain_ip_addresses dip ON dip.ip_address_id = dst_ip.id
    LEFT JOIN domains d ON d.id = dip.domain_id
    GROUP BY COALESCE(d.domain, dst_ip.ip_address)
    ORDER BY cnt DESC
    LIMIT 200
");

$blockedHosts30 = [];
while ($row = $topBlocked30->fetch_assoc()) {
    $blockedHosts30[] = $row;
}

// Top 200 blocked hosts (365 days) - optimized with normalization
$topBlocked365 = $mysqli->query("
    SELECT
        COALESCE(d.domain, dst_ip.ip_address, 'Unknown') as blocked_host,
        COUNT(DISTINCT CONCAT(
            COALESCE(be.src_ip_id, ''), '-',
            COALESCE(be.dst_ip_id, ''), '-',
            COALESCE(be.dst_port, ''), '-',
            be.time_bucket
        )) as cnt
    FROM (
        SELECT DISTINCT
            dst_ip_id,
            src_ip_id,
            dst_port,
            FLOOR(UNIX_TIMESTAMP(event_time) / 30) as time_bucket
        FROM blocked_events
        WHERE event_time >= NOW() - INTERVAL 365 DAY
    ) be
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN domain_ip_addresses dip ON dip.ip_address_id = dst_ip.id
    LEFT JOIN domains d ON d.id = dip.domain_id
    GROUP BY COALESCE(d.domain, dst_ip.ip_address)
    ORDER BY cnt DESC
    LIMIT 200
");

$blockedHosts365 = [];
while ($row = $topBlocked365->fetch_assoc()) {
    $blockedHosts365[] = $row;
}

echo json_encode([
    'blocked30' => $blockedHosts30,
    'blocked365' => $blockedHosts365
]);
?>