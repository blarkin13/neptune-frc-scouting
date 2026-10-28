<?php
/** Neptune QA Center: org-scoped, read-only validation of real scouting workflows.
 *  This module does not submit actions, change matches, or change scouting data.
 */
declare(strict_types=1);
require_once __DIR__.'/_qa_catalog.php';
require_once __DIR__.'/_qa_questions.php';
require_once __DIR__.'/_qa_modules.php';

function nq_add_column(PDO $pdo, string $table, string $column, string $ddl): void {
    $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $q->execute([$table, $column]);
    if (!$q->fetchColumn()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl"); }
}
function nq_install(PDO $pdo): void {
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) { return; }
    nq_module_catalog_install($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      created_by BIGINT UNSIGNED NOT NULL,
      tester_user_id BIGINT UNSIGNED NOT NULL,
      event_id BIGINT UNSIGNED NOT NULL,
      match_id BIGINT UNSIGNED NOT NULL,
      frc_team_number INT UNSIGNED NOT NULL,
      alliance VARCHAR(4) NOT NULL,
      station TINYINT UNSIGNED NOT NULL,
      scenario VARCHAR(32) NOT NULL,
      baseline_run SMALLINT UNSIGNED NOT NULL,
      baseline_action_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      baseline_session_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      baseline_tag_hash CHAR(64) NOT NULL DEFAULT '',
      baseline_match_state VARCHAR(16) NOT NULL,
      status VARCHAR(16) NOT NULL DEFAULT 'open',
      evidence_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      KEY ix_qa_organization (organization_id, created_at),
      KEY ix_qa_tester (organization_id, tester_user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_checkpoints (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      qa_run_id BIGINT UNSIGNED NOT NULL,
      evidence_hash CHAR(64) NOT NULL,
      result_status VARCHAR(16) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_qa_checkpoint (qa_run_id,evidence_hash),
      KEY ix_qa_check_org (organization_id,qa_run_id,checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // QA-only metadata, never a general-purpose database browser. Additive schema.
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_feedback (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      qa_run_id BIGINT UNSIGNED NOT NULL,
      reporter_user_id BIGINT UNSIGNED NOT NULL,
      step_key VARCHAR(48) NOT NULL DEFAULT '',
      category VARCHAR(24) NOT NULL,
      severity VARCHAR(16) NOT NULL,
      summary VARCHAR(190) NOT NULL,
      details TEXT NULL,
      review_status VARCHAR(16) NOT NULL DEFAULT 'open',
      reviewed_by BIGINT UNSIGNED NULL,
      reviewed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY ix_qa_feedback_org (organization_id,review_status,created_at),
      KEY ix_qa_feedback_run (organization_id,qa_run_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_responses (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      qa_run_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      scenario VARCHAR(32) NOT NULL,
      question_key VARCHAR(32) NOT NULL,
      answer_value VARCHAR(700) NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      UNIQUE KEY uq_qa_response (qa_run_id,question_key),
      KEY ix_qa_response_org (organization_id,scenario,question_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_qa_navigation (
      qa_run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      organization_id BIGINT UNSIGNED NOT NULL,
      tester_user_id BIGINT UNSIGNED NOT NULL,
      page_kind VARCHAR(24) NOT NULL,
      page_path VARCHAR(200) NOT NULL,
      reported_match_id BIGINT UNSIGNED NOT NULL,
      reported_robot INT UNSIGNED NOT NULL,
      last_seen_at DATETIME NOT NULL,
      KEY ix_qa_nav_user (organization_id,tester_user_id,last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Additive columns for module baselines, exiting a test, and structured bug reports.
    nq_add_column($pdo,'neptune_qa_runs','baseline_json','LONGTEXT NULL');
    nq_add_column($pdo,'neptune_qa_runs','exit_reason','VARCHAR(24) NULL');
    nq_add_column($pdo,'neptune_qa_runs','exit_note','VARCHAR(500) NULL');
    nq_add_column($pdo,'neptune_qa_runs','exited_at','DATETIME NULL');
    nq_add_column($pdo,'neptune_qa_runs','tester_finished_at','DATETIME NULL');
    nq_add_column($pdo,'neptune_qa_runs','client_report_json','LONGTEXT NULL');
    nq_add_column($pdo,'neptune_qa_feedback','trying_text','VARCHAR(400) NULL');
    nq_add_column($pdo,'neptune_qa_feedback','expected_text','VARCHAR(600) NULL');
    nq_add_column($pdo,'neptune_qa_feedback','repeatable','VARCHAR(12) NULL');
    nq_add_column($pdo,'neptune_qa_feedback','impact','VARCHAR(16) NULL');
    nq_add_column($pdo,'neptune_qa_feedback','context_json','TEXT NULL');
    $done[$id] = true;
}
function nq_manager(array $user): bool {
    return in_array((string)($user['role']??''),['owner','admin','strategy'],true);
}
function nq_qa_event(array $event): bool {
    return preg_match('/(^|[^a-z])(test|qa|sandbox)([^a-z]|$)/i',(string)($event['name']??$event['event_name']??''))===1
      && trim((string)($event['tba_event_key']??''))==='';
}
function nq_load(PDO $pdo, int $org, int $runId, array $user): ?array {
    $q=$pdo->prepare('SELECT q.*,e.name event_name,e.event_code,m.comp_level,m.match_number,m.state match_state,m.run_number current_run, us.display_name tester_name FROM neptune_qa_runs q JOIN events e ON e.id=q.event_id AND e.organization_id=q.organization_id JOIN matches m ON m.id=q.match_id AND m.organization_id=q.organization_id AND m.event_id=q.event_id JOIN users us ON us.id=q.tester_user_id AND us.organization_id=q.organization_id WHERE q.id=? AND q.organization_id=? LIMIT 1');
    $q->execute([$runId,$org]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row || (!nq_manager($user) && (int)$row['tester_user_id']!==(int)$user['id']))return null;
    return $row;
}
function nq_tag_hash(?array $r): string {
    if(!$r)return '';
    return hash('sha256',json_encode([
      $r['scoring_contribution']??null,
      json_decode((string)($r['tags_json']??'[]'),true),
      json_decode((string)($r['tag_weights_json']??'{}'),true),
      $r['note']??null
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function nq_step(string $name,string $label,bool $passed,string $detail,bool $failed=false): array {
    return ['key'=>$name,'label'=>$label,'status'=>$failed?'failed':($passed?'passed':'waiting'),'detail'=>$detail];
}
function nq_create(PDO $pdo,int $org,int $creator,int $tester,int $matchId,int $robot,string $scenario): int {
    $module=nq_module_for_run($scenario);
    if(!$module || $module['mode']!=='automated')throw new InvalidArgumentException('This QA module does not have an executable verifier yet.');
    $q=$pdo->prepare('SELECT m.id,m.organization_id,m.event_id,m.state,m.run_number,e.name,e.event_code,e.tba_event_key,mt.alliance,mt.station FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id JOIN match_teams mt ON mt.match_id=m.id WHERE m.organization_id=? AND m.id=? AND mt.frc_team_number=? LIMIT 1');
    $q->execute([$org,$matchId,$robot]);$match=$q->fetch(PDO::FETCH_ASSOC);
    if(!$match || nq_qa_slot($match)!==$module['slot'])throw new InvalidArgumentException('Select a match in QA Test Event '.$module['slot'].' belonging to your organization.');
    nq_validate_module_target($pdo,$org,$scenario,$match,$robot);
    $q=$pdo->prepare('SELECT id,role FROM users WHERE id=? AND organization_id=? AND active=1');$q->execute([$tester,$org]);$testerRecord=$q->fetch(PDO::FETCH_ASSOC);
    if(!$testerRecord)throw new InvalidArgumentException('Tester is not active in your organization.');
    if(!in_array((string)$testerRecord['role'],$module['roles'],true))throw new InvalidArgumentException('This module requires tester role: '.implode(', ',$module['roles']).'.');
    $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM scouting_actions WHERE organization_id=? AND match_id=? AND frc_team_number=? AND created_by=?');
    $q->execute([$org,$matchId,$robot,$tester]);$lastAction=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM scout_sessions WHERE organization_id=? AND match_id=? AND frc_team_number=? AND user_id=?');
    $q->execute([$org,$matchId,$robot,$tester]);$lastSession=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT scoring_contribution,tags_json,tag_weights_json,note FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND frc_team_number=? AND user_id=?');
    $q->execute([$org,$matchId,$robot,$tester]);$tag=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('INSERT INTO neptune_qa_runs (organization_id,created_by,tester_user_id,event_id,match_id,frc_team_number,alliance,station,scenario,baseline_run,baseline_action_id,baseline_session_id,baseline_tag_hash,baseline_match_state,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$org,$creator,$tester,$match['event_id'],$matchId,$robot,$match['alliance'],$match['station'],$scenario,(int)$match['run_number'],$lastAction,$lastSession,nq_tag_hash($tag),(string)$match['state']]);
        $runId=(int)$pdo->lastInsertId();
        // Admin-level setup is done by the QA system itself (assignment, alliance score, review baselines).
        $baseline=nq_prepare_module($pdo,$org,$creator,$tester,$scenario,$match,$robot);
        if($baseline)$pdo->prepare('UPDATE neptune_qa_runs SET baseline_json=? WHERE id=? AND organization_id=?')->execute([json_encode($baseline,JSON_UNESCAPED_SLASHES),$runId,$org]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return $runId;
}
/** The verifier reads published scoring rules and actual writes; it never writes scouting rows. */
function nq_evaluate(PDO $pdo,array $run): array {
    $org=(int)$run['organization_id'];$mid=(int)$run['match_id'];$robot=(int)$run['frc_team_number'];
    $uid=(int)$run['tester_user_id'];$baseline=(int)$run['baseline_action_id'];$runNumber=(int)$run['baseline_run'];
    $q=$pdo->prepare('SELECT m.state,m.run_number,m.match_number,m.started_at,m.ended_at,m.red_score,m.blue_score FROM matches m WHERE m.id=? AND m.organization_id=? AND m.event_id=?');
    $q->execute([$mid,$org,(int)$run['event_id']]);$match=$q->fetch(PDO::FETCH_ASSOC);
    if(!$match)throw new RuntimeException('QA match is missing.');
    $evidence=['match'=>$mid,'run'=>$runNumber,'current_state'=>$match['state'],'team'=>$robot,'tester_id'=>$uid,'expected_station'=>(string)$run['alliance'].' '.(int)$run['station']];
    if((int)$match['run_number']!==$runNumber){
        return ['status'=>'failed','steps'=>[nq_step('run','Same scouting run',false,'Match was rescouted after this QA run was assigned.',true)],'evidence'=>$evidence,'page'=>null];
    }
    // Advisory page tracking never substitutes for a scouting/Tag database write.
    $q=$pdo->prepare('SELECT page_kind,last_seen_at FROM neptune_user_page_presence WHERE organization_id=? AND user_id=? ORDER BY last_seen_at DESC LIMIT 1');
    $q->execute([$org,$uid]);$presence=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $presenceFresh=$presence&&strtotime((string)$presence['last_seen_at'].' UTC')>=time()-120;
    $q=$pdo->prepare('SELECT page_kind,page_path,reported_match_id,reported_robot,last_seen_at FROM neptune_qa_navigation WHERE qa_run_id=? AND organization_id=? AND tester_user_id=? LIMIT 1');
    $q->execute([(int)$run['id'],$org,$uid]);$nav=$q->fetch(PDO::FETCH_ASSOC)?:null;
    $navFresh=$nav&&strtotime((string)$nav['last_seen_at'].' UTC')>=time()-120;
    $expectedPage=nq_uses_traditional_scouting((string)$run['scenario'])?'traditional':((string)$run['scenario']==='tag'?'tag-match':null);
    $correctPage=$expectedPage!==null&&$navFresh&&(string)$nav['page_kind']===$expectedPage&&(int)$nav['reported_match_id']===$mid&&(int)$nav['reported_robot']===$robot;
    $evidence['page_tracking']=['expected'=>$expectedPage,'observed'=>$navFresh?($nav['page_kind']??null):($presenceFresh?($presence['page_kind']??null):null),
      'correct_qa_target'=>(bool)$correctPage,'path'=>$navFresh?($nav['page_path']??null):null,
      'last_seen_at'=>$nav['last_seen_at']??($presence['last_seen_at']??null),
      'note'=>'Client-reported advisory presence. Only database scoring/Tag writes determine pass/fail.'];
    $steps=[];
    if($run['scenario']==='traditional'){
        $q=$pdo->prepare('SELECT id,created_at,status,last_seen_at,alliance,station,frc_team_number FROM scout_sessions WHERE organization_id=? AND event_id=? AND match_id=? AND match_run_number=? AND frc_team_number=? AND user_id=? AND id>? ORDER BY id DESC LIMIT 1');
        $q->execute([$org,(int)$run['event_id'],$mid,$runNumber,$robot,$uid,(int)$run['baseline_session_id']]);$session=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $sessionValid=$session&&(string)$session['alliance']===(string)$run['alliance']&&(int)$session['station']===(int)$run['station'];
        $q=$pdo->prepare('SELECT id,scout_session_id,action_code,action_type,result,points,match_time_sec,phase,deleted_at,deleted_by,deletion_reason FROM scouting_actions WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND match_run_number=? AND created_by=? AND id>? ORDER BY id ASC LIMIT 250');
        $q->execute([$org,(int)$run['event_id'],$mid,$robot,$runNumber,$uid,$baseline]);$allActions=$q->fetchAll(PDO::FETCH_ASSOC);
        $actions=$sessionValid?array_values(array_filter($allActions,static fn($a)=>(int)$a['scout_session_id']===(int)$session['id'])):[];
        $q=$pdo->prepare('SELECT gr.match_config_json FROM events e JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id WHERE e.id=? AND e.organization_id=?');
        $q->execute([(int)$run['event_id'],$org]);$config=(string)($q->fetchColumn()?:'');$decoded=json_decode($config,true)?:[];
        $offense=null;$defense=null;
        foreach(($decoded['buttons']??[]) as $b){
            if(!is_array($b)||trim((string)($b['code']??''))==='')continue;
            if(($b['type']??'')==='offense'&&$offense===null)$offense=['code'=>(string)$b['code'],'name'=>(string)($b['name']??$b['code'])];
            if(($b['type']??'')==='defense'&&$defense===null)$defense=['code'=>(string)$b['code'],'name'=>(string)($b['name']??$b['code'])];
        }
        $evidence['expected_actions']=['offense'=>$offense,'defense'=>$defense];
        $success=array_values(array_filter($actions,static fn($a)=>$offense!==null&&$a['action_code']===$offense['code']&&$a['result']==='Success'));
        $failure=array_values(array_filter($actions,static fn($a)=>$offense!==null&&$a['action_code']===$offense['code']&&$a['result']==='Failure'));
        $defenseRows=array_values(array_filter($actions,static fn($a)=>$defense!==null&&$a['action_code']===$defense['code']));
        $undo=array_values(array_filter($actions,static fn($a)=>$a['deletion_reason']==='scout_undo'&&$a['deleted_at']!==null&&(int)$a['deleted_by']===$uid));
        $active=array_values(array_filter($actions,static fn($a)=>$a['deleted_at']===null));
        // Independent SQL aggregate proves the undo affected the authoritative active totals.
        $storedTotals=['action_count'=>0,'points'=>0];
        if($sessionValid){
            $totalsQuery=$pdo->prepare('SELECT COUNT(*) AS action_count,COALESCE(SUM(points),0) AS points FROM scouting_actions WHERE organization_id=? AND event_id=? AND match_id=? AND scout_session_id=? AND match_run_number=? AND created_by=? AND id>? AND deleted_at IS NULL');
            $totalsQuery->execute([$org,(int)$run['event_id'],$mid,(int)$session['id'],$runNumber,$uid,$baseline]);
            $storedTotals=$totalsQuery->fetch(PDO::FETCH_ASSOC)?:$storedTotals;
        }
        $totalsCorrect=$sessionValid && (int)$storedTotals['action_count']===count($active) && abs((float)$storedTotals['points']-array_sum(array_map('floatval',array_column($active,'points'))))<0.011;
        $errors=[];
        if(count($allActions)>=250)$errors[]='This QA run exceeded the 250-action verifier limit; use a fresh fixture';
        if($actions){require_once dirname(__DIR__).'/api/_validate-scout-action.php';
            foreach($actions as $a){
                try{$v=neptune_validated_scout_action(['code'=>$a['action_code']],$a['result'],$a['match_time_sec'],$config);
                    if(abs((float)$v['points']-(float)$a['points'])>0.011 || $v['type']!==$a['action_type'] || $v['phase']!==$a['phase'])$errors[]='Action #'.$a['id'].' has incorrect points/type/phase';
                }catch(Throwable $ex){$errors[]='Action #'.$a['id'].' failed game-rule validation';}
            }
        }
        $started=$run['baseline_match_state']==='ready' && !empty($match['started_at']) && strtotime((string)$match['started_at'].' UTC')>=strtotime((string)$run['created_at'].' UTC');
        $successId=$success?(int)$success[0]['id']:0;
        $failureAfter=array_values(array_filter($failure,static fn($a)=>(int)$a['id']>$successId));
        $failureId=($successId&&$failureAfter)?(int)$failureAfter[0]['id']:0;
        $defenseAfter=array_values(array_filter($defenseRows,static fn($a)=>(int)$a['id']>$failureId));
        $defenseId=($failureId&&$defenseAfter)?(int)$defenseAfter[0]['id']:0;
        $lastExpectedId=$defense!==null?$defenseId:$failureId;
        $undoAfter=array_values(array_filter($undo,static fn($a)=>(int)$a['id']>=$lastExpectedId));
        $ordered=$successId>0&&$failureId>0&&($defense===null||$defenseId>0);
        $validUndo=$ordered&&$undoAfter;
        $evidence['scout_session_id']=$session['id']??null;
        $evidence['new_action_count']=count($actions);$evidence['active_actions']=count($active);
        $evidence['recorded_points']=round(array_sum(array_map('floatval',array_column($active,'points'))),2);
        $evidence['stored_active_totals']=['actions'=>(int)$storedTotals['action_count'],'points'=>(float)$storedTotals['points']];
        $evidence['action_ids']=array_map('intval',array_column($actions,'id'));$evidence['validation_errors']=$errors;
        $evidence['required_sequence_ids']=['success'=>$successId,'failure'=>$failureId,'defense'=>$defenseId,'undo'=>($undoAfter?(int)$undoAfter[0]['id']:null)];
        $steps[]=nq_step('start','QA match is started (the tester\'s Start button does this automatically)',$started,'Match state: '.$match['state']);
        $steps[]=nq_step('connected','Tester joins '.(string)$run['alliance'].' station '.(int)$run['station'],(bool)$sessionValid,$sessionValid?'Authenticated session #'.$session['id']:'Waiting for assigned tester on the correct QA robot/station', (bool)$session&&!$sessionValid);
        $steps[]=nq_step('success','Record SUCCESS: '.($offense['name']??'no offense action configured'),(bool)$success,'Expected action code: '.($offense['code']??'missing').'; matching records: '.count($success),$offense===null);
        $steps[]=nq_step('failure','Record FAILURE: '.($offense['name']??'no offense action configured'),(bool)$failureAfter,'Use the same action after SUCCESS. Matching later records: '.count($failureAfter),$offense===null);
        if($defense!==null)$steps[]=nq_step('defense','Record a defense attempt: '.$defense['name'],(bool)$defenseAfter,'Expected action code: '.$defense['code'].'; must follow the failed attempt');
        $steps[]=nq_step('points','Neptune checks points and game phase against the published rules (automatic, nothing to do)',count($actions)>0&&!$errors,count($actions).' recorded actions checked'.($errors?'; '.implode('; ',$errors):''),(bool)$errors);
        $steps[]=nq_step('undo','Use Undo after completing the requested actions',(bool)$validUndo,$undoAfter?'Audited deletion of action #'.$undoAfter[0]['id']:'Waiting for an auditable undo after the scripted actions');
        $steps[]=nq_step('totals','Neptune checks the score and action count after your Undo (automatic, nothing to do)',(bool)($validUndo&&$totalsCorrect),
          'Database active actions: '.(int)$storedTotals['action_count'].' · score: '.round((float)$storedTotals['points'],2).($totalsCorrect?' · matches independent verifier':' · differs from expected after undo'),$sessionValid&&!$totalsCorrect);
        $steps[]=nq_step('end','QA match ends (Neptune ends it automatically once every other step has passed)',$match['state']==='ended'&&$started,'Match state: '.$match['state']);
    }elseif($run['scenario']==='tag'){
        $q=$pdo->prepare('SELECT id,observation_id,scoring_contribution,tags_json,tag_weights_json,note,updated_at,alliance,station FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND user_id=? LIMIT 1');
        $q->execute([$org,(int)$run['event_id'],$mid,$robot,$uid]);$tag=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $changed=$tag&&nq_tag_hash($tag)!==(string)$run['baseline_tag_hash'];
        $tags=$tag?json_decode((string)$tag['tags_json'],true):[];if(!is_array($tags))$tags=[];
        $hasScore=$tag&&$tag['scoring_contribution']!==null&&(int)$tag['scoring_contribution']>=0&&(int)$tag['scoring_contribution']<=100;
        $correctStation=$tag&&(string)$tag['alliance']===(string)$run['alliance']&&(int)$tag['station']===(int)$run['station'];
        $unified=false;$unifiedTags=[];
        if($tag&&(int)($tag['observation_id']??0)>0){
            $q=$pdo->prepare("SELECT source_key FROM tag_scouting_observations WHERE id=? AND organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND created_by=? AND context='match' LIMIT 1");
            $q->execute([(int)$tag['observation_id'],$org,(int)$run['event_id'],$mid,$robot,$uid]);
            $source=$q->fetchColumn();$unified=$source!==false&&(string)$source==='match-data:'.(int)$tag['id'];
            if($unified){
                $q=$pdo->prepare('SELECT t.seed_key,t.slug FROM tag_scouting_observation_tags ot JOIN tag_scouting_tags t ON t.id=ot.tag_id WHERE ot.observation_id=? AND (t.organization_id IS NULL OR t.organization_id=?)');
                $q->execute([(int)$tag['observation_id'],$org]);
                foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$seed=(string)($r['seed_key']??'');$unifiedTags[]=str_starts_with($seed,'match:')?substr($seed,6):str_replace('-','_',(string)$r['slug']);}
            }
        }
        $linksCorrect=$unified&&in_array('scoring',$unifiedTags,true)&&in_array('attempted_defense',$unifiedTags,true);
        $steps[]=nq_step('save','Save a new or changed Tag observation',(bool)$changed,$tag?'Tag report #'.$tag['id'].'; prior report content must change':'Waiting for tester-owned Tag report');
        $steps[]=nq_step('station','Neptune confirms the report is for your assigned robot and station (automatic, nothing to do)',$changed&&(bool)$correctStation,$correctStation?'Matches assigned '.(string)$run['alliance'].' station '.(int)$run['station']:'Tag report station does not match assignment',(bool)$tag&&!$correctStation);
        $steps[]=nq_step('score','Set a scoring contribution from 0 to 100%',(bool)($changed&&$hasScore),$hasScore?'Saved estimate: '.(int)$tag['scoring_contribution'].'%':'No valid contribution saved');
        $steps[]=nq_step('scoring_tag','Select the Scoring tag',(bool)($changed&&in_array('scoring',$tags,true)),'Stored tags: '.implode(', ',$tags));
        $steps[]=nq_step('defense_tag','Select the Attempted Defense tag',(bool)($changed&&in_array('attempted_defense',$tags,true)),'Stored tags: '.implode(', ',$tags));
        $steps[]=nq_step('unified','Neptune copies your report into the shared Tag record (automatic, nothing to do; can take a few seconds after autosave)',(bool)($changed&&$linksCorrect),$linksCorrect?'Shared record #'.$tag['observation_id'].' contains both selected tags':'Waiting for Neptune to copy the tags to the shared record (it does this itself after autosave)');
        $evidence['tag_row_id']=$tag['id']??null;$evidence['tag_updated_at']=$tag['updated_at']??null;
        $evidence['share_pct']=$tag['scoring_contribution']??null;$evidence['tags']=$tags;
        $evidence['unified_tags']=$unifiedTags;$evidence['observation_id']=$tag['observation_id']??null;
    }elseif($run['scenario']==='assignment'){
        $steps=nq_eval_assignment($pdo,$run,$match,$evidence);
    }elseif($run['scenario']==='connection_guard'){
        $steps=nq_eval_connection_guard($pdo,$run,$match,$evidence);
    }elseif($run['scenario']==='analytics'){
        $steps=nq_eval_analytics($pdo,$run,$match,$evidence);
    }elseif($run['scenario']==='data_quality'){
        $steps=nq_eval_data_quality($pdo,$run,$match,$evidence);
    }else{
        throw new RuntimeException('QA verifier not implemented for this scenario.');
    }
    $failed=array_filter($steps,static fn($s)=>$s['status']==='failed');
    $waiting=array_filter($steps,static fn($s)=>$s['status']==='waiting');
    $status=$failed?'failed':(!$waiting?'passed':'in_progress');
    return ['status'=>$status,'steps'=>$steps,'evidence'=>$evidence,'page'=>$correctPage?$expectedPage:($presenceFresh?$presence['page_kind']:null)];
}
