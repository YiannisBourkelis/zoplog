<?php
/**
 * Centralized ZopLog Database Configuration
 * Reads from /etc/zoplog/database.conf with fallbacks
 */

function load_zoplog_database_config() {
    // Default configuration
    $config = [
        'host' => getenv('ZOPLOG_DB_HOST') ?: 'localhost',
        'user' => getenv('ZOPLOG_DB_USER') ?: 'root', 
        'password' => getenv('ZOPLOG_DB_PASS') ?: '',
        'database' => getenv('ZOPLOG_DB_NAME') ?: 'logs_db',
        'port' => (int)(getenv('ZOPLOG_DB_PORT') ?: 3306),
    ];
    
    // Try config file locations in order
    $config_paths = [
        '/etc/zoplog/database.conf',
    ];
    
    foreach ($config_paths as $path) {
        if (!file_exists($path)) continue;
        
        try {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'conf') {
                // INI-style config file
                $ini = parse_ini_file($path, true);
                if (isset($ini['database'])) {
                    $db_section = $ini['database'];
                    $config['host'] = $db_section['host'] ?? $config['host'];
                    $config['user'] = $db_section['user'] ?? $config['user'];
                    $config['password'] = $db_section['password'] ?? $config['password'];
                    $config['database'] = $db_section['name'] ?? $config['database'];
                    $config['port'] = (int)($db_section['port'] ?? $config['port']);
                    break;
                }
            } else {
                // Legacy key=value format
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || $line[0] === '#') continue;
                        
                        $parts = explode('=', $line, 2);
                        if (count($parts) === 2) {
                            $key = strtoupper(trim($parts[0]));
                            $value = trim($parts[1]);
                            
                            switch ($key) {
                                case 'DB_HOST':
                                case 'HOST':
                                    $config['host'] = $value;
                                    break;
                                case 'DB_USER':
                                case 'USER':
                                    $config['user'] = $value;
                                    break;
                                case 'DB_PASS':
                                case 'PASSWORD':
                                case 'PASS':
                                    $config['password'] = $value;
                                    break;
                                case 'DB_NAME':
                                case 'DATABASE':
                                case 'NAME':
                                    $config['database'] = $value;
                                    break;
                                case 'DB_PORT':
                                case 'PORT':
                                    $config['port'] = (int)$value;
                                    break;
                            }
                        }
                    }
                    break;
                }
            }
        } catch (Exception $e) {
            error_log("Warning: Could not read ZopLog config from $path: " . $e->getMessage());
            continue;
        }
    }
    
    // Final fallback for password
    if (empty($config['password'])) {
        $config['password'] = 'uxaz0F0XMDAG7X1pVRF8yGCTo5sT7dnYNAVwZukags4=';
    }
    
    return $config;
}

// Load configuration and create connection
$db_config = load_zoplog_database_config();

$mysqli = @new mysqli(
    $db_config['host'],
    $db_config['user'], 
    $db_config['password'],
    $db_config['database'],
    $db_config['port']
);

if ($mysqli->connect_errno) {
    http_response_code(500);
    die('Database connection failed: ' . htmlspecialchars($mysqli->connect_error));
}

$mysqli->set_charset('utf8mb4');

// Export for backwards compatibility
$DB_HOST = $db_config['host'];
$DB_USER = $db_config['user'];
$DB_PASS = $db_config['password'];
$DB_NAME = $db_config['database'];
$DB_PORT = $db_config['port'];
?>
