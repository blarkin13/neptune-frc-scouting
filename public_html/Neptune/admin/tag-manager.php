<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/scout/_tag_scouting.php';

$u=require_role(['owner','admin','strategy']);
$navigationSection='command';
tag_unified_ensure_schema($pdo);
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$ready=tagobs_tables_ready($pdo);
$flash=null;
$showAll=!empty($_GET['show_all']);
$tab=(string)($_GET['tab']??$_POST['tab']??'match');if(!in_array($tab,['match','pit','team'],true))$tab='match';
$editId=(int)($_GET['edit']??0);
$edit=null;

if($ready && $_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='save_order'){
            $categoriesRaw=json_decode((string)($_POST['category_order']??'[]'),true);
            $tagsRaw=json_decode((string)($_POST['tag_order']??'[]'),true);
            if(!is_array($categoriesRaw)||!is_array($tagsRaw))throw new RuntimeException('Invalid ordering data.');
            $available=array_values(array_filter(tagobs_visible_tags($pdo,$org,true),static fn($r)=>!empty($r['active'])&&!empty($r[$tab.'_enabled'])));
            $allowedIds=array_fill_keys(array_map('intval',array_column($available,'id')),true);
            $allowedCategories=array_fill_keys(array_column($available,'category'),true);
            $catOrder=[];
            foreach($categoriesRaw as $name){
                if(!is_string($name)||!isset($allowedCategories[$name])||isset($catOrder[$name]))throw new RuntimeException('Unrecognized or repeated category.');
                $catOrder[$name]=count($catOrder);
            }
            if(count($catOrder)!==count($allowedCategories))throw new RuntimeException('Category order is incomplete.');
            $tagOrder=[];
            foreach($tagsRaw as $id){
                if(!is_int($id)||!isset($allowedIds[$id])||isset($tagOrder[$id]))throw new RuntimeException('Unrecognized or repeated tag.');
                $tagOrder[$id]=count($tagOrder);
            }
            if(count($tagOrder)!==count($allowedIds))throw new RuntimeException('Tag order is incomplete.');
            $pdo->beginTransaction();
            $c=$pdo->prepare('INSERT INTO tag_scouting_category_order (organization_id,context,category,sort_order) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)');
            foreach($catOrder as $name=>$i)$c->execute([$org,$tab,$name,($i+1)*100]);
            $t=$pdo->prepare('INSERT INTO tag_scouting_tag_order (organization_id,context,tag_id,sort_order) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)');
            foreach($tagOrder as $id=>$i)$t->execute([$org,$tab,$id,($i+1)*100]);
            $pdo->commit();
            $flash=['good',ucfirst($tab).' category and tag order saved.'];
        }elseif($action==='toggle_context'){
            $id=(int)($_POST['id']??0);
            $field=$tab.'_enabled';
            $lookup=$pdo->prepare('SELECT * FROM tag_scouting_tags WHERE id=? AND (organization_id=? OR organization_id IS NULL) LIMIT 1');
            $lookup->execute([$id,$org]);$original=$lookup->fetch(PDO::FETCH_ASSOC);
            if(!$original)throw new RuntimeException('Tag not found.');
            $current=next(iter(array_filter(tagobs_visible_tags($pdo,$org,true),static fn($row)=>(int)$row['id']===$id)),null);
            if(!$current)throw new RuntimeException('Tag is not available to your organization.');
            $next=empty($current[$field])?1:0;
            if($original['organization_id']===null){
                // Preserve all other contexts, overridden text/icon/order, and existing visibility.
                $stmt=$pdo->prepare("INSERT INTO tag_scouting_tag_overrides (organization_id,tag_id,label,category,icon,severity,match_enabled,pit_enabled,team_enabled,sort_order,description,when_to_use,why_important) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),team_enabled=VALUES(team_enabled)");
                $flags=['match'=>(int)$current['match_enabled'],'pit'=>(int)$current['pit_enabled'],'team'=>(int)$current['team_enabled']];
                $flags[$tab]=$next;
                $stmt->execute([$org,$id,$current['label'],$current['category'],$current['icon'],$current['severity'],$flags['match'],$flags['pit'],$flags['team'],(int)$current['sort_order'],$current['description']??null,$current['when_to_use']??null,$current['why_important']??null]);
            }else{
                $stmt=$pdo->prepare("UPDATE tag_scouting_tags SET {$field}=? WHERE id=? AND organization_id=?");
                $stmt->execute([$next,$id,$org]);
            }
            $flash=['good',ucfirst($tab).' tag '.($next?'activated':'deactivated').'. Other scouting tabs were not changed.'];
        }else{
            $id=(int)($_POST['id']??0);
            $label=mb_substr(trim((string)($_POST['label']??'')),0,120);
            if($label==='')throw new RuntimeException('Tag name is required.');
            $slug=tagobs_slug($label);
            if($slug==='')throw new RuntimeException('Tag name must contain letters or numbers.');
            $category=mb_substr(trim((string)($_POST['category']??'General')),0,80)?:'General';
            $icon=mb_substr(trim((string)($_POST['icon']??'fa-solid fa-tag')),0,120)?:'fa-solid fa-tag';
            if(!preg_match('/^[a-z0-9 _-]+$/i',$icon))$icon='fa-solid fa-tag';
            $severity=(string)($_POST['severity']??'info');
            if(!in_array($severity,['positive','info','warning','critical'],true))$severity='info';
            $match=!empty($_POST['match_enabled'])?1:0;
            $pit=!empty($_POST['pit_enabled'])?1:0;
            $teamEnabled=!empty($_POST['team_enabled'])?1:0;
            // A tag can be inactive in all contexts while preserving historical records.
            $sort=max(0,min(10000,(int)($_POST['sort_order']??100)));
            $description=mb_substr(trim((string)($_POST['description']??'')),0,3000);
            $when=mb_substr(trim((string)($_POST['when_to_use']??'')),0,3000);
            $why=mb_substr(trim((string)($_POST['why_important']??'')),0,3000);

            $s=$pdo->prepare('SELECT id,label FROM tag_scouting_tags WHERE organization_id=? AND slug=? AND id<>? LIMIT 1');
            $s->execute([$org,$slug,$id]);
            if($dupe=$s->fetch())throw new RuntimeException('A team tag named “'.$dupe['label'].'” already exists.');

            if($id>0){
                $lookup=$pdo->prepare('SELECT id,organization_id FROM tag_scouting_tags WHERE id=? AND (organization_id=? OR organization_id IS NULL) LIMIT 1');
                $lookup->execute([$id,$org]);$found=$lookup->fetch();
                if(!$found)throw new RuntimeException('Tag not found in your organization.');
                if($found['organization_id']===null){
                    $stmt=$pdo->prepare("INSERT INTO tag_scouting_tag_overrides (organization_id,tag_id,label,category,icon,severity,match_enabled,pit_enabled,team_enabled,sort_order,description,when_to_use,why_important) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),category=VALUES(category),icon=VALUES(icon),severity=VALUES(severity),match_enabled=VALUES(match_enabled),pit_enabled=VALUES(pit_enabled),team_enabled=VALUES(team_enabled),sort_order=VALUES(sort_order),description=VALUES(description),when_to_use=VALUES(when_to_use),why_important=VALUES(why_important)");
                    $stmt->execute([$org,$id,$label,$category,$icon,$severity,$match,$pit,$teamEnabled,$sort,$description,$when,$why]);
                }else{
                    $stmt=$pdo->prepare('UPDATE tag_scouting_tags SET label=?,slug=?,category=?,icon=?,severity=?,match_enabled=?,pit_enabled=?,team_enabled=?,sort_order=?,description=?,when_to_use=?,why_important=? WHERE id=? AND organization_id=?');
                    $stmt->execute([$label,$slug,$category,$icon,$severity,$match,$pit,$teamEnabled,$sort,$description,$when,$why,$id,$org]);
                }
                $flash=['good','Tag updated.'];
            }else{
                $stmt=$pdo->prepare("INSERT INTO tag_scouting_tags (organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,team_enabled,active,sort_order,description,when_to_use,why_important,created_by) VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)");
                $stmt->execute([$org,$label,$slug,$category,$icon,$severity,$match,$pit,$teamEnabled,$sort,$description,$when,$why,$userId]);
                $flash=['good','Tag created for your organization.'];
            }
            $editId=0;
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$flash=['bad',$e->getMessage()];}
}

