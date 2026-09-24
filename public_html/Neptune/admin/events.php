<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$msg='';
$error='';
$selectedEvent=(int)($_GET['event_id']??$_POST['event_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $op=$_POST['op']??'create';
    try{
        if($op==='create'){
            $name=trim((string)($_POST['name']??''));
            $game=(int)($_POST['game_id']??0);
            if($name===''||$game<1)throw new RuntimeException('Event name and game are required.');

            $gs=$pdo->prepare('SELECT id,current_revision_id FROM games WHERE id=? AND organization_id=? AND is_archived=0');
            $gs->execute([$game,$org]);
            $gameRow=$gs->fetch();
            if(!$gameRow)throw new RuntimeException('Events can only use a game configuration owned by your organization. Clone a shared game first.');
            $revisionId=(int)($gameRow['current_revision_id']??0);
            if($revisionId<=0)throw new RuntimeException('Publish the game configuration before creating an event.');

            $pdo->prepare(
                "INSERT INTO events(organization_id,game_id,game_revision_id,name,event_code,tba_event_key,start_date,end_date,active,event_status,is_current)
                 VALUES(?,?,?,?,?,?,?,?,1,?,0)"
            )->execute([
                $org,$game,$revisionId,$name,
                trim((string)($_POST['event_code']??''))?:null,
                trim((string)($_POST['tba_event_key']??''))?:null,
                $_POST['start_date']?:null,
                $_POST['end_date']?:null,
                !empty($_POST['open_pit'])?'pit_open':'planned'
            ]);
            $selectedEvent=(int)$pdo->lastInsertId();
            if(!empty($_POST['make_current'])){
                $pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?')->execute([$org]);
                $pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?")->execute([$selectedEvent,$org]);
            }
            $msg='Event created and pinned to the current published game revision.';
        }elseif($op==='set_current'){
            $id=(int)$_POST['event_id'];
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?')->execute([$org]);
            $s=$pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?");
            $s->execute([$id,$org]);
            $pdo->commit();
            $selectedEvent=$id;
            $msg='Current event updated and pit scouting is available.';
        }elseif($op==='status'){
            $id=(int)$_POST['event_id'];
            $status=$_POST['status']??'pit_open';
            if(!in_array($status,['planned','pit_open','schedule_ready','running','complete'],true))throw new RuntimeException('Invalid event status.');
            $pdo->prepare('UPDATE events SET event_status=? WHERE id=? AND organization_id=?')->execute([$status,$id,$org]);
            $selectedEvent=$id;
            $msg='Event status updated.';
        }elseif($op==='update_revision'){
            $id=(int)$_POST['event_id'];
            $s=$pdo->prepare(
                'SELECT e.id,e.game_id,e.game_revision_id,g.current_revision_id
                 FROM events e
                 JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
                 WHERE e.id=? AND e.organization_id=?'
            );
            $s->execute([$id,$org]);
            $ev=$s->fetch();
            if(!$ev)throw new RuntimeException('Event not found.');
            $currentRevisionId=(int)($ev['current_revision_id']??0);
            if($currentRevisionId<=0)throw new RuntimeException('This game does not have a published revision.');

            if((int)$ev['game_revision_id']===$currentRevisionId){
                $selectedEvent=$id;
                $msg='This event already uses the latest published revision.';
            }else{
                $checks=[
                    ['SELECT COUNT(*) FROM scouting_actions WHERE organization_id=? AND event_id=? AND deleted_at IS NULL',[$org,$id]],
                    ['SELECT COUNT(*) FROM pit_scouting WHERE organization_id=? AND event_id=?',[$org,$id]],
                    ['SELECT COUNT(*) FROM pre_scouting WHERE organization_id=? AND event_id=?',[$org,$id]],
                    ['SELECT COUNT(*) FROM scout_sessions WHERE organization_id=? AND event_id=?',[$org,$id]],
                    ["SELECT COUNT(*) FROM matches WHERE organization_id=? AND event_id=? AND (started_at IS NOT NULL OR state IN ('running','paused','ended'))",[$org,$id]],
                ];
                $used=0;
                foreach($checks as [$sql,$params]){
                    $c=$pdo->prepare($sql);
                    $c->execute($params);
                    $used+=(int)$c->fetchColumn();
                }
                if($used>0)throw new RuntimeException('This event already contains scouting data or started matches, so its game revision is locked.');
                $pdo->prepare('UPDATE events SET game_revision_id=? WHERE id=? AND organization_id=?')->execute([$currentRevisionId,$id,$org]);
                $selectedEvent=$id;
                $msg='Event updated to the latest published game revision.';
            }
        }elseif($op==='add_roster'){
            $id=(int)$_POST['event_id'];
            $s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=?');
            $s->execute([$id,$org]);
            if(!$s->fetchColumn())throw new RuntimeException('Event not found.');
            $text=(string)($_POST['team_numbers']??'');
            preg_match_all('/\b\d{1,5}\b/',$text,$m);
            $nums=array_values(array_unique(array_map('intval',$m[0]??[])));
            $nums=array_values(array_filter($nums,fn($n)=>$n>0));
            if(!$nums)throw new RuntimeException('Paste at least one FRC team number.');
            $ins=$pdo->prepare("INSERT INTO event_teams(event_id,frc_team_number,tba_team_key) VALUES(?,?,?) ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))");
            foreach($nums as $n)$ins->execute([$id,$n,'frc'.$n]);
            $pdo->prepare("UPDATE events SET event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?")->execute([$id,$org]);
            $selectedEvent=$id;
            $msg=count($nums).' team numbers added to the event roster.';
        }elseif($op==='remove_team'){
            $id=(int)$_POST['event_id'];
            $team=(int)$_POST['team_number'];
            $s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=?');
            $s->execute([$id,$org]);
            if(!$s->fetchColumn())throw new RuntimeException('Event not found.');
            $pdo->prepare('DELETE FROM event_teams WHERE event_id=? AND frc_team_number=?')->execute([$id,$team]);
            $selectedEvent=$id;
            $msg='Team removed from the event roster.';
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}

$s=$pdo->prepare(
    "SELECT g.id,g.name,g.season_year,g.current_revision_id,gr.revision_number
     FROM games g
     JOIN game_revisions gr ON gr.id=g.current_revision_id AND gr.status='published'
     WHERE g.organization_id=? AND g.is_archived=0
     ORDER BY g.season_year DESC,g.name"
);
$s->execute([$org]);
$games=$s->fetchAll();

$s=$pdo->prepare(
    "SELECT e.*,g.name game_name,
            er.revision_number game_revision_number,
            cr.revision_number current_revision_number,
            g.current_revision_id,
            (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
            (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,
            (SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete
     FROM events e
     JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
     JOIN game_revisions er ON er.id=e.game_revision_id AND er.game_id=e.game_id
     LEFT JOIN game_revisions cr ON cr.id=g.current_revision_id
     WHERE e.organization_id=?
     ORDER BY e.is_current DESC,COALESCE(e.start_date,'9999-12-31'),e.id DESC"
);
$s->execute([$org]);
$events=$s->fetchAll();
if(!$selectedEvent&&$events)$selectedEvent=(int)$events[0]['id'];

$roster=[];
$selected=null;
if($selectedEvent){
    foreach($events as $ev){
        if((int)$ev['id']===$selectedEvent){$selected=$ev;break;}
    }
    if($selected){
        $s=$pdo->prepare("SELECT et.*,ps.status,ps.updated_at FROM event_teams et LEFT JOIN pit_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number WHERE et.event_id=? ORDER BY et.frc_team_number");
        $s->execute([$org,$selectedEvent]);
        $roster=$s->fetchAll();
    }
}

$pageTitle='Event Setup';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>SATURN</span><small>Event Administration</small></div><h1 style="margin-bottom:4px">Event Setup</h1><div class="muted">Create the event and open pit scouting before a match schedule exists. Each event is pinned to one immutable published game revision.</div></div><div class="toolbar"><a class="btn secondary" href="tba-sync.php"><i class="fa-solid fa-cloud-arrow-down"></i> TBA Sync</a><a class="btn secondary" href="<?=e(base_url('pit/index.php'.($selectedEvent?'?event_id='.$selectedEvent:'')))?>"><i class="fa-solid fa-clipboard-list"></i> Pit Scouting</a></div></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if(!$games):?><div class="notice bad"><b>No published organization-owned game is available.</b> Create or clone a game, then publish its draft before creating an event.</div><?php endif;?>
<div class="grid">
<div class="card"><h2>Create Event Early</h2><p class="muted">Use this as soon as you know where you are going. Neptune pins the event to the game's current published revision, so later game edits cannot silently alter this event.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="create"><label>Name</label><input name="name" required placeholder="FIT District Waco Event"><label>Game</label><select name="game_id" required><?php foreach($games as $g):?><option value="<?=$g['id']?>"><?=e($g['season_year'].' '.$g['name'].' · Rev '.$g['revision_number'])?></option><?php endforeach;?></select><div class="grid"><div><label>Start</label><input type="date" name="start_date"></div><div><label>End</label><input type="date" name="end_date"></div></div><label>TBA event key <span class="muted">optional</span></label><input name="tba_event_key" placeholder="2026tx..."><label>Internal event code <span class="muted">optional</span></label><input name="event_code"><div class="choice-grid" style="margin-top:14px"><label class="choice-chip"><input type="checkbox" name="open_pit" value="1" checked> <span>Open pit scouting</span></label><label class="choice-chip"><input type="checkbox" name="make_current" value="1" checked> <span>Make current event</span></label></div><div class="toolbar"><button><i class="fa-solid fa-plus"></i> Create Event</button></div></form></div>
<div class="card"><h2>Configured Events</h2><div class="event-card-list"><?php foreach($events as $ev):?><a class="event-mini-card <?=$ev['is_current']?'current':''?>" href="?event_id=<?=$ev['id']?>"><div><b><?=e($ev['name'])?></b><div class="muted"><?=e($ev['game_name'].' · Rev '.$ev['game_revision_number'])?></div></div><div class="event-mini-stats"><span class="pill"><?=e(str_replace('_',' ',strtoupper($ev['event_status'])))?></span><span><?=e($ev['team_count'])?> teams</span><span><?=e($ev['match_count'])?> matches</span><span><?=e($ev['pit_complete'])?> pit complete</span></div></a><?php endforeach;?></div></div>
</div>
<?php if($selected):?>
<div class="grid" style="margin-top:16px">
<div class="card"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0"><?=e($selected['name'])?></h2><div class="muted"><?=e($selected['is_current']?'Current event · ':'')?><?=e(str_replace('_',' ',strtoupper($selected['event_status'])))?> · <?=e($selected['game_name'])?> Rev <?=e($selected['game_revision_number'])?></div></div><?php if(!$selected['is_current']):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="set_current"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><button><i class="fa-solid fa-location-dot"></i> Make Current</button></form><?php endif;?></div>
<?php if((int)$selected['game_revision_id']!==(int)$selected['current_revision_id']):?><form method="post" style="margin-top:12px" data-confirm="Move this event to the latest published game revision? Neptune will refuse if scouting data or started matches already exist." data-confirm-title="Update event revision" data-confirm-button="Update revision"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="update_revision"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><button class="secondary"><i class="fa-solid fa-code-branch"></i> Update to Published Rev <?=e($selected['current_revision_number'])?></button></form><?php endif;?>
<form method="post" class="toolbar"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="status"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><div style="min-width:220px"><label style="margin-top:0">Event stage</label><select name="status"><option value="planned" <?=$selected['event_status']==='planned'?'selected':''?>>Planned</option><option value="pit_open" <?=$selected['event_status']==='pit_open'?'selected':''?>>Pit Scouting Open</option><option value="schedule_ready" <?=$selected['event_status']==='schedule_ready'?'selected':''?>>Schedule Ready</option><option value="running" <?=$selected['event_status']==='running'?'selected':''?>>Matches Running</option><option value="complete" <?=$selected['event_status']==='complete'?'selected':''?>>Complete</option></select></div><button class="secondary"><i class="fa-solid fa-floppy-disk"></i> Save Stage</button></form><div class="event-sync-status"><div><span>Roster</span><b><?=e($selected['team_count'])?> teams</b><small><?=e($selected['roster_synced_at']?'TBA synced '.$selected['roster_synced_at'].' UTC':'Manual / not TBA-synced yet')?></small></div><div><span>Schedule</span><b><?=e($selected['match_count'])?> matches</b><small><?=e($selected['schedule_synced_at']?'TBA synced '.$selected['schedule_synced_at'].' UTC':'Not published / not synced yet')?></small></div><div><span>Pit</span><b><?=e($selected['pit_complete'])?> / <?=e($selected['team_count'])?></b><small>Completed forms</small></div></div></div>
<div class="card"><h2>Add / Paste Event Roster</h2><p class="muted">Paste team numbers from a FIRST event list, spreadsheet, email, or any text. Neptune extracts the numbers and removes duplicates.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="add_roster"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><label>Team numbers</label><textarea name="team_numbers" rows="8" placeholder="118&#10;148&#10;324&#10;6369&#10;6773"></textarea><div class="toolbar"><button><i class="fa-solid fa-users"></i> Add to Roster</button></div></form></div>
</div>
<div class="card" style="margin-top:16px"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Event Roster</h2><a class="btn good" href="<?=e(base_url('pit/index.php?event_id='.$selectedEvent))?>"><i class="fa-solid fa-clipboard-check"></i> Start Pit Scouting</a></div><?php if(!$roster):?><div class="notice">No teams yet. Paste a roster above or use TBA Sync.</div><?php else:?><div class="table-wrap"><table class="table"><tr><th>Team</th><th>Nickname</th><th>Pit status</th><th></th></tr><?php foreach($roster as $r):?><tr><td><b>#<?=e($r['frc_team_number'])?></b></td><td><?=e($r['nickname']?:'—')?></td><td><?php if($r['status']==='complete'):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Complete</span><?php elseif($r['status']==='in_progress'):?><span class="pill">In progress</span><?php else:?><span class="muted">Not scouted</span><?php endif;?></td><td><div class="toolbar" style="margin:0"><a class="btn secondary" href="<?=e(base_url('pit/scout.php?event_id='.$selectedEvent.'&team='.$r['frc_team_number']))?>"><i class="fa-solid fa-clipboard-list"></i> Scout</a><form method="post" data-confirm="Remove this team from the event roster?" data-confirm-title="Remove team" data-confirm-button="Remove" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="remove_team"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><input type="hidden" name="team_number" value="<?=$r['frc_team_number']?>"><button class="danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form></div></td></tr><?php endforeach;?></table></div><?php endif;?></div>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
