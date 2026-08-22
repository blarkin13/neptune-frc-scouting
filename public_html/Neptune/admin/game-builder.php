<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin']);
$msg='';$error='';
$palette=['#004977','#D03027','#27814e','#f7f3eb','#e3e8f0'];
$layouts=['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'];
$editingId=(int)($_GET['game_id']??$_POST['game_id']??0);

function normalize_action_code(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','_',$value)??'';
    return trim($value,'_');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $name=trim($_POST['name']??'');
    $year=(int)($_POST['year']??date('Y'));
    $rawButtons=json_decode($_POST['buttons_json']??'[]',true);
    $auton=max(1,(int)($_POST['auton_seconds']??15));
    $teleop=max(1,(int)($_POST['teleop_seconds']??135));
    $transition=max(0,(int)($_POST['transition_pause_seconds']??10));
    $endgame=max(0,min($teleop,(int)($_POST['endgame_seconds']??15)));

    if($name===''||!is_array($rawButtons)){
        $error='Enter a game name and at least one valid action.';
    } else {
        $buttons=[];$seen=[];
        foreach($rawButtons as $b){
            if(!is_array($b)) continue;
            $actionName=trim((string)($b['name']??''));
            if($actionName==='') continue;
            $base=normalize_action_code((string)($b['code']??$actionName));
            if($base==='') $base='action';
            $code=$base;
            if(isset($seen[$code])){
                $i=1;
                while(isset($seen[$base.'_b'.$i])) $i++;
                $code=$base.'_b'.$i;
            }
            $seen[$code]=true;
            $layout=(string)($b['layout']??'rect-1x1');
            // Backwards-compatible old Stat Owl layout names.
            $layout=['rect-1'=>'rect-1x1','rect-2'=>'rect-2x1','circle-1'=>'circle-1x1','circle-2'=>'circle-2x2'][$layout]??$layout;
            if(!in_array($layout,$layouts,true)) $layout='rect-1x1';
            $color=(string)($b['bgColor']??'#004977');
            if(!in_array(strtolower($color),array_map('strtolower',$palette),true)) $color='#004977';
            $type=(string)($b['type']??'offense');
            if(!in_array($type,['offense','defense','cooperative','other'],true)) $type='other';
            $buttons[]=[
                'name'=>$actionName,'code'=>$code,'type'=>$type,'location'=>trim((string)($b['location']??'')),
                'layout'=>$layout,'autonPoints'=>(float)($b['autonPoints']??0),'teleopPoints'=>(float)($b['teleopPoints']??0),'bgColor'=>$color
            ];
        }
        if(!$buttons){
            $error='Add at least one action button.';
        } else {
            $slug=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$name),'-'));
            $cfg=['game'=>$name,'timing'=>['autonSeconds'=>$auton,'transitionPauseSeconds'=>$transition,'teleopSeconds'=>$teleop,'endgameSeconds'=>$endgame],'grid'=>['columns'=>4],'buttons'=>$buttons];
            $json=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            $filename=$name.'.json';
            $path=dirname(__DIR__).'/games/'.$filename;
            if(@file_put_contents($path,$json)===false){
                $error='Neptune could not write the game JSON file. Check permissions on /Neptune/games/.';
            } else {
                try{
                    if($editingId>0){
                        $s=$pdo->prepare('UPDATE games SET name=?,season_year=?,slug=?,json_filename=?,config_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                        $s->execute([$name,$year,$slug,$filename,$json,$editingId]);
                    } else {
                        $s=$pdo->prepare('INSERT INTO games(name,season_year,slug,json_filename,config_json,created_by) VALUES(?,?,?,?,?,?)');
                        $s->execute([$name,$year,$slug,$filename,$json,$u['id']]);
                        $editingId=(int)$pdo->lastInsertId();
                    }
                    $msg='Game saved. The scout layout and timing configuration are ready.';
                }catch(Throwable $e){$error='Unable to save game: '.$e->getMessage();}
            }
        }
    }
}

