<?php
header('Content-Type: application/json');
require_once __DIR__ . '/zoplog_config.php';

function respond($status, $message, $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

$action = $_POST['action'] ?? '';
if ($action === '') respond('error', 'action required');

// Utility: find all IPs for a hostname (last 30 days traffic)
function find_associated_ips($hostname) {
    global $mysqli;
    $stmt = $mysqli->prepare('
        SELECT DISTINCT ip.ip_address, ip.id as ip_id
        FROM ip_addresses ip
        JOIN domain_ip_addresses dip ON dip.ip_address_id = ip.id
        JOIN domains d ON d.id = dip.domain_id
        JOIN packet_logs pl ON pl.domain_id = d.id
        WHERE d.domain = ? AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    $stmt->close();
    return $rows;
}

// Utility: remove an IP from all zoplog sets across all blocklists
function remove_ip_from_all_sets($ip) {
    $isV6 = strpos($ip, ':') !== false;
    $fam = $isV6 ? 'v6' : 'v4';
    $removed = false;

    // Discover active blocklist ids
    global $mysqli;
    $res = $mysqli->query("SELECT id FROM blocklists");
    $ids = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $ids[] = intval($r['id']); } }

    $scriptDev = realpath(__DIR__ . '/../scripts/zoplog-nft-del-element');
    $scriptProd = '/opt/zoplog/zoplog/scripts/zoplog-nft-del-element';
    $script = $scriptDev ?: (file_exists($scriptProd) ? $scriptProd : null);

    foreach ($ids as $id) {
        $set = "zoplog-blocklist-{$id}-{$fam}";
        if ($script) {
            $cmd = sprintf('sudo %s %s %s %s 2>/dev/null', escapeshellarg($script), escapeshellarg($fam), escapeshellarg($set), escapeshellarg($ip));
            exec($cmd, $out, $rc);
            if ($rc === 0) $removed = true;
        }
    }
    return $removed;
}

if ($action === 'unblock_ip') {
    $ip = trim($_POST['ip'] ?? '');
    $hostname = trim($_POST['hostname'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) respond('error', 'invalid ip');

    // When hostname is provided, remove all related IPs; else only the exact IP
    $ips = [];
    if ($hostname) {
        $assoc = find_associated_ips($hostname);
        if (!empty($assoc)) {
            $ips = array_unique(array_map(fn($r) => $r['ip_address'], $assoc));
        }
    }
    if (empty($ips)) $ips = [$ip];

    $count = 0;
    foreach ($ips as $ipx) {
        if (remove_ip_from_all_sets($ipx)) $count++;
    }
    respond('ok', "Removed ${count} IP(s) from firewall");
}

if ($action === 'unblock_hostname') {
    $hostname = trim($_POST['hostname'] ?? '');
    if ($hostname === '') respond('error', 'hostname required');

    $assoc = find_associated_ips($hostname);
    $count = 0;
    foreach ($assoc as $row) {
        if (remove_ip_from_all_sets($row['ip_address'])) $count++;
    }

    // Also remove from system/manual blocklists if present
    $stmt = $mysqli->prepare('SELECT bd.id FROM blocklist_domains bd JOIN blocklists bl ON bl.id = bd.blocklist_id WHERE bd.domain = ? AND bl.type IN ("manual","system")');
    $stmt->bind_param('s', $hostname);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $bdId = intval($r['id']);
        $del = $mysqli->prepare('DELETE FROM blocklist_domains WHERE id = ?');
        $del->bind_param('i', $bdId);
        $del->execute();
        $del->close();
    }
    $stmt->close();

    respond('ok', "Hostname unblocked; removed ${count} IP(s) from firewall");
}

if ($action === 'add_to_whitelist') {
    $hostname = trim($_POST['hostname'] ?? '');
    if ($hostname === '') respond('error', 'hostname required');

    // Ensure default whitelist exists (or create one)
    $name = 'Auto Whitelist';
    $category = 'other';
    $now = date('Y-m-d H:i:s');

    $res = $mysqli->query("SELECT id FROM whitelists WHERE name = 'Auto Whitelist' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $wlId = intval($res->fetch_assoc()['id']);
    } else {
        $stmt = $mysqli->prepare('INSERT INTO whitelists (name, category, active, created_at, updated_at) VALUES (?, ?, "active", ?, ?)');
        $stmt->bind_param('ssss', $name, $category, $now, $now);
        $stmt->execute();
        $wlId = $stmt->insert_id;
        $stmt->close();
    }

    // Insert domain into whitelist (ignore duplicates)
    $stmt = $mysqli->prepare('INSERT IGNORE INTO whitelist_domains (whitelist_id, domain) VALUES (?, ?)');
    $stmt->bind_param('is', $wlId, $hostname);
    $stmt->execute();
    $stmt->close();

    // Remove any currently blocked IPs for this hostname from firewall
    $assoc = find_associated_ips($hostname);
    $count = 0;
    foreach ($assoc as $row) {
        if (remove_ip_from_all_sets($row['ip_address'])) $count++;
    }

    respond('ok', "Added to whitelist; removed ${count} IP(s) from firewall");
}

respond('error', 'Unknown action');
