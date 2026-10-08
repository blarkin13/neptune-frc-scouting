<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only.\n");
    exit(1);
}

$snapshotFile=(string)($argv[1]??'');
$configFile=(string)($argv[2]??'');
if($snapshotFile===''||$configFile===''||!is_file($snapshotFile)||!is_file($configFile)){
    fwrite(STDERR,"Usage: php import-event.php /path/to/event.json /path/to/config.php\n");
    exit(1);
}

$config=require $configFile;
$db=$config['db']??[];
$dsn=sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host']??'127.0.0.1',
    (int)($db['port']??3306),
    $db['name']??'neptune_portable',
    $db['charset']??'utf8mb4'
);
$pdo=new PDO($dsn,(string)($db['user']??''),(string)($db['pass']??''),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$raw=file_get_contents($snapshotFile);
$data=json_decode((string)$raw,true);
if(!is_array($data)||($data['format']??'')!=='neptune-portable-snapshot-v1'||!is_array($data['tables']??null)){
    throw new RuntimeException('Portable event snapshot is invalid.');
}

$tables=$data['tables'];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try{
    foreach($tables as $table=>$rows){
        if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$table)||!is_array($rows)||!$rows)continue;

        $exists=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $exists->execute([(string)$table]);
        if((int)$exists->fetchColumn()===0){
            fwrite(STDOUT,"Skipping missing table: {$table}\n");
            continue;
        }

        $columns=array_keys((array)$rows[0]);
        $columns=array_values(array_filter($columns,static fn($c)=>preg_match('/^[A-Za-z0-9_]+$/',(string)$c)));
        if(!$columns)continue;

        $quoted=array_map(static fn($c)=>'`'.$c.'`',$columns);
        $placeholders=implode(',',array_fill(0,count($columns),'?'));
        $updates=implode(',',array_map(
            static fn($c)=>'`'.$c.'`=VALUES(`'.$c.'`)',
            $columns
        ));
        $sql='INSERT INTO `'.$table.'` ('.implode(',',$quoted).') VALUES('.$placeholders.') '
            .'ON DUPLICATE KEY UPDATE '.$updates;
        $stmt=$pdo->prepare($sql);

        $count=0;
        foreach($rows as $row){
            if(!is_array($row))continue;
            $values=[];
            foreach($columns as $col)$values[]=$row[$col]??null;
            $stmt->execute($values);
            $count++;
        }
        fwrite(STDOUT,"Imported {$table}: {$count}\n");
    }
}finally{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

fwrite(STDOUT,"Portable event snapshot import complete.\n");
