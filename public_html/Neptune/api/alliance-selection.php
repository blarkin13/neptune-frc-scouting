<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_alliance_helpers.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';

$u=require_login();
$org=(int)$u['organization_id'];
$userId=(int)$u['id'];
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);

if(!alliance_schema_ready($pdo)){
    json_response(['ok'=>false,'error'=>'schema_missing','message'=>'Run sql/2026-09-21_alliance-selection-state.sql first.'],503);
}

function alliance_api_event(PDO $pdo,int $org,int $eventId): array {
    $event=alliance_event($pdo,$org,$eventId);
    if(!$event) json_response(['ok'=>false,'message'=>'Event not found for this organization.'],404);
    return $event;
}
function alliance_api_require_edit(bool $canEdit): void {
    if(!$canEdit) access_denied('Alliance Selection changes require Strategy, Admin, or Owner access.');
}
function alliance_active_id(array $w): string { return (string)($w['active_id']??array_key_first($w['scenarios'])); }
function alliance_draft_list(array $w): array {
    $out=[];
    foreach($w['scenarios'] as $id=>$s){
        $out[]=['id'=>$id,'name'=>(string)$s['name'],'mode'=>(string)$s['mode'],'status'=>'active','revision_cursor'=>count($s['undo']??[]),'max_revision'=>count($s['undo']??[])+count($s['redo']??[])];
    }
    return $out;
}
function alliance_selected_map(array $scenario): array {
    $selected=[];
    foreach(range(1,8) as $a) foreach(['pick1','pick2','backup'] as $slot){
        $t=(int)($scenario['slots'][$a][$slot]??0); if($t>0)$selected[$t]=true;
    }
    return $selected;
}
function alliance_public_statuses(array $scenario): array {
    $statuses=$scenario['statuses'];
    foreach(alliance_selected_map($scenario) as $team=>$_){
        $statuses[$team]??=['state'=>'available','favorite'=>false];
        $statuses[$team]['state']='selected';
    }
    return $statuses;
}
function alliance_has_picks(array $scenario): bool {
    foreach(range(1,8) as $a) foreach(['pick1','pick2','backup'] as $slot) if((int)($scenario['slots'][$a][$slot]??0)>0)return true;
    return false;
}
function alliance_unique_scenario_name(array $w,string $base): string {
    $used=[];foreach($w['scenarios'] as $s)$used[strtolower((string)$s['name'])]=true;
    $name=trim($base)?:'Scenario';$try=$name;$i=2;
    while(isset($used[strtolower($try)]))$try=$name.' '.$i++;
    return $try;
}
function alliance_prepare_state(PDO $pdo,int $org,int $eventId,bool $canEdit=false,int $userId=0,string $requestedDraftId=''): array {
    $event=alliance_api_event($pdo,$org,$eventId);
    $stored=alliance_load_workspace($pdo,$org,$eventId,false);
    $w=$stored['workspace'];
    $rankings=alliance_rankings_for_event($event);
    $activeId=alliance_active_id($w);
    if($requestedDraftId!=='' && isset($w['scenarios'][$requestedDraftId])) $activeId=$requestedDraftId;
    $scenario=$w['scenarios'][$activeId];

    $captainsEmpty=true;
    foreach(range(1,8) as $a) if((int)($scenario['slots'][$a]['captain']??0)>0){$captainsEmpty=false;break;}
    if($captainsEmpty && !$stored['exists'] && $rankings['rows']){
        alliance_seed_captains($scenario,$rankings['rows']);
        $w['scenarios'][$activeId]=$scenario;
        if($canEdit){
            alliance_save_workspace($pdo,$org,$eventId,$w,$userId);
            $stored['exists']=true;
        }
    }

    $rankingMap=[];
    foreach($rankings['rows'] as $r)$rankingMap[(int)$r['team']]=$r;
    $teams=[];
    $s=$pdo->prepare('SELECT frc_team_number,nickname,city,state_prov,country FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $s->execute([$eventId]);
    $rosterRows=$s->fetchAll();
    $rosterTeamNumbers=array_values(array_unique(array_map(static fn($row)=>(int)($row['frc_team_number']??0),$rosterRows)));

    // Build the Team Pool from the same shared AUGUR robot model used by
    // Match Strategy and the Alliance Matchup predictor. The public EPA value
    // remains available separately, while neptune_epa is the blended offensive
    // value shown on pool cards and used by the Alliance Current Subtotal.
    // augur_epa remains a compatibility alias for older clients.
    $modelSettings=alliance_matchup_settings(is_array($w['settings']['matchup_model']??null)?$w['settings']['matchup_model']:[]);
    $augurMetrics=[];$augurError='';$epaMap=[];
    try{
        if(count($rosterTeamNumbers)>=2){
            $split=(int)ceil(count($rosterTeamNumbers)/2);
            $poolA=array_slice($rosterTeamNumbers,0,$split);
            $poolB=array_slice($rosterTeamNumbers,$split);
            if(!$poolB){$poolB=[array_pop($poolA)];}
            $prediction=augur_prediction_run(
                $pdo,$org,$event,$poolA,$poolB,$modelSettings,
                [
                    // Neptune EPA itself does not depend on qualification record;
                    // rankings are already loaded above for the Team Pool UI.
                    'use_tba_rankings'=>false,
                    'use_epa'=>true,
                    'side_a_number'=>'Pool A',
                    'side_b_number'=>'Pool B',
                ]
            );
            foreach(['a','b'] as $side){
                foreach(($prediction[$side]['teams']??[]) as $teamRow){
                    $teamNumber=(int)($teamRow['team']??0);
                    if($teamNumber>0&&is_array($teamRow['metrics']??null))$augurMetrics[$teamNumber]=$teamRow['metrics'];
                }
            }
        }
    }catch(Throwable $e){error_log('Alliance Selection AUGUR pool: '.$e->getMessage());$augurError='Neptune EPA is temporarily unavailable for the Team Pool.';$augurMetrics=[];}

    // Keep Public EPA available even if the shared prediction model could not
    // be calculated, so the detailed EPA views still have their independent
    // public baseline. Never label this fallback as Neptune EPA.
    if(!$augurMetrics){
        try{
            if(function_exists('augur_epa_tables_ready')&&augur_epa_tables_ready($pdo)){
                $epaMap=augur_epa_rating_map($pdo,$org,$event,$rosterTeamNumbers,null);
            }
        }catch(Throwable $ignored){$epaMap=[];}
    }

    foreach($rosterRows as $row){
        $n=(int)$row['frc_team_number'];$r=$rankingMap[$n]??[];$m=$augurMetrics[$n]??[];$epa=$epaMap[$n]??[];
        $publicEpa=isset($m['epa'])&&is_numeric($m['epa'])?(float)$m['epa']:(isset($epa['epa'])&&is_numeric($epa['epa'])?(float)$epa['epa']:null);
        $teams[]=[
            'team'=>$n,'nickname'=>(string)($row['nickname']??''),'city'=>(string)($row['city']??''),'state_prov'=>(string)($row['state_prov']??''),'country'=>(string)($row['country']??''),
            'rank'=>(int)($r['rank']??0),'points'=>(float)($r['points']??0),'wins'=>(int)($r['wins']??0),'losses'=>(int)($r['losses']??0),'ties'=>(int)($r['ties']??0),'matches'=>(int)($r['matches']??0),
            'epa'=>$publicEpa!==null?round($publicEpa,1):null,
            'neptune_epa'=>isset($m['neptune_epa'])&&is_numeric($m['neptune_epa'])?round((float)$m['neptune_epa'],1):(isset($m['augur_epa'])&&is_numeric($m['augur_epa'])?round((float)$m['augur_epa'],1):null),
            'augur_epa'=>isset($m['neptune_epa'])&&is_numeric($m['neptune_epa'])?round((float)$m['neptune_epa'],1):(isset($m['augur_epa'])&&is_numeric($m['augur_epa'])?round((float)$m['augur_epa'],1):null), // legacy API alias
            'augur_confidence'=>isset($m['confidence'])&&is_numeric($m['confidence'])?round((float)$m['confidence'],1):null,
            'augur_trend'=>isset($m['slope'])&&is_numeric($m['slope'])?round((float)$m['slope'],2):null,
            'augur_matches'=>(int)($m['current_matches']??0),
            'augur_event_avg'=>isset($m['event_avg'])&&is_numeric($m['event_avg'])?round((float)$m['event_avg'],1):null,
            'augur_recent_avg'=>isset($m['recent_avg'])&&is_numeric($m['recent_avg'])?round((float)$m['recent_avg'],1):null,
            'augur_source'=>(string)($m['baseline_source']??''),
        ];
    }
    usort($teams,static function($a,$b){
        $ap=(float)$a['points'];$bp=(float)$b['points'];
        if($ap!==$bp)return $bp<=>$ap;
        $ar=(int)$a['rank'];$br=(int)$b['rank'];
        if($ar&&$br)return $ar<=>$br;
        if($ar)return -1;if($br)return 1;
        return (int)$a['team']<=>(int)$b['team'];
    });

    return [
        'ok'=>true,'can_edit'=>$canEdit,'version'=>(int)$stored['version'],
        'event'=>['id'=>(int)$event['id'],'name'=>(string)$event['name'],'game_name'=>(string)$event['game_name'],'season_year'=>(int)$event['season_year'],'tba_event_key'=>(string)($event['tba_event_key']??'')],
        'ranking'=>['label'=>(string)$rankings['label'],'precision'=>(int)$rankings['precision'],'error'=>(string)$rankings['error'],'available'=>!empty($rankings['rows'])],
        'augur_model'=>['available'=>!empty($augurMetrics),'error'=>$augurError],
        'drafts'=>alliance_draft_list($w),
        'draft'=>['id'=>$activeId,'name'=>(string)$scenario['name'],'mode'=>(string)$scenario['mode'],'status'=>'active','revision_cursor'=>count($scenario['undo']??[])],
        'settings'=>[
            'captain_picks_allowed'=>(bool)($w['settings']['captain_picks_allowed']??true),
            'matchup_model'=>alliance_matchup_settings(is_array($w['settings']['matchup_model']??null)?$w['settings']['matchup_model']:[]),
        ],
        'slots'=>$scenario['slots'],'statuses'=>alliance_public_statuses($scenario),'teams'=>$teams,
        'alliance_tags'=>$scenario['alliance_tags']??[],
        'pick_lists'=>$scenario['pick_lists']??alliance_empty_pick_lists(),
        'can_undo'=>!empty($scenario['undo']),'can_redo'=>!empty($scenario['redo']),
    ];
}

$eventId=(int)($_REQUEST['event_id']??0);
if($eventId<1) json_response(['ok'=>false,'message'=>'Missing event.'],400);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $requestedDraftId=trim((string)($_GET['draft_id']??''));
    json_response(alliance_prepare_state($pdo,$org,$eventId,$canEdit,$userId,$requestedDraftId));
}
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
verify_csrf();alliance_api_require_edit($canEdit);
$event=alliance_api_event($pdo,$org,$eventId);
$op=trim((string)($_POST['op']??''));

