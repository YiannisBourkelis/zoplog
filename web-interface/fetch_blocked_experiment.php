<?php
header("Content-Type: application/json");

// Include database configuration and connection
require_once __DIR__ . '/zoplog_config.php';

try {
    // Accept cursor and limit from GET
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 30;
    if ($limit <= 0 || $limit > 500) $limit = 30;
    $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : null;
    $where_clause = $last_id ? "WHERE be.id < " . $last_id : "";

    // Main query: fetch blocked events with pagination
    $sql = "SELECT
        pid.primary_ip_id,
        pid.primary_ip,
        GROUP_CONCAT(DISTINCT bd.domain ORDER BY bd.domain SEPARATOR '|') AS all_hostnames,
        MAX(pid.id) AS latest_event_id,
        MAX(pid.event_time) AS latest_event_time,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.direction ORDER BY pid.event_time DESC), ',', 1) AS latest_direction,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.proto ORDER BY pid.event_time DESC), ',', 1) AS latest_proto,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.src_ip_id ORDER BY pid.event_time DESC), ',', 1) AS latest_src_ip,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.dst_ip_id ORDER BY pid.event_time DESC), ',', 1) AS latest_dst_ip,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.iface_in ORDER BY pid.event_time DESC), ',', 1) AS latest_iface_in,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.iface_out ORDER BY pid.event_time DESC), ',', 1) AS latest_iface_out,
        SUBSTRING_INDEX(GROUP_CONCAT(pid.message ORDER BY pid.event_time DESC), ',', 1) AS latest_message,
        COUNT(*) AS event_count
    FROM (
        SELECT
            CASE WHEN direction = 'IN' THEN src_ip_id
                 WHEN direction = 'OUT' THEN dst_ip_id
                 ELSE src_ip_id END AS primary_ip_id,
            CASE WHEN direction = 'IN' THEN src_ip.ip_address
                 WHEN direction = 'OUT' THEN dst_ip.ip_address
                 ELSE src_ip.ip_address END AS primary_ip,
            be.id,
            be.event_time,
            be.direction,
            be.proto,
            be.src_ip_id,
            be.dst_ip_id,
            be.iface_in,
            be.iface_out,
            be.message
        FROM blocked_events be
        LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
        LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
        " . ($last_id ? "WHERE be.id < " . intval($last_id) : "") . "
    ) pid
    LEFT JOIN blocked_ips bi ON bi.ip_id = pid.primary_ip_id AND bi.last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    LEFT JOIN blocklist_domains bd ON bi.blocklist_domain_id = bd.id
    GROUP BY pid.primary_ip_id, pid.primary_ip
    ORDER BY latest_event_id DESC
    LIMIT " . $limit;

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
            'primary_ip' => $row['primary_ip'],
            'primary_ip_id' => intval($row['primary_ip_id']),
            'all_hostnames' => $row['all_hostnames'],
            'latest_event_time' => $row['latest_event_time'],
            'event_count' => intval($row['event_count']),
            'latest_direction' => $row['latest_direction'],
            'latest_proto' => $row['latest_proto'],
            'latest_src_ip' => $row['latest_src_ip'],
            'latest_dst_ip' => $row['latest_dst_ip'],
            'latest_iface_in' => $row['latest_iface_in'],
            'latest_iface_out' => $row['latest_iface_out'],
            'latest_message' => $row['latest_message'],
            'cnt_url_blocklists' => 0,
            'cnt_manual_system_blocklists' => 0
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
