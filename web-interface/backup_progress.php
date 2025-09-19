<?php
// backup_progress.php - Check backup progress
require_once __DIR__ . '/zoplog_config.php';

header('Content-Type: application/json');

$timestamp = $_GET['timestamp'] ?? '';
if (empty($timestamp)) {
    http_response_code(400);
    echo json_encode(['error' => 'No timestamp provided']);
    exit;
}

// Check both possible locations for progress file
$progress_files = [
    __DIR__ . '/backups/backup_progress_' . $timestamp . '.json',
    sys_get_temp_dir() . '/zoplog_backups/backup_progress_' . $timestamp . '.json'
];

foreach ($progress_files as $progress_file) {
    if (file_exists($progress_file)) {
        $progress = json_decode(file_get_contents($progress_file), true);
        if ($progress) {
            // Add elapsed time
            $progress['elapsed_time'] = time() - $progress['start_time'];

            // Clean up old progress files (older than 1 hour)
            if ($progress['status'] === 'completed' && $progress['elapsed_time'] > 3600) {
                unlink($progress_file);
            }

            echo json_encode($progress);
            exit;
        }
    }
}

// If no progress file found, return not found
http_response_code(404);
echo json_encode(['error' => 'Backup progress not found']);
?>