<?php
if(!isset($pageTitle)) $pageTitle='Neptune';
$u=current_user();
$hideChrome=$hideChrome??false;
$bodyClass=trim((string)($bodyClass??''));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#05080b">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Neptune">
<title><?=e($pageTitle)?> · Neptune</title>
<script>try{document.documentElement.dataset.theme=localStorage.getItem('neptune-theme')||'dark'}catch(e){}</script>
<link rel="icon" type="image/png" sizes="64x64" href="<?=e(base_url('images/favicon.png'))?>">
<link rel="apple-touch-icon" href="<?=e(base_url('images/app-icon.png'))?>">
<link rel="manifest" href="<?=e(base_url('manifest.webmanifest'))?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<link rel="stylesheet" href="<?=e(base_url('assets/css/app.css'))?>">
</head>
<body class="<?=e($bodyClass)?>">
<?php if(!$hideChrome):?>
<header class="topbar">
    <a class="brand" href="<?=e(base_url($u ? 'dashboard/index.php' : 'index.php'))?>" aria-label="Neptune home">
        <img class="brand-logo" src="<?=e(base_url('images/logo.png'))?>" alt="Neptune">
    </a>
    <?php if($u): ?>
        <span class="organization-name"><?=e($u['organization_name']??'')?></span>
        <button class="mobile-menu-toggle" type="button" id="mobileMenuToggle" aria-expanded="false" aria-controls="mainNav" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <nav class="nav" id="mainNav">
            <div class="mobile-nav-heading">
                <strong><?=e($u['organization_name']??'Neptune')?></strong>
                <small><?=e($u['display_name']??$u['username']??'')?></small>
            </div>
            <a href="<?=e(base_url('dashboard/index.php'))?>"><i class="fa-solid fa-house"></i><span>Home</span></a>
            <a href="<?=e(base_url('pit/index.php'))?>"><i class="fa-solid fa-clipboard-list"></i><span>Pit Scouting</span></a>
            <a href="<?=e(base_url('scout/index.php'))?>"><i class="fa-solid fa-crosshairs"></i><span>Scout</span></a>
            <a href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-chart-column"></i><span>Analytics</span></a>
            <?php if(in_array($u['role'],['owner','admin','strategy'],true)): ?>
                <a href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-tower-broadcast"></i><span>Command</span></a>
            <?php endif; ?>
            <button class="theme-toggle" type="button" id="themeToggle" aria-label="Toggle day/night mode"><i class="fa-solid fa-moon"></i><span class="theme-label">Night mode</span></button>
            <a href="<?=e(base_url('logout.php'))?>" title="Logout"><i class="fa-solid fa-right-from-bracket"></i><span class="nav-logout">Logout</span></a>
        </nav>
    <?php else: ?>
        <nav class="nav nav-public">
            <button class="theme-toggle" type="button" id="themeToggle" aria-label="Toggle day/night mode"><i class="fa-solid fa-moon"></i></button>
        </nav>
    <?php endif; ?>
</header>
<?php endif;?>
<main class="wrap <?=$hideChrome?'wrap-wide':''?>">
