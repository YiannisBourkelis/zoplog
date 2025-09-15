#!/bin/bash
# Check PHP opcache status on remote machine

echo "=== PHP Opcache Status Check ==="
echo

# Check PHP version
echo "PHP Version:"
php -v | head -n1
echo

# Check if opcache extension is loaded
echo "Opcache Extension Status:"
if php -m | grep -q opcache; then
    echo "✅ Opcache extension is loaded"
else
    echo "❌ Opcache extension is NOT loaded"
fi
echo

# Check opcache configuration
echo "Opcache Configuration:"
echo "opcache.enable: $(php -r 'echo ini_get("opcache.enable") ? "On" : "Off";')"
echo "opcache.enable_cli: $(php -r 'echo ini_get("opcache.enable_cli") ? "On" : "Off";')"
echo "opcache.memory_consumption: $(php -r 'echo ini_get("opcache.memory_consumption");') MB"
echo "opcache.max_accelerated_files: $(php -r 'echo ini_get("opcache.max_accelerated_files");')"
echo "opcache.revalidate_freq: $(php -r 'echo ini_get("opcache.revalidate_freq");')"
echo

# Check opcache statistics if enabled
echo "Opcache Statistics:"
if php -r 'echo ini_get("opcache.enable");' | grep -q "1"; then
    php -r '
    if (function_exists("opcache_get_status")) {
        $status = opcache_get_status(false);
        if ($status) {
            echo "Cache hits: " . ($status["opcache_statistics"]["hits"] ?? "N/A") . "\n";
            echo "Cache misses: " . ($status["opcache_statistics"]["misses"] ?? "N/A") . "\n";
            echo "Cache full: " . ($status["opcache_statistics"]["cache_full"] ? "Yes" : "No") . "\n";
            echo "Memory used: " . round(($status["memory_usage"]["used_memory"] ?? 0) / 1024 / 1024, 2) . " MB\n";
            echo "Memory free: " . round(($status["memory_usage"]["free_memory"] ?? 0) / 1024 / 1024, 2) . " MB\n";
            echo "Memory wasted: " . round(($status["memory_usage"]["wasted_memory"] ?? 0) / 1024 / 1024, 2) . " MB\n";
        } else {
            echo "Opcache is enabled but no statistics available yet\n";
        }
    } else {
        echo "opcache_get_status function not available\n";
    }
    '
else
    echo "Opcache is disabled"
fi
echo

# Show PHP configuration file locations
echo "PHP Configuration Files:"
echo "FPM config: $(php -r 'echo php_ini_loaded_file();')"
echo "CLI config: $(php -c /etc/php/*/cli/php.ini 2>/dev/null -r 'echo php_ini_loaded_file();' 2>/dev/null || echo "Not found")"
echo

echo "=== End of Opcache Check ==="