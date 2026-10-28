<?php
declare(strict_types=1);
require_once __DIR__.'/community-analysis.php';
require_once __DIR__.'/community-scoring.php';
require_once __DIR__.'/community-game-actions.php';
require_once dirname(__DIR__).'/public_html/Neptune/scout/_tag_schema.php';
function community_video_tag_catalog(?array $match=null): array {
    $keys=['auto_no_attempt','auto_partial','auto_completed','auto_failed','no_scoring','scoring','slow_cycles','fast_cycles','no_defense','attempted_defense','mechanical_issues','disabled','recovered','end_no_attempt','end_partial','end_completed','end_failed','strong_support','penalty_risk'];
    $seed=tag_unified_match_tag_seed_catalog();$out=[];
    foreach($keys as $key){$r=$seed[$key];$out[$key]=['key'=>$key,'label'=>$r[0],'category'=>$r[1],'scope'=>in_array($key,['auto_no_attempt','no_scoring','no_defense','end_no_attempt'],true)?'match':'moment','definition_version'=>'neptune-match-tags-v1'];}
    if($match!==null)$out=array_merge($out,community_video_game_config($match)['actions']);
    return $out;
}
/** One control per existing tag; keyboard map is rendered into data-shortcut. */
function community_video_tag_controls():array {
    return [
        'Scoring & Defense'=>[
            'scoring'=>['Scoring','S'], 'attempted_defense'=>['Defense','D'],
            'fast_cycles'=>['Fast cycles','T'], 'slow_cycles'=>['Slow cycles','L'],
            'no_scoring'=>['No scoring','G'], 'no_defense'=>['No defense','H'],
        ],
        'Autonomous'=>[
            'auto_no_attempt'=>['No attempt','Q'], 'auto_partial'=>['Partial','W'],
            'auto_completed'=>['Completed','A'], 'auto_failed'=>['Failed','F'],
        ],
        'Endgame'=>[
            'end_no_attempt'=>['No attempt','V'], 'end_partial'=>['Partial','B'],
            'end_completed'=>['Completed','E'], 'end_failed'=>['Failed','N'],
        ],
        'Reliability'=>[
            'mechanical_issues'=>['Mechanical issues','M'], 'disabled'=>['Disabled','X'],
            'recovered'=>['Recovered','C'],
        ],
        'Alliance'=>[
            'strong_support'=>['Support','K'], 'penalty_risk'=>['Penalty risk','P'],
        ],
    ];
}
function community_video_tags_schema(PDO $db): void {
    neptune_migration_apply($db,'community.video.tags.v1','Neptune_Community_Video_Tags_v1.zip',function()use($db):void{
        $db->exec("CREATE TABLE IF NOT EXISTS community_video_labels (
          id CHAR(32) COLLATE utf8mb4_bin PRIMARY KEY, match_id BIGINT UNSIGNED NOT NULL,
          contributor_key CHAR(64) NOT NULL,user_id BIGINT UNSIGNED NULL,ip_key CHAR(64) NOT NULL,
          source_url VARCHAR(1000) NOT NULL,video_id CHAR(11) NOT NULL,roster_json TEXT NOT NULL,
          team_number INT NOT NULL,tag_key VARCHAR(64) NOT NULL,tag_label VARCHAR(120) NOT NULL,
          tag_category VARCHAR(80) NOT NULL,definition_version VARCHAR(80) NOT NULL,
          label_scope VARCHAR(16) NOT NULL,start_ms INT NULL,end_ms INT NULL,video_duration_ms INT NOT NULL,
          certainty VARCHAR(16) NOT NULL,note TEXT NOT NULL,review_state VARCHAR(16) NOT NULL DEFAULT 'contributed',
          created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
          KEY match_labels(match_id,created_at),KEY contributor_labels(contributor_key,created_at),
          KEY training_labels(review_state,created_at),KEY rate_lookup(ip_key,created_at),
          FOREIGN KEY(match_id) REFERENCES community_matches(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS community_video_label_reviews (
          id CHAR(32) COLLATE utf8mb4_bin PRIMARY KEY,label_id CHAR(32) COLLATE utf8mb4_bin NOT NULL,
          reviewer_user_id BIGINT UNSIGNED NULL,action VARCHAR(16) NOT NULL,note TEXT NOT NULL,created_at BIGINT NOT NULL,
          KEY label_history(label_id,created_at),FOREIGN KEY(label_id) REFERENCES community_video_labels(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    });
    neptune_migration_apply($db,'community.video.tags.learning.v3','Neptune_Retrainable_Tags_v5.zip',function()use($db):void{
        $db->exec("ALTER TABLE community_video_labels ADD COLUMN polarity VARCHAR(8) NOT NULL DEFAULT 'present'");
    });
    neptune_migration_apply($db,'community.video.scoring.v1','Neptune_Community_Scoring_v1.zip',function()use($db):void{
        $db->exec("ALTER TABLE community_video_labels ADD COLUMN scoring_json TEXT NULL, ADD COLUMN replaces_id CHAR(32) NULL");
    });
    neptune_migration_apply($db,'community.video.tags.open.v2','Neptune_Community_Tagging_Studio_v3.zip',function()use($db):void{
        $db->exec("ALTER TABLE community_video_labels ALTER COLUMN review_state SET DEFAULT 'contributed'");
        community_video_tags_remove_queue($db);
    });
}
function community_video_tags_remove_queue(PDO $db):void {
    // Preserve the contributor's original certainty and all withdrawn/legacy decisions.
    $db->exec("UPDATE community_video_labels SET review_state='contributed' WHERE review_state='pending'");
}
final class CommunityVideoTags {
    public function __construct(private PDO $db,private mixed $scoreFetch=null){}
    private function q(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    public function match(int $id):array {
        $r=$this->q('SELECT id,match_key,title,source_url,roster_json FROM community_matches WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new CommunityAnalysisError(404,'Match not found. Choose a match from Browse matches.');
        $r['video_id']=self::videoId($r['source_url']);$r['roster']=json_decode($r['roster_json'],true,512,JSON_THROW_ON_ERROR);
        if(!$r['video_id']||count(array_unique(array_merge($r['roster']['red']??[],$r['roster']['blue']??[])))!==6)throw new CommunityAnalysisError(422,'This match needs a linked YouTube video and six-team roster before it can be tagged.');
        return $r;
    }
    public static function videoId(string $url):?string {
        $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||!in_array(strtolower($p['host']??''),['youtube.com','www.youtube.com'],true)||($p['path']??'')!=='/watch')return null;
        parse_str($p['query']??'',$q);return is_string($q['v']??null)&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$q['v'])?$q['v']:null;
    }
    public static function validate(array $in,array $match):array {
        $catalog=community_video_tag_catalog($match);$key=$in['tag_key']??'';
        if(!is_string($key)||!isset($catalog[$key]))throw new CommunityAnalysisError(422,'Choose an available game or scouting action.');
        $tag=$catalog[$key];$team=filter_var($in['team']??null,FILTER_VALIDATE_INT);
        if(!in_array($team,array_merge($match['roster']['red'],$match['roster']['blue']),true))throw new CommunityAnalysisError(422,'Choose one of the six robots in this match.');
        $scope=$in['scope']??'';$duration=filter_var($in['duration_ms']??null,FILTER_VALIDATE_INT);
        if(!in_array($scope,['moment','interval','match'],true)||($tag['scope']==='match'&&$scope!=='match')||(str_starts_with($key,'gb:')&&$scope!=='moment'))throw new CommunityAnalysisError(422,'Choose a valid recording type for this action.');
        if($duration===false||$duration<1000||$duration>600000)throw new CommunityAnalysisError(422,'Wait for a match video of ten minutes or less to load.');
        $start=$scope==='match'?null:filter_var($in['start_ms']??null,FILTER_VALIDATE_INT);
        $end=$scope==='match'?null:filter_var($in['end_ms']??null,FILTER_VALIDATE_INT);
        if($scope!=='match'&&($start===false||$end===false||$start<0||$end<$start||$end>$duration||($scope==='moment'&&$start!==$end)||($scope==='interval'&&$end<=$start)))throw new CommunityAnalysisError(422,'The label needs a valid video timestamp or start/end interval.');
        $polarity=$in['polarity']??'present';
        if(!in_array($polarity,['present','absent'],true)||($polarity==='absent'&&($tag['scope']==='match'||$scope==='match')))throw new CommunityAnalysisError(422,'Counterexamples need a timestamp or interval and a positive action tag.');
        $certainty=$in['certainty']??'';$note=$in['note']??'';
        if(!in_array($certainty,['observed','uncertain'],true)||!is_string($note)||mb_strlen($note)>1000)throw new CommunityAnalysisError(422,'Choose certainty and keep notes under 1,000 characters.');
        $id=$in['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new CommunityAnalysisError(422,'Invalid label identifier.');
        $replaces=$in['replaces_id']??null;
        if($replaces!==null&&(!is_string($replaces)||!preg_match('/^[a-f0-9]{32}$/D',$replaces)||$replaces===$id))throw new CommunityAnalysisError(422,'Invalid corrected label reference.');
        $validated=['id'=>$id,'team'=>$team,'tag'=>$tag,'scope'=>$scope,'start_ms'=>$start,'end_ms'=>$end,'duration_ms'=>$duration,'certainty'=>$certainty,'polarity'=>$polarity,'note'=>trim($note)];
        $validated['scoring']=community_scoring_validate($in['scoring']??null,$match,$validated);$validated['replaces_id']=$replaces;
        return $validated;
    }
    public function mine(int $match,string $actor):array {return array_map('community_scoring_decode',$this->q('SELECT id,team_number,tag_key,tag_label,tag_category,polarity,label_scope,start_ms,end_ms,certainty,note,review_state,created_at,scoring_json,replaces_id FROM community_video_labels WHERE match_id=? AND contributor_key=? ORDER BY created_at DESC,id DESC LIMIT 200',[$match,$actor])->fetchAll(PDO::FETCH_ASSOC));}
    public function save(array $in,int $match,string $actor,?int $user,string $ip):string {
        $m=$this->match($match);
        $candidate=$in['id']??'';$prior=is_string($candidate)&&preg_match('/^[a-f0-9]{32}$/D',$candidate)?$this->q('SELECT * FROM community_video_labels WHERE id=?',[$candidate])->fetch(PDO::FETCH_ASSOC):false;
        if(is_array($in['scoring']??null)&&($in['scoring']['mode']??'')==='alliance_share'){
            $stored=$prior?json_decode($prior['scoring_json']??'null',true):null;
            $m['alliance_totals']=is_array($stored)&&($stored['mode']??'')==='alliance_share'?[$stored['alliance']=>$stored['alliance_score'],'source'=>$stored['alliance_score_source'],'source_url'=>$stored['alliance_score_source_url'],'fetched_at'=>$stored['alliance_score_fetched_at']]:community_scoring_alliance_totals($m,$this->scoreFetch);
        }
        $v=self::validate($in,$m);
        if($prior){
            if($prior['contributor_key']!==$actor||(int)$prior['match_id']!==$match||(int)$prior['team_number']!==$v['team']||$prior['tag_key']!==$v['tag']['key']||$prior['label_scope']!==$v['scope']||$prior['start_ms']!=$v['start_ms']||$prior['end_ms']!=$v['end_ms']||($prior['polarity']??'present')!==$v['polarity']||($prior['replaces_id']??null)!==$v['replaces_id']||json_decode($prior['scoring_json']??'null',true)!==$v['scoring']||$prior['certainty']!==$v['certainty']||$prior['note']!==$v['note'])throw new CommunityAnalysisError(409,'This label identifier has already been used.');
            return $v['id'];
        }
        $since=time()-60;$n=$this->q('SELECT COUNT(*) FROM community_video_labels WHERE created_at>? AND (contributor_key=? OR ip_key=?)',[$since,$actor,$ip])->fetchColumn();
        if((int)$n>=180)throw new CommunityAnalysisError(429,'Please pause briefly before saving more labels.');
        $cols=['id','match_id','contributor_key','user_id','ip_key','source_url','video_id','roster_json','team_number','tag_key','tag_label','tag_category','definition_version','label_scope','start_ms','end_ms','video_duration_ms','certainty','note','review_state','created_at','updated_at','polarity','scoring_json','replaces_id'];
        $this->db->beginTransaction();try {
        if($v['replaces_id']){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $original=$this->q('SELECT * FROM community_video_labels WHERE id=?'.$lock,[$v['replaces_id']])->fetch(PDO::FETCH_ASSOC);
            if(!$original||$original['contributor_key']!==$actor||(int)$original['match_id']!==$match||$original['review_state']==='withdrawn'||$original['tag_key']!=='scoring'||$v['tag']['key']!=='scoring'||(int)$original['team_number']!==$v['team']||$original['start_ms']!=$v['start_ms']||$original['end_ms']!=$v['end_ms']||$original['label_scope']!==$v['scope'])throw new CommunityAnalysisError(409,'Only your own active scoring entry can receive corrected amount details.');
            $this->q("UPDATE community_video_labels SET review_state='withdrawn',updated_at=? WHERE id=?",[time(),$v['replaces_id']]);
            $this->q('INSERT INTO community_video_label_reviews VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$v['replaces_id'],$user,'corrected','Scoring details replaced by '.$v['id'],time()]);
        }
        $this->q('INSERT INTO community_video_labels ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',[$v['id'],$match,$actor,$user,$ip,$m['source_url'],$m['video_id'],$m['roster_json'],$v['team'],$v['tag']['key'],$v['tag']['label'],$v['tag']['category'],$v['tag']['definition_version'],$v['scope'],$v['start_ms'],$v['end_ms'],$v['duration_ms'],$v['certainty'],$v['note'],'contributed',time(),time(),$v['polarity'],$v['scoring']===null?null:json_encode($v['scoring'],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$v['replaces_id']]);
        $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $v['id'];
    }
    public function withdraw(string $id,string $actor):void {
        $this->db->beginTransaction();try{
            $q=$this->q("UPDATE community_video_labels SET review_state='withdrawn',updated_at=? WHERE id=? AND contributor_key=? AND review_state<>'withdrawn'",[time(),$id,$actor]);
            if($q->rowCount())$this->q('INSERT INTO community_video_label_reviews VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$id,null,'withdrawn','Contributor withdrew this label.',time()]);
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function training():PDOStatement {
        return $this->q("SELECT l.id,l.match_id,m.match_key,l.source_url,l.video_id,l.roster_json,l.team_number,l.tag_key,l.tag_label,l.tag_category,l.definition_version,l.label_scope,l.start_ms,l.end_ms,l.video_duration_ms,l.certainty,l.polarity,l.scoring_json,l.replaces_id,l.note,l.review_state,l.created_at FROM community_video_labels l JOIN community_matches m ON m.id=l.match_id WHERE l.review_state IN ('contributed','verified') ORDER BY l.match_id,l.id");
    }
}
