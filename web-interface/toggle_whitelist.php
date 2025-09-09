// Function to remove blocked IPs for all domains in a whitelist
function remove_blocked_ips_for_whitelist($whitelist_id) {
    global $mysqli;
    
    // Find all domains in this whitelist
    $stmt = $mysqli->prepare('SELECT domain FROM whitelist_domains WHERE whitelist_id = ?');
    $stmt->bind_param('i', $whitelist_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $domains = [];
    while ($row = $result->fetch_assoc()) {
        $domains[] = $row['domain'];
    }
    $stmt->close();
    
    // Remove blocked IPs for each domain
    foreach ($domains as $domain) {
        remove_blocked_ips_for_domain($domain);
    }
}

// Function to remove blocked IPs for a single domain
function remove_blocked_ips_for_domain($domain) {
    global $mysqli;
    
    // Find all blocklist domains that match this domain
    $stmt = $mysqli->prepare('
        SELECT bd.id, bd.blocklist_id, ip.ip_address
        FROM blocklist_domains bd
        JOIN blocked_ips bi ON bi.blocklist_domain_id = bd.id
        JOIN ip_addresses ip ON ip.id = bi.ip_id
        WHERE bd.domain = ?
    ');
    $stmt->bind_param('s', $domain);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ips_to_remove = [];
    while ($row = $result->fetch_assoc()) {
        $ips_to_remove[] = [
            'ip' => $row['ip_address'],
            'blocklist_id' => $row['blocklist_id']
        ];
    }
    $stmt->close();
    
    // Remove each IP from the firewall
    foreach ($ips_to_remove as $item) {
        $ip = $item['ip'];
        $blocklist_id = $item['blocklist_id'];
        
        // Determine if IPv4 or IPv6
        $is_ipv6 = strpos($ip, ':') !== false;
        $set_name = "zoplog-blocklist-{$blocklist_id}-" . ($is_ipv6 ? 'v6' : 'v4');
        
        // Use nft to delete the element from the set
        $nft_cmd = "/usr/sbin/nft delete element inet zoplog {$set_name} { {$ip} } 2>/dev/null || true";
        exec($nft_cmd);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$state = isset($_POST['active']) ? trim($_POST['active']) : null; // expecting 'active' or 'inactive'
if ($id <= 0 || !in_array($state, ['active','inactive'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

// Update the whitelist state
$stmt = $mysqli->prepare('UPDATE whitelists SET active = ?, updated_at = ? WHERE id = ?');
$now = date('Y-m-d H:i:s');
$stmt->bind_param('ssi', $state, $now, $id);
if ($stmt->execute()) {
    // If toggling to active, remove blocked IPs for all domains in this whitelist
    if ($state === 'active') {
        remove_blocked_ips_for_whitelist($id);
    }
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update whitelist']);
}
$stmt->close();
?>
