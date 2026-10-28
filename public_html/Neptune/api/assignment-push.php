<?php
/** Device-level opt-in for Neptune assignment Web Push. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$org=(int)($u['organization_id']??0);$uid=(int)($u['id']??0);
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if($org<1||$uid<1){json_response(['status'=>'error'],403);exit;}
try{
  if($_SERVER['REQUEST_METHOD']==='GET'){
    $keyFile='/etc/neptune/push-vapid-public.txt';
    $key=@file_get_contents($keyFile);
    if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{80,100}$/',trim($key)))json_response(['status'=>'unavailable','message'=>'Push is not configured on this server']);
    else json_response(['status'=>'success','publicKey'=>trim($key)]);
    exit;
  }
  if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');json_response(['status'=>'error'],405);exit;}
  verify_csrf();
  $action=(string)($_POST['action']??'');
  $raw=(string)($_POST['subscription']??'');
  if(strlen($raw)>8192)throw new InvalidArgumentException('Subscription too large');
  $sub=json_decode($raw,true);
  $endpoint=is_array($sub)?(string)($sub['endpoint']??''):'';
  $p256dh=is_array($sub)?(string)($sub['keys']['p256dh']??''):'';
  $auth=is_array($sub)?(string)($sub['keys']['auth']??''):'';
  if(!preg_match('~^https://[^\s]{5,2000}$~i',$endpoint) || parse_url($endpoint,PHP_URL_USER)!==null || parse_url($endpoint,PHP_URL_PASS)!==null || strlen($p256dh)>200 || strlen($auth)>200)throw new InvalidArgumentException('Invalid subscription');
  // Push delivery makes outbound HTTP requests. Restrict destinations to recognized push gateways.
  $host=strtolower((string)parse_url($endpoint,PHP_URL_HOST));
  if(!in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','push.services.mozilla.com','web.push.apple.com','webpush.push.apple.com'],true) && !preg_match('/^wns[0-9-]*\.notify\.windows\.com$/',$host))throw new InvalidArgumentException('Unsupported push provider');
  $hash=hash('sha256',$endpoint);
  if($action==='subscribe'){
    if(!preg_match('/^[A-Za-z0-9_-]{75,150}$/',$p256dh)||!preg_match('/^[A-Za-z0-9_-]{10,100}$/',$auth))throw new InvalidArgumentException('Invalid subscription keys');
    // Ownership is reassigned if the browser was previously used by another user.
    $s=$pdo->prepare('INSERT INTO neptune_push_subscriptions (organization_id,user_id,endpoint_hash,endpoint,p256dh,auth,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE organization_id=VALUES(organization_id),user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),enabled=1,updated_at=UTC_TIMESTAMP()');
    $s->execute([$org,$uid,$hash,$endpoint,$p256dh,$auth]);
  }elseif($action==='unsubscribe'){
    $s=$pdo->prepare('DELETE FROM neptune_push_subscriptions WHERE endpoint_hash=? AND organization_id=? AND user_id=?');$s->execute([$hash,$org,$uid]);
  }else throw new InvalidArgumentException('Invalid action');
  json_response(['status'=>'success']);
}catch(InvalidArgumentException $e){json_response(['status'=>'error','message'=>$e->getMessage()],400);
}catch(Throwable $e){error_log('[Neptune push subscriptions] '.$e->getMessage());json_response(['status'=>'error','message'=>'Push subscriptions unavailable'],503);}
