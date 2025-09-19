<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top hosts data
$topHostsRes = $mysqli->query("
    SELECT d.domain, COUNT(*) AS cnt
    FROM packet_logs p
    LEFT JOIN domains d ON p.domain_id = d.id
    WHERE d.domain IS NOT NULL
    GROUP BY d.domain
    ORDER BY cnt DESC
    LIMIT 200
");

$topHosts = [];
while ($row = $topHostsRes->fetch_assoc()) {
    $topHosts[] = $row;
}

echo json_encode($topHosts);
?>