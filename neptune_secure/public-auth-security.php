<?php
declare(strict_types=1);

/**
 * Public authentication hardening for Neptune.
 *
 * Rate-limit rows contain only SHA-256 bucket hashes. Raw client IP addresses,
 * passwords, organization names, usernames, Google identifiers, and secrets
 * are never stored in the rate-limit table.
 */

function neptune_public_auth_ensure_schema(PDO $pdo): void {
    static $done=false;
    if($done)return;

    neptune_migration_apply(
        $pdo,
        'auth.public-rate-limits.v1',
        'Neptune_Public_Registration_Login_Hardening_v1_Maintenance_Console.zip',
        function() use ($pdo): void {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS neptune_auth_rate_limits (
                    bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
                    action VARCHAR(40) NOT NULL,
                    attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    window_started_at DATETIME NOT NULL,
                    blocked_until DATETIME NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_narl_action (action),
                    KEY idx_narl_updated (updated_at),
                    KEY idx_narl_blocked (blocked_until)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    );

    // Cleanup is runtime maintenance, not a schema migration.
    try{
        $pdo->exec("DELETE FROM neptune_auth_rate_limits
                    WHERE updated_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY)
                      AND (blocked_until IS NULL OR blocked_until < UTC_TIMESTAMP())");
    }catch(Throwable $ignored){}

    $done=true;
}

function neptune_public_client_ip(): string {
    global $config;
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));

    // Only honor forwarding headers on deployments that explicitly trust their proxy.
    if(!empty($config['app']['trust_proxy']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
        $candidate=trim(explode(',',(string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if(filter_var($candidate,FILTER_VALIDATE_IP))$ip=$candidate;
    }
    if(!filter_var($ip,FILTER_VALIDATE_IP))$ip='unknown';
    return $ip;
}

function neptune_public_rate_bucket(string $action,string $scope): string {
    return hash('sha256',$action.'|'.$scope);
}

function neptune_public_rate_status(
    PDO $pdo,
    string $action,
    string $scope,
    int $limit,
    int $windowSeconds
): array {
    neptune_public_auth_ensure_schema($pdo);
    $hash=neptune_public_rate_bucket($action,$scope);
    $s=$pdo->prepare(
        "SELECT attempts,
                UNIX_TIMESTAMP(window_started_at) window_started,
                UNIX_TIMESTAMP(blocked_until) blocked_until
         FROM neptune_auth_rate_limits
         WHERE bucket_hash=? LIMIT 1"
    );
    $s->execute([$hash]);
    $row=$s->fetch();
    if(!$row)return ['allowed'=>true,'retry_after'=>0];

    $now=time();
    $blocked=(int)($row['blocked_until']??0);
    if($blocked>$now)return ['allowed'=>false,'retry_after'=>max(1,$blocked-$now)];

    $started=(int)($row['window_started']??0);
    if($started<=0 || ($now-$started)>=$windowSeconds)return ['allowed'=>true,'retry_after'=>0];

    // A row can reach the threshold just before blocked_until is written during
    // a competing request. Treat it as limited for the remainder of the window.
    if((int)$row['attempts'] >= $limit){
        return ['allowed'=>false,'retry_after'=>max(1,$windowSeconds-($now-$started))];
    }
    return ['allowed'=>true,'retry_after'=>0];
}

function neptune_public_rate_hit(
    PDO $pdo,
    string $action,
    string $scope,
    int $limit,
    int $windowSeconds,
    int $blockSeconds
): array {
    neptune_public_auth_ensure_schema($pdo);
    $hash=neptune_public_rate_bucket($action,$scope);
    $now=time();

    $s=$pdo->prepare(
        "SELECT attempts,
                UNIX_TIMESTAMP(window_started_at) window_started,
                UNIX_TIMESTAMP(blocked_until) blocked_until
         FROM neptune_auth_rate_limits
         WHERE bucket_hash=? LIMIT 1"
    );
    $s->execute([$hash]);
    $row=$s->fetch();

    if(!$row){
        $attempts=1;
        $started=$now;
    }else{
        $started=(int)($row['window_started']??0);
        $attempts=(int)($row['attempts']??0);
        if($started<=0 || ($now-$started)>=$windowSeconds){
            $attempts=1;
            $started=$now;
        }else{
            $attempts++;
        }
    }

    $blockedUntil=$attempts >= $limit ? $now+$blockSeconds : null;
    $pdo->prepare(
        "INSERT INTO neptune_auth_rate_limits
            (bucket_hash,action,attempts,window_started_at,blocked_until)
         VALUES(?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            action=VALUES(action),
            attempts=VALUES(attempts),
            window_started_at=VALUES(window_started_at),
            blocked_until=VALUES(blocked_until),
            updated_at=CURRENT_TIMESTAMP"
    )->execute([
        $hash,
        substr($action,0,40),
        $attempts,
        gmdate('Y-m-d H:i:s',$started),
        $blockedUntil?gmdate('Y-m-d H:i:s',$blockedUntil):null,
    ]);

    return [
        'allowed'=>$attempts<$limit,
        'retry_after'=>$blockedUntil?max(1,$blockedUntil-$now):0,
        'attempts'=>$attempts,
    ];
}

function neptune_public_rate_clear(PDO $pdo,string $action,string $scope): void {
    $hash=neptune_public_rate_bucket($action,$scope);
    $s=$pdo->prepare('DELETE FROM neptune_auth_rate_limits WHERE bucket_hash=?');
    $s->execute([$hash]);
}

function neptune_public_retry_minutes(int $seconds): int {
    return max(1,(int)ceil(max(1,$seconds)/60));
}

function neptune_public_org_slug(string $name): string {
    $slug=strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-',$name),'-'));
    return $slug!==''?$slug:'organization';
}

function neptune_public_unique_org_slug(PDO $pdo,string $name): string {
    $base=neptune_public_org_slug($name);
    $slug=$base;
    $i=2;
    $s=$pdo->prepare('SELECT COUNT(*) FROM organizations WHERE slug=?');
    while(true){
        $s->execute([$slug]);
        if(!(int)$s->fetchColumn())return $slug;
        $slug=$base.'-'.$i++;
        if($i>9999)throw new RuntimeException('Could not allocate organization slug.');
    }
}

function neptune_public_resolve_organization(PDO $pdo,string $input): ?array {
    $input=trim($input);
    if($input==='')return null;

    // Slugs are globally unique and therefore the cleanest identifier.
    $s=$pdo->prepare(
        "SELECT id,name,slug,platform_status,google_signin_mode
         FROM organizations
         WHERE LOWER(slug)=LOWER(?)
         LIMIT 1"
    );
    $s->execute([$input]);
    $row=$s->fetch();
    if($row)return $row;

    // Names are accepted only when the exact case-insensitive name resolves to
    // exactly one organization. Ambiguous names never produce a public list.
    $s=$pdo->prepare(
        "SELECT id,name,slug,platform_status,google_signin_mode
         FROM organizations
         WHERE LOWER(name)=LOWER(?)
         ORDER BY id
         LIMIT 2"
    );
    $s->execute([$input]);
    $rows=$s->fetchAll();
    return count($rows)===1?$rows[0]:null;
}

function neptune_public_log_exception(string $context,Throwable $e,array $meta=[]): string {
    $reference=strtoupper(substr(bin2hex(random_bytes(8)),0,12));
    $safeMeta=[];
    foreach($meta as $k=>$v){
        if(in_array((string)$k,['password','client_secret','token','code','authorization'],true))continue;
        if(is_scalar($v)||$v===null)$safeMeta[(string)$k]=(string)$v;
    }
    error_log(sprintf(
        'Neptune public error [%s] context=%s exception=%s message=%s file=%s line=%d meta=%s',
        $reference,
        preg_replace('/[^a-zA-Z0-9._-]+/','_',substr($context,0,60)),
        get_class($e),
        str_replace(["\r","\n"],' ',substr($e->getMessage(),0,500)),
        basename($e->getFile()),
        $e->getLine(),
        json_encode($safeMeta,JSON_UNESCAPED_SLASHES)
    ));
    return $reference;
}

function neptune_public_internal_error(string $context,Throwable $e,array $meta=[]): never {
    $internalErrorReference=neptune_public_log_exception($context,$e,$meta);
    http_response_code(500);

    $errorPage=dirname(__DIR__).'/public_html/Neptune/errors/500.php';
    if(is_file($errorPage)){
        require $errorPage;
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "Neptune could not complete this request.\nReference: ".$internalErrorReference;
    exit;
}
