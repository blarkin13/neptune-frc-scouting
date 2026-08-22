<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
$u=require_login();$org=(int)$u['organization_id'];$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);$team=(int)($_GET['team']??$_POST['team']??0);$msg='';$error='';

$s=$pdo->prepare("SELECT e.*,g.name game_name,g.pit_config_json,et.nickname FROM events e JOIN games g ON g.id=e.game_id JOIN event_teams et ON et.event_id=e.id AND et.frc_team_number=? WHERE e.id=? AND e.organization_id=?");$s->execute([$team,$eventId,$org]);$event=$s->fetch();
if(!$event){http_response_code(404);exit('Team is not on this event roster.');}
$questions=neptune_pit_all_questions($event['pit_config_json']??null);

$s=$pdo->prepare('SELECT * FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$pit=$s->fetch()?:null;
$data=$pit?json_decode($pit['data_json'],true):[];if(!is_array($data))$data=[];

function save_pit_photo(PDO $pdo,array $u,int $org,int $pitId,int $eventId,int $team,string $category,array $file): void {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return;
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('A pit photo failed to upload.');
    if((int)($file['size']??0)>8*1024*1024)throw new RuntimeException('Pit photos must be 8 MB or smaller.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
    if(!$ext||@getimagesize($file['tmp_name'])===false)throw new RuntimeException('Pit photos must be JPG, PNG, or WebP images.');
    $rel='uploads/pit/'.$org.'/'.$eventId.'/'.$team; $root=dirname(__DIR__).'/'.$rel;
    if(!is_dir($root)&&!mkdir($root,0755,true)&&!is_dir($root))throw new RuntimeException('Could not create the pit photo directory.');
    $name=$category.'-'.bin2hex(random_bytes(8)).'.'.$ext;$dest=$root.'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Could not save the pit photo.');
    $path=$rel.'/'.$name;
    $pdo->prepare('INSERT INTO pit_scouting_photos(pit_scouting_id,organization_id,category,file_path,uploaded_by) VALUES(?,?,?,?,?)')->execute([$pitId,$org,$category,$path,(int)$u['id']]);
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
        $notes=trim($_POST['notes']??'');$status=(($_POST['save_mode']??'complete')==='draft')?'in_progress':'complete';
        if($missing)throw new RuntimeException('Complete the required fields: '.implode(', ',$missing));
        $ownerTeam=primary_team_id($pdo,(int)$u['id'],$org);
        $json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $sql="INSERT INTO pit_scouting(organization_id,owner_team_id,event_id,frc_team_number,submitted_by,data_json,notes,status,started_at,completed_at) VALUES(?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),IF(?='complete',UTC_TIMESTAMP(),NULL)) ON DUPLICATE KEY UPDATE owner_team_id=COALESCE(VALUES(owner_team_id),owner_team_id),submitted_by=VALUES(submitted_by),data_json=VALUES(data_json),notes=VALUES(notes),status=VALUES(status),started_at=COALESCE(started_at,UTC_TIMESTAMP()),completed_at=IF(VALUES(status)='complete',UTC_TIMESTAMP(),completed_at)";
        $pdo->prepare($sql)->execute([$org,$ownerTeam,$eventId,$team,(int)$u['id'],$json,$notes,$status,$status]);
        $s=$pdo->prepare('SELECT id FROM pit_scouting WHERE organization_id=? AND event_id=? AND frc_team_number=?');$s->execute([$org,$eventId,$team]);$pitId=(int)$s->fetchColumn();
        foreach(['front','back','left','right','mechanism'] as $cat)if(isset($_FILES['photo_'.$cat]))save_pit_photo($pdo,$u,$org,$pitId,$eventId,$team,$cat,$_FILES['photo_'.$cat]);
        $msg=$status==='complete'?'Pit scouting marked complete.':'Draft saved.';
        $pit=$pdo->query('SELECT * FROM pit_scouting WHERE id='.(int)$pitId)->fetch();$data=$clean;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$photos=[];if($pit){$s=$pdo->prepare('SELECT * FROM pit_scouting_photos WHERE pit_scouting_id=? ORDER BY category,created_at DESC');$s->execute([(int)$pit['id']]);$photos=$s->fetchAll();}
$s=$pdo->prepare("SELECT et.frc_team_number FROM event_teams et LEFT JOIN pit_scouting ps ON ps.organization_id=? AND ps.event_id=et.event_id AND ps.frc_team_number=et.frc_team_number AND ps.status='complete' WHERE et.event_id=? AND ps.id IS NULL ORDER BY CASE WHEN et.frc_team_number>? THEN 0 ELSE 1 END,et.frc_team_number LIMIT 1");$s->execute([$org,$eventId,$team]);$nextTeam=(int)$s->fetchColumn();

$groups=[];foreach($questions as $q)$groups[(string)($q['group']??'Other')][]=$q;
$pageTitle='Pit #'.$team;include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between"><div><div class="muted"><?=e($event['name'])?> · <?=e($event['game_name'])?></div><h1 style="margin:3px 0">#<?=e($team)?> <?=e($event['nickname']?:'')?></h1><div class="muted">Pit Scouting</div></div><div class="toolbar"><a class="btn secondary" href="index.php?event_id=<?=$eventId?>"><i class="fa-solid fa-arrow-left"></i> Team List</a><?php if($nextTeam):?><a class="btn secondary" href="scout.php?event_id=<?=$eventId?>&team=<?=$nextTeam?>">Next Unscouted <i class="fa-solid fa-arrow-right"></i></a><?php endif;?></div></div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="team" value="<?=$team?>">
<?php foreach($groups as $group=>$qs):?><section class="card pit-form-section"><h2><?=e($group)?></h2><div class="pit-form-grid"><?php foreach($qs as $q)neptune_pit_render_field($q,$data[$q['code']]??($q['type']==='multiselect'?[]:''));?></div></section><?php endforeach;?>
<section class="card pit-form-section"><h2><i class="fa-solid fa-camera"></i> Robot Photos</h2><p class="muted">Optional. JPG, PNG, or WebP; maximum 8 MB each.</p><div class="pit-photo-inputs"><?php foreach(['front'=>'Front','back'=>'Back','left'=>'Left side','right'=>'Right side','mechanism'=>'Mechanism / detail'] as $cat=>$label):?><div><label><?=e($label)?></label><input type="file" name="photo_<?=$cat?>" accept="image/jpeg,image/png,image/webp"></div><?php endforeach;?></div><?php if($photos):?><div class="pit-photo-grid"><?php foreach($photos as $p):?><a href="<?=e(base_url($p['file_path']))?>" target="_blank"><img src="<?=e(base_url($p['file_path']))?>" alt="<?=e($p['category'])?>"><span><?=e(ucfirst($p['category']))?></span></a><?php endforeach;?></div><?php endif;?></section>
<section class="card pit-form-section"><h2>Additional Notes</h2><label>Anything strategy should know that was not covered above</label><textarea name="notes" rows="5"><?=e($pit['notes']??'')?></textarea></section>
<div class="sticky-save"><button class="secondary" name="save_mode" value="draft"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button><button class="good" name="save_mode" value="complete"><i class="fa-solid fa-circle-check"></i> Mark Complete</button></div>
</form>
<?php include dirname(__DIR__).'/partials_footer.php';
