<?php
if(!isset($pageTitle)) $pageTitle='Neptune';

$u=current_user();
$hideChrome=$hideChrome??false;
$bodyClass=trim((string)($bodyClass??''));
$moduleName=strtoupper(trim((string)($moduleName??'')));

$activeSection=match($moduleName){
    'TRIDENT'=>'scouting',
    'AUGUR'=>'analytics',
    'SATURN','VULCAN','SALT'=>'command',
    default=>'home',
};

$appCssVersion=@filemtime(__DIR__.'/assets/css/app.css')?:1;
$neptuneCssVersion=@filemtime(__DIR__.'/assets/css/neptune-ui.css')?:1;
$fontAwesomeCssVersion=@filemtime(__DIR__.'/assets/vendor/fontawesome/css/all.min.css')?:1;
$fontAwesomeCompatVersion=@filemtime(__DIR__.'/assets/css/fontawesome-v7-compat.css')?:1;
$neptuneJsVersion=@filemtime(__DIR__.'/assets/js/neptune-ui.js')?:1;
$pageStyles=is_array($pageStyles??null)?$pageStyles:[];

/*
|--------------------------------------------------------------------------
| Public homepage SEO
|--------------------------------------------------------------------------
| This stays in the shared header so index.php can remain the known-good
| authentication controller.
*/
$scriptPath=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
$isPublicHome=!$u && (bool)preg_match('~/(?:index\.php)?$~',$scriptPath);

$siteUrl='https://neptune.mckinneysteamacademy.org/';
$seoTitle='Neptune FRC Scouting Platform | Analytics & Strategy';
$seoDescription='Neptune is an FRC scouting platform for live match scouting, pit scouting, pre-scouting, robot intelligence, analytics, strategy, event management, TBA integration and multi-team scouting.';
$seoOgTitle='Neptune FRC Scouting Platform';
$seoOgDescription='Scout matches, understand robots and build strategy with Neptune: FRC match scouting, pit scouting, pre-scouting, analytics, event control and TBA integration.';
$seoOgImage=$siteUrl.'images/neptune-og.png';

$documentTitle=$isPublicHome
    ? $seoTitle
    : (($pageTitle==='Neptune') ? 'Neptune' : $pageTitle.' - Neptune');

$homeStructuredData=[
    '@context'=>'https://schema.org',
    '@graph'=>[
        [
            '@type'=>'Organization',
            '@id'=>$siteUrl.'#organization',
            'name'=>'McKinney STEM Academy',
            'url'=>$siteUrl,
            'logo'=>[
                '@type'=>'ImageObject',
                'url'=>$siteUrl.'images/logo.png',
            ],
        ],
        [
            '@type'=>'WebSite',
            '@id'=>$siteUrl.'#website',
            'url'=>$siteUrl,
            'name'=>'Neptune FRC Scouting Platform',
            'description'=>$seoDescription,
            'publisher'=>['@id'=>$siteUrl.'#organization'],
        ],
        [
            '@type'=>'WebPage',
            '@id'=>$siteUrl.'#webpage',
            'url'=>$siteUrl,
            'name'=>$seoTitle,
            'description'=>$seoDescription,
            'isPartOf'=>['@id'=>$siteUrl.'#website'],
            'about'=>['@id'=>$siteUrl.'#application'],
            'breadcrumb'=>['@id'=>$siteUrl.'#breadcrumbs'],
            'primaryImageOfPage'=>[
                '@type'=>'ImageObject',
                'url'=>$seoOgImage,
                'width'=>1200,
                'height'=>630,
            ],
        ],
        [
            '@type'=>'WebApplication',
            '@id'=>$siteUrl.'#application',
            'name'=>'Neptune',
            'alternateName'=>'Neptune FRC Scouting Platform',
            'url'=>$siteUrl,
            'description'=>$seoDescription,
            'applicationCategory'=>'EducationalApplication',
            'applicationSubCategory'=>'FIRST Robotics Competition scouting and strategy platform',
            'operatingSystem'=>'Any',
            'browserRequirements'=>'Requires a modern web browser.',
            'image'=>$seoOgImage,
            'creator'=>['@id'=>$siteUrl.'#organization'],
            'featureList'=>[
                'FRC live match scouting',
                'Pit scouting',
                'Pre-scouting',
                'Robot intelligence',
                'Scouting analytics',
                'Match strategy',
                'Command Center',
                'Game Builder',
                'Event management',
                'The Blue Alliance integration',
                'Multi-organization scouting',
                'Hosted deployment',
                'Self-hosted cloud deployment',
                'Portable local event-server deployment',
            ],
        ],
        [
            '@type'=>'BreadcrumbList',
            '@id'=>$siteUrl.'#breadcrumbs',
            'itemListElement'=>[
                [
                    '@type'=>'ListItem',
                    'position'=>1,
                    'name'=>'Neptune',
                    'item'=>$siteUrl,
                ],
            ],
        ],
    ],
];
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

