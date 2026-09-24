<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
header('Cache-Control: public, max-age=300, stale-while-revalidate=1800');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET') json_response(['ok'=>false,'message'=>'Read-only API. Use GET.'],405);
if(!augur_epa_tables_ready($pdo)) json_response(['ok'=>false,'message'=>'Public EPA Archive is not installed.'],503);

function ar_api_table_exists(PDO $pdo,string $table): bool {
    static $cache=[];
    if(array_key_exists($table,$cache))return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$table]);
    return $cache[$table]=((int)$s->fetchColumn()>0);
}
$teamDirectoryReady=ar_api_table_exists($pdo,'augur_epa_team_directory');

$year=(int)($_GET['year']??0);
$eventKey=trim((string)($_GET['event']??''));
$legacyEventId=(int)($_GET['event_id']??0);
$team=(int)($_GET['team']??0);
$limit=max(1,min(1000,(int)($_GET['limit']??500)));

if($eventKey===''&&$legacyEventId>0){
    $s=$pdo->prepare("SELECT tba_event_key FROM events WHERE id=? AND tba_event_key IS NOT NULL AND tba_event_key<>'' LIMIT 1");
    $s->execute([$legacyEventId]);$eventKey=(string)($s->fetchColumn()?:'');
}

function ar_api_row(array $r,int $rank): array {
    $total=(float)$r['rating'];
    $auto=$r['auto_rating']!==null?(float)$r['auto_rating']:null;
    $teleop=$r['teleop_rating']!==null?(float)$r['teleop_rating']:null;
    $endgame=$r['endgame_rating']!==null?(float)$r['endgame_rating']:null;
    return [
        'rank'=>$rank,
        'team'=>(int)$r['frc_team_number'],
        'team_info'=>[
            'name'=>$r['team_name']??null,
            'full_name'=>$r['team_full_name']??null,
            'city'=>$r['team_city']??null,
            'state_prov'=>$r['team_state_prov']??null,
            'country'=>$r['team_country']??null,
        ],
        'epa'=>['total'=>$total,'auto'=>$auto,'teleop'=>$teleop,'endgame'=>$endgame],
        'rating'=>$total,'auto'=>$auto,'teleop'=>$teleop,'endgame'=>$endgame,
        'trend'=>(float)$r['trend'],
        'sigma'=>(float)$r['sigma'],
        'confidence'=>(float)$r['confidence'],
        'matches'=>(int)$r['matches_played'],
        'record'=>['wins'=>(int)$r['wins'],'losses'=>(int)$r['losses'],'ties'=>(int)$r['ties']],
        'model_version'=>(string)$r['model_version'],
        'updated_at'=>(string)$r['updated_at'],
    ];
}