$games=$pdo->query('SELECT id,name,season_year,config_json FROM games ORDER BY season_year DESC,name')->fetchAll();
$editGame=null;$cfg=['game'=>'','timing'=>['autonSeconds'=>15,'transitionPauseSeconds'=>10,'teleopSeconds'=>135,'endgameSeconds'=>15],'buttons'=>[]];
if($editingId){
    $s=$pdo->prepare('SELECT * FROM games WHERE id=?');$s->execute([$editingId]);$editGame=$s->fetch()?:null;
    if($editGame){$parsed=json_decode($editGame['config_json'],true);if(is_array($parsed))$cfg=array_replace_recursive($cfg,$parsed);}
}
$t=game_timing($cfg);
$pageTitle='Game Builder';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">Game Builder</h1><div class="muted">Four-column, grid-snapped scout controls generated from JSON.</div></div><a class="btn secondary" href="game-builder.php"><i class="fa-solid fa-plus"></i> New Game</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>

<div class="card" style="margin-bottom:16px">
  <form method="get" class="toolbar" style="margin:0">
    <div style="min-width:320px;flex:1"><label style="margin-top:0">Load an existing game to edit</label><select name="game_id" onchange="this.form.submit()"><option value="">Select a game…</option><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$editingId===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' · '.$g['name'])?></option><?php endforeach;?></select></div>
  </form>
</div>

<form method="post" id="gameForm">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=$editingId?>"><input type="hidden" name="buttons_json" id="buttons_json">
<div class="builder-layout">
  <div>
    <div class="card">
      <h2><?= $editingId?'Edit Game':'New Game' ?></h2>
      <div class="grid"><div><label>Game name</label><input name="name" required value="<?=e($cfg['game']??'')?>" placeholder="2026 Rebuilt"></div><div><label>Season</label><input name="year" type="number" value="<?=e($editGame['season_year']??date('Y'))?>" required></div></div>
      <h3 style="margin-top:22px">Match Timing</h3>
      <div class="builder-timing">
        <div><label>Autonomous</label><input type="number" min="1" name="auton_seconds" value="<?=$t['auton']?>"><div class="muted">seconds</div></div>
        <div><label>Auto → Teleop pause</label><input type="number" min="0" name="transition_pause_seconds" value="<?=$t['transition']?>"><div class="muted">timer freezes</div></div>
        <div><label>Teleop</label><input type="number" min="1" name="teleop_seconds" value="<?=$t['teleop']?>"><div class="muted">seconds</div></div>
        <div><label>Endgame</label><input type="number" min="0" name="endgame_seconds" value="<?=$t['endgame']?>"><div class="muted">last seconds</div></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="toolbar" style="justify-content:space-between"><h2 style="margin:0">Action Buttons</h2><button type="button" class="secondary" id="addAction"><i class="fa-solid fa-plus"></i> Add Action</button></div>
      <p class="muted">Codes are generated from the action name. Duplicate names receive <code>_b1</code>, <code>_b2</code>, etc.</p>
      <div id="rows" class="builder-actions"></div>
      <div class="toolbar"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Game JSON</button></div>
    </div>
  </div>

  <aside class="card preview-sticky">
    <h2>Scout Grid Preview</h2><p class="muted">Buttons pack automatically into a four-column grid in this order.</p>
    <div id="preview" class="button-preview-grid"></div>
    <div class="muted" style="margin-top:10px">Palette: blue, red, green, neutral, selected.</div>
  </aside>
</div>
</form>

<template id="rowTemplate"><div class="action-row"><div class="action-row-grid">
  <div class="wide"><label>Name</label><input data-k="name" class="action-name" placeholder="Score fuel"></div>
  <div><label>Code</label><input data-k="code" class="code-field" readonly></div>
  <div><label>Type</label><select data-k="type"><option value="offense">Offense</option><option value="defense">Defense</option><option value="cooperative">Cooperative</option><option value="other">Other</option></select></div>
  <div><label>Location</label><input data-k="location" placeholder="hub"></div>
  <div><label>Shape / size</label><select data-k="layout"><optgroup label="Rectangle"><option value="rect-1x1">1 × 1</option><option value="rect-1x2">1 × 2</option><option value="rect-2x1">2 × 1</option><option value="rect-1x4">1 × 4</option><option value="rect-4x1">4 × 1</option></optgroup><optgroup label="Circle"><option value="circle-1x1">1 × 1</option><option value="circle-2x2">2 × 2</option><option value="circle-4x4">4 × 4</option></optgroup></select></div>
  <div><label>Auton points</label><input type="number" step="0.1" data-k="autonPoints" value="0"></div>
  <div><label>Teleop points</label><input type="number" step="0.1" data-k="teleopPoints" value="0"></div>
  <div><label>Color</label><select data-k="bgColor" class="palette-select"><option value="#004977">Blue</option><option value="#D03027">Red</option><option value="#27814e">Green</option><option value="#f7f3eb">Neutral</option><option value="#e3e8f0">Selected</option></select></div>
