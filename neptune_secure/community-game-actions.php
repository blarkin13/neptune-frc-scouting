<?php
declare(strict_types=1);
/**
 * Read-only published Game Builder action adapter for Auto Scout.
 * Never changes games, revisions, or the existing game JSON files.
 */
function community_video_game_config(array $match):array {
    global $pdo,$config;
    $matchKey=(string)($match['match_key']??'');
    if(!preg_match('/^(20\d{2})/',$matchKey,$parts))
        throw new CommunityAnalysisError(422,'The match does not identify a season.');
    $year=(int)$parts[1];
    $org=(int)($config['app']['platform_organization_id']??1);
    if($org<1)throw new CommunityAnalysisError(422,'Platform organization is not configured.');
    // game_revisions is the published source of truth. Do not read draft files or the
    // directory of templates, which may not match what Game Builder has published.
    $q=$pdo->prepare("SELECT g.id game_id,g.name game_name,g.current_revision_id,
        gr.id revision_id,gr.revision_number,gr.match_config_json,gr.checksum
        FROM games g
        JOIN game_revisions gr ON gr.id=g.current_revision_id
          AND gr.game_id=g.id AND gr.organization_id=g.organization_id AND gr.status='published'
        WHERE g.organization_id=? AND g.season_year=? AND g.is_archived=0
        ORDER BY g.id");
    $q->execute([$org,$year]);
    $revisions=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($revisions)!==1)
        throw new CommunityAnalysisError(422,count($revisions)===0
            ?'No published Game Builder revision was found for season '.$year.'.'
            :'Multiple published games exist for season '.$year.'. Link this match to the intended game before labeling; Neptune will not guess.');
    $r=$revisions[0];
    $raw=(string)$r['match_config_json'];
    $game=json_decode($raw,true);
    if(!is_array($game)||!is_array($game['buttons']??null))
        throw new CommunityAnalysisError(422,'The published Game Builder revision has invalid match actions.');
    $revision=(int)$r['revision_id'];
    $version='neptune-game-rev-'.$revision.'-'.substr(hash('sha256',$raw),0,32);
    $seen=[];$actions=[];
    foreach($game['buttons'] as $item) {
        if(!is_array($item)||!is_string($item['code']??null)
            ||!preg_match('/^[a-z0-9_]{1,55}$/D',$item['code'])
            ||!is_string($item['name']??null))
            throw new CommunityAnalysisError(422,'Invalid action in the published game revision.');
        $code=$item['code'];
        if(isset($seen[$code]))throw new CommunityAnalysisError(422,'Duplicate published game action: '.$code);
        $seen[$code]=true;
        $label=trim($item['name']);
        if($label===''||strlen($label)>120)throw new CommunityAnalysisError(422,'Invalid published game action name.');
        // Absence is not an action observed in video (e.g. did_not_show).
        if(preg_match('/^(?:no_|did_not_|not_|absent)/i',$code))continue;
        $type=(string)($item['type']??'');
        $category=community_video_game_group($code,$type);
        $actions['gb:'.$code]=['key'=>'gb:'.$code,'label'=>$label,'category'=>$category,
            'scope'=>'moment','definition_version'=>$version,'game_code'=>$code,
            'game'=>(string)($game['game']??$r['game_name'])];
    }
    if(!$actions)throw new CommunityAnalysisError(422,'This published game has no observable actions.');
    return ['year'=>$year,'name'=>(string)($game['game']??$r['game_name']),
       'version'=>$version,'revision_id'=>$revision,'actions'=>$actions];
}
function community_video_game_group(string $code,string $type):string {
    if(preg_match('/(?:climb|cage|barge|park)/',$code))return 'Endgame';
    if(preg_match('/^(?:auto_|leave_|zone$)/',$code))return 'Autonomous';
    if(in_array($code,['disabled','mechanical_issues','recovered'],true))return 'Reliability';
    if($type==='defense')return 'Defense';
    if($type==='cooperative'||preg_match('/(?:feed|support|alliance|assist)/',$code))return 'Alliance';
    return 'Scoring';
}
function community_video_game_controls(array $match):array {
    $game=community_video_game_config($match);
    // Preserve the existing five-column dock, but populate it exclusively from
    // the published Game Builder buttons. No hardcoded legacy actions are appended.
    $groups=['Scoring'=>[],'Defense'=>[],'Autonomous'=>[],'Endgame'=>[],
             'Reliability'=>[],'Alliance'=>[]];
    $suggestions=['score_1_fuel'=>'S','score_2_fuel'=>'D','score_3_fuel'=>'T',
      'score_4_fuel'=>'L','score_10_fuel'=>'G','score_25_fuel'=>'H',
      'defense'=>'J','plays_defense'=>'J','feed'=>'K',
      'climb_lv_1'=>'V','climb_lv_2'=>'B','climb_lv_3'=>'E',
      'disabled'=>'X'];
    $pool=str_split('sdtlghqwavbfemnxckpjzyi');
    $reserved=['r'=>true,'u'=>true,'o'=>true];
    foreach($game['actions'] as $key=>$action) {
        $code=$action['game_code'];
        $preferred=$suggestions[$code]??null;
        if($preferred!==null)$reserved[strtolower($preferred)]=true;
    }
    $used=['r'=>true,'u'=>true,'o'=>true];
    foreach($game['actions'] as $key=>$action) {
        $code=$action['game_code'];
        $preferred=$suggestions[$code]??null;
        $shortcut='';
        if($preferred!==null&&!isset($used[strtolower($preferred)]))$shortcut=$preferred;
        else {
            foreach($pool as $candidate) {
                if(!isset($reserved[$candidate])&&!isset($used[$candidate])) {
                    $shortcut=strtoupper($candidate);break;
                }
            }
            if($shortcut==='')foreach($pool as $candidate) {
                if(!isset($used['shift+'.$candidate])) {
                    $shortcut='Shift+'.strtoupper($candidate);break;
                }
            }
        }
        if($shortcut==='')throw new CommunityAnalysisError(422,'This game contains too many actions for unique shortcuts.');
        $used[strtolower($shortcut)]=true;
        $groups[$action['category']][$key]=[$action['label'],$shortcut];
    }
    // Suppress empty categories. REBUILT has no dedicated auto action buttons,
    // so it displays five compact groups: Scoring, Defense, Endgame, Reliability, Alliance.
    $groups=array_filter($groups,static fn(array $controls):bool=>count($controls)>0);
    return ['game'=>$game,'groups'=>$groups];
}

