<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable.php';

$portableLocal=neptune_portable_is_local($config);
$u=require_role($portableLocal?['owner','admin']:['owner','admin','strategy']);

if(!$portableLocal){
    neptune_portable_ensure_schema($pdo);
}

$pageTitle=$portableLocal?'Portable Organization Server':'Portable Deployment';
$moduleName='SATURN';
$flash=null;
$syncSummary=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');

    if($portableLocal && $action==='resolve_match_tag'){
        try {
            require_once dirname(__DIR__,3).'/neptune_secure/portable-match-tag-resolver.php';
            $out=npp_resolve_match_tag_conflict($pdo,$config,(int)$u['id'],
                (int)($_POST['event_id']??0),(int)($_POST['match_id']??0),
                (int)($_POST['scout_id']??0),(int)($_POST['team']??0),
                (string)($_POST['choice']??''));
            $which=$out['choice']==='keep_aws'?'AWS':'Portable';
            $flash=['type'=>'good','message'=>'Resolved this Match Tag conflict: kept '.$which.'. The choice was audited. Run Download AWS Scouting to verify agreement.'];
        }catch(Throwable $e){
            $flash=['type'=>'bad','message'=>'Conflict resolution did not complete. Neither version should be assumed resolved.','detail'=>$e->getMessage()];
        }
    }elseif($portableLocal && in_array($action,['sync_to_cloud','download_from_cloud','sync_both'],true)){
        // Release the browser session lock while lengthy manual uploads run.
        // Presence and connection-status polling can continue independently.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try{
            // Download FIRST during a two-way sync. Uploading the older portable
            // copy first could overwrite a recently edited cloud observation.
            $prePullSummary=null;
            if($action==='sync_both'){
                require_once dirname(__DIR__,3).'/neptune_secure/portable-sync-pull-client.php';
                $prePullSummary=neptune_portable_pull_cloud($pdo,$config);
                $syncSummary=['download'=>$prePullSummary];
                if((int)($prePullSummary['conflicts']??0)>0){
                    throw new RuntimeException('AWS download identified conflicting tag observations; upload was not started. Review the conflict samples in the report.');
                }
            }
            if($action!=='download_from_cloud'){
            require_once dirname(__DIR__,3).'/neptune_secure/portable-sync-client.php';
            $syncSummary=neptune_portable_sync_to_cloud($pdo,$config);
            if($prePullSummary!==null)$syncSummary['download']=$prePullSummary;
            // The portable client predates manual alliance scores. Send these as a
            // separate event-scoped section; conflicts abort sync rather than overwrite.
            if(neptune_portable_table_exists($pdo,'tag_manual_alliance_scores')){
                $manualSettings=neptune_portable_local_settings($config);
                $manualOrg=(int)$manualSettings['organization_id'];
                $manualEventStmt=$pdo->prepare('SELECT id FROM events WHERE organization_id=? ORDER BY id');
                $manualEventStmt->execute([$manualOrg]);
                foreach($manualEventStmt->fetchAll(PDO::FETCH_COLUMN) as $manualEvent){
                    $manualSettings['event_id']=(int)$manualEvent;
                    $syncSummary['manual_scores'][]=neptune_portable_send_section(
                        $pdo,$manualSettings,'manual_scores',
                        'SELECT s.organization_id,s.event_id,s.match_id,s.red_score,s.blue_score,s.revision,s.updated_by,s.updated_at,
                                m.comp_level AS match_comp_level,m.set_number AS match_set_number,
                                m.match_number,m.field_id AS match_field_id
                         FROM tag_manual_alliance_scores s
                         JOIN matches m ON m.id=s.match_id AND m.organization_id=s.organization_id AND m.event_id=s.event_id
                         WHERE s.organization_id=? AND s.event_id=? ORDER BY s.match_id',
                        [$manualOrg,(int)$manualEvent]
                    );
                }
            }
            // Include manual score sections in the overall completion status.
            foreach(($syncSummary['manual_scores'] ?? []) as $part){
                $syncSummary['totals']['rows_skipped'] += (int)($part['skipped']??0);
                $syncSummary['totals']['rows_sent'] += (int)($part['rows']??0) - (int)($part['unchanged_rows']??0);
                $syncSummary['totals']['rows_unchanged'] += (int)($part['unchanged_rows']??0);
                $syncSummary['totals']['batches_sent'] += (int)($part['batches']??0);
                $syncSummary['totals']['rows_unacknowledged'] += (int)($part['unacknowledged_rows']??0);
            }
            // A missing local media file is not an unacknowledged database row.
            // Retain overall incomplete status, but report the two conditions separately.
            $syncSummary['database_incomplete'] = (int)($syncSummary['totals']['rows_unacknowledged']??0)>0;
            $syncSummary['media_incomplete'] = (int)($syncSummary['totals']['media_skipped']??0)>0;
            $syncSummary['incomplete'] = $syncSummary['database_incomplete'] || $syncSummary['media_incomplete'];
            $syncSummary['ok'] = !$syncSummary['incomplete'];
            // Record only fully successful organization-wide uploads.
            if (!$syncSummary['incomplete']) try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS portable_sync_tracking (organization_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, last_success_utc DATETIME NOT NULL)');
                $st=$pdo->prepare('INSERT INTO portable_sync_tracking (organization_id,last_success_utc) VALUES (?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_success_utc=VALUES(last_success_utc)');
                $st->execute([(int)($config['portable']['organization_id']??0)]);
            } catch (Throwable $trackingError) {
                error_log('Neptune portable sync succeeded but status marker could not be saved: '.$trackingError->getMessage());
            }

            $flash=$syncSummary['database_incomplete']
                ? ['type'=>'bad','message'=>'AWS received sync requests, but some database records were not acknowledged. Review the summary.']
                : ($syncSummary['media_incomplete']
                    ? ['type'=>'warn','message'=>'All database records synchronized. Some local media files are missing or could not be uploaded.']
                    : ['type'=>'good','message'=>'Organization upload completed successfully.']);
            if($action==='sync_both' && !empty($syncSummary['database_incomplete'])){
                throw new RuntimeException('Upload contained unacknowledged database records; AWS download was not started.');
            }
            }
            if($action!=='sync_to_cloud'){
                if($action==='download_from_cloud'){
                    require_once dirname(__DIR__,3).'/neptune_secure/portable-sync-pull-client.php';
                    $pullSummary=neptune_portable_pull_cloud($pdo,$config);
                }else{
                    $pullSummary=$prePullSummary; // never redownload immediately after upload
                }
                if(!is_array($syncSummary))$syncSummary=[];
                $syncSummary['download']=$pullSummary;
                $flash=['type'=>'good','message'=>$action==='sync_both'
                    ? 'Two-way database synchronization completed (AWS download first).'
                    : 'Cloud scouting download completed.'];
                if((int)($pullSummary['conflicts']??0)>0)
                    $flash=['type'=>'warn','message'=>'Cloud scouting downloaded with unresolved observation conflicts. No conflicting local observations were changed.'];
                if(!empty($syncSummary['media_incomplete'])){
                    $flash=['type'=>'warn','message'=>'Two-way database synchronization completed; some original local media files remain missing.'];
                }
            }
        }catch(Throwable $e){
            $flash=['type'=>'bad','message'=>'Organization sync failed.','detail'=>$e->getMessage()];
        }
    }elseif(!$portableLocal && in_array($action,['download_clean','download_organization'],true)){
        try{
            $orgId=(int)($u['organization_id']??0);
            if($orgId<=0)throw new RuntimeException('Your Neptune account is not attached to an organization.');

            $pkg=neptune_portable_build_package(
                $pdo,$config,
                $action==='download_clean'?'clean':'organization',
                (int)$u['id'],
                $action==='download_clean'?null:$orgId,
                null
            );

            header('Content-Type: application/zip');
            header('Content-Length: '.(string)$pkg['bytes']);
            header('Content-Disposition: attachment; filename="'.addslashes($pkg['filename']).'"');
            header('X-Neptune-SHA256: '.$pkg['sha256']);
            header('Cache-Control: no-store, private');
            readfile($pkg['path']);
            @unlink($pkg['path']);
            exit;
        }catch(Throwable $e){
            $flash=['type'=>'bad','message'=>'Portable package could not be built.','detail'=>$e->getMessage()];
        }
    }
}