$tags=$ready?tagobs_ordered_tags($pdo,$org,$tab,tagobs_visible_tags($pdo,$org,true)):[];
if($ready&&$editId>0){
    foreach($tags as $candidate){if((int)$candidate['id']===$editId){$edit=$candidate;break;}}
}
// Tab filtering applies to the effective, per-organization context flags.
$visibleTags=array_values(array_filter($tags,static fn($tag)=>
    $showAll || ((int)($tag[$tab.'_enabled']??0)===1 && (int)$tag['active']===1)
));
$categories=array_values(array_unique(array_filter(array_map(static fn($t)=>(string)$t['category'],$tags))));sort($categories);
$pageTitle='Tag Editor';$moduleName='TRIDENT';
$pageStyles=['assets/vendor/fontawesome/css/all.min.css','assets/css/spot-icon-picker.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.tag-editor-tabs{display:flex;gap:9px;margin:0 0 15px;flex-wrap:wrap}.tag-editor-tabs .btn.active{border-color:var(--module-trident,var(--accent));color:var(--module-trident,var(--accent));background:color-mix(in srgb,var(--module-trident,var(--accent)) 12%,var(--panel2))}.tag-editor-tabs .btn{min-width:95px;justify-content:center}.spot-tag-admin{display:grid;grid-template-columns:minmax(300px,.75fr) minmax(0,1.25fr);gap:16px;align-items:start}.spot-admin-card{padding:16px}.spot-contexts{display:flex;gap:12px;flex-wrap:wrap}.spot-tag-table{width:100%;border-collapse:collapse}.spot-tag-table th,.spot-tag-table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.spot-tag-table th{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.spot-tag-preview{display:inline-flex;align-items:center;gap:7px;font-weight:850}.spot-scope{font-size:.64rem}.spot-tag-actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center;white-space:nowrap}.spot-tag-actions form{margin:0;display:inline-flex}.spot-tag-actions .btn,.spot-tag-actions button{white-space:nowrap;padding:8px 11px;min-height:38px;font-size:.72rem}.spot-muted-row{opacity:.58}.spot-icon-examples{font-size:.7rem;color:var(--muted);line-height:1.5}.spot-context-pills{display:flex;gap:4px;flex-wrap:wrap}.spot-context-pills span{font-size:.62rem;border:1px solid var(--line);border-radius:999px;padding:3px 5px}@media(max-width:900px){.tag-editor-tabs{display:flex;gap:9px;margin:0 0 15px}.tag-editor-tabs .btn{min-width:95px;justify-content:center}.spot-tag-admin{grid-template-columns:1fr}.spot-tag-table thead{display:none}.spot-tag-table,.spot-tag-table tbody,.spot-tag-table tr,.spot-tag-table td{display:block;width:100%}.spot-tag-table tr{padding:10px 0;border-bottom:1px solid var(--line)}.spot-tag-table td{border:0;padding:4px 0}}

.tag-editor-actions{display:flex;align-items:center;flex-wrap:wrap;gap:14px;justify-content:space-between;margin:14px 0}.tag-editor-count{display:grid;gap:3px}.tag-editor-count strong{font-size:1.3rem}.tag-editor-count span{color:var(--muted);font-size:.83rem}.tag-editor-show{display:flex;align-items:center;gap:7px;font-size:.82rem;color:var(--muted)}.tag-editor-workspace{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}.tag-editor-workspace:has(.tag-editor-form:not([hidden])){grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr)}.tag-editor-form[hidden]{display:none}.tag-editor-form{position:sticky;top:15px;max-height:90vh;overflow:auto}.tag-editor-form-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.tag-editor-form-head h2{margin:0}.tag-editor-category{margin-bottom:18px}.tag-editor-category h2{font-size:1rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:5px 0 12px}.tag-editor-rows{display:grid;gap:9px}.tag-editor-tag{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}.tag-editor-tag-off{opacity:.7}.tag-editor-tag-main{display:flex;align-items:center;min-width:0;gap:13px}.tag-editor-glyph{width:46px;height:46px;display:grid;place-items:center;border:1px solid var(--line);border-radius:12px;flex:none}.tag-editor-glyph i{font-size:1.5rem;color:var(--module-trident,var(--accent))}.tag-editor-tag-text{min-width:0;display:grid;gap:3px}.tag-editor-tag-text strong{font-size:1rem}.tag-editor-tag-text small{color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:55ch}.tag-editor-tag-text span{font-size:.7rem;color:var(--muted)}.tag-editor-tag-buttons{display:flex;gap:6px;flex:none}.tag-editor-tag-buttons form{margin:0}.tag-editor-tag-buttons button,.tag-editor-tag-buttons .btn{min-height:40px;padding:8px 11px}.tag-editor-form label{display:block;margin-top:10px}.tag-editor-form input:not([type=checkbox]),.tag-editor-form textarea,.tag-editor-form select{width:100%}@media(max-width:1000px){.tag-editor-workspace:has(.tag-editor-form:not([hidden])){grid-template-columns:1fr}.tag-editor-form{position:static;max-height:none}}@media(max-width:600px){.tag-editor-tag{flex-wrap:wrap}.tag-editor-tag-buttons{width:100%;justify-content:flex-end}.tag-editor-actions{align-items:stretch}.tag-editor-tag-text small{max-width:260px}}

