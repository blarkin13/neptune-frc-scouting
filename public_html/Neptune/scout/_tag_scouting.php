<?php
require_once __DIR__.'/_tag_schema.php';
require_once dirname(__DIR__).'/_event_selection.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';

function tagobs_tables_ready(PDO $pdo): bool {
    tag_unified_ensure_schema($pdo);
    static $ready=null;
    if($ready!==null) return $ready;
    $needed=['tag_scouting_tags','tag_scouting_tag_visibility','tag_scouting_observations','tag_scouting_observation_tags','tag_scouting_media','tag_scouting_preferences'];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    foreach($needed as $table){
        $s->execute([$table]);
        if((int)$s->fetchColumn()===0) return $ready=false;
    }
    return $ready=true;
}

function tagobs_can_manage(array $u): bool {
    return in_array((string)($u['role']??''),['owner','admin','strategy'],true);
}

function tagobs_csrf_or_fail(string $token): void {
    if(!hash_equals((string)($_SESSION['csrf']??''),$token)){
        json_response(['status'=>'error','message'=>'Invalid CSRF token.'],419);
    }
}

function tagobs_slug(string $label): string {
    $slug=strtolower(trim($label));
    $slug=preg_replace('/[^a-z0-9]+/','-',$slug)??'';
    return trim($slug,'-');
}

function tagobs_context_label(string $context): string {
    return match($context){
        'match'=>'Match',
        'pit'=>'Pit',
        default=>'Team',
    };
}

function tagobs_severity_rank(string $severity): int {
    return match($severity){
        'critical'=>4,
        'warning'=>3,
        'positive'=>2,
        default=>1,
    };
}

function tagobs_highest_severity(array $tags): string {
    $best='info';$rank=1;
    foreach($tags as $tag){
        $sev=(string)($tag['severity']??'info');
        $r=tagobs_severity_rank($sev);
        if($r>$rank){$rank=$r;$best=$sev;}
    }
    return $best;
}

function tagobs_visible_tags(PDO $pdo,int $org,bool $includeInactive=false): array {
    $sql="SELECT t.*,
        CASE WHEN t.organization_id IS NULL THEN 'global' ELSE 'organization' END scope,
        COALESCE(v.active,t.active) effective_active
      FROM tag_scouting_tags t
      LEFT JOIN tag_scouting_tag_visibility v ON v.tag_id=t.id AND v.organization_id=?
      WHERE (t.organization_id IS NULL OR t.organization_id=?)";
    if(!$includeInactive)$sql.=' AND COALESCE(v.active,t.active)=1';
    $sql.=" ORDER BY FIELD(t.category,'Performance','Discipline','Reliability','Pit','Strategy','General'),t.category,t.sort_order,t.label";
    $s=$pdo->prepare($sql);$s->execute([$org,$org]);
    $rows=$s->fetchAll();
    foreach($rows as &$row)$row['active']=(int)$row['effective_active'];unset($row);
    return $rows;
}

