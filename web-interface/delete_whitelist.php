<?php
// delete_whitelist.php - Delete a whitelist and its domains
require_once __DIR__ . '/zoplog_config.php';
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
    $stmt = $mysqli->prepare('DELETE FROM whitelist_domains WHERE whitelist_id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Delete whitelist
    $stmt = $mysqli->prepare('DELETE FROM whitelists WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    $mysqli->commit();
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete whitelist: ' . $e->getMessage()]);
}
?>
