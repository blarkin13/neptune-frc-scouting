<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_tag_scouting.php';

$u=require_login();
tag_unified_ensure_schema($pdo);
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$ready=tagobs_tables_ready($pdo);
$canManage=tagobs_can_manage($u);
$settingsCatalog=$ready?tagobs_visible_tags($pdo,$org,false):[];
$tags=$ready?tagobs_ordered_tags($pdo,$org,(in_array((string)($_GET['tag_tab']??$_GET['tab']??'team'),['pit','team'],true)?(string)($_GET['tag_tab']??$_GET['tab']):'team'),tagobs_visible_tags($pdo,$org,false)):[];
$pref=$ready?tagobs_user_preference($pdo,$org,$userId):['event_id'=>null,'field_id'=>null,'auto_follow'=>1];
$requestedEventId=(int)($_GET['event_id']??0);
$selectorEventId=$requestedEventId>0?$requestedEventId:(int)($pref['event_id']??0);
$events=$ready?tagobs_events($pdo,$org,true,$selectorEventId):[];
$active=$ready?tagobs_active_matches($pdo,$org):[];
$validEventIds=array_map(static fn($row)=>(int)$row['id'],$events);
$initialEventId=($requestedEventId>0&&in_array($requestedEventId,$validEventIds,true))?$requestedEventId:((int)($pref['event_id']??0)>0&&in_array((int)$pref['event_id'],$validEventIds,true)?(int)$pref['event_id']:0);

$tagTab=(string)($_GET['tag_tab']??$_GET['tab']??'team');
if(!in_array($tagTab,['pit','team'],true))$tagTab='team';

$feed=$ready?tagobs_observation_rows($pdo,$org,null,null,100,false):[];
$review=$ready&&$canManage?tagobs_observation_rows($pdo,$org,null,null,100,true):[];
$feed=array_values(array_filter($feed,static fn($r)=>(string)($r['context']??'')===$tagTab));
$review=array_values(array_filter($review,static fn($r)=>(string)($r['context']??'')===$tagTab));
$feed=array_slice($feed,0,20);
$review=array_slice($review,0,20);
$media=$ready?tagobs_media_for_observations($pdo,$org,array_merge(array_column($feed,'id'),array_column($review,'id'))):[];
foreach($feed as &$r)$r['media']=$media[$r['id']]??[];unset($r);
foreach($review as &$r)$r['media']=$media[$r['id']]??[];unset($r);
$pageTitle='Tag Scouting';
$moduleName='TRIDENT';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.spot-layout{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(320px,.7fr);gap:16px;align-items:start}
.spot-stack{display:grid;gap:16px}
.spot-card{padding:16px}
.spot-page-head{margin-bottom:16px!important}
.spot-head-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.spot-export-actions{display:flex;gap:8px;flex-wrap:wrap}

.spot-tabs{display:none}
.tag-context-shell{--impact-cyan:#38d7ff;--impact-glow:color-mix(in srgb,var(--module-trident,var(--accent,#6f7cff)) 42%,transparent);position:relative}
.tag-unified-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:14px}
.tag-unified-tab{display:flex;align-items:center;justify-content:center;gap:7px;min-height:46px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);color:var(--muted);text-decoration:none;font-weight:900}
.tag-unified-tab.active{color:var(--module-trident);border-color:var(--module-trident);background:color-mix(in srgb,var(--module-trident) 8%,var(--panel2));box-shadow:inset 0 0 0 1px var(--module-trident)}

.tag-context-hero{position:relative;overflow:hidden;padding:22px;border:1px solid var(--line);border-radius:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel,#111827) 86%,var(--module-trident,#0b79b7) 14%),var(--panel,#111827));box-shadow:0 18px 55px rgba(0,0,0,.14)}
.tag-context-hero:before,.tag-context-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(3px)}
.tag-context-hero:before{width:320px;height:320px;right:-110px;top:-180px;background:radial-gradient(circle,var(--impact-glow),transparent 68%)}
.tag-context-hero:after{width:220px;height:220px;left:-120px;bottom:-160px;background:radial-gradient(circle,color-mix(in srgb,var(--impact-cyan) 26%,transparent),transparent 70%)}
.tag-context-kicker{display:flex;align-items:center;gap:8px;font-size:.75rem;letter-spacing:.16em;font-weight:950;text-transform:uppercase;color:var(--muted)}
.tag-context-title{font-size:clamp(2.15rem,7vw,4.8rem);line-height:.9;margin:10px 0 12px;letter-spacing:-.065em}
.tag-context-title span{background:linear-gradient(90deg,var(--module-trident,var(--accent,#7c8cff)),var(--impact-cyan));-webkit-background-clip:text;background-clip:text;color:transparent}
.tag-context-sub{max-width:820px;margin:0;color:var(--muted);font-size:1rem;line-height:1.55}
.tag-context-selectors{position:relative;display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:19px}
.tag-context-selectors label{display:grid;gap:7px;font-weight:900;font-size:.76rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted)}
.tag-context-selectors select,.tag-context-selectors input{width:100%;min-height:54px;border-radius:15px;font-size:1rem;font-weight:850}
.tag-context-team-field{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}
.tag-context-team-field>label{min-width:0}
.tag-context-team-field button{min-height:54px;border-radius:15px;white-space:nowrap}
.tag-context-selected{position:relative;margin:12px 0 0}
.tag-context-tools{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin:-2px 0 16px}
.tag-context-tools .btn{min-height:40px}
.tag-context-tools .spot-export-actions{display:flex;gap:8px}
.spot-tab{border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:9px;padding:10px 8px;font-weight:900;display:flex;align-items:center;justify-content:center;gap:7px;min-height:44px}
.spot-tab.active{border-color:var(--module-trident);box-shadow:inset 0 0 0 1px var(--module-trident);color:var(--module-trident);background:color-mix(in srgb,var(--module-trident) 7%,var(--panel2))}

.spot-follow{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--panel2);margin-bottom:12px}
.spot-follow strong{display:block}
.spot-follow small{color:var(--muted)}
.spot-follow label{display:flex;align-items:center;gap:7px;font-weight:800;color:var(--muted)}

.spot-active-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px}
.spot-match{border:1px solid var(--line);border-radius:10px;padding:11px;background:var(--panel2)}
.spot-match.following{border-color:var(--module-trident);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--module-trident) 60%,transparent)}
.spot-match-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.spot-match-head b{font-size:.95rem}
.spot-match-sub{font-size:.72rem;color:var(--muted);margin-top:2px}
.spot-state{font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;font-weight:950;padding:4px 6px;border-radius:999px;border:1px solid var(--line)}
.spot-state.running{color:var(--good)}
.spot-state.paused{color:var(--warn)}

.spot-teams{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-top:10px}
.spot-team-btn{border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--text);padding:8px 5px;min-width:0;text-align:center;min-height:48px}
.spot-team-btn b{display:block;font-size:.92rem}
.spot-team-btn small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.61rem;color:var(--muted)}
.spot-team-btn.red{border-color:color-mix(in srgb,#ef4444 42%,var(--line))}
.spot-team-btn.blue{border-color:color-mix(in srgb,#3b82f6 42%,var(--line))}
.spot-team-btn.selected{outline:2px solid var(--module-trident);outline-offset:1px}

.spot-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.spot-team-line{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}
.spot-selected{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-radius:10px;border:1px solid color-mix(in srgb,var(--module-trident) 38%,var(--line));background:color-mix(in srgb,var(--module-trident) 7%,var(--panel2));margin:12px 0}
.spot-selected-number{font-size:1.35rem;font-weight:950}
.spot-selected-meta{font-size:.73rem;color:var(--muted)}

.spot-observation-divider{border:0;border-top:1px solid var(--line);margin:16px 0}
.spot-tag-groups{display:grid;gap:12px}
.spot-tag-category h3{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:0 0 7px}
.spot-tags{display:flex;flex-wrap:wrap;gap:7px}
.spot-tag{position:relative}
.spot-tag input{position:absolute;opacity:0;pointer-events:none}
.spot-tag span{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:var(--panel2);border-radius:999px;padding:9px 12px;font-size:.78rem;font-weight:800;cursor:pointer}
.spot-tag span i{font-size:1.08rem;line-height:1;flex:0 0 auto}
.spot-tag input:checked+span{border-color:var(--module-trident);box-shadow:inset 0 0 0 1px var(--module-trident);background:color-mix(in srgb,var(--module-trident) 12%,var(--panel2));color:var(--text)}
.spot-tag.sev-critical span{border-left:3px solid var(--bad)}
.spot-tag.sev-warning span{border-left:3px solid var(--warn)}
.spot-tag.sev-positive span{border-left:3px solid var(--good)}

.spot-media-actions{display:flex;gap:8px;flex-wrap:wrap}
.spot-media-label-short{display:none}
.spot-file-summary{font-size:.73rem;color:var(--muted);margin-top:8px;overflow-wrap:anywhere}
.spot-hidden-file{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;opacity:0!important;pointer-events:none!important}
.spot-save-wrap{margin-top:16px}
#spotSave{width:100%;padding:12px;min-height:48px}

.spot-feed{display:grid;gap:8px}
.spot-item{border:1px solid var(--line);border-radius:9px;padding:10px;background:var(--panel2)}
.spot-item-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.spot-item-team{font-weight:950;font-size:1rem}
.spot-item-meta{font-size:.68rem;color:var(--muted);margin-top:2px;line-height:1.35}
.spot-item-note{font-size:.78rem;line-height:1.4;margin-top:7px;white-space:pre-wrap}
.spot-item-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.spot-chip{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--line);border-radius:999px;padding:4px 6px;font-size:.64rem}
.spot-chip.critical{color:var(--bad)}
.spot-chip.warning{color:var(--warn)}
.spot-chip.positive{color:var(--good)}
.spot-media-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.spot-media-link{display:inline-flex;align-items:center;gap:5px;font-size:.7rem}
.spot-resolved{opacity:.68}
.spot-review-count{color:var(--bad)}

.spot-dialog{width:min(680px,calc(100vw - 28px));border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);padding:0;box-shadow:0 24px 80px rgba(0,0,0,.5)}
.spot-dialog::backdrop{background:rgba(0,0,0,.58)}
.spot-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line)}
.spot-dialog-body{padding:16px}
.spot-search-results{display:grid;gap:7px;margin-top:10px;max-height:50vh;overflow:auto}
.spot-search-result{display:flex;justify-content:space-between;gap:10px;text-align:left;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text)}

.spot-empty{padding:16px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:9px;line-height:1.4}
.spot-empty i{display:block;font-size:1.15rem;margin-bottom:6px;color:var(--module-trident)}
.spot-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.spot-section-title h2{margin:0}
.spot-help{font-size:.72rem;color:var(--muted);line-height:1.45}

