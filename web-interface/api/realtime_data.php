<?php
// api/realtime_data.php - Real-time data API for complete dashboard
header('Content-Type: application/json');

require_once __DIR__ . '/../zoplog_config.php';

// Start session to store system timeline data
session_start();

// System Resources Monitoring Function
function getSystemMetrics() {
    $metrics = [];
    
    // CPU Usage and Details
    $load = sys_getloadavg();
    $cpuCores = (int)shell_exec('nproc') ?: 1;
    $cpuUsage = min(100, ($load[0] / $cpuCores) * 100);
    $metrics['cpu'] = round($cpuUsage, 1);
    $metrics['cpu_cores'] = $cpuCores;
    $metrics['cpu_load_1'] = round($load[0], 2);
    $metrics['cpu_load_5'] = round($load[1], 2);
    $metrics['cpu_load_15'] = round($load[2], 2);
    
    // CPU frequency (if available)
    $cpuFreq = @file_get_contents('/proc/cpuinfo');
    if ($cpuFreq && preg_match('/cpu MHz\s*:\s*([\d.]+)/', $cpuFreq, $matches)) {
        $metrics['cpu_freq'] = round($matches[1], 0);
    } else {
        $metrics['cpu_freq'] = 0;
    }
    
    // Memory Usage with detailed info
    $memInfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $memTotal);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $memAvailable);
    preg_match('/MemFree:\s+(\d+)/', $memInfo, $memFree);
    preg_match('/Buffers:\s+(\d+)/', $memInfo, $buffers);
    preg_match('/Cached:\s+(\d+)/', $memInfo, $cached);
    
    if ($memTotal && $memAvailable) {
        $totalMem = $memTotal[1]; // KB
        $availableMem = $memAvailable[1]; // KB
        $usedMem = $totalMem - $availableMem;
        $memUsage = ($usedMem / $totalMem) * 100;
        
        $metrics['memory'] = round($memUsage, 1);
        $metrics['memory_total_mb'] = round($totalMem / 1024, 0);
        $metrics['memory_used_mb'] = round($usedMem / 1024, 0);
        $metrics['memory_free_mb'] = round($availableMem / 1024, 0);
        $metrics['memory_buffers_mb'] = round(($buffers[1] ?? 0) / 1024, 0);
        $metrics['memory_cached_mb'] = round(($cached[1] ?? 0) / 1024, 0);
    } else {
        $metrics['memory'] = 0;
        $metrics['memory_total_mb'] = 0;
        $metrics['memory_used_mb'] = 0;
        $metrics['memory_free_mb'] = 0;
        $metrics['memory_buffers_mb'] = 0;
        $metrics['memory_cached_mb'] = 0;
    }
    
    // Disk Usage with detailed info (root filesystem where DB is located)
    $diskTotal = disk_total_space('/');
    $diskFree = disk_free_space('/');
    if ($diskTotal && $diskFree) {
        $diskUsed = $diskTotal - $diskFree;
        $diskUsage = ($diskUsed / $diskTotal) * 100;
        
        $metrics['disk'] = round($diskUsage, 1);
        $metrics['disk_total_gb'] = round($diskTotal / (1024*1024*1024), 1);
        $metrics['disk_used_gb'] = round($diskUsed / (1024*1024*1024), 1);
        $metrics['disk_free_gb'] = round($diskFree / (1024*1024*1024), 1);
    } else {
        $metrics['disk'] = 0;
        $metrics['disk_total_gb'] = 0;
        $metrics['disk_used_gb'] = 0;
        $metrics['disk_free_gb'] = 0;
    }
    
    // Disk I/O Statistics
    $diskStats = @file_get_contents('/proc/diskstats');
    $metrics['disk_read_ops'] = 0;
    $metrics['disk_write_ops'] = 0;
    $metrics['disk_read_mb'] = 0;
    $metrics['disk_write_mb'] = 0;
    
    if ($diskStats) {
        $lines = explode("\n", $diskStats);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 14) {
                $device = $parts[2];
                // Look for main disk devices (sda, nvme0n1, mmcblk, etc.) - exclude partitions and loop devices
                if (preg_match('/^(sda|sdb|sdc|nvme\d+n\d+|vda|hda|mmcblk\d+)$/', $device)) {
                    $metrics['disk_read_ops'] += (int)$parts[3];
                    $metrics['disk_write_ops'] += (int)$parts[7];
                    $metrics['disk_read_mb'] += round(((int)$parts[5] * 512) / (1024*1024), 1);
                    $metrics['disk_write_mb'] += round(((int)$parts[9] * 512) / (1024*1024), 1);
                }
            }
        }
    }
    
    // Network Usage (approximate based on interface stats)
    $netStats = @file_get_contents('/proc/net/dev');
    $networkUsage = 0;
    $metrics['network_rx_mb'] = 0;
    $metrics['network_tx_mb'] = 0;
    
    if ($netStats) {
        $lines = explode("\n", $netStats);
        $totalBytes = 0;
        $interfaces = 0;
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false && !preg_match('/lo:|virbr|docker/', $line)) {
                $parts = preg_split('/\s+/', trim(substr($line, strpos($line, ':') + 1)));
                if (count($parts) >= 9) {
                    $rxBytes = (int)$parts[0];
                    $txBytes = (int)$parts[8];
                    $metrics['network_rx_mb'] += round($rxBytes / (1024*1024), 1);
                    $metrics['network_tx_mb'] += round($txBytes / (1024*1024), 1);
                    $totalBytes += $rxBytes + $txBytes;
                    $interfaces++;
                }
            }
        }
        // Estimate network usage as percentage (very rough approximation)
        if ($interfaces > 0 && $totalBytes > 0) {
            // Use fmod for float modulo to avoid implicit float->int conversion deprecation
            $networkUsage = min(100.0, fmod(($totalBytes / (1024*1024*1024)), 100.0)); // Rough estimate without int cast
        }
    }
    $metrics['network'] = round($networkUsage, 1);
    
    // System uptime
    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime) {
        $uptimeSeconds = (int)explode(' ', $uptime)[0];
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);
        $metrics['uptime'] = "{$days}d {$hours}h {$minutes}m";
    } else {
        $metrics['uptime'] = 'Unknown';
    }
    
    $metrics['timestamp'] = gmdate('H:i:s'); // Use GMT/UTC with seconds for precision
    return $metrics;
}

