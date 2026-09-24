<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';

$u=require_role(['owner','admin']);
$org=(int)$u['organization_id'];
$msg='';
$error='';
$palette=['#004977','#D03027','#27814e','#f7f3eb','#e3e8f0'];
$layouts=['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'];
$editingId=(int)($_GET['game_id']??$_POST['game_id']??0);

function normalize_action_code(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','_',$value)??'';
    return trim($value,'_');
}

function neptune_game_slug(string $value): string {
    $slug=strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-',$value),'-'));
    return $slug!==''?$slug:'game';
}

function neptune_unique_game_slug(PDO $pdo,int $org,int $year,string $base,int $excludeId=0): string {
    $base=neptune_game_slug($base);
    $candidate=$base;
    $i=2;
    while(true){
        $s=$pdo->prepare('SELECT id FROM games WHERE organization_id=? AND season_year=? AND slug=? AND id<>? LIMIT 1');
        $s->execute([$org,$year,$candidate,$excludeId]);
        if(!$s->fetchColumn()) return $candidate;
        $candidate=$base.'-'.$i++;
    }
}

function neptune_accessible_game(PDO $pdo,int $org,int $gameId): ?array {
    if($gameId<=0) return null;
    $s=$pdo->prepare(
        "SELECT g.*,o.name owner_org_name,
                CASE WHEN g.organization_id=? THEN 1 ELSE 0 END is_owned
         FROM games g
         JOIN organizations o ON o.id=g.organization_id
         WHERE g.id=?
           AND (
             g.organization_id=?
             OR EXISTS(
               SELECT 1 FROM game_config_shares gcs
               WHERE gcs.game_id=g.id AND gcs.recipient_organization_id=?
             )
           )
         LIMIT 1"
    );
    $s->execute([$org,$gameId,$org,$org]);
    return $s->fetch()?:null;
}

function neptune_accessible_games(PDO $pdo,int $org): array {
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
    return $s->fetchAll();
}

function neptune_owned_game(PDO $pdo,int $org,int $gameId): ?array {
    $s=$pdo->prepare('SELECT * FROM games WHERE id=? AND organization_id=? LIMIT 1');
    $s->execute([$gameId,$org]);
    return $s->fetch()?:null;
}

function neptune_visible_revision(PDO $pdo,array $game,int $viewerOrg): ?array {
    if((int)$game['organization_id']===$viewerOrg){
        $draft=neptune_draft_game_revision($pdo,(int)$game['id']);
        if($draft) return $draft;
    }
    return neptune_current_game_revision($pdo,(int)$game['id']);
}

function neptune_save_revision_field_image(PDO $pdo,int $gameId,int $revisionId,array $file): array {
    if($gameId<=0||$revisionId<=0)throw new RuntimeException('Choose a saved game first.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Choose a field image first.');

    $publicRoot=neptune_public_root();
    $relDir='uploads/fields/game-'.$gameId.'/draft-'.$revisionId;
    $root=$publicRoot.'/'.$relDir;
    $saved=neptune_store_uploaded_image($file,$root,'field',2600,20*1024*1024);
    $tempName=(string)($saved['filename']??'');
    if($tempName==='')throw new RuntimeException('The field image could not be saved.');

    $tempPath=$root.'/'.$tempName;
    $ext=strtolower(pathinfo($tempName,PATHINFO_EXTENSION));
    if(!in_array($ext,['avif','webp','jpg','jpeg','png'],true))throw new RuntimeException('Unsupported saved field image format.');

    foreach(['avif','webp','jpg','jpeg','png'] as $oldExt){
        $old=$root.'/background.'.$oldExt;
        if(is_file($old)&&$old!==$tempPath)@unlink($old);
    }

    $finalName='background.'.$ext;
    $finalPath=$root.'/'.$finalName;
    if($tempPath!==$finalPath&&!@rename($tempPath,$finalPath))throw new RuntimeException('Could not finalize the field background image.');

    $rel=$relDir.'/'.$finalName;
    $revision=neptune_revision_by_id($pdo,$revisionId);
    if(!$revision)throw new RuntimeException('Draft revision not found.');
    $checksum=neptune_revision_checksum(
        (string)$revision['match_config_json'],
        $revision['pit_config_json'],
        $revision['pre_scout_config_json'],
        $rel
    );
    $pdo->prepare('UPDATE game_revisions SET field_image_path=?,checksum=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND game_id=? AND status=\'draft\'')
        ->execute([$rel,$checksum,$revisionId,$gameId]);

    return ['path'=>$rel,'saved'=>$saved];
}

