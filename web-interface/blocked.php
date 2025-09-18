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
    
    /* Click action buttons styling */
    .table-container {
      position: relative;
    }
    
    .table-row {
      position: relative;
      transition: background-color 0.2s ease;
      cursor: pointer;
    }
    
    .table-row:hover {
      background-color: #f8fafc;
    }
    
    .table-row.active {
      background-color: #eff6ff;
      border-left: 3px solid #3b82f6;
    }
    
    .hover-actions {
      position: fixed;
      right: 20px;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(4px);
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      padding: 8px;
      display: none;
      z-index: 50;
      gap: 4px;
      border: 1px solid rgba(226, 232, 240, 0.8);
      pointer-events: auto;
      flex-direction: column;
      min-width: 180px;
    }
    
    .hover-actions-active {
      display: flex !important;
    }
    
    .hover-actions .button-row {
      display: flex;
      gap: 4px;
      margin-bottom: 6px;
    }
    
    .hover-actions .info-text {
      font-size: 11px;
      color: #64748b;
      padding: 2px 4px;
      border-top: 1px solid #e2e8f0;
      background: rgba(248, 250, 252, 0.8);
      border-radius: 4px;
      line-height: 1.2;
    }
    
    .domain-list {
      max-height: 150px;
      overflow-y: auto;
      border: 1px solid #e2e8f0;
      border-radius: 4px;
      background: #f8fafc;
      margin: 4px 0;
    }
    
    .domain-item {
      padding: 4px 8px;
      font-size: 12px;
      border-bottom: 1px solid #e2e8f0;
      color: #475569;
    }
    
    .domain-item:last-child {
      border-bottom: none;
    }
    
    .hover-actions .info-text .hostname {
      font-weight: 600;
      color: #475569;
    }
    
    .hover-actions .info-text .ip {
      color: #64748b;
      font-family: monospace;
    }
    
    .hover-actions button {
      font-size: 12px;
      padding: 4px 8px;
      border-radius: 4px;
      transition: all 0.2s ease;
      white-space: nowrap;
      border: none;
      cursor: pointer;
      opacity: 0.95;
    }
    
    .hover-actions button:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      opacity: 1;
    }
    
    /* Ensure button colors are preserved */
    .hover-actions .bg-green-500 {
      background-color: #10b981 !important;
      color: white !important;
    }
    
    .hover-actions .bg-orange-500 {
      background-color: #f97316 !important;
      color: white !important;
    }
    
    .hover-actions .bg-red-500 {
      background-color: #ef4444 !important;
      color: white !important;
    }
    
    .hover-actions .bg-green-500:hover {
      background-color: #059669 !important;
    }
    
    .hover-actions .bg-orange-500:hover {
      background-color: #ea580c !important;
    }
    
    .hover-actions .bg-red-500:hover {
      background-color: #dc2626 !important;
    }
    
    /* Tree-like structure styling */
    .parent-row {
      font-weight: normal;
    }
    
    .child-row {
      background-color: #f8fafc;
      border-left: 3px solid #e2e8f0;
    }
    
    .child-row.hidden {
      display: none;
    }
    
    .hostname-cell {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .expand-toggle {
      cursor: pointer;
      color: #64748b;
      font-size: 12px;
      user-select: none;
      transition: transform 0.2s ease;
      min-width: 16px;
    }
    
    .expand-toggle:hover {
      color: #475569;
    }
    
    .expand-toggle.expanded {
      transform: rotate(90deg);
    }
    
    .child-hostname {
      padding-left: 24px;
      color: #64748b;
      font-style: italic;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    
    .tree-line {
      color: #cbd5e1;
      font-family: monospace;
    }
    
    /* Time column styling */
    .time-column {
      font-weight: normal;
      padding-left: 8px; /* Reduced since arrow is now inside */
      min-width: 120px;
    }
    
    .time-cell-with-arrow {
      display: flex;
      align-items: flex-start;
      gap: 8px;
    }
    
    .time-arrow {
      cursor: pointer;
      color: #64748b;
      font-size: 12px;
      user-select: none;
      transition: transform 0.2s ease;
      flex-shrink: 0;
      margin-top: 2px;
    }
    
    .time-arrow:hover {
      color: #475569;
    }
    
    .time-arrow.expanded {
      transform: rotate(90deg);
    }
    
    .time-arrow-spacer {
      width: 16px;
      flex-shrink: 0;
    }
    
    .time-cell {
      display: flex;
      flex-direction: column;
      line-height: 1.2;
      flex-grow: 1;
    }
    
    .date-line {
      font-size: 12px;
      color: #475569;
      font-weight: normal;
    }
    
    .time-line {
      font-size: 11px;
      color: #64748b;
      font-weight: normal;
      margin-top: 2px;
    }
    
    /* Make all table text normal weight */
    table.min-w-full tr {
      font-weight: normal;
    }
    
    table.min-w-full tr td {
      font-weight: normal;
    }
    
    table.min-w-full tr th {
      font-weight: normal;
    }
    
    /* Smooth transitions for expand/collapse */
    .child-row {
      transition: all 0.2s ease;
    }
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
          <option value="30000" selected>30s</option>
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
    <div class="overflow-x-auto bg-white shadow rounded-lg table-container">
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
// JavaScript variables
let offset = 0;
let lastId = null; // cursor: the smallest id we've loaded; next page should ask for rows with id < lastId
let autoRefreshTimer = null;
let latestId = null; // the largest id we've loaded; for auto-refresh, get rows with id > latestId
let filters = { ip: "", direction: "", proto: "", iface: "" };
let userAtTop = true;
let isLoading = false; // Prevent multiple simultaneous requests

// Global function to format event time
function formatEventTime(timestamp) {
  if (!timestamp || timestamp === 'N/A') return 'N/A';
  try {
    const date = new Date(timestamp);
    const dateStr = date.toLocaleDateString();
    const timeStr = date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});
    return `<div class="time-cell">
              <div class="date-line">${dateStr}</div>
              <div class="time-line">${timeStr}</div>
            </div>`;
  } catch (e) {
    return timestamp;
  }
}

