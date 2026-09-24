<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();$org=(int)$u['organization_id'];$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);$msg='';$error='';$epaDbReady=neptune_prescout_public_epa_ready($pdo);$oprDbReady=neptune_prescout_opr_table_ready($pdo);

if(!$eventId){
    $s=$pdo->prepare("SELECT id FROM events WHERE organization_id=? AND active=1 ORDER BY is_current DESC,CASE event_status WHEN 'running' THEN 0 WHEN 'schedule_ready' THEN 1 WHEN 'pit_open' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END,COALESCE(start_date,'9999-12-31'),id DESC LIMIT 1");
    $s->execute([$org]);$eventId=(int)$s->fetchColumn();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $op=$_POST['op']??'';
        if($op==='refresh_tba'){
            $s=$pdo->prepare('SELECT * FROM events WHERE id=? AND organization_id=?');$s->execute([$eventId,$org]);$ev=$s->fetch();
            if(!$ev)throw new RuntimeException('Event not found.');
            if(empty($ev['tba_event_key']))throw new RuntimeException('This event does not have a TBA event key yet. Add/import it from TBA Sync first.');
            $rows=tba_get('event/'.rawurlencode($ev['tba_event_key']).'/teams/simple',900);$count=0;
            $q=$pdo->prepare('INSERT INTO event_teams(event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname),city=VALUES(city),state_prov=VALUES(state_prov),country=VALUES(country),tba_team_key=VALUES(tba_team_key)');
            foreach($rows as $t){$n=(int)($t['team_number']??0);if(!$n)continue;$q->execute([$eventId,$n,$t['nickname']??null,$t['city']??null,$t['state_prov']??null,$t['country']??null,$t['key']??('frc'.$n)]);$count++;}
            $msg='TBA team information refreshed for '.$count.' event teams.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$s=$pdo->prepare('SELECT e.*,g.name game_name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.active=1 ORDER BY e.is_current DESC,COALESCE(e.start_date,\'9999-12-31\'),e.name');$s->execute([$org]);$events=$s->fetchAll();
$event=null;$teams=[];$complete=0;$contacted=0;$noResponse=0;$notStarted=0;$epaMap=[];$oprContext=['teams'=>[],'events'=>[],'alliance_rows'=>0];
if($eventId){
    $s=$pdo->prepare('SELECT e.*,g.name game_name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=?');$s->execute([$eventId,$org]);$event=$s->fetch()?:null;
    if($event){
        $sql="SELECT et.frc_team_number,et.nickname,et.city,et.state_prov,et.country,ps.contact_status,ps.status,ps.updated_at,ps.data_json,u.display_name scout_name,rsp.id season_profile_id,rsp.updated_at season_updated_at,rsp.tba_json,
            (SELECT COUNT(*) FROM pit_scouting p2 JOIN events e2 ON e2.id=p2.event_id WHERE p2.organization_id=? AND e2.game_id=? AND p2.frc_team_number=et.frc_team_number AND p2.event_id<>?) prior_pit_count,
            (SELECT COUNT(*) FROM pre_scouting p3 WHERE p3.organization_id=? AND p3.game_id=? AND p3.frc_team_number=et.frc_team_number AND p3.event_id<>?) prior_pre_count
            FROM event_teams et
            LEFT JOIN pre_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number
            LEFT JOIN users u ON u.id=ps.submitted_by
            LEFT JOIN robot_season_profiles rsp ON rsp.organization_id=? AND rsp.game_id=? AND rsp.frc_team_number=et.frc_team_number
            WHERE et.event_id=? ORDER BY et.frc_team_number";
        $s=$pdo->prepare($sql);$s->execute([$org,$event['game_id'],$eventId,$org,$event['game_id'],$eventId,$org,$org,$event['game_id'],$eventId]);$teams=$s->fetchAll();
        foreach($teams as &$t){
            $t['pre_data']=json_decode((string)($t['data_json']??''),true)?:[];
            $t['profile_intel']=neptune_prescout_profile_stats($t);
            if(($t['status']??'')==='complete'||($t['contact_status']??'')==='received')$complete++;
            elseif(($t['contact_status']??'')==='contacted')$contacted++;
            elseif(in_array(($t['contact_status']??''),['no_response','unavailable'],true))$noResponse++;
            else $notStarted++;
        } unset($t);

        // EPA is read in one local database query for the full event roster.
        // No Statbotics request is made when Pre-Scouting loads.
        $epaMap=neptune_prescout_public_epa_map(
            $pdo,
            (int)$event['season_year'],
            array_map(static fn($row)=>(int)($row['frc_team_number']??0),$teams)
        );


        // OPR is read from Neptune's persisted AUGUR OPR table. The table is
        // built from the same local archived qualification samples used by
        // Public EPA, so loading Pre-Scouting does not perform an OPR rebuild.
        $oprContext=neptune_prescout_opr_context(
            $pdo,
            $org,
            (int)$event['game_id'],
            $eventId,
            (int)$event['season_year'],
            (string)($event['tba_event_key']??''),
            (string)($event['start_date']??''),
            array_map(static fn($row)=>(int)($row['frc_team_number']??0),$teams)
        );
    }
}
$total=count($teams);$pct=$total?round($complete*100/$total):0;
$pageTitle='Pre-Scouting';$moduleName='TRIDENT';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between">
  <div><h1 style="margin-bottom:4px">Pre-Scouting</h1><div class="muted">Research and contact teams before the event. Neptune keeps the same season's robot answers available at later events.</div></div>
  <?php if($event&&$event['tba_event_key']):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="refresh_tba"><input type="hidden" name="event_id" value="<?=$eventId?>"><button class="secondary"><i class="fa-solid fa-cloud-arrow-down"></i> Refresh TBA Team Info</button></form><?php endif;?>
</div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if($events):?><div class="card"><form method="get" class="analytics-toolbar"><div style="min-width:320px"><label style="margin-top:0">Event</label><select name="event_id" onchange="this.form.submit()"><?php foreach($events as $ev):?><option value="<?=$ev['id']?>" <?=$eventId===(int)$ev['id']?'selected':''?>><?=e(($ev['is_current']?'★ ':'').$ev['name'].' · '.$ev['game_name'])?></option><?php endforeach;?></select></div></form></div><?php endif;?>

<?php if(!$event):?>
<div class="notice" style="margin-top:16px">No event is available yet. Create/import the event and its roster first.</div>
<?php else:?>
<section class="card prescout-summary" style="margin-top:16px">
  <div><span class="muted">Event pre-scouting</span><h2 style="margin:3px 0"><?=e($event['name'])?></h2><div class="muted"><?=e($event['season_year'].' · '.$event['game_name'])?></div></div>
  <div class="prescout-summary-numbers"><div><b><?=$complete?></b><span>Received</span></div><div><b><?=$contacted?></b><span>Contacted</span></div><div><b><?=$noResponse?></b><span>No response</span></div><div><b><?=$notStarted?></b><span>Not started</span></div></div>
  <div class="progress-track"><span style="width:<?=$pct?>%"></span></div>
</section>
<?php if(!$epaDbReady):?><div class="notice" style="margin-top:12px"><i class="fa-solid fa-database"></i> Neptune's local Public EPA table is not installed or unavailable. Legacy cached EPA is shown when present.</div><?php endif;?>
<?php if(!$oprDbReady):?><div class="notice" style="margin-top:12px"><i class="fa-solid fa-calculator"></i> The persisted OPR table has not been built yet. Neptune will use local event data as a fallback. Run <code>php admin/augur-opr-rebuild-cli.php <?=e((string)(int)$event['season_year'])?></code> to populate all archived event OPR values.</div><?php endif;?>
<?php if(!$teams):?>
<div class="notice" style="margin-top:16px">This event does not have a team roster. Import it from The Blue Alliance or add team numbers in Event Setup.</div>
<?php else:?>
<div class="card" style="margin-top:16px"><div class="pit-list-toolbar"><div><label style="margin-top:0">Find team</label><input id="preSearch" placeholder="Team number, nickname, scout, state, or country"></div><div><label style="margin-top:0">Show</label><select id="preStatus"><option value="all">All teams</option><option value="not_started">Not started</option><option value="contacted">Contacted</option><option value="received">Received</option><option value="no_response">No response</option><option value="unavailable">Unavailable</option></select></div></div></div>

<div class="card prescout-sheet-card" style="margin-top:12px">
  <div class="prescout-sheet-note"><i class="fa-solid fa-table"></i> Spreadsheet view: team/location and event history come from TBA/cache; season record, Public EPA, Auto, Teleop, Endgame, and EPA rank come from Neptune's local AUGUR EPA database. Most Recent Event OPR and Previous Event OPR come from Neptune's local OPR table; Season Avg OPR is the qualification-match-weighted average of the team's prior event OPR values.</div>
  <div class="prescout-sheet-wrap">
    <table class="prescout-sheet" id="preScoutTable">
      <thead><tr><th>Num</th><th>Team</th><th>State / Country</th><th>Win Rate</th><th>EPA</th><th>Auto</th><th>Teleop</th><th>Endgame</th><th>Most Recent Event OPR</th><th>Previous Event OPR</th><th>Season Avg OPR</th><th>Rank</th><th>Alliances / Events</th><th>Scout</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach($teams as $t):
        $contact=$t['contact_status']?:'not_started';$known=(int)$t['season_profile_id']>0||(int)$t['prior_pit_count']>0||(int)$t['prior_pre_count']>0;
        $loc=trim(implode(' / ',array_filter([$t['state_prov']??'',$t['country']??''])));$n=(int)$t['frc_team_number'];
        $cached=$t['profile_intel'];$sm=$epaMap[$n]??($cached['public_epa']??($cached['statbotics']??[]));
        $localOpr=$oprContext['teams'][$n]??[];
        $oprs=is_array($localOpr['events']??null)?array_values($localOpr['events']):[];
        if(!$oprs&&is_array($cached['oprs']??null))$oprs=array_values($cached['oprs']);
        $latestEvent=$localOpr['latest_event']??($oprs?end($oprs):null);
        $previousEvent=$localOpr['previous_event']??(count($oprs)>1?$oprs[count($oprs)-2]:null);
        $opr1=$localOpr['latest_event_opr']??($localOpr['opr1']??(is_array($latestEvent)?($latestEvent['opr']??null):null));
        $opr2=$localOpr['previous_event_opr']??($localOpr['opr2']??(is_array($previousEvent)?($previousEvent['opr']??null):null));
        $oprTotal=$localOpr['season_avg_opr']??($localOpr['season_opr']??null);
        // Compatibility fallback for profiles saved before Season Avg OPR.
        // Average available event OPR values; never sum OPR values.
        if(!is_numeric($oprTotal)){
            $vals=array_values(array_filter([$opr1,$opr2],'is_numeric'));
            $oprTotal=$vals?array_sum(array_map('floatval',$vals))/count($vals):null;
        }
        $opr1Title=(string)(is_array($latestEvent)?($latestEvent['event_name']??'Most recent prior event'):'Most recent prior event');
        $opr2Title=(string)(is_array($previousEvent)?($previousEvent['event_name']??'Previous prior event'):'Previous prior event');
        $history=is_array($cached['event_history']??null)?$cached['event_history']:[];$historyText=implode(' · ',array_values(array_filter(array_map(fn($h)=>trim((string)($h['short']??'')),$history))));
        $scout=trim((string)($t['pre_data']['scout']??''))?:($t['scout_name']??'');
        $search=strtolower($n.' '.($t['nickname']??'').' '.$loc.' '.$scout.' '.$historyText);
      ?>
      <tr class="prescout-sheet-row" data-status="<?=e($contact)?>" data-search="<?=e($search)?>">
        <td class="prescout-team-num"><a href="team.php?event_id=<?=$eventId?>&team=<?=$n?>">#<?=e($n)?></a></td>
        <td><a class="prescout-team-link" href="team.php?event_id=<?=$eventId?>&team=<?=$n?>"><?=e($t['nickname']?:'FRC Team '.$n)?></a><?php if($known):?><span class="prescout-known-dot" title="Season data available"><i class="fa-solid fa-database"></i></span><?php endif;?></td>
        <td><?=e($loc?:'—')?></td>
        <td><?=e($sm['record']??'—')?></td>
        <td class="num"><?=e(neptune_prescout_num($sm['epa']??null,1))?></td>
        <td class="num"><?=e(neptune_prescout_num($sm['auto']??null,1))?></td>
        <td class="num"><?=e(neptune_prescout_num($sm['teleop']??null,1))?></td>
        <td class="num"><?=e(neptune_prescout_num($sm['endgame']??null,1))?></td>
        <td class="num" title="<?=e($opr1Title)?>"><?=e(neptune_prescout_num($opr1,2))?></td>
        <td class="num" title="<?=e($opr2Title)?>"><?=e(neptune_prescout_num($opr2,2))?></td>
        <td class="num" title="Combined local OPR across all prior qualification matches"><?=e(neptune_prescout_num($oprTotal,2))?></td>
        <td class="num"><?=e(neptune_prescout_num($sm['rank']??null,0))?></td>
        <td class="prescout-history-cell" title="<?=e($historyText)?>"><?=e($historyText?:'—')?></td>
        <td><?=e($scout?:'—')?></td>
        <td><span class="pill prescout-contact-pill"><?php if($contact==='received'):?><i class="fa-solid fa-circle-check"></i> RECEIVED<?php elseif($contact==='contacted'):?><i class="fa-solid fa-paper-plane"></i> CONTACTED<?php elseif($contact==='no_response'):?><i class="fa-solid fa-clock"></i> NO RESPONSE<?php elseif($contact==='unavailable'):?><i class="fa-solid fa-ban"></i> UNAVAILABLE<?php else:?><i class="fa-regular fa-circle"></i> NOT STARTED<?php endif;?></span></td>
        <td><a class="btn secondary prescout-open-btn" href="team.php?event_id=<?=$eventId?>&team=<?=$n?>"><i class="fa-solid fa-pen"></i></a></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<script>
const psq=document.getElementById('preSearch'),pss=document.getElementById('preStatus'),psc=[...document.querySelectorAll('.prescout-sheet-row')];
function filterPre(){const q=(psq?.value||'').trim().toLowerCase(),s=pss?.value||'all';psc.forEach(c=>{c.hidden=!((!q||c.dataset.search.includes(q))&&(s==='all'||c.dataset.status===s));});}
psq?.addEventListener('input',filterPre);pss?.addEventListener('change',filterPre);
</script>
<?php endif;?>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
