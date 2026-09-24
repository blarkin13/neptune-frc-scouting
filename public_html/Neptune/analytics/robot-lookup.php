<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once __DIR__.'/_augur_epa.php';


$u=require_login();
$org=(int)$u['organization_id'];
$pageTitle='Robot Lookup';
$moduleName='AUGUR';

function rl_decode(?string $json): array {
    if(!$json) return [];
    $v=json_decode($json,true);
    return is_array($v)?$v:[];
}
function rl_table_exists(PDO $pdo,string $table): bool {
    static $cache=[];
    if(array_key_exists($table,$cache)) return $cache[$table];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $q->execute([$table]);
    return $cache[$table]=((int)$q->fetchColumn()>0);
}
function rl_label(string $key): string {
    $key=preg_replace('/[_-]+/',' ',$key);
    return ucwords(trim((string)$key));
}
function rl_value(mixed $value): string {
    if(is_bool($value)) return $value?'Yes':'No';
    if($value===null || $value==='') return '—';
    if(is_array($value)){
        if(!$value) return '—';
        $flat=[];
        foreach($value as $k=>$v){
            if(is_scalar($v) || $v===null) $flat[]=(string)$v;
            else $flat[]=json_encode($v,JSON_UNESCAPED_SLASHES);
        }
        return implode(', ',$flat);
    }
    return (string)$value;
}

function rl_is_auton_path_field(string $key): bool {
    $k=strtolower(trim(preg_replace('/[^a-z0-9]+/','_',trim($key))??'','_'));
    return in_array($k,['auton_path','auto_path','autonomous_path'],true);
}

function rl_auton_path_strokes(mixed $value): array {
    if(is_string($value)){
        $decoded=json_decode(trim($value),true);
        if(json_last_error()===JSON_ERROR_NONE) $value=$decoded;
    }
    if(!is_array($value)) return [];

    if(isset($value['strokes']) && is_array($value['strokes'])){
        $value=$value['strokes'];
    } elseif(array_is_list($value) && isset($value[0],$value[1]) && is_numeric($value[0]) && is_array($value[1])){
        $value=$value[1];
    }

    $strokes=[];
    foreach($value as $stroke){
        if(!is_array($stroke)) continue;
        if(isset($stroke[0]) && is_array($stroke[0]) && !isset($stroke[0]['x']) && array_is_list($stroke)){
            foreach($stroke as $nested){
                if(is_array($nested)) $strokes[]=$nested;
            }
        } else {
            $strokes[]=$stroke;
        }
    }

    $clean=[];
    foreach($strokes as $stroke){
        $points=[];
        foreach($stroke as $p){
            if(!is_array($p) || !isset($p['x'],$p['y']) || !is_numeric($p['x']) || !is_numeric($p['y'])) continue;
            $points[]=[
                'x'=>max(0.0,min(1.0,(float)$p['x'])),
                'y'=>max(0.0,min(1.0,(float)$p['y'])),
            ];
        }
        if($points) $clean[]=$points;
    }
    return $clean;
}

function rl_auton_field_url(array $row,int $org): string {
    $gameId=(int)($row['game_id']??0);
    if($gameId<=0) return '';
    $rel=neptune_revision_field_path($row,$gameId,$org);
    return $rel!==''?base_url($rel):'';
}

function rl_auton_path_visual(mixed $value,string $fieldUrl,string $label): string {
    $strokes=rl_auton_path_strokes($value);
    if(!$strokes) return '<span class="muted">Recorded path</span>';

    $pointCount=0;
    foreach($strokes as $stroke) $pointCount+=count($stroke);

    if($fieldUrl===''){
        return '<span>Recorded path · '.number_format($pointCount).' points</span>'
            .'<small class="robot-lookup-auton-caption">Field image unavailable.</small>';
    }

    $svg='<svg class="robot-lookup-auton-svg" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">';
    foreach($strokes as $stroke){
        $coords=[];
        foreach($stroke as $p){
            $coords[]=number_format($p['x']*1000,2,'.','').','.number_format($p['y']*1000,2,'.','');
        }
        if(count($coords)>=2){
            $svg.='<polyline points="'.htmlspecialchars(implode(' ',$coords),ENT_QUOTES,'UTF-8').'"/>';
        }
        $first=$stroke[0];
        $last=$stroke[count($stroke)-1];
        $svg.='<circle class="auton-start" cx="'.number_format($first['x']*1000,2,'.','').'" cy="'.number_format($first['y']*1000,2,'.','').'" r="13"/>';
        $svg.='<circle class="auton-end" cx="'.number_format($last['x']*1000,2,'.','').'" cy="'.number_format($last['y']*1000,2,'.','').'" r="13"/>';
    }
    $svg.='</svg>';

    return '<div class="robot-lookup-auton-mini" title="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'">'
        .'<img src="'.htmlspecialchars($fieldUrl,ENT_QUOTES,'UTF-8').'" alt="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'" loading="lazy">'
        .$svg
        .'</div><small class="robot-lookup-auton-caption">'.count($strokes).' path stroke'.(count($strokes)===1?'':'s').' · '.number_format($pointCount).' points</small>';
}

