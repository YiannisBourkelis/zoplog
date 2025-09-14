<?php
// block_hostname.php - Add/remove hostname from system blocklist
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$hostname = trim($_POST['hostname'] ?? '');
$action = trim($_POST['action'] ?? 'block'); // 'block' or 'unblock'

if (empty($hostname)) {
    echo json_encode(['status' => 'error', 'message' => 'Hostname is required']);
    exit;
}

// Validate hostname format
if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $hostname)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid hostname format']);
    exit;
}

try {
    // Get system blocklist ID and active status
    $systemBlocklistRes = $mysqli->query("SELECT id, active FROM blocklists WHERE type = 'system' LIMIT 1");
    if (!$systemBlocklistRes || $systemBlocklistRes->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'System blocklist not found']);
        exit;
    }

    $systemBlocklist = $systemBlocklistRes->fetch_assoc();
    $systemBlocklistId = $systemBlocklist['id'];
    $isActive = $systemBlocklist['active'];

    if ($action === 'block') {
        // Check if hostname already exists in system blocklist
        $existingRes = $mysqli->query("
            SELECT id FROM blocklist_domains
            WHERE blocklist_id = $systemBlocklistId AND domain = '" . $mysqli->real_escape_string($hostname) . "'
        ");

        if ($existingRes && $existingRes->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Hostname already blocked']);
            exit;
        }

        // Ensure system blocklist is active
        if ($isActive !== 'active') {
            $stmt = $mysqli->prepare("UPDATE blocklists SET active = 'active', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $systemBlocklistId);
            $stmt->execute();
            $stmt->close();
            $isActive = 'active';
        }

        // Add hostname to system blocklist
        $stmt = $mysqli->prepare("INSERT INTO blocklist_domains (blocklist_id, domain) VALUES (?, ?)");
        $stmt->bind_param("is", $systemBlocklistId, $hostname);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'ok', 'message' => 'Hostname blocked successfully', 'action' => 'blocked']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to block hostname']);
        }

        $stmt->close();

    } elseif ($action === 'unblock') {
        // Check if hostname exists in system blocklist
        $existingRes = $mysqli->query("
            SELECT id FROM blocklist_domains
            WHERE blocklist_id = $systemBlocklistId AND domain = '" . $mysqli->real_escape_string($hostname) . "'
        ");

        if (!$existingRes || $existingRes->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Hostname not found in blocklist']);
            exit;
        }

        $domainId = $existingRes->fetch_assoc()['id'];

        // Find all IPs associated with this hostname in the last 30 days
        $stmt = $mysqli->prepare('
            SELECT DISTINCT ip.ip_address, ip.id as ip_id
            FROM ip_addresses ip
            JOIN hostnames h ON h.ip_id = ip.id
            JOIN packet_logs pl ON pl.hostname_id = h.id
            WHERE h.hostname = ? AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ');
        $stmt->bind_param('s', $hostname);
        $stmt->execute();
        $ipResult = $stmt->get_result();
        $associatedIPs = [];
        while ($row = $ipResult->fetch_assoc()) {
            $associatedIPs[] = $row;
        }
        $stmt->close();

        // For each associated IP, remove from firewall and clean up blocked_ips entries
        $removedIPs = [];
        foreach ($associatedIPs as $ipData) {
            $ipAddress = $ipData['ip_address'];
            $ipId = $ipData['ip_id'];

            // Keep in blocked_ips for statistics but mark as unblocked by updating last_seen
            // This preserves historical data while indicating the IP is no longer actively blocked
            $stmt = $mysqli->prepare('UPDATE blocked_ips SET last_seen = NOW() WHERE blocklist_domain_id = ? AND ip_id = ?');
            $stmt->bind_param('ii', $domainId, $ipId);
            $stmt->execute();
            $stmt->close();

            // Remove from firewall sets (both IPv4 and IPv6 sets for this blocklist)
            $ipFamily = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4';
            $setName = "zoplog-blocklist-{$systemBlocklistId}-{$ipFamily}";

            // Execute nft delete command with sudo
            $scriptPath = __DIR__ . '/../scripts/zoplog-nft-del-element';
            
            // Check if development script exists, otherwise use production path
            if (!file_exists($scriptPath)) {
                $scriptPath = '/opt/zoplog/zoplog/scripts/zoplog-nft-del-element';
            }
            
            if (file_exists($scriptPath)) {
                $nftCommand = sprintf('sudo %s %s %s %s 2>/dev/null', 
                    escapeshellarg($scriptPath), 
                    escapeshellarg($ipFamily), 
                    escapeshellarg($setName), 
                    escapeshellarg($ipAddress)
                );
                exec($nftCommand, $output, $returnCode);
            } else {
                $returnCode = 1; // Script not found
            }

            if ($returnCode === 0) {
                $removedIPs[] = $ipAddress;
            }
        }

        // Remove hostname from system blocklist
        $stmt = $mysqli->prepare("DELETE FROM blocklist_domains WHERE id = ?");
        $stmt->bind_param("i", $domainId);

        if ($stmt->execute()) {
            $message = 'Hostname unblocked successfully';
            if (!empty($removedIPs)) {
                $message .= '. Removed ' . count($removedIPs) . ' associated IP(s) from firewall rules: ' . implode(', ', $removedIPs);
            }
            echo json_encode(['status' => 'ok', 'message' => $message, 'action' => 'unblocked']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to unblock hostname']);
        }

        $stmt->close();

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

$mysqli->close();
?>
