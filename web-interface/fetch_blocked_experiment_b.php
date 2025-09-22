<?php
header("Content-Type: application/json");

// Include database configuration and connection
require_once __DIR__ . '/zoplog_config.php';

try {
    // Accept cursor and limit from GET
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
    if ($limit <= 0 || $limit > 200) $limit = 200;
    $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : null;
    $latest_id = isset($_GET['latest_id']) ? intval($_GET['latest_id']) : null;

    $where_conditions = [];
    if ($last_id) {
        $where_conditions[] = "id < " . intval($last_id);
    }
    if ($latest_id !== null) {
        $where_conditions[] = "id > " . intval($latest_id);
    }
    
    // Safeguard: if both conditions exist and would create impossible query, prioritize latest_id (newer data)
    if ($last_id && $latest_id !== null && intval($last_id) <= intval($latest_id)) {
        $where_conditions = ["id > " . intval($latest_id)]; // Only use latest_id
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    $sql = "SELECT
        be.id,
        be.event_time,
        be.direction,
        UPPER(be.proto) as proto,
        be.src_ip_id,
        src_ip.ip_address src_ip_address,
        be.dst_ip_id,
        dst_ip.ip_address dst_ip_address,
        be.wan_ip_id,
        wan_ip.ip_address wan_ip_address,
        be.src_port,
        be.dst_port,
        be.iface_in,
        be.iface_out,
        be.wan_ip_id AS primary_ip_id,
        wan_ip.ip_address AS primary_ip,
        GROUP_CONCAT(DISTINCT d.domain ORDER BY dia.last_seen DESC, d.domain SEPARATOR '|') AS all_hostnames
    FROM (
        SELECT * FROM blocked_events {$where_clause} ORDER BY id DESC LIMIT {$limit}
    ) be
    LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN ip_addresses wan_ip ON be.wan_ip_id = wan_ip.id
    LEFT JOIN domain_ip_addresses dia ON dia.ip_address_id = be.wan_ip_id 
        AND dia.last_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    LEFT JOIN domains d ON dia.domain_id = d.id
    GROUP BY be.id
    ORDER BY be.id DESC";

    $result = $mysqli->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            "error" => "Database query failed",
            "message" => $mysqli->error
        ]);
        exit;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => intval($row['id']),
            'primary_ip' => $row['primary_ip'],
            'primary_ip_id' => intval($row['primary_ip_id']),
            'all_hostnames' => $row['all_hostnames'],
            'latest_event_time' => $row['event_time'],
            'latest_direction' => $row['direction'],
            'latest_proto' => $row['proto'],
            'latest_src_ip' => $row['src_ip_address'],
            'latest_dst_ip' => $row['dst_ip_address'],
            'latest_iface_in' => $row['iface_in'],
            'latest_iface_out' => $row['iface_out'],
            //'latest_message' => substr($row['message'], 0, 200) . (strlen($row['message']) > 200 ? '...' : ''),
        ];
    }

    $result->free();
    $mysqli->close();

    // Output the JSON
    echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Exception occurred",
        "message" => $e->getMessage()
    ]);
}
?>
