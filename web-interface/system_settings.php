<?php
// system_settings.php - System configuration page
require_once __DIR__ . '/zoplog_config.php';

$message = '';
$messageType = '';

// Load current settings from centralized config
function loadSystemSettings() {
    $settingsFile = '/etc/zoplog/zoplog.conf';
    
    $defaults = [
        'monitor_interface' => 'eth0',  // Default to WAN interface for better internet traffic monitoring
        'firewall_interface' => 'eth0',  // Apply firewall rules to internet-facing interface
        'capture_mode' => 'promiscuous',
        'log_level' => 'INFO',
        'block_mode' => 'immediate',
        'log_blocked' => true,
        'update_interval' => 30,
        'max_log_entries' => 10000,
        'last_updated' => null
    ];
    
    // Try centralized config first (INI format)
    if (file_exists($settingsFile)) {
        $settings = parse_ini_file($settingsFile, true);
        if ($settings !== false) {
            $config = $defaults;
            if (isset($settings['monitoring'])) {
                $config['monitor_interface'] = $settings['monitoring']['interface'] ?? $config['monitor_interface'];
                $config['capture_mode'] = $settings['monitoring']['capture_mode'] ?? $config['capture_mode'];
                $config['log_level'] = $settings['monitoring']['log_level'] ?? $config['log_level'];
            }
            if (isset($settings['firewall'])) {
                $config['firewall_interface'] = $settings['firewall']['apply_to_interface'] ?? $config['firewall_interface'];
                $config['block_mode'] = $settings['firewall']['block_mode'] ?? $config['block_mode'];
                $config['log_blocked'] = filter_var($settings['firewall']['log_blocked'] ?? $config['log_blocked'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($settings['system'])) {
                $config['update_interval'] = (int)($settings['system']['update_interval'] ?? $config['update_interval']);
                $config['max_log_entries'] = (int)($settings['system']['max_log_entries'] ?? $config['max_log_entries']);
                $config['last_updated'] = $settings['system']['last_updated'] ?? $config['last_updated'];
            }
            return $config;
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

// Save settings to centralized config
function saveSystemSettings($settings) {
    $settingsFile = '/etc/zoplog/zoplog.conf';
    $settingsDir = dirname($settingsFile);
    
    // Ensure directory exists
    if (!is_dir($settingsDir)) {
        if (!mkdir($settingsDir, 0755, true)) {
            error_log("Could not create settings directory: $settingsDir");
            return false;
        }
    }
    
    $settings['last_updated'] = date('Y-m-d H:i:s');
    
    // Create INI format configuration
    $config = "# ZopLog System Configuration\n";
    $config .= "# This file contains system settings for monitoring and firewall\n\n";
    
    $config .= "[monitoring]\n";
    $config .= "interface = " . ($settings['monitor_interface'] ?? 'eth0') . "\n";
    $config .= "capture_mode = " . ($settings['capture_mode'] ?? 'promiscuous') . "\n";
    $config .= "log_level = " . ($settings['log_level'] ?? 'INFO') . "\n\n";
    
    $config .= "[firewall]\n";
    $config .= "apply_to_interface = " . ($settings['firewall_interface'] ?? $settings['monitor_interface'] ?? 'eth0') . "\n";
    $config .= "block_mode = " . ($settings['block_mode'] ?? 'immediate') . "\n";
    $config .= "log_blocked = " . ($settings['log_blocked'] ? 'true' : 'false') . "\n\n";
    
    $config .= "[system]\n";
    $config .= "update_interval = " . ($settings['update_interval'] ?? 30) . "\n";
    $config .= "max_log_entries = " . ($settings['max_log_entries'] ?? 10000) . "\n";
    $config .= "last_updated = " . $settings['last_updated'] . "\n";
    
    // Try to write the file
    $result = file_put_contents($settingsFile, $config);
    
    if ($result !== false) {
        // Set proper permissions (readable and writable by www-data group)
        chmod($settingsFile, 0660);
        
        // Try to set proper ownership
        if (function_exists('chown') && function_exists('chgrp')) {
            @chown($settingsFile, 'root');
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
    $output = shell_exec("cd /home/yiannis/projects/zoplog/python-logger && python3 $testScript 2>&1");
    unlink($testScript);
    
    return $output;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'save_settings') {
            $interface = $_POST['monitor_interface'] ?? '';
            $firewallInterface = $_POST['firewall_interface'] ?? '';
            $availableInterfaces = getAvailableInterfaces();
            
            if (in_array($interface, $availableInterfaces) && in_array($firewallInterface, $availableInterfaces)) {
                $settings = [
                    'monitor_interface' => $interface,
                    'firewall_interface' => $firewallInterface
                ];
                
                // Debug information - now using centralized config
                $settingsFile = '/etc/zoplog/zoplog.conf';
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
        } elseif ($_POST['action'] === 'fix_config_permissions') {
            // Fix configuration file permissions
            $commands = [
                'sudo chmod 660 /etc/zoplog/zoplog.conf',
                'sudo chown root:www-data /etc/zoplog/zoplog.conf'
            ];
            
            $outputs = [];
            $allSuccess = true;
            foreach ($commands as $cmd) {
                $output = shell_exec($cmd . ' 2>&1');
                if ($output === null || trim($output) === '') {
                    $outputs[] = "$cmd: Success";
                } else {
                    $outputs[] = "$cmd: " . trim($output);
                    $allSuccess = false;
                }
            }
            
            // Test if permissions are now correct
            $settingsFile = '/etc/zoplog/zoplog.conf';
            $isWritable = is_writable($settingsFile);
            $outputs[] = "";
            $outputs[] = "Configuration file permissions check:";
            $outputs[] = "File writable by web server: " . ($isWritable ? 'YES' : 'NO');
            
            if ($isWritable) {
                $message = "Configuration permissions fixed successfully!\n\n" . implode("\n", $outputs);
                $messageType = 'success';
            } else {
                $message = "Permission fix completed, but file may still not be writable:\n\n" . implode("\n", $outputs);
                $messageType = 'warning';
            }
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
                Network Monitoring & Firewall Configuration
            </h2>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-lg font-semibold text-blue-900 mb-2">💡 Recommended Configuration for Internet Monitoring</h3>
                <div class="text-sm text-blue-800 space-y-2">
                    <p><strong>For better threat detection, monitor your WAN interface (eth0) instead of the bridge:</strong></p>
                    <ul class="list-disc list-inside ml-4 space-y-1">
                        <li><strong>eth0</strong> - Captures all internet traffic (recommended for security monitoring)</li>
                        <li><strong>br-zoplog</strong> - Captures internal bridge traffic (less effective for threat detection)</li>
                        <li><strong>Other interfaces</strong> - For specific network segments</li>
                    </ul>
                    <p class="font-medium">💡 Most malicious traffic comes from the internet, so monitoring eth0 provides better protection!</p>
                </div>
            </div>
            
            <form method="POST" class="space-y-6">
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
                                <?php if ($iface === 'eth0'): ?>
                                    (WAN Interface - Recommended for Internet Monitoring)
                                <?php elseif ($iface === 'br-zoplog'): ?>
                                    (Bridge Interface - Internal Traffic)
                                <?php elseif (strpos($iface, 'eth') === 0): ?>
                                    (Ethernet Interface)
                                <?php elseif (strpos($iface, 'wlan') === 0): ?>
                                    (Wireless Interface)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-2 text-sm text-gray-600">
                        Current monitoring: <strong><?php echo htmlspecialchars($currentSettings['monitor_interface']); ?></strong>
                    </p>
                </div>

                <div>
                    <label for="firewall_interface" class="block text-sm font-medium text-gray-700 mb-2">
                        Select Interface for Firewall Rules:
                    </label>
                    <select name="firewall_interface" id="firewall_interface" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($availableInterfaces as $iface): ?>
                            <option value="<?php echo htmlspecialchars($iface); ?>" 
                                    <?php echo $iface === ($currentSettings['firewall_interface'] ?? $currentSettings['monitor_interface']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($iface); ?>
                                <?php if ($iface === 'eth0'): ?>
                                    (WAN Interface - Recommended for Internet Blocking)
                                <?php elseif ($iface === 'br-zoplog'): ?>
                                    (Bridge Interface - All Traffic)
                                <?php elseif (strpos($iface, 'eth') === 0): ?>
                                    (Ethernet Interface)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-2 text-sm text-gray-600">
                        Current firewall: <strong><?php echo htmlspecialchars($currentSettings['firewall_interface'] ?? $currentSettings['monitor_interface']); ?></strong>
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
                    Save Configuration
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
                        <input type="hidden" name="action" value="fix_config_permissions">
                        <button type="submit" 
                                class="bg-orange-600 text-white px-6 py-3 rounded-lg hover:bg-orange-700 transition duration-200 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            Fix Config Permissions
                        </button>
                    </form>
                </div>
                
                <p class="text-sm text-gray-500">
                    If you get "Could not save settings file" errors, try the "Fix Config Permissions" button first.<br>
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
