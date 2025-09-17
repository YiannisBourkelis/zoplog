<?php
header("Content-Type: application/json");

// Include database configuration and connection
require_once __DIR__ . '/zoplog_config.php';

try {
    // Query to aggregate blocked events by primary IP with hostname information
    $sql = "SELECT
        primary_ip_data.primary_ip_id,
        primary_ip_data.primary_ip,
        GROUP_CONCAT(DISTINCT bd.domain ORDER BY bd.domain SEPARATOR '|') AS all_hostnames,
        primary_ip_data.latest_event_time,
        latest_event.direction AS latest_direction,
        UPPER(latest_event.proto) AS latest_proto,
        latest_event.src_ip AS latest_src_ip,
        latest_event.dst_ip AS latest_dst_ip,
        latest_event.iface_in AS latest_iface_in,
        latest_event.iface_out AS latest_iface_out,
        latest_event.message AS latest_message,
        0 AS cnt_url_blocklists,
        0 AS cnt_manual_system_blocklists
    FROM (
        -- Subquery to aggregate by primary IP
        SELECT
            CASE WHEN be.direction = 'OUT' THEN be.dst_ip_id WHEN be.direction = 'IN' THEN be.src_ip_id ELSE be.dst_ip_id END AS primary_ip_id,
            CASE WHEN be.direction = 'OUT' THEN dst_ip.ip_address WHEN be.direction = 'IN' THEN src_ip.ip_address ELSE dst_ip.ip_address END AS primary_ip,
            MAX(be.event_time) AS latest_event_time
        FROM blocked_events be
        LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
        LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
        GROUP BY primary_ip_id
        ORDER BY latest_event_time DESC
        LIMIT 30
    ) AS primary_ip_data
    -- Join to get hostname information
    LEFT JOIN blocked_ips bi ON bi.ip_id = primary_ip_data.primary_ip_id AND bi.last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    LEFT JOIN blocklist_domains bd ON bi.blocklist_domain_id = bd.id
    -- Join to get latest event details
    LEFT JOIN (
        SELECT
            CASE WHEN be.direction = 'OUT' THEN be.dst_ip_id WHEN be.direction = 'IN' THEN be.src_ip_id ELSE be.dst_ip_id END AS primary_ip_id,
            be.direction,
            be.proto,
            src_ip.ip_address AS src_ip,
            dst_ip.ip_address AS dst_ip,
            be.iface_in,
            be.iface_out,
            be.message
        FROM blocked_events be
        LEFT JOIN ip_addresses src_ip ON be.src_ip_id = src_ip.id
        LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
        INNER JOIN (
            SELECT
                CASE WHEN direction = 'OUT' THEN dst_ip_id WHEN direction = 'IN' THEN src_ip_id ELSE dst_ip_id END AS primary_ip_id,
                MAX(event_time) AS max_time
            FROM blocked_events
            GROUP BY CASE WHEN direction = 'OUT' THEN dst_ip_id WHEN direction = 'IN' THEN src_ip_id ELSE dst_ip_id END
        ) latest_times ON (
            CASE WHEN be.direction = 'OUT' THEN be.dst_ip_id WHEN be.direction = 'IN' THEN be.src_ip_id ELSE be.dst_ip_id END = latest_times.primary_ip_id
            AND be.event_time = latest_times.max_time
        )
    ) AS latest_event ON latest_event.primary_ip_id = primary_ip_data.primary_ip_id
    GROUP BY primary_ip_data.primary_ip_id, primary_ip_data.primary_ip, primary_ip_data.latest_event_time,
             latest_event.direction, latest_event.proto, latest_event.src_ip, latest_event.dst_ip,
             latest_event.iface_in, latest_event.iface_out, latest_event.message
    ORDER BY primary_ip_data.latest_event_time DESC";

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
            'all_hostnames' => $row['all_hostnames']." ---sddsdfsfsdf",
            'latest_event_time' => $row['latest_event_time'],
            'latest_direction' => $row['latest_direction'],
            'latest_proto' => $row['latest_proto'],
            'latest_src_ip' => $row['latest_src_ip'],
            'latest_dst_ip' => $row['latest_dst_ip'],
            'latest_iface_in' => $row['latest_iface_in'],
            'latest_iface_out' => $row['latest_iface_out'],
            'latest_message' => $row['latest_message'],
            'cnt_url_blocklists' => intval($row['cnt_url_blocklists']),
            'cnt_manual_system_blocklists' => intval($row['cnt_manual_system_blocklists'])
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
