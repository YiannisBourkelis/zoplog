<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top 200 hosts (using cumulative allowed_count from domain_ip_addresses, filtered by last 30 days)
$top30 = $mysqli->query("
    SELECT d.domain, dia.allowed_count as cnt
    FROM domain_ip_addresses dia
    LEFT JOIN domains d ON dia.domain_id = d.id
    WHERE d.domain IS NOT NULL AND dia.allowed_count > 0
    AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY dia.allowed_count DESC
    LIMIT 200
");

$topHosts30 = [];
while ($row = $top30->fetch_assoc()) {
    $topHosts30[] = $row;
}

// Top 200 hosts (same data, using cumulative counts, filtered by last 365 days)
$top365 = $mysqli->query("
    SELECT d.domain, dia.allowed_count as cnt
    FROM domain_ip_addresses dia
    LEFT JOIN domains d ON dia.domain_id = d.id
    WHERE d.domain IS NOT NULL AND dia.allowed_count > 0
    AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 365 DAY)
    ORDER BY dia.allowed_count DESC
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