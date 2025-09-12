<?php
// blocklist_view.php - show and edit a single blocklist; search and paginate domains
require_once __DIR__ . '/zoplog_config.php';

// Allowed categories (keep in sync with add_blocklist.php)
$allowedCategories = [
  'adware','malware','phishing','cryptomining','tracking','scam','fakenews','gambling','social','porn','streaming','proxyvpn','shopping','hate','other'
];

// Params
$blocklistId = isset($_GET['list']) ? intval($_GET['list']) : 0;
if ($blocklistId <= 0) {
  http_response_code(400);
  echo 'Invalid blocklist id';
  exit;
}

// Handle updates (description/category)
$updateMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $newDesc = trim($_POST['description'] ?? '');
  $newCat = trim($_POST['category'] ?? '');
  if ($newCat === '' || !in_array($newCat, $allowedCategories, true)) {
    $updateMsg = 'Please select a valid category.';
  } else {
    $stmt = $mysqli->prepare('UPDATE blocklists SET description = ?, category = ?, updated_at = ? WHERE id = ?');
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('sssi', $newDesc, $newCat, $now, $blocklistId);
    if ($stmt->execute()) {
      $updateMsg = 'Saved.';
    } else {
      $updateMsg = 'Failed to save: ' . htmlspecialchars($stmt->error);
    }
    $stmt->close();
  }
}

// Fetch blocklist row
$stmt = $mysqli->prepare('SELECT id, url, description, category, active, created_at, updated_at, type FROM blocklists WHERE id = ?');
$stmt->bind_param('i', $blocklistId);
$stmt->execute();
$blRes = $stmt->get_result();
$blocklist = $blRes->fetch_assoc();
$stmt->close();
if (!$blocklist) {
  http_response_code(404);
  echo 'Blocklist not found';
  exit;
}

// Check if this blocklist allows domain deletion (system or manual types)
$canDeleteDomains = in_array($blocklist['type'] ?? 'url', ['system', 'manual']);

// Search + pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 200;

