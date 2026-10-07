<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_tag_scouting.php';

$u=require_login();
tag_unified_ensure_schema($pdo);
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$ready=tagobs_tables_ready($pdo);
$canManage=tagobs_can_manage($u);
$tags=$ready?tagobs_visible_tags($pdo,$org,false):[];
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
</style>

<section>
  <div class="tag-context-shell">
    <?=tag_unified_tabs_html($tagTab,$initialEventId,(int)($_GET['team']??0))?>

    <section class="tag-context-hero">
      <div class="tag-context-kicker neptune-hero-kicker"><i class="fa-solid fa-crosshairs" aria-hidden="true"></i><span>TRIDENT · OBSERVATION SCOUTING</span></div>
      <h1 class="tag-context-title">Tag <span>Scouting</span></h1>
      <p class="tag-context-sub">
        <?php if($tagTab==='pit'):?>
          Capture quick pit-side intelligence with shared tags, notes, photos, and video. Keep observations fast, specific, and useful for strategy.
        <?php else:?>
          Capture team-level intelligence that is not tied to one match or pit visit. Tag patterns, driver behavior, reliability, strategy notes, and anything worth remembering.
        <?php endif;?>
      </p>

      <div class="tag-context-selectors">
        <label>Event
          <select id="spotTeamEvent">
            <option value="">No specific event</option>
            <?=neptune_event_options_html($events,$initialEventId)?>
          </select>
        </label>

        <div class="tag-context-team-field">
          <label>Robot
            <select id="spotTeamNumber">
              <option value="">Choose a robot</option>
            </select>
          </label>
          <button class="secondary" type="button" id="spotFindTeam"><i class="fa-solid fa-robot"></i> Find Robot</button>
        </div>
      </div>

      <div class="spot-selected tag-context-selected" id="spotSelected" hidden>
        <div>
          <div class="spot-selected-number" id="spotSelectedNumber">#—</div>
          <div class="spot-selected-meta" id="spotSelectedMeta"></div>
        </div>
        <button class="secondary" type="button" id="spotClearTeam"><i class="fa-solid fa-xmark"></i> Clear</button>
      </div>
    </section>

    <div class="tag-context-tools">
      <button class="btn secondary" type="button" id="spotQuickFind"><i class="fa-solid fa-robot"></i> Find Robot</button>
      <?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?>
      <?php if($canManage):?><a class="btn secondary" href="<?=e(base_url('scout/tag-manage-tags.php'))?>"><i class="fa-solid fa-tags"></i> Manage Tags</a><?php endif;?>
      <div class="spot-export-actions">
        <a class="btn secondary" href="<?=e(base_url('scout/tag-export.php?format=csv'))?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <a class="btn secondary" href="<?=e(base_url('scout/tag-export.php?format=json'))?>"><i class="fa-solid fa-code"></i> JSON</a>
      </div>
    </div>
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
            <h2>Observation</h2>
            <div class="spot-help">Choose any tags that apply. A note-only observation is also allowed.</div>
          </div>
          <?php if($canManage):?><a href="<?=e(base_url('scout/tag-manage-tags.php'))?>" class="btn secondary"><i class="fa-solid fa-plus"></i> Add Tag</a><?php endif;?>
        </div>
        <div class="spot-tag-groups" id="spotTagGroups"></div>

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

        <div class="spot-save-wrap"><button type="button" id="spotSave" class="good"><i class="fa-solid fa-floppy-disk"></i> Save Tag Observation</button></div>
      </section>
    </div>

    <aside class="spot-stack spot-side">
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

  <dialog class="spot-dialog" id="spotTeamSearchDialog">
    <div class="spot-dialog-head"><div><b>Find a Robot</b><div class="spot-help">Search the selected event roster, or all active rosters when no event is selected.</div></div><button class="secondary" type="button" id="spotCloseSearch"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="spot-dialog-body"><input type="search" id="spotSearchInput" placeholder="Team number, name, city, or country" autocomplete="off"><div class="spot-search-results" id="spotSearchResults"></div></div>
  </dialog>

