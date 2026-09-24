<?php

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_augur_prediction_settings.php';

function alliance_required_tables(): array {
    return ['alliance_selection_state'];
}

function alliance_schema_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $s = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alliance_selection_state'");
    $ready = ((int)$s->fetchColumn()) === 1;
    return $ready;
}

function alliance_event(PDO $pdo, int $org, int $eventId): ?array {
    $s = $pdo->prepare(
        'SELECT e.*,g.name game_name,g.season_year
         FROM events e
         JOIN games g ON g.id=e.game_id
         WHERE e.id=? AND e.organization_id=?
         LIMIT 1'
    );
    $s->execute([$eventId, $org]);
    return $s->fetch() ?: null;
}

function alliance_team_on_roster(PDO $pdo, int $eventId, int $team): bool {
    $s = $pdo->prepare('SELECT 1 FROM event_teams WHERE event_id=? AND frc_team_number=? LIMIT 1');
    $s->execute([$eventId, $team]);
    return (bool)$s->fetchColumn();
}

function alliance_empty_slots(): array {
    $out=[];
    foreach(range(1,8) as $a) $out[$a]=['captain'=>null,'pick1'=>null,'pick2'=>null,'backup'=>null];
    return $out;
}

function alliance_tag_ids(): array {
    return ['first_pick','second_pick','third_pick','fourth_pick','defense_pick','best_defense','high_ceiling','reliable','sleeper','versatile'];
}

function alliance_empty_pick_lists(): array {
    $out=[];
    foreach(['1','2','3','4'] as $tier) $out[$tier]=array_fill(0,6,null);
    return $out;
}

function alliance_new_scenario(string $id='s1', string $name='Scenario 1'): array {
    return [
        'id'=>$id,
        'name'=>$name,
        'mode'=>'scenario',
        'slots'=>alliance_empty_slots(),
        'statuses'=>[],
        'alliance_tags'=>[],
        'pick_lists'=>alliance_empty_pick_lists(),
        'undo'=>[],
        'redo'=>[],
    ];
}


function alliance_matchup_settings(array $raw=[]): array {
    return augur_prediction_settings($raw);
}

function alliance_default_workspace(): array {
    return [
        'active_id'=>'s1',
        'next_id'=>2,
        'settings'=>[
            // Preserve the behavior Neptune used before this setting existed.
            // Organizations can turn this off from the Team Pool when an event
            // does not allow alliance captains to select other captains.
            'captain_picks_allowed'=>true,
            'matchup_model'=>alliance_matchup_settings(),
        ],
        'scenarios'=>['s1'=>alliance_new_scenario()],
    ];
}

