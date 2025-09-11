<?php
// Check if this is an AJAX request
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// If AJAX request, don't output the menu HTML, only the content structure
if ($isAjax) {
  // For AJAX requests, we'll handle this in JavaScript
  return;
}
?>

<?php
// Menu configuration
$menuItems = [
  [
    "href" => "index.php",
    "caption" => "Dashboard",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 10v10h16V10"/></svg>',
  ],
  [
    "href" => "logger.php",
    "caption" => "Allowed Traffic",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h12M8 12h12M8 18h12"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h.01M4 12h.01M4 18h.01"/></svg>',
  ],
  [
    "href" => "blocked.php",
    "caption" => "Blocked Traffic",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 8.25l7.5 7.5"/></svg>',
  ],
  [
    "href" => "blocklists.php",
    "caption" => "Block Lists",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 2a1 1 0 00-1 1v1H6a2 2 0 00-2 2v13a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-2V3a1 1 0 00-1-1H9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 8h6M9 12h6M9 16h4"/></svg>',
  ],
  [
    "href" => "whitelist.php",
    "caption" => "Whitelist",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>',
  ],
  [
    "href" => "system_settings.php",
    "caption" => "System Settings",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
  ],
  [
    "href" => "database.php",
    "caption" => "Database",
    "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>',
  ],
  // Add more items here as needed
];

// Calculate max caption length for desktop button width
$maxLen = 0;
foreach ($menuItems as $item) {
  $len = strlen($item['caption']);
  if ($len > $maxLen) $maxLen = $len;
}
$maxWidthRem = ($maxLen * 0.65 + 2);

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="fixed z-10 bg-white shadow pl-4
            w-full h-20 top-0 left-0 flex flex-row items-center justify-start
            md:left-0 md:top-0 md:h-screen md:flex-col md:items-start md:justify-start md:pl-0"
     style="width: auto; min-width: 0;">
  <div class="flex flex-row items-center justify-start w-full h-full space-x-2 overflow-x-auto
              md:flex-col md:items-center md:justify-start md:space-x-0 md:space-y-4 md:w-auto md:h-auto mt-0 md:mt-6 md:overflow-x-visible"
       style="width: auto; min-width: 0;">
    <?php foreach ($menuItems as $item):
      $isActive = ($currentPage === $item['href']);
    ?>
      <a href="<?= $item['href'] ?>" data-page="<?= $item['href'] ?>"
         class="menu-link flex flex-col items-center justify-center px-4 py-2 rounded-xl transition flex-shrink-0
                <?= $isActive ? 'bg-blue-100 text-blue-600 shadow-lg' : 'text-gray-500 hover:bg-gray-100 hover:text-blue-500' ?>"
         style="
            width: auto;
            min-width: 0;
            max-width: none;
            word-break: normal;
            overflow-wrap: break-word;
            white-space: nowrap;
            flex-shrink: 0;
            <?php if ($isActive): ?>
                @media (min-width: 768px) {
                    width: <?= $maxWidthRem ?>rem !important;
                }
            <?php endif; ?>
         ">
        <span class="mb-1"><?= $item['icon'] ?></span>
        <span class="text-xs font-semibold text-center w-full break-words leading-tight" style="word-break: normal; overflow-wrap: break-word; white-space: normal;"><?= $item['caption'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>

<!-- Main Content Container -->
<div id="main-content" class="transition-opacity duration-300">
  <!-- Content will be loaded here via AJAX -->
</div>

<!-- Loading Indicator -->
<div id="loading-indicator" class="fixed inset-0 bg-white bg-opacity-75 flex items-center justify-center z-50 hidden">
  <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
</div>
<style>
@media (min-width: 768px) {
  nav {
    width: auto !important;
    min-width: 0 !important;
    height: 100vh !important;
    left: 0 !important;
    top: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
    justify-content: flex-start !important;
  }
  body {
    margin-left: <?= $maxWidthRem ?>rem !important;
    margin-top: 0 !important;
    padding-top: 0 !important;
  }
  #main-content {
    margin-left: <?= $maxWidthRem ?>rem !important;
    padding: 1rem !important;
    margin-top: 0 !important;
    padding-top: 0 !important;
  }
}
@media (max-width: 767px) {
  nav {
    width: 100vw !important;
    min-width: 0 !important;
    height: 5rem !important;
    left: 0 !important;
    top: 0 !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: flex-start !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    padding-left: 1rem !important;
  }
  body {
    margin-left: 0 !important;
    margin-top: 5rem !important;
  }
  #main-content {
    margin-top: 5rem !important;
    padding: 1rem !important;
  }
}
</style>

