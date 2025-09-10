<?php
// whitelist_view.php - show and edit a single whitelist; search and paginate domains
require_once __DIR__ . '/zoplog_config.php';

// Allowed categories
$allowedCategories = [
  'adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other'
];

// Function to remove blocked IPs for a whitelisted domain
function remove_blocked_ips_for_domain($domain) {
    global $mysqli;
    
    // Find all blocklist domains that match this domain
    $stmt = $mysqli->prepare('
        SELECT bd.id, bd.blocklist_id, ip.ip_address
        FROM blocklist_domains bd
        JOIN blocked_ips bi ON bi.blocklist_domain_id = bd.id
        JOIN ip_addresses ip ON ip.id = bi.ip_id
        WHERE bd.domain = ?
    ');
    $stmt->bind_param('s', $domain);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ips_to_remove = [];
    while ($row = $result->fetch_assoc()) {
        $ips_to_remove[] = [
            'ip' => $row['ip_address'],
            'blocklist_id' => $row['blocklist_id']
        ];
    }
    $stmt->close();
    
  // Remove each IP from the firewall
    foreach ($ips_to_remove as $item) {
        $ip = $item['ip'];
        $blocklist_id = $item['blocklist_id'];
        
        // Determine if IPv4 or IPv6
        $is_ipv6 = strpos($ip, ':') !== false;
        $set_name = "zoplog-blocklist-{$blocklist_id}-" . ($is_ipv6 ? 'v6' : 'v4');

    // Use helper script via sudo to delete element from set
    $fam = $is_ipv6 ? 'v6' : 'v4';
    $script = '/opt/zoplog/zoplog/scripts/zoplog-nft-del-element';
    if (file_exists($script)) {
      $cmd = sprintf('sudo %s %s %s %s 2>/dev/null', escapeshellarg($script), escapeshellarg($fam), escapeshellarg($set_name), escapeshellarg($ip));
      exec($cmd);
    } else {
      $devScript = realpath(__DIR__ . '/../scripts/zoplog-nft-del-element');
      if ($devScript) {
        $cmd = sprintf('%s %s %s %s 2>/dev/null', escapeshellarg($devScript), escapeshellarg($fam), escapeshellarg($set_name), escapeshellarg($ip));
        exec($cmd);
      }
    }
    }
}

// Params
$whitelistId = isset($_GET['list']) ? intval($_GET['list']) : 0;
if ($whitelistId <= 0) {
  http_response_code(400);
  echo 'Invalid whitelist id';
  exit;
}

// Handle updates (name/category)
$updateMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_whitelist'])) {
  $newName = trim($_POST['name'] ?? '');
  $newCat = trim($_POST['category'] ?? '');
  if ($newName === '') {
    $updateMsg = 'Name is required.';
  } elseif ($newCat === '' || !in_array($newCat, $allowedCategories, true)) {
    $updateMsg = 'Please select a valid category.';
  } else {
    $stmt = $mysqli->prepare('UPDATE whitelists SET name = ?, category = ?, updated_at = ? WHERE id = ?');
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('sssi', $newName, $newCat, $now, $whitelistId);
    if ($stmt->execute()) {
      $updateMsg = 'Saved.';
    } else {
      $updateMsg = 'Failed to save: ' . htmlspecialchars($stmt->error);
    }
    $stmt->close();
  }
}

// Handle add domain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_domain'])) {
  $domain = trim($_POST['domain'] ?? '');
  if ($domain !== '') {
    $stmt = $mysqli->prepare('INSERT IGNORE INTO whitelist_domains (whitelist_id, domain) VALUES (?, ?)');
    $stmt->bind_param('is', $whitelistId, $domain);
    $stmt->execute();
    $stmt->close();
    
    // Remove blocked IPs for this whitelisted domain
    remove_blocked_ips_for_domain($domain);
  }
}

// Handle remove domain
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_domain'])) {
  $domain = trim($_POST['domain'] ?? '');
  if ($domain !== '') {
    $stmt = $mysqli->prepare('DELETE FROM whitelist_domains WHERE whitelist_id = ? AND domain = ?');
    $stmt->bind_param('is', $whitelistId, $domain);
    $stmt->execute();
    $stmt->close();
  }
}

