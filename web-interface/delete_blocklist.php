<?php
// delete_blocklist.php - Delete a blocklist and its domains
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid id']);
    exit;
}

$mysqli->begin_transaction();
try {
    // Delete domains first due to FK
    $stmt = $mysqli->prepare('DELETE FROM blocklist_domains WHERE blocklist_id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Delete blocklist
    $stmt = $mysqli->prepare('DELETE FROM blocklists WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Before committing, remove firewall rules via helper
    $cmd = 'sudo -n /usr/local/sbin/zoplog-firewall-remove ' . escapeshellarg((string)$id);
    $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new Exception('Failed to spawn firewall removal process');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode !== 0) {
        throw new Exception('Firewall removal failed (exit ' . $exitCode . '): ' . trim($stderr ?: $stdout));
    }

    $mysqli->commit();
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
