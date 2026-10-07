<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_robot_recall.php';
require_once __DIR__.'/_robot-recall-competition.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

function rrph_local_path(string $value): string
{
    $value=trim($value);
    if($value==='') return '/';
    if(preg_match('~^https?://~i',$value)){
        $parts=parse_url($value);
        $path=(string)($parts['path']??'/');
        $query=isset($parts['query'])?'?'.$parts['query']:'';
        return '/'.ltrim($path,'/').$query;
    }
    return '/'.ltrim($value,'/');
}

function rrph_local_request(string $path, string $sessionName, string $sessionId, string $method='GET', array $post=[]): array
{
    $serverName=preg_replace('/[^A-Za-z0-9.-]/','',(string)($_SERVER['SERVER_NAME']??'')) ?: 'localhost';
    $path=rrph_local_path($path);
    $attempts=[['https',443],['http',80]];
    $last=['status'=>0,'body'=>'','error'=>'Internal request failed.'];
    foreach($attempts as [$scheme,$port]){
        $url=$scheme.'://'.$serverName.$path;
        $ch=curl_init($url);
        if(!$ch) continue;
        $headers=['Accept: application/json,text/html;q=0.9','Cookie: '.$sessionName.'='.$sessionId];
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HEADER=>false,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_ENCODING=>'',
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_RESOLVE=>[$serverName.':'.$port.':127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_SSL_VERIFYHOST=>0,
        ]);
        if(strtoupper($method)==='POST'){
            curl_setopt($ch,CURLOPT_POST,true);
            curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post,'','&',PHP_QUERY_RFC3986));
            $headers[]='Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
            curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
        }
        $body=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=(string)curl_error($ch);
        curl_close($ch);
        $last=['status'=>$status,'body'=>is_string($body)?$body:'','error'=>$error];
        if($body!==false && $status>=200 && $status<500 && $status!==301 && $status!==302 && $status!==307 && $status!==308) return $last;
    }
    return $last;
}

function rrph_with_service_session(int $serviceUserId, callable $callback): mixed
{
    if($serviceUserId<=0) throw new RuntimeException('Robot Recall practice host service account is unavailable.');
    $origId=session_id();
    $origActive=session_status()===PHP_SESSION_ACTIVE;
    if($origActive) session_write_close();

    $tmpId=bin2hex(random_bytes(20));
    $sessionName=session_name();
    $useCookies=(string)ini_get('session.use_cookies');
    @ini_set('session.use_cookies','0');
    session_id($tmpId);
    if(!session_start()) throw new RuntimeException('Could not initialize the practice host service session.');
    $tmpId=session_id();
    $_SESSION=[];
    $_SESSION['user_id']=$serviceUserId;
    $csrf=function_exists('csrf_token')?(string)csrf_token():'';
    session_write_close();

    try{
        return $callback($sessionName,$tmpId,$csrf);
    } finally {
        session_id($tmpId);
        @session_start();
        $_SESSION=[];
        @session_destroy();
        @session_write_close();
        if($origId!==''){
            session_id($origId);
            @session_start();
        }
        @ini_set('session.use_cookies',$useCookies);
    }
}

try{
    if(!function_exists('curl_init')) throw new RuntimeException('The public practice host requires the PHP cURL extension.');
    robot_recall_ensure_schema($pdo);
    rrc_ensure_schema($pdo);

    $key=trim((string)($_REQUEST['key']??''));
    $host=rrc_public_host_by_token($pdo,$key);
    if(!$host) throw new RuntimeException('This practice host link is invalid or has expired.');

    $sessionId=(int)$host['session_id'];
    $orgId=(int)$host['organization_id'];
    $serviceUserId=(int)$host['service_user_id'];
    $session=robot_recall_host_session($pdo,$sessionId,$orgId);
    if(!$session) throw new RuntimeException('Practice room not found.');
    $flag=rrc_session_flag($pdo,$sessionId,$orgId);
    if((string)($flag['play_mode']??'')!=='practice') throw new RuntimeException('This room is no longer a public practice room.');

    $action=strtolower(trim((string)($_REQUEST['action']??'state')));
    $allowed=['state','start','reveal','leaderboard','next','finish'];
    if(!in_array($action,$allowed,true)) throw new RuntimeException('That practice host action is not allowed.');

    if($action==='state'){
        $publicSessionName=session_name();
        $publicSessionId=session_id();
        $result=rrph_local_request(base_url('api/robot-recall-public.php?action=state&view=screen&room='.rawurlencode((string)$session['room_code'])),$publicSessionName,$publicSessionId,'GET');
    } else {
        $result=rrph_with_service_session($serviceUserId,function(string $sessionName,string $tmpId,string $csrf) use($action,$sessionId){
        if($csrf==='') throw new RuntimeException('Could not initialize Robot Recall host security.');
        return rrph_local_request(base_url('api/robot-recall-host.php'),$sessionName,$tmpId,'POST',[
            'action'=>$action,
            'session_id'=>$sessionId,
            'csrf'=>$csrf,
        ]);
        });
    }

    $status=(int)($result['status']??0);
    $body=(string)($result['body']??'');
    $decoded=json_decode($body,true);
    if(!is_array($decoded)) throw new RuntimeException('Robot Recall host returned an invalid response.');
    http_response_code($status>=100&&$status<=599?$status:200);
    echo json_encode($decoded,JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);
}