function renderActionButtons(row, hostnames = [], isParent = false) {
  const primaryIp = row.primary_ip || '';
  
  // Build buttons based on whether it's parent or child row
  const btns = [];
  
  if (isParent && hostnames.length > 1) {
    // Parent row with multiple hostnames
    btns.push(`<button class="add-wl-btn bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600 text-xs" data-hosts='${JSON.stringify(hostnames)}'>+ WL All (${hostnames.length})</button>`);
    btns.push(`<button class="add-wl-btn bg-blue-500 text-white px-2 py-1 rounded hover:bg-blue-600 text-xs" data-host="${hostnames[0]}">+ WL First</button>`);
  } else if (isParent && hostnames.length === 1) {
    // Parent row with single hostname
    btns.push(`<button class="add-wl-btn bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600 text-xs" data-host="${hostnames[0]}">+ Whitelist</button>`);
  } else if (!isParent && hostnames.length === 1) {
    // Child row with individual hostname
    const hostname = hostnames[0];
    btns.push(`<button class="add-wl-btn bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600 text-xs" data-host="${hostname}">+ WL</button>`);
  }
  
  // Always add unblock IP option
  btns.push(`<button class="unblock-ip-btn bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600 text-xs" data-ip="${primaryIp}">Unblock IP</button>`);
  
  const infoText = `IP: ${primaryIp}${hostnames.length > 0 ? ` | ${hostnames.join(', ')}` : ''}`;
  
  return `
    <div class="button-row">${btns.join('')}</div>
    <div class="info-text">${infoText}</div>
  `;
}

