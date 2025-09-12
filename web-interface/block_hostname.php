<?php
// block_hostname.php - Add hostname to system blocklist
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$hostname = trim($_POST['hostname'] ?? '');

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
    // Get system blocklist ID
    $systemBlocklistRes = $mysqli->query("SELECT id FROM blocklists WHERE type = 'system' LIMIT 1");
    if (!$systemBlocklistRes || $systemBlocklistRes->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'System blocklist not found']);
        exit;
    }

    $systemBlocklistId = $systemBlocklistRes->fetch_assoc()['id'];

    // Check if hostname already exists in system blocklist
    $existingRes = $mysqli->query("
        SELECT id FROM blocklist_domains
        WHERE blocklist_id = $systemBlocklistId AND domain = '" . $mysqli->real_escape_string($hostname) . "'
    ");

    if ($existingRes && $existingRes->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Hostname already blocked']);
        exit;
    }

    // Add hostname to system blocklist
    $stmt = $mysqli->prepare("INSERT INTO blocklist_domains (blocklist_id, domain) VALUES (?, ?)");
    $stmt->bind_param("is", $systemBlocklistId, $hostname);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'ok', 'message' => 'Hostname blocked successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to block hostname']);
    }

    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

$mysqli->close();
?>
