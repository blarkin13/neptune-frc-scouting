<?php
declare(strict_types=1);
// Explicit CLI import of public-approved metadata only; never copies org scouting.
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/neptune_secure/bootstrap.php';
if($argc!==2) { fwrite(STDERR,"Usage: php scripts/community-analysis-import.php approved-match.json\n");exit(1); }
try {
 $m=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
 if(($m['public_distribution_approved']??false)!==true)throw new RuntimeException('Public source distribution must be explicitly approved.');
 if(!preg_match('/^[a-zA-Z0-9_.:-]{3,190}$/D',$m['match_key']??''))throw new RuntimeException('Canonical match key required.');
 $url=parse_url($m['source_url']??'');
 if(($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass']))throw new RuntimeException('Direct HTTPS source required.');
 if(!preg_match('/^[a-f0-9]{64}$/D',$m['source_sha256']??''))throw new RuntimeException('SHA-256 required.');
 if(!is_int($m['duration_ms']??null)||$m['duration_ms']<1||$m['duration_ms']>600000)throw new RuntimeException('Pilot duration must be 1–600000 ms.');
 $teams=[];
 foreach(['red','blue'] as $alliance){
  if(!is_array($m['roster'][$alliance]??null)||!array_is_list($m['roster'][$alliance])||count($m['roster'][$alliance])!==3)throw new RuntimeException('Three teams per alliance required.');
  foreach($m['roster'][$alliance] as $t){if(!is_int($t)||$t<1)throw new RuntimeException('Team numbers must be positive integers.');$teams[]=$t;}
 }
 if(count(array_unique($teams))!==6)throw new RuntimeException('Six distinct teams required.');
 if(!is_string($m['title']??null)||trim($m['title'])===''||mb_strlen($m['title'])>255||!is_string($m['rules']??null)||strlen($m['rules'])>30000)throw new RuntimeException('Title and bounded game rules required.');
 $q=$pdo->prepare('INSERT INTO community_matches(match_key,title,roster_json,source_url,source_sha256,duration_ms,rules_text,ready,created_at) VALUES(?,?,?,?,?,?,?,1,?)');
 $q->execute([$m['match_key'],$m['title'],json_encode($m['roster'],JSON_THROW_ON_ERROR),$m['source_url'],$m['source_sha256'],$m['duration_ms'],$m['rules'],time()]);
 echo "Added public-approved work item ".$pdo->lastInsertId()."\n";
}catch(Throwable $e){fwrite(STDERR,"Import failed: ".$e->getMessage()."\n");exit(1);}
