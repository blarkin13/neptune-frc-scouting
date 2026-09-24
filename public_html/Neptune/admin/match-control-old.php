<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];$msg='';$selectedEvent=(int)($_GET['event_id']??$_POST['event_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$op=$_POST['op']??'';$id=(int)($_POST['match_id']??0);
    if($id){
        $s=$pdo->prepare('SELECT m.* FROM matches m WHERE m.id=? AND m.organization_id=?');$s->execute([$id,$org]);$match=$s->fetch();
        if(!$match){$msg='Match not found.';} else {
            $pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?')->execute([$org]);
            $pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','schedule_ready',event_status) WHERE id=? AND organization_id=?")->execute([(int)$match['event_id'],$org]);
            $field=max(1,(int)$match['field_id']);
            if($op==='ready'){
                $count=$pdo->prepare('SELECT COUNT(*) FROM match_teams WHERE match_id=?');$count->execute([$id]);$teamCount=(int)$count->fetchColumn();
                if($teamCount<1){$msg='This match has no imported teams. Sync the event schedule from The Blue Alliance first.';} else {
                    $newRun=(int)$match['run_number'];
                    if($match['state']==='ended'){
                        $oldRun=$newRun;
                        $newRun++;
                        $pdo->beginTransaction();
                        try{
                            $pdo->prepare("UPDATE scouting_actions SET deleted_at=COALESCE(deleted_at,UTC_TIMESTAMP()),deleted_by=COALESCE(deleted_by,?),deletion_reason=COALESCE(deletion_reason,'match_rescout') WHERE organization_id=? AND match_id=? AND match_run_number=? AND deleted_at IS NULL")
                                ->execute([$u['id'],$org,$id,$oldRun]);
                            $pdo->prepare("UPDATE scout_sessions SET status='closed' WHERE organization_id=? AND match_id=? AND match_run_number=? AND status<>'closed'")->execute([$org,$id,$oldRun]);
                            $pdo->prepare("UPDATE matches SET state='ready',run_number=?,started_at=NULL,ended_at=NULL,paused_at=NULL,total_pause_seconds=0 WHERE id=? AND organization_id=?")->execute([$newRun,$id,$org]);
                            $pdo->commit();
                            $msg='Match reopened for re-scouting as run '.$newRun.'. Run '.$oldRun.' actions were voided and no longer count in analytics.';
                        } catch(Throwable $ex){
                            if($pdo->inTransaction())$pdo->rollBack();
                            throw $ex;
                        }
                    } else {
                        $pdo->prepare("UPDATE matches SET state='ready',run_number=?,started_at=NULL,ended_at=NULL,paused_at=NULL,total_pause_seconds=0 WHERE id=? AND organization_id=?")->execute([$newRun,$id,$org]);
                        $msg='Match is ready for scouts.';
                    }
                }
            } elseif($op==='start'){
                $pdo->prepare("UPDATE matches SET state='ended',ended_at=UTC_TIMESTAMP(),paused_at=NULL WHERE organization_id=? AND field_id=? AND id<>? AND state IN ('running','paused')")->execute([$org,$field,$id]);
                $pdo->prepare("UPDATE matches SET state='running',started_at=UTC_TIMESTAMP(),ended_at=NULL,paused_at=NULL,total_pause_seconds=0 WHERE id=? AND organization_id=?")->execute([$id,$org]);$pdo->prepare("UPDATE events SET event_status='running' WHERE id=? AND organization_id=?")->execute([(int)$match['event_id'],$org]);$msg='Match started.';
            } elseif($op==='pause'){
                $pdo->prepare("UPDATE matches SET state='paused',paused_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND state='running'")->execute([$id,$org]);$msg='Match paused.';
            } elseif($op==='resume'){
                $s=$pdo->prepare('SELECT paused_at,total_pause_seconds FROM matches WHERE id=? AND organization_id=?');$s->execute([$id,$org]);$m=$s->fetch();$extra=$m&&$m['paused_at']?max(0,time()-strtotime($m['paused_at'].' UTC')):0;
                $pdo->prepare("UPDATE matches SET state='running',total_pause_seconds=total_pause_seconds+?,paused_at=NULL WHERE id=? AND organization_id=?")->execute([$extra,$id,$org]);$msg='Match resumed.';
            } elseif($op==='end'){
                $pdo->prepare("UPDATE matches SET state='ended',ended_at=UTC_TIMESTAMP(),paused_at=NULL WHERE id=? AND organization_id=?")->execute([$id,$org]);$msg='Match ended. This scouting run is preserved.';
            }
        }
    }
}

$s=$pdo->prepare("SELECT e.id,e.name,g.name game_name,e.tba_event_key,e.is_current,e.event_status FROM events e JOIN games g ON g.id=e.game_id WHERE e.organization_id=? AND e.active=1 ORDER BY e.is_current DESC,COALESCE(e.start_date,'1900-01-01') DESC,e.id DESC");$s->execute([$org]);$events=$s->fetchAll();if(!$selectedEvent&&$events)$selectedEvent=(int)$events[0]['id'];
$matches=[];$robotStats=[];$nextScheduledId=0;
if($selectedEvent){
    $s=$pdo->prepare("SELECT m.*,e.name event_name,g.name game_name,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=1 LIMIT 1) red1,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=2 LIMIT 1) red2,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=3 LIMIT 1) red3,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=1 LIMIT 1) blue1,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=2 LIMIT 1) blue2,
      (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=3 LIMIT 1) blue3,
      (SELECT COUNT(*) FROM match_teams mt WHERE mt.match_id=m.id) team_count,
      (SELECT COUNT(*) FROM scouting_actions sa WHERE sa.match_id=m.id AND sa.organization_id=m.organization_id AND sa.match_run_number=m.run_number AND sa.deleted_at IS NULL) action_count,
      (SELECT COALESCE(SUM(sa.points),0) FROM scouting_actions sa WHERE sa.match_id=m.id AND sa.organization_id=m.organization_id AND sa.match_run_number=m.run_number AND sa.deleted_at IS NULL) scout_points,
      (SELECT COUNT(*) FROM scout_sessions ss WHERE ss.match_id=m.id AND ss.organization_id=m.organization_id AND ss.match_run_number=m.run_number) scout_count
      FROM matches m JOIN events e ON e.id=m.event_id JOIN games g ON g.id=m.game_id
      WHERE m.organization_id=? AND m.event_id=?
      ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f','legacy'),m.set_number,m.match_number");
    $s->execute([$org,$selectedEvent]);$matches=$s->fetchAll();
    foreach($matches as $m){if(!$nextScheduledId&&$m['state']==='scheduled')$nextScheduledId=(int)$m['id'];}
    $s=$pdo->prepare("SELECT match_id,match_run_number,frc_team_number,COUNT(*) actions,COALESCE(SUM(points),0) pts FROM scouting_actions WHERE organization_id=? AND event_id=? AND deleted_at IS NULL GROUP BY match_id,match_run_number,frc_team_number");$s->execute([$org,$selectedEvent]);
    foreach($s->fetchAll() as $r)$robotStats[$r['match_id'].'-'.$r['match_run_number'].'-'.$r['frc_team_number']]=$r;
}
$pageTitle='Match Control';$moduleName='SATURN';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>SATURN</span><small>Match Control</small></div><h1 style="margin-bottom:4px">Match Control</h1><div class="muted">Official robot assignments come from the TBA schedule. Re-scouting voids the previous run so only the new run counts.</div></div><a class="btn secondary" href="tba-sync.php"><i class="fa-solid fa-rotate"></i> TBA Sync</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
<div class="card"><form method="get" class="toolbar"><div style="min-width:320px;flex:1"><label style="margin-top:0">Event</label><select name="event_id" onchange="this.form.submit()"><?php foreach($events as $e):?><option value="<?=$e['id']?>" <?=$selectedEvent===(int)$e['id']?'selected':''?>><?=e(($e['is_current']?'★ ':'').$e['name'].' · '.$e['game_name'])?></option><?php endforeach;?></select></div></form></div>
<div class="card" style="margin-top:16px"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Matches</h2><span class="muted">Blue outline = next match to ready · blue = ready · green = running</span></div>
<?php if(!$matches):?><div class="notice">No matches are loaded. Import or refresh the event from The Blue Alliance.</div><?php else:?><div class="match-list">
<?php foreach($matches as $m):$run=(int)$m['run_number'];$isNext=((int)$m['id']===$nextScheduledId);?>
<div class="match-row <?=e($m['state'])?> <?=$isNext?'next-up':''?>">
  <div class="match-heading"><div class="match-meta"><b class="match-title"><?=e(neptune_match_label($m))?></b><span class="pill"><?=e(strtoupper($m['state']))?></span><?php if($run>1):?><span class="pill"><i class="fa-solid fa-rotate-right"></i> Run <?=$run?></span><?php endif;?></div><div class="muted"><?php if($m['scheduled_time']):?><?=e($m['scheduled_time'])?> UTC<?php endif;?></div></div>
  <div class="alliance-strip">
    <?php foreach(['red'=>'Red','blue'=>'Blue'] as $prefix=>$label):?><div class="alliance <?=$prefix?>"><span><?=$label?></span><?php for($i=1;$i<=3;$i++):$k=$prefix.$i;$team=(int)($m[$k]??0);$stat=$team?($robotStats[$m['id'].'-'.$run.'-'.$team]??null):null;?><b><?=$team?'#'.e($team):'—'?><?php if($stat):?><small><?=e(round((float)$stat['pts'],1))?> pts · <?=e($stat['actions'])?> actions</small><?php endif;?></b><?php endfor;?></div><?php endforeach;?>
  </div>
  <div class="match-summary"><span><b>Scout points:</b> <?=e(round((float)$m['scout_points'],1))?></span><span><b>Actions:</b> <?=e($m['action_count'])?></span><span><b>Scout sessions:</b> <?=e($m['scout_count'])?></span><?php if($m['red_score']!==null&&$m['blue_score']!==null):?><span><b>TBA score:</b> Red <?=e($m['red_score'])?> · Blue <?=e($m['blue_score'])?></span><?php endif;?></div>
  <div class="toolbar match-controls"><form method="post" class="toolbar" style="margin:0"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="match_id" value="<?=$m['id']?>"><input type="hidden" name="event_id" value="<?=$selectedEvent?>">
    <?php if($m['state']==='scheduled'):?><button name="op" value="ready" <?=$m['team_count']<1?'disabled':''?>><i class="fa-solid fa-circle-check"></i> Make Ready</button>
    <?php elseif($m['state']==='ended'):?><button class="secondary rescout-button" name="op" value="ready" <?=$m['team_count']<1?'disabled':''?>><i class="fa-solid fa-rotate-right"></i> Re-scout / Ready Again</button>
    <?php elseif($m['state']==='ready'):?><button class="good" name="op" value="start"><i class="fa-solid fa-play"></i> Start Match</button>
    <?php elseif($m['state']==='running'):?><button class="secondary" name="op" value="pause"><i class="fa-solid fa-pause"></i> Pause</button><button class="danger" name="op" value="end"><i class="fa-solid fa-stop"></i> End</button>
    <?php elseif($m['state']==='paused'):?><button class="good" name="op" value="resume"><i class="fa-solid fa-play"></i> Resume</button><button class="danger" name="op" value="end"><i class="fa-solid fa-stop"></i> End</button><?php endif;?></form>
    <a class="btn secondary" href="live.php?match_id=<?=$m['id']?>"><i class="fa-solid fa-eye"></i> Monitor</a>
  </div>
</div>
<?php endforeach;?></div><?php endif;?></div>
<script>
document.querySelectorAll('.rescout-button').forEach(btn=>btn.addEventListener('click',async e=>{
  e.preventDefault();
  const ok=await NeptuneUI.confirm('Re-scout this match?\n\nAll scouting actions from the current run will be voided and removed from analytics. A clean new run will start at 0 actions / 0 points.',{title:'Start a clean scouting run',confirmText:'Re-scout match',danger:true});
  if(!ok)return;
  const form=btn.closest('form');
  if(!form)return;
  const op=document.createElement('input');op.type='hidden';op.name=btn.name;op.value=btn.value;form.appendChild(op);
  form.submit();
}));
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
