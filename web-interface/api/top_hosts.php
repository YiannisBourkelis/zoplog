<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top hosts data
$topHostsRes = $mysqli->query("
SELECT sub.domain, SUM(sub.allowed_count) AS cnt   
FROM (
    SELECT d.domain, dia.allowed_count
    FROM domain_ip_addresses dia
    LEFT JOIN domains d ON dia.domain_id = d.id
    WHERE d.domain IS NOT NULL AND dia.allowed_count > 0
) sub
GROUP BY sub.domain
ORDER BY cnt DESC  
LIMIT 200
");

$topHosts = [];
while ($row = $topHostsRes->fetch_assoc()) {
    $topHosts[] = $row;
}

echo json_encode($topHosts);
?>