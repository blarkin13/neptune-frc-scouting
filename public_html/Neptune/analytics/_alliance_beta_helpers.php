<?php
require_once __DIR__ . '/_alliance_helpers.php';

function alliance_beta_schema_ready(PDO $pdo): bool {
    $names=['alliance_selection_beta_event_state','alliance_selection_beta_team_state'];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)");
    $q->execute($names);
    return (int)$q->fetchColumn()===2;
}

function alliance_beta_install_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS alliance_selection_beta_event_state (
      organization_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      state_json LONGTEXT NOT NULL,
      version INT UNSIGNED NOT NULL DEFAULT 1,
      updated_by BIGINT UNSIGNED NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (organization_id,event_id),
      KEY idx_asbeta_event (event_id),
      CONSTRAINT fk_asbeta_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
      CONSTRAINT fk_asbeta_event_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
      CONSTRAINT fk_asbeta_event_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS alliance_selection_beta_team_state (
      organization_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      strategy_frc_team_number INT UNSIGNED NOT NULL,
      state_json LONGTEXT NOT NULL,
      version INT UNSIGNED NOT NULL DEFAULT 1,
      updated_by BIGINT UNSIGNED NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (organization_id,event_id,strategy_frc_team_number),
      KEY idx_asbeta_team_event (event_id),
      KEY idx_asbeta_team_number (strategy_frc_team_number),
      CONSTRAINT fk_asbeta_team_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
      CONSTRAINT fk_asbeta_team_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
      CONSTRAINT fk_asbeta_team_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function alliance_beta_event(PDO $pdo,int $org,int $eventId): ?array {
    $s=$pdo->prepare('SELECT e.*,g.name game_name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
    $s->execute([$eventId,$org]);
    return $s->fetch()?:null;
}

function alliance_beta_org_teams(PDO $pdo,int $org): array {
    $s=$pdo->prepare('SELECT id,frc_team_number,nickname,display_name,active FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');
    $s->execute([$org]);
    return $s->fetchAll();
}

function alliance_beta_strategy_team(PDO $pdo,int $org,int $team): ?array {
    $s=$pdo->prepare('SELECT id,frc_team_number,nickname,display_name FROM teams WHERE organization_id=? AND frc_team_number=? AND active=1 LIMIT 1');
    $s->execute([$org,$team]);
    return $s->fetch()?:null;
}

function alliance_beta_roster(PDO $pdo,int $eventId): array {
    $s=$pdo->prepare('SELECT frc_team_number,nickname,city,state_prov,country FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $s->execute([$eventId]);
    return $s->fetchAll();
}

function alliance_beta_empty_alliances(): array {
    $out=[];
    foreach(range(1,8) as $n) $out[(string)$n]=['captain'=>null,'pick1'=>null,'pick2'=>null,'backup'=>null];
    return $out;
}
function alliance_beta_empty_pick_lists(): array {
    return ['1'=>array_fill(0,6,null),'2'=>array_fill(0,6,null),'3'=>array_fill(0,6,null),'4'=>array_fill(0,6,null)];
}
function alliance_beta_default_event_state(array $rankingRows=[]): array {
    $state=['alliances'=>alliance_beta_empty_alliances(),'drafted_elsewhere'=>[],'declined'=>[],'broken'=>[]];
    alliance_beta_seed_captains($state,$rankingRows);
    return $state;
}
function alliance_beta_default_team_state(): array {
    return [
        'pick_lists'=>alliance_beta_empty_pick_lists(),
        'unavailable'=>[],
        'do_not_pick'=>[],
        'favorites'=>[],
        'alliance_tags'=>[],
        'planning'=>[
            'scenario'=>'auto',
            'needs'=>['auto'=>2,'teleop'=>2,'defense'=>2,'endgame'=>2,'reliability'=>3],
        ],
    ];
}

function alliance_beta_seed_captains(array &$state,array $rankingRows): void {
    foreach(range(1,8) as $a) $state['alliances'][(string)$a]['captain']=null;
    $i=1;
    foreach($rankingRows as $row){
        $team=(int)($row['team']??0);
        if($team<1)continue;
        $state['alliances'][(string)$i]['captain']=$team;
        if(++$i>8)break;
    }
}
function alliance_beta_has_any_captain(array $state): bool {
    foreach(range(1,8) as $a) if((int)($state['alliances'][(string)$a]['captain']??0)>0)return true;
    return false;
}
function alliance_beta_has_any_picks(array $state): bool {
    foreach(range(1,8) as $a) foreach(['pick1','pick2','backup'] as $slot) if((int)($state['alliances'][(string)$a][$slot]??0)>0)return true;
    return false;
}
function alliance_beta_apply_default_captains(array &$state,array $rankingRows): void {
    if(!$rankingRows)return;
    if(!alliance_beta_has_any_captain($state) && !alliance_beta_has_any_picks($state)) alliance_beta_seed_captains($state,$rankingRows);
}

function alliance_beta_positive_team($v): ?int {
    return is_numeric($v) && (int)$v>0 ? (int)$v : null;
}
function alliance_beta_normalize_event_state(array $raw,array $rankingRows=[]): array {
    $out=alliance_beta_default_event_state();
    foreach(range(1,8) as $a){
        $row=$raw['alliances'][(string)$a]??$raw['alliances'][$a]??[];
        if(!is_array($row)) $row=[];
        foreach(['captain','pick1','pick2','backup'] as $slot) $out['alliances'][(string)$a][$slot]=alliance_beta_positive_team($row[$slot]??null);
    }
    foreach(['drafted_elsewhere','declined','broken'] as $key){
        foreach((array)($raw[$key]??[]) as $team=>$flag){$t=(int)$team;if($t>0&&$flag)$out[$key][(string)$t]=true;}
    }
    alliance_beta_apply_default_captains($out,$rankingRows);
    return $out;
}
function alliance_beta_normalize_team_state(array $raw): array {
    $out=alliance_beta_default_team_state();
    foreach(['1','2','3','4'] as $tier){
        $row=is_array($raw['pick_lists'][$tier]??null)?array_values($raw['pick_lists'][$tier]):[];
        foreach(range(0,5) as $i)$out['pick_lists'][$tier][$i]=alliance_beta_positive_team($row[$i]??null);
    }
    foreach(['unavailable','do_not_pick','favorites'] as $key){
        foreach((array)($raw[$key]??[]) as $team=>$flag){$t=(int)$team;if($t>0&&$flag)$out[$key][(string)$t]=true;}
    }
    $allowed=array_flip(alliance_tag_ids());
    foreach((array)($raw['alliance_tags']??[]) as $team=>$tags){
        $t=(int)$team;if($t<1||!is_array($tags))continue;
        $clean=[];
        foreach($tags as $tag){$tag=(string)$tag;if(isset($allowed[$tag])&&!in_array($tag,$clean,true))$clean[]=$tag;}
        if($clean)$out['alliance_tags'][(string)$t]=array_slice($clean,0,8);
    }
    $planning=is_array($raw['planning']??null)?$raw['planning']:[];
    $scenario=(string)($planning['scenario']??'auto');
    if(!in_array($scenario,['auto','captain','first_pick','second_pick','bubble'],true))$scenario='auto';
    $out['planning']['scenario']=$scenario;
    foreach(['auto','teleop','defense','endgame','reliability'] as $need){
        $v=(int)($planning['needs'][$need]??$out['planning']['needs'][$need]);
        $out['planning']['needs'][$need]=max(0,min(3,$v));
    }
    return $out;
}

function alliance_beta_load_event_state(PDO $pdo,int $org,int $eventId,bool $forUpdate=false,array $rankingRows=[]): array {
    $sql='SELECT state_json,version FROM alliance_selection_beta_event_state WHERE organization_id=? AND event_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([$org,$eventId]);$r=$s->fetch();
    if(!$r)return ['state'=>alliance_beta_default_event_state($rankingRows),'version'=>0];
    $j=json_decode((string)$r['state_json'],true);
    return ['state'=>alliance_beta_normalize_event_state(is_array($j)?$j:[],$rankingRows),'version'=>(int)$r['version']];
}
function alliance_beta_save_event_state(PDO $pdo,int $org,int $eventId,array $state,int $userId,array $rankingRows=[]): int {
    $state=alliance_beta_normalize_event_state($state,$rankingRows);
    $s=$pdo->prepare('INSERT INTO alliance_selection_beta_event_state(organization_id,event_id,state_json,version,updated_by) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=version+1,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
    $s->execute([$org,$eventId,json_encode($state,JSON_UNESCAPED_SLASHES),$userId?:null]);
    $q=$pdo->prepare('SELECT version FROM alliance_selection_beta_event_state WHERE organization_id=? AND event_id=?');$q->execute([$org,$eventId]);return (int)$q->fetchColumn();
}
function alliance_beta_load_team_state(PDO $pdo,int $org,int $eventId,int $strategyTeam,bool $forUpdate=false): array {
    $sql='SELECT state_json,version FROM alliance_selection_beta_team_state WHERE organization_id=? AND event_id=? AND strategy_frc_team_number=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([$org,$eventId,$strategyTeam]);$r=$s->fetch();
    if(!$r)return ['state'=>alliance_beta_default_team_state(),'version'=>0];
    $j=json_decode((string)$r['state_json'],true);
    return ['state'=>alliance_beta_normalize_team_state(is_array($j)?$j:[]),'version'=>(int)$r['version']];
}
function alliance_beta_save_team_state(PDO $pdo,int $org,int $eventId,int $strategyTeam,array $state,int $userId): int {
    $state=alliance_beta_normalize_team_state($state);
    $s=$pdo->prepare('INSERT INTO alliance_selection_beta_team_state(organization_id,event_id,strategy_frc_team_number,state_json,version,updated_by) VALUES(?,?,?,?,1,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),version=version+1,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
    $s->execute([$org,$eventId,$strategyTeam,json_encode($state,JSON_UNESCAPED_SLASHES),$userId?:null]);
    $q=$pdo->prepare('SELECT version FROM alliance_selection_beta_team_state WHERE organization_id=? AND event_id=? AND strategy_frc_team_number=?');$q->execute([$org,$eventId,$strategyTeam]);return (int)$q->fetchColumn();
}
function alliance_beta_roster_has(array $roster,int $team): bool {foreach($roster as $r)if((int)$r['frc_team_number']===$team || (int)($r['team']??0)===$team)return true;return false;}
function alliance_beta_shared_drafted(array $shared): array {
    $out=[];
    foreach($shared['alliances'] as $a=>$row)foreach(['pick1','pick2','backup'] as $slot){$t=(int)($row[$slot]??0);if($t>0)$out[(string)$t]=['alliance'=>(int)$a,'slot'=>$slot];}
    foreach($shared['drafted_elsewhere'] as $team=>$flag)if($flag&&!isset($out[(string)$team]))$out[(string)$team]=['alliance'=>null,'slot'=>'elsewhere'];
    return $out;
}

function alliance_beta_production_reference(PDO $pdo,int $org,int $eventId): array {
    $empty=['available'=>false,'draft_name'=>'','pick_lists'=>alliance_beta_empty_pick_lists(),'favorites'=>[],'do_not_pick'=>[],'unavailable'=>[],'alliance_tags'=>[],'matchup_model'=>alliance_matchup_settings(),'captain_picks_allowed'=>true];
    if(!alliance_schema_ready($pdo))return $empty;
    try{
        $loaded=alliance_load_workspace($pdo,$org,$eventId,false);
        $w=$loaded['workspace'];
        $active=(string)($w['active_id']??array_key_first($w['scenarios']??[]));
        $s=$w['scenarios'][$active]??null;
        if(!is_array($s))return $empty;
        $favorites=[];$dnp=[];
        foreach((array)($s['statuses']??[]) as $team=>$status){
            $t=(int)$team;if($t<1||!is_array($status))continue;
            if(!empty($status['favorite']))$favorites[(string)$t]=true;
            if((string)($status['state']??'')==='dnp')$dnp[(string)$t]=true;
        }
        return [
            'available'=>true,
            'draft_name'=>(string)($s['name']??'Current Strategy'),
            'pick_lists'=>$s['pick_lists']??alliance_beta_empty_pick_lists(),
            'favorites'=>$favorites,
            'do_not_pick'=>$dnp,
            'unavailable'=>$s['pick_list_taken']??[],
            'alliance_tags'=>$s['alliance_tags']??[],
            'matchup_model'=>alliance_matchup_settings(is_array($w['settings']['matchup_model']??null)?$w['settings']['matchup_model']:[]),
            'captain_picks_allowed'=>(bool)($w['settings']['captain_picks_allowed']??true),
        ];
    }catch(Throwable $e){
        error_log('Alliance beta production reference: '.$e->getMessage());
        return $empty;
    }
}
