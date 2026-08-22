<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
$u=require_role(['owner','admin','strategy']);$org=(int)$u['organization_id'];$msg='';$error='';$found=[];
$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');$s->execute([$org]);$teams=$s->fetchAll();
$games=$pdo->query('SELECT id,name,season_year FROM games WHERE is_archived=0 ORDER BY season_year DESC,name')->fetchAll();
$team=(int)($_POST['team_number']??($teams[0]['frc_team_number']??0));$year=(int)($_POST['year']??date('Y'));$game=(int)($_POST['game_id']??($games[0]['id']??0));

function tba_optional(string $path,int $ttl=60): array {
    try{return tba_get($path,$ttl);}catch(Throwable $e){if(str_contains($e->getMessage(),'HTTP 404'))return [];throw $e;}
}
function import_tba_event(PDO $pdo,int $org,int $game,string $key): array {
    $ev=tba_get("event/{$key}",120);$name=$ev['name']??$key;
    $s=$pdo->prepare('SELECT id FROM events WHERE organization_id=? AND tba_event_key=? LIMIT 1');$s->execute([$org,$key]);$eventId=(int)$s->fetchColumn();
    if(!$eventId){$s=$pdo->prepare('SELECT id FROM events WHERE organization_id=? AND game_id=? AND name=? LIMIT 1');$s->execute([$org,$game,$name]);$eventId=(int)$s->fetchColumn();}
    if(!$eventId&&!empty($ev['start_date'])){$s=$pdo->prepare('SELECT id FROM events WHERE organization_id=? AND game_id=? AND is_current=1 AND tba_event_key IS NULL AND start_date=? LIMIT 1');$s->execute([$org,$game,$ev['start_date']]);$eventId=(int)$s->fetchColumn();}
    if($eventId){
        $pdo->prepare("UPDATE events SET game_id=?,name=?,event_code=?,tba_event_key=?,start_date=?,end_date=?,active=1,last_tba_sync_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?")->execute([$game,$name,$ev['event_code']??null,$key,$ev['start_date']??null,$ev['end_date']??null,$eventId,$org]);
    }else{
        $pdo->prepare("INSERT INTO events(organization_id,game_id,name,event_code,tba_event_key,start_date,end_date,active,event_status,last_tba_sync_at) VALUES(?,?,?,?,?,?,?,1,'planned',UTC_TIMESTAMP())")->execute([$org,$game,$name,$ev['event_code']??null,$key,$ev['start_date']??null,$ev['end_date']??null]);$eventId=(int)$pdo->lastInsertId();
    }

    $eventTeams=tba_optional("event/{$key}/teams/simple",120);$teamCount=0;
    foreach($eventTeams as $t){$tn=(int)($t['team_number']??0);if(!$tn)continue;$pdo->prepare('INSERT INTO event_teams(event_id,frc_team_number,nickname,tba_team_key) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname),tba_team_key=VALUES(tba_team_key)')->execute([$eventId,$tn,$t['nickname']??null,$t['key']??null]);$teamCount++;}

    $matches=tba_optional("event/{$key}/matches/simple",30);$matchCount=0;
    foreach($matches as $m){
        $level=$m['comp_level']??'qm';$set=max(1,(int)($m['set_number']??1));$num=max(1,(int)($m['match_number']??1));
        $sched=!empty($m['predicted_time'])?gmdate('Y-m-d H:i:s',(int)$m['predicted_time']):(!empty($m['time'])?gmdate('Y-m-d H:i:s',(int)$m['time']):null);
        $rs=$m['alliances']['red']['score']??null;$bs=$m['alliances']['blue']['score']??null;$rs=is_numeric($rs)&&(int)$rs>=0?(int)$rs:null;$bs=is_numeric($bs)&&(int)$bs>=0?(int)$bs:null;
        $winner='Unknown';if($rs!==null&&$bs!==null)$winner=$rs===$bs?'Tie':($rs>$bs?'Red':'Blue');$importState=($rs!==null&&$bs!==null)?'ended':'scheduled';
        $q="INSERT INTO matches(organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,scheduled_time,red_score,blue_score,winning_alliance,state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tba_match_key=VALUES(tba_match_key),scheduled_time=VALUES(scheduled_time),red_score=VALUES(red_score),blue_score=VALUES(blue_score),winning_alliance=VALUES(winning_alliance),state=IF(VALUES(red_score) IS NOT NULL AND state='scheduled','ended',state)";
        $pdo->prepare($q)->execute([$org,$eventId,$game,$m['key']??null,$level,$set,$num,1,$sched,$rs,$bs,$winner,$importState]);
        $ms=$pdo->prepare('SELECT id FROM matches WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=1');$ms->execute([$org,$eventId,$level,$set,$num]);$matchId=(int)$ms->fetchColumn();
        foreach(['red'=>'Red','blue'=>'Blue'] as $ak=>$av){foreach(($m['alliances'][$ak]['team_keys']??[]) as $i=>$tk){$tn=(int)preg_replace('/\D/','',$tk);if($tn){$pdo->prepare('INSERT INTO match_teams(match_id,frc_team_number,alliance,station) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)')->execute([$matchId,$tn,$av,$i+1]);$pdo->prepare('INSERT INTO event_teams(event_id,frc_team_number,tba_team_key) VALUES(?,?,?) ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))')->execute([$eventId,$tn,'frc'.$tn]);}}}
        $matchCount++;
    }
    $s=$pdo->prepare('SELECT COUNT(*) FROM event_teams WHERE event_id=?');$s->execute([$eventId]);$teamCount=(int)$s->fetchColumn();

    if($teamCount>0)$pdo->prepare('UPDATE events SET roster_synced_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
    if($matchCount>0){$pdo->prepare("UPDATE events SET schedule_synced_at=UTC_TIMESTAMP(),event_status=IF(event_status IN ('planned','pit_open'),'schedule_ready',event_status) WHERE id=?")->execute([$eventId]);}
    elseif($teamCount>0){$pdo->prepare("UPDATE events SET event_status=IF(event_status='planned','pit_open',event_status) WHERE id=?")->execute([$eventId]);}
    $pdo->prepare('UPDATE events SET last_tba_sync_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
    $s=$pdo->prepare('SELECT COUNT(*) FROM events WHERE organization_id=? AND is_current=1');$s->execute([$org]);if((int)$s->fetchColumn()===0){$pdo->prepare('UPDATE events SET is_current=1 WHERE id=? AND organization_id=?')->execute([$eventId,$org]);}
    return ['event_id'=>$eventId,'name'=>$name,'teams'=>$teamCount,'matches'=>$matchCount,'schedule_available'=>$matchCount>0];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$op=$_POST['op']??'find';
  try{
    if($op==='find'){$found=tba_get("team/frc{$team}/events/{$year}/simple",120);}
    elseif($op==='import_event'){$key=trim($_POST['event_key']??'');if($key==='')throw new RuntimeException('Missing TBA event key.');$r=import_tba_event($pdo,$org,$game,$key);$msg=$r['schedule_available']?"{$r['name']} synced: {$r['teams']} roster teams and {$r['matches']} matches.":"{$r['name']} is ready for pit scouting: {$r['teams']} roster teams imported. The match schedule is not published yet.";$found=$team?tba_get("team/frc{$team}/events/{$year}/simple",120):[];}
  }catch(Throwable $e){$error=$e->getMessage();}
}
$s=$pdo->prepare("SELECT e.*,g.name game_name,(SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,(SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,(SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.tba_event_key IS NOT NULL ORDER BY e.is_current DESC,COALESCE(e.start_date,'1900-01-01') DESC");$s->execute([$org]);$imported=$s->fetchAll();
$byKey=[];foreach($imported as $x)$byKey[$x['tba_event_key']]=$x;
$pageTitle='TBA Sync';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">The Blue Alliance Sync</h1><div class="muted">Import the event roster as soon as it is available. The match schedule can be added later with Refresh—pit scouting does not wait for matches.</div></div><a class="btn secondary" href="events.php"><i class="fa-solid fa-calendar-days"></i> Event Setup</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<div class="card"><form method="post" class="analytics-toolbar"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="find"><div><label>Our FRC team</label><select name="team_number"><?php foreach($teams as $t):?><option value="<?=$t['frc_team_number']?>" <?=$team===(int)$t['frc_team_number']?'selected':''?>>#<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?></option><?php endforeach;?></select></div><div><label>Season</label><input type="number" name="year" value="<?=$year?>"></div><div style="min-width:300px"><label>Neptune game</label><select name="game_id"><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$game===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' '.$g['name'])?></option><?php endforeach;?></select></div><button><i class="fa-solid fa-magnifying-glass"></i> Find Events</button></form></div>
<?php if($found):?><div class="card" style="margin-top:16px"><h2><?=$year?> Events for #<?=e($team)?></h2><div class="match-list"><?php foreach($found as $f):$key=$f['key']??'';$known=$byKey[$key]??null;?><div class="match-row"><div class="match-heading"><div><b><?=e($f['name']??$key)?></b><div class="muted"><?=e(($f['start_date']??'').' – '.($f['end_date']??''))?> · <?=e($key)?></div><?php if($known):?><div class="match-summary"><span><b><?=e($known['team_count'])?></b> teams</span><span><b><?=e($known['match_count'])?></b> matches</span><span><b><?=e($known['pit_complete'])?></b> pit complete</span></div><?php endif;?></div><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="import_event"><input type="hidden" name="event_key" value="<?=e($key)?>"><input type="hidden" name="team_number" value="<?=$team?>"><input type="hidden" name="year" value="<?=$year?>"><input type="hidden" name="game_id" value="<?=$game?>"><button><i class="fa-solid fa-cloud-arrow-down"></i> <?=$known?'Refresh Roster / Schedule':'Import Event / Roster'?></button></form></div></div><?php endforeach;?></div></div><?php endif;?>
<div class="card" style="margin-top:16px"><h2>Imported TBA Events</h2><div class="table-wrap"><table class="table"><tr><th>Event</th><th>Stage</th><th>Roster</th><th>Schedule</th><th>Pit</th><th>Last check</th><th></th></tr><?php foreach($imported as $ev):?><tr><td><b><?=e($ev['name'])?></b><?php if($ev['is_current']):?><div><span class="pill"><i class="fa-solid fa-location-dot"></i> Current</span></div><?php endif;?></td><td><?=e(str_replace('_',' ',strtoupper($ev['event_status'])))?></td><td><?=e($ev['team_count'])?> teams</td><td><?php if((int)$ev['match_count']>0):?><span class="success"><i class="fa-solid fa-circle-check"></i> <?=e($ev['match_count'])?> matches</span><?php else:?><span class="muted"><i class="fa-solid fa-clock"></i> Not published</span><?php endif;?></td><td><?=e($ev['pit_complete'])?> / <?=e($ev['team_count'])?></td><td><?=e($ev['last_tba_sync_at']?:'—')?> UTC</td><td><div class="toolbar" style="margin:0"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="import_event"><input type="hidden" name="event_key" value="<?=e($ev['tba_event_key'])?>"><input type="hidden" name="team_number" value="<?=$team?>"><input type="hidden" name="year" value="<?=$year?>"><input type="hidden" name="game_id" value="<?=$ev['game_id']?>"><button class="secondary"><i class="fa-solid fa-rotate"></i> <?=$ev['match_count']?'Refresh':'Check Schedule'?></button></form><a class="btn secondary" href="<?=e(base_url('pit/index.php?event_id='.$ev['id']))?>"><i class="fa-solid fa-clipboard-list"></i> Pit</a><?php if($ev['match_count']):?><a class="btn secondary" href="match-control.php?event_id=<?=$ev['id']?>"><i class="fa-solid fa-tower-broadcast"></i> Matches</a><?php endif;?></div></td></tr><?php endforeach;?></table></div></div>
<?php include dirname(__DIR__).'/partials_footer.php';
