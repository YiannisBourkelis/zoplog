<?php
// blocklists.php - list and manage blocklists
require_once __DIR__ . '/zoplog_config.php';

// Fetch blocklists with domain counts
$res = $mysqli->query("SELECT id, url, description, category, active, created_at, updated_at, type,
  (SELECT COUNT(*) FROM blocklist_domains bd WHERE bd.blocklist_id = blocklists.id) AS domains_count
  FROM blocklists
  ORDER BY CASE WHEN type = 'system' THEN 0 ELSE 1 END, created_at DESC");
$blocklists = [];
if ($res) {
    while ($row = $res->fetch_assoc()) { $blocklists[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ZopLog - Block Lists</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <style>
    .toggle-active.active { background-color: #d1fae5; color: #065f46; }
    .toggle-active.inactive { background-color: #e5e7eb; color: #374151; }
  </style>
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
      <h1 class="text-2xl font-bold">Block Lists</h1>
    </div>

    <!-- Add Blocklist Form -->
    <div class="bg-white rounded-lg shadow p-6 mb-8" id="add-form-card">
      <h2 class="text-lg font-semibold mb-4">Add New Block List</h2>
      <form id="add-blocklist-form" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1" for="bl-url">URL</label>
          <input id="bl-url" name="url" type="url" class="w-full border rounded px-3 py-2" placeholder="https://example.com/blocklist.txt" required>
          <p class="text-xs text-red-600 mt-1 hidden" id="err-url"></p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" for="bl-desc">Description (optional)</label>
          <input id="bl-desc" name="description" type="text" class="w-full border rounded px-3 py-2" placeholder="Short description">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" for="bl-cat">Category</label>
          <select id="bl-cat" name="category" class="w-full border rounded px-3 py-2" required>
            <option value="">Select a category...</option>
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
            <option value="other">Other</option>
          </select>
          <p class="text-xs text-red-600 mt-1 hidden" id="err-category"></p>
        </div>
        <div class="md:col-span-2 flex items-center justify-between gap-3">
          <div id="add-feedback" class="text-sm"></div>
          <button type="submit" class="bg-red-500 text-white px-6 py-2 rounded hover:bg-red-600" id="add-btn">Add Block List</button>
        </div>
      </form>
    </div>

    <!-- Blocklist Cards from DB -->
    <div id="blocklist-cards" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($blocklists as $b): ?>
        <div class="bg-white rounded-lg shadow p-5 flex flex-col relative group hover:ring-2 hover:ring-red-400 transition">
          <div class="flex items-center justify-between mb-2 relative z-20">
            <div class="flex items-center gap-2">
              <span class="text-xs px-2 py-1 rounded <?= htmlspecialchars($b['category']) === 'social' ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700' ?>"><?= htmlspecialchars(ucfirst($b['category'])) ?></span>
              <span class="text-xs px-2 py-1 rounded <?= $b['type'] === 'system' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>"><?= htmlspecialchars(ucfirst($b['type'])) ?></span>
              <span class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700"><?= (int)($b['domains_count'] ?? 0) ?> domains</span>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="toggle-active flex items-center px-2 py-1 rounded-full transition-colors <?= ($b['active']==='active') ? 'active bg-green-100 text-green-700' : 'inactive bg-gray-100 text-gray-700' ?>" data-id="<?= (int)$b['id'] ?>">
                <span class="font-semibold text-xs mr-2"><?= ($b['active']==='active') ? 'Active' : 'Inactive' ?></span>
                <span class="w-6 h-6 flex items-center justify-center">
                  <span class="inline-block w-4 h-4 rounded-full <?= ($b['active']==='active') ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                </span>
              </button>
              <div class="relative z-30">
                <button type="button" class="menu-btn p-1 rounded hover:bg-gray-200 transition-colors" data-id="<?= (int)$b['id'] ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5h.01M12 12h.01M12 19h.01" /></svg>
                </button>
                <div class="menu-popup fixed w-24 bg-white border rounded shadow-lg hidden z-50" data-id="<?= (int)$b['id'] ?>" style="top:0;left:0;">
                  <button class="edit-btn w-full text-left px-3 py-2 text-sm hover:bg-gray-100" data-id="<?= (int)$b['id'] ?>">Edit</button>
                  <?php if ($b['type'] !== 'system'): ?>
                    <button class="delete-btn w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50" data-id="<?= (int)$b['id'] ?>">Delete</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <h3 class="font-bold text-lg mb-1 break-all relative"><?= htmlspecialchars($b['url']) ?></h3>
          <?php if (!empty($b['description'])): ?>
            <p class="text-sm text-gray-700 mb-2 relative"><?= htmlspecialchars($b['description']) ?></p>
          <?php endif; ?>
          <div class="flex justify-between text-xs text-gray-500 mt-auto relative">
            <span>Date Added: <?= htmlspecialchars($b['created_at']) ?></span>
            <span>Last Updated: <?= htmlspecialchars($b['updated_at']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($blocklists)): ?>
        <div class="text-gray-500">No blocklists yet. Add one above.</div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Add Blocklist form submission
    (function(){
      const form = document.getElementById('add-blocklist-form');
      const errUrl = document.getElementById('err-url');
      const errCat = document.getElementById('err-category');
      const feedback = document.getElementById('add-feedback');
      const btn = document.getElementById('add-btn');
      if (!form) return;
      function clearErrors(){
        if (errUrl) { errUrl.textContent=''; errUrl.classList.add('hidden'); }
        if (errCat) { errCat.textContent=''; errCat.classList.add('hidden'); }
        if (feedback) { feedback.textContent=''; feedback.className='text-sm'; }
      }
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        btn.disabled = true; btn.classList.add('opacity-60');
        feedback.textContent = 'Downloading and parsing…';
        feedback.classList.add('text-gray-600');
        try {
          const fd = new FormData(form);
          const res = await fetch('add_blocklist.php', { method: 'POST', body: fd });
          const json = await res.json();
          if (json.status === 'validation_error') {
            if (json.errors?.url) { errUrl.textContent = json.errors.url; errUrl.classList.remove('hidden'); }
            if (json.errors?.category) { errCat.textContent = json.errors.category; errCat.classList.remove('hidden'); }
            feedback.textContent = 'Please fix the highlighted errors.';
            feedback.classList.remove('text-gray-600');
            feedback.classList.add('text-red-600');
          } else if (json.status === 'ok') {
            feedback.textContent = `Added block list successfully. Domains added: ${json.domains_count}.`;
            feedback.classList.remove('text-gray-600');
            feedback.classList.add('text-green-600');
            form.reset();
            window.location.reload();
          } else {
            feedback.textContent = json.message || 'Failed to add block list.';
            feedback.classList.remove('text-gray-600');
            feedback.classList.add('text-red-600');
          }
        } catch(err) {
          feedback.textContent = 'Network error. Please try again.';
          feedback.classList.remove('text-gray-600');
          feedback.classList.add('text-red-600');
        } finally {
          btn.disabled = false; btn.classList.remove('opacity-60');
        }
      });
    })();

    // Toggle active/inactive (server)
    document.querySelectorAll('.toggle-active').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = btn.dataset.id;
        const isActive = btn.classList.contains('active');
        const nextState = isActive ? 'inactive' : 'active';
        try {
          const res = await fetch('toggle_blocklist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ id, active: nextState }) });
          const json = await res.json();
          if (json.status === 'ok') {
            const isActiveNow = (nextState === 'active');
            btn.classList.toggle('active', isActiveNow);
            btn.classList.toggle('inactive', !isActiveNow);
            btn.classList.toggle('bg-green-100', isActiveNow);
            btn.classList.toggle('text-green-700', isActiveNow);
            btn.classList.toggle('bg-gray-100', !isActiveNow);
            btn.classList.toggle('text-gray-700', !isActiveNow);
            const dot = btn.querySelector('span span');
            if (dot) { dot.classList.toggle('bg-green-500', isActiveNow); dot.classList.toggle('bg-gray-400', !isActiveNow); }
            const label = btn.querySelector('.mr-2');
            if (label) label.textContent = isActiveNow ? 'Active' : 'Inactive';
          }
        } catch {}
      });
    });

    // Menu popup positioning
    document.querySelectorAll('.menu-btn').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const id = btn.dataset.id;
        const popup = document.querySelector(`.menu-popup[data-id="${id}"]`);
        document.querySelectorAll('.menu-popup').forEach(p => { if (p !== popup) p.classList.add('hidden'); });
        if (popup.parentElement !== document.body) document.body.appendChild(popup);
        const rect = btn.getBoundingClientRect();
        popup.style.top = (rect.bottom + 4) + 'px';
        popup.style.left = (rect.right - 96) + 'px';
        popup.classList.toggle('hidden');
      });
    });

    // Edit -> navigate to view
    document.querySelectorAll('.edit-btn').forEach(b => {
      b.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = b.dataset.id;
        window.location.href = 'blocklist_view.php?list=' + id;
      });
    });

    // Delete -> server delete and remove card
    document.querySelectorAll('.delete-btn').forEach(b => {
      b.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = b.dataset.id;
        if (!confirm('Delete this blocklist?')) return;
        try {
          const res = await fetch('delete_blocklist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ id }) });
          const json = await res.json();
          if (json.status === 'ok') {
            const cardBtn = document.querySelector(`button.toggle-active[data-id="${id}"]`);
            if (cardBtn) {
              const cardEl = cardBtn.closest('.bg-white');
              if (cardEl) cardEl.remove();
            }
          }
        } catch {}
      });
    });

    // Hide popups when clicking outside
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.menu-btn') && !e.target.closest('.menu-popup')) {
        document.querySelectorAll('.menu-popup').forEach(popup => popup.classList.add('hidden'));
      }
    });

    window.addEventListener('scroll', () => {
      document.querySelectorAll('.menu-popup').forEach(popup => popup.classList.add('hidden'));
    }, { passive: true });
    window.addEventListener('resize', () => {
      document.querySelectorAll('.menu-popup').forEach(popup => popup.classList.add('hidden'));
    });
  </script>
</body>
</html>