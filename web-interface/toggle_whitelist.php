<?php
// toggle_whitelist.php - Toggle active state for a whitelist
require_once __DIR__ . '/zoplog_config.php';
header('Content-Type: application/json');

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
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update whitelist']);
}
$stmt->close();
?>
