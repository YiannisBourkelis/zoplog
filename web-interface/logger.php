<?php
// logger.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ZopLog - Allowed Traffic</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* New row highlighting */
    .new-row {
      background-color: #d1fae5; /* light green */
      transition: background-color 3s ease;
    }
  </style>
</head>
<body class="bg-gray-100 text-gray-900">
  <?php include "menu.php"; ?>
  <div class="container mx-auto py-6">
    <div class="flex items-center space-x-3 mb-4">
      <!-- Logger icon -->
      <span>
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6m-6 0h6"/>
        </svg>
      </span>
  <h1 class="text-2xl font-bold">Allowed Traffic</h1>
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
        <input id="filter-mac" type="text" placeholder="Filter by MAC" class="border rounded px-2 py-1">
        <button id="clear-mac" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>
      <div class="flex items-center gap-2">
        <input id="filter-domain" type="text" placeholder="Filter by Domain" class="border rounded px-2 py-1">
        <button id="clear-domain" class="text-gray-400 hover:text-gray-600 hidden">✕</button>
      </div>

      <select id="filter-method" class="border rounded px-2 py-1">
        <option value="">Any Method</option>
        <option value="GET">GET</option>
        <option value="POST">POST</option>
        <option value="PUT">PUT</option>
        <option value="DELETE">DELETE</option>
        <option value="HEAD">HEAD</option>
        <option value="OPTIONS">OPTIONS</option>
        <option value="PATCH">PATCH</option>
        <option value="TLS_CLIENTHELLO">TLS_CLIENTHELLO</option>
        <option value="N/A">N/A</option>
      </select>

      <select id="filter-type" class="border rounded px-2 py-1">
        <option value="">Any Type</option>
        <option value="HTTP">HTTP</option>
        <option value="HTTPS">HTTPS</option>
      </select>

      <button id="apply-filters" class="bg-blue-500 text-white px-3 py-1 rounded">Apply Filters</button>
    </div>

    <!-- Logs table -->
    <div class="overflow-x-auto bg-white shadow rounded-lg">
      <table class="min-w-full text-sm text-left">
        <thead class="bg-gray-200">
          <tr>
            <th class="px-4 py-2">Time</th>
            <th class="px-4 py-2">Domain</th>
            <th class="px-4 py-2">Src IP</th>
            <th class="px-4 py-2">Src MAC</th>
            <th class="px-4 py-2">Dst IP</th>
            <th class="px-4 py-2">Dst MAC</th>
            <th class="px-4 py-2">Type</th>
            <th class="px-4 py-2">Method</th>
            <th class="px-4 py-2">Path</th>
            <th class="px-4 py-2">Actions</th>
          </tr>
        </thead>
        <tbody id="logs-body"></tbody>
      </table>
    </div>

    <!-- Loading indicator -->
    <div id="loading" class="text-center py-4 hidden">Loading...</div>

  </div>

  <!-- Floating "new logs" banner -->
  <div id="new-logs-banner" 
       class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 bg-blue-600 text-white px-4 py-2 rounded shadow-lg cursor-pointer">
    🔄 New allowed traffic – click to view
  </div>

<script>
let offset = 0;
let autoRefreshTimer = null;
let latestTimestamp = null;
let filters = { ip: "", mac: "", domain: "", method: "", type: "" };
let userAtTop = true;

function renderRow(row) {
  const domain = row.domain || "";
  const blockButton = domain ? `<button class="block-domain-btn bg-red-500 text-white px-2 py-1 rounded text-xs hover:bg-red-600" data-domain="${domain}" data-action="block">Block</button>` : "";
  return `
    <td class="px-4 py-2">${row.packet_timestamp}</td>
    <td class="px-4 py-2">${row.domain || ""}</td>
    <td class="px-4 py-2">${row.src_ip}:${row.src_port}</td>
    <td class="px-4 py-2">${row.src_mac || ""}</td>
    <td class="px-4 py-2">${row.dst_ip}:${row.dst_port}</td>
    <td class="px-4 py-2">${row.dst_mac || ""}</td>
    <td class="px-4 py-2">${row.type}</td>
    <td class="px-4 py-2">${row.method}</td>
    <td class="px-4 py-2">${row.path || ""}</td>
    <td class="px-4 py-2">${blockButton}</td>
  `;
}

async function fetchLogs(reset=false, prepend=false) {
  const tbody = document.getElementById("logs-body");
  document.getElementById("loading").classList.remove("hidden");

  const params = new URLSearchParams({
    offset: reset ? 0 : offset,
    limit: 200,
    ip: filters.ip,
    mac: filters.mac,
    domain: filters.domain,
    method: filters.method,
    type: filters.type
  });

  if (prepend && latestTimestamp) {
    params.append("since", latestTimestamp);
  }

  try {
    const res = await fetch("fetch_logs.php?" + params.toString());
    
    // Check if the response is successful
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    
    const data = await res.json();

    if (prepend) {
      if (data.length > 0) {
        latestTimestamp = data[data.length - 1].packet_timestamp;
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
        latestTimestamp = data[0].packet_timestamp;
      }

      offset += data.length;
    }

    // Update last refresh indicator on successful data fetch
    updateLastRefreshIndicator();
  } catch (e) {
    console.error("Fetch failed:", e);

    // Show error indicator
    updateLastRefreshIndicator(true);
  } finally {
    document.getElementById("loading").classList.add("hidden");
    
    // Update block button states after loading new data
    if (!prepend) {
      updateBlockButtonStates();
    }
  }
}

