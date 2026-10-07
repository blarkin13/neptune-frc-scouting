<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);$org=(int)$u['organization_id'];$canChangeRevision=in_array((string)($u['role']??''),['owner','admin'],true);$msg='';$error='';$selectedEvent=(int)($_GET['event_id']??$_POST['event_id']??0);


function neptune_accessible_games(PDO $pdo,int $org): array {
    $s=$pdo->prepare(
        "SELECT DISTINCT g.id,g.name,g.season_year,g.organization_id,g.current_revision_id,
                CASE WHEN g.organization_id=? THEN 0 ELSE 1 END AS is_shared
         FROM games g
         LEFT JOIN game_config_shares gs
           ON gs.game_id=g.id
          AND gs.recipient_organization_id=?
         WHERE g.is_archived=0
           AND (g.organization_id=? OR gs.id IS NOT NULL)
         ORDER BY g.season_year DESC,g.name"
    );
    $s->execute([$org,$org,$org]);
    return $s->fetchAll();
}

function neptune_event_game_revision_id(PDO $pdo,int $org,int $game): int {
    $s=$pdo->prepare(
        "SELECT g.id,g.current_revision_id
         FROM games g
         LEFT JOIN game_config_shares gs
           ON gs.game_id=g.id
          AND gs.recipient_organization_id=?
         WHERE g.id=?
           AND g.is_archived=0
           AND (g.organization_id=? OR gs.id IS NOT NULL)
         LIMIT 1"
    );
    $s->execute([$org,$game,$org]);
    $row=$s->fetch();

    if(!$row) {
        throw new RuntimeException('The selected Neptune game is not available to this organization.');
    }

    $revisionId=(int)($row['current_revision_id']??0);

    if($revisionId>0) {
        $r=$pdo->prepare(
            "SELECT id
             FROM game_revisions
             WHERE id=? AND game_id=? AND status='published'
             LIMIT 1"
        );
        $r->execute([$revisionId,$game]);
        if((int)$r->fetchColumn()>0) return $revisionId;
    }

    $r=$pdo->prepare(
        "SELECT id
         FROM game_revisions
         WHERE game_id=? AND status='published'
         ORDER BY revision_number DESC,id DESC
         LIMIT 1"
    );
    $r->execute([$game]);
    $revisionId=(int)$r->fetchColumn();

    if($revisionId<1) {
        throw new RuntimeException(
            'The selected Neptune game does not have a published revision. Publish the game in VULCAN before creating or syncing an event.'
        );
    }

    return $revisionId;
}


