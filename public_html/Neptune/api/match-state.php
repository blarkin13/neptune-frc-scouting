<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$id=(int)($_GET['match_id']??0);$sid=(int)($_GET['session_id']??0);
$s=$pdo->prepare('SELECT m.id,m.state,m.started_at,m.paused_at,m.total_pause_seconds,m.ended_at,m.run_number,gr.match_config_json config_json FROM matches m JOIN events e ON e.id=m.event_id JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=m.game_id WHERE m.id=? AND m.organization_id=?');
$s->execute([$id,$u['organization_id']]);
$m=$s->fetch();
if(!$m) json_response(['error'=>'not found'],404);
$t=game_timing($m['config_json']);
$physical=null;$elapsed=null;$remaining=null;$stage='waiting';$transitionRemaining=0;
if($m['started_at']){
    $end=time();
    if($m['state']==='paused'&&$m['paused_at']) $end=strtotime($m['paused_at'].' UTC');
    if($m['state']==='ended'&&$m['ended_at']) $end=strtotime($m['ended_at'].' UTC');
    $physical=max(0,$end-strtotime($m['started_at'].' UTC')-(int)$m['total_pause_seconds']);
    if($physical<$t['auton']){
        $elapsed=$physical;$stage='auton';
    } elseif($physical<($t['auton']+$t['transition'])){
        $elapsed=$t['auton'];$stage='transition';$transitionRemaining=($t['auton']+$t['transition'])-$physical;
    } else {
        $elapsed=min($t['duration'],$physical-$t['transition']);
        $stage=$elapsed>=($t['duration']-$t['endgame'])?'endgame':'teleop';
    }
    $remaining=max(0,$t['duration']-$elapsed);
    if($m['state']==='paused') $stage='paused';
    if($m['state']==='ended') $stage='ended';
}
$out=[
    'id'=>(int)$m['id'],'state'=>$m['state'],'run_number'=>(int)$m['run_number'],
    'server_time'=>gmdate('c'),'server_time_ms'=>(int)round(microtime(true)*1000),'clock_revision'=>2,
    'elapsed_seconds'=>$elapsed,'physical_elapsed_seconds'=>$physical,'remaining_seconds'=>$remaining,
    'stage'=>$stage,'transition_remaining_seconds'=>$transitionRemaining,'timing'=>$t
];
if($sid>0){
    $s=$pdo->prepare('SELECT id FROM scout_sessions WHERE id=? AND organization_id=? AND match_id=? AND user_id=? AND match_run_number=?');
    $s->execute([$sid,$u['organization_id'],$id,$u['id'],(int)$m['run_number']]);
    if($s->fetchColumn()){
        $s=$pdo->prepare("SELECT COUNT(*) action_count,COALESCE(SUM(points),0) score FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL");
        $s->execute([$u['organization_id'],$sid,(int)$m['run_number']]);$sum=$s->fetch()?:['action_count'=>0,'score'=>0];
        $s=$pdo->prepare("SELECT action_name,action_code,result,points FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $s->execute([$u['organization_id'],$sid,(int)$m['run_number']]);$last=$s->fetch()?:null;
        $out['scout_summary']=['action_count'=>(int)$sum['action_count'],'score'=>(float)$sum['score'],'last_action'=>$last];
    }
}
json_response($out);
