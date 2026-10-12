<?php
/** Neptune Event Operations v42 — management UI, non-destructive organization-team assignments. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];$canConfig=in_array((string)$u['role'],['owner','admin'],true);
$pageTitle='Event Operations';$moduleName='SATURN';$msg='';$error='';
$selectedId=(int)($_POST['event_id']??$_GET['event_id']??0);
function eo_h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function eo_stage_label(string $v): string {return ['planned'=>'Planned','pit_open'=>'Pit Open','schedule_ready'=>'Schedule Ready','running'=>'Running','complete'=>'Complete'][$v]??ucwords(str_replace('_',' ',$v));}
function eo_stage_class(string $v): string {return in_array($v,['planned','pit_open','schedule_ready','running','complete'],true)?'eo-stage-'.str_replace('_','-',$v):'eo-stage-planned';}
function eo_event(PDO $pdo,int $org,int $id): array {
 $s=$pdo->prepare('SELECT e.*,g.name game_name,g.season_year,gr.revision_number revision_number FROM events e JOIN games g ON g.id=e.game_id LEFT JOIN game_revisions gr ON gr.id=e.game_revision_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
 $s->execute([$id,$org]);return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function eo_games(PDO $pdo,int $org): array {
 $s=$pdo->prepare('SELECT DISTINCT g.id,g.name,g.season_year,g.current_revision_id FROM games g LEFT JOIN game_config_shares sh ON sh.game_id=g.id AND sh.recipient_organization_id=? WHERE g.is_archived=0 AND (g.organization_id=? OR sh.id IS NOT NULL) AND g.current_revision_id IS NOT NULL ORDER BY g.season_year DESC,g.name');$s->execute([$org,$org]);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function eo_revision(PDO $pdo,int $game): int {
 $s=$pdo->prepare("SELECT id FROM game_revisions WHERE game_id=? AND status='published' ORDER BY revision_number DESC LIMIT 1");$s->execute([$game]);return (int)$s->fetchColumn();
}
$allowed=['planned','pit_open','schedule_ready','running','complete'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$op=(string)($_POST['op']??'');
 try{
  if($op==='create'){
   $game=(int)($_POST['game_id']??0);$name=trim((string)($_POST['name']??''));if($name===''||$game<1)throw new RuntimeException('Event name and game are required.');
   $accessible=array_map('intval',array_column(eo_games($pdo,$org),'id'));if(!in_array($game,$accessible,true))throw new RuntimeException('Game is not available.');
   $revision=eo_revision($pdo,$game);if(!$revision)throw new RuntimeException('Game needs a published revision.');
   $s=$pdo->prepare("INSERT INTO events (organization_id,game_id,game_revision_id,name,start_date,end_date,tba_event_key,event_code,active,event_status,is_current) VALUES (?,?,?,?,?,?,?,?,1,'planned',0)");
   $s->execute([$org,$game,$revision,$name,($_POST['start_date']??'')?:null,($_POST['end_date']??'')?:null,trim((string)($_POST['tba_event_key']??''))?:null,trim((string)($_POST['event_code']??''))?:null]);
   $selectedId=(int)$pdo->lastInsertId();$msg='Event created. Import the competition roster next; organization teams on the roster will be recognized automatically.';
  }else{
   $event=eo_event($pdo,$org,$selectedId);if(!$event)throw new RuntimeException('Event does not belong to your organization.');
   if($op==='archive' || $op==='unarchive'){
    if(!$canConfig)throw new RuntimeException('Owner/Admin required to archive or unarchive events.');
    if($op==='archive' && (int)$event['is_current']===1)throw new RuntimeException('Choose another Default Current Event before archiving this event.');
    $target=$op==='archive'?0:1;
    $s=$pdo->prepare('UPDATE events SET active=? WHERE id=? AND organization_id=?');$s->execute([$target,$selectedId,$org]);
    if(!$target){try{$pdo->prepare('UPDATE neptune_event_live_state SET enabled=0 WHERE organization_id=? AND event_id=?')->execute([$org,$selectedId]);}catch(Throwable $ignored){}}
    $msg=$target?'Event unarchived. All existing scouting history is preserved.':'Event archived. All existing scouting history is preserved.';
   }elseif($op==='status'){
    $status=(string)($_POST['status']??'');if(!(int)$event['active'])throw new RuntimeException('Unarchive the event before changing its stage.');if(!in_array($status,$allowed,true))throw new RuntimeException('Invalid event status.');
    $s=$pdo->prepare('UPDATE events SET event_status=? WHERE id=? AND organization_id=?');$s->execute([$status,$selectedId,$org]);$msg='Event stage updated.';
   }elseif($op==='details'){
    if(!$canConfig)throw new RuntimeException('Owner/Admin required to edit event details.');
    $name=trim((string)($_POST['name']??''));if(!$name)throw new RuntimeException('Event name required.');
    $start=($_POST['start_date']??'')?:null;$end=($_POST['end_date']??'')?:null;
    if($start&&$end&&$end<$start)throw new RuntimeException('End date cannot precede start date.');
    $s=$pdo->prepare('UPDATE events SET name=?,start_date=?,end_date=? WHERE id=? AND organization_id=?');$s->execute([$name,$start,$end,$selectedId,$org]);$msg='Event details saved.';
   }elseif($op==='current'){
    if(!(int)$event['active'])throw new RuntimeException('Unarchive the event before making it current.');
    // Existing convention: Current Event is organization-wide, and only one at a time.
    $pdo->beginTransaction();$s=$pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?');$s->execute([$org]);$s=$pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?");$s->execute([$selectedId,$org]);$pdo->commit();$msg='Default Current Event changed. Other events remain available.';
   }elseif($op==='update_revision'){
    if(!$canConfig)throw new RuntimeException('Owner/Admin required to update game revision.');
    $revisionId=(int)($_POST['revision_id']??0);
    $check=$pdo->prepare("SELECT id FROM game_revisions WHERE id=? AND game_id=? AND status='published'");$check->execute([$revisionId,(int)$event['game_id']]);
    if(!$check->fetchColumn())throw new RuntimeException('The revision is not published for this game.');
    $active=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE event_id=? AND organization_id=? AND state IN ('running','paused')");$active->execute([$selectedId,$org]);
    if((int)$active->fetchColumn()>0)throw new RuntimeException('End the active or paused match before changing the game revision.');
    $s=$pdo->prepare('UPDATE events SET game_revision_id=? WHERE id=? AND organization_id=?');$s->execute([$revisionId,$selectedId,$org]);$msg='Event game revision updated. Existing scouting history was not deleted.';
   }elseif($op==='roster_add'){
    preg_match_all('/\b\d{1,5}\b/',(string)($_POST['numbers']??''),$m);$nums=array_unique(array_map('intval',$m[0]??[]));if(!$nums)throw new RuntimeException('Enter FRC team numbers.');
    $s=$pdo->prepare("INSERT INTO event_teams (event_id,frc_team_number,tba_team_key) VALUES (?,?,?) ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))");foreach($nums as $n){if($n>0)$s->execute([$selectedId,$n,'frc'.$n]);}$msg='Roster entries added.';
   }elseif($op==='roster_remove'){
    $team=(int)($_POST['team']??0);if($team<1)throw new RuntimeException('Invalid team number.');
    $s=$pdo->prepare('DELETE FROM event_teams WHERE event_id=? AND frc_team_number=?');$s->execute([$selectedId,$team]);$msg='Removed team from roster (scouting history unchanged).';
   }else{throw new RuntimeException('Unknown operation.');}
  }
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$games=eo_games($pdo,$org);
$q=$pdo->prepare("SELECT e.*,g.name game_name,g.season_year,gr.revision_number revision_number,
 (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
 (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id AND m.organization_id=e.organization_id) match_count,
 (SELECT COUNT(*) FROM pit_scouting p WHERE p.event_id=e.id AND p.organization_id=e.organization_id AND p.status='complete') pit_count
 FROM events e JOIN games g ON g.id=e.game_id LEFT JOIN game_revisions gr ON gr.id=e.game_revision_id WHERE e.organization_id=? ORDER BY e.is_current DESC,e.start_date DESC,e.id DESC");$q->execute([$org]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
if(!$selectedId&&$events){foreach($events as $ev){if((int)$ev['active']===1){$selectedId=(int)$ev['id'];break;}}if(!$selectedId)$selectedId=(int)$events[0]['id'];}$selected=eo_event($pdo,$org,$selectedId);
$eoLiveReady=false;$eoLiveState=[];$eoLiveByEvent=[];$eoLiveInterval=2;
try{
 $eoLiveReady=(bool)$pdo->query("SHOW TABLES LIKE 'neptune_event_live_state'")->fetchColumn();
 if($eoLiveReady){
  // One organization-scoped query supplies the full event list, not only the selected event.
  $ls=$pdo->prepare("SELECT event_id,enabled,last_attempt_at,last_success_at,last_message FROM neptune_event_live_state WHERE organization_id=?");
  $ls->execute([$org]);
  foreach($ls->fetchAll(PDO::FETCH_ASSOC) as $liveRow){$eoLiveByEvent[(int)$liveRow['event_id']]=$liveRow;}
  $eoLiveState=$eoLiveByEvent[$selectedId]??[];
  require_once dirname(__DIR__).'/_tba_scheduler.php';
  $eoLiveInterval=(int)(neptune_tba_schedule_settings($pdo)['live_interval_minutes']??2);
 }
}catch(Throwable $ignored){$eoLiveReady=false;$eoLiveState=[];$eoLiveByEvent=[];}
$revisionOptions=[];if($selected){$r=$pdo->prepare("SELECT id,revision_number FROM game_revisions WHERE game_id=? AND status='published' ORDER BY revision_number DESC");$r->execute([(int)$selected['game_id']]);$revisionOptions=$r->fetchAll(PDO::FETCH_ASSOC);}
$roster=[];$orgTeamsAtEvent=[];
if($selected){
 $s=$pdo->prepare('SELECT et.* FROM event_teams et WHERE et.event_id=? ORDER BY et.frc_team_number');
 $s->execute([$selectedId]);$roster=$s->fetchAll(PDO::FETCH_ASSOC);
 // A team from this organization is attending when its number is on the event roster.
 // This avoids requiring a duplicate manual assignment after a TBA/FIRST import.
 $s=$pdo->prepare('SELECT t.frc_team_number,et.nickname FROM teams t INNER JOIN event_teams et ON et.frc_team_number=t.frc_team_number AND et.event_id=? WHERE t.organization_id=? AND t.active=1 ORDER BY t.frc_team_number');
 $s->execute([$selectedId,$org]);$orgTeamsAtEvent=$s->fetchAll(PDO::FETCH_ASSOC);
}
// On-demand, read-only diagnostics usable from Event Operations when Apache logs
// or the Maintenance Console itself are not writable by the PHP worker.
$eoRefreshDiagnostics=[];
if ($canConfig && $selected && (string)($_GET['refresh_diag']??'')==='1') {
 $dependencies=[
  'Refresh Now API'=>'api/tba-event-refresh-now.php',
  'Event Live scheduler'=>'admin/tba-event-live-sync-cli.php',
  'TBA event refresh helper'=>'admin/_tba_live_sync.php',
  'FIRST event sync helper'=>'admin/_first_events.php',
  'FIRST event link helper'=>'admin/_first_event_links.php',
 ];
 foreach($dependencies as $label=>$rel){
  $file=dirname(__DIR__).'/'.$rel;
  if(!is_file($file)){$eoRefreshDiagnostics[]=['error',$label,'Missing from the deployed app'];continue;}
  if(!is_readable($file)){$eoRefreshDiagnostics[]=['error',$label,'Not readable by the PHP process'];continue;}
  try{$source=@file_get_contents($file);if($source===false)throw new RuntimeException('not readable');token_get_all($source,TOKEN_PARSE);$eoRefreshDiagnostics[]=['ok',$label,'Present; PHP syntax OK'];}
  catch(Throwable $e){$eoRefreshDiagnostics[]=['error',$label,'PHP syntax/read error'];}
 }
 try{
  $exists=(bool)$pdo->query("SHOW TABLES LIKE 'neptune_event_live_state'")->fetchColumn();
  $eoRefreshDiagnostics[]=$exists?['ok','Event live database','Table present']:['error','Event live database','v46 table not installed'];
 }catch(Throwable $e){$eoRefreshDiagnostics[]=['error','Event live database','Could not query live state'];}
 if(trim((string)($selected['tba_event_key']??''))===''){
  $eoRefreshDiagnostics[]=['warning','TBA event link','No TBA event key; confirmed FIRST link needed'];
 }else{
  $eoRefreshDiagnostics[]=['ok','TBA event link','Event has a linked TBA key'];
 }
 if(!is_file('/etc/cron.d/neptune-event-live')){
  $eoRefreshDiagnostics[]=['warning','Scheduled refresh','Standard v46 cron file not found (may be installed another way)'];
 }else{
  $eoRefreshDiagnostics[]=['ok','Scheduled refresh','Standard cron file exists; execution not verified'];
 }
 $eoRefreshDiagnostics[]=['info','Latest recorded result',substr((string)($eoLiveState['last_message']??'No last result recorded'),0,300)];
}
include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* Event Operations v48 — scoped responsive workspace */
.eo-workspace{--eo-blue:#54baff;--eo-surface:var(--panel2,var(--panel));--eo-line:var(--line);--eo-quiet:var(--muted);min-width:0}
.eo-workspace *{box-sizing:border-box}
.eo-workspace .eo-muted{color:var(--eo-quiet);font-size:.87rem;line-height:1.45}
.eo-page-head{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:0 0 20px}
.eo-page-head .eo-hero-intro{flex:1 1 470px;min-width:0}
.eo-page-head .eo-page-tools{flex:0 1 auto;justify-content:flex-end;position:relative;z-index:1}
.eo-page-head .neptune-hero-pills{max-width:100%}
.eo-page-head h1{font-size:clamp(1.65rem,2.7vw,2.15rem);letter-spacing:-.035em;line-height:1.12;margin:7px 0}
.eo-page-head p{margin:0;max-width:700px}
.eo-page-eyebrow{color:var(--eo-quiet);font-weight:800;letter-spacing:.11em;font-size:.68rem;text-transform:uppercase}
.eo-page-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.eo-page-tools .btn{margin:0;display:inline-flex;gap:8px;align-items:center;font-size:.85rem}
.eo-grid{display:grid;grid-template-columns:minmax(270px,310px) minmax(0,1fr);gap:16px;margin:0 0 20px;align-items:start}
.eo-grid>*{min-width:0}
.eo-card{min-width:0;border-radius:15px!important}
.eo-sidebar{padding:17px!important;position:sticky;top:12px;display:flex;flex-direction:column;max-height:calc(100vh - 32px);gap:13px}
.eo-sidebar-head{display:flex;gap:12px;align-items:center;justify-content:space-between}
.eo-sidebar-head h2{font-size:1.25rem;line-height:1.2;margin:3px 0 0;letter-spacing:-.025em}
.eo-sidebar-count{border:1px solid var(--eo-line);border-radius:10px;min-width:40px;padding:6px 9px;font-weight:800;text-align:center;color:var(--text)}
.eo-search{display:flex;align-items:center;gap:10px;padding:0 12px;border:1px solid var(--eo-line);border-radius:11px;background:var(--eo-surface);color:var(--eo-quiet)}
.eo-search:focus-within{border-color:var(--eo-blue);box-shadow:0 0 0 2px #54baff25}
.eo-search input{color:var(--text);background:transparent!important;border:0!important;box-shadow:none!important;outline:none!important;width:100%;min-width:0;margin:0!important;padding:11px 0!important;font-size:.87rem}
.eo-sidebar-body{overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;min-height:0;padding-right:3px;flex:1}
.eo-list-heading{display:flex;align-items:center;justify-content:space-between;margin:3px 0 9px;gap:10px;font-size:.72rem;color:var(--eo-quiet);font-weight:850;letter-spacing:.07em;text-transform:uppercase}
.eo-list-heading strong{font-size:.76rem;font-weight:750;color:var(--eo-quiet)}
.eo-list{display:grid;gap:7px}
.eo-event{display:block;position:relative;padding:11px 12px 10px;border:1px solid var(--eo-line);border-radius:11px;background:var(--eo-surface);text-decoration:none!important;color:var(--text)!important;outline:0;transition:border-color .16s,background .16s,box-shadow .16s}
.eo-event:hover{border-color:var(--eo-blue)}
.eo-event:focus-visible{outline:2px solid var(--eo-blue);outline-offset:2px}
.eo-event[hidden]{display:none!important}
.eo-event.selected{border-color:var(--eo-blue);box-shadow:inset 3px 0 0 var(--eo-blue),0 0 0 1px #54baff60;background:linear-gradient(120deg,#54baff10,transparent 78%),var(--eo-surface)}
.eo-event-name{display:block;font-size:.88rem;line-height:1.3;font-weight:800;letter-spacing:-.01em;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.eo-event-badges{display:flex;align-items:center;gap:5px;flex-wrap:wrap;min-width:0;margin-top:8px}
.eo-status{display:inline-flex;align-items:center;gap:5px;border-radius:6px;padding:3px 7px;border:1px solid transparent;font-size:.70rem;font-weight:800;line-height:1.3;white-space:nowrap;max-width:100%}
.eo-stage-planned{color:#a4c7ff;background:#28426866;border-color:#5776a055}
.eo-stage-pit-open{color:#78d8ec;background:#15596855;border-color:#38b9d055}
.eo-stage-schedule-ready{color:#e6ca79;background:#70561455;border-color:#be943755}
.eo-stage-running{color:#8df1ae;background:#17653955;border-color:#47b87b55}
.eo-stage-complete{color:#d2b9ff;background:#57328255;border-color:#9063d255}
.eo-stage-archived{color:var(--eo-quiet);background:var(--eo-surface);border-color:var(--eo-line)}
.eo-current{color:#b9e8ff;background:#16558155;border-color:#54bbed}
.eo-status.eo-live-on{color:#92f2b1;background:#17653955;border-color:#47b87b88}
.eo-status.eo-live-off{color:var(--eo-quiet);background:var(--eo-surface);border-color:var(--eo-line)}
.eo-status.eo-live-on i,.eo-status.eo-live-off i{font-size:.8em}
.eo-event-meta{display:block;margin-top:7px;color:var(--eo-quiet);font-size:.76rem;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.eo-event-archived{opacity:.82}
.eo-archived-wrap{margin-top:17px;padding-top:13px;border-top:1px solid var(--eo-line)}
.eo-archived-wrap summary{cursor:pointer;font-size:.82rem;font-weight:800;color:var(--text);margin-bottom:10px}
.eo-archived-wrap:not([open]) summary{margin-bottom:0}
.eo-add{border-top:1px solid var(--eo-line);padding-top:13px;margin-top:auto}
.eo-add>summary{cursor:pointer;color:var(--eo-blue);font-weight:800;font-size:.85rem;list-style:none}
.eo-add>summary::before{content:'+';font-size:1.12rem;vertical-align:middle;margin-right:8px}
.eo-add[open]>summary::before{content:'−'}
.eo-add>summary::-webkit-details-marker{display:none}
.eo-form{display:grid;gap:11px}
.eo-form label{font-size:.86rem}
.eo-form input,.eo-form select{min-width:0;width:100%}
.eo-detail{padding:20px 21px 24px!important;min-width:0;overflow:hidden}
.eo-selected-header{display:flex;flex-direction:column;gap:8px;min-width:0}
.eo-selected-header h2{font-size:clamp(1.28rem,2vw,1.65rem);letter-spacing:-.025em;line-height:1.23;margin:0;overflow-wrap:anywhere}
.eo-selected-metadata{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;font-size:.83rem;color:var(--eo-quiet)}
.eo-selected-metadata>span+span::before{content:'·';padding-right:10px;color:var(--eo-quiet)}
.eo-selected-header .eo-event-badges{margin-top:1px}
.eo-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:var(--eo-line);border:1px solid var(--eo-line);border-radius:12px;overflow:hidden;margin:17px 0 0}
.eo-fact{background:var(--eo-surface);min-width:0;padding:12px 15px;display:flex;gap:11px;align-items:center}
.eo-fact-icon{flex:0 0 34px;width:34px;height:34px;border-radius:9px;background:#54baff12;display:flex;align-items:center;justify-content:center;color:var(--eo-blue)}
.eo-fact-copy{display:flex;flex-direction:column;min-width:0}
.eo-fact b{font-size:1.27rem;font-weight:850;line-height:1.07}
.eo-fact small{color:var(--eo-quiet);font-size:.73rem;margin-top:4px}
.eo-tabs{display:flex;align-items:center;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--eo-line);margin:19px 0 19px;padding-bottom:0}
.eo-tabs button{appearance:none!important;border:0!important;border-bottom:2px solid transparent!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;color:var(--eo-quiet)!important;margin:0!important;padding:11px 13px 12px!important;font-size:.84rem!important;font-weight:780!important;display:inline-flex;gap:8px;align-items:center;white-space:nowrap;transition:color .15s,border-color .15s}
.eo-tabs button:hover{color:var(--text)!important;background:#54baff0c!important}
.eo-tabs button[aria-selected=true]{border-bottom-color:var(--eo-blue)!important;color:var(--text)!important}
.eo-tabs button:focus-visible{outline:2px solid var(--eo-blue)!important;outline-offset:-3px}
.eo-pane{display:none;min-width:0}.eo-pane.active{display:block}
.eo-pane>h3{font-size:1.04rem;margin:0 0 12px}
.eo-overview-layout{display:block;min-width:0}
.eo-primary-stack{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;min-width:0}
.eo-panel{border:1px solid var(--eo-line);border-radius:12px;padding:17px 18px;background:var(--eo-surface);min-width:0}
.eo-panel-head{display:flex;gap:11px;align-items:center;margin-bottom:13px}
.eo-panel-head>i{color:var(--eo-blue);font-size:.98rem}
.eo-panel h3{font-size:1rem;margin:0;font-weight:850}
.eo-panel p{margin:6px 0 0;font-size:.82rem;line-height:1.4}
.eo-stage-form{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap;margin:14px 0 0}
.eo-stage-form .eo-form-field{flex:1 1 205px;min-width:0}
.eo-stage-form label{display:block;color:var(--eo-quiet);font-size:.76rem;font-weight:760;margin-bottom:7px}
.eo-stage-form select{width:100%;min-width:0;margin:0}
.eo-stage-form button{flex:0 0 auto;white-space:nowrap;margin:0}
.eo-inline-setting{display:flex;align-items:center;justify-content:space-between;gap:15px;border-top:1px solid var(--eo-line);margin-top:17px;padding-top:15px}
.eo-inline-setting h4{margin:0;font-size:.87rem}
.eo-inline-setting p{margin:4px 0 0;font-size:.78rem;color:var(--eo-quiet)}
.eo-inline-setting form{margin:0;flex:0 0 auto}
.eo-inline-setting .eo-status{flex:0 0 auto}
.eo-inline-setting .btn,.eo-inline-setting button{white-space:nowrap}
.eo-checklist{margin:0;padding:0;list-style:none;display:grid;gap:0}
.eo-checklist li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--eo-line)}
.eo-checklist li:last-child{border-bottom:0;padding-bottom:0}
.eo-checklist li:first-child{padding-top:0}
.eo-checklist .eo-check-label{display:flex;gap:9px;align-items:center;font-size:.83rem;min-width:0}
.eo-checklist .eo-check-label i{width:17px;color:var(--eo-quiet);text-align:center}
.eo-checklist .eo-check-ok{color:#8df1ae;font-weight:780;font-size:.76rem}
.eo-checklist .eo-check-warn{color:#e6ca79;font-weight:780;font-size:.76rem}
.eo-text-link{background:none!important;border:0!important;padding:0!important;margin:13px 0 0!important;color:var(--eo-blue)!important;text-decoration:none;cursor:pointer;font-size:.81rem;font-weight:780;box-shadow:none!important}
.eo-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.eo-roster{max-height:440px;overflow:auto}
.eo-assignment{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid var(--eo-line)}
.eo-assignment form{margin:0}
.eo-notice{padding:11px 13px;border:1px solid var(--eo-line);border-radius:10px;font-size:.85rem;margin:10px 0}
.eo-sync-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.eo-sync-head h3{margin-bottom:5px}.eo-sync-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.eo-refresh-now{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:46px;padding:10px 16px;border-radius:11px;font-weight:800;white-space:nowrap}.eo-refresh-now:disabled{opacity:.6;cursor:wait}.eo-refresh-result{font-size:.86rem;margin:10px 0;line-height:1.4}.eo-refresh-result:empty{display:none}.eo-refresh-result[data-state="good"]{color:#8df1ae}.eo-refresh-result[data-state="warning"]{color:#e6ca79}.eo-refresh-result[data-state="bad"]{color:#ffb4b4}.eo-live-toggle{border:1px solid var(--eo-line);border-radius:11px;padding:12px 16px;background:var(--eo-surface);color:var(--text);display:inline-flex;align-items:center;gap:10px;cursor:pointer;max-width:100%}
.eo-live-toggle.is-on{border-color:#47b87b;color:#8df1ae;background:#17653933}.eo-live-toggle strong{font-size:.83em;padding:3px 6px;border:1px solid currentColor;border-radius:5px}
.eo-live-toggle:disabled{opacity:.6;cursor:wait}.eo-sync-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}
.eo-sync-facts>div{min-width:0;border:1px solid var(--eo-line);border-radius:10px;padding:12px;overflow-wrap:anywhere}.eo-sync-facts small{display:block;color:var(--eo-quiet);margin-bottom:5px}.eo-sync-facts b{font-size:.92rem}
.eo-archive-panel{border:1px solid var(--eo-line);border-radius:12px;padding:14px;margin-top:22px}.eo-archive-panel h3{margin:0 0 8px}.eo-archive-panel button{margin-top:8px}
.eo-empty-search{display:none;font-size:.79rem;color:var(--eo-quiet);padding:10px 0}
@media(max-width:1090px){.eo-grid{grid-template-columns:minmax(230px,270px) minmax(0,1fr)}.eo-primary-stack{grid-template-columns:minmax(0,1fr)}}
@media(max-width:820px){.eo-grid{grid-template-columns:minmax(0,1fr)}.eo-sidebar{position:static;max-height:none}.eo-sidebar-body{max-height:325px}.eo-primary-stack{grid-template-columns:minmax(0,1fr)}}
@media(max-width:620px){.eo-detail{padding:16px!important}.eo-sidebar{padding:15px!important}.eo-fact{padding:12px 9px;gap:7px}.eo-fact-icon{display:none}.eo-fact b{font-size:1.14rem}.eo-tabs{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin}.eo-tabs button{padding:10px!important;font-size:.78rem!important}.eo-stage-form{flex-direction:column;align-items:stretch}.eo-stage-form .eo-form-field{flex:auto;width:100%}.eo-stage-form button{width:100%}.eo-inline-setting{flex-wrap:wrap}.eo-sync-facts{grid-template-columns:minmax(0,1fr)}.eo-sync-actions{width:100%;justify-content:flex-start}.eo-refresh-now{flex:1 1 145px}.eo-live-toggle{flex:1 1 145px;justify-content:center}}
/* v52: use Neptune's canonical responsive hero. On phones, clear the
   desktop 470px flex-basis so it cannot turn into a 470px-tall header. */
@media(max-width:760px){
  .eo-page-head .eo-hero-intro{flex:0 1 auto!important;min-height:0!important;width:100%}
  .eo-page-head .eo-page-tools{flex:0 1 auto!important;min-height:0!important}
}

/* v56: event-local, read-only refresh diagnostics */
.eo-diagnostic{margin-top:14px;padding:14px;border:1px solid var(--eo-line);border-radius:12px;background:var(--eo-surface)}
.eo-diagnostic h4{margin:0 0 8px;font-size:.93rem}
.eo-diagnostic p{margin:0 0 9px}
.eo-diagnostic ul{list-style:none;padding:0;margin:0;display:grid;gap:8px}
.eo-diagnostic li{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px;align-items:start;font-size:.79rem}
.eo-diagnostic li span:last-child{min-width:0;overflow-wrap:anywhere}
.eo-diagnostic .eo-diagnostic-state{min-width:63px;text-align:center;border:1px solid var(--eo-line);border-radius:5px;padding:2px 5px;color:var(--muted);font-weight:850;text-transform:uppercase;font-size:.68rem}
.eo-diagnostic li[data-state="ok"] .eo-diagnostic-state{color:#8df1ae;border-color:#47b87b66}
.eo-diagnostic li[data-state="error"] .eo-diagnostic-state{color:#ffa2a2;border-color:#ff6b6b77}
.eo-diagnostic li[data-state="warning"] .eo-diagnostic-state{color:#e6ca79;border-color:#be943755}
</style>
<div class="eo-workspace">
<header class="eo-page-head module-page-header neptune-page-hero" aria-label="Event Operations">
 <div class="eo-hero-intro">
  <h1>Event Operations</h1>
  <p class="eo-muted">Prepare, manage, and monitor every competition in one place.</p>
  <div class="neptune-hero-pills" aria-label="Event Operations quick guide">
   <span class="neptune-hero-pill"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i><strong>1</strong> Choose event</span>
   <span class="neptune-hero-pill"><i class="fa-solid fa-sliders" aria-hidden="true"></i><strong>2</strong> Manage operations</span>
   <span class="neptune-hero-pill"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i><strong>3</strong> Monitor live sync</span>
  </div>
 </div>
 <nav class="eo-page-tools" aria-label="Event data tools"><a class="btn secondary" href="<?=eo_h(base_url('admin/tba-sync.php'))?>"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i> TBA Sync</a><a class="btn secondary" href="<?=eo_h(base_url('admin/first-sync.php'))?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> FIRST Events</a></nav>
</header>
<?php if($msg):?><div class="notice good"><?=eo_h($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=eo_h($error)?></div><?php endif;?>
<div class="eo-grid">
<aside class="card eo-card eo-sidebar" aria-label="Organization events">
 <div class="eo-sidebar-head"><div><div class="eo-page-eyebrow">YOUR ORGANIZATION</div><h2>Events</h2></div><span class="eo-sidebar-count" title="Active events"><?=count(array_filter($events,static fn($e)=>(int)$e['active']===1))?></span></div>
 <label class="eo-search" for="eoEventSearch"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="eoEventSearch" type="search" placeholder="Find an event" autocomplete="off" aria-label="Filter events by name"></label>
 <div class="eo-sidebar-body"><div class="eo-list-heading"><span>Active events</span><strong><?=count(array_filter($events,static fn($e)=>(int)$e['active']===1))?></strong></div>
 <div class="eo-list" id="eoActiveEvents">
 <?php foreach($events as $ev):if(!(int)$ev['active'])continue;?><a class="eo-event <?= (int)$ev['id']===$selectedId?'selected':'' ?>" href="?event_id=<?=(int)$ev['id']?>" data-eo-event-name="<?=eo_h(strtolower((string)$ev['name'].' '.(string)$ev['game_name']))?>" title="<?=eo_h($ev['name'])?>" <?=(int)$ev['id']===$selectedId?'aria-current="page"':''?>><b class="eo-event-name"><?=eo_h($ev['name'])?></b><div class="eo-event-badges"><?php if((int)$ev['is_current']):?><span class="eo-status eo-current"><i class="fa-solid fa-star" aria-hidden="true"></i> Default</span><?php endif;?><span class="eo-status <?=eo_stage_class((string)$ev['event_status'])?>"><?=eo_h(eo_stage_label((string)$ev['event_status']))?></span><?php if($eoLiveReady):$liveOn=!empty($eoLiveByEvent[(int)$ev['id']]['enabled']);?><span class="eo-status <?=$liveOn?'eo-live-on':'eo-live-off'?>" data-eo-live-badge="<?=(int)$ev['id']?>" title="<?=$liveOn?'Automatic refresh enabled. Check Live Sync for last success.':'Automatic refresh disabled.'?>"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> <span><?=$liveOn?'Live ON':'Live OFF'?></span></span><?php endif;?></div><span class="eo-event-meta"><?=eo_h($ev['season_year'].' '.$ev['game_name'])?></span><span class="eo-event-meta" style="margin-top:1px"><?=(int)$ev['team_count']?> robots · <?=(int)$ev['match_count']?> matches</span></a><?php endforeach;?>
 </div>
 <?php if(!array_filter($events,static fn($e)=>(int)$e['active']===1)):?><p class="eo-muted">No active events.</p><?php endif;?><p class="eo-empty-search" id="eoNoEvents">No events match your search.</p>
 <?php $archivedEvents=array_values(array_filter($events,static fn($e)=>(int)$e['active']===0));if($archivedEvents):?><details class="eo-archived-wrap" id="eoArchivedEvents" <?=!empty($selected)&&!(int)$selected['active']?'open':''?>><summary>Archived events (<?=count($archivedEvents)?>)</summary><div class="eo-list"><?php foreach($archivedEvents as $ev):?><a class="eo-event eo-event-archived <?= (int)$ev['id']===$selectedId?'selected':'' ?>" href="?event_id=<?=(int)$ev['id']?>" data-eo-event-name="<?=eo_h(strtolower((string)$ev['name'].' '.(string)$ev['game_name']))?>" title="<?=eo_h($ev['name'])?>"><b class="eo-event-name"><?=eo_h($ev['name'])?></b><div class="eo-event-badges"><span class="eo-status eo-stage-archived">Archived</span><span class="eo-status <?=eo_stage_class((string)$ev['event_status'])?>"><?=eo_h(eo_stage_label((string)$ev['event_status']))?></span></div><span class="eo-event-meta"><?=eo_h($ev['season_year'].' '.$ev['game_name'])?></span><span class="eo-event-meta" style="margin-top:1px"><?=(int)$ev['team_count']?> robots · <?=(int)$ev['match_count']?> matches</span></a><?php endforeach;?></div></details><?php endif;?></div>
 <details class="eo-add" id="eoCreateEvent"><summary>Create new event</summary><form method="post" class="eo-form" style="margin-top:12px"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="op" value="create"><label>Name <input name="name" required></label><label>Game <select name="game_id" required><?php foreach($games as $g):?><option value="<?=(int)$g['id']?>"><?=eo_h($g['season_year'].' '.$g['name'])?></option><?php endforeach;?></select></label><label>Start <input type="date" name="start_date"></label><label>End <input type="date" name="end_date"></label><label>TBA event key (optional) <input name="tba_event_key"></label><label>Event code (optional) <input name="event_code"></label><button type="submit">Create event</button></form></details>
</aside>
<article class="card eo-card eo-detail">
<?php if(!$selected):?><h2>Choose an event</h2><p class="eo-muted">Select an event to manage it.</p><?php else:?>
<header class="eo-selected-header">
 <div class="eo-page-eyebrow">SELECTED EVENT</div>
 <h2><?=eo_h($selected['name'])?></h2>
 <div class="eo-selected-metadata"><span><?=eo_h($selected['season_year'].' '.$selected['game_name'])?></span><span><?=eo_h($selected['start_date']?:'Date TBD')?><?php if(!empty($selected['end_date'])&&$selected['end_date']!==$selected['start_date']):?> — <?=eo_h($selected['end_date'])?><?php endif;?></span></div>
 <div class="eo-event-badges"><?php if((int)$selected['is_current']):?><span class="eo-status eo-current"><i class="fa-solid fa-star" aria-hidden="true"></i> Default Current</span><?php endif;?><span class="eo-status <?=!(int)$selected['active']?'eo-stage-archived':eo_stage_class((string)$selected['event_status'])?>"><?=!(int)$selected['active']?'Archived':eo_h(eo_stage_label((string)$selected['event_status']))?></span><?php if($eoLiveReady && (int)$selected['active']):$selectedLiveOn=!empty($eoLiveState['enabled']);?><span class="eo-status <?=$selectedLiveOn?'eo-live-on':'eo-live-off'?>" data-eo-live-badge="<?=$selectedId?>" title="<?=$selectedLiveOn?'Automatic refresh enabled. Check Live Sync for last success.':'Automatic refresh disabled.'?>"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> <span><?=$selectedLiveOn?'Live ON':'Live OFF'?></span></span><?php endif;?></div>
</header>
<?php $currentRow=null;foreach($events as $ev){if((int)$ev['id']===$selectedId){$currentRow=$ev;break;}} ?>
<div class="eo-facts">
 <div class="eo-fact"><span class="eo-fact-icon"><i class="fa-solid fa-robot" aria-hidden="true"></i></span><span class="eo-fact-copy"><b><?=(int)($currentRow['team_count']??0)?></b><small>Robots</small></span></div>
 <div class="eo-fact"><span class="eo-fact-icon"><i class="fa-solid fa-list-ol" aria-hidden="true"></i></span><span class="eo-fact-copy"><b><?=(int)($currentRow['match_count']??0)?></b><small>Matches</small></span></div>
 <div class="eo-fact"><span class="eo-fact-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><span class="eo-fact-copy"><b><?=(int)($currentRow['pit_count']??0)?></b><small>Pit complete</small></span></div>
</div>
<nav class="eo-tabs" role="tablist" aria-label="Event operations sections"><?php foreach(['overview'=>['Overview','fa-gauge-high'],'teams'=>['Teams','fa-people-group'],'schedule'=>['Schedule','fa-calendar-days'],'sync'=>['Live Sync','fa-satellite-dish'],'settings'=>['Settings','fa-gear']] as $key=>$tab):?><button type="button" data-eo-tab="<?=eo_h($key)?>" role="tab" aria-selected="<?=$key==='overview'?'true':'false'?>" aria-controls="eo-pane-<?=eo_h($key)?>" id="eo-tab-<?=eo_h($key)?>"><i class="fa-solid <?=eo_h($tab[1])?>" aria-hidden="true"></i><?=eo_h($tab[0])?></button><?php endforeach;?></nav>
<section class="eo-pane active" data-eo-pane="overview" id="eo-pane-overview" role="tabpanel" aria-labelledby="eo-tab-overview">
<?php if(!(int)$selected['active']):?><div class="eo-notice">Archived event. Unarchive it in Settings to resume management. Historical scouting records remain available.</div><?php endif;?>
<div class="eo-overview-layout">
 <div class="eo-primary-stack">
  <section class="eo-panel">
   <div class="eo-panel-head"><i class="fa-solid fa-sliders" aria-hidden="true"></i><h3>Event controls</h3></div>
   <p class="eo-muted">Update this event's stage without affecting other competitions.</p>
   <form method="post" class="eo-stage-form"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="status"><div class="eo-form-field"><label for="eoEventStage">Competition stage</label><select name="status" id="eoEventStage" <?=!(int)$selected['active']?'disabled':''?>><?php foreach(['planned'=>'Planned','pit_open'=>'Pit Scouting Open','schedule_ready'=>'Schedule Ready','running'=>'Matches Running','complete'=>'Complete'] as $k=>$v):?><option value="<?=$k?>" <?=$selected['event_status']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><button type="submit" <?=!(int)$selected['active']?'disabled':''?>>Save stage</button></form>
   <div class="eo-inline-setting"><div><h4><i class="fa-solid fa-star" aria-hidden="true"></i> Default event</h4><p>Used when older screens open without an event selected.</p></div><?php if((int)$selected['is_current']):?><span class="eo-status eo-current"><i class="fa-solid fa-check" aria-hidden="true"></i> Current default</span><?php elseif((int)$selected['active']):?><form method="post"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="current"><button type="submit" class="secondary">Make default</button></form><?php else:?><span class="eo-muted">Archived</span><?php endif;?></div>
  </section>
  <section class="eo-panel">
   <div class="eo-panel-head"><i class="fa-solid fa-list-check" aria-hidden="true"></i><h3>Event readiness</h3></div>
   <ul class="eo-checklist">
    <li><span class="eo-check-label"><i class="fa-solid fa-robot" aria-hidden="true"></i> Team roster</span><span class="<?=!empty($currentRow['team_count'])?'eo-check-ok':'eo-check-warn'?>"><?=!empty($currentRow['team_count'])?(int)$currentRow['team_count'].' added':'No teams'?></span></li>
    <li><span class="eo-check-label"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> Match schedule</span><span class="<?=!empty($currentRow['match_count'])?'eo-check-ok':'eo-check-warn'?>"><?=!empty($currentRow['match_count'])?(int)$currentRow['match_count'].' loaded':'Not loaded'?></span></li>
    <li><span class="eo-check-label"><i class="fa-solid fa-link" aria-hidden="true"></i> TBA event link</span><span class="<?=!empty($selected['tba_event_key'])?'eo-check-ok':'eo-check-warn'?>"><?=!empty($selected['tba_event_key'])?'Linked':'Not linked'?></span></li>
    <li><span class="eo-check-label"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> Live synchronization</span><span id="eoOverviewLiveStatus" class="<?=!empty($eoLiveState['enabled'])?'eo-check-ok':'eo-check-warn'?>"><?=!$eoLiveReady?'Unavailable':(!empty($eoLiveState['enabled'])?'Enabled':'Disabled')?></span></li>
   </ul>
   <button type="button" class="eo-text-link" data-eo-switch="sync">Manage live synchronization <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
  </section>
 </div>

</div>
</section>
<section class="eo-pane" data-eo-pane="teams" id="eo-pane-teams" role="tabpanel" aria-labelledby="eo-tab-teams">
 <h3>Our teams at this event (<?=count($orgTeamsAtEvent)?>)</h3>
 <p class="eo-muted">Automatically identified from your organization's teams on the competition roster. No separate assignment is needed.</p>
 <?php if(!$orgTeamsAtEvent):?>
 <p class="eo-muted">None of your organization's active teams are listed in this event roster. Verify the roster or import it from TBA/FIRST.</p>
 <?php else:?>
 <div class="eo-roster">
 <?php foreach($orgTeamsAtEvent as $team):?>
 <div class="eo-assignment"><span><b>Team #<?=(int)$team['frc_team_number']?></b><?=!empty($team['nickname'])?' · '.eo_h($team['nickname']):''?></span><span class="eo-status eo-stage-running"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> On roster</span></div>
 <?php endforeach;?>
 </div>
 <?php endif;?>
 <h3>Competition roster (<?=count($roster)?>)</h3>
 <div class="eo-roster"><?php foreach($roster as $r):?><div class="eo-assignment"><span><b>#<?=(int)$r['frc_team_number']?></b> <?=eo_h($r['nickname']??'')?></span><form method="post" data-confirm="Remove this team from the event roster?" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="team" value="<?=(int)$r['frc_team_number']?>"><input type="hidden" name="op" value="roster_remove"><button class="secondary" type="submit">Remove</button></form></div><?php endforeach;?></div>
 <details class="eo-add"><summary><b>Add team numbers to roster</b></summary><form method="post" class="eo-form"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="roster_add"><textarea name="numbers" rows="4" placeholder="6369 6773"></textarea><button>Add to roster</button></form></details>
</section>
<section class="eo-pane" data-eo-pane="schedule" id="eo-pane-schedule" role="tabpanel" aria-labelledby="eo-tab-schedule"><h3>Schedule and match operations</h3><p><?= (int)($currentRow['match_count']??0)?> imported matches. <?=!empty($selected['schedule_synced_at'])?'Last import: '.eo_h($selected['schedule_synced_at']).' UTC.':'No schedule sync timestamp recorded.'?></p><div class="eo-row"><a class="btn secondary" href="<?=eo_h(base_url('admin/match-control.php?event_id='.$selectedId))?>">Open Match Control</a><a class="btn secondary" href="tba-sync.php">TBA Schedule Import</a><a class="btn secondary" href="first-sync.php?event_id=<?=$selectedId?>">FIRST Schedule Import</a></div></section>
<section class="eo-pane" data-eo-pane="sync" id="eo-pane-sync" role="tabpanel" aria-labelledby="eo-tab-sync">
 <div class="eo-sync-head"><div><h3 style="margin:0">At Event Live</h3><p class="eo-muted">Independent automatic updates for this event. Other events can run at the same time.</p></div>
 <?php if($eoLiveReady && (int)$selected['active']===1):?>
 <div class="eo-sync-actions">
 <button type="button" class="eo-live-toggle <?=!empty($eoLiveState['enabled'])?'is-on':''?>" id="eoTbaLiveToggle" data-endpoint="<?=eo_h(base_url('api/tba-event-live-toggle.php'))?>" data-event="<?=$selectedId?>" data-csrf="<?=eo_h(csrf_token())?>" data-enabled="<?=!empty($eoLiveState['enabled'])?'1':'0'?>" aria-pressed="<?=!empty($eoLiveState['enabled'])?'true':'false'?>"><i class="fa-solid fa-satellite-dish"></i> At Event Live <strong><?=!empty($eoLiveState['enabled'])?'ON':'OFF'?></strong></button>
 <button type="button" class="btn secondary eo-refresh-now" id="eoRefreshNow" data-endpoint="<?=eo_h(base_url('api/tba-event-refresh-now.php'))?>" data-event="<?=$selectedId?>" data-csrf="<?=eo_h(csrf_token())?>"><i class="fa-solid fa-rotate" aria-hidden="true"></i> <span>Refresh Now</span></button>
 </div>
 <?php else:?><span class="eo-status eo-stage-archived"><?=!$eoLiveReady?'Install v46 SQL':'Archived'?></span><?php endif;?></div>
 <div id="eoRefreshResult" class="eo-refresh-result" role="status" aria-live="polite"></div>
 <?php if(!$eoLiveReady):?><div class="eo-notice">Install the v46 SQL database migration to enable independent event live synchronization.</div>
 <?php elseif(!(int)$selected['active']):?><div class="eo-notice">Unarchive this event before enabling live updates.</div>
 <?php else:?><div class="eo-notice">Refresh interval: about <b><?=$eoLiveInterval?></b> minutes. Live updates require the separate v46 AWS scheduler to be installed and running. Each event has its own ON/OFF state. <b>Refresh Now</b> imports the official event data even while Live is OFF. Public EPA/OPR calculations run through the existing scheduled analytics.</div><?php endif;?>
 <div class="eo-sync-facts"><div><small>TBA event key</small><b><?=eo_h($selected['tba_event_key']?:'Not linked')?></b></div><div><small>Last imported (UTC)</small><b id="eoLastImported"><?=eo_h($selected['last_tba_sync_at']?:'Never')?></b></div><div><small>Last successful refresh (UTC)</small><b id="eoLastSuccess"><?=eo_h($eoLiveState['last_success_at']??'Never')?></b></div><div><small>Last attempt (UTC) / result</small><b id="eoLastAttempt"><?=eo_h($eoLiveState['last_attempt_at']??'Never')?></b><small id="eoLastMessage"><?=eo_h($eoLiveState['last_message']??'No refresh recorded')?></small></div></div>
 <?php if($canConfig):?>
 <div style="margin-top:15px">
  <a class="btn secondary" href="?event_id=<?=$selectedId?>&amp;refresh_diag=1#sync"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i> Run Refresh Diagnostics</a>
 </div>
 <?php if($eoRefreshDiagnostics):?>
 <section class="eo-diagnostic" aria-label="Refresh diagnostics">
  <h4>Read-only refresh diagnostics</h4>
  <p class="eo-muted">Checks deployed dependencies and event state. Does not start a refresh or need access to Apache logs.</p>
  <ul>
   <?php foreach($eoRefreshDiagnostics as $d):?>
    <li data-state="<?=eo_h($d[0])?>"><span class="eo-diagnostic-state"><?=eo_h($d[0])?></span><span><strong><?=eo_h($d[1])?></strong> — <?=eo_h($d[2])?></span></li>
   <?php endforeach;?>
  </ul>
 </section>
 <?php endif;?>
 <?php endif;?>
 <div class="eo-row"><a class="btn secondary" href="tba-sync.php"><i class="fa-solid fa-cloud-arrow-down"></i> TBA Sync</a><a class="btn secondary" href="first-sync.php?event_id=<?=$selectedId?>"><i class="fa-solid fa-calendar"></i> FIRST Events</a></div>
</section>
<section class="eo-pane" data-eo-pane="settings" id="eo-pane-settings" role="tabpanel" aria-labelledby="eo-tab-settings"><h3>Event details</h3><?php if(!$canConfig):?><p class="eo-muted">Owner/Admin required to edit details.</p><?php else:?><form method="post" class="eo-form"><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="details"><label>Event name <input name="name" value="<?=eo_h($selected['name'])?>" required></label><label>Start date <input type="date" name="start_date" value="<?=eo_h($selected['start_date'])?>"></label><label>End date <input type="date" name="end_date" value="<?=eo_h($selected['end_date'])?>"></label><button>Save details</button></form><?php endif;?><?php if($canConfig):?><h3>Game revision</h3><form method="post" class="eo-row" data-confirm="Update the event game revision? Existing records stay attached to this event."><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="update_revision"><select name="revision_id" aria-label="Published game revision"><?php foreach($revisionOptions as $r):?><option value="<?=(int)$r['id']?>" <?=(int)$selected['game_revision_id']===(int)$r['id']?'selected':''?>>Revision <?=(int)$r['revision_number']?></option><?php endforeach;?></select><button class="secondary">Update revision</button></form><?php endif;?><?php if($canConfig):?><div class="eo-archive-panel"><h3><?=!(int)$selected['active']?'Restore event':'Archive event'?></h3><p class="eo-muted"><?=!(int)$selected['active']?'Restore this event to the active event list. Existing observations, matches and roster remain intact.':'Hide this event from the active event list without deleting any observations, matches, rosters, or event settings.'?></p><?php if((int)$selected['is_current']):?><p class="eo-muted">Select another Default Current Event before archiving this one.</p><?php else:?><form method="post" <?=((int)$selected['active']===1)?'data-confirm="Archive this event? No scouting history will be deleted." data-confirm-title="Archive event"':''?>><input type="hidden" name="csrf" value="<?=eo_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$selectedId?>"><input type="hidden" name="op" value="<?=(int)$selected['active']?'archive':'unarchive'?>"><button class="secondary" type="submit"><i class="fa-solid <?=(int)$selected['active']?'fa-box-archive':'fa-box-open'?>"></i> <?=(int)$selected['active']?'Archive Event':'Unarchive Event'?></button></form><?php endif;?></div><?php endif;?><p class="eo-muted">Game revision <?=eo_h($selected['revision_number']??'unknown')?> · Event ID <?=$selectedId?>. TBA link changes and revision migrations remain in their existing specialized tools.</p></section>
<?php endif;?></article></div>
<script>(function(){let tabs=document.querySelectorAll('[data-eo-tab]');let panels=document.querySelectorAll('[data-eo-pane]');function show(name){tabs.forEach(b=>{let on=b.dataset.eoTab===name;b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1});panels.forEach(p=>p.classList.toggle('active',p.dataset.eoPane===name));if(history.replaceState)history.replaceState(null,'','#'+name)}tabs.forEach(b=>b.addEventListener('click',()=>show(b.dataset.eoTab)));let hash=location.hash.substring(1);if(['overview','teams','schedule','sync','settings'].includes(hash))show(hash);
document.querySelectorAll('[data-eo-switch]').forEach(el=>el.addEventListener('click',()=>show(el.dataset.eoSwitch)));
let search=document.getElementById('eoEventSearch');if(search)search.addEventListener('input',()=>{let q=search.value.toLocaleLowerCase().trim(),visible=0;document.querySelectorAll('[data-eo-event-name]').forEach(e=>{let ok=e.dataset.eoEventName.includes(q);e.hidden=!ok;if(ok)visible++});let empty=document.getElementById('eoNoEvents');if(empty)empty.style.display=q&&visible===0?'block':'none';let archive=document.getElementById('eoArchivedEvents');if(archive&&q)archive.open=true;});
let live=document.getElementById('eoTbaLiveToggle');if(live)live.addEventListener('click',async()=>{if(live.disabled)return;let next=live.dataset.enabled!=='1';live.disabled=true;try{let body=new URLSearchParams({csrf:live.dataset.csrf||'',enabled:next?'1':'0',event_id:live.dataset.event||''});let res=await fetch(live.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});let data=await res.json().catch(()=>({}));if(!res.ok||!data.ok)throw new Error(data.message||'Unable to change At Event Live.');live.dataset.enabled=data.enabled?'1':'0';live.classList.toggle('is-on',!!data.enabled);live.setAttribute('aria-pressed',data.enabled?'true':'false');live.querySelector('strong').textContent=data.enabled?'ON':'OFF';document.querySelectorAll('[data-eo-live-badge]').forEach(badge=>{if(badge.dataset.eoLiveBadge!==live.dataset.event)return;badge.classList.toggle('eo-live-on',!!data.enabled);badge.classList.toggle('eo-live-off',!data.enabled);badge.title=data.enabled?'Automatic refresh enabled. Check Live Sync for last success.':'Automatic refresh disabled.';let label=badge.querySelector('span');if(label)label.textContent=data.enabled?'Live ON':'Live OFF';});let overview=document.getElementById('eoOverviewLiveStatus');if(overview){overview.textContent=data.enabled?'Enabled':'Disabled';overview.classList.toggle('eo-check-ok',!!data.enabled);overview.classList.toggle('eo-check-warn',!data.enabled);}window.NeptuneUI?.toast('At Event Live '+(data.enabled?'enabled':'disabled')+(data.event?.name?' for '+data.event.name:''),'good');}catch(e){window.NeptuneUI?.toast(e.message||'At Event Live update failed.','bad');}finally{live.disabled=false;}});

let refresh=document.getElementById('eoRefreshNow');
if(refresh)refresh.addEventListener('click',async()=>{
 if(refresh.disabled)return;
 const label=refresh.querySelector('span');
 const status=document.getElementById('eoRefreshResult');
 const lastImportedBefore=document.getElementById('eoLastImported')?.textContent?.trim()||'Never';
 const liveButton=document.getElementById('eoTbaLiveToggle');
 refresh.disabled=true;
 if(liveButton)liveButton.disabled=true;
 if(label)label.textContent='Refreshing…';
 if(status){status.dataset.state='';status.textContent='Contacting TBA/FIRST for this event. This may take a little while.';}
 try{
  const body=new URLSearchParams({csrf:refresh.dataset.csrf||'',event_id:refresh.dataset.event||''});
  const res=await fetch(refresh.dataset.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
  const responseText=await res.text();
  let data;
  try{data=JSON.parse(responseText);}catch(_parseError){throw new Error('Server returned HTTP '+res.status+' without a JSON response. Run Refresh Diagnostics below to check deployed PHP dependencies.');}
  if(!res.ok||!data.ok)throw new Error(data.message||('Refresh failed (HTTP '+res.status+'). Run Refresh Diagnostics below.'));
  const fields=[['eoLastImported',data.last_imported_at],['eoLastSuccess',data.last_success_at],['eoLastAttempt',data.last_attempt_at],['eoLastMessage',data.last_message]];
  for(const [id,value] of fields){const el=document.getElementById(id);if(el)el.textContent=value||'Never';}
  if(status){status.dataset.state='good';status.textContent=data.message||'Event refresh completed.';}
  window.NeptuneUI?.toast(data.message||'Event refresh completed.','good',{title:'Refresh Now'});
 }catch(e){
  let message=e.message||'Event refresh failed.';
  let state='bad';
  // A proxy timeout or analytics error may arrive AFTER official data commits.
  // Re-check persisted timestamps instead of incorrectly claiming no import occurred.
  try{
   const statusUrl=new URL(refresh.dataset.endpoint,location.href);
   statusUrl.searchParams.set('status','1');
   statusUrl.searchParams.set('event_id',refresh.dataset.event||'');
   const checked=await fetch(statusUrl.toString(),{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
   const persisted=await checked.json();
   if(checked.ok&&persisted.ok){
    for(const [id,value] of [['eoLastImported',persisted.last_imported_at],['eoLastSuccess',persisted.last_success_at],['eoLastAttempt',persisted.last_attempt_at],['eoLastMessage',persisted.last_message]]){
     const el=document.getElementById(id);if(el)el.textContent=value||'Never';
    }
    if(persisted.last_imported_at&&persisted.last_imported_at!==lastImportedBefore){
     state='warning';
     message='An official event import was recorded, but Refresh Now did not receive confirmation. Check the last result before retrying. '+message;
    }else if(persisted.last_message&&persisted.last_message.startsWith('Refresh failed:')){
     message=persisted.last_message;
    }
   }
  }catch(_statusError){}
  if(status){status.dataset.state=state;status.textContent=message;}
  window.NeptuneUI?.toast(message,state==='warning'?'good':'bad',{title:state==='warning'?'Import recorded — completion unconfirmed':'Refresh Now'});
 }finally{
  refresh.disabled=false;
  if(liveButton)liveButton.disabled=false;
  if(label)label.textContent='Refresh Now';
 }
});
})();</script>
</div>
<?php include dirname(__DIR__).'/partials_footer.php';