function neptune_clone_published_field(string $sourceRel,int $destGameId,int $revisionNumber): string {
    $sourceRel=ltrim(str_replace('\\','/',trim($sourceRel)),'/');
    if($sourceRel===''||str_contains($sourceRel,'../'))return '';
    $publicRoot=neptune_public_root();
    $source=$publicRoot.'/'.$sourceRel;
    if(!is_file($source))return '';
    $ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));
    if(!in_array($ext,['avif','webp','jpg','jpeg','png'],true))return '';
    $relDir='uploads/fields/game-'.$destGameId.'/revision-'.$revisionNumber;
    $destDir=$publicRoot.'/'.$relDir;
    if(!is_dir($destDir)&&!mkdir($destDir,0775,true)&&!is_dir($destDir))throw new RuntimeException('Could not create the cloned field-image folder.');
    $dest=$destDir.'/background.'.$ext;
    if(!copy($source,$dest))throw new RuntimeException('Could not copy the field background into the cloned game revision.');
    @chmod($dest,0664);
    return $relDir.'/background.'.$ext;
}

function neptune_delete_draft_field(array $draft): void {
    $rel=ltrim(str_replace('\\','/',trim((string)($draft['field_image_path']??''))),'/');
    if($rel===''||str_contains($rel,'../')||!preg_match('~^uploads/fields/game-\\d+/draft-\\d+/background\\.(avif|webp|jpe?g|png)$~i',$rel))return;
    $abs=neptune_public_root().'/'.$rel;
    if(is_file($abs))@unlink($abs);
    $dir=dirname($abs);
    if(is_dir($dir))@rmdir($dir);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'save_game');

    try{
        if($action==='clone_game'){
            $sourceId=(int)($_POST['source_game_id']??0);
            $source=neptune_accessible_game($pdo,$org,$sourceId);
            if(!$source)throw new RuntimeException('That shared game is not available to your organization.');
            $sourceRevision=neptune_current_game_revision($pdo,$sourceId);
            if(!$sourceRevision)throw new RuntimeException('That game does not have a published revision to clone.');

            $cloneName=(string)$source['name'];
            $year=(int)$source['season_year'];
            $baseSlug=neptune_game_slug((string)$source['slug']);
            $slug=neptune_unique_game_slug($pdo,$org,$year,$baseSlug);
            if($slug!==$baseSlug){
                $cloneName.=' Copy';
                $slug=neptune_unique_game_slug($pdo,$org,$year,neptune_game_slug($cloneName));
            }

            $cfg=json_decode((string)$sourceRevision['match_config_json'],true);
            if(is_array($cfg))$cfg['game']=$cloneName;
            $matchJson=is_array($cfg)
                ? json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
                : (string)$sourceRevision['match_config_json'];
            if($matchJson===false||trim((string)$matchJson)==='')throw new RuntimeException('Could not prepare the cloned match configuration.');

            $pdo->beginTransaction();
            try{
                $s=$pdo->prepare(
                    'INSERT INTO games(organization_id,name,season_year,slug,json_filename,config_json,pit_config_json,pre_scout_config_json,created_by,is_archived)
                     VALUES(?,?,?,?,?,?,?,?,?,0)'
                );
                $s->execute([$org,$cloneName,$year,$slug,'',(string)$matchJson,$sourceRevision['pit_config_json'],$sourceRevision['pre_scout_config_json'],$u['id']]);
                $newId=(int)$pdo->lastInsertId();

                $s=$pdo->prepare(
                    "INSERT INTO game_revisions
                     (organization_id,game_id,revision_number,status,match_config_json,pit_config_json,pre_scout_config_json,field_image_path,checksum,created_by,published_by,published_at)
                     VALUES(?,?,1,'published',?,?,?,?,?,?,?,UTC_TIMESTAMP())"
                );
                $initialChecksum=neptune_revision_checksum((string)$matchJson,$sourceRevision['pit_config_json'],$sourceRevision['pre_scout_config_json'],null);
                $s->execute([$org,$newId,(string)$matchJson,$sourceRevision['pit_config_json'],$sourceRevision['pre_scout_config_json'],null,$initialChecksum,$u['id'],$u['id']]);
                $newRevisionId=(int)$pdo->lastInsertId();

                $sourceField=neptune_revision_field_path($sourceRevision,$sourceId,(int)$source['organization_id']);
                $clonedField=$sourceField!==''?neptune_clone_published_field($sourceField,$newId,1):'';
                if($clonedField!==''){
                    $checksum=neptune_revision_checksum((string)$matchJson,$sourceRevision['pit_config_json'],$sourceRevision['pre_scout_config_json'],$clonedField);
                    $pdo->prepare('UPDATE game_revisions SET field_image_path=?,checksum=? WHERE id=?')->execute([$clonedField,$checksum,$newRevisionId]);
                }

                $pdo->prepare('UPDATE games SET current_revision_id=? WHERE id=? AND organization_id=?')->execute([$newRevisionId,$newId,$org]);
                $pdo->commit();
                $editingId=$newId;
                $msg='Published configuration cloned to your organization as Revision 1. Your copy is independent.';
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
        }elseif($action==='upload_field_background'){
            $fieldGameId=(int)($_POST['field_game_id']??0);
            $fieldGame=neptune_owned_game($pdo,$org,$fieldGameId);
            if(!$fieldGame)throw new RuntimeException('Only the organization that owns this game can replace its field background.');
            $draft=neptune_ensure_game_draft_revision($pdo,$fieldGameId,$org,(int)$u['id']);
            $result=neptune_save_revision_field_image($pdo,$fieldGameId,(int)$draft['id'],$_FILES['field_background']??[]);
            $saved=$result['saved'];
            $msg='Draft Revision '.(int)$draft['revision_number'].' field background updated. Publish the draft to use it for future events. '.($saved['width']??0).'×'.($saved['height']??0).' '.strtoupper((string)($saved['format']??'image')).'.';
        }elseif($action==='publish_game'){
            $gameId=(int)($_POST['game_id']??0);
            $published=neptune_publish_game_draft($pdo,$gameId,$org,(int)$u['id']);
            $editingId=$gameId;
            $msg='Revision '.(int)$published['revision_number'].' published. New events will use this revision; existing events remain pinned to the revision they already use.';
        }elseif($action==='discard_draft'){
            $gameId=(int)($_POST['game_id']??0);
            $owned=neptune_owned_game($pdo,$org,$gameId);
            if(!$owned)throw new RuntimeException('Game not found.');
            $draft=neptune_draft_game_revision($pdo,$gameId);
            if(!$draft)throw new RuntimeException('There is no draft revision to discard.');
            neptune_delete_draft_field($draft);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE games SET draft_revision_id=NULL WHERE id=? AND organization_id=? AND draft_revision_id=?')->execute([$gameId,$org,(int)$draft['id']]);
            $pdo->prepare("DELETE FROM game_revisions WHERE id=? AND game_id=? AND organization_id=? AND status='draft'")->execute([(int)$draft['id'],$gameId,$org]);
            $pdo->commit();
            $editingId=$gameId;
            $msg='Draft discarded. The published revision was not changed.';
        }elseif($action==='save_game'){
            $name=trim((string)($_POST['name']??''));
            $year=(int)($_POST['year']??date('Y'));
            $rawButtons=json_decode($_POST['buttons_json']??'[]',true);
            $auton=max(1,(int)($_POST['auton_seconds']??15));
            $teleop=max(1,(int)($_POST['teleop_seconds']??135));
            $transition=max(0,(int)($_POST['transition_pause_seconds']??10));
            $endgame=max(0,min($teleop,(int)($_POST['endgame_seconds']??15)));

            $ownedExisting=null;
            if($editingId>0){
                $ownedExisting=neptune_owned_game($pdo,$org,$editingId);
                if(!$ownedExisting)throw new RuntimeException('Shared game configurations are read-only. Clone the game to your organization before editing it.');
            }
            if($name===''||!is_array($rawButtons))throw new RuntimeException('Enter a game name and at least one valid action.');

            $buttons=[];$seen=[];
            foreach($rawButtons as $b){
                if(!is_array($b))continue;
                $actionName=trim((string)($b['name']??''));
                if($actionName==='')continue;
                $base=normalize_action_code((string)($b['code']??$actionName));
                if($base==='')$base='action';
                $code=$base;
                if(isset($seen[$code])){
                    $i=1;while(isset($seen[$base.'_b'.$i]))$i++;$code=$base.'_b'.$i;
                }
                $seen[$code]=true;
                $layout=(string)($b['layout']??'rect-1x1');
                $layout=['rect-1'=>'rect-1x1','rect-2'=>'rect-2x1','circle-1'=>'circle-1x1','circle-2'=>'circle-2x2'][$layout]??$layout;
                if(!in_array($layout,$layouts,true))$layout='rect-1x1';
                $color=(string)($b['bgColor']??'#004977');
                if(!in_array(strtolower($color),array_map('strtolower',$palette),true))$color='#004977';
                $type=(string)($b['type']??'offense');
                if(!in_array($type,['offense','defense','cooperative','other'],true))$type='other';
                $buttons[]=[
                    'name'=>$actionName,'code'=>$code,'type'=>$type,'location'=>trim((string)($b['location']??'')),
                    'layout'=>$layout,'autonPoints'=>(float)($b['autonPoints']??0),
                    'teleopPoints'=>(float)($b['teleopPoints']??0),'bgColor'=>$color
                ];
            }
            if(!$buttons)throw new RuntimeException('Add at least one action button.');

            $slug=neptune_unique_game_slug($pdo,$org,$year,neptune_game_slug($name),$editingId);
            $cfg=[
                'schema_version'=>2,
                'game'=>$name,
                'timing'=>[
                    'autonSeconds'=>$auton,
                    'transitionPauseSeconds'=>$transition,
                    'teleopSeconds'=>$teleop,
                    'endgameSeconds'=>$endgame
                ],
                'grid'=>['columns'=>4],
                'buttons'=>$buttons
            ];
            $json=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if($json===false)throw new RuntimeException('Could not encode the game configuration.');

            if($editingId<=0){
                $s=$pdo->prepare(
                    'INSERT INTO games(organization_id,name,season_year,slug,json_filename,config_json,pit_config_json,pre_scout_config_json,created_by,is_archived)
                     VALUES(?,?,?,?,?,?,?,?,?,0)'
                );
                $s->execute([$org,$name,$year,$slug,'',$json,null,null,$u['id']]);
                $editingId=(int)$pdo->lastInsertId();
            }else{
                $pdo->prepare('UPDATE games SET name=?,season_year=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND organization_id=?')
                    ->execute([$name,$year,$slug,$editingId,$org]);
            }

            $draft=neptune_ensure_game_draft_revision($pdo,$editingId,$org,(int)$u['id']);
            $checksum=neptune_revision_checksum($json,$draft['pit_config_json'],$draft['pre_scout_config_json'],$draft['field_image_path']);
            $pdo->prepare(
                "UPDATE game_revisions
                 SET match_config_json=?,checksum=?,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND game_id=? AND organization_id=? AND status='draft'"
            )->execute([$json,$checksum,(int)$draft['id'],$editingId,$org]);
            $msg='Draft Revision '.(int)$draft['revision_number'].' saved. Publish it when Match, Pit, Pre-Scout, and field settings are ready.';
        }else{
            throw new RuntimeException('Unknown Game Builder action.');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
}

$games=neptune_accessible_games($pdo,$org);
$editGame=null;
$editRevision=null;
$currentRevision=null;
$draftRevision=null;
$cfg=[
    'schema_version'=>2,
    'game'=>'',
    'timing'=>['autonSeconds'=>15,'transitionPauseSeconds'=>10,'teleopSeconds'=>135,'endgameSeconds'=>15],
    'buttons'=>[]
];
$canEdit=true;
if($editingId){
    $editGame=neptune_accessible_game($pdo,$org,$editingId);
    if(!$editGame){
        $error=$error?:'That game is not owned by or shared with your organization.';
        $editingId=0;
    }else{
        $canEdit=(int)$editGame['organization_id']===$org;
        $currentRevision=neptune_current_game_revision($pdo,$editingId);
        $draftRevision=$canEdit?neptune_draft_game_revision($pdo,$editingId):null;
        $editRevision=$draftRevision?:$currentRevision;
        if($editRevision){
            $parsed=json_decode((string)$editRevision['match_config_json'],true);
            if(is_array($parsed))$cfg=array_replace_recursive($cfg,$parsed);
        }
    }
}

$fieldGameId=(int)($_POST['field_game_id']??$_GET['field_game_id']??0);
$fieldGame=$fieldGameId>0?neptune_accessible_game($pdo,$org,$fieldGameId):null;
if($fieldGameId>0&&!$fieldGame){
    $fieldGameId=0;
    $error=$error?:'That field background is not available to your organization.';
}
$fieldCanEdit=$fieldGame?((int)$fieldGame['organization_id']===$org):true;
$fieldRevision=null;
if($fieldGame){
    $fieldRevision=$fieldCanEdit?(neptune_draft_game_revision($pdo,$fieldGameId)?:neptune_current_game_revision($pdo,$fieldGameId)):neptune_current_game_revision($pdo,$fieldGameId);
}
$fieldPath=$fieldRevision?neptune_revision_field_path($fieldRevision,$fieldGameId,(int)$fieldGame['organization_id']):'';
$fieldUrl=$fieldPath!==''?'/'.ltrim($fieldPath,'/'):'';
$imageCaps=neptune_image_capabilities();
$fieldUploadLimit=$imageCaps['effective_upload_max_bytes']>0?neptune_format_bytes((int)$imageCaps['effective_upload_max_bytes']):'server limit';
$t=game_timing($cfg);

$pageTitle='Game Builder';
$moduleName='VULCAN';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><div class="module-eyebrow"><span>VULCAN</span><small>Game Configuration</small></div><h1 style="margin-bottom:4px">Game Builder</h1><div class="muted">Database-backed, revisioned Match / Pit / Pre-Scout configuration. Published revisions are immutable.</div></div><a class="btn secondary" href="game-builder.php"><i class="fa-solid fa-plus"></i> New Game</a></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if($editGame && !$canEdit):?>
  <div class="notice">
    <div class="toolbar" style="justify-content:space-between;margin:0">
      <span><i class="fa-solid fa-lock"></i> <b>Read-only shared configuration.</b> Owned by <?=e($editGame['owner_org_name'])?>. Clone it before making changes.</span>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="clone_game">
        <input type="hidden" name="source_game_id" value="<?=$editingId?>">
        <button type="submit"><i class="fa-solid fa-copy"></i> Clone to My Organization</button>
      </form>
    </div>
  </div>
<?php endif;?>

<div class="card" style="margin-bottom:16px">
  <form method="get" class="toolbar" style="margin:0">
    <div style="min-width:320px;flex:1"><label style="margin-top:0">Load an existing game to edit</label><select name="game_id" onchange="this.form.submit()"><option value="">Select a game…</option><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$editingId===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' · '.$g['name'].((int)$g['organization_id']===$org?(!empty($g['draft_revision_number'])?' · Draft r'.$g['draft_revision_number']:' · Published r'.($g['current_revision_number']??'—')):' · Shared by '.$g['owner_org_name'].' · r'.($g['current_revision_number']??'—')))?></option><?php endforeach;?></select></div>
  </form>
</div>

<?php if($editGame):?>
<div class="card" style="margin-bottom:16px">
  <div class="toolbar" style="justify-content:space-between;margin:0;align-items:flex-start">
    <div>
      <div class="module-eyebrow"><span>REVISION</span><small><?=e($canEdit?'Organization-owned':'Shared read-only')?></small></div>
      <h2 style="margin:4px 0 6px"><?=e($editGame['season_year'].' · '.$editGame['name'])?></h2>
      <div class="toolbar" style="margin:0">
        <?php if($currentRevision):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Published Rev <?=e($currentRevision['revision_number'])?></span><?php else:?><span class="pill">Not published</span><?php endif;?>
        <?php if($draftRevision):?><span class="pill"><i class="fa-solid fa-pen"></i> Draft Rev <?=e($draftRevision['revision_number'])?></span><?php endif;?>
      </div>
      <div class="muted" style="margin-top:7px">Existing events stay pinned to the revision they were created with. Publishing only changes the default for future events.</div>
    </div>
    <?php if($canEdit && $draftRevision):?>
      <div class="toolbar" style="margin:0">
        <form method="post" style="margin:0" data-confirm="Discard this draft? The current published revision will not be changed." data-confirm-title="Discard draft" data-confirm-button="Discard" data-confirm-danger="1">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="discard_draft"><input type="hidden" name="game_id" value="<?=$editingId?>">
          <button type="submit" class="secondary"><i class="fa-solid fa-rotate-left"></i> Discard Draft</button>
        </form>
        <form method="post" style="margin:0" data-confirm="Publish this revision? Existing events will stay on their current revision; new events will use this one." data-confirm-title="Publish game revision" data-confirm-button="Publish">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="publish_game"><input type="hidden" name="game_id" value="<?=$editingId?>">
          <button type="submit" class="good"><i class="fa-solid fa-cloud-arrow-up"></i> Publish Revision <?=e($draftRevision['revision_number'])?></button>
        </form>
      </div>
    <?php endif;?>
  </div>
</div>
<?php endif;?>

<form method="post" id="gameForm">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_game"><input type="hidden" name="game_id" value="<?=$editingId?>"><input type="hidden" name="buttons_json" id="buttons_json">
<fieldset class="builder-readonly-fieldset" <?=$canEdit?'':'disabled'?>>
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
      <div class="toolbar"><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button></div>
    </div>
  </div>

  <aside class="card preview-sticky">
    <h2>Scout Grid Preview</h2><p class="muted">Buttons pack automatically into a four-column grid in this order.</p>
    <div id="preview" class="button-preview-grid"></div>
    <div class="muted" style="margin-top:10px">Palette: blue, red, green, neutral, selected.</div>
  </aside>
</div>
</fieldset>
</form>

<style>
.builder-readonly-fieldset{border:0;padding:0;margin:0;min-width:0}
.builder-readonly-fieldset:disabled{opacity:.72}
.field-bg-card{margin-top:16px;overflow:hidden}
.field-bg-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}
.field-bg-header h2{margin:3px 0 4px}
.field-bg-layout{display:grid;grid-template-columns:minmax(290px,380px) minmax(0,1fr);gap:18px;align-items:start}
.field-bg-controls,.field-bg-preview-panel{border:1px solid var(--line);border-radius:8px;background:var(--panel2);padding:16px}
.field-bg-controls h3,.field-bg-preview-panel h3{margin:0 0 6px;font-size:1rem}
.field-bg-controls form+form{margin-top:16px;padding-top:16px;border-top:1px solid var(--line)}
.field-bg-file{margin-top:12px}
.field-bg-file input[type=file]{width:100%}
.field-bg-actions{margin:14px 0 0}
.field-bg-actions button{width:100%;justify-content:center}
.field-bg-preview-frame{display:flex;align-items:center;justify-content:center;min-height:230px;max-height:440px;border:1px solid var(--line);border-radius:7px;background:var(--panel);overflow:hidden;padding:10px}
.field-bg-preview-frame img{display:block;width:100%;height:auto;max-height:418px;object-fit:contain}
.field-bg-empty{display:flex;min-height:230px;align-items:center;justify-content:center;text-align:center;color:var(--muted);padding:28px}
.field-bg-empty i{display:block;font-size:2rem;margin-bottom:10px}
.field-bg-status{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
@media(max-width:900px){.field-bg-layout{grid-template-columns:1fr}.field-bg-preview-frame{min-height:180px}.field-bg-header{display:block}.field-bg-header .pill{margin-top:10px}}
</style>

<div class="card field-bg-card" id="field-background">
  <div class="field-bg-header">
    <div>
      <div class="module-eyebrow"><span>VULCAN</span><small>Autonomous Canvas</small></div>
      <h2><i class="fa-solid fa-map"></i> Field Background</h2>
      <div class="muted">Field artwork is part of the game draft. Publishing snapshots the image into that immutable revision so existing events never change underneath scouts.</div>
    </div>
    <?php if($fieldGameId>0 && $fieldUrl):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Background set</span><?php endif;?>
  </div>

  <?php if(!$games):?>
    <div class="notice">Save a game first, then return here to add its field background.</div>
  <?php else:?>
    <div class="field-bg-layout">
      <section class="field-bg-controls">
        <h3>1. Choose game</h3>
        <div class="muted">Nothing is selected by default, so Neptune does not load a field image until you ask for one.</div>

        <form method="get" id="fieldGamePicker">
          <?php if($editingId>0):?><input type="hidden" name="game_id" value="<?=$editingId?>"><?php endif;?>
          <label for="field_game_id">Game</label>
          <select name="field_game_id" id="field_game_id" onchange="this.form.submit()">
            <option value="" <?=$fieldGameId===0?'selected':''?>>Select a game…</option>
            <?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$fieldGameId===(int)$g['id']?'selected':''?>><?=e($g['season_year'].' · '.$g['name'].((int)$g['organization_id']===$org?(!empty($g['draft_revision_number'])?' · Draft r'.$g['draft_revision_number']:' · Published r'.($g['current_revision_number']??'—')):' · Shared by '.$g['owner_org_name'].' · r'.($g['current_revision_number']??'—')))?></option><?php endforeach;?>
          </select>
        </form>

        <?php if($fieldGameId>0 && $fieldCanEdit):?>
          <form method="post" enctype="multipart/form-data" id="fieldBackgroundForm">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="upload_field_background">
            <input type="hidden" name="field_game_id" value="<?=$fieldGameId?>">
            <input type="hidden" name="game_id" value="<?=$editingId?>">

            <h3>2. Upload field image</h3>
            <div class="muted">Top-down field artwork works best. Uploading creates or updates the game draft; publish the draft when it is ready.</div>
            <div class="field-bg-file">
              <label for="field_background">Image file</label>
              <input id="field_background" type="file" name="field_background" accept="image/*" required>
              <div class="muted" style="margin-top:6px">Maximum upload: <?=e($fieldUploadLimit)?>.</div>
            </div>
            <div class="field-bg-actions">
              <button type="submit" class="secondary"><i class="fa-solid fa-upload"></i> <?=$fieldUrl?'Replace Field Background':'Upload Field Background'?></button>
            </div>
          </form>
        <?php elseif($fieldGameId>0):?>
          <div class="notice" style="margin-top:16px"><i class="fa-solid fa-lock"></i> This field image belongs to <?=e($fieldGame['owner_org_name'])?> and is shared read-only. Clone the game to replace it.</div>
        <?php else:?>
          <div class="notice" style="margin-top:16px"><i class="fa-solid fa-arrow-up"></i> Select a game above to manage its field background.</div>
        <?php endif;?>
      </section>

      <section class="field-bg-preview-panel">
        <h3>Preview</h3>
        <?php if($fieldGameId<=0):?>
          <div class="field-bg-empty"><div><i class="fa-regular fa-image"></i>Select a game to load its current field background.</div></div>
        <?php elseif($fieldUrl):?>
          <div class="field-bg-preview-frame"><img src="<?=e($fieldUrl)?>" alt="Current autonomous field background"></div>
          <div class="field-bg-status muted"><i class="fa-solid fa-circle-check"></i> <?=e(($fieldRevision['status']??'published')==='draft'?'Draft image — publish to make it active for future events.':'Published image for this revision.')?></div>
        <?php else:?>
          <div class="field-bg-empty"><div><i class="fa-regular fa-image"></i>No field background has been uploaded for this game yet.</div></div>
        <?php endif;?>
      </section>
    </div>
  <?php endif;?>
</div>

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
