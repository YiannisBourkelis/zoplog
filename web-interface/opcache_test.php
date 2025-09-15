<?php
// Opcache Test Page for ZopLog
echo "<!DOCTYPE html>";
echo "<html lang='en'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>ZopLog - Opcache Test</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }";
echo ".container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }";
echo "h1 { color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px; }";
echo ".status-good { color: #059669; font-weight: bold; }";
echo ".status-bad { color: #dc2626; font-weight: bold; }";
echo "table { width: 100%; border-collapse: collapse; margin-top: 20px; }";
echo "th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }";
echo "th { background-color: #f9fafb; font-weight: 600; }";
echo ".metric-value { font-weight: bold; color: #2563eb; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>🔧 ZopLog Opcache Status</h1>";

// Check if opcache is enabled
$opcache_enabled = ini_get('opcache.enable');
$status_class = $opcache_enabled ? 'status-good' : 'status-bad';
$status_text = $opcache_enabled ? '✅ Enabled' : '❌ Disabled';

echo "<p><strong>Opcache Status:</strong> <span class='$status_class'>$status_text</span></p>";

if ($opcache_enabled) {
    echo "<table>";
    echo "<tr><th>Configuration</th><th>Value</th><th>Status</th></tr>";

    // Memory consumption
    $memory_mb = ini_get('opcache.memory_consumption');
    $memory_status = $memory_mb >= 128 ? '✅ Good' : '⚠️ Low';
    echo "<tr><td>Memory Consumption</td><td class='metric-value'>{$memory_mb} MB</td><td>$memory_status</td></tr>";

    // Max accelerated files
    $max_files = ini_get('opcache.max_accelerated_files');
    $files_status = $max_files >= 4000 ? '✅ Good' : '⚠️ Low';
    echo "<tr><td>Max Accelerated Files</td><td class='metric-value'>{$max_files}</td><td>$files_status</td></tr>";

    // Revalidate frequency
    $revalidate_freq = ini_get('opcache.revalidate_freq');
    $revalidate_status = $revalidate_freq == 0 ? '✅ Production Ready' : '⚠️ Development Mode';
    echo "<tr><td>Revalidate Frequency</td><td class='metric-value'>{$revalidate_freq} seconds</td><td>$revalidate_status</td></tr>";

    // Validate timestamps
    $validate_timestamps = ini_get('opcache.validate_timestamps');
    $validate_status = $validate_timestamps ? '✅ Enabled' : '❌ Disabled';
    echo "<tr><td>Validate Timestamps</td><td class='metric-value'>" . ($validate_timestamps ? 'Yes' : 'No') . "</td><td>$validate_status</td></tr>";

    echo "</table>";

    // Opcache statistics
    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status(false);
        if ($status) {
            echo "<h2>📊 Cache Statistics</h2>";
            echo "<table>";
            echo "<tr><th>Metric</th><th>Value</th></tr>";

            $hits = $status['opcache_statistics']['hits'] ?? 0;
            $misses = $status['opcache_statistics']['misses'] ?? 0;
            $total = $hits + $misses;
            $hit_rate = $total > 0 ? round(($hits / $total) * 100, 1) : 0;

            echo "<tr><td>Cache Hits</td><td class='metric-value'>{$hits}</td></tr>";
            echo "<tr><td>Cache Misses</td><td class='metric-value'>{$misses}</td></tr>";
            echo "<tr><td>Hit Rate</td><td class='metric-value'>{$hit_rate}%</td></tr>";

            $used_memory = round(($status['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2);
            $free_memory = round(($status['memory_usage']['free_memory'] ?? 0) / 1024 / 1024, 2);
            $total_memory = $used_memory + $free_memory;

            echo "<tr><td>Memory Used</td><td class='metric-value'>{$used_memory} MB</td></tr>";
            echo "<tr><td>Memory Free</td><td class='metric-value'>{$free_memory} MB</td></tr>";
            echo "<tr><td>Memory Total</td><td class='metric-value'>{$total_memory} MB</td></tr>";

            echo "</table>";
        }
    }

    echo "<h2>🎯 Performance Impact</h2>";
    echo "<p>With opcache enabled and properly configured, your ZopLog web interface should load significantly faster!</p>";
    echo "<ul>";
    echo "<li>✅ PHP files are compiled once and cached in memory</li>";
    echo "<li>✅ Reduced CPU usage for PHP execution</li>";
    echo "<li>✅ Faster page load times</li>";
    echo "<li>✅ Better user experience</li>";
    echo "</ul>";

} else {
    echo "<div style='background-color: #fef2f2; border: 1px solid #fecaca; padding: 16px; border-radius: 8px; margin-top: 20px;'>";
    echo "<h3 style='color: #dc2626; margin-top: 0;'>⚠️ Opcache is Disabled</h3>";
    echo "<p>To enable opcache for better performance:</p>";
    echo "<ol>";
    echo "<li>Install opcache extension: <code>sudo apt-get install php8.4-opcache</code></li>";
    echo "<li>Enable in php.ini: <code>opcache.enable=1</code></li>";
    echo "<li>Restart PHP-FPM: <code>sudo systemctl restart php8.4-fpm</code></li>";
    echo "</ol>";
    echo "</div>";
}

echo "<p style='margin-top: 30px; color: #6b7280; font-size: 14px;'>";
echo "Generated on: " . date('Y-m-d H:i:s') . " | PHP Version: " . phpversion();
echo "</p>";

echo "</div>";
echo "</body>";
echo "</html>";
?>