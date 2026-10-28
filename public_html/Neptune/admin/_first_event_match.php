<?php
declare(strict_types=1);
/**
 * Pure FIRST event-directory matching helpers. No database, network, or tokens.
 * Suggestions only: the user confirms a code before files are written.
 */
function neptune_first_match_words(string $name): array {
    $name=strtolower($name);
    $name=(string)preg_replace('/[\x{2018}\x{2019}]/u', "'", $name);
    $name=(string)preg_replace('/[^a-z0-9]+/i', ' ', $name);
    $stop=['first'=>1,'frc'=>1,'robotics'=>1,'event'=>1,'competition'=>1,
           'tournament'=>1,'of'=>1,'the'=>1,'by'=>1,'presented'=>1,'and'=>1,
           'season'=>1,'offseason'=>1,'off'=>1,'regional'=>1,'district'=>1];
    $tokens=[];
    foreach(explode(' ',trim($name)) as $w){
        if($w!==''&&strlen($w)>1&&!isset($stop[$w])&&!preg_match('/^20\d\d$/',$w))$tokens[$w]=true;
    }
    return array_keys($tokens);
}
function neptune_first_directory_code(array $event): string {
    $code=strtoupper(trim((string)($event['code']??$event['eventCode']??'')));
    return preg_match('/^[A-Z0-9]{3,12}$/',$code)?$code:'';
}
function neptune_first_directory_name(array $event): string {
    return trim((string)($event['name']??$event['eventName']??''));
}
function neptune_first_date_window(array $event): array {
    $start=substr((string)($event['dateStart']??$event['startDate']??''),0,10);
    $end=substr((string)($event['dateEnd']??$event['endDate']??$start),0,10);
    foreach([$start,$end] as $s){if($s!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$s))return ['',''];}
    return [$start,$end];
}
function neptune_first_match_score(array $local,array $first,?string $query=null): int {
    $code=neptune_first_directory_code($first);
    if($code==='')return 0;
    $linked=strtoupper(trim((string)($local['event_code']??'')));
    if($linked!==''&&$linked===$code)return 300;
    $left=neptune_first_match_words($query!==null&&trim($query)!==''?$query:(string)($local['name']??''));
    $right=neptune_first_match_words(neptune_first_directory_name($first));
    if(!$left||!$right)return 0;
    $both=count(array_intersect($left,$right));
    if(!$both)return 0;
    $score=(int)round(80*(2*$both)/(count($left)+count($right)));
    if($both===count($left)&&$both===count($right))$score+=32;
    elseif($both===min(count($left),count($right))&&$both>=2)$score+=18;
    // A single common word is weak evidence, particularly at large events.
    if($both===1)$score=min(30,$score);
    $date=substr((string)($local['start_date']??''),0,10);
    [$start,$end]=neptune_first_date_window($first);
    if($date!==''&&$start!==''&&$end!==''){
        if($date>=$start&&$date<=$end)$score+=24;
        else {
            $a=strtotime($date.' 12:00:00 UTC');$b=strtotime($start.' 12:00:00 UTC');
            if($a!==false&&$b!==false&&abs($a-$b)<=86400)$score+=12;
        }
    }
    return $score;
}
function neptune_first_directory_suggestions(array $directory,array $local,string $search=''): array {
    $ranked=[];
    $needle=strtolower(trim($search));
    foreach($directory as $event){
        if(!is_array($event))continue;
        $code=neptune_first_directory_code($event);
        if($code==='')continue;
        $name=neptune_first_directory_name($event);
        $hay=strtolower($name.' '.$code.' '.($event['city']??'').' '.($event['stateProv']??$event['stateprov']??''));
        if($needle!=='' && !str_contains($hay,$needle)){
            // Allow a multi-word fuzzy search, but require more than one shared term.
            $queryScore=neptune_first_match_score(['name'=>$search],$event,$search);
            if($queryScore<45)continue;
        }
        $score=neptune_first_match_score($local,$event,$search!==''?$search:null);
        if($needle!==''&&strtoupper($needle)===$code)$score=350;
        $ranked[]=['event'=>$event,'score'=>$score,'code'=>$code];
    }
    usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['code'],$b['code']));
    return $ranked;
}
