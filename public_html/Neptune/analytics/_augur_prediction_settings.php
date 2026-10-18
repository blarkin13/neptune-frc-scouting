<?php

function augur_prediction_default_settings(): array {
    return [
        'event_offense_weight'=>25.0,
        'recent_offense_weight'=>40.0,
        'ceiling_weight'=>15.0,
        // Backtested across 7 events (556 matches): EPA at 100 against the scouting weights below
        // gave the best winner accuracy and Brier score.
        'epa_weight'=>100.0,
        'trend_strength'=>1.5,
        'defense_suppression_weight'=>75.0,
        'defense_activity_weight'=>25.0,
        'defense_action_points'=>1.5,
        'max_defense_adjustment_pct'=>20.0,
        'tba_form_strength'=>5.0,
    ];
}

function augur_prediction_settings(array $raw=[]): array {
    $defaults=augur_prediction_default_settings();
    // Transparently carry forward settings saved by earlier AUGUR builds.
    if(!isset($raw['epa_weight'])){
        if(isset($raw['local_epa_weight'])) $raw['epa_weight']=$raw['local_epa_weight'];
        elseif(isset($raw['statbotics_weight'])) $raw['epa_weight']=$raw['statbotics_weight'];
    }
    $out=$defaults;
    foreach($defaults as $key=>$default){
        if(isset($raw[$key])&&is_numeric($raw[$key])) $out[$key]=(float)$raw[$key];
    }
    foreach(['event_offense_weight','recent_offense_weight','ceiling_weight','epa_weight','defense_suppression_weight','defense_activity_weight'] as $key){
        $out[$key]=max(0.0,min(100.0,$out[$key]));
    }
    $out['trend_strength']=max(0.0,min(4.0,$out['trend_strength']));
    $out['defense_action_points']=max(0.0,min(8.0,$out['defense_action_points']));
    $out['max_defense_adjustment_pct']=max(0.0,min(40.0,$out['max_defense_adjustment_pct']));
    $out['tba_form_strength']=max(0.0,min(20.0,$out['tba_form_strength']));
    // Events saved with the untouched pre-backtest defaults (EPA baseline 35) follow the new
    // default; any setting someone changed by hand is kept as saved.
    $oldDefaults=['epa_weight'=>35.0]+$defaults;
    if($out==$oldDefaults)$out['epa_weight']=$defaults['epa_weight'];
    return $out;
}