// Count total domains (with optional search)
if ($search !== '') {
  $like = '%' . $search . '%';
  $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM blocklist_domains WHERE blocklist_id = ? AND domain LIKE ?');
  $stmt->bind_param('is', $blocklistId, $like);
} else {
  $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM blocklist_domains WHERE blocklist_id = ?');
  $stmt->bind_param('i', $blocklistId);
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
  $stmt = $mysqli->prepare('SELECT domain FROM blocklist_domains WHERE blocklist_id = ? AND domain LIKE ? ORDER BY domain ASC LIMIT ? OFFSET ?');
  $stmt->bind_param('isii', $blocklistId, $like, $perPage, $offset);
} else {
  $stmt = $mysqli->prepare('SELECT domain FROM blocklist_domains WHERE blocklist_id = ? ORDER BY domain ASC LIMIT ? OFFSET ?');
  $stmt->bind_param('iii', $blocklistId, $perPage, $offset);
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
  <title>ZopLog - Blocklist Domains</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-900">
<?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center space-x-3">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-1.414-1.414A9 9 0 105.636 18.364l1.414-1.414A7 7 0 1116.95 7.05z" />
          </svg>
        </span>
        <div>
          <h1 class="text-2xl font-bold">Blocklist</h1>
          <div class="text-sm text-gray-600 break-all">URL: <?= htmlspecialchars($blocklist['url']) ?></div>
          <div class="text-xs text-gray-500">Created: <?= htmlspecialchars($blocklist['created_at']) ?> | Updated: <?= htmlspecialchars($blocklist['updated_at']) ?></div>
          <div class="text-xs mt-1">
            <span class="px-2 py-1 rounded text-xs 
              <?php 
                if ($blocklist['type'] === 'system') echo 'bg-purple-100 text-purple-700';
                elseif ($blocklist['type'] === 'manual') echo 'bg-blue-100 text-blue-700';
                else echo 'bg-gray-100 text-gray-700';
              ?>">
              <?= htmlspecialchars(ucfirst($blocklist['type'])) ?> Blocklist
            </span>
            <?php if ($canDeleteDomains): ?>
              <span class="text-green-600 text-xs ml-2">✓ Domain deletion enabled</span>
            <?php else: ?>
              <span class="text-orange-600 text-xs ml-2">⚠ Domain deletion disabled (URL-based)</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <a href="blocklists.php" class="text-blue-600 hover:underline">← Back to Blocklists</a>
    </div>

    <!-- Edit description/category -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
      <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1" for="desc">Description</label>
          <input id="desc" name="description" type="text" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($blocklist['description'] ?? '') ?>" placeholder="Short description">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" for="cat">Category</label>
          <select id="cat" name="category" class="w-full border rounded px-3 py-2" required>
            <?php foreach ($allowedCategories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>" <?= ($blocklist['category'] === $cat) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($cat)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-3 flex items-center gap-3">
          <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Save</button>
          <?php if ($updateMsg): ?><span class="text-sm <?= strpos($updateMsg,'Failed')===0||strpos($updateMsg,'Please')===0 ? 'text-red-600' : 'text-green-600' ?>"><?= htmlspecialchars($updateMsg) ?></span><?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Search Controls -->
    <form method="get" class="flex flex-wrap gap-4 items-center mb-4">
      <input type="hidden" name="list" value="<?= (int)$blocklistId ?>">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="border rounded px-3 py-2 w-full md:w-1/3" placeholder="Search domains...">
      <input type="hidden" name="page" value="1">
      <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Search</button>
      <div class="text-sm text-gray-600">Total: <?= number_format($totalRows) ?> domains<?= $search ? " (filtered)" : '' ?></div>
    </form>

    <!-- Domains Table -->
    <div class="overflow-x-auto bg-white shadow rounded-lg">
      <table class="min-w-full text-sm text-left">
        <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Domain Name</th>
            <?php if ($canDeleteDomains): ?>
              <th class="px-4 py-2">Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($domainsPage as $domain): ?>
            <tr>
              <td class="px-4 py-2"><?= htmlspecialchars($domain) ?></td>
              <?php if ($canDeleteDomains): ?>
                <td class="px-4 py-2">
                  <button type="button" class="delete-domain-btn bg-red-500 text-white px-2 py-1 rounded text-xs hover:bg-red-600" 
                          data-domain="<?= htmlspecialchars($domain) ?>" 
                          data-blocklist-id="<?= (int)$blocklistId ?>">
                    Delete
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($domainsPage)): ?>
            <tr>
              <td class="px-4 py-6 text-gray-500" colspan="<?= $canDeleteDomains ? '2' : '1' ?>">No domains found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination Controls -->
    <div class="flex items-center justify-center gap-2 mt-6">
      <?php if ($page > 1): ?>
        <a href="?list=<?= (int)$blocklistId ?>&page=<?= $page-1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">&lt; Prev</a>
      <?php endif; ?>
      <span class="font-semibold">Page <?= $page ?> of <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?list=<?= (int)$blocklistId ?>&page=<?= $page+1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">Next &gt;</a>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Delete domain functionality
    document.addEventListener('click', async (e) => {
      if (e.target.classList.contains('delete-domain-btn')) {
        e.preventDefault();
        const domain = e.target.dataset.domain;
        const blocklistId = e.target.dataset.blocklistId;
        
        if (!domain || !blocklistId) return;
        
        if (!confirm(`Delete domain "${domain}" from this blocklist?`)) {
          return;
        }
        
        try {
          const formData = new FormData();
          formData.append('domain', domain);
          formData.append('blocklist_id', blocklistId);
          
          const res = await fetch('delete_domain.php', {
            method: 'POST',
            body: formData
          });
          
          const data = await res.json();
          
          if (data.status === 'ok') {
            // Remove the row from the table
            e.target.closest('tr').remove();
            alert(data.message);
            
            // Reload the page to update counts and pagination
            window.location.reload();
          } else {
            alert(`Failed to delete domain: ${data.message}`);
          }
        } catch (error) {
          alert('Network error. Please try again.');
        }
      }
    });
  </script>
</body>
</html>
  </div>