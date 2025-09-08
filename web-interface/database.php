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
    <title>Database Information - ZopLog</title>
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
                <pre class="whitespace-pre-wrap font-mono text-sm"><?php echo htmlspecialchars($message); ?></pre>
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
            </div>
            <div class="mt-4 text-sm text-gray-600">
                <p><strong>Optimize Tables:</strong> Reorganizes table data and indexes for better performance.</p>
                <p><strong>Check Integrity:</strong> Verifies that table data is not corrupted.</p>
            </div>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>