function tagobs_events(PDO $pdo,int $org,bool $activeOnly=true,int $selectedEventId=0): array {
    if($activeOnly){
        return neptune_event_selector_rows($pdo,$org,$selectedEventId,neptune_selector_show_history());
    }
    $s=$pdo->prepare("SELECT e.*,g.season_year,g.name game_name,g.is_archived game_is_archived
        FROM events e JOIN games g ON g.id=e.game_id
        WHERE e.organization_id=?
        ORDER BY e.is_current DESC,g.season_year DESC,COALESCE(e.start_date,e.end_date,'1900-01-01') DESC,e.name,e.id DESC");
    $s->execute([$org]);
    return $s->fetchAll();
}

function tagobs_match_payload(PDO $pdo,array $m): array {
    $s=$pdo->prepare("SELECT mt.frc_team_number,mt.alliance,mt.station,et.nickname,et.city,et.state_prov,et.country
      FROM match_teams mt
      LEFT JOIN event_teams et ON et.event_id=? AND et.frc_team_number=mt.frc_team_number
      WHERE mt.match_id=?
      ORDER BY FIELD(mt.alliance,'Red','Blue'),mt.station");
    $s->execute([(int)$m['event_id'],(int)$m['id']]);
    $teams=[];
    foreach($s->fetchAll() as $t){
        $teams[]=[
            'team'=>(int)$t['frc_team_number'],
            'alliance'=>(string)$t['alliance'],
            'station'=>(int)$t['station'],
            'nickname'=>(string)($t['nickname']??''),
            'city'=>(string)($t['city']??''),
            'state_prov'=>(string)($t['state_prov']??''),
            'country'=>(string)($t['country']??''),
        ];
    }
    return [
        'id'=>(int)$m['id'],
        'event_id'=>(int)$m['event_id'],
        'event_name'=>(string)$m['event_name'],
        'tba_event_key'=>(string)($m['tba_event_key']??''),
        'field_id'=>(int)$m['field_id'],
        'state'=>(string)$m['state'],
        'label'=>neptune_match_label($m),
        'comp_level'=>(string)$m['comp_level'],
        'set_number'=>(int)$m['set_number'],
        'match_number'=>(int)$m['match_number'],
        'scheduled_time'=>$m['scheduled_time']??null,
        'teams'=>$teams,
    ];
}

/**
 * One current/next match for every active event + field.
 * Running/paused wins, then ready, then the next scheduled match.
 */
function tagobs_active_matches(PDO $pdo,int $org): array {
    $s=$pdo->prepare("SELECT m.id,m.event_id,m.field_id,m.state,m.comp_level,m.set_number,m.match_number,m.scheduled_time,
        e.name event_name,e.tba_event_key,e.is_current
      FROM matches m
      JOIN events e ON e.id=m.event_id
      WHERE m.organization_id=? AND e.organization_id=? AND e.active=1 AND m.state<>'ended'
      ORDER BY e.is_current DESC,e.name,m.field_id,
        FIELD(m.state,'running','paused','ready','scheduled'),
        CASE WHEN m.scheduled_time IS NULL THEN 1 ELSE 0 END,m.scheduled_time,
        FIELD(m.comp_level,'qm','ef','qf','sf','f'),m.set_number,m.match_number");
    $s->execute([$org,$org]);
    $chosen=[];
    foreach($s->fetchAll() as $m){
        $key=(int)$m['event_id'].':'.(int)$m['field_id'];
        if(isset($chosen[$key]))continue;
        $chosen[$key]=tagobs_match_payload($pdo,$m);
    }
    return array_values($chosen);
}

function tagobs_matches_for_event(PDO $pdo,int $org,int $eventId): array {
    $s=$pdo->prepare("SELECT m.id,m.event_id,m.field_id,m.state,m.comp_level,m.set_number,m.match_number,m.scheduled_time,
        e.name event_name,e.tba_event_key
      FROM matches m
      JOIN events e ON e.id=m.event_id
      WHERE m.organization_id=? AND e.organization_id=? AND m.event_id=?
      ORDER BY FIELD(m.comp_level,'qm','ef','qf','sf','f'),m.set_number,m.match_number,m.field_id");
    $s->execute([$org,$org,$eventId]);
    $out=[];
    foreach($s->fetchAll() as $m)$out[]=tagobs_match_payload($pdo,$m);
    return $out;
}

function tagobs_team_search(PDO $pdo,int $org,string $q,int $limit=40): array {
    $q=trim($q);$limit=max(1,min(100,$limit));
    if($q==='')return [];
    $like='%'.$q.'%';
    $digits=preg_replace('/\D+/','',$q)??'';
    $sql="SELECT et.frc_team_number,
        MAX(NULLIF(et.nickname,'')) nickname,
        MAX(NULLIF(et.city,'')) city,
        MAX(NULLIF(et.state_prov,'')) state_prov,
        MAX(NULLIF(et.country,'')) country,
        GROUP_CONCAT(DISTINCT e.name ORDER BY e.is_current DESC,e.name SEPARATOR ' · ') events
      FROM event_teams et
      JOIN events e ON e.id=et.event_id
      WHERE e.organization_id=? AND e.active=1
        AND (CAST(et.frc_team_number AS CHAR) LIKE ? OR et.nickname LIKE ? OR et.city LIKE ? OR et.country LIKE ?)";
    $args=[$org,$digits!==''?'%'.$digits.'%':$like,$like,$like,$like];
    $sql.=" GROUP BY et.frc_team_number
      ORDER BY CASE WHEN CAST(et.frc_team_number AS CHAR)=? THEN 0 ELSE 1 END,et.frc_team_number
      LIMIT {$limit}";
    $args[]=$digits;
    $s=$pdo->prepare($sql);$s->execute($args);
    return array_map(static fn($r)=>[
        'team'=>(int)$r['frc_team_number'],
        'nickname'=>(string)($r['nickname']??''),
        'city'=>(string)($r['city']??''),
        'state_prov'=>(string)($r['state_prov']??''),
        'country'=>(string)($r['country']??''),
        'events'=>(string)($r['events']??''),
    ],$s->fetchAll());
}

function tagobs_user_preference(PDO $pdo,int $org,int $userId): array {
    $s=$pdo->prepare("SELECT event_id,field_id,auto_follow FROM tag_scouting_preferences WHERE organization_id=? AND user_id=? LIMIT 1");
    $s->execute([$org,$userId]);
    $r=$s->fetch();
    return $r?:['event_id'=>null,'field_id'=>null,'auto_follow'=>1];
}

function tagobs_parse_tag_blob(?string $blob): array {
    if(!$blob)return [];
    $out=[];
    foreach(explode('~~',$blob) as $chunk){
        $parts=explode('||',$chunk,4);
        if(count($parts)<4)continue;
        $out[]=['id'=>(int)$parts[0],'label'=>$parts[1],'icon'=>$parts[2],'severity'=>$parts[3]];
    }
    return $out;
}

function tagobs_observation_rows(PDO $pdo,int $org,?int $eventId=null,?int $team=null,int $limit=40,bool $reviewOnly=false): array {
    $limit=max(1,min(200,$limit));
    $sql="SELECT o.*,e.name event_name,e.tba_event_key,
        m.comp_level,m.set_number,m.match_number,m.state match_state,
        u.display_name scout_name,
        ru.display_name resolved_by_name,
        GROUP_CONCAT(DISTINCT CONCAT(t.id,'||',REPLACE(t.label,'~~',' '),'||',t.icon,'||',t.severity) ORDER BY t.category,t.sort_order,t.label SEPARATOR '~~') tag_blob,
        COUNT(DISTINCT CASE WHEN med.media_type='photo' THEN med.id END) photo_count,
        COUNT(DISTINCT CASE WHEN med.media_type='video' THEN med.id END) video_count,
        MAX(md.scoring_contribution) scoring_contribution
      FROM tag_scouting_observations o
      LEFT JOIN events e ON e.id=o.event_id
      LEFT JOIN matches m ON m.id=o.match_id
      LEFT JOIN users u ON u.id=o.created_by
      LEFT JOIN users ru ON ru.id=o.resolved_by
      LEFT JOIN tag_scouting_observation_tags ot ON ot.observation_id=o.id
      LEFT JOIN tag_scouting_tags t ON t.id=ot.tag_id
      LEFT JOIN tag_scouting_media med ON med.observation_id=o.id
      LEFT JOIN tag_scouting_match_data md ON md.observation_id=o.id
      WHERE o.organization_id=?";
    $args=[$org];
    if($eventId){$sql.=' AND o.event_id=?';$args[]=$eventId;}
    if($team){$sql.=' AND o.frc_team_number=?';$args[]=$team;}
    if($reviewOnly)$sql.=" AND o.status='open' AND o.severity IN ('warning','critical')";
    $sql.=" GROUP BY o.id ORDER BY o.created_at DESC,o.id DESC LIMIT {$limit}";
    $s=$pdo->prepare($sql);$s->execute($args);
    $rows=[];
    foreach($s->fetchAll() as $r){
        $r['id']=(int)$r['id'];
        $r['event_id']=$r['event_id']===null?null:(int)$r['event_id'];
        $r['match_id']=$r['match_id']===null?null:(int)$r['match_id'];
        $r['field_id']=$r['field_id']===null?null:(int)$r['field_id'];
        $r['frc_team_number']=(int)$r['frc_team_number'];
        $r['tags']=tagobs_parse_tag_blob($r['tag_blob']??null);
        unset($r['tag_blob']);
        $r['photo_count']=(int)$r['photo_count'];
        $r['video_count']=(int)$r['video_count'];
        $r['match_label']=$r['match_id']?neptune_match_label($r):null;
        $rows[]=$r;
    }
    return $rows;
}

function tagobs_media_for_observations(PDO $pdo,int $org,array $observationIds): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$observationIds),static fn($v)=>$v>0)));
    if(!$ids)return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $s=$pdo->prepare("SELECT id,observation_id,media_type,original_filename,mime_type,file_size,width,height,duration_seconds,created_at
      FROM tag_scouting_media
      WHERE organization_id=? AND observation_id IN ({$ph})
      ORDER BY observation_id,id");
    $s->execute(array_merge([$org],$ids));
    $out=[];
    foreach($s->fetchAll() as $r){
        $r['id']=(int)$r['id'];$r['observation_id']=(int)$r['observation_id'];
        $r['url']=base_url('scout/tag-media.php?id='.$r['id']);
        $out[$r['observation_id']][]=$r;
    }
    return $out;
}

