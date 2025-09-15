<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../zoplog_config.php';

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
        if (preg_match('/Chrome\/(\d+)/', $ua, $matches)) {
            $browserName = "Chrome " . $matches[1];
        } else {
            $browserName = "Chrome";
        }
    } elseif (stripos($ua, "firefox") !== false) {
        if (preg_match('/Firefox\/(\d+)/', $ua, $matches)) {
            $browserName = "Firefox " . $matches[1];
        } else {
            $browserName = "Firefox";
        }
    } elseif (stripos($ua, "safari") !== false && stripos($ua, "chrome") === false) {
        if (preg_match('/Version\/(\d+)/', $ua, $matches)) {
            $browserName = "Safari " . $matches[1];
        } else {
            $browserName = "Safari";
        }
    } elseif (stripos($ua, "edg") !== false) {
        if (preg_match('/Edg\/(\d+)/', $ua, $matches)) {
            $browserName = "Edge " . $matches[1];
        } else {
            $browserName = "Edge";
        }
    } elseif (stripos($ua, "opera") !== false || stripos($ua, "opr/") !== false) {
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

echo json_encode([
    'browsers' => $topBrowsers,
    'languages' => $langs
]);
?>