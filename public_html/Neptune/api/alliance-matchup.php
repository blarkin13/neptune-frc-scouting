<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_alliance_helpers.php';
require_once dirname(__DIR__).'/analytics/_augur_prediction_model.php';

$u=require_login();
$org=(int)$u['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$draftId=trim((string)($_GET['draft_id']??''));
$allianceA=(int)($_GET['alliance_a']??0);
$allianceB=(int)($_GET['alliance_b']??0);

if($eventId<1||$allianceA<1||$allianceA>8||$allianceB<1||$allianceB>8||$allianceA===$allianceB){
    json_response(['ok'=>false,'message'=>'Choose two different alliances.'],400);
}

$event=alliance_event($pdo,$org,$eventId);
if(!$event) json_response(['ok'=>false,'message'=>'Event not found for this organization.'],404);
if(!alliance_schema_ready($pdo)) json_response(['ok'=>false,'message'=>'Alliance Selection database migration is required.'],503);

$loaded=alliance_load_workspace($pdo,$org,$eventId,false);
$workspace=$loaded['workspace'];
if($draftId===''||!isset($workspace['scenarios'][$draftId])) $draftId=(string)$workspace['active_id'];
$scenario=$workspace['scenarios'][$draftId]??reset($workspace['scenarios']);
$settings=augur_prediction_settings($workspace['settings']['matchup_model']??[]);

function augur_alliance_selection_team_numbers(array $scenario,int $alliance): array {
    $row=$scenario['slots'][$alliance]??$scenario['slots'][(string)$alliance]??[];
    $out=[];
    foreach(['captain','pick1','pick2'] as $slot){
        $team=(int)($row[$slot]??0);
        if($team>0)$out[]=$team;
    }
    return array_values(array_unique($out));
}

$teamsA=augur_alliance_selection_team_numbers($scenario,$allianceA);
$teamsB=augur_alliance_selection_team_numbers($scenario,$allianceB);
if(!$teamsA||!$teamsB){
    json_response(['ok'=>false,'message'=>'Each selected alliance needs at least a captain before a matchup can be estimated.'],400);
}

try{
    $prediction=augur_prediction_run(
        $pdo,
        $org,
        $event,
        $teamsA,
        $teamsB,
        $settings,
        [
            'use_tba_rankings'=>true,
            'use_epa'=>true,
            'side_a_number'=>$allianceA,
            'side_b_number'=>$allianceB,
        ]
    );

    json_response([
        'ok'=>true,
        'event'=>['id'=>$eventId,'name'=>(string)$event['name'],'game_name'=>(string)$event['game_name']],
        'draft_id'=>$draftId,
        'settings'=>$prediction['settings'],
        'confidence'=>$prediction['confidence'],
        'alliance_a'=>['number'=>$allianceA]+$prediction['a'],
        'alliance_b'=>['number'=>$allianceB]+$prediction['b'],
        'factors'=>$prediction['factors'],
        'method'=>$prediction['method'],
    ]);
}catch(Throwable $e){
    json_response(['ok'=>false,'message'=>$e->getMessage()],400);
}