function renderRow(row) {
  console.log("renderRow called with:", row);
  
  // Parse hostnames
  const allHostnames = (row.all_hostnames && row.all_hostnames.trim() !== '') ? row.all_hostnames.split('|') : [];
  const primaryHostname = allHostnames.length > 0 ? allHostnames[0] : 'No hostname';
  
  // Use the latest event details with fallbacks
  const eventTime = row.latest_event_time || 'N/A';
  const direction = row.latest_direction || 'N/A';
  const proto = row.latest_proto || 'N/A';
  const srcIp = row.latest_src_ip || 'N/A';
  const dstIp = row.latest_dst_ip || 'N/A';
  const ifaceIn = row.latest_iface_in || '';
  const ifaceOut = row.latest_iface_out || '';
  const message = row.latest_message || '';
  
  // Format the event time to show date and time on separate lines
  const formattedTime = formatEventTime(eventTime);
  const hostnameDisplay = allHostnames.length > 1 ? 
    `<div class="hostname-cell">
       ${primaryHostname} <span class="text-xs text-gray-500">(+${allHostnames.length - 1} more)</span>
     </div>` : 
    primaryHostname;
  
  // Create time display with tree arrow on the left
  const timeDisplay = allHostnames.length > 1 ? 
    `<div class="time-cell-with-arrow">
       <span class="expand-toggle time-arrow" onclick="toggleChildren('${row.id}')">▶</span>
       ${formattedTime}
     </div>` : 
    `<div class="time-cell-with-arrow">
       <span class="time-arrow-spacer"></span>
       ${formattedTime}
     </div>`;
  
  const actions = renderActionButtons(row, allHostnames, true);
  
  return `
    <td class="px-4 py-2 time-column">${timeDisplay}</td>
    <td class="px-4 py-2">${hostnameDisplay}</td>
    <td class="px-4 py-2">${direction}</td>
    <td class="px-4 py-2">${proto}</td>
    <td class="px-4 py-2">${srcIp}</td>
    <td class="px-4 py-2">${dstIp}</td>
    <td class="px-4 py-2">${ifaceIn}</td>
    <td class="px-4 py-2">${ifaceOut}</td>
    <td class="px-4 py-2 truncate-cell" title="${message.replace(/"/g, '&quot;')}">${message}</td>
    <div class="hover-actions">${actions}</div>
  `;
}

// Function to create child rows for hostnames
function createChildRows(row, allHostnames) {
  const childHostnames = allHostnames.slice(1); // Skip first hostname (already shown in parent)
  let childRowsHtml = '';
  
  childHostnames.forEach((hostname, index) => {
    const isLast = index === childHostnames.length - 1;
    const treeSymbol = isLast ? '└─' : '├─';
    const actions = renderActionButtons(row, [hostname], false);
    
    childRowsHtml += `
      <tr class="child-row hidden" data-parent-ip="${row.primary_ip}">
        <td class="px-4 py-2 time-column">
          <div class="time-cell-with-arrow">
            <span class="time-arrow-spacer"></span>
          </div>
        </td>
        <td class="px-4 py-2">
          <div class="child-hostname">
            <span class="tree-line">${treeSymbol}</span>
            ${hostname}
          </div>
        </td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <td class="px-4 py-2"></td>
        <div class="hover-actions">${actions}</div>
      </tr>
    `;
  });
  
  return childRowsHtml;
}

// Helper function to escape IP address for CSS class names
function escapeIpForClass(ip) {
  return ip.replace(/\./g, '-');
}

// Function to add hostname(s) to whitelist
async function addToWhitelist(uniqueId, hostname, displayName) {
  try {
    // For "all domains" actions, hostname might be a comma-separated list
    const hostnames = hostname.includes(',') ? hostname.split(',').map(h => h.trim()) : [hostname];
    
    // Process each hostname
    let lastResponse = null;
    for (const host of hostnames) {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_whitelist', hostname: host })
      });
      
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      
      const json = await res.json();
      
      if (json.status !== 'ok') {
        throw new Error(json.message || `Failed to whitelist ${host}`);
      }
      
      lastResponse = json; // Store the last response to use for the message
    }
    
    // Close any open popup
    const activeActions = document.querySelector('.hover-actions-active');
    if (activeActions) {
      activeActions.remove();
    }
    const activeRow = document.querySelector('.table-row.active');
    if (activeRow) {
      activeRow.classList.remove('active');
    }
    
    // Show success message using the backend response
    if (lastResponse && lastResponse.message) {
      showTemporaryMessage(`✓ ${lastResponse.message}`, 'success');
    } else {
      // Fallback to generic message if no backend message
      if (hostnames.length > 1) {
        showTemporaryMessage(`✓ Successfully added ${hostnames.length} domains to whitelist.`, 'success');
      } else {
        showTemporaryMessage(`✓ Successfully added "${displayName}" to whitelist.`, 'success');
      }
    }
    
    // Mark rows as whitelisted instead of removing them
    if (uniqueId.startsWith('all-ip-')) {
      // Mark all rows for this IP as whitelisted
      const ip = uniqueId.replace('all-ip-', '');
      const escapedIp = escapeIpForClass(ip);
      const parentRow = document.querySelector(`tr[data-ip="${ip}"]`);
      const childRows = document.querySelectorAll(`tr.child-row-${escapedIp}`);
      
      if (parentRow) {
        markRowAsWhitelisted(parentRow);
      }
      childRows.forEach(row => {
        markRowAsWhitelisted(row);
      });
    } else {
      // Mark single domain row as whitelisted
      const targetRow = document.querySelector(`tr[onclick*="${uniqueId}"]`) || 
                        document.querySelector(`button[onclick*="${uniqueId}"]`)?.closest('tr');
      if (targetRow) {
        markRowAsWhitelisted(targetRow);
      }
    }
  } catch (err) {
    console.error('Error adding to whitelist:', err);
    showTemporaryMessage(`Error: ${err.message}`, 'error');
  }
}

