<?php
/** Authoritative validation for Traditional match action submissions. */
declare(strict_types=1);

function neptune_validated_scout_action($rawAction, $rawResult, $rawSec, $configJson): array {
    if (!is_array($rawAction)) throw new InvalidArgumentException('Invalid action payload.');
    $code=trim((string)($rawAction['code']??''));
    if ($code==='') throw new InvalidArgumentException('Action code is required.');
    if (!in_array($rawResult,['Success','Failure','Neutral'],true)) throw new InvalidArgumentException('Invalid action result.');
    if (!is_int($rawSec) && !(is_string($rawSec) && preg_match('/^[0-9]+$/D',$rawSec))) {
        throw new InvalidArgumentException('Valid match time is required.');
    }
    $config=is_array($configJson)?$configJson:json_decode((string)$configJson,true);
    if (!is_array($config) || !isset($config['buttons']) || !is_array($config['buttons'])) {
        throw new RuntimeException('The event game revision is not configured for scoring.');
    }
    $duration=game_timing($config)['duration'];
    $sec=(int)$rawSec;
    if ($sec<0 || $sec>$duration) throw new InvalidArgumentException('Match time is outside the game duration.');
    $selected=null;
    foreach($config['buttons'] as $button){
        if (!is_array($button) || !isset($button['code'])) continue;
        if (trim((string)$button['code'])===$code){
            if ($selected!==null) throw new RuntimeException('Duplicate action codes in the game revision.');
            $selected=$button;
        }
    }
    if ($selected===null) throw new InvalidArgumentException('Action is not part of the event game revision.');
    $name=trim((string)($selected['name']??''));
    if($name==='') throw new RuntimeException('Game revision action has no name.');
    $phase=phase_for_game_time($sec,$config);
    $rawPoints=$phase==='auton'?($selected['autonPoints']??0):($selected['teleopPoints']??0);
    if (!is_numeric($rawPoints) || !is_finite((float)$rawPoints)) throw new RuntimeException('Invalid score in game revision.');
    $points=$rawResult==='Success'?(float)$rawPoints:0.0;
    return ['code'=>$code,'name'=>$name,'type'=>(string)($selected['type']??''),
        'location'=>(string)($selected['location']??''),'result'=>$rawResult,
        'points'=>$points,'sec'=>$sec,'phase'=>$phase];
}
