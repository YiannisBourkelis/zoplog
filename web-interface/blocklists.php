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
          <label class="block text-sm font-medium mb-1">Category</label>
          <select class="w-full border rounded px-3 py-2">
            <option value="adware">Adware</option>
            <option value="malware">Malware</option>
            <option value="phishing">Phishing</option>
            <option value="cryptomining">Cryptomining</option>
            <option value="tracking">Tracking</option>
            <option value="scam">Scam</option>
            <option value="fakenews">Fake News</option>
            <option value="gambling">Gambling</option>
            <option value="social">Social</option>
            <option value="porn">Porn</option>
            <option value="streaming">Streaming</option>
            <option value="proxyvpn">Proxy/VPN</option>
            <option value="shopping">Shopping</option>
            <option value="hate">Hate/Violence</option>
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
      <div class="bg-white rounded-lg shadow p-5 flex flex-col relative group">
        <a href="blocklist_view.php?list=easylist" class="absolute inset-0 z-0"></a>
        <div class="flex items-center justify-between mb-2 relative z-10">
          <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">Adware</span>
          <!-- Toggle Active/Inactive Top Right -->
          <button type="button" class="toggle-btn flex items-center px-2 py-1 rounded-full transition-colors bg-green-100 text-green-700 active ml-auto" data-list="easylist">
            <span class="font-semibold text-xs mr-2">Active</span>
            <span class="w-6 h-6 flex items-center justify-center">
              <span class="inline-block w-4 h-4 bg-green-500 rounded-full"></span>
            </span>
          </button>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all relative z-10">https://easylist.to/easylist/easylist.txt</h3>
        <p class="text-sm text-gray-700 mb-2 relative z-10">EasyList ad blocking filter list.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto relative z-10">
          <span>Date Added: 2024-08-01</span>
          <span>Last Updated: 2024-08-30</span>
        </div>
        <!-- Card actions bottom row -->
        <div class="flex items-center justify-end mt-4 relative z-10">
          <button class="delete-btn text-red-500 hover:text-red-700 px-2 py-1 rounded transition hover:bg-red-100 flex items-center" title="Delete" data-list="easylist">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            <span class="ml-1">Delete</span>
          </button>
        </div>
      </div>
      <!-- Example Card 2 -->
      <div class="bg-white rounded-lg shadow p-5 flex flex-col relative group">
        <a href="blocklist_view.php?list=socialblock" class="absolute inset-0 z-0"></a>
        <div class="flex items-center justify-between mb-2 relative z-10">
          <span class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">Social</span>
          <button type="button" class="toggle-btn flex items-center px-2 py-1 rounded-full transition-colors bg-gray-100 text-gray-700 inactive ml-auto" data-list="socialblock">
            <span class="font-semibold text-xs mr-2">Inactive</span>
            <span class="w-6 h-6 flex items-center justify-center">
              <span class="inline-block w-4 h-4 bg-gray-400 rounded-full"></span>
            </span>
          </button>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all relative z-10">https://someone.com/social-block.txt</h3>
        <p class="text-sm text-gray-700 mb-2 relative z-10">Blocks social media domains.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto relative z-10">
          <span>Date Added: 2024-07-15</span>
          <span>Last Updated: 2024-08-29</span>
        </div>
        <div class="flex items-center justify-end mt-4 relative z-10">
          <button class="delete-btn text-red-500 hover:text-red-700 px-2 py-1 rounded transition hover:bg-red-100 flex items-center" title="Delete" data-list="socialblock">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            <span class="ml-1">Delete</span>
          </button>
        </div>
      </div>
      <!-- Example Card 3 -->
      <div class="bg-white rounded-lg shadow p-5 flex flex-col relative group">
        <a href="blocklist_view.php?list=pornblock" class="absolute inset-0 z-0"></a>
        <div class="flex items-center justify-between mb-2 relative z-10">
          <span class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded">Porn</span>
          <button type="button" class="toggle-btn flex items-center px-2 py-1 rounded-full transition-colors bg-green-100 text-green-700 active ml-auto" data-list="pornblock">
            <span class="font-semibold text-xs mr-2">Active</span>
            <span class="w-6 h-6 flex items-center justify-center">
              <span class="inline-block w-4 h-4 bg-green-500 rounded-full"></span>
            </span>
          </button>
        </div>
        <h3 class="font-bold text-lg mb-1 break-all relative z-10">https://blocklists.org/porn.txt</h3>
        <p class="text-sm text-gray-700 mb-2 relative z-10">Adult content blocklist.</p>
        <div class="flex justify-between text-xs text-gray-500 mt-auto relative z-10">
          <span>Date Added: 2024-06-10</span>
          <span>Last Updated: 2024-08-28</span>
        </div>
        <div class="flex items-center justify-end mt-4 relative z-10">
          <button class="delete-btn text-red-500 hover:text-red-700 px-2 py-1 rounded transition hover:bg-red-100 flex items-center" title="Delete" data-list="pornblock">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            <span class="ml-1">Delete</span>
          </button>
        </div>
      </div>
      <!-- Add more example cards as needed -->
    </div>
  </div>
  <script>
    // Toggle active/inactive state
    document.querySelectorAll('.toggle-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (btn.classList.contains('active')) {
          btn.classList.remove('active', 'bg-green-100', 'text-green-700');
          btn.classList.add('inactive', 'bg-gray-100', 'text-gray-700');
          btn.querySelector('span span').classList.remove('bg-green-500');
          btn.querySelector('span span').classList.add('bg-gray-400');
          btn.querySelector('.mr-2').textContent = 'Inactive';
        } else {
          btn.classList.remove('inactive', 'bg-gray-100', 'text-gray-700');
          btn.classList.add('active', 'bg-green-100', 'text-green-700');
          btn.querySelector('span span').classList.remove('bg-gray-400');
          btn.querySelector('span span').classList.add('bg-green-500');
          btn.querySelector('.mr-2').textContent = 'Active';
        }
      });
    });

    // Delete button (demo only)
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        btn.closest('.group').remove();
      });
    });
  </script>
</body>
</html>