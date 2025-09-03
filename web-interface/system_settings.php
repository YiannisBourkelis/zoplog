<?php
// system_settings.php - System configuration page
require_once __DIR__ . '/db.php';

$message = '';
$messageType = '';

// Load current settings
function loadSystemSettings() {
    $settingsFile = '/home/yiannis/projects/zoplog/settings.json';
    $defaults = [
        'monitor_interface' => 'br-zoplog',
        'last_updated' => null
    ];
    
    if (file_exists($settingsFile)) {
        $content = file_get_contents($settingsFile);
        if ($content !== false) {
            $settings = json_decode($content, true);
            if ($settings !== null) {
                return array_merge($defaults, $settings);
            }
        }
    }
    return $defaults;
}

// Get available network interfaces
function getAvailableInterfaces() {
    $interfaces = [];
    $output = shell_exec('ip link show 2>/dev/null');
    if ($output) {
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (strpos($line, ': ') !== false && stripos($line, 'state') !== false) {
                $parts = explode(':', $line, 3);
                if (isset($parts[1])) {
                    $interface = trim(explode('@', $parts[1])[0]);
                    if ($interface !== 'lo') { // Skip loopback
                        $interfaces[] = $interface;
                    }
                }
            }
        }
    }
    
    // Fallback interfaces if detection fails
    if (empty($interfaces)) {
        $interfaces = ['eth0', 'eth1', 'br-zoplog'];
    }
    
    return $interfaces;
}

// Save settings
function saveSystemSettings($settings) {
    $settingsFile = '/home/yiannis/projects/zoplog/settings.json';
    $settingsDir = dirname($settingsFile);
    
    // Ensure directory exists
    if (!is_dir($settingsDir)) {
        if (!mkdir($settingsDir, 0755, true)) {
            error_log("Could not create settings directory: $settingsDir");
            return false;
        }
    }
    
    $settings['last_updated'] = date('Y-m-d H:i:s');
    
    $json = json_encode($settings, JSON_PRETTY_PRINT);
    
    // Try to write the file
    $result = file_put_contents($settingsFile, $json);
    
    if ($result !== false) {
        // Set proper permissions
        chmod($settingsFile, 0664);
        
        // Try to set group to www-data for web access
        if (function_exists('chgrp')) {
            @chgrp($settingsFile, 'www-data');
        }
        
        return true;
    } else {
        error_log("Could not write to settings file: $settingsFile");
        return false;
    }
}

