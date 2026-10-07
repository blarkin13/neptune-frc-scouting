<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_event_selection.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$msg='';
$error='';
$selectedEvent=(int)($_GET['event_id']??$_POST['event_id']??0);

function manual_schedule_ajax(): bool {
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';
}

function manual_schedule_json(array $payload,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
    exit;
}

function manual_schedule_event(PDO $pdo,int $org,int $eventId): ?array {
    $s=$pdo->prepare(
        "SELECT e.*,g.name game_name,g.season_year
         FROM events e
         JOIN games g ON g.id=e.game_id
         WHERE e.id=? AND e.organization_id=?
         LIMIT 1"
    );
    $s->execute([$eventId,$org]);
    return $s->fetch()?:null;
}

function manual_schedule_roster(PDO $pdo,int $eventId): array {
    $s=$pdo->prepare(
        "SELECT frc_team_number,nickname
         FROM event_teams
         WHERE event_id=?
         ORDER BY frc_team_number"
    );
    $s->execute([$eventId]);
    return $s->fetchAll();
}

function manual_schedule_existing(PDO $pdo,int $org,int $eventId): array {
    $s=$pdo->prepare(
        "SELECT m.*,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=1 LIMIT 1) r1,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=2 LIMIT 1) r2,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Red' AND mt.station=3 LIMIT 1) r3,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=1 LIMIT 1) b1,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=2 LIMIT 1) b2,
          (SELECT mt.frc_team_number FROM match_teams mt WHERE mt.match_id=m.id AND mt.alliance='Blue' AND mt.station=3 LIMIT 1) b3,
          (SELECT COUNT(*) FROM scout_sessions ss WHERE ss.match_id=m.id) scout_session_count,
          (SELECT COUNT(*) FROM scouting_actions sa WHERE sa.match_id=m.id AND sa.deleted_at IS NULL) action_count
         FROM matches m
         WHERE m.organization_id=? AND m.event_id=? AND m.comp_level='qm'
         ORDER BY m.match_number,m.field_id,m.id"
    );
    $s->execute([$org,$eventId]);
    return $s->fetchAll();
}

function manual_schedule_team_numbers(array $row): array {
    return [
        'r1'=>(int)($row['r1']??0),
        'r2'=>(int)($row['r2']??0),
        'r3'=>(int)($row['r3']??0),
        'b1'=>(int)($row['b1']??0),
        'b2'=>(int)($row['b2']??0),
        'b3'=>(int)($row['b3']??0),
    ];
}

function manual_schedule_row_has_team(array $row): bool {
    foreach(manual_schedule_team_numbers($row) as $team){if($team>0)return true;}
    return false;
}

function manual_schedule_team_options(array $roster,int $selected=0): string {
    $out='<option value="">Select robot</option>';
    foreach($roster as $team){
        $num=(int)$team['frc_team_number'];
        $label='#'.$num;
        if(trim((string)($team['nickname']??''))!=='')$label.=' · '.trim((string)$team['nickname']);
        $out.='<option value="'.$num.'"'.($selected===$num?' selected':'').'>'.e($label).'</option>';
    }
    return $out;
}

