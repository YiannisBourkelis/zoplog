<?php
// add_blocklist.php - Handle adding a new block list
// Expects POST: url (required), category (required), description (optional)

require_once __DIR__ . '/zoplog_config.php';

function respond_json($status, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

/**
 * Execute a shell command and capture exit code, stdout, and stderr.
 * Returns [code, stdout, stderr].
 */
function run_cmd($cmd) {
    $descriptors = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, [
        'bypass_shell' => false,
    ]);
    if (!is_resource($proc)) {
        return [127, '', 'Failed to spawn process'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $stdout, $stderr];
}

/**
 * Apply firewall rules for this blocklist via a root-owned helper.
 * Expects: sudoers allows www-data to run scripts in ZopLog scripts directory without password.
 */
function ensure_firewall_rules(int $blocklistId) {
    require_once 'zoplog_config.php';
    $scripts_path = get_zoplog_scripts_path();
    $cmd = 'sudo -n ' . escapeshellarg($scripts_path . '/zoplog-firewall-apply') . ' ' . escapeshellarg((string)$blocklistId);
    [$code, $out, $err] = run_cmd($cmd);
    if ($code !== 0) {
        $detail = trim($err ?: $out);
        throw new Exception("Firewall apply failed (exit $code): " . ($detail !== '' ? $detail : 'unknown error'));
    }
}

$allowedCategories = [
    'adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other'
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json('error', 'Invalid request method');
}

$url = trim($_POST['url'] ?? '');
$description = trim($_POST['description'] ?? '');
$category = trim($_POST['category'] ?? '');

$errors = [];
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    $errors['url'] = 'Please provide a valid URL.';
}
if ($category === '' || !in_array($category, $allowedCategories, true)) {
    $errors['category'] = 'Please select a valid category.';
}

if (!empty($errors)) {
    respond_json('validation_error', 'Please fix the form errors.', ['errors' => $errors]);
}

// Download the list
$ctx = stream_context_create([
    'http' => ['timeout' => 15, 'user_agent' => 'zoplog/1.0'],
    'https' => ['timeout' => 15, 'user_agent' => 'zoplog/1.0']
]);
$data = @file_get_contents($url, false, $ctx);
if ($data === false) {
    respond_json('error', 'Failed to download the block list from the provided URL.');
}

// Normalize line endings
$data = str_replace(["\r\n", "\r"], "\n", $data);
$lines = explode("\n", $data);

$domains = [];
$hostsPattern = '/^(?:#.*|\s*)$/'; // skip comments/empty
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;

    // Hosts file format: optional IP + whitespace + domain
    // e.g., "0.0.0.0 example.com" or "127.0.0.1 example.com"
    if (preg_match('/^(?:0\.0\.0\.0|127\.0\.0\.1|::1|::)\s+([^#\s]+)$/i', $line, $m)) {
        $dom = strtolower($m[1]);
    } else {
        // raw domain per line
        $dom = strtolower(preg_replace('/\s+.*/', '', $line));
    }

    // Strip leading/trailing dots and sanitize
    $dom = trim($dom, ". ");
    // Remove protocol or path if mistakenly present
    if (preg_match('#^https?://#i', $dom)) {
        $parts = parse_url($dom);
        $dom = $parts['host'] ?? '';
    }

    // Validate domain: allow subdomains; disallow IPs
    if ($dom !== '' && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $dom)) {
        $domains[] = $dom;
    }
}

$domains = array_values(array_unique($domains));
if (count($domains) === 0) {
    respond_json('error', 'The downloaded content does not appear to be a valid hosts or domain list.');
}

$mysqli->begin_transaction();
try {
    // Insert into blocklists table
    $stmt = $mysqli->prepare("INSERT INTO blocklists (url, description, category, active, created_at, updated_at) VALUES (?,?,?,?,?,?)");
    $now = date('Y-m-d H:i:s');
    $active = 'active';
    $stmt->bind_param('ssssss', $url, $description, $category, $active, $now, $now);
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert blocklist: ' . $stmt->error);
    }
    $blocklistId = $stmt->insert_id;
    $stmt->close();

    // Bulk insert domains into blocklist_domains
    $stmt = $mysqli->prepare("INSERT INTO blocklist_domains (blocklist_id, domain) VALUES (?, ?)");
    if (!$stmt) throw new Exception('Prepare failed for domains: ' . $mysqli->error);

    foreach ($domains as $dom) {
        $stmt->bind_param('is', $blocklistId, $dom);
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert domain ' . $dom . ': ' . $stmt->error);
        }
    }
    $stmt->close();

    // Before committing, ensure ipset and iptables rules are present
    try {
        ensure_firewall_rules($blocklistId);
    } catch (Throwable $fwErr) {
        throw new Exception('Firewall setup error: ' . $fwErr->getMessage());
    }

    $mysqli->commit();

    respond_json('ok', 'Block list added successfully.', [
        'blocklist_id' => $blocklistId,
        'domains_count' => count($domains)
    ]);
} catch (Throwable $e) {
    $mysqli->rollback();
    respond_json('error', $e->getMessage());
}
