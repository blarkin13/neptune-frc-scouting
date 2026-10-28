<?php
declare(strict_types=1);
const COMMUNITY_MC_PACKAGE = 'Neptune_Community_Analysis_Pilot_v1_Maintenance_Console.zip';
function community_analysis_settings(PDO $pdo, array $config): array {
    try {
        $raw=$pdo->query('SELECT settings_json FROM community_settings WHERE id=1')->fetchColumn();
        if($raw!==false) return json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    } catch(PDOException $e) {
        if(!in_array((string)$e->getCode(),['42S02','42P01'],true)) throw $e;
    }
    return $config['community_analysis']??[];
}
function community_analysis_install(PDO $pdo): void {
    neptune_migration_apply($pdo,'community.analysis.pilot.v1',COMMUNITY_MC_PACKAGE,function()use($pdo):void {
        $sql=file_get_contents(dirname(__DIR__).'/sql/2026-10-17-community-analysis.sql');
        if($sql===false) throw new RuntimeException('Pilot schema file is missing.');
        $sql=preg_replace('/^--.*$/m','',$sql);
        foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $pdo->exec($statement);
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_settings (id INT PRIMARY KEY, settings_json TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $q=$pdo->prepare('INSERT IGNORE INTO community_settings(id,settings_json) VALUES(1,?)');
        $q->execute([json_encode(['enabled'=>false,'secret'=>bin2hex(random_bytes(32)),'contributor_user_ids'=>[],'reviewer_user_ids'=>[]],JSON_THROW_ON_ERROR)]);
    });
}
function community_analysis_user_ids(string $text): array {
    $text=trim($text);
    if($text==='')return [];
    if(!preg_match('/^[1-9][0-9]*(?:[ ,\r\n]+[1-9][0-9]*)*$/D',$text))throw new InvalidArgumentException('Use positive user IDs separated by commas or spaces.');
    return array_values(array_unique(array_map('intval',preg_split('/[ ,\r\n]+/',$text))));
}