function manual_schedule_save(PDO $pdo,int $org,array $event,array $allowedTeams,array $row): int {
    if(trim((string)($event['tba_event_key']??''))!==''){
        throw new RuntimeException('This event is linked to TBA. Use TBA Sync for its official schedule.');
    }

    $eventId=(int)$event['id'];
    $gameId=(int)$event['game_id'];
    $matchId=(int)($row['match_id']??0);
    $matchNumber=(int)($row['match_number']??0);
    $fieldId=max(1,(int)($row['field_id']??1));
    if($matchNumber<1)throw new RuntimeException('Qualification match number must be 1 or greater.');

    $teams=manual_schedule_team_numbers($row);
    foreach($teams as $slot=>$team){
        if($team<1)throw new RuntimeException('Q'.$matchNumber.' is incomplete. Select all six robots before saving it.');
        if(!isset($allowedTeams[$team]))throw new RuntimeException('Team '.$team.' is not on this event roster.');
    }
    if(count(array_unique(array_values($teams)))!==6){
        throw new RuntimeException('Q'.$matchNumber.' contains the same robot more than once.');
    }

    if($matchId>0){
        $s=$pdo->prepare(
            "SELECT id,state,started_at,
                    (SELECT COUNT(*) FROM scout_sessions ss WHERE ss.match_id=m.id) scout_session_count,
                    (SELECT COUNT(*) FROM scouting_actions sa WHERE sa.match_id=m.id AND sa.deleted_at IS NULL) action_count
             FROM matches m
             WHERE m.id=? AND m.organization_id=? AND m.event_id=? AND m.comp_level='qm'
             LIMIT 1"
        );
        $s->execute([$matchId,$org,$eventId]);
        $existing=$s->fetch();
        if(!$existing)throw new RuntimeException('That match no longer exists. Refresh the page and try again.');
        if(($existing['state']??'scheduled')!=='scheduled'||!empty($existing['started_at'])||(int)$existing['scout_session_count']>0||(int)$existing['action_count']>0){
            throw new RuntimeException('Q'.$matchNumber.' is already in use and its robot assignments are locked.');
        }

        $s=$pdo->prepare(
            "SELECT id FROM matches
             WHERE organization_id=? AND event_id=? AND comp_level='qm' AND set_number=1 AND match_number=? AND field_id=? AND id<>?
             LIMIT 1"
        );
        $s->execute([$org,$eventId,$matchNumber,$fieldId,$matchId]);
        if($s->fetchColumn())throw new RuntimeException('Q'.$matchNumber.' already exists on Field '.$fieldId.'.');

        $pdo->prepare(
            "UPDATE matches
             SET match_number=?,field_id=?,set_number=1,game_id=?,tba_match_key=NULL
             WHERE id=? AND organization_id=? AND event_id=?"
        )->execute([$matchNumber,$fieldId,$gameId,$matchId,$org,$eventId]);
    }else{
        $s=$pdo->prepare(
            "SELECT id FROM matches
             WHERE organization_id=? AND event_id=? AND comp_level='qm' AND set_number=1 AND match_number=? AND field_id=?
             LIMIT 1"
        );
        $s->execute([$org,$eventId,$matchNumber,$fieldId]);
        if($s->fetchColumn())throw new RuntimeException('Q'.$matchNumber.' already exists on Field '.$fieldId.'.');

        $pdo->prepare(
            "INSERT INTO matches
             (organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,state)
             VALUES(?,?,?,NULL,'qm',1,?,?,'scheduled')"
        )->execute([$org,$eventId,$gameId,$matchNumber,$fieldId]);
        $matchId=(int)$pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM match_teams WHERE match_id=?')->execute([$matchId]);
    $ins=$pdo->prepare('INSERT INTO match_teams(match_id,frc_team_number,alliance,station) VALUES(?,?,?,?)');
    $ins->execute([$matchId,$teams['r1'],'Red',1]);
    $ins->execute([$matchId,$teams['r2'],'Red',2]);
    $ins->execute([$matchId,$teams['r3'],'Red',3]);
    $ins->execute([$matchId,$teams['b1'],'Blue',1]);
    $ins->execute([$matchId,$teams['b2'],'Blue',2]);
    $ins->execute([$matchId,$teams['b3'],'Blue',3]);

    $pdo->prepare(
        "UPDATE events
         SET event_status=IF(event_status IN ('planned','pit_open'),'schedule_ready',event_status)
         WHERE id=? AND organization_id=?"
    )->execute([$eventId,$org]);

    return $matchId;
}

$events=neptune_event_selector_rows($pdo,$org,$selectedEvent,neptune_selector_show_history());
if(!$selectedEvent&&$events)$selectedEvent=(int)$events[0]['id'];
$event=$selectedEvent?manual_schedule_event($pdo,$org,$selectedEvent):null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if(!$event)throw new RuntimeException('Event not found.');
        $roster=manual_schedule_roster($pdo,$selectedEvent);
        $allowedTeams=[];
        foreach($roster as $team)$allowedTeams[(int)$team['frc_team_number']]=true;
        if(!$allowedTeams)throw new RuntimeException('Add the event robots before building the match schedule.');

        $rows=is_array($_POST['rows']??null)?$_POST['rows']:[];
        $op=(string)($_POST['op']??'save_all');
        $saved=[];

        $pdo->beginTransaction();
        if($op==='save_row'){
            $index=(string)($_POST['row_index']??'');
            if($index===''||!isset($rows[$index])||!is_array($rows[$index]))throw new RuntimeException('Match row not found.');
            if(!manual_schedule_row_has_team($rows[$index]))throw new RuntimeException('Select robots before saving this match.');
            $saved[$index]=manual_schedule_save($pdo,$org,$event,$allowedTeams,$rows[$index]);
        }elseif($op==='save_all'){
            foreach($rows as $index=>$row){
                if(!is_array($row))continue;
                $existingId=(int)($row['match_id']??0);
                if(!$existingId&&!manual_schedule_row_has_team($row))continue;
                $saved[(string)$index]=manual_schedule_save($pdo,$org,$event,$allowedTeams,$row);
            }
            if(!$saved)throw new RuntimeException('Add at least one complete match before saving.');
        }else{
            throw new RuntimeException('Unsupported schedule action.');
        }
        $pdo->commit();
        $msg=count($saved)===1?'Match schedule row saved.':count($saved).' match schedule rows saved.';

        if(manual_schedule_ajax())manual_schedule_json(['ok'=>true,'message'=>$msg,'saved'=>$saved]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
        if(manual_schedule_ajax())manual_schedule_json(['ok'=>false,'message'=>$error],422);
    }
}

