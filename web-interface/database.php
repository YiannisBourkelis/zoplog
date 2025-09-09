<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// database.php - Database information and monitoring page
require_once __DIR__ . '/zoplog_config.php';

$message = '';
$messageType = '';

// Function to get database information
function getDatabaseInfo() {
    global $mysqli;

    try {
        // Get total database size
        $result = $mysqli->query("
            SELECT
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_size_mb,
                ROUND(SUM(data_length) / 1024 / 1024, 2) AS data_size_mb,
                ROUND(SUM(index_length) / 1024 / 1024, 2) AS index_size_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $sizeInfo = $result->fetch_assoc();
        $result->free();

        // Get table details
        $result = $mysqli->query("
            SELECT
                table_name,
                table_rows,
                ROUND((data_length) / 1024 / 1024, 2) AS data_size_mb,
                ROUND((index_length) / 1024 / 1024, 2) AS index_size_mb,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_size_mb,
                table_comment
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            ORDER BY (data_length + index_length) DESC
        ");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row;
        }
        $result->free();

        // Get database connection info
        $result = $mysqli->query("SELECT DATABASE() as db_name, USER() as db_user, VERSION() as mysql_version");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $connectionInfo = $result->fetch_assoc();
        $result->free();

        // Get recent activity (last 24 hours)
        $result = $mysqli->query("
            SELECT
                COUNT(*) as total_packets_24h,
                COUNT(DISTINCT ip.ip_address) as unique_ips_24h
            FROM packet_logs pl
            LEFT JOIN ip_addresses ip ON pl.src_ip_id = ip.id
            WHERE pl.packet_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $activity24h = $result->fetch_assoc();
        $result->free();

        // Get blocked IPs count
        $result = $mysqli->query("SELECT COUNT(*) as blocked_ips_count FROM blocked_ips");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $blockedCount = $result->fetch_assoc();
        $result->free();

        // Get blocklist stats
        $result = $mysqli->query("
            SELECT
                (SELECT COUNT(*) FROM blocked_ips) as blocklist_ips,
                (SELECT COUNT(*) FROM blocklist_domains) as blocklist_domains,
                0 as whitelist_ips
        ");
        if (!$result) {
            throw new Exception("Query failed: " . $mysqli->error);
        }
        $blocklistStats = $result->fetch_assoc();
        $result->free();

        return [
            'size_info' => $sizeInfo,
            'tables' => $tables,
            'connection' => $connectionInfo,
            'activity_24h' => $activity24h,
            'blocked_count' => $blockedCount,
            'blocklist_stats' => $blocklistStats
        ];

    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Handle form submission for database maintenance
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'optimize_tables') {
            try {
                $result = $mysqli->query("SHOW TABLES");
                if (!$result) {
                    throw new Exception("Query failed: " . $mysqli->error);
                }
                
                $tables = [];
                while ($row = $result->fetch_array()) {
                    $tables[] = $row[0];
                }
                $result->free();

                $results = [];
                foreach ($tables as $table) {
                    $optimize_result = $mysqli->query("OPTIMIZE TABLE `$table`");
                    if (!$optimize_result) {
                        throw new Exception("Failed to optimize table $table: " . $mysqli->error);
                    }
                    $results[] = "Optimized table: $table";
                }

                $message = "Database optimization completed:\n" . implode("\n", $results);
                $messageType = 'success';

            } catch (Exception $e) {
                $message = "Error optimizing database: " . $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'check_integrity') {
            try {
                $result = $mysqli->query("SHOW TABLES");
                if (!$result) {
                    throw new Exception("Query failed: " . $mysqli->error);
                }
                
                $tables = [];
                while ($row = $result->fetch_array()) {
                    $tables[] = $row[0];
                }
                $result->free();

                $results = [];
                foreach ($tables as $table) {
                    $check_result = $mysqli->query("CHECK TABLE `$table`");
                    if (!$check_result) {
                        throw new Exception("Failed to check table $table: " . $mysqli->error);
                    }
                    $check_row = $check_result->fetch_assoc();
                    $results[] = "$table: " . $check_row['Msg_text'];
                    $check_result->free();
                }

                $message = "Database integrity check results:\n" . implode("\n", $results);
                $messageType = 'info';

            } catch (Exception $e) {
                $message = "Error checking database integrity: " . $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'backup_database') {
            try {
                // Get database name
                $db_result = $mysqli->query("SELECT DATABASE() as db_name");
                $db_row = $db_result->fetch_assoc();
                $db_name = $db_row['db_name'];
                $db_result->free();

                // Create backup directory - use system temp if web directory is not writable
                $backup_dir = __DIR__ . '/backups';
                if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
                    $backup_dir = sys_get_temp_dir() . '/zoplog_backups';
                    if (!is_dir($backup_dir)) {
                        mkdir($backup_dir, 0755, true);
                    }
                }

                // Generate filename with timestamp
                $timestamp = date('Y-m-d_H-i-s');
                $sql_filename = "backup_{$db_name}_{$timestamp}.sql";
                $zip_filename = "backup_{$db_name}_{$timestamp}.zip";
                $sql_filepath = $backup_dir . '/' . $sql_filename;
                $zip_filepath = $backup_dir . '/' . $zip_filename;

                // Get all tables
                $tables_result = $mysqli->query("SHOW TABLES");
                $tables = [];
                while ($row = $tables_result->fetch_array()) {
                    $tables[] = $row[0];
                }
                $tables_result->free();

                // Create SQL dump
                $sql_content = "-- ZopLog Database Backup\n";
                $sql_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
                $sql_content .= "-- Database: {$db_name}\n\n";

                $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

                foreach ($tables as $table) {
                    // Get table structure
                    $create_result = $mysqli->query("SHOW CREATE TABLE `$table`");
                    $create_row = $create_result->fetch_assoc();
                    $sql_content .= "-- Table structure for `$table`\n";
                    $sql_content .= $create_row['Create Table'] . ";\n\n";
                    $create_result->free();

                    // Get table data
                    $data_result = $mysqli->query("SELECT * FROM `$table`");
                    if ($data_result->num_rows > 0) {
                        $sql_content .= "-- Data for `$table`\n";
                        while ($row = $data_result->fetch_assoc()) {
                            $values = array_map(function($value) use ($mysqli) {
                                return $value === null ? 'NULL' : "'" . $mysqli->real_escape_string($value) . "'";
                            }, $row);
                            $sql_content .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $sql_content .= "\n";
                    }
                    $data_result->free();
                }

                $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";

                // Write SQL file
                file_put_contents($sql_filepath, $sql_content);

                // Create ZIP file
                $zip = new ZipArchive();
                if ($zip->open($zip_filepath, ZipArchive::CREATE) === TRUE) {
                    $zip->addFile($sql_filepath, $sql_filename);
                    $zip->close();

                    // Clean up SQL file
                    unlink($sql_filepath);

                    // If backup was created in temp directory, copy to web directory
                    $web_backup_dir = __DIR__ . '/backups';
                    if ($backup_dir !== $web_backup_dir) {
                        if (!is_dir($web_backup_dir)) {
                            mkdir($web_backup_dir, 0755, true);
                        }
                        $web_zip_filepath = $web_backup_dir . '/' . $zip_filename;
                        copy($zip_filepath, $web_zip_filepath);
                        unlink($zip_filepath);
                        $zip_filepath = $web_zip_filepath;
                    }

                    $message = "Database backup created successfully! <a href='backups/{$zip_filename}' class='text-blue-600 underline' download>Click here to download</a>";
                    $messageType = 'success';

                } else {
                    throw new Exception("Failed to create ZIP file");
                }

            } catch (Exception $e) {
                $message = "Error creating database backup: " . $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'delete_backup') {
            try {
                if (!isset($_POST['filename'])) {
                    throw new Exception("No filename specified");
                }

                $filename = basename($_POST['filename']); // Prevent directory traversal
                $filepath = __DIR__ . '/backups/' . $filename;

                // Validate file exists and is in backups directory
                if (!file_exists($filepath)) {
                    throw new Exception("Backup file not found");
                }

                // Additional security: ensure file is actually a .zip file
                if (pathinfo($filepath, PATHINFO_EXTENSION) !== 'zip') {
                    throw new Exception("Invalid file type");
                }

                // Delete the file
                if (unlink($filepath)) {
                    $message = "Backup file '" . htmlspecialchars($filename) . "' has been deleted successfully.";
                    $messageType = 'success';
                } else {
                    throw new Exception("Failed to delete backup file");
                }

            } catch (Exception $e) {
                $message = "Error deleting backup file: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$dbInfo = getDatabaseInfo();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZopLog - Database Information</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-900">
    <?php include "menu.php"; ?>

    <div class="container mx-auto py-6 px-4">
        <div class="flex items-center space-x-3 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
            </svg>
            <h1 class="text-2xl font-bold">Database Information</h1>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800 border border-green-200' :
                                                    ($messageType === 'error' ? 'bg-red-100 text-red-800 border border-red-200' :
                                                    ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' :
                                                    'bg-blue-100 text-blue-800 border border-blue-200')) ?>">
                <div class="font-mono text-sm">
                    <?php
                    // Check if message contains HTML tags
                    if (strpos($message, '<') !== false && strpos($message, '>') !== false) {
                        // Message contains HTML, output as-is
                        echo $message;
                    } else {
                        // Message is plain text, escape it
                        echo htmlspecialchars($message);
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($dbInfo['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <strong>Error:</strong> <?php echo htmlspecialchars($dbInfo['error']); ?>
            </div>
        <?php else: ?>

        <!-- Database Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Size</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($dbInfo['size_info']['total_size_mb']); ?> MB</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Data Size</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($dbInfo['size_info']['data_size_mb']); ?> MB</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-purple-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Index Size</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($dbInfo['size_info']['index_size_mb']); ?> MB</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Blocked IPs</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($dbInfo['blocked_count']['blocked_ips_count']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Summary -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                </svg>
                Recent Activity (Last 24 Hours)
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-blue-600"><?php echo number_format($dbInfo['activity_24h']['total_packets_24h']); ?></div>
                    <div class="text-sm text-gray-600">Packets Logged</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?php echo number_format($dbInfo['activity_24h']['unique_ips_24h']); ?></div>
                    <div class="text-sm text-gray-600">Unique IPs</div>
                </div>
            </div>
        </div>

        <!-- Blocklist Summary -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Blocklist & Whitelist Summary
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-red-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-red-600"><?php echo number_format($dbInfo['blocklist_stats']['blocklist_ips']); ?></div>
                    <div class="text-sm text-gray-600">Blocked IPs</div>
                </div>
                <div class="bg-orange-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-orange-600"><?php echo number_format($dbInfo['blocklist_stats']['blocklist_domains']); ?></div>
                    <div class="text-sm text-gray-600">Blocked Domains</div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-2xl font-bold text-green-600"><?php echo number_format($dbInfo['blocklist_stats']['whitelist_ips']); ?></div>
                    <div class="text-sm text-gray-600">Whitelisted IPs</div>
                </div>
            </div>
        </div>

        <!-- Database Connection Info -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                </svg>
                Database Connection
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <span class="text-sm font-medium text-gray-600">Database:</span>
                    <div class="font-mono text-sm"><?php echo htmlspecialchars($dbInfo['connection']['db_name']); ?></div>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600">User:</span>
                    <div class="font-mono text-sm"><?php echo htmlspecialchars($dbInfo['connection']['db_user']); ?></div>
                </div>
                <div>
                    <span class="text-sm font-medium text-gray-600">MySQL Version:</span>
                    <div class="font-mono text-sm"><?php echo htmlspecialchars($dbInfo['connection']['mysql_version']); ?></div>
                </div>
            </div>
        </div>

        <!-- Table Details -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                Table Details
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Table Name</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rows</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data Size</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Index Size</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Size</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($dbInfo['tables'] as $table): ?>
                        <tr>
                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($table['table_name']); ?>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                <?php echo number_format($table['table_rows']); ?>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($table['data_size_mb']); ?> MB
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($table['index_size_mb']); ?> MB
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($table['total_size_mb']); ?> MB
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Database Maintenance -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                </svg>
                Database Maintenance
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="optimize_tables">
                    <button type="submit"
                            class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Optimize Tables
                    </button>
                </form>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="check_integrity">
                    <button type="submit"
                            class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition duration-200 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Check Integrity
                    </button>
                </form>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="backup_database">
                    <button type="submit"
                            class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition duration-200 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Backup Database
                    </button>
                </form>
            </div>
            <div class="mt-4 text-sm text-gray-600">
                <p><strong>Optimize Tables:</strong> Reorganizes table data and indexes for better performance.</p>
                <p><strong>Check Integrity:</strong> Verifies that table data is not corrupted.</p>
                <p><strong>Backup Database:</strong> Creates a complete SQL backup and downloads it as a ZIP file.</p>
            </div>

            <!-- Existing Backup Files -->
            <?php
            $backup_dir = __DIR__ . '/backups';
            $backup_files = [];

            if (is_dir($backup_dir)) {
                $files = scandir($backup_dir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                        $file_path = $backup_dir . '/' . $file;
                        $backup_files[] = [
                            'name' => $file,
                            'path' => $file_path,
                            'size' => filesize($file_path),
                            'modified' => filemtime($file_path)
                        ];
                    }
                }
                // Sort by modification time (newest first)
                usort($backup_files, function($a, $b) {
                    return $b['modified'] - $a['modified'];
                });
            }
            ?>

            <?php if (!empty($backup_files)): ?>
            <div class="mt-6 border-t pt-6">
                <h3 class="text-lg font-semibold mb-4 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Existing Backup Files (<?php echo count($backup_files); ?>)
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filename</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($backup_files as $file): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($file['name']); ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo number_format($file['size'] / 1024 / 1024, 2); ?> MB
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('Y-m-d H:i:s', $file['modified']); ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm space-x-2">
                                    <a href="backups/<?php echo urlencode($file['name']); ?>"
                                       class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded text-blue-700 bg-blue-100 hover:bg-blue-200 transition duration-200"
                                       download>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        Download
                                    </a>
                                    <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this backup file?')">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="filename" value="<?php echo htmlspecialchars($file['name']); ?>">
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded text-red-700 bg-red-100 hover:bg-red-200 transition duration-200">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>
