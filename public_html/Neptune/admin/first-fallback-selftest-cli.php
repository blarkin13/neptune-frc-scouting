<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/_tba_auto_discovery.php';
function nfs_test(bool $ok,string $description): void {
    echo ($ok?'PASS ':'FAIL ').$description."\n";
    if(!$ok)$GLOBALS['nfs_failed']++;
}
$GLOBALS['nfs_failed']=0;
$event=['name'=>'STEM Gals - NTX Tournament of Robots','start_date'=>'2026-10-10'];
$tba=['key'=>'2026txmck','event_code'=>'TXMCK','name'=>'NTX Tournament of Robots','start_date'=>'2026-10-10'];
$teams=array_map(static fn($n)=>['frc_team_number'=>$n],[6369,6773,8749,9992,10340,7506]);
$remote=array_map(static fn($n)=>['team_number'=>$n],[6369,6773,8749,9992,10340,7506]);
$r=neptune_tba_discovery_assess($event,$tba,'TXMCK',$teams,$remote);
nfs_test($r['safe']&&$r['code_match']&&$r['team_overlap']===6,'Matching code/name/date/roster auto-links');
$wrongDate=$tba;$wrongDate['start_date']='2026-11-15';
nfs_test(!neptune_tba_discovery_assess($event,$wrongDate,'TXMCK',$teams,$remote)['safe'],'Rejects wrong event date');
$wrongTeams=array_map(static fn($n)=>['team_number'=>$n],[1,2,3,4,5,6369]);
nfs_test(!neptune_tba_discovery_assess($event,$tba,'TXMCK',$teams,$wrongTeams)['safe'],'Rejects roster conflict even with exact event code');
$similar=$tba;$similar['event_code']='OTHER';$similar['name']='Completely Different Tournament';
nfs_test(!neptune_tba_discovery_assess($event,$similar,'TXMCK',$teams,$remote)['safe'],'Rejects unrelated event/code');
$manualMatch=$tba;$manualMatch['event_code']='DIFFERENT';
nfs_test(neptune_tba_discovery_assess($event,$manualMatch,'TXMCK',$teams,$remote)['safe'],'Can link same named, dated, roster-confirmed event even when provider codes differ');
$tbaMatch=['comp_level'=>'qm','match_number'=>1,'alliances'=>[
    'red'=>['team_keys'=>['frc6369','frc6773','frc8749']],
    'blue'=>['team_keys'=>['frc9992','frc10340','frc7506']]]];
$stationRows=[];
foreach(['Red'=>[6369,6773,8749],'Blue'=>[9992,10340,7506]] as $a=>$nums){
    foreach($nums as $n)$stationRows[]=['match_number'=>1,'alliance'=>$a,'frc_team_number'=>$n];
}
nfs_test(neptune_tba_discovery_schedule_compare($stationRows,[$tbaMatch])['safe'],'Matching FIRST/TBA station rosters permit handoff');
$wrongMatch=$tbaMatch;$wrongMatch['alliances']['red']['team_keys'][0]='frc3005';
nfs_test(!neptune_tba_discovery_schedule_compare($stationRows,[$wrongMatch])['safe'],'Conflicting FIRST/TBA station rosters block handoff');
nfs_test(neptune_first_discovery_due(null),'Never-checked event is due');
nfs_test(!neptune_first_discovery_due(gmdate('Y-m-d H:i:s',time()-120),30),'Discovery is throttled');
$first=file_get_contents(__DIR__.'/_first_events.php')?:'';
$tbaSource=file_get_contents(__DIR__.'/_tba_live_sync.php')?:'';
$scheduler=file_get_contents(__DIR__.'/tba-scheduled-sync-cli.php')?:'';
nfs_test(str_contains($first,'neptune_first_link_save(')&&!str_contains($first,'UPDATE events SET event_code='),'FIRST event code cannot be overwritten by TBA metadata');
nfs_test(str_contains($first,'IF(tba_match_key IS NOT NULL AND red_score IS NOT NULL')&&str_contains($tbaSource,'red_score=COALESCE(VALUES(red_score),red_score)'),'TBA score precedence is preserved');
nfs_test(str_contains($scheduler,"function nts_weekly_refresh(")&&str_contains($scheduler,"'cron_tba_weekly'"),'Monday weekly function and job still present');
exit($GLOBALS['nfs_failed']?1:0);
