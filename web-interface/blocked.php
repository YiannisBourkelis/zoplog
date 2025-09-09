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

      <input id="filter-ip" type="text" placeholder="Filter by IP" class="border rounded px-2 py-1">
      <input id="filter-direction" type="text" placeholder="Direction (IN/OUT/FWD)" class="border rounded px-2 py-1">
      <input id="filter-proto" type="text" placeholder="Protocol (TCP/UDP/ICMP)" class="border rounded px-2 py-1">
      <input id="filter-iface" type="text" placeholder="Iface (IN/OUT)" class="border rounded px-2 py-1">

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

function renderRow(row) {
  const src = `${row.src_ip || ''}${row.src_port ? ':'+row.src_port : ''}`;
  const dst = `${row.dst_ip || ''}${row.dst_port ? ':'+row.dst_port : ''}`;
  const msg = row.message || '';
  return `
    <td class="px-4 py-2">${row.event_time}</td>
  <td class="px-4 py-2">${row.hostname ? row.hostname : ''}</td>
    <td class="px-4 py-2">${row.direction}</td>
    <td class="px-4 py-2">${row.proto || ''}</td>
    <td class="px-4 py-2">${src}</td>
    <td class="px-4 py-2">${dst}</td>
    <td class="px-4 py-2">${row.iface_in || ''}</td>
    <td class="px-4 py-2">${row.iface_out || ''}</td>
    <td class="px-4 py-2 truncate-cell" title="${msg.replaceAll('"','&quot;')}">${msg}</td>
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

// Banner click → jump to top
document.getElementById("new-logs-banner").addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
  document.getElementById("new-logs-banner").classList.add("hidden");
});

// Initial load
fetchBlocked(true);
</script>

</body>
</html>
