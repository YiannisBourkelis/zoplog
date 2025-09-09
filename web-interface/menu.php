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
<nav class="fixed z-10 bg-white shadow
            w-full h-20 top-0 left-0 flex flex-row items-center justify-center
            md:left-0 md:top-0 md:h-screen md:flex-col md:items-start md:justify-start"
     style="width: auto; min-width: 0;">
  <div class="flex flex-row items-center justify-center w-full h-full space-x-2
              md:flex-col md:items-center md:justify-start md:space-x-0 md:space-y-4 md:w-auto md:h-auto mt-0 md:mt-6"
       style="width: auto; min-width: 0;">
    <?php foreach ($menuItems as $item):
      $isActive = ($currentPage === $item['href']);
    ?>
      <a href="<?= $item['href'] ?>"
         class="flex flex-col items-center justify-center px-4 py-2 rounded-xl transition
                <?= $isActive ? 'bg-blue-100 text-blue-600 shadow-lg' : 'text-gray-500 hover:bg-gray-100 hover:text-blue-500' ?>"
         style="
            width: auto;
            min-width: 0;
            max-width: 100vw;
            word-break: break-word;
            white-space: normal;
            <?php if ($isActive): ?>
                @media (min-width: 768px) {
                    width: <?= $maxWidthRem ?>rem !important;
                }
            <?php endif; ?>
         ">
        <span class="mb-1"><?= $item['icon'] ?></span>
        <span class="text-xs font-semibold break-words text-center w-full"><?= $item['caption'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
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
    justify-content: center !important;
  }
  body {
    margin-left: 0 !important;
    margin-top: 5rem !important;
  }
}
</style>