function rl_has_value(mixed $value): bool {
    if($value===null || $value==='') return false;
    if(is_array($value) && !$value) return false;
    return true;
}
function rl_location(array $team): string {
    return implode(', ',array_values(array_filter([
        trim((string)($team['city']??'')),
        trim((string)($team['state_prov']??'')),
        trim((string)($team['country']??'')),
    ],static fn($v)=>$v!=='')));
}
function rl_pct(float|int $n,float|int $d): string {
    return $d>0?number_format(($n/$d)*100,1).'%':'—';
}
function rl_num(float|int|string|null $n,int $dec=1): string {
    if($n===null || $n==='') return '—';
    return number_format((float)$n,$dec);
}
function rl_percentile(mixed $value): string {
    if($value===null || $value==='') return '—';
    $n=(float)$value;
    if($n<=1.0) $n*=100;
    return number_format($n,1).'%';
}
function rl_https_url(mixed $value): string {
    $url=trim((string)$value);
    if($url==='') return '';
    $parts=parse_url($url);
    if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https') return '';
    return $url;
}
function rl_avatar_src(mixed $value): string {
    $b64=preg_replace('/\s+/','',trim((string)$value));
    if($b64==='' || strlen($b64)>3000000) return '';
    if(!preg_match('/^[A-Za-z0-9+\/=]+$/',$b64)) return '';
    if(base64_decode($b64,true)===false) return '';
    return 'data:image/png;base64,'.$b64;
}
function rl_media_image(array $media): array {
    $type=(string)($media['type']??'');
    $details=is_array($media['details']??null)?$media['details']:[];
    $src='';
    $label='';
    $view=rl_https_url($media['view_url']??'');

    if($type==='smugmug-photo'){
        $src=rl_https_url($details['image_url_med']??($details['image_url']??''));
        $label=(string)($details['title']??($details['caption']??'SmugMug photo'));
        if($view==='') $view=rl_https_url($details['web_uri']??'');
    } elseif($type==='smugmug-album'){
        $src=rl_https_url($details['cover_url_med']??($details['cover_url']??''));
        $label=(string)($details['title']??'SmugMug gallery');
        if($view==='') $view=rl_https_url($details['web_uri']??'');
    } elseif($type==='grabcad' || $type==='onshape'){
        $src=rl_https_url($details['model_image']??'');
        $label=(string)($details['model_name']??strtoupper($type).' model');
    } elseif($type==='cd-thread'){
        $src=rl_https_url($details['image_url']??'');
        $label=(string)($details['thread_title']??'Chief Delphi image');
    } elseif(in_array($type,['imgur','instagram-image','cdphotothread'],true)){
        $src=rl_https_url($media['direct_url']??'');
        $label=match($type){
            'imgur'=>'Imgur image',
            'instagram-image'=>'Instagram image',
            default=>'Chief Delphi image',
        };
    }

    return ['src'=>$src,'view'=>$view,'label'=>$label,'type'=>$type];
}
function rl_search_norm(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/',' ', $value)??'';
    return trim(preg_replace('/\\s+/',' ', $value)??'');
}
function rl_team_search_score(array $team,string $query): int {
    $q=rl_search_norm($query);
    if($q==='') return 0;

    $nickname=rl_search_norm((string)($team['nickname']??''));
    $name=rl_search_norm((string)($team['name']??''));
    $school=rl_search_norm((string)($team['school_name']??''));

    $score=0;
    foreach([
        [$nickname,1000,850,700],
        [$name,900,760,620],
        [$school,650,520,420],
    ] as [$field,$exact,$starts,$contains]){
        if($field==='') continue;
        if($field===$q) $score=max($score,$exact);
        elseif(str_starts_with($field,$q)) $score=max($score,$starts);
        elseif(str_contains($field,$q)) $score=max($score,$contains);
    }

    if($score===0 && strlen($q)>=5){
        foreach([$nickname,$name] as $field){
            if($field==='') continue;
            similar_text($q,$field,$pct);
            if($pct>=72) $score=max($score,(int)round($pct*4));
        }
    }
    return $score;
}
function rl_tba_name_search(string $query,int $year,int $limit=20): array {
    $matches=[];
    $seen=[];

    // TBA exposes the season team directory in 500-team pages. tba_get()
    // provides the existing Neptune cache, so later name searches are fast.
    for($page=0;$page<32;$page++){
        $rows=tba_get("teams/{$year}/{$page}/simple",86400);
        if(!is_array($rows) || !$rows) break;

        foreach($rows as $team){
            if(!is_array($team)) continue;
            $num=(int)($team['team_number']??0);
            if($num<=0 || isset($seen[$num])) continue;

            $score=rl_team_search_score($team,$query);
            if($score<=0) continue;

            $seen[$num]=true;
            $matches[]=[
                'frc_team_number'=>$num,
                'nickname'=>$team['nickname']??$team['name']??'',
                'name'=>$team['name']??'',
                'city'=>$team['city']??'',
                'state_prov'=>$team['state_prov']??'',
                'country'=>$team['country']??'',
                '_source'=>'tba',
                '_year'=>$year,
                '_score'=>$score,
            ];
        }

        // An exact nickname/full-name match is unambiguous enough to stop.
        $exact=array_values(array_filter($matches,static fn($m)=>(int)($m['_score']??0)>=900));
        if(count($exact)===1) return $exact;
        if(count($matches)>=$limit) break;
    }

    usort($matches,static function($a,$b){
        $cmp=((int)($b['_score']??0))<=>((int)($a['_score']??0));
        if($cmp!==0) return $cmp;
        return ((int)$a['frc_team_number'])<=>((int)$b['frc_team_number']);
    });
    return array_slice($matches,0,$limit);
}

$q=trim((string)($_GET['q']??''));
$team=(int)($_GET['team']??0);
$suggestions=[];
$error='';
$tbaError='';

if($team<=0 && $q!==''){
    if(preg_match('/(?:frc\s*)?#?\s*(\d{1,6})/i',$q,$m)){
        $team=(int)$m[1];
    } else {
        $like='%'.$q.'%';

        // Search data Neptune already knows.
        $s=$pdo->prepare(
            "SELECT DISTINCT et.frc_team_number,et.nickname,et.city,et.state_prov,et.country
             FROM event_teams et
             JOIN events e ON e.id=et.event_id
             WHERE e.organization_id=? AND et.nickname LIKE ?
             ORDER BY et.nickname,et.frc_team_number
             LIMIT 20"
        );
        $s->execute([$org,$like]);
        foreach($s->fetchAll() as $row){
            $row['_source']='neptune';
            $suggestions[(int)$row['frc_team_number']]=$row;
        }

        $s=$pdo->prepare(
            "SELECT frc_team_number,tba_json
             FROM robot_season_profiles
             WHERE organization_id=? AND tba_json LIKE ?
             ORDER BY updated_at DESC
             LIMIT 40"
        );
        $s->execute([$org,$like]);
        foreach($s->fetchAll() as $row){
            $tj=rl_decode($row['tba_json']);
            $ti=is_array($tj['team']??null)?$tj['team']:[];
            $hay=strtolower(implode(' ',[
                (string)($ti['nickname']??''),
                (string)($ti['name']??''),
                (string)($ti['school_name']??''),
            ]));
            if($hay!=='' && str_contains($hay,strtolower($q))){
                $num=(int)$row['frc_team_number'];
                if(!isset($suggestions[$num])){
                    $suggestions[$num]=[
                        'frc_team_number'=>$num,
                        'nickname'=>$ti['nickname']??$ti['name']??'',
                        'name'=>$ti['name']??'',
                        'city'=>$ti['city']??'',
                        'state_prov'=>$ti['state_prov']??'',
                        'country'=>$ti['country']??'',
                        '_source'=>'neptune',
                    ];
                }
            }
        }

        // Also search The Blue Alliance's current-season directory, so a team
        // does not need to be in one of this organization's rosters first.
        try{
            $searchYear=(int)date('Y');
            foreach(rl_tba_name_search($q,$searchYear,20) as $candidate){
                $num=(int)$candidate['frc_team_number'];
                if($num<=0) continue;
                if(isset($suggestions[$num])) $candidate['_source']='neptune+tba';
                $suggestions[$num]=array_merge($suggestions[$num]??[],$candidate);
            }
        } catch(Throwable $ex){
            $tbaError=$ex->getMessage();
        }

        if(count($suggestions)===1){
            $team=(int)array_key_first($suggestions);
        } elseif($suggestions){
            uasort($suggestions,static function($a,$b){
                $cmp=((int)($b['_score']??0))<=>((int)($a['_score']??0));
                if($cmp!==0) return $cmp;
                return ((int)$a['frc_team_number'])<=>((int)$b['frc_team_number']);
            });
            $suggestions=array_slice($suggestions,0,20,true);
        }
    }
}

$tbaTeam=[];
$seasonProfiles=[];
$pitRows=[];
$preRows=[];
$eventSummary=[];
$actionBreakdown=[];
$pitPhotosByScout=[];
$knownNames=[];
$tbaAvatar='';
$tbaMedia=[];
$tbaMediaYears=[];
$epaSeasonRating=null;
$epaYears=[];
$epaYear=0;
$epaLastEvent=null;
$spotRows=[];
$spotMedia=[];

