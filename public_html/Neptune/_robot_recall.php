<?php
declare(strict_types=1);

/**
 * Neptune Robot Recall
 * Public, no-login audience game using Neptune's local team-logo cache.
 */

function robot_recall_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        organization_id BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        event_id BIGINT UNSIGNED NULL,
        season_year SMALLINT UNSIGNED NOT NULL,
        source_mode VARCHAR(24) NOT NULL DEFAULT 'event',
        room_code CHAR(6) NOT NULL,
        title VARCHAR(160) NOT NULL DEFAULT 'Robot Recall',
        question_count SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        question_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 15,
        status VARCHAR(24) NOT NULL DEFAULT 'lobby',
        current_question_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        question_started_at DATETIME(6) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_robot_recall_room (room_code),
        KEY idx_robot_recall_org_created (organization_id,created_at),
        KEY idx_robot_recall_event (event_id),
        CONSTRAINT fk_robot_recall_session_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
        CONSTRAINT fk_robot_recall_session_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_robot_recall_session_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_questions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL,
        correct_team_number INT UNSIGNED NOT NULL,
        correct_nickname VARCHAR(160) NULL,
        logo_path VARCHAR(255) NOT NULL,
        options_json LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_robot_recall_question_order (session_id,sort_order),
        CONSTRAINT fk_robot_recall_question_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_players (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        nickname VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        score INT UNSIGNED NOT NULL DEFAULT 0,
        correct_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_robot_recall_player_name (session_id,nickname),
        UNIQUE KEY uq_robot_recall_player_token (session_id,token_hash),
        KEY idx_robot_recall_player_score (session_id,score,correct_count),
        CONSTRAINT fk_robot_recall_player_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_answers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        question_id BIGINT UNSIGNED NOT NULL,
        player_id BIGINT UNSIGNED NOT NULL,
        selected_team_number INT UNSIGNED NOT NULL,
        is_correct TINYINT(1) NOT NULL DEFAULT 0,
        response_ms INT UNSIGNED NOT NULL DEFAULT 0,
        points_awarded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        answered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_robot_recall_answer (question_id,player_id),
        KEY idx_robot_recall_answer_session (session_id,question_id),
        CONSTRAINT fk_robot_recall_answer_session FOREIGN KEY (session_id) REFERENCES robot_recall_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_robot_recall_answer_question FOREIGN KEY (question_id) REFERENCES robot_recall_questions(id) ON DELETE CASCADE,
        CONSTRAINT fk_robot_recall_answer_player FOREIGN KEY (player_id) REFERENCES robot_recall_players(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function robot_recall_table_exists(PDO $pdo,string $table): bool {
    static $cache=[];
    $key=$table;
    if(array_key_exists($key,$cache))return $cache[$key];
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->execute([$table]);
        return $cache[$key]=((int)$s->fetchColumn()>0);
    }catch(Throwable $e){return $cache[$key]=false;}
}

function robot_recall_clean_room(string $room): string {
    return preg_replace('/\D+/','',$room)??'';
}

function robot_recall_room(PDO $pdo,string $room): ?array {
    $room=robot_recall_clean_room($room);
    if(strlen($room)!==6)return null;
    $s=$pdo->prepare('SELECT s.*,e.name event_name,g.name game_name FROM robot_recall_sessions s LEFT JOIN events e ON e.id=s.event_id LEFT JOIN games g ON g.id=e.game_id WHERE s.room_code=? LIMIT 1');
    $s->execute([$room]);
    return $s->fetch()?:null;
}

function robot_recall_host_session(PDO $pdo,int $id,int $org): ?array {
    if($id<=0||$org<=0)return null;
    $s=$pdo->prepare('SELECT s.*,e.name event_name,g.name game_name FROM robot_recall_sessions s LEFT JOIN events e ON e.id=s.event_id LEFT JOIN games g ON g.id=e.game_id WHERE s.id=? AND s.organization_id=? LIMIT 1');
    $s->execute([$id,$org]);
    return $s->fetch()?:null;
}

function robot_recall_recent_sessions(PDO $pdo,int $org,int $limit=12): array {
    $limit=max(1,min(50,$limit));
    $s=$pdo->prepare("SELECT s.*,e.name event_name,(SELECT COUNT(*) FROM robot_recall_players p WHERE p.session_id=s.id) player_count FROM robot_recall_sessions s LEFT JOIN events e ON e.id=s.event_id WHERE s.organization_id=? ORDER BY s.created_at DESC LIMIT {$limit}");
    $s->execute([$org]);
    return $s->fetchAll();
}

function robot_recall_generate_room(PDO $pdo): string {
    for($i=0;$i<40;$i++){
        $room=(string)random_int(100000,999999);
        $s=$pdo->prepare('SELECT 1 FROM robot_recall_sessions WHERE room_code=? LIMIT 1');
        $s->execute([$room]);
        if(!$s->fetchColumn())return $room;
    }
    throw new RuntimeException('Could not allocate a game room code. Please try again.');
}

function robot_recall_public_root(): string {
    return __DIR__;
}

function robot_recall_logo_helper(): void {
    if(!function_exists('neptune_team_logo_info'))require_once __DIR__.'/analytics/_team_logo_cache.php';
}

function robot_recall_event_pool(PDO $pdo,int $org,int $eventId): array {
    robot_recall_logo_helper();
    $s=$pdo->prepare('SELECT e.id,e.name,g.season_year FROM events e JOIN games g ON g.id=e.game_id WHERE e.id=? AND e.organization_id=? LIMIT 1');
    $s->execute([$eventId,$org]);
    $event=$s->fetch();
    if(!$event)throw new RuntimeException('Event not found for your organization.');
    $season=(int)$event['season_year'];
    $q=$pdo->prepare('SELECT frc_team_number,nickname FROM event_teams WHERE event_id=? ORDER BY frc_team_number');
    $q->execute([$eventId]);
    $pool=[];
    foreach($q->fetchAll() as $row){
        $team=(int)$row['frc_team_number'];
        if($team<=0)continue;
        $info=neptune_team_logo_info($org,$season,$team);
        $pool[]=[
            'team'=>$team,
            'nickname'=>trim((string)($row['nickname']??'')),
            'logo_path'=>!empty($info['exists'])?(string)$info['path']:'',
        ];
    }
    return ['season'=>$season,'event'=>$event,'pool'=>$pool];
}

function robot_recall_cached_team_numbers(int $org): array {
    robot_recall_logo_helper();
    $dir=neptune_team_logo_abs_dir($org,0);
    if(!is_dir($dir))return [];
    $teams=[];
    foreach(['png','avif','webp','jpg','jpeg'] as $ext){
        foreach(glob($dir.'/frc*.'.$ext)?:[] as $file){
            if(preg_match('/\/frc(\d+)\.[A-Za-z0-9]+$/',(string)$file,$m))$teams[(int)$m[1]]=true;
        }
    }
    $out=array_keys($teams);sort($out,SORT_NUMERIC);return $out;
}

function robot_recall_team_names(PDO $pdo,int $org,int $season,array $teamNumbers): array {
    $wanted=array_fill_keys(array_map('intval',$teamNumbers),true);
    $names=[];
    if(robot_recall_table_exists($pdo,'augur_epa_team_directory')){
        try{
            $s=$pdo->prepare('SELECT frc_team_number,nickname,name FROM augur_epa_team_directory WHERE season_year=? ORDER BY frc_team_number');
            $s->execute([$season]);
            foreach($s->fetchAll() as $r){
                $n=(int)$r['frc_team_number'];if(!isset($wanted[$n]))continue;
                $name=trim((string)($r['nickname']??''));
                if($name==='')$name=trim((string)($r['name']??''));
                if($name!=='')$names[$n]=$name;
            }
        }catch(Throwable $e){}
    }
    try{
        $s=$pdo->prepare('SELECT et.frc_team_number,et.nickname FROM event_teams et JOIN events e ON e.id=et.event_id WHERE e.organization_id=? ORDER BY COALESCE(e.end_date,e.start_date,e.created_at) DESC,e.id DESC');
        $s->execute([$org]);
        foreach($s->fetchAll() as $r){
            $n=(int)$r['frc_team_number'];if(!isset($wanted[$n])||isset($names[$n]))continue;
            $name=trim((string)($r['nickname']??''));if($name!=='')$names[$n]=$name;
        }
    }catch(Throwable $e){}
    try{
        $s=$pdo->prepare('SELECT frc_team_number,COALESCE(NULLIF(nickname,\'\'),NULLIF(display_name,\'\')) team_name FROM teams WHERE organization_id=?');
        $s->execute([$org]);
        foreach($s->fetchAll() as $r){
            $n=(int)$r['frc_team_number'];if(!isset($wanted[$n])||isset($names[$n]))continue;
            $name=trim((string)($r['team_name']??''));if($name!=='')$names[$n]=$name;
        }
    }catch(Throwable $e){}
    return $names;
}

function robot_recall_all_cached_pool(PDO $pdo,int $org,int $season): array {
    robot_recall_logo_helper();
    $teams=robot_recall_cached_team_numbers($org);
    $names=robot_recall_team_names($pdo,$org,$season,$teams);
    $pool=[];
    foreach($teams as $team){
        $info=neptune_team_logo_info($org,$season,$team);
        if(empty($info['exists']))continue;
        $pool[]=['team'=>$team,'nickname'=>$names[$team]??'','logo_path'=>(string)$info['path']];
    }
    return ['season'=>$season,'event'=>null,'pool'=>$pool];
}

function robot_recall_option_label(int $team,string $nickname): string {
    $nickname=trim($nickname);
    return '#'.$team.($nickname!==''?' · '.$nickname:'');
}

function robot_recall_create_session(PDO $pdo,int $org,int $userId,array $input): array {
    robot_recall_ensure_schema($pdo);
    $source=(string)($input['source_mode']??'event');
    if(!in_array($source,['event','all_cached'],true))$source='event';
    $eventId=max(0,(int)($input['event_id']??0));
    $season=max(1992,min((int)date('Y')+1,(int)($input['season_year']??date('Y'))));
    $questionCount=max(3,min(50,(int)($input['question_count']??10)));
    $questionSeconds=max(5,min(60,(int)($input['question_seconds']??15)));
    $title=trim((string)($input['title']??'Robot Recall'));
    if($title==='')$title='Robot Recall';
    if(function_exists('mb_substr'))$title=mb_substr($title,0,160);else $title=substr($title,0,160);

    if($source==='event'){
        if($eventId<=0)throw new RuntimeException('Choose an event for this game.');
        $data=robot_recall_event_pool($pdo,$org,$eventId);
        $season=(int)$data['season'];
    }else{
        $eventId=0;
        $data=robot_recall_all_cached_pool($pdo,$org,$season);
    }
    $pool=$data['pool'];
    if(count($pool)<4)throw new RuntimeException('Robot Recall needs at least four teams in the selected pool.');
    $correctCandidates=array_values(array_filter($pool,static fn($x)=>trim((string)($x['logo_path']??''))!==''));
    if(count($correctCandidates)<1)throw new RuntimeException('No cached team logos are available for this pool. Download team logos first.');
    if($questionCount>count($correctCandidates))$questionCount=count($correctCandidates);
    if($questionCount<3 && count($correctCandidates)>=1)$questionCount=count($correctCandidates);

    shuffle($correctCandidates);
    $correctCandidates=array_slice($correctCandidates,0,$questionCount);
    $room=robot_recall_generate_room($pdo);

    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("INSERT INTO robot_recall_sessions(organization_id,created_by,event_id,season_year,source_mode,room_code,title,question_count,question_seconds,status,current_question_index) VALUES(?,?,?,?,?,?,?,?,?,'lobby',0)");
        $s->execute([$org,$userId,$eventId?:null,$season,$source,$room,$title,$questionCount,$questionSeconds]);
        $sessionId=(int)$pdo->lastInsertId();

        $ins=$pdo->prepare('INSERT INTO robot_recall_questions(session_id,sort_order,correct_team_number,correct_nickname,logo_path,options_json) VALUES(?,?,?,?,?,?)');
        foreach($correctCandidates as $i=>$correct){
            $distractors=array_values(array_filter($pool,static fn($x)=>(int)$x['team']!==(int)$correct['team']));
            shuffle($distractors);
            $options=array_merge([$correct],array_slice($distractors,0,3));
            if(count($options)<4)throw new RuntimeException('Not enough unique teams to build four answer choices.');
            shuffle($options);
            $optionRows=[];
            foreach($options as $o){
                $team=(int)$o['team'];$nick=trim((string)($o['nickname']??''));
                $optionRows[]=['team'=>$team,'nickname'=>$nick,'label'=>robot_recall_option_label($team,$nick)];
            }
            $ins->execute([$sessionId,$i+1,(int)$correct['team'],trim((string)($correct['nickname']??'')),(string)$correct['logo_path'],json_encode($optionRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
        $pdo->commit();
        $session=robot_recall_host_session($pdo,$sessionId,$org);
        if(!$session)throw new RuntimeException('Game was created but could not be reloaded.');
        return $session;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function robot_recall_question(PDO $pdo,int $sessionId,int $sortOrder): ?array {
    if($sessionId<=0||$sortOrder<=0)return null;
    $s=$pdo->prepare('SELECT * FROM robot_recall_questions WHERE session_id=? AND sort_order=? LIMIT 1');
    $s->execute([$sessionId,$sortOrder]);
    $row=$s->fetch();
    if(!$row)return null;
    $row['options']=json_decode((string)$row['options_json'],true)?:[];
    return $row;
}

function robot_recall_player_from_token(PDO $pdo,int $sessionId,string $token): ?array {
    $token=trim($token);if($sessionId<=0||strlen($token)<20)return null;
    $hash=hash('sha256',$token);
    $s=$pdo->prepare('SELECT * FROM robot_recall_players WHERE session_id=? AND token_hash=? LIMIT 1');
    $s->execute([$sessionId,$hash]);
    return $s->fetch()?:null;
}

function robot_recall_clean_nickname(string $nickname): string {
    $nickname=preg_replace('/[\x00-\x1F\x7F]/u','',trim($nickname))??'';
    $nickname=preg_replace('/\s+/u',' ',$nickname)??$nickname;
    if(function_exists('mb_substr'))$nickname=mb_substr($nickname,0,24);else $nickname=substr($nickname,0,24);
    return trim($nickname);
}

function robot_recall_join(PDO $pdo,array $session,string $nickname): array {
    if(($session['status']??'')==='finished')throw new RuntimeException('That Robot Recall game has already finished.');
    $nickname=robot_recall_clean_nickname($nickname);
    $len=function_exists('mb_strlen')?mb_strlen($nickname):strlen($nickname);
    if($len<2)throw new RuntimeException('Enter a nickname with at least 2 characters.');
    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM robot_recall_players WHERE session_id=?');$countStmt->execute([(int)$session['id']]);
    if((int)$countStmt->fetchColumn()>=300)throw new RuntimeException('This game room is full.');
    $token=bin2hex(random_bytes(24));
    try{
        $s=$pdo->prepare('INSERT INTO robot_recall_players(session_id,nickname,token_hash) VALUES(?,?,?)');
        $s->execute([(int)$session['id'],$nickname,hash('sha256',$token)]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')throw new RuntimeException('That nickname is already in this room. Choose another one.');
        throw $e;
    }
    return ['player_id'=>(int)$pdo->lastInsertId(),'token'=>$token,'nickname'=>$nickname];
}

function robot_recall_parse_utc_micro(?string $value): ?float {
    $value=trim((string)$value);if($value==='')return null;
    try{
        $dt=new DateTimeImmutable($value,new DateTimeZone('UTC'));
        return (float)$dt->format('U.u');
    }catch(Throwable $e){return null;}
}

function robot_recall_deadline_ms(array $session): ?int {
    $start=robot_recall_parse_utc_micro($session['question_started_at']??null);
    if($start===null)return null;
    return (int)round(($start+max(1,(int)$session['question_seconds']))*1000);
}

function robot_recall_leaderboard(PDO $pdo,int $sessionId,int $limit=10,?int $playerId=null): array {
    $limit=max(1,min(100,$limit));
    $s=$pdo->prepare("SELECT id,nickname,score,correct_count,joined_at FROM robot_recall_players WHERE session_id=? ORDER BY score DESC,correct_count DESC,joined_at ASC,id ASC LIMIT {$limit}");
    $s->execute([$sessionId]);
    $rows=[];$rank=0;
    foreach($s->fetchAll() as $r){$rank++;$rows[]=['rank'=>$rank,'id'=>(int)$r['id'],'nickname'=>(string)$r['nickname'],'score'=>(int)$r['score'],'correct_count'=>(int)$r['correct_count']];}
    $playerRank=null;
    if($playerId){
        $q=$pdo->prepare('SELECT score,correct_count,joined_at,id FROM robot_recall_players WHERE id=? AND session_id=?');$q->execute([$playerId,$sessionId]);$p=$q->fetch();
        if($p){
            $r=$pdo->prepare('SELECT COUNT(*)+1 FROM robot_recall_players WHERE session_id=? AND (score>? OR (score=? AND correct_count>?) OR (score=? AND correct_count=? AND (joined_at<? OR (joined_at=? AND id<?))))');
            $r->execute([$sessionId,(int)$p['score'],(int)$p['score'],(int)$p['correct_count'],(int)$p['score'],(int)$p['correct_count'],$p['joined_at'],$p['joined_at'],(int)$p['id']]);
            $playerRank=(int)$r->fetchColumn();
        }
    }
    return ['rows'=>$rows,'player_rank'=>$playerRank];
}

function robot_recall_state(PDO $pdo,array $session,?array $player=null,bool $screen=false): array {
    $sessionId=(int)$session['id'];
    $status=(string)$session['status'];
    $idx=(int)$session['current_question_index'];
    $question=$idx>0?robot_recall_question($pdo,$sessionId,$idx):null;
    $playersStmt=$pdo->prepare('SELECT COUNT(*) FROM robot_recall_players WHERE session_id=?');$playersStmt->execute([$sessionId]);$playerCount=(int)$playersStmt->fetchColumn();
    $state=[
        'room'=>(string)$session['room_code'],
        'title'=>(string)$session['title'],
        'status'=>$status,
        'question_number'=>$idx,
        'question_count'=>(int)$session['question_count'],
        'question_seconds'=>(int)$session['question_seconds'],
        'player_count'=>$playerCount,
        'server_time_ms'=>(int)round(microtime(true)*1000),
        'deadline_ms'=>robot_recall_deadline_ms($session),
        'event_name'=>(string)($session['event_name']??''),
        'season_year'=>(int)$session['season_year'],
    ];
    if($question && in_array($status,['question','reveal','leaderboard'],true)){
        $state['options']=$question['options'];
        if($screen){
            $state['logo_url']=base_url('api/robot-recall-logo.php?room='.rawurlencode((string)$session['room_code']).'&q='.$idx);
            $a=$pdo->prepare('SELECT COUNT(*) FROM robot_recall_answers WHERE question_id=?');
            $a->execute([(int)$question['id']]);
            $state['answers_received']=(int)$a->fetchColumn();
        }
    }
    if($status==='reveal' && $question){
        $state['correct_team_number']=(int)$question['correct_team_number'];
        $state['correct_nickname']=(string)$question['correct_nickname'];
        $state['correct_label']=robot_recall_option_label((int)$question['correct_team_number'],(string)$question['correct_nickname']);
    }
    if($player){
        $playerId=(int)$player['id'];
        $state['player']=['id'=>$playerId,'nickname'=>(string)$player['nickname'],'score'=>(int)$player['score'],'correct_count'=>(int)$player['correct_count']];
        if($question){
            $a=$pdo->prepare('SELECT selected_team_number,is_correct,response_ms,points_awarded FROM robot_recall_answers WHERE question_id=? AND player_id=? LIMIT 1');
            $a->execute([(int)$question['id'],$playerId]);$ans=$a->fetch();
            if($ans)$state['answer']=['selected_team_number'=>(int)$ans['selected_team_number'],'is_correct'=>(bool)$ans['is_correct'],'response_ms'=>(int)$ans['response_ms'],'points_awarded'=>(int)$ans['points_awarded']];
        }
        $pdo->prepare('UPDATE robot_recall_players SET last_seen_at=UTC_TIMESTAMP() WHERE id=?')->execute([$playerId]);
    }
    if(in_array($status,['reveal','leaderboard','finished'],true)){
        $lb=robot_recall_leaderboard($pdo,$sessionId,$screen?10:5,$player?(int)$player['id']:null);
        $state['leaderboard']=$lb['rows'];
        if($player)$state['player_rank']=$lb['player_rank'];
    }
    return $state;
}

function robot_recall_submit_answer(PDO $pdo,array $session,array $player,int $selectedTeam): array {
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM robot_recall_sessions WHERE id=? FOR UPDATE');$s->execute([(int)$session['id']]);$locked=$s->fetch();
        if(!$locked||$locked['status']!=='question')throw new RuntimeException('Answers are closed for this question.');
        $idx=(int)$locked['current_question_index'];$question=robot_recall_question($pdo,(int)$locked['id'],$idx);
        if(!$question)throw new RuntimeException('Question not found.');
        $validTeams=array_map(static fn($o)=>(int)($o['team']??0),$question['options']);
        if(!in_array($selectedTeam,$validTeams,true))throw new RuntimeException('That answer is not one of the choices.');
        $existing=$pdo->prepare('SELECT selected_team_number,is_correct,response_ms,points_awarded FROM robot_recall_answers WHERE question_id=? AND player_id=? LIMIT 1');
        $existing->execute([(int)$question['id'],(int)$player['id']]);
        if($row=$existing->fetch()){
            $pdo->commit();
            return ['already_answered'=>true,'selected_team_number'=>(int)$row['selected_team_number'],'is_correct'=>(bool)$row['is_correct'],'response_ms'=>(int)$row['response_ms'],'points_awarded'=>(int)$row['points_awarded']];
        }
        $started=robot_recall_parse_utc_micro($locked['question_started_at']??null);
        if($started===null)throw new RuntimeException('The question timer has not started.');
        $duration=max(5,(int)$locked['question_seconds']);
        $elapsed=max(0.0,microtime(true)-$started);
        if($elapsed>$duration+0.35)throw new RuntimeException('Time is up for this question.');
        $responseMs=(int)round(min($duration,$elapsed)*1000);
        $correct=$selectedTeam===(int)$question['correct_team_number'];
        $points=0;
        if($correct){
            $ratio=max(0.0,min(1.0,1.0-($elapsed/$duration)));
            $points=max(250,min(1000,(int)round(250+750*$ratio)));
        }
        $i=$pdo->prepare('INSERT INTO robot_recall_answers(session_id,question_id,player_id,selected_team_number,is_correct,response_ms,points_awarded,answered_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $i->execute([(int)$locked['id'],(int)$question['id'],(int)$player['id'],$selectedTeam,$correct?1:0,$responseMs,$points]);
        if($points>0)$pdo->prepare('UPDATE robot_recall_players SET score=score+?,correct_count=correct_count+1,last_seen_at=UTC_TIMESTAMP() WHERE id=?')->execute([$points,(int)$player['id']]);
        else $pdo->prepare('UPDATE robot_recall_players SET last_seen_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$player['id']]);
        $pdo->commit();
        return ['already_answered'=>false,'selected_team_number'=>$selectedTeam,'is_correct'=>$correct,'response_ms'=>$responseMs,'points_awarded'=>$points];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function robot_recall_host_transition(PDO $pdo,array $session,string $action): array {
    $sessionId=(int)$session['id'];
    $status=(string)$session['status'];
    $idx=(int)$session['current_question_index'];
    $total=(int)$session['question_count'];
    switch($action){
        case 'start':
            if($status!=='lobby')throw new RuntimeException('The game has already started.');
            $pdo->prepare("UPDATE robot_recall_sessions SET status='question',current_question_index=1,question_started_at=UTC_TIMESTAMP(6),finished_at=NULL WHERE id=?")->execute([$sessionId]);
            break;
        case 'reveal':
            if($status!=='question')throw new RuntimeException('There is no active question to reveal.');
            $pdo->prepare("UPDATE robot_recall_sessions SET status='reveal' WHERE id=?")->execute([$sessionId]);
            break;
        case 'leaderboard':
            if(!in_array($status,['reveal','question'],true))throw new RuntimeException('Reveal the question before showing the leaderboard.');
            $pdo->prepare("UPDATE robot_recall_sessions SET status='leaderboard' WHERE id=?")->execute([$sessionId]);
            break;
        case 'next':
            if(!in_array($status,['reveal','leaderboard'],true))throw new RuntimeException('Finish the current question before moving on.');
            if($idx>=$total){
                $pdo->prepare("UPDATE robot_recall_sessions SET status='finished',finished_at=UTC_TIMESTAMP(),question_started_at=NULL WHERE id=?")->execute([$sessionId]);
            }else{
                $pdo->prepare("UPDATE robot_recall_sessions SET status='question',current_question_index=current_question_index+1,question_started_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$sessionId]);
            }
            break;
        case 'finish':
            if($status==='finished')break;
            $pdo->prepare("UPDATE robot_recall_sessions SET status='finished',finished_at=UTC_TIMESTAMP(),question_started_at=NULL WHERE id=?")->execute([$sessionId]);
            break;
        default: throw new RuntimeException('Unknown host action.');
    }
    $s=$pdo->prepare('SELECT s.*,e.name event_name,g.name game_name FROM robot_recall_sessions s LEFT JOIN events e ON e.id=s.event_id LEFT JOIN games g ON g.id=e.game_id WHERE s.id=?');$s->execute([$sessionId]);
    return $s->fetch()?:$session;
}

function robot_recall_host_state(PDO $pdo,array $session): array {
    $screen=robot_recall_state($pdo,$session,null,true);
    $answers=0;
    if((int)$session['current_question_index']>0){
        $q=robot_recall_question($pdo,(int)$session['id'],(int)$session['current_question_index']);
        if($q){$s=$pdo->prepare('SELECT COUNT(*) FROM robot_recall_answers WHERE question_id=?');$s->execute([(int)$q['id']]);$answers=(int)$s->fetchColumn();}
    }
    $screen['answers_received']=$answers;
    $screen['join_url']=robot_recall_join_url((string)$session['room_code']);
    $lb=robot_recall_leaderboard($pdo,(int)$session['id'],10,null);$screen['leaderboard']=$lb['rows'];
    return $screen;
}

function robot_recall_join_url(string $room): string {
    global $config;
    $trustProxy=!empty($config['app']['trust_proxy']);
    $proto='http';
    if((!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off'))$proto='https';
    if($trustProxy&&!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))$proto=strtolower(trim(explode(',',(string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    $host=(string)($_SERVER['HTTP_HOST']??'neptune.mckinneysteamacademy.org');
    return $proto.'://'.$host.base_url('play/?room='.rawurlencode($room));
}

function robot_recall_safe_logo_abs(string $relative): ?string {
    $relative=ltrim(str_replace('\\','/',$relative),'/');
    if($relative===''||str_contains($relative,'..')||!str_starts_with($relative,'uploads/team-logos/'))return null;
    $root=realpath(robot_recall_public_root());$path=realpath(robot_recall_public_root().'/'.$relative);
    if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path))return null;
    return $path;
}