function tagobs_flatten_uploads(array $files,string $field): array {
    if(!isset($files[$field]))return [];
    $f=$files[$field];
    if(!is_array($f['name']??null))return [$f];
    $out=[];
    foreach($f['name'] as $i=>$name){
        $out[]=[
            'name'=>$name,
            'type'=>$f['type'][$i]??'',
            'tmp_name'=>$f['tmp_name'][$i]??'',
            'error'=>$f['error'][$i]??UPLOAD_ERR_NO_FILE,
            'size'=>$f['size'][$i]??0,
        ];
    }
    return $out;
}

function tagobs_secure_media_root(): string {
    return dirname(__DIR__,3).'/neptune_secure/spot_media';
}

function tagobs_detect_mime(string $path): string {
    if(class_exists('finfo')){
        try{$f=new finfo(FILEINFO_MIME_TYPE);return (string)($f->file($path)?:'');}catch(Throwable){}
    }
    return (string)(mime_content_type($path)?:'');
}

function tagobs_store_media_file(array $file,int $org,int $observationId,int $team): array {
    $err=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($err===UPLOAD_ERR_NO_FILE)throw new RuntimeException('No media file was selected.');
    if($err!==UPLOAD_ERR_OK)throw new RuntimeException(neptune_upload_error_message($err));
    $tmp=(string)($file['tmp_name']??'');
    if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('The uploaded file could not be verified.');
    $size=(int)($file['size']??0);
    if($size<=0)throw new RuntimeException('The selected file is empty.');

    $mime=tagobs_detect_mime($tmp);
    $photoMimes=['image/jpeg','image/png','image/webp','image/avif','image/heic','image/heif'];
    $videoMimes=['video/mp4','video/quicktime','video/webm','video/x-m4v','video/3gpp'];
    $root=tagobs_secure_media_root();
    $relDir='org-'.$org.'/'.date('Y').'/'.date('m');
    $destDir=$root.'/'.$relDir;
    if(!is_dir($destDir)&&!mkdir($destDir,0750,true)&&!is_dir($destDir))throw new RuntimeException('Could not create Tag Scouting media storage.');

    if(in_array($mime,$photoMimes,true)){
        if($size>20*1024*1024)throw new RuntimeException('Photos must be 20 MB or smaller before optimization.');
        try{
            $stored=neptune_store_uploaded_image($file,$destDir,'tag-'.$observationId.'-team-'.$team,2000,20*1024*1024);
        }catch(Throwable $e){
            throw new RuntimeException(str_replace(['Pit photos','pit photo'],['Photos','photo'],$e->getMessage()));
        }
        return [
            'media_type'=>'photo',
            'storage_relpath'=>$relDir.'/'.$stored['filename'],
            'original_filename'=>mb_substr((string)($file['name']??'photo'),0,255),
            'mime_type'=>$stored['mime'],
            'file_size'=>$stored['bytes'],
            'width'=>$stored['width'],
            'height'=>$stored['height'],
            'duration_seconds'=>null,
        ];
    }

    if(in_array($mime,$videoMimes,true)){
        if($size>100*1024*1024)throw new RuntimeException('Videos must be 100 MB or smaller.');
        $ext=match($mime){
            'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm','video/x-m4v'=>'m4v','video/3gpp'=>'3gp',default=>'bin'
        };
        $name='tag-'.$observationId.'-team-'.$team.'-'.bin2hex(random_bytes(8)).'.'.$ext;
        $dest=$destDir.'/'.$name;
        if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Could not save the uploaded video.');
        @chmod($dest,0640);
        return [
            'media_type'=>'video',
            'storage_relpath'=>$relDir.'/'.$name,
            'original_filename'=>mb_substr((string)($file['name']??'video'),0,255),
            'mime_type'=>$mime,
            'file_size'=>(int)filesize($dest),
            'width'=>null,'height'=>null,'duration_seconds'=>null,
        ];
    }

    throw new RuntimeException('Tag Scouting accepts photos and MP4, MOV, WebM, M4V, or 3GP video files.');
}