.tag-sort-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding:8px 2px 18px}.tag-sort-toolbar .muted{flex:1;font-size:.78rem}.tag-sort-category-head{display:flex;align-items:center;gap:10px;margin-bottom:9px}.tag-sort-category-head h2{margin:0!important;flex:1}.tag-drag-handle{cursor:grab;touch-action:none;color:var(--muted);padding:7px;display:inline-flex;align-items:center}.tag-drag-handle:active{cursor:grabbing}.tag-sort-arrows{display:inline-flex;gap:4px}.tag-sort-arrows button{min-height:32px;padding:3px 9px}.tag-editor-library .tag-editor-category{padding:9px;border:1px solid var(--line);border-radius:12px}
@media(max-width:720px){.tag-sort-toolbar{align-items:stretch}.tag-sort-toolbar button{width:100%}.tag-editor-tag-main{gap:7px}.tag-editor-tag-buttons{flex-wrap:wrap}}
</style>
<section class="module-page">
<header class="module-page-header" style="margin-bottom:16px"><div><div class="module-code">TRIDENT</div><h1>Tag Editor</h1><p>Manage Match, Pit, and Team tags in one place. Edit defaults safely for your organization; historical observations remain unchanged.</p></div><a class="btn secondary" href="<?=e(base_url('scout/tag.php?tab='.$tab))?>"><i class="fa-solid fa-arrow-left"></i> Tag Scouting</a></header>
<?php if(!$ready):?><div class="notice bad">Tag Scouting storage could not be initialized. Check the server/database error log.</div><?php else:?>
<?php if($flash):?><div class="notice <?=$flash[0]==='bad'?'bad':'good'?>"><?=e($flash[1])?></div><?php endif;?>
<nav class="tag-editor-tabs" aria-label="Tag type">
<?php foreach(['match'=>'fa-hashtag','pit'=>'fa-screwdriver-wrench','team'=>'fa-binoculars'] as $context=>$iconClass):?>
  <a class="btn secondary <?= $tab===$context?'active':'' ?>" <?= $tab===$context?'aria-current="page"':'' ?> href="<?=e(base_url('admin/tag-manager.php?tab='.$context))?>"><i class="fa-solid <?=$iconClass?>" aria-hidden="true"></i> <?=ucfirst($context)?></a>
