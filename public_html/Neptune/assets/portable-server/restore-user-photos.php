<?php
/**
 * Restore lossless profile-photo BLOBs after the portable JSON snapshot import.
 * Also safe to run directly on an existing local portable database.
 * Not a web endpoint. Never connects to AWS and never changes login data.
 *
 * Usage: php restore-user-photos.php /path/to/user-photos.jsonl /path/to/config.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
if ($argc !== 3 || !is_file($argv[1]) || !is_file($argv[2])) {
    fwrite(STDERR,"Usage: php restore-user-photos.php <user-photos.jsonl> <portable-config.php>\n");
    exit(1);
}
$config=require $argv[2];
if (!is_array($config) || empty($config['app']['portable_mode'])) {
    fwrite(STDERR,"Refusing photo restore: target is not a portable Neptune server.\n");
    exit(1);
}
$org=(int)($config['portable']['organization_id']??0);
if ($org<1) { fwrite(STDERR,"Portable organization ID is missing.\n"); exit(1); }

$src=@fopen($argv[1],'rb');
if (!$src) {fwrite(STDERR,"Could not read photo archive.\n");exit(1);}
$header=fgets($src);
$metadata=is_string($header)?json_decode($header,true):null;
if (!is_array($metadata) || ($metadata['format']??'')!=='neptune-portable-user-photos-v1'
    || (int)($metadata['organization_id']??0)!==$org) {
    fclose($src);
    fwrite(STDERR,"Photo archive does not belong to this portable organization.\n");
    exit(1);
}

try {
    $db=$config['db']??[];
    $pdo=new PDO(
        'mysql:host='.($db['host']??'127.0.0.1').';port='.(int)($db['port']??3306).';dbname='.($db['name']??'').';charset=utf8mb4',
        (string)($db['user']??''),(string)($db['pass']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $select=$pdo->prepare('SELECT p.image_data FROM scouting_assignment_user_photos p JOIN users u ON u.id=p.user_id AND u.organization_id=p.organization_id WHERE p.organization_id=? AND p.user_id=? LIMIT 1');
    $update=$pdo->prepare('UPDATE scouting_assignment_user_photos SET mime_type=?,image_data=? WHERE organization_id=? AND user_id=?');
    $insert=$pdo->prepare('INSERT INTO scouting_assignment_user_photos (organization_id,user_id,mime_type,image_data,updated_by,updated_at) VALUES (?,?,?,?,NULL,UTC_TIMESTAMP())');
    $checkUser=$pdo->prepare('SELECT 1 FROM users WHERE organization_id=? AND id=? LIMIT 1');

    $restored=0; $alreadyValid=0; $invalid=0; $missingUser=0;
    while (($line=fgets($src))!==false) {
        if (trim($line)==='') continue;
        $item=json_decode($line,true);
        $uid=(int)($item['user_id']??0);
        $mime=(string)($item['mime_type']??'');
        $encoded=$item['image_b64']??null;
        $raw=is_string($encoded)?base64_decode($encoded,true):false;
        $info=is_string($raw)?@getimagesizefromstring($raw):false;
        if ($uid<1 || !in_array($mime,['image/jpeg','image/png','image/webp'],true)
            || !is_string($raw) || $raw==='' || strlen($raw)>4*1024*1024
            || !is_array($info) || ($info['mime']??'')!==$mime
            || !hash_equals((string)($item['sha256']??''),hash('sha256',$raw))) {
            $invalid++; continue;
        }
        $checkUser->execute([$org,$uid]);
        if (!$checkUser->fetchColumn()) { $missingUser++; continue; }
        $select->execute([$org,$uid]);
        $old=$select->fetchColumn();
        if (is_resource($old)) $old=stream_get_contents($old);
        if (is_string($old) && is_array(@getimagesizefromstring($old))) {
            $alreadyValid++;continue; // Never overwrite a valid locally updated portrait.
        }
        if ($old!==false) {
            $update->bindValue(1,$mime,PDO::PARAM_STR);
            $update->bindValue(2,$raw,PDO::PARAM_LOB);
            $update->bindValue(3,$org,PDO::PARAM_INT);
            $update->bindValue(4,$uid,PDO::PARAM_INT);
            $update->execute();
        } else {
            $insert->bindValue(1,$org,PDO::PARAM_INT);
            $insert->bindValue(2,$uid,PDO::PARAM_INT);
            $insert->bindValue(3,$mime,PDO::PARAM_STR);
            $insert->bindValue(4,$raw,PDO::PARAM_LOB);
            $insert->execute();
        }
        $restored++;
    }
    fclose($src);
    echo "Profile photos: {$restored} restored, {$alreadyValid} already valid, {$missingUser} missing user references, {$invalid} invalid exports.\n";
    if ($invalid || $missingUser) exit(2);
} catch (Throwable $e) {
    fclose($src);
    fwrite(STDERR,'Portable photo restoration failed: '.$e->getMessage()."\n");
    exit(1);
}
