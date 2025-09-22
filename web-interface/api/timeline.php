<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

// Timeline data (last 10 minutes)
$allowedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(packet_timestamp, '%H:%i') AS minute, COUNT(*) AS cnt
    FROM packet_logs
    WHERE packet_timestamp >= DATE_SUB(DATE_SUB(NOW(), INTERVAL MINUTE(NOW()) MINUTE), INTERVAL 10 MINUTE)
    GROUP BY minute
    ORDER BY minute ASC
");

$blockedTimelineRes = $mysqli->query("
    SELECT DATE_FORMAT(event_time, '%H:%i') AS minute,
           COUNT(DISTINCT CONCAT(
               wan_ip_id, '-',
               FLOOR(UNIX_TIMESTAMP(event_time) / 30)
           )) AS cnt
    FROM blocked_events
    WHERE event_time >= DATE_SUB(DATE_SUB(NOW(), INTERVAL MINUTE(NOW()) MINUTE), INTERVAL 10 MINUTE)
    GROUP BY minute
    ORDER BY minute ASC
");

$allowedTimeline = [];
while ($row = $allowedTimelineRes->fetch_assoc()) {
    $allowedTimeline[$row['minute']] = $row['cnt'];
}

$blockedTimeline = [];
while ($row = $blockedTimelineRes->fetch_assoc()) {
    $blockedTimeline[$row['minute']] = $row['cnt'];
}

// Get all unique minutes
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

// Calculate percentages for the last 10 minutes
$totalAllowed10min = array_sum(array_column($timeline, 'allowed'));
$totalBlocked10min = array_sum(array_column($timeline, 'blocked'));
$total10min = $totalAllowed10min + $totalBlocked10min;

$allowedPercentage = $total10min > 0 ? round(($totalAllowed10min / $total10min) * 100, 1) : 0;
$blockedPercentage = $total10min > 0 ? round(($totalBlocked10min / $total10min) * 100, 1) : 0;

echo json_encode([
    'timeline' => $timeline,
    'allowedPercentage' => $allowedPercentage,
    'blockedPercentage' => $blockedPercentage,
    'total10min' => $total10min
]);
?>