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

$sql = "
SELECT be.event_time, be.direction, be.src_port, be.dst_port, be.proto, be.iface_in, be.iface_out, be.message,
       src_ip.ip_address AS src_ip, dst_ip.ip_address AS dst_ip
FROM blocked_events be
LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
$where_sql
ORDER BY be.event_time $order
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
