<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_first_events.php';
require_once __DIR__.'/_first_event_match.php';
neptune_first_links_install($pdo);
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$message='';$error='';
$requestedYear=(int)($_REQUEST['year']??0);
$eventId=(int)($_REQUEST['event_id']??0);
$search=trim((string)($_GET['search']??''));
$date=trim((string)($_GET['date']??''));
$showAll=isset($_GET['browse']);
$local=[];$selected=null;$ranked=[];$year=$requestedYear;
try {
    $q=$pdo->prepare('SELECT e.id,e.name,e.event_code,e.tba_event_key,e.start_date,e.end_date,e.is_current,g.season_year,fl.first_event_code FROM events e JOIN games g ON g.id=e.game_id LEFT JOIN neptune_first_event_links fl ON fl.event_id=e.id AND fl.organization_id=e.organization_id WHERE e.organization_id=? ORDER BY e.is_current DESC,e.start_date DESC,e.id DESC');
    $q->execute([$org]);$allLocal=$q->fetchAll(PDO::FETCH_ASSOC);
    if($eventId>0){
        foreach($allLocal as $row){if((int)$row['id']===$eventId){$selected=$row;break;}}
        if(!$selected)throw new RuntimeException('Selected Neptune event is unavailable to this organization.');
    }elseif($allLocal){
        // Default to organization's current event, never another organization's.
        $selected=$allLocal[0];
        $eventId=(int)$selected['id'];
    }
    if($year===0)$year=(int)($selected['season_year']??date('Y'));
    if($year<2020||$year>2100)throw new RuntimeException('Invalid FIRST season.');
    $local=array_values(array_filter($allLocal,static fn($ev)=>(int)$ev['season_year']===$year));
    if($selected&&(int)$selected['season_year']!==$year){
        $selected=$local[0]??null;
        $eventId=(int)($selected['id']??0);
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        $postEvent=(int)($_POST['event_id']??0);
        $code=neptune_first_code((string)($_POST['first_code']??''));
        if($postEvent<1)throw new RuntimeException('Choose an existing Neptune event.');
        $r=neptune_first_sync($pdo,$org,$postEvent,$code,$year);
        $message="FIRST sync completed: {$r['teams']} teams, {$r['matches']} qualification matches, {$r['stations']} stations, {$r['scored']} scored.";
        if(!empty($r['epa']['changed']))$message.=' Provisional AUGUR EPA updated for '.(int)$r['epa']['teams'].' teams.';
        if(!empty($r['epa_error']))$message.=' Provisional AUGUR update needs attention: '.$r['epa_error'];
        $eventId=$postEvent;
        foreach($local as $row){if((int)$row['id']===$postEvent){$selected=$row;break;}}
        if($selected)$selected['first_event_code']=$code;
    }
    $directory=neptune_first_get($year.'/events');
    $rows=$directory['Events']??$directory['events']??[];
    if(!is_array($rows))throw new RuntimeException('Unexpected FIRST event directory response.');
    if($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        $rows=array_values(array_filter($rows,static function($e)use($date){
            if(!is_array($e))return false;
            [$start,$end]=neptune_first_date_window($e);
            return $start!==''&&$date>=$start&&$date<=$end;
        }));
    }
    $ranked=neptune_first_directory_suggestions($rows,$selected??[],$search);
}catch(Throwable $e){$error=$e->getMessage();}
function fs_h($s): string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function fs_button_text(array $row,string $code): string {
    return strtoupper(trim((string)($row['first_event_code']??'')))===$code ? 'Refresh from FIRST' : 'Link & sync FIRST data';
}
?>
<?php
$pageTitle='FIRST Event Sync';
$moduleName='SATURN';
$navigationSection='command';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* FIRST Sync follows native Neptune page scaffolding and global palette.
 * Only the match-picker layout and spacing are page-specific. */
