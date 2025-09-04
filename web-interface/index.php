<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use centralized DB config/connection
require_once __DIR__ . '/zoplog_config.php';

// Total requests (allowed + blocked normalized)
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

// Top hosts
$topHostsRes = $mysqli->query("
    SELECT h.hostname, COUNT(*) AS cnt 
    FROM packet_logs p
    LEFT JOIN hostnames h ON p.hostname_id = h.id
    WHERE h.hostname IS NOT NULL
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 5
");
$topHosts = [];
while ($row = $topHostsRes->fetch_assoc()) {
    $topHosts[] = $row;
}

// Requests over time (last 10 minutes, per minute)
// Get allowed requests from packet_logs
$allowedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(packet_timestamp, '%H:%i') AS minute, COUNT(*) AS cnt
    FROM packet_logs
    WHERE packet_timestamp >= NOW() - INTERVAL 10 MINUTE
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
    WHERE event_time >= NOW() - INTERVAL 10 MINUTE
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

// --- NEW STATS ---

// Top 200 hosts (30 days)
$top30 = $mysqli->query("
    SELECT h.hostname, COUNT(*) as cnt
    FROM packet_logs p
    JOIN hostnames h ON p.hostname_id = h.id
    WHERE p.packet_timestamp >= NOW() - INTERVAL 30 DAY
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 200
");

// Top 200 hosts (365 days)
$top365 = $mysqli->query("
    SELECT h.hostname, COUNT(*) as cnt
    FROM packet_logs p
    JOIN hostnames h ON p.hostname_id = h.id
    WHERE p.packet_timestamp >= NOW() - INTERVAL 365 DAY
    GROUP BY h.hostname
    ORDER BY cnt DESC
    LIMIT 200
");

// Top 200 blocked hosts (30 days) - normalized to reduce retry noise
$topBlocked30 = $mysqli->query("
    SELECT 
        COALESCE(h.hostname, dst_ip.ip_address, 'Unknown') as blocked_host,
        COUNT(DISTINCT CONCAT(
            COALESCE(src_ip_id, ''), '-',
            COALESCE(dst_ip_id, ''), '-', 
            COALESCE(dst_port, ''), '-',
            FLOOR(UNIX_TIMESTAMP(event_time) / 30)
        )) as cnt
    FROM blocked_events be
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN hostnames h ON h.ip_id = dst_ip.id
    WHERE be.event_time >= NOW() - INTERVAL 30 DAY
    GROUP BY COALESCE(h.hostname, dst_ip.ip_address)
    ORDER BY cnt DESC
    LIMIT 200
");

// Top 200 blocked hosts (365 days) - normalized to reduce retry noise  
$topBlocked365 = $mysqli->query("
    SELECT 
        COALESCE(h.hostname, dst_ip.ip_address, 'Unknown') as blocked_host,
        COUNT(DISTINCT CONCAT(
            COALESCE(src_ip_id, ''), '-',
            COALESCE(dst_ip_id, ''), '-', 
            COALESCE(dst_port, ''), '-',
            FLOOR(UNIX_TIMESTAMP(event_time) / 30)
        )) as cnt
    FROM blocked_events be
    LEFT JOIN ip_addresses dst_ip ON be.dst_ip_id = dst_ip.id
    LEFT JOIN hostnames h ON h.ip_id = dst_ip.id
    WHERE be.event_time >= NOW() - INTERVAL 365 DAY
    GROUP BY COALESCE(h.hostname, dst_ip.ip_address)
    ORDER BY cnt DESC
    LIMIT 200
");

// Browser stats - detailed categorization
$uaRes = $mysqli->query("
    SELECT ua.user_agent, COUNT(*) as cnt
    FROM packet_logs p
    JOIN user_agents ua ON p.user_agent_id = ua.id
    WHERE ua.user_agent IS NOT NULL
    GROUP BY ua.user_agent
    ORDER BY cnt DESC
");
$detailedBrowsers = [];
while ($row = $uaRes->fetch_assoc()) {
    $ua = $row["user_agent"];
    $cnt = $row["cnt"];
    if (!$ua) continue;
    
    $browserName = "Other";
    if (stripos($ua, "chrome") !== false && stripos($ua, "edg") === false && stripos($ua, "opr") === false) {
        // Chrome versions
        if (preg_match('/Chrome\/(\d+)/', $ua, $matches)) {
            $browserName = "Chrome " . $matches[1];
        } else {
            $browserName = "Chrome";
        }
    } elseif (stripos($ua, "firefox") !== false) {
        // Firefox versions
        if (preg_match('/Firefox\/(\d+)/', $ua, $matches)) {
            $browserName = "Firefox " . $matches[1];
        } else {
            $browserName = "Firefox";
        }
    } elseif (stripos($ua, "safari") !== false && stripos($ua, "chrome") === false) {
        // Safari versions
        if (preg_match('/Version\/(\d+)/', $ua, $matches)) {
            $browserName = "Safari " . $matches[1];
        } else {
            $browserName = "Safari";
        }
    } elseif (stripos($ua, "edg") !== false) {
        // Edge versions
        if (preg_match('/Edg\/(\d+)/', $ua, $matches)) {
            $browserName = "Edge " . $matches[1];
        } else {
            $browserName = "Edge";
        }
    } elseif (stripos($ua, "opera") !== false || stripos($ua, "opr/") !== false) {
        // Opera versions
        if (preg_match('/OPR\/(\d+)/', $ua, $matches)) {
            $browserName = "Opera " . $matches[1];
        } else {
            $browserName = "Opera";
        }
    }
    
    if (!isset($detailedBrowsers[$browserName])) {
        $detailedBrowsers[$browserName] = 0;
    }
    $detailedBrowsers[$browserName] += $cnt;
}

// Sort browsers by count and get top 20
arsort($detailedBrowsers);
$topBrowsers = array_slice($detailedBrowsers, 0, 20, true);
$otherBrowsersCount = array_sum(array_slice($detailedBrowsers, 20));

// Add "Other" category if there are more than 20 browsers
if ($otherBrowsersCount > 0) {
    $topBrowsers["Other"] = $otherBrowsersCount;
}

// Language stats
$langRes = $mysqli->query("
    SELECT al.accept_language
    FROM packet_logs p
    JOIN accept_languages al ON p.accept_language_id = al.id
    WHERE al.accept_language IS NOT NULL
");
$langs = [];
while ($row = $langRes->fetch_assoc()) {
    $lang = substr($row["accept_language"], 0, 2);
    if (!$lang) continue;
    if (!isset($langs[$lang])) $langs[$lang] = 0;
    $langs[$lang]++;
}
arsort($langs);
$langs = array_slice($langs, 0, 10, true);

// System Resources Monitoring
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
                // Look for main disk devices (sda, nvme0n1, etc.) - exclude partitions and loop devices
                if (preg_match('/^(sda|sdb|sdc|nvme\d+n\d+|vda|hda)$/', $device)) {
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
    
    $metrics['timestamp'] = date('H:i');
    return $metrics;
}

// Collect current system metrics
$currentMetrics = getSystemMetrics();

// For demo purposes, create sample historical data (in production, you'd store this in DB)
$systemTimeline = [];
$currentTime = time();
for ($i = 9; $i >= 0; $i--) {
    $timestamp = date('H:i', $currentTime - ($i * 60));
    // Generate sample data with some variation around current values
    $cpuVariation = rand(-10, 10);
    $memVariation = rand(-5, 5);
    $diskVariation = rand(-2, 2);
    $netVariation = rand(-15, 15);
    
    $systemTimeline[] = [
        'timestamp' => $timestamp,
        'cpu' => max(0, min(100, $currentMetrics['cpu'] + $cpuVariation)),
        'memory' => max(0, min(100, $currentMetrics['memory'] + $memVariation)),
        'disk' => max(0, min(100, $currentMetrics['disk'] + $diskVariation)),
        'network' => max(0, min(100, $currentMetrics['network'] + $netVariation))
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Network Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100 text-gray-900">
  <?php include "menu.php"; ?>
<div class="container mx-auto py-6">
    <h1 class="text-3xl font-bold mb-6">📊 Network Dashboard</h1>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Total Requests</h2>
        <p class="text-3xl font-bold text-blue-600"><?= number_format($totalRequests) ?></p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Allowed Requests</h2>
        <p class="text-3xl font-bold text-green-600"><?= number_format($allowedRequests) ?></p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold flex items-center justify-center">
          Blocked Requests
          <span class="ml-1 text-xs text-gray-500" title="Normalized - similar requests within 30-second windows are grouped to reduce retry noise">*</span>
        </h2>
        <p class="text-3xl font-bold text-red-600"><?= number_format($blockedRequests) ?></p>
        <p class="text-xs text-gray-500 mt-1">Deduplicated</p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Unique IPs</h2>
        <p class="text-3xl font-bold text-purple-600"><?= number_format($uniqueIPs) ?></p>
      </div>
    </div>

    <!-- Charts grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Traffic breakdown pie chart -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Traffic Breakdown (Last 10 min)</h2>
        <canvas id="trafficChart"></canvas>
        <div class="mt-4 text-center text-sm text-gray-600">
          <div class="flex justify-center space-x-4">
            <span class="flex items-center">
              <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
              Allowed: <?= number_format($totalAllowed10min) ?> (<?= $allowedPercentage ?>%)
            </span>
            <span class="flex items-center">
              <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
              Blocked: <?= number_format($totalBlocked10min) ?> (<?= $blockedPercentage ?>%) <span class="text-xs">*normalized</span>
            </span>
          </div>
        </div>
      </div>

      <!-- Timeline chart -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Requests Over Time (Last 10 min)</h2>
        <canvas id="timelineChart"></canvas>
      </div>
    </div>

    <!-- System Resources Chart -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">System Resources (Last 10 min)</h2>
      <canvas id="systemChart"></canvas>
      
      <!-- Detailed System Information Grid -->
      <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- CPU Details -->
        <div class="bg-blue-50 rounded-lg p-4">
          <div class="text-blue-700 font-semibold text-lg mb-2">🔷 CPU</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-blue-600"><?= $currentMetrics['cpu'] ?>%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Cores:</span>
              <span class="font-medium"><?= $currentMetrics['cpu_cores'] ?></span>
            </div>
            <?php if ($currentMetrics['cpu_freq'] > 0): ?>
            <div class="flex justify-between">
              <span class="text-gray-600">Frequency:</span>
              <span class="font-medium"><?= $currentMetrics['cpu_freq'] ?> MHz</span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between">
              <span class="text-gray-600">Load Avg:</span>
              <span class="font-medium"><?= $currentMetrics['cpu_load_1'] ?></span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>5m/15m:</span>
              <span><?= $currentMetrics['cpu_load_5'] ?>/<?= $currentMetrics['cpu_load_15'] ?></span>
            </div>
          </div>
        </div>

        <!-- Memory Details -->
        <div class="bg-green-50 rounded-lg p-4">
          <div class="text-green-700 font-semibold text-lg mb-2">🟢 Memory</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-green-600"><?= $currentMetrics['memory'] ?>%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Total:</span>
              <span class="font-medium"><?= number_format($currentMetrics['memory_total_mb']) ?> MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Used:</span>
              <span class="font-medium text-red-600"><?= number_format($currentMetrics['memory_used_mb']) ?> MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Free:</span>
              <span class="font-medium text-green-600"><?= number_format($currentMetrics['memory_free_mb']) ?> MB</span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>Buffers/Cache:</span>
              <span><?= number_format($currentMetrics['memory_buffers_mb']) ?>/<?= number_format($currentMetrics['memory_cached_mb']) ?> MB</span>
            </div>
          </div>
        </div>

        <!-- Disk Details -->
        <div class="bg-yellow-50 rounded-lg p-4">
          <div class="text-yellow-700 font-semibold text-lg mb-2">💾 Disk</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-yellow-600"><?= $currentMetrics['disk'] ?>%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Total:</span>
              <span class="font-medium"><?= $currentMetrics['disk_total_gb'] ?> GB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Used:</span>
              <span class="font-medium text-red-600"><?= $currentMetrics['disk_used_gb'] ?> GB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Free:</span>
              <span class="font-medium text-green-600"><?= $currentMetrics['disk_free_gb'] ?> GB</span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>I/O Total:</span>
              <span><?= $currentMetrics['disk_read_mb'] + $currentMetrics['disk_write_mb'] ?> MB</span>
            </div>
          </div>
        </div>

        <!-- Network & System Details -->
        <div class="bg-purple-50 rounded-lg p-4">
          <div class="text-purple-700 font-semibold text-lg mb-2">🌐 Network & System</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Activity:</span>
              <span class="font-bold text-purple-600"><?= $currentMetrics['network'] ?>%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">RX Total:</span>
              <span class="font-medium"><?= $currentMetrics['network_rx_mb'] ?> MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">TX Total:</span>
              <span class="font-medium"><?= $currentMetrics['network_tx_mb'] ?> MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Uptime:</span>
              <span class="font-medium text-blue-600"><?= $currentMetrics['uptime'] ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Disk I/O Statistics -->
      <div class="mt-4 bg-gray-50 rounded-lg p-4">
        <h3 class="font-semibold text-gray-700 mb-3">💿 Disk I/O Statistics</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div class="text-center">
            <div class="text-gray-600">Read Operations</div>
            <div class="font-bold text-blue-600"><?= number_format($currentMetrics['disk_read_ops']) ?></div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Write Operations</div>
            <div class="font-bold text-red-600"><?= number_format($currentMetrics['disk_write_ops']) ?></div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Data Read</div>
            <div class="font-bold text-green-600"><?= $currentMetrics['disk_read_mb'] ?> MB</div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Data Written</div>
            <div class="font-bold text-orange-600"><?= $currentMetrics['disk_write_mb'] ?> MB</div>
          </div>
        </div>
        <div class="mt-2 text-xs text-gray-500 text-center">
          <i>Note: Disk I/O values are cumulative since system boot. Real-time rates would require periodic sampling.</i>
        </div>
      </div>
    </div>

    <!-- Top Hosts -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">Top Hosts</h2>
      <table id="topHostsTable" class="min-w-full text-sm text-left">
        <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Hostname</th>
            <th class="px-4 py-2">Requests</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topHosts as $host): ?>
            <tr>
              <td class="px-4 py-2"><?= htmlspecialchars($host["hostname"]) ?></td>
              <td class="px-4 py-2"><?= number_format($host["cnt"]) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- EXTENDED SECTION -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Top Hosts 30d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-green-600">Top 200 Hosts (Last 30 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently accessed allowed destinations</p>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $top30->fetch_assoc()): ?>
            <li class="text-sm"><?= htmlspecialchars($row['hostname']) ?> 
                <span class="text-gray-500">(<?= number_format($row['cnt']) ?>)</span></li>
          <?php endwhile; ?>
        </ol>
      </div>

      <!-- Top Hosts 365d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-green-600">Top 200 Hosts (Last 365 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently accessed allowed destinations</p>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $top365->fetch_assoc()): ?>
            <li class="text-sm"><?= htmlspecialchars($row['hostname']) ?> 
                <span class="text-gray-500">(<?= number_format($row['cnt']) ?>)</span></li>
          <?php endwhile; ?>
        </ol>
      </div>
    </div>

    <!-- BLOCKED HOSTS SECTION -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Top Blocked Hosts 30d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-red-600">Top 200 Blocked Hosts (Last 30 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently blocked destinations <span class="text-xs">*normalized</span></p>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $topBlocked30->fetch_assoc()): ?>
            <li class="text-sm"><?= htmlspecialchars($row['blocked_host']) ?> 
                <span class="text-red-500">(<?= number_format($row['cnt']) ?> blocked)</span></li>
          <?php endwhile; ?>
        </ol>
      </div>

      <!-- Top Blocked Hosts 365d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-red-600">Top 200 Blocked Hosts (Last 365 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently blocked destinations <span class="text-xs">*normalized</span></p>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $topBlocked365->fetch_assoc()): ?>
            <li class="text-sm"><?= htmlspecialchars($row['blocked_host']) ?> 
                <span class="text-red-500">(<?= number_format($row['cnt']) ?> blocked)</span></li>
          <?php endwhile; ?>
        </ol>
      </div>
    </div>

    <!-- Browser and Language Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Browsers -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Browser Usage (Top 20)</h2>
        <canvas id="browserChart"></canvas>
      </div>

      <!-- Languages -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Top Languages</h2>
        <canvas id="langChart"></canvas>
      </div>
    </div>
  </div>

<script>
// Chart references for real-time updates
let trafficChart;
let timelineChart;
let systemChart;

// Traffic Breakdown Pie Chart (Last 10 min)
const trafficData = {
  labels: ['Allowed Requests', 'Blocked Requests (Normalized)'],
  datasets: [{
    data: [<?= $totalAllowed10min ?>, <?= $totalBlocked10min ?>],
    backgroundColor: ['#10b981', '#ef4444'],
    borderColor: ['#059669', '#dc2626'],
    borderWidth: 2
  }]
};
trafficChart = new Chart(document.getElementById('trafficChart'), {
  type: 'pie',
  data: trafficData,
  options: {
    responsive: true,
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          padding: 20,
          usePointStyle: true
        }
      },
      title: {
        display: true,
        text: 'Firewall Protection Overview'
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            const label = context.label || '';
            const value = context.parsed;
            const total = <?= $total10min ?>;
            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
            return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
          }
        }
      }
    }
  }
});

// System Resources Chart
const systemData = {
  labels: <?= json_encode(array_column($systemTimeline, "timestamp")) ?>,
  datasets: [
    {
      label: 'CPU Usage %',
      data: <?= json_encode(array_column($systemTimeline, "cpu")) ?>,
      borderColor: '#3b82f6',
      backgroundColor: 'rgba(59, 130, 246, 0.1)',
      fill: false,
      tension: 0.3
    },
    {
      label: 'Memory Usage %',
      data: <?= json_encode(array_column($systemTimeline, "memory")) ?>,
      borderColor: '#10b981',
      backgroundColor: 'rgba(16, 185, 129, 0.1)',
      fill: false,
      tension: 0.3
    },
    {
      label: 'Disk Usage %',
      data: <?= json_encode(array_column($systemTimeline, "disk")) ?>,
      borderColor: '#f59e0b',
      backgroundColor: 'rgba(245, 158, 11, 0.1)',
      fill: false,
      tension: 0.3
    },
    {
      label: 'Network Activity %',
      data: <?= json_encode(array_column($systemTimeline, "network")) ?>,
      borderColor: '#8b5cf6',
      backgroundColor: 'rgba(139, 92, 246, 0.1)',
      fill: false,
      tension: 0.3
    }
  ]
};
systemChart = new Chart(document.getElementById('systemChart'), {
  type: 'line',
  data: systemData,
  options: {
    responsive: true,
    plugins: {
      legend: {
        position: 'top',
      },
      title: {
        display: true,
        text: 'System Resource Utilization'
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        max: 100,
        title: {
          display: true,
          text: 'Usage Percentage (%)'
        }
      },
      x: {
        title: {
          display: true,
          text: 'Time (HH:MM)'
        }
      }
    }
  }
});

// Requests over Time (Line Chart)
const timelineData = {
  labels: <?= json_encode(array_column($timeline, "minute")) ?>,
  datasets: [
    {
      label: 'Allowed Requests',
      data: <?= json_encode(array_column($timeline, "allowed")) ?>,
      borderColor: '#10b981',
      backgroundColor: 'rgba(16, 185, 129, 0.1)',
      fill: false,
      tension: 0.3
    },
    {
      label: 'Blocked Requests (Normalized)',
      data: <?= json_encode(array_column($timeline, "blocked")) ?>,
      borderColor: '#ef4444',
      backgroundColor: 'rgba(239, 68, 68, 0.1)',
      fill: false,
      tension: 0.3
    },
    {
      label: 'Total Requests',
      data: <?= json_encode(array_column($timeline, "total")) ?>,
      borderColor: '#3b82f6',
      backgroundColor: 'rgba(59, 130, 246, 0.1)',
      fill: false,
      tension: 0.3,
      borderDash: [5, 5]
    }
  ]
};
timelineChart = new Chart(document.getElementById('timelineChart'), {
  type: 'line',
  data: timelineData,
  options: {
    responsive: true,
    plugins: {
      legend: {
        position: 'top',
      },
      title: {
        display: true,
        text: 'Network Traffic Over Time'
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        title: {
          display: true,
          text: 'Number of Requests'
        }
      },
      x: {
        title: {
          display: true,
          text: 'Time (HH:MM)'
        }
      }
    }
  }
});

// Browser chart (Pie)
const browserData = <?= json_encode($topBrowsers) ?>;
new Chart(document.getElementById('browserChart'), {
  type: 'pie',
  data: {
    labels: Object.keys(browserData),
    datasets: [{
      data: Object.values(browserData),
      backgroundColor: [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#d946ef',
        '#f97316', '#84cc16', '#06b6d4', '#8b5cf6', '#f59e0b', '#ef4444', '#10b981',
        '#3b82f6', '#d946ef', '#f97316', '#84cc16', '#06b6d4', '#8b5cf6', '#6b7280'
      ]
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: {
        position: 'right',
        labels: {
          boxWidth: 12,
          padding: 10,
          usePointStyle: true
        }
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            const label = context.label || '';
            const value = context.parsed;
            const total = Object.values(browserData).reduce((a, b) => a + b, 0);
            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
            return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
          }
        }
      }
    }
  }
});

// Language chart (Pie)
const langData = <?= json_encode($langs) ?>;
new Chart(document.getElementById('langChart'), {
  type: 'pie',
  data: {
    labels: Object.keys(langData),
    datasets: [{
      data: Object.values(langData),
      backgroundColor: [
        '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#d946ef',
        '#f97316', '#84cc16', '#6b7280'
      ]
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: {
        position: 'right',
        labels: {
          boxWidth: 12,
          padding: 10,
          usePointStyle: true
        }
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            const label = context.label || '';
            const value = context.parsed;
            const total = Object.values(langData).reduce((a, b) => a + b, 0);
            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
            return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
          }
        }
      }
    }
  }
});

// Real-time chart updates every 2 seconds
async function updateChartsRealtime() {
  try {
    const response = await fetch('api/realtime_data.php');
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    const data = await response.json();
    
    // Update summary cards
    if (data.summary) {
      const summary = data.summary;
      
      // Update summary card values
      document.querySelector('.text-blue-600').textContent = summary.total_requests.toLocaleString();
      document.querySelector('.text-green-600').textContent = summary.allowed_requests.toLocaleString();
      document.querySelector('.text-red-600').textContent = summary.blocked_requests.toLocaleString();
      document.querySelector('.text-purple-600').textContent = summary.unique_ips.toLocaleString();
    }
    
    // Update timeline chart
    if (timelineChart && data.timeline) {
      timelineChart.data.labels = data.timeline.map(item => item.minute);
      timelineChart.data.datasets[0].data = data.timeline.map(item => item.allowed);
      timelineChart.data.datasets[1].data = data.timeline.map(item => item.blocked);
      timelineChart.data.datasets[2].data = data.timeline.map(item => item.total);
      timelineChart.update('none'); // 'none' for no animation to make it smoother
    }
    
    // Update traffic breakdown pie chart
    if (trafficChart && data.traffic_breakdown) {
      const breakdown = data.traffic_breakdown;
      trafficChart.data.datasets[0].data = [breakdown.allowed, breakdown.blocked];
      
      // Update tooltip calculation for new totals
      trafficChart.options.plugins.tooltip.callbacks.label = function(context) {
        const label = context.label || '';
        const value = context.parsed;
        const total = breakdown.total;
        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
        return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
      };
      
      trafficChart.update('none'); // 'none' for no animation
      
      // Update the summary text below the pie chart
      const summaryDiv = document.querySelector('#trafficChart').parentElement.querySelector('.mt-4 .flex');
      if (summaryDiv) {
        summaryDiv.innerHTML = `
          <span class="flex items-center">
            <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
            Allowed: ${breakdown.allowed.toLocaleString()} (${breakdown.allowed_percentage}%)
          </span>
          <span class="flex items-center">
            <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
            Blocked: ${breakdown.blocked.toLocaleString()} (${breakdown.blocked_percentage}%) <span class="text-xs">*normalized</span>
          </span>
        `;
      }
    }
    
    // Update system resources chart
    if (systemChart && data.system_timeline) {
      systemChart.data.labels = data.system_timeline.map(item => item.timestamp);
      systemChart.data.datasets[0].data = data.system_timeline.map(item => item.cpu);
      systemChart.data.datasets[1].data = data.system_timeline.map(item => item.memory);
      systemChart.data.datasets[2].data = data.system_timeline.map(item => item.disk);
      systemChart.data.datasets[3].data = data.system_timeline.map(item => item.network);
      systemChart.update('none');
    }
    
    // Update system metrics details
    if (data.system_metrics) {
      const metrics = data.system_metrics;
      
      // Update CPU details
      const cpuSection = document.querySelector('.bg-blue-50');
      if (cpuSection) {
        cpuSection.innerHTML = `
          <div class="text-blue-700 font-semibold text-lg mb-2">🔷 CPU</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-blue-600">${metrics.cpu}%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Cores:</span>
              <span class="font-medium">${metrics.cpu_cores}</span>
            </div>
            ${metrics.cpu_freq > 0 ? `
            <div class="flex justify-between">
              <span class="text-gray-600">Frequency:</span>
              <span class="font-medium">${metrics.cpu_freq} MHz</span>
            </div>
            ` : ''}
            <div class="flex justify-between">
              <span class="text-gray-600">Load Avg:</span>
              <span class="font-medium">${metrics.cpu_load_1}</span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>5m/15m:</span>
              <span>${metrics.cpu_load_5}/${metrics.cpu_load_15}</span>
            </div>
          </div>
        `;
      }
      
      // Update Memory details
      const memorySection = document.querySelector('.bg-green-50');
      if (memorySection) {
        memorySection.innerHTML = `
          <div class="text-green-700 font-semibold text-lg mb-2">🟢 Memory</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-green-600">${metrics.memory}%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Total:</span>
              <span class="font-medium">${metrics.memory_total_mb.toLocaleString()} MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Used:</span>
              <span class="font-medium text-red-600">${metrics.memory_used_mb.toLocaleString()} MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Free:</span>
              <span class="font-medium text-green-600">${metrics.memory_free_mb.toLocaleString()} MB</span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>Buffers/Cache:</span>
              <span>${metrics.memory_buffers_mb.toLocaleString()}/${metrics.memory_cached_mb.toLocaleString()} MB</span>
            </div>
          </div>
        `;
      }
      
      // Update Disk details
      const diskSection = document.querySelector('.bg-yellow-50');
      if (diskSection) {
        diskSection.innerHTML = `
          <div class="text-yellow-700 font-semibold text-lg mb-2">💾 Disk</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Usage:</span>
              <span class="font-bold text-yellow-600">${metrics.disk}%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Total:</span>
              <span class="font-medium">${metrics.disk_total_gb} GB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Used:</span>
              <span class="font-medium text-red-600">${metrics.disk_used_gb} GB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Free:</span>
              <span class="font-medium text-green-600">${metrics.disk_free_gb} GB</span>
            </div>
            <div class="flex justify-between text-xs text-gray-500">
              <span>I/O Total:</span>
              <span>${(metrics.disk_read_mb + metrics.disk_write_mb).toFixed(1)} MB</span>
            </div>
          </div>
        `;
      }
      
      // Update Network & System details
      const networkSection = document.querySelector('.bg-purple-50');
      if (networkSection) {
        networkSection.innerHTML = `
          <div class="text-purple-700 font-semibold text-lg mb-2">🌐 Network & System</div>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-600">Activity:</span>
              <span class="font-bold text-purple-600">${metrics.network}%</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">RX Total:</span>
              <span class="font-medium">${metrics.network_rx_mb} MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">TX Total:</span>
              <span class="font-medium">${metrics.network_tx_mb} MB</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Uptime:</span>
              <span class="font-medium text-blue-600">${metrics.uptime}</span>
            </div>
          </div>
        `;
      }
      
      // Update Disk I/O Statistics
      const diskIOSection = document.querySelector('.bg-gray-50 .grid');
      if (diskIOSection) {
        diskIOSection.innerHTML = `
          <div class="text-center">
            <div class="text-gray-600">Read Operations</div>
            <div class="font-bold text-blue-600">${metrics.disk_read_ops.toLocaleString()}</div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Write Operations</div>
            <div class="font-bold text-red-600">${metrics.disk_write_ops.toLocaleString()}</div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Data Read</div>
            <div class="font-bold text-green-600">${metrics.disk_read_mb} MB</div>
          </div>
          <div class="text-center">
            <div class="text-gray-600">Data Written</div>
            <div class="font-bold text-orange-600">${metrics.disk_write_mb} MB</div>
          </div>
        `;
      }
    }
    
    // Update top hosts table
    if (data.top_hosts) {
      const topHostsTable = document.querySelector('#topHostsTable tbody');
      if (topHostsTable) {
        topHostsTable.innerHTML = data.top_hosts.map(host => `
          <tr>
            <td class="px-4 py-2">${host.hostname}</td>
            <td class="px-4 py-2">${host.cnt.toLocaleString()}</td>
          </tr>
        `).join('');
      }
    }
    
    // Update last refresh time indicator (add a small indicator)
    const timeIndicator = document.getElementById('last-refresh') || (() => {
      const indicator = document.createElement('div');
      indicator.id = 'last-refresh';
      indicator.className = 'fixed top-4 right-4 bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-medium shadow-lg z-50';
      document.body.appendChild(indicator);
      return indicator;
    })();
    timeIndicator.textContent = `Live • ${new Date().toLocaleTimeString()}`;
    timeIndicator.classList.remove('bg-red-100', 'text-red-800');
    timeIndicator.classList.add('bg-green-100', 'text-green-800');
    
  } catch (error) {
    console.error('Error updating real-time data:', error);
    
    // Show error indicator
    const timeIndicator = document.getElementById('last-refresh');
    if (timeIndicator) {
      timeIndicator.textContent = `Error • ${new Date().toLocaleTimeString()}`;
      timeIndicator.classList.remove('bg-green-100', 'text-green-800');
      timeIndicator.classList.add('bg-red-100', 'text-red-800');
    }
  }
}

// Start real-time updates every 2 seconds
setInterval(updateChartsRealtime, 2000);

// Initial update after 2 seconds to show it's working
setTimeout(updateChartsRealtime, 2000);
</script>

</body>
</html>