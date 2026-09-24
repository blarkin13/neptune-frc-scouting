<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin']);
$org=(int)$u['organization_id'];
$msg='';
$error='';

$s=$pdo->prepare(
    "SELECT g.*,o.name owner_org_name,
            CASE WHEN g.organization_id=? THEN 1 ELSE 0 END is_owned,
            cr.revision_number current_revision_number,
            dr.revision_number draft_revision_number
     FROM games g
     JOIN organizations o ON o.id=g.organization_id
     LEFT JOIN game_revisions cr ON cr.id=g.current_revision_id
     LEFT JOIN game_revisions dr ON dr.id=g.draft_revision_id
     WHERE g.organization_id=?
        OR EXISTS(
           SELECT 1 FROM game_config_shares gcs
           WHERE gcs.game_id=g.id AND gcs.recipient_organization_id=?
        )
     ORDER BY is_owned DESC,g.season_year DESC,g.name"
);
$s->execute([$org,$org,$org]);
$games=$s->fetchAll();

$gameId=(int)($_GET['game_id']??$_POST['game_id']??($games[0]['id']??0));
$game=null;
foreach($games as $candidate){
    if((int)$candidate['id']===$gameId){$game=$candidate;break;}
}
if(!$game&&$games){$game=$games[0];$gameId=(int)$game['id'];}
$canEdit=$game?((int)$game['organization_id']===$org):false;
$currentRevision=$game?neptune_current_game_revision($pdo,$gameId):null;
$draftRevision=$canEdit&&$game?neptune_draft_game_revision($pdo,$gameId):null;
$viewRevision=$draftRevision?:$currentRevision;

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$game)throw new RuntimeException('Game not found.');
        if(!$canEdit)throw new RuntimeException('Shared game configurations are read-only. Clone this game to your organization before editing its form.');

        $raw=json_decode($_POST['questions_json']??'[]',true);
        if(!is_array($raw))throw new RuntimeException('Invalid pre-scout form configuration.');

        $questions=[];$used=[];
        foreach($raw as $row){
            if(!is_array($row))continue;
            $label=trim((string)($row['label']??''));
            if($label==='')continue;
            $code=strtolower(trim((string)($row['code']??'')));
            $code=preg_replace('/[^a-z0-9_]+/','_',$code);
            $code=trim($code,'_');
            if($code==='')$code='question';
            $base=$code;$i=1;while(isset($used[$code])){$code=$base.'_b'.$i++;}$used[$code]=true;
            $type=(string)($row['type']??'text');
            if(!in_array($type,['yes_no','select','multiselect','number','text','textarea'],true))$type='text';
            $group=trim((string)($row['group']??'Game Specific'))?:'Game Specific';
            $opts=[];
            if(in_array($type,['select','multiselect'],true)){
                $opts=array_values(array_filter(array_map('trim',preg_split('/\r?\n|,/',(string)($row['options']??''))),fn($x)=>$x!==''));
            }
            $questions[]=['group'=>$group,'code'=>$code,'label'=>$label,'type'=>$type,'options'=>$opts,'required'=>!empty($row['required'])];
        }

        $json=json_encode(['schema_version'=>2,'version'=>2,'questions'=>$questions],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false)throw new RuntimeException('Could not encode form configuration.');

        $draft=neptune_ensure_game_draft_revision($pdo,$gameId,$org,(int)$u['id']);
        $matchJson=(string)$draft['match_config_json'];
        $pitJson=$draft['pit_config_json'];
        $preJson=$json;
        $checksum=neptune_revision_checksum($matchJson,$pitJson,$preJson,$draft['field_image_path']);
        $s=$pdo->prepare("UPDATE game_revisions SET pre_scout_config_json=?,checksum=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND game_id=? AND organization_id=? AND status='draft'");
        $s->execute([$json,$checksum,(int)$draft['id'],$gameId,$org]);
        $msg='Pre-Scout form saved to Draft Revision '.(int)$draft['revision_number'].'. Publish the revision in Game Builder when all configuration is ready.';
        $draftRevision=neptune_revision_by_id($pdo,(int)$draft['id']);
        $viewRevision=$draftRevision;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$config=$viewRevision?json_decode((string)($viewRevision['pre_scout_config_json']??''),true):[];
