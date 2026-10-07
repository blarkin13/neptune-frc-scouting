<?php
declare(strict_types=1);

/**
 * Neptune Platform Owner MFA
 *
 * Password authentication for a Platform Owner must be followed by a TOTP
 * authenticator code (or one-time recovery code). Google authentication is
 * accepted as the alternate strong-authentication path.
 */

function neptune_platform_owner_is(array $user): bool {
    global $config;
    $platformOrgId=max(1,(int)($config['app']['platform_organization_id']??1));
    return (string)($user['role']??'')==='owner'
        && (int)($user['organization_id']??0)===$platformOrgId;
}

function neptune_mfa_ensure_schema(PDO $pdo): void {
    static $done=false;
    if($done)return;

    $apply=function() use ($pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_user_mfa (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            totp_secret_ciphertext TEXT NOT NULL,
            recovery_code_hashes LONGTEXT NULL,
            last_totp_step BIGINT NULL,
            enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_neptune_user_mfa_user
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_neptune_user_mfa_org
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
            KEY idx_neptune_user_mfa_org (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    };

    if(function_exists('neptune_migration_apply')){
        neptune_migration_apply(
            $pdo,
            'security.platform-owner-mfa.v1',
            'Neptune_Platform_Owner_MFA_v1',
            $apply
        );
    }else{
        $apply();
    }

    $done=true;
}

function neptune_mfa_env_value(string $name): string {
    $direct=getenv($name);
    if(is_string($direct)&&trim($direct)!=='')return trim($direct);

    $path='/etc/scout/neptune.env';
    if(is_readable($path)){
        $values=@parse_ini_file($path,false,INI_SCANNER_RAW);
        if(is_array($values)&&isset($values[$name])&&trim((string)$values[$name])!==''){
            return trim((string)$values[$name]);
        }
    }
    return '';
}

function neptune_mfa_crypto_key(): string {
    global $config;

    $configured=neptune_mfa_env_value('NEPTUNE_MFA_ENCRYPTION_KEY');
    if($configured===''){
        $configured=trim((string)($config['app']['mfa_encryption_key']??''));
    }

    if($configured!==''){
        $b64=base64_decode($configured,true);
        if(is_string($b64)&&strlen($b64)>=32)return hash('sha256',$b64,true);
        if(preg_match('/^[a-f0-9]{64,}$/i',$configured))return hash('sha256',hex2bin(substr($configured,0,64))?:$configured,true);
        return hash('sha256',$configured,true);
    }

    // Compatibility fallback for self-hosted installs that have not yet added
    // an explicit MFA encryption key. A dedicated stable key is recommended.
    $material=(string)($config['db']['pass']??'')
        ."\0".(string)($config['app']['session_name']??'NEPTUNESESSID')
        ."\0".(string)($config['app']['base_url']??'/Neptune');

    if($material==="\0NEPTUNESESSID\0/Neptune"){
        throw new RuntimeException(
            'Platform-owner MFA needs NEPTUNE_MFA_ENCRYPTION_KEY or app.mfa_encryption_key.'
        );
    }

    return hash('sha256',"Neptune Platform MFA v1\0".$material,true);
}

function neptune_mfa_encrypt(string $plaintext): string {
    $key=neptune_mfa_crypto_key();

    if(function_exists('sodium_crypto_secretbox')){
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=sodium_crypto_secretbox($plaintext,$nonce,$key);
        return 'sodium:'.base64_encode($nonce.$cipher);
    }

    if(function_exists('openssl_encrypt')){
        $iv=random_bytes(12);
        $tag='';
        $cipher=openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if($cipher===false)throw new RuntimeException('Could not encrypt the authenticator secret.');
        return 'aesgcm:'.base64_encode($iv.$tag.$cipher);
    }

    throw new RuntimeException('Platform-owner MFA requires PHP Sodium or OpenSSL.');
}

function neptune_mfa_decrypt(string $stored): string {
    $key=neptune_mfa_crypto_key();

    if(str_starts_with($stored,'sodium:')){
        if(!function_exists('sodium_crypto_secretbox_open'))throw new RuntimeException('PHP Sodium is required to read this authenticator secret.');
        $raw=base64_decode(substr($stored,7),true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid authenticator secret.');
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$key);
        if($plain===false)throw new RuntimeException('Could not decrypt the authenticator secret.');
        return $plain;
    }

    if(str_starts_with($stored,'aesgcm:')){
        if(!function_exists('openssl_decrypt'))throw new RuntimeException('PHP OpenSSL is required to read this authenticator secret.');
        $raw=base64_decode(substr($stored,7),true);
        if(!is_string($raw)||strlen($raw)<=28)throw new RuntimeException('Invalid authenticator secret.');
        $iv=substr($raw,0,12);
        $tag=substr($raw,12,16);
        $cipher=substr($raw,28);
        $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'');
        if($plain===false)throw new RuntimeException('Could not decrypt the authenticator secret.');
        return $plain;
    }

    throw new RuntimeException('Unknown authenticator secret format.');
}

function neptune_base32_encode(string $binary): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits='';
    foreach(str_split($binary) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);

    $out='';
    for($i=0,$len=strlen($bits);$i<$len;$i+=5){
        $chunk=substr($bits,$i,5);
        if(strlen($chunk)<5)$chunk=str_pad($chunk,5,'0');
        $out.=$alphabet[bindec($chunk)];
    }
    return $out;
}

