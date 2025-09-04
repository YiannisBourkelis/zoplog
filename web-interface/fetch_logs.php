<?php
header("Content-Type: application/json");

// Database connection via centralized helper
require_once __DIR__ . '/zoplog_config.php';

// Get parameters
$offset = intval($_GET["offset"] ?? 0);
$limit = intval($_GET["limit"] ?? 200);
$ip = $_GET["ip"] ?? "";
$mac = $_GET["mac"] ?? "";
$hostname = $_GET["hostname"] ?? "";
$method = $_GET["method"] ?? "";
$type = $_GET["type"] ?? "";
$since = $_GET["since"] ?? "";

// Build dynamic WHERE clause
$where = [];
$params = [];
$types = "";

// IP filter
if ($ip) {
    $where[] = "(src_ip.ip_address LIKE ? OR dst_ip.ip_address LIKE ?)";
    $params[] = "%$ip%";
    $params[] = "%$ip%";
    $types .= "ss";
}

// MAC filter
if ($mac) {
    $where[] = "(src_mac.mac_address LIKE ? OR dst_mac.mac_address LIKE ?)";
    $params[] = "%$mac%";
    $params[] = "%$mac%";
    $types .= "ss";
}

// Hostname filter
if ($hostname) {
    $where[] = "h.hostname LIKE ?";
    $params[] = "%$hostname%";
    $types .= "s";
}

// Method filter
if ($method) {
    $where[] = "p.method = ?";
    $params[] = $method;
    $types .= "s";
}

// Type filter
if ($type) {
    $where[] = "p.type = ?";
    $params[] = $type;
    $types .= "s";
}

// Since filter (for auto-refresh)
if ($since) {
    $where[] = "p.packet_timestamp > ?";
    $params[] = $since;
    $types .= "s";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Determine order & limit/offset
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

// Build query
$sql = "
SELECT p.packet_timestamp, p.src_port, p.dst_port, p.method, p.type,
       src_ip.ip_address AS src_ip, dst_ip.ip_address AS dst_ip,
       src_mac.mac_address AS src_mac, dst_mac.mac_address AS dst_mac,
       h.hostname, path.path
FROM packet_logs p
LEFT JOIN ip_addresses src_ip ON p.src_ip_id = src_ip.id
LEFT JOIN ip_addresses dst_ip ON p.dst_ip_id = dst_ip.id
LEFT JOIN mac_addresses src_mac ON p.src_mac_id = src_mac.id
LEFT JOIN mac_addresses dst_mac ON p.dst_mac_id = dst_mac.id
LEFT JOIN hostnames h ON p.hostname_id = h.id
LEFT JOIN paths path ON p.path_id = path.id
$where_sql
ORDER BY p.packet_timestamp $order
$limit_sql
";

// Prepare statement
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => $mysqli->error]);
    exit;
}

// Bind parameters dynamically
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