// Test traffic logging
function testTrafficLogging($interface) {
    // Create a simple test script
    $testScript = '/tmp/zoplog_test.py';
    $testContent = '#!/usr/bin/env python3
import scapy.all as scapy
import sys
import signal
import time

packets_captured = 0

def packet_handler(packet):
    global packets_captured
    packets_captured += 1
    if packets_captured >= 5:  # Capture just a few packets for test
        print(f"SUCCESS: Captured {packets_captured} packets on interface ' . $interface . '")
        sys.exit(0)

def timeout_handler(signum, frame):
    print(f"TIMEOUT: Only captured {packets_captured} packets in 10 seconds")
    sys.exit(1)

# Set timeout
signal.signal(signal.SIGALRM, timeout_handler)
signal.alarm(10)

try:
    print("Testing packet capture on interface ' . $interface . '...")
    scapy.sniff(iface="' . $interface . '", prn=packet_handler, count=5, timeout=10, store=False)
except Exception as e:
    print(f"ERROR: {e}")
    sys.exit(1)
';

    file_put_contents($testScript, $testContent);
    chmod($testScript, 0755);
    
    // Run the test
    $output = shell_exec("cd /opt/zoplog/zoplog/python-logger && source venv/bin/activate && python3 $testScript 2>&1");
    unlink($testScript);
    
    return $output;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'save_settings') {
            $interface = $_POST['monitor_interface'] ?? '';
            $availableInterfaces = getAvailableInterfaces();
            
            if (in_array($interface, $availableInterfaces)) {
                $settings = [
                    'monitor_interface' => $interface
                ];
                
                // Debug information
                $settingsFile = '/home/yiannis/projects/zoplog/settings.json';
                $settingsDir = dirname($settingsFile);
                
                // Check permissions and directory
                $debugInfo = [];
                $debugInfo[] = "Settings file path: $settingsFile";
                $debugInfo[] = "Settings directory: $settingsDir";
                $debugInfo[] = "Directory exists: " . (is_dir($settingsDir) ? 'YES' : 'NO');
                $debugInfo[] = "Directory writable: " . (is_writable($settingsDir) ? 'YES' : 'NO');
                $debugInfo[] = "File exists: " . (file_exists($settingsFile) ? 'YES' : 'NO');
                if (file_exists($settingsFile)) {
                    $debugInfo[] = "File writable: " . (is_writable($settingsFile) ? 'YES' : 'NO');
                }
                $debugInfo[] = "Current user: " . get_current_user();
                $debugInfo[] = "Web server user: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_getuid())['name'] : 'unknown');
                
                if (saveSystemSettings($settings)) {
                    $message = "Settings saved successfully! You may need to restart the logger service for changes to take effect.\n\nInterface set to: $interface";
                    $messageType = 'success';
                } else {
                    $message = "Error: Could not save settings file.\n\nDebugging information:\n" . implode("\n", $debugInfo);
                    $messageType = 'error';
                }
            } else {
                $message = "Error: Invalid interface selected.";
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'test_logging') {
            $interface = $_POST['test_interface'] ?? '';
            $availableInterfaces = getAvailableInterfaces();
            
            if (in_array($interface, $availableInterfaces)) {
                $testResult = testTrafficLogging($interface);
                $message = "Test Result:\n" . $testResult;
                $messageType = strpos($testResult, 'SUCCESS') !== false ? 'success' : 'warning';
            } else {
                $message = "Error: Invalid interface for testing.";
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'restart_services') {
            $services = ['zoplog-logger', 'zoplog-blockreader'];
            $results = [];
            $allSuccess = true;
            
            foreach ($services as $service) {
                $output = shell_exec("sudo systemctl restart $service 2>&1");
                $status = shell_exec("sudo systemctl is-active $service 2>&1");
                
                if ($output === null) {
                    $results[] = "$service: Restart command executed";
                    $results[] = "$service status: " . trim($status ?: 'unknown');
                } else {
                    $results[] = "$service: " . trim($output);
                    $allSuccess = false;
                }
            }
            
            $message = "Services restart " . ($allSuccess ? "completed" : "attempted") . ":\n" . implode("\n", $results);
            $messageType = $allSuccess ? 'success' : 'warning';
            
            // Wait a moment and check final status
            sleep(2);
            $message .= "\n\nFinal Status Check:";
            foreach ($services as $service) {
                $status = shell_exec("sudo systemctl is-active $service 2>/dev/null");
                $message .= "\n$service: " . trim($status ?: 'unknown');
            }
        } elseif ($_POST['action'] === 'check_services') {
            $services = ['zoplog-logger', 'zoplog-blockreader'];
            $results = [];
            
            foreach ($services as $service) {
                $status = shell_exec("sudo systemctl is-active $service 2>/dev/null");
                $enabled = shell_exec("sudo systemctl is-enabled $service 2>/dev/null");
                $results[] = "$service:";
                $results[] = "  Status: " . trim($status ?: 'unknown');
                $results[] = "  Enabled: " . trim($enabled ?: 'unknown');
                
                // Get last few log lines
                $logs = shell_exec("sudo journalctl -u $service --no-pager -n 3 --since '5 minutes ago' 2>/dev/null");
                if ($logs) {
                    $results[] = "  Recent logs: " . trim($logs);
                }
                $results[] = "";
            }
            
            $message = "Service Status:\n" . implode("\n", $results);
            $messageType = 'info';
        } elseif ($_POST['action'] === 'fix_permissions') {
            // Try to fix permissions and create settings file
            $commands = [
                'sudo mkdir -p /home/yiannis/projects/zoplog',
                'sudo touch /home/yiannis/projects/zoplog/settings.json',
                'sudo chown yiannis:www-data /home/yiannis/projects/zoplog/settings.json',
                'sudo chmod 664 /home/yiannis/projects/zoplog/settings.json'
            ];
            
            $outputs = [];
            foreach ($commands as $cmd) {
                $output = shell_exec($cmd . ' 2>&1');
                $outputs[] = "$cmd: " . ($output ?: 'success');
            }
            
            // Create initial settings content
            $initialSettings = ['monitor_interface' => 'br-zoplog', 'last_updated' => date('Y-m-d H:i:s')];
            $result = shell_exec('echo \'' . json_encode($initialSettings, JSON_PRETTY_PRINT) . '\' | sudo tee /home/yiannis/projects/zoplog/settings.json > /dev/null 2>&1');
            
            $message = "Permission fix attempted:\n" . implode("\n", $outputs);
            $messageType = 'info';
        }
    }
}