.fs-sync { min-width:0; }
.fs-sync .fs-back { display:inline-flex;align-items:center;gap:8px;margin:0 0 16px;font-size:.88rem;font-weight:800;color:var(--accent); }
.fs-sync .fs-back:hover { text-decoration:underline; }
.fs-sync .fs-section { margin:16px 0; }
.fs-sync .fs-section h2 { display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:1.2rem;line-height:1.3;color:var(--text); }
.fs-sync .fs-section h2 i { display:grid;place-items:center;width:36px;height:36px;flex:none;border-radius:9px;background:var(--panel2);color:var(--module-accent-readable,var(--accent));font-size:.98rem; }
.fs-sync .fs-section > p { color:var(--muted);line-height:1.5; }
.fs-sync .fs-flash { margin:14px 0;line-height:1.5; }
.fs-sync .fs-form-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;align-items:end; }
.fs-sync .fs-field { min-width:0; }
.fs-sync .fs-field label { margin:0 0 7px;font-size:.82rem;line-height:1.35; }
.fs-sync .fs-form-grid .fs-field :is(input,select,button) { display:flex;align-items:center;width:100%;height:44px;min-height:44px;min-width:0;margin:0;padding:0 12px;border-radius:9px;font-size:.88rem;line-height:1.25; }
.fs-sync .fs-form-grid .fs-field select { display:block;appearance:auto;-webkit-appearance:menulist; }
.fs-sync .fs-form-grid .fs-field button { padding-inline:12px;white-space:normal;text-align:center; }
.fs-sync .fs-meta { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0 0; }
.fs-sync .fs-meta .pill { max-width:100%;color:var(--text);font-weight:800; }
.fs-sync .fs-small { font-size:.82rem;line-height:1.45;color:var(--muted); }
.fs-sync .fs-match { background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:16px;margin:11px 0; }
.fs-sync .fs-match.fs-featured { border-color:color-mix(in srgb,var(--module-accent-readable,var(--accent)) 58%,var(--line));background:color-mix(in srgb,var(--module-accent-readable,var(--accent)) 6%,var(--panel2)); }
.fs-sync .fs-match-head { display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap; }
.fs-sync .fs-match-title { font-size:1.03rem;font-weight:900;color:var(--text);line-height:1.3; }
.fs-sync .fs-match > .fs-small { margin:9px 0; }
.fs-sync .fs-actions { display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:11px; }
.fs-sync .fs-actions form { margin:0; }
.fs-sync .fs-actions button,.fs-sync .fs-match-head button { min-width:175px;border-radius:9px;min-height:42px;padding:9px 13px;font-size:.85rem; }
.fs-sync .fs-hint { margin-top:16px;padding-top:13px;border-top:1px solid var(--line); }
.fs-sync details { margin-top:16px; }
.fs-sync details>summary { cursor:pointer;color:var(--accent);font-weight:800;padding:7px 0; }
.fs-sync details>summary:focus-visible { outline:2px solid var(--accent);outline-offset:3px;border-radius:5px; }
.fs-sync code { color:var(--accent); }
.fs-sync .fs-empty-note { color:var(--muted); }
@media(max-width:640px) {
  .fs-sync .fs-form-grid { grid-template-columns:1fr; }
  .fs-sync .fs-actions form,.fs-sync .fs-actions button,.fs-sync .fs-match-head form,.fs-sync .fs-match-head button { width:100%; }
  .fs-sync .fs-match-head { flex-direction:column; }
}
</style>
<div class="module-page fs-sync">
<a class="fs-back" href="<?=fs_h(base_url('admin/events.php'.($eventId?'?event_id='.$eventId:'')))?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Event Operations</a>
<header class="neptune-page-hero neptune-page-hero--saturn" aria-label="FIRST Event Sync"><div><div class="neptune-hero-kicker">SATURN / EVENT OPERATIONS</div><h1>FIRST Event Sync</h1><p>Find your competition in FIRST, confirm the match, and import teams, schedule and results into this organization’s Neptune event.</p><div class="neptune-hero-pills"><span class="neptune-hero-pill"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Choose event</span><span class="neptune-hero-pill"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Match FIRST listing</span><span class="neptune-hero-pill"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i> Sync safely</span></div></div></header>
<?php if($message):?><div class="notice good fs-flash" role="status"><?=fs_h($message)?></div><?php endif;?>
<?php if($error):?><div class="notice bad fs-flash" role="alert"><?=fs_h($error)?></div><?php endif;?>
<div class="card fs-section">
<h2><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> 1. Select your Neptune event</h2>
<form method="get" class="fs-form-grid">
<div class="fs-field"><label for="localEvent">Your event</label><select name="event_id" id="localEvent" onchange="this.form.submit()" <?=!$local?'disabled':''?>><?php foreach($local as $ev):?><option value="<?=(int)$ev['id']?>" <?=(int)$ev['id']===$eventId?'selected':''?>><?=fs_h($ev['name'])?> (<?=fs_h($ev['season_year'])?>)</option><?php endforeach;?></select></div>
<div class="fs-field"><label for="year">Season</label><input id="year" type="number" min="2020" max="2100" name="year" value="<?=$year?>"></div>
<div class="fs-field"><label for="search">Optional FIRST search</label><input id="search" name="search" value="<?=fs_h($search)?>" placeholder="Automatic from event name"></div>
<div class="fs-field"><label for="date">Optional event date</label><input id="date" type="date" name="date" value="<?=fs_h($date)?>"></div>
<div class="fs-field"><button type="submit">Find matching FIRST event</button></div>
</form>
<?php if($selected):?><div class="fs-meta"><span class="pill">NEPTUNE #<?=(int)$selected['id']?></span><span class="fs-small"><?=fs_h($selected['name'])?></span><?php if(!empty($selected['first_event_code'])):?><span class="pill">FIRST linked: <?=fs_h($selected['first_event_code'])?></span><?php endif;?><?php if(!empty($selected['tba_event_key'])):?><span class="pill">TBA linked: <?=fs_h($selected['tba_event_key'])?></span><?php endif;?></div><?php else:?><p>Create a Neptune event in Event Setup to import teams and matches.</p><?php endif;?>
</div>
<div class="card fs-section">
<h2><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i> 2. Confirm the FIRST match and synchronize</h2>
<?php
$linked=strtoupper(trim((string)($selected['first_event_code']??'')));
$suggested=array_values(array_filter($ranked,static fn($r)=>$r['score']>=45));
if($linked!=='')$suggested=array_values(array_filter($ranked,static fn($r)=>$r['code']===$linked));
?>
<?php if($suggested):?><p><?=count($suggested)>1?'Suggested matches based on the selected Neptune event. Verify the name and date before importing.':'Found a likely matching FIRST event. Confirm the name and date.'?></p>
<?php foreach(array_slice($suggested,0,5) as $i=>$item):$e=$item['event'];$code=$item['code'];$name=neptune_first_directory_name($e);[$start,$end]=neptune_first_date_window($e);?>
<div class="fs-match <?=$i===0?'fs-featured':''?>"><div class="fs-match-head"><div><div class="fs-match-title"><?=fs_h($name?:$code)?></div><div class="fs-meta"><span class="pill"><?=fs_h($code)?></span><?php if($i===0):?><span class="pill"><?=$linked===$code?'Already linked':'Top suggestion'?></span><?php endif;?></div></div></div><p class="fs-small"><?=fs_h(($start?:'Date unknown').($end!==''&&$end!==$start?' – '.$end:'').' · '.trim(($e['city']??'').' '.($e['stateProv']??$e['stateprov']??'')))?></p>
<div class="fs-actions"><form method="post"><input type="hidden" name="csrf" value="<?=fs_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="first_code" value="<?=fs_h($code)?>"><input type="hidden" name="year" value="<?=$year?>"><button type="submit" <?=$eventId<1?'disabled':''?>><?=fs_h(fs_button_text($selected??[],$code))?></button></form></div></div>
<?php endforeach;?>
<?php else:?><p>No high-confidence name match was found<?= $linked!==''?' for the linked FIRST code':'' ?>. Search by event code or name below, or browse the directory.</p><?php endif;?>
<details <?=(!$suggested||$showAll)?'open':''?>><summary>Browse FIRST event directory (<?=count($ranked)?> results)</summary>
<?php foreach(array_slice($ranked,0,100) as $item):$e=$item['event'];$code=$item['code'];$name=neptune_first_directory_name($e);[$start,$end]=neptune_first_date_window($e);$conflict=$linked!==''&&$linked!==$code;?><div class="fs-match"><div class="fs-match-head"><div><div class="fs-match-title"><?=fs_h($name?:$code)?></div><div class="fs-meta"><span class="pill"><?=fs_h($code)?></span><span class="fs-small"><?=fs_h(($start?:'Date unknown').' · '.($e['city']??''))?></span></div></div><form method="post"><input type="hidden" name="csrf" value="<?=fs_h(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="first_code" value="<?=fs_h($code)?>"><input type="hidden" name="year" value="<?=$year?>"><button class="secondary" type="submit" <?=($conflict||$eventId<1)?'disabled title="This Neptune event is linked to a different FIRST code"':''?>><?=fs_h(fs_button_text($selected??[],$code))?></button></form></div></div><?php endforeach;?>
</details>
<div class="fs-hint fs-small">The FIRST code is saved separately from TBA's event code after a successful sync. Subsequent imports use the same link. At Event Live can now refresh confirmed FIRST links automatically. FIRST-only scores produce event-local provisional AUGUR EPA; public historical ratings remain TBA-based.</div>
</div>
</div>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
