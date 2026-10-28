<?php
/** Push delivery is best-effort and never part of an assignment transaction. */
declare(strict_types=1);
function neptune_assignment_push_send(PDO $pdo,int $org,int $uid,int $limit=10): void {
    if($org<1 || $uid<1)return;
    $autoload='/opt/neptune/push/vendor/autoload.php';
    $privateFile='/etc/neptune/push-vapid-private.txt';
    $publicFile='/etc/neptune/push-vapid-public.txt';
    if(!is_file($autoload)||!is_readable($privateFile)||!is_readable($publicFile))return;
    require_once $autoload;
    if(!class_exists(\Minishlink\WebPush\WebPush::class))return;
    $private=trim((string)file_get_contents($privateFile));$public=trim((string)file_get_contents($publicFile));
    if($private===''||$public==='')return;
    try {
        $s=$pdo->prepare('SELECT n.id,n.title,n.body,s.id sid,s.endpoint,s.p256dh,s.auth FROM scouting_assignment_notifications n JOIN neptune_push_subscriptions s ON s.organization_id=n.organization_id AND s.user_id=n.recipient_user_id AND s.enabled=1 LEFT JOIN neptune_push_delivery_attempts d ON d.notification_id=n.id AND d.subscription_id=s.id WHERE n.organization_id=? AND n.recipient_user_id=? AND n.created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY AND n.created_at>=s.created_at AND d.notification_id IS NULL ORDER BY n.id ASC,s.id ASC LIMIT '.max(1,min($limit,100)));
        $s->execute([$org,$uid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!$rows)return;
        $push=new \Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>'mailto:admin@neptunefrc.com','publicKey'=>$public,'privateKey'=>$private]],['TTL'=>300,'timeout'=>3]);
        $insert=$pdo->prepare('INSERT IGNORE INTO neptune_push_delivery_attempts (notification_id,subscription_id,attempted_at,success) VALUES (?,?,UTC_TIMESTAMP(),0)');
        foreach($rows as $r){
            $insert->execute([(int)$r['id'],(int)$r['sid']]);
            if($insert->rowCount()!==1)continue;
            try {
                $sub=\Minishlink\WebPush\Subscription::create(['endpoint'=>$r['endpoint'],'publicKey'=>$r['p256dh'],'authToken'=>$r['auth'],'contentEncoding'=>'aes128gcm']);
                $payload=json_encode(['id'=>(int)$r['id'],'title'=>(string)$r['title'],'body'=>(string)$r['body']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $push->queueNotification($sub,$payload);
                foreach($push->flush() as $report){
                    $ok=$report->isSuccess()?1:0;
                    $pdo->prepare('UPDATE neptune_push_delivery_attempts SET success=? WHERE notification_id=? AND subscription_id=?')->execute([$ok,(int)$r['id'],(int)$r['sid']]);
                    if(!$ok && $report->isSubscriptionExpired())$pdo->prepare('UPDATE neptune_push_subscriptions SET enabled=0 WHERE id=? AND organization_id=? AND user_id=?')->execute([(int)$r['sid'],$org,$uid]);
                }
            }catch(Throwable $e){error_log('[Neptune push] '.get_class($e));}
        }
    }catch(Throwable $e){error_log('[Neptune push dispatch] '.$e->getMessage());}
}
