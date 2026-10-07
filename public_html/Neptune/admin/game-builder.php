<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_event_selection.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';

$u=require_role(['owner','admin']);
$org=(int)$u['organization_id'];
$msg='';
$error='';
$actionPalette=[
    // Dedicated theme-aware scouting colors from Interface Styling > Game Builder · Action Palette.
    ['role'=>'game_primary','cssVar'=>'--game-primary','fallbackVar'=>'--c1-blue','label'=>'Primary','hint'=>'Primary scoring'],
    ['role'=>'game_secondary','cssVar'=>'--game-secondary','fallbackVar'=>'--accent','label'=>'Secondary','hint'=>'Secondary scoring'],
    ['role'=>'game_intake','cssVar'=>'--game-intake','fallbackVar'=>'--module-org','label'=>'Intake','hint'=>'Intake / collection'],
    ['role'=>'game_defense','cssVar'=>'--game-defense','fallbackVar'=>'--bad','label'=>'Defense','hint'=>'Defense'],
    ['role'=>'game_coop','cssVar'=>'--game-coop','fallbackVar'=>'--good','label'=>'Co-op','hint'=>'Cooperative action'],
    ['role'=>'game_endgame','cssVar'=>'--game-endgame','fallbackVar'=>'--module-augur','label'=>'Endgame','hint'=>'Endgame'],
    ['role'=>'game_special','cssVar'=>'--game-special','fallbackVar'=>'--module-saturn','label'=>'Special','hint'=>'Special / bonus'],
    ['role'=>'game_utility','cssVar'=>'--game-utility','fallbackVar'=>'--module-system','label'=>'Utility','hint'=>'Utility / alternate'],
];
$actionColorRoles=array_column($actionPalette,'cssVar','role');
$legacyActionColorRoles=[
    'primary'=>'--c1-blue','focus'=>'--accent','competition_red'=>'--c1-red','competition_green'=>'--c1-green',
    'success'=>'--good','error'=>'--bad','warning'=>'--warn','ready'=>'--ready',
    'blue_alliance'=>'--alliance-blue','blue_alliance_deep'=>'--alliance-blue-deep',
];
$validActionColorRoles=$actionColorRoles+$legacyActionColorRoles;
$layouts=['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'];
$editingId=(int)($_GET['game_id']??$_POST['game_id']??0);

function normalize_action_code(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','_',$value)??'';
    return trim($value,'_');
}

function neptune_default_action_color_role(string $type): string {
    return match($type){
        'defense'=>'game_defense',
        'cooperative'=>'game_coop',
        'other'=>'game_secondary',
        default=>'game_primary',
    };
}

function neptune_action_color(string $value,string $fallback='#0072B2'): string {
    $value=trim($value);
    if(preg_match('/^#[0-9a-fA-F]{6}$/',$value))return strtoupper($value);
    return strtoupper($fallback);
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
    return neptune_game_selector_rows($pdo,$org,true,neptune_selector_show_history());
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
            $msg='Revision '.(int)$published['revision_number'].' published. Existing events can now be moved to this revision below.';
        }elseif($action==='deploy_revision_to_event'){
            $gameId=(int)($_POST['game_id']??0);
            $eventId=(int)($_POST['event_id']??0);
            $revisionId=(int)($_POST['revision_id']??0);
            $owned=neptune_owned_game($pdo,$org,$gameId);
            if(!$owned)throw new RuntimeException('Game not found.');
            $r=$pdo->prepare("SELECT id,revision_number FROM game_revisions WHERE id=? AND game_id=? AND status='published' LIMIT 1");
            $r->execute([$revisionId,$gameId]);
            $target=$r->fetch();
            if(!$target)throw new RuntimeException('The selected published revision could not be found.');
            $e=$pdo->prepare(
                "SELECT e.id,e.name,e.event_status,e.game_revision_id,gr.revision_number current_revision_number
"
               ."FROM events e JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
"
               ."WHERE e.id=? AND e.organization_id=? AND e.game_id=? LIMIT 1"
            );
            $e->execute([$eventId,$org,$gameId]);
            $event=$e->fetch();
            if(!$event)throw new RuntimeException('Event not found for this game.');
            $active=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE event_id=? AND organization_id=? AND state IN ('running','paused')");
            $active->execute([$eventId,$org]);
            if((int)$active->fetchColumn()>0)throw new RuntimeException('This event has a running or paused match. End the active match before changing its game revision.');
            if((int)$event['game_revision_id']===$revisionId){
                $msg=$event['name'].' is already using Revision '.(int)$target['revision_number'].'.';
            }else{
                $pdo->prepare('UPDATE events SET game_revision_id=? WHERE id=? AND organization_id=? AND game_id=?')
                    ->execute([$revisionId,$eventId,$org,$gameId]);
                $msg=$event['name'].' moved from Revision '.(int)$event['current_revision_number'].' to Revision '.(int)$target['revision_number'].'. Match, Pit, Pre-Scout, field, and action appearance now use the new revision.';
            }
            $editingId=$gameId;
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
                $type=(string)($b['type']??'offense');
                if(!in_array($type,['offense','defense','cooperative','other'],true))$type='other';

                $requestedRole=trim((string)($b['colorRole']??''));
                $legacyColor=(string)($b['bgColor']??'');
                if($requestedRole==='' && preg_match('/^#[0-9a-fA-F]{6}$/',$legacyColor)){
                    // Existing revisions used literal hex colors. Preserve them as fixed custom colors.
                    $requestedRole='custom';
                }
                if($requestedRole!=='custom' && !array_key_exists($requestedRole,$validActionColorRoles)){
                    $requestedRole=neptune_default_action_color_role($type);
                }

                $button=[
                    'name'=>$actionName,'code'=>$code,'type'=>$type,'location'=>trim((string)($b['location']??'')),
                    'layout'=>$layout,'autonPoints'=>(float)($b['autonPoints']??0),
                    'teleopPoints'=>(float)($b['teleopPoints']??0),'colorRole'=>$requestedRole
                ];
                if($requestedRole==='custom'){
                    $button['bgColor']=neptune_action_color($legacyColor);
                }
                $buttons[]=$button;
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

$eventDeployments=[];
if($editingId>0 && $canEdit && $currentRevision){
    $s=$pdo->prepare(
        "SELECT e.id,e.name,e.event_status,e.is_current,e.game_revision_id,gr.revision_number current_revision_number,
"
       ."       (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id AND m.organization_id=e.organization_id) match_count,
"
       ."       (SELECT COUNT(*) FROM scouting_actions sa WHERE sa.event_id=e.id AND sa.organization_id=e.organization_id AND sa.deleted_at IS NULL) action_count,
"
       ."       (SELECT COUNT(*) FROM matches m2 WHERE m2.event_id=e.id AND m2.organization_id=e.organization_id AND m2.state IN ('running','paused')) active_match_count
"
       ."FROM events e
"
       ."JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
"
       ."WHERE e.organization_id=? AND e.game_id=?
"
       ."ORDER BY e.is_current DESC,COALESCE(e.start_date,'9999-12-31'),e.id DESC"
    );
    $s->execute([$org,$editingId]);
    $eventDeployments=$s->fetchAll();
}

// Deployment is an exception workflow, not a status dashboard. Only keep
// events that are actually behind the currently published revision. When
// every event is current, the deployment panel disappears entirely.
$staleEventDeployments=[];
if($currentRevision && $eventDeployments){
    $latestRevisionId=(int)$currentRevision['id'];
    $staleEventDeployments=array_values(array_filter(
        $eventDeployments,
        static fn(array $ev): bool => (int)$ev['game_revision_id'] !== $latestRevisionId
    ));
}

$fieldGameId=(int)($_POST['field_game_id']??$_GET['field_game_id']??($editingId>0?$editingId:0));
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
<style>
.vgb{--vgb-accent:var(--module-vulcan,var(--accent));--vgb-accent-soft:color-mix(in srgb,var(--vgb-accent) 68%,var(--text));--vgb-glow:color-mix(in srgb,var(--vgb-accent) 42%,transparent);max-width:1600px;margin:0 auto}
.vgb *{box-sizing:border-box}.vgb-hero{position:relative;overflow:hidden;margin-bottom:16px;padding:26px;border:1px solid color-mix(in srgb,var(--vgb-accent) 42%,var(--line));border-radius:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel) 88%,var(--vgb-accent) 12%),color-mix(in srgb,var(--panel) 97%,var(--vgb-accent) 3%));box-shadow:0 18px 55px var(--shadow)}
.vgb-hero:before,.vgb-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}.vgb-hero:before{width:340px;height:340px;right:-120px;top:-200px;background:radial-gradient(circle,var(--vgb-glow),transparent 68%)}.vgb-hero:after{width:250px;height:250px;left:-135px;bottom:-175px;background:radial-gradient(circle,color-mix(in srgb,var(--vgb-accent) 24%,transparent),transparent 70%)}
.vgb-kicker{position:relative;display:flex;align-items:center;gap:8px;color:color-mix(in srgb,var(--vgb-accent) 76%,var(--muted));font-size:.74rem;font-weight:950;letter-spacing:.15em;text-transform:uppercase}.vgb-title{position:relative;margin:10px 0 10px;font-size:clamp(2.3rem,6vw,4.9rem);line-height:.9;letter-spacing:-.065em}.vgb-title span{background:linear-gradient(90deg,var(--vgb-accent-soft),var(--vgb-accent));-webkit-background-clip:text;background-clip:text;color:transparent}.vgb-sub{position:relative;max-width:920px;margin:0;color:var(--muted);font-size:1rem;line-height:1.55}
.vgb-hero-tools{position:relative;display:grid;grid-template-columns:minmax(260px,1fr) auto;gap:12px;align-items:end;margin-top:20px}.vgb-hero-tools form{margin:0}.vgb-hero-tools label{margin-top:0}.vgb-hero-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.vgb-status-row{position:relative;display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.vgb-status-row .pill{background:color-mix(in srgb,var(--panel2) 88%,transparent)}
.vgb-panel{margin-top:16px;padding:20px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 10px 30px var(--shadow)}.vgb-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.vgb-panel-head h2{margin:2px 0 5px}.vgb-panel-head p{margin:0;color:var(--muted);line-height:1.5}.vgb-section-kicker{display:flex;align-items:center;gap:7px;color:var(--vgb-accent);font-size:.7rem;font-weight:950;letter-spacing:.12em;text-transform:uppercase}
.vgb-deploy-list{display:grid;gap:9px}.vgb-deploy-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:var(--panel2)}.vgb-deploy-main{min-width:0}.vgb-deploy-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-weight:900}.vgb-deploy-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:5px;color:var(--muted);font-size:.78rem}.vgb-rev-arrow{display:inline-flex;align-items:center;gap:6px}.vgb-deploy-row.is-stale{border-color:color-mix(in srgb,var(--vgb-accent) 44%,var(--line));background:color-mix(in srgb,var(--vgb-accent) 5%,var(--panel2))}.vgb-deploy-row form{margin:0}.vgb-deploy-row button{white-space:nowrap}