<script>
// SPA (Single Page Application) functionality for menu navigation
document.addEventListener('DOMContentLoaded', function() {
  const nav = document.querySelector('nav');
  const menuContainer = nav.querySelector('div');
  const menuLinks = document.querySelectorAll('.menu-link');
  const mainContent = document.getElementById('main-content');
  const loadingIndicator = document.getElementById('loading-indicator');
  
  let currentPage = '<?= $currentPage ?>';
  
  // Function to check if element is visible in scroll container
  function isElementVisible(element) {
    const containerRect = nav.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    
    return elementRect.left >= containerRect.left && 
           elementRect.right <= containerRect.right;
  }
  
  // Function to scroll element into view
  function scrollElementIntoView(element) {
    const containerRect = nav.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    
    if (elementRect.left < containerRect.left) {
      // Element is to the left, scroll left to show it
      nav.scrollLeft -= (containerRect.left - elementRect.left) + 16; // 16px padding
    } else if (elementRect.right > containerRect.right) {
      // Element is to the right, scroll right to show it
      nav.scrollLeft += (elementRect.right - containerRect.right) + 16; // 16px padding
    }
  }
  
  // Function to update active menu item
  function updateActiveMenuItem(page) {
    menuLinks.forEach(link => {
      const linkPage = link.getAttribute('data-page');
      if (linkPage === page) {
        link.classList.add('bg-blue-100', 'text-blue-600', 'shadow-lg');
        link.classList.remove('text-gray-500', 'hover:bg-gray-100', 'hover:text-blue-500');
      } else {
        link.classList.remove('bg-blue-100', 'text-blue-600', 'shadow-lg');
        link.classList.add('text-gray-500', 'hover:bg-gray-100', 'hover:text-blue-500');
      }
    });
    
    // Ensure active item is visible on mobile
    if (window.innerWidth <= 767) {
      const activeItem = nav.querySelector('a.bg-blue-100');
      if (activeItem && !isElementVisible(activeItem)) {
        setTimeout(() => scrollElementIntoView(activeItem), 100);
      }
    }
  }
  
  // Function to load page content via AJAX
  function loadPage(page, updateHistory = true) {
    console.log('Loading page:', page);
    
    // Show loading indicator
    loadingIndicator.classList.remove('hidden');
    mainContent.style.opacity = '0.5';
    
    fetch(page, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.text();
    })
    .then(html => {
      console.log('Received HTML length:', html.length);
      
      // For AJAX responses, the content should be clean (no menu included since menu.php returns early)
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      console.log('Parsed document title:', doc.title);
      
      // Look for the main content area first
      let contentToLoad = '';
      
      // Look for a main content wrapper or specific content areas
      const mainContentArea = doc.querySelector('#main-content');
      if (mainContentArea) {
        console.log('Found #main-content element');
        contentToLoad = mainContentArea.innerHTML;
      } else {
        // Get the body content (should be clean now)
        console.log('Using body content');
        contentToLoad = doc.body.innerHTML;
      }
      
      console.log('Final content length:', contentToLoad.length);
      
      // Clear existing content completely
      mainContent.innerHTML = '';
      
      // Update main content
      mainContent.innerHTML = contentToLoad;
      
      // Update current page
      currentPage = page;
      
      // Update active menu item
      updateActiveMenuItem(page);
      
      // Update page title
      const titleElement = doc.querySelector('title');
      if (titleElement) {
        document.title = titleElement.textContent;
      }
      
      // Update URL without page reload
      if (updateHistory) {
        history.pushState({page: page}, '', page);
      }
      
      // Hide loading indicator
      loadingIndicator.classList.add('hidden');
      mainContent.style.opacity = '1';
      
      // Re-initialize any charts or dynamic content
      initializeDynamicContent();
        
        // Re-initialize any charts or dynamic content
        initializeDynamicContent();
      }, 50);
    })
    .catch(error => {
      console.error('Error loading page:', error);
      mainContent.innerHTML = '<div class="p-8 text-center text-red-600"><h2 class="text-2xl font-bold mb-4">Error Loading Page</h2><p>There was an error loading the requested page. Please try again.</p></div>';
      loadingIndicator.classList.add('hidden');
      mainContent.style.opacity = '1';
    });
  }
  
  // Function to initialize dynamic content (charts, etc.)
  function initializeDynamicContent() {
    // Re-initialize Chart.js charts if they exist
    if (typeof Chart !== 'undefined') {
      // This would need to be customized based on the specific charts used
      console.log('Re-initializing charts...');
    }
  }
  
  // Handle menu clicks
  menuLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const page = this.getAttribute('data-page');
      
      if (page && page !== currentPage) {
        loadPage(page);
      }
    });
  });
  
  // Handle browser back/forward buttons
  window.addEventListener('popstate', function(e) {
    if (e.state && e.state.page) {
      loadPage(e.state.page, false);
    }
  });
  
  // Initialize with current page content
  if (window.innerWidth <= 767) {
    // Restore scroll position on mobile
    const savedScrollPosition = localStorage.getItem('menuScrollPosition');
    if (savedScrollPosition) {
      nav.scrollLeft = parseInt(savedScrollPosition);
    }
  }
  
  // Save scroll position when scrolling on mobile
  nav.addEventListener('scroll', function() {
    if (window.innerWidth <= 767) {
      localStorage.setItem('menuScrollPosition', nav.scrollLeft);
    }
  });
  
  // Clear scroll position on window resize to desktop
  window.addEventListener('resize', function() {
    if (window.innerWidth > 767) {
      localStorage.removeItem('menuScrollPosition');
    }
  });
  
  // Initialize history state
  history.replaceState({page: currentPage}, '', currentPage);
});
</script>