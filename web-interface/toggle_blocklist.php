<?php
// toggle_blocklist.php - Toggle active state for a blocklist
require_once __DIR__ . '/db.php';
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

$stmt = $mysqli->prepare('UPDATE blocklists SET active = ?, updated_at = ? WHERE id = ?');
$now = date('Y-m-d H:i:s');
$stmt->bind_param('ssi', $state, $now, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    exit;
}

echo json_encode(['status' => 'ok']);
