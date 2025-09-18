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

// Basic validation & caps
if ($limit <= 0 || $limit > 1000) {
    $limit = 200;
}
if ($offset < 0) {
    $offset = 0;
}

// Build WHERE clauses and params for the first query (blocked_events aggregation)
$where_clauses = [];
$params = [];
$types = '';

if ($ip) {
    $where_clauses[] = "(src_ip.ip_address LIKE ? OR dst_ip.ip_address LIKE ? )";
    $params[] = "%$ip%";
    $params[] = "%$ip%";
    $types .= 'ss';
}
if ($direction) {
    $where_clauses[] = "be.direction = ?";
    $params[] = $direction;
    $types .= 's';
}
if ($proto) {
    $where_clauses[] = "UPPER(be.proto) = ?";
    $params[] = $proto;
    $types .= 's';
}
if ($iface) {
    $where_clauses[] = "(be.iface_in LIKE ? OR be.iface_out LIKE ? )";
    $params[] = "%$iface%";
    $params[] = "%$iface%";
    $types .= 'ss';
}
if ($since) {
    $where_clauses[] = "be.event_time > ?";
    $params[] = $since;
    $types .= 's';
}

$where_sql = $where_clauses ? ('WHERE ' . implode(' AND ', $where_clauses)) : '';
$order = $since ? 'ASC' : 'DESC';

// Query 1: aggregate blocked_events by primary ip id
$sql1 = "SELECT
    CASE WHEN be.direction = 'OUT' THEN be.dst_ip_id WHEN be.direction = 'IN' THEN be.src_ip_id ELSE be.dst_ip_id END AS primary_ip_id,
    MAX(be.event_time) AS latest_event_time,
    COUNT(*) AS event_count
FROM blocked_events be
LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
$where_sql
GROUP BY primary_ip_id
ORDER BY latest_event_time $order
LIMIT ? OFFSET ?";

$stmt1 = $mysqli->prepare($sql1);
if (!$stmt1) {
    http_response_code(500);
    echo json_encode(["error" => "prepare failed: " . $mysqli->error]);
    exit;
}

// bind params for query1
$bind_vals = $params;
$bind_types = $types . 'ii';
$bind_vals[] = $limit;
$bind_vals[] = $offset;

// dynamic bind using references
$refs = [];
$refs[] = &$bind_types;
for ($i = 0; $i < count($bind_vals); $i++) {
    $refs[] = &$bind_vals[$i];
}
call_user_func_array([$stmt1, 'bind_param'], $refs);

$stmt1->execute();
$res1 = $stmt1->get_result();
$primary_ids = [];
$meta = [];
while ($r = $res1->fetch_assoc()) {
    $pid = intval($r['primary_ip_id']);
    if ($pid <= 0) continue;
    $primary_ids[] = $pid;
    $meta[$pid] = [
        'latest_event_time' => $r['latest_event_time'],
        'event_count' => (int)$r['event_count']
    ];
}
$stmt1->close();

if (empty($primary_ids)) {
    echo json_encode([]);
    $mysqli->close();
    exit;
}

// To keep things simple with mysqli's bind_param limitations for IN lists,
// we'll build safe, escaped IN lists for the next queries.
function mysqli_quote_array($mysqli, $arr) {
    $out = [];
    foreach ($arr as $v) {
        $out[] = intval($v);
    }
    return implode(',', $out);
}

$in_list = mysqli_quote_array($mysqli, $primary_ids);

// Query 2a: ip strings
$sql_ips = "SELECT id, ip_address FROM ip_addresses WHERE id IN ($in_list)";
$res_ips = $mysqli->query($sql_ips);
$ip_map = [];
while ($row = $res_ips->fetch_assoc()) {
    $ip_map[intval($row['id'])] = $row['ip_address'];
}

