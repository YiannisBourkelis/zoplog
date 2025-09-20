<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top 30 blocked domains (30 days) - based on blocked_events and domain_ip_addresses
$topBlocked30 = $mysqli->query("
SELECT
    d.domain as blocked_domain,
    COUNT(DISTINCT CONCAT(
        COALESCE(be.src_ip_id, ''),
        '-',
        COALESCE(be.dst_port, ''),
        '-',
        FLOOR(UNIX_TIMESTAMP(be.event_time) / 30)
    )) as block_count
FROM blocked_events be
INNER JOIN domain_ip_addresses dip ON dip.ip_address_id = be.wan_ip_id
INNER JOIN domains d ON d.id = dip.domain_id
WHERE be.event_time >= NOW() - INTERVAL 30 DAY
GROUP BY d.domain
ORDER BY block_count DESC
LIMIT 30;
");

$blockedDomains30 = [];
while ($row = $topBlocked30->fetch_assoc()) {
    $blockedDomains30[] = [
        'blocked_host' => $row['blocked_domain'],
        'cnt' => (int)$row['block_count']
    ];
}

// Top 30 blocked domains (365 days) - based on blocked_events and domain_ip_addresses
$topBlocked365 = $mysqli->query("
SELECT
    d.domain as blocked_domain,
    COUNT(DISTINCT CONCAT(
        COALESCE(be.src_ip_id, ''),
        '-',
        COALESCE(be.dst_port, ''),
        '-',
        FLOOR(UNIX_TIMESTAMP(be.event_time) / 30)
    )) as block_count
FROM blocked_events be
INNER JOIN domain_ip_addresses dip ON dip.ip_address_id = be.wan_ip_id
INNER JOIN domains d ON d.id = dip.domain_id
WHERE be.event_time >= NOW() - INTERVAL 365 DAY
GROUP BY d.domain
ORDER BY block_count DESC
LIMIT 30;
");

$blockedDomains365 = [];
while ($row = $topBlocked365->fetch_assoc()) {
    $blockedDomains365[] = [
        'blocked_host' => $row['blocked_domain'],
        'cnt' => (int)$row['block_count']
    ];
}

echo json_encode([
    'blocked30' => $blockedDomains30,
    'blocked365' => $blockedDomains365
]);
?>