<?php
// blocklist_view.php
// Dummy blocklist domains
$domains = [];
for ($i = 1; $i <= 1500; $i++) {
    $domains[] = "example{$i}.com";
}

// Pagination logic
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 500;
$totalPages = ceil(count($domains) / $perPage);

// Search logic
$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
if ($search !== '') {
    $domains = array_filter($domains, fn($d) => strpos(strtolower($d), $search) !== false);
    $totalPages = ceil(count($domains) / $perPage);
    $page = 1;
}

// Sort logic
$sort = isset($_GET['sort']) && $_GET['sort'] === 'desc' ? 'desc' : 'asc';
if ($sort === 'desc') {
    rsort($domains);
} else {
    sort($domains);
}

// Slice for current page
$domainsPage = array_slice($domains, ($page - 1) * $perPage, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Blocklist Domains</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-900">
<?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center space-x-3 mb-6">
      <span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-1.414-1.414A9 9 0 105.636 18.364l1.414-1.414A7 7 0 1116.95 7.05z" />
        </svg>
      </span>
      <h1 class="text-2xl font-bold">Domains in Blocklist</h1>
    </div>

    <!-- Search and Sort Controls -->
    <form method="get" class="flex flex-wrap gap-4 items-center mb-4">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="border rounded px-3 py-2 w-full md:w-1/3" placeholder="Search domains...">
      <input type="hidden" name="page" value="1">
      <select name="sort" class="border rounded px-3 py-2">
        <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>Sort: Ascending</option>
        <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Sort: Descending</option>
      </select>
      <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Apply</button>
    </form>

    <!-- Domains Table -->
    <div class="overflow-x-auto bg-white shadow rounded-lg">
      <table class="min-w-full text-sm text-left">
        <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Domain Name</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($domainsPage as $domain): ?>
            <tr>
              <td class="px-4 py-2"><?= htmlspecialchars($domain) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination Controls -->
    <div class="flex items-center justify-center gap-2 mt-6">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page-1 ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">&lt; Prev</a>
      <?php endif; ?>
      <span class="font-semibold">Page <?= $page ?> of <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page+1 ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">Next &gt;</a>
      <?php endif; ?>
    </div>
  </div>