$portableSettings=$portableLocal&&is_array($config['portable']??null)?$config['portable']:[];
$localOrg=null;
if($portableLocal){
    $orgId=(int)($portableSettings['organization_id']??0);
    if($orgId>0){
        $s=$pdo->prepare('SELECT * FROM organizations WHERE id=?');
        $s->execute([$orgId]);
        $localOrg=$s->fetch()?:null;
    }
}

$lastSyncUtc=null;
if($portableLocal){
    try {
        $st=$pdo->prepare('SELECT last_success_utc FROM portable_sync_tracking WHERE organization_id=?');
        $st->execute([(int)($portableSettings['organization_id']??0)]);
        $lastSyncUtc=$st->fetchColumn()?:null;
    } catch(Throwable $ignored) {} // Tracking begins after the first sync.
}
// Probe only the server-configured HTTPS host; no user-provided URL.
if($portableLocal && isset($_GET['connection_status'])){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    $cloud=(string)($portableSettings['cloud_url']??'');
    $reachable=false;
    if(preg_match('~^https://[^/]+(?:/.*)?$~i',$cloud) && function_exists('curl_init')){
        $handle=curl_init(rtrim($cloud,'/').'/api/portable-sync.php');
        curl_setopt_array($handle,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true]);
        curl_exec($handle);
        $code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
        $reachable=($code>=200 && $code<500); // 401/405 can still mean the cloud answered.
        curl_close($handle);
    }
    echo json_encode(['reachable'=>$reachable,'checked_at_utc'=>gmdate('c')]);
    exit;
}

