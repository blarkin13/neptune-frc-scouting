<?php
declare(strict_types=1);

/** Community data is global. Never query private scouting from this service. */
final class CommunityAnalysisError extends RuntimeException {
    public function __construct(public readonly int $httpStatus, string $message) { parent::__construct($message); }
}
final class CommunityAnalysis {
    public function __construct(private PDO $db, private string $secret, private int $leaseSeconds = 300) {
        if (strlen($secret) < 32) throw new RuntimeException('Community analysis requires a dedicated secret of at least 32 characters.');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    private function run(string $sql, array $args = []): PDOStatement {
        $s = $this->db->prepare($sql); $s->execute($args); return $s;
    }
    private function transaction(callable $fn): mixed {
        for ($try=0; ; $try++) {
            $this->db->beginTransaction();
            try { $out = $fn(); $this->db->commit(); return $out; }
            catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                if ($e instanceof PDOException && (string)$e->getCode()==='40001' && $try<3) { usleep(random_int(10000,50000)); continue; }
                throw $e;
            }
        }
    }
    private function token(array $a): string {
        return hash_hmac('sha256', $a['id'].'|'.$a['user_id'].'|'.$a['device_id'].'|'.$a['request_key'], $this->secret);
    }
    private function lock(): string { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    public function catalog(int $after = 0, bool $public = false): array {
        if ($public) return $this->run('SELECT m.id,m.match_key,m.title,m.roster_json,p.published_at FROM community_publications p JOIN community_matches m ON m.id=p.match_id WHERE m.id>? ORDER BY m.id LIMIT 50', [$after])->fetchAll(PDO::FETCH_ASSOC);
        return $this->run("SELECT m.id,m.match_key,m.title,m.roster_json,m.duration_ms,
            CASE WHEN l.expires_at>? THEN 'in_progress' ELSE 'available' END work_status
            FROM community_matches m LEFT JOIN community_leases l ON l.match_id=m.id
            WHERE m.ready=1 AND m.id>? AND NOT EXISTS (SELECT 1 FROM community_submissions s WHERE s.match_id=m.id AND s.review_state IN ('pending','published'))
            ORDER BY m.id LIMIT 50", [time(),$after])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function published(int $match): array {
        $r = $this->run('SELECT m.match_key,m.title,m.roster_json,s.payload_json,p.published_at FROM community_publications p JOIN community_matches m ON m.id=p.match_id JOIN community_submissions s ON s.id=p.submission_id WHERE p.match_id=?', [$match])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new CommunityAnalysisError(404,'No published analysis.');
        $r['analysis'] = json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR); unset($r['payload_json']); return $r;
    }
    public function claim(int $match, int $user, string $device, string $request): array {
        if ($match<1 || $user<1 || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$request)) throw new CommunityAnalysisError(422,'Invalid claim.');
        try { return $this->transaction(function() use ($match,$user,$device,$request) {
            // Lock the match before checking submission state, including concurrent submit/review.
            $m=$this->run('SELECT * FROM community_matches WHERE id=?'.$this->lock(),[$match])->fetch(PDO::FETCH_ASSOC);
            if (!$m || !(int)$m['ready']) throw new CommunityAnalysisError(404,'Match is not ready.');
            $old=$this->run('SELECT * FROM community_attempts WHERE user_id=? AND request_key=?',[$user,$request])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ((int)$old['match_id']!==$match || $old['device_id']!==$device) throw new CommunityAnalysisError(409,'Claim key already used.');
                $l=$this->run('SELECT expires_at FROM community_leases WHERE attempt_id=? AND expires_at>?',[$old['id'],time()])->fetchColumn();
                if (!$l) throw new CommunityAnalysisError(409,'Previous claim is no longer active. Use a new claim key.');
                return $this->claimResponse($old,$m,(int)$l);
            }
            $this->run('DELETE FROM community_leases WHERE expires_at<=? AND (match_id=? OR user_id=? OR device_id=?)', [time(),$match,$user,$device]);
            if ($this->run("SELECT 1 FROM community_submissions WHERE match_id=? AND review_state IN ('pending','published') LIMIT 1",[$match])->fetchColumn()) throw new CommunityAnalysisError(409,'Match is already submitted.');
            $a=['id'=>bin2hex(random_bytes(16)),'match_id'=>$match,'user_id'=>$user,'device_id'=>$device,'request_key'=>$request];
            $this->run("INSERT INTO community_attempts(id,match_id,user_id,device_id,request_key,status,created_at) VALUES(?,?,?,?,?,'processing',?)",array_merge(array_values($a),[time()]));
            $expiry=time()+$this->leaseSeconds;
            // Unique match, user and device constraints are the final concurrency guard. No global cap.
            $this->run("INSERT INTO community_leases(match_id,attempt_id,user_id,device_id,expires_at,stage) VALUES(?,?,?,?,?,'Preparing')",[$match,$a['id'],$user,$device,$expiry]);
            return $this->claimResponse($a,$m,$expiry);
        }); } catch (PDOException $e) {
            if (in_array((string)$e->getCode(),['23000','23505'],true)) throw new CommunityAnalysisError(409,'This match, account or helper is already working.');
            throw $e;
        }
    }
    private function claimResponse(array $a,array $m,int $expiry): array {
        return ['attempt_id'=>$a['id'],'lease_token'=>$this->token($a),'expires_at'=>$expiry,'server_time'=>time(),
            'match'=>['id'=>(int)$m['id'],'key'=>$m['match_key'],'title'=>$m['title'],'roster'=>json_decode($m['roster_json'],true),
                'source_url'=>$m['source_url'],'source_sha256'=>$m['source_sha256'],'duration_ms'=>(int)$m['duration_ms'],'rules'=>$m['rules_text']],
            'pipeline'=>['schema_version'=>1,'paid_api_allowed'=>false]];
    }
    private function attempt(string $id,int $user,string $device,string $token): array {
        $a=$this->run('SELECT * FROM community_attempts WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int)$a['user_id']!==$user || $a['device_id']!==$device || !hash_equals($this->token($a),$token)) throw new CommunityAnalysisError(403,'Invalid attempt credentials.');
        return $a;
    }
    public function recordMedia(string $id,int $user,string $device,string $token,string $hash,int $duration):array {
        $a=$this->attempt($id,$user,$device,$token);
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||$duration<1000||$duration>600000)throw new CommunityAnalysisError(422,'Invalid video fingerprint or duration. Individual match videos must be at most ten minutes.');
        return $this->transaction(function()use($id,$a,$hash,$duration){
            $m=$this->run('SELECT * FROM community_matches WHERE id=?'.$this->lock(),[$a['match_id']])->fetch(PDO::FETCH_ASSOC);
            if($m['source_sha256']!==''||!preg_match('~^https://www\.youtube\.com/watch\?v=[A-Za-z0-9_-]{11}$~D',$m['source_url']))throw new CommunityAnalysisError(422,'This source already has a fixed fingerprint.');
            if(!$this->run('SELECT 1 FROM community_leases WHERE attempt_id=? AND expires_at>?'.$this->lock(),[$id,time()])->fetchColumn())throw new CommunityAnalysisError(409,'Reservation expired.');
            $old=$this->run('SELECT * FROM community_attempt_media WHERE attempt_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['source_sha256'],$hash)||(int)$old['duration_ms']!==$duration)throw new CommunityAnalysisError(409,'A different video was already recorded for this attempt. Release the match and start again.');}
            else $this->run('INSERT INTO community_attempt_media(attempt_id,source_sha256,duration_ms) VALUES(?,?,?)',[$id,$hash,$duration]);
            return ['source_sha256'=>$hash,'duration_ms'=>$duration];
        });
    }
    public function heartbeat(string $id,int $user,string $device,string $token,string $stage): array {
        $this->attempt($id,$user,$device,$token);
        $expiry=time()+$this->leaseSeconds;
        $s=$this->run('UPDATE community_leases SET expires_at=?,stage=? WHERE attempt_id=? AND expires_at>?',[$expiry,mb_substr($stage,0,100),$id,time()]);
        // PDO may report unchanged rows when two heartbeats share a second; verify the current lease.
        if (!$s->rowCount() && !$this->run('SELECT 1 FROM community_leases WHERE attempt_id=? AND expires_at>?',[$id,time()])->fetchColumn()) throw new CommunityAnalysisError(409,'Lease expired or reassigned.');
        return ['expires_at'=>$expiry];
    }
    public function release(string $id,int $user,string $device,string $token): void {
        $this->attempt($id,$user,$device,$token);
        $this->transaction(function() use ($id) {
            $this->run('DELETE FROM community_leases WHERE attempt_id=?',[$id]);
            $this->run("UPDATE community_attempts SET status='released' WHERE id=? AND status='processing'",[$id]);
        });
    }
    public static function validatePayload(array $p,array $m): void {
        if (($p['schema_version']??null)!==1 || ($p['source_sha256']??'')!==$m['source_sha256']) throw new CommunityAnalysisError(422,'Schema or source hash mismatch.');
        foreach (['summary','model','runtime','prompt_version'] as $key) {
            if (!is_string($p[$key]??null) || trim($p[$key])==='' || mb_strlen($p[$key])>($key==='summary'?20000:200)) throw new CommunityAnalysisError(422,'Missing or oversized '.$key.'.');
        }
        if (($p['inference_location']??'')!=='local') throw new CommunityAnalysisError(422,'Only local inference is accepted.');
        $roster=json_decode($m['roster_json'],true,512,JSON_THROW_ON_ERROR);
        $teams=array_merge($roster['red'],$roster['blue']);
        $obs=$p['observations']??null;
        if (!is_array($obs) || !array_is_list($obs) || count($obs)<1 || count($obs)>1000) throw new CommunityAnalysisError(422,'Expected 1–1000 observations.');
        foreach ($obs as $o) {
            if (!is_array($o) || !is_int($o['start_ms']??null) || !is_int($o['end_ms']??null) || $o['start_ms']<0 || $o['end_ms']<$o['start_ms'] || $o['end_ms']>(int)$m['duration_ms']) throw new CommunityAnalysisError(422,'Observation time is outside the video.');
            if (($o['team']??null)!==null && (!is_int($o['team']) || !in_array($o['team'],$teams,true))) throw new CommunityAnalysisError(422,'Observation team is not on the roster.');
            if (!in_array($o['certainty']??'', ['observed','uncertain'],true) || !is_string($o['note']??null) || trim($o['note'])==='' || mb_strlen($o['note'])>2000) throw new CommunityAnalysisError(422,'Invalid observation.');
        }
        // Tags and scores deliberately stay disabled until their evidence contracts are implemented.
        if (!empty($p['tags']) || !empty($p['scoring_contribution'])) throw new CommunityAnalysisError(422,'Tags and scoring estimates are not supported by schema v1.');
        $allowed=['schema_version','source_sha256','summary','model','runtime','prompt_version','inference_location','observations'];
        if (array_diff(array_keys($p),$allowed)) throw new CommunityAnalysisError(422,'Unexpected submission fields.');
        foreach ($obs as $o) if (array_diff(array_keys($o),['start_ms','end_ms','team','certainty','note'])) throw new CommunityAnalysisError(422,'Unexpected observation fields.');
    }
    public function submit(string $id,int $user,string $device,string $token,array $payload): array {
        $a=$this->attempt($id,$user,$device,$token);
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (strlen($json)>1048576) throw new CommunityAnalysisError(413,'Submission exceeds 1 MiB.');
        $hash=hash('sha256',$json);
        return $this->transaction(function() use ($id,$user,$a,$payload,$json,$hash) {
            $m=$this->run('SELECT * FROM community_matches WHERE id=?'.$this->lock(),[$a['match_id']])->fetch(PDO::FETCH_ASSOC);
            $old=$this->run('SELECT id,payload_hash,review_state FROM community_submissions WHERE attempt_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if (!hash_equals($old['payload_hash'],$hash)) throw new CommunityAnalysisError(409,'Attempt already has a different submission.');
                return ['submission_id'=>$old['id'],'review_state'=>$old['review_state']];
            }
            if($m['source_sha256']===''){
                $media=$this->run('SELECT source_sha256,duration_ms FROM community_attempt_media WHERE attempt_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
                if(!$media)throw new CommunityAnalysisError(422,'Video fingerprint must be recorded before submission.');
                $m=array_merge($m,$media);
            }
            self::validatePayload($payload,$m);
            if (!$this->run('SELECT 1 FROM community_leases WHERE attempt_id=? AND expires_at>?'.$this->lock(),[$id,time()])->fetchColumn()) throw new CommunityAnalysisError(409,'Lease expired or reassigned.');
            $sid=bin2hex(random_bytes(16));
            $this->run("INSERT INTO community_submissions(id,attempt_id,match_id,user_id,payload_json,payload_hash,review_state,created_at) VALUES(?,?,?,?,?,?,'pending',?)",[$sid,$id,$a['match_id'],$user,$json,$hash,time()]);
            $this->run('DELETE FROM community_leases WHERE attempt_id=?',[$id]);
            $this->run("UPDATE community_attempts SET status='submitted' WHERE id=?",[$id]);
            return ['submission_id'=>$sid,'review_state'=>'pending'];
        });
    }
    public function reviews(): array {
        return $this->run("SELECT s.id,s.user_id,s.match_id,s.payload_json,s.created_at,m.title,m.source_url FROM community_submissions s JOIN community_matches m ON m.id=s.match_id WHERE s.review_state='pending' ORDER BY s.created_at LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function review(string $id,int $reviewer,string $decision,string $note): void {
        if (!in_array($decision,['published','rejected'],true) || mb_strlen($note)>2000 || ($decision==='rejected' && trim($note)==='')) throw new CommunityAnalysisError(422,'Invalid review decision or reason.');
        $this->transaction(function() use ($id,$reviewer,$decision,$note) {
            $r=$this->run('SELECT * FROM community_submissions WHERE id=?'.$this->lock(),[$id])->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new CommunityAnalysisError(404,'Submission not found.');
            if ((int)$r['user_id']===$reviewer) throw new CommunityAnalysisError(403,'A different reviewer must approve this analysis.');
            if ($r['review_state']!=='pending') throw new CommunityAnalysisError(409,'Submission already reviewed.');
            $this->run('UPDATE community_submissions SET review_state=?,reviewer_id=?,review_note=?,reviewed_at=? WHERE id=?',[$decision,$reviewer,$note,time(),$id]);
            if ($decision==='published') $this->run('INSERT INTO community_publications(match_id,submission_id,published_at) VALUES(?,?,?)',[$r['match_id'],$id,time()]);
        });
    }
}