$currentSettings = loadSystemSettings();
$availableInterfaces = getAvailableInterfaces();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - ZopLog</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-900">
    <?php include "menu.php"; ?>
    
    <div class="container mx-auto py-6 px-4">
        <div class="flex items-center space-x-3 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <h1 class="text-2xl font-bold">System Settings</h1>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 
                                                    ($messageType === 'error' ? 'bg-red-100 text-red-800 border border-red-200' : 
                                                    ($messageType === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 
                                                    'bg-blue-100 text-blue-800 border border-blue-200')) ?>">
                <pre class="whitespace-pre-wrap font-mono text-sm"><?php echo htmlspecialchars($message); ?></pre>
            </div>
        <?php endif; ?>

        <!-- Network Interface Configuration -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                </svg>
                Network Monitoring Interface
            </h2>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_settings">
                
                <div>
                    <label for="monitor_interface" class="block text-sm font-medium text-gray-700 mb-2">
                        Select Interface for Traffic Monitoring:
                    </label>
                    <select name="monitor_interface" id="monitor_interface" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($availableInterfaces as $iface): ?>
                            <option value="<?php echo htmlspecialchars($iface); ?>" 
                                    <?php echo $iface === $currentSettings['monitor_interface'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($iface); ?>
                                <?php if ($iface === 'br-zoplog'): ?>
                                    (Bridge Interface - Recommended)
                                <?php elseif (strpos($iface, 'eth') === 0): ?>
                                    (Ethernet Interface)
                                <?php elseif (strpos($iface, 'wlan') === 0): ?>
                                    (Wireless Interface)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-2 text-sm text-gray-600">
                        Current setting: <strong><?php echo htmlspecialchars($currentSettings['monitor_interface']); ?></strong>
                        <?php if ($currentSettings['last_updated']): ?>
                            (Last updated: <?php echo htmlspecialchars($currentSettings['last_updated']); ?>)
                        <?php endif; ?>
                    </p>
                </div>
                
                <button type="submit" 
                        class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    Save Settings
                </button>
            </form>
        </div>

        <!-- Interface Testing -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Test Traffic Monitoring
            </h2>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="test_logging">
                
                <div>
                    <label for="test_interface" class="block text-sm font-medium text-gray-700 mb-2">
                        Select Interface to Test:
                    </label>
                    <select name="test_interface" id="test_interface" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500">
                        <?php foreach ($availableInterfaces as $iface): ?>
                            <option value="<?php echo htmlspecialchars($iface); ?>"
                                    <?php echo $iface === $currentSettings['monitor_interface'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($iface); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-2 text-sm text-gray-600">
                        This will test if the selected interface can capture network traffic.
                        The test captures a few packets and reports success or failure.
                    </p>
                </div>
                
                <button type="submit" 
                        class="bg-yellow-600 text-white px-6 py-3 rounded-lg hover:bg-yellow-700 transition duration-200 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Test Interface
                </button>
            </form>
        </div>

        <!-- Service Management -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Service Management
            </h2>
            
            <div class="space-y-4">
                <p class="text-gray-600">
                    After changing interface settings, you may need to restart the logging services 
                    for the changes to take effect.
                </p>
                
                <div class="flex flex-wrap gap-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="restart_services">
                        <button type="submit" 
                                class="bg-purple-600 text-white px-6 py-3 rounded-lg hover:bg-purple-700 transition duration-200 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Restart Services
                        </button>
                    </form>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="check_services">
                        <button type="submit" 
                                class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Check Status
                        </button>
                    </form>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="fix_permissions">
                        <button type="submit" 
                                class="bg-orange-600 text-white px-6 py-3 rounded-lg hover:bg-orange-700 transition duration-200 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            Fix Permissions
                        </button>
                    </form>
                </div>
                
                <p class="text-sm text-gray-500">
                    If you get "Could not save settings file" errors, try the "Fix Permissions" button first.<br>
                    If service restart fails, you may need to configure sudoers permissions for the web server.
                </p>
            </div>
        </div>

        <!-- Interface Status Display -->
        <div class="mt-6 bg-gray-50 rounded-lg p-4">
            <h3 class="text-lg font-medium mb-3">Available Network Interfaces:</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php 
                $interfaceStatus = shell_exec('ip -o link show 2>/dev/null | grep -v "lo:"');
                $statusLines = $interfaceStatus ? explode("\n", trim($interfaceStatus)) : [];
                
                foreach ($availableInterfaces as $iface): 
                    $isActive = false;
                    $status = 'UNKNOWN';
                    foreach ($statusLines as $line) {
                        if (strpos($line, $iface . ':') !== false) {
                            $isActive = strpos($line, 'UP') !== false;
                            $status = $isActive ? 'UP' : 'DOWN';
                            break;
                        }
                    }
                ?>
                    <div class="flex items-center space-x-2 p-2 bg-white rounded border">
                        <div class="w-3 h-3 rounded-full <?php echo $isActive ? 'bg-green-500' : 'bg-red-500'; ?>"></div>
                        <span class="font-medium"><?php echo htmlspecialchars($iface); ?></span>
                        <span class="text-sm text-gray-600">(<?php echo $status; ?>)</span>
                        <?php if ($iface === $currentSettings['monitor_interface']): ?>
                            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">MONITORING</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
