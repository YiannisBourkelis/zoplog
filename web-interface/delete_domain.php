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

        // Find all IPs associated with this hostname
        $stmt = $mysqli->prepare('
            SELECT DISTINCT ip.ip_address, ip.id as ip_id
            FROM ip_addresses ip
            JOIN hostnames h ON h.ip_id = ip.id
            WHERE h.hostname = ?
        ');
        $stmt->bind_param('s', $domain);
        $stmt->execute();
        $ipResult = $stmt->get_result();
        $associatedIPs = [];
        while ($row = $ipResult->fetch_assoc()) {
            $associatedIPs[] = $row;
        }
        $stmt->close();

        // For each associated IP, check if it's blocked and remove from firewall
        $removedIPs = [];
        foreach ($associatedIPs as $ipData) {
            $ipAddress = $ipData['ip_address'];
            $ipId = $ipData['ip_id'];

            // Check if this IP is blocked for this blocklist domain
            $stmt = $mysqli->prepare('SELECT id FROM blocked_ips WHERE blocklist_domain_id = ? AND ip_id = ?');
            $stmt->bind_param('ii', $blocklistDomainId, $ipId);
            $stmt->execute();
            $blockedResult = $stmt->get_result();

            if ($blockedResult->num_rows > 0) {
                // Remove from firewall sets
                $ipFamily = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4';
                $setName = "zoplog-blocklist-{$blocklistId}-{$ipFamily}";

                // Execute nft delete command
                $scriptPath = __DIR__ . '/../scripts/zoplog-nft-del-element';
                $nftCommand = escapeshellcmd($scriptPath) . ' ' . escapeshellarg($ipFamily) . ' ' . escapeshellarg($setName) . ' ' . escapeshellarg($ipAddress);
                exec($nftCommand . ' 2>/dev/null', $output, $returnCode);

                if ($returnCode === 0) {
                    $removedIPs[] = $ipAddress;
                }

                // Remove from blocked_ips table
                $stmt->close();
                $stmt = $mysqli->prepare('DELETE FROM blocked_ips WHERE blocklist_domain_id = ? AND ip_id = ?');
                $stmt->bind_param('ii', $blocklistDomainId, $ipId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt->close();
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
