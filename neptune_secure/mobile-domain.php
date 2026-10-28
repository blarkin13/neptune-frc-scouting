<?php
declare(strict_types=1);

final class MobileError extends RuntimeException {
    public function __construct(public int $status, string $message, public array $detail = []) { parent::__construct($message); }
}
function mobile_uuid(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value)) throw new MobileError(422, 'Invalid operation or action ID.');
    return strtolower($value);
}
function mobile_int(mixed $value, string $field, int $min = 1, int $max = PHP_INT_MAX): int {
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) throw new MobileError(422, 'Invalid '.$field.'.');
    return (int)$value;
}
function mobile_text(mixed $value, string $field, int $max): string {
    if (!is_string($value) || strlen($value) > $max) throw new MobileError(422, 'Invalid '.$field.'.');
    return trim($value);
}
function mobile_json(string $value): array {
    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new MobileError(422, 'Expected a JSON object.');
    return $decoded;
}
function mobile_canonical(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = json_decode(mobile_canonical($item), true, 512, JSON_THROW_ON_ERROR);
    }
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}
function mobile_pit_version(?array $pit, array $photos = []): ?string {
    if (!$pit) return null;
    return hash('sha256', mobile_canonical(['id'=>(int)$pit['id'], 'data'=>mobile_json((string)$pit['data_json']), 'notes'=>(string)($pit['notes']??''), 'status'=>$pit['status'], 'updated_at'=>$pit['updated_at'], 'photos'=>$photos]));
}
function mobile_validate_pit(array $questions, mixed $posted, bool $complete): array {
    if (!is_array($posted)) throw new MobileError(422, 'Invalid pit answers.');
    $clean = [];
    foreach ($questions as $question) {
        $code = (string)($question['code']??''); if ($code==='') continue;
        $type = (string)($question['type']??'text');
        $value = $posted[$code]??($type==='multiselect'?[]:'');
        $options = $type==='yes_no'?['Yes','No','Unknown']:($question['options']??[]);
        if ($type==='multiselect') {
            if (!is_array($value) || !array_is_list($value)) throw new MobileError(422, 'Invalid '.$code.'.');
            foreach ($value as $item) if (!is_string($item) || !in_array($item,$options,true)) throw new MobileError(422,'Invalid option for '.$code.'.');
            $value=array_values(array_unique($value));
        } else {
            $value=mobile_text($value,$code,10000);
            if ($value!=='' && in_array($type,['select','yes_no'],true) && !in_array($value,$options,true)) throw new MobileError(422,'Invalid option for '.$code.'.');
            if ($value!=='' && $type==='number') {
                if (!is_numeric($value) || !is_finite((float)$value) || (isset($question['min'])&&(float)$value<(float)$question['min']) || (isset($question['max'])&&(float)$value>(float)$question['max'])) throw new MobileError(422, 'Invalid number for '.$code.'.');
            }
        }
        if ($complete && !empty($question['required']) && ($value===''||$value===[])) throw new MobileError(422,'Required answer missing: '.($question['label']??$code));
        $clean[$code]=$value;
    }
    return $clean;
}
function mobile_validate_actions(array $payload, array $config): array {
    $actions=$payload['actions']??null;
    if (!is_array($actions) || !array_is_list($actions) || count($actions)>3000) throw new MobileError(422,'A match must contain at most 3000 actions.');
    $timing=$config['timing']??[];
    $auto=max(1,(int)($timing['autonSeconds']??15));
    $duration=$auto+max(1,(int)($timing['teleopSeconds']??135));
    $endgame=max(0,min($duration-$auto,(int)($timing['endgameSeconds']??15)));
    $buttons=[]; foreach (($config['buttons']??[]) as $button) $buttons[(string)($button['code']??'')]=$button;
    $clean=[]; $seen=[];
    foreach ($actions as $action) {
        if (!is_array($action)) throw new MobileError(422,'Invalid action.');
        $id=mobile_uuid($action['id']??null);
        if (isset($seen[$id])) throw new MobileError(422,'Duplicate action ID in this report.');
        $seen[$id]=true;
        $code=mobile_text($action['code']??null,'action code',190);
        $button=$buttons[$code]??null;
        if (!$button) throw new MobileError(409,'The selected action is no longer in the event game revision.');
        $result=$action['result']??'';
        if (!in_array($result,['Success','Failure'],true)) throw new MobileError(422,'Invalid action result.');
        $seconds=mobile_int($action['elapsed']??null,'match time',0,$duration);
        $timestamp=mobile_text($action['capturedAt']??null,'action timestamp',40);
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,3})?Z$/D',$timestamp) || strtotime($timestamp)===false || strtotime($timestamp)>time()+300) throw new MobileError(422,'Invalid action timestamp. Check the phone clock.');
        $clean[]=['uuid'=>$id,'code'=>$code,'name'=>(string)($button['name']??$code),'type'=>(string)($button['type']??''),'location'=>(string)($button['location']??''),'result'=>$result,'points'=>$result==='Success'?(float)($seconds<$auto?($button['autonPoints']??0):($button['teleopPoints']??0)):0,'seconds'=>$seconds,'phase'=>$seconds<$auto?'auton':($seconds>=$duration-$endgame?'endgame':'teleop'),'recorded_at'=>gmdate('Y-m-d H:i:s',strtotime($timestamp))];
    }
    return $clean;
}

function mobile_authorization_header(array $server,array $headers): string {
    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $key) if (isset($server[$key])&&is_string($server[$key])&&$server[$key]!=='') return $server[$key];
    // mod_php can expose Authorization through getallheaders() without a CGI variable.
    foreach ($headers as $name=>$value) if (strcasecmp((string)$name,'Authorization')===0&&is_string($value)) return $value;
    return '';
}
