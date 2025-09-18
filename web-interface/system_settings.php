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
        'firewall_rule_timeout' => 10800,  // 3 hours default
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
                $config['firewall_rule_timeout'] = max(1, (int)($settings['firewall']['firewall_rule_timeout'] ?? $config['firewall_rule_timeout']));
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

// Format timeout value for display
function formatTimeout($seconds) {
    if ($seconds == 0) {
        return "No expiration (persistent)";
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;
    
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . " hour" . ($hours != 1 ? "s" : "");
    }
    if ($minutes > 0) {
        $parts[] = $minutes . " minute" . ($minutes != 1 ? "s" : "");
    }
    if ($remainingSeconds > 0 && empty($parts)) {
        $parts[] = $remainingSeconds . " second" . ($remainingSeconds != 1 ? "s" : "");
    }
    
    return implode(", ", $parts);
}

// Save settings to centralized config
function saveSystemSettings($settings) {
    $settingsFile = '/etc/zoplog/zoplog.conf';
    $settingsDir = dirname($settingsFile);
    
    // Ensure directory and file exist with correct permissions; zoplog user has permissions
    if (!is_dir($settingsDir) || !is_writable($settingsDir)) {
        // Attempt to create/fix directory via direct execution
        @shell_exec('/bin/mkdir -p ' . escapeshellarg($settingsDir) . ' 2>/dev/null');
        @shell_exec('/bin/chown root:zoplog ' . escapeshellarg($settingsDir) . ' 2>/dev/null');
        @shell_exec('/bin/chmod 775 ' . escapeshellarg($settingsDir) . ' 2>/dev/null');
    }
    if (!file_exists($settingsFile) || !is_writable($settingsFile)) {
        // Ensure the file exists and is writable by group
        @shell_exec('/usr/bin/touch ' . escapeshellarg($settingsFile) . ' 2>/dev/null');
        @shell_exec('/bin/chown root:zoplog ' . escapeshellarg($settingsFile) . ' 2>/dev/null');
        @shell_exec('/bin/chmod 660 ' . escapeshellarg($settingsFile) . ' 2>/dev/null');
    }
    
    // If still not writable, bail out
    if (!is_writable($settingsFile)) {
        error_log("Settings file not writable: $settingsFile");
        return false;
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
    $config .= "log_blocked = " . ($settings['log_blocked'] ? 'true' : 'false') . "\n";
    $config .= "firewall_rule_timeout = " . ($settings['firewall_rule_timeout'] ?? 10800) . "\n\n";
    
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
            $firewallInterface = $_POST['firewall_interface'] ?? $interface; // Default to monitor interface
            $availableInterfaces = getAvailableInterfaces();
            
            if (in_array($interface, $availableInterfaces) && in_array($firewallInterface, $availableInterfaces)) {
                $firewallTimeout = (int)($_POST['firewall_rule_timeout'] ?? 10800);
                
                // Validate firewall timeout (must be at least 1 second)
                if ($firewallTimeout < 1) {
                    $message = "Firewall rule timeout must be at least 1 second.";
                    $messageType = 'error';
                } else {
                    $settings = [
                        'monitor_interface' => $interface,
                        'firewall_interface' => $firewallInterface,
                        'firewall_rule_timeout' => $firewallTimeout
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
        // Use explicit paths to match sudoers rules for zoplog user
        $output = shell_exec('/bin/systemctl restart ' . escapeshellarg($service) . ' 2>&1');
        $status = shell_exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>&1');
                
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
                $status = shell_exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null');
                $message .= "\n$service: " . trim($status ?: 'unknown');
            }
        } elseif ($_POST['action'] === 'check_services') {
            $services = ['zoplog-logger', 'zoplog-blockreader'];
            $results = [];
            
            foreach ($services as $service) {
                $status = shell_exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null');
                $enabled = shell_exec('/bin/systemctl is-enabled ' . escapeshellarg($service) . ' 2>/dev/null');
                $results[] = "$service:";
                $results[] = "  Status: " . trim($status ?: 'unknown');
                $results[] = "  Enabled: " . trim($enabled ?: 'unknown');
                
                // Get brief service status instead of journalctl to match sudoers for zoplog user
                $svcStatus = shell_exec('/bin/systemctl status ' . escapeshellarg($service) . ' 2>/dev/null');
                if ($svcStatus) {
                    $results[] = "  Status output: " . trim($svcStatus);
                }
                $results[] = "";
            }
            
            $message = "Service Status:\n" . implode("\n", $results);
            $messageType = 'info';
    } elseif ($_POST['action'] === 'fix_config_permissions') {
            // Fix configuration file permissions
            $commands = [
        '/usr/bin/touch /etc/zoplog/zoplog.conf',
        '/bin/chown root:zoplog /etc/zoplog/zoplog.conf',
        '/bin/chmod 660 /etc/zoplog/zoplog.conf'
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
        } elseif ($_POST['action'] === 'clean_firewall_rules') {
            // Clean all ZopLog firewall rules and recreate for all blocklists
            require_once 'zoplog_config.php';
            $scripts_path = get_zoplog_scripts_path();
            
            try {
                // First, clean all existing rules
                $cleanOutput = shell_exec('/usr/bin/sudo -n ' . escapeshellarg($scripts_path . '/zoplog-nft-clean') . ' 2>&1');
                
                $messages = [];
                $messageType = 'success';
                
                if ($cleanOutput === null) {
                    $messages[] = "Firewall cleanup completed.";
                } elseif (strpos($cleanOutput, 'sudo:') !== false) {
                    $message = "Permission denied: sudo configuration may need updating for firewall commands.\n\nOutput: " . trim($cleanOutput);
                    $messageType = 'error';
                } else {
                    $messages[] = "Cleanup: " . trim($cleanOutput);
                }
                
                // If cleanup succeeded, recreate rules for all blocklists
                if ($messageType !== 'error') {
                    // Use the same database connection method as other parts of the web interface
                    $db_config = load_zoplog_database_config();
                    $mysqli = new mysqli($db_config['host'], $db_config['user'], $db_config['password'], $db_config['database']);
                    
                    if ($mysqli->connect_error) {
                        $messages[] = "Database error: " . $mysqli->connect_error;
                        $messageType = 'warning';
                    } else {
                        // Get all blocklists (active and inactive)
                        $result = $mysqli->query("SELECT id, url, description, active FROM blocklists ORDER BY id");
                        $recreated = [];
                        $errors = [];
                        
                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $blocklistId = $row['id'];
                                $url = $row['url'];
                                $description = $row['description'];
                                $active = $row['active'];
                                
                                // Use description if available, otherwise use a truncated URL for display
                                $displayName = !empty($description) ? $description : (strlen($url) > 50 ? substr($url, 0, 47) . '...' : $url);
                                
                                // Apply firewall rules for this blocklist
                                $applyCmd = '/usr/bin/sudo -n ' . escapeshellarg($scripts_path . '/zoplog-firewall-apply') . ' ' . escapeshellarg($blocklistId) . ' 2>&1';
                                $applyOutput = shell_exec($applyCmd);
                                
                                // Check if the command actually failed or just produced informational output
                                $isError = false;
                                if ($applyOutput !== null && trim($applyOutput) !== '') {
                                    $output = trim($applyOutput);
                                    // These are normal success messages, not errors
                                    $successPatterns = [
                                        'Applying firewall rules to internet-facing interface:',
                                        'Firewall rules applied successfully',
                                        'Rules created for blocklist'
                                    ];
                                    
                                    $isError = true;
                                    foreach ($successPatterns as $pattern) {
                                        if (strpos($output, $pattern) !== false) {
                                            $isError = false;
                                            break;
                                        }
                                    }
                                    
                                    // Also check for specific error indicators
                                    $errorPatterns = [
                                        'Error:',
                                        'Permission denied',
                                        'Operation not permitted',
                                        'command not found',
                                        'No such file'
                                    ];
                                    
                                    foreach ($errorPatterns as $pattern) {
                                        if (stripos($output, $pattern) !== false) {
                                            $isError = true;
                                            break;
                                        }
                                    }
                                }
                                
                                if (!$isError) {
                                    $recreated[] = "ID $blocklistId ($displayName) - " . ($active === 'active' ? 'Active' : 'Inactive');
                                } else {
                                    $errors[] = "ID $blocklistId ($displayName): " . trim($applyOutput);
                                }
                            }
                            
                            if (!empty($recreated)) {
                                $messages[] = "Recreated rules for " . count($recreated) . " blocklist(s):\n" . implode("\n", $recreated);
                            }
                            if (!empty($errors)) {
                                $messages[] = "Errors recreating some rules:\n" . implode("\n", $errors);
                                $messageType = 'warning';
                            }
                        } else {
                            $messages[] = "No blocklists found in database to recreate rules for.";
                        }
                        
                        $mysqli->close();
                    }
                }
                
                $message = implode("\n\n", $messages);
                
            } catch (Exception $e) {
                $message = "Error during firewall cleanup and recreation: " . $e->getMessage();
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'reboot_device') {
            // Reboot using explicit whitelisted paths from sudoers
            if (file_exists('/bin/systemctl')) {
                shell_exec('/bin/systemctl reboot >/dev/null 2>&1 &');
            } elseif (file_exists('/sbin/shutdown')) {
                shell_exec('/sbin/shutdown -r now >/dev/null 2>&1 &');
            } elseif (file_exists('/sbin/reboot')) {
                shell_exec('/sbin/reboot >/dev/null 2>&1 &');
            }
            $message = "Device reboot initiated. The web interface will go offline shortly.";
            $messageType = 'warning';
        } elseif ($_POST['action'] === 'poweroff_device') {
            // Power off using explicit whitelisted paths from sudoers
            if (file_exists('/bin/systemctl')) {
                shell_exec('/bin/systemctl poweroff >/dev/null 2>&1 &');
            } elseif (file_exists('/sbin/shutdown')) {
                shell_exec('/sbin/shutdown -h now >/dev/null 2>&1 &');
            } elseif (file_exists('/sbin/poweroff')) {
                shell_exec('/sbin/poweroff >/dev/null 2>&1 &');
            } elseif (file_exists('/sbin/halt')) {
                shell_exec('/sbin/halt -p >/dev/null 2>&1 &');
            }
            $message = "Device power-off initiated. You will need to power it on manually.";
            $messageType = 'warning';
        } elseif ($_POST['action'] === 'get_firewall_status') {
            // Handle AJAX request for firewall status
            try {
                require_once 'zoplog_config.php';
                $scripts_path = get_zoplog_scripts_path();
                
                $output = shell_exec('/usr/bin/sudo -n ' . escapeshellarg($scripts_path . '/zoplog-nft-show') . ' 2>&1');
                
                if ($output === null || trim($output) === '') {
                    $output = 'No output from firewall status command.';
                } elseif (strpos($output, 'sudo:') !== false) {
                    $output = 'Permission denied: sudo configuration may need updating for firewall commands.';
                }
                
                echo "FIREWALL_OUTPUT_START\n" . trim($output) . "\nFIREWALL_OUTPUT_END";
                exit;
            } catch (Exception $e) {
                echo "FIREWALL_OUTPUT_START\nError retrieving firewall status: " . $e->getMessage() . "\nFIREWALL_OUTPUT_END";
                exit;
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
    <title>ZopLog - System Settings</title>
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
                    <label for="firewall_rule_timeout" class="block text-sm font-medium text-gray-700 mb-2">
                        Firewall Rule Expiration Timeout (seconds):
                    </label>
                    <input type="number" name="firewall_rule_timeout" id="firewall_rule_timeout" 
                           value="<?php echo htmlspecialchars($currentSettings['firewall_rule_timeout'] ?? 10800); ?>" 
                           min="1" max="604800" step="1"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="mt-2 text-sm text-gray-600">
                        Current timeout: <strong><?php echo htmlspecialchars($currentSettings['firewall_rule_timeout'] ?? 10800); ?> seconds</strong>
                        (<?php echo htmlspecialchars(formatTimeout($currentSettings['firewall_rule_timeout'] ?? 10800)); ?>)
                        <br>Firewall rules will automatically expire after this time period.
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
                    If service restart fails, you may need to configure sudoers permissions for the zoplog user.
                </p>
            </div>
        </div>

        <!-- Firewall Rules Management -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Firewall Rules Management
            </h2>
            
            <div class="mb-4">
                <p class="text-gray-600 mb-4">
                    Clean up all ZopLog-created firewall rules and recreate them for all blocklists in the database. This will rebuild the nftables configuration from scratch.
                </p>
                
                <div class="flex flex-wrap gap-4">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to clean and recreate all ZopLog firewall rules? This will rebuild all nftables rules from the database.');">
                        <input type="hidden" name="action" value="clean_firewall_rules">
                        <button type="submit" 
                                class="bg-orange-600 text-white px-6 py-3 rounded-lg hover:bg-orange-700 transition duration-200 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Clean & Recreate All Rules
                        </button>
                    </form>

                    <button type="button" onclick="showFirewallStatus()" 
                            class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Show Current Rules
                    </button>
                </div>
                
                <div id="firewall-status" class="mt-4 hidden bg-gray-100 rounded-lg p-4">
                    <h4 class="font-medium mb-2">Current ZopLog Firewall Rules:</h4>
                    <pre id="firewall-output" class="text-sm text-gray-700 whitespace-pre-wrap max-h-64 overflow-y-auto"></pre>
                </div>
            </div>
            
            <p class="text-sm text-gray-500">
                <strong>Note:</strong> This operation will clean all existing ZopLog firewall rules and recreate them for all blocklists in the database (both active and inactive). 
                Blocklist data remains intact. This is useful for rebuilding the firewall configuration or fixing rule inconsistencies.
            </p>
        </div>

        <!-- Power Controls (after Service Management) -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 3a9 9 0 11-7.446 3.985M13 3v4m0-4H9"/>
                </svg>
                Power Controls
            </h2>
            <div class="flex flex-wrap gap-4">
                <form method="POST" onsubmit="return confirm('Are you sure you want to reboot the device? This will interrupt all services.');">
                    <input type="hidden" name="action" value="reboot_device">
                    <button type="submit" 
                            class="bg-red-600 text-white px-6 py-3 rounded-lg hover:bg-red-700 transition duration-200 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 3a9 9 0 11-7.446 3.985M13 3v4m0-4H9"/>
                        </svg>
                        Reboot Device
                    </button>
                </form>

                <form method="POST" onsubmit="return confirm('Are you sure you want to power off the device? You will need to power it on manually.');">
                    <input type="hidden" name="action" value="poweroff_device">
                    <button type="submit" 
                            class="bg-gray-700 text-white px-6 py-3 rounded-lg hover:bg-gray-800 transition duration-200 flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Power Off
                    </button>
                </form>
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

    <script>
    function showFirewallStatus() {
        const statusDiv = document.getElementById('firewall-status');
        const outputPre = document.getElementById('firewall-output');
        
        // Show loading
        statusDiv.classList.remove('hidden');
        outputPre.textContent = 'Loading firewall rules...';
        
        // Fetch current firewall rules
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_firewall_status'
        })
        .then(response => response.text())
        .then(data => {
            // Extract just the firewall output from the response
            const match = data.match(/FIREWALL_OUTPUT_START\n(.*?)\nFIREWALL_OUTPUT_END/s);
            if (match) {
                outputPre.textContent = match[1] || 'No ZopLog firewall rules found.';
            } else {
                outputPre.textContent = 'Could not retrieve firewall status.';
            }
        })
        .catch(error => {
            outputPre.textContent = 'Error fetching firewall status: ' + error.message;
        });
    }
    </script>
</body>
</html>
