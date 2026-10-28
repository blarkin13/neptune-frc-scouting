<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-owner-only.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-video-tags.php';
$settings=community_analysis_settings($pdo,$config);
if(empty($settings['enabled'])){http_response_code(404);exit('Community analysis is not enabled.');}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');community_video_tags_schema($pdo);$service=new CommunityVideoTags($pdo);
if(isset($_GET['export'])){
    header('Content-Type: application/x-ndjson');header('Content-Disposition: attachment; filename="neptune-public-video-labels.ndjson"');
    $rows=$service->training();while($r=$rows->fetch(PDO::FETCH_ASSOC)){
        $r=community_scoring_decode($r);
        $r['schema_version']=2;$r['season']=preg_match('/^(20[0-9]{2})/D',(string)$r['match_key'],$seasonMatch)?(int)$seasonMatch[1]:null;$r['game_key']=[2025=>'2025-reefscape',2026=>'2026-rebuilt'][$r['season']]??null;$r['evidence_type']='human_video_label';$r['review_required']=false;$r['time_basis']='video_elapsed_ms';$r['source_sha256']=null;
        $r['roster']=json_decode($r['roster_json'],true);unset($r['roster_json']);
        foreach(['match_id','team_number','start_ms','end_ms','video_duration_ms','created_at'] as $key)if($r[$key]!==null)$r[$key]=(int)$r[$key];
        echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }exit;
}
$learningCounts=[];
$learningRows=$service->training();while($row=$learningRows->fetch(PDO::FETCH_ASSOC)){
    if(!preg_match('/^(20[0-9]{2})/',(string)$row['match_key'],$year))continue;
    $season=(int)$year[1];$learningCounts[$season]??=['labels'=>0,'matches'=>[],'counterexamples'=>0,'scoring_amounts'=>0];
    $learningCounts[$season]['labels']++;$learningCounts[$season]['matches'][(int)$row['match_id']]=true;
    if($row['scoring_json']!==null)$learningCounts[$season]['scoring_amounts']++;
    if(($row['polarity']??'present')==='absent')$learningCounts[$season]['counterexamples']++;
}
ksort($learningCounts);
require_once __DIR__.'/layout.php';community_page_start('Community Scouting Data','Saved robot tags and scoring estimates provide examples for training and testing.','catalog');
?><section><h2>Every clear observation helps</h2><p>Choose a robot and tag the actions you see. You can add a whole-match scoring estimate with the points field or percentage slider. One robot is enough; you do not need to cover a whole match.</p><p><a class="community-button" href="./?view=catalog">Watch &amp; tag →</a><a href="./?view=results">See saved tags &amp; scores</a></p></section>
<section><h2>What has been saved?</h2><?php if(!$learningCounts):?><p>No public contributions yet.</p><?php endif ?><?php foreach($learningCounts as $season=>$count):?><p><strong><?=$season?> <?=e([2025=>'REEFSCAPE',2026=>'REBUILT'][$season]??'season')?></strong> · <?=$count['labels']?> contributions · <?=count($count['matches'])?> matches · <?=$count['scoring_amounts']?> scoring entries</p><?php endforeach ?><p>These are contribution counts, not model accuracy. Saving a tag does not retrain the model immediately. A separate training computer prepares video examples, trains a model and returns a report to Neptune.</p></section>
<details><summary>Download data and understand the fields</summary><p>Each contribution keeps its match, robot, video time, tag and stated certainty. Whole-match score estimates keep robot points and the share of the full final alliance score, including penalties. Sequence counts stay separate and are never added to those estimates.</p><p>Missing actions and amounts stay unknown. Corrections replace earlier versions in the export; withdrawn entries are excluded. Notes are public. Account IDs, browser IDs and network identifiers are excluded.</p><p><a class="community-button" href="?export=1">Download public scouting data (NDJSON)</a></p><p>Trainer v1.4 learns experimental action patterns and whole-match scoring contributions from these examples. Score estimates retain their stated uncertainty. Model predictions stay separate from human scouting. Testing uses separate reference matches to count correct tags, wrong tags and missed actions.</p></details><?php community_page_end(); ?>
