<?php
// Menu configuration
$menuItems = [
    [
        "href" => "index.php",
        "caption" => "Dashboard",
        "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M13 5v6h6m-6 0v6m0 0H7m6 0h6"/></svg>',
    ],
    [
        "href" => "logger.php",
        "caption" => "Logs",
        "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6m-6 0h6"/></svg>',
    ],
    [
        "href" => "blocklists.php",
        "caption" => "Block Lists",
        "icon" => '<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-1.414-1.414A9 9 0 105.636 18.364l1.414-1.414A7 7 0 1116.95 7.05z" /></svg>',
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