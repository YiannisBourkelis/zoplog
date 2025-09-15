<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top 200 hosts (30 days)
$top30 = $mysqli->query("
    SELECT h.hostname, COUNT(*) as cnt
    FROM packet_logs p
    JOIN hostnames h ON p.hostname_id = h.id
    WHERE p.packet_timestamp >= NOW() - INTERVAL 30 DAY
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 200
");

$topHosts30 = [];
while ($row = $top30->fetch_assoc()) {
    $topHosts30[] = $row;
}

// Top 200 hosts (365 days)
$top365 = $mysqli->query("
    SELECT h.hostname, COUNT(*) as cnt
    FROM packet_logs p
    JOIN hostnames h ON p.hostname_id = h.id
    WHERE p.packet_timestamp >= NOW() - INTERVAL 365 DAY
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 200
");

$topHosts365 = [];
while ($row = $top365->fetch_assoc()) {
    $topHosts365[] = $row;
}

echo json_encode([
    'top30' => $topHosts30,
    'top365' => $topHosts365
]);
?>