function tagobs_staging_dir(int $org,int $userId): string {
    return tagobs_secure_media_root().'/_staging/org-'.$org.'/user-'.$userId;
}

function tagobs_staging_token_valid(string $token): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{20,80}$/',$token);
}

function tagobs_cleanup_staged_uploads(int $org,int $userId,int $maxAgeSeconds=21600): void {
    $dir=tagobs_staging_dir($org,$userId);
    if(!is_dir($dir))return;
    $cutoff=time()-max(900,$maxAgeSeconds);
    foreach(glob($dir.'/*')?:[] as $path){
        if(!is_file($path))continue;
        $mtime=@filemtime($path);
        if($mtime!==false && $mtime<$cutoff)@unlink($path);
    }
}

function tagobs_receive_video_chunk(array $file,int $org,int $userId,string $token,int $chunkIndex,int $totalChunks,string $originalName,int $totalSize,string $claimedType=''): array {
    if(!tagobs_staging_token_valid($token))throw new RuntimeException('Invalid staged upload token.');
    if($chunkIndex<0||$totalChunks<1||$chunkIndex>=$totalChunks||$totalChunks>200)throw new RuntimeException('Invalid video chunk sequence.');
    if($totalSize<=0||$totalSize>100*1024*1024)throw new RuntimeException('Videos must be 100 MB or smaller.');

    $err=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($err!==UPLOAD_ERR_OK)throw new RuntimeException(neptune_upload_error_message($err));
    $tmp=(string)($file['tmp_name']??'');
    if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('The uploaded video chunk could not be verified.');
    $chunkSize=(int)($file['size']??0);
    if($chunkSize<=0||$chunkSize>2*1024*1024)throw new RuntimeException('Video upload chunk is invalid.');

    $dir=tagobs_staging_dir($org,$userId);
    if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Could not create temporary Tag Scouting upload storage.');
    $part=$dir.'/'.$token.'.part';
    $metaPath=$dir.'/'.$token.'.json';

    if($chunkIndex===0){
        @unlink($part);@unlink($metaPath);
        $meta=[
            'token'=>$token,
            'organization_id'=>$org,
            'user_id'=>$userId,
            'original_filename'=>mb_substr($originalName!==''?$originalName:'video',0,255),
            'claimed_type'=>mb_substr($claimedType,0,100),
            'total_size'=>$totalSize,
            'total_chunks'=>$totalChunks,
            'next_index'=>0,
            'complete'=>false,
            'created_at'=>time(),
        ];
    }else{
        $raw=is_file($metaPath)?file_get_contents($metaPath):false;
        $meta=$raw!==false?json_decode($raw,true):null;
        if(!is_array($meta))throw new RuntimeException('Video upload session expired. Please select the video again.');
        if((int)($meta['organization_id']??0)!==$org||(int)($meta['user_id']??0)!==$userId)throw new RuntimeException('Video upload session does not belong to this account.');
        if((int)($meta['total_size']??0)!==$totalSize||(int)($meta['total_chunks']??0)!==$totalChunks)throw new RuntimeException('Video upload metadata changed during upload.');
    }

    $expected=(int)($meta['next_index']??0);
    if($chunkIndex<$expected){
        clearstatcache(true,$part);
        return [
            'token'=>$token,
            'complete'=>!empty($meta['complete']),
            'received_bytes'=>(int)(@filesize($part)?:0),
            'total_size'=>$totalSize,
            'next_index'=>$expected,
            'duplicate'=>true,
        ];
    }
    if($chunkIndex>$expected)throw new RuntimeException('Video upload chunk arrived out of order. Please retry the upload.');

    $in=@fopen($tmp,'rb');
    $out=@fopen($part,$chunkIndex===0?'wb':'ab');
    if(!$in||!$out){
        if(is_resource($in))fclose($in);
        if(is_resource($out))fclose($out);
        throw new RuntimeException('Could not write the temporary video upload.');
    }
    if(!flock($out,LOCK_EX)){fclose($in);fclose($out);throw new RuntimeException('Could not lock the temporary video upload.');}
    $written=stream_copy_to_stream($in,$out);
    fflush($out);flock($out,LOCK_UN);fclose($in);fclose($out);
    if($written===false||(int)$written!==$chunkSize)throw new RuntimeException('Video upload chunk was incomplete.');

    clearstatcache(true,$part);
    $current=(int)(@filesize($part)?:0);
    if($current>$totalSize||$current>100*1024*1024){
        @unlink($part);@unlink($metaPath);
        throw new RuntimeException('Video upload exceeded the 100 MB limit.');
    }

    $meta['next_index']=$chunkIndex+1;
    $complete=$meta['next_index']===$totalChunks;
    if($complete){
        clearstatcache(true,$part);
        $current=(int)(@filesize($part)?:0);
        if($current!==$totalSize){
            @unlink($part);@unlink($metaPath);
            throw new RuntimeException('Video upload size did not match the selected file. Please retry.');
        }
        $mime=tagobs_detect_mime($part);
        $videoMimes=['video/mp4','video/quicktime','video/webm','video/x-m4v','video/3gpp'];
        if(!in_array($mime,$videoMimes,true)){
            @unlink($part);@unlink($metaPath);
            throw new RuntimeException('Tag Scouting accepts MP4, MOV, WebM, M4V, or 3GP video files.');
        }
        $meta['complete']=true;
        $meta['mime_type']=$mime;
        @chmod($part,0640);
    }

    if(file_put_contents($metaPath,json_encode($meta,JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Could not save video upload state.');
    @chmod($metaPath,0640);
    return ['token'=>$token,'complete'=>$complete,'received_bytes'=>$current,'total_size'=>$totalSize,'next_index'=>$meta['next_index']];
}

function tagobs_store_staged_video(string $token,int $org,int $userId,int $observationId,int $team): array {
    if(!tagobs_staging_token_valid($token))throw new RuntimeException('Invalid staged video token.');
    $dir=tagobs_staging_dir($org,$userId);
    $part=$dir.'/'.$token.'.part';
    $metaPath=$dir.'/'.$token.'.json';
    $raw=is_file($metaPath)?file_get_contents($metaPath):false;
    $meta=$raw!==false?json_decode($raw,true):null;
    if(!is_array($meta)||empty($meta['complete'])||!is_file($part))throw new RuntimeException('The staged video upload is incomplete or expired.');
    if((int)($meta['organization_id']??0)!==$org||(int)($meta['user_id']??0)!==$userId)throw new RuntimeException('The staged video upload does not belong to this account.');
    if((int)($meta['created_at']??0)<time()-21600)throw new RuntimeException('The staged video upload expired. Please upload it again.');

    $size=(int)(@filesize($part)?:0);
    if($size<=0||$size>100*1024*1024||$size!==(int)($meta['total_size']??0))throw new RuntimeException('The staged video upload is invalid.');
    $mime=tagobs_detect_mime($part);
    $ext=match($mime){
        'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm','video/x-m4v'=>'m4v','video/3gpp'=>'3gp',default=>''
    };
    if($ext==='')throw new RuntimeException('Tag Scouting accepts MP4, MOV, WebM, M4V, or 3GP video files.');

    $root=tagobs_secure_media_root();
    $relDir='org-'.$org.'/'.date('Y').'/'.date('m');
    $destDir=$root.'/'.$relDir;
    if(!is_dir($destDir)&&!mkdir($destDir,0750,true)&&!is_dir($destDir))throw new RuntimeException('Could not create Tag Scouting media storage.');
    $name='tag-'.$observationId.'-team-'.$team.'-'.bin2hex(random_bytes(8)).'.'.$ext;
    $dest=$destDir.'/'.$name;
    if(!@rename($part,$dest))throw new RuntimeException('Could not finalize the uploaded video.');
    @chmod($dest,0640);
    @unlink($metaPath);

    return [
        'media_type'=>'video',
        'storage_relpath'=>$relDir.'/'.$name,
        'original_filename'=>mb_substr((string)($meta['original_filename']??'video'),0,255),
        'mime_type'=>$mime,
        'file_size'=>(int)filesize($dest),
        'width'=>null,'height'=>null,'duration_seconds'=>null,
    ];
}

function tagobs_delete_stored_relpath(string $rel): void {
    $rel=ltrim(str_replace('\\','/',$rel),'/');
    if($rel===''||str_contains($rel,'../'))return;
    $root=realpath(tagobs_secure_media_root());
    if(!$root)return;
    $path=realpath($root.'/'.$rel);
    if($path && str_starts_with($path,$root.DIRECTORY_SEPARATOR) && is_file($path))@unlink($path);
}
