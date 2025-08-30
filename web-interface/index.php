<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$mysqli = new mysqli("localhost", "root", "8888", "logs_db");
if ($mysqli->connect_error) {
    die("DB Connection failed: " . $mysqli->connect_error);
}

// Total requests
$totalRes = $mysqli->query("SELECT COUNT(*) AS cnt FROM packet_logs");
$totalRequests = $totalRes->fetch_assoc()["cnt"];

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

// Requests by method
$methodsRes = $mysqli->query("
    SELECT method, COUNT(*) AS cnt
    FROM packet_logs
    GROUP BY method
");
$methods = [];
while ($row = $methodsRes->fetch_assoc()) {
    $methods[$row["method"]] = $row["cnt"];
}

// Requests over time (last 10 minutes, per minute)
$timelineRes = $mysqli->query("
    SELECT DATE_FORMAT(packet_timestamp, '%H:%i') AS minute, COUNT(*) AS cnt
    FROM packet_logs
    WHERE packet_timestamp >= NOW() - INTERVAL 10 MINUTE
    GROUP BY minute
    ORDER BY minute ASC
");
$timeline = [];
while ($row = $timelineRes->fetch_assoc()) {
    $timeline[] = $row;
}

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

// Browser stats
$uaRes = $mysqli->query("
    SELECT ua.user_agent
    FROM packet_logs p
    JOIN user_agents ua ON p.user_agent_id = ua.id
    WHERE ua.user_agent IS NOT NULL
");
$browsers = ["Chrome"=>0,"Firefox"=>0,"Safari"=>0,"Edge"=>0,"Opera"=>0,"Other"=>0];
while ($row = $uaRes->fetch_assoc()) {
    $ua = $row["user_agent"];
    if (!$ua) continue;
    if (stripos($ua, "chrome") !== false && stripos($ua, "edg") === false) {
        $browsers["Chrome"]++;
    } elseif (stripos($ua, "firefox") !== false) {
        $browsers["Firefox"]++;
    } elseif (stripos($ua, "safari") !== false && stripos($ua, "chrome") === false) {
        $browsers["Safari"]++;
    } elseif (stripos($ua, "edg") !== false) {
        $browsers["Edge"]++;
    } elseif (stripos($ua, "opera") !== false || stripos($ua, "opr/") !== false) {
        $browsers["Opera"]++;
    } else {
        $browsers["Other"]++;
    }
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
        <h2 class="text-xl font-semibold">Unique IPs</h2>
        <p class="text-3xl font-bold text-green-600"><?= number_format($uniqueIPs) ?></p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Top Host</h2>
        <p class="text-lg font-bold text-purple-600">
          <?= $topHosts[0]["hostname"] ?? "N/A" ?>
        </p>
        <p class="text-gray-600"><?= $topHosts[0]["cnt"] ?? 0 ?> requests</p>
      </div>
      <div class="bg-white rounded-2xl shadow p-6 text-center">
        <h2 class="text-xl font-semibold">Monitoring</h2>
        <p class="text-lg text-gray-600">Live network stats</p>
      </div>
    </div>

    <!-- Charts grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Methods pie chart -->
      <div class="bg-white rounded-2xl shadow p-6">
        <h2 class="text-xl font-semibold mb-4">Requests by Method</h2>
        <canvas id="methodsChart"></canvas>
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

    <!-- Browsers -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">Browser Usage</h2>
      <canvas id="browserChart" height="100"></canvas>
    </div>

    <!-- Languages -->
    <div class="bg-white rounded-2xl shadow p-6 mt-6">
      <h2 class="text-xl font-semibold mb-4">Top Languages</h2>
      <canvas id="langChart" height="100"></canvas>
    </div>
  </div>

<script>
// Requests by Method (Pie Chart)
const methodsData = {
  labels: <?= json_encode(array_keys($methods)) ?>,
  datasets: [{
    data: <?= json_encode(array_values($methods)) ?>,
    backgroundColor: ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#d946ef']
  }]
};
new Chart(document.getElementById('methodsChart'), {
  type: 'pie',
  data: methodsData
});

// Requests over Time (Line Chart)
const timelineData = {
  labels: <?= json_encode(array_column($timeline, "minute")) ?>,
  datasets: [{
    label: 'Requests',
    data: <?= json_encode(array_column($timeline, "cnt")) ?>,
    borderColor: '#3b82f6',
    fill: false,
    tension: 0.3
  }]
};
new Chart(document.getElementById('timelineChart'), {
  type: 'line',
  data: timelineData
});

// Browser chart
const browserData = <?= json_encode($browsers) ?>;
new Chart(document.getElementById('browserChart'), {
  type: 'doughnut',
  data: {
    labels: Object.keys(browserData),
    datasets: [{ data: Object.values(browserData) }]
  }
});

// Language chart
const langData = <?= json_encode($langs) ?>;
new Chart(document.getElementById('langChart'), {
  type: 'bar',
  data: {
    labels: Object.keys(langData),
    datasets: [{
      label: "Top Languages",
      data: Object.values(langData),
      backgroundColor: '#10b981'
    }]
  }
});
</script>

</body>
</html>