if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$op=$_POST['op']??'create';
    try{
        if($op==='create'){
            $name=trim($_POST['name']??'');$game=(int)($_POST['game_id']??0);if($name===''||$game<1)throw new RuntimeException('Event name and game are required.');
            $gameRevisionId=neptune_event_game_revision_id($pdo,$org,$game);
            $pdo->prepare("INSERT INTO events(organization_id,game_id,game_revision_id,name,event_code,tba_event_key,start_date,end_date,active,event_status,is_current) VALUES(?,?,?,?,?,?,?,?,1,?,0)")->execute([$org,$game,$gameRevisionId,$name,trim($_POST['event_code']??'')?:null,trim($_POST['tba_event_key']??'')?:null,$_POST['start_date']?:null,$_POST['end_date']?:null,!empty($_POST['open_pit'])?'pit_open':'planned']);
            $selectedEvent=(int)$pdo->lastInsertId();
            if(!empty($_POST['make_current'])){$pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?')->execute([$org]);$pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?")->execute([$selectedEvent,$org]);}
            $msg='Event created. You can add the roster now; a match schedule is not required for pit scouting.';
        } elseif($op==='set_current'){
            $id=(int)$_POST['event_id'];$pdo->beginTransaction();$pdo->prepare('UPDATE events SET is_current=0 WHERE organization_id=?')->execute([$org]);$s=$pdo->prepare("UPDATE events SET is_current=1,event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?");$s->execute([$id,$org]);$pdo->commit();$selectedEvent=$id;$msg='Current event updated and pit scouting is available.';
        } elseif($op==='status'){
            $id=(int)$_POST['event_id'];$status=$_POST['status']??'pit_open';if(!in_array($status,['planned','pit_open','schedule_ready','running','complete'],true))throw new RuntimeException('Invalid event status.');$pdo->prepare('UPDATE events SET event_status=? WHERE id=? AND organization_id=?')->execute([$status,$id,$org]);$selectedEvent=$id;$msg='Event status updated.';
        } elseif($op==='update_revision'){
            if(!$canChangeRevision)throw new RuntimeException('Owner or Admin access is required to change an event game revision.');
            $id=(int)($_POST['event_id']??0);
            $revisionId=(int)($_POST['revision_id']??0);
            $s=$pdo->prepare(
                "SELECT e.id,e.name,e.game_id,e.game_revision_id,gr.revision_number current_revision_number
"
               ."FROM events e JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
"
               ."WHERE e.id=? AND e.organization_id=? LIMIT 1"
            );
            $s->execute([$id,$org]);
            $event=$s->fetch();
            if(!$event)throw new RuntimeException('Event not found.');
            $r=$pdo->prepare("SELECT id,revision_number FROM game_revisions WHERE id=? AND game_id=? AND status='published' LIMIT 1");
            $r->execute([$revisionId,(int)$event['game_id']]);
            $target=$r->fetch();
            if(!$target)throw new RuntimeException('That published revision is not available for this event game.');
            $active=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE event_id=? AND organization_id=? AND state IN ('running','paused')");
            $active->execute([$id,$org]);
            if((int)$active->fetchColumn()>0)throw new RuntimeException('End the running or paused match before changing this event revision.');
            if((int)$event['game_revision_id']!==$revisionId){
                $pdo->prepare('UPDATE events SET game_revision_id=? WHERE id=? AND organization_id=?')->execute([$revisionId,$id,$org]);
                $msg=$event['name'].' moved from Revision '.(int)$event['current_revision_number'].' to Revision '.(int)$target['revision_number'].'.';
            }else{$msg=$event['name'].' is already using Revision '.(int)$target['revision_number'].'.';}
            $selectedEvent=$id;
        } elseif($op==='add_roster'){
            $id=(int)$_POST['event_id'];$s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=?');$s->execute([$id,$org]);if(!$s->fetchColumn())throw new RuntimeException('Event not found.');
            $text=(string)($_POST['team_numbers']??'');preg_match_all('/\b\d{1,5}\b/',$text,$m);$nums=array_values(array_unique(array_map('intval',$m[0]??[])));$nums=array_values(array_filter($nums,fn($n)=>$n>0));if(!$nums)throw new RuntimeException('Paste at least one FRC team number.');
            $ins=$pdo->prepare("INSERT INTO event_teams(event_id,frc_team_number,tba_team_key) VALUES(?,? ,?) ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))");foreach($nums as $n)$ins->execute([$id,$n,'frc'.$n]);
            $pdo->prepare("UPDATE events SET event_status=IF(event_status='planned','pit_open',event_status) WHERE id=? AND organization_id=?")->execute([$id,$org]);$selectedEvent=$id;$msg=count($nums).' team numbers added to the event roster.';
        } elseif($op==='remove_team'){
            $id=(int)$_POST['event_id'];$team=(int)$_POST['team_number'];$s=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=?');$s->execute([$id,$org]);if(!$s->fetchColumn())throw new RuntimeException('Event not found.');
            $pdo->prepare('DELETE FROM event_teams WHERE event_id=? AND frc_team_number=?')->execute([$id,$team]);$selectedEvent=$id;$msg='Team removed from the event roster.';
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$games=neptune_accessible_games($pdo,$org);
$s=$pdo->prepare("SELECT e.*,g.name game_name,g.current_revision_id latest_revision_id,gr.revision_number game_revision_number,latest.revision_number latest_revision_number,(SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,(SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,(SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete,(SELECT COUNT(*) FROM matches mx WHERE mx.event_id=e.id AND mx.organization_id=e.organization_id AND mx.state IN ('running','paused')) active_match_count FROM events e JOIN games g ON g.id=e.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id LEFT JOIN game_revisions latest ON latest.id=g.current_revision_id AND latest.game_id=g.id WHERE e.organization_id=? ORDER BY e.is_current DESC,COALESCE(e.start_date,'9999-12-31'),e.id DESC");$s->execute([$org]);$events=$s->fetchAll();if(!$selectedEvent&&$events)$selectedEvent=(int)$events[0]['id'];
$roster=[];$selected=null;if($selectedEvent){foreach($events as $ev)if((int)$ev['id']===$selectedEvent){$selected=$ev;break;}if($selected){$s=$pdo->prepare("SELECT et.*,ps.status,ps.updated_at FROM event_teams et LEFT JOIN pit_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number WHERE et.event_id=? ORDER BY et.frc_team_number");$s->execute([$org,$selectedEvent]);$roster=$s->fetchAll();}}
$pageTitle='Event Setup';$moduleName='SATURN';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>SATURN</span><small>Event Administration</small></div><h1 style="margin-bottom:4px">Event Setup</h1><div class="muted">Create the event and open pit scouting before a match schedule exists. TBA can fill in or refresh the official roster and schedule later.</div></div><div class="toolbar"><a class="btn secondary" href="tba-sync.php"><i class="fa-solid fa-cloud-arrow-down"></i> TBA Sync</a><a class="btn secondary" href="<?=e(base_url('pit/index.php'.($selectedEvent?'?event_id='.$selectedEvent:'')))?>"><i class="fa-solid fa-clipboard-list"></i> Pit Scouting</a></div></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<div class="grid">
<div class="card"><h2>Create Event Early</h2><p class="muted">Use this as soon as you know where you are going. You can paste the pit roster next, then sync TBA whenever its data becomes available.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="create"><label>Name</label><input name="name" required placeholder="FIT District Waco Event"><label>Game</label><select name="game_id" required><?php foreach($games as $g):?><option value="<?=$g['id']?>"><?=e($g['season_year'].' '.$g['name'])?></option><?php endforeach;?></select><div class="grid"><div><label>Start</label><input type="date" name="start_date"></div><div><label>End</label><input type="date" name="end_date"></div></div><label>TBA event key <span class="muted">optional</span></label><input name="tba_event_key" placeholder="2026tx..."><label>Internal event code <span class="muted">optional</span></label><input name="event_code"><div class="choice-grid" style="margin-top:14px"><label class="choice-chip"><input type="checkbox" name="open_pit" value="1" checked> <span>Open pit scouting</span></label><label class="choice-chip"><input type="checkbox" name="make_current" value="1" checked> <span>Make current event</span></label></div><div class="toolbar"><button><i class="fa-solid fa-plus"></i> Create Event</button></div></form></div>
<div class="card"><h2>Configured Events</h2><div class="event-card-list"><?php foreach($events as $ev):?><a class="event-mini-card <?=$ev['is_current']?'current':''?>" href="?event_id=<?=$ev['id']?>"><div><b><?=e($ev['name'])?></b><div class="muted"><?=e($ev['game_name'])?></div></div><div class="event-mini-stats"><span class="pill"><?=e(str_replace('_',' ',strtoupper($ev['event_status'])))?></span><span><?=e($ev['team_count'])?> teams</span><span><?=e($ev['match_count'])?> matches</span><span><?=e($ev['pit_complete'])?> pit complete</span><span>Rev <?=e($ev['game_revision_number'])?><?=((int)($ev['latest_revision_id']??0)>0 && (int)$ev['game_revision_id']!==(int)$ev['latest_revision_id'])?' → '.e($ev['latest_revision_number']):''?></span></div></a><?php endforeach;?></div></div>
</div>
<?php if($selected):?>
<div class="grid" style="margin-top:16px">
<div class="card"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0"><?=e($selected['name'])?></h2><div class="muted"><?=e($selected['is_current']?'Current event · ':'')?><?=e(str_replace('_',' ',strtoupper($selected['event_status'])))?></div></div><?php if(!$selected['is_current']):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="set_current"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><button><i class="fa-solid fa-location-dot"></i> Make Current</button></form><?php endif;?></div><form method="post" class="toolbar"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="status"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><div style="min-width:220px"><label style="margin-top:0">Event stage</label><select name="status"><option value="planned" <?=$selected['event_status']==='planned'?'selected':''?>>Planned</option><option value="pit_open" <?=$selected['event_status']==='pit_open'?'selected':''?>>Pit Scouting Open</option><option value="schedule_ready" <?=$selected['event_status']==='schedule_ready'?'selected':''?>>Schedule Ready</option><option value="running" <?=$selected['event_status']==='running'?'selected':''?>>Matches Running</option><option value="complete" <?=$selected['event_status']==='complete'?'selected':''?>>Complete</option></select></div><button class="secondary"><i class="fa-solid fa-floppy-disk"></i> Save Stage</button></form><div class="event-sync-status"><div><span>Roster</span><b><?=e($selected['team_count'])?> teams</b><small><?=e($selected['roster_synced_at']?'TBA synced '.$selected['roster_synced_at'].' UTC':'Manual / not TBA-synced yet')?></small></div><div><span>Schedule</span><b><?=e($selected['match_count'])?> matches</b><small><?=e($selected['schedule_synced_at']?'TBA synced '.$selected['schedule_synced_at'].' UTC':'Not published / not synced yet')?></small></div><div><span>Pit</span><b><?=e($selected['pit_complete'])?> / <?=e($selected['team_count'])?></b><small>Completed forms</small></div></div>
<?php $revisionBehind=(int)($selected['latest_revision_id']??0)>0 && (int)$selected['game_revision_id']!==(int)$selected['latest_revision_id'];?>
<div style="margin-top:16px;padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel2)">
  <div class="toolbar" style="justify-content:space-between;align-items:center;margin:0">
    <div>
      <div style="font-weight:900"><i class="fa-solid fa-code-branch"></i> Game Revision</div>
      <div class="muted" style="margin-top:4px">This event is pinned to <b>Revision <?=e($selected['game_revision_number'])?></b><?php if($revisionBehind):?>. The game now has <b>Revision <?=e($selected['latest_revision_number'])?></b> published.<?php else:?> and is up to date.<?php endif;?></div>
    </div>
    <?php if($revisionBehind && $canChangeRevision):?>
      <?php if((int)$selected['active_match_count']>0):?>
        <button class="secondary" type="button" disabled><i class="fa-solid fa-lock"></i> Match Active</button>
      <?php else:?>
        <form method="post" style="margin:0" data-confirm="Update <?=e($selected['name'])?> from Revision <?=e($selected['game_revision_number'])?> to Revision <?=e($selected['latest_revision_number'])?>? This changes the full game configuration used by the event; existing scouting records are kept." data-confirm-title="Update event revision" data-confirm-button="Update Event">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="update_revision"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><input type="hidden" name="revision_id" value="<?=$selected['latest_revision_id']?>">
          <button type="submit"><i class="fa-solid fa-arrow-up-right-dots"></i> Update to Rev <?=e($selected['latest_revision_number'])?></button>
        </form>
      <?php endif;?>
    <?php elseif($revisionBehind):?>
      <span class="pill"><i class="fa-solid fa-lock"></i> Owner/Admin can update</span>
    <?php else:?>
      <span class="pill"><i class="fa-solid fa-circle-check"></i> Latest revision</span>
    <?php endif;?>
  </div>
</div>
</div>
<div class="card"><h2>Add / Paste Event Roster</h2><p class="muted">Paste team numbers from a FIRST event list, spreadsheet, email, or any text. Neptune extracts the numbers and removes duplicates.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="add_roster"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><label>Team numbers</label><textarea name="team_numbers" rows="8" placeholder="118&#10;148&#10;324&#10;6369&#10;6773"></textarea><div class="toolbar"><button><i class="fa-solid fa-users"></i> Add to Roster</button></div></form></div>
</div>
<div class="card" style="margin-top:16px"><div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Event Roster</h2><a class="btn good" href="<?=e(base_url('pit/index.php?event_id='.$selectedEvent))?>"><i class="fa-solid fa-clipboard-check"></i> Start Pit Scouting</a></div><?php if(!$roster):?><div class="notice">No teams yet. Paste a roster above or use TBA Sync.</div><?php else:?><div class="table-wrap"><table class="table"><tr><th>Team</th><th>Nickname</th><th>Pit status</th><th></th></tr><?php foreach($roster as $r):?><tr><td><b>#<?=e($r['frc_team_number'])?></b></td><td><?=e($r['nickname']?:'—')?></td><td><?php if($r['status']==='complete'):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Complete</span><?php elseif($r['status']==='in_progress'):?><span class="pill">In progress</span><?php else:?><span class="muted">Not scouted</span><?php endif;?></td><td><div class="toolbar" style="margin:0"><a class="btn secondary" href="<?=e(base_url('pit/scout.php?event_id='.$selectedEvent.'&team='.$r['frc_team_number']))?>"><i class="fa-solid fa-clipboard-list"></i> Scout</a><form method="post" data-confirm="Remove this team from the event roster?" data-confirm-title="Remove team" data-confirm-button="Remove" data-confirm-danger="1"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="op" value="remove_team"><input type="hidden" name="event_id" value="<?=$selectedEvent?>"><input type="hidden" name="team_number" value="<?=$r['frc_team_number']?>"><button class="danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form></div></td></tr><?php endforeach;?></table></div><?php endif;?></div>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
