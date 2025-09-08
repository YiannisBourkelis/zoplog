<?php
// whitelist.php - list and manage whitelists
require_once __DIR__ . '/zoplog_config.php';

// Fetch whitelists with domain counts
$res = $mysqli->query("SELECT id, name, category, active, created_at, updated_at,
  (SELECT COUNT(*) FROM whitelist_domains wd WHERE wd.whitelist_id = whitelists.id) AS domains_count
  FROM whitelists
  ORDER BY created_at DESC");
$whitelists = [];
if ($res) {
    while ($row = $res->fetch_assoc()) { $whitelists[] = $row; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Whitelists</title>
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
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </span>
      <h1 class="text-2xl font-bold">Whitelists</h1>
    </div>

    <!-- Add Whitelist Form -->
    <div class="bg-white rounded-lg shadow p-6 mb-8" id="add-form-card">
      <h2 class="text-lg font-semibold mb-4">Add New Whitelist</h2>
      <form id="add-whitelist-form" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1" for="wl-name">Name</label>
          <input id="wl-name" name="name" type="text" class="w-full border rounded px-3 py-2" placeholder="Whitelist name" required>
          <p class="text-xs text-red-600 mt-1 hidden" id="err-name"></p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1" for="wl-cat">Category</label>
          <select id="wl-cat" name="category" class="w-full border rounded px-3 py-2" required>
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
          <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600" id="add-btn">Add Whitelist</button>
        </div>
      </form>
    </div>

    <!-- Whitelist Cards from DB -->
    <div id="whitelist-cards" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($whitelists as $w): ?>
        <div class="bg-white rounded-lg shadow p-5 flex flex-col relative group hover:ring-2 hover:ring-green-400 transition">
          <div class="flex items-center justify-between mb-2 relative z-20">
            <div class="flex items-center gap-2">
              <span class="text-xs px-2 py-1 rounded <?= htmlspecialchars($w['category']) === 'social' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?>"><?= htmlspecialchars(ucfirst($w['category'])) ?></span>
              <span class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700"><?= (int)($w['domains_count'] ?? 0) ?> domains</span>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="toggle-active flex items-center px-2 py-1 rounded-full transition-colors <?= ($w['active']==='active') ? 'active bg-green-100 text-green-700' : 'inactive bg-gray-100 text-gray-700' ?>" data-id="<?= (int)$w['id'] ?>">
                <span class="font-semibold text-xs mr-2"><?= ($w['active']==='active') ? 'Active' : 'Inactive' ?></span>
                <span class="w-6 h-6 flex items-center justify-center">
                  <span class="inline-block w-4 h-4 rounded-full <?= ($w['active']==='active') ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                </span>
              </button>
              <div class="relative z-30">
                <button type="button" class="menu-btn p-1 rounded hover:bg-gray-200 transition-colors" data-id="<?= (int)$w['id'] ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5h.01M12 12h.01M12 19h.01" /></svg>
                </button>
                <div class="menu-popup fixed w-24 bg-white border rounded shadow-lg hidden z-50" data-id="<?= (int)$w['id'] ?>" style="top:0;left:0;">
                  <button class="edit-btn w-full text-left px-3 py-2 text-sm hover:bg-gray-100" data-id="<?= (int)$w['id'] ?>">Edit</button>
                  <button class="delete-btn w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50" data-id="<?= (int)$w['id'] ?>">Delete</button>
                </div>
              </div>
            </div>
          </div>
          <h3 class="font-bold text-lg mb-1 break-all relative"><?= htmlspecialchars($w['name']) ?></h3>
          <div class="flex justify-between text-xs text-gray-500 mt-auto relative">
            <span>Date Added: <?= htmlspecialchars($w['created_at']) ?></span>
            <span>Last Updated: <?= htmlspecialchars($w['updated_at']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($whitelists)): ?>
        <div class="text-gray-500">No whitelists yet. Add one above.</div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Add Whitelist form submission
    (function(){
      const form = document.getElementById('add-whitelist-form');
      const errName = document.getElementById('err-name');
      const errCat = document.getElementById('err-category');
      const feedback = document.getElementById('add-feedback');
      const btn = document.getElementById('add-btn');
      if (!form) return;
      function clearErrors(){
        if (errName) { errName.textContent=''; errName.classList.add('hidden'); }
        if (errCat) { errCat.textContent=''; errCat.classList.add('hidden'); }
        if (feedback) { feedback.textContent=''; feedback.className='text-sm'; }
      }
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        btn.disabled = true; btn.classList.add('opacity-60');
        feedback.textContent = 'Creating whitelist…';
        feedback.classList.add('text-gray-600');
        try {
          const fd = new FormData(form);
          const res = await fetch('add_whitelist.php', { method: 'POST', body: fd });
          const json = await res.json();
          if (json.status === 'validation_error') {
            if (json.errors?.name) { errName.textContent = json.errors.name; errName.classList.remove('hidden'); }
            if (json.errors?.category) { errCat.textContent = json.errors.category; errCat.classList.remove('hidden'); }
            feedback.textContent = 'Please fix the highlighted errors.';
            feedback.classList.remove('text-gray-600');
            feedback.classList.add('text-red-600');
          } else if (json.status === 'ok') {
            feedback.textContent = 'Whitelist added successfully.';
            feedback.classList.remove('text-gray-600');
            feedback.classList.add('text-green-600');
            form.reset();
            window.location.reload();
          } else {
            feedback.textContent = json.message || 'Failed to add whitelist.';
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

    // Toggle active/inactive
    document.querySelectorAll('.toggle-active').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = btn.dataset.id;
        const isActive = btn.classList.contains('active');
        const nextState = isActive ? 'inactive' : 'active';
        try {
          const res = await fetch('toggle_whitelist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ id, active: nextState }) });
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
        window.location.href = 'whitelist_view.php?list=' + id;
      });
    });

    // Delete
    document.querySelectorAll('.delete-btn').forEach(b => {
      b.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = b.dataset.id;
        if (!confirm('Delete this whitelist?')) return;
        try {
          const res = await fetch('delete_whitelist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ id }) });
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

    // Hide popups
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