<?php endforeach;?>
</nav>
<div class="tag-editor-actions">
  <div class="tag-editor-count"><strong><?=ucfirst(e($tab))?> Tag Scouting</strong><span><?=count(array_filter($tags,static fn($t)=>!empty($t['active'])&&!empty($t[$tab.'_enabled'])))?> active tags displayed in this tab</span></div>
  <label class="tag-editor-show"><input type="checkbox" id="showInactive" <?=$showAll?'checked':''?> onchange="location.href=this.checked?<?=json_encode(base_url('admin/tag-manager.php?tab='.$tab.'&show_all=1'))?>:<?=json_encode(base_url('admin/tag-manager.php?tab='.$tab))?>"> Show inactive and other-context tags</label>
  <button type="button" class="btn" id="showAddTag"><i class="fa-solid fa-plus"></i> Add <?=$tab==='match'?'Match':($tab==='pit'?'Pit':'Alliance')?> Tag</button>
</div>
<div class="tag-editor-workspace">
 <section class="card spot-admin-card tag-editor-library">
  <?php if(!$showAll && $visibleTags):?>
  <div class="tag-sort-toolbar"><strong><i class="fa-solid fa-grip-vertical"></i> Arrange categories and tags</strong><span class="muted">Drag a handle or use arrows, then Save Order.</span><button type="submit" form="tagOrderForm" class="btn"><i class="fa-solid fa-floppy-disk"></i> Save Order</button></div>
  <form id="tagOrderForm" method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="<?=e($tab)?>"><input type="hidden" name="action" value="save_order"><input type="hidden" name="category_order" id="categoryOrderJson"><input type="hidden" name="tag_order" id="tagOrderJson"></form>
  <?php endif;?>
  <?php if(!$visibleTags):?><p class="muted">No tags for this tab. Show inactive tags to activate an existing one, or add a new tag.</p><?php endif;?>
  <?php $prevCategory=null; foreach($visibleTags as $tag): if($prevCategory!==$tag['category']):if($prevCategory!==null)echo '</div></div>'; $prevCategory=$tag['category'];?><div class="tag-editor-category" data-category="<?=e($prevCategory)?>" draggable="false"><div class="tag-sort-category-head"><span class="tag-drag-handle" draggable="true" title="Drag category" aria-label="Drag category"><i class="fa-solid fa-grip-vertical"></i></span><h2><?=e($prevCategory)?></h2><span class="tag-sort-arrows"><button type="button" class="secondary" data-shift="category-up" aria-label="Move category up">↑</button><button type="button" class="secondary" data-shift="category-down" aria-label="Move category down">↓</button></span></div><div class="tag-editor-rows"><?php endif;?>
  <?php $on=!empty($tag['active'])&&!empty($tag[$tab.'_enabled']);?>
  <div data-tag-id="<?=(int)$tag['id']?>" class="tag-editor-tag <?=$on?'':'tag-editor-tag-off'?>">
    <div class="tag-editor-tag-main"><span class="tag-drag-handle" draggable="true" title="Drag tag" aria-label="Drag tag"><i class="fa-solid fa-grip-vertical"></i></span><span class="tag-editor-glyph"><i class="<?=e($tag['icon']?:'fa-solid fa-tag')?>"></i></span><div class="tag-editor-tag-text"><strong><?=e($tag['label'])?></strong><small><?=e($tag['description']?:($tag['when_to_use']?:'No description yet'))?></small><span><?= $on?'Active in '.ucfirst($tab):'Not shown in '.ucfirst($tab)?><?= $tag['scope']==='global'?' · Neptune default':' · Organization tag' ?></span></div></div>
    <div class="tag-editor-tag-buttons"><span class="tag-sort-arrows"><button type="button" class="secondary" data-shift="tag-up" aria-label="Move tag up">↑</button><button type="button" class="secondary" data-shift="tag-down" aria-label="Move tag down">↓</button></span><a class="btn secondary" href="<?=e(base_url('admin/tag-manager.php?tab='.$tab.'&edit='.(int)$tag['id'].($showAll?'&show_all=1':'')))?>"><i class="fa-solid fa-pen"></i> Edit</a>
    <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="tab" value="<?=e($tab)?>"><input type="hidden" name="action" value="toggle_context"><input type="hidden" name="id" value="<?=(int)$tag['id']?>"><button class="secondary" type="submit" aria-label="<?=$on?'Deactivate':'Activate'?> <?=e($tag['label'])?> in <?=$tab?>"><i class="fa-solid <?=$on?'fa-eye-slash':'fa-eye'?>"></i> <?=$on?'Deactivate':'Activate'?></button></form></div>
  </div>
  <?php endforeach; if($prevCategory!==null)echo '</div></div>'; ?>
 </section>
 <section class="card spot-admin-card tag-editor-form" id="tagEditorForm" <?=$edit?'':'hidden'?>>
  <div class="tag-editor-form-head"><h2><?=$edit?'Edit Tag':'Add '.ucfirst($tab).' Tag'?></h2><a href="<?=e(base_url('admin/tag-manager.php?tab='.$tab.($showAll?'&show_all=1':'')))?>" class="btn secondary"><i class="fa-solid fa-xmark"></i> Close</a></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="tab" value="<?=e($tab)?>"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
    <label>Tag name</label><input name="label" maxlength="120" required value="<?=e($edit['label']??'')?>" placeholder="Tag name">
    <label>Category</label><input name="category" list="spotCategories" maxlength="80" value="<?=e($edit['category']??($tab==='match'?'Scoring':'General'))?>"><datalist id="spotCategories"><?php foreach($categories as $c):?><option value="<?=e($c)?>"><?php endforeach;?></datalist>
    <label for="spotIconClass">Font Awesome icon</label><div class="spot-icon-field"><span class="spot-icon-current-preview"><i id="spotIconCurrentPreview" class="<?=e($edit['icon']??'fa-solid fa-tag')?>"></i></span><input id="spotIconClass" name="icon" maxlength="120" value="<?=e($edit['icon']??'fa-solid fa-tag')?>"><button type="button" class="secondary spot-icon-choose-btn" id="spotChooseIconBtn"><i class="fa-solid fa-icons"></i> Choose Icon</button></div>
    <label>Signal</label><select name="severity"><?php foreach(['positive'=>'Positive','info'=>'Information','warning'=>'Warning','critical'=>'Critical'] as $v=>$l):?><option value="<?=$v?>" <?=($edit['severity']??'info')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select>
    <label>Available in</label><div class="spot-contexts"><label><input type="checkbox" name="match_enabled" value="1" <?=($edit?!empty($edit['match_enabled']):$tab==='match')?'checked':''?>> Match</label><label><input type="checkbox" name="pit_enabled" value="1" <?=($edit?!empty($edit['pit_enabled']):$tab==='pit')?'checked':''?>> Pit</label><label><input type="checkbox" name="team_enabled" value="1" <?=($edit?!empty($edit['team_enabled']):$tab==='team')?'checked':''?>> Team</label></div>
    <label>Description</label><textarea name="description" rows="2" placeholder="What this tag means"><?=e($edit['description']??'')?></textarea>
    <label>When to use</label><textarea name="when_to_use" rows="3" placeholder="What the scout should observe"><?=e($edit['when_to_use']??'')?></textarea>
    <label>Why it matters</label><textarea name="why_important" rows="3" placeholder="What this observation tells strategy"><?=e($edit['why_important']??'')?></textarea>
    <label>Sort order</label><input type="number" name="sort_order" min="0" max="10000" value="<?=e($edit['sort_order']??100)?>">
    <div class="toolbar" style="margin-top:16px"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?=$edit?'Save Changes':'Create Tag'?></button></div>
  </form>
 </section>
