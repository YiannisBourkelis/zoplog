<?php
// blocked.php — View blocked traffic (from blocked_events)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ZopLog - Blocked Traffic</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .new-row { background-color: #fee2e2; transition: background-color 3s ease; }
    .truncate-cell { max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  </style>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
</head>
<body class="bg-gray-100 text-gray-900">
  <?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center space-x-3 mb-4">
      <span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-1.414-1.414A9 9 0 105.636 18.364l1.414-1.414A7 7 0 1116.95 7.05z" />
        </svg>
      </span>
  <h1 class="text-2xl font-bold">Blocked Traffic</h1>
    </div>

    <!-- Controls -->
    <div class="flex flex-wrap gap-4 items-center mb-4">
      <label class="flex items-center gap-2">
        Auto-refresh:
        <select id="refresh-interval" class="border rounded px-2 py-1">
          <option value="0">Disabled</option>
          <option value="1000">1s</option>
          <option value="5000">5s</option>
          <option value="10000">10s</option>
          <option value="30000">30s</option>
        </select>
      </label>

      <div class="flex items-center gap-2">
        <input id="filter-ip" type="text" placeholder="Filter by IP" class="border rounded px-2 py-1">
        <button id="clear-ip" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>
      <div class="flex items-center gap-2">
        <input id="filter-direction" type="text" placeholder="Direction (IN/OUT/FWD)" class="border rounded px-2 py-1">
        <button id="clear-direction" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>
      <div class="flex items-center gap-2">
        <input id="filter-proto" type="text" placeholder="Protocol (TCP/UDP/ICMP)" class="border rounded px-2 py-1">
        <button id="clear-proto" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>
      <div class="flex items-center gap-2">
        <input id="filter-iface" type="text" placeholder="Iface (IN/OUT)" class="border rounded px-2 py-1">
        <button id="clear-iface" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>

      <button id="apply-filters" class="bg-blue-500 text-white px-3 py-1 rounded">Apply Filters</button>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto bg-white shadow rounded-lg">
      <table class="min-w-full text-sm text-left">
    <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Time</th>
      <th class="px-4 py-2">Hostname</th>
            <th class="px-4 py-2">Direction</th>
            <th class="px-4 py-2">Proto</th>
            <th class="px-4 py-2">Src</th>
            <th class="px-4 py-2">Dst</th>
            <th class="px-4 py-2">IN Iface</th>
            <th class="px-4 py-2">OUT Iface</th>
            <th class="px-4 py-2">Message</th>
            <th class="px-4 py-2">Actions</th>
          </tr>
        </thead>
        <tbody id="logs-body"></tbody>
      </table>
    </div>

    <div id="loading" class="text-center py-4 hidden">Loading...</div>
  </div>

  <div id="new-logs-banner" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 bg-blue-600 text-white px-4 py-2 rounded shadow-lg cursor-pointer">
  🔄 New blocked traffic – click to view
  </div>

<script>
let offset = 0;
let autoRefreshTimer = null;
let latestTimestamp = null;
let filters = { ip: "", direction: "", proto: "", iface: "" };
let userAtTop = true;

function renderActionButtons(row) {
  const host = row.hostname || '';
  const dstIp = row.dst_ip || '';

  // Only IP known
  if (!host) {
    return `<button class="unblock-ip-btn bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" data-ip="${dstIp}">Unblock IP</button>`;
  }

  const cntManual = parseInt(row.cnt_manual_system_blocklists || 0);
  const btns = [];
  // Always allow add to whitelist for hostnames
  btns.push(`<button class="add-wl-btn bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600" data-host="${host}">Add to whitelist</button>`);
  // Allow unblock hostname only if present in manual/system lists
  if (cntManual > 0) {
    btns.push(`<button class="unblock-host-btn bg-orange-500 text-white px-3 py-1 rounded hover:bg-orange-600" data-host="${host}">Unblock Hostname</button>`);
  }
  // Always provide Unblock IP as a fallback action
  btns.push(`<button class="unblock-ip-btn bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600" data-ip="${dstIp}" data-host="${host}">Unblock IP</button>`);
  return btns.join(' ');
}

// Removed fetchHostnameMeta function as it is no longer needed

function renderRow(row) {
  const src = `${row.src_ip || ''}${row.src_port ? ':'+row.src_port : ''}`;
  const dst = `${row.dst_ip || ''}${row.dst_port ? ':'+row.dst_port : ''}`;
  const msg = row.message || '';
  const actions = renderActionButtons(row);
  return `
    <td class="px-4 py-2">${row.event_time}</td>
  <td class="px-4 py-2">${row.hostname ? row.hostname : ''}</td>
    <td class="px-4 py-2">${row.direction}</td>
    <td class="px-4 py-2">${row.proto || ''}</td>
    <td class="px-4 py-2">${src}</td>
    <td class="px-4 py-2">${dst}</td>
    <td class="px-4 py-2">${row.iface_in || ''}</td>
    <td class="px-4 py-2">${row.iface_out || ''}</td>
    <td class="px-4 py-2 truncate-cell" title="${msg.replaceAll('\"','&quot;')}">${msg}</td>
    <td class="px-4 py-2 actions-cell">${actions}</td>
  `;
}

async function fetchBlocked(reset=false, prepend=false) {
  const tbody = document.getElementById("logs-body");
  document.getElementById("loading").classList.remove("hidden");

  const params = new URLSearchParams({
    offset: reset ? 0 : offset,
    limit: 200,
    ip: filters.ip,
    direction: filters.direction,
    proto: filters.proto,
    iface: filters.iface
  });

  if (prepend && latestTimestamp) {
    params.append("since", latestTimestamp);
  }

  try {
    const res = await fetch("fetch_blocked.php?" + params.toString());
    const data = await res.json();

    if (prepend) {
      if (data.length > 0) {
        latestTimestamp = data[data.length - 1].event_time;
        const fragment = document.createDocumentFragment();
        data.forEach(row => {
          const tr = document.createElement("tr");
          tr.dataset.hostname = row.hostname || '';
          tr.dataset.row = JSON.stringify(row);
          tr.innerHTML = renderRow(row);
          tr.classList.add("new-row");
          fragment.appendChild(tr);
          setTimeout(() => tr.classList.remove("new-row"), 3000);
        });
        tbody.insertBefore(fragment, tbody.firstChild);
        if (!userAtTop) {
          document.getElementById("new-logs-banner").classList.remove("hidden");
        } else {
          tbody.parentElement.scrollTop = 0;
        }
      }
    } else {
      if (reset) {
        tbody.innerHTML = "";
        offset = 0;
      }
      const fragment = document.createDocumentFragment();
      data.forEach(row => {
        const tr = document.createElement("tr");
        tr.dataset.hostname = row.hostname || '';
        tr.dataset.row = JSON.stringify(row);
        tr.innerHTML = renderRow(row);
        fragment.appendChild(tr);
      });
      tbody.appendChild(fragment);
      if (data.length > 0) {
        latestTimestamp = data[0].event_time;
      }
      offset += data.length;
    }
  } catch (e) {
    console.error("Fetch blocked failed:", e);
  } finally {
    document.getElementById("loading").classList.add("hidden");
  }
}

// Infinite scroll
window.addEventListener("scroll", () => {
  if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 100) {
    fetchBlocked();
  }
  userAtTop = window.scrollY < 50;
  if (userAtTop) document.getElementById("new-logs-banner").classList.add("hidden");
});

