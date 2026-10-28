<?php
/** Canonical match-state fingerprint for portable optimistic concurrency. */
declare(strict_types=1);
function neptune_portable_match_base_hash(array $row): string {
    $ints = ['total_pause_seconds','run_number','red_score','blue_score'];
    $keys = ['tba_match_key','scheduled_time','started_at','ended_at','paused_at',
        'total_pause_seconds','run_number','red_score','blue_score','winning_alliance','state'];
    $normalized=[];
    foreach($keys as $key){
        $v=$row[$key]??null;
        if($v==='')$v=null;
        if($v!==null)$v=in_array($key,$ints,true)?(int)$v:(string)$v;
        $normalized[$key]=$v;
    }
    return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
