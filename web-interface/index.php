<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$mysqli = new mysqli("localhost", "root", "8888", "logs_db");
if ($mysqli->connect_error) {
    die("DB Connection failed: " . $mysqli->connect_error);
}

// Total requests (allowed + blocked)
$allowedRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM packet_logs");
$allowedRequests = $allowedRes->fetch_assoc()["cnt"];

$blockedRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM blocked_events");
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

// Get blocked requests from blocked_events
$blockedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(event_time, '%H:%i') AS minute, COUNT(*) AS cnt
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
        <h2 class="text-xl font-semibold">Blocked Requests</h2>
        <p class="text-3xl font-bold text-red-600"><?= number_format($blockedRequests) ?></p>
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
              Blocked: <?= number_format($totalBlocked10min) ?> (<?= $blockedPercentage ?>%)
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
  labels: ['Allowed Requests', 'Blocked Requests'],
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
      label: 'Blocked Requests',
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