<?php
declare(strict_types=1);
function npr_match_payload_hash(array $row): string {
    $tags=json_decode((string)($row['tags_json']??'[]'),true);
    $weights=json_decode((string)($row['tag_weights_json']??'{}'),true);
    if(!is_array($tags)||!is_array($weights))throw new RuntimeException('Match Tag Scouting record has invalid JSON.');
    $tags=array_values(array_unique(array_map('strval',$tags)));sort($tags,SORT_STRING);
    $normalWeights=[];
    foreach($weights as $k=>$v)$normalWeights[(string)$k]=is_numeric($v)?(string)(int)$v:(string)$v;
    ksort($normalWeights,SORT_STRING);
    $share=($row['scoring_contribution']??null);
    $data=[
        'alliance'=>(string)($row['alliance']??''),
        'station'=>($row['station']??null)===null?null:(int)$row['station'],
        'scoring'=>($share===null||$share==='')?null:(int)$share,
        'note'=>trim((string)($row['note']??'')),
        'tags'=>$tags,
        'weights'=>$normalWeights,
    ];
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