if($team>0){
    // Local season profiles provide a fallback if TBA is temporarily unavailable.
    $s=$pdo->prepare(
        "SELECT rsp.*,g.name game_name,g.season_year,e.name last_event_name
         FROM robot_season_profiles rsp
         JOIN games g ON g.id=rsp.game_id
         LEFT JOIN events e ON e.id=rsp.last_event_id
         WHERE rsp.organization_id=? AND rsp.frc_team_number=?
         ORDER BY g.season_year DESC,rsp.updated_at DESC"
    );
    $s->execute([$org,$team]);
    $seasonProfiles=$s->fetchAll();

    foreach($seasonProfiles as $profile){
        $tj=rl_decode($profile['tba_json']);
        if(!$tbaTeam && is_array($tj['team']??null)) $tbaTeam=$tj['team'];
    }

    // Public EPA is read entirely from Neptune's local AUGUR-managed EPA archive.
    // Robot Lookup never waits on Statbotics or triggers a TBA EPA rebuild.
    $epaYears=[(int)date('Y')];
    foreach($seasonProfiles as $profile){
        $y=(int)($profile['season_year']??0);
        if($y>0) $epaYears[]=$y;
    }

    if(augur_epa_tables_ready($pdo)){
        $s=$pdo->prepare("SELECT DISTINCT season_year
            FROM augur_epa_season_ratings
            WHERE frc_team_number=?
            ORDER BY season_year DESC");
        $s->execute([$team]);
        foreach($s->fetchAll(PDO::FETCH_COLUMN) as $y){
            $y=(int)$y;
            if($y>0) $epaYears[]=$y;
        }
    }

    $epaYears=array_values(array_unique(array_filter(
        $epaYears,
        static fn($y)=>$y>=1992 && $y<=((int)date('Y')+1)
    )));
    rsort($epaYears);

    $requestedEpaYear=(int)($_GET['epa_year']??0);
    $epaYear=($requestedEpaYear>0 && in_array($requestedEpaYear,$epaYears,true))
        ? $requestedEpaYear
        : ($epaYears[0]??(int)date('Y'));

    if(augur_epa_tables_ready($pdo)){
        $epaSeasonRating=augur_epa_season_rating($pdo,$epaYear,$team);
        if($epaSeasonRating && !empty($epaSeasonRating['last_event_key'])){
            $s=$pdo->prepare("SELECT tba_event_key,name,short_name,start_date,end_date
                FROM augur_epa_archive_events
                WHERE tba_event_key=?
                LIMIT 1");
            $s->execute([(string)$epaSeasonRating['last_event_key']]);
            $epaLastEvent=$s->fetch()?:null;
        }
    }

    try{
        $live=tba_get("team/frc{$team}",3600);
        if(is_array($live) && !empty($live['team_number'])) $tbaTeam=$live;
    } catch(Throwable $ex){
        $tbaError=$ex->getMessage();
    }

    // TBA media is season-specific. Check the current season plus the two
    // previous seasons so an available team avatar or recent robot image can
    // still be shown when the current season has not published media yet.
    $currentYear=(int)date('Y');
    $years=[$currentYear,$currentYear-1,$currentYear-2];
    foreach($seasonProfiles as $profile){
        $y=(int)($profile['season_year']??0);
        if($y>0) $years[]=$y;
    }
    $years=array_values(array_unique(array_filter($years,static fn($y)=>$y>=1992 && $y<=((int)date('Y')+1))));
    rsort($years);
    $years=array_slice($years,0,3);

    $seenMedia=[];
    foreach($years as $year){
        try{
            $mediaRows=tba_get("team/frc{$team}/media/{$year}",21600);
            if(!is_array($mediaRows)) continue;

            foreach($mediaRows as $media){
                if(!is_array($media)) continue;

                if($tbaAvatar==='' && ($media['type']??'')==='avatar'){
                    $candidate=rl_avatar_src($media['details']['base64Image']??'');
                    if($candidate!==''){
                        $tbaAvatar=$candidate;
                        $tbaMediaYears[]=$year;
                    }
                    continue;
                }

                $img=rl_media_image($media);
                if($img['src']==='') continue;
                if(isset($seenMedia[$img['src']])) continue;

                $seenMedia[$img['src']]=true;
                $img['year']=$year;
                $img['preferred']=!empty($media['preferred']);
                $tbaMedia[]=$img;
                $tbaMediaYears[]=$year;
            }
        } catch(Throwable $ex){
            // Media is optional. A missing year or transient TBA error should
            // never prevent the rest of the robot intelligence page loading.
        }
    }

    usort($tbaMedia,static function($a,$b){
        $pref=((int)!empty($b['preferred']))<=>((int)!empty($a['preferred']));
        if($pref!==0) return $pref;
        return ((int)$b['year'])<=>((int)$a['year']);
    });
    $tbaMedia=array_slice($tbaMedia,0,8);
    $tbaMediaYears=array_values(array_unique($tbaMediaYears));
    rsort($tbaMediaYears);

    // If local data knows a friendlier nickname, keep it available.
    $s=$pdo->prepare(
        "SELECT et.nickname,MAX(e.start_date) AS latest_start
         FROM event_teams et
         JOIN events e ON e.id=et.event_id
         WHERE e.organization_id=? AND et.frc_team_number=? AND et.nickname IS NOT NULL AND et.nickname<>''
         GROUP BY et.nickname
         ORDER BY latest_start DESC,et.nickname
         LIMIT 5"
    );
    $s->execute([$org,$team]);
    $knownNames=$s->fetchAll(PDO::FETCH_COLUMN);

    // Match scouting: include this organization's analytics plus active shared analytics.
    $scopeActions="
      (
        a.organization_id=?
        OR a.owner_team_id IN (
          SELECT sr.owner_team_id
          FROM sharing_relationships sr
          JOIN teams rt ON rt.id=sr.recipient_team_id
          WHERE rt.organization_id=?
            AND sr.status='active'
            AND sr.share_analytics=1
            AND (sr.starts_at IS NULL OR sr.starts_at<=UTC_TIMESTAMP())
            AND (sr.expires_at IS NULL OR sr.expires_at>=UTC_TIMESTAMP())
        )
      )";

    $sql="SELECT
            a.game_id,a.event_id,g.name game_name,g.season_year,e.name event_name,e.start_date,
            COUNT(*) action_count,
            COUNT(DISTINCT a.match_id) match_count,
            SUM(CASE WHEN a.result='Success' THEN 1 ELSE 0 END) success_count,
            SUM(CASE WHEN a.result='Failure' THEN 1 ELSE 0 END) failure_count,
            COALESCE(SUM(a.points),0) total_points,
            COALESCE(SUM(CASE WHEN a.phase='auton' THEN a.points ELSE 0 END),0) auton_points,
            COALESCE(SUM(CASE WHEN a.phase='teleop' THEN a.points ELSE 0 END),0) teleop_points,
            COALESCE(SUM(CASE WHEN a.phase='endgame' THEN a.points ELSE 0 END),0) endgame_points
          FROM scouting_actions a
          JOIN events e ON e.id=a.event_id
          JOIN games g ON g.id=a.game_id
          WHERE a.deleted_at IS NULL
            AND a.frc_team_number=?
            AND {$scopeActions}
          GROUP BY a.game_id,a.event_id,g.name,g.season_year,e.name,e.start_date
          ORDER BY g.season_year DESC,COALESCE(e.start_date,'1900-01-01') DESC,e.name";
    $s=$pdo->prepare($sql);
    $s->execute([$team,$org,$org]);
    $eventSummary=$s->fetchAll();

    $sql="SELECT
            a.game_id,a.event_id,a.action_code,
            COALESCE(MAX(NULLIF(a.action_name,'')),a.action_code) action_name,
            COUNT(*) attempts,
            SUM(CASE WHEN a.result='Success' THEN 1 ELSE 0 END) success_count,
            SUM(CASE WHEN a.result='Failure' THEN 1 ELSE 0 END) failure_count,
            COALESCE(SUM(a.points),0) points
          FROM scouting_actions a
          WHERE a.deleted_at IS NULL
            AND a.frc_team_number=?
            AND {$scopeActions}
          GROUP BY a.game_id,a.event_id,a.action_code
          ORDER BY a.game_id DESC,a.event_id DESC,points DESC,attempts DESC";
    $s=$pdo->prepare($sql);
    $s->execute([$team,$org,$org]);
    foreach($s->fetchAll() as $row){
        $key=(int)$row['game_id'].':'.(int)$row['event_id'];
        $actionBreakdown[$key][]=$row;
    }

    // Pit data: include local rows plus rows explicitly shared for pit access.
    $s=$pdo->prepare(
        "SELECT ps.*,e.name event_name,e.start_date,e.game_id,e.game_revision_id,g.name game_name,g.season_year,gr.field_image_path
         FROM pit_scouting ps
         JOIN events e ON e.id=ps.event_id
         JOIN games g ON g.id=e.game_id
         LEFT JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
         WHERE ps.frc_team_number=?
           AND (
             ps.organization_id=?
             OR ps.owner_team_id IN (
               SELECT sr.owner_team_id
               FROM sharing_relationships sr
               JOIN teams rt ON rt.id=sr.recipient_team_id
               WHERE rt.organization_id=?
                 AND sr.status='active'
                 AND sr.share_pit_data=1
                 AND (sr.starts_at IS NULL OR sr.starts_at<=UTC_TIMESTAMP())
                 AND (sr.expires_at IS NULL OR sr.expires_at>=UTC_TIMESTAMP())
             )
           )
         ORDER BY g.season_year DESC,COALESCE(e.start_date,'1900-01-01') DESC,ps.updated_at DESC"
    );
    $s->execute([$team,$org,$org]);
    $pitRows=$s->fetchAll();

    if($pitRows){
        $ids=array_map(static fn($r)=>(int)$r['id'],$pitRows);
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare(
            "SELECT pit_scouting_id,category,file_path,caption
             FROM pit_scouting_photos
             WHERE organization_id=? AND pit_scouting_id IN ({$ph})
             ORDER BY pit_scouting_id,created_at"
        );
        $s->execute(array_merge([$org],$ids));
        foreach($s->fetchAll() as $photo){
            $pitPhotosByScout[(int)$photo['pit_scouting_id']][]=$photo;
        }
    }

    // Pre-scout records are organization-scoped because that table has no owner_team_id sharing key.
    $s=$pdo->prepare(
        "SELECT p.*,e.name event_name,e.start_date,e.game_id event_game_id,e.game_revision_id,g.name game_name,g.season_year,gr.field_image_path
         FROM pre_scouting p
         JOIN events e ON e.id=p.event_id
         JOIN games g ON g.id=p.game_id
         LEFT JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
         WHERE p.organization_id=? AND p.frc_team_number=?
         ORDER BY g.season_year DESC,COALESCE(e.start_date,'1900-01-01') DESC,p.updated_at DESC"
    );
    $s->execute([$org,$team]);
    $preRows=$s->fetchAll();

    // Spot Scouting is intentionally organization-private and remains separate
    // from public EPA. Show the latest human observations for strategy review.
    if(rl_table_exists($pdo,'spot_observations') && rl_table_exists($pdo,'spot_tags') && rl_table_exists($pdo,'spot_observation_tags') && rl_table_exists($pdo,'spot_observation_media')){
        $s=$pdo->prepare("SELECT o.*,e.name event_name,m.comp_level,m.set_number,m.match_number,u.display_name scout_name,ru.display_name resolved_by_name,
            GROUP_CONCAT(DISTINCT CONCAT(t.label,'||',t.icon,'||',t.severity) ORDER BY t.category,t.sort_order,t.label SEPARATOR '~~') tag_blob,
            COUNT(DISTINCT med.id) media_count
          FROM spot_observations o
          LEFT JOIN events e ON e.id=o.event_id
          LEFT JOIN matches m ON m.id=o.match_id
          LEFT JOIN users u ON u.id=o.created_by
          LEFT JOIN users ru ON ru.id=o.resolved_by
          LEFT JOIN spot_observation_tags ot ON ot.observation_id=o.id
          LEFT JOIN spot_tags t ON t.id=ot.tag_id
          LEFT JOIN spot_observation_media med ON med.observation_id=o.id
          WHERE o.organization_id=? AND o.frc_team_number=?
          GROUP BY o.id
          ORDER BY o.created_at DESC,o.id DESC
          LIMIT 30");
        $s->execute([$org,$team]);
        $spotRows=$s->fetchAll();
        if($spotRows && rl_table_exists($pdo,'spot_observation_media')){
            $ids=array_map('intval',array_column($spotRows,'id'));
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $s=$pdo->prepare("SELECT id,observation_id,media_type,original_filename FROM spot_observation_media WHERE organization_id=? AND observation_id IN ({$ph}) ORDER BY observation_id,id");
            $s->execute(array_merge([$org],$ids));
            foreach($s->fetchAll() as $media)$spotMedia[(int)$media['observation_id']][]=$media;
        }
    }
}

$displayName=(string)($tbaTeam['nickname']??($knownNames[0]??''));
$fullName=(string)($tbaTeam['name']??'');
$location=rl_location($tbaTeam);

include dirname(__DIR__).'/partials_header.php';
?>
<style>
.robot-lookup-search{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:end}
.robot-lookup-hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(260px,.8fr);gap:16px}
.robot-lookup-title{font-size:clamp(1.9rem,4vw,3.2rem);margin:0}
.robot-lookup-sub{font-size:1rem;margin-top:5px}
.robot-lookup-identity{display:grid;grid-template-columns:auto minmax(0,1fr);gap:16px;align-items:center}
.robot-lookup-avatar{width:104px;height:104px;object-fit:contain;border-radius:12px;border:1px solid var(--line);background:#fff;padding:6px}
.robot-lookup-tba-note{font-size:.72rem;color:var(--muted);margin-top:8px}
.robot-lookup-tba-media{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.robot-lookup-tba-media-card{display:block;text-decoration:none;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel2)}
.robot-lookup-tba-media-card img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:#fff}
.robot-lookup-tba-media-card span{display:block;padding:7px 9px;font-size:.75rem}
.robot-lookup-stat-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.robot-lookup-stat-ranks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}
.robot-lookup-stat-rank{padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}
.robot-lookup-stat-rank b{display:block;font-size:1.15rem}
.robot-lookup-stat-rank small{color:var(--muted)}
.robot-lookup-stat-details summary{cursor:pointer;font-weight:800}

.robot-lookup-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}
.robot-lookup-kpi{padding:12px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}
.robot-lookup-kpi b{display:block;font-size:1.25rem}
.robot-lookup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.robot-lookup-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px}
.robot-lookup-field{padding:8px 0;border-bottom:1px solid var(--line)}
.robot-lookup-field.auton-path-field{grid-column:1/-1}
.robot-lookup-auton-mini{position:relative;width:min(100%,300px);max-width:300px;margin-top:5px;border:1px solid var(--line);border-radius:7px;overflow:hidden;background:var(--panel2);line-height:0}
.robot-lookup-auton-mini img{display:block;width:100%;height:auto;max-height:160px;object-fit:fill}
.robot-lookup-auton-svg{position:absolute;inset:0;width:100%;height:100%}
.robot-lookup-auton-svg polyline{fill:none;stroke:var(--accent);stroke-width:4;vector-effect:non-scaling-stroke;stroke-linecap:round;stroke-linejoin:round}
.robot-lookup-auton-svg circle{vector-effect:non-scaling-stroke;stroke-width:2}
.robot-lookup-auton-svg .auton-start{fill:var(--good);stroke:#fff}
.robot-lookup-auton-svg .auton-end{fill:var(--bad);stroke:#fff}
.robot-lookup-auton-caption{display:block;margin-top:4px;color:var(--muted);font-size:.66rem;line-height:1.2}
.robot-lookup-field b{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:3px}
.robot-lookup-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-top:10px}
.robot-lookup-photo{display:block;border:1px solid var(--line);border-radius:7px;overflow:hidden;background:var(--panel2)}
.robot-lookup-photo img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}
.robot-lookup-photo span{display:block;padding:6px 8px;font-size:.72rem}
.robot-lookup-event{margin-top:12px}
.robot-lookup-event:first-child{margin-top:0}
.robot-lookup-table td,.robot-lookup-table th{white-space:nowrap}
.robot-lookup-suggestions{display:grid;gap:8px;margin-top:10px}
.robot-lookup-suggestion{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--line);border-radius:7px;text-decoration:none}
.robot-lookup-suggestion:hover{background:var(--panel2)}
.robot-lookup-suggestion-main{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.robot-lookup-source{font-size:.68rem;font-weight:850;letter-spacing:.04em;text-transform:uppercase}
.robot-lookup-empty{padding:24px;text-align:center}
.robot-lookup-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.robot-lookup-section-title h2{margin:0}
.robot-lookup-chip-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.robot-lookup-spot-list{display:grid;gap:9px}.robot-lookup-spot{padding:11px;border:1px solid var(--line);border-radius:8px;background:var(--panel2)}.robot-lookup-spot-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.robot-lookup-spot-meta{font-size:.7rem;color:var(--muted);margin-top:2px}.robot-lookup-spot-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}.robot-lookup-spot-note{margin-top:7px;line-height:1.45}.robot-lookup-spot-media{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px}.robot-lookup-spot-media a{font-size:.72rem}.robot-lookup-spot.resolved{opacity:.67}
@media(max-width:900px){
  .robot-lookup-hero,.robot-lookup-grid{grid-template-columns:1fr}
  .robot-lookup-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:600px){
  .robot-lookup-search{grid-template-columns:1fr}
  .robot-lookup-fields{grid-template-columns:1fr}
  .robot-lookup-kpis{grid-template-columns:1fr 1fr}
  .robot-lookup-identity{grid-template-columns:1fr}
  .robot-lookup-avatar{width:88px;height:88px}
  .robot-lookup-stat-ranks{grid-template-columns:1fr}
}
</style>