@media(max-width:1050px){
  .spot-layout{grid-template-columns:1fr}
  .spot-side{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .spot-side .card{margin:0!important}
}

@media(max-width:720px){
  .tag-context-hero{padding:16px;border-radius:19px}
  .tag-context-selectors{grid-template-columns:1fr;margin-top:16px;gap:11px}
  .tag-context-selectors select,.tag-context-selectors input{min-height:54px;font-size:1rem}
  .tag-context-team-field{grid-template-columns:minmax(0,1fr) auto}
  .tag-context-team-field button{min-height:54px;padding-left:13px;padding-right:13px}
  .tag-context-selected{margin-top:10px}
  .tag-context-tools{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:0 0 12px}
  .tag-context-tools>.btn{width:100%;min-width:0;justify-content:center}
  .tag-context-tools .spot-export-actions{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
  .tag-context-tools .spot-export-actions .btn{width:100%;justify-content:center}


  .spot-layout,.spot-stack{gap:12px}
  .spot-card{padding:12px;border-radius:12px}
  .spot-tabs{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin:0 0 12px}
  .spot-tab{min-height:44px;padding:7px 6px;font-size:.78rem;border-radius:9px}
  .spot-tab i{font-size:.9rem}

  .spot-form-grid,.spot-side{grid-template-columns:1fr}
  .spot-active-grid{grid-template-columns:1fr}
  .spot-team-line{grid-template-columns:minmax(0,1fr) auto}
  .spot-team-line button{width:auto;min-height:44px}
  .spot-form-grid select,.spot-form-grid input{min-height:46px}

  .spot-follow{display:grid;grid-template-columns:1fr;gap:8px;padding:10px;margin-bottom:10px}
  .spot-follow strong{font-size:.9rem}
  .spot-follow small{display:block;font-size:.68rem;line-height:1.35;margin-top:2px}
  .spot-follow label{width:100%;justify-content:flex-start;font-size:.78rem;padding-top:7px;border-top:1px solid var(--line)}

  .spot-match{padding:10px}
  .spot-match-head b{font-size:.88rem}
  .spot-match-sub{font-size:.68rem}
  .spot-teams{gap:6px}
  .spot-team-btn{min-height:50px;padding:7px 4px}
  .spot-team-btn b{font-size:.9rem}
  .spot-team-btn small{font-size:.58rem}

  .spot-selected{padding:10px 11px;margin:10px 0}
  .spot-selected-number{font-size:1.12rem}
  .spot-selected-meta{font-size:.68rem;line-height:1.3}
  #spotClearTeam{padding:7px 9px;min-height:38px;font-size:.72rem}

  .spot-observation-divider{margin:13px 0}
  .spot-section-title{align-items:flex-start;margin-bottom:9px}
  .spot-section-title h2{font-size:1.45rem;line-height:1.08}
  .spot-section-title .btn{padding:7px 9px;min-height:38px;font-size:.72rem;white-space:nowrap}
  .spot-help{font-size:.68rem;line-height:1.4;margin-top:3px}

  .spot-tag-groups{gap:14px}
  .spot-tag-category h3{font-size:.66rem;margin-bottom:7px}
  .spot-tags{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
  .spot-tag span{width:100%;min-height:56px;border-radius:11px;padding:12px 13px;font-size:.86rem;line-height:1.2;gap:9px}
  .spot-tag span i{font-size:1.28rem;line-height:1;flex:0 0 auto}

  #spotNote{min-height:108px;font-size:16px;line-height:1.4}
  .spot-media-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
  .spot-media-actions button{min-width:0;min-height:54px;padding:7px 4px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font-size:.72rem;line-height:1.05}
  .spot-media-actions button i{font-size:1rem}
  .spot-media-label-full{display:none}
  .spot-media-label-short{display:inline}
  .spot-file-summary{font-size:.68rem;line-height:1.35}

  .spot-save-wrap{position:sticky;bottom:8px;z-index:10;margin:14px -4px -4px;padding:8px 4px 4px;background:linear-gradient(to bottom,transparent 0,var(--panel) 22%)}
  #spotSave{min-height:50px;padding:10px 12px;font-size:.92rem;box-shadow:0 8px 24px rgba(0,0,0,.28)}

  .spot-item{padding:10px}
  .spot-item-team{font-size:1rem}
  .spot-item-meta{font-size:.65rem}
  .spot-chip{font-size:.62rem;padding:4px 6px}
  .spot-item-note{font-size:.75rem}
  .spot-media-link{font-size:.68rem}

  .spot-dialog{width:calc(100vw - 20px);max-height:85vh}
  .spot-dialog-head{padding:12px}
  .spot-dialog-body{padding:12px}
  .spot-search-results{max-height:58vh}

  .spot-empty{padding:13px 12px;font-size:.82rem}
}

@media(max-width:390px){
  .spot-tags{grid-template-columns:1fr}
  .spot-tag span{min-height:60px;padding:13px 14px;font-size:.9rem}
  .spot-tag span i{font-size:1.35rem}
  .spot-media-actions button{font-size:.68rem}
}

/* v18: same condensed hero structure as Match Tag Scouting. */
.tag-context-shell .tag-context-hero{padding:clamp(14px,1.8vw,22px)!important}
.tag-context-shell .tag-context-kicker{line-height:1.25!important;margin:0 0 7px!important}
.tag-context-shell .tag-context-title{font-size:clamp(1.9rem,4vw,3rem)!important;line-height:1.08!important;margin:0 0 8px!important}
.tag-context-shell .tag-context-sub{font-size:clamp(.82rem,1vw,.94rem)!important;line-height:1.42!important;margin:0!important;max-width:680px}
.tag-context-shell .tag-context-selectors{margin-top:14px!important;gap:9px!important}
.tag-context-shell .tag-context-selectors label{gap:5px!important}
.tag-context-shell .tag-context-selectors select,.tag-context-shell .tag-context-selectors input{min-height:46px!important}
@media(max-width:600px){.tag-context-shell .tag-context-hero{padding:14px!important}.tag-context-shell .tag-context-title{font-size:clamp(1.85rem,7vw,2.25rem)!important}.tag-context-shell .tag-context-selectors{margin-top:12px!important}}

/* Match-style large tag controls. Scoped to Pit / Alliance. */
.spot-tag-category{padding:13px;border:1px solid var(--line);border-radius:18px;background:var(--panel)}
.spot-tag-category h3{font-size:.85rem;letter-spacing:.04em;margin-bottom:12px;color:var(--text)}
.spot-tags{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
.spot-tag{display:block;min-width:0;touch-action:pan-y;user-select:none;-webkit-user-select:none}
.spot-tag span{display:flex;width:100%;min-height:62px;align-items:center;justify-content:flex-start;border-radius:15px;padding:12px;text-align:left;font-size:.85rem;line-height:1.3;white-space:normal}
.spot-tag span i{font-size:1.15rem}
.spot-tag input:focus-visible+span{outline:3px solid var(--module-trident);outline-offset:2px}
.spot-tag input:checked+span{background:color-mix(in srgb,var(--module-trident) 25%,var(--panel2));border-width:2px}
.spot-offline-status{padding:10px 12px;border-radius:12px;background:var(--panel2);border:1px solid var(--line);margin-top:12px;font-size:.8rem}
.spot-history-detail{margin-top:8px}.spot-history-detail summary{cursor:pointer;font-weight:700}
@media(max-width:380px){.spot-tags{grid-template-columns:1fr}}

/* v35: Match-consistent compact card controls, no redundant utility toolbar */
.tag-context-shell .spot-tag-groups{gap:10px}
.tag-context-shell .spot-tag-category{padding:12px;border:1px solid var(--line);border-radius:15px;background:var(--panel2,var(--panel))}
.tag-context-shell .spot-tag-category h3{font-size:.73rem;letter-spacing:.11em;text-transform:uppercase;color:var(--muted);margin:0 0 9px;font-weight:1000}
.tag-context-shell .spot-tags{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:8px}
.tag-context-shell .spot-tag{cursor:pointer;position:relative;min-width:0;touch-action:pan-y}
.tag-context-shell .spot-tag input{position:absolute;opacity:0;pointer-events:none}
.tag-context-shell .spot-tag span{position:relative;display:flex;align-items:center;gap:12px;min-height:74px;width:100%;border:1px solid var(--line);border-radius:12px;padding:12px 37px 12px 13px;font-size:.88rem;font-weight:900;line-height:1.15;background:color-mix(in srgb,var(--panel) 96%,var(--text) 4%);user-select:none;transition:.14s transform,.14s border-color,.14s background,.14s box-shadow}
.tag-context-shell .spot-tag span i{font-size:clamp(1.65rem,2.5vw,2rem);min-width:34px;color:var(--muted);text-align:center;line-height:1}
.tag-context-shell .spot-tag span::after{content:"✓";position:absolute;right:10px;top:50%;transform:translateY(-50%) scale(.55);opacity:0;width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:var(--module-trident,var(--accent));color:var(--solid-text,#fff);font-weight:1000;transition:.14s opacity,.14s transform}
.tag-context-shell .spot-tag:active span{transform:scale(.975)}
.tag-context-shell .spot-tag input:checked+span{border:1px solid var(--module-trident,var(--accent));background:linear-gradient(135deg,color-mix(in srgb,var(--module-trident,var(--accent)) 22%,var(--panel)),color-mix(in srgb,var(--module-trident,var(--accent)) 8%,var(--panel)));box-shadow:0 0 0 2px var(--impact-glow),0 10px 24px var(--shadow)}
.tag-context-shell .spot-tag input:checked+span i{color:var(--module-trident,var(--accent))}
.tag-context-shell .spot-tag input:checked+span::after{opacity:1;transform:translateY(-50%) scale(1)}
.tag-context-shell .spot-tag.sev-critical span,.tag-context-shell .spot-tag.sev-warning span,.tag-context-shell .spot-tag.sev-positive span{border-left-width:1px}
.tag-context-shell .spot-tag.sev-critical input:checked+span,.tag-context-shell .spot-tag.sev-warning input:checked+span{border-color:var(--bad);box-shadow:0 0 0 2px color-mix(in srgb,var(--bad) 28%,transparent)}
.tag-context-shell .spot-tag.sev-critical input:checked+span i,.tag-context-shell .spot-tag.sev-warning input:checked+span i{color:var(--bad)}
.tag-context-shell .spot-tag.sev-critical input:checked+span::after,.tag-context-shell .spot-tag.sev-warning input:checked+span::after{background:var(--bad)}
@media(max-width:720px){.tag-context-shell .spot-tags{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.tag-context-shell .spot-tag span{font-size:.76rem;min-height:64px;padding:7px 25px 7px 8px;gap:8px}.tag-context-shell .spot-tag span i{font-size:1.5rem;min-width:29px}}
@media(max-width:380px){.tag-context-shell .spot-tags{grid-template-columns:repeat(2,minmax(0,1fr))}}

/* v36: Information Source radio buttons are controlled card labels, not theme checkbox layouts. */
.tag-context-shell .spot-evidence-source{margin:14px 0 18px;padding:14px;border:1px solid var(--line);border-radius:15px;min-width:0}
.tag-context-shell .spot-evidence-source legend{font-size:.94rem;font-weight:900;padding:0 7px;color:var(--text)}
.tag-context-shell .spot-evidence-source legend span{color:var(--muted);font-weight:600}
.tag-context-shell .spot-evidence-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:4px 0 10px}
.tag-context-shell .spot-evidence-option{position:relative;display:block;margin:0!important;cursor:pointer;min-width:0}
.tag-context-shell .spot-evidence-option input{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:18px;height:18px;margin:0;accent-color:var(--module-trident,var(--accent));z-index:1;opacity:1;appearance:auto;-webkit-appearance:radio}
.tag-context-shell .spot-evidence-option span{display:flex;align-items:center;gap:9px;min-height:56px;width:100%;padding:10px 12px 10px 40px;border-radius:12px;border:1px solid var(--line);background:var(--panel2);color:var(--text);font-size:.9rem;font-weight:800;line-height:1.35;white-space:normal}
.tag-context-shell .spot-evidence-option input:checked+span{border-color:var(--module-trident,var(--accent));background:color-mix(in srgb,var(--module-trident,var(--accent)) 14%,var(--panel2))}
.tag-context-shell .spot-evidence-option input:focus-visible+span{outline:2px solid var(--module-trident,var(--accent));outline-offset:2px}
.tag-context-shell .spot-evidence-source .spot-help{margin:0;font-size:.8rem}
@media(max-width:440px){.tag-context-shell .spot-evidence-options{grid-template-columns:1fr}}
.spot-roster-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:8px;margin-top:12px;max-height:55vh;overflow:auto}
.spot-roster-picker button{min-height:55px;text-align:left;border:1px solid var(--line);border-radius:10px;background:var(--panel2);color:var(--text);padding:9px 11px;font-weight:800}
.spot-roster-picker button small{display:block;font-weight:500;color:var(--muted);font-size:.74rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.spot-roster-picker button:hover,.spot-roster-picker button:focus-visible{border-color:var(--module-trident,var(--accent))}

/* v37: event-first Pit/Alliance flow; robot selection never follows Match. */
.tag-context-shell [hidden]{display:none!important}
.tag-context-shell .tag-context-selectors{grid-template-columns:minmax(0,1fr);max-width:760px}
.tag-context-shell .spot-selected{align-items:center;gap:12px;margin-top:14px}
.spot-robot-identity{display:flex;align-items:center;gap:12px;min-width:0}
.spot-selected-avatar{width:52px;height:52px;flex:0 0 52px;display:grid;place-items:center;border:1px solid var(--line);border-radius:14px;background:var(--panel2);overflow:hidden;font-size:26px;color:var(--accent)}
.spot-selected-avatar img,.spot-floating-avatar img,.spot-roster-avatar img{width:100%;height:100%;object-fit:contain}
.spot-floating-robot{position:fixed;bottom:calc(18px + env(safe-area-inset-bottom,0px));left:50%;transform:translateX(-50%);z-index:85;pointer-events:none}
#spotFindTeam{pointer-events:auto;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:88px;height:88px;border-radius:50%;padding:7px;background:var(--panel);border:3px solid var(--module-trident,var(--accent));color:var(--text);box-shadow:0 8px 24px rgba(0,0,0,.24);cursor:pointer;font-size:.8rem;font-weight:950}
.spot-floating-avatar{display:grid;place-items:center;overflow:hidden;width:48px;height:48px;color:var(--module-trident,var(--accent));font-size:30px;border-radius:50%}
#spotRosterDialog{width:min(620px,calc(100vw - 24px));max-width:620px;max-height:min(88dvh,900px);border-radius:20px;overflow:hidden}
#spotRosterDialog .spot-dialog-head{position:sticky;top:0;background:var(--panel);z-index:2;padding:15px 18px}
#spotRosterDialog .spot-dialog-head b{font-size:1.25rem}
#spotRosterDialog .spot-dialog-body{overflow:auto;max-height:calc(88dvh - 85px);padding:15px}
#spotRosterFilter{width:100%;min-height:48px}
#spotRosterGrid.spot-roster-picker{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-height:none;overflow:visible}
#spotRosterGrid.spot-roster-picker button{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:4px;min-height:145px;padding:13px 7px;border-radius:14px}
#spotRosterGrid.spot-roster-picker button b{font-size:1.2rem}
#spotRosterGrid.spot-roster-picker button small{font-size:.75rem;max-width:100%;white-space:normal;line-height:1.2;text-align:center}
.spot-roster-avatar{display:grid;place-items:center;overflow:hidden;width:57px;height:57px;border-radius:12px;color:var(--module-trident,var(--accent));font-size:30px;background:var(--panel);border:1px solid var(--line)}
.tag-context-shell .spot-source-compact{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:13px 0 18px}
.tag-context-shell .spot-source-label{font-size:.82rem;font-weight:900;color:var(--muted);margin-right:4px}
.tag-context-shell .spot-source-compact label{display:block;position:relative;cursor:pointer;margin:0}
.tag-context-shell .spot-source-compact input{position:absolute;opacity:0;width:1px;height:1px}
.tag-context-shell .spot-source-compact label span{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;border:1px solid var(--line);background:var(--panel2);color:var(--muted);font-size:.84rem;font-weight:800}
.tag-context-shell .spot-source-compact input:checked+span{border-color:var(--module-trident,var(--accent));color:var(--text);background:color-mix(in srgb,var(--module-trident,var(--accent)) 14%,var(--panel2))}
.tag-context-shell .spot-source-compact input:focus-visible+span{outline:2px solid var(--accent);outline-offset:3px}
.tag-context-shell .spot-save-wrap{position:relative!important;bottom:auto!important;margin:18px 0 0!important;padding:0!important;background:transparent!important;z-index:auto!important;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.tag-context-shell #spotSave{width:auto;min-width:210px;padding:12px 23px}
.tag-context-shell #spotOfflineStatus{flex:1;font-size:.8rem;min-width:140px}
.tag-context-shell .spot-layout{padding-bottom:110px}
@media(max-width:600px){.spot-floating-robot{bottom:calc(10px + env(safe-area-inset-bottom,0px))}#spotFindTeam{height:78px;width:78px}.spot-floating-avatar{height:41px;width:41px}.tag-context-shell #spotSave{width:100%}}
/* v38: source choices are exactly the same card controls as tags. */
.tag-context-shell .spot-source-compact{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:16px 0 20px!important}
.tag-context-shell .spot-source-compact .spot-source-label{grid-column:1/-1;font-size:.82rem;color:var(--muted)}
.tag-context-shell .spot-source-compact label{display:block;position:relative;min-width:0;cursor:pointer}
.tag-context-shell .spot-source-compact input{position:absolute!important;opacity:0!important;width:1px!important;height:1px!important;pointer-events:none!important;appearance:none!important}
.tag-context-shell .spot-source-compact label span{box-sizing:border-box;display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;min-height:68px!important;padding:12px!important;border:1px solid var(--line)!important;border-left:3px solid var(--line)!important;border-radius:14px!important;background:var(--panel2)!important;color:var(--text)!important;font-weight:900!important;font-size:.9rem!important}
.tag-context-shell .spot-source-compact label span i{font-size:1.45rem!important;color:var(--muted)}
.tag-context-shell .spot-source-compact input:checked+span{border-color:var(--accent)!important;border-left-color:var(--accent)!important;background:color-mix(in srgb,var(--accent) 13%,var(--panel2))!important;box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 18%,transparent)!important}
.tag-context-shell .spot-source-compact input:checked+span i{color:var(--accent)}
.tag-context-shell .spot-save-wrap{margin:24px 0 0!important;padding:0 0 105px!important;display:block!important;position:static!important;transform:none!important}
.tag-context-shell #spotSave{position:static!important;width:100%;min-height:54px}
.tag-context-shell #spotOfflineStatus{position:static!important;margin-top:10px!important;max-width:100%}
.tag-context-shell .spot-card{overflow:visible}
.tag-context-settings-btn{border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:12px;width:46px;height:46px;cursor:pointer;font-size:1.25rem}
.tag-context-settings-list details{border:1px solid var(--line);border-radius:12px;padding:12px;margin:10px 0}.tag-context-settings-list summary{font-weight:900;cursor:pointer}
.tag-context-settings-list button{display:block;width:100%;padding:10px;text-align:left;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--text)}

.tag-context-hero #spotSettingsOpen{position:absolute;right:14px;top:12px;z-index:3}
.tag-context-shell .spot-browser-pref{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line);cursor:pointer}
.tag-context-shell .spot-browser-pref input{width:20px;height:20px;flex:none;accent-color:var(--accent)}
.tag-context-shell .spot-browser-pref span{display:grid;gap:4px}
.tag-context-shell .spot-browser-pref small{font-size:.8rem;color:var(--muted)}
/* v39: Pit evidence selectors use the identical large tag-card interaction, never native radios. */
.tag-context-shell .spot-source-compact{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important;margin:14px 0 20px!important}
.tag-context-shell .spot-source-compact .spot-source-label{grid-column:1/-1}
.tag-context-shell .spot-source-compact label{display:block!important;position:relative!important;margin:0!important;min-width:0!important}
.tag-context-shell .spot-source-compact label>input[type="radio"]{position:absolute!important;opacity:0!important;appearance:none!important;-webkit-appearance:none!important;pointer-events:none!important;width:1px!important;height:1px!important;left:0!important;top:0!important;clip-path:inset(50%)!important}
.tag-context-shell .spot-source-compact label>span{display:flex!important;align-items:center!important;min-height:74px!important;border-radius:15px!important;border:2px solid var(--line)!important;padding:12px 14px!important;background:var(--panel2)!important;color:var(--muted)!important;font-size:.96rem!important}
.tag-context-shell .spot-source-compact label>input:checked+span{border-color:var(--accent)!important;background:color-mix(in srgb,var(--accent) 12%,var(--panel2))!important;color:var(--text)!important}
.tag-context-shell .spot-source-compact label>input:checked+span i{color:var(--accent)!important}
/* Keep saves and sync notices in the document flow, away from the viewport-fixed robot picker. */
.tag-context-shell .spot-save-wrap{position:static!important;bottom:auto!important;transform:none!important;z-index:1!important;padding:0 0 155px!important;margin:26px 0 0!important;background:none!important}
.tag-context-shell .spot-save-wrap #spotSave,.tag-context-shell .spot-save-wrap #spotOfflineStatus{position:static!important;bottom:auto!important;transform:none!important;z-index:auto!important}
.tag-context-shell #spotOfflineStatus{margin-top:14px!important}
@media(max-width:640px){.tag-context-shell .spot-source-compact{grid-template-columns:repeat(2,minmax(0,1fr))!important}.tag-context-shell .spot-source-compact label>span{min-height:66px!important;padding:8px!important;font-size:.79rem!important}}

/* v40: observation content is a sibling of .tag-context-shell, so styles must be unscoped. */
.spot-source-compact{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important;margin:18px 0 24px!important;align-items:stretch!important}
.spot-source-compact .spot-source-label{grid-column:1/-1;font-size:.82rem;font-weight:900;color:var(--muted)}
.spot-source-compact label{display:block!important;position:relative!important;min-width:0!important;cursor:pointer!important;margin:0!important}
.spot-source-compact label input[type=radio]{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;appearance:none!important;-webkit-appearance:none!important;clip-path:inset(50%)!important;pointer-events:none!important;left:0!important;top:0!important;margin:0!important}
.spot-source-compact label span{display:flex!important;align-items:center!important;justify-content:center!important;gap:10px!important;min-height:74px!important;padding:12px!important;border:2px solid var(--line)!important;border-left:5px solid var(--line)!important;border-radius:14px!important;background:var(--panel2)!important;color:var(--muted)!important;font-weight:900!important;font-size:.94rem!important;line-height:1.25!important;text-align:center!important}
.spot-source-compact label span i{font-size:1.3rem!important}
.spot-source-compact label input:checked+span{border-color:var(--module-trident,var(--accent))!important;background:color-mix(in srgb,var(--module-trident,var(--accent)) 12%,var(--panel2))!important;color:var(--text)!important;box-shadow:inset 0 0 0 1px var(--module-trident,var(--accent))!important}
.spot-source-compact label input:focus-visible+span{outline:3px solid var(--accent)!important;outline-offset:3px!important}
.spot-save-wrap{position:static!important;bottom:auto!important;inset:auto!important;transform:none!important;z-index:auto!important;background:transparent!important;margin:24px 0 0!important;padding:0 0 160px!important;display:block!important}
.spot-save-wrap #spotSave,.spot-save-wrap #spotOfflineStatus{position:static!important;bottom:auto!important;transform:none!important;width:100%!important;max-width:100%!important}
.spot-save-wrap #spotOfflineStatus{margin-top:12px!important;min-height:45px!important;overflow-wrap:anywhere!important}
.spot-layout{padding-bottom:130px!important}
/* Match-style settings location and structure on Pit/Alliance. */
.tag-context-hero{position:relative!important}
.tag-context-hero #spotSettingsOpen{position:absolute!important;right:14px!important;top:12px!important;z-index:5!important;display:grid!important;place-items:center!important;width:42px!important;height:42px!important;border:1px solid var(--line)!important;border-radius:12px!important;background:var(--panel)!important;color:var(--text)!important;font-size:1.2rem!important}
#spotSettingsDialog{width:min(640px,calc(100vw - 24px));max-height:88dvh;border:1px solid var(--line);border-radius:18px;background:var(--panel);color:var(--text)}
#spotSettingsDialog .spot-dialog-head{padding:18px 20px;border-bottom:1px solid var(--line)}
#spotSettingsDialog .spot-dialog-body{padding:20px;overflow:auto}
#spotSettingsDialog .spot-browser-pref{display:flex;align-items:flex-start;gap:12px;padding:16px 0;border-bottom:1px solid var(--line);cursor:pointer}
#spotSettingsDialog .spot-browser-pref span{display:grid;gap:5px}
#spotSettingsDialog .spot-browser-pref input{width:20px;height:20px;flex:none;accent-color:var(--accent)}
#spotSettingsDialog .tag-context-settings-list>details{margin:14px 0;padding:14px;border:1px solid var(--line);border-radius:12px}
#spotSettingsDialog .tag-context-settings-list details details{margin:10px 0 0}
@media(max-width:600px){.spot-source-compact label span{min-height:64px!important;padding:8px!important;font-size:.78rem!important;gap:6px!important}.spot-save-wrap{padding-bottom:175px!important}}
</style>

<section>
  <div class="tag-context-shell">
    <?=tag_unified_tabs_html($tagTab,$initialEventId,0)?>

    <section class="tag-context-hero">
      <button type="button" id="spotSettingsOpen" class="tag-context-settings-btn" aria-label="Scouting settings" title="Scouting settings"><i class="fa-solid fa-gear"></i></button>
      <div class="tag-context-kicker neptune-hero-kicker"><i class="fa-solid fa-crosshairs" aria-hidden="true"></i><span>TRIDENT · OBSERVATION SCOUTING</span></div>
      <h1 class="tag-context-title">Tag <span>Scouting</span></h1>
      <p class="tag-context-sub">
        <?php if($tagTab==='pit'):?>
          Capture quick pit-side intelligence with shared tags, notes, photos, and video. Keep observations fast, specific, and useful for strategy.
        <?php else:?>
          Evaluate alliance candidates, collaboration, readiness, and pick-list priorities. Save separate assessments and review other scouts’ evidence.
        <?php endif;?>
      </p>

      <div class="tag-context-selectors">
        <label>Event
          <select id="spotTeamEvent">
            <option value="">Choose an event</option>
            <?=neptune_event_options_html($events,$initialEventId)?>
          </select>
        </label>
        <select id="spotTeamNumber" aria-label="Selected robot" hidden><option value="">Choose a robot</option></select>
      </div>
      <div class="spot-selected tag-context-selected" id="spotSelected" hidden>
        <div class="spot-robot-identity">
          <span class="spot-selected-avatar" id="spotSelectedAvatar"><i class="fa-solid fa-robot"></i></span>
          <div><div class="spot-selected-number" id="spotSelectedNumber">#—</div><div class="spot-selected-meta" id="spotSelectedMeta"></div></div>
        </div>
        <button class="secondary" type="button" id="spotClearTeam"><i class="fa-solid fa-xmark"></i> Clear</button>
      </div>
    </section>


  </div>

<?php if(!$ready):?>
  <div class="notice bad"><b>Tag Scouting storage could not be initialized.</b> Reload the page or check the server/database error log.</div>
<?php else:?>
  <div class="spot-layout">
    <div class="spot-stack">
      <section class="card spot-card">
        <div class="spot-section-title">
          <div>
            <div class="module-eyebrow"><span><?=strtoupper(e($tagTab))?></span><small>Quick Observation</small></div>
            <h2><?= $tagTab==='pit' ? 'Pit Observation' : 'Alliance Evaluation' ?></h2>
            <div class="spot-help">Choose any tags that apply. A note-only observation is also allowed.</div>
          </div>

        </div>
        <?php if($tagTab==='pit'):?>
        <div class="spot-source-compact" role="group" aria-label="Information source">
          <span class="spot-source-label">Source</span>
          <label><input type="radio" name="pitEvidenceSource" value="observed" checked><span><i class="fa-solid fa-eye"></i> Observed</span></label>
          <label><input type="radio" name="pitEvidenceSource" value="team_reported"><span><i class="fa-solid fa-comments"></i> Team reported</span></label>
        </div>
        <?php endif;?>
        <div class="spot-tag-groups" id="spotTagGroups"></div><div class="spot-help" style="margin:10px 0">Tap to select. Press and hold any tag for its description, when to use it, and why it matters.</div>

        <label for="spotNote" style="margin-top:14px">Optional note</label>
        <textarea id="spotNote" rows="4" placeholder="What did you notice? Keep it specific enough that strategy can use it later."></textarea>

        <div style="margin-top:14px"><b>Photo / video evidence</b><div class="spot-help">Photos are optimized automatically. Videos are stored privately and limited to 100 MB each.</div></div>
        <div class="spot-media-actions" style="margin-top:8px">
          <button type="button" class="secondary" data-file-button="spotPhotos"><i class="fa-solid fa-camera"></i><span class="spot-media-label-full">Take Photo</span><span class="spot-media-label-short">Photo</span></button>
          <button type="button" class="secondary" data-file-button="spotVideos"><i class="fa-solid fa-video"></i><span class="spot-media-label-full">Record Video</span><span class="spot-media-label-short">Video</span></button>
          <button type="button" class="secondary" data-file-button="spotUploads"><i class="fa-solid fa-paperclip"></i><span class="spot-media-label-full">Upload Files</span><span class="spot-media-label-short">Files</span></button>
        </div>
        <input class="spot-hidden-file" type="file" id="spotPhotos" name="photos[]" accept="image/*" capture="environment" multiple>
        <input class="spot-hidden-file" type="file" id="spotVideos" name="videos[]" accept="video/*" capture="environment" multiple>
        <input class="spot-hidden-file" type="file" id="spotUploads" name="uploads[]" accept="image/*,video/mp4,video/quicktime,video/webm,video/x-m4v,video/3gpp" multiple>
        <div class="spot-file-summary" id="spotFileSummary">No attachments selected.</div>

        <div class="spot-save-wrap"><button type="button" id="spotSave" class="good"><i class="fa-solid fa-floppy-disk"></i> Save Observation</button><div class="spot-offline-status" id="spotOfflineStatus" role="status" aria-live="polite">Checking local queue…</div></div>
      </section>
    </div>

    <aside class="spot-stack spot-side">
      <section class="card spot-card">
        <div class="spot-section-title"><div><h2>Robot History</h2><div class="spot-help" id="spotHistoryStatus">Select a robot to see all submitted observations, including entries from other scouts.</div></div><button type="button" class="secondary" id="spotHistoryRefresh" aria-label="Refresh robot history"><i class="fa-solid fa-rotate"></i></button></div>
        <div id="spotRobotHistory" class="spot-feed"><div class="spot-empty">Select a robot to review its history.</div></div>
      </section>

      <section class="card spot-card">
        <div class="spot-section-title"><div><h2>Live Feed</h2><div class="spot-help">Recent observations from your organization.</div></div><button class="secondary" type="button" id="spotRefresh"><i class="fa-solid fa-rotate"></i></button></div>
        <div class="spot-feed" id="spotFeed"></div>
      </section>
      <?php if($canManage):?>
      <section class="card spot-card">
        <div class="spot-section-title"><div><h2>Review Queue</h2><div class="spot-help">Open warning and critical observations that may need follow-up.</div></div><span class="pill spot-review-count" id="spotReviewCount">0</span></div>
        <div class="spot-feed" id="spotReview"></div>
      </section>
      <?php endif;?>
    </aside>
  </div>

  <dialog class="spot-dialog" id="spotSettingsDialog"><div class="spot-dialog-head"><b>Scouting settings</b><button type="button" class="secondary" id="spotSettingsClose" aria-label="Close settings"><i class="fa-solid fa-xmark"></i></button></div><div class="spot-dialog-body"><p class="spot-help">Settings for this browser. Match, Pit, and Alliance use the same preferences and tag reference sections.</p>
    <label class="spot-browser-pref"><input type="checkbox" id="spotRememberStation"><span><strong>Remember alliance station</strong><small>When changing Match robots, keep the same alliance and station.</small></span></label>
    <label class="spot-browser-pref"><input type="checkbox" id="spotTagHaptics"><span><strong>Touch feedback</strong><small>Vibrate briefly on supported devices when selecting a tag.</small></span></label>
    <div class="tag-context-settings-list" id="spotSettingsTagSections"></div></div></dialog>
  <dialog class="spot-dialog" id="spotRobotDetails"><div class="spot-dialog-head"><b id="spotRobotDetailsTitle">Robot information</b><button type="button" class="secondary" id="spotRobotDetailsClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="spot-dialog-body" id="spotRobotDetailsBody"></div></dialog>
  <dialog class="spot-dialog" id="spotTagHelp"><div class="spot-dialog-head"><b id="spotTagHelpTitle">Tag information</b><button type="button" class="secondary" id="spotTagHelpClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="spot-dialog-body"><p id="spotTagHelpDescription"></p><h3>When to use</h3><p id="spotTagHelpWhen"></p><h3>Why it matters</h3><p id="spotTagHelpWhy"></p></div></dialog>
  <div class="spot-floating-robot"><button type="button" id="spotFindTeam" aria-label="Choose event robot" title="Choose an event robot"><span class="spot-floating-avatar" id="spotFloatingAvatar"><i class="fa-solid fa-robot"></i></span><span id="spotFloatingNumber">Robot</span></button></div>
  <dialog class="spot-dialog" id="spotRosterDialog">
    <div class="spot-dialog-head"><div><b><i class="fa-solid fa-robot"></i> Select Event Robot</b><div class="spot-help" id="spotRosterTitle">All robots at the selected event.</div></div><button type="button" class="secondary" id="spotRosterClose" aria-label="Close robot picker"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="spot-dialog-body"><input id="spotRosterFilter" type="search" placeholder="Filter by team number or name" autocomplete="off"><div id="spotRosterGrid" class="spot-roster-picker"></div></div>
  </dialog>
  <dialog class="spot-dialog" id="spotTeamSearchDialog">
    <div class="spot-dialog-head"><div><b>Find a Robot</b><div class="spot-help">Search the selected event roster, or all active rosters when no event is selected.</div></div><button class="secondary" type="button" id="spotCloseSearch"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="spot-dialog-body"><input type="search" id="spotSearchInput" placeholder="Team number, name, city, or country" autocomplete="off"><div class="spot-search-results" id="spotSearchResults"></div></div>
  </dialog>

<script src="<?=e(base_url('scout/tag-offline.js'))?>"></script>
<script>
(() => {
  'use strict';
  const CSRF=<?=json_encode(csrf_token())?>;
  const API=<?=json_encode(base_url('scout/tag-api.php'))?>;
  const CAN_MANAGE=<?=$canManage?'true':'false'?>;
  const tags=<?=json_encode($tags,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const settingsTags=<?=json_encode($settingsCatalog,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let state={
    active_matches:<?=json_encode($active,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    preference:<?=json_encode($pref,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    feed:<?=json_encode($feed,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    review:<?=json_encode($review,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>
  };
  const TAG_TAB=<?=json_encode($tagTab)?>;
  const USER_QUEUE_SCOPE=<?=json_encode($org.':'.$userId)?>;
  const INITIAL_EVENT_ID=<?=json_encode($initialEventId)?>;
  let mode=TAG_TAB;
  let selected={team:0,nickname:'',event_id:0,match_id:0,field_id:0,entry_mode:'auto'};
  let manualMatches=[];
  let pollBusy=false;

  const $=s=>document.querySelector(s);
  const $$=s=>Array.from(document.querySelectorAll(s));
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast=(m,t='info',o={})=>window.NeptuneUI?.toast?.(m,t,o);
  const fmtTime=v=>{if(!v)return '';const d=new Date(String(v).replace(' ','T'));return Number.isNaN(d.getTime())?String(v):d.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});};
  const fieldName=m=>`${m.event_name}${Number(m.field_id)>1?' · Field '+m.field_id:''}`;

  function resetRobotSelection(eventId=Number($('#spotTeamEvent')?.value||0)){
    selected={team:0,nickname:'',event_id:Number(eventId||0),match_id:0,field_id:0,entry_mode:'manual'};
    const select=$('#spotTeamNumber');
    if(select)select.value='';
    if($('#spotSelected'))$('#spotSelected').hidden=true;
    updateRobotIdentity(0,'',null);
    renderRobotHistory([]);
    $$('.spot-team-btn.selected').forEach(btn=>btn.classList.remove('selected'));
  }

  function setMode(next){
    mode=(next==='pit'||next==='team')?next:TAG_TAB;
    resetRobotSelection();
    $$('input[name="spotTag"]').forEach(i=>i.checked=false);
    renderTags();
  }

  let rosterTeams=[];
  function robotAvatar(logo){
    if(typeof logo==='string' && /^(?:\/(?!\/)|https?:\/\/)[^\s]*$/.test(logo))return `<img src="${esc(logo)}" alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode('🤖'))">`;
    return '<i class="fa-solid fa-robot" aria-hidden="true"></i>';
  }
  function updateRobotIdentity(team,nickname,logo){
    const image=robotAvatar(logo);
    $('#spotFloatingAvatar').innerHTML=image;
    $('#spotFloatingNumber').textContent=team?'#'+team:'Robot';
    $('#spotSelectedAvatar').innerHTML=image;
  }
  function chooseTeam(team,meta={}){
    selected.team=Number(team||0);
    selected.nickname=meta.nickname||'';
    if(meta.event_id!==undefined)selected.event_id=Number(meta.event_id||0);
    if(meta.match_id!==undefined)selected.match_id=Number(meta.match_id||0);
    if(meta.field_id!==undefined)selected.field_id=Number(meta.field_id||0);
    if(meta.entry_mode)selected.entry_mode=meta.entry_mode;
    if(!selected.team){$('#spotSelected').hidden=true;renderRobotHistory([]);return;}
    $('#spotSelectedNumber').textContent='#'+selected.team+(selected.nickname?' · '+selected.nickname:'');
    const bits=[];
    if(meta.event_name)bits.push(meta.event_name);
    if(meta.match_label)bits.push(meta.match_label);
    if(meta.location)bits.push(meta.location);
    $('#spotSelectedMeta').textContent=bits.join(' · ') || (mode==='pit'?'Pit observation':'Alliance observation');
    $('#spotSelected').hidden=false;
    updateRobotIdentity(selected.team,selected.nickname,meta.logo||rosterTeams.find(t=>Number(t.team)===selected.team)?.logo||'');
    loadRobotHistory();
    $('.spot-team-btn.selected')?.classList.remove('selected');
    const btn=document.querySelector(`.spot-team-btn[data-team="${selected.team}"][data-match="${selected.match_id}"]`);btn?.classList.add('selected');
  }
  $('#spotClearTeam').addEventListener('click',()=>resetRobotSelection());
  $('#spotTeamNumber').addEventListener('change',e=>{
    const n=Number(e.target.value||0);
    const label=e.target.selectedOptions?.[0]?.textContent||'';
    const nickname=label.includes(' · ')?label.split(' · ').slice(1).join(' · '):'';
    if(n>0)chooseTeam(n,{nickname,event_id:Number($('#spotTeamEvent').value||0),entry_mode:'manual'});
    else resetRobotSelection();
  });
  $('#spotTeamEvent').addEventListener('change',async()=>{
    const eventId=Number($('#spotTeamEvent').value||0);
    // Event changes are a hard context boundary. Never carry a robot from one
    // event into another event's observation context.
    resetRobotSelection(eventId);
    await loadTeamOptions(0);
    await refreshState(true);
  });

  function renderActive(){
    const host=$('#spotActiveMatches');
    if(!host)return;
    const pref=state.preference||{};
    const rows=Array.isArray(state.active_matches)?state.active_matches:[];
    const followCard=$('#spotFollowCard');
    if(!rows.length){
      if(followCard)followCard.hidden=true;
      host.innerHTML='<div class="spot-empty"><i class="fa-solid fa-calendar-xmark"></i>No active or upcoming matches are loaded for an active event.<br><small>Use Choose Match, Pit, or Team to scout manually.</small></div>';
      return;
    }
    if(followCard)followCard.hidden=false;
    host.innerHTML=rows.map(m=>{
      const following=Number(pref.event_id||0)===Number(m.event_id)&&Number(pref.field_id||0)===Number(m.field_id);
      const teamHtml=(m.teams||[]).map(t=>`<button type="button" class="spot-team-btn ${String(t.alliance).toLowerCase()}" data-team="${Number(t.team)}" data-match="${Number(m.id)}" data-event="${Number(m.event_id)}" data-field="${Number(m.field_id)}" data-nickname="${esc(t.nickname||'')}" data-event-name="${esc(m.event_name)}" data-match-label="${esc(m.label)}"><b>#${Number(t.team)}</b><small>${esc(t.nickname||t.alliance+' '+t.station)}</small></button>`).join('');
      return `<div class="spot-match ${following?'following':''}" data-field-card="${Number(m.event_id)}:${Number(m.field_id)}"><div class="spot-match-head"><div><b>${esc(fieldName(m))}</b><div class="spot-match-sub">${esc(m.label)}${m.scheduled_time?' · '+esc(fmtTime(m.scheduled_time)):''}</div></div><span class="spot-state ${esc(m.state)}">${esc(m.state)}</span></div><div class="spot-teams">${teamHtml}</div><button type="button" class="secondary" style="width:100%;margin-top:8px" data-follow-event="${Number(m.event_id)}" data-follow-field="${Number(m.field_id)}"><i class="fa-solid fa-location-crosshairs"></i> ${following?'Following this field':'Follow this field'}</button></div>`;
    }).join('');
    host.querySelectorAll('.spot-team-btn').forEach(btn=>btn.addEventListener('click',()=>{
      const m=rows.find(x=>Number(x.id)===Number(btn.dataset.match));
      chooseTeam(btn.dataset.team,{nickname:btn.dataset.nickname,event_id:btn.dataset.event,match_id:btn.dataset.match,field_id:btn.dataset.field,event_name:btn.dataset.eventName,match_label:btn.dataset.matchLabel,entry_mode:'auto'});
      if($('#spotAutoFollow').checked)savePreference(Number(btn.dataset.event),Number(btn.dataset.field),true,true);
    }));
    host.querySelectorAll('[data-follow-event]').forEach(btn=>btn.addEventListener('click',()=>savePreference(Number(btn.dataset.followEvent),Number(btn.dataset.followField),$('#spotAutoFollow').checked)));
    renderFollowing();
  }

  function renderFollowing(){
    if(!$('#spotAutoFollow')||!$('#spotFollowingTitle')||!$('#spotFollowingSub'))return;
    const p=state.preference||{};
    const m=(state.active_matches||[]).find(x=>Number(x.event_id)===Number(p.event_id||0)&&Number(x.field_id)===Number(p.field_id||0));
    $('#spotAutoFollow').checked=Number(p.auto_follow||0)===1;
    if(m){$('#spotFollowingTitle').textContent='Following: '+fieldName(m);$('#spotFollowingSub').textContent=`${m.label} · ${m.state}. Neptune follows this field independently from other fields/divisions.`;}
    else{$('#spotFollowingTitle').textContent='All active fields';$('#spotFollowingSub').textContent='Tap a field below to follow it on this scout account.';}
  }

  async function savePreference(eventId,fieldId,auto,silent=false){
    try{
      const r=await fetch(API+'?action=preference',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({csrf:CSRF,event_id:eventId,field_id:fieldId,auto_follow:auto?1:0})});
      const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not save field preference.');
      state.preference=d.preference;renderActive();if(!silent)toast('Tag Scouting field preference saved.','good');
    }catch(e){toast(e.message||'Could not save preference.','bad');}
  }
  if($('#spotAutoFollow'))$('#spotAutoFollow').addEventListener('change',()=>{
    const p=state.preference||{};
    if(Number(p.event_id||0)>0)savePreference(Number(p.event_id),Number(p.field_id||1),$('#spotAutoFollow').checked);
  });

  function renderTags(){
    const key=TAG_TAB+'_enabled';
    const visible=tags.filter(t=>Number(t.active)===1&&Number(t[key])===1);
    const groups={};visible.forEach(t=>(groups[t.category]??=[]).push(t));
    const host=$('#spotTagGroups');if(!host)return;
    host.innerHTML=Object.entries(groups).map(([cat,items])=>`<section class="spot-tag-category"><h3>${esc(cat)}</h3><div class="spot-tags">${items.map(t=>`<label class="spot-tag sev-${esc(t.severity)}" data-tag-id="${Number(t.id)}"><input type="checkbox" name="spotTag" value="${Number(t.id)}"><span><i class="${esc(t.icon||'fa-solid fa-tag')}"></i>${esc(t.label)}</span></label>`).join('')}</div></section>`).join('') || '<div class="spot-empty">No tags are configured for this observation type.</div>';
    for(const el of host.querySelectorAll('.spot-tag')){
      let timer=null,held=false,startX=0,startY=0;
      const clear=()=>{if(timer!==null)clearTimeout(timer);timer=null;};
      el.addEventListener('pointerdown',ev=>{if(ev.button!==0&&ev.pointerType==='mouse')return;held=false;startX=ev.clientX;startY=ev.clientY;clear();timer=setTimeout(()=>{held=true;openTagInfo(Number(el.dataset.tagId));},600);});
      el.addEventListener('pointermove',ev=>{if(Math.abs(ev.clientX-startX)>12||Math.abs(ev.clientY-startY)>12)clear();});
      for(const event of ['pointerup','pointercancel','pointerleave'])el.addEventListener(event,clear);
      el.addEventListener('click',ev=>{if(held){ev.preventDefault();ev.stopPropagation();held=false;}},true);
      el.addEventListener('contextmenu',ev=>{ev.preventDefault();clear();openTagInfo(Number(el.dataset.tagId));});
    }
  }
  function openTagInfo(id){
    const t=tags.find(x=>Number(x.id)===id);if(!t)return;
    $('#spotTagHelpTitle').textContent=t.label||'Tag information';
    $('#spotTagHelpDescription').textContent=t.description||'No description has been entered.';
    $('#spotTagHelpWhen').textContent=t.when_to_use||'Ask your strategy team when this tag should be used.';
    $('#spotTagHelpWhy').textContent=t.why_important||'No rationale has been entered.';
    $('#spotTagHelp').showModal();
  }
  $('#spotTagHelpClose').addEventListener('click',()=>$('#spotTagHelp').close());
  // Browser-only settings shared with Match and Alliance.
  function sharedPreference(input,key,fallback){if(!input)return;
    try{input.checked=(localStorage.getItem(key)??(fallback?'1':'0'))==='1';}catch(error){input.checked=fallback;}
    input.addEventListener('change',()=>{try{localStorage.setItem(key,input.checked?'1':'0');}catch(error){}});
  }
  sharedPreference($('#spotRememberStation'),'neptune.tag.rememberStation',true);
  sharedPreference($('#spotTagHaptics'),'neptune.tag.haptics',true);
  $('#spotTagGroups')?.addEventListener('change',event=>{
    if(event.target.matches('input[type="checkbox"]')){
      try{if(localStorage.getItem('neptune.tag.haptics')!=='0'&&navigator.vibrate)navigator.vibrate(8);}catch(error){}
    }
  });
  const settingsHost=$('#spotSettingsTagSections');
  if(settingsHost){settingsHost.replaceChildren();
    for(const [context,title] of [['match','Match'],['pit','Pit'],['team','Alliance']]){
      const section=document.createElement('details');const sum=document.createElement('summary');sum.textContent=title+' tag guide';section.append(sum);
      const groups=new Map();
      for(const t of settingsTags){if(Number(t.active)!==1||Number(t[context+'_enabled'])!==1)continue;
        const category=t.category||'General';if(!groups.has(category))groups.set(category,[]);groups.get(category).push(t);}
      for(const [category,items] of groups){const group=document.createElement('details');const summary=document.createElement('summary');summary.textContent=category+' · '+items.length+' tags';group.append(summary);
        for(const tag of items){const button=document.createElement('button');button.type='button';button.textContent=tag.label;
          button.addEventListener('click',()=>{$('#spotSettingsDialog').close();
            $('#spotTagHelpTitle').textContent=tag.label||'Tag information';
            $('#spotTagHelpDescription').textContent=tag.description||'No description has been entered.';
            $('#spotTagHelpWhen').textContent=tag.when_to_use||'No guidance has been entered.';
            $('#spotTagHelpWhy').textContent=tag.why_important||'No rationale has been entered.';
            $('#spotTagHelp').showModal();});group.append(button);}
        section.append(group);}settingsHost.append(section);
    }
  }
  $('#spotSettingsOpen')?.addEventListener('click',()=>$('#spotSettingsDialog')?.showModal());
  $('#spotSettingsClose')?.addEventListener('click',()=>$('#spotSettingsDialog')?.close());
  $('#spotRobotDetailsClose')?.addEventListener('click',()=>$('#spotRobotDetails')?.close());
  async function showRosterRobotDetails(info){
    const dlg=$('#spotRobotDetails');
    $('#spotRobotDetailsTitle').textContent='#'+info.team+' · '+(info.nickname||'Robot');
    const body=$('#spotRobotDetailsBody');body.replaceChildren();
    if(info.logo){const img=document.createElement('img');img.src=info.logo;img.alt='Team logo';img.style.cssText='width:80px;height:80px;object-fit:contain';body.append(img);}
    const rows=document.createElement('div');body.append(rows);
    function showRow(label,value){if(value===undefined||value===null||String(value).trim()==='')return;
      const row=document.createElement('p');const strong=document.createElement('strong');strong.textContent=label+': ';
      row.append(strong,document.createTextNode(String(value)));rows.append(row);}
    function paint(t){rows.replaceChildren();showRow('Team number',t.team||info.team);showRow('Team name',t.nickname||info.nickname);
      showRow('City',t.city);showRow('State / Province',t.state_prov);showRow('Country',t.country);
      showRow('Rookie year',t.rookie_year);showRow('School / organization',t.school_name);
      showRow('Motto',t.motto);showRow('Full team name',t.full_name);
      if(t.website&&/^https?:\/\//i.test(t.website)){
        const row=document.createElement('p');const a=document.createElement('a');a.href=t.website;a.target='_blank';a.rel='noopener noreferrer';a.textContent='Team website';row.append(a);rows.append(row);
      }
      if(!rows.children.length){const note=document.createElement('p');note.textContent='No public team details are cached yet.';rows.append(note);}
    }
    paint(info);
    const status=document.createElement('p');status.className='spot-help';status.textContent='Loading Team Lookup / TBA information…';body.append(status);
    $('#spotRosterDialog')?.close();dlg.showModal();
    const eventId=Number($('#spotTeamEvent')?.value||0);
    if(!eventId){status.textContent='Select an event to retrieve team details.';return;}
    try{
      const r=await fetch(API+'?action=robot_details&event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(info.team),{headers:{Accept:'application/json'}});
      const data=await r.json();if(!r.ok||data.status!=='success')throw new Error(data.message||'Team details are unavailable');
      if(dlg.open){paint({...info,...data.team});status.textContent='Public TBA information via Neptune Team Lookup cache.';}
    }catch(error){status.textContent='Showing locally available details. More information requires a connection to Neptune/TBA.';}
  }


  async function loadTeamOptions(keepTeam=0){
    const select=$('#spotTeamNumber');
    if(!select)return;
    const eventId=Number($('#spotTeamEvent')?.value||0);
    select.disabled=true;
    select.innerHTML='<option value="">Loading teams…</option>';
    try{
      const r=await fetch(API+'?action=event_teams&event_id='+encodeURIComponent(eventId),{
        headers:{Accept:'application/json','Cache-Control':'no-store'}
      });
      const d=await r.json();
      if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not load teams.');
      const teams=d.teams||[];
      select.innerHTML=(teams.length
        ? '<option value="">Choose a robot</option>'
        : '<option value="">No robots loaded for this event</option>')+teams.map(t=>
        `<option value="${Number(t.team)}">#${Number(t.team)}${t.nickname?' · '+esc(t.nickname):''}</option>`
      ).join('');
      select.disabled=false;

      const wanted=Number(keepTeam||0);
      if(wanted>0){
        const opt=[...select.options].find(o=>Number(o.value)===wanted);
        if(opt)select.value=String(wanted);
      }
    }catch(e){
      select.innerHTML='<option value="">Could not load teams</option>';
      select.disabled=false;
      toast(e.message||'Could not load teams.','bad');
    }
  }

  async function loadManualMatches(){
    const eventId=Number($('#spotManualEvent').value||0),select=$('#spotManualMatch');
    selected={team:0,nickname:'',event_id:eventId,match_id:0,field_id:0,entry_mode:'manual'};$('#spotSelected').hidden=true;$('#spotManualTeams').innerHTML='';
    if(!eventId){select.disabled=true;select.innerHTML='<option value="">Select match</option>';return;}
    select.disabled=true;select.innerHTML='<option>Loading…</option>';
    try{
      const r=await fetch(API+'?action=matches&event_id='+eventId,{headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not load matches.');
      manualMatches=d.matches||[];select.innerHTML='<option value="">Select match</option>'+manualMatches.map(m=>`<option value="${m.id}">${esc(m.label)}${Number(m.field_id)>1?' · Field '+m.field_id:''} · ${esc(m.state)}</option>`).join('');select.disabled=false;
    }catch(e){select.innerHTML='<option value="">Could not load matches</option>';toast(e.message||'Could not load matches.','bad');}
  }
  if($('#spotManualEvent'))$('#spotManualEvent').addEventListener('change',loadManualMatches);
  if($('#spotManualMatch'))$('#spotManualMatch').addEventListener('change',()=>{
    const m=manualMatches.find(x=>Number(x.id)===Number($('#spotManualMatch').value||0));const host=$('#spotManualTeams');
    if(!m){host.innerHTML='';return;}
    selected={team:0,nickname:'',event_id:Number(m.event_id),match_id:Number(m.id),field_id:Number(m.field_id),entry_mode:'manual'};$('#spotSelected').hidden=true;
    host.innerHTML=(m.teams||[]).map(t=>`<button type="button" class="spot-team-btn ${String(t.alliance).toLowerCase()}" data-team="${Number(t.team)}"><b>#${Number(t.team)}</b><small>${esc(t.nickname||t.alliance+' '+t.station)}</small></button>`).join('');
    host.querySelectorAll('.spot-team-btn').forEach(btn=>btn.addEventListener('click',()=>{const t=(m.teams||[]).find(x=>Number(x.team)===Number(btn.dataset.team));chooseTeam(btn.dataset.team,{nickname:t?.nickname||'',event_id:m.event_id,match_id:m.id,field_id:m.field_id,event_name:m.event_name,match_label:m.label,entry_mode:'manual'});}));
  });

  const dlg=$('#spotTeamSearchDialog'),search=$('#spotSearchInput'),results=$('#spotSearchResults');let searchTimer=0;
  function openTeamSearch(){dlg.showModal();search.value='';results.innerHTML='<div class="spot-empty">Start typing a team number, name, city, or country.</div>';setTimeout(()=>search.focus(),50);}
  // v36: full-event roster picker, independent of assigned match robots.
  const rosterDialog=$('#spotRosterDialog'), rosterGrid=$('#spotRosterGrid'), rosterFilter=$('#spotRosterFilter');
  function renderRoster(){
    const q=rosterFilter.value.trim().toLowerCase();
    const rows=rosterTeams.filter(t=>!q||String(t.team).includes(q)||String(t.nickname||'').toLowerCase().includes(q));
    rosterGrid.innerHTML=rows.length?rows.map(t=>`<button type="button" data-roster-team="${Number(t.team)}"><span class="spot-roster-avatar">${robotAvatar(t.logo||'')}</span><b>#${Number(t.team)}</b><small>${esc(t.nickname||'Robot name unavailable')}</small></button>`).join(''):'<div class="spot-empty">No robots found in this event roster.</div>';
  }
  $('#spotFindTeam').addEventListener('click',async()=>{
    if(!Number($('#spotTeamEvent').value||0)){toast('Choose an event first.','warn');$('#spotTeamEvent').focus();return;}
    rosterDialog.showModal();rosterFilter.value='';rosterGrid.innerHTML='<div class="spot-empty">Loading event robots…</div>';
    const eventId=Number($('#spotTeamEvent').value||0);
    $('#spotRosterTitle').textContent='All robots registered for the selected event · '+($('#spotTeamEvent').selectedOptions?.[0]?.textContent||'');
    try{
      const response=await fetch(API+'?action=event_teams&event_id='+encodeURIComponent(eventId),{headers:{Accept:'application/json'},cache:'no-store'});
      const data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not load the roster.');
      rosterTeams=data.teams||[];renderRoster();
    }catch(error){rosterGrid.innerHTML='<div class="spot-empty">'+esc(error.message||'Could not load robots.')+'</div>';}
  });
  $('#spotRosterClose').addEventListener('click',()=>rosterDialog.close());
  rosterFilter.addEventListener('input',renderRoster);
  let rosterHold=false,rosterTimer=null,rosterPointer=null,rosterX=0,rosterY=0;
  rosterGrid.addEventListener('pointerdown',event=>{
    const button=event.target.closest('[data-roster-team]');if(!button)return;
    if(event.pointerType==='mouse'&&event.button!==0)return;
    rosterHold=false;rosterPointer=button;rosterX=event.clientX;rosterY=event.clientY;clearTimeout(rosterTimer);
    rosterTimer=setTimeout(()=>{rosterHold=true;const info=rosterTeams.find(t=>Number(t.team)===Number(button.dataset.rosterTeam));if(info)showRosterRobotDetails(info);},550);
  });
  rosterGrid.addEventListener('pointermove',event=>{if(Math.hypot(event.clientX-rosterX,event.clientY-rosterY)>16)clearTimeout(rosterTimer);});
  ['pointerup','pointercancel','pointerleave'].forEach(type=>rosterGrid.addEventListener(type,()=>clearTimeout(rosterTimer)));
  rosterGrid.addEventListener('contextmenu',event=>{const button=event.target.closest('[data-roster-team]');if(!button)return;event.preventDefault();clearTimeout(rosterTimer);rosterHold=true;const info=rosterTeams.find(t=>Number(t.team)===Number(button.dataset.rosterTeam));if(info)showRosterRobotDetails(info);});
  rosterGrid.addEventListener('click',event=>{
    if(rosterHold){rosterHold=false;return;}
    const button=event.target.closest('[data-roster-team]');if(!button)return;
    const team=Number(button.dataset.rosterTeam),info=rosterTeams.find(t=>Number(t.team)===team);if(!team)return;
    let option=[...$('#spotTeamNumber').options].find(o=>Number(o.value)===team);
    if(!option){option=document.createElement('option');option.value=String(team);option.textContent='#'+team+(info?.nickname?' · '+info.nickname:'');$('#spotTeamNumber').appendChild(option);}
    $('#spotTeamNumber').value=String(team);
    chooseTeam(team,{nickname:info?.nickname||'',logo:info?.logo||'',event_id:Number($('#spotTeamEvent').value||0),entry_mode:'manual'});
    rosterDialog.close();
  });
  // v36: v35 removed this toolbar button; keep legacy binding optional.
  $('#spotQuickFind')?.addEventListener('click',openTeamSearch);
  $('#spotCloseSearch').addEventListener('click',()=>dlg.close());
  search.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(async()=>{
    const q=search.value.trim();if(q.length<1){results.innerHTML='';return;}
    try{const eventId=Number($('#spotTeamEvent').value||0);const r=await fetch(API+'?action=search&q='+encodeURIComponent(q)+'&event_id='+encodeURIComponent(eventId),{headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok)throw new Error(d.message||'Search failed.');const teams=d.teams||[];results.innerHTML=teams.length?teams.map(t=>`<button type="button" class="spot-search-result" data-team="${t.team}" data-name="${esc(t.nickname||'')}" data-location="${esc([t.city,t.state_prov,t.country].filter(Boolean).join(', '))}"><span><b>#${t.team}${t.nickname?' · '+esc(t.nickname):''}</b><small class="muted" style="display:block">${esc([t.city,t.state_prov,t.country].filter(Boolean).join(', '))}</small></span><small>${esc(t.events||'')}</small></button>`).join(''):'<div class="spot-empty">No matching robots in this event roster.</div>';results.querySelectorAll('[data-team]').forEach(b=>b.addEventListener('click',()=>{if(['auto','manual'].includes(mode))setMode('team');const eventId=Number($('#spotTeamEvent').value||0);const team=Number(b.dataset.team);let opt=[...$('#spotTeamNumber').options].find(o=>Number(o.value)===team);if(eventId>0&&!opt){toast('That robot is not on the selected event roster.','warn');return;}if(!opt){opt=document.createElement('option');opt.value=String(team);opt.textContent='#'+team+(b.dataset.name?' · '+b.dataset.name:'');$('#spotTeamNumber').appendChild(opt);}$('#spotTeamNumber').value=String(team);chooseTeam(team,{nickname:b.dataset.name,event_id:eventId,location:b.dataset.location,entry_mode:'manual'});dlg.close();}));}catch(e){results.innerHTML='<div class="spot-empty">'+esc(e.message||'Search failed.')+'</div>';}
  },180);});

  $$('[data-file-button]').forEach(b=>b.addEventListener('click',()=>document.getElementById(b.dataset.fileButton)?.click()));
  ['spotPhotos','spotVideos','spotUploads'].forEach(id=>$('#'+id).addEventListener('change',renderFiles));
  function renderFiles(){
    const files=['spotPhotos','spotVideos','spotUploads'].flatMap(id=>Array.from($('#'+id).files||[]));
    $('#spotFileSummary').textContent=files.length
      ? `${files.length} attachment${files.length===1?'':'s'} · ${files.map(f=>f.name).join(' · ')}`
      : 'No attachments selected.';
  }

  function obsLocation(o){const bits=[];if(o.event_name)bits.push(o.event_name);if(o.context==='match'&&o.match_label)bits.push(o.match_label);else bits.push(o.context==='pit'?'Pit':'Alliance');if(o.field_id&&Number(o.field_id)>1)bits.push('Field '+o.field_id);return bits.join(' · ');}
  function renderObservation(o,review=false){
    const scoreHtml=o.context==='match'&&o.scoring_contribution!==null&&o.scoring_contribution!==undefined&&o.scoring_contribution!==''
      ?`<span class="spot-chip info"><i class="fa-solid fa-chart-pie"></i>${Number(o.scoring_contribution)}% scoring</span>`:'';
    const sourceHtml=o.context==='pit'&&o.evidence_source?`<span class="spot-chip info">${o.evidence_source==='team_reported'?'Team Reported':'Observed'}</span>`:'';
    const tagsHtml=sourceHtml+scoreHtml+(o.tags||[]).map(t=>`<span class="spot-chip ${esc(t.severity)}"><i class="${esc(t.icon||'fa-solid fa-tag')}"></i>${esc(t.label)}</span>`).join('');
    const mediaHtml=(o.media||[]).map(m=>`<a class="spot-media-link" href="${esc(m.url)}" target="_blank" rel="noopener"><i class="fa-solid ${m.media_type==='video'?'fa-video':'fa-camera'}"></i>${m.media_type==='video'?'Video':'Photo'}</a>`).join('');
    const canResolve=review&&CAN_MANAGE&&o.status==='open';
    return `<div class="spot-item ${o.status==='resolved'?'spot-resolved':''}" data-observation="${o.id}"><div class="spot-item-top"><div><div class="spot-item-team">#${o.frc_team_number}</div><div class="spot-item-meta">${esc(obsLocation(o))} · ${esc(o.scout_name||'Scout')} · ${esc(fmtTime(o.created_at))}</div></div><span class="spot-chip ${esc(o.severity)}">${esc(o.status)}</span></div>${tagsHtml?`<div class="spot-item-tags">${tagsHtml}</div>`:''}${o.note?`<div class="spot-item-note">${esc(o.note)}</div>`:''}${mediaHtml?`<div class="spot-media-links">${mediaHtml}</div>`:''}${canResolve?`<button type="button" class="secondary" style="width:100%;margin-top:8px" data-resolve="${o.id}"><i class="fa-solid fa-circle-check"></i> Mark Resolved</button>`:''}</div>`;
  }
  function renderFeed(){
    const currentEvent=Number($('#spotTeamEvent')?.value||0);
    const rows=(state.feed||[]).filter(o=>String(o.context||'')===TAG_TAB&&(!currentEvent||Number(o.event_id||0)===currentEvent)).slice(0,12);
    $('#spotFeed').innerHTML=rows.length?rows.map(o=>renderObservation(o,false)).join(''):`<div class="spot-empty">No ${TAG_TAB==='pit'?'Pit':'Alliance'} observations yet${currentEvent?' for this event':''}.</div>`;
    if(CAN_MANAGE){
      const rev=(state.review||[]).filter(o=>String(o.context||'')===TAG_TAB&&(!currentEvent||Number(o.event_id||0)===currentEvent)).slice(0,12);
      $('#spotReviewCount').textContent=String(rev.length);
      $('#spotReview').innerHTML=rev.length?rev.map(o=>renderObservation(o,true)).join(''):'<div class="spot-empty">Nothing needs review.</div>';
      $('#spotReview').querySelectorAll('[data-resolve]').forEach(b=>b.addEventListener('click',()=>resolveObservation(Number(b.dataset.resolve))));
    }
  }
  async function resolveObservation(id){
    const note=await window.NeptuneUI?.prompt?.('Optional resolution note',{title:'Resolve Tag Observation',placeholder:'Repaired, verified, no longer an issue…',confirmText:'Resolve'});if(note===null||note===undefined)return;
    try{const r=await fetch(API+'?action=resolve',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({csrf:CSRF,observation_id:id,resolution_note:note})});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not resolve observation.');toast('Observation resolved.','good');await refreshState();}catch(e){toast(e.message||'Could not resolve observation.','bad');}
  }

  let historySequence=0, lastOtherHistoryNotice='';
  async function pendingRobotRows(team){
    const jobs=await window.NeptuneTagOffline.pending(USER_QUEUE_SCOPE);
    return jobs.filter(j=>Number(j.frc_team_number)===team&&j.context===TAG_TAB).map(j=>({
      id:'local-'+j.uuid,frc_team_number:team,scout_name:'Saved on this device · awaiting sync',created_at:new Date(j.created).toISOString(),
      tags:(j.tag_ids||[]).map(id=>tags.find(t=>Number(t.id)===Number(id))).filter(Boolean),note:j.note||'',
      context:TAG_TAB,evidence_source:j.evidence_source,media:[],status:'pending',severity:'info'
    }));
  }
  async function renderRobotHistory(rows){
    const host=$('#spotRobotHistory');if(!host)return;
    const team=Number(selected.team||0);
    rows=[...await pendingRobotRows(team),...rows];
    $('#spotHistoryStatus').textContent=team?`${rows.length} saved ${TAG_TAB==='pit'?'Pit':'Alliance'} submission(s) for #${team}. Expand any entry to view it.`:'Select a robot to see saved observations.';
    host.innerHTML=rows.length?rows.map(o=>`<details class="spot-item spot-history-detail"><summary>#${Number(o.frc_team_number)} · ${esc(o.scout_name||'Scout')} · ${esc(fmtTime(o.created_at))} · ${(o.tags||[]).length} tag(s)</summary>${renderObservation(o,false)}</details>`).join(''):(team?'<div class="spot-empty">No synced observations for this robot yet. Other devices may have unsynced entries.</div>':'<div class="spot-empty">Select a robot to view its history.</div>');
  }
  async function loadRobotHistory(){
    const team=Number(selected.team||0),request=++historySequence;
    if(!team){renderRobotHistory([]);return;}
    try{
      const eventId=Number(selected.event_id||$('#spotTeamEvent').value||0);
      const r=await fetch(API+'?action=robot_history&context='+encodeURIComponent(TAG_TAB)+'&team='+team+'&event_id='+eventId,{headers:{Accept:'application/json'},cache:'no-store'});
      if(!r.ok)throw Error('History is unavailable while offline');const d=await r.json();if(d.status!=='success')throw Error(d.message||'History unavailable');
      if(request!==historySequence||team!==Number(selected.team))return;
      renderRobotHistory(d.rows||[]);
      const other=(d.rows||[]).filter(o=>Number(o.created_by)!==Number(<?=json_encode($userId)?>));
      const noticeKey=TAG_TAB+':'+team+':'+other.map(o=>o.id).join(',');
      if(other.length&&lastOtherHistoryNotice!==noticeKey){lastOtherHistoryNotice=noticeKey;toast(`${other.length} previously saved ${TAG_TAB==='pit'?'Pit':'Alliance'} observation(s) by other scouts for #${team}.`,'info');}
    }catch(e){if(request===historySequence){await renderRobotHistory([]);$('#spotHistoryStatus').textContent='Offline: showing locally queued entries only. Shared history refreshes when online.';}}
  }
  $('#spotHistoryRefresh').addEventListener('click',loadRobotHistory);

  async function refreshState(silent=false){if(pollBusy)return;pollBusy=true;try{const eventId=Number($('#spotTeamEvent')?.value||0);const r=await fetch(API+'?action=state&context='+encodeURIComponent(TAG_TAB)+'&event_id='+encodeURIComponent(eventId),{headers:{Accept:'application/json','Cache-Control':'no-store'}});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not refresh Tag Scouting.');state=d;renderFeed();if(selected.team)loadRobotHistory();}catch(e){if(!silent)toast(e.message||'Could not refresh Tag Scouting.','bad');}finally{pollBusy=false;}}
  $('#spotRefresh').addEventListener('click',()=>refreshState(false));

  const TAG_VIDEO_MAX=100*1024*1024;
  const TAG_VIDEO_CHUNK=1024*1024;

  const isVideoFile=file=>{
    const type=String(file?.type||'').toLowerCase();
    if(type.startsWith('video/'))return true;
    return /\.(mp4|mov|webm|m4v|3gp)$/i.test(String(file?.name||''));
  };

  const uniqueFiles=files=>{
    const seen=new Set(),out=[];
    for(const f of files){
      const key=[f.name,f.size,f.lastModified,f.type].join('|');
      if(seen.has(key))continue;
      seen.add(key);out.push(f);
    }
    return out;
  };

  function uploadToken(){
    if(window.crypto?.randomUUID)return window.crypto.randomUUID().replaceAll('-','');
    const bytes=new Uint8Array(24);window.crypto?.getRandomValues?.(bytes);
    return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('') || (Date.now().toString(36)+Math.random().toString(36).slice(2));
  }

  async function uploadVideoInChunks(file,onProgress){
    if(file.size>TAG_VIDEO_MAX)throw new Error(`${file.name} is larger than the 100 MB video limit.`);
    const totalChunks=Math.ceil(file.size/TAG_VIDEO_CHUNK);
    const token=uploadToken();
    for(let i=0;i<totalChunks;i++){
      const start=i*TAG_VIDEO_CHUNK,end=Math.min(file.size,start+TAG_VIDEO_CHUNK);
      const fd=new FormData();
      fd.append('csrf',CSRF);
      fd.append('upload_token',token);
      fd.append('chunk_index',String(i));
      fd.append('total_chunks',String(totalChunks));
      fd.append('file_name',file.name);
      fd.append('file_size',String(file.size));
      fd.append('file_type',file.type||'');
      fd.append('chunk',file.slice(start,end),`chunk-${i}.bin`);

      let lastError=null;
      for(let attempt=0;attempt<3;attempt++){
        try{
          const r=await fetch(API+'?action=upload_chunk',{method:'POST',body:fd,headers:{Accept:'application/json'}});
          const d=await r.json();
          if(!r.ok||d.status!=='success')throw new Error(d.message||'Video upload failed.');
          lastError=null;break;
        }catch(e){
          lastError=e;
          if(attempt<2)await new Promise(resolve=>setTimeout(resolve,500*(attempt+1)));
        }
      }
      if(lastError)throw lastError;
      onProgress?.(Math.round(((i+1)/totalChunks)*100));
    }
    return token;
  }

  $('#spotSave').addEventListener('click',async()=>{
    const team=Number(selected.team||$('#spotTeamNumber').value||0);if(!team){toast('Select a robot first.','warn');return;}
    const context=mode==='pit'?'pit':(mode==='team'?'team':'match');
    if(context==='match'&&!selected.match_id){toast('Select a match and robot first.','warn');return;}

    const photoFiles=uniqueFiles([
      ...Array.from($('#spotPhotos').files||[]),
      ...Array.from($('#spotUploads').files||[]).filter(f=>!isVideoFile(f))
    ]);
    const videoFiles=uniqueFiles([
      ...Array.from($('#spotVideos').files||[]),
      ...Array.from($('#spotUploads').files||[]).filter(isVideoFile)
    ]);
    if(photoFiles.length+videoFiles.length>8){toast('Attach no more than 8 photos/videos to one observation.','warn');return;}
    const tooLarge=videoFiles.find(f=>f.size>TAG_VIDEO_MAX);
    if(tooLarge){toast(`${tooLarge.name} is larger than the 100 MB video limit.`,'warn');return;}

    const btn=$('#spotSave');btn.disabled=true;
    try{
      const observation={uuid:crypto.randomUUID(),context,entry_mode:'manual',event_id:Number(selected.event_id||$('#spotTeamEvent').value||0),frc_team_number:team,
        evidence_source:context==='pit'?(document.querySelector('input[name="pitEvidenceSource"]:checked')?.value||'observed'):'',
        note:$('#spotNote').value||'',tag_ids:$$('input[name="spotTag"]:checked').map(i=>Number(i.value)),
        files:[...photoFiles,...videoFiles].map(f=>({name:f.name,file:f}))};
      if(!observation.tag_ids.length&&!observation.note.trim()&&!observation.files.length){toast('Choose a tag, enter a note, or attach a file.','warn');return;}
      await window.NeptuneTagOffline.enqueue(USER_QUEUE_SCOPE,observation);
      toast('Saved on this device; syncing when the server is available.','good');
      $('#spotNote').value='';$$('input[name="spotTag"]').forEach(i=>i.checked=false);
      ['spotPhotos','spotVideos','spotUploads'].forEach(id=>$('#'+id).value='');renderFiles();
      await flushQueue();await renderQueueStatus();await loadRobotHistory();
    }catch(e){toast('Local save failed: '+(e.message||e),'bad',{title:'Not saved'});}
    finally{btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Observation';}

  });

  let syncing=false;
  async function renderQueueStatus(){
    const n=await window.NeptuneTagOffline.count(USER_QUEUE_SCOPE);
    const node=$('#spotOfflineStatus');node.textContent=n?`${n} observation(s) safely stored on this device and awaiting server confirmation.`:'All locally queued observations have synced.';
    node.dataset.pending=String(n);
  }
  async function flushQueue(){
    if(syncing||!navigator.onLine)return;syncing=true;
    try{
      await window.NeptuneTagOffline.flush(USER_QUEUE_SCOPE,async job=>{
        const fd=new FormData();fd.append('action','save');fd.append('csrf',CSRF);
        for(const key of ['uuid','context','entry_mode','event_id','frc_team_number','evidence_source','note'])fd.append(key,String(job[key]??''));
        for(const id of job.tag_ids||[])fd.append('tag_ids[]',String(id));
        for(const item of job.files||[]){
          const file=item.file;
          if(isVideoFile(file)){
            const token=await uploadVideoInChunks(file);
            fd.append('staged_video_tokens[]',token);
          }else fd.append('photos[]',file,item.name||'photo');
        }
        const r=await fetch(API+'?action=save',{method:'POST',body:fd,headers:{Accept:'application/json'}});
        const d=await r.json();if(!r.ok||d.status!=='success')throw Error(d.message||'Sync rejected');
      });
      await refreshState(true);
    }catch(e){/* Keep queued records until a successful acknowledgement. */}
    finally{syncing=false;await renderQueueStatus();}
  }
  window.addEventListener('online',flushQueue);
  window.addEventListener('pageshow',flushQueue);
  renderQueueStatus().then(flushQueue);
  setInterval(flushQueue,15000);

  const initialEvent=Number(INITIAL_EVENT_ID||state.preference?.event_id||0);
  if(initialEvent>0){
    $('#spotTeamEvent').value=String(initialEvent);
    if($('#spotManualEvent'))$('#spotManualEvent').value=String(initialEvent);
  }
  setMode(TAG_TAB);
  const initialTeam=0; // Never carry the Match robot into Pit or Alliance.
  (async()=>{
    await loadTeamOptions(initialTeam);
    if(initialTeam>0 && Number($('#spotTeamNumber').value||0)===initialTeam){
      chooseTeam(initialTeam,{event_id:Number($('#spotTeamEvent').value||0),entry_mode:'manual'});
    }else if(initialTeam>0){
      resetRobotSelection(Number($('#spotTeamEvent').value||0));
    }
    renderTags();
    renderFeed();
  })();
  window.setInterval(()=>refreshState(true),15000);
})();
</script>
<?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