// Function to unblock IP address
async function unblockIP(ipAddress) {
  try {
    const res = await fetch('blocked_actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'unblock_ip', ip: ipAddress })
    });
    
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    
    const json = await res.json();
    
    if (json.status === 'ok') {
      // Close any open popup
      const activeActions = document.querySelector('.hover-actions-active');
      if (activeActions) {
        activeActions.remove();
      }
      const activeRow = document.querySelector('.table-row.active');
      if (activeRow) {
        activeRow.classList.remove('active');
      }
      
      // Show success message
      showTemporaryMessage(`✓ Successfully unblocked IP "${ipAddress}".`, 'success');
      
      // Mark all rows for this IP as unblocked instead of removing them
      const escapedIp = escapeIpForClass(ipAddress);
      const parentRow = document.querySelector(`tr[data-ip="${ipAddress}"]`);
      const childRows = document.querySelectorAll(`tr.child-row-${escapedIp}`);
      
      if (parentRow) {
        markRowAsUnblocked(parentRow);
      }
      childRows.forEach(row => {
        markRowAsUnblocked(row);
      });
    } else {
      showTemporaryMessage(json.message || 'Failed to unblock IP.', 'error');
    }
  } catch (err) {
    console.error('Error unblocking IP:', err);
    showTemporaryMessage('Network error while unblocking IP.', 'error');
  }
}

// Function to mark a row as whitelisted
function markRowAsWhitelisted(row) {
  row.style.backgroundColor = '#f0f9ff';
  row.style.borderLeft = '4px solid #10b981';
  row.style.opacity = '0.8';
  
  // Add a visual indicator in the first cell
  const firstCell = row.querySelector('td');
  if (firstCell && !firstCell.querySelector('.status-indicator')) {
    const indicator = document.createElement('span');
    indicator.className = 'status-indicator';
    indicator.innerHTML = '✓ WHITELISTED';
    indicator.style.cssText = 'background: #10b981; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 8px; font-weight: bold;';
    firstCell.appendChild(indicator);
  }
  
  // Disable action buttons in this row
  const buttons = row.querySelectorAll('button');
  buttons.forEach(btn => {
    btn.disabled = true;
    btn.style.opacity = '0.5';
  });
}

// Function to mark a row as unblocked
function markRowAsUnblocked(row) {
  row.style.backgroundColor = '#fef3c7';
  row.style.borderLeft = '4px solid #f59e0b';
  row.style.opacity = '0.8';
  
  // Add a visual indicator in the first cell
  const firstCell = row.querySelector('td');
  if (firstCell && !firstCell.querySelector('.status-indicator')) {
    const indicator = document.createElement('span');
    indicator.className = 'status-indicator';
    indicator.innerHTML = '⚠ UNBLOCKED';
    indicator.style.cssText = 'background: #f59e0b; color: white; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 8px; font-weight: bold;';
    firstCell.appendChild(indicator);
  }
  
  // Disable action buttons in this row
  const buttons = row.querySelectorAll('button');
  buttons.forEach(btn => {
    btn.disabled = true;
    btn.style.opacity = '0.5';
  });
}

