<?php
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

try {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT message FROM blocked_event_messages WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['message' => $row['message']]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found']);
    }

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Exception occurred",
        "message" => $e->getMessage()
    ]);
}