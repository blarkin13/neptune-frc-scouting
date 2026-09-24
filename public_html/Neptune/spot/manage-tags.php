<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$ready=spot_tables_ready($pdo);
$flash=null;
$editId=(int)($_GET['edit']??0);
$edit=null;

if($ready && $_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='toggle'){
            $id=(int)($_POST['id']??0);
            $s=$pdo->prepare('SELECT id,organization_id,active FROM spot_tags WHERE id=? AND (organization_id IS NULL OR organization_id=?) LIMIT 1');
            $s->execute([$id,$org]);$tag=$s->fetch();
            if(!$tag)throw new RuntimeException('Tag not found.');
            if($tag['organization_id']===null){
                $s=$pdo->prepare('SELECT active FROM spot_tag_visibility WHERE organization_id=? AND tag_id=? LIMIT 1');$s->execute([$org,$id]);
                $current=$s->fetchColumn();$effective=$current===false?(int)$tag['active']:(int)$current;$next=$effective?0:1;
                $s=$pdo->prepare('INSERT INTO spot_tag_visibility (organization_id,tag_id,active) VALUES(?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),updated_at=CURRENT_TIMESTAMP');
                $s->execute([$org,$id,$next]);
            }else{
                $s=$pdo->prepare('UPDATE spot_tags SET active=IF(active=1,0,1) WHERE id=? AND organization_id=?');$s->execute([$id,$org]);
            }
            $flash=['good','Tag availability updated.'];
        }else{
            $id=(int)($_POST['id']??0);
            $label=mb_substr(trim((string)($_POST['label']??'')),0,120);
            if($label==='')throw new RuntimeException('Tag name is required.');
            $slug=spot_slug($label);
            if($slug==='')throw new RuntimeException('Tag name must contain letters or numbers.');
            $category=mb_substr(trim((string)($_POST['category']??'General')),0,80)?:'General';
            $icon=mb_substr(trim((string)($_POST['icon']??'fa-solid fa-tag')),0,120)?:'fa-solid fa-tag';
            if(!preg_match('/^[a-z0-9 _-]+$/i',$icon))$icon='fa-solid fa-tag';
            $severity=(string)($_POST['severity']??'info');
            if(!in_array($severity,['positive','info','warning','critical'],true))$severity='info';
            $match=!empty($_POST['match_enabled'])?1:0;
            $pit=!empty($_POST['pit_enabled'])?1:0;
            $general=!empty($_POST['general_enabled'])?1:0;
            if(!$match&&!$pit&&!$general)throw new RuntimeException('Enable the tag for at least one observation type.');
            $sort=max(0,min(10000,(int)($_POST['sort_order']??100)));

            $s=$pdo->prepare('SELECT id,label FROM spot_tags WHERE organization_id=? AND slug=? AND id<>? LIMIT 1');
            $s->execute([$org,$slug,$id]);
            if($dupe=$s->fetch())throw new RuntimeException('A team tag named “'.$dupe['label'].'” already exists.');

            if($id>0){
                $s=$pdo->prepare('UPDATE spot_tags SET label=?,slug=?,category=?,icon=?,severity=?,match_enabled=?,pit_enabled=?,general_enabled=?,sort_order=? WHERE id=? AND organization_id=?');
                $s->execute([$label,$slug,$category,$icon,$severity,$match,$pit,$general,$sort,$id,$org]);
                if(!$s->rowCount()){
                    $s=$pdo->prepare('SELECT id FROM spot_tags WHERE id=? AND organization_id=?');$s->execute([$id,$org]);
                    if(!(int)$s->fetchColumn())throw new RuntimeException('Tag not found. Global default tags are read-only.');
                }
                $flash=['good','Tag updated.'];
            }else{
                $s=$pdo->prepare("INSERT INTO spot_tags (organization_id,label,slug,category,icon,severity,match_enabled,pit_enabled,general_enabled,active,sort_order,created_by) VALUES(?,?,?,?,?,?,?,?,?,1,?,?)");
                $s->execute([$org,$label,$slug,$category,$icon,$severity,$match,$pit,$general,$sort,$userId]);
                $flash=['good','Tag created for your organization.'];
            }
            $editId=0;
        }
    }catch(Throwable $e){$flash=['bad',$e->getMessage()];}
}