// Function to show temporary success/error messages
function showTemporaryMessage(message, type = 'success') {
  // Remove any existing message
  const existingMessage = document.getElementById('temp-message');
  if (existingMessage) {
    existingMessage.remove();
  }
  
  // Create new message element
  const messageDiv = document.createElement('div');
  messageDiv.id = 'temp-message';
  messageDiv.className = `fixed top-4 right-4 px-4 py-2 rounded-lg shadow-lg z-50 transition-opacity duration-300 ${
    type === 'success' 
      ? 'bg-green-500 text-white' 
      : 'bg-red-500 text-white'
  }`;
  messageDiv.textContent = message;
  
  // Add to page
  document.body.appendChild(messageDiv);
  
  // Auto-hide after 3 seconds
  setTimeout(() => {
    messageDiv.style.opacity = '0';
    setTimeout(() => messageDiv.remove(), 300);
  }, 3000);
}

// Function to toggle child rows visibility
function toggleChildren(toggleElement, rowId) {
  console.log("toggleChildren called with:", toggleElement, rowId);
  
  // Prevent the click from bubbling up to the row click handler
  if (event) {
    event.stopPropagation();
  }
  
  const childRows = document.querySelectorAll(`tr.child-row-${rowId}`);
  console.log("Row ID:", rowId);
  console.log("Found child rows:", childRows.length, childRows);
  
  const isExpanded = toggleElement.classList.contains('expanded');
  console.log("Is expanded:", isExpanded);
  
  if (isExpanded) {
    // Collapse
    toggleElement.classList.remove('expanded');
    childRows.forEach(row => row.style.display = 'none');
  } else {
    // Expand
    toggleElement.classList.add('expanded');
    childRows.forEach(row => row.style.display = '');
  }
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
    <div class="hover-actions">${actions}</div>
  `;
}

async function fetchBlocked(reset=false, prepend=false) {
  if (isLoading) return; // Prevent multiple simultaneous requests
  isLoading = true;
  
  const tbody = document.getElementById("logs-body");
  document.getElementById("loading").classList.remove("hidden");

  const params = new URLSearchParams({
    limit: 200,
    ip: filters.ip,
    direction: filters.direction,
    proto: filters.proto,
    iface: filters.iface
  });

  // For cursor-based pagination: when not resetting and we have a lastId, request older rows
  if (!reset && lastId) {
    params.append('last_id', lastId);
  }

  if (prepend) {
    if (latestId !== null) {
      params.append("latest_id", latestId);
    } else {
      // If latestId is null, get events with ID > 0 (all events)
      params.append("latest_id", 0);
    }
  }

  try {
    const res = await fetch("fetch_blocked_experiment_b.php?" + params.toString());
    
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    
    const data = await res.json();

    if (prepend) {
      if (data.length > 0) {
        // Update latestId to the maximum ID from new data for future auto-refresh
        const newIds = data.map(r => parseInt(r.id, 10)).filter(id => !isNaN(id));
        if (newIds.length > 0) {
          latestId = Math.max(latestId || 0, ...newIds);
        }
        const fragment = document.createDocumentFragment();
        
        data.forEach(row => {
          const allHostnames = (row.all_hostnames && row.all_hostnames.trim() !== '') ? row.all_hostnames.split('|') : [];
          const escapedIp = escapeIpForClass(row.primary_ip);
          
          // Create parent row
          const tr = document.createElement("tr");
          tr.className = "table-row parent-row";
          tr.dataset.ip = row.primary_ip;
          tr.dataset.allHostnames = JSON.stringify(allHostnames);
          tr.dataset.row = JSON.stringify(row);
          
          // Use simple innerHTML for parent row
          tr.innerHTML = `
            <td class="time-column">
              <div class="time-cell-with-arrow">
                ${allHostnames.length > 1 ? 
                  `<span class="expand-toggle time-arrow" onclick="toggleChildren(this, '${row.id}')">▶</span>` : 
                  '<span class="time-arrow-spacer"></span>'
                }
                ${formatEventTime(row.latest_event_time || '')}
              </div>
            </td>
            <td>${allHostnames.length > 0 ? allHostnames[0] : row.primary_ip}${allHostnames.length > 1 ? ` (+${allHostnames.length - 1} more)` : ''}</td>
            <td>${row.latest_direction || ''}</td>
            <td>${row.latest_proto || ''}</td>
            <td>${row.latest_src_ip || ''}</td>
            <td>${row.latest_dst_ip || ''}</td>
            <td>${row.latest_iface_in || ''}</td>
            <td>${row.latest_iface_out || ''}</td>
            <td class="message-cell">${(row.latest_message || '').replace(/"/g, '&quot;').substring(0, 100)}${(row.latest_message || '').length > 100 ? '...' : ''}</td>
            <td>
              <div class="hover-actions" style="display: none;">
                <button onclick="addToWhitelist('all-ip-${row.primary_ip}', '${allHostnames.join(', ')}', '${allHostnames.join(', ')}')" class="whitelist-btn">Whitelist All Domains (${allHostnames.length})</button>
                <div class="domain-list">
                  ${allHostnames.map(h => `<div class="domain-item">${h}</div>`).join('')}
                </div>
                <button onclick="unblockIP('${row.primary_ip}')" class="unblock-btn">Unblock IP</button>
              </div>
            </td>
          `;
          
          tr.classList.add("new-row");
          fragment.appendChild(tr);
          setTimeout(() => tr.classList.remove("new-row"), 3000);
          
          // Add click event listeners for dynamic button positioning
          addRowClickListeners(tr);
          
          // Create child rows if there are multiple hostnames
          if (allHostnames.length > 1) {
            allHostnames.slice(1).forEach((hostname, index) => {
              const childTr = document.createElement("tr");
              childTr.className = `child-row child-row-${row.id} table-row`;
              childTr.style.display = "none";
              childTr.innerHTML = `
                <td style="padding-left: 40px;">${index === allHostnames.length - 2 ? '└─' : '├─'} ${hostname}</td>
                <td colspan="8"></td>
                <td>
                  <div class="hover-actions" style="display: none;">
                    <button onclick="addToWhitelist('single-${hostname}', '${hostname}', '${hostname}')" class="whitelist-btn">Whitelist "${hostname}"</button>
                    <button onclick="unblockIP('${row.primary_ip}')" class="unblock-btn">Unblock IP</button>
                  </div>
                </td>
              `;
              childTr.classList.add("new-row");
              fragment.appendChild(childTr);
              setTimeout(() => childTr.classList.remove("new-row"), 3000);
              
              // Add click listener for child rows
              addRowClickListeners(childTr);
            });
          }
        });
        
        tbody.insertBefore(fragment, tbody.firstChild);
        if (!userAtTop) {
          document.getElementById("new-logs-banner").classList.remove("hidden");
        } else {
          window.scrollTo(0, 0);
        }
      }
    } else {
      if (reset) {
        tbody.innerHTML = "";
        offset = 0;
        lastId = null;
      }
      
      const fragment = document.createDocumentFragment();
  data.forEach(row => {
        const allHostnames = (row.all_hostnames && row.all_hostnames.trim() !== '') ? row.all_hostnames.split('|') : [];
        const escapedIp = escapeIpForClass(row.primary_ip);
        
        // Create parent row
        const tr = document.createElement("tr");
        tr.className = "table-row parent-row";
        tr.dataset.ip = row.primary_ip;
        tr.dataset.allHostnames = JSON.stringify(allHostnames);
        tr.dataset.row = JSON.stringify(row);
        
        // Use simple innerHTML for parent row
        tr.innerHTML = `
          <td class="time-column">
            <div class="time-cell-with-arrow">
              ${allHostnames.length > 1 ? 
                `<span class="expand-toggle time-arrow" onclick="toggleChildren(this, '${row.id}')">▶</span>` : 
                '<span class="time-arrow-spacer"></span>'
              }
              ${formatEventTime(row.latest_event_time || '')}
            </div>
          </td>
          <td>${allHostnames.length > 0 ? allHostnames[0] : row.primary_ip}${allHostnames.length > 1 ? ` (+${allHostnames.length - 1} more)` : ''}</td>
          <td>${row.latest_direction || ''}</td>
          <td>${row.latest_proto || ''}</td>
          <td>${row.latest_src_ip || ''}</td>
          <td>${row.latest_dst_ip || ''}</td>
          <td>${row.latest_iface_in || ''}</td>
          <td>${row.latest_iface_out || ''}</td>
          <td class="message-cell">${(row.latest_message || '').replace(/"/g, '&quot;').substring(0, 100)}${(row.latest_message || '').length > 100 ? '...' : ''}</td>
          <td>
            <div class="hover-actions" style="display: none;">
              <button onclick="addToWhitelist('all-ip-${row.primary_ip}', '${allHostnames.join(', ')}', '${allHostnames.join(', ')}')" class="whitelist-btn">Whitelist All Domains (${allHostnames.length})</button>
              <div class="domain-list">
                ${allHostnames.map(h => `<div class="domain-item">${h}</div>`).join('')}
              </div>
              <button onclick="unblockIP('${row.primary_ip}')" class="unblock-btn">Unblock IP</button>
            </div>
          </td>
        `;
        
        fragment.appendChild(tr);
        
        // Add click event listeners for dynamic button positioning
        addRowClickListeners(tr);
        
        // Create child rows if there are multiple hostnames
        if (allHostnames.length > 1) {
          allHostnames.slice(1).forEach((hostname, index) => {
            const childTr = document.createElement("tr");
            childTr.className = `child-row child-row-${row.id} table-row`;
            childTr.style.display = "none";
            childTr.innerHTML = `
              <td style="padding-left: 40px;">${index === allHostnames.length - 2 ? '└─' : '├─'} ${hostname}</td>
              <td colspan="8"></td>
              <td>
                <div class="hover-actions" style="display: none;">
                  <button onclick="addToWhitelist('single-${hostname}', '${hostname}', '${hostname}')" class="whitelist-btn">Whitelist "${hostname}"</button>
                  <button onclick="unblockIP('${row.primary_ip}')" class="unblock-btn">Unblock IP</button>
                </div>
              </td>
            `;
            fragment.appendChild(childTr);
            
            // Add click listener for child rows
            addRowClickListeners(childTr);
          });
        }
      });
      
      tbody.appendChild(fragment);
      if (data.length > 0) {
        // update lastId: find the minimum id in the returned batch (cursor for older rows)
        const ids = data.map(r => {
          const id = parseInt(r.id, 10);
          return isNaN(id) ? null : id;
        }).filter(id => id !== null);
        if (ids.length > 0) {
          const minId = Math.min(...ids);
          lastId = minId;
          // Also update latestId to the maximum ID for auto-refresh
          const maxId = Math.max(...ids);
          latestId = Math.max(latestId || 0, maxId);
        }
      }
    }

    updateLastRefreshIndicator();
  } catch (e) {
    console.error("Fetch blocked failed:", e);
    updateLastRefreshIndicator(true);
  } finally {
    document.getElementById("loading").classList.add("hidden");
    isLoading = false;
  }
}

// Function to add click event listeners for dynamic button positioning
function addRowClickListeners(tr) {
  tr.addEventListener('click', (e) => {
    // Prevent triggering if clicking on a button or expand toggle
    if (e.target.tagName === 'BUTTON' || e.target.classList.contains('expand-toggle')) {
      return;
    }
    
    // Remove any existing active row and actions
    const existingActiveRow = document.querySelector('.table-row.active');
    const existingActions = document.querySelector('.hover-actions-active');
    
    if (existingActiveRow) {
      existingActiveRow.classList.remove('active');
    }
    if (existingActions) {
      existingActions.remove();
    }
    
    // If clicking the same row that was already active, just close it
    if (existingActiveRow === tr) {
      return;
    }
    
    // Mark this row as active
    tr.classList.add('active');
    
    // Get the actions HTML from the row
    const actionsDiv = tr.querySelector('.hover-actions');
    if (actionsDiv) {
      // Create a new floating action container
      const floatingActions = document.createElement('div');
      floatingActions.className = 'hover-actions hover-actions-active';
      floatingActions.innerHTML = actionsDiv.innerHTML;
      
      // Position it relative to the row
      const rowRect = tr.getBoundingClientRect();
      
      // Calculate position
      const top = rowRect.top + (rowRect.height / 2);
      const right = 20; // Fixed distance from right edge of viewport
      
      floatingActions.style.position = 'fixed';
      floatingActions.style.top = top + 'px';
      floatingActions.style.right = right + 'px';
      floatingActions.style.transform = 'translateY(-50%)';
      floatingActions.style.display = 'flex';
      
      document.body.appendChild(floatingActions);
    }
  });
}

// Close actions when clicking outside the table
document.addEventListener('click', (e) => {
  // Check if click is outside the table or on a non-row element
  const tableContainer = document.querySelector('.table-container');
  const clickedRow = e.target.closest('.table-row');
  const clickedButton = e.target.closest('.hover-actions-active');
  
  // If clicking outside table and not on floating buttons, close actions
  if (!tableContainer.contains(e.target) && !clickedButton) {
    const activeRow = document.querySelector('.table-row.active');
    const activeActions = document.querySelector('.hover-actions-active');
    
    if (activeRow) {
      activeRow.classList.remove('active');
    }
    if (activeActions) {
      activeActions.remove();
    }
  }
});

let scrollThrottle = false;

// Infinite scroll
window.addEventListener("scroll", () => {
  if (!scrollThrottle && window.innerHeight + window.scrollY >= document.body.offsetHeight - 100) {
    scrollThrottle = true;
    fetchBlocked();
    setTimeout(() => scrollThrottle = false, 200); // Throttle for 200ms
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
const initialInterval = savedInterval ? parseInt(savedInterval) : parseInt(refreshSelect.value);
if (initialInterval > 0) {
  autoRefreshTimer = setInterval(() => fetchBlocked(false, true), initialInterval);
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

// Listen for refresh interval changes
refreshSelect.addEventListener("change", () => {
  const newInterval = parseInt(refreshSelect.value);
  
  // Clear existing timer
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
  
  // Save to localStorage
  localStorage.setItem("blocked-refresh-interval", newInterval);
  
  // Set up new timer if interval > 0
  if (newInterval > 0) {
    autoRefreshTimer = setInterval(() => fetchBlocked(false, true), newInterval);
  }
  
  // Update indicator
  updateLastRefreshIndicator();
});

// Filters
document.getElementById("apply-filters").addEventListener("click", () => {
  filters.ip = document.getElementById("filter-ip").value;
  filters.direction = document.getElementById("filter-direction").value.toUpperCase();
  filters.proto = document.getElementById("filter-proto").value.toUpperCase();
  filters.iface = document.getElementById("filter-iface").value;
  latestId = null;
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

// Initialize last refresh indicator
updateLastRefreshIndicator();

// Delegate button actions
document.addEventListener('click', async (e) => {
  const unblockIpBtn = e.target.closest('.unblock-ip-btn');
  const unblockHostBtn = e.target.closest('.unblock-host-btn');
  const addWlBtn = e.target.closest('.add-wl-btn');

  // Function to close the actions popup
  const closeActionsPopup = () => {
    const activeRow = document.querySelector('.table-row.active');
    const activeActions = document.querySelector('.hover-actions-active');
    
    if (activeRow) {
      activeRow.classList.remove('active');
    }
    if (activeActions) {
      activeActions.remove();
    }
  };

  if (unblockIpBtn) {
    const ip = unblockIpBtn.dataset.ip;
    const host = unblockIpBtn.dataset.host || '';
    if (!ip) return;
    
    // Close popup immediately when button is clicked
    closeActionsPopup();
    
    unblockIpBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'unblock_ip', ip, hostname: host })
      });
      
      // Check if the response is successful
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      
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
    
    // Close popup immediately when button is clicked
    closeActionsPopup();
    
    unblockHostBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'unblock_hostname', hostname: host })
      });
      
      // Check if the response is successful
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      
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
    
    // Close popup immediately when button is clicked
    closeActionsPopup();
    
    addWlBtn.disabled = true;
    try {
      const res = await fetch('blocked_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_whitelist', hostname: host })
      });
      
      // Check if the response is successful
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      
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
