<?php
declare(strict_types=1);
function neptune_organization_wifi_schema(PDO $db):void {
    neptune_migration_apply($db,'organization.wifi.v2','Neptune_Organization_Wifi_Display_v4.zip',function()use($db):void{
        $db->exec("CREATE TABLE IF NOT EXISTS organization_wifi_settings (
          organization_id BIGINT UNSIGNED PRIMARY KEY,ssid VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
          password_cipher TEXT NOT NULL,security_type VARCHAR(12) NOT NULL,
          updated_by BIGINT UNSIGNED NOT NULL,updated_at BIGINT NOT NULL,archived TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    });
}
function neptune_event_wifi_schema(PDO $db):void {
    neptune_migration_apply($db,'event.wifi.v1','Neptune_Event_Hub_Wifi_v1.zip',function()use($db):void{
        $db->exec("CREATE TABLE IF NOT EXISTS event_wifi_networks (
          id CHAR(32) COLLATE utf8mb4_bin PRIMARY KEY,organization_id BIGINT UNSIGNED NOT NULL,event_id BIGINT UNSIGNED NOT NULL,
          ssid VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,password_cipher TEXT NOT NULL,security_type VARCHAR(12) NOT NULL,
          note VARCHAR(500) NOT NULL DEFAULT '',created_by BIGINT UNSIGNED NOT NULL,updated_by BIGINT UNSIGNED NOT NULL,
          created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,archived TINYINT NOT NULL DEFAULT 0,
          KEY event_networks(organization_id,event_id,archived),KEY contributor(organization_id,created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    });
}
final class NeptuneEventWifiError extends RuntimeException{}
final class NeptuneEventWifi {
    public function __construct(private PDO $db,private string $key){if(strlen($key)!==32)throw new RuntimeException('Wi-Fi encryption key unavailable.');}
    private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private static function organization(array $user):int {
        $org=(int)($user['organization_id']??0);
        if($org<1||(int)($user['id']??0)<1)throw new NeptuneEventWifiError('Sign in to your organization to use Wi-Fi Access.');
        return $org;
    }
    public function organizationNetwork(array $user):?array {
        $org=self::organization($user);
        $row=$this->q('SELECT * FROM organization_wifi_settings WHERE organization_id=? AND archived=0',[$org])->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['password']=$this->decrypt($row['password_cipher'],'Neptune organization Wi-Fi v2|'.$org.'|'.$row['ssid']);
        unset($row['password_cipher']);return $row;
    }
    public function saveOrganization(array $user,array $in):void {
        $org=self::organization($user);$v=self::validate($in);
        $cipher=$this->encrypt($v['password'],'Neptune organization Wi-Fi v2|'.$org.'|'.$v['ssid']);
        $sql='INSERT INTO organization_wifi_settings(organization_id,ssid,password_cipher,security_type,updated_by,updated_at,archived) VALUES(?,?,?,?,?,?,0) ';
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql.='ON CONFLICT(organization_id) DO UPDATE SET ssid=excluded.ssid,password_cipher=excluded.password_cipher,security_type=excluded.security_type,updated_by=excluded.updated_by,updated_at=excluded.updated_at,archived=0';
        else $sql.='ON DUPLICATE KEY UPDATE ssid=VALUES(ssid),password_cipher=VALUES(password_cipher),security_type=VALUES(security_type),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at),archived=0';
        $this->q($sql,[$org,$v['ssid'],$cipher,$v['security_type'],(int)$user['id'],time()]);
    }
    public function clearOrganization(array $user):void {
        $org=self::organization($user);
        $this->q('UPDATE organization_wifi_settings SET archived=1,updated_by=?,updated_at=? WHERE organization_id=?',[(int)$user['id'],time(),$org]);
    }
    public function events(int $org):array {return $this->q('SELECT id,name,is_current FROM events WHERE organization_id=? ORDER BY is_current DESC,id DESC LIMIT 100',[$org])->fetchAll(PDO::FETCH_ASSOC);}
    public function event(int $org,int $id):array {
        $r=$this->q('SELECT id,name,is_current FROM events WHERE id=? AND organization_id=?',[$id,$org])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new NeptuneEventWifiError('Choose an event belonging to your organization.');return $r;
    }
    public static function validate(array $in):array {
        $ssid=$in['ssid']??'';$password=$in['password']??'';$type=$in['security_type']??'';$note=$in['note']??'';
        if(!is_string($ssid)||trim($ssid)===''||strlen($ssid)>32||!mb_check_encoding($ssid,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$ssid))throw new NeptuneEventWifiError('Enter the exact network name, up to 32 bytes.');
        if(!in_array($type,['WPA','WEP','open'],true))throw new NeptuneEventWifiError('Choose the network security type.');
        if(!is_string($password)||strlen($password)>128||str_contains($password,"\0")||($type!=='open'&&$password===''))throw new NeptuneEventWifiError('Enter the network password, or choose an open network.');
        if(!is_string($note)||mb_strlen($note)>500)throw new NeptuneEventWifiError('Keep the note under 500 characters.');
        return ['ssid'=>$ssid,'password'=>$type==='open'?'':$password,'security_type'=>$type,'note'=>trim($note)];
    }
    private function aad(int $org,int $event,string $ssid):string{return 'Neptune event Wi-Fi v1|'.$org.'|'.$event.'|'.$ssid;}
    private function encrypt(string $plain,string $aad):string {
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$aad);
        if($cipher===false)throw new RuntimeException('Wi-Fi password could not be secured.');return base64_encode($nonce.$tag.$cipher);
    }
    private function decrypt(string $cipher,string $aad):string {
        $raw=base64_decode($cipher,true);if($raw===false||strlen($raw)<28)throw new RuntimeException('Saved Wi-Fi password is unreadable.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$aad);
        if($plain===false)throw new RuntimeException('Saved Wi-Fi password could not be decrypted.');return $plain;
    }
    public function networks(int $org,int $event):array {
        $this->event($org,$event);$rows=$this->q('SELECT * FROM event_wifi_networks WHERE organization_id=? AND event_id=? AND archived=0 ORDER BY updated_at DESC,id',[$org,$event])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['password']=$this->decrypt($r['password_cipher'],$this->aad($org,$event,$r['ssid']));unset($r['password_cipher']);}unset($r);return $rows;
    }
    public static function canManage(array $user,array $row):bool {return (int)($user['organization_id']??0)===(int)$row['organization_id']&&((int)($user['id']??0)===(int)$row['created_by']||in_array($user['role']??'',['owner','admin','strategy'],true));}
    public function save(array $user,int $event,array $in,string $id=''):string {
        $org=(int)$user['organization_id'];$uid=(int)$user['id'];$this->event($org,$event);$v=self::validate($in);$now=time();
        if($id!==''){
            $row=$this->q('SELECT * FROM event_wifi_networks WHERE id=? AND organization_id=? AND event_id=? AND archived=0',[$id,$org,$event])->fetch(PDO::FETCH_ASSOC);
            if(!$row||!self::canManage($user,$row))throw new NeptuneEventWifiError('You can edit your own networks. Organization managers can edit all networks in their organization.');
            $cipher=$this->encrypt($v['password'],$this->aad($org,$event,$v['ssid']));
            $this->q('UPDATE event_wifi_networks SET ssid=?,password_cipher=?,security_type=?,note=?,updated_by=?,updated_at=? WHERE id=? AND organization_id=? AND event_id=?',[$v['ssid'],$cipher,$v['security_type'],$v['note'],$uid,$now,$id,$org,$event]);
        }else{
            $id=bin2hex(random_bytes(16));$cipher=$this->encrypt($v['password'],$this->aad($org,$event,$v['ssid']));
            $this->q('INSERT INTO event_wifi_networks(id,organization_id,event_id,ssid,password_cipher,security_type,note,created_by,updated_by,created_at,updated_at,archived) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)',[$id,$org,$event,$v['ssid'],$cipher,$v['security_type'],$v['note'],$uid,$uid,$now,$now]);
        }return $id;
    }
    public function archive(array $user,int $event,string $id):void {
        $org=(int)$user['organization_id'];$this->event($org,$event);$row=$this->q('SELECT * FROM event_wifi_networks WHERE id=? AND organization_id=? AND event_id=? AND archived=0',[$id,$org,$event])->fetch(PDO::FETCH_ASSOC);
        if(!$row||!self::canManage($user,$row))throw new NeptuneEventWifiError('You cannot remove this network.');
        $this->q('UPDATE event_wifi_networks SET archived=1,updated_by=?,updated_at=? WHERE id=? AND organization_id=? AND event_id=?',[(int)$user['id'],time(),$id,$org,$event]);
    }
}