$tags=$ready?spot_visible_tags($pdo,$org,true):[];
if($ready&&$editId>0){
    $s=$pdo->prepare('SELECT * FROM spot_tags WHERE id=? AND organization_id=? LIMIT 1');$s->execute([$editId,$org]);$edit=$s->fetch()?:null;
}
$categories=array_values(array_unique(array_filter(array_map(static fn($t)=>(string)$t['category'],$tags))));sort($categories);
$pageTitle='Spot Scouting Tags';$moduleName='TRIDENT';
$pageStyles=['assets/vendor/fontawesome/css/all.min.css','assets/css/spot-icon-picker.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<style>
.spot-tag-admin{display:grid;grid-template-columns:minmax(300px,.75fr) minmax(0,1.25fr);gap:16px;align-items:start}.spot-admin-card{padding:16px}.spot-contexts{display:flex;gap:12px;flex-wrap:wrap}.spot-tag-table{width:100%;border-collapse:collapse}.spot-tag-table th,.spot-tag-table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.spot-tag-table th{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.spot-tag-preview{display:inline-flex;align-items:center;gap:7px;font-weight:850}.spot-scope{font-size:.64rem}.spot-tag-actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center;white-space:nowrap}.spot-tag-actions form{margin:0;display:inline-flex}.spot-tag-actions .btn,.spot-tag-actions button{white-space:nowrap;padding:8px 11px;min-height:38px;font-size:.72rem}.spot-muted-row{opacity:.58}.spot-icon-examples{font-size:.7rem;color:var(--muted);line-height:1.5}.spot-context-pills{display:flex;gap:4px;flex-wrap:wrap}.spot-context-pills span{font-size:.62rem;border:1px solid var(--line);border-radius:999px;padding:3px 5px}@media(max-width:900px){.spot-tag-admin{grid-template-columns:1fr}.spot-tag-table thead{display:none}.spot-tag-table,.spot-tag-table tbody,.spot-tag-table tr,.spot-tag-table td{display:block;width:100%}.spot-tag-table tr{padding:10px 0;border-bottom:1px solid var(--line)}.spot-tag-table td{border:0;padding:4px 0}}
</style>
<section class="module-page">
<header class="module-page-header" style="margin-bottom:16px"><div><div class="module-code">TRIDENT</div><h1>Spot Scouting Tags</h1><p>Create organization-specific tags without changing Neptune code. Neptune defaults can be hidden for your organization, while their definitions remain read-only.</p></div><a class="btn secondary" href="<?=e(base_url('spot/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Spot Scouting</a></header>
<?php if(!$ready):?><div class="notice bad">Import <code>sql/2026-09-22_spot-scouting-v1.sql</code> first.</div><?php else:?>
<?php if($flash):?><div class="notice <?=$flash[0]==='bad'?'bad':'good'?>"><?=e($flash[1])?></div><?php endif;?>
<div class="spot-tag-admin">
<section class="card spot-admin-card">
  <h2 style="margin-top:0"><?=$edit?'Edit Tag':'Add Tag'?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
    <label>Tag name</label><input name="label" maxlength="120" required value="<?=e($edit['label']??'')?>" placeholder="Great Defense">
    <label>Category</label><input name="category" list="spotCategories" maxlength="80" value="<?=e($edit['category']??'General')?>"><datalist id="spotCategories"><?php foreach($categories as $c):?><option value="<?=e($c)?>"><?php endforeach;?></datalist>
    <label for="spotIconClass">Font Awesome icon class</label>
    <div class="spot-icon-field">
      <span class="spot-icon-current-preview" aria-hidden="true"><i id="spotIconCurrentPreview" class="<?=e($edit['icon']??'fa-solid fa-tag')?>"></i></span>
      <input id="spotIconClass" name="icon" maxlength="120" value="<?=e($edit['icon']??'fa-solid fa-tag')?>">
      <button type="button" class="secondary spot-icon-choose-btn" id="spotChooseIconBtn"><i class="fa-solid fa-icons"></i> Choose Icon</button>
    </div>
    <div class="spot-icon-examples">Choose from Neptune's locally hosted Font Awesome Free library, or enter a class manually. Examples: <code>fa-solid fa-shield-halved</code>, <code>fa-solid fa-bolt</code>, <code>fa-solid fa-screwdriver-wrench</code></div>
    <label>Signal</label><select name="severity"><?php foreach(['positive'=>'Positive','info'=>'Information','warning'=>'Warning','critical'=>'Critical'] as $v=>$l):?><option value="<?=$v?>" <?=($edit['severity']??'info')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select>
    <label>Available in</label><div class="spot-contexts"><label><input type="checkbox" name="match_enabled" value="1" <?=!$edit||!empty($edit['match_enabled'])?'checked':''?>> Match</label><label><input type="checkbox" name="pit_enabled" value="1" <?=!$edit||!empty($edit['pit_enabled'])?'checked':''?>> Pit</label><label><input type="checkbox" name="general_enabled" value="1" <?=!$edit||!empty($edit['general_enabled'])?'checked':''?>> Team Only</label></div>
    <label>Sort order</label><input type="number" min="0" max="10000" name="sort_order" value="<?=e($edit['sort_order']??100)?>">
    <div class="toolbar" style="margin-top:14px"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> <?=$edit?'Save Changes':'Create Tag'?></button><?php if($edit):?><a class="btn secondary" href="<?=e(base_url('spot/manage-tags.php'))?>">Cancel</a><?php endif;?></div>
  </form>
</section>
<section class="card spot-admin-card">
  <h2 style="margin-top:0">Tag Library</h2>
  <div class="table-wrap"><table class="spot-tag-table"><thead><tr><th>Tag</th><th>Category</th><th>Signal</th><th>Contexts</th><th>Scope</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($tags as $tag):?>
    <tr class="<?=empty($tag['active'])?'spot-muted-row':''?>"><td><span class="spot-tag-preview"><i class="<?=e($tag['icon'])?>"></i><?=e($tag['label'])?></span></td><td><?=e($tag['category'])?></td><td><?=e(ucfirst($tag['severity']))?></td><td><div class="spot-context-pills"><?php if($tag['match_enabled']):?><span>Match</span><?php endif;?><?php if($tag['pit_enabled']):?><span>Pit</span><?php endif;?><?php if($tag['general_enabled']):?><span>Team Only</span><?php endif;?></div></td><td><span class="pill spot-scope"><?=e($tag['scope']==='global'?'Neptune default':'Your organization')?></span></td><td><div class="spot-tag-actions"><?php if($tag['scope']==='organization'):?><a class="btn secondary" href="<?=e(base_url('spot/manage-tags.php?edit='.(int)$tag['id']))?>"><i class="fa-solid fa-pen"></i> Edit</a><?php endif;?><form method="post" data-confirm="<?=e(($tag['active']?'Disable':'Enable').' this Spot Scouting tag for your organization?')?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=e($tag['id'])?>"><button class="secondary" type="submit"><i class="fa-solid <?=empty($tag['active'])?'fa-eye':'fa-eye-slash'?>"></i> <?=empty($tag['active'])?'Enable':'Disable'?></button></form></div></td></tr>
  <?php endforeach;?></tbody></table></div>
</section>
</div>
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
