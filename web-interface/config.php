<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'logs.db');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

// Create a database connection
try {
    $pdo = new PDO("sqlite:" . __DIR__ . '/../data/' . DB_NAME);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>