<?php
header('Content-Type: application/json');
require_once __DIR__ . '/zoplog_config.php';

$hostname = trim($_GET['hostname'] ?? '');
if ($hostname === '') {
    echo json_encode(['status' => 'error', 'message' => 'hostname required']);
    exit;
}

// Determine if hostname appears in any URL-type blocklists
$stmt = $mysqli->prepare('
    SELECT COUNT(*) AS cnt_url, SUM(CASE WHEN bl.type = "manual" THEN 1 ELSE 0 END) AS cnt_manual
    FROM blocklist_domains bd
    JOIN blocklists bl ON bl.id = bd.blocklist_id
    WHERE bd.domain = ?
');
$stmt->bind_param('s', $hostname);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cntUrl = intval($res['cnt_url'] ?? 0);
$cntManual = intval($res['cnt_manual'] ?? 0);

$allowAddToWhitelist = true; // Always allow whitelisting a hostname
$allowUnblockHostname = ($cntManual > 0); // Only allow unblock hostname if it exists in manual/system lists

// Special case: if only IP (no dot), treat as IP-only (UI will handle)
if (filter_var($hostname, FILTER_VALIDATE_IP)) {
    $allowAddToWhitelist = false;
    $allowUnblockHostname = true; // can still unblock IP
}

echo json_encode([
    'status' => 'ok',
    'allow_add_to_whitelist' => $allowAddToWhitelist,
    'allow_unblock_hostname' => $allowUnblockHostname,
]);

$mysqli->close();
