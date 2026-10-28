<?php
declare(strict_types=1);
function community_page_start(string $title,string $subtitle,string $active='public',string $eyebrow='AUTO SCOUT'):void {
 global $pdo,$config,$u,$pageTitle,$moduleName,$hideChrome,$isPublicHome,$navigationSection;
 $pageTitle=$title;$moduleName='AUTO SCOUT';
 // ?embed=1: the page alone, inside another Auto Scout page (3 · Actions shows Watch & tag this way). No header, hero or menus.
 $embed=($_GET['embed']??'')==='1';$hideChrome=$embed;
 $viewer=function_exists('current_user')?current_user():null;
 // Auto Scout pages sit under the Auto Scout menu section for the platform owner who has it.
 if(in_array($viewer['role']??'',['owner','admin'],true)&&(int)($viewer['organization_id']??0)===(int)($config['app']['platform_organization_id']??1))$navigationSection='autoscout';
 include dirname(__DIR__).'/partials_header.php';
 ?>
<link rel="stylesheet" href="/community/assets/community.css?v=9-mission-pages">
<?php
 if($embed){echo '<style>body{background:transparent!important}header,footer,.site-header,.site-footer,.neptune-breadcrumbs,.neptune-alert-bell,.neptune-alert-toasts,.nd-depth-field{display:none!important}main.wrap{padding:0!important;margin:0!important;max-width:none!important}</style><div class="community-main auto-scout-platform community-embed">';return;}
 $communityHeroIcon=neptune_hero_relief_icon($title,'AUTO SCOUT');
 $communityHeroSteps=neptune_hero_guide($title);
 $communityHeroKicker=$eyebrow==='AUTO SCOUT'
     ? 'AUTO SCOUT · '.neptune_hero_category($title,'AUTO SCOUT')
     : $eyebrow;
 ?>
<div class="community-main auto-scout-platform">
  <div class="community-platform-hero neptune-page-hero" data-neptune-hero="server">
    <i class="fa-solid <?=e($communityHeroIcon)?> nd-page-mission-mark" aria-hidden="true"></i>
    <div class="neptune-hero-kicker"><i class="fa-solid fa-tags" aria-hidden="true"></i><span><?=e($communityHeroKicker)?></span></div>
    <h1><?=e($title)?></h1>
    <p><?=e($subtitle)?></p>
    <div class="neptune-hero-pills" aria-label="Page quick guide">
      <?php foreach($communityHeroSteps as $i=>$step): ?>
        <span class="neptune-hero-pill"><i class="fa-solid <?=e($step[0])?>" aria-hidden="true"></i><strong><?=$i+1?></strong> <?=e($step[1])?></span>
      <?php endforeach; ?>
    </div>
  </div>
<?php
 // Three pillars in order, then testing. Watch & tag and saved tags belong to Actions.
 $pillarOf=['catalog'=>'actions','contribute'=>'actions','results'=>'actions','learning'=>'actions','scoring'=>'field'];$current=$pillarOf[$active]??$active;
 $links=['robots'=>['1 · Robots','/autoscout/robot-labels.php','robot'],'field'=>['2 · Field','/autoscout/scoring-areas.php','field'],'actions'=>['3 · Actions','/autoscout/train.php','actions'],'test'=>['Test & release','/autoscout/train.php?view=test',''],'helpers'=>['Helpers & settings','/autoscout/train.php?view=helpers','']];
?><nav class="community-nav" aria-label="Auto Scout"><?php foreach($links as $key=>[$label,$href,$pillar]):?><a href="<?=e($href)?>"<?=$pillar?' data-pillar="'.$pillar.'"':''?> <?=$current===$key?'aria-current="page"':''?>><?=e($label)?></a><?php endforeach ?><?php if($active!=='hub'):?><a href="/autoscout/index.php">← Auto Scout</a><?php endif?></nav>
<?php }
function community_page_end():void {
 global $pdo,$config,$u,$pageTitle,$moduleName,$hideChrome,$isPublicHome;
 echo '</div>';
 include dirname(__DIR__).'/partials_footer.php';
}
