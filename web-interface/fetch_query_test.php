<?php
header("Content-Type: application/json");

// Include database configuration and connection
require_once __DIR__ . '/zoplog_config.php';

try {
    // Query to get the 10 most recent records from blocked_events table
    $sql = "SELECT
        be.id,
        be.event_time,
        be.direction,
        UPPER(be.proto) as proto,
        be.src_ip_id,
        be.dst_ip_id,
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
    ) AND bi.last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    LEFT JOIN blocklist_domains bd ON bi.blocklist_domain_id = bd.id
    GROUP BY be.id, be.event_time, be.direction, be.proto, be.src_ip_id, be.dst_ip_id, 
             be.src_port, be.dst_port, be.iface_in, be.iface_out, be.message
    ORDER BY be.event_time DESC
    LIMIT 30";

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
            'event_time' => $row['event_time'],
            'direction' => $row['direction'],
            'proto' => $row['proto'],
            'src_ip_id' => intval($row['src_ip_id']),
            'dst_ip_id' => intval($row['dst_ip_id']),
            'primary_ip_id' => intval($row['primary_ip_id']),
            'primary_ip' => $row['primary_ip'],
            'all_hostnames' => $row['all_hostnames'],
            'src_port' => intval($row['src_port'] ?? 0),
            'dst_port' => intval($row['dst_port'] ?? 0),
            'iface_in' => $row['iface_in'],
            'iface_out' => $row['iface_out'],
            'message' => $row['message']
        ];
    }

    // Return the results as JSON
    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "data" => $rows
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Exception occurred",
        "message" => $e->getMessage()
    ]);
} finally {
    // Close the database connection
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>