// Auto refresh
const refreshSelect = document.getElementById("refresh-interval");
const savedInterval = localStorage.getItem("blocked-refresh-interval");
if (savedInterval !== null) {
  refreshSelect.value = savedInterval;
}
refreshSelect.addEventListener("change", e => {
  clearInterval(autoRefreshTimer);
  const interval = parseInt(e.target.value);
  localStorage.setItem("blocked-refresh-interval", e.target.value);
  if (interval > 0) {
    autoRefreshTimer = setInterval(() => fetchBlocked(false, true), interval);
  }
});
if (savedInterval && parseInt(savedInterval) > 0) {
  autoRefreshTimer = setInterval(() => fetchBlocked(false, true), parseInt(savedInterval));
}

// Filters
document.getElementById("apply-filters").addEventListener("click", () => {
  filters.ip = document.getElementById("filter-ip").value;
  filters.direction = document.getElementById("filter-direction").value.toUpperCase();
  filters.proto = document.getElementById("filter-proto").value.toUpperCase();
  filters.iface = document.getElementById("filter-iface").value;
  latestTimestamp = null;
  fetchBlocked(true);
});

// Enter key support for filter inputs
['filter-ip', 'filter-direction', 'filter-proto', 'filter-iface'].forEach(id => {
  const input = document.getElementById(id);
  const clearBtn = document.getElementById('clear-' + id.split('-')[1]);
  
  input.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      document.getElementById("apply-filters").click();
    }
  });
  
  input.addEventListener('input', () => {
    clearBtn.classList.toggle('hidden', !input.value);
  });
  
  clearBtn.addEventListener('click', () => {
    input.value = '';
    clearBtn.classList.add('hidden');
    document.getElementById("apply-filters").click();
  });
});

// Banner click → jump to top
document.getElementById("new-logs-banner").addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
  document.getElementById("new-logs-banner").classList.add("hidden");
});

// Initial load
fetchBlocked(true);

// Delegate button actions
document.addEventListener('click', async (e) => {
  const unblockIpBtn = e.target.closest('.unblock-ip-btn');
  const unblockHostBtn = e.target.closest('.unblock-host-btn');
  const addWlBtn = e.target.closest('.add-wl-btn');

  if (unblockIpBtn) {
    const ip = unblockIpBtn.dataset.ip;
    const host = unblockIpBtn.dataset.host || '';
    if (!ip) return;
    unblockIpBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'unblock_ip', ip, hostname: host })
      });
      const json = await res.json();
      alert(json.message || (json.status === 'ok' ? 'IP unblocked.' : 'Failed to unblock IP'));
    } catch (err) {
      alert('Network error while unblocking IP');
    } finally {
      unblockIpBtn.disabled = false;
    }
  }

  if (unblockHostBtn) {
    const host = unblockHostBtn.dataset.host;
    if (!host) return;
    unblockHostBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'unblock_hostname', hostname: host })
      });
      const json = await res.json();
      alert(json.message || (json.status === 'ok' ? 'Hostname unblocked.' : 'Failed to unblock hostname'));
    } catch (err) {
      alert('Network error while unblocking hostname');
    } finally {
      unblockHostBtn.disabled = false;
    }
  }

  if (addWlBtn) {
    const host = addWlBtn.dataset.host;
    if (!host) return;
    addWlBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_whitelist', hostname: host })
      });
      const json = await res.json();
      alert(json.message || (json.status === 'ok' ? 'Added to whitelist.' : 'Failed to add to whitelist'));
    } catch (err) {
      alert('Network error while adding to whitelist');
    } finally {
      addWlBtn.disabled = false;
    }
  }
});
</script>

</body>
</html>
