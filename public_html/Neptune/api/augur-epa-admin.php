<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

$u=require_role(['owner','admin','strategy']);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
verify_csrf();
if(!augur_epa_tables_ready($pdo))json_response(['ok'=>false,'message'=>'Public EPA Archive tables are not installed.'],503);

$action=(string)($_POST['action']??'');
$year=(int)($_POST['year']??0);

try{
    if($action==='process_year_step'){
        if($year<1992||$year>(int)date('Y')+1)throw new RuntimeException('Invalid year.');
        $discover=augur_epa_discover_year($pdo,$year,false);
        $next=augur_epa_next_work_event($pdo,$year);
        if(!$next){
            json_response(['ok'=>true,'done'=>true,'year'=>$year,'discovery'=>$discover]);
        }
        $key=(string)$next['tba_event_key'];
        $result=augur_epa_ensure_event_by_key($pdo,$key,false);
        $remaining=0;
        $s=$pdo->prepare('SELECT * FROM augur_epa_archive_events WHERE season_year=?');
        $s->execute([$year]);
        foreach($s->fetchAll() as $ev)if(augur_epa_event_needs_work($ev))$remaining++;
        json_response([
            'ok'=>true,'done'=>false,'year'=>$year,'event_key'=>$key,'event_name'=>$next['name'],
            'result'=>$result,'remaining'=>$remaining
        ]);
    }

    if($action==='recalculate_year'){
        if($year<1992||$year>(int)date('Y')+1)throw new RuntimeException('Invalid year.');
        $result=augur_epa_recalculate_year($pdo,$year);
        json_response(['ok'=>true,'result'=>$result]);
    }

    if($action==='discover_year'){
        $result=augur_epa_discover_year($pdo,$year,!empty($_POST['force']));
        json_response(['ok'=>true,'result'=>$result]);
    }

    if($action==='refresh_event'){
        $key=trim((string)($_POST['event_key']??''));
        if($key==='')throw new RuntimeException('Event key is required.');
        $result=augur_epa_ensure_event_by_key($pdo,$key,true);
        json_response(['ok'=>true,'result'=>$result]);
    }

    json_response(['ok'=>false,'message'=>'Unknown action.'],400);
}catch(Throwable $e){
    json_response(['ok'=>false,'message'=>$e->getMessage()],500);
}