function alliance_normalize_workspace(array $w): array {
    $scenarios=is_array($w['scenarios']??null)?$w['scenarios']:[];
    if(!$scenarios) $scenarios=['s1'=>alliance_new_scenario()];
    $normalized=[];
    foreach($scenarios as $id=>$s){
        if(!is_array($s)) continue;
        $sid=preg_replace('/[^A-Za-z0-9_-]/','',(string)($s['id']??$id));
        if($sid==='') $sid='s'.(count($normalized)+1);
        $slots=alliance_empty_slots();
        foreach(range(1,8) as $a){
            $row=$s['slots'][$a]??$s['slots'][(string)$a]??[];
            if(!is_array($row)) $row=[];
            foreach(['captain','pick1','pick2','backup'] as $slot){
                $v=$row[$slot]??null;
                $slots[$a][$slot]=is_numeric($v)&&((int)$v)>0?(int)$v:null;
            }
        }
        $statuses=[];
        foreach(($s['statuses']??[]) as $team=>$row){
            $team=(int)$team;
            if($team<1||!is_array($row)) continue;
            $state=(string)($row['state']??'available');
            if(!in_array($state,['available','declined','broken','do_not_pick'],true)) $state='available';
            $statuses[$team]=['state'=>$state,'favorite'=>!empty($row['favorite'])];
        }
        $allowedTagIds=array_flip(alliance_tag_ids());
        $allianceTags=[];
        foreach(($s['alliance_tags']??[]) as $team=>$tags){
            $team=(int)$team;
            if($team<1||!is_array($tags)) continue;
            $clean=[];
            foreach($tags as $tag){
                $tag=(string)$tag;
                if(isset($allowedTagIds[$tag])&&!in_array($tag,$clean,true))$clean[]=$tag;
            }
            if($clean)$allianceTags[$team]=array_slice($clean,0,8);
        }
        $pickLists=alliance_empty_pick_lists();
        $rawPickLists=is_array($s['pick_lists']??null)?$s['pick_lists']:[];
        foreach(['1','2','3','4'] as $tier){
            $source=is_array($rawPickLists[$tier]??null)?array_values($rawPickLists[$tier]):[];
            foreach(range(0,5) as $index){
                $value=$source[$index]??null;
                $pickLists[$tier][$index]=is_numeric($value)&&((int)$value)>0?(int)$value:null;
            }
        }
        $normalized[$sid]=[
            'id'=>$sid,
            'name'=>trim((string)($s['name']??'Scenario'))?:'Scenario',
            'mode'=>($s['mode']??'scenario')==='live'?'live':'scenario',
            'slots'=>$slots,
            'statuses'=>$statuses,
            'alliance_tags'=>$allianceTags,
            'pick_lists'=>$pickLists,
            'undo'=>array_slice(is_array($s['undo']??null)?$s['undo']:[],-25),
            'redo'=>array_slice(is_array($s['redo']??null)?$s['redo']:[],-25),
        ];
    }
    if(!$normalized) $normalized=['s1'=>alliance_new_scenario()];
    $active=(string)($w['active_id']??'');
    if(!isset($normalized[$active])) $active=(string)array_key_first($normalized);
    $next=max(2,(int)($w['next_id']??2));
    $settings=is_array($w['settings']??null)?$w['settings']:[];
    $captainPicksAllowed=array_key_exists('captain_picks_allowed',$settings)
        ? (bool)$settings['captain_picks_allowed']
        : true;
    $matchupModel=alliance_matchup_settings(is_array($settings['matchup_model']??null)?$settings['matchup_model']:[]);
    return [
        'active_id'=>$active,
        'next_id'=>$next,
        'settings'=>[
            'captain_picks_allowed'=>$captainPicksAllowed,
            'matchup_model'=>$matchupModel,
        ],
        'scenarios'=>$normalized,
    ];
}

function alliance_load_workspace(PDO $pdo,int $org,int $eventId,bool $forUpdate=false): array {
    $sql='SELECT state_json,version FROM alliance_selection_state WHERE organization_id=? AND event_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([$org,$eventId]);$row=$s->fetch();
    if(!$row) return ['workspace'=>alliance_default_workspace(),'version'=>0,'exists'=>false];
    $w=json_decode((string)$row['state_json'],true);
    if(!is_array($w)) $w=alliance_default_workspace();
    return ['workspace'=>alliance_normalize_workspace($w),'version'=>(int)$row['version'],'exists'=>true];
}

