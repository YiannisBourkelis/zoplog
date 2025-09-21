<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Simplified parallel execution using single connection with optimized queries
$query30 = "
SELECT domains.domain AS blocked_domain,
COUNT(*) AS block_count
FROM domains
RIGHT JOIN domain_ip_addresses ON domains.id = domain_ip_addresses.domain_id
RIGHT JOIN ip_addresses ON domain_ip_addresses.ip_address_id = ip_addresses.id
RIGHT JOIN blocked_events ON ip_addresses.id = blocked_events.wan_ip_id
WHERE blocked_events.event_time >= NOW() - INTERVAL 400000 SECOND
GROUP BY domains.domain
ORDER BY COUNT(*) DESC
LIMIT 10
";

$query365 = "
SELECT domains.domain AS blocked_domain,
COUNT(*) AS block_count
FROM domains
RIGHT JOIN domain_ip_addresses ON domains.id = domain_ip_addresses.domain_id
RIGHT JOIN ip_addresses ON domain_ip_addresses.ip_address_id = ip_addresses.id
RIGHT JOIN blocked_events ON ip_addresses.id = blocked_events.wan_ip_id
WHERE blocked_events.event_time >= NOW() - INTERVAL 365 DAY
GROUP BY domains.domain
ORDER BY COUNT(*) DESC
LIMIT 10
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
            'blocked_host' => $row['blocked_domain'],
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
            'blocked_host' => $row['blocked_domain'],
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