<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Simplified parallel execution using single connection with optimized queries
$query30 = "
SELECT sub.domain, SUM(sub.blocked_count) AS block_count
FROM (
    SELECT d.domain, dia.blocked_count
    FROM domain_ip_addresses dia
    LEFT JOIN domains d ON dia.domain_id = d.id
    WHERE d.domain IS NOT NULL
    AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 30 DAY)
) sub
GROUP BY sub.domain
HAVING SUM(sub.blocked_count) > 0
ORDER BY SUM(sub.blocked_count) DESC
LIMIT 200
";

$query365 = "
SELECT sub.domain, SUM(sub.blocked_count) AS block_count
FROM (
    SELECT d.domain, dia.blocked_count
    FROM domain_ip_addresses dia
    LEFT JOIN domains d ON dia.domain_id = d.id
    WHERE d.domain IS NOT NULL
    AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 365 DAY)
) sub
GROUP BY sub.domain
HAVING SUM(sub.blocked_count) > 0
ORDER BY SUM(sub.blocked_count) DESC
LIMIT 200
";

// Execute queries sequentially but with optimized settings
$mysqli->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
$mysqli->query("SET SESSION optimizer_switch = 'index_merge=on,index_merge_union=on,index_merge_sort_union=on,index_merge_intersection=on'");

$start_time = microtime(true);

// Execute both queries
$topBlocked30 = $mysqli->query($query30);
$topBlocked365 = $mysqli->query($query365);

$execution_time = microtime(true) - $start_time;
error_log("Sequential queries executed in: " . number_format($execution_time, 4) . " seconds");

// Debug: Check if results are valid
if (!$topBlocked30) {
    error_log("30-day result is null: " . $mysqli->error);
}
if (!$topBlocked365) {
    error_log("365-day result is null: " . $mysqli->error);
}

// Process 30-day results
$blockedDomains30 = [];
if ($topBlocked30 && $topBlocked30->num_rows > 0) {
    while ($row = $topBlocked30->fetch_assoc()) {
        $blockedDomains30[] = [
            'blocked_host' => $row['domain'],
            'cnt' => (int)$row['block_count']
        ];
    }
} else {
    error_log("30-day query returned no results. Rows: " . ($topBlocked30 ? $topBlocked30->num_rows : 'null') . ", Error: " . $mysqli->error);
}

// Process 365-day results
$blockedDomains365 = [];
if ($topBlocked365 && $topBlocked365->num_rows > 0) {
    while ($row = $topBlocked365->fetch_assoc()) {
        $blockedDomains365[] = [
            'blocked_host' => $row['domain'],
            'cnt' => (int)$row['block_count']
        ];
    }
} else {
    error_log("365-day query returned no results. Rows: " . ($topBlocked365 ? $topBlocked365->num_rows : 'null') . ", Error: " . $mysqli->error);
}

echo json_encode([
    'blocked30' => $blockedDomains30,
    'blocked365' => $blockedDomains365
]);
?>