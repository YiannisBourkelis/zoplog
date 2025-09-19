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
        $where_conditions[] = "be.id < " . intval($last_id);
    }
    if ($latest_id) {
        $where_conditions[] = "be.id > " . intval($latest_id);
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
        be.src_port,
        be.dst_port,
        be.iface_in,
        be.iface_out,
        be.message,
        CASE
            WHEN be.direction = 'IN' THEN be.src_ip_id
            WHEN be.direction = 'OUT' THEN be.dst_ip_id
            ELSE be.src_ip_id
        END AS primary_ip_id,
        CASE
            WHEN be.direction = 'IN' THEN src_ip.ip_address
            WHEN be.direction = 'OUT' THEN dst_ip.ip_address
            ELSE src_ip.ip_address
        END AS primary_ip,
        GROUP_CONCAT(DISTINCT bd.domain ORDER BY bd.domain SEPARATOR '|') AS all_hostnames
    FROM blocked_events be
    LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN blocked_ips bi ON bi.ip_id = (
        CASE
            WHEN be.direction = 'IN' THEN be.src_ip_id
            WHEN be.direction = 'OUT' THEN be.dst_ip_id
            ELSE be.src_ip_id
        END
    ) AND bi.last_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    LEFT JOIN blocklist_domains bd ON bi.blocklist_domain_id = bd.id
    {$where_clause}
    GROUP BY be.id, be.event_time, be.direction, be.proto, be.src_ip_id, be.dst_ip_id,
             be.src_port, be.dst_port, be.iface_in, be.iface_out, be.message
    ORDER BY be.id DESC
    LIMIT {$limit}";


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
            'latest_message' => $row['message'],
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