</div>

<script>
(()=>{
 const lib=document.querySelector('.tag-editor-library');const form=document.getElementById('tagOrderForm');
 if(!lib||!form)return;
 const categories=()=>[...lib.querySelectorAll('.tag-editor-category')];
 const rows=c=>[...c.querySelectorAll('.tag-editor-tag')];
 let dragged=null;
 lib.addEventListener('dragstart',e=>{
  const handle=e.target.closest('.tag-drag-handle');if(!handle)return;
  const tag=handle.closest('.tag-editor-tag');const cat=handle.closest('.tag-editor-category');
  dragged=tag?{type:'tag',el:tag,cat}:{type:'category',el:cat};
  e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','neptune-order');
 });
 lib.addEventListener('dragover',e=>{
  if(!dragged)return;
  const target=dragged.type==='tag'?e.target.closest('.tag-editor-tag'):e.target.closest('.tag-editor-category');
  if(!target||target===dragged.el)return;
  if(dragged.type==='tag'&&target.closest('.tag-editor-category')!==dragged.cat)return;
  e.preventDefault();e.dataTransfer.dropEffect='move';
 });
 lib.addEventListener('drop',e=>{
  if(!dragged)return;
  const target=dragged.type==='tag'?e.target.closest('.tag-editor-tag'):e.target.closest('.tag-editor-category');
  if(target&&target!==dragged.el&&(dragged.type==='category'||target.closest('.tag-editor-category')===dragged.cat)){
   e.preventDefault();const box=dragged.type==='tag'?dragged.cat.querySelector('.tag-editor-rows'):lib;
   const rect=target.getBoundingClientRect();const before=e.clientY<rect.top+rect.height/2;
   box.insertBefore(dragged.el,before?target:target.nextSibling);
  }
  dragged=null;
 });
 lib.addEventListener('dragend',()=>dragged=null);
 lib.addEventListener('click',e=>{
  const b=e.target.closest('[data-shift]');if(!b)return;
  const isCat=b.dataset.shift.startsWith('category');const el=b.closest(isCat?'.tag-editor-category':'.tag-editor-tag');
  const holder=isCat?lib:el.closest('.tag-editor-rows');
  const sib=b.dataset.shift.endsWith('up')?el.previousElementSibling:el.nextElementSibling;
  if(!sib||!(isCat?sib.classList.contains('tag-editor-category'):sib.classList.contains('tag-editor-tag')))return;
  if(b.dataset.shift.endsWith('up'))holder.insertBefore(el,sib);else holder.insertBefore(sib,el);
 });
 form.addEventListener('submit',e=>{
  const cs=categories();const ts=cs.flatMap(rows).map(x=>Number(x.dataset.tagId));
  document.getElementById('categoryOrderJson').value=JSON.stringify(cs.map(x=>x.dataset.category));
  document.getElementById('tagOrderJson').value=JSON.stringify(ts);
 });
})();
</script>
<script>document.getElementById('showAddTag')?.addEventListener('click',()=>{const f=document.getElementById('tagEditorForm');f.hidden=false;f.scrollIntoView({block:'start',behavior:'smooth'});f.querySelector('[name=label]')?.focus({preventScroll:true});});</script>
<?php endif;?>
<dialog class="spot-icon-picker-dialog" id="spotIconPicker" data-catalog-url="<?=e(base_url('assets/vendor/fontawesome/icon-catalog.json'))?>" aria-labelledby="spotIconPickerTitle">
  <div class="spot-icon-picker-shell">
    <header class="spot-icon-picker-head">
      <div><h2 id="spotIconPickerTitle"><i class="fa-solid fa-icons"></i> Choose Icon</h2><p id="spotIconVersion">Font Awesome Free icon library</p></div>
      <button type="button" class="secondary spot-icon-picker-close" id="spotIconPickerClose" aria-label="Close icon picker"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="spot-icon-picker-layout">
      <section class="spot-icon-browser" aria-label="Available icons">
        <div class="spot-icon-tools">
          <label class="spot-icon-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="spotIconSearch" placeholder="Search icons: shield, robot, defense, warning…" autocomplete="off"></label>
          <div class="spot-icon-filters">
            <select id="spotIconCategory" aria-label="Icon category"><option value="">All categories</option></select>
            <div class="spot-icon-style-tabs" role="group" aria-label="Icon style filter">
              <button type="button" class="secondary active" data-icon-style-filter="all">All</button>
              <button type="button" class="secondary" data-icon-style-filter="solid">Solid</button>
              <button type="button" class="secondary" data-icon-style-filter="regular">Regular</button>
              <button type="button" class="secondary" data-icon-style-filter="brands">Brands</button>
            </div>
          </div>
        </div>
        <div class="spot-icon-scroll" id="spotIconScroll">
          <div class="spot-icon-result-meta"><span id="spotIconResultCount">Loading icons…</span><span>Click an icon for details</span></div>
          <div class="spot-icon-grid" id="spotIconGrid"></div>
          <div class="spot-icon-load-sentinel" id="spotIconLoadSentinel" aria-hidden="true"></div>
        </div>
      </section>
      <aside class="spot-icon-detail" id="spotIconDetail" aria-label="Selected icon details">
        <div class="spot-icon-detail-preview" id="spotIconDetailPreview"><i class="fa-solid fa-icons"></i></div>
        <div class="spot-icon-detail-title"><h3 id="spotIconDetailTitle">Choose an icon</h3><code id="spotIconDetailName">Select any icon from the library.</code></div>
        <div class="spot-icon-detail-section"><span>Available styles</span><div class="spot-icon-variant-list" id="spotIconVariants"></div></div>
        <div class="spot-icon-detail-section"><span>Categories</span><div class="spot-icon-categories" id="spotIconCategories"></div></div>
        <div class="spot-icon-detail-section"><span>Class applied to tag</span><div class="spot-icon-code"><code id="spotIconClassCode"></code></div></div>
        <div class="spot-icon-detail-actions"><button type="button" class="secondary" id="spotIconCancelBtn">Cancel</button><button type="button" id="spotIconApplyBtn" disabled><i class="fa-solid fa-check"></i> Apply Icon</button></div>
      </aside>
    </div>
  </div>
</dialog>
<script defer src="<?=e(base_url('assets/js/spot-icon-picker.js'))?>?v=<?=@filemtime(dirname(__DIR__).'/assets/js/spot-icon-picker.js')?:1?>"></script>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
