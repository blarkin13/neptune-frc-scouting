<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';
$u=require_login();$org=(int)$u['organization_id'];$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);$team=(int)($_GET['team']??$_POST['team']??0);$msg='';$error='';

$s=$pdo->prepare("SELECT e.*,g.name game_name,gr.pit_config_json,gr.field_image_path,gr.revision_number game_revision_number,et.nickname FROM events e JOIN games g ON g.id=e.game_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=?");$s->execute([$team,$eventId,$org]);$event=$s->fetch();
if(!$event){http_response_code(404);exit('Team is not on this event roster.');}
$questions=neptune_pit_all_questions($event['pit_config_json']??null);
$autonFieldPath=neptune_revision_field_path($event,(int)($event['game_id']??0),$org);
$autonFieldUrl=$autonFieldPath!==''?'/'.ltrim($autonFieldPath,'/'):'';

$s=$pdo->prepare('SELECT * FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$pit=$s->fetch()?:null;
$data=$pit?json_decode($pit['data_json'],true):[];if(!is_array($data))$data=[];
$preFillSource='';$preFillNotes='';
if(!$pit){
    $s=$pdo->prepare('SELECT data_json,notes FROM robot_season_profiles WHERE organization_id=? AND game_id=? AND frc_team_number=?');$s->execute([$org,$event['game_id'],$team]);if($r=$s->fetch()){$x=json_decode($r['data_json']??'{}',true);if(is_array($x)){$data=$x;$preFillSource='season knowledge';}$preFillNotes=(string)($r['notes']??'');}
    $s=$pdo->prepare('SELECT data_json,notes FROM pre_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);if($r=$s->fetch()){$x=json_decode($r['data_json']??'{}',true);if(is_array($x)){$data=array_merge($data,$x);$preFillSource='this event pre-scout';}$preFillNotes=(string)($r['notes']??$preFillNotes);}
}

function save_pit_photo(PDO $pdo,array $u,int $org,int $pitId,int $eventId,int $team,string $category,array $file): ?array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    $rel='uploads/pit/'.$org.'/'.$eventId.'/'.$team;
    $publicRoot=dirname(__DIR__);
    $root=$publicRoot.'/'.$rel;
    $saved=neptune_store_uploaded_image($file,$root,$category,1800,20*1024*1024);
    $path=$rel.'/'.$saved['filename'];

    // Each named photo slot represents one current image. Uploading a new
    // Front/Back/Left/Right/Mechanism photo replaces older copies in that slot.
    $oldStmt=$pdo->prepare('SELECT id,file_path FROM pit_scouting_photos WHERE pit_scouting_id=? AND organization_id=? AND category=?');
    $oldStmt->execute([$pitId,$org,$category]);
    $oldPhotos=$oldStmt->fetchAll();

    $pdo->prepare('INSERT INTO pit_scouting_photos(pit_scouting_id,organization_id,category,file_path,caption,uploaded_by) VALUES(?,?,?,?,?,?)')->execute([
        $pitId,$org,$category,$path,
        'Optimized '.($saved['format']??'image').' · '.($saved['width']??0).'×'.($saved['height']??0),
        (int)$u['id']
    ]);

    if($oldPhotos){
        $ids=array_map(fn($p)=>(int)$p['id'],$oldPhotos);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $params=array_merge($ids,[$pitId,$org,$category]);
        $pdo->prepare("DELETE FROM pit_scouting_photos WHERE id IN ($placeholders) AND pit_scouting_id=? AND organization_id=? AND category=?")->execute($params);
        foreach($oldPhotos as $oldPhoto){
            neptune_delete_pit_image_file($publicRoot,(string)$oldPhoto['file_path']);
        }
    }
    return $saved;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $posted=is_array($_POST['pit']??null)?$_POST['pit']:[];$clean=[];$missing=[];
            foreach($questions as $q){
                $code=(string)($q['code']??'');if($code==='')continue;$type=(string)($q['type']??'text');$v=$posted[$code]??($type==='multiselect'?[]:'');
                if($type==='multiselect'){$v=is_array($v)?array_values(array_filter(array_map('trim',$v),fn($x)=>$x!=='')):[];}else{$v=trim((string)$v);}
                if(!empty($q['required'])&&($_POST['save_mode']??'complete')==='complete'&&($v===''||$v===[]))$missing[]=(string)($q['label']??$code);
                $clean[$code]=$v;
            }

            // Keep the autonomous drawing with this robot's pit record. Coordinates are normalized 0..1,
            // so the path remains aligned when the canvas is resized on phones, tablets, or desktops.
            $autonRaw=trim((string)($_POST['auton_path_json']??''));
            if($autonRaw!==''){
                $auton=json_decode($autonRaw,true);
                if(!is_array($auton) || !isset($auton['strokes']) || !is_array($auton['strokes']))throw new RuntimeException('Invalid autonomous path data.');
                $clean['_auton_path']=$auton;
            }elseif(isset($data['_auton_path']) && is_array($data['_auton_path'])){
                $clean['_auton_path']=$data['_auton_path'];
            }

            $notes=trim($_POST['notes']??'');$status=(($_POST['save_mode']??'complete')==='draft')?'in_progress':'complete';
            if($missing)throw new RuntimeException('Complete the required fields: '.implode(', ',$missing));
            $ownerTeam=primary_team_id($pdo,(int)$u['id'],$org);
            $json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $sql="INSERT INTO pit_scouting(organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,started_at,completed_at) VALUES(?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),IF(?='complete',UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE owner_team_id=COALESCE(VALUES(owner_team_id),owner_team_id),submitted_by=VALUES(submitted_by),data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),started_at=COALESCE(started_at,UTC_TIMESTAMP()),completed_at=IF(VALUES(status)='complete',UTC_TIMESTAMP(),completed_at)";
            $pdo->prepare($sql)->execute([$org,$ownerTeam,$eventId,$team,(int)$u['id'],$json,$notes,$status,$status]);
            $s=$pdo->prepare('SELECT id FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$pitId=(int)$s->fetchColumn();
            $savedPhotos=[];
            foreach(['front','back','left','right','mechanism'] as $cat){
                // Mobile uses two hidden inputs so the scout can explicitly choose
                // camera capture or an existing photo. Keep the original field name
                // as a fallback for older cached pages.
                foreach(['photo_'.$cat.'_camera','photo_'.$cat.'_library','photo_'.$cat] as $photoField){
                    if(!isset($_FILES[$photoField]))continue;
                    if(($_FILES[$photoField]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                    $savedPhoto=save_pit_photo($pdo,$u,$org,$pitId,$eventId,$team,$cat,$_FILES[$photoField]);
                    if($savedPhoto)$savedPhotos[]=$savedPhoto;
                    break;
                }
            }
            $msg=$status==='complete'?'Pit scouting marked complete.':'Draft saved.';
            if($savedPhotos){
                $formats=array_values(array_unique(array_map(fn($x)=>(string)($x['format']??'image'),$savedPhotos)));
                $msg.=' '.count($savedPhotos).' photo'.(count($savedPhotos)===1?'':'s').' optimized as '.implode('/', $formats).'.';
            }
        $pit=$pdo->query('SELECT * FROM pit_scouting WHERE id='.(int)$pitId)->fetch();$data=$clean;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$photos=[];if($pit){$s=$pdo->prepare('SELECT * FROM pit_scouting_photos WHERE pit_scouting_id=? ORDER BY category,created_at DESC');$s->execute([(int)$pit['id']]);$photos=$s->fetchAll();}
$s=$pdo->prepare("SELECT et.frc_team_number FROM event_teams et LEFT JOIN pit_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number AND ps.status='complete' WHERE et.event_id=? AND ps.id IS NULL ORDER BY CASE WHEN et.frc_team_number>? THEN 0 ELSE 1 END,et.frc_team_number LIMIT 1");$s->execute([$org,$eventId,$team]);$nextTeam=(int)$s->fetchColumn();

$groups=[];foreach($questions as $q)$groups[(string)($q['group']??'Other')][]=$q;
$imageCaps=neptune_image_capabilities();
$photoLimit=$imageCaps['effective_upload_max_bytes']>0?neptune_format_bytes((int)$imageCaps['effective_upload_max_bytes']):'server limit';
$autonPath=is_array($data['_auton_path']??null)?$data['_auton_path']:['version'=>1,'strokes'=>[]];
$pageTitle='Pit #'.$team;$moduleName='TRIDENT';include dirname(__DIR__).'/partials_header.php';
?>
<style>
.auton-canvas-wrap{position:relative;width:100%;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel2);aspect-ratio:2/1;min-height:240px}.auton-canvas{display:block;width:100%;height:100%;touch-action:none;cursor:crosshair}.auton-canvas-empty{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;color:var(--muted);text-align:center;padding:24px}.auton-canvas-empty i{display:block;font-size:2rem;margin-bottom:8px}.auton-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.auton-help{font-size:.82rem;color:var(--muted);margin-top:8px;line-height:1.45}
.pit-photo-capture-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.pit-photo-capture{min-width:0}.pit-photo-capture>label:first-child{display:block;margin:0 0 7px;font-weight:850}.pit-photo-trigger{display:grid;place-items:center;position:relative;width:100%;min-height:190px;margin:0;border:1px dashed var(--line);border-radius:8px;background:var(--panel);color:inherit;cursor:pointer;overflow:hidden;transition:border-color .15s ease,background .15s ease,transform .15s ease}.pit-photo-trigger:hover,.pit-photo-trigger:focus-visible{border-color:var(--accent);background:var(--panel2);outline:none}.pit-photo-trigger:active{transform:scale(.995)}.pit-photo-placeholder{display:grid;place-items:center;gap:8px;text-align:center;color:var(--muted);padding:18px}.pit-photo-placeholder i{font-size:2rem;color:var(--accent)}.pit-photo-placeholder span{font-weight:800;color:var(--text)}.pit-photo-placeholder small{font-size:.78rem;color:var(--muted);font-weight:600}.pit-photo-trigger img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.pit-photo-input{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important}.pit-photo-filemeta{margin-top:7px;font-size:.78rem;overflow-wrap:anywhere}.pit-photo-clear{margin-top:7px;width:100%}.pit-photo-filemeta[hidden],.pit-photo-clear[hidden]{display:none!important}.pit-photo-choice-backdrop{position:fixed;inset:0;z-index:10000;display:none;align-items:flex-end;justify-content:center;padding:18px;background:rgba(0,0,0,.58);backdrop-filter:blur(3px)}.pit-photo-choice-backdrop.open{display:flex}.pit-photo-choice-sheet{width:min(520px,100%);background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 20px 70px rgba(0,0,0,.38)}.pit-photo-choice-head{padding:3px 3px 12px}.pit-photo-choice-head b{display:block;font-size:1.05rem}.pit-photo-choice-head span{display:block;margin-top:3px;color:var(--muted);font-size:.84rem}.pit-photo-choice-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pit-photo-choice-action{min-height:86px;display:flex;align-items:center;justify-content:center;gap:10px;text-align:left}.pit-photo-choice-action i{font-size:1.5rem}.pit-photo-choice-action span{display:block}.pit-photo-choice-action small{display:block;margin-top:2px;color:var(--muted);font-weight:600}.pit-photo-choice-cancel{width:100%;margin-top:10px}
@media(max-width:700px){.auton-canvas-wrap{min-height:190px}.pit-photo-capture-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pit-photo-trigger{min-height:150px}}@media(max-width:430px){.pit-photo-capture-grid{grid-template-columns:1fr}.pit-photo-trigger{min-height:180px}.pit-photo-choice-actions{grid-template-columns:1fr}.pit-photo-choice-action{min-height:72px}}
</style>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>TRIDENT</span><small>Pit Scouting</small></div><div class="muted"><?=e($event['name'])?> · <?=e($event['game_name'])?></div><h1 style="margin:3px 0">#<?=e($team)?> <?=e($event['nickname']?:'')?></h1><div class="muted">Pit Scouting</div></div><div class="toolbar"><a class="btn secondary" href="index.php?event_id=<?=$eventId?>"><i class="fa-solid fa-arrow-left"></i> Team List</a><?php if($nextTeam):?><a class="btn secondary" href="scout.php?event_id=<?=$eventId?>&team=<?=$nextTeam?>">Next Unscouted <i class="fa-solid fa-arrow-right"></i></a><?php endif;?></div></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if(!$pit&&$preFillSource):?><div class="notice"><i class="fa-solid fa-database"></i> This pit form is pre-filled from <b><?=e($preFillSource)?></b> for the same game/year. Confirm or correct it at the robot before marking pit scouting complete.</div><?php endif;?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="team" value="<?=$team?>"><input type="hidden" name="action" value="save_pit"><input type="hidden" name="auton_path_json" id="autonPathJson" value="">
<?php foreach($groups as $group=>$qs):?><section class="card pit-form-section"><h2><?=e($group)?></h2><div class="pit-form-grid"><?php foreach($qs as $q)neptune_pit_render_field($q,$data[$q['code']]??($q['type']==='multiselect'?[]:''));?></div></section><?php endforeach;?>
<section class="card pit-form-section">
  <div class="toolbar" style="justify-content:space-between">
    <div><h2 style="margin:0 0 4px"><i class="fa-solid fa-route"></i> Autonomous Path</h2><div class="muted">Draw the robot's expected autonomous route directly on the field. Mouse, touch, and stylus are supported.</div></div>
    <span class="pill"><i class="fa-solid fa-pen"></i> Saved with this robot</span>
  </div>
  <div class="auton-canvas-wrap" id="autonCanvasWrap">
    <canvas id="autonCanvas" class="auton-canvas" aria-label="Autonomous path drawing canvas"></canvas>
    <div class="auton-canvas-empty" id="autonCanvasEmpty" <?= $autonFieldUrl ? 'hidden' : '' ?>><div><i class="fa-solid fa-map"></i><b>No field background uploaded yet.</b><br>An owner/admin can add the field image in VULCAN → Game Builder. You can still draw on the blank field.</div></div>
  </div>
  <div class="auton-toolbar">
    <button type="button" class="secondary" id="autonUndo"><i class="fa-solid fa-rotate-left"></i> Undo Stroke</button>
    <button type="button" class="danger" id="autonClear"><i class="fa-solid fa-eraser"></i> Clear Path</button>
    <span class="muted" id="autonStrokeCount"></span>
  </div>
  <div class="auton-help">Each pen-down gesture is stored as one stroke. Paths use normalized coordinates, so they stay aligned when viewed on different screen sizes.</div>
</section>
<section class="card pit-form-section">
  <div class="toolbar pit-photo-heading" style="justify-content:space-between">
    <div>
      <h2 style="margin-bottom:4px"><i class="fa-solid fa-camera"></i> Robot Photos</h2>
      <p class="muted" style="margin:0">Tap a photo card to add or replace an image. On a phone, use the camera or photo library; on a computer, choose an image file.</p>
    </div>
    <span class="pill"><i class="fa-solid fa-compress"></i> <?=e($imageCaps['preferred_label'])?> preferred</span>
  </div>
  <div class="notice"><b>Automatic optimization:</b> Images are resized to a maximum of 1800 px and saved as AVIF when supported, with WebP/JPEG fallbacks. Upload limit: <?=e($photoLimit)?>.</div>
  <div class="pit-photo-inputs pit-photo-capture-grid">
    <?php foreach(['front'=>'Front','back'=>'Back','left'=>'Left side','right'=>'Right side','mechanism'=>'Mechanism / detail'] as $cat=>$label):?>
      <div class="pit-photo-capture" data-photo-capture data-photo-label="<?=e($label)?>">
        <label><?=e($label)?></label>
        <button type="button" class="pit-photo-preview pit-photo-trigger" data-photo-preview aria-label="Add <?=e($label)?> robot photo">
          <div class="pit-photo-placeholder"><i class="fa-solid fa-camera"></i><span>Add photo</span><small>Tap to take or choose</small></div>
          <img alt="<?=e($label)?> preview" hidden>
        </button>
        <input class="pit-photo-input" data-photo-camera id="photo_<?=$cat?>_camera" type="file" name="photo_<?=$cat?>_camera" accept="image/*" capture="environment">
        <input class="pit-photo-input" data-photo-library id="photo_<?=$cat?>_library" type="file" name="photo_<?=$cat?>_library" accept="image/*">
        <div class="pit-photo-filemeta muted" data-photo-meta hidden></div>
        <button type="button" class="btn secondary pit-photo-clear" data-photo-clear hidden><i class="fa-solid fa-xmark"></i> Remove selected photo</button>
      </div>
    <?php endforeach;?>
  </div>
  <div class="pit-photo-choice-backdrop" id="pitPhotoChoice" role="dialog" aria-modal="true" aria-labelledby="pitPhotoChoiceTitle">
    <div class="pit-photo-choice-sheet">
      <div class="pit-photo-choice-head"><b id="pitPhotoChoiceTitle">Add robot photo</b><span>Choose how you want to add this image.</span></div>
      <div class="pit-photo-choice-actions">
        <button type="button" class="secondary pit-photo-choice-action" id="pitPhotoTake"><i class="fa-solid fa-camera"></i><span><b>Take photo</b><small>Open the phone camera</small></span></button>
        <button type="button" class="secondary pit-photo-choice-action" id="pitPhotoChoose"><i class="fa-solid fa-images"></i><span><b>Choose photo</b><small>Use an existing image</small></span></button>
      </div>
      <button type="button" class="secondary pit-photo-choice-cancel" id="pitPhotoCancel">Cancel</button>
    </div>
  </div>
  <?php if($photos):?>
    <h3 class="pit-photo-saved-title" data-saved-photo-title>Saved Photos</h3>
    <div class="pit-photo-grid" data-saved-photo-grid>
      <?php foreach($photos as $p):?>
        <div class="pit-photo-saved-card" data-saved-photo-id="<?=e($p['id'])?>">
          <a href="<?=e(base_url($p['file_path']))?>" target="_blank" rel="noopener"><img src="<?=e(base_url($p['file_path']))?>" alt="<?=e(ucfirst($p['category']))?> robot photo"><span><?=e(ucfirst($p['category']))?></span></a>
          <button type="button" class="danger pit-photo-saved-delete" data-delete-pit-photo="<?=e($p['id'])?>" aria-label="Delete <?=e($p['category'])?> photo" title="Delete photo"><i class="fa-solid fa-trash"></i></button>
        </div>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</section>
<section class="card pit-form-section"><h2>Additional Notes</h2><label>Anything strategy should know that was not covered above</label><textarea name="notes" rows="5"><?=e($pit['notes']??$preFillNotes)?></textarea></section>
<div class="sticky-save"><button class="secondary" name="save_mode" value="draft"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button><button class="good" name="save_mode" value="complete"><i class="fa-solid fa-circle-check"></i> Mark Complete</button></div>
</form>
<script>
(function(){
  const canvas=document.getElementById('autonCanvas');
  const wrap=document.getElementById('autonCanvasWrap');
  const hidden=document.getElementById('autonPathJson');
  const undoBtn=document.getElementById('autonUndo');
  const clearBtn=document.getElementById('autonClear');
  const countEl=document.getElementById('autonStrokeCount');
  const emptyEl=document.getElementById('autonCanvasEmpty');
  const fieldUrl=<?=json_encode($autonFieldUrl,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const initialPath=<?=json_encode($autonPath,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let strokes=Array.isArray(initialPath?.strokes)?initialPath.strokes.filter(Array.isArray):[];
  let activeStroke=null;
  let drawing=false;
  let dpr=Math.max(1,window.devicePixelRatio||1);
  let bg=null;

  function syncHidden(){
    if(hidden)hidden.value=JSON.stringify({version:1,strokes});
    if(countEl)countEl.textContent=strokes.length+' stroke'+(strokes.length===1?'':'s');
    if(undoBtn)undoBtn.disabled=strokes.length===0;
    if(clearBtn)clearBtn.disabled=strokes.length===0;
  }
  function fitCanvas(){
    if(!canvas||!wrap)return;
    if(bg?.naturalWidth&&bg?.naturalHeight)wrap.style.aspectRatio=bg.naturalWidth+' / '+bg.naturalHeight;
    const r=wrap.getBoundingClientRect();
    dpr=Math.max(1,window.devicePixelRatio||1);
    canvas.width=Math.max(1,Math.round(r.width*dpr));
    canvas.height=Math.max(1,Math.round(r.height*dpr));
    draw();
  }
  function drawGrid(ctx,w,h){
    ctx.save();
    ctx.fillStyle='#111827';ctx.fillRect(0,0,w,h);
    ctx.strokeStyle='rgba(255,255,255,.10)';ctx.lineWidth=1*dpr;
    for(let i=1;i<12;i++){const x=w*i/12;ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,h);ctx.stroke();}
    for(let i=1;i<6;i++){const y=h*i/6;ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(w,y);ctx.stroke();}
    ctx.restore();
  }
  function drawArrow(ctx,a,b,w,h){
    if(!a||!b)return;
    const ax=a.x*w, ay=a.y*h, bx=b.x*w, by=b.y*h;
    const angle=Math.atan2(by-ay,bx-ax), size=12*dpr;
    ctx.beginPath();ctx.moveTo(bx,by);ctx.lineTo(bx-size*Math.cos(angle-Math.PI/6),by-size*Math.sin(angle-Math.PI/6));ctx.moveTo(bx,by);ctx.lineTo(bx-size*Math.cos(angle+Math.PI/6),by-size*Math.sin(angle+Math.PI/6));ctx.stroke();
  }
  function draw(){
    if(!canvas)return;
    const ctx=canvas.getContext('2d');const w=canvas.width,h=canvas.height;
    ctx.clearRect(0,0,w,h);
    if(bg?.complete&&bg.naturalWidth){ctx.drawImage(bg,0,0,w,h);}else{drawGrid(ctx,w,h);}
    ctx.save();ctx.strokeStyle='#ff3b30';ctx.fillStyle='#ff3b30';ctx.lineWidth=Math.max(4,5*dpr);ctx.lineCap='round';ctx.lineJoin='round';
    strokes.forEach(stroke=>{
      if(!Array.isArray(stroke)||stroke.length<1)return;
      ctx.beginPath();stroke.forEach((p,i)=>{const x=p.x*w,y=p.y*h;i?ctx.lineTo(x,y):ctx.moveTo(x,y)});ctx.stroke();
      const start=stroke[0];ctx.beginPath();ctx.arc(start.x*w,start.y*h,6*dpr,0,Math.PI*2);ctx.fill();
      if(stroke.length>1)drawArrow(ctx,stroke[stroke.length-2],stroke[stroke.length-1],w,h);
    });
    ctx.restore();
  }
  function pointFromEvent(e){
    const r=canvas.getBoundingClientRect();
    return {x:Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)),y:Math.max(0,Math.min(1,(e.clientY-r.top)/r.height))};
  }
  canvas?.addEventListener('pointerdown',e=>{e.preventDefault();canvas.setPointerCapture?.(e.pointerId);drawing=true;activeStroke=[pointFromEvent(e)];strokes.push(activeStroke);syncHidden();draw();});
  canvas?.addEventListener('pointermove',e=>{if(!drawing||!activeStroke)return;e.preventDefault();const p=pointFromEvent(e),last=activeStroke[activeStroke.length-1];if(!last||Math.hypot(p.x-last.x,p.y-last.y)>.003){activeStroke.push(p);syncHidden();draw();}});
  function stopDrawing(){drawing=false;activeStroke=null;syncHidden();draw();}
  canvas?.addEventListener('pointerup',stopDrawing);canvas?.addEventListener('pointercancel',stopDrawing);
  undoBtn?.addEventListener('click',()=>{strokes.pop();syncHidden();draw();});
  clearBtn?.addEventListener('click',()=>{if(strokes.length&&confirm('Clear the entire autonomous path?')){strokes=[];syncHidden();draw();}});
  if(fieldUrl){bg=new Image();bg.onload=()=>{if(emptyEl)emptyEl.hidden=true;fitCanvas();};bg.onerror=()=>{if(emptyEl)emptyEl.hidden=false;fitCanvas();};bg.src=fieldUrl;}
  const ro=('ResizeObserver' in window)?new ResizeObserver(fitCanvas):null;ro?.observe(wrap);window.addEventListener('resize',fitCanvas);syncHidden();requestAnimationFrame(fitCanvas);

  const photoChoice=document.getElementById('pitPhotoChoice');
  const photoChoiceTitle=document.getElementById('pitPhotoChoiceTitle');
  const photoTake=document.getElementById('pitPhotoTake');
  const photoChoose=document.getElementById('pitPhotoChoose');
  const photoCancel=document.getElementById('pitPhotoCancel');
  let activePhotoCard=null;

  function closePhotoChoice(){
    photoChoice?.classList.remove('open');
    activePhotoCard=null;
  }
  function openPhotoChoice(card){
    activePhotoCard=card;
    if(photoChoiceTitle)photoChoiceTitle.textContent='Add '+(card?.dataset.photoLabel||'robot')+' photo';
    photoChoice?.classList.add('open');
  }
  photoTake?.addEventListener('click',()=>{
    const input=activePhotoCard?.querySelector('[data-photo-camera]');
    closePhotoChoice();
    input?.click();
  });
  photoChoose?.addEventListener('click',()=>{
    const input=activePhotoCard?.querySelector('[data-photo-library]');
    closePhotoChoice();
    input?.click();
  });
  photoCancel?.addEventListener('click',closePhotoChoice);
  photoChoice?.addEventListener('click',e=>{if(e.target===photoChoice)closePhotoChoice();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&photoChoice?.classList.contains('open'))closePhotoChoice();});

  document.querySelectorAll('[data-photo-capture]').forEach(card=>{
    const cameraInput=card.querySelector('[data-photo-camera]');
    const libraryInput=card.querySelector('[data-photo-library]');
    const preview=card.querySelector('[data-photo-preview]');
    const img=preview?.querySelector('img');
    const placeholder=preview?.querySelector('.pit-photo-placeholder');
    const meta=card.querySelector('[data-photo-meta]');
    const clear=card.querySelector('[data-photo-clear]');
    let objectUrl='';

    function reset(){
      if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl='';}
      if(cameraInput)cameraInput.value='';
      if(libraryInput)libraryInput.value='';
      if(img){img.hidden=true;img.removeAttribute('src');}
      if(placeholder)placeholder.hidden=false;
      if(meta){meta.textContent='';meta.hidden=true;}
      if(clear)clear.hidden=true;
    }
    function useFile(input,otherInput,sourceLabel){
      const file=input?.files?.[0];
      if(!file)return;
      if(otherInput)otherInput.value='';
      if(objectUrl)URL.revokeObjectURL(objectUrl);
      objectUrl=URL.createObjectURL(file);
      if(img){img.src=objectUrl;img.hidden=false;}
      if(placeholder)placeholder.hidden=true;
      if(meta){
        const mb=file.size/(1024*1024);
        meta.textContent=sourceLabel+' · '+file.name+' · '+(mb>=1?mb.toFixed(1)+' MB':Math.max(1,Math.round(file.size/1024))+' KB');
        meta.hidden=false;
      }
      if(clear)clear.hidden=false;
    }

    preview?.addEventListener('click',()=>openPhotoChoice(card));
    cameraInput?.addEventListener('change',()=>useFile(cameraInput,libraryInput,'Camera'));
    libraryInput?.addEventListener('change',()=>useFile(libraryInput,cameraInput,'Photo library'));
    clear?.addEventListener('click',reset);
  });

  document.querySelectorAll('[data-delete-pit-photo]').forEach(button=>{
    button.addEventListener('click',async()=>{
      const id=Number(button.dataset.deletePitPhoto||0);
      if(!id)return;
      if(!confirm('Delete this saved robot photo?'))return;
      button.disabled=true;
      try{
        const response=await fetch('<?=e(base_url('api/delete-pit-photo.php'))?>',{
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:JSON.stringify({photo_id:id,csrf:'<?=e(csrf_token())?>'})
        });
        const data=await response.json();
        if(!response.ok||data.status!=='success')throw new Error(data.message||'Could not delete the photo.');
        button.closest('[data-saved-photo-id]')?.remove();
        const grid=document.querySelector('[data-saved-photo-grid]');
        if(grid&&!grid.querySelector('[data-saved-photo-id]')){
          grid.remove();
          document.querySelector('[data-saved-photo-title]')?.remove();
        }
      }catch(error){
        alert(error?.message||'Could not delete the photo.');
        button.disabled=false;
      }
    });
  });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
