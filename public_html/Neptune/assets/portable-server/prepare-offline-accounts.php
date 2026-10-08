<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$configFile=(string)($argv[1]??'');
$outputFile=(string)($argv[2]??'');
$googleOnlyFile=(string)($argv[3]??'');

if($configFile===''||$outputFile===''||!is_file($configFile)){
    fwrite(STDERR,"Usage: php prepare-offline-accounts.php /path/to/config.php /path/to/output.txt [/path/to/google-only-users.json]\n");
    exit(1);
}

$config=require $configFile;
$db=$config['db']??[];
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host']??'127.0.0.1',(int)($db['port']??3306),$db['name']??'neptune_portable',$db['charset']??'utf8mb4');
$pdo=new PDO($dsn,(string)($db['user']??''),(string)($db['pass']??''),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

function np_col(PDO $pdo,string $table,string $column): bool {
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table,$column]);
    return (int)$s->fetchColumn()>0;
}

// Google cannot be required on an offline server.
$orgUpdates=[];
if(np_col($pdo,'organizations','google_signin_mode')) $orgUpdates[]="google_signin_mode='optional'";
if(np_col($pdo,'organizations','google_auto_provision')) $orgUpdates[]='google_auto_provision=0';
if($orgUpdates) $pdo->exec('UPDATE organizations SET '.implode(',',$orgUpdates));

$known=[];
if($googleOnlyFile!=='' && is_file($googleOnlyFile)){
    $meta=json_decode((string)file_get_contents($googleOnlyFile),true);
    if(is_array($meta) && ($meta['format']??'')==='neptune-portable-google-only-users-v1' && is_array($meta['users']??null)){
        foreach($meta['users'] as $entry){
            $id=(int)($entry['id']??0);
            if($id>0)$known[$id]=$entry;
        }
    }
}

$lines=[
    'Neptune Portable Offline Accounts',
    'Generated: '.gmdate('c'),
    '',
    'Existing Neptune password credentials were preserved.',
    'Temporary passwords below were created only for accounts that production',
    'identified as Google-created accounts with no established local password.',
    'These passwords exist ONLY on this portable server and never sync to AWS.',
    '',
];

if(!$known){
    $lines[]='No Google-only accounts required temporary offline credentials.';
    $lines[]='';
    file_put_contents($outputFile,implode(PHP_EOL,$lines).PHP_EOL);
    @chmod($outputFile,0600);
    fwrite(STDOUT,"Preserved all existing password credentials; no proven Google-only accounts required temporary passwords.\n");
    exit(0);
}

$ids=array_keys($known);
$placeholders=implode(',',array_fill(0,count($ids),'?'));
$s=$pdo->prepare("SELECT * FROM users WHERE active=1 AND id IN ({$placeholders}) ORDER BY organization_id,username");
$s->execute($ids);
$rows=$s->fetchAll();

$update=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?');
$count=0;
foreach($rows as $row){
    $id=(int)$row['id'];
    if(!isset($known[$id]))continue;

    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $raw='';
    for($i=0;$i<18;$i++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)];
    $password=substr($raw,0,6).'-'.substr($raw,6,6).'-'.substr($raw,12,6);
    $update->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
    $count++;

    $entry=$known[$id];
    $lines[]=(string)(($row['display_name']??'')?:($entry['display_name']??'')?:$row['username']);
    $lines[]='Username: '.$row['username'];
    if(isset($row['role']))$lines[]='Role: '.$row['role'];
    $email=(string)(($row['google_email']??'')?:($row['email']??'')?:($entry['google_email']??''));
    if($email!=='')$lines[]='Google email: '.$email;
    $lines[]='Temporary local password: '.$password;
    $lines[]='';
}

file_put_contents($outputFile,implode(PHP_EOL,$lines).PHP_EOL);
@chmod($outputFile,0600);
fwrite(STDOUT,"Preserved existing password credentials.\n");
fwrite(STDOUT,"Prepared {$count} proven Google-only offline account(s).\n");
fwrite(STDOUT,"Credentials: {$outputFile}\n");
