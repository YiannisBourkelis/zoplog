<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top hosts data
$topHostsRes = $mysqli->query("
    SELECT h.hostname, COUNT(*) AS cnt
    FROM packet_logs p
    LEFT JOIN hostnames h ON p.hostname_id = h.id
    WHERE h.hostname IS NOT NULL
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 5
");

$topHosts = [];
while ($row = $topHostsRes->fetch_assoc()) {
    $topHosts[] = $row;
}

echo json_encode($topHosts);
?>