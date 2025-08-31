<?php
// db.php - MariaDB connection helper
// Adjust credentials as needed
$DB_HOST = getenv('ZOPLOG_DB_HOST') ?: 'localhost';
$DB_USER = getenv('ZOPLOG_DB_USER') ?: 'root';
$DB_PASS = getenv('ZOPLOG_DB_PASS') ?: '8888';
$DB_NAME = getenv('ZOPLOG_DB_NAME') ?: 'logs_db';

$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($mysqli->connect_error));
}
$mysqli->set_charset('utf8mb4');
?>