$questions=is_array($config['questions']??null)?$config['questions']:[];

$pageTitle='Pre-Scout Form Builder';
$moduleName='VULCAN';
include dirname(__DIR__).'/partials_header.php';
?>
<style>
/* Builder-page fallback styles. Scoped here so these pages remain styled even if shared CSS is stale. */
.builder-page{max-width:1260px;margin:0 auto;padding-top:4px}
.builder-page .builder-page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:6px 0 20px;padding-bottom:16px;border-bottom:1px solid var(--line)}
.builder-page .builder-page-header>div{min-width:0}
.builder-page .builder-page-header h1{margin:0 0 6px;font-size:clamp(2rem,3.2vw,2.75rem);letter-spacing:-.04em;line-height:1.05}
.builder-page .builder-page-header p{margin:0;max-width:850px;color:var(--muted);font-size:.96rem;line-height:1.5}
.builder-page .builder-readonly-fieldset{border:0;padding:0;margin:0;min-width:0}
.builder-page .builder-readonly-fieldset:disabled{opacity:.72}
.builder-page .builder-card{padding:18px;border:1px solid var(--line);border-radius:12px;background:var(--panel);box-shadow:0 10px 26px var(--shadow)}
.builder-page .builder-card+.builder-card{margin-top:16px}
.builder-page .builder-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}
.builder-page .builder-card-head h2{margin:0 0 4px;font-size:1.15rem}
.builder-page .builder-card-head p{margin:0;color:var(--muted);font-size:.84rem;line-height:1.45}
.builder-page .builder-game-row{display:grid;grid-template-columns:minmax(260px,440px) minmax(0,1fr);align-items:end;gap:14px}
.builder-page .builder-game-row label,.builder-page .action-row label{display:block;margin:0 0 6px;color:var(--muted);font-size:.72rem;font-weight:850;letter-spacing:.01em}
.builder-page input,.builder-page select,.builder-page textarea{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.builder-page .builder-actions{display:grid;gap:12px;margin-top:12px}
.builder-page .action-row{padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.builder-page .action-row-grid{display:grid;grid-template-columns:2fr 1.15fr 1fr 1.1fr;gap:12px}
.builder-page .action-row-grid>div{min-width:0}
.builder-page .action-row-grid .wide{grid-column:span 2}
.builder-page .action-row textarea{resize:vertical;min-height:72px}
.builder-page .code-field{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.builder-page .drag-controls{display:flex;justify-content:flex-end;gap:7px;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)}
.builder-page .drag-controls button{display:inline-grid;place-items:center;width:42px;height:38px;padding:0}
.builder-page .builder-save-row{display:flex;justify-content:flex-end;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.builder-page .builder-save-row button{min-width:180px}
.builder-page .notice{margin-bottom:14px}
@media(max-width:820px){
  .builder-page .builder-page-header{align-items:stretch;flex-direction:column}
  .builder-page .builder-page-header .btn{align-self:flex-start}
  .builder-page .builder-game-row{grid-template-columns:1fr}
  .builder-page .builder-card-head{align-items:stretch;flex-direction:column}
  .builder-page .builder-card-head .toolbar{justify-content:flex-start;flex-wrap:wrap}
  .builder-page .action-row-grid{grid-template-columns:1fr 1fr}
  .builder-page .action-row-grid .wide{grid-column:span 2}
}
@media(max-width:540px){
  .builder-page .builder-card{padding:14px}
  .builder-page .action-row{padding:13px}
  .builder-page .action-row-grid{grid-template-columns:1fr}
  .builder-page .action-row-grid .wide{grid-column:auto}
  .builder-page .builder-save-row button{width:100%}
}
</style>
<section class="module-page builder-page">
  <header class="builder-page-header">
    <div>
      <h1>Pre-Scout Form Builder</h1>
      <p>Build the questions your pre-scout team uses for this game. Neptune keeps the answers reusable for the same robot during the same season.</p>
    </div>
    <a class="btn secondary" href="<?=e(base_url('prescout/index.php'))?>"><i class="fa-solid fa-envelope-open-text"></i> Open Pre-Scouting</a>
  </header>

  <?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
  <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
  <?php if($game && $canEdit):?>
    <div class="notice"><i class="fa-solid fa-code-branch"></i> <?php if($draftRevision):?><b>Editing Draft Revision <?=e($draftRevision['revision_number'])?>.</b> Changes do not affect existing events until a revision is published.<?php elseif($currentRevision):?><b>Published Revision <?=e($currentRevision['revision_number'])?> is active.</b> Saving here will create the next draft revision.<?php else:?><b>This game has not been published yet.</b> Saving here updates its draft.<?php endif;?></div>
  <?php endif;?>
  <?php if($game && !$canEdit):?>
    <div class="notice">
      <div class="toolbar" style="justify-content:space-between;margin:0">
        <span><i class="fa-solid fa-lock"></i> <b>Read-only shared configuration.</b> Owned by <?=e($game['owner_org_name'])?>.</span>
        <form method="post" action="<?=e(base_url('admin/game-builder.php'))?>" style="margin:0">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="clone_game">
          <input type="hidden" name="source_game_id" value="<?=$gameId?>">
          <button type="submit"><i class="fa-solid fa-copy"></i> Clone to My Organization</button>
        </form>
      </div>
    </div>
  <?php endif;?>

  <div class="card builder-card">
    <form method="get" class="builder-game-row">
      <div>
        <label style="margin-top:0">Game</label>
        <select name="game_id" onchange="this.form.submit()">
          <?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$gameId===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' · '.$g['name'].((int)$g['organization_id']===$org?(!empty($g['draft_revision_number'])?' · Draft r'.$g['draft_revision_number']:' · Published r'.($g['current_revision_number']??'—')):' · Shared by '.$g['owner_org_name'].' · r'.($g['current_revision_number']??'—')))?></option><?php endforeach;?>
        </select>
      </div>
      <span class="muted">Choose which season/game configuration you are editing.</span>
    </form>
  </div>

  <div class="card builder-card">
    <div class="builder-card-head">
      <div><h2>Game-Specific Questions</h2><p>These are the actual questions shown on the team Pre-Scout form. Codes auto-fill and duplicates become _b1, _b2, etc.</p></div>
      <div class="toolbar" style="margin:0"><button type="button" class="secondary" id="loadRebuiltTemplate" <?=$canEdit?'':'disabled'?>><i class="fa-solid fa-table"></i> Load 2026 REBUILT Template</button><button type="button" id="addQuestion" <?=$canEdit?'':'disabled'?>><i class="fa-solid fa-plus"></i> Add Question</button></div>
    </div>
    <form method="post" id="preBuilderForm">
      <fieldset class="builder-readonly-fieldset" <?=$canEdit?'':'disabled'?>>
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="game_id" value="<?=$gameId?>">
      <input type="hidden" name="questions_json" id="questionsJson">
      <div id="questionRows" class="builder-actions"></div>
      <div class="builder-save-row"><button class="good"><i class="fa-solid fa-floppy-disk"></i> Save Pre-Scout Draft</button></div>
      </fieldset>
    </form>
  </div>
<template id="questionTemplate"><div class="action-row pit-question-row"><div class="action-row-grid"><div class="wide"><label>Question</label><input data-k="label" class="q-label"></div><div><label>Code</label><input data-k="code" class="code-field q-code"></div><div><label>Type</label><select data-k="type"><option value="yes_no">Yes / No</option><option value="select">Dropdown</option><option value="multiselect">Multi-select</option><option value="number">Number</option><option value="text">Short text</option><option value="textarea">Long text</option></select></div><div><label>Section</label><input data-k="group" value="Game Specific"></div><div class="wide"><label>Options <span class="muted">(comma or one per line; dropdown/multi-select only)</span></label><textarea data-k="options" rows="2"></textarea></div><div><label>Required</label><select data-k="required"><option value="0">No</option><option value="1">Yes</option></select></div></div><div class="drag-controls"><button type="button" class="secondary q-up"><i class="fa-solid fa-arrow-up"></i></button><button type="button" class="secondary q-down"><i class="fa-solid fa-arrow-down"></i></button><button type="button" class="danger q-delete"><i class="fa-solid fa-trash"></i></button></div></div></template>
<script>
const initial=<?=json_encode($questions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,rows=document.getElementById('questionRows'),tpl=document.getElementById('questionTemplate');
function slug(s){return String(s||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')||'question'}
function makeUnique(input){let base=slug(input.value),used=[...document.querySelectorAll('.q-code')].filter(x=>x!==input).map(x=>x.value);let v=base,i=1;while(used.includes(v))v=base+'_b'+i++;input.value=v;}
function add(q={}){const n=tpl.content.firstElementChild.cloneNode(true);n.querySelectorAll('[data-k]').forEach(el=>{let v=q[el.dataset.k];if(el.dataset.k==='options'&&Array.isArray(v))v=v.join(', ');if(el.dataset.k==='required')v=q.required?'1':'0';if(v!==undefined)el.value=v;});const label=n.querySelector('.q-label'),code=n.querySelector('.q-code');let touched=!!q.code;label.addEventListener('input',()=>{if(!touched){code.value=slug(label.value);makeUnique(code)}});code.addEventListener('input',()=>touched=true);code.addEventListener('blur',()=>makeUnique(code));n.querySelector('.q-delete').onclick=()=>n.remove();n.querySelector('.q-up').onclick=()=>{if(n.previousElementSibling)rows.insertBefore(n,n.previousElementSibling)};n.querySelector('.q-down').onclick=()=>{if(n.nextElementSibling)rows.insertBefore(n.nextElementSibling,n)};rows.appendChild(n);}
const rebuiltTemplate=[
{group:'2026 REBUILT Robot Questions',code:'auton_description',label:'Auto',type:'textarea',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'trench_capable',label:'Trench',type:'yes_no',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'scoring_capacity',label:'Hopper Size',type:'text',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'scoring_mechanism',label:'Shooter',type:'text',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'endgame_capability',label:'Climb',type:'text',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'scoring_throughput',label:'Throughput',type:'text',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'drive_notes',label:'Drive Notes',type:'textarea',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'defense_notes',label:'Defence / CounterDefence (Ramming Speed, Behavior, etc)',type:'textarea',options:[],required:false},
{group:'2026 REBUILT Robot Questions',code:'robot_archetype',label:'Archetype',type:'text',options:[],required:false}
];
(initial||[]).forEach(add);document.getElementById('addQuestion').onclick=()=>add({group:'Game Specific',type:'yes_no'});
document.getElementById('loadRebuiltTemplate').onclick=async()=>{if(rows.children.length){const ok=await NeptuneUI.confirm('Replace the current questions with the 2026 REBUILT pre-scout template?',{title:'Replace questions',confirmText:'Replace',danger:true});if(!ok)return;}rows.innerHTML='';rebuiltTemplate.forEach(add);NeptuneUI.toast('2026 REBUILT template loaded.','good');};
document.getElementById('preBuilderForm').addEventListener('submit',()=>{const data=[...document.querySelectorAll('.pit-question-row')].map(r=>{const o={};r.querySelectorAll('[data-k]').forEach(el=>o[el.dataset.k]=el.value);o.required=o.required==='1';return o});document.getElementById('questionsJson').value=JSON.stringify(data)});
</script>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