function community_game_action_row(array $r,?int $season=null):bool {
 return ($r['schema_version']??null)===2&&($r['evidence_type']??'')==='human_video_label'
 &&preg_match('/^gb:[A-Za-z0-9_][A-Za-z0-9_.:-]{0,119}$/D',(string)($r['tag_key']??''))
 &&preg_match('/^neptune-game-(?:v1|rev-[1-9][0-9]*)-[a-f0-9]{32}$/D',(string)($r['definition_version']??''))
 &&is_int($r['season']??null)&&($season===null||$r['season']===$season)
 &&str_starts_with((string)($r['game_key']??''),$r['season'].'-')
 &&($r['time_basis']??'')==='video_elapsed_ms'&&($r['label_scope']??'')==='moment'
 &&is_int($r['start_ms']??null)&&$r['start_ms']>=0&&($r['end_ms']??null)===$r['start_ms']
 &&($r['scoring']??null)===null
 &&is_string($r['tag_label']??null)&&strlen($r['tag_label'])>0&&strlen($r['tag_label'])<=240
 &&is_string($r['tag_category']??null)&&strlen($r['tag_category'])>0&&strlen($r['tag_category'])<=240;
}
function community_game_action_definitions(string $snapshot,string $gameKey):array {
 $out=[];foreach(explode("\n",$snapshot) as $line){
  if(!$line)continue;$r=json_decode($line,true);
  if(!is_array($r)||!community_game_action_row($r)||$r['game_key']!==$gameKey||($r['certainty']??'')!=='observed'||!in_array($r['polarity']??'present',['present','absent'],true))continue;
  $tag=$r['tag_key'];$out[$tag]??=['contract'=>'game-builder-moments-v1','game_key'=>$gameKey,'label_scope'=>'moment','time_basis'=>'video_elapsed_ms','action_code'=>substr($tag,3),'variants'=>[]];
  $v=array_intersect_key($r,array_flip(['definition_version','tag_label','tag_category']));if(!in_array($v,$out[$tag]['variants'],true))$out[$tag]['variants'][]=$v;
 }
 return $out;
}
function community_game_action_definition_matches(mixed $definition,array $expected):bool {
 if(!is_array($definition))return false;
 foreach(['contract','game_key','label_scope','time_basis','action_code'] as $k)if(($definition[$k]??null)!==$expected[$k])return false;
 $variants=$definition['variants']??null;
 if(!is_array($variants)||!$variants||count($variants)>count($expected['variants']))return false;
 foreach($variants as $v)if(!is_array($v)||!in_array($v,$expected['variants'],false))return false;
 return true;
}

function community_snapshot_has_game_actions(string $snapshot):bool {
 foreach(explode("\n",$snapshot) as $line){$r=$line?json_decode($line,true):null;if(is_array($r)&&str_starts_with((string)($r['tag_key']??''),'gb:'))return true;}return false;
}
function community_require_game_action_helper(string $snapshot,array $hardware):void {
 if(community_snapshot_has_game_actions($snapshot)&&!in_array('game-builder-moments-v1',$hardware['label_contracts']??[],true))throw new CommunityAnalysisError(422,'These matches include Game Builder actions. Update the training helper to v3.0.2 or newer before continuing. Your tags and saved videos are kept.');
}
function community_require_game_action_model_helper(array $model,array $hardware):void {
 foreach(array_keys($model['heads']??[]) as $tag)if(str_starts_with((string)$tag,'gb:')){
  if(!in_array('game-builder-moments-v1',$hardware['label_contracts']??[],true))throw new CommunityAnalysisError(422,'This model predicts Game Builder actions. Update the training helper to v3.0.2 or newer before testing it.');return;
 }
}