<section class="module-page">
  <header class="module-page-header" style="margin-bottom:16px">
    <div>
      <div class="module-code">AUGUR</div>
      <h1>Robot Lookup</h1>
      <p>Search by FRC team number or team name, then combine TBA identity with Neptune scouting intelligence.</p>
    </div>
    <a class="btn secondary" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Analytics &amp; Strategy</a>
  </header>

  <div class="card">
    <form method="get" class="robot-lookup-search">
      <div>
        <label style="margin-top:0">Team number or name</label>
        <input type="search" name="q" value="<?=e($q!==''?$q:($team>0?(string)$team:''))?>" placeholder="6369 or Mercenaries" autofocus>
      </div>
      <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Look Up Robot</button>
    </form>
  </div>

  <?php if($q!=='' && $team<=0):?>
    <div class="card" style="margin-top:16px">
      <?php if($suggestions):?>
        <div class="robot-lookup-section-title"><h2>Matching Teams</h2><span class="pill"><?=count($suggestions)?> found</span></div>
        <div class="robot-lookup-suggestions">
          <?php foreach($suggestions as $candidate):
            $source=(string)($candidate['_source']??'neptune');
            $sourceLabel=$source==='tba'
                ? 'TBA '.($candidate['_year']??date('Y'))
                : ($source==='neptune+tba'?'Neptune + TBA':'Neptune');
          ?>
            <a class="robot-lookup-suggestion" href="?team=<?=(int)$candidate['frc_team_number']?>">
              <span>
                <span class="robot-lookup-suggestion-main">
                  <b>#<?=e($candidate['frc_team_number'])?> <?=e($candidate['nickname']??'')?></b>
                  <span class="pill robot-lookup-source"><?=e($sourceLabel)?></span>
                </span>
                <span class="muted" style="display:block"><?=e(implode(', ',array_filter([$candidate['city']??'',$candidate['state_prov']??'',$candidate['country']??''])))?></span>
              </span>
              <i class="fa-solid fa-arrow-right"></i>
            </a>
          <?php endforeach;?>
        </div>
      <?php else:?>
        <div class="robot-lookup-empty"><h2>No team-name match</h2><p class="muted">Neptune searched its local data and The Blue Alliance's <?=e(date('Y'))?> team directory. Try another part of the team name or the FRC team number.</p></div>
      <?php endif;?>
    </div>
  <?php endif;?>

  <?php if($team>0):?>
    <div class="robot-lookup-hero" style="margin-top:16px">
      <div class="card">
        <div class="robot-lookup-identity">
          <?php if($tbaAvatar!==''):?>
            <img class="robot-lookup-avatar" src="<?=e($tbaAvatar)?>" alt="<?=e('Team '.$team.' TBA avatar')?>">
          <?php endif;?>
          <div>
            <div class="module-eyebrow"><span>FRC #<?=e($team)?></span><small>Robot Profile</small></div>
            <h1 class="robot-lookup-title">#<?=e($team)?><?= $displayName!=='' ? ' · '.e($displayName) : '' ?></h1>
            <?php if($fullName!=='' && $fullName!==$displayName):?><div class="robot-lookup-sub"><?=e($fullName)?></div><?php endif;?>
            <?php if($location!==''):?><div class="muted" style="margin-top:6px"><i class="fa-solid fa-location-dot"></i> <?=e($location)?></div><?php endif;?>
            <div class="robot-lookup-chip-row">
              <?php if(!empty($tbaTeam['rookie_year'])):?><span class="pill">Rookie <?=e($tbaTeam['rookie_year'])?></span><?php endif;?>
              <?php if(!empty($tbaTeam['school_name'])):?><span class="pill"><?=e($tbaTeam['school_name'])?></span><?php endif;?>
              <?php if(!empty($tbaTeam['motto'])):?><span class="pill"><?=e($tbaTeam['motto'])?></span><?php endif;?>
            </div>
            <div class="toolbar" style="margin-top:12px">
              <?php if(!empty($tbaTeam['website'])):?><a class="btn secondary" href="<?=e($tbaTeam['website'])?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Team Website</a><?php endif;?>
              <a class="btn secondary" href="<?=e('https://www.thebluealliance.com/team/'.$team)?>" target="_blank" rel="noopener"><i class="fa-solid fa-bolt"></i> View on TBA</a>
            </div>
            <div class="robot-lookup-tba-note">Team identity and TBA media are powered by The Blue Alliance.</div>
          </div>
        </div>
        <?php if($tbaError && !$tbaTeam):?><div class="notice" style="margin-top:12px">TBA is unavailable right now. Neptune scouting data is still shown below.</div><?php endif;?>
      </div>

      <div class="card">
        <h2 style="margin-top:0">Neptune Coverage</h2>
        <div class="robot-lookup-kpis">
          <div class="robot-lookup-kpi"><b><?=count($eventSummary)?></b><span class="muted">Scouted events</span></div>
          <div class="robot-lookup-kpi"><b><?=count($pitRows)?></b><span class="muted">Pit reports</span></div>
          <div class="robot-lookup-kpi"><b><?=count($preRows)?></b><span class="muted">Pre-scout reports</span></div>
          <div class="robot-lookup-kpi"><b><?=count($seasonProfiles)?></b><span class="muted">Season profiles</span></div>
        </div>
      </div>
    </div>

    <?php if($tbaMedia):?>
      <div class="card" style="margin-top:16px">
        <div class="robot-lookup-section-title">
          <div>
            <div class="module-eyebrow"><span>TBA MEDIA</span><small><?=e(implode(' · ',$tbaMediaYears))?></small></div>
            <h2>Team &amp; Robot Images</h2>
          </div>
          <a class="btn secondary" href="<?=e('https://www.thebluealliance.com/team/'.$team)?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> The Blue Alliance</a>
        </div>
        <div class="robot-lookup-tba-media">
          <?php foreach($tbaMedia as $media):
            $target=$media['view']!==''?$media['view']:$media['src'];
          ?>
            <a class="robot-lookup-tba-media-card" href="<?=e($target)?>" target="_blank" rel="noopener">
              <img src="<?=e($media['src'])?>" alt="<?=e(($media['label']?:'TBA team image').' · '.$media['year'])?>" loading="lazy">
              <span><b><?=e($media['year'])?></b> · <?=e($media['label']?:'Team media')?><?=!empty($media['preferred'])?' · Preferred':''?></span>
            </a>
          <?php endforeach;?>
        </div>
        <div class="robot-lookup-tba-note">Media is supplied by The Blue Alliance and labeled by season so older robot photos are not mistaken for the current robot.</div>
      </div>
    <?php endif;?>

    <div class="card" style="margin-top:16px">
      <div class="robot-lookup-stat-head">
        <div>
          <div class="module-eyebrow"><span>AUGUR</span><small>Public EPA Archive</small></div>
          <h2 style="margin:0">Public EPA · <?=e($epaYear)?></h2>
          <div class="muted">Public TBA-derived EPA stored locally in Neptune. No Statbotics request is made when this page loads.</div>
        </div>
        <?php if(count($epaYears)>1):?>
          <form method="get" class="toolbar" style="margin:0">
            <input type="hidden" name="team" value="<?=e($team)?>">
            <label style="margin:0">
              <span class="muted" style="display:block;font-size:.72rem;margin-bottom:3px">Season</span>
              <select name="epa_year" onchange="this.form.submit()">
                <?php foreach($epaYears as $y):?>
                  <option value="<?=e($y)?>" <?=$y===$epaYear?'selected':''?>><?=e($y)?></option>
                <?php endforeach;?>
              </select>
            </label>
          </form>
        <?php endif;?>
      </div>

      <?php if($epaSeasonRating):
        $epaTotal=$epaSeasonRating['rating']??null;
        $autoEpa=$epaSeasonRating['auto_rating']??null;
        $teleopEpa=$epaSeasonRating['teleop_rating']??null;
        $endgameEpa=$epaSeasonRating['endgame_rating']??null;
        $wins=(int)($epaSeasonRating['wins']??0);
        $losses=(int)($epaSeasonRating['losses']??0);
        $ties=(int)($epaSeasonRating['ties']??0);
        $decided=$wins+$losses+$ties;
        $winrate=$decided>0?($wins+($ties*0.5))/$decided:null;
        $seasonRank=null;$seasonTeams=0;$seasonPercentile=null;
        if(augur_epa_tables_ready($pdo)){
            $s=$pdo->prepare("SELECT
                1 + SUM(CASE WHEN rating>? THEN 1 ELSE 0 END) AS rank_no,
                COUNT(*) AS team_count
                FROM augur_epa_season_ratings
                WHERE season_year=?");
            $s->execute([(float)$epaTotal,$epaYear]);
            $rankRow=$s->fetch()?:[];
            $seasonRank=isset($rankRow['rank_no'])?(int)$rankRow['rank_no']:null;
            $seasonTeams=(int)($rankRow['team_count']??0);
            if($seasonRank!==null && $seasonTeams>1){
                $seasonPercentile=(1-(($seasonRank-1)/($seasonTeams-1)))*100;
            } elseif($seasonTeams===1){
                $seasonPercentile=100.0;
            }
        }
      ?>
        <div class="robot-lookup-kpis">
          <div class="robot-lookup-kpi"><b><?=rl_num($epaTotal,1)?></b><span class="muted">Public EPA</span></div>
          <div class="robot-lookup-kpi"><b><?=rl_num($autoEpa,1)?></b><span class="muted">Auto EPA</span></div>
          <div class="robot-lookup-kpi"><b><?=rl_num($teleopEpa,1)?></b><span class="muted">Teleop EPA</span></div>
          <div class="robot-lookup-kpi"><b><?=rl_num($endgameEpa,1)?></b><span class="muted">Endgame EPA</span></div>
        </div>

        <div class="robot-lookup-kpis">
          <div class="robot-lookup-kpi">
            <b><?=e($wins.'-'.$losses.'-'.$ties)?></b>
            <span class="muted">Archived record</span>
          </div>
          <div class="robot-lookup-kpi">
            <b><?=$winrate!==null?number_format($winrate*100,1).'%':'—'?></b>
            <span class="muted">Win rate</span>
          </div>
          <div class="robot-lookup-kpi">
            <b><?=((float)($epaSeasonRating['trend']??0)>=0?'+':'').rl_num($epaSeasonRating['trend']??0,1)?></b>
            <span class="muted">EPA trend</span>
          </div>
          <div class="robot-lookup-kpi">
            <b><?=rl_num($epaSeasonRating['confidence']??null,0)?>%</b>
            <span class="muted">Model confidence</span>
          </div>
        </div>

        <div class="robot-lookup-stat-ranks">
          <div class="robot-lookup-stat-rank">
            <b><?=$seasonRank!==null?'#'.e($seasonRank):'—'?></b>
            <span>Archived season rank</span>
            <?php if($seasonPercentile!==null):?><small><?=number_format($seasonPercentile,1)?> percentile · <?=e($seasonTeams)?> teams</small><?php endif;?>
          </div>
          <div class="robot-lookup-stat-rank">
            <b><?=e((int)($epaSeasonRating['events_played']??0))?></b>
            <span>Rated events</span>
            <small><?=e((int)($epaSeasonRating['matches_played']??0))?> qualification matches</small>
          </div>
          <div class="robot-lookup-stat-rank">
            <b><?=e($epaLastEvent['short_name']??$epaLastEvent['name']??($epaSeasonRating['last_event_key']??'—'))?></b>
            <span>Latest archived event</span>
            <?php if(!empty($epaSeasonRating['model_version'])):?><small><?=e($epaSeasonRating['model_version'])?></small><?php endif;?>
          </div>
        </div>

        <div class="robot-lookup-tba-note">Source: AUGUR Public EPA Archive using public TBA match results. Neptune scouting observations remain separate below and can be blended into AUGUR predictions without changing this public EPA value.</div>
      <?php else:?>
        <div class="notice" style="margin-top:12px">
          <i class="fa-solid fa-database"></i>
          No archived Public EPA is available for team #<?=e($team)?> in <?=e($epaYear)?>.
          <span class="muted" style="display:block;margin-top:4px">
            <?php if(!augur_epa_tables_ready($pdo)):?>
              The AUGUR Public EPA Archive tables are not installed yet.
            <?php else:?>
              Backfill or rebuild this season from the AUGUR Public EPA Archive page. Robot Lookup will use the stored result automatically afterward.
            <?php endif;?>
          </span>
        </div>
      <?php endif;?>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="robot-lookup-section-title">
        <div><div class="module-eyebrow"><span>MATCH DATA</span><small>Game + Event</small></div><h2>Scouting Summary</h2></div>
      </div>
      <?php if(!$eventSummary):?>
        <div class="notice">No match scouting actions are available for team #<?=e($team)?>.</div>
      <?php else:?>
        <?php foreach($eventSummary as $summary):
          $key=(int)$summary['game_id'].':'.(int)$summary['event_id'];
          $decisions=(int)$summary['success_count']+(int)$summary['failure_count'];
          $ppm=(int)$summary['match_count']>0?(float)$summary['total_points']/(int)$summary['match_count']:0;
        ?>
          <div class="robot-lookup-event">
            <div class="toolbar" style="justify-content:space-between;margin-bottom:8px">
              <div><b><?=e($summary['season_year'].' · '.$summary['game_name'])?></b><div class="muted"><?=e($summary['event_name'])?></div></div>
              <span class="pill"><?=e($summary['match_count'])?> matches</span>
            </div>
            <div class="robot-lookup-kpis">
              <div class="robot-lookup-kpi"><b><?=rl_num($summary['total_points'],1)?></b><span class="muted">Scout points</span></div>
              <div class="robot-lookup-kpi"><b><?=rl_num($ppm,1)?></b><span class="muted">Points / match</span></div>
              <div class="robot-lookup-kpi"><b><?=rl_pct((int)$summary['success_count'],$decisions)?></b><span class="muted">Action success</span></div>
              <div class="robot-lookup-kpi"><b><?=e($summary['action_count'])?></b><span class="muted">Recorded actions</span></div>
            </div>
            <div class="robot-lookup-chip-row">
              <span class="pill">Auto <?=rl_num($summary['auton_points'],1)?> pts</span>
              <span class="pill">Teleop <?=rl_num($summary['teleop_points'],1)?> pts</span>
              <span class="pill">Endgame <?=rl_num($summary['endgame_points'],1)?> pts</span>
            </div>

            <?php if(!empty($actionBreakdown[$key])):?>
              <div class="table-wrap" style="margin-top:10px">
                <table class="table robot-lookup-table">
                  <thead><tr><th>Action</th><th>Attempts</th><th>Success</th><th>Failure</th><th>Success %</th><th>Points</th></tr></thead>
                  <tbody>
                  <?php foreach($actionBreakdown[$key] as $a):
                    $attemptDecisions=(int)$a['success_count']+(int)$a['failure_count'];
                  ?>
                    <tr>
                      <td><?=e($a['action_name'])?></td>
                      <td><?=e($a['attempts'])?></td>
                      <td><?=e($a['success_count'])?></td>
                      <td><?=e($a['failure_count'])?></td>
                      <td><?=rl_pct((int)$a['success_count'],$attemptDecisions)?></td>
                      <td><?=rl_num($a['points'],1)?></td>
                    </tr>
                  <?php endforeach;?>
                  </tbody>
                </table>
              </div>
            <?php endif;?>
          </div>
        <?php endforeach;?>
      <?php endif;?>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="robot-lookup-section-title"><div><div class="module-eyebrow"><span>SPOT</span><small>Human Observations</small></div><h2>Spot Scouting</h2></div><div class="toolbar" style="margin:0"><span class="pill"><?=count($spotRows)?></span><a class="btn secondary" href="<?=e(base_url('spot/index.php'))?>"><i class="fa-solid fa-plus"></i> Add Observation</a></div></div>
      <?php if(!$spotRows):?>
        <div class="notice">No Spot Scouting observations are available for this robot.</div>
      <?php else:?>
        <div class="robot-lookup-spot-list">
        <?php foreach($spotRows as $spot):
          $spotTags=[];
          foreach(explode('~~',(string)($spot['tag_blob']??'')) as $chunk){
            if($chunk==='')continue;$parts=explode('||',$chunk,3);$spotTags[]=['label'=>$parts[0]??'','icon'=>$parts[1]??'fa-solid fa-tag','severity'=>$parts[2]??'info'];
          }
          $spotMatch=!empty($spot['match_id'])?neptune_match_label($spot):'';
        ?>
          <div class="robot-lookup-spot <?=($spot['status']??'open')==='resolved'?'resolved':''?>">
            <div class="robot-lookup-spot-head"><div><b><?=e(($spot['context']??'general')==='match'?'Match':(($spot['context']??'general')==='pit'?'Pit':'Team Only'))?><?=!empty($spot['event_name'])?' · '.e($spot['event_name']):''?><?=($spotMatch!=='')?' · '.e($spotMatch):''?></b><div class="robot-lookup-spot-meta"><?=e($spot['scout_name']?:'Scout')?> · <?=e($spot['created_at'])?><?=!empty($spot['field_id'])&&$spot['field_id']>1?' · Field '.e($spot['field_id']):''?></div></div><span class="pill"><?=e($spot['status'])?></span></div>
            <?php if($spotTags):?><div class="robot-lookup-spot-tags"><?php foreach($spotTags as $t):?><span class="pill"><i class="<?=e($t['icon'])?>"></i> <?=e($t['label'])?></span><?php endforeach;?></div><?php endif;?>
            <?php if(trim((string)($spot['note']??''))!==''):?><div class="robot-lookup-spot-note"><?=nl2br(e($spot['note']))?></div><?php endif;?>
            <?php if(!empty($spotMedia[(int)$spot['id']])):?><div class="robot-lookup-spot-media"><?php foreach($spotMedia[(int)$spot['id']] as $media):?><a href="<?=e(base_url('spot/media.php?id='.(int)$media['id']))?>" target="_blank" rel="noopener"><i class="fa-solid <?=$media['media_type']==='video'?'fa-video':'fa-camera'?>"></i> <?=e($media['media_type']==='video'?'Video':'Photo')?></a><?php endforeach;?></div><?php endif;?>
            <?php if(($spot['status']??'open')==='resolved' && !empty($spot['resolution_note'])):?><div class="robot-lookup-spot-meta" style="margin-top:7px">Resolved<?=!empty($spot['resolved_by_name'])?' by '.e($spot['resolved_by_name']):''?>: <?=e($spot['resolution_note'])?></div><?php endif;?>
          </div>
        <?php endforeach;?>
        </div>
      <?php endif;?>
    </div>

    <div class="robot-lookup-grid" style="margin-top:16px">
      <div class="card">
        <div class="robot-lookup-section-title"><div><div class="module-eyebrow"><span>PIT</span><small>Robot Inspection</small></div><h2>Pit Scouting</h2></div><span class="pill"><?=count($pitRows)?></span></div>
        <?php if(!$pitRows):?>
          <div class="notice">No pit scouting records are available for this robot.</div>
        <?php else:?>
          <?php foreach($pitRows as $pit):
            $data=rl_decode($pit['data_json']);
          ?>
            <div class="robot-lookup-event">
              <div class="toolbar" style="justify-content:space-between"><div><b><?=e($pit['event_name'])?></b><div class="muted"><?=e($pit['season_year'].' · '.$pit['game_name'])?></div></div><span class="pill"><?=e(str_replace('_',' ',$pit['status']))?></span></div>
              <div class="robot-lookup-fields">
                <?php $pitFieldUrl=rl_auton_field_url($pit,$org); foreach($data as $k=>$v): if(!rl_has_value($v)) continue;?>
                  <?php if(rl_is_auton_path_field((string)$k)):?>
                    <div class="robot-lookup-field auton-path-field"><b><?=e(rl_label((string)$k))?></b><?=rl_auton_path_visual($v,$pitFieldUrl,'Team '.$team.' autonomous path')?></div>
                  <?php else:?>
                    <div class="robot-lookup-field"><b><?=e(rl_label((string)$k))?></b><span><?=e(rl_value($v))?></span></div>
                  <?php endif;?>
                <?php endforeach;?>
                <?php if(trim((string)($pit['notes']??''))!==''):?><div class="robot-lookup-field"><b>Notes</b><span><?=nl2br(e($pit['notes']))?></span></div><?php endif;?>
              </div>
              <?php if(!empty($pitPhotosByScout[(int)$pit['id']])):?>
                <div class="robot-lookup-photo-grid">
                  <?php foreach($pitPhotosByScout[(int)$pit['id']] as $photo):?>
                    <a class="robot-lookup-photo" href="<?=e(base_url($photo['file_path']))?>" target="_blank" rel="noopener">
                      <img src="<?=e(base_url($photo['file_path']))?>" alt="<?=e(($photo['caption']??'') ?: ('Pit photo · '.$photo['category']))?>" loading="lazy">
                      <span><?=e(ucfirst($photo['category']))?><?=!empty($photo['caption'])?' · '.e($photo['caption']):''?></span>
                    </a>
                  <?php endforeach;?>
                </div>
              <?php endif;?>
            </div>
          <?php endforeach;?>
        <?php endif;?>
      </div>

      <div class="card">
        <div class="robot-lookup-section-title"><div><div class="module-eyebrow"><span>PRE-SCOUT</span><small>Season Preparation</small></div><h2>Pre-Scouting</h2></div><span class="pill"><?=count($preRows)?></span></div>
        <?php if(!$preRows):?>
          <div class="notice">No pre-scouting records are available for this robot.</div>
        <?php else:?>
          <?php foreach($preRows as $pre):
            $data=rl_decode($pre['data_json']);
          ?>
            <div class="robot-lookup-event">
              <div class="toolbar" style="justify-content:space-between"><div><b><?=e($pre['event_name'])?></b><div class="muted"><?=e($pre['season_year'].' · '.$pre['game_name'])?></div></div><span class="pill"><?=e(str_replace('_',' ',$pre['status']))?></span></div>
              <div class="robot-lookup-fields">
                <?php if(empty($pre['game_id'])&&!empty($pre['event_game_id']))$pre['game_id']=$pre['event_game_id']; $preFieldUrl=rl_auton_field_url($pre,$org); foreach($data as $k=>$v): if(!rl_has_value($v)) continue;?>
                  <?php if(rl_is_auton_path_field((string)$k)):?>
                    <div class="robot-lookup-field auton-path-field"><b><?=e(rl_label((string)$k))?></b><?=rl_auton_path_visual($v,$preFieldUrl,'Team '.$team.' autonomous path')?></div>
                  <?php else:?>
                    <div class="robot-lookup-field"><b><?=e(rl_label((string)$k))?></b><span><?=e(rl_value($v))?></span></div>
                  <?php endif;?>
                <?php endforeach;?>
                <?php if(trim((string)($pre['notes']??''))!==''):?><div class="robot-lookup-field"><b>Notes</b><span><?=nl2br(e($pre['notes']))?></span></div><?php endif;?>
                <?php if(($pre['contact_status']??'not_started')!=='not_started'):?><div class="robot-lookup-field"><b>Contact</b><span><?=e(str_replace('_',' ',$pre['contact_status']))?><?=!empty($pre['contact_name'])?' · '.e($pre['contact_name']):''?></span></div><?php endif;?>
              </div>
            </div>
          <?php endforeach;?>
        <?php endif;?>
      </div>
    </div>

    <?php if($seasonProfiles):?>
      <div class="card" style="margin-top:16px">
        <div class="robot-lookup-section-title"><div><div class="module-eyebrow"><span>SEASON</span><small>Cached Robot Intelligence</small></div><h2>Season Profiles</h2></div></div>
        <?php foreach($seasonProfiles as $profile):
          $local=rl_decode($profile['data_json']);
          $tj=rl_decode($profile['tba_json']);
          $intel=is_array($tj['prescout_intel']??null)?$tj['prescout_intel']:[];
          $stat=is_array($intel['statbotics']??null)?$intel['statbotics']:[];
          $history=is_array($intel['event_history']??null)?$intel['event_history']:[];
        ?>
          <div class="robot-lookup-event">
            <div class="toolbar" style="justify-content:space-between"><div><b><?=e($profile['season_year'].' · '.$profile['game_name'])?></b><?php if(!empty($profile['last_event_name'])):?><div class="muted">Last event: <?=e($profile['last_event_name'])?></div><?php endif;?></div><?php if(!empty($profile['tba_updated_at'])):?><span class="pill">TBA <?=e($profile['tba_updated_at'])?></span><?php endif;?></div>
            <?php if($stat):?>
              <div class="robot-lookup-kpis">
                <div class="robot-lookup-kpi"><b><?=e(rl_value($stat['record']??null))?></b><span class="muted">Record</span></div>
                <div class="robot-lookup-kpi"><b><?=isset($stat['epa'])?rl_num($stat['epa'],1):'—'?></b><span class="muted">Public EPA</span></div>
                <div class="robot-lookup-kpi"><b><?=isset($stat['auto'])?rl_num($stat['auto'],1):'—'?></b><span class="muted">Auto</span></div>
                <div class="robot-lookup-kpi"><b><?=isset($stat['teleop'])?rl_num($stat['teleop'],1):'—'?></b><span class="muted">Teleop</span></div>
              </div>
            <?php endif;?>
            <?php if($local):?>
              <div class="robot-lookup-fields" style="margin-top:8px">
                <?php foreach($local as $k=>$v): if(!rl_has_value($v)) continue;?>
                  <div class="robot-lookup-field"><b><?=e(rl_label((string)$k))?></b><span><?=e(rl_value($v))?></span></div>
                <?php endforeach;?>
              </div>
            <?php endif;?>
            <?php if($history):?>
              <div class="table-wrap" style="margin-top:10px">
                <table class="table"><thead><tr><th>Event</th><th>OPR</th><th>Status</th></tr></thead><tbody>
                <?php foreach($history as $h):?>
                  <tr><td><?=e($h['name']??$h['key']??'Event')?></td><td><?=isset($h['opr'])?rl_num($h['opr'],1):'—'?></td><td><?=e($h['status']??$h['short']??'—')?></td></tr>
                <?php endforeach;?>
                </tbody></table>
              </div>
            <?php endif;?>
          </div>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  <?php elseif($q===''):?>
    <div class="card robot-lookup-empty" style="margin-top:16px">
      <i class="fa-solid fa-robot" style="font-size:2rem"></i>
      <h2>Search for a robot</h2>
      <p class="muted">Use a team number for any FRC team. Team-name search works against names Neptune already knows from event rosters and cached season profiles.</p>
    </div>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