<title><?=e($documentTitle)?></title>

<?php if($isPublicHome): ?>
<meta name="description" content="<?=e($seoDescription)?>">
<link rel="canonical" href="<?=e($siteUrl)?>">
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">

<meta property="og:title" content="<?=e($seoOgTitle)?>">
<meta property="og:description" content="<?=e($seoOgDescription)?>">
<meta property="og:url" content="<?=e($siteUrl)?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Neptune">
<meta property="og:locale" content="en_US">
<meta property="og:image" content="<?=e($seoOgImage)?>">
<meta property="og:image:secure_url" content="<?=e($seoOgImage)?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Neptune FRC Scouting Platform">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?=e($seoOgTitle)?>">
<meta name="twitter:description" content="<?=e($seoOgDescription)?>">
<meta name="twitter:image" content="<?=e($seoOgImage)?>">
<meta name="twitter:image:alt" content="Neptune FRC Scouting Platform">

<link rel="preload" as="image" href="/images/logo.png" fetchpriority="high">

<script type="application/ld+json"><?=json_encode(
    $homeStructuredData,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
)?></script>
<?php endif; ?>

<script>try{document.documentElement.dataset.theme=localStorage.getItem('neptune-theme')||'dark'}catch(e){}</script>

<link rel="icon" type="image/png" sizes="64x64" href="/images/favicon.png">
<link rel="apple-touch-icon" href="/images/app-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="<?=e(base_url('assets/vendor/fontawesome/css/all.min.css'))?>?v=<?=$fontAwesomeCssVersion?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/fontawesome-v7-compat.css'))?>?v=<?=$fontAwesomeCompatVersion?>">
<link rel="stylesheet" href="/assets/css/app.css?v=<?=$appCssVersion?>">
<link rel="stylesheet" href="/assets/css/neptune-ui.css?v=<?=$neptuneCssVersion?>">
<?php foreach($pageStyles as $pageStyle): $pageStyle=(string)$pageStyle; $pageStyleVersion=@filemtime(__DIR__.'/'.ltrim($pageStyle,'/'))?:1; ?>
<link rel="stylesheet" href="<?=e(base_url($pageStyle))?>?v=<?=$pageStyleVersion?>">
<?php endforeach;?>
<style>
/* User Guide link: compact header icon on desktop, normal nav row on mobile. */
.neptune-help-link .neptune-help-label{display:none}
@media (min-width:761px){
    .nav-clean .neptune-help-link{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:38px;
        height:38px;
        padding:0;
        flex:0 0 38px;
        border:1px solid var(--line);
        border-radius:8px;
        background:var(--panel2);
        color:var(--text);
        text-decoration:none;
        box-sizing:border-box;
    }
    .nav-clean .neptune-help-link i{
        margin:0;
        width:auto;
    }
}
@media (max-width:760px){
    .nav-clean .neptune-help-link .neptune-help-label{display:inline}
}
</style>
<script defer src="/assets/js/neptune-ui.js?v=<?=$neptuneJsVersion?>"></script>
</head>

