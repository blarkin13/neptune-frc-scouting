<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_team_logo_cache.php';

$org=0;$target=1000;$includeCustom=true;
foreach(array_slice($argv,1) as $arg){
    if(preg_match('/^--org=(\d+)$/',$arg,$m))$org=(int)$m[1];
    elseif(preg_match('/^--width=(\d+)$/',$arg,$m))$target=max(64,min(2400,(int)$m[1]));
}
if($org<1){
    $org=(int)($pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn()?:0);
}
if($org<1){fwrite(STDERR,"No organization found. Use --org=ID.\n");exit(2);}
$teams=neptune_team_logo_cached_teams($org);
$stats=['upscaled'=>0,'already_large'=>0,'missing'=>0,'unsupported'=>0,'error'=>0];
printf("Neptune Team Logo Upscaler\nOrganization: %d\nTarget width: %dpx\nCached logos: %d\n\n",$org,$target,count($teams));
foreach($teams as $i=>$team){
    try{$r=neptune_team_logo_upscale($org,(int)date('Y'),(int)$team,$target,true);}catch(Throwable $e){$r=['ok'=>false,'status'=>'error','message'=>$e->getMessage()];}
    $st=(string)($r['status']??'error');if(!isset($stats[$st]))$st='error';$stats[$st]++;
    printf("[%d/%d] #%d %-13s %s\n",$i+1,count($teams),$team,$st,(string)($r['message']??''));
}
printf("\nFinished: %d upscaled, %d already >= %dpx, %d unsupported, %d errors.\n",$stats['upscaled'],$stats['already_large'],$target,$stats['unsupported'],$stats['error']);
exit($stats['error']?1:0);