try{
    $pdo->beginTransaction();
    $loaded=alliance_load_workspace($pdo,$org,$eventId,true);$w=$loaded['workspace'];
    $requested=(string)($_POST['draft_id']??'');
    if($requested!==''&&isset($w['scenarios'][$requested]))$w['active_id']=$requested;
    $id=alliance_active_id($w);$scenario=$w['scenarios'][$id];
    $rankings=alliance_rankings_for_event($event);$rows=$rankings['rows'];

    if($op==='create_draft'){
        $new='s'.max(2,(int)$w['next_id']);$w['next_id']=max(2,(int)$w['next_id'])+1;
        $name=alliance_unique_scenario_name($w,trim((string)($_POST['name']??''))?:'Scenario');
        $w['scenarios'][$new]=alliance_new_scenario($new,$name);
        if($rows)alliance_seed_captains($w['scenarios'][$new],$rows);
        $w['active_id']=$new;$id=$new;
    } elseif($op==='clone_draft'){
        $new='s'.max(2,(int)$w['next_id']);$w['next_id']=max(2,(int)$w['next_id'])+1;
        $copy=$scenario;$copy['id']=$new;$copy['name']=alliance_unique_scenario_name($w,trim((string)($_POST['name']??''))?:($scenario['name'].' Copy'));$copy['mode']='scenario';$copy['undo']=[];$copy['redo']=[];
        $w['scenarios'][$new]=$copy;$w['active_id']=$new;$id=$new;
    } elseif($op==='rename_draft'){
        $name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Enter a scenario name.');
        $scenario['name']=$name;$w['scenarios'][$id]=$scenario;
    } elseif($op==='archive_draft'){
        if(count($w['scenarios'])<=1)throw new RuntimeException('Keep at least one scenario.');
        unset($w['scenarios'][$id]);$w['active_id']=(string)array_key_first($w['scenarios']);$id=$w['active_id'];
    } elseif($op==='set_mode'){
        $mode=(($_POST['mode']??'scenario')==='live')?'live':'scenario';
        if($mode==='live')foreach($w['scenarios'] as $sid=>&$s)$s['mode']='scenario';unset($s);
        $scenario=$w['scenarios'][$id];$scenario['mode']=$mode;$w['scenarios'][$id]=$scenario;
    } elseif($op==='set_captain_picks'){
        $allowed=((string)($_POST['allowed']??'0'))==='1';
        $w['settings']??=[];
        $w['settings']['captain_picks_allowed']=$allowed;
    } elseif($op==='set_matchup_settings'){
        $raw=json_decode((string)($_POST['settings_json']??''),true);
        if(!is_array($raw))throw new RuntimeException('Invalid matchup model settings.');
        $w['settings']??=[];
        $w['settings']['matchup_model']=alliance_matchup_settings($raw);
    } elseif($op==='refresh_rankings' || $op==='seed_captains'){
        if(!$rows)throw new RuntimeException('TBA qualification rankings are not available for this event yet.');
        if(alliance_has_picks($scenario))throw new RuntimeException('Picks have already started. Refresh rankings before alliance selection begins.');
        alliance_push_undo($scenario);alliance_seed_captains($scenario,$rows);$w['scenarios'][$id]=$scenario;
    } elseif($op==='set_slot'){
        $alliance=(int)($_POST['alliance_number']??0);$slot=(string)($_POST['slot_type']??'');$team=(int)($_POST['team']??0);
        if($alliance<1||$alliance>8||!in_array($slot,['captain','pick1','pick2','backup'],true))throw new RuntimeException('Invalid alliance slot.');
        if($slot==='captain')throw new RuntimeException('Alliance captains come from TBA qualification rankings. Use Refresh TBA Rankings instead.');
        if($team>0&&!alliance_team_on_roster($pdo,$eventId,$team))throw new RuntimeException('That team is not on this event roster.');
        alliance_push_undo($scenario);
        if($team>0){
            $status=$scenario['statuses'][$team]['state']??'available';
            if(in_array($status,['declined','broken','do_not_pick'],true))throw new RuntimeException('That team is currently marked '.str_replace('_',' ',$status).'.');
            $captainAlliance=alliance_find_captain_alliance($scenario,$team);
            if($captainAlliance>0){
                $captainPicksAllowed=(bool)($w['settings']['captain_picks_allowed']??true);
                if(!$captainPicksAllowed){
                    throw new RuntimeException('Captain-to-captain selections are disabled for this event.');
                }
                if(alliance_captain_is_locked($scenario,$team,$captainPicksAllowed)){
                    if($captainAlliance===1)throw new RuntimeException('Alliance 1 captain is never available to be selected.');
                    throw new RuntimeException('Alliance '.$captainAlliance.' captain is no longer available after that alliance has made a selection.');
                }
                if($alliance >= $captainAlliance)throw new RuntimeException('A captain can only be selected by a higher-seeded alliance.');
                $scenario['slots'][$captainAlliance]['captain']=null;
                alliance_promote_after_captain_pick($scenario,$captainAlliance,$rows,$team);
            }
            foreach(range(1,8) as $a)foreach(['pick1','pick2','backup'] as $slt)if((int)($scenario['slots'][$a][$slt]??0)===$team)$scenario['slots'][$a][$slt]=null;
        }
        $scenario['slots'][$alliance][$slot]=$team>0?$team:null;$w['scenarios'][$id]=$scenario;
    } elseif($op==='set_status'){
        $team=(int)($_POST['team']??0);$state=(string)($_POST['state']??'available');
        if($team<1||!alliance_team_on_roster($pdo,$eventId,$team))throw new RuntimeException('Invalid team.');
        if(!in_array($state,['available','declined','broken','do_not_pick'],true))throw new RuntimeException('Invalid status.');
        if(alliance_team_used_as_pick($scenario,$team))throw new RuntimeException('Remove this team from its pick slot before changing its status.');
        alliance_push_undo($scenario);$scenario['statuses'][$team]??=['state'=>'available','favorite'=>false];$scenario['statuses'][$team]['state']=$state;$w['scenarios'][$id]=$scenario;
    } elseif($op==='toggle_favorite'){
        $team=(int)($_POST['team']??0);if($team<1||!alliance_team_on_roster($pdo,$eventId,$team))throw new RuntimeException('Invalid team.');
        alliance_push_undo($scenario);$scenario['statuses'][$team]??=['state'=>'available','favorite'=>false];$scenario['statuses'][$team]['favorite']=empty($scenario['statuses'][$team]['favorite']);$w['scenarios'][$id]=$scenario;
    } elseif($op==='set_team_tags'){
        $team=(int)($_POST['team']??0);if($team<1||!alliance_team_on_roster($pdo,$eventId,$team))throw new RuntimeException('Invalid team.');
        $rawTags=json_decode((string)($_POST['tags_json']??'[]'),true);if(!is_array($rawTags))throw new RuntimeException('Invalid alliance tags.');
        $allowed=array_flip(alliance_tag_ids());$tags=[];
        foreach($rawTags as $tag){$tag=(string)$tag;if(isset($allowed[$tag])&&!in_array($tag,$tags,true))$tags[]=$tag;}
        alliance_push_undo($scenario);$scenario['alliance_tags']??=[];
        if($tags)$scenario['alliance_tags'][$team]=array_slice($tags,0,8);else unset($scenario['alliance_tags'][$team]);
        $w['scenarios'][$id]=$scenario;
    } elseif($op==='set_pick_list_slot'){
        $tier=(string)($_POST['tier']??'');$index=(int)($_POST['slot_index']??-1);$team=(int)($_POST['team']??0);
        if(!in_array($tier,['1','2','3','4'],true)||$index<0||$index>5)throw new RuntimeException('Invalid pick-list slot.');
        if($team>0&&!alliance_team_on_roster($pdo,$eventId,$team))throw new RuntimeException('That team is not on this event roster.');
        alliance_push_undo($scenario);$scenario['pick_lists']??=alliance_empty_pick_lists();
        foreach(['1','2','3','4'] as $listTier){
            $scenario['pick_lists'][$listTier]??=array_fill(0,6,null);
            foreach(range(0,5) as $slotIndex){if($team>0&&(int)($scenario['pick_lists'][$listTier][$slotIndex]??0)===$team)$scenario['pick_lists'][$listTier][$slotIndex]=null;}
        }
        $scenario['pick_lists'][$tier][$index]=$team>0?$team:null;$w['scenarios'][$id]=$scenario;
    } elseif($op==='undo'){
        if(empty($scenario['undo']))throw new RuntimeException('Nothing to undo.');
        $scenario['redo'][]=alliance_snapshot($scenario);$snap=array_pop($scenario['undo']);alliance_restore_snapshot($scenario,$snap);$w['scenarios'][$id]=$scenario;
    } elseif($op==='redo'){
        if(empty($scenario['redo']))throw new RuntimeException('Nothing to redo.');
        $scenario['undo'][]=alliance_snapshot($scenario);$snap=array_pop($scenario['redo']);alliance_restore_snapshot($scenario,$snap);$w['scenarios'][$id]=$scenario;
    } else throw new RuntimeException('Unknown alliance-selection operation.');

    $version=alliance_save_workspace($pdo,$org,$eventId,$w,$userId);$pdo->commit();
    $message=($op==='refresh_rankings'||$op==='seed_captains')
        ? 'Alliance captains refreshed from TBA qualification rankings.'
        : ($op==='set_captain_picks'
            ? (((bool)($w['settings']['captain_picks_allowed']??true)) ? 'Captain-to-captain selections enabled.' : 'Captain-to-captain selections disabled.')
            : ($op==='set_matchup_settings' ? 'Matchup model settings saved.' : 'Alliance Selection updated.'));
    json_response(['ok'=>true,'draft_id'=>$id,'version'=>$version,'message'=>$message]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],400);
}