function alliance_save_workspace(PDO $pdo,int $org,int $eventId,array $workspace,int $userId): int {
    $workspace=alliance_normalize_workspace($workspace);
    $json=json_encode($workspace,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $s=$pdo->prepare(
        'INSERT INTO alliance_selection_state(organization_id,event_id,state_json,version,updated_by)
         VALUES(?,?,?,1,?)
         ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=version+1,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
    );
    $s->execute([$org,$eventId,$json,$userId?:null]);
    $q=$pdo->prepare('SELECT version FROM alliance_selection_state WHERE organization_id=? AND event_id=?');
    $q->execute([$org,$eventId]);
    return (int)$q->fetchColumn();
}

function alliance_active_scenario(array &$workspace): array {
    $id=(string)$workspace['active_id'];
    if(!isset($workspace['scenarios'][$id])){
        $id=(string)array_key_first($workspace['scenarios']);
        $workspace['active_id']=$id;
    }
    return $workspace['scenarios'][$id];
}

function alliance_snapshot(array $scenario): array {
    return [
        'slots'=>$scenario['slots'],
        'statuses'=>$scenario['statuses'],
        'alliance_tags'=>$scenario['alliance_tags']??[],
        'pick_lists'=>$scenario['pick_lists']??alliance_empty_pick_lists(),
    ];
}

function alliance_push_undo(array &$scenario): void {
    $scenario['undo'][]=alliance_snapshot($scenario);
    $scenario['undo']=array_slice($scenario['undo'],-25);
    $scenario['redo']=[];
}

function alliance_restore_snapshot(array &$scenario,array $snapshot): void {
    if(isset($snapshot['slots'])&&is_array($snapshot['slots'])) $scenario['slots']=$snapshot['slots'];
    if(isset($snapshot['statuses'])&&is_array($snapshot['statuses'])) $scenario['statuses']=$snapshot['statuses'];
    if(isset($snapshot['alliance_tags'])&&is_array($snapshot['alliance_tags'])) $scenario['alliance_tags']=$snapshot['alliance_tags'];
    if(isset($snapshot['pick_lists'])&&is_array($snapshot['pick_lists'])) $scenario['pick_lists']=$snapshot['pick_lists'];
}

function alliance_rankings_for_event(array $event): array {
    $eventKey=trim((string)($event['tba_event_key']??''));
    if($eventKey==='') return ['rows'=>[],'label'=>'Points','precision'=>2,'error'=>'This event is not linked to The Blue Alliance.'];
    require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
    try{
        $raw=tba_get('event/'.rawurlencode($eventKey).'/rankings',60);
        $info=is_array($raw['sort_order_info']??null)?$raw['sort_order_info']:[];
        $first=$info[0]??[];
        $label=trim((string)($first['name']??''))?:'Points';
        $precision=is_numeric($first['precision']??null)?(int)$first['precision']:2;
        $rows=[];
        foreach((array)($raw['rankings']??[]) as $r){
            if(!is_array($r)) continue;
            $key=(string)($r['team_key']??'');
            if(!preg_match('/^frc(\d+)$/',$key,$m)) continue;
            $record=is_array($r['record']??null)?$r['record']:[];
            $sort=is_array($r['sort_orders']??null)?$r['sort_orders']:[];
            $rows[]=[
                'team'=>(int)$m[1],
                'rank'=>(int)($r['rank']??0),
                'points'=>isset($sort[0])&&is_numeric($sort[0])?(float)$sort[0]:0.0,
                'wins'=>(int)($record['wins']??0),
                'losses'=>(int)($record['losses']??0),
                'ties'=>(int)($record['ties']??0),
                'matches'=>(int)($r['matches_played']??0),
            ];
        }
        usort($rows,static fn($a,$b)=>($a['rank']<=>$b['rank']) ?: ($b['points']<=>$a['points']));
        return ['rows'=>$rows,'label'=>$label,'precision'=>$precision,'error'=>''];
    }catch(Throwable $e){
        return ['rows'=>[],'label'=>'Points','precision'=>2,'error'=>$e->getMessage()];
    }
}

function alliance_seed_captains(array &$scenario,array $rankingRows): void {
    foreach(range(1,8) as $a) $scenario['slots'][$a]['captain']=null;
    $i=1;
    foreach($rankingRows as $row){
        $team=(int)($row['team']??0);
        if($team<1) continue;
        $scenario['slots'][$i]['captain']=$team;
        if(++$i>8) break;
    }
}

function alliance_find_captain_alliance(array $scenario,int $team): int {
    foreach(range(1,8) as $a) if((int)($scenario['slots'][$a]['captain']??0)===$team) return $a;
    return 0;
}

function alliance_team_used_as_pick(array $scenario,int $team): bool {
    foreach(range(1,8) as $a) foreach(['pick1','pick2','backup'] as $slot) if((int)($scenario['slots'][$a][$slot]??0)===$team) return true;
    return false;
}

function alliance_captain_is_locked(array $scenario,int $team,bool $captainPicksAllowed=true): bool {
    $alliance=alliance_find_captain_alliance($scenario,$team);
    if($alliance===0)return false;
    if($alliance===1)return true;
    if(!$captainPicksAllowed)return true;
    foreach(['pick1','pick2','backup'] as $slot) if((int)($scenario['slots'][$alliance][$slot]??0)>0)return true;
    return false;
}

function alliance_promote_after_captain_pick(array &$scenario,int $fromAlliance,array $rankingRows,int $excludeTeam=0): void {
    for($a=$fromAlliance;$a<8;$a++) $scenario['slots'][$a]['captain']=$scenario['slots'][$a+1]['captain']??null;
    $used=[];
    if($excludeTeam>0)$used[$excludeTeam]=true;
    foreach(range(1,8) as $a) foreach(['captain','pick1','pick2','backup'] as $slot){
        $t=(int)($scenario['slots'][$a][$slot]??0); if($t>0)$used[$t]=true;
    }
    $next=null;
    foreach($rankingRows as $row){
        $t=(int)($row['team']??0); if($t<1||isset($used[$t]))continue;
        $st=$scenario['statuses'][$t]['state']??'available';
        if(in_array($st,['declined','broken','do_not_pick'],true))continue;
        $next=$t;break;
    }
    $scenario['slots'][8]['captain']=$next;
}
