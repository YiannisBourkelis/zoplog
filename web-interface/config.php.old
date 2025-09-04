<?php
// ZopLog Web Interface Configuration

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'logs_db');
define('DB_USER', 'zoplog_db');
define('DB_PASS', 'uxaz0F0XMDAG7X1pVRF8yGCTo5sT7dnYNAVwZukags4=');

// System settings file path
define('SYSTEM_SETTINGS_FILE', '/home/yiannis/projects/zoplog/settings.json');

// Default system settings
define('DEFAULT_MONITOR_INTERFACE', 'br-zoplog');

/**
 * Load system settings from JSON file
 */
function loadSystemSettings() {
    $defaults = [
        'monitor_interface' => DEFAULT_MONITOR_INTERFACE,
        'last_updated' => null
    ];
    
    if (file_exists(SYSTEM_SETTINGS_FILE)) {
        $content = file_get_contents(SYSTEM_SETTINGS_FILE);
        if ($content !== false) {
            $settings = json_decode($content, true);
            if ($settings !== null) {
                return array_merge($defaults, $settings);
            }
        }
    }
    return $defaults;
}

/**
 * Save system settings to JSON file
 */
function saveSystemSettings($settings) {
    $settings['last_updated'] = date('Y-m-d H:i:s');
    
    $json = json_encode($settings, JSON_PRETTY_PRINT);
    $result = file_put_contents(SYSTEM_SETTINGS_FILE, $json);
    
    if ($result !== false) {
        // Set proper permissions
        chmod(SYSTEM_SETTINGS_FILE, 0644);
        return true;
    }
    return false;
}

// Create a database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
                   DB_USER, 
                   DB_PASS,
                   [
                       PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                       PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                   ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    echo "Database connection failed. Please check configuration.";
}
?>