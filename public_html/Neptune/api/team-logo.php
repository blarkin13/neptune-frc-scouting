<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once dirname(__DIR__,3).'/neptune_secure/image.php';
require_once dirname(__DIR__).'/analytics/_alliance_helpers.php';
require_once dirname(__DIR__).'/analytics/_team_logo_cache.php';

$u=require_login();
$org=(int)$u['organization_id'];
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'message'=>'POST required.'],405);
verify_csrf();
$eventId=(int)($_POST['event_id']??0);
$team=(int)($_POST['team']??0);
$action=trim((string)($_POST['action']??''));
$requestedSeason=(int)($_POST['season']??date('Y'));
$privileged=in_array((string)($u['role']??''),['owner','admin','strategy'],true);


// Persistent-cache maintenance actions do not require an event selection.
if($action==='list_cached_logos'){
    if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
    $teams=neptune_team_logo_cached_teams($org);
    json_response(['ok'=>true,'teams'=>$teams,'count'=>count($teams)]);
}
if($action==='upscale_cached_logo'){
    if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
    if($team<1)json_response(['ok'=>false,'message'=>'Missing team.'],422);
    $season=max(1992,min((int)date('Y')+1,$requestedSeason));
    try{
        $result=neptune_team_logo_upscale($org,$season,$team,1000,true);
        if(!empty($result['logo']['path']))$result['url']=base_url($result['logo']['path']).'?v='.rawurlencode((string)($result['logo']['version']??time()));
        json_response($result,!empty($result['ok'])?200:500);
    }catch(Throwable $e){json_response(['ok'=>false,'status'=>'error','team'=>$team,'message'=>$e->getMessage()],500);}
}

// Global Team Logo Cache mode is intentionally limited to privileged users.
// It lets Team Logo Cache discover all teams TBA lists for a season and fill
// Neptune's persistent organization/team logo library without choosing an event.
if($action==='list_tba_teams'){
    if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
    $page=max(0,(int)($_POST['page']??0));
    $season=max(1992,min((int)date('Y')+1,$requestedSeason));
    try{
        $rows=tba_get('teams/'.$season.'/'.$page,86400*7);
        $teams=[];
        foreach(is_array($rows)?$rows:[] as $row){
            if(!is_array($row))continue;
            $n=(int)preg_replace('/\D+/','',(string)($row['key']??''));
            if($n<1)$n=(int)($row['team_number']??0);
            if($n<1)continue;
            $teams[]=['team'=>$n,'nickname'=>(string)($row['nickname']??'')];
        }
        json_response(['ok'=>true,'season'=>$season,'page'=>$page,'teams'=>$teams,'done'=>count($teams)===0]);
    }catch(Throwable $e){
        $message=$e->getMessage();
        if(str_contains($message,'TBA request failed'))$message='Could not reach The Blue Alliance while loading the team directory.';
        json_response(['ok'=>false,'message'=>$message],500);
    }
}

if($team<1)json_response(['ok'=>false,'message'=>'Missing team.'],422);
$event=null;
if($eventId>0){
    $event=alliance_event($pdo,$org,$eventId);
    if(!$event||!alliance_team_on_roster($pdo,$eventId,$team))json_response(['ok'=>false,'message'=>'That team is not on this event roster.'],404);
    $season=(int)($event['season_year']??date('Y'));
}else{
    if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required for the global logo library.'],403);
    $season=max(1992,min((int)date('Y')+1,$requestedSeason));
}

try{
    if($action==='auto_fetch_tba'){
        // Any logged-in user who can view Robot Cards may fill a missing local
        // cache entry. This never overwrites an existing/custom logo.
        $info=neptune_team_logo_fetch_tba($org,$season,$team,false);
        if(empty($info['exists']))json_response(['ok'=>true,'status'=>'missing','message'=>'TBA does not currently have an avatar for this team.','logo'=>$info]);
        json_response(['ok'=>true,'status'=>'cached','message'=>'TBA logo cached locally.','logo'=>$info,'url'=>base_url($info['path']).'?v='.rawurlencode((string)$info['version'])]);
    }
    if($action==='fetch_tba_global'){
        if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
        // Global sync checks the selected season only. This makes a full-season
        // cache practical and avoids multiplying TBA requests across old years.
        // Existing/custom cached logos are returned immediately and are never overwritten.
        $info=neptune_team_logo_fetch_tba($org,$season,$team,false,[$season]);
        if(empty($info['exists']))json_response(['ok'=>true,'status'=>'missing','message'=>'No TBA avatar for this team in '.$season.'.','logo'=>$info]);
        json_response(['ok'=>true,'status'=>'cached','message'=>'TBA logo cached locally.','logo'=>$info,'url'=>base_url($info['path']).'?v='.rawurlencode((string)$info['version'])]);
    }
    if($action==='fetch_tba'){
        if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
        $info=neptune_team_logo_fetch_tba($org,$season,$team,true);
        if(empty($info['exists']))json_response(['ok'=>true,'status'=>'missing','message'=>'TBA does not currently have an avatar for this team.','logo'=>$info]);
        json_response(['ok'=>true,'status'=>'cached','message'=>'TBA logo cached locally.','logo'=>$info,'url'=>base_url($info['path']).'?v='.rawurlencode((string)$info['version'])]);
    }
    if($action==='upload'){
        if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
        $file=$_FILES['logo']??null;
        if(!is_array($file))json_response(['ok'=>false,'message'=>'Choose an image to upload.'],422);
        $info=neptune_team_logo_store_upload($org,$season,$team,$file,$u);
        json_response(['ok'=>true,'status'=>'custom','message'=>'Replacement logo saved locally.','logo'=>$info,'url'=>base_url($info['path']).'?v='.rawurlencode((string)$info['version'])]);
    }
    if($action==='remove'){
        if(!$privileged)json_response(['ok'=>false,'message'=>'Strategy access required.'],403);
        neptune_team_logo_remove($org,$season,$team);
        json_response(['ok'=>true,'status'=>'removed','message'=>'Cached logo removed.']);
    }
    json_response(['ok'=>false,'message'=>'Unknown logo action.'],422);
}catch(Throwable $e){
    $message=$e->getMessage();
    if(str_contains($message,'TBA request failed'))$message='Could not reach The Blue Alliance. Existing cached logos are unchanged.';
    json_response(['ok'=>false,'message'=>$message],500);
}