.vgb-setup-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(180px,.65fr);gap:12px}.vgb-timing{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px}.vgb-time-box{padding:12px;border:1px solid var(--line);border-radius:13px;background:var(--panel2)}.vgb-time-box label{margin-top:0}.vgb-time-box .muted{margin-top:5px;font-size:.72rem}
.vgb-workspace{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(360px,.65fr);gap:16px;align-items:start}.vgb-actions{display:grid;gap:12px}.vgb-action-card{border:1px solid var(--line);border-radius:17px;background:var(--panel2);overflow:hidden;transition:border-color .15s,box-shadow .15s,transform .15s}.vgb-action-card.is-selected{border-color:var(--vgb-accent);box-shadow:0 0 0 2px var(--vgb-glow)}.vgb-action-card.is-dragging{opacity:.55}.vgb-action-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--panel) 70%,transparent)}.vgb-action-ident{display:flex;align-items:center;gap:10px;min-width:0}.vgb-drag{display:grid;place-items:center;width:34px;height:34px;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--muted);cursor:grab}.vgb-drag:active{cursor:grabbing}.vgb-action-number{display:inline-grid;place-items:center;min-width:28px;height:28px;padding:0 7px;border-radius:999px;background:color-mix(in srgb,var(--vgb-accent) 14%,var(--panel));color:var(--vgb-accent);font-size:.72rem;font-weight:950}.vgb-action-title{min-width:0;font-weight:950;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.vgb-action-tools{display:flex;gap:5px}.vgb-action-tools button{min-width:34px;padding:7px 9px}.vgb-action-body{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:14px;padding:14px}.vgb-field-wide{grid-column:1/-1}.vgb-name-input{font-size:1.05rem;font-weight:900}
.vgb-control-group{display:grid;grid-template-rows:auto 1fr;min-width:0}.vgb-control-group>label{margin-bottom:6px}.vgb-segment{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;height:100%}.vgb-segment button{display:flex;min-height:72px;flex-direction:column;align-items:center;justify-content:center;gap:7px;padding:9px 8px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--muted);font-size:.74rem;font-weight:900;text-transform:none}.vgb-segment button i{font-size:1rem}.vgb-segment button.active{border-color:var(--vgb-accent);background:color-mix(in srgb,var(--vgb-accent) 13%,var(--panel));color:var(--text);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--vgb-accent) 55%,transparent)}
.vgb-points{display:grid;grid-template-columns:1fr 1fr;gap:8px;height:100%}.vgb-points>div{display:grid;grid-template-rows:auto 36px;gap:5px;min-height:72px;padding:8px 10px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.vgb-points label{margin:0;font-size:.7rem;align-self:center}.vgb-points input{height:36px;padding:6px 10px;font-weight:900}
.vgb-shapes{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:6px}.vgb-shape{display:grid;place-items:center;min-height:50px;padding:6px;border:1px solid var(--line);border-radius:10px;background:var(--panel);color:var(--muted)}.vgb-shape.active{border-color:var(--vgb-accent);background:color-mix(in srgb,var(--vgb-accent) 11%,var(--panel));box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--vgb-accent) 50%,transparent)}.shape-mini{display:block;border:2px solid currentColor;background:color-mix(in srgb,currentColor 14%,transparent)}.shape-rect-1x1{width:15px;height:15px}.shape-rect-1x2{width:13px;height:28px}.shape-rect-2x1{width:28px;height:13px}.shape-rect-1x4{width:10px;height:34px}.shape-rect-4x1{width:34px;height:10px}.shape-circle-1x1{width:16px;height:16px;border-radius:50%}.shape-circle-2x2{width:25px;height:25px;border-radius:50%}.shape-circle-4x4{width:34px;height:34px;border-radius:50%}
.vgb-colors{display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:8px}.vgb-color{--swatch:#64748B;position:relative;display:flex;min-width:0;min-height:78px;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:6px;padding:6px;border:2px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);overflow:hidden;text-align:left}.vgb-color:hover{border-color:color-mix(in srgb,var(--swatch) 68%,var(--line));filter:none}.vgb-color-dot{display:block;flex:0 0 42px;width:100%;min-width:100%;height:42px;border:1px solid color-mix(in srgb,#000 20%,transparent);border-radius:8px;background:var(--swatch)}.vgb-color-copy{display:flex;min-width:0;align-items:baseline;justify-content:space-between;gap:5px;padding:0 2px}.vgb-color-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.68rem;font-weight:950}.vgb-color-hint{color:var(--muted);font-size:.58rem;font-weight:800;white-space:nowrap}.vgb-color.active{border-color:var(--vgb-accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--vgb-accent) 18%,transparent),inset 0 0 0 1px color-mix(in srgb,var(--vgb-accent) 34%,transparent)}.vgb-color.active:after{content:"✓";position:absolute;right:9px;top:9px;display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:var(--panel);color:var(--text);font-size:.67rem;font-weight:1000;box-shadow:0 2px 6px rgba(0,0,0,.35)}.vgb-color-caption{display:flex;justify-content:space-between;gap:8px;margin-top:7px;color:var(--muted);font-size:.7rem}.vgb-custom-color{display:flex;align-items:center;gap:10px;margin-top:9px;padding:10px 11px;border:2px solid var(--line);border-radius:11px;background:var(--panel);cursor:pointer}.vgb-custom-color.active{border-color:var(--vgb-accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--vgb-accent) 18%,transparent)}.vgb-custom-color input[type=color]{width:54px;height:38px;padding:2px;border-radius:8px;cursor:pointer}.vgb-custom-color span{font-size:.8rem;font-weight:850}.vgb-custom-color code{margin-left:auto;color:var(--muted);font-size:.72rem;font-weight:850}.vgb-advanced{grid-column:1/-1;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.vgb-advanced summary{cursor:pointer;padding:10px 12px;color:var(--muted);font-size:.78rem;font-weight:900}.vgb-advanced-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:0 12px 12px}.vgb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.86rem}
.vgb-preview{position:sticky;top:98px;padding:18px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 10px 30px var(--shadow)}.vgb-preview-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.vgb-preview h2{margin:2px 0 5px}.vgb-phase{display:flex;gap:5px;padding:4px;border:1px solid var(--line);border-radius:11px;background:var(--panel2)}.vgb-phase button{padding:7px 9px;border:0;border-radius:8px;background:transparent;color:var(--muted);font-size:.72rem;font-weight:950;text-transform:none}.vgb-phase button.active{background:var(--vgb-accent);color:var(--solid-text)}.vgb-preview-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));grid-auto-rows:var(--neptune-grid-cell,82px);grid-auto-flow:dense;gap:9px;margin-top:14px;padding:12px;border:1px dashed var(--line);border-radius:14px;background:var(--bg);min-height:225px}.vgb-preview-action{position:relative;display:flex;align-items:center;justify-content:center;min-width:0;min-height:0;padding:8px;border:2px solid color-mix(in srgb,var(--c1-border) 55%,transparent);border-radius:8px;overflow:hidden;text-align:center;font-weight:950;cursor:pointer;user-select:none}.vgb-preview-action:hover{outline:2px solid var(--vgb-accent);outline-offset:1px}.vgb-preview-action .vgb-preview-name{max-width:100%;overflow:hidden;text-overflow:ellipsis}.vgb-preview-points{position:absolute;right:5px;bottom:5px;min-width:24px;padding:3px 5px;border-radius:999px;background:rgba(0,0,0,.48);color:#fff;font-size:.62rem;font-weight:1000}.vgb-preview-note{margin-top:10px;color:var(--muted);font-size:.75rem;line-height:1.45}.vgb-preview-empty{grid-column:1/-1;display:grid;place-items:center;min-height:190px;color:var(--muted);text-align:center}
.vgb-empty-actions{padding:28px;border:1px dashed var(--line);border-radius:14px;text-align:center;color:var(--muted)}.vgb-empty-actions i{display:block;margin-bottom:8px;font-size:1.7rem}.vgb-add{display:flex;justify-content:center;margin-top:12px}.vgb-add button{min-width:170px}
.vgb-savebar[hidden]{display:none!important}.vgb-savebar{position:sticky;bottom:12px;z-index:35;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 0 0;padding:12px 14px;border:1px solid color-mix(in srgb,var(--vgb-accent) 50%,var(--line));border-radius:15px;background:color-mix(in srgb,var(--panel) 94%,transparent);box-shadow:0 16px 42px rgba(0,0,0,.28);backdrop-filter:blur(10px)}.vgb-savebar strong{display:block}.vgb-savebar small{color:var(--muted)}
.vgb-field{margin-top:16px}.vgb-field-grid{display:grid;grid-template-columns:minmax(290px,390px) minmax(0,1fr);gap:16px}.vgb-field-controls,.vgb-field-preview{padding:16px;border:1px solid var(--line);border-radius:14px;background:var(--panel2)}.vgb-field-controls h3,.vgb-field-preview h3{margin:0 0 6px}.vgb-field-controls form+form{margin-top:16px;padding-top:16px;border-top:1px solid var(--line)}.vgb-field-frame{display:flex;align-items:center;justify-content:center;min-height:250px;max-height:460px;padding:10px;border:1px solid var(--line);border-radius:12px;background:var(--panel);overflow:hidden}.vgb-field-frame img{display:block;width:100%;height:auto;max-height:438px;object-fit:contain}.vgb-field-empty{display:grid;place-items:center;min-height:250px;padding:28px;color:var(--muted);text-align:center}.vgb-field-empty i{display:block;margin-bottom:9px;font-size:2rem}
.vgb-related{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}.vgb-related a{display:flex;align-items:center;gap:13px;padding:16px;border:1px solid var(--line);border-radius:15px;background:var(--panel);color:var(--text);text-decoration:none}.vgb-related a:hover{border-color:var(--vgb-accent)}.vgb-related i{display:grid;place-items:center;width:42px;height:42px;border-radius:11px;background:color-mix(in srgb,var(--vgb-accent) 15%,var(--panel2));color:var(--vgb-accent)}.vgb-related b,.vgb-related span{display:block}.vgb-related span{margin-top:3px;color:var(--muted);font-size:.78rem}
.builder-readonly-fieldset{border:0;padding:0;margin:0;min-width:0}.builder-readonly-fieldset:disabled{opacity:.72}.layout-rect-1x1{grid-column:span 1;grid-row:span 1}.layout-rect-1x2{grid-column:span 1;grid-row:span 2}.layout-rect-2x1{grid-column:span 2;grid-row:span 1}.layout-rect-1x4{grid-column:span 1;grid-row:span 4}.layout-rect-4x1{grid-column:span 4;grid-row:span 1}.layout-circle-1x1{grid-column:span 1;grid-row:span 1;border-radius:50%}.layout-circle-2x2{grid-column:span 2;grid-row:span 2;border-radius:50%}.layout-circle-4x4{grid-column:span 4;grid-row:span 4;border-radius:50%}
@media(max-width:1050px){.vgb-workspace{grid-template-columns:1fr}.vgb-preview{position:relative;top:auto}.vgb-field-grid{grid-template-columns:1fr}}
@media(max-width:980px){.vgb-colors{grid-template-columns:repeat(2,minmax(130px,1fr))}}
@media(max-width:760px){.vgb-deploy-row{grid-template-columns:1fr}.vgb-deploy-row form,.vgb-deploy-row button{width:100%}.vgb-deploy-row button{justify-content:center}.vgb-hero{padding:18px;border-radius:19px}.vgb-hero-tools{grid-template-columns:1fr}.vgb-panel{padding:15px;border-radius:16px}.vgb-panel-head{flex-direction:column}.vgb-setup-grid{grid-template-columns:1fr}.vgb-timing{grid-template-columns:1fr 1fr}.vgb-action-body{grid-template-columns:1fr}.vgb-field-wide,.vgb-advanced{grid-column:1}.vgb-shapes{grid-template-columns:repeat(4,1fr)}.vgb-related{grid-template-columns:1fr}.vgb-savebar{bottom:8px}.vgb-preview-grid{grid-auto-rows:76px}}
@media(max-width:460px){.vgb-timing{grid-template-columns:1fr 1fr}.vgb-segment{grid-template-columns:1fr 1fr}.vgb-colors{grid-template-columns:repeat(2,minmax(0,1fr))}.vgb-action-head{align-items:flex-start}.vgb-action-tools{flex-wrap:wrap;justify-content:flex-end}.vgb-action-tools .move-up,.vgb-action-tools .move-down{display:none}.vgb-advanced-grid{grid-template-columns:1fr}.vgb-savebar{align-items:stretch;flex-direction:column}.vgb-savebar button{width:100%;justify-content:center}}
</style>

<div class="vgb">
  <section class="vgb-hero">
    <div class="vgb-kicker"><i class="fa-solid fa-hammer"></i> VULCAN · GAME DESIGN</div>
    <h1 class="vgb-title">Game <span>Builder</span></h1>
    <p class="vgb-sub">Build the scout experience visually. Configure match timing, design action buttons, preview the four-column scout grid, and publish an immutable game revision when it is ready.</p>
    <div class="vgb-hero-tools">
      <form method="get">
        <label for="gamePicker">Load game</label>
        <select id="gamePicker" name="game_id" onchange="this.form.submit()"><option value="">New game…</option><?=neptune_game_options_html($games,$editingId,true)?></select>
      </form>
      <div class="vgb-hero-actions">
        <?=neptune_history_toggle_html(neptune_selector_show_history(),'games')?>
        <a class="btn secondary" href="game-builder.php"><i class="fa-solid fa-plus"></i> New Game</a>
      </div>
    </div>
    <?php if($editGame):?>
      <div class="vgb-status-row">
        <span class="pill"><i class="fa-solid fa-building"></i> <?=e($canEdit?'Organization-owned':'Shared by '.$editGame['owner_org_name'])?></span>
        <?php if($currentRevision):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Published Rev <?=e($currentRevision['revision_number'])?></span><?php else:?><span class="pill"><i class="fa-regular fa-circle"></i> Not published</span><?php endif;?>
        <?php if($draftRevision):?><span class="pill"><i class="fa-solid fa-pen"></i> Draft Rev <?=e($draftRevision['revision_number'])?></span><?php endif;?>
      </div>
    <?php endif;?>
  </section>

  <?php if($msg):?><div class="notice good"><i class="fa-solid fa-circle-check"></i> <?=e($msg)?></div><?php endif;?>
  <?php if($error):?><div class="notice bad"><i class="fa-solid fa-triangle-exclamation"></i> <?=e($error)?></div><?php endif;?>

  <?php if($editGame && !$canEdit):?>
    <div class="notice">
      <div class="toolbar" style="justify-content:space-between;margin:0">
        <span><i class="fa-solid fa-lock"></i> <b>Read-only shared configuration.</b> Clone it to make an organization-owned copy.</span>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="clone_game"><input type="hidden" name="source_game_id" value="<?=$editingId?>">
          <button type="submit"><i class="fa-solid fa-copy"></i> Clone to My Organization</button>
        </form>
      </div>
    </div>
  <?php endif;?>

  <?php if($editGame && $canEdit && $draftRevision):?>
    <section class="vgb-panel">
      <div class="vgb-panel-head" style="margin-bottom:0">
        <div>
          <div class="vgb-section-kicker"><i class="fa-solid fa-code-branch"></i> Revision Control</div>
          <h2><?=e($editGame['season_year'].' · '.$editGame['name'])?></h2>
          <p>Existing events stay pinned to their current revision. Publishing changes only the default revision for future events.</p>
        </div>
        <div class="toolbar" style="margin:0">
          <form method="post" style="margin:0" data-confirm="Discard this draft? The current published revision will not be changed." data-confirm-title="Discard draft" data-confirm-button="Discard" data-confirm-danger="1">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="discard_draft"><input type="hidden" name="game_id" value="<?=$editingId?>">
            <button type="submit" class="secondary"><i class="fa-solid fa-rotate-left"></i> Discard Draft</button>
          </form>
          <form method="post" style="margin:0" data-confirm="Publish this revision? Existing events will stay on their current revision; new events will use this one." data-confirm-title="Publish game revision" data-confirm-button="Publish">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="publish_game"><input type="hidden" name="game_id" value="<?=$editingId?>">
            <button type="submit" class="good"><i class="fa-solid fa-cloud-arrow-up"></i> Publish Rev <?=e($draftRevision['revision_number'])?></button>
          </form>
        </div>
      </div>
    </section>
  <?php endif;?>

  <?php if($editGame && $canEdit && $currentRevision && $staleEventDeployments):?>
    <section class="vgb-panel">
      <div class="vgb-panel-head">
        <div>
          <div class="vgb-section-kicker"><i class="fa-solid fa-rocket"></i> Event Revision Deployment</div>
          <h2>Existing Events</h2>
          <p>Events are intentionally pinned to the revision they were created with. Move an event here when you want it to use Published Revision <?=e($currentRevision['revision_number'])?>, including the new theme-aware action colors.</p>
        </div>
        <span class="pill"><i class="fa-solid fa-code-branch"></i> Latest: Rev <?=e($currentRevision['revision_number'])?></span>
      </div>
      <div class="notice" style="margin-top:0"><i class="fa-solid fa-circle-info"></i> Updating an event changes its full game snapshot — Match actions/timing, Pit, Pre-Scout, field image, and action appearance. Existing scouting records are not deleted. Neptune blocks revision changes while a match is running or paused.</div>
      <div class="vgb-deploy-list">
        <?php foreach($staleEventDeployments as $ev):$stale=true;?>
          <div class="vgb-deploy-row is-stale">
            <div class="vgb-deploy-main">
              <div class="vgb-deploy-title">
                <span><?=e($ev['name'])?></span>
                <?php if($ev['is_current']):?><span class="pill"><i class="fa-solid fa-location-dot"></i> Current event</span><?php endif;?>
              </div>
              <div class="vgb-deploy-meta">
                <span class="vgb-rev-arrow"><b>Rev <?=e($ev['current_revision_number'])?></b><i class="fa-solid fa-arrow-right"></i><b>Rev <?=e($currentRevision['revision_number'])?></b></span>
                <span><?=e(str_replace('_',' ',ucwords((string)$ev['event_status'],'_')))?></span>
                <span><?=e($ev['match_count'])?> matches</span>
                <span><?=e($ev['action_count'])?> recorded actions</span>
              </div>
            </div>
            <?php if((int)$ev['active_match_count']>0):?>
                <button type="button" class="secondary" disabled title="End the active match first"><i class="fa-solid fa-lock"></i> Match Active</button>
              <?php else:?>
                <form method="post" data-confirm="Move <?=e($ev['name'])?> from Revision <?=e($ev['current_revision_number'])?> to Revision <?=e($currentRevision['revision_number'])?>? This updates the full game configuration used by this event." data-confirm-title="Update event revision" data-confirm-button="Update Event">
                  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                  <input type="hidden" name="action" value="deploy_revision_to_event">
                  <input type="hidden" name="game_id" value="<?=$editingId?>">
                  <input type="hidden" name="event_id" value="<?=$ev['id']?>">
                  <input type="hidden" name="revision_id" value="<?=$currentRevision['id']?>">
                  <button type="submit"><i class="fa-solid fa-arrow-up-right-dots"></i> Update to Rev <?=e($currentRevision['revision_number'])?></button>
                </form>
              <?php endif;?>
          </div>
        <?php endforeach;?>
      </div>
    </section>
  <?php endif;?>

  <form method="post" id="gameForm">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_game"><input type="hidden" name="game_id" value="<?=$editingId?>"><input type="hidden" name="buttons_json" id="buttons_json">
    <fieldset class="builder-readonly-fieldset" <?=$canEdit?'':'disabled'?>>
      <section class="vgb-panel">
        <div class="vgb-panel-head">
          <div><div class="vgb-section-kicker"><i class="fa-solid fa-stopwatch"></i> Match Setup</div><h2><?= $editingId?'Game & Timing':'Create Game' ?></h2><p>Core game metadata and the timing scouts will see during a match.</p></div>
          <?php if($canEdit):?><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button><?php endif;?>
        </div>
        <div class="vgb-setup-grid">
          <div><label>Game name</label><input name="name" required value="<?=e($cfg['game']??'')?>" placeholder="2027 Game Name"></div>
          <div><label>Season</label><input name="year" type="number" min="1992" max="2100" value="<?=e($editGame['season_year']??date('Y'))?>" required></div>
        </div>
        <div class="vgb-timing">
          <div class="vgb-time-box"><label>Autonomous</label><input type="number" min="1" name="auton_seconds" value="<?=$t['auton']?>"><div class="muted">seconds</div></div>
          <div class="vgb-time-box"><label>Transition</label><input type="number" min="0" name="transition_pause_seconds" value="<?=$t['transition']?>"><div class="muted">timer pause</div></div>
          <div class="vgb-time-box"><label>Teleop</label><input type="number" min="1" name="teleop_seconds" value="<?=$t['teleop']?>"><div class="muted">seconds</div></div>
          <div class="vgb-time-box"><label>Endgame</label><input type="number" min="0" name="endgame_seconds" value="<?=$t['endgame']?>"><div class="muted">last seconds</div></div>
        </div>
      </section>

      <section class="vgb-panel">
        <div class="vgb-panel-head">
          <div><div class="vgb-section-kicker"><i class="fa-solid fa-grid-2"></i> Action Designer</div><h2>Scout Actions</h2><p>Use the dedicated Game Builder action palette from Interface Styling. Each preset supplies eight coordinated scouting colors; choose Custom only when an action must keep a fixed color.</p></div>
          <?php if($canEdit):?><button type="button" class="secondary" id="addAction"><i class="fa-solid fa-plus"></i> Add Action</button><?php endif;?>
        </div>
        <div class="vgb-workspace">
          <div>
            <div id="rows" class="vgb-actions"></div>
            <div id="actionEmpty" class="vgb-empty-actions" hidden><i class="fa-solid fa-table-cells-large"></i>Add your first scout action to begin building the grid.</div>
            <?php if($canEdit):?><div class="vgb-add"><button type="button" class="secondary" id="addActionBottom"><i class="fa-solid fa-plus"></i> Add Action</button></div><?php endif;?>
          </div>
          <aside class="vgb-preview">
            <div class="vgb-preview-head">
              <div><div class="vgb-section-kicker"><i class="fa-solid fa-mobile-screen"></i> Live Preview</div><h2>Scout Grid</h2><div class="muted">Drag preview buttons to reorder, or select one to edit it.</div></div>
              <div class="vgb-phase" aria-label="Preview scoring phase"><button type="button" class="active" data-preview-phase="auton">Auto</button><button type="button" data-preview-phase="teleop">Teleop</button></div>
            </div>
            <div id="preview" class="vgb-preview-grid"></div>
            <div class="vgb-preview-note"><i class="fa-solid fa-palette"></i> Game colors resolve live from <b>Interface Styling → Game Builder · Action Palette</b>. Change Neptune themes and these preview colors change with them. Fixed custom colors stay unchanged.</div>
          </aside>
        </div>
      </section>

      <?php if($canEdit):?>
      <div class="vgb-savebar" id="dirtyBar" hidden>
        <div><strong><i class="fa-solid fa-pen-to-square"></i> Unsaved changes</strong><small>Save the draft before leaving or publishing.</small></div>
        <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button>
      </div>
      <?php endif;?>
    </fieldset>
  </form>

  <section class="vgb-panel vgb-field" id="field-background">
    <div class="vgb-panel-head">
      <div><div class="vgb-section-kicker"><i class="fa-solid fa-map"></i> Autonomous Canvas</div><h2>Field Background</h2><p>Field artwork is revisioned with the game. Publishing snapshots the image so existing events never change underneath scouts.</p></div>
      <?php if($fieldGameId>0 && $fieldUrl):?><span class="pill"><i class="fa-solid fa-circle-check"></i> Background set</span><?php endif;?>
    </div>
    <?php if(!$games):?>
      <div class="notice">Save a game first, then add its field background.</div>
    <?php else:?>
      <div class="vgb-field-grid">
        <section class="vgb-field-controls">
          <h3>Game</h3><div class="muted">The game currently being edited is selected automatically.</div>
          <form method="get" id="fieldGamePicker"><?php if(neptune_selector_show_history()):?><input type="hidden" name="history" value="1"><?php endif;?><?php if($editingId>0):?><input type="hidden" name="game_id" value="<?=$editingId?>"><?php endif;?>
            <label for="field_game_id">Field configuration</label><select name="field_game_id" id="field_game_id" onchange="this.form.submit()"><option value="">Select a game…</option><?=neptune_game_options_html($games,$fieldGameId,true)?></select>
          </form>
          <?php if($fieldGameId>0 && $fieldCanEdit):?>
            <form method="post" enctype="multipart/form-data" id="fieldBackgroundForm">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="upload_field_background"><input type="hidden" name="field_game_id" value="<?=$fieldGameId?>"><input type="hidden" name="game_id" value="<?=$editingId?>">
              <h3>Field image</h3><div class="muted">Top-down field artwork works best. Uploading creates or updates the draft.</div>
              <label for="field_background">Image file</label><input id="field_background" type="file" name="field_background" accept="image/*" required>
              <div class="muted" style="margin-top:6px">Maximum upload: <?=e($fieldUploadLimit)?>.</div>
              <button type="submit" class="secondary" style="width:100%;justify-content:center;margin-top:12px"><i class="fa-solid fa-upload"></i> <?=$fieldUrl?'Replace Field Background':'Upload Field Background'?></button>
            </form>
          <?php elseif($fieldGameId>0):?>
            <div class="notice" style="margin-top:16px"><i class="fa-solid fa-lock"></i> Shared read-only field image. Clone the game to replace it.</div>
          <?php endif;?>
        </section>
        <section class="vgb-field-preview">
          <h3>Preview</h3>
          <?php if($fieldGameId<=0):?><div class="vgb-field-empty"><div><i class="fa-regular fa-image"></i>Select a game to load its field background.</div></div>
          <?php elseif($fieldUrl):?><div class="vgb-field-frame"><img src="<?=e($fieldUrl)?>" alt="Current autonomous field background"></div><div class="muted" style="margin-top:9px"><i class="fa-solid fa-circle-check"></i> <?=e(($fieldRevision['status']??'published')==='draft'?'Draft image — publish to activate it for future events.':'Published image for this revision.')?></div>
          <?php else:?><div class="vgb-field-empty"><div><i class="fa-regular fa-image"></i>No field background has been uploaded for this game yet.</div></div><?php endif;?>
        </section>
      </div>
    <?php endif;?>
  </section>

  <div class="vgb-related">
    <a href="pit-builder.php"><i class="fa-solid fa-screwdriver-wrench"></i><div><b>Pit Form Builder</b><span>Configure game-specific pit questions for this season.</span></div></a>
    <a href="pre-scout-builder.php"><i class="fa-solid fa-clipboard-list"></i><div><b>Pre-Scout Builder</b><span>Configure pre-event research and outreach fields.</span></div></a>
  </div>
</div>

<template id="rowTemplate">
  <article class="vgb-action-card">
    <div class="vgb-action-head">
      <div class="vgb-action-ident"><span class="vgb-drag" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span><span class="vgb-action-number">1</span><span class="vgb-action-title">New action</span></div>
      <div class="vgb-action-tools">
        <button type="button" class="secondary move-up" title="Move up"><i class="fa-solid fa-arrow-up"></i></button><button type="button" class="secondary move-down" title="Move down"><i class="fa-solid fa-arrow-down"></i></button><button type="button" class="secondary duplicate-action" title="Duplicate action"><i class="fa-solid fa-copy"></i></button><button type="button" class="danger remove-action" title="Delete action"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div>
    <div class="vgb-action-body">
      <div class="vgb-field-wide"><label>Action name</label><input data-k="name" class="vgb-name-input" placeholder="Score fuel"></div>
      <div class="vgb-control-group"><label>Type</label><input type="hidden" data-k="type" value="offense"><div class="vgb-segment" data-control="type"><button type="button" data-value="offense"><i class="fa-solid fa-bolt"></i><span>Offense</span></button><button type="button" data-value="defense"><i class="fa-solid fa-shield-halved"></i><span>Defense</span></button><button type="button" data-value="cooperative"><i class="fa-solid fa-people-group"></i><span>Co-op</span></button><button type="button" data-value="other"><i class="fa-solid fa-ellipsis"></i><span>Other</span></button></div></div>
      <div class="vgb-control-group"><label>Points</label><div class="vgb-points"><div><label>Auton</label><input type="number" step="0.1" data-k="autonPoints" value="0"></div><div><label>Teleop</label><input type="number" step="0.1" data-k="teleopPoints" value="0"></div></div></div>
      <div class="vgb-field-wide"><label>Shape / grid size</label><input type="hidden" data-k="layout" value="rect-1x1"><div class="vgb-shapes" data-control="layout">
        <button type="button" class="vgb-shape" data-value="rect-1x1" title="Rectangle 1 × 1"><span class="shape-mini shape-rect-1x1"></span></button><button type="button" class="vgb-shape" data-value="rect-1x2" title="Rectangle 1 × 2"><span class="shape-mini shape-rect-1x2"></span></button><button type="button" class="vgb-shape" data-value="rect-2x1" title="Rectangle 2 × 1"><span class="shape-mini shape-rect-2x1"></span></button><button type="button" class="vgb-shape" data-value="rect-1x4" title="Rectangle 1 × 4"><span class="shape-mini shape-rect-1x4"></span></button><button type="button" class="vgb-shape" data-value="rect-4x1" title="Rectangle 4 × 1"><span class="shape-mini shape-rect-4x1"></span></button><button type="button" class="vgb-shape" data-value="circle-1x1" title="Circle 1 × 1"><span class="shape-mini shape-circle-1x1"></span></button><button type="button" class="vgb-shape" data-value="circle-2x2" title="Circle 2 × 2"><span class="shape-mini shape-circle-2x2"></span></button><button type="button" class="vgb-shape" data-value="circle-4x4" title="Circle 4 × 4"><span class="shape-mini shape-circle-4x4"></span></button>
      </div></div>
      <div class="vgb-field-wide"><label>Action appearance</label><input type="hidden" data-k="colorRole" value="game_primary"><input type="hidden" data-k="bgColor" value=""><div class="vgb-colors" data-control="color"></div><div class="vgb-color-caption"><span><i class="fa-solid fa-wand-magic-sparkles"></i> Game palette follows Interface Styling</span><span class="vgb-color-status">Primary scoring</span></div><label class="vgb-custom-color"><input type="color" class="custom-color" value="#0072B2"><span><i class="fa-solid fa-eye-dropper"></i> Fixed custom color</span><code class="custom-color-hex">#0072B2</code></label></div>
      <details class="vgb-advanced"><summary><i class="fa-solid fa-sliders"></i> Advanced</summary><div class="vgb-advanced-grid"><div><label>Location</label><input data-k="location" placeholder="Hub, reef, source…"></div><div><label>Generated code</label><input data-k="code" class="vgb-code" readonly></div></div></details>
    </div>
  </article>
</template>
<script>
const initialButtons=<?=json_encode(array_values($cfg['buttons']??[]),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const actionPalette=<?=json_encode($actionPalette,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
const canEdit=<?=json_encode($canEdit)?>;
const rows=document.getElementById('rows'),preview=document.getElementById('preview'),tpl=document.getElementById('rowTemplate'),emptyState=document.getElementById('actionEmpty'),dirtyBar=document.getElementById('dirtyBar');
const oldLayouts={'rect-1':'rect-1x1','rect-2':'rect-2x1','circle-1':'circle-1x1','circle-2':'circle-2x2'};
const roleDefaults={offense:'game_primary',defense:'game_defense',cooperative:'game_coop',other:'game_secondary'};
const legacyRoleMap={primary:'game_primary',focus:'game_secondary',competition_red:'game_defense',competition_green:'game_coop',success:'game_intake',error:'game_defense',warning:'game_special',ready:'game_secondary',blue_alliance:'game_primary',blue_alliance_deep:'game_utility'};
const roleMap=Object.fromEntries(actionPalette.map(item=>[item.role,item]));
let previewPhase='auton',dirty=false,initializing=true,dragRow=null;

function slugCode(v){return String(v||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')||'action'}
function normalizeHex(v,fallback='#0072B2'){
  v=String(v||'').trim();
  if(/^#[0-9a-f]{6}$/i.test(v))return v.toUpperCase();
  if(/^#[0-9a-f]{3}$/i.test(v))return '#'+v.slice(1).split('').map(ch=>ch+ch).join('').toUpperCase();
  return fallback;
}
function colorChannels(value){
  value=String(value||'').trim();
  if(/^#[0-9a-f]{3,8}$/i.test(value)){
    let h=value.slice(1);
    if(h.length===3||h.length===4)h=h.slice(0,3).split('').map(ch=>ch+ch).join('');
    if(h.length>=6)return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];
  }
  const m=value.match(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);
  return m?[Number(m[1]),Number(m[2]),Number(m[3])]:[0,114,178];
}
function textFor(bg){
  const ch=colorChannels(bg).map(x=>Math.max(0,Math.min(255,x))/255).map(x=>x<=.03928?x/12.92:Math.pow((x+.055)/1.055,2.4));
  const lum=.2126*ch[0]+.7152*ch[1]+.0722*ch[2],white=1.05/(lum+.05),black=(lum+.05)/.05;
  return black>=white?'#111827':'#FFFFFF';
}
function themeColor(role){
  const item=roleMap[role];
  if(!item)return '#0072B2';
  const root=getComputedStyle(document.documentElement);
  const value=root.getPropertyValue(item.cssVar).trim();
  const fallback=item.fallbackVar?root.getPropertyValue(item.fallbackVar).trim():'';
  return value||fallback||'#0072B2';
}
function themeCss(role){
  const item=roleMap[role];
  if(!item)return null;
  return item.fallbackVar?`var(${item.cssVar}, var(${item.fallbackVar}))`:`var(${item.cssVar})`;
}
function appearanceFor(data,type='offense'){
  const role=String(data.colorRole||'').trim();
  if(role==='custom')return {role:'custom',color:normalizeHex(data.bgColor,'#0072B2'),css:null,label:'Fixed custom color'};
  if(roleMap[role])return {role,color:themeColor(role),css:themeCss(role),label:roleMap[role].hint};
  // Backward compatibility: old revisions stored only a literal bgColor.
  if(/^#[0-9a-f]{3,8}$/i.test(String(data.bgColor||'')))return {role:'custom',color:normalizeHex(data.bgColor,'#0072B2'),css:null,label:'Fixed legacy color'};
  const fallbackRole=roleDefaults[type]||'game_primary';
  return {role:fallbackRole,color:themeColor(fallbackRole),css:themeCss(fallbackRole),label:roleMap[fallbackRole].hint};
}
function markDirty(){if(initializing||!canEdit)return;dirty=true;if(dirtyBar)dirtyBar.hidden=false}
function updateNumbers(){[...rows.children].forEach((r,i)=>{r.querySelector('.vgb-action-number').textContent=String(i+1);const n=r.querySelector('[data-k=name]').value.trim();r.querySelector('.vgb-action-title').textContent=n||'New action'})}
function refreshCodes(){const used={};[...rows.children].forEach(r=>{const name=r.querySelector('[data-k=name]').value;const base=slugCode(name);let code=base;if(used[base]!==undefined){used[base]++;code=base+'_b'+used[base]}else used[base]=0;r.querySelector('[data-k=code]').value=code});updateNumbers()}
function setSegment(r,key,value){const hidden=r.querySelector(`[data-k="${key}"]`);if(!hidden)return;hidden.value=value;r.querySelectorAll(`[data-control="${key}"] [data-value]`).forEach(b=>b.classList.toggle('active',b.dataset.value===value))}
function setRole(r,role,mark=true){
  const roleInput=r.querySelector('[data-k=colorRole]'),bgInput=r.querySelector('[data-k=bgColor]');
  roleInput.value=roleMap[role]?role:'custom';
  if(roleInput.value!=='custom')bgInput.value='';
  r.dataset.autoColor='0';
  syncAppearance(r);renderPreview();if(mark)markDirty();
}
function buildColors(r){
  const holder=r.querySelector('[data-control=color]');
  holder.innerHTML='';
  actionPalette.forEach(item=>{
    const b=document.createElement('button');
    b.type='button';b.className='vgb-color';b.dataset.role=item.role;b.style.setProperty('--swatch',item.fallbackVar?`var(${item.cssVar}, var(${item.fallbackVar}))`:`var(${item.cssVar})`);
    b.title=`${item.label} · ${item.hint} · ${item.cssVar}`;
    b.innerHTML='<span class="vgb-color-dot"></span><span class="vgb-color-copy"><span class="vgb-color-name"></span><span class="vgb-color-hint"></span></span>';
    b.querySelector('.vgb-color-name').textContent=item.label;
    b.querySelector('.vgb-color-hint').textContent=item.hint;
    b.addEventListener('click',()=>setRole(r,item.role));
    holder.appendChild(b);
  });
  syncAppearance(r);
}
function syncAppearance(r){
  const role=r.querySelector('[data-k=colorRole]').value;
  const bg=r.querySelector('[data-k=bgColor]').value;
  r.querySelectorAll('.vgb-color').forEach(b=>b.classList.toggle('active',b.dataset.role===role));
  const customWrap=r.querySelector('.vgb-custom-color'),custom=r.querySelector('.custom-color'),hex=r.querySelector('.custom-color-hex'),status=r.querySelector('.vgb-color-status');
  customWrap.classList.toggle('active',role==='custom');
  if(role==='custom'){
    const fixed=normalizeHex(bg||custom.value,'#0072B2');
    custom.value=fixed;hex.textContent=fixed;status.textContent='Fixed · does not change with theme';
  }else if(roleMap[role]){
    const resolved=themeColor(role);
    hex.textContent='CUSTOM';
    status.textContent=`${roleMap[role].hint} · ${resolved}`;
  }else{
    status.textContent='Theme color';
  }
}
function selectRow(r,scroll=false){[...rows.children].forEach(x=>x.classList.toggle('is-selected',x===r));if(scroll)r.scrollIntoView({behavior:'smooth',block:'center'})}
function bindRow(r,v={}){
  const type=(['offense','defense','cooperative','other'].includes(v.type)?v.type:'offense');
  const layout=oldLayouts[v.layout]||v.layout||'rect-1x1';
  r.querySelector('[data-k=name]').value=v.name||'';
  r.querySelector('[data-k=location]').value=v.location||'';
  r.querySelector('[data-k=autonPoints]').value=v.autonPoints??0;
  r.querySelector('[data-k=teleopPoints]').value=v.teleopPoints??0;
  setSegment(r,'type',type);
  setSegment(r,'layout',['rect-1x1','rect-1x2','rect-2x1','rect-1x4','rect-4x1','circle-1x1','circle-2x2','circle-4x4'].includes(layout)?layout:'rect-1x1');

  const storedRole=String(v.colorRole||'');
  const migratedRole=legacyRoleMap[storedRole]||storedRole;
  const validRole=roleMap[migratedRole]?migratedRole:'';
  const explicitCustom=storedRole==='custom';
  const legacyColor=/^#[0-9a-f]{3,8}$/i.test(String(v.bgColor||''));
  if(validRole){
    r.querySelector('[data-k=colorRole]').value=validRole;
    r.querySelector('[data-k=bgColor]').value='';
    r.dataset.autoColor='0';
  }else if(explicitCustom||legacyColor){
    r.querySelector('[data-k=colorRole]').value='custom';
    r.querySelector('[data-k=bgColor]').value=normalizeHex(v.bgColor,'#0072B2');
    r.dataset.autoColor='0';
  }else{
    r.querySelector('[data-k=colorRole]').value=roleDefaults[type]||'game_primary';
    r.querySelector('[data-k=bgColor]').value='';
    r.dataset.autoColor='1';
  }
  buildColors(r);

  r.querySelectorAll('[data-control=type] button').forEach(b=>b.addEventListener('click',()=>{
    const old=r.querySelector('[data-k=type]').value;
    setSegment(r,'type',b.dataset.value);
    if(r.dataset.autoColor==='1'){
      r.dataset.autoColor='1';
      r.querySelector('[data-k=colorRole]').value=roleDefaults[b.dataset.value]||'game_primary';
      r.querySelector('[data-k=bgColor]').value='';
      syncAppearance(r);
    }
    renderPreview();markDirty();
  }));
  r.querySelectorAll('[data-control=layout] button').forEach(b=>b.addEventListener('click',()=>{setSegment(r,'layout',b.dataset.value);renderPreview();markDirty()}));
  r.querySelector('.custom-color').addEventListener('input',e=>{
    r.querySelector('[data-k=colorRole]').value='custom';
    r.querySelector('[data-k=bgColor]').value=normalizeHex(e.target.value);
    r.dataset.autoColor='0';syncAppearance(r);renderPreview();markDirty();
  });
  r.querySelector('.vgb-custom-color').addEventListener('click',e=>{
    if(e.target.closest('input'))return;
    const custom=r.querySelector('.custom-color');
    r.querySelector('[data-k=colorRole]').value='custom';
    r.querySelector('[data-k=bgColor]').value=normalizeHex(custom.value,'#0072B2');
    r.dataset.autoColor='0';syncAppearance(r);renderPreview();markDirty();
  });
  r.querySelector('.remove-action').addEventListener('click',()=>{r.remove();refreshCodes();renderPreview();markDirty()});
  r.querySelector('.duplicate-action').addEventListener('click',()=>{const data=collectRow(r);data.name=(data.name||'Action')+' Copy';addRow(data,true,r);markDirty()});
  r.querySelector('.move-up').addEventListener('click',()=>{if(r.previousElementSibling)rows.insertBefore(r,r.previousElementSibling);refreshCodes();renderPreview();markDirty()});
  r.querySelector('.move-down').addEventListener('click',()=>{if(r.nextElementSibling)rows.insertBefore(r.nextElementSibling,r);refreshCodes();renderPreview();markDirty()});
  r.querySelectorAll('input:not([type=hidden]):not([type=color])').forEach(x=>x.addEventListener('input',()=>{refreshCodes();renderPreview();markDirty()}));
  r.querySelector('.vgb-drag').draggable=canEdit;
  r.querySelector('.vgb-drag').addEventListener('dragstart',e=>{dragRow=r;r.classList.add('is-dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','row')});
  r.querySelector('.vgb-drag').addEventListener('dragend',()=>{r.classList.remove('is-dragging');dragRow=null});
  r.addEventListener('dragover',e=>{if(!dragRow||dragRow===r)return;e.preventDefault();e.dataTransfer.dropEffect='move'});
  r.addEventListener('drop',e=>{if(!dragRow||dragRow===r)return;e.preventDefault();const rect=r.getBoundingClientRect(),after=e.clientY>rect.top+rect.height/2;rows.insertBefore(dragRow,after?r.nextSibling:r);refreshCodes();renderPreview();markDirty()});
  r.addEventListener('click',e=>{if(!e.target.closest('button,input,select,summary,label'))selectRow(r)});
  refreshCodes();renderPreview();
}
function addRow(v={},mark=true,after=null){const r=tpl.content.firstElementChild.cloneNode(true);if(after&&after.parentNode===rows)rows.insertBefore(r,after.nextSibling);else rows.appendChild(r);bindRow(r,v);if(mark)markDirty();selectRow(r,false);return r}
function collectRow(r){const o={};r.querySelectorAll('[data-k]').forEach(x=>{o[x.dataset.k]=x.type==='number'?Number(x.value):x.value});return o}
function collect(){return [...rows.children].map(collectRow).filter(o=>String(o.name||'').trim())}
function reorderByIndex(from,to){const cards=[...rows.children];if(from===to||!cards[from]||!cards[to])return;const moved=cards[from],target=cards[to];if(from<to)rows.insertBefore(moved,target.nextSibling);else rows.insertBefore(moved,target);refreshCodes();renderPreview();markDirty()}
function renderPreview(){
  preview.innerHTML='';
  const records=[...rows.children].map((row,index)=>({row,index,data:collectRow(row)})).filter(x=>String(x.data.name||'').trim());
  emptyState.hidden=rows.children.length>0;
  if(!records.length){preview.innerHTML='<div class="vgb-preview-empty"><div><i class="fa-solid fa-table-cells-large fa-2x"></i><div style="margin-top:8px">Add an action to preview the scout grid.</div></div></div>';return}
  records.forEach(rec=>{
    const b=rec.data,appearance=appearanceFor(b,b.type),el=document.createElement('div');
    el.className='vgb-preview-action layout-'+b.layout;
    el.style.background=appearance.css||appearance.color;
    el.style.color=textFor(appearance.color);
    el.draggable=canEdit;el.dataset.index=String(rec.index);
    const pts=previewPhase==='auton'?Number(b.autonPoints||0):Number(b.teleopPoints||0);
    el.innerHTML=`<span class="vgb-preview-name"></span><span class="vgb-preview-points">${pts>0?'+':''}${Number.isInteger(pts)?pts:pts.toFixed(1)}</span>`;
    el.querySelector('.vgb-preview-name').textContent=b.name;
    el.addEventListener('click',()=>selectRow(rec.row,true));
    el.addEventListener('dragstart',e=>{e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(rec.index))});
    el.addEventListener('dragover',e=>{if(canEdit)e.preventDefault()});
    el.addEventListener('drop',e=>{if(!canEdit)return;e.preventDefault();const from=Number(e.dataTransfer.getData('text/plain'));reorderByIndex(from,rec.index)});
    preview.appendChild(el);
  });
}
function addDefault(){addRow({layout:'rect-1x1',colorRole:'game_primary',type:'offense',autonPoints:0,teleopPoints:0})}
if(document.getElementById('addAction'))document.getElementById('addAction').addEventListener('click',addDefault);
if(document.getElementById('addActionBottom'))document.getElementById('addActionBottom').addEventListener('click',addDefault);
document.querySelectorAll('[data-preview-phase]').forEach(b=>b.addEventListener('click',()=>{previewPhase=b.dataset.previewPhase;document.querySelectorAll('[data-preview-phase]').forEach(x=>x.classList.toggle('active',x===b));renderPreview()}));
document.getElementById('gameForm').addEventListener('input',e=>{if(!e.target.closest('.vgb-action-card'))markDirty()});
document.getElementById('gameForm').addEventListener('submit',()=>{refreshCodes();document.getElementById('buttons_json').value=JSON.stringify(collect());dirty=false});
(initialButtons.length?initialButtons:[{}]).forEach(v=>addRow(v,false));
initializing=false;dirty=false;if(dirtyBar)dirtyBar.hidden=true;renderPreview();
new MutationObserver(()=>{document.querySelectorAll('.vgb-action-card').forEach(syncAppearance);renderPreview()}).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue=''}});
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
