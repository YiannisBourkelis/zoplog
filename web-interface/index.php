<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$mysqli = new mysqli("localhost", "root", "8888", "logs_db");
if ($mysqli->connect_error) {
    die("DB Connection failed: " . $mysqli->connect_error);
}

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
    
    // CPU Usage
    $load = sys_getloadavg();
    $cpuCores = (int)shell_exec('nproc') ?: 1;
    $cpuUsage = min(100, ($load[0] / $cpuCores) * 100);
    $metrics['cpu'] = round($cpuUsage, 1);
    
    // Memory Usage
    $memInfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $memTotal);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $memAvailable);
    if ($memTotal && $memAvailable) {
        $totalMem = $memTotal[1] * 1024; // Convert to bytes
        $availableMem = $memAvailable[1] * 1024;
        $usedMem = $totalMem - $availableMem;
        $memUsage = ($usedMem / $totalMem) * 100;
        $metrics['memory'] = round($memUsage, 1);
    } else {
        $metrics['memory'] = 0;
    }
    
    // Disk Usage (root filesystem)
    $diskTotal = disk_total_space('/');
    $diskFree = disk_free_space('/');
    if ($diskTotal && $diskFree) {
        $diskUsed = $diskTotal - $diskFree;
        $diskUsage = ($diskUsed / $diskTotal) * 100;
        $metrics['disk'] = round($diskUsage, 1);
    } else {
        $metrics['disk'] = 0;
    }
    
    // Network Usage (approximate based on interface stats)
    $netStats = @file_get_contents('/proc/net/dev');
    $networkUsage = 0;
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
      <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-center text-sm">
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-blue-600 font-semibold">CPU</div>
          <div class="text-lg font-bold"><?= $currentMetrics['cpu'] ?>%</div>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-green-600 font-semibold">Memory</div>
          <div class="text-lg font-bold"><?= $currentMetrics['memory'] ?>%</div>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-yellow-600 font-semibold">Disk</div>
          <div class="text-lg font-bold"><?= $currentMetrics['disk'] ?>%</div>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
          <div class="text-purple-600 font-semibold">Network</div>
          <div class="text-lg font-bold"><?= $currentMetrics['network'] ?>%</div>
        </div>
      </div>
    </div>

    <!-- Top Hosts -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">Top Hosts</h2>
      <table class="min-w-full text-sm text-left">
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
        <h2 class="text-xl font-semibold mb-4">Top 200 Hosts (Last 30 Days)</h2>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $top30->fetch_assoc()): ?>
            <li><?= htmlspecialchars($row['hostname']) ?> 
                <span class="text-gray-500">(<?= $row['cnt'] ?>)</span></li>
          <?php endwhile; ?>
        </ol>
      </div>

      <!-- Top Hosts 365d -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Top 200 Hosts (Last 365 Days)</h2>
        <ol class="list-decimal pl-6 space-y-1 h-64 overflow-y-auto">
          <?php while ($row = $top365->fetch_assoc()): ?>
            <li><?= htmlspecialchars($row['hostname']) ?> 
                <span class="text-gray-500">(<?= $row['cnt'] ?>)</span></li>
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
new Chart(document.getElementById('trafficChart'), {
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
new Chart(document.getElementById('systemChart'), {
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
new Chart(document.getElementById('timelineChart'), {
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
</script>

</body>
</html>