if(!empty($_GET['events'])){
    if($year<1)$year=(int)$pdo->query('SELECT MAX(season_year) FROM augur_epa_archive_events')->fetchColumn();
    $s=$pdo->prepare("SELECT e.tba_event_key,e.name,e.short_name,e.start_date,e.end_date,e.city,e.state_prov,e.country,
        e.source_status,e.is_complete,e.qual_match_count,e.auto_sample_count,e.teleop_sample_count,e.endgame_sample_count,
        e.ratings_calculated_at,e.model_version,
        (SELECT COUNT(*) FROM augur_epa_event_ratings r WHERE r.tba_event_key=e.tba_event_key) rating_count
        FROM augur_epa_archive_events e WHERE e.season_year=? ORDER BY COALESCE(e.start_date,e.end_date),e.name");
    $s->execute([$year]);
    json_response(['ok'=>true,'scope'=>'events','year'=>$year,'model_version'=>AUGUR_EPA_MODEL_VERSION,'events'=>$s->fetchAll()]);
}

if(!empty($_GET['history'])&&$team>0){
    if($year<1)$year=(int)$pdo->query('SELECT MAX(season_year) FROM augur_epa_archive_events')->fetchColumn();
    $s=$pdo->prepare($teamDirectoryReady
        ? "SELECT r.*,e.name event_name,e.start_date,e.end_date,
                  d.nickname team_name,d.name team_full_name,d.city team_city,d.state_prov team_state_prov,d.country team_country
           FROM augur_epa_event_ratings r
           JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key
           LEFT JOIN augur_epa_team_directory d ON d.season_year=r.season_year AND d.frc_team_number=r.frc_team_number
           WHERE r.season_year=? AND r.frc_team_number=?
           ORDER BY COALESCE(e.start_date,e.end_date),r.tba_event_key"
        : "SELECT r.*,e.name event_name,e.start_date,e.end_date
           FROM augur_epa_event_ratings r
           JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key
           WHERE r.season_year=? AND r.frc_team_number=?
           ORDER BY COALESCE(e.start_date,e.end_date),r.tba_event_key");
    $s->execute([$year,$team]);$rows=$s->fetchAll();$out=[];
    foreach($rows as $i=>$r)$out[]=array_merge(['event'=>['key'=>$r['tba_event_key'],'name'=>$r['event_name'],'start_date'=>$r['start_date'],'end_date'=>$r['end_date']]],ar_api_row($r,$i+1));
    json_response(['ok'=>true,'scope'=>'team_history','year'=>$year,'team'=>$team,'model_version'=>AUGUR_EPA_MODEL_VERSION,'events'=>$out]);
}

if($eventKey!==''){
    $s=$pdo->prepare('SELECT * FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1');$s->execute([$eventKey]);$event=$s->fetch();
    if(!$event)json_response(['ok'=>false,'message'=>'Event is not in the local EPA archive.'],404);
    $sql=$teamDirectoryReady
        ? 'SELECT r.*,d.nickname team_name,d.name team_full_name,d.city team_city,d.state_prov team_state_prov,d.country team_country
           FROM augur_epa_event_ratings r
           LEFT JOIN augur_epa_team_directory d ON d.season_year=r.season_year AND d.frc_team_number=r.frc_team_number
           WHERE r.tba_event_key=?'
        : 'SELECT r.* FROM augur_epa_event_ratings r WHERE r.tba_event_key=?';
    $args=[$eventKey];
    if($team>0){$sql.=' AND r.frc_team_number=?';$args[]=$team;}
    $sql.=' ORDER BY r.rating DESC,r.frc_team_number LIMIT '.$limit;
    $s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();$out=[];
    foreach($rows as $i=>$r){
        $rank=$i+1;
        if($team>0){
            $rq=$pdo->prepare('SELECT 1+COUNT(*) FROM augur_epa_event_ratings WHERE tba_event_key=? AND rating>?');
            $rq->execute([$eventKey,(float)$r['rating']]);$rank=(int)$rq->fetchColumn();
        }
        $out[]=ar_api_row($r,$rank);
    }
    json_response(['ok'=>true,'scope'=>'event','event'=>[
        'key'=>$event['tba_event_key'],'name'=>$event['name'],'year'=>(int)$event['season_year'],
        'start_date'=>$event['start_date'],'end_date'=>$event['end_date'],'source_status'=>$event['source_status'],
        'qual_matches'=>(int)$event['qual_match_count']
    ],'model_version'=>AUGUR_EPA_MODEL_VERSION,'count'=>count($out),'ratings'=>$out]);
}

if($year<1)$year=(int)$pdo->query('SELECT MAX(season_year) FROM augur_epa_season_ratings')->fetchColumn();
if($year<1){
    json_response(['ok'=>true,'api'=>'AUGUR Public EPA Archive API v3','model_version'=>AUGUR_EPA_MODEL_VERSION,'read_only'=>true,'usage'=>[
        'events'=>'?events=1&year=2026','season'=>'?year=2026','event'=>'?event=2026txama',
        'team_season'=>'?year=2026&team=6369','team_history'=>'?history=1&year=2026&team=6369'
    ]]);
}

$sql=$teamDirectoryReady
    ? 'SELECT r.*,d.nickname team_name,d.name team_full_name,d.city team_city,d.state_prov team_state_prov,d.country team_country
       FROM augur_epa_season_ratings r
       LEFT JOIN augur_epa_team_directory d ON d.season_year=r.season_year AND d.frc_team_number=r.frc_team_number
       WHERE r.season_year=?'
    : 'SELECT r.* FROM augur_epa_season_ratings r WHERE r.season_year=?';
$args=[$year];
if($team>0){$sql.=' AND r.frc_team_number=?';$args[]=$team;}
$sql.=' ORDER BY r.rating DESC,r.frc_team_number LIMIT '.$limit;
$s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();$out=[];
foreach($rows as $i=>$r){
    $rank=$i+1;
    if($team>0){
        $rq=$pdo->prepare('SELECT 1+COUNT(*) FROM augur_epa_season_ratings WHERE season_year=? AND rating>?');
        $rq->execute([$year,(float)$r['rating']]);$rank=(int)$rq->fetchColumn();
    }
    $out[]=ar_api_row($r,$rank);
}
json_response(['ok'=>true,'scope'=>'season','year'=>$year,'model_version'=>AUGUR_EPA_MODEL_VERSION,'count'=>count($out),'ratings'=>$out]);