// Fetch whitelist row
$stmt = $mysqli->prepare('SELECT id, name, category, active, created_at, updated_at FROM whitelists WHERE id = ?');
$stmt->bind_param('i', $whitelistId);
$stmt->execute();
$wlRes = $stmt->get_result();
$whitelist = $wlRes->fetch_assoc();
$stmt->close();
if (!$whitelist) {
  http_response_code(404);
  echo 'Whitelist not found';
  exit;
}

// Search + pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 200;

// Count total domains
if ($search !== '') {
  $like = '%' . $search . '%';
  $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM whitelist_domains WHERE whitelist_id = ? AND domain LIKE ?');
  $stmt->bind_param('is', $whitelistId, $like);
} else {
  $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM whitelist_domains WHERE whitelist_id = ?');
  $stmt->bind_param('i', $whitelistId);
}
$stmt->execute();
$countRes = $stmt->get_result();
$totalRows = intval($countRes->fetch_assoc()['cnt'] ?? 0);
$stmt->close();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Fetch page rows
if ($search !== '') {
  $like = '%' . $search . '%';
  $stmt = $mysqli->prepare('SELECT domain FROM whitelist_domains WHERE whitelist_id = ? AND domain LIKE ? ORDER BY domain ASC LIMIT ? OFFSET ?');
  $stmt->bind_param('isii', $whitelistId, $like, $perPage, $offset);
} else {
  $stmt = $mysqli->prepare('SELECT domain FROM whitelist_domains WHERE whitelist_id = ? ORDER BY domain ASC LIMIT ? OFFSET ?');
  $stmt->bind_param('iii', $whitelistId, $perPage, $offset);
}
$stmt->execute();
$domainsRes = $stmt->get_result();
$domainsPage = [];
while ($row = $domainsRes->fetch_assoc()) { $domainsPage[] = $row['domain']; }
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ZopLog - Whitelist Domains</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-900">
<?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center space-x-3">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </span>
        <h1 class="text-2xl font-bold">Whitelist: <?= htmlspecialchars($whitelist['name']) ?></h1>
      </div>
      <a href="whitelist.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">Back to Whitelists</a>
    </div>

    <!-- Update Whitelist Form -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
      <h2 class="text-lg font-semibold mb-4">Edit Whitelist</h2>
      <?php if ($updateMsg): ?>
        <p class="mb-4 text-sm <?= strpos($updateMsg, 'Failed') === false ? 'text-green-600' : 'text-red-600' ?>"><?= htmlspecialchars($updateMsg) ?></p>
      <?php endif; ?>
      <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1" for="name">Name</label>
          <input id="name" name="name" type="text" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($whitelist['name']) ?>" required>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" for="category">Category</label>
          <select id="category" name="category" class="w-full border rounded px-3 py-2" required>
            <option value="">Select a category...</option>
            <?php foreach ($allowedCategories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= $whitelist['category'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($cat)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2">
          <button type="submit" name="update_whitelist" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">Update Whitelist</button>
        </div>
      </form>
    </div>

    <!-- Add Domain Form -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
      <h2 class="text-lg font-semibold mb-4">Add Domain</h2>
      <form method="post" class="flex gap-4">
        <input name="domain" type="text" class="flex-1 border rounded px-3 py-2" placeholder="example.com" required>
        <button type="submit" name="add_domain" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">Add Domain</button>
      </form>
    </div>

    <!-- Search and Domains List -->
    <div class="bg-white rounded-lg shadow p-6">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Domains (<?= number_format($totalRows) ?> total)</h2>
        <form method="get" class="flex gap-2">
          <input type="hidden" name="list" value="<?= $whitelistId ?>">
          <input name="search" type="text" class="border rounded px-3 py-2" placeholder="Search domains..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">Search</button>
        </form>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
        <?php foreach ($domainsPage as $domain): ?>
          <div class="flex justify-between items-center border rounded p-3">
            <span class="break-all"><?= htmlspecialchars($domain) ?></span>
            <form method="post" class="ml-2" onsubmit="return confirm('Remove this domain?')">
              <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
              <button type="submit" name="remove_domain" class="text-red-500 hover:text-red-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="flex justify-center">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?list=<?= $whitelistId ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>" class="mx-1 px-3 py-2 border rounded <?= $i === $page ? 'bg-blue-500 text-white' : 'bg-white text-blue-500 hover:bg-blue-100' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