<script>
(() => {
  'use strict';
  const CSRF=<?=json_encode(csrf_token())?>;
  const API=<?=json_encode(base_url('scout/tag-api.php'))?>;
  const CAN_MANAGE=<?=$canManage?'true':'false'?>;
  const tags=<?=json_encode($tags,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let state={
    active_matches:<?=json_encode($active,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    preference:<?=json_encode($pref,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    feed:<?=json_encode($feed,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
    review:<?=json_encode($review,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>
  };
  const TAG_TAB=<?=json_encode($tagTab)?>;
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
    $$('.spot-team-btn.selected').forEach(btn=>btn.classList.remove('selected'));
  }

  function setMode(next){
    mode=(next==='pit'||next==='team')?next:TAG_TAB;
    resetRobotSelection();
    $$('input[name="spotTag"]').forEach(i=>i.checked=false);
    renderTags();
  }

  function chooseTeam(team,meta={}){
    selected.team=Number(team||0);
    selected.nickname=meta.nickname||'';
    if(meta.event_id!==undefined)selected.event_id=Number(meta.event_id||0);
    if(meta.match_id!==undefined)selected.match_id=Number(meta.match_id||0);
    if(meta.field_id!==undefined)selected.field_id=Number(meta.field_id||0);
    if(meta.entry_mode)selected.entry_mode=meta.entry_mode;
    if(!selected.team){$('#spotSelected').hidden=true;return;}
    $('#spotSelectedNumber').textContent='#'+selected.team+(selected.nickname?' · '+selected.nickname:'');
    const bits=[];
    if(meta.event_name)bits.push(meta.event_name);
    if(meta.match_label)bits.push(meta.match_label);
    if(meta.location)bits.push(meta.location);
    $('#spotSelectedMeta').textContent=bits.join(' · ') || (mode==='pit'?'Pit observation':'Team observation');
    $('#spotSelected').hidden=false;
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
    const context=mode==='pit'?'pit':(mode==='team'?'team':'match');
    const key=context+'_enabled';
    const visible=tags.filter(t=>Number(t.active)===1&&Number(t[key])===1);
    const groups={};visible.forEach(t=>(groups[t.category]??=[]).push(t));
    const host=$('#spotTagGroups');
    if(!host)return;
    host.innerHTML=Object.entries(groups).map(([cat,items])=>`<div class="spot-tag-category"><h3>${esc(cat)}</h3><div class="spot-tags">${items.map(t=>`<label class="spot-tag sev-${esc(t.severity)}"><input type="checkbox" name="spotTag" value="${Number(t.id)}"><span><i class="${esc(t.icon||'fa-solid fa-tag')}"></i>${esc(t.label)}</span></label>`).join('')}</div></div>`).join('') || '<div class="spot-empty">No tags are configured for this observation type.</div>';
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
  $('#spotFindTeam').addEventListener('click',openTeamSearch);
  $('#spotQuickFind').addEventListener('click',openTeamSearch);
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

  function obsLocation(o){const bits=[];if(o.event_name)bits.push(o.event_name);if(o.context==='match'&&o.match_label)bits.push(o.match_label);else bits.push(o.context==='pit'?'Pit':'Team');if(o.field_id&&Number(o.field_id)>1)bits.push('Field '+o.field_id);return bits.join(' · ');}
  function renderObservation(o,review=false){
    const scoreHtml=o.context==='match'&&o.scoring_contribution!==null&&o.scoring_contribution!==undefined&&o.scoring_contribution!==''
      ?`<span class="spot-chip info"><i class="fa-solid fa-chart-pie"></i>${Number(o.scoring_contribution)}% scoring</span>`:'';
    const tagsHtml=scoreHtml+(o.tags||[]).map(t=>`<span class="spot-chip ${esc(t.severity)}"><i class="${esc(t.icon||'fa-solid fa-tag')}"></i>${esc(t.label)}</span>`).join('');
    const mediaHtml=(o.media||[]).map(m=>`<a class="spot-media-link" href="${esc(m.url)}" target="_blank" rel="noopener"><i class="fa-solid ${m.media_type==='video'?'fa-video':'fa-camera'}"></i>${m.media_type==='video'?'Video':'Photo'}</a>`).join('');
    const canResolve=review&&CAN_MANAGE&&o.status==='open';
    return `<div class="spot-item ${o.status==='resolved'?'spot-resolved':''}" data-observation="${o.id}"><div class="spot-item-top"><div><div class="spot-item-team">#${o.frc_team_number}</div><div class="spot-item-meta">${esc(obsLocation(o))} · ${esc(o.scout_name||'Scout')} · ${esc(fmtTime(o.created_at))}</div></div><span class="spot-chip ${esc(o.severity)}">${esc(o.status)}</span></div>${tagsHtml?`<div class="spot-item-tags">${tagsHtml}</div>`:''}${o.note?`<div class="spot-item-note">${esc(o.note)}</div>`:''}${mediaHtml?`<div class="spot-media-links">${mediaHtml}</div>`:''}${canResolve?`<button type="button" class="secondary" style="width:100%;margin-top:8px" data-resolve="${o.id}"><i class="fa-solid fa-circle-check"></i> Mark Resolved</button>`:''}</div>`;
  }
  function renderFeed(){
    const currentEvent=Number($('#spotTeamEvent')?.value||0);
    const rows=(state.feed||[]).filter(o=>String(o.context||'')===TAG_TAB&&(!currentEvent||Number(o.event_id||0)===currentEvent)).slice(0,12);
    $('#spotFeed').innerHTML=rows.length?rows.map(o=>renderObservation(o,false)).join(''):`<div class="spot-empty">No ${TAG_TAB==='pit'?'Pit':'Team'} observations yet${currentEvent?' for this event':''}.</div>`;
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

  async function refreshState(silent=false){if(pollBusy)return;pollBusy=true;try{const eventId=Number($('#spotTeamEvent')?.value||0);const r=await fetch(API+'?action=state&context='+encodeURIComponent(TAG_TAB)+'&event_id='+encodeURIComponent(eventId),{headers:{Accept:'application/json','Cache-Control':'no-store'}});const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not refresh Tag Scouting.');state=d;renderFeed();}catch(e){if(!silent)toast(e.message||'Could not refresh Tag Scouting.','bad');}finally{pollBusy=false;}}
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

    const btn=$('#spotSave');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
    try{
      const stagedTokens=[];
      for(let i=0;i<videoFiles.length;i++){
        const file=videoFiles[i];
        const token=await uploadVideoInChunks(file,pct=>{
          btn.innerHTML=`<i class="fa-solid fa-spinner fa-spin"></i> Video ${i+1}/${videoFiles.length} · ${pct}%`;
        });
        stagedTokens.push(token);
      }

      btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving observation…';
      const fd=new FormData();
      fd.append('action','save');fd.append('csrf',CSRF);fd.append('context',context);fd.append('entry_mode',mode==='auto'?'auto':'manual');fd.append('event_id',String(selected.event_id||$('#spotTeamEvent').value||0));fd.append('match_id',String(selected.match_id||0));fd.append('frc_team_number',String(team));fd.append('note',$('#spotNote').value||'');
      $$('input[name="spotTag"]:checked').forEach(i=>fd.append('tag_ids[]',i.value));
      for(const f of photoFiles)fd.append('photos[]',f,f.name);
      for(const token of stagedTokens)fd.append('staged_video_tokens[]',token);

      const r=await fetch(API+'?action=save',{method:'POST',body:fd,headers:{Accept:'application/json'}});
      const d=await r.json();if(!r.ok||d.status!=='success')throw new Error(d.message||'Could not save observation.');
      toast('Tag observation saved.','good');$('#spotNote').value='';$$('input[name="spotTag"]').forEach(i=>i.checked=false);['spotPhotos','spotVideos','spotUploads'].forEach(id=>$('#'+id).value='');renderFiles();await refreshState(true);
    }catch(e){
      toast(e.message||'Could not save observation.','bad',{title:'Save failed'});
    }finally{
      btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Tag Observation';
    }
  });

  const initialEvent=Number(INITIAL_EVENT_ID||state.preference?.event_id||0);
  if(initialEvent>0){
    $('#spotTeamEvent').value=String(initialEvent);
    if($('#spotManualEvent'))$('#spotManualEvent').value=String(initialEvent);
  }
  setMode(TAG_TAB);
  const initialTeam=Number(<?=json_encode((int)($_GET['team']??0))?>||0);
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
  window.setInterval(()=>refreshState(true),5000);
})();
</script>
<?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
