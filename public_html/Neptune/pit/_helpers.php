<?php
function neptune_pit_general_questions(): array {
    return [
        ['group'=>'Robot Basics','code'=>'drivetrain','label'=>'Drivetrain','type'=>'select','options'=>['Swerve','Tank / West Coast','Mecanum','Other'],'required'=>true],
        ['group'=>'Robot Basics','code'=>'robot_weight','label'=>'Robot weight (lb)','type'=>'number','min'=>0,'max'=>200,'step'=>'0.1','required'=>false],
        ['group'=>'Robot Basics','code'=>'extends_outside_frame','label'=>'Does the robot extend outside the frame perimeter?','type'=>'yes_no','required'=>true],
        ['group'=>'Robot Basics','code'=>'extension_notes','label'=>'Extension / mechanism notes','type'=>'text','required'=>false],

        ['group'=>'Game Piece Handling','code'=>'floor_intake','label'=>'Can intake game pieces from the floor?','type'=>'yes_no','required'=>false],
        ['group'=>'Game Piece Handling','code'=>'human_player_intake','label'=>'Can load from a human player / source station?','type'=>'yes_no','required'=>false],
        ['group'=>'Game Piece Handling','code'=>'multi_piece','label'=>'Can carry or control multiple game pieces at once?','type'=>'yes_no','required'=>false],
        ['group'=>'Game Piece Handling','code'=>'handling_notes','label'=>'Game-piece handling limitations or preferences','type'=>'textarea','required'=>false],

        ['group'=>'Autonomous','code'=>'auton_routine_count','label'=>'Number of autonomous routines','type'=>'number','min'=>0,'max'=>50,'step'=>'1','required'=>false],
        ['group'=>'Autonomous','code'=>'auton_start_positions','label'=>'Available autonomous starting positions','type'=>'text','required'=>false],
        ['group'=>'Autonomous','code'=>'auton_description','label'=>'What do the autonomous routines attempt?','type'=>'textarea','required'=>false],
        ['group'=>'Autonomous','code'=>'auton_partner_notes','label'=>'Auto path / alliance-partner restrictions','type'=>'textarea','required'=>false],

        ['group'=>'Endgame','code'=>'endgame_capability','label'=>'Primary endgame capability','type'=>'select','options'=>['None','Park','Low','Mid','High / Deep','Other'],'required'=>false],
        ['group'=>'Endgame','code'=>'endgame_time','label'=>'Approximate endgame time required (seconds)','type'=>'number','min'=>0,'max'=>150,'step'=>'1','required'=>false],
        ['group'=>'Endgame','code'=>'endgame_notes','label'=>'Endgame location, space, or partner requirements','type'=>'textarea','required'=>false],

        ['group'=>'Strategy','code'=>'preferred_roles','label'=>'Preferred roles','type'=>'multiselect','options'=>['Primary scorer','Secondary scorer','Feeder / support','Defense','Flexible'],'required'=>false],
        ['group'=>'Strategy','code'=>'willing_defense','label'=>'Willing to play defense?','type'=>'yes_no','required'=>false],
        ['group'=>'Strategy','code'=>'defense_resistance','label'=>'How well does the robot tolerate defense?','type'=>'select','options'=>['Unknown','Low','Medium','High'],'required'=>false],
        ['group'=>'Strategy','code'=>'preferred_field_area','label'=>'Preferred field area / traffic pattern','type'=>'text','required'=>false],
        ['group'=>'Strategy','code'=>'alliance_partner_notes','label'=>'What should alliance partners know?','type'=>'textarea','required'=>false],

        ['group'=>'Reliability','code'=>'fully_functional','label'=>'Is the robot currently fully functional?','type'=>'yes_no','required'=>false],
        ['group'=>'Reliability','code'=>'known_issues','label'=>'Current mechanical, electrical, or software issues','type'=>'textarea','required'=>false],
        ['group'=>'Reliability','code'=>'vulnerabilities','label'=>'Mechanisms vulnerable to contact or damage','type'=>'textarea','required'=>false],
        ['group'=>'Reliability','code'=>'major_changes','label'=>'Major changes made recently / at this event','type'=>'textarea','required'=>false],
    ];
}

function neptune_pit_game_questions(array|string|null $json): array {
    if(is_string($json)) $json=json_decode($json,true)?:[];
    if(!is_array($json)) return [];
    $q=$json['questions']??[];
    return is_array($q)?array_values(array_filter($q,'is_array')):[];
}

function neptune_pit_all_questions(array|string|null $gameConfig): array {
    return array_merge(neptune_pit_general_questions(),neptune_pit_game_questions($gameConfig));
}

function neptune_pit_render_field(array $q, mixed $value): void {
    $code=(string)($q['code']??'');
    $label=(string)($q['label']??$code);
    $type=(string)($q['type']??'text');
    $required=!empty($q['required']);
    $options=is_array($q['options']??null)?$q['options']:[];
    $name='pit['.$code.']';
    echo '<div class="pit-field">';
    echo '<label for="pit_'.e($code).'">'.e($label).($required?' <span class="required-mark">*</span>':'').'</label>';
    if($type==='yes_no'){
        echo '<select id="pit_'.e($code).'" name="'.e($name).'"'.($required?' required':'').'><option value="">— Select —</option>';
        foreach(['Yes','No','Unknown'] as $o) echo '<option value="'.e($o).'"'.((string)$value===$o?' selected':'').'>'.e($o).'</option>';
        echo '</select>';
    } elseif($type==='select'){
        echo '<select id="pit_'.e($code).'" name="'.e($name).'"'.($required?' required':'').'><option value="">— Select —</option>';
        foreach($options as $o) echo '<option value="'.e($o).'"'.((string)$value===(string)$o?' selected':'').'>'.e($o).'</option>';
        echo '</select>';
    } elseif($type==='multiselect'){
        $selected=is_array($value)?$value:[];
        echo '<div class="choice-grid">';
        foreach($options as $i=>$o){$id='pit_'.preg_replace('/[^a-z0-9_]+/i','_',$code).'_'.$i;echo '<label class="choice-chip" for="'.e($id).'"><input id="'.e($id).'" type="checkbox" name="'.e($name).'[]" value="'.e($o).'"'.(in_array($o,$selected,true)?' checked':'').'> <span>'.e($o).'</span></label>';}
        echo '</div>';
    } elseif($type==='number'){
        $min=isset($q['min'])?' min="'.e($q['min']).'"':'';$max=isset($q['max'])?' max="'.e($q['max']).'"':'';$step=isset($q['step'])?' step="'.e($q['step']).'"':'';
        echo '<input id="pit_'.e($code).'" type="number" name="'.e($name).'" value="'.e((string)$value).'"'.$min.$max.$step.($required?' required':'').'>';
    } elseif($type==='textarea'){
        echo '<textarea id="pit_'.e($code).'" name="'.e($name).'" rows="3"'.($required?' required':'').'>'.e((string)$value).'</textarea>';
    } else {
        echo '<input id="pit_'.e($code).'" name="'.e($name).'" value="'.e((string)$value).'"'.($required?' required':'').'>';
    }
    if(!empty($q['help'])) echo '<div class="field-help">'.e($q['help']).'</div>';
    echo '</div>';
}
