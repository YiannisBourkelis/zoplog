<?php
// delete_domain.php - Delete a domain from a blocklist
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$domain = trim($_POST['domain'] ?? '');
$blocklistId = intval($_POST['blocklist_id'] ?? 0);

if (empty($domain) || $blocklistId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Domain and blocklist ID are required']);
    exit;
}

// Validate hostname format
if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid domain format']);
    exit;
}

try {
    // Check if the blocklist exists and allows domain deletion
    $stmt = $mysqli->prepare('SELECT type FROM blocklists WHERE id = ?');
    $stmt->bind_param('i', $blocklistId);
    $stmt->execute();
    $result = $stmt->get_result();
    $blocklist = $result->fetch_assoc();
    $stmt->close();

    if (!$blocklist) {
        echo json_encode(['status' => 'error', 'message' => 'Blocklist not found']);
        exit;
    }

    if (!in_array($blocklist['type'], ['system', 'manual'])) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete domains from this type of blocklist']);
        exit;
    }

    // Start transaction for atomic operation
    $mysqli->begin_transaction();

    try {
        // Get the blocklist_domain_id for this domain
        $stmt = $mysqli->prepare('SELECT id FROM blocklist_domains WHERE blocklist_id = ? AND domain = ?');
        $stmt->bind_param('is', $blocklistId, $domain);
        $stmt->execute();
        $domainResult = $stmt->get_result();
        $domainRow = $domainResult->fetch_assoc();
        $stmt->close();

        if (!$domainRow) {
            $mysqli->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Domain not found in blocklist']);
            exit;
        }

        $blocklistDomainId = $domainRow['id'];

        // Find all IPs associated with this hostname in the last 1 days
        $stmt = $mysqli->prepare('
            SELECT DISTINCT ip.ip_address, ip.id as ip_id
            FROM ip_addresses ip
            JOIN domain_ip_addresses dip ON dip.ip_address_id = ip.id
            JOIN domains d ON d.id = dip.domain_id
            JOIN packet_logs pl ON pl.domain_id = d.id
            WHERE d.domain = ? AND pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ');
        $stmt->bind_param('s', $domain);
        $stmt->execute();
        $ipResult = $stmt->get_result();
        $associatedIPs = [];
        while ($row = $ipResult->fetch_assoc()) {
            $associatedIPs[] = $row;
        }
        $stmt->close();

        // For each associated IP, remove from firewall and update blocked_ips for statistics
        $removedIPs = [];
        foreach ($associatedIPs as $ipData) {
            $ipAddress = $ipData['ip_address'];
            $ipId = $ipData['ip_id'];

            // Keep in blocked_ips for statistics but mark as unblocked by updating last_seen
            // This preserves historical data while indicating the IP is no longer actively blocked
            $stmt = $mysqli->prepare('UPDATE blocked_ips SET last_seen = NOW() WHERE blocklist_domain_id = ? AND ip_id = ?');
            $stmt->bind_param('ii', $blocklistDomainId, $ipId);
            $stmt->execute();
            $stmt->close();

            // Remove from firewall sets (both IPv4 and IPv6 sets for this blocklist)
            $ipFamily = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4';
            $setName = "zoplog-blocklist-{$blocklistId}-{$ipFamily}";

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

        // Delete the domain from blocklist_domains
        $stmt = $mysqli->prepare('DELETE FROM blocklist_domains WHERE id = ?');
        $stmt->bind_param('i', $blocklistDomainId);
        $stmt->execute();
        $stmt->close();

        // Commit transaction
        $mysqli->commit();

        $message = 'Domain deleted successfully';
        if (!empty($removedIPs)) {
            $message .= '. Removed ' . count($removedIPs) . ' associated IP(s) from firewall rules: ' . implode(', ', $removedIPs);
        }

        echo json_encode(['status' => 'ok', 'message' => $message]);

    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

$mysqli->close();
?>