// Get all summary statistics
$allowedRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM packet_logs");
$allowedRequests = $allowedRes->fetch_assoc()["cnt"];

$blockedRes = $mysqli->query("
    SELECT COUNT(DISTINCT CONCAT(
        COALESCE(src_ip_id, ''), '-',
        COALESCE(dst_ip_id, ''), '-', 
        COALESCE(dst_port, ''), '-',
        FLOOR(UNIX_TIMESTAMP(event_time) / 30) -- 30-second dedup window
    )) AS cnt 
    FROM blocked_events
");
$blockedRequests = $blockedRes->fetch_assoc()["cnt"];
$totalRequests = $allowedRequests + $blockedRequests;

// Unique IPs
$uniqueRes = $mysqli->query("
    SELECT COUNT(DISTINCT src_ip_id) + COUNT(DISTINCT dst_ip_id) AS cnt 
    FROM packet_logs
");
$uniqueIPs = $uniqueRes->fetch_assoc()["cnt"];

// Top hosts (last 5)
$topHostsRes = $mysqli->query("
    SELECT d.domain, COUNT(*) AS cnt 
    FROM packet_logs p
    LEFT JOIN domains d ON p.domain_id = d.id
    WHERE d.domain IS NOT NULL
    GROUP BY d.domain
    ORDER BY cnt DESC
    LIMIT 5
");
$topHosts = [];
while ($row = $topHostsRes->fetch_assoc()) {
    $topHosts[] = $row;
}

// Get allowed requests from packet_logs (last 10 minutes, per minute - minute-aligned)
$allowedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(packet_timestamp, '%H:%i') AS minute, COUNT(*) AS cnt
    FROM packet_logs
    WHERE packet_timestamp >= DATE_SUB(DATE_SUB(NOW(), INTERVAL MINUTE(NOW()) MINUTE), INTERVAL 10 MINUTE)
    GROUP BY minute
    ORDER BY minute ASC
");
$allowedTimeline = [];
while ($row = $allowedTimelineRes->fetch_assoc()) {
    $allowedTimeline[$row['minute']] = $row['cnt'];
}

// Get blocked requests from blocked_events (normalized - group similar requests within 30-second windows)
$blockedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(event_time, '%H:%i') AS minute, 
           COUNT(DISTINCT CONCAT(
               COALESCE(src_ip_id, ''), '-',
               COALESCE(dst_ip_id, ''), '-', 
               COALESCE(dst_port, ''), '-',
               FLOOR(UNIX_TIMESTAMP(event_time) / 30) -- 30-second dedup window
           )) AS cnt
    FROM blocked_events
    WHERE event_time >= DATE_SUB(DATE_SUB(NOW(), INTERVAL MINUTE(NOW()) MINUTE), INTERVAL 10 MINUTE)
    GROUP BY minute
    ORDER BY minute ASC
");
$blockedTimeline = [];
while ($row = $blockedTimelineRes->fetch_assoc()) {
    $blockedTimeline[$row['minute']] = $row['cnt'];
}

// Get all unique minutes from both datasets
$allMinutes = array_unique(array_merge(array_keys($allowedTimeline), array_keys($blockedTimeline)));
sort($allMinutes);

// Create combined timeline
$timeline = [];
foreach ($allMinutes as $minute) {
    $allowed = $allowedTimeline[$minute] ?? 0;
    $blocked = $blockedTimeline[$minute] ?? 0;
    $total = $allowed + $blocked;
    
    $timeline[] = [
        'minute' => $minute,
        'allowed' => $allowed,
        'blocked' => $blocked,
        'total' => $total
    ];
}

// If no data in last 10 minutes, create empty timeline to show the graph
if (empty($timeline)) {
    $currentTime = time();
    for ($i = 9; $i >= 0; $i--) {
        $minute = date('H:i', $currentTime - ($i * 60));
        $timeline[] = [
            'minute' => $minute,
            'allowed' => 0,
            'blocked' => 0,
            'total' => 0
        ];
    }
}

// Calculate totals for the last 10 minutes for the pie chart
$totalAllowed10min = array_sum(array_column($timeline, 'allowed'));
$totalBlocked10min = array_sum(array_column($timeline, 'blocked'));
$total10min = $totalAllowed10min + $totalBlocked10min;

// Calculate percentages
$allowedPercentage = $total10min > 0 ? round(($totalAllowed10min / $total10min) * 100, 1) : 0;
$blockedPercentage = $total10min > 0 ? round(($totalBlocked10min / $total10min) * 100, 1) : 0;

// Get system metrics
$systemMetrics = getSystemMetrics();

// Manage system timeline in session (rolling 60-second window)
$currentTime = time();
$currentSecond = gmdate('H:i:s', $currentTime); // Use GMT/UTC with seconds for precision

// Initialize or get existing timeline from session
if (!isset($_SESSION['system_timeline'])) {
    $_SESSION['system_timeline'] = [];
}

// Always add a new data point every 2 seconds
$_SESSION['system_timeline'][] = [
    'timestamp' => $currentSecond,
    'cpu' => $systemMetrics['cpu'],
    'memory' => $systemMetrics['memory'],
    'disk' => $systemMetrics['disk'],
    'network' => $systemMetrics['network']
];

// Clean up old entries (keep only last 60 seconds - about 30 data points at 2-second intervals)
$sixtySecondsAgo = $currentTime - 60;
$_SESSION['system_timeline'] = array_filter($_SESSION['system_timeline'], function($entry) use ($sixtySecondsAgo) {
    $entryTime = strtotime(gmdate('Y-m-d') . ' ' . $entry['timestamp']);
    return $entryTime >= $sixtySecondsAgo;
});

// Re-index array after filtering
$_SESSION['system_timeline'] = array_values($_SESSION['system_timeline']);

// Limit to maximum 30 data points to prevent memory issues
if (count($_SESSION['system_timeline']) > 30) {
    $_SESSION['system_timeline'] = array_slice($_SESSION['system_timeline'], -30);
}

// Use the session timeline data
$systemTimeline = $_SESSION['system_timeline'];

// Return all the data
echo json_encode([
    'summary' => [
        'total_requests' => $totalRequests,
        'allowed_requests' => $allowedRequests,
        'blocked_requests' => $blockedRequests,
        'unique_ips' => $uniqueIPs
    ],
    'timeline' => $timeline,
    'traffic_breakdown' => [
        'allowed' => $totalAllowed10min,
        'blocked' => $totalBlocked10min,
        'total' => $total10min,
        'allowed_percentage' => $allowedPercentage,
        'blocked_percentage' => $blockedPercentage
    ],
    'system_metrics' => $systemMetrics,
    'top_hosts' => $topHosts,
    'timestamp' => date('Y-m-d H:i:s')
]);

$mysqli->close();
?>
