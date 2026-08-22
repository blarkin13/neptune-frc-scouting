<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin']);$msg='';$error='';
$games=$pdo->query('SELECT id,name,season_year,pit_config_json FROM games ORDER BY season_year DESC,name')->fetchAll();
$gameId=(int)($_GET['game_id']??$_POST['game_id']??($games[0]['id']??0));

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $s=$pdo->prepare('SELECT id FROM games WHERE id=?');$s->execute([$gameId]);if(!$s->fetchColumn())throw new RuntimeException('Game not found.');
        $raw=json_decode($_POST['questions_json']??'[]',true);if(!is_array($raw))throw new RuntimeException('Invalid pit form configuration.');
        $questions=[];$used=[];
        foreach($raw as $row){
            if(!is_array($row))continue;$label=trim((string)($row['label']??''));if($label==='')continue;
            $code=strtolower(trim((string)($row['code']??'')));$code=preg_replace('/[^a-z0-9_]+/','_',$code);$code=trim($code,'_');if($code==='')$code='question';
            $base=$code;$i=1;while(isset($used[$code])){$code=$base.'_b'.$i++;}$used[$code]=true;
            $type=(string)($row['type']??'text');if(!in_array($type,['yes_no','select','multiselect','number','text','textarea'],true))$type='text';
            $group=trim((string)($row['group']??'Game Specific'))?:'Game Specific';
            $opts=[];if(in_array($type,['select','multiselect'],true)){$opts=array_values(array_filter(array_map('trim',preg_split('/\r?\n|,/',(string)($row['options']??''))),fn($x)=>$x!==''));}
            $questions[]=['group'=>$group,'code'=>$code,'label'=>$label,'type'=>$type,'options'=>$opts,'required'=>!empty($row['required'])];
        }
        $json=json_encode(['version'=>1,'questions'=>$questions],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE games SET pit_config_json=? WHERE id=?')->execute([$json,$gameId]);$msg='Pit form saved.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$s=$pdo->prepare('SELECT * FROM games WHERE id=?');$s->execute([$gameId]);$game=$s->fetch();$config=$game?json_decode($game['pit_config_json']??'',true):[];$questions=is_array($config['questions']??null)?$config['questions']:[];
$pageTitle='Pit Form Builder';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><h1 style="margin-bottom:4px">Pit Form Builder</h1><div class="muted">Add game-specific pit questions. Neptune automatically includes the standard robot, autonomous, endgame, strategy, and reliability questions.</div></div><a class="btn secondary" href="<?=e(base_url('pit/index.php'))?>"><i class="fa-solid fa-clipboard-list"></i> Open Pit Scouting</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<div class="card"><form method="get" class="analytics-toolbar"><div style="min-width:340px"><label style="margin-top:0">Game</label><select name="game_id" onchange="this.form.submit()"><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$gameId===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' · '.$g['name'])?></option><?php endforeach;?></select></div></form></div>
<div class="card" style="margin-top:16px"><div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0">Game-Specific Questions</h2><div class="muted">Codes auto-fill from the question name and are made unique with _b1, _b2, etc.</div></div><button type="button" id="addQuestion"><i class="fa-solid fa-plus"></i> Add Question</button></div>
<form method="post" id="pitBuilderForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=$gameId?>"><input type="hidden" name="questions_json" id="questionsJson"><div id="questionRows" class="builder-actions"></div><div class="toolbar"><button class="good"><i class="fa-solid fa-floppy-disk"></i> Save Pit Form</button></div></form></div>
<template id="questionTemplate"><div class="action-row pit-question-row"><div class="action-row-grid"><div class="wide"><label>Question</label><input data-k="label" class="q-label"></div><div><label>Code</label><input data-k="code" class="code-field q-code"></div><div><label>Type</label><select data-k="type"><option value="yes_no">Yes / No</option><option value="select">Dropdown</option><option value="multiselect">Multi-select</option><option value="number">Number</option><option value="text">Short text</option><option value="textarea">Long text</option></select></div><div><label>Section</label><input data-k="group" value="Game Specific"></div><div class="wide"><label>Options <span class="muted">(comma or one per line; dropdown/multi-select only)</span></label><textarea data-k="options" rows="2"></textarea></div><div><label>Required</label><select data-k="required"><option value="0">No</option><option value="1">Yes</option></select></div></div><div class="drag-controls"><button type="button" class="secondary q-up"><i class="fa-solid fa-arrow-up"></i></button><button type="button" class="secondary q-down"><i class="fa-solid fa-arrow-down"></i></button><button type="button" class="danger q-delete"><i class="fa-solid fa-trash"></i></button></div></div></template>
<script>
const initial=<?=json_encode($questions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,rows=document.getElementById('questionRows'),tpl=document.getElementById('questionTemplate');
function slug(s){return String(s||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')||'question'}
function makeUnique(input){let base=slug(input.value),used=[...document.querySelectorAll('.q-code')].filter(x=>x!==input).map(x=>x.value);let v=base,i=1;while(used.includes(v))v=base+'_b'+i++;input.value=v;}
function add(q={}){const n=tpl.content.firstElementChild.cloneNode(true);n.querySelectorAll('[data-k]').forEach(el=>{let v=q[el.dataset.k];if(el.dataset.k==='options'&&Array.isArray(v))v=v.join(', ');if(el.dataset.k==='required')v=q.required?'1':'0';if(v!==undefined)el.value=v;});const label=n.querySelector('.q-label'),code=n.querySelector('.q-code');let touched=!!q.code;label.addEventListener('input',()=>{if(!touched){code.value=slug(label.value);makeUnique(code)}});code.addEventListener('input',()=>touched=true);code.addEventListener('blur',()=>makeUnique(code));n.querySelector('.q-delete').onclick=()=>n.remove();n.querySelector('.q-up').onclick=()=>{if(n.previousElementSibling)rows.insertBefore(n,n.previousElementSibling)};n.querySelector('.q-down').onclick=()=>{if(n.nextElementSibling)rows.insertBefore(n.nextElementSibling,n)};rows.appendChild(n);}
(initial||[]).forEach(add);document.getElementById('addQuestion').onclick=()=>add({group:'Game Specific',type:'yes_no'});
document.getElementById('pitBuilderForm').addEventListener('submit',()=>{const data=[...document.querySelectorAll('.pit-question-row')].map(r=>{const o={};r.querySelectorAll('[data-k]').forEach(el=>o[el.dataset.k]=el.value);o.required=o.required==='1';return o});document.getElementById('questionsJson').value=JSON.stringify(data)});
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
