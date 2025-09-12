<?php
// check_blocked_hostnames.php - Check which hostnames are blocked in system blocklist
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$hostnamesJson = trim($_POST['hostnames'] ?? '');

if (empty($hostnamesJson)) {
    echo json_encode(['status' => 'error', 'message' => 'Hostnames are required']);
    exit;
}

$hostnames = json_decode($hostnamesJson, true);

if (!is_array($hostnames) || empty($hostnames)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid hostnames format']);
    exit;
}

try {
    // Get system blocklist ID
    $systemBlocklistRes = $mysqli->query("SELECT id FROM blocklists WHERE type = 'system' LIMIT 1");
    if (!$systemBlocklistRes || $systemBlocklistRes->num_rows === 0) {
        echo json_encode(['status' => 'ok', 'blocked' => []]);
        exit;
    }

    $systemBlocklistId = $systemBlocklistRes->fetch_assoc()['id'];

    // Prepare placeholders for IN clause
    $placeholders = str_repeat('?,', count($hostnames) - 1) . '?';

    // Check which hostnames are blocked
    $stmt = $mysqli->prepare("
        SELECT domain FROM blocklist_domains
        WHERE blocklist_id = ? AND domain IN ($placeholders)
    ");
    $stmt->bind_param('i' . str_repeat('s', count($hostnames)), $systemBlocklistId, ...$hostnames);

    $stmt->execute();
    $result = $stmt->get_result();

    $blocked = [];
    while ($row = $result->fetch_assoc()) {
        $blocked[] = $row['domain'];
    }

    echo json_encode(['status' => 'ok', 'blocked' => $blocked]);

    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

$mysqli->close();
?>