// Query 2b: recent hostnames for these ids (30 days), src and dst
$sql_hostnames = "SELECT hp.ip_id, GROUP_CONCAT(DISTINCT d.domain ORDER BY d.domain SEPARATOR '|') AS all_hostnames
FROM (
    SELECT pl.src_ip_id AS ip_id, pl.domain_id
    FROM packet_logs pl
    WHERE pl.src_ip_id IS NOT NULL
      AND pl.domain_id IS NOT NULL
      AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND pl.src_ip_id IN ($in_list)
    UNION ALL
    SELECT pl.dst_ip_id AS ip_id, pl.domain_id
    FROM packet_logs pl
    WHERE pl.dst_ip_id IS NOT NULL
      AND pl.domain_id IS NOT NULL
      AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND pl.dst_ip_id IN ($in_list)
) AS hp
JOIN domains d ON hp.domain_id = d.id
GROUP BY hp.ip_id";

$res_hn = $mysqli->query($sql_hostnames);
$hostname_map = [];
while ($row = $res_hn->fetch_assoc()) {
    $hostname_map[intval($row['ip_id'])] = $row['all_hostnames'];
}

// Query 2c: latest blocked_event per primary id. We'll prepare a statement and execute per id.
$sql_latest = "SELECT be.direction, UPPER(be.proto) as proto, be.src_port, be.dst_port, be.iface_in, be.iface_out, be.message, be.event_time,
    src_ip.ip_address AS src_ip, dst_ip.ip_address AS dst_ip
FROM blocked_events be
LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
WHERE (CASE WHEN be.direction = 'OUT' THEN be.dst_ip_id WHEN be.direction = 'IN' THEN be.src_ip_id ELSE be.dst_ip_id END) = ?
ORDER BY be.event_time DESC
LIMIT 1";

$stmt_latest = $mysqli->prepare($sql_latest);
if (!$stmt_latest) {
    http_response_code(500);
    echo json_encode(["error" => "prepare latest failed: " . $mysqli->error]);
    exit;
}

// helper to sanitize strings for json encoding: ensure valid UTF-8 and strip
// control chars except tab/newline/carriage-return which JSON can handle
$sanitize = function($s) {
    if ($s === null) return null;
    // ensure it's a string
    $s = (string)$s;
    // remove non-printable control chars (except \t, \n, \r)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    // ensure valid UTF-8 by replacing invalid sequences
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return $s;
};

$rows = [];
foreach ($primary_ids as $pid) {
    $stmt_latest->bind_param('i', $pid);
    $stmt_latest->execute();
    $resl = $stmt_latest->get_result();
    $ld = $resl->fetch_assoc();

    $rows[] = [
        'primary_ip' => $sanitize($ip_map[$pid] ?? ''),
        'primary_ip_id' => $pid,
        'all_hostnames' => $sanitize($hostname_map[$pid] ?? ''),
        'latest_event_time' => $meta[$pid]['latest_event_time'] ?? $ld['event_time'] ?? null,
        'event_count' => $meta[$pid]['event_count'] ?? 0,
        'latest_direction' => $sanitize($ld['direction'] ?? null),
        'latest_proto' => $sanitize($ld['proto'] ?? null),
        'latest_src_ip' => $sanitize($ld['src_ip'] ?? null),
        'latest_dst_ip' => $sanitize($ld['dst_ip'] ?? null),
        'latest_iface_in' => $sanitize($ld['iface_in'] ?? null),
        'latest_iface_out' => $sanitize($ld['iface_out'] ?? null),
        'latest_message' => $sanitize($ld['message'] ?? null),
        'cnt_url_blocklists' => 0,
        'cnt_manual_system_blocklists' => 0
    ];
}

$stmt_latest->close();
$mysqli->close();

// final encode
$json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    // fallback: try partial output
    $json = json_encode($rows, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if ($json === false) {
    http_response_code(500);
    echo json_encode(["error" => "json_encode failed", "msg" => json_last_error_msg()]);
    exit;
}

echo $json;
