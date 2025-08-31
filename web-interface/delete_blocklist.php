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

    $mysqli->commit();
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
