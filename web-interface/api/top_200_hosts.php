<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Top 200 hosts (sum of allowed_count from recent activity, last 30 days)
$top30 = $mysqli->query("
    SELECT sub.domain, SUM(sub.allowed_count) AS cnt
    FROM (
        SELECT d.domain, dia.allowed_count
        FROM domain_ip_addresses dia
        LEFT JOIN domains d ON dia.domain_id = d.id
        WHERE d.domain IS NOT NULL
        AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ) sub
    GROUP BY sub.domain
    HAVING SUM(sub.allowed_count) > 0
    ORDER BY SUM(sub.allowed_count) DESC
    LIMIT 200
");

$topHosts30 = [];
while ($row = $top30->fetch_assoc()) {
    $topHosts30[] = $row;
}

// Top 200 hosts (sum of allowed_count from recent activity, last 365 days)
$top365 = $mysqli->query("
    SELECT sub.domain, SUM(sub.allowed_count) AS cnt
    FROM (
        SELECT d.domain, dia.allowed_count
        FROM domain_ip_addresses dia
        LEFT JOIN domains d ON dia.domain_id = d.id
        WHERE d.domain IS NOT NULL
        AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 365 DAY)
    ) sub
    GROUP BY sub.domain
    HAVING SUM(sub.allowed_count) > 0
    ORDER BY SUM(sub.allowed_count) DESC
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