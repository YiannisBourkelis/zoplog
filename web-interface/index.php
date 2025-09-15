<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use centralized DB config/connection
require_once __DIR__ . '/zoplog_config.php';

// Add ZopLog signature header for device identification
header('X-ZopLog-Server: ZopLog Server');

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

// Top hosts - loaded asynchronously
$topHosts = [];

// Timeline data - loaded asynchronously
$timeline = [];
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

// Timeline percentages - loaded asynchronously
$totalAllowed10min = 0;
$totalBlocked10min = 0;
$total10min = 0;
$allowedPercentage = 0;
$blockedPercentage = 0;

// --- NEW STATS ---

// Top hosts and blocked hosts data - loaded asynchronously

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

// Collect current system metrics
$currentMetrics = getSystemMetrics();

// Initialize with just current data point (no fake history)
$systemTimeline = [
    [
        'timestamp' => gmdate('H:i:s'), // Use GMT/UTC with seconds for precision
        'cpu' => $currentMetrics['cpu'],
        'memory' => $currentMetrics['memory'],
        'disk' => $currentMetrics['disk'],
        'network' => $currentMetrics['network']
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ZopLog - Network Dashboard</title>
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
        <p id="total-requests-count" class="text-3xl font-bold text-blue-600"><?= number_format($totalRequests) ?></p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Allowed Requests</h2>
        <p id="allowed-requests-count" class="text-3xl font-bold text-green-600"><?= number_format($allowedRequests) ?></p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold flex items-center justify-center">
          Blocked Requests
          <span class="ml-1 text-xs text-gray-500" title="Normalized - similar requests within 30-second windows are grouped to reduce retry noise">*</span>
        </h2>
        <p id="blocked-requests-count" class="text-3xl font-bold text-red-600"><?= number_format($blockedRequests) ?></p>
        <p class="text-xs text-gray-500 mt-1">Deduplicated</p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Unique IPs</h2>
        <p id="unique-ips-count" class="text-3xl font-bold text-purple-600"><?= number_format($uniqueIPs) ?></p>
      </div>
    </div>

    <!-- Charts grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Traffic breakdown pie chart -->
      <div class="bg-white rounded-2xl shadow p-6 h-96">
        <h2 class="text-xl font-semibold mb-4">Traffic Breakdown (Last 10 min)</h2>
        <div class="h-64 flex items-center justify-center">
          <canvas id="trafficChart" class="max-w-full max-h-full"></canvas>
        </div>
        <div class="mt-4 text-center text-sm text-gray-600">
          <div class="flex justify-center space-x-4">
            <span class="flex items-center">
              <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
              Allowed: <span id="allowed-count">Loading...</span>
            </span>
            <span class="flex items-center">
              <span class="w-3 h-3 bg-red-500 rounded-full mr-2"></span>
              Blocked: <span id="blocked-count">Loading...</span> <span class="text-xs">*normalized</span>
            </span>
          </div>
        </div>
      </div>

      <!-- Timeline chart -->
      <div class="bg-white rounded-2xl shadow p-6 h-96 flex flex-col">
        <h2 class="text-xl font-semibold mb-4">Requests Over Time (Last 10 min)</h2>
        <div id="timelineLoading" class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p class="mt-2 text-gray-600">Loading timeline data...</p>
          </div>
        </div>
        <div class="flex-1" id="timelineChartContainer">
          <canvas id="timelineChart" class="hidden"></canvas>
        </div>
      </div>
    </div>

    <!-- System Resources Chart -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">System Resources (Last 60 seconds)</h2>
      <canvas id="systemChart" class="w-full h-64"></canvas>
      
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
      <div id="topHostsLoading" class="text-center py-8">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        <p class="mt-2 text-gray-600">Loading top hosts...</p>
      </div>
      <table id="topHostsTable" class="min-w-full text-sm text-left hidden">
        <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Hostname</th>
            <th class="px-4 py-2">Requests</th>
          </tr>
        </thead>
        <tbody id="topHostsBody">
        </tbody>
      </table>
    </div>

    <!-- EXTENDED SECTION -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Top Hosts 30d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-green-600">Top 200 Hosts (Last 30 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently accessed allowed destinations</p>
        <div id="topHosts30Loading" class="h-64 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
            <p class="mt-2 text-gray-600">Loading top hosts...</p>
          </div>
        </div>
        <ol id="topHosts30List" class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto hidden">
        </ol>
      </div>

      <!-- Top Hosts 365d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-green-600">Top 200 Hosts (Last 365 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently accessed allowed destinations</p>
        <div id="topHosts365Loading" class="h-64 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
            <p class="mt-2 text-gray-600">Loading top hosts...</p>
          </div>
        </div>
        <ol id="topHosts365List" class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto hidden">
        </ol>
      </div>
    </div>

    <!-- BLOCKED HOSTS SECTION -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Top Blocked Hosts 30d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-red-600">Top 200 Blocked Hosts (Last 30 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently blocked destinations <span class="text-xs">*normalized</span></p>
        <div id="blockedHosts30Loading" class="h-64 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-red-600"></div>
            <p class="mt-2 text-gray-600">Loading blocked hosts...</p>
          </div>
        </div>
        <ol id="blockedHosts30List" class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto hidden">
        </ol>
      </div>

      <!-- Top Blocked Hosts 365d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4 text-red-600">Top 200 Blocked Hosts (Last 365 Days)</h2>
        <p class="text-sm text-gray-600 mb-3">Most frequently blocked destinations <span class="text-xs">*normalized</span></p>
        <div id="blockedHosts365Loading" class="h-64 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-red-600"></div>
            <p class="mt-2 text-gray-600">Loading blocked hosts...</p>
          </div>
        </div>
        <ol id="blockedHosts365List" class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto hidden">
        </ol>
      </div>
    </div>

    <!-- Browser and Language Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
      <!-- Browsers -->
      <div class="bg-white rounded-2xl shadow p-6 flex flex-col">
        <h2 class="text-xl font-semibold mb-4">Browser Usage (Top 20)</h2>
        <div id="browserLoading" class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-purple-600"></div>
            <p class="mt-2 text-gray-600">Loading browser stats...</p>
          </div>
        </div>
        <div class="flex-1" id="browserChartContainer">
          <canvas id="browserChart" class="hidden w-full h-full"></canvas>
        </div>
      </div>

      <!-- Languages -->
      <div class="bg-white rounded-2xl shadow p-6 flex flex-col">
        <h2 class="text-xl font-semibold mb-4">Top Languages</h2>
        <div id="langLoading" class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-purple-600"></div>
            <p class="mt-2 text-gray-600">Loading language stats...</p>
          </div>
        </div>
        <div class="flex-1" id="langChartContainer">
          <canvas id="langChart" class="hidden w-full h-full"></canvas>
        </div>
      </div>
    </div>
  </div>

<script>
console.log('ZopLog JavaScript loaded successfully');
// Chart references for real-time updates
let trafficChart;
let timelineChart;
let systemChart;

// Traffic Breakdown Pie Chart (Last 10 min)
const trafficData = {
  labels: ['Allowed Requests', 'Blocked Requests (Normalized)'],
  datasets: [{
    data: [0, 0], // Will be updated with real data from API
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
    maintainAspectRatio: false,
    layout: {
      padding: {
        top: 10,
        bottom: 10,
        left: 10,
        right: 10
      }
    },
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          padding: 15,
          usePointStyle: true,
          font: {
            size: 12
          }
        }
      },
      title: {
        display: true,
        text: 'Firewall Protection Overview',
        font: {
          size: 14
        },
        padding: {
          top: 10,
          bottom: 10
        }
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

// System Resources Chart - Custom HTML5 Canvas Implementation
class SystemResourcesChart {
  constructor(canvasId) {
    this.canvas = document.getElementById(canvasId);
    this.ctx = this.canvas.getContext('2d');
    this.data = {
      cpu: [],
      memory: [],
      network: []
    };
    this.maxPoints = 30; // 60 seconds at 2-second intervals
    this.colors = {
      cpu: '#3b82f6',
      memory: '#10b981',
      network: '#8b5cf6'
    };
    this.labels = {
      cpu: 'CPU',
      memory: 'Memory',
      network: 'Network'
    };

    this.resize();
    // Debounced resize handler to prevent excessive redraws
    let resizeTimeout;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => this.resize(), 100);
    });
  }

  resize() {
    const rect = this.canvas.getBoundingClientRect();
    const newWidth = rect.width;
    const newHeight = rect.height;

    // Only resize if dimensions actually changed
    if (newWidth === this.width && newHeight === this.height) {
      return;
    }

    // Reset canvas scaling
    this.ctx.setTransform(1, 0, 0, 1, 0, 0);

    // Set canvas size for high DPI displays
    this.canvas.width = newWidth * window.devicePixelRatio;
    this.canvas.height = newHeight * window.devicePixelRatio;

    // Scale context for crisp rendering
    this.ctx.scale(window.devicePixelRatio, window.devicePixelRatio);

    // Update stored dimensions
    this.width = newWidth;
    this.height = newHeight;

    // Redraw with new dimensions
    this.draw();
  }

  addDataPoint(metrics) {
    // Add new data point to the right
    this.data.cpu.push(metrics.cpu);
    this.data.memory.push(metrics.memory);
    this.data.network.push(metrics.network);

    // Keep only the last maxPoints
    if (this.data.cpu.length > this.maxPoints) {
      this.data.cpu.shift();
      this.data.memory.shift();
      this.data.network.shift();
    }

    this.draw();
  }

  draw() {
    this.ctx.clearRect(0, 0, this.width, this.height);

    // Draw grid
    this.ctx.strokeStyle = '#e5e7eb';
    this.ctx.lineWidth = 1;

    // Horizontal grid lines
    for (let i = 0; i <= 4; i++) {
      const y = (this.height - 60) * (i / 4) + 30;
      this.ctx.beginPath();
      this.ctx.moveTo(0, y);
      this.ctx.lineTo(this.width, y);
      this.ctx.stroke();

      // Y-axis labels
      this.ctx.fillStyle = '#6b7280';
      this.ctx.font = '10px Arial';
      this.ctx.textAlign = 'right';
      this.ctx.fillText(`${100 - i * 25}%`, 25, y + 3);
    }

    // Vertical grid lines (time markers)
    for (let i = 0; i <= 6; i++) {
      const x = this.width * (i / 6);
      this.ctx.beginPath();
      this.ctx.moveTo(x, 30);
      this.ctx.lineTo(x, this.height - 30);
      this.ctx.stroke();

      // Time labels
      if (i > 0) {
        const secondsAgo = (6 - i) * 10;
        this.ctx.fillStyle = '#6b7280';
        this.ctx.font = '9px Arial';
        this.ctx.textAlign = 'center';
        let label = '';
        if (secondsAgo === 0) label = 'now';
        else if (secondsAgo <= 10) label = `${secondsAgo}s`;
        else if (secondsAgo <= 60) label = `${Math.floor(secondsAgo/10)*10}s`;
        this.ctx.fillText(label, x, this.height - 10);
      }
    }

    // Draw data lines
    Object.keys(this.data).forEach(metric => {
      if (this.data[metric].length === 0) return;

      this.ctx.strokeStyle = this.colors[metric];
      this.ctx.lineWidth = 2;
      this.ctx.beginPath();

      const points = this.data[metric];
      for (let i = 0; i < points.length; i++) {
        const x = this.width - ((points.length - 1 - i) * (this.width / (this.maxPoints - 1)));
        const y = 30 + ((100 - points[i]) / 100) * (this.height - 60);

        if (i === 0) {
          this.ctx.moveTo(x, y);
        } else {
          this.ctx.lineTo(x, y);
        }
      }

      this.ctx.stroke();
    });

    // Draw legend
    const legendY = 15;
    let legendX = 10;
    Object.keys(this.labels).forEach(metric => {
      // Color box
      this.ctx.fillStyle = this.colors[metric];
      this.ctx.fillRect(legendX, legendY - 8, 12, 8);

      // Label
      this.ctx.fillStyle = '#374151';
      this.ctx.font = '11px Arial';
      this.ctx.textAlign = 'left';
      this.ctx.fillText(this.labels[metric], legendX + 16, legendY);

      legendX += 80;
    });
  }
}

// Initialize custom system resources chart
systemResourcesChart = new SystemResourcesChart('systemChart');

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

// Browser and language charts are now loaded asynchronously

// Async loading functions for initial page load
async function loadTopHosts() {
  try {
    console.log('Loading top hosts...');
    const response = await fetch('/api/top_hosts.php');
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();
    console.log('Top hosts data received:', data);
    
    // Update the first Top Hosts table (top 5 hosts)
    const topHostsTable = document.querySelector('#topHostsTable tbody');
    if (topHostsTable && data) {
      topHostsTable.innerHTML = data.slice(0, 5).map(host => 
        `<tr>
          <td class="px-4 py-2">${host.hostname}</td>
          <td class="px-4 py-2">${host.cnt.toLocaleString()}</td>
        </tr>`
      ).join('');
      console.log('Updated topHostsTable with top 5 hosts');
    }
    
    // Update 30-day top hosts (using ordered list)
    const topHosts30List = document.getElementById('topHosts30List');
    if (topHosts30List && data) {
      topHosts30List.innerHTML = data.slice(0, 200).map(host => 
        `<li class="flex justify-between items-center py-1">
          <span class="text-gray-800">${host.hostname}</span>
          <span class="text-gray-600 font-medium">${host.cnt.toLocaleString()}</span>
        </li>`
      ).join('');
      console.log('Updated topHosts30List with', data.length, 'items');
    }
    
    // Update 365-day top hosts (using ordered list) - same data for now
    const topHosts365List = document.getElementById('topHosts365List');
    if (topHosts365List && data) {
      topHosts365List.innerHTML = data.slice(0, 200).map(host => 
        `<li class="flex justify-between items-center py-1">
          <span class="text-gray-800">${host.hostname}</span>
          <span class="text-gray-600 font-medium">${host.cnt.toLocaleString()}</span>
        </li>`
      ).join('');
      console.log('Updated topHosts365List with', data.length, 'items');
    }
    
    // Hide loading spinners and show content
    document.getElementById('topHostsLoading').classList.add('hidden');
    document.getElementById('topHostsTable').classList.remove('hidden');
    document.getElementById('topHosts30Loading').classList.add('hidden');
    document.getElementById('topHosts30List').classList.remove('hidden');
    document.getElementById('topHosts365Loading').classList.add('hidden');
    document.getElementById('topHosts365List').classList.remove('hidden');
    console.log('Top hosts loading completed successfully');
    
  } catch (error) {
    console.error('Error loading top hosts:', error);
    // Show error state
    document.getElementById('topHostsLoading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
    document.getElementById('topHosts30Loading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
    document.getElementById('topHosts365Loading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
  }
}

async function loadBlockedHosts() {
  try {
    console.log('Loading blocked hosts...');
    const response = await fetch('/api/top_blocked_hosts.php');
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();
    console.log('Blocked hosts data received:', data);
    
    // Update 30-day blocked hosts (using ordered list)
    const blockedHosts30List = document.getElementById('blockedHosts30List');
    if (blockedHosts30List && data.blocked30) {
      blockedHosts30List.innerHTML = data.blocked30.map(host => 
        `<li class="flex justify-between items-center py-1">
          <span class="text-gray-800">${host.blocked_host}</span>
          <span class="text-gray-600 font-medium">${host.cnt.toLocaleString()}</span>
        </li>`
      ).join('');
    }
    
    // Update 365-day blocked hosts (using ordered list)
    const blockedHosts365List = document.getElementById('blockedHosts365List');
    if (blockedHosts365List && data.blocked365) {
      blockedHosts365List.innerHTML = data.blocked365.map(host => 
        `<li class="flex justify-between items-center py-1">
          <span class="text-gray-800">${host.blocked_host}</span>
          <span class="text-gray-600 font-medium">${host.cnt.toLocaleString()}</span>
        </li>`
      ).join('');
    }
    
    // Hide loading spinners and show lists
    document.getElementById('blockedHosts30Loading').classList.add('hidden');
    document.getElementById('blockedHosts30List').classList.remove('hidden');
    document.getElementById('blockedHosts365Loading').classList.add('hidden');
    document.getElementById('blockedHosts365List').classList.remove('hidden');
    
  } catch (error) {
    console.error('Error loading blocked hosts:', error);
    // Show error state
    document.getElementById('blockedHosts30Loading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
    document.getElementById('blockedHosts365Loading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
  }
}

async function loadTimelineData() {
  try {
    console.log('Loading timeline data...');
    const response = await fetch('/api/timeline.php');
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();
    console.log('Timeline data received:', data);
    
    // Update timeline chart
    if (timelineChart && data.timeline) {
      timelineChart.data.labels = data.timeline.map(item => item.minute);
      timelineChart.data.datasets[0].data = data.timeline.map(item => item.allowed);
      timelineChart.data.datasets[1].data = data.timeline.map(item => item.blocked);
      timelineChart.data.datasets[2].data = data.timeline.map(item => item.total);
      timelineChart.update();
      console.log('Timeline chart updated with', data.timeline.length, 'data points');
    } else {
      console.warn('Timeline chart not ready or no data received');
    }
    
    // Hide loading spinner and show chart
    document.getElementById('timelineLoading').classList.add('hidden');
    document.getElementById('timelineChart').classList.remove('hidden');
    
  } catch (error) {
    console.error('Error loading timeline data:', error);
    // Show error state
    document.getElementById('timelineLoading').innerHTML = '<div class="text-center text-red-600">Error loading timeline data</div>';
  }
}

async function loadBrowserLanguageStats() {
  try {
    console.log('Loading browser/language stats...');
    const response = await fetch('/api/browser_language_stats.php');
    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
    const data = await response.json();
    console.log('Browser/language data received:', data);
    
    // Update browser chart
    if (data.browsers) {
      const browserChart = new Chart(document.getElementById('browserChart'), {
        type: 'pie',
        data: {
          labels: Object.keys(data.browsers),
          datasets: [{
            data: Object.values(data.browsers),
            backgroundColor: [
              '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#d946ef',
              '#f97316', '#84cc16', '#06b6d4', '#8b5cf6', '#f59e0b', '#ef4444', '#10b981',
              '#3b82f6', '#d946ef', '#f97316', '#84cc16', '#06b6d4', '#8b5cf6', '#6b7280'
            ]
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          layout: {
            padding: {
              top: 10,
              bottom: 10,
              left: 10,
              right: 10
            }
          },
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
                  const total = Object.values(data.browsers).reduce((a, b) => a + b, 0);
                  const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    }
    
    // Update language chart
    if (data.languages) {
      const langChart = new Chart(document.getElementById('langChart'), {
        type: 'pie',
        data: {
          labels: Object.keys(data.languages),
          datasets: [{
            data: Object.values(data.languages),
            backgroundColor: [
              '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#d946ef',
              '#f97316', '#84cc16', '#6b7280'
            ]
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          layout: {
            padding: {
              top: 10,
              bottom: 10,
              left: 10,
              right: 10
            }
          },
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
                  const total = Object.values(data.languages).reduce((a, b) => a + b, 0);
                  const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                  return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
                }
              }
            }
          }
        }
      });
    }
    
    // Hide loading spinners and show charts
    document.getElementById('browserLoading').classList.add('hidden');
    document.getElementById('browserChart').classList.remove('hidden');
    document.getElementById('langLoading').classList.add('hidden');
    document.getElementById('langChart').classList.remove('hidden');
    
  } catch (error) {
    console.error('Error loading browser/language stats:', error);
    // Show error state
    document.getElementById('browserLoading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
    document.getElementById('langLoading').innerHTML = '<div class="text-center text-red-600">Error loading data</div>';
  }
}

// Load all async data when page loads
document.addEventListener('DOMContentLoaded', function() {
  console.log('DOM loaded, starting async data loading...');
  updateChartsRealtime(); // Load traffic chart data immediately
  loadTopHosts();
  loadBlockedHosts();
  loadTimelineData();
  loadBrowserLanguageStats();
});

// Also try loading immediately in case DOMContentLoaded doesn't fire
console.log('Script loaded, attempting immediate async loading...');
if (document.readyState === 'loading') {
  // Document still loading, wait for DOMContentLoaded
} else {
  // Document already loaded
  console.log('Document already loaded, starting async data loading...');
  updateChartsRealtime(); // Load traffic chart data immediately
  loadTopHosts();
  loadBlockedHosts();
  loadTimelineData();
  loadBrowserLanguageStats();
}

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
      const totalRequests = parseInt(summary.allowed_requests) + parseInt(summary.blocked_requests);
      const totalEl = document.getElementById('total-requests-count');
      const allowedEl = document.getElementById('allowed-requests-count');
      const blockedEl = document.getElementById('blocked-requests-count');
      const uniqueEl = document.getElementById('unique-ips-count');
      if (totalEl) totalEl.textContent = totalRequests.toLocaleString();
      if (allowedEl) allowedEl.textContent = parseInt(summary.allowed_requests).toLocaleString();
      if (blockedEl) blockedEl.textContent = parseInt(summary.blocked_requests).toLocaleString();
      if (uniqueEl) uniqueEl.textContent = parseInt(summary.unique_ips).toLocaleString();
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
      const allowedSpan = document.getElementById('allowed-count');
      const blockedSpan = document.getElementById('blocked-count');
      if (allowedSpan && blockedSpan) {
        allowedSpan.textContent = `${breakdown.allowed.toLocaleString()} (${breakdown.allowed_percentage}%)`;
        blockedSpan.textContent = `${breakdown.blocked.toLocaleString()} (${breakdown.blocked_percentage}%)`;
      }
    }
    
    // Update system resources chart (custom HTML5 canvas with smooth scrolling)
    if (systemResourcesChart && data.system_metrics) {
      // Add new data point to the custom chart
      systemResourcesChart.addDataPoint({
        cpu: data.system_metrics.cpu,
        memory: data.system_metrics.memory,
        network: data.system_metrics.network
      });
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