<?php
// toggle_blocklist.php - Toggle active state for a blocklist
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

// First attempt to apply firewall change via helper (idempotent)
require_once 'zoplog_config.php';
$scripts_path = get_zoplog_scripts_path();
$cmd = escapeshellarg($scripts_path . '/zoplog-firewall-toggle') . ' ' . escapeshellarg((string)$id) . ' ' . escapeshellarg($state);
$descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to spawn firewall toggle process']);
    exit;
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);
if ($exitCode !== 0) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Firewall toggle failed (exit ' . $exitCode . '): ' . trim($stderr ?: $stdout)]);
    exit;
}

// If firewall step succeeded, update DB state
$stmt = $mysqli->prepare('UPDATE blocklists SET active = ?, updated_at = ? WHERE id = ?');
$now = date('Y-m-d H:i:s');
$stmt->bind_param('ssi', $state, $now, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    exit;
}

echo json_encode(['status' => 'ok']);
