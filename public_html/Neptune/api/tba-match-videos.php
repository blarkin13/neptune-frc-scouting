<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900');

$raw=trim((string)($_GET['matches']??''));
if($raw===''){
    http_response_code(400);
    echo json_encode(['error'=>'matches is required']);
    exit;
}

$keys=array_values(array_unique(array_filter(array_map('trim',explode(',',$raw)))));
if(count($keys)>30){
    http_response_code(400);
    echo json_encode(['error'=>'Maximum 30 match keys per request']);
    exit;
}

$out=[];
foreach($keys as $key){
    if(!preg_match('/^[A-Za-z0-9_-]{5,64}$/',$key)){
        $out[$key]=['youtube_url'=>null,'tba_url'=>null,'error'=>'invalid match key'];
        continue;
    }

    $tbaUrl='https://www.thebluealliance.com/match/'.rawurlencode($key);
    $youtube=null;
    try{
        $match=tba_get('match/'.$key,21600);
        if(is_array($match)){
            foreach((array)($match['videos']??[]) as $video){
                if(!is_array($video))continue;
                if(strtolower((string)($video['type']??''))!=='youtube')continue;
                $id=trim((string)($video['key']??$video['foreign_key']??''));
                if($id!==''&&preg_match('/^[A-Za-z0-9_-]{6,32}$/',$id)){
                    $youtube='https://www.youtube.com/watch?v='.rawurlencode($id);
                    break;
                }
            }
        }
    }catch(Throwable $e){
        // A video is optional; callers still receive the TBA match link.
    }
    $out[$key]=['youtube_url'=>$youtube,'tba_url'=>$tbaUrl];
}

echo json_encode([
    'matches'=>$out,
    'source'=>'The Blue Alliance match media',
    'generated_at'=>gmdate(DATE_ATOM),
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