$events=neptune_event_selector_rows($pdo,$org,$selectedEvent,neptune_selector_show_history());
$event=$selectedEvent?manual_schedule_event($pdo,$org,$selectedEvent):null;
$roster=$event?manual_schedule_roster($pdo,$selectedEvent):[];
$matches=$event?manual_schedule_existing($pdo,$org,$selectedEvent):[];

$teamOptions=manual_schedule_team_options($roster,0);

$pageTitle='Manual Match Schedule';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.manual-schedule-wrap{overflow:auto;margin-top:14px}
.manual-schedule-table{min-width:1040px;width:100%;border-collapse:separate;border-spacing:0 8px}
.manual-schedule-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;padding:0 8px 4px}
.manual-schedule-table td{padding:8px;background:color-mix(in srgb,var(--panel) 92%,transparent);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.manual-schedule-table td:first-child{border-left:1px solid var(--line);border-radius:10px 0 0 10px}
.manual-schedule-table td:last-child{border-right:1px solid var(--line);border-radius:0 10px 10px 0}
.manual-schedule-table select,.manual-schedule-table input{margin:0;min-width:118px}
.manual-schedule-table .match-no{width:78px;min-width:78px;font-weight:800}
.manual-schedule-table .field-no{width:74px;min-width:74px}
.manual-schedule-table .alliance-red select{border-color:color-mix(in srgb,#ef4444 45%,var(--line))}
.manual-schedule-table .alliance-blue select{border-color:color-mix(in srgb,#3b82f6 45%,var(--line))}
.manual-schedule-row.saved td{box-shadow:inset 0 0 0 1px color-mix(in srgb,#22c55e 48%,transparent)}
.manual-schedule-row.locked td{opacity:.78}
.manual-save-state{font-size:.78rem;color:var(--muted);white-space:nowrap}
@media(max-width:760px){.manual-schedule-table{min-width:930px}.manual-schedule-table select{min-width:108px}}
</style>

<div class="toolbar" style="justify-content:space-between">
  <div>
    <div class="module-eyebrow"><span>SATURN</span><small>Event Administration</small></div>
    <h1 style="margin-bottom:4px">Manual Match Schedule</h1>
    <div class="muted">Build qualification matches directly in Neptune when an event is not available from The Blue Alliance. R1–R3 and B1–B3 are the scouting stations.</div>
  </div>
  <div class="toolbar">
    <a class="btn secondary" href="<?=e(base_url('admin/events.php'.($selectedEvent?'?event_id='.$selectedEvent:'')))?>"><i class="fa-solid fa-calendar-days"></i> Event Setup</a>
    <a class="btn secondary" href="<?=e(base_url('admin/match-control.php'.($selectedEvent?'?event_id='.$selectedEvent:'')))?>"><i class="fa-solid fa-circle-play"></i> Match Control</a>
  </div>
</div>

<?php if($msg):?><div class="notice good" id="manualScheduleServerMessage"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="notice bad" id="manualScheduleServerError"><?=e($error)?></div><?php endif;?>

<div class="card">
  <form method="get" class="toolbar">
    <div style="min-width:320px;flex:1">
      <label style="margin-top:0">Event</label>
      <select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$selectedEvent)?></select>
    </div>
    <div><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
  </form>
</div>

<?php if(!$event):?>
  <div class="notice" style="margin-top:16px">Create or select an event first.</div>
<?php elseif(trim((string)($event['tba_event_key']??''))!==''):?>
  <div class="notice" style="margin-top:16px"><b>This event is linked to TBA.</b> Manual schedule editing is disabled so an official TBA refresh cannot conflict with hand-entered assignments. Use <a href="<?=e(base_url('admin/tba-sync.php'))?>">TBA Sync</a>.</div>
<?php elseif(!$roster):?>
  <div class="notice" style="margin-top:16px"><b>No robots are attached to this event yet.</b> Add the event roster in <a href="<?=e(base_url('admin/events.php?event_id='.$selectedEvent))?>">Event Setup</a>, then return here.</div>
<?php else:?>
<div class="card" style="margin-top:16px">
  <div class="toolbar" style="justify-content:space-between;align-items:flex-start">
    <div>
      <h2 style="margin:0"><?=e($event['name'])?></h2>
      <div class="muted"><?=e(($event['season_year']??'').' · '.$event['game_name'])?> · <?=count($roster)?> robots available</div>
    </div>
    <span class="pill"><i class="fa-solid fa-pen-to-square"></i> Manual schedule</span>
  </div>

  <form method="post" id="manualScheduleForm">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <input type="hidden" name="event_id" value="<?=$selectedEvent?>">
    <input type="hidden" name="op" id="manualScheduleOp" value="save_all">
    <input type="hidden" name="row_index" id="manualScheduleRowIndex" value="">

    <div class="manual-schedule-wrap">
      <table class="manual-schedule-table">
        <thead><tr><th>#</th><th>Field</th><th>R1</th><th>R2</th><th>R3</th><th>B1</th><th>B2</th><th>B3</th><th></th></tr></thead>
        <tbody id="manualScheduleBody">
        <?php
        $renderRows=$matches;
        if(!$renderRows)$renderRows=[['id'=>0,'match_number'=>1,'field_id'=>1,'r1'=>0,'r2'=>0,'r3'=>0,'b1'=>0,'b2'=>0,'b3'=>0,'state'=>'scheduled','started_at'=>null,'scout_session_count'=>0,'action_count'=>0]];
        foreach($renderRows as $idx=>$m):
            $locked=(($m['state']??'scheduled')!=='scheduled')||!empty($m['started_at'])||(int)($m['scout_session_count']??0)>0||(int)($m['action_count']??0)>0;
        ?>
          <tr class="manual-schedule-row <?=$locked?'locked':''?>" data-row-index="<?=$idx?>">
            <?php if($locked):?>
              <td class="match-no">Q<?=e($m['match_number'])?></td>
              <td><?=e($m['field_id'])?></td>
              <td>#<?=e($m['r1']?:'—')?></td><td>#<?=e($m['r2']?:'—')?></td><td>#<?=e($m['r3']?:'—')?></td>
              <td>#<?=e($m['b1']?:'—')?></td><td>#<?=e($m['b2']?:'—')?></td><td>#<?=e($m['b3']?:'—')?></td>
              <td><span class="manual-save-state"><i class="fa-solid fa-lock"></i> In use</span></td>
            <?php else:?>
              <td><input type="hidden" name="rows[<?=$idx?>][match_id]" value="<?=e($m['id']??0)?>"><div style="display:flex;align-items:center;gap:4px"><b>Q</b><input class="match-no" type="number" min="1" name="rows[<?=$idx?>][match_number]" value="<?=e($m['match_number']??($idx+1))?>" required></div></td>
              <td><input class="field-no" type="number" min="1" name="rows[<?=$idx?>][field_id]" value="<?=e($m['field_id']??1)?>" required></td>
              <?php foreach(['r1','r2','r3'] as $slot):?><td class="alliance-red"><select name="rows[<?=$idx?>][<?=$slot?>]" data-slot="<?=$slot?>"><?=manual_schedule_team_options($roster,(int)($m[$slot]??0))?></select></td><?php endforeach;?>
              <?php foreach(['b1','b2','b3'] as $slot):?><td class="alliance-blue"><select name="rows[<?=$idx?>][<?=$slot?>]" data-slot="<?=$slot?>"><?=manual_schedule_team_options($roster,(int)($m[$slot]??0))?></select></td><?php endforeach;?>
              <td><div class="toolbar" style="margin:0;flex-wrap:nowrap"><button type="submit" class="secondary manual-row-save" data-row-index="<?=$idx?>"><i class="fa-solid fa-floppy-disk"></i> Save</button><span class="manual-save-state" aria-live="polite"></span></div></td>
            <?php endif;?>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>

    <div class="toolbar" style="justify-content:space-between;margin-top:12px">
      <button type="button" class="secondary" id="manualAddMatch"><i class="fa-solid fa-plus"></i> Next Match</button>
      <button type="submit" id="manualSaveAll"><i class="fa-solid fa-floppy-disk"></i> Save All</button>
    </div>
  </form>
</div>
<?php endif;?>

<?php if($event&&trim((string)($event['tba_event_key']??''))===''&&$roster):?>
<script>
(() => {
  'use strict';
  const form=document.getElementById('manualScheduleForm');
  const body=document.getElementById('manualScheduleBody');
  const add=document.getElementById('manualAddMatch');
  const op=document.getElementById('manualScheduleOp');
  const rowIndex=document.getElementById('manualScheduleRowIndex');
  const teamOptions=<?=json_encode($teamOptions,JSON_UNESCAPED_SLASHES)?>;
  if(!form||!body||!add||!op||!rowIndex)return;

  function esc(s){return String(s).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
  function rowIndexes(){return [...body.querySelectorAll('.manual-schedule-row[data-row-index]')].map(r=>Number(r.dataset.rowIndex)||0);}
  function nextIndex(){const values=rowIndexes();return values.length?Math.max(...values)+1:0;}
  function nextMatchNumber(){
    let max=0;
    body.querySelectorAll('input[name$="[match_number]"]').forEach(el=>{max=Math.max(max,Number(el.value)||0);});
    body.querySelectorAll('.manual-schedule-row.locked .match-no').forEach(el=>{const n=Number(String(el.textContent||'').replace(/\D/g,''))||0;max=Math.max(max,n);});
    return max+1;
  }
  function latestField(){
    const fields=[...body.querySelectorAll('input[name$="[field_id]"]')];
    return fields.length?Math.max(1,Number(fields[fields.length-1].value)||1):1;
  }
  function makeSelect(index,slot,allianceClass){
    return `<td class="${allianceClass}"><select name="rows[${index}][${slot}]" data-slot="${slot}">${teamOptions}</select></td>`;
  }
  add.addEventListener('click',()=>{
    const index=nextIndex();
    const number=nextMatchNumber();
    const field=latestField();
    const tr=document.createElement('tr');
    tr.className='manual-schedule-row';
    tr.dataset.rowIndex=String(index);
    tr.innerHTML=`
      <td><input type="hidden" name="rows[${index}][match_id]" value="0"><div style="display:flex;align-items:center;gap:4px"><b>Q</b><input class="match-no" type="number" min="1" name="rows[${index}][match_number]" value="${number}" required></div></td>
      <td><input class="field-no" type="number" min="1" name="rows[${index}][field_id]" value="${field}" required></td>
      ${makeSelect(index,'r1','alliance-red')}${makeSelect(index,'r2','alliance-red')}${makeSelect(index,'r3','alliance-red')}
      ${makeSelect(index,'b1','alliance-blue')}${makeSelect(index,'b2','alliance-blue')}${makeSelect(index,'b3','alliance-blue')}
      <td><div class="toolbar" style="margin:0;flex-wrap:nowrap"><button type="submit" class="secondary manual-row-save" data-row-index="${index}"><i class="fa-solid fa-floppy-disk"></i> Save</button><span class="manual-save-state" aria-live="polite"></span></div></td>`;
    body.appendChild(tr);
    tr.querySelector('select')?.focus();
  });

  function rowDuplicate(row){
    const values=[...row.querySelectorAll('select[data-slot]')].map(s=>Number(s.value)||0).filter(Boolean);
    return values.length!==new Set(values).size;
  }
  body.addEventListener('change',event=>{
    const select=event.target.closest('select[data-slot]');
    if(!select)return;
    const row=select.closest('.manual-schedule-row');
    if(!row)return;
    const state=row.querySelector('.manual-save-state');
    if(rowDuplicate(row)){
      if(state)state.textContent='Duplicate robot';
    }else if(state&&state.textContent==='Duplicate robot')state.textContent='';
  });

  function toast(message,type='good'){
    if(window.NeptuneUI&&typeof NeptuneUI.toast==='function')NeptuneUI.toast(message,type,{title:'Manual Schedule'});
  }

  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const submitter=event.submitter||document.activeElement;
    const isRow=submitter&&submitter.classList&&submitter.classList.contains('manual-row-save');
    const targetIndex=isRow?String(submitter.dataset.rowIndex||''):'';
    const targetRow=isRow?body.querySelector(`.manual-schedule-row[data-row-index="${CSS.escape(targetIndex)}"]`):null;

    if(targetRow&&rowDuplicate(targetRow)){toast('A robot can only appear once in the same match.','bad');return;}
    if(!isRow){
      const duplicate=[...body.querySelectorAll('.manual-schedule-row:not(.locked)')].find(row=>rowDuplicate(row));
      if(duplicate){duplicate.scrollIntoView({behavior:'smooth',block:'center'});toast('One of the matches contains a duplicate robot.','bad');return;}
    }

    op.value=isRow?'save_row':'save_all';
    rowIndex.value=targetIndex;
    const buttons=[...form.querySelectorAll('button')];
    buttons.forEach(b=>b.disabled=true);
    const state=targetRow?.querySelector('.manual-save-state');
    if(state)state.textContent='Saving…';

    try{
      const response=await fetch(window.location.href,{method:'POST',body:new FormData(form),credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
      const data=await response.json().catch(()=>({ok:false,message:'The server returned an unreadable response.'}));
      if(!response.ok||!data.ok)throw new Error(data.message||'Unable to save the schedule.');

      if(isRow){
        const matchId=data.saved&&data.saved[targetIndex]?Number(data.saved[targetIndex]):0;
        const hidden=targetRow?.querySelector('input[name$="[match_id]"]');
        if(hidden&&matchId>0)hidden.value=String(matchId);
        targetRow?.classList.add('saved');
        if(state)state.textContent='Saved';
        setTimeout(()=>targetRow?.classList.remove('saved'),1200);
      }else{
        [...body.querySelectorAll('.manual-schedule-row:not(.locked)')].forEach(row=>{row.classList.add('saved');const s=row.querySelector('.manual-save-state');if(s)s.textContent='Saved';});
      }
      toast(data.message||'Schedule saved.','good');
      if(!isRow)setTimeout(()=>window.location.reload(),450);
    }catch(err){
      if(state)state.textContent='Not saved';
      toast(err&&err.message?err.message:'Unable to save the schedule.','bad');
    }finally{
      buttons.forEach(b=>b.disabled=false);
      op.value='save_all';
      rowIndex.value='';
    }
  });
})();
</script>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