// Infinite scroll
window.addEventListener("scroll", () => {
  if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 100) {
    fetchLogs();
  }

  userAtTop = window.scrollY < 50;
  if (userAtTop) document.getElementById("new-logs-banner").classList.add("hidden");
});

// Auto refresh
const refreshSelect = document.getElementById("refresh-interval");

// Load saved interval from localStorage
const savedInterval = localStorage.getItem("refresh-interval");
if (savedInterval !== null) {
  refreshSelect.value = savedInterval;
}

// Listen for changes and save to localStorage
refreshSelect.addEventListener("change", e => {
  clearInterval(autoRefreshTimer);
  const interval = parseInt(e.target.value);
  localStorage.setItem("refresh-interval", e.target.value);
  if (interval > 0) {
    autoRefreshTimer = setInterval(() => fetchLogs(false, true), interval);
  }
});

// If interval was set, start auto-refresh
if (savedInterval && parseInt(savedInterval) > 0) {
  autoRefreshTimer = setInterval(() => fetchLogs(false, true), parseInt(savedInterval));
}

// Update last refresh time indicator
function updateLastRefreshIndicator(isError = false) {
  const timeIndicator = document.getElementById('last-refresh') || (() => {
    const indicator = document.createElement('div');
    indicator.id = 'last-refresh';
    indicator.className = 'fixed top-4 right-4 bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-medium shadow-lg z-50';
    document.body.appendChild(indicator);
    return indicator;
  })();

  const currentInterval = parseInt(refreshSelect.value);
  const currentTime = new Date().toLocaleTimeString();

  if (isError) {
    timeIndicator.textContent = `Error • ${currentTime}`;
    timeIndicator.className = 'fixed top-4 right-4 bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-medium shadow-lg z-50';
  } else if (currentInterval === 0) {
    timeIndicator.textContent = `Manual • ${currentTime}`;
    timeIndicator.className = 'fixed top-4 right-4 bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-xs font-medium shadow-lg z-50';
  } else {
    timeIndicator.textContent = `Live • ${currentTime}`;
    timeIndicator.className = 'fixed top-4 right-4 bg-green-100 text-green-800 px-3 py-1 rounded-full text-xs font-medium shadow-lg z-50';
  }
}

// Listen for refresh interval changes to update indicator
refreshSelect.addEventListener("change", () => {
  updateLastRefreshIndicator();
});

// Filters
document.getElementById("apply-filters").addEventListener("click", () => {
  filters.ip = document.getElementById("filter-ip").value;
  filters.mac = document.getElementById("filter-mac").value;
  filters.domain = document.getElementById("filter-domain").value;
  filters.method = document.getElementById("filter-method").value;
  filters.type = document.getElementById("filter-type").value;
  latestTimestamp = null;
  fetchLogs(true);
});

// Enter key support for filter inputs
['filter-ip', 'filter-mac', 'filter-domain'].forEach(id => {
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

// Block domain functionality
document.addEventListener('click', async (e) => {
  if (e.target.classList.contains('block-domain-btn')) {
    const domain = e.target.dataset.domain;
    const action = e.target.dataset.action;
    
    if (!domain || !action) return;
    
    const confirmed = confirm(
      action === 'block' 
      ? `Block domain "${domain}"? This will add it to the system blocklist.`
      : `Unblock domain "${domain}"? This will remove it from the system blocklist.`
    );
    
    if (!confirmed) return;
    
    try {
      const formData = new FormData();
      formData.append('domain', domain);
      formData.append('action', action);
      
      const res = await fetch('block_hostname.php', {
        method: 'POST',
        body: formData
      });
      
      const data = await res.json();
      
      if (data.success) {
        if (action === 'block') {
          alert(data.message || `Domain "${domain}" has been blocked successfully!`);
        } else {
          alert(data.message || `Domain "${domain}" has been unblocked successfully!`);
        }
        // Refresh the page to show updated block status
        location.reload();
      } else {
        alert(`Failed to ${action} domain: ${data.message}`);
      }
    } catch (error) {
      console.error('Block/unblock error:', error);
      alert(`Failed to ${action} domain: ${error.message}`);
    }
  }
});

// Function to check and update block button states for visible domains
async function updateBlockButtonStates() {
  const buttons = document.querySelectorAll('.block-domain-btn[data-action="block"]');
  const hostnames = Array.from(buttons).map(btn => btn.dataset.hostname).filter((v, i, a) => a.indexOf(v) === i);
  
  if (hostnames.length === 0) return;
  
  try {
    // Check which hostnames are already blocked
    const formData = new FormData();
    formData.append('hostnames', JSON.stringify(hostnames));
    
    const res = await fetch('check_blocked_hostnames.php', {
      method: 'POST',
      body: formData
    });
    
    // Check if the response is successful
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    
    const data = await res.json();
    
    if (data.status === 'ok' && data.blocked) {
      // Update buttons for blocked hostnames
      buttons.forEach(button => {
        const hostname = button.dataset.hostname;
        if (data.blocked.includes(hostname)) {
          button.textContent = 'Undo Block';
          button.dataset.action = 'unblock';
          button.classList.remove('bg-red-500', 'hover:bg-red-600');
          button.classList.add('bg-orange-500', 'hover:bg-orange-600');
        }
      });
    }
  } catch (error) {
    console.error('Failed to check blocked hostnames:', error);
  }
}

// Initial load
fetchLogs(true);

// Initialize last refresh indicator
updateLastRefreshIndicator();
</script>

</body>
</html>