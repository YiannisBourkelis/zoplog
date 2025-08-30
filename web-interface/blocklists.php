<?php
// blocklists.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Block Lists</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-900">
<?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center space-x-3 mb-6">
      <!-- Blocklists icon -->
      <span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-1.414-1.414A9 9 0 105.636 18.364l1.414-1.414A7 7 0 1116.95 7.05z" />
        </svg>
      </span>
      <h1 class="text-2xl font-bold">Block Lists</h1>
    </div>

    <!-- Add Blocklist Form -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
      <h2 class="text-lg font-semibold mb-4">Add New Block List</h2>
      <form class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1">URL</label>
          <input type="url" class="w-full border rounded px-3 py-2" placeholder="https://example.com/blocklist.txt">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Description</label>
          <input type="text" class="w-full border rounded px-3 py-2" placeholder="Short description">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Type</label>
          <select class="w-full border rounded px-3 py-2">
            <option value="domains">Domains List</option>
            <option value="hosts">Hosts File Format</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Category</label>
          <select class="w-full border rounded px-3 py-2">
            <option value="adware">Adware</option>
            <option value="malware">Malware</option>
            <option value="fakenews">Fake News</option>
            <option value="gambling">Gambling</option>
            <option value="social">Social</option>
            <option value="porn">Porn</option>
          </select>
        </div>
        <div class="md:col-span-2 flex justify-end">
          <button type="submit" class="bg-red-500 text-white px-6 py-2 rounded hover:bg-red-600">Add Block List</button>
        </div>
      </form>
    </div>

    <!-- Search and Pagination Controls -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4 gap-4">
      <input type="text" class="border rounded px-3 py-2 w-full md:w-1/3" placeholder="Search block lists...">
      <div class="flex items-center gap-2">
        <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">&lt;</button>
        <span class="font-semibold">Page 1 of 3</span>
        <button class="px-3 py-1 rounded bg-gray-200 hover:bg-gray-300">&gt;</button>
      </div>
    </div>

    <!-- Blocklist Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <!-- Example Card 1 -->
      <a href="blocklist_view.php?list=easylist" class="bg-white rounded-lg shadow p-5 flex flex-col cursor-pointer hover:ring-2 hover:ring-red-400 transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">Adware</span>
          <span class="text-xs text-gray-500">Domains List</span>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all">https://easylist.to/easylist/easylist.txt</h3>
        <p class="text-sm text-gray-700 mb-2">EasyList ad blocking filter list.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto">
          <span>Date Added: 2024-08-01</span>
          <span>Last Updated: 2024-08-30</span>
        </div>
      </a>
      <!-- Example Card 2 -->
      <a href="blocklist_view.php?list=socialblock" class="bg-white rounded-lg shadow p-5 flex flex-col cursor-pointer hover:ring-2 hover:ring-blue-400 transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">Social</span>
          <span class="text-xs text-gray-500">Hosts File Format</span>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all">https://someone.com/social-block.txt</h3>
        <p class="text-sm text-gray-700 mb-2">Blocks social media domains.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto">
          <span>Date Added: 2024-07-15</span>
          <span>Last Updated: 2024-08-29</span>
        </div>
      </a>
      <!-- Example Card 3 -->
      <a href="blocklist_view.php?list=pornblock" class="bg-white rounded-lg shadow p-5 flex flex-col cursor-pointer hover:ring-2 hover:ring-purple-400 transition">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded">Porn</span>
          <span class="text-xs text-gray-500">Domains List</span>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all">https://blocklists.org/porn.txt</h3>
        <p class="text-sm text-gray-700 mb-2">Adult content blocklist.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto">
          <span>Date Added: 2024-06-10</span>
          <span>Last Updated: 2024-08-28</span>
        </div>
      </a>
      <!-- Add more example cards as needed -->
    </div>
  </div>
</body>
</html>