</div><div class="drag-controls"><button type="button" class="secondary move-up" title="Move up"><i class="fa-solid fa-arrow-up"></i></button><button type="button" class="secondary move-down" title="Move down"><i class="fa-solid fa-arrow-down"></i></button><button type="button" class="danger remove-action"><i class="fa-solid fa-trash"></i></button></div></div></template>
<script>
const initialButtons=<?=json_encode(array_values($cfg['buttons']??[]),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const rows=document.getElementById('rows'),preview=document.getElementById('preview'),tpl=document.getElementById('rowTemplate');
const oldLayouts={'rect-1':'rect-1x1','rect-2':'rect-2x1','circle-1':'circle-1x1','circle-2':'circle-2x2'};
function slugCode(v){return String(v||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')||'action'}
function addRow(v={}){
  const r=tpl.content.firstElementChild.cloneNode(true);rows.appendChild(r);
  r.querySelectorAll('[data-k]').forEach(el=>{let k=el.dataset.k,val=v[k];if(k==='layout'&&oldLayouts[val])val=oldLayouts[val];if(k==='bgColor'&&val&&!['#004977','#d03027','#27814e','#f7f3eb','#e3e8f0'].includes(String(val).toLowerCase()))val=(v.type==='defense'?'#D03027':v.type==='cooperative'?'#e3e8f0':'#004977');if(val!==undefined&&val!==null)el.value=val});
  r.querySelector('.remove-action').onclick=()=>{r.remove();refreshCodes();renderPreview()};
  r.querySelector('.move-up').onclick=()=>{if(r.previousElementSibling)rows.insertBefore(r,r.previousElementSibling);refreshCodes();renderPreview()};
  r.querySelector('.move-down').onclick=()=>{if(r.nextElementSibling)rows.insertBefore(r.nextElementSibling,r);refreshCodes();renderPreview()};
  r.querySelectorAll('input,select').forEach(x=>x.addEventListener('input',()=>{refreshCodes();renderPreview()}));
  refreshCodes();renderPreview();
}
function refreshCodes(){
  const used={};
  [...rows.children].forEach(r=>{const name=r.querySelector('[data-k=name]').value;const base=slugCode(name);let code=base;if(used[base]!==undefined){used[base]++;code=base+'_b'+used[base]}else used[base]=0;r.querySelector('[data-k=code]').value=code});
}
function collect(){const out=[];[...rows.children].forEach(r=>{const o={};r.querySelectorAll('[data-k]').forEach(x=>o[x.dataset.k]=x.type==='number'?Number(x.value):x.value);if(o.name)out.push(o)});return out}
function renderPreview(){preview.innerHTML='';collect().forEach(b=>{const el=document.createElement('div');el.className='preview-action layout-'+b.layout;el.textContent=b.name;el.style.background=b.bgColor;el.style.color=['#f7f3eb','#e3e8f0'].includes(String(b.bgColor).toLowerCase())?'#17212a':'#fff';preview.appendChild(el)});if(!preview.children.length)preview.innerHTML='<div class="muted">Add an action to preview the scout grid.</div>'}
document.getElementById('addAction').onclick=()=>addRow({layout:'rect-1x1',bgColor:'#004977',type:'offense',autonPoints:0,teleopPoints:0});
document.getElementById('gameForm').addEventListener('submit',()=>{refreshCodes();document.getElementById('buttons_json').value=JSON.stringify(collect())});
(initialButtons.length?initialButtons:[{}]).forEach(addRow);
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