<body class="<?=e($bodyClass)?>" data-module="<?=e($moduleName)?>">
<?php if(!$hideChrome):?>
<header class="topbar topbar-clean">
    <a class="brand" href="<?=e(base_url($u ? 'dashboard/index.php' : 'index.php'))?>" aria-label="Neptune home">
        <img
            class="brand-logo"
            src="/images/logo.png"
            alt="Neptune FRC Scouting Platform"
            width="96"
            height="96"
            decoding="async"
            <?=$isPublicHome?'fetchpriority="high"':''?>
        >
    </a>

    <?php if($u): ?>
        <div class="topbar-identity">
            <b><?=e($u['organization_name']??'Neptune')?></b>
        </div>

        <button class="mobile-menu-toggle" type="button" id="mobileMenuToggle" aria-expanded="false" aria-controls="mainNav" aria-label="Open navigation menu">
            <i class="fa-solid fa-bars"></i>
        </button>

        <nav class="nav nav-clean" id="mainNav">
            <div class="mobile-nav-heading">
                <strong><?=e($u['organization_name']??'Neptune')?></strong>
                <small><?=e($u['display_name']??$u['username']??'')?></small>
            </div>

            <a class="<?=$activeSection==='home'?'active':''?>" href="<?=e(base_url('dashboard/index.php'))?>"><i class="fa-solid fa-house"></i><span>Home</span></a>
            <a class="<?=$activeSection==='scouting'?'active':''?>" href="<?=e(base_url('scouting.php'))?>"><i class="fa-solid fa-crosshairs"></i><span>Scouting</span></a>
            <a class="<?=$activeSection==='analytics'?'active':''?>" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-chart-line"></i><span>Analytics &amp; Strategy</span></a>

            <?php if(in_array($u['role'],['owner','admin','strategy'],true)): ?>
                <a class="<?=$activeSection==='command'?'active':''?>" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-tower-broadcast"></i><span>Command Center</span></a>
            <?php endif; ?>

            <a class="neptune-help-link" href="<?=e(base_url('help/'))?>" title="Neptune User Guide" aria-label="Neptune User Guide">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span class="neptune-help-label">User Guide</span>
            </a>

            <button class="theme-toggle" type="button" id="themeToggle" aria-label="Toggle day/night mode"><i class="fa-solid fa-moon"></i><span class="theme-label">Night mode</span></button>
            <a class="logout-link" href="<?=e(base_url('logout.php'))?>" title="Logout"><i class="fa-solid fa-right-from-bracket"></i><span class="nav-logout">Logout</span></a>
        </nav>
    <?php else: ?>
        <nav class="nav nav-public">
            <button class="theme-toggle" type="button" id="themeToggle" aria-label="Toggle day/night mode"><i class="fa-solid fa-moon"></i></button>
        </nav>
    <?php endif; ?>
</header>
<?php endif;?>

<main class="wrap <?=$hideChrome?'wrap-wide':''?>">
<?php
$hideBreadcrumbs=$hideBreadcrumbs??false;
$isLiveMatchScouting=(bool)preg_match('~/scout/match\.php$~',$scriptPath);

if($u && !$hideChrome && !$hideBreadcrumbs && !$isLiveMatchScouting):
    $crumbs=[];
    $isHome=(bool)preg_match('~/dashboard/index\.php$~',$scriptPath);
    $crumbs[]=['Home',$isHome?null:base_url('dashboard/index.php')];

    if(str_ends_with($scriptPath,'/scouting.php') || preg_match('~/(scout|pit|prescout|spot)/~',$scriptPath)){
        if(!str_ends_with($scriptPath,'/scouting.php')) $crumbs[]=['Scouting',base_url('scouting.php')];
    } elseif(preg_match('~/analytics/~',$scriptPath) || str_ends_with($scriptPath,'/dashboard/data.php')){
        if(!str_ends_with($scriptPath,'/analytics/index.php')) $crumbs[]=['Analytics & Strategy',base_url('analytics/index.php')];
    } elseif(preg_match('~/admin/~',$scriptPath)){
        if(!str_ends_with($scriptPath,'/admin/index.php')) $crumbs[]=['Command Center',base_url('admin/index.php')];
    }

    $lastLabel=$pageTitle?:'Neptune';
    $existing=array_map(fn($x)=>$x[0],$crumbs);
    if(!in_array($lastLabel,$existing,true)) $crumbs[]=[$lastLabel,null];
?>
<nav class="neptune-breadcrumbs" aria-label="Breadcrumb">
  <?php foreach($crumbs as $i=>$crumb):?>
    <?php if($i):?><i class="fa-solid fa-chevron-right crumb-sep" aria-hidden="true"></i><?php endif;?>
    <?php if($crumb[1]):?><a href="<?=e($crumb[1])?>"><?=e($crumb[0])?></a><?php else:?><span class="crumb-current" aria-current="page"><?=e($crumb[0])?></span><?php endif;?>
  <?php endforeach;?>
</nav>
<?php endif;?>