function neptune_base32_decode(string $encoded): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded=strtoupper(preg_replace('/[^A-Z2-7]/i','',$encoded)??'');
    if($encoded==='')return '';

    $bits='';
    foreach(str_split($encoded) as $c){
        $pos=strpos($alphabet,$c);
        if($pos===false)return '';
        $bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);
    }

    $out='';
    for($i=0,$len=strlen($bits)-7;$i<$len;$i+=8){
        $out.=chr(bindec(substr($bits,$i,8)));
    }
    return $out;
}

function neptune_totp_code(string $secret,int $step, int $digits=6): string {
    $key=neptune_base32_decode($secret);
    if($key==='')return '';

    $counter=pack('N2',($step>>32)&0xffffffff,$step&0xffffffff);
    $hash=hash_hmac('sha1',$counter,$key,true);
    $offset=ord($hash[19])&0x0f;
    $binary=((ord($hash[$offset])&0x7f)<<24)
        |((ord($hash[$offset+1])&0xff)<<16)
        |((ord($hash[$offset+2])&0xff)<<8)
        |(ord($hash[$offset+3])&0xff);

    $mod=10**$digits;
    return str_pad((string)($binary%$mod),$digits,'0',STR_PAD_LEFT);
}

function neptune_totp_verify(string $secret,string $code,?int $lastUsedStep=null): ?int {
    $code=preg_replace('/\D/','',$code)??'';
    if(strlen($code)!==6)return null;

    $nowStep=intdiv(time(),30);
    for($delta=-1;$delta<=1;$delta++){
        $step=$nowStep+$delta;
        if($lastUsedStep!==null&&$step<=$lastUsedStep)continue;
        if(hash_equals(neptune_totp_code($secret,$step),$code))return $step;
    }
    return null;
}

function neptune_mfa_generate_secret(): string {
    return neptune_base32_encode(random_bytes(20));
}

function neptune_mfa_recovery_codes(int $count=10): array {
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codes=[];
    for($i=0;$i<$count;$i++){
        $raw='';
        for($j=0;$j<10;$j++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)];
        $codes[]='NPT-'.substr($raw,0,5).'-'.substr($raw,5);
    }
    return $codes;
}

function neptune_mfa_hash_recovery_codes(array $codes): string {
    $hashes=[];
    foreach($codes as $code){
        $normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$code)??'');
        $hashes[]=password_hash($normalized,PASSWORD_DEFAULT);
    }
    return json_encode($hashes,JSON_UNESCAPED_SLASHES)?:'[]';
}

function neptune_mfa_use_recovery_code(PDO $pdo,array $mfa,string $code): bool {
    $normalized=strtoupper(preg_replace('/[^A-Z0-9]/','',$code)??'');
    if(strlen($normalized)<8)return false;

    $hashes=json_decode((string)($mfa['recovery_code_hashes']??'[]'),true);
    if(!is_array($hashes))$hashes=[];

    foreach($hashes as $i=>$hash){
        if(is_string($hash)&&password_verify($normalized,$hash)){
            unset($hashes[$i]);
            $pdo->prepare(
                'UPDATE neptune_user_mfa SET recovery_code_hashes=?,updated_at=UTC_TIMESTAMP() WHERE user_id=?'
            )->execute([json_encode(array_values($hashes),JSON_UNESCAPED_SLASHES),(int)$mfa['user_id']]);
            return true;
        }
    }
    return false;
}

function neptune_mfa_record(PDO $pdo,int $userId): ?array {
    neptune_mfa_ensure_schema($pdo);
    $s=$pdo->prepare('SELECT * FROM neptune_user_mfa WHERE user_id=? LIMIT 1');
    $s->execute([$userId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function neptune_mfa_is_enrolled(PDO $pdo,int $userId): bool {
    $row=neptune_mfa_record($pdo,$userId);
    return $row!==null&&trim((string)($row['totp_secret_ciphertext']??''))!=='';
}

function neptune_platform_mfa_begin(array $row): void {
    session_regenerate_id(true);
    unset($_SESSION['user']);
    unset($_SESSION['platform_mfa_recovery_codes'],$_SESSION['platform_mfa_setup_secret']);

    $_SESSION['platform_mfa_pending']=[
        'user_id'=>(int)$row['id'],
        'organization_id'=>(int)$row['organization_id'],
        'expires_at'=>time()+600,
        'failures'=>0,
    ];
}

function neptune_platform_mfa_pending(): ?array {
    $pending=$_SESSION['platform_mfa_pending']??null;
    if(!is_array($pending))return null;
    if((int)($pending['expires_at']??0)<time()){
        unset($_SESSION['platform_mfa_pending'],$_SESSION['platform_mfa_setup_secret']);
        return null;
    }
    return $pending;
}

function neptune_platform_mfa_fail(): int {
    $pending=neptune_platform_mfa_pending();
    if(!$pending)return 0;
    $pending['failures']=(int)($pending['failures']??0)+1;
    $_SESSION['platform_mfa_pending']=$pending;
    return (int)$pending['failures'];
}

function neptune_platform_mfa_mark_verified(string $method='totp'): void {
    if(empty($_SESSION['user'])||!is_array($_SESSION['user']))return;
    $_SESSION['user']['platform_mfa_verified_at']=time();
    $_SESSION['user']['platform_mfa_method']=$method;
}

function neptune_platform_mfa_session_is_strong(array $user): bool {
    if(!neptune_platform_owner_is($user))return true;

    $method=strtolower(trim((string)($user['auth_method']??$_SESSION['user']['auth_method']??'')));
    if($method==='google')return true;

    $verified=(int)($_SESSION['user']['platform_mfa_verified_at']??0);
    $maxAge=12*3600;
    return $verified>0&&(time()-$verified)<=$maxAge;
}
