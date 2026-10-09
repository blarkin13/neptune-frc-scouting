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

    if($portableLocal && $action==='sync_to_cloud'){
        try{
            require_once dirname(__DIR__,3).'/neptune_secure/portable-sync-client.php';
            $syncSummary=neptune_portable_sync_to_cloud($pdo,$config);
            // Record only fully successful organization-wide uploads.
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS portable_sync_tracking (organization_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, last_success_utc DATETIME NOT NULL)');
                $st=$pdo->prepare('INSERT INTO portable_sync_tracking (organization_id,last_success_utc) VALUES (?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_success_utc=VALUES(last_success_utc)');
                $st->execute([(int)($config['portable']['organization_id']??0)]);
            } catch (Throwable $trackingError) {
                error_log('Neptune portable sync succeeded but status marker could not be saved: '.$trackingError->getMessage());
            }

            $flash=['type'=>'good','message'=>'Organization sync completed successfully.'];
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
<a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
</header>

<?php if($flash):?>
<div class="notice <?=$flash['type']==='bad'?'bad':'good'?>">
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
<h2>Sync Organization to AWS</h2>
<div class="npd-status" aria-live="polite">
<div class="npd-stat"><small>AWS connection</small><b id="neptune-cloud-status">Checking…</b></div>
<div class="npd-stat"><small>Last successful upload (UTC)</small><b><?=e($lastSyncUtc?($lastSyncUtc.' UTC'):'Not recorded')?></b></div>
<div class="npd-stat"><small>Changes waiting</small><b>Not calculated</b><small>Use Sync after scouting; local changes are not tracked individually yet.</small></div>
</div>
<p id="neptune-sync-guidance">Sync runs only when you press the button. Connection availability does not start an upload.</p>
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
<form method="post">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input type="hidden" name="action" value="sync_to_cloud">
<button type="submit"><i class="fa-solid fa-cloud-arrow-up"></i> Sync Organization to AWS</button>
</form>
<?php if($syncSummary):?>
<details class="npd-sync-details" open>
<summary>Sync report</summary>
<pre><?=e(json_encode($syncSummary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
</details>
<?php endif;?>
</article>
</div>
<?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
