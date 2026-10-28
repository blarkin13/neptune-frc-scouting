<?php
declare(strict_types=1);
require_once __DIR__.'/community-analysis.php';
function community_pairing_schema(PDO $pdo): void {
    neptune_migration_apply($pdo,'community.helper-browser-connect.v1','Neptune_Helper_Browser_Connect_v1_Maintenance_Console.zip',function()use($pdo):void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_pair_requests (
            id CHAR(32) COLLATE utf8mb4_bin PRIMARY KEY, challenge VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            state CHAR(64) NOT NULL, port INT NOT NULL, user_code VARCHAR(10) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending', user_id BIGINT UNSIGNED NULL,
            credential_revision CHAR(64) NULL, code_hash CHAR(64) NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
            KEY request_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_pair_limits (
            ip_hash CHAR(64) PRIMARY KEY, window_start BIGINT NOT NULL, starts INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    });
}
final class CommunityPairing {
    public function __construct(private PDO $db,private array $settings){}
    private function q(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    private function tx(callable $fn):mixed {
        $this->db->beginTransaction();try{$result=$fn();$this->db->commit();return $result;}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function user(int $id):array {
        $u=$this->q('SELECT u.id,u.password_hash,u.active,u.must_change_password,u.display_name,u.username,o.platform_status FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$u||!(int)$u['active']||(int)$u['must_change_password']||($u['platform_status']??'active')!=='active')throw new CommunityAnalysisError(403,'This account is not currently eligible to connect a helper.');
        if(!in_array($id,array_map('intval',$this->settings['contributor_user_ids']??[]),true))throw new CommunityAnalysisError(403,'The Neptune owner has not enabled contributor access for your account.');
        return $u;
    }
    public function start(array $p,string $ip):array {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($p['challenge']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($p['state']??''))||!is_int($p['port']??null)||$p['port']<1024||$p['port']>65535)throw new CommunityAnalysisError(422,'Invalid connection request.');
        return $this->tx(function()use($p,$ip):array {
            $now=time();$hash=hash_hmac('sha256',$ip,(string)$this->settings['secret']);
            $insert=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $this->q($insert.' INTO community_pair_limits(ip_hash,window_start,starts) VALUES(?,?,0)',[$hash,$now]);
            $limit=$this->q('SELECT * FROM community_pair_limits WHERE ip_hash=?'.$this->lock(),[$hash])->fetch(PDO::FETCH_ASSOC);
            $count=$now-(int)$limit['window_start']>=60?0:(int)$limit['starts'];
            if($count>=20)throw new CommunityAnalysisError(429,'Too many connection requests. Wait one minute, then try again.');
            $this->q('UPDATE community_pair_limits SET window_start=?,starts=? WHERE ip_hash=?',[$count===0?$now:$limit['window_start'],$count+1,$hash]);
            $this->q('DELETE FROM community_pair_requests WHERE expires_at<?',[$now-3600]);
            $this->q('DELETE FROM community_pair_limits WHERE window_start<?',[$now-86400]);
            $id=bin2hex(random_bytes(16));$code=strtoupper(substr(bin2hex(random_bytes(5)),0,8));$display=substr($code,0,4).'-'.substr($code,4);
            $this->q('INSERT INTO community_pair_requests(id,challenge,state,port,user_code,created_at,expires_at) VALUES(?,?,?,?,?,?,?)',[$id,$p['challenge'],$p['state'],$p['port'],$display,$now,$now+600]);
            return ['request_id'=>$id,'user_code'=>$display,'expires_in'=>600];
        });
    }
    public function inspect(string $id):array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new CommunityAnalysisError(404,'Connection request not found.');
        $r=$this->q('SELECT * FROM community_pair_requests WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r||(int)$r['expires_at']<=time())throw new CommunityAnalysisError(410,'This connection request expired. Click Connect to Neptune again in the helper.');
        return $r;
    }
    public function authorize(string $id,int $uid,string $revision,bool $approve):string {
        return $this->tx(function()use($id,$uid,$revision,$approve):string {
            $r=$this->q('SELECT * FROM community_pair_requests WHERE id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);
            if(!$r||(int)$r['expires_at']<=time())throw new CommunityAnalysisError(410,'Connection request expired.');
            if($r['status']!=='pending')throw new CommunityAnalysisError(409,'This request was already answered. Return to the helper.');
            $u=$this->user($uid);
            if(!hash_equals(hash('sha256',$u['password_hash']),$revision))throw new CommunityAnalysisError(401,'Sign in again before approving this helper.');
            $code=bin2hex(random_bytes(32));
            $this->q('UPDATE community_pair_requests SET status=?,user_id=?,credential_revision=?,code_hash=? WHERE id=?',[$approve?'approved':'denied',$uid,$revision,hash('sha256',$code),$id]);
            return 'http://127.0.0.1:'.(int)$r['port'].'/neptune-connect?'.http_build_query(['state'=>$r['state'],$approve?'code':'error'=>$approve?$code:'access_denied']);
        });
    }
    public function exchange(array $p):array {
        if(!preg_match('/^[a-f0-9]{32}$/D',(string)($p['request_id']??''))||!preg_match('/^[a-f0-9]{64}$/D',(string)($p['code']??''))||!preg_match('/^[A-Za-z0-9_-]{43,128}$/D',(string)($p['verifier']??'')))throw new CommunityAnalysisError(422,'Invalid connection exchange.');
        return $this->tx(function()use($p):array {
            $r=$this->q('SELECT * FROM community_pair_requests WHERE id=?'.$this->lock(),[$p['request_id']])->fetch(PDO::FETCH_ASSOC);
            $challenge=rtrim(strtr(base64_encode(hash('sha256',$p['verifier'],true)),'+/','-_'),'=');
            if(!$r||(int)$r['expires_at']<=time()||$r['status']!=='approved'||!hash_equals((string)$r['code_hash'],hash('sha256',$p['code']))||!hash_equals($r['challenge'],$challenge))throw new CommunityAnalysisError(401,'Connection approval is invalid, expired, or already used. Connect again.');
            $u=$this->user((int)$r['user_id']);
            if(!hash_equals($r['credential_revision'],hash('sha256',$u['password_hash'])))throw new CommunityAnalysisError(401,'Your account changed. Sign in and connect again.');
            $count=(int)$this->q('SELECT COUNT(*) FROM community_devices WHERE user_id=? AND revoked=0 AND expires_at>?',[$u['id'],time()])->fetchColumn();
            if($count>=20)throw new CommunityAnalysisError(409,'Remove an unused helper connection from Neptune Helper Setup before connecting another.');
            $token=bin2hex(random_bytes(32));$expires=time()+30*86400;
            $this->q('INSERT INTO community_devices(id,user_id,token_hash,credential_revision,expires_at,created_at) VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$u['id'],hash('sha256',$token),$r['credential_revision'],$expires,time()]);
            $this->q("UPDATE community_pair_requests SET status='used',code_hash=NULL WHERE id=?",[$r['id']]);
            return ['access_token'=>$token,'expires_at'=>$expires,'display_name'=>$u['display_name']?:$u['username']];
        });
    }
}
