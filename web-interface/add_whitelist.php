<?php
// add_whitelist.php - Handle adding a new whitelist
// Expects POST: name (required), category (required)

require_once __DIR__ . '/zoplog_config.php';

function respond_json($status, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

// Allowed categories
$allowedCategories = [
  'adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other'
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json('error', 'Method not allowed');
}

$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');

$errors = [];
if ($name === '') {
    $errors['name'] = 'Name is required.';
}
if ($category === '' || !in_array($category, $allowedCategories, true)) {
    $errors['category'] = 'Please select a valid category.';
}

if (!empty($errors)) {
    respond_json('validation_error', 'Validation failed', ['errors' => $errors]);
}

// Insert whitelist
$now = date('Y-m-d H:i:s');
$stmt = $mysqli->prepare('INSERT INTO whitelists (name, category, active, created_at, updated_at) VALUES (?, ?, "active", ?, ?)');
$stmt->bind_param('ssss', $name, $category, $now, $now);
if ($stmt->execute()) {
    $whitelistId = $stmt->insert_id;
    $stmt->close();
    respond_json('ok', 'Whitelist added successfully', ['whitelist_id' => $whitelistId]);
} else {
    $stmt->close();
    respond_json('error', 'Failed to add whitelist: ' . htmlspecialchars($stmt->error));
}
?>