include dirname(__DIR__).'/partials_header.php';
?>
<style>
.npd{max-width:1280px;margin:0 auto}.npd-hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:18px}
.npd-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.npd-card{padding:22px;border:1px solid var(--line);border-radius:18px;background:var(--panel)}
.npd-card h2{margin:4px 0 8px}.npd-card p{color:var(--muted);line-height:1.6}.npd-card .btn,.npd-card button{margin-top:12px}
.npd-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.npd-step{padding:13px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}
.npd-step b{display:block;margin-bottom:5px;font-size:.84rem}.npd-step span{color:var(--muted);font-size:.78rem;line-height:1.4}
.npd-warning{margin-top:14px;padding:12px 14px;border:1px solid color-mix(in srgb,#f59e0b 42%,var(--line));border-radius:12px;background:color-mix(in srgb,#f59e0b 8%,var(--panel))}
.npd-status{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.npd-stat{padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}
.npd-stat small{display:block;color:var(--muted);margin-bottom:4px}.npd-stat b{overflow-wrap:anywhere}.npd-actions{display:flex;gap:10px;flex-wrap:wrap}.npd-actions form{margin:0}
.npd-sync-details{margin-top:14px}.npd-sync-details pre{max-height:360px;overflow:auto;white-space:pre-wrap;font-size:.75rem}
/* Indeterminate progress: server performs one long POST and does not stream per-batch progress. */
.npd-sync-modal{position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;padding:20px;background:rgba(2,8,21,.78);backdrop-filter:blur(5px)}
.npd-sync-modal[hidden]{display:none!important}
.npd-sync-modal-card{width:min(100%,590px);max-height:calc(100vh - 40px);overflow:auto;box-shadow:0 25px 90px rgba(0,0,0,.6);margin:0!important}
body.npd-sync-modal-open{overflow:hidden}

.npd-sync-progress{margin-top:16px;padding:18px;border:1px solid color-mix(in srgb,var(--accent) 45%,var(--line));border-radius:14px;background:var(--panel2)}
.npd-sync-long-wait[hidden]{display:none!important}
.npd-sync-progress-head{display:flex;align-items:center;gap:14px}
.npd-sync-spinner{width:32px;height:32px;flex:none;border:4px solid var(--line);border-top-color:var(--accent);border-radius:50%;animation:npd-sync-spin .95s linear infinite}
.npd-sync-progress-head strong{display:block;font-size:1rem}
.npd-sync-progress-head p{margin:4px 0 0;line-height:1.5}
.npd-sync-progress-track{height:5px;position:relative;overflow:hidden;margin:15px 0 10px;background:var(--line);border-radius:8px}
.npd-sync-progress-track:after{content:"";position:absolute;top:0;bottom:0;left:-35%;width:35%;border-radius:8px;background:var(--accent);animation:npd-sync-sweep 1.7s ease-in-out infinite}
.npd-sync-progress .npd-sync-progress-meta{margin:0;font-size:.85rem}
.npd-sync-progress .npd-sync-long-wait{margin:8px 0 0;font-size:.86rem;font-weight:650}
@keyframes npd-sync-spin{to{transform:rotate(360deg)}}
@keyframes npd-sync-sweep{to{left:105%}}
@media(prefers-reduced-motion:reduce){.npd-sync-spinner,.npd-sync-progress-track:after{animation:none}.npd-sync-progress-track:after{left:0;width:50%}}
@media(max-width:900px){.npd-grid{grid-template-columns:1fr}.npd-flow{grid-template-columns:1fr 1fr}.npd-status{grid-template-columns:1fr}}
@media(max-width:560px){.npd-flow{grid-template-columns:1fr}.npd-hero{display:block}}
</style>

<section class="module-page npd">
<header class="module-page-header npd-hero">
<div>
<div class="module-code">SATURN</div>
<h1><?=$portableLocal?'Portable Organization Server':'Portable Deployment'?></h1>
<p><?=$portableLocal
?'Run your organization locally over a private LAN with no Internet, then synchronize all event changes back to Neptune when connectivity returns.'
:'Build Neptune for offline Mac, Linux, or Windows (WSL2 beta). Organization packages include your complete Neptune organization and do not require selecting one event.'?></p>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="<?=e(base_url('admin/handoff-import.php'))?>"><i class="fa-solid fa-qrcode"></i> Import Hand-off</a><a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a></div>
</header>

<?php if($flash):?>
<div class="<?=$flash['type']==='warn'?'npd-warning':'notice '.($flash['type']==='bad'?'bad':'good')?>">
<b><?=e($flash['message'])?></b>
<?php if(!empty($flash['detail'])):?><div style="margin-top:5px;white-space:pre-wrap"><?=e($flash['detail'])?></div><?php endif;?>
</div>
<?php endif;?>

<?php if(!$portableLocal):?>
<div class="npd-warning"><b><i class="fa-brands fa-apple"></i> macOS first run</b><br>
If Gatekeeper blocks <code>Install Neptune.command</code>, paste this into Terminal:<br>
<code>xattr -dr com.apple.quarantine ~/Downloads/Neptune_Portable_*</code><br>
Then double-click the installer again. Apple Silicon normally installs Neptune at <code>/opt/homebrew/var/www/Neptune</code>. If MySQL is already installed, Neptune reuses its binaries with a separate private database and does not require the existing MySQL password.</div>
<div class="npd-warning"><b><i class="fa-brands fa-windows"></i> Windows beta:</b> A Windows portable download runs through Ubuntu/WSL2 (not native Windows). Read <code>WINDOWS-SETUP.txt</code> in the generated portable package. Tablet LAN connectivity must be verified before competition use.</div>
<div class="npd-grid">
<article class="npd-card">
<div class="module-eyebrow"><span>PORTABLE · CLEAN</span><small>Safe to distribute</small></div>
<h2>Download Clean Server Package</h2>
<p>Packages the latest running Neptune application, canonical schema, Mac/Linux installers, and Windows WSL2 beta launchers without any organization accounts or scouting data.</p>
<div class="npd-flow">
<div class="npd-step"><b>1 · Unzip</b><span>Extract on Mac, Linux, or Windows.</span></div>
<div class="npd-step"><b>2 · Install</b><span>Run the installer. Neptune installs into the normal web root.</span></div>
<div class="npd-step"><b>3 · Register</b><span>Create the first local organization/Owner.</span></div>
<div class="npd-step"><b>4 · Run</b><span>Use localhost or the LAN address.</span></div>
</div>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="download_clean">
<button type="submit"><i class="fa-solid fa-download"></i> Download Clean Server Package</button>
</form>
</article>

<article class="npd-card">
<div class="module-eyebrow"><span>PORTABLE · ORGANIZATION</span><small>Private snapshot + cloud sync</small></div>
<h2>Download Organization Server</h2>
<p>Creates an offline copy of <strong><?=e((string)($u['organization_name']??'your organization'))?></strong> with all of its events, users, teams, game configuration, schedules, scouting history, pit data, strategy data and referenced uploads.</p>
<div class="npd-flow">
<div class="npd-step"><b>1 · Download</b><span>No event picker. The whole organization is included.</span></div>
<div class="npd-step"><b>2 · Install</b><span>Mac/Linux shell installer, or Windows WSL2 beta launcher.</span></div>
<div class="npd-step"><b>3 · Offline</b><span>Run over a wired router/switch with no WAN connection.</span></div>
<div class="npd-step"><b>4 · Sync</b><span>Reconnect and merge all event changes back to AWS.</span></div>
</div>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="download_organization">
<button type="submit"><i class="fa-solid fa-server"></i> Download Organization Server</button>
</form>
<div class="npd-warning"><b><i class="fa-solid fa-triangle-exclamation"></i> Private package</b><br>
Contains your organization's account password hashes and scouting data. Google-linked users receive new temporary <em>local-only</em> passwords during installation; cloud passwords, Google links, roles and MFA are never changed by sync.</div>
</article>
</div>
<?php else:?>
<div class="npd-grid" id="sync">
<article class="npd-card">
<div class="module-eyebrow"><span>OFFLINE SERVER</span><small><?=e((string)($localOrg['name']??'Organization'))?></small></div>
<h2>Organization Server</h2>
<p>This server is configured for offline field operation. Password login, scouting, pit data, schedules, strategy and local analytics continue to work without Internet.</p>
<div class="npd-status">
<div class="npd-stat"><small>Organization</small><b><?=e((string)($localOrg['name']??'Unknown'))?></b></div>
<div class="npd-stat"><small>Cloud destination</small><b><?=e((string)($portableSettings['cloud_url']??'Not configured'))?></b></div>
<div class="npd-stat"><small>Package created</small><b><?=e((string)($portableSettings['package_created_at']??'Unknown'))?></b></div>
</div>
</article>

<article class="npd-card">
<div class="module-eyebrow"><span>CLOUD SYNC</span><small>Organization-wide</small></div>
<h2>Manual cloud synchronization</h2>
<div class="npd-status" aria-live="polite">
<div class="npd-stat"><small>AWS connection</small><b id="neptune-cloud-status">Checking…</b></div>
<div class="npd-stat"><small>Last successful upload (UTC)</small><b><?=e($lastSyncUtc?($lastSyncUtc.' UTC'):'Not recorded')?></b></div>
<div class="npd-stat"><small>Changes waiting</small><b>Not calculated</b><small>Use Sync after scouting; local changes are not tracked individually yet.</small></div>
</div>
<p id="neptune-sync-guidance">Sync runs only when you press a button; connection availability does not automatically transfer data. Two-way sync uploads first, then imports missing AWS scouting observations into the portable server without overwriting local records.</p>
<script>
(function(){
  const target=document.getElementById('neptune-cloud-status');
  const url=new URL(window.location.href); url.searchParams.set('connection_status','1');
  async function check(){
    try {
      const res=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store'});
      if(!res.ok) throw Error('HTTP '+res.status);
      const status=await res.json();
      target.textContent=status.reachable?'AWS reachable · manual sync only':'AWS unavailable';
    }catch(e){target.textContent='Connection check failed';}
  }
  check();setInterval(check,60000);
})();
</script>

<p>Synchronizes each local event back to the cloud using the organization-bound portable token. Authentication settings, passwords, Google linkage, MFA and user roles are not pushed to AWS.</p>
<div class="npd-sync-buttons" style="display:flex;flex-wrap:wrap;gap:10px"><form method="post" class="neptune-sync-form" id="neptune-sync-form">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="sync_to_cloud">
<button type="submit" id="neptune-sync-submit"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> Upload to AWS</button>
</form>
<form method="post" class="neptune-sync-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="download_from_cloud"><button type="submit"><i class="fa-solid fa-cloud-arrow-down"></i> Download AWS scouting</button></form>
<form method="post" class="neptune-sync-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="sync_both"><button type="submit"><i class="fa-solid fa-rotate"></i> Sync Both Ways</button></form></div>
<p style="font-size:.85rem;margin-top:8px">AWS downloads import missing scouting records and safely reconcile newer cloud tag-observation notes/status/source keys/associations. Competing local and cloud edits are reported instead of overwritten. Sync Both Ways downloads first, then uploads; standalone Upload should only be used when cloud-only edits do not need merging. Pit/pre-scouting, strategy and media downloads remain unsupported.</p>
<div class="npd-sync-modal" id="neptune-sync-progress" role="dialog" aria-modal="true" aria-labelledby="neptune-sync-modal-title" aria-describedby="neptune-sync-modal-description" hidden>
 <div class="npd-sync-progress npd-sync-modal-card" tabindex="-1" id="neptune-sync-modal-card" role="status" aria-live="polite">
  <div class="npd-sync-progress-head">
    <div class="npd-sync-spinner" aria-hidden="true"></div>
    <div>
      <strong id="neptune-sync-modal-title">Sync in progress — please wait</strong>
      <p id="neptune-sync-modal-description">Synchronizing with AWS. Keep this tab open; the first download may examine existing historical records and can take several minutes.</p>
    </div>
  </div>
  <div class="npd-sync-progress-track" aria-hidden="true"></div>
  <p class="npd-sync-progress-meta">Elapsed: <span id="neptune-sync-elapsed" aria-hidden="true">0:00</span> · The animation shows activity, not a percentage complete.</p>
  <p class="npd-sync-long-wait" id="neptune-sync-long-wait" hidden>Still working. Neptune will display the final results when AWS responds. Please don't refresh or click Sync again.</p>
 </div>
</div>
<script>
(function(){
  const forms=Array.from(document.querySelectorAll('form.neptune-sync-form'));
  const buttons=forms.map(f=>f.querySelector('button[type=submit]'));
  const originals=buttons.map(b=>b.innerHTML);
  const panel=document.getElementById('neptune-sync-progress');
  const elapsed=document.getElementById('neptune-sync-elapsed');
  const longWait=document.getElementById('neptune-sync-long-wait');
  if(!forms.length||!panel||!elapsed||!longWait)return;
  let running=false,startedAt=0,timerId=0;
  function tick(){
    const seconds=Math.max(0,Math.floor((Date.now()-startedAt)/1000));
    elapsed.textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');
    if(seconds>=60)longWait.hidden=false;
  }
  function reset(){
    running=false;clearInterval(timerId);
    buttons.forEach((b,i)=>{b.disabled=false;b.innerHTML=originals[i];});
    forms.forEach(f=>f.removeAttribute('aria-busy'));panel.hidden=true;longWait.hidden=true;
    document.body.classList.remove('npd-sync-modal-open');
    delete document.body.dataset.neptuneSyncing;
  }
  forms.forEach(function(form){form.addEventListener('submit',function(event){
    event.preventDefault();
    if(running)return; // Prevent duplicate uploads, including repeated Enter presses.
    running=true;
    buttons.forEach(b=>b.disabled=true);
    form.querySelector('button[type=submit]').textContent='Syncing with AWS…';
    forms.forEach(f=>f.setAttribute('aria-busy','true'));
    document.body.dataset.neptuneSyncing='1';
    panel.hidden=false;
    document.body.classList.add('npd-sync-modal-open');
    const card=document.getElementById('neptune-sync-modal-card');
    if(card)card.focus({preventScroll:true});
    startedAt=Date.now();tick();
    timerId=setInterval(tick,1000);
    // Allow Safari to paint the indicator before starting the original PHP POST.
    requestAnimationFrame(function(){setTimeout(function(){
      HTMLFormElement.prototype.submit.call(form);
    },100)});
  });});
  window.addEventListener('pageshow',function(event){if(event.persisted)reset();});
})();
</script>
<?php if($syncSummary):?>
<?php $matchConflicts=(array)($syncSummary['download']['match_tag_reconciliation']['conflict_samples']??[]); ?>
<?php $conflictCount=max((int)($syncSummary['download']['conflicts']??0),(int)($syncSummary['download']['match_tag_reconciliation']['conflicts']??0),count($matchConflicts)); ?>
<div class="npd-card" id="neptune-sync-report" style="margin-top:14px" role="status" aria-live="polite">
  <h2 style="margin:0 0 12px"<?= $conflictCount>0 ? ' role="alert"' : '' ?>><?= $conflictCount>0
      ? 'Sync needs attention — '.e((string)$conflictCount).' unresolved conflict'.($conflictCount===1?'':'s')
      : (!empty($syncSummary['database_incomplete']) ? 'Database sync incomplete — review required'
      : (!empty($syncSummary['media_incomplete']) ? 'Database synchronized — media needs attention' : 'Sync completed')) ?></h2>
  <?php if($conflictCount>0): ?>
  <p><strong>Action required:</strong> The versions disagree. Open the conflict review dialog before considering the sync complete. Neither version is selected automatically.</p>
  <?php endif; ?>
<?php if($matchConflicts): ?>
<style>
#neptune-conflict-modal{box-sizing:border-box;width:min(92vw,650px);max-height:min(80vh,720px);overflow:auto;margin:auto;padding:24px;border:1px solid #64748b;border-radius:16px;background:#101d2e;color:#f1f5f9;box-shadow:0 20px 75px #000b}
#neptune-conflict-modal::backdrop{background:rgba(0,0,0,.78)}
#neptune-conflict-modal .npd-card{background:#15263c}
#neptune-conflict-modal .npd-actions{display:flex;flex-wrap:wrap;gap:12px}
#neptune-conflict-modal button{cursor:pointer}
#neptune-conflict-modal [hidden]{display:none!important}
#neptune-conflict-modal .npd-card{padding:18px;margin:0!important}
#neptune-conflict-modal .npd-card p{margin:10px 0 16px}
#neptune-conflict-modal .npd-actions button{font-size:0.95rem;padding:10px 14px}
#neptune-conflict-close{flex:0 0 auto;white-space:nowrap}
#neptune-resolution-confirm{background:transparent!important;border:0!important;padding:0!important}
#neptune-resolution-confirm .npd-actions{margin-top:24px}
.neptune-conflict-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.neptune-conflict-modal-header h2{margin:0 0 12px}
#neptune-conflict-close{font-size:1rem;padding:8px 14px}
</style>
<button type="button" id="neptune-conflict-open" onclick="document.getElementById('neptune-conflict-modal').showModal()">Review <?=e((string)$conflictCount)?> unresolved conflict<?= $conflictCount===1?'':'s' ?></button>
<dialog id="neptune-conflict-modal" aria-labelledby="neptune-conflict-title">
<div class="neptune-conflict-modal-header">
<h2 id="neptune-conflict-title">Resolve Match Tag Scouting conflicts</h2>
<button type="button" id="neptune-conflict-close" onclick="document.getElementById('neptune-conflict-modal').close()" aria-label="Close conflict dialog">Close ×</button>
</div>
<p id="neptune-conflict-intro">Choose which copy to retain for each conflicting record. The other copy is preserved in an audit snapshot.</p>
<div id="neptune-resolution-choices">
<?php foreach($matchConflicts as $conflict): ?>
<?php $eid=(int)($conflict['event_id']??0);$mid=(int)($conflict['match_id']??0);$uid=(int)($conflict['scout_id']??0);$team=(int)($conflict['team']??0); ?>
<?php if($eid>0 && $mid>0 && $uid>0 && $team>0 && (string)($conflict['reason']??'')!=='missing_local'): ?>
<div class="npd-card" style="margin:12px 0">
<strong>Event <?=e((string)$eid)?> · <?=e((string)($conflict['match_level']??''))?> <?=e((string)($conflict['match_number']??''))?> · Team <?=e((string)$team)?> · Scout <?=e((string)$uid)?></strong>
<p>Reason: <?=e((string)($conflict['reason']??'different_versions'))?>. Choose only after reviewing each server's scouting screen.</p>
<div class="npd-actions">
<?php foreach(['keep_aws'=>'Keep AWS — copy to Portable','keep_portable'=>'Keep Portable — copy to AWS'] as $choice=>$label): ?>
<form method="post" class="neptune-resolution-form">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="resolve_match_tag">
<input type="hidden" name="event_id" value="<?=e((string)$eid)?>">
<input type="hidden" name="match_id" value="<?=e((string)$mid)?>">
<input type="hidden" name="scout_id" value="<?=e((string)$uid)?>">
<input type="hidden" name="team" value="<?=e((string)$team)?>">
<input type="hidden" name="choice" value="<?=e($choice)?>">
<button type="button" class="neptune-resolution-choose" data-decision-label="<?=e($label)?>"><?=e($label)?></button>
</form>
<?php endforeach; ?>
</div></div>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div id="neptune-resolution-confirm" hidden aria-live="polite">
  <h3 style="margin-top:0">Confirm your choice</h3>
  <p id="neptune-resolution-description"></p>
  <p>Only this record will change. The previous values will be saved in the audit history.</p>
  <div class="npd-actions">
    <button type="button" id="neptune-resolution-cancel">Back</button>
    <button type="button" id="neptune-resolution-apply">Confirm and apply</button>
  </div>
</div>
</dialog>
<?php endif; ?>

  <?php $totals=$syncSummary['totals']??[]; ?>
  <?php if(isset($syncSummary['download'])):?><p><strong>AWS → Portable:</strong> <?=e(number_format((int)$syncSummary['download']['inserted']))?> new records imported · <?=e(number_format((int)($syncSummary['download']['updated']??0)))?> existing tag observations updated · <?=e(number_format((int)$syncSummary['download']['existing']))?> previously present · <?=e((string)$syncSummary['download']['pages'])?> cloud import pages.</p>
<?php endif;?>
  <?php if(isset($syncSummary['events_synced'])):?><p>Events: <?=e((string)($syncSummary['events_synced']??0))?> ·
     Rows sent: <?=e(number_format((int)($totals['rows_sent']??0)))?> ·
     Unchanged rows: <?=e(number_format((int)($totals['rows_unchanged']??0)))?> ·
     Batches sent: <?=e((string)($totals['batches_sent']??0))?> ·
     Skipped: <?=e(number_format((int)($totals['rows_skipped']??0)))?> ·
     <strong>Unacknowledged: <?=e(number_format((int)($totals['rows_unacknowledged']??0)))?></strong> ·
     Media missing/skipped: <?=e((string)($totals['media_skipped']??0))?></p><?php endif;?>
  <?php if(!empty($syncSummary['incomplete'])): ?>
    <?php if(!empty($syncSummary['database_incomplete'])): ?>
    <p>Database batches with unacknowledged rows will be retried on the next manual sync.</p>
    <?php else: ?>
    <p>All database batches were acknowledged. Missing media remains outstanding; another manual sync cannot upload files that are no longer present locally.</p>
    <?php endif; ?>
    <?php foreach(($syncSummary['events']??[]) as $eventReport): foreach(($eventReport['sections']??[]) as $item):
      if((int)($item['unacknowledged_rows']??0)<=0)continue;
      $causes=[];foreach(($item['responses']??[]) as $response){foreach(($response['skip_reasons']??[]) as $reason=>$count){$causes[$reason]=($causes[$reason]??0)+(int)$count;}}
      ?>
      <p>Event <?=e((string)$eventReport['event_id'])?> · <?=e((string)$item['section'])?>:
        <?=e((string)($item['skipped']??0))?> skipped in <?=e((string)($item['unacknowledged_rows']??0))?> unacknowledged rows<?= $causes ? ' (' . e(implode(', ',array_map(static fn($r,$n)=>$r.': '.$n,array_keys($causes),array_values($causes)))) . ')' : '' ?></p>
    <?php endforeach;
      $missing=(int)($eventReport['media']['pit_skipped']??0)+(int)($eventReport['media']['tag_skipped']??0);
      if($missing>0): ?>
      <p>Event <?=e((string)$eventReport['event_id'])?> · Media: <?=e((string)$missing)?> missing, empty, or too large locally</p>
      <?php endif; endforeach; ?>
  <?php endif; ?>
</div>
<details class="npd-sync-details">
<summary>Detailed JSON report</summary>
<pre><?=e(json_encode($syncSummary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
</details>
<?php endif;?>
</article>
</div>
<?php endif;?>
<?php if($syncSummary && $matchConflicts): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('neptune-conflict-modal');
  if (!modal) return;
  var confirmation = document.getElementById('neptune-resolution-confirm');
  var description = document.getElementById('neptune-resolution-description');
  var confirmButton = document.getElementById('neptune-resolution-apply');
  var cancelButton = document.getElementById('neptune-resolution-cancel');
  var pendingForm = null;
  var submitting = false;
  var choicePane = document.getElementById('neptune-resolution-choices');
  var intro = document.getElementById('neptune-conflict-intro');
  var title = document.getElementById('neptune-conflict-title');
  modal.querySelectorAll('.neptune-resolution-choose').forEach(function (button) {
    button.addEventListener('click', function () {
      var form = button.closest('form');
      if (!form || submitting) return;
      var matchCard = form.closest('.npd-card');
      var matchName = matchCard ? matchCard.querySelector('strong') : null;
      pendingForm = form;
      description.textContent = (button.dataset.decisionLabel || button.textContent.trim()) + ' for ' + (matchName ? matchName.textContent.trim() : 'this record') + '?';
      choicePane.hidden = true;
      intro.hidden = true;
      confirmation.hidden = false;
      title.textContent = 'Confirm resolution';
      modal.scrollTop = 0;
      confirmButton.focus({preventScroll:true});
    });
  });
  cancelButton.addEventListener('click', function () {
    confirmation.hidden = true;
    choicePane.hidden = false;
    intro.hidden = false;
    title.textContent = 'Resolve Match Tag Scouting conflicts';
    pendingForm = null;
  });
  confirmButton.addEventListener('click', function () {
    if (!pendingForm || submitting) return;
    submitting = true;
    confirmButton.disabled = true;
    pendingForm.submit();
  });
  modal.addEventListener('close', function () {
    confirmation.hidden = true;
    choicePane.hidden = false;
    intro.hidden = false;
    title.textContent = 'Resolve Match Tag Scouting conflicts';
    pendingForm = null;
  });
  if (typeof modal.showModal === 'function' && !modal.open) modal.showModal();
});
</script>
<?php endif; ?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
