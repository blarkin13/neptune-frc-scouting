<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
$u=require_role(['owner']);
$platformOrgId=max(1,(int)($config['app']['platform_organization_id']??1));
if((int)($u['organization_id']??0)!==$platformOrgId)access_denied('Platform owner access required.');
neptune_require_platform_owner_google($u);
neptune_auth_ensure_schema($pdo);neptune_platform_ensure_schema($pdo);
function npa_redirect(array $params=[]): never{$url=base_url('admin/platform-administration.php');if($params)$url.='?'.http_build_query($params);header('Location: '.$url);exit;}
function npa_flash(string $type,string $message,?string $password=null):void{$_SESSION['npa_flash']=['type'=>$type,'message'=>$message,'password'=>$password];}
function npa_take_flash():?array{$f=$_SESSION['npa_flash']??null;unset($_SESSION['npa_flash']);return is_array($f)?$f:null;}
function npa_temp_password():string{$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';$out='Npt-';for($i=0;$i<14;$i++)$out.=$alphabet[random_int(0,strlen($alphabet)-1)];return $out.'!7';}
function npa_org(PDO $pdo,int $id):?array{$s=$pdo->prepare('SELECT * FROM organizations WHERE id=? LIMIT 1');$s->execute([$id]);return $s->fetch()?:null;}
function npa_audit(PDO $pdo,int $targetOrg,int $actorId,string $action,array $meta=[]):void{neptune_auth_audit($pdo,$targetOrg,$actorId,$action,'organization',(string)$targetOrg,$meta);}
function npa_safe_ident(string $name):string{if(!preg_match('/^[A-Za-z0-9_]+$/',$name))throw new RuntimeException('Unsafe database identifier.');return '`'.$name.'`';}
function npa_export_rows(PDOStatement $stmt,array $redact):void{$first=true;echo '[';while($row=$stmt->fetch(PDO::FETCH_ASSOC)){foreach($redact as $field)unset($row[$field]);if(!$first)echo ',';echo json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$first=false;}echo ']';}
function npa_export_org(PDO $pdo,array $org,array $actor):never{
    @set_time_limit(0);while(ob_get_level()>0)@ob_end_clean();
    $slug=preg_replace('/[^a-z0-9_-]+/i','-',(string)($org['slug']??('org-'.$org['id'])));$slug=trim((string)$slug,'-')?:('org-'.$org['id']);
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="neptune-'.$slug.'-'.gmdate('Ymd-His').'.json"');header('X-Content-Type-Options: nosniff');
    echo '{"format":"neptune-organization-export-v1","exported_at":'.json_encode(gmdate('c')).',"organization":';$safeOrg=$org;unset($safeOrg['suspended_by']);echo json_encode($safeOrg,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);echo ',"redacted_fields":["password_hash","google_sub","token_hash","code_hash","source_ip","ip_address"],"tables":{';
    $redact=['password_hash','google_sub','token_hash','code_hash','source_ip','ip_address'];
    $q=$pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='organization_id' ORDER BY TABLE_NAME");$tables=$q->fetchAll(PDO::FETCH_COLUMN);$firstTable=true;
    foreach($tables as $table){if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$table))continue;if(!$firstTable)echo ',';echo json_encode((string)$table).':';$stmt=$pdo->prepare('SELECT * FROM '.npa_safe_ident((string)$table).' WHERE organization_id=?');$stmt->execute([(int)$org['id']]);npa_export_rows($stmt,$redact);$firstTable=false;}
    $relations=['user_teams'=>"SELECT ut.* FROM user_teams ut JOIN users u ON u.id=ut.user_id WHERE u.organization_id=?",'event_teams'=>"SELECT et.* FROM event_teams et JOIN events e ON e.id=et.event_id WHERE e.organization_id=?",'match_teams'=>"SELECT mt.* FROM match_teams mt JOIN matches m ON m.id=mt.match_id WHERE m.organization_id=?",'sharing_relationships'=>"SELECT sr.* FROM sharing_relationships sr LEFT JOIN teams ot ON ot.id=sr.owner_team_id LEFT JOIN teams rt ON rt.id=sr.recipient_team_id WHERE ot.organization_id=? OR rt.organization_id=?"];
    foreach($relations as $table=>$sql){$exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute([$table]);if(!(int)$exists->fetchColumn())continue;echo ','.json_encode($table).':';$stmt=$pdo->prepare($sql);$params=$table==='sharing_relationships'?[(int)$org['id'],(int)$org['id']]:[(int)$org['id']];$stmt->execute($params);npa_export_rows($stmt,$redact);}
    echo '}}';npa_audit($pdo,(int)$org['id'],(int)$actor['id'],'platform_org_exported',['format'=>'json-v1']);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');$orgId=(int)($_POST['organization_id']??0);$org=npa_org($pdo,$orgId);
    if(!$org){npa_flash('bad','Organization not found.');npa_redirect();}
    try{
        if($action==='suspend'){
            if($orgId===$platformOrgId)throw new RuntimeException('The platform organization cannot be suspended.');$reason=trim((string)($_POST['reason']??''));if($reason==='')$reason='Suspended by platform administrator.';
            $pdo->prepare("UPDATE organizations SET platform_status='suspended',suspended_at=UTC_TIMESTAMP(),suspended_by=?,suspension_reason=? WHERE id=?")->execute([(int)$u['id'],substr($reason,0,255),$orgId]);npa_audit($pdo,$orgId,(int)$u['id'],'platform_org_suspended',['reason'=>$reason]);npa_flash('good',$org['name'].' has been suspended. Existing signed-in users will be blocked on their next authenticated request.');npa_redirect(['org'=>$orgId]);
        }
        if($action==='reactivate'){$pdo->prepare("UPDATE organizations SET platform_status='active',suspended_at=NULL,suspended_by=NULL,suspension_reason=NULL WHERE id=?")->execute([$orgId]);npa_audit($pdo,$orgId,(int)$u['id'],'platform_org_reactivated');npa_flash('good',$org['name'].' has been reactivated.');npa_redirect(['org'=>$orgId]);}
        if($action==='reset_owner'){$ownerId=(int)($_POST['owner_id']??0);$s=$pdo->prepare("SELECT id,display_name,email,active FROM users WHERE id=? AND organization_id=? AND role='owner' LIMIT 1");$s->execute([$ownerId,$orgId]);$owner=$s->fetch();if(!$owner)throw new RuntimeException('Owner account not found in this organization.');if(empty($owner['active']))throw new RuntimeException('Reactivate the owner account before issuing a temporary password.');$password=npa_temp_password();$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1,password_changed_at=NULL WHERE id=? AND organization_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$ownerId,$orgId]);npa_audit($pdo,$orgId,(int)$u['id'],'platform_owner_password_reset',['owner_user_id'=>$ownerId]);npa_flash('good','Temporary password issued for '.$owner['display_name'].'. It is shown once below.',$password);npa_redirect(['org'=>$orgId]);}
        if($action==='clear_google_domain'){
            $previousDomain=trim((string)($org['google_workspace_domain']??''));$previousAuto=(int)($org['google_auto_provision']??0);
            if($previousDomain===''&&$previousAuto===0)throw new RuntimeException('This organization has no Google Workspace domain or automatic user creation to clear.');
            $pdo->prepare('UPDATE organizations SET google_workspace_domain=NULL,google_auto_provision=0 WHERE id=?')->execute([$orgId]);
            npa_audit($pdo,$orgId,(int)$u['id'],'platform_google_domain_cleared',['previous_domain'=>$previousDomain,'previous_auto_provision'=>$previousAuto]);
            npa_flash('good','Google Workspace domain and automatic user creation were cleared for '.$org['name'].'. Existing users keep their accounts and can still sign in.');
            npa_redirect(['org'=>$orgId]);
        }
        if($action==='export')npa_export_org($pdo,$org,$u);
        throw new RuntimeException('Unknown platform administration action.');
    }catch(Throwable $e){npa_flash('bad',$e->getMessage());npa_redirect(['org'=>$orgId]);}
}
$platformTab=((string)($_GET['tab']??'')==='accounts')?'accounts':'organizations';
$selectedOrgId=(int)($_GET['org']??0);
$sql="SELECT o.*,(SELECT COUNT(*) FROM users u2 WHERE u2.organization_id=o.id) user_count,(SELECT COUNT(*) FROM users u2 WHERE u2.organization_id=o.id AND u2.active=1) active_user_count,(SELECT COUNT(*) FROM users u2 WHERE u2.organization_id=o.id AND u2.role='owner' AND u2.active=1) owner_count,(SELECT COUNT(*) FROM teams t WHERE t.organization_id=o.id AND t.active=1) team_count,(SELECT COUNT(*) FROM events e WHERE e.organization_id=o.id) event_count,(SELECT COUNT(*) FROM scouting_actions sa WHERE sa.organization_id=o.id AND sa.deleted_at IS NULL) action_count,NULLIF(GREATEST(COALESCE((SELECT MAX(u3.last_login_at) FROM users u3 WHERE u3.organization_id=o.id),'1970-01-01 00:00:00'),COALESCE((SELECT MAX(a.created_at) FROM audit_log a WHERE a.organization_id=o.id),'1970-01-01 00:00:00'),COALESCE((SELECT MAX(ss.last_seen_at) FROM scout_sessions ss WHERE ss.organization_id=o.id),'1970-01-01 00:00:00'),COALESCE((SELECT MAX(sa2.recorded_at) FROM scouting_actions sa2 WHERE sa2.organization_id=o.id),'1970-01-01 00:00:00')),'1970-01-01 00:00:00') last_activity FROM organizations o ORDER BY CASE WHEN o.id=? THEN 0 ELSE 1 END,o.name";
$s=$pdo->prepare($sql);$s->execute([$platformOrgId]);$organizations=$s->fetchAll();$totalUsers=array_sum(array_map(fn($r)=>(int)$r['user_count'],$organizations));$suspendedCount=count(array_filter($organizations,fn($r)=>strtolower((string)$r['platform_status'])==='suspended'));$activeCount=count($organizations)-$suspendedCount;
$selected=null;$owners=[];$teams=[];$recentAudit=[];
if($selectedOrgId>0){foreach($organizations as $row)if((int)$row['id']===$selectedOrgId){$selected=$row;break;}if($selected){$s=$pdo->prepare("SELECT id,display_name,username,email,active,last_login_at,must_change_password,google_email,google_linked_at FROM users WHERE organization_id=? AND role='owner' ORDER BY active DESC,display_name");$s->execute([$selectedOrgId]);$owners=$s->fetchAll();$s=$pdo->prepare("SELECT id,frc_team_number,COALESCE(NULLIF(display_name,''),NULLIF(nickname,''),CONCAT('Team ',frc_team_number)) display_name,active FROM teams WHERE organization_id=? ORDER BY frc_team_number");$s->execute([$selectedOrgId]);$teams=$s->fetchAll();$s=$pdo->prepare("SELECT action,entity_type,entity_id,created_at,user_id FROM audit_log WHERE organization_id=? ORDER BY created_at DESC,id DESC LIMIT 15");$s->execute([$selectedOrgId]);$recentAudit=$s->fetchAll();}}
$flash=npa_take_flash();$pageTitle='Platform Administration';$moduleName='SATURN';include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* Platform Administration: tenant cards remain readable at any viewport. */
.npa-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.npa-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
.npa-stat{min-width:0;padding:16px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}
.npa-stat b{display:block;font-size:1.65rem}.npa-stat span{color:var(--muted);font-size:.82rem}
.npa-layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(340px,.7fr);align-items:start;gap:16px}
.npa-layout.no-selection{grid-template-columns:minmax(0,1fr)}
.npa-layout > .card{min-width:0}
.npa-org-list{display:grid;gap:10px}
.npa-org{display:grid;grid-template-columns:minmax(0,1.65fr) repeat(4,minmax(0,.48fr)) auto;gap:10px;align-items:center;min-width:0;padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel2);color:var(--text);text-decoration:none;transition:border-color .16s,background .16s}
.npa-org:hover,.npa-org:focus-visible{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 6%,var(--panel2));text-decoration:none}
.npa-org:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.npa-org.selected{border-color:var(--accent);box-shadow:inset 3px 0 0 var(--accent)}
.npa-org-name{display:flex;gap:11px;align-items:center;min-width:0}
.npa-org-identity{min-width:0;flex:1 1 auto;overflow-wrap:anywhere}
.npa-org-logo{width:44px;height:44px;border:1px solid var(--line);border-radius:12px;background:#fff;display:grid;place-items:center;overflow:hidden;flex:0 0 44px}
.npa-org-logo img{width:100%;height:100%;object-fit:contain;padding:5px}.npa-org-logo i{color:#64748b}
.npa-org-name strong{display:block;line-height:1.25;overflow-wrap:anywhere}
.npa-org-name small{display:block;color:var(--muted);overflow-wrap:anywhere;line-height:1.35;margin-top:3px}
.npa-metric{min-width:0}.npa-metric strong{display:block;font-size:.86rem;overflow-wrap:anywhere}.npa-metric small{color:var(--muted);font-size:.75rem}
.npa-org-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;min-width:0;flex-wrap:wrap}
.npa-org-action{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel);color:var(--text);font-weight:800;font-size:.82rem;white-space:nowrap}
.npa-status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:.76rem;font-weight:900;border:1px solid var(--line);white-space:nowrap}
.npa-status.active{color:var(--good)}.npa-status.suspended{color:var(--bad)}
.npa-details{scroll-margin-top:112px}
.npa-detail-head{display:flex;gap:12px;align-items:center;min-width:0}.npa-detail-head>div:last-child{min-width:0}
.npa-detail-head h2{overflow-wrap:anywhere}
.npa-detail-logo{width:58px;height:58px;border-radius:16px;background:#fff;border:1px solid var(--line);display:grid;place-items:center;overflow:hidden;flex:0 0 58px}
.npa-detail-logo img{width:100%;height:100%;object-fit:contain;padding:6px}
.npa-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}
.npa-detail-box{min-width:0;padding:12px;border:1px solid var(--line);border-radius:13px;background:var(--panel2)}
.npa-detail-box b{display:block;overflow-wrap:anywhere}.npa-detail-box span{font-size:.78rem;color:var(--muted)}
.npa-owner{min-width:0;padding:12px;border:1px solid var(--line);border-radius:14px;background:var(--panel2);margin-top:9px}
.npa-owner-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}
.npa-owner-top>div{min-width:0;overflow-wrap:anywhere}
.npa-owner small{display:block;color:var(--muted);margin-top:3px;overflow-wrap:anywhere}
.npa-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.npa-actions>*{max-width:100%}
.npa-danger{border-color:color-mix(in srgb,var(--bad) 45%,var(--line));color:var(--bad)}
.npa-team-list{display:flex;gap:7px;flex-wrap:wrap}.npa-team-list .pill{max-width:100%;white-space:normal;overflow-wrap:anywhere}
.npa-audit{display:grid;gap:8px;margin-top:10px}.npa-audit-row{padding:9px 10px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);font-size:.82rem;overflow-wrap:anywhere}.npa-audit-row small{display:block;color:var(--muted)}
.npa-directory-intro{max-width:55ch}
/* Tablets get a single column but keep a compact, legible card grid. */
@media(max-width:1100px){
 .npa-layout{grid-template-columns:minmax(0,1fr)}
 .npa-org{grid-template-columns:minmax(0,1.65fr) repeat(3,minmax(0,.5fr)) auto}
 .npa-org .hide-mid{display:none}
}
@media(max-width:700px){
 .npa-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
 .npa-stat{padding:12px}.npa-stat b{font-size:1.45rem}
 .npa-layout{gap:12px}
 /* Show selected management controls before the long directory. */
 .npa-layout.has-selection>.npa-details{order:-1}
 .npa-layout>.card{padding:16px}
 .npa-org{grid-template-columns:minmax(0,1fr);gap:10px;padding:13px}
 .npa-org .npa-metric{display:none}
 .npa-org-actions{justify-content:space-between;border-top:1px solid var(--line);padding-top:10px}
 .npa-org-name strong{font-size:.97rem}
 .npa-org-action{min-height:36px}
 .npa-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
 .npa-detail-box{padding:10px}
 .npa-owner-top{flex-wrap:wrap}
 .npa-head>.toolbar{max-width:100%}
}
@media(max-width:375px){
 .npa-detail-grid{grid-template-columns:minmax(0,1fr)}
 .npa-org-logo{width:40px;height:40px;flex-basis:40px}
 .npa-org-actions{gap:6px}
}
</style>
<style>
.npa-tabs{display:flex;align-items:stretch;flex-wrap:wrap;gap:7px;margin:15px 0 18px;border-bottom:1px solid var(--line)}
.npa-tabs a{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border:1px solid var(--line);border-bottom:0;border-radius:11px 11px 0 0;background:var(--panel2);color:var(--muted);text-decoration:none;font-weight:800}
.npa-tabs a.selected{color:var(--text);background:var(--panel);box-shadow:inset 0 3px var(--accent,#55bdff)}
.npa-tabs a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.npa-account-panel{padding:clamp(12px,2vw,20px);overflow:hidden}
.npa-account-panel .naa{max-width:none;padding:0}
@media(max-width:530px){.npa-tabs a{flex:1 1 100%;border-bottom:1px solid var(--line);border-radius:9px}}
</style>
<section class="module-page command-page"><header class="module-page-header npa-head"><div><div class="module-code">SATURN · PLATFORM</div><h1>Platform Administration</h1><p>Installation-wide organization oversight, owner recovery, tenant status, activity, and data export.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a></div></header>
<?php if($flash):?><div class="notice <?=$flash['type']==='bad'?'bad':'good'?>" style="margin-bottom:14px"><b><?=e($flash['message'])?></b><?php if(!empty($flash['password'])):?><div style="margin-top:8px">Temporary password — shown once: <code style="user-select:all;font-size:1rem"><?=e($flash['password'])?></code></div><?php endif;?></div><?php endif;?>
<nav class="npa-tabs" role="tablist" aria-label="Platform administration areas">
<a role="tab" aria-selected="<?=$platformTab==='organizations'?'true':'false'?>" class="<?=$platformTab==='organizations'?'selected':''?>" href="?tab=organizations"><i class="fa-solid fa-building" aria-hidden="true"></i> Organizations</a>
<a role="tab" aria-selected="<?=$platformTab==='accounts'?'true':'false'?>" class="<?=$platformTab==='accounts'?'selected':''?>" href="?tab=accounts"><i class="fa-solid fa-user-clock" aria-hidden="true"></i> Account Access &amp; Activity</a>
</nav>
<?php if($platformTab==='accounts'):?>
<div class="card npa-account-panel"><?php define('NEPTUNE_PLATFORM_ACCOUNT_PANEL',true); include __DIR__.'/_platform-account-activity.php'; ?></div>
<?php else:?>
<div class="npa-summary"><div class="npa-stat"><b><?=count($organizations)?></b><span>Organizations</span></div><div class="npa-stat"><b><?=$activeCount?></b><span>Active organizations</span></div><div class="npa-stat"><b><?=$suspendedCount?></b><span>Suspended organizations</span></div><div class="npa-stat"><b><?=$totalUsers?></b><span>Total Neptune users</span></div></div>
<div class="npa-layout <?=$selected?'has-selection':'no-selection'?>"><div class="card npa-directory"><div class="module-eyebrow"><span>ORGANIZATIONS</span><small>All tenants</small></div><h2 style="margin-top:5px">Organization Directory</h2><p class="muted">Tap an organization below to manage its owners, teams, access, and recent activity.</p><div class="npa-org-list">
<?php foreach($organizations as $o):$status=strtolower((string)($o['platform_status']??'active'));$logo=trim((string)($o['logo_path']??''));?><a class="npa-org <?=$selectedOrgId===(int)$o['id']?'selected':''?>" href="<?=e(base_url('admin/platform-administration.php').'?org='.(int)$o['id'].'#npa-org-details')?>" <?=$selectedOrgId===(int)$o['id']?'aria-current="true"':''?>><div class="npa-org-name"><div class="npa-org-logo neptune-org-logo-frame"><?php if($logo!==''):?><img class="neptune-org-logo-image" src="<?=e(base_url($logo))?>" alt=""><?php else:?><i class="fa-solid fa-building"></i><?php endif;?></div><div class="npa-org-identity"><strong><?=e($o['name'])?></strong><small><?=e($o['slug'])?> · ID <?=(int)$o['id']?> <?=(int)$o['id']===$platformOrgId?'· Platform organization':''?></small></div></div><div class="npa-metric"><strong><?=(int)$o['owner_count']?></strong><small>owners</small></div><div class="npa-metric"><strong><?=(int)$o['user_count']?></strong><small>users</small></div><div class="npa-metric hide-mid"><strong><?=(int)$o['team_count']?></strong><small>teams</small></div><div class="npa-metric"><strong><?=e($o['last_activity']?:'Never')?></strong><small>last activity</small></div><div class="npa-org-actions"><span class="npa-status <?=$status?>"><i class="fa-solid <?=$status==='suspended'?'fa-circle-pause':'fa-circle-check'?>"></i> <?=e(ucfirst($status))?></span><span class="npa-org-action"><?=$selectedOrgId===(int)$o['id']?'Selected':'Manage'?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span></div></a><?php endforeach;?></div></div>
<?php if($selected):$status=strtolower((string)($selected['platform_status']??'active'));$logo=trim((string)($selected['logo_path']??''));?><aside class="card npa-details" id="npa-org-details"><div class="npa-detail-head"><div class="npa-detail-logo neptune-org-logo-frame"><?php if($logo!==''):?><img class="neptune-org-logo-image" src="<?=e(base_url($logo))?>" alt=""><?php else:?><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><?php endif;?></div><div><div class="module-eyebrow"><span>ORGANIZATION</span><small>ID <?=(int)$selected['id']?></small></div><h2 style="margin:3px 0"><?=e($selected['name'])?></h2><span class="npa-status <?=$status?>"><?=e(ucfirst($status))?></span></div></div><div class="npa-detail-grid"><div class="npa-detail-box"><b><?=(int)$selected['user_count']?> / <?=(int)$selected['active_user_count']?></b><span>Total / active users</span></div><div class="npa-detail-box"><b><?=(int)$selected['team_count']?></b><span>Active FRC teams</span></div><div class="npa-detail-box"><b><?=(int)$selected['event_count']?></b><span>Events</span></div><div class="npa-detail-box"><b><?=number_format((int)$selected['action_count'])?></b><span>Scouting actions</span></div><div class="npa-detail-box"><b><?=e($selected['last_activity']?:'Never')?></b><span>Last activity</span></div><div class="npa-detail-box"><b><?=e(neptune_time($selected['created_at']??'')?:'—')?></b><span>Created</span></div></div>
<?php if($status==='suspended'):?><div class="notice bad" style="margin-top:12px"><b>Suspended</b><div><?=e($selected['suspension_reason']?:'No reason recorded.')?></div><?php if($selected['suspended_at']):?><small>Since <?=e($selected['suspended_at'])?></small><?php endif;?></div><?php endif;?><h3 style="margin:18px 0 6px">Owners</h3><?php if(!$owners):?><div class="notice bad">No owner accounts found. This organization requires platform attention.</div><?php endif;?><?php foreach($owners as $owner):?><div class="npa-owner"><div class="npa-owner-top"><div><b><?=e($owner['display_name'])?></b><small>@<?=e($owner['username'])?><?=!empty($owner['email'])?' · '.e($owner['email']):''?></small><small>Last login: <?=e($owner['last_login_at']?:'Never')?><?=!empty($owner['google_email'])?' · Google linked':''?></small></div><span class="pill"><?=$owner['active']?'Active':'Inactive'?></span></div><form method="post" style="margin-top:9px" data-confirm="Issue a new temporary password for this owner? Their current Neptune password will stop working." data-confirm-title="Reset owner password" data-confirm-button="Issue password" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reset_owner"><input type="hidden" name="organization_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="owner_id" value="<?=(int)$owner['id']?>"><button class="secondary" <?=$owner['active']?'':'disabled'?>><i class="fa-solid fa-key"></i> Reset Owner Password</button></form></div><?php endforeach;?><h3 style="margin:18px 0 6px">Teams</h3><div class="npa-team-list"><?php if(!$teams):?><span class="muted">No teams configured.</span><?php else:?><?php foreach($teams as $team):?><span class="pill">#<?=e($team['frc_team_number'])?> <?=e($team['display_name'])?></span><?php endforeach;?><?php endif;?></div>
<div class="npa-actions"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="export"><input type="hidden" name="organization_id" value="<?=(int)$selected['id']?>"><button class="secondary"><i class="fa-solid fa-file-export"></i> Export Organization Data</button></form><?php if((int)$selected['id']!==$platformOrgId):?><?php if($status==='active'):?><form method="post" data-confirm="Suspend this organization? Existing signed-in users will lose access on their next request." data-confirm-title="Suspend organization" data-confirm-button="Suspend" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="suspend"><input type="hidden" name="organization_id" value="<?=(int)$selected['id']?>"><label class="muted" style="display:block;font-size:.8rem;margin-bottom:6px">Reason for suspension<input type="text" name="reason" value="Platform administrator suspension" maxlength="255" required style="display:block;width:100%;margin-top:3px"></label><button class="secondary npa-danger"><i class="fa-solid fa-ban"></i> Suspend Organization</button></form><?php else:?><form method="post" data-confirm="Reactivate this organization?" data-confirm-title="Reactivate organization" data-confirm-button="Reactivate"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="organization_id" value="<?=(int)$selected['id']?>"><button><i class="fa-solid fa-circle-play"></i> Reactivate Organization</button></form><?php endif;?><?php endif;?><?php $npaGoogleDomain=trim((string)($selected['google_workspace_domain']??''));if($npaGoogleDomain!==''||!empty($selected['google_auto_provision'])):?><form method="post" data-confirm="Clear the Google Workspace domain<?=$npaGoogleDomain!==''?' ('.e($npaGoogleDomain).')':''?> and turn off automatic user creation for this organization? Existing users keep their accounts." data-confirm-title="Clear Workspace domain" data-confirm-button="Clear domain" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="clear_google_domain"><input type="hidden" name="organization_id" value="<?=(int)$selected['id']?>"><button class="secondary npa-danger"><i class="fa-solid fa-eraser"></i> Clear Google Domain<?=$npaGoogleDomain!==''?' ('.e($npaGoogleDomain).')':''?></button></form><?php endif;?></div><h3 style="margin:18px 0 6px">Recent Activity</h3><div class="npa-audit"><?php if(!$recentAudit):?><span class="muted">No audit activity recorded.</span><?php endif;?><?php foreach($recentAudit as $a):?><div class="npa-audit-row"><b><?=e($a['action'])?></b><small><?=e(neptune_time($a['created_at']))?><?=!empty($a['entity_type'])?' · '.e($a['entity_type']):''?></small></div><?php endforeach;?></div></aside><?php endif;?></div><?php endif;?></section><?php include dirname(__DIR__).'/partials_footer.php';
