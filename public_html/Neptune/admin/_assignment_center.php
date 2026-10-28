<?php
/** Neptune scouting assignment rules: deliberately independent of HTTP/database so rules can be tested. */
declare(strict_types=1);

function sac_match_slot(int $matchId,string $alliance,int $station): string {
    if($matchId<1 || !in_array($alliance,['Red','Blue'],true) || $station<1 || $station>3){
        throw new InvalidArgumentException('Invalid match or alliance position.');
    }
    return 'm:'.$matchId.':'.($alliance==='Red'?'R':'B').$station;
}
function sac_pit_slot(int $eventId,int $team): string {
    if($eventId<1 || $team<1 || $team>999999){
        throw new InvalidArgumentException('Invalid event or team number.');
    }
    return 'p:'.$eventId.':'.$team;
}
function sac_mode(string $mode): string {
    if(!in_array($mode,['traditional','tag','either'],true))throw new InvalidArgumentException('Choose a valid scouting mode.');
    return $mode;
}
function sac_status_transition(string $from,string $operation): array {
    $map=[
        'assigned'=>['acknowledge'=>['acknowledged','acknowledged'],'complete'=>['completed','completed'],'cancel'=>['cancelled','cancelled']],
        'acknowledged'=>['complete'=>['completed','completed'],'cancel'=>['cancelled','cancelled']],
        'completed'=>['reopen'=>['assigned','reopened']],
        'cancelled'=>['reopen'=>['assigned','reopened']],
    ];
    if(!isset($map[$from][$operation]))throw new InvalidArgumentException('That status change is not permitted.');
    return $map[$from][$operation];
}
function sac_upload_image(array $file,int $maxBytes): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) {
        throw new InvalidArgumentException('Choose a valid uploaded image.');
    }
    $size=(int)($file['size']??0);
    if($size<1 || $size>$maxBytes)throw new InvalidArgumentException('Image exceeds the upload size limit.');
    $data=file_get_contents($file['tmp_name']);
    if($data===false || strlen($data)>$maxBytes || strlen($data)!==$size)throw new InvalidArgumentException('Could not read the uploaded image.');
    $info=@getimagesizefromstring($data);
    $mime=(string)($info['mime']??'');
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true) || (int)($info[0]??0)>4000 || (int)($info[1]??0)>4000 || (int)($info[0]??0)<1 || (int)($info[1]??0)<1){
        throw new InvalidArgumentException('Use a JPG, PNG, or WebP image up to 4000 pixels per side.');
    }
    return [$mime,$data];
}
function sac_match_label(array $m): string {
    $level=strtoupper((string)($m['comp_level']??'qm'));
    $short=['QM'=>'Q','QF'=>'QF','SF'=>'SF','F'=>'F','EF'=>'EF'];
    $prefix=$short[$level]??$level;
    $set=(int)($m['set_number']??1);
    return $prefix.($level==='QM'?'':$set.'-').(int)($m['match_number']??0);
}
function sac_initials(string $name): string {
    $words=preg_split('/\s+/u',trim($name))?:[];
    $letters='';
    foreach(array_slice($words,0,2) as $word){$letters.=function_exists('mb_substr')?mb_substr($word,0,1,'UTF-8'):substr($word,0,1);}
    return strtoupper($letters?:'?');
}
