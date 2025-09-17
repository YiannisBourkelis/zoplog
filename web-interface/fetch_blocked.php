<?php
header("Content-Type: application/json");

require_once __DIR__ . '/zoplog_config.php';

$offset = intval($_GET["offset"] ?? 0);
$limit = intval($_GET["limit"] ?? 200);
$ip = $_GET["ip"] ?? "";
$direction = strtoupper($_GET["direction"] ?? "");
$proto = strtoupper($_GET["proto"] ?? "");
$iface = $_GET["iface"] ?? ""; // matches either iface_in or iface_out
$since = $_GET["since"] ?? "";

$where = [];
$params = [];
$types = "";

if ($ip) {
    $where[] = "(src_ip.ip_address LIKE ? OR dst_ip.ip_address LIKE ?)";
    $params[] = "%$ip%";
    $params[] = "%$ip%";
    $types .= "ss";
}
if ($direction) {
    $where[] = "be.direction = ?";
    $params[] = $direction;
    $types .= "s";
}
if ($proto) {
    $where[] = "UPPER(be.proto) = ?";
    $params[] = $proto;
    $types .= "s";
}
if ($iface) {
    $where[] = "(be.iface_in LIKE ? OR be.iface_out LIKE ?)";
    $params[] = "%$iface%";
    $params[] = "%$iface%";
    $types .= "ss";
}
if ($since) {
    $where[] = "be.event_time > ?";
    $params[] = $since;
    $types .= "s";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";
$order = $since ? "ASC" : "DESC";
$limit_sql = $since ? "LIMIT ?" : "LIMIT ? OFFSET ?";
if ($since) {
    $params[] = $limit;
    $types .= "i";
} else {
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
}

// Build enriched query to group by IP and get all related hostnames (simplified and optimized)
$sql = "
SELECT
    CASE
        WHEN be.direction = 'OUT' THEN dst_ip.ip_address
        WHEN be.direction = 'IN' THEN src_ip.ip_address
        ELSE dst_ip.ip_address
    END AS primary_ip,
    CASE
        WHEN be.direction = 'OUT' THEN dst_ip.id
        WHEN be.direction = 'IN' THEN src_ip.id
        ELSE dst_ip.id
    END AS primary_ip_id,
    -- Get hostnames from a simple join with pre-filtered hostname data
    COALESCE(hostname_data.all_hostnames, '') AS all_hostnames,
    MAX(be.event_time) AS latest_event_time,
    COUNT(DISTINCT be.id) AS event_count,
    
    -- Get details from latest event using SUBSTRING_INDEX
    SUBSTRING_INDEX(GROUP_CONCAT(be.direction ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_direction,
    SUBSTRING_INDEX(GROUP_CONCAT(UPPER(be.proto) ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_proto,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(src_ip.ip_address, '') ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_src_ip,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(dst_ip.ip_address, '') ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_dst_ip,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(be.iface_in, '') ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_iface_in,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(be.iface_out, '') ORDER BY be.event_time DESC SEPARATOR '|'), '|', 1) AS latest_iface_out,
    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(be.message, '') ORDER BY be.event_time DESC SEPARATOR '~'), '~', 1) AS latest_message,
    
    0 AS cnt_url_blocklists,
    0 AS cnt_manual_system_blocklists
FROM blocked_events be
LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
LEFT JOIN (
    -- Simple aggregation of hostnames by IP from recent logs
    SELECT 
        pl.src_ip_id AS ip_id,
        GROUP_CONCAT(DISTINCT h.hostname ORDER BY h.hostname SEPARATOR '|') AS all_hostnames
    FROM packet_logs pl
    LEFT JOIN hostnames h ON pl.hostname_id = h.id
    WHERE pl.hostname_id IS NOT NULL 
    AND h.hostname IS NOT NULL
    AND pl.src_ip_id IS NOT NULL
    AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY pl.src_ip_id
    
    UNION
    
    SELECT 
        pl.dst_ip_id AS ip_id,
        GROUP_CONCAT(DISTINCT h.hostname ORDER BY h.hostname SEPARATOR '|') AS all_hostnames
    FROM packet_logs pl
    LEFT JOIN hostnames h ON pl.hostname_id = h.id
    WHERE pl.hostname_id IS NOT NULL 
    AND h.hostname IS NOT NULL
    AND pl.dst_ip_id IS NOT NULL
    AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY pl.dst_ip_id
) AS hostname_data ON hostname_data.ip_id = CASE
    WHEN be.direction = 'OUT' THEN dst_ip.id
    WHEN be.direction = 'IN' THEN src_ip.id
    ELSE dst_ip.id
END
$where_sql
GROUP BY primary_ip, primary_ip_id, hostname_data.all_hostnames
ORDER BY latest_event_time $order
$limit_sql
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => $mysqli->error]);
    exit;
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode($rows);

$stmt->close();
$mysqli->close();
?>
