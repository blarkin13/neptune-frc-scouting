<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();$org=(int)$u['organization_id'];$canDatabaseLab=in_array(($u['role']??''),['strategy','admin','owner'],true);

// My Account lives on this existing route because Maintenance Console deployments
// cannot reliably install a new PHP endpoint. Keep the normal dashboard unchanged.
if (($_GET['view'] ?? '') === 'account') {
    $accountUserId = (int)($u['id'] ?? 0);
    if ($accountUserId <= 0 || $org <= 0) { access_denied(); }
    $accountRole = (string)($u['role'] ?? 'scout');
    $accountError = '';
    // Only the signed-in member can retrieve their own profile photo here.
    // Assignment Center's admin-only endpoint serves the same stored photo to admins.
    if (isset($_GET['profile_photo'])) {
        try {
            $q=$pdo->prepare('SELECT p.mime_type,p.image_data FROM scouting_assignment_user_photos p INNER JOIN users u ON u.id=p.user_id AND u.organization_id=p.organization_id WHERE p.organization_id=? AND p.user_id=? AND u.active=1 LIMIT 1');
            $q->execute([$org,$accountUserId]);
            $profileImage=$q->fetch(PDO::FETCH_ASSOC);
            if(!$profileImage || !in_array((string)$profileImage['mime_type'],['image/jpeg','image/png','image/webp'],true)) {http_response_code(404);exit;}
            header('Content-Type: '.$profileImage['mime_type']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            header('Content-Disposition: inline');
            echo $profileImage['image_data'];exit;
        }catch(Throwable $photoReadError){error_log('[Neptune account profile photo read] '.get_class($photoReadError));http_response_code(404);exit;}
    }
    // A profile photo is managed only by its owner in My Account.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'profile_photo') {
        verify_csrf();
        try {
            $photoAction=(string)($_POST['photo_operation']??'');
            if($photoAction==='upload') {
                require_once dirname(__DIR__).'/admin/_assignment_center.php';
                [$mime,$bytes]=sac_upload_image($_FILES['user_photo']??[],1024*1024);
                $q=$pdo->prepare('INSERT INTO scouting_assignment_user_photos (organization_id,user_id,mime_type,image_data,updated_by,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type),image_data=VALUES(image_data),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
                $q->bindValue(1,$org,PDO::PARAM_INT);$q->bindValue(2,$accountUserId,PDO::PARAM_INT);
                $q->bindValue(3,$mime);$q->bindValue(4,$bytes,PDO::PARAM_LOB);
                $q->bindValue(5,$accountUserId,PDO::PARAM_INT);$q->execute();
            }elseif($photoAction==='remove') {
                $q=$pdo->prepare('DELETE FROM scouting_assignment_user_photos WHERE organization_id=? AND user_id=?');
                $q->execute([$org,$accountUserId]);
            }elseif($photoAction==='google') {
                if(($u['auth_method']??'')!=='google')throw new InvalidArgumentException('Sign in with Google to select your Google profile picture.');
                $picture=(string)($_SESSION['google_profile_picture']??'');
                if($picture==='')throw new InvalidArgumentException('No Google profile picture is available in this session.');
                require_once dirname(__DIR__).'/auth/_scouting_assignment_photo_capture.php';
                // The helper refuses to replace an existing uploaded picture.
                $check=$pdo->prepare('SELECT 1 FROM scouting_assignment_user_photos WHERE organization_id=? AND user_id=? LIMIT 1');
                $check->execute([$org,$accountUserId]);
                if($check->fetchColumn())throw new InvalidArgumentException('Remove your current photo before choosing the Google photo.');
                sac_capture_google_photo($pdo,[['id'=>$accountUserId,'organization_id'=>$org,'active'=>1]],$picture);
                $check->execute([$org,$accountUserId]);
                if(!$check->fetchColumn())throw new InvalidArgumentException('Google photo could not be saved. You can upload a photo instead.');
            }else throw new InvalidArgumentException('Invalid photo action.');
            header('Location: '.base_url('dashboard/index.php').'?view=account&photo_saved=1#account-profile',true,303);exit;
        }catch(Throwable $photoError){
            $accountError=$photoError instanceof InvalidArgumentException?$photoError->getMessage():'Could not update profile photo. Please try again.';
            error_log('[Neptune My Account photo] '.get_class($photoError));
        }
    }
    // Assignment status actions belong to the assignee, not to arbitrary submitted user IDs.
    $assignmentFlash = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'assignment_status') {
        verify_csrf();
        try {
            require_once dirname(__DIR__).'/admin/_assignment_center.php';
            $assignmentId=(int)($_POST['assignment_id']??0);
            $operation=(string)($_POST['transition']??'');
            if($assignmentId<1 || !in_array($operation,['acknowledge','complete'],true))throw new InvalidArgumentException('Invalid assignment action.');
            $response=trim((string)($_POST['response_text']??''));
            if(strlen($response)>4000)throw new InvalidArgumentException('Response is too long.');
            $photo=null;
            if(isset($_FILES['evidence_photo']) && ($_FILES['evidence_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $photo=sac_upload_image($_FILES['evidence_photo'],4*1024*1024);
            }
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM scouting_assignments WHERE id=? AND organization_id=? AND assignee_user_id=? FOR UPDATE');
            $q->execute([$assignmentId,$org,$accountUserId]);
            $assignment=$q->fetch(PDO::FETCH_ASSOC);
            if(!$assignment)throw new InvalidArgumentException('Assignment no longer belongs to your account.');
            [$nextStatus,$auditAction]=sac_status_transition((string)$assignment['status'],$operation);
            if($operation==='complete' && $assignment['kind']==='request'){
                if($assignment['request_kind']==='question' && $response==='' && trim((string)$assignment['response_text'])==='')throw new InvalidArgumentException('Provide an answer before completing this question.');
                if($assignment['request_kind']==='photo'){
                    $q=$pdo->prepare('SELECT COUNT(*) FROM scouting_assignment_evidence WHERE organization_id=? AND assignment_id=?');
                    $q->execute([$org,$assignmentId]);
                    if(!$photo && !(int)$q->fetchColumn())throw new InvalidArgumentException('Attach a photograph before completing this request.');
                }
            }
            $q=$pdo->prepare("UPDATE scouting_assignments SET status=?,updated_by=?, acknowledged_at=IF(?='acknowledged',UTC_TIMESTAMP(),acknowledged_at),acknowledged_by=IF(?='acknowledged',?,acknowledged_by),completed_at=IF(?='completed',UTC_TIMESTAMP(),completed_at),completed_by=IF(?='completed',?,completed_by),response_text=IF(?='completed' AND kind='request' AND ?<>'',?,response_text) WHERE id=? AND organization_id=? AND assignee_user_id=?");
            $q->execute([$nextStatus,$accountUserId,$nextStatus,$nextStatus,$accountUserId,$nextStatus,$nextStatus,$accountUserId,$nextStatus,$response,$response,$assignmentId,$org,$accountUserId]);
            if($photo && $operation==='complete' && $assignment['kind']==='request'){
                $q=$pdo->prepare('INSERT INTO scouting_assignment_evidence (organization_id,assignment_id,mime_type,image_data,uploaded_by,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
                $q->bindValue(1,$org,PDO::PARAM_INT);$q->bindValue(2,$assignmentId,PDO::PARAM_INT);
                $q->bindValue(3,$photo[0]);$q->bindValue(4,$photo[1],PDO::PARAM_LOB);$q->bindValue(5,$accountUserId,PDO::PARAM_INT);$q->execute();
            }
            $q=$pdo->prepare('INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$org,(int)$assignment['event_id'],$assignmentId,$auditAction,$accountUserId,$accountUserId,$assignment['status'],$nextStatus,$accountUserId,'Updated by assigned scouter from My Account']);
            $pdo->commit();
            header('Location: '.base_url('dashboard/index.php').'?view=account&assignment_saved=1#account-assignments',true,303);exit;
        } catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $accountError=$e instanceof InvalidArgumentException?$e->getMessage():'Could not update assignment. Please try again.';
            error_log('[Neptune My Account assignment status] '. $e->getMessage());
        }
    }
    // One CSRF-protected transaction for a batch. Only currently assigned work owned
    // by this signed-in member may be acknowledged; every row retains an audit event.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'bulk_acknowledge') {
        verify_csrf();
        try {
            require_once dirname(__DIR__).'/admin/_assignment_center.php';
            $submitted=$_POST['assignment_ids']??[];
            if(!is_array($submitted) || !$submitted || count($submitted)>300) throw new InvalidArgumentException('Select 1–300 assignments to acknowledge.');
            $ids=[];
            foreach($submitted as $raw){
                if(!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw<1)throw new InvalidArgumentException('Invalid assignment selection.');
                $ids[]=(int)$raw;
            }
            $ids=array_values(array_unique($ids));
            sort($ids,SORT_NUMERIC);
            if(!$ids || count($ids)>300)throw new InvalidArgumentException('Select 1–300 assignments to acknowledge.');
            [$nextStatus,$auditAction]=sac_status_transition('assigned','acknowledge');
            $pdo->beginTransaction();
            $places=implode(',',array_fill(0,count($ids),'?'));
            $q=$pdo->prepare("SELECT id,event_id,status FROM scouting_assignments WHERE organization_id=? AND assignee_user_id=? AND id IN ($places) ORDER BY id FOR UPDATE");
            $q->execute(array_merge([$org,$accountUserId],$ids));
            $locked=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($locked)!==count($ids))throw new InvalidArgumentException('One or more selected assignments no longer belong to your account. Refresh and try again.');
            foreach($locked as $row){
                if($row['status']!=='assigned')throw new InvalidArgumentException('One or more selected assignments changed status. Refresh and try again.');
            }
            $update=$pdo->prepare("UPDATE scouting_assignments SET status=?,updated_by=?,acknowledged_at=UTC_TIMESTAMP(),acknowledged_by=? WHERE id=? AND organization_id=? AND assignee_user_id=? AND status='assigned'");
            $history=$pdo->prepare('INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            foreach($locked as $row){
                $id=(int)$row['id'];
                $update->execute([$nextStatus,$accountUserId,$accountUserId,$id,$org,$accountUserId]);
                if($update->rowCount()!==1)throw new RuntimeException('Assignment changed during bulk acknowledgment.');
                $history->execute([$org,(int)$row['event_id'],$id,$auditAction,$accountUserId,$accountUserId,'assigned',$nextStatus,$accountUserId,'Bulk acknowledged by assigned scouter from My Account']);
            }
            $pdo->commit();
            header('Location: '.base_url('dashboard/index.php').'?view=account&acknowledged='.count($locked).'#account-assignments',true,303);exit;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $accountError=$e instanceof InvalidArgumentException?$e->getMessage():'Could not acknowledge the selected assignments. Please try again.';
            error_log('[Neptune My Account bulk acknowledge] '.get_class($e).' '.$e->getMessage());
        }
    }
    // Profile edits do not depend on the optional preferences table.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === '') {
        verify_csrf();
        $newName = trim((string)($_POST['display_name'] ?? ''));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($newName, 'UTF-8') : strlen($newName);
        if ($nameLength < 2 || $nameLength > 120 || preg_match('/[\x00-\x1F\x7F]/', $newName)) {
            $accountError = 'Enter a display name between 2 and 120 characters, without control characters.';
        } else {
            try {
                $q = $pdo->prepare('UPDATE users SET display_name=? WHERE id=? AND organization_id=? AND active=1');
                $q->execute([$newName, $accountUserId, $org]);
                if ($q->rowCount() > 0 || $newName === (string)($u['display_name'] ?? '')) {
                    $_SESSION['user']['display_name'] = $newName;
                    header('Location: ' . base_url('dashboard/index.php') . '?view=account&saved=1');
                    exit;
                }
                $accountError = 'Your account could not be updated. Please contact an administrator.';
            } catch (Throwable $accountSaveError) {
                $accountError = 'Your display name could not be saved. Please try again.';
                error_log('[Neptune My Account name update] ' . $accountSaveError->getMessage());
            }
        }
    }

    $accountDetails = ['display_name'=>(string)($u['display_name']??$u['username']??''),
        'username'=>(string)($u['username']??''),'email'=>(string)($u['email']??''),
        'role'=>$accountRole,'organization_name'=>(string)($u['organization_name']??'Neptune'),
        'last_login_at'=>null,'google_sub'=>null];
    try {
        $q = $pdo->prepare('SELECT display_name,username,email,role,last_login_at FROM users WHERE id=? AND organization_id=? LIMIT 1');
        $q->execute([$accountUserId,$org]);
        $fromDb = $q->fetch(PDO::FETCH_ASSOC);
        if ($fromDb) $accountDetails = array_merge($accountDetails, $fromDb);
        // Google is an optional auth migration; support installations without it.
        $q = $pdo->prepare('SELECT google_sub FROM users WHERE id=? AND organization_id=? LIMIT 1');
        $q->execute([$accountUserId,$org]);
        $accountDetails['google_sub'] = $q->fetchColumn() ?: null;
    } catch (Throwable $accountDetailsError) {
        // Keep the account usable if optional Google columns are not installed.
        error_log('[Neptune My Account details] ' . $accountDetailsError->getMessage());
    }
    $accountTeams = [];
    try {
        $q = $pdo->prepare('SELECT t.frc_team_number,t.name,ut.is_primary FROM user_teams ut JOIN teams t ON t.id=ut.team_id WHERE ut.user_id=? AND t.organization_id=? ORDER BY ut.is_primary DESC,t.frc_team_number LIMIT 20');
        $q->execute([$accountUserId,$org]);
        $accountTeams = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $accountTeamsError) { error_log('[Neptune My Account teams] ' . $accountTeamsError->getMessage()); }

    $accountCounts = ['match'=>0,'pit'=>0,'pre'=>0];
    $accountActivity = [];
    $activityDefinitions = [
        'match' => ["SELECT ss.frc_team_number,ss.created_at AS activity_at,e.name AS event_name FROM scout_sessions ss JOIN events e ON e.id=ss.event_id AND e.organization_id=ss.organization_id WHERE ss.user_id=? AND ss.organization_id=? AND ss.status IN ('submitted','closed') ORDER BY ss.created_at DESC LIMIT 8",
                    "SELECT COUNT(*) FROM scout_sessions WHERE user_id=? AND organization_id=? AND status IN ('submitted','closed')", 'fa-crosshairs', 'Match scouted', 'scouting.php'],
        'pit' => ["SELECT ps.frc_team_number,COALESCE(ps.completed_at,ps.updated_at) AS activity_at,e.name AS event_name FROM pit_scouting ps JOIN events e ON e.id=ps.event_id AND e.organization_id=ps.organization_id WHERE ps.submitted_by=? AND ps.organization_id=? AND ps.status='complete' ORDER BY activity_at DESC LIMIT 8",
                  "SELECT COUNT(*) FROM pit_scouting WHERE submitted_by=? AND organization_id=? AND status='complete'", 'fa-clipboard-check', 'Pit report completed', 'pit/index.php'],
        'pre' => ["SELECT prs.frc_team_number,COALESCE(prs.completed_at,prs.updated_at) AS activity_at,e.name AS event_name FROM pre_scouting prs JOIN events e ON e.id=prs.event_id AND e.organization_id=prs.organization_id WHERE prs.submitted_by=? AND prs.organization_id=? AND prs.status='complete' ORDER BY activity_at DESC LIMIT 8",
                  "SELECT COUNT(*) FROM pre_scouting WHERE submitted_by=? AND organization_id=? AND status='complete'", 'fa-magnifying-glass', 'Pre-scout completed', 'prescout/index.php'],
    ];
    foreach ($activityDefinitions as $kind=>$definition) {
        try {
            $q = $pdo->prepare($definition[1]); $q->execute([$accountUserId,$org]);
            $accountCounts[$kind] = (int)$q->fetchColumn();
            $q = $pdo->prepare($definition[0]); $q->execute([$accountUserId,$org]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $item['label']=$definition[3]; $item['icon']=$definition[2]; $item['href']=$definition[4];
                $accountActivity[]=$item;
            }
        } catch (Throwable $accountActivityError) { error_log('[Neptune My Account activity '. $kind .'] '.$accountActivityError->getMessage()); }
    }
    usort($accountActivity, static fn($a,$b)=>strcmp((string)($b['activity_at']??''),(string)($a['activity_at']??'')));
    $accountActivity = array_slice($accountActivity,0,9);
    // My Assignments permits assignee-only status updates through the guarded POST handler.
    // Keep My Account available on installations where the optional assignment migration
    // has not yet been applied.
    $myAssignments = [];
    $myAssignmentsError = false;
    try {
        $q = $pdo->prepare("SELECT a.id,a.event_id,a.kind,a.status,a.alliance,a.station,a.scouting_mode,
            a.frc_team_number,a.request_kind,a.instructions,a.response_text,
            a.assigned_at,a.acknowledged_at,a.completed_at,
            e.name AS event_name,m.comp_level,m.set_number,m.match_number
            FROM scouting_assignments a
            INNER JOIN events e ON e.id=a.event_id AND e.organization_id=a.organization_id
            LEFT JOIN matches m ON m.id=a.match_id AND m.event_id=a.event_id AND m.organization_id=a.organization_id
            WHERE a.organization_id=? AND a.assignee_user_id=?
            ORDER BY CASE a.status WHEN 'assigned' THEN 0 WHEN 'acknowledged' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,
                     a.assigned_at DESC,a.id DESC LIMIT 1000");
        $q->execute([$org,$accountUserId]);
        $myAssignments = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $assignmentError) {
        $myAssignmentsError = true;
        error_log('[Neptune My Account assignments] '. $assignmentError->getMessage());
    }
    // Group only genuinely consecutive match numbers; missing matches never imply
    // they were assigned. Each assignment remains separately actionable and audited.
    $assignmentDisplay=[];$matchBuckets=[];$standalone=[];
    foreach($myAssignments as $assignment){
        if((string)$assignment['kind']==='match' && (int)($assignment['match_number']??0)>0 && !empty($assignment['comp_level'])){
            $key=implode('|',[(int)($assignment['event_id']??0),strtoupper((string)$assignment['comp_level']),(int)($assignment['set_number']??0),strtolower((string)($assignment['alliance']??'')),(int)($assignment['station']??0),(string)($assignment['scouting_mode']??'either')]);
            $matchBuckets[$key][]=$assignment;
        }else{$standalone[]=$assignment;}
    }
    foreach($matchBuckets as $bucket){
        usort($bucket,static function($a,$b){return ((int)$a['match_number']<=>(int)$b['match_number']) ?: ((int)$a['id']<=>(int)$b['id']);});
        $runs=[];$run=[];$previous=0;
        foreach($bucket as $assignment){
            $number=(int)$assignment['match_number'];
            if($run && $number!==$previous+1){$runs[]=$run;$run=[];}
            $run[]=$assignment;$previous=$number;
        }
        if($run)$runs[]=$run;
        foreach($runs as $run){
            if(count($run)>=3){$assignmentDisplay[]=['type'=>'range','items'=>$run];}
            else foreach($run as $single)$assignmentDisplay[]=['type'=>'single','items'=>[$single]];
        }
    }
    foreach($standalone as $single)$assignmentDisplay[]=['type'=>'single','items'=>[$single]];
    // Short list labels are always computed from the underlying match record.
    function account_assignment_match_label(array $row):string{
        $level=strtoupper((string)($row['comp_level']??'QM'));
        $prefix=['QM'=>'Q','QF'=>'QF','SF'=>'SF','F'=>'F','EF'=>'EF'][$level]??$level;
        return $prefix.($level==='QM'?'':((int)($row['set_number']??1)).'-').(int)($row['match_number']??0);
    }
    $pendingAssignmentCount=count(array_filter($myAssignments,static fn($a)=>(string)$a['status']==='assigned'));
    $accountPhoto = '';
    $accountHasStoredPhoto=false;
    try {
        $q=$pdo->prepare('SELECT 1 FROM scouting_assignment_user_photos WHERE organization_id=? AND user_id=? LIMIT 1');
        $q->execute([$org,$accountUserId]);
        $accountHasStoredPhoto=(bool)$q->fetchColumn();
    }catch(Throwable $photoAvailabilityError){error_log('[Neptune My Account photo availability] '.get_class($photoAvailabilityError));}
    if($accountHasStoredPhoto) {
        $accountPhoto=base_url('dashboard/index.php').'?view=account&profile_photo=1';
    }elseif (($u['auth_method'] ?? '') === 'google' && !empty($accountDetails['google_sub'])) {
        $candidate = (string)($_SESSION['google_profile_picture'] ?? '');
        $parts = parse_url($candidate);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') === 'https' && ($host === 'googleusercontent.com' || str_ends_with($host, '.googleusercontent.com'))) {
            $accountPhoto = $candidate;
        }
    }
    $accountInitial = function_exists('mb_substr') ? mb_substr((string)$accountDetails['display_name'],0,1,'UTF-8') : substr((string)$accountDetails['display_name'],0,1);
    $pageTitle = 'My Account'; $moduleName = 'NEPTUNE'; $navigationSection='home';
    $bodyClass = trim(($bodyClass??'') . ' neptune-my-account');
    include dirname(__DIR__).'/partials_header.php';
    ?>
<style id="neptune-account-styles">
.account-ocean{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.account-ocean span{position:absolute;bottom:-12vh;left:var(--x);width:var(--size);height:var(--size);border:1px solid rgba(205,233,251,.38);background:radial-gradient(circle at 25% 20%,rgba(255,255,255,.14),transparent 57%);border-radius:50%;opacity:0;animation:acct-rise var(--duration) linear var(--delay) infinite}
@keyframes acct-rise{0%{opacity:0;transform:translate3d(0,0,0)}14%,85%{opacity:var(--alpha)}100%{opacity:0;transform:translate3d(var(--drift),-130vh,0)}}
.neptune-my-account main.wrap{position:relative;z-index:1;max-width:1340px}
.account-hero,.account-panel{background:color-mix(in srgb,var(--panel,var(--surface,#11243a)) 42%,transparent)!important;border:1px solid color-mix(in srgb,var(--accent,#42b9e9) 25%,var(--border,#36475b));border-radius:22px;box-shadow:0 14px 36px rgba(0,0,0,.13);backdrop-filter:none}
.account-hero{position:relative;overflow:hidden;display:flex;align-items:center;gap:28px;padding:clamp(24px,4vw,52px);margin-bottom:22px}
.account-hero::before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 0 0,color-mix(in srgb,var(--accent,#42b9e9) 14%,transparent),transparent 65%)}
.account-hero>*{position:relative}.account-kicker{font-size:.8rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--accent,#42b9e9)}
.account-hero h1{font-size:clamp(2.1rem,4.6vw,4.15rem);line-height:1.04;margin:.28em 0 .2em;color:var(--text,#eef6ff);letter-spacing:-.045em}
.account-hero p{color:var(--muted,#a4b8ca);max-width:64ch;line-height:1.65;margin:0}
.account-avatar{width:clamp(76px,11vw,128px);height:clamp(76px,11vw,128px);border:1px solid color-mix(in srgb,var(--accent,#42b9e9) 42%,transparent);border-radius:25%;display:grid;place-items:center;flex-shrink:0;color:var(--accent,#42b9e9);background:color-mix(in srgb,var(--accent,#42b9e9) 12%,transparent);font-size:clamp(2rem,4vw,3.5rem)}
.account-avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block}.account-avatar b{position:absolute;right:-7px;bottom:-7px;padding:4px 8px;border-radius:99px;background:var(--panel,#183048);font-size:.65rem;color:var(--text,#fff);border:1px solid var(--border,#324c68)}
.account-tabs{display:flex;flex-wrap:wrap;gap:9px;margin:0 0 20px}.account-tabs a{border:1px solid var(--border,#354b67);border-radius:999px;padding:10px 16px;text-decoration:none;color:var(--text,#e9f5ff);background:color-mix(in srgb,var(--panel,#14263e) 60%,transparent)}.account-tabs a:hover,.account-tabs a:focus-visible{border-color:var(--accent,#42b9e9)}
.account-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(275px,.9fr);gap:20px;align-items:start}.account-panel{padding:clamp(20px,2.7vw,30px);min-width:0;margin:0 0 20px}.account-panel h2{margin:0 0 6px;font-size:1.45rem;color:var(--text,#fff)}.account-panel p.account-hint{color:var(--muted,#a4b8ca);margin:0 0 21px}.account-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.account-detail-label{display:block;font-size:.76rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted,#a4b8ca);margin-bottom:4px}.account-detail-value{font-weight:700;overflow-wrap:anywhere}.account-form-field{display:flex;flex-direction:column;gap:7px;margin-bottom:16px;min-width:0}.account-form-field label{font-weight:700;font-size:.9rem}.account-form-field input,.account-form-field select{width:100%;max-width:100%;padding:12px 13px;border-radius:12px;border:1px solid var(--border,#425a6e);background:var(--panel2,var(--panel,#102238));color:var(--text,#fff);font:inherit}.account-form-field input:focus-visible,.account-form-field select:focus-visible{outline:2px solid var(--accent,#42b9e9);outline-offset:2px}
.account-save{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:22px}.account-save button{border:0;border-radius:12px;background:var(--accent,#42b9e9);color:var(--bg,#05121d);font-weight:800;padding:12px 22px;cursor:pointer}.account-save button:disabled{opacity:.5;cursor:not-allowed}.account-stat-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.account-stat{border-radius:14px;border:1px solid var(--border,#344b65);padding:14px;text-align:center;background:color-mix(in srgb,var(--panel,#12304a) 55%,transparent)}.account-stat b{display:block;font-size:clamp(1.5rem,3vw,2rem);color:var(--text,#fff)}.account-stat span{font-size:.78rem;color:var(--muted,#a7bdcd)}.account-activity{list-style:none;padding:0;margin:0}.account-activity li{display:flex;gap:12px;align-items:center;border-top:1px solid var(--border,#34506a);padding:12px 0}.account-activity li:first-child{border-top:0}.account-activity-icon{color:var(--accent,#42b9e9);width:23px;text-align:center}.account-activity a{color:var(--text,#fff);font-weight:700}.account-activity small{display:block;color:var(--muted,#9db2c5);margin-top:3px}.account-note{font-size:.85rem;color:var(--muted,#a4b8ca);line-height:1.55}.account-alert{padding:13px 16px;margin-bottom:18px;border:1px solid var(--accent,#42b9e9);border-radius:11px}.account-muted{color:var(--muted,#a4b8ca)}
@media(max-width:850px){.account-grid{grid-template-columns:1fr}.account-hero{gap:16px}}
@media(max-width:550px){.account-hero{flex-direction:column;align-items:flex-start}.account-details{grid-template-columns:1fr}.account-stat{padding:9px 5px}}
@media(prefers-reduced-motion:reduce){.account-ocean{display:none!important}}

/* Compact, theme-aware identity and security panel */
.account-identity{max-width:none;padding:clamp(20px,3vw,34px)}
.account-identity-head{display:flex;align-items:center;gap:18px;margin-bottom:20px;min-width:0}
.account-identity-avatar{width:82px;height:82px;min-width:82px;font-size:2.25rem;border-radius:50%}
.account-identity-name{min-width:0;flex:1}.account-identity-name h2{font-size:clamp(1.5rem,2.5vw,2rem);margin:4px 0}
.account-identity-name p.account-hint{margin:0 0 8px}
.account-provider{display:inline-flex;gap:8px;align-items:center;color:var(--muted,#a4b8ca);font-weight:700;font-size:.86rem}
.account-edit-link,.account-security-action{color:var(--accent,#42b9e9);text-decoration:none;font-weight:750;white-space:nowrap}
.account-edit-link:hover,.account-security-action:hover{text-decoration:underline}
.account-name-field{max-width:660px;margin:0 0 20px}
.account-details-tight{border-top:1px solid var(--border,var(--line,#39526a));padding-top:18px;gap:14px 26px}
.account-security-line{display:flex;align-items:center;justify-content:space-between;gap:20px;border-top:1px solid var(--border,var(--line,#39526a));padding-top:17px;margin-top:21px;flex-wrap:wrap}
.account-security-line strong{display:block;margin-bottom:5px}.account-security-line strong i{color:var(--accent,#42b9e9)}
.account-security-line span{display:block;font-size:.86rem;color:var(--muted,#a4b8ca)}
.account-save-inline button[hidden]{display:none!important}.account-save-inline{margin-top:14px}.account-save-inline button{font-size:.9rem;padding:9px 17px}
.account-work-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.account-team-block{border-top:1px solid var(--border,var(--line,#39526a));margin-top:20px;padding-top:16px}.account-team-block h3{font-size:1rem;margin:0 0 11px}.account-team-chips{display:flex;flex-wrap:wrap;gap:8px}.account-team-chip{border:1px solid var(--border,var(--line,#39526a));border-radius:999px;padding:6px 10px;font-size:.82rem;color:var(--text,#e8f4fc)}
@media(max-width:850px){.account-work-grid{grid-template-columns:1fr}}@media(max-width:600px){.account-identity-head{flex-wrap:wrap}.account-edit-link{margin-left:auto}.account-security-line{align-items:flex-start}}

.account-assignment-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}.account-assignment-controls form{margin:0}.account-assignment-controls button{padding:8px 12px;border:1px solid var(--border,#364b66);border-radius:10px;cursor:pointer;background:var(--accent,#42b9e9);color:#061522;font-weight:800}.account-assignment-controls details{width:100%}.account-assignment-controls textarea{display:block;max-width:550px;width:100%;margin:8px 0}.account-assignment-controls input[type=file]{max-width:100%;margin:8px 0}.account-assignment-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.account-assignment-count{padding:7px 12px;border-radius:99px;border:1px solid var(--border,#364b66);font-weight:800;font-size:.83rem}.account-assignment-list{display:grid;gap:9px;margin-top:15px}.account-assignment-item{display:flex;align-items:flex-start;gap:12px;padding:12px;border:1px solid var(--border,#364b66);border-radius:13px;min-width:0}.account-assignment-icon{flex:0 0 34px;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:var(--accent,#42b9e9);background:color-mix(in srgb,var(--accent,#42b9e9) 12%,transparent)}.account-assignment-info{flex:1;min-width:0}.account-assignment-info strong{display:block;color:var(--text,#fff)}.account-assignment-info small{display:block;color:var(--muted,#aac3d3);margin-top:3px}.account-assignment-info p{font-size:.87rem;margin:7px 0 0;white-space:normal;overflow-wrap:anywhere}.account-assignment-status{border:1px solid var(--border,#364b66);border-radius:99px;padding:5px 8px;font-weight:800;font-size:.75rem;white-space:nowrap}.account-status-completed{border-color:#349c74}.account-status-acknowledged{border-color:#65bbd8}.account-status-cancelled{opacity:.65}@media(max-width:520px){.account-assignment-item{flex-wrap:wrap}.account-assignment-status{margin-left:46px}}

/* One compact account identity card: photo, profile and security together. */
.account-profile-unified{position:relative;overflow:hidden;padding:clamp(20px,3.5vw,38px);margin-bottom:16px}
.account-profile-unified::before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at top left,color-mix(in srgb,var(--accent,#42b9e9) 10%,transparent),transparent 70%)}
.account-profile-unified>*{position:relative}
.account-profile-top{margin-bottom:21px}
.account-profile-top h1{font-size:clamp(1.9rem,4.4vw,3.2rem);line-height:1.04;margin:7px 0 8px;letter-spacing:-.035em;color:var(--text,#f0f8ff)}
.account-profile-top p{font-size:.92rem;margin:0;color:var(--muted,#a4b8ca)}
.account-profile-person{display:flex;gap:20px;align-items:center;min-width:0}
.account-photo-trigger{position:relative;display:grid;place-items:center;padding:0;border-radius:25%;border:2px solid color-mix(in srgb,var(--accent,#42b9e9) 65%,transparent);width:94px;height:94px;min-width:94px;color:var(--accent,#42b9e9);cursor:pointer;overflow:visible;background:color-mix(in srgb,var(--accent,#42b9e9) 12%,var(--panel,#162a3d));box-shadow:0 6px 18px rgba(0,0,0,.18)}
.account-photo-trigger img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block}
.account-photo-trigger:focus-visible,.account-photo-modal button:focus-visible,.account-inline-edit:focus-visible{outline:3px solid var(--accent,#42b9e9);outline-offset:3px}
.account-photo-camera-mark{position:absolute;right:-7px;bottom:-7px;display:grid;place-items:center;width:33px;height:33px;border-radius:50%;color:#061a2a;background:var(--accent,#42b9e9);border:3px solid var(--panel,#12243a);font-size:.85rem}
.account-person-text{flex:1;min-width:0}
.account-person-text h2{font-size:clamp(1.35rem,3vw,1.85rem);line-height:1.17;margin:0 0 4px;overflow-wrap:anywhere}
.account-person-text p{color:var(--muted,#a4b8ca);margin:0 0 7px;font-size:.93rem}
.account-person-actions{display:flex;align-items:center;gap:13px;flex-wrap:wrap;margin-top:6px}
.account-inline-edit{border:0;background:none;cursor:pointer;color:var(--accent,#42b9e9);padding:5px 0;font:inherit;font-weight:750;font-size:.86rem}
.account-avatar-hint{color:var(--muted,#a4b8ca);font-size:.8rem;margin:10px 0 0}
.account-name-editor[hidden],.account-photo-confirm[hidden],.account-photo-feedback[hidden]{display:none!important}
.account-name-editor{margin-top:17px;padding:15px;background:color-mix(in srgb,var(--panel,#12243a) 85%,transparent);border:1px solid var(--border,#364b66);border-radius:13px;max-width:640px}
.account-name-editor .account-form-field{margin-bottom:10px}
.account-name-buttons{display:flex;gap:8px;flex-wrap:wrap}.account-name-buttons button{padding:9px 15px;border-radius:10px;border:1px solid var(--border,#364b66);cursor:pointer;font-weight:750;background:var(--accent,#42b9e9);color:#071a29}.account-name-buttons .account-cancel{background:transparent;color:var(--text,#fff)}
.account-profile-details{border-top:1px solid var(--border,var(--line,#39526a));margin-top:21px;padding-top:8px}
.account-profile-details summary{list-style:none;display:flex;align-items:center;gap:10px;cursor:pointer;min-height:42px;font-weight:750;color:var(--text,#fff)}.account-profile-details summary::-webkit-details-marker{display:none}
.account-profile-details summary .account-detail-expander{margin-left:auto;color:var(--muted,#a4b8ca);transition:transform .2s}.account-profile-details[open] summary .account-detail-expander{transform:rotate(180deg)}
.account-profile-details .account-details{margin-top:16px}
.account-profile-details .account-security-line{margin-top:18px}
.account-photo-modal{color:var(--text,#fff);background:var(--panel,#102338);border:1px solid var(--border,#425a6e);width:min(420px,calc(100vw - 28px));max-height:calc(100dvh - 32px);overflow-y:auto;border-radius:22px;padding:22px;box-shadow:0 20px 80px rgba(0,0,0,.55)}
.account-photo-modal::backdrop{background:rgba(1,8,19,.7);backdrop-filter:blur(5px)}
.account-photo-modal header{display:flex;align-items:center;justify-content:space-between;gap:12px}.account-photo-modal header h2{margin:0;font-size:1.35rem}
.account-photo-close{border:1px solid var(--border,#425a6e);background:transparent;color:var(--text,#fff);border-radius:12px;width:42px;height:42px;cursor:pointer}
.account-photo-modal p{color:var(--muted,#a4b8ca);font-size:.87rem;line-height:1.5;margin:12px 0 16px}
.account-photo-options{display:grid;gap:9px}
.account-photo-options button{width:100%;text-align:left;display:flex;align-items:center;gap:12px;padding:13px 15px;min-height:48px;border:1px solid var(--border,#425a6e);border-radius:13px;background:color-mix(in srgb,var(--panel,#11243a) 80%,white 4%);color:var(--text,#fff);font:inherit;cursor:pointer}
.account-photo-options button:hover{border-color:var(--accent,#42b9e9)}.account-photo-options button i{width:22px;color:var(--accent,#42b9e9);text-align:center}
.account-photo-options form{margin:0}.account-photo-options .account-photo-danger{color:var(--text,#fff)}.account-photo-options .account-photo-danger i{color:#ff8f9c}
.account-photo-confirm{padding:12px;margin-top:12px;border:1px solid var(--border,#425a6e);border-radius:13px}.account-photo-confirm p{margin:0 0 10px}
.account-photo-confirm-actions{display:flex;gap:8px}.account-photo-confirm-actions button{flex:1;min-height:42px;justify-content:center;font-weight:750}.account-photo-confirm-actions .account-photo-remove-final{background:#832b42;color:#fff}
.account-photo-feedback{border:1px solid var(--border,#425a6e);border-radius:10px;padding:10px 12px;margin-top:13px;font-size:.85rem;line-height:1.4}
.account-photo-upload-forms{position:absolute;inset:auto auto auto -10000px;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.account-photo-fallback{display:block;margin-top:14px}.account-photo-fallback input{width:100%}
@media(max-width:550px){.account-profile-unified{padding:21px}.account-profile-person{gap:15px;align-items:center}.account-photo-trigger{width:76px;height:76px;min-width:76px}.account-photo-camera-mark{width:29px;height:29px}.account-person-text h2{font-size:1.25rem}.account-avatar-hint{margin-top:7px}}
@media(prefers-reduced-motion:reduce){.account-profile-details summary .account-detail-expander{transition:none}}

/* Compact assignment ranges and bulk acknowledgment, responsive without alert dialogs. */
.account-bulk-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:9px;padding:12px;margin-top:14px;border:1px solid var(--border,#354b67);border-radius:13px;background:color-mix(in srgb,var(--accent,#42b9e9) 7%,transparent)}
.account-bulk-toolbar>span{margin-right:auto;color:var(--text,#f7fbff);font-size:.88rem}
.account-bulk-toolbar button{padding:9px 13px;border:1px solid var(--border,#435e79);border-radius:10px;font:inherit;font-weight:750;cursor:pointer;background:var(--accent,#42b9e9);color:#071b2a}
.account-bulk-toolbar .account-bulk-secondary{background:transparent;color:var(--text,#f7fbff)}
.account-bulk-toolbar button:disabled{opacity:.48;cursor:not-allowed}
.account-bulk-status{font-size:.82rem;color:var(--muted,#abc0d1);margin:7px 2px 0}
.account-range-group{border:1px solid var(--border,#364b66);border-radius:14px;overflow:hidden;background:color-mix(in srgb,var(--panel,#152a40) 93%,white 3%)}
.account-range-header{display:flex;align-items:flex-start;gap:12px;padding:15px}
.account-range-heading{flex:1;min-width:0}.account-range-heading strong{display:block;font-size:1rem;color:var(--text,#fff)}.account-range-heading small{display:block;font-size:.82rem;line-height:1.5;color:var(--muted,#a9bfce);margin-top:4px}
.account-range-counts{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.account-range-counts span{font-size:.75rem;border:1px solid var(--border,#364b66);padding:4px 7px;border-radius:99px;color:var(--text,#f7fbff)}
.account-range-select,.account-task-select{display:inline-flex;align-items:center;gap:7px;color:var(--text,#fff);cursor:pointer;font-size:.8rem;white-space:nowrap}.account-range-select input,.account-task-select input{width:19px;height:19px;flex:0 0 19px;accent-color:var(--accent,#42b9e9)}
.account-range-details{border-top:1px solid var(--border,#364b66)}.account-range-details>summary{cursor:pointer;padding:11px 15px;color:var(--accent,#42b9e9);font-weight:750;list-style:none}.account-range-details>summary::-webkit-details-marker{display:none}.account-range-details>summary i{margin-left:6px;transition:transform .2s}.account-range-details[open]>summary i{transform:rotate(180deg)}
.account-range-items{display:grid;gap:7px;padding:0 11px 11px}.account-range-item{background:color-mix(in srgb,var(--panel,#152a40) 78%,transparent)}
.account-assignment-ranges>.account-assignment-item{margin:0}.account-task-select{flex:0 0 19px;padding-top:6px}
@media(max-width:560px){.account-range-header{flex-wrap:wrap}.account-range-heading{min-width:min(100%,240px)}.account-range-select{width:100%;padding-bottom:5px}.account-bulk-toolbar>span{width:100%}.account-bulk-toolbar button{flex:1;min-width:120px}.account-bulk-toolbar button[type=submit]{flex-basis:100%}}

</style>
<div class="account-ocean" id="accountOcean" aria-hidden="true"></div>
<section class="account-panel account-profile-unified" id="account-profile">
  <header class="account-profile-top">
    <div class="account-kicker"><i class="fa-solid fa-compass" aria-hidden="true"></i> NEPTUNE / PERSONAL COMMAND</div>
    <h1>My Account</h1>
    <p>Your profile, security, and scouting assignments in one place.</p>
  </header>
  <div class="account-profile-person">
    <button id="accountAvatarButton" class="account-photo-trigger" type="button" aria-label="Change your profile photo" aria-haspopup="dialog" aria-controls="accountPhotoDialog" title="Change profile photo">
      <?php if($accountPhoto):?><img src="<?=e($accountPhoto)?>" alt="" referrerpolicy="no-referrer"><?php else:?><i class="fa-solid fa-user-astronaut" aria-hidden="true"></i><?php endif;?>
      <span class="account-photo-camera-mark" aria-hidden="true"><i class="fa-solid fa-camera"></i></span>
    </button>
    <div class="account-person-text">
      <h2><?=e($accountDetails['display_name'])?></h2>
      <p><?=e($accountDetails['organization_name'])?> · <?=e(ucfirst($accountDetails['role']))?></p>
      <div class="account-person-actions">
        <?php if(!empty($accountDetails['google_sub'])):?><span class="account-provider"><i class="fa-brands fa-google" aria-hidden="true"></i> Google linked</span><?php endif;?>
        <button type="button" class="account-inline-edit" id="accountEditNameButton" aria-controls="accountNameEditor" aria-expanded="false"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit name</button>
      </div>
    </div>
  </div>
  <p class="account-avatar-hint"><i class="fa-solid fa-camera" aria-hidden="true"></i> Tap your photo to change it or take a new one.</p>
  <form method="post" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile" id="account-settings-form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <div class="account-name-editor" id="accountNameEditor" hidden>
      <div class="account-form-field"><label for="account-display-name">Display name</label><input id="account-display-name" type="text" name="display_name" value="<?=e($accountDetails['display_name'])?>" maxlength="120" required autocomplete="nickname"></div>
      <div class="account-name-buttons"><button type="submit" id="account-save-button"><i class="fa-solid fa-check" aria-hidden="true"></i> Save name</button><button type="button" class="account-cancel" id="accountCancelName">Cancel</button></div>
    </div>
  </form>
  <details class="account-profile-details" id="accountDetails">
    <summary><i class="fa-solid fa-shield-halved" aria-hidden="true" style="color:var(--accent,#42b9e9)"></i> Account details &amp; security <i class="fa-solid fa-chevron-down account-detail-expander" aria-hidden="true"></i></summary>
    <div class="account-details account-details-tight">
      <div><span class="account-detail-label">Username</span><span class="account-detail-value"><?=e($accountDetails['username'])?></span></div>
      <div><span class="account-detail-label">Email</span><span class="account-detail-value"><?=e($accountDetails['email'] ?: 'Not set')?></span></div>
      <div><span class="account-detail-label">Last sign-in</span><span class="account-detail-value"><?=e($accountDetails['last_login_at']??'Not recorded')?></span></div>
      <div><span class="account-detail-label">Organization</span><span class="account-detail-value"><?=e($accountDetails['organization_name'])?></span></div>
    </div>
    <div class="account-security-line"><div><strong><i class="fa-solid fa-key" aria-hidden="true"></i> Account security</strong><span>Manage your Neptune local password</span></div><a href="<?=e(base_url('change-password.php'))?>" class="account-security-action">Manage password <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></div>
  </details>
</section>
<?php if (!empty($_GET['saved'])): ?><div class="account-alert" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Your profile has been saved.</div><?php endif; ?>
<?php if (!empty($_GET['photo_saved'])): ?><div class="account-alert" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Your profile photo has been updated.</div><?php endif; ?>
<?php if ($accountError): ?><div class="account-alert" role="alert"><?=e($accountError)?></div><?php endif; ?>
<nav class="account-tabs" aria-label="My Account sections"><a href="#account-profile">Profile</a><a href="#account-contributions">Contributions</a><a href="#account-assignments">My Assignments</a><a href="#account-activity">Activity</a></nav>
<dialog class="account-photo-modal" id="accountPhotoDialog" aria-labelledby="accountPhotoTitle">
  <header><h2 id="accountPhotoTitle">Your profile photo</h2><button type="button" class="account-photo-close" id="accountPhotoClose" aria-label="Close photo options"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
  <p>Choose a photo or take one now. Your picture will appear in Neptune's scouting rosters.</p>
  <div class="account-photo-options">
    <button type="button" id="accountPhotoChoose"><i class="fa-solid fa-image" aria-hidden="true"></i> Choose from device</button>
    <button type="button" id="accountPhotoCamera"><i class="fa-solid fa-camera" aria-hidden="true"></i> Take a photo</button>
    <?php if(!$accountHasStoredPhoto && ($u['auth_method']??'')==='google' && !empty($accountDetails['google_sub']) && $accountPhoto):?>
    <form method="post" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="profile_photo"><input type="hidden" name="photo_operation" value="google">
      <button type="submit"><i class="fa-brands fa-google" aria-hidden="true"></i> Use my Google photo</button>
    </form>
    <?php endif;?>
    <?php if($accountHasStoredPhoto):?><button type="button" class="account-photo-danger" id="accountPhotoAskRemove"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Remove saved photo</button><?php endif;?>
  </div>
  <?php if($accountHasStoredPhoto):?>
  <div class="account-photo-confirm" id="accountPhotoRemoveConfirm" hidden>
    <p>Remove your saved Neptune photo? Your avatar will return to the available Google photo or default icon.</p>
    <form method="post" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile" class="account-photo-confirm-actions">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="profile_photo"><input type="hidden" name="photo_operation" value="remove">
      <button type="button" id="accountPhotoKeep">Keep photo</button><button type="submit" class="account-photo-remove-final"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Remove</button>
    </form>
  </div>
  <?php endif;?>
  <div class="account-photo-feedback" id="accountPhotoFeedback" role="status" aria-live="polite" hidden></div>
  <div class="account-photo-upload-forms">
    <form id="accountPhotoChooseForm" method="post" enctype="multipart/form-data" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="profile_photo"><input type="hidden" name="photo_operation" value="upload">
      <input id="accountPhotoFile" type="file" name="user_photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif">
    </form>
    <form id="accountPhotoCameraForm" method="post" enctype="multipart/form-data" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="profile_photo"><input type="hidden" name="photo_operation" value="upload">
      <input id="accountPhotoCameraFile" type="file" name="user_photo" accept="image/*" capture="user">
    </form>
  </div>
  <noscript><form method="post" enctype="multipart/form-data" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-profile" class="account-photo-fallback"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="profile_photo"><input type="hidden" name="photo_operation" value="upload"><label for="accountPhotoFallbackFile">Choose a JPG, PNG, or WebP photo under 1 MB</label><input id="accountPhotoFallbackFile" type="file" name="user_photo" accept="image/jpeg,image/png,image/webp" required><button type="submit">Save photo</button></form></noscript>
</dialog>
  <section class="account-panel" id="account-contributions"><h2><i class="fa-solid fa-chart-simple"></i> My contributions</h2><p class="account-hint">Completed scouting work credited to your account.</p>
    <div class="account-stat-row"><div class="account-stat"><b><?=number_format($accountCounts['match'])?></b><span>Matches scouted</span></div><div class="account-stat"><b><?=number_format($accountCounts['pit'])?></b><span>Pit reports</span></div><div class="account-stat"><b><?=number_format($accountCounts['pre'])?></b><span>Pre-scout reports</span></div></div>
    <?php if ($accountTeams): ?><div class="account-team-block"><h3><i class="fa-solid fa-people-group"></i> My teams</h3><div class="account-team-chips"><?php foreach ($accountTeams as $team): ?><span class="account-team-chip">#<?=e($team['frc_team_number'])?><?=!empty($team['name'])?' · '.e($team['name']):''?><?=!empty($team['is_primary'])?' ★':''?></span><?php endforeach; ?></div></div><?php endif; ?>
    <p class="account-note" style="margin:15px 0 0">Older records without a user attribution are not counted.</p>
  </section>
<section class="account-panel" id="account-assignments">
  <div class="account-assignment-head"><div><h2><i class="fa-solid fa-clipboard-list"></i> My Assignments</h2><p class="account-hint">Consecutive match assignments are shown as ranges. Expand any range to see every match and its status.</p></div>
    <span class="account-assignment-count"><?=count($myAssignments)?> assignments</span></div>
  <?php if(isset($_GET['assignment_saved'])): ?><div class="account-alert" role="status">Assignment status saved.</div><?php endif; ?>
  <?php if(isset($_GET['acknowledged'])): ?><div class="account-alert" role="status"><?=(int)$_GET['acknowledged']?> assignment(s) acknowledged.</div><?php endif; ?>
  <?php if($myAssignmentsError): ?><p class="account-note">Assignment records are unavailable in this environment. Ask an administrator to check the assignment database setup.</p>
  <?php elseif(!$myAssignments): ?><p class="account-note">You do not have any scouting assignments yet.</p>
  <?php else: ?>
    <?php if($pendingAssignmentCount): ?>
    <form id="accountBulkAcknowledgeForm" method="post" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-assignments">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="bulk_acknowledge">
      <div class="account-bulk-toolbar" role="group" aria-label="Bulk acknowledgment">
        <span><b><?=$pendingAssignmentCount?></b> awaiting acknowledgment</span>
        <button type="button" id="accountSelectPending" class="account-bulk-secondary">Select all pending</button>
        <button type="button" id="accountClearPending" class="account-bulk-secondary">Clear</button>
        <button type="submit" id="accountBulkSubmit" disabled>Acknowledge selected <span id="accountSelectedCount">(0)</span></button>
      </div>
      <div id="accountBulkSelectionStatus" class="account-bulk-status" role="status" aria-live="polite">Select the assignments you want to acknowledge.</div>
    </form>
    <?php endif; ?>
    <div class="account-assignment-list account-assignment-ranges">
    <?php foreach($assignmentDisplay as $group):
      $isRange=$group['type']==='range';$items=$group['items'];$first=$items[0];$last=end($items);
      $groupStatus=['assigned'=>0,'acknowledged'=>0,'completed'=>0,'cancelled'=>0];
      foreach($items as $entry){$st=(string)$entry['status'];$groupStatus[$st]=($groupStatus[$st]??0)+1;}
    ?>
      <?php if($isRange): ?>
        <div class="account-range-group">
          <div class="account-range-header">
            <?php if($groupStatus['assigned']>0): ?><label class="account-range-select"><input type="checkbox" class="account-range-toggle" aria-label="Select all pending assignments in this match range"><span>Select pending</span></label><?php endif; ?>
            <div class="account-range-heading"><strong><?=e(account_assignment_match_label($first).'–'.account_assignment_match_label($last))?> · <?=e((string)$first['alliance'])?> <?=(int)$first['station']?></strong>
              <small><?=count($items)?> consecutive matches, including every match between · <?=e((string)($first['event_name']??'Event'))?> · <?=e(ucfirst((string)($first['scouting_mode']??'either')))?> scouting</small>
              <span class="account-range-counts"><?php if($groupStatus['assigned']): ?><span><?=$groupStatus['assigned']?> awaiting acknowledgment</span><?php endif; ?><?php if($groupStatus['acknowledged']): ?><span><?=$groupStatus['acknowledged']?> acknowledged</span><?php endif; ?><?php if($groupStatus['completed']): ?><span><?=$groupStatus['completed']?> completed</span><?php endif; ?><?php if($groupStatus['cancelled']): ?><span><?=$groupStatus['cancelled']?> cancelled</span><?php endif; ?></span>
            </div>
          </div>
          <details class="account-range-details"><summary>View all <?=count($items)?> matches <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
          <div class="account-range-items">
      <?php endif; ?>
      <?php foreach($items as $assignment):
         $kind=(string)$assignment['kind'];$status=(string)$assignment['status'];
         $title=$kind==='match'?(account_assignment_match_label($assignment).' · '.(string)$assignment['alliance'].' '.(int)$assignment['station']):($kind==='pit'?'Pit scouting · Team #'.(int)$assignment['frc_team_number']:'Request · '.ucfirst((string)($assignment['request_kind']??'task')));
         $icon=$kind==='match'?'fa-flag-checkered':($kind==='pit'?'fa-robot':'fa-camera');
      ?>
        <article class="account-assignment-item <?= $isRange?'account-range-item':'' ?>">
          <?php if($status==='assigned'): ?><label class="account-task-select"><input type="checkbox" class="account-assignment-select" name="assignment_ids[]" value="<?=(int)$assignment['id']?>" form="accountBulkAcknowledgeForm" aria-label="Select <?=e($title)?> for bulk acknowledgment"></label><?php endif; ?>
          <span class="account-assignment-icon"><i class="fa-solid <?=e($icon)?>" aria-hidden="true"></i></span>
          <div class="account-assignment-info"><strong><?=e($title)?></strong><small><?=e($assignment['event_name']??'Event')?><?php if($kind==='match'):?> · <?=e(ucfirst((string)($assignment['scouting_mode']??'either')))?> scouting<?php endif;?></small>
          <?php if($kind==='request' && trim((string)($assignment['instructions']??''))!==''):?><p><?=nl2br(e((string)$assignment['instructions']))?></p><?php endif;?>
          <?php if($kind==='request' && trim((string)($assignment['response_text']??''))!==''):?><p><b>Response:</b> <?=nl2br(e((string)$assignment['response_text']))?></p><?php endif;?>
            <?php if(in_array($status,['assigned','acknowledged'],true)): ?>
            <div class="account-assignment-controls">
            <?php if($status==='assigned'): ?>
              <form method="post" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-assignments">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="assignment_status">
                <input type="hidden" name="assignment_id" value="<?=(int)$assignment['id']?>"><input type="hidden" name="transition" value="acknowledge">
                <button type="submit"><i class="fa-solid fa-check"></i> Acknowledge</button>
              </form>
            <?php endif; ?>
            <details><summary>Mark complete<?= $kind==='request'?' / provide response':'' ?></summary>
              <form method="post" enctype="multipart/form-data" action="<?=e(base_url('dashboard/index.php'))?>?view=account#account-assignments">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="account_action" value="assignment_status">
                <input type="hidden" name="assignment_id" value="<?=(int)$assignment['id']?>"><input type="hidden" name="transition" value="complete">
                <?php if($kind==='request'): ?>
                  <label><small>Answer / notes<?=(($assignment['request_kind']??'')==='question')?' (required)':''?></small><textarea rows="3" name="response_text" maxlength="4000" placeholder="Enter your response"></textarea></label>
                  <label><small>Attach photo (JPG, PNG, WebP, up to 4 MB)</small><input type="file" accept="image/jpeg,image/png,image/webp" name="evidence_photo"></label>
                <?php endif; ?>
                <button type="submit"><i class="fa-solid fa-check-double"></i> Save as completed</button>
              </form>
            </details>
            </div>
            <?php endif; ?>
          </div><span class="account-assignment-status account-status-<?=e($status)?>"><?=e(ucfirst($status))?></span>
        </article>
      <?php endforeach; ?>
      <?php if($isRange): ?></div></details></div><?php endif; ?>
    <?php endforeach; ?></div>
  <?php endif; ?>
  <p class="account-note" style="margin-top:12px">Bulk acknowledgment affects only your assigned tasks and records a separate history entry for each. You can still complete each task individually; completing it does not automatically submit scouting observations.</p>
</section>
  <section class="account-panel" id="account-activity"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recent activity</h2><p class="account-hint">Your most recent completed scouting reports.</p>
    <?php if($accountActivity): ?><ul class="account-activity"><?php foreach($accountActivity as $item): ?><li><span class="account-activity-icon"><i class="fa-solid <?=e($item['icon'])?>"></i></span><div><a href="<?=e(base_url($item['href']))?>"><?=e($item['label'])?> · Team <?=e($item['frc_team_number'])?></a><small><?=e($item['event_name'])?> · <?=e($item['activity_at'])?></small></div></li><?php endforeach; ?></ul><?php else: ?><p class="account-note">No completed scouting records linked to your account yet.</p><?php endif; ?>
  </section>
<script>
(function(){
 const bulkForm=document.getElementById('accountBulkAcknowledgeForm');
 if(bulkForm){
   const checkboxes=Array.from(document.querySelectorAll('.account-assignment-select'));
   const groups=Array.from(document.querySelectorAll('.account-range-group'));
   const submit=document.getElementById('accountBulkSubmit');
   const count=document.getElementById('accountSelectedCount');
   const label=document.getElementById('accountBulkSelectionStatus');
   const refresh=()=>{
     const selected=checkboxes.filter(x=>x.checked).length;
     if(submit)submit.disabled=selected===0 || selected>300;
     if(count)count.textContent=`(${selected})`;
     if(label)label.textContent=selected>300?'Select up to 300 assignments at a time.':(selected?`${selected} task${selected===1?'':'s'} selected for acknowledgment.`:'Select the assignments you want to acknowledge.');
     for(const group of groups){
       const child=Array.from(group.querySelectorAll('.account-assignment-select'));
       const toggle=group.querySelector('.account-range-toggle');
       if(!toggle)continue;
       const checked=child.filter(x=>x.checked).length;
       toggle.checked=child.length>0&&checked===child.length;
       toggle.indeterminate=checked>0&&checked<child.length;
     }
   };
   checkboxes.forEach(x=>x.addEventListener('change',refresh));
   groups.forEach(group=>{const toggle=group.querySelector('.account-range-toggle');if(!toggle)return;
     toggle.addEventListener('change',()=>{group.querySelectorAll('.account-assignment-select').forEach(input=>{input.checked=toggle.checked;});refresh();});
   });
   document.getElementById('accountSelectPending')?.addEventListener('click',()=>{checkboxes.forEach(x=>{x.checked=true;});refresh();});
   document.getElementById('accountClearPending')?.addEventListener('click',()=>{checkboxes.forEach(x=>{x.checked=false;});refresh();});
   bulkForm.addEventListener('submit',e=>{if(checkboxes.filter(x=>x.checked).length===0 || checkboxes.filter(x=>x.checked).length>300)e.preventDefault();});
   refresh();
 }

 const nameInput=document.getElementById('account-display-name');
 const nameEditor=document.getElementById('accountNameEditor');
 const nameEditButton=document.getElementById('accountEditNameButton');
 const nameCancel=document.getElementById('accountCancelName');
 const saveBtn=document.getElementById('account-save-button');
 if(nameInput&&nameEditor&&nameEditButton){
   const original=nameInput.value;
   const openEditor=()=>{nameEditor.hidden=false;nameEditButton.setAttribute('aria-expanded','true');nameInput.focus();};
   const closeEditor=()=>{nameEditor.hidden=true;nameEditButton.setAttribute('aria-expanded','false');nameInput.value=original;};
   nameEditButton.addEventListener('click',()=>nameEditor.hidden?openEditor():closeEditor());
   nameCancel?.addEventListener('click',closeEditor);
   nameInput.addEventListener('input',()=>{if(saveBtn)saveBtn.disabled=nameInput.value.trim()===original;});
   if(saveBtn)saveBtn.disabled=true;
 }
 const photoDialog=document.getElementById('accountPhotoDialog');
 const photoTrigger=document.getElementById('accountAvatarButton');
 const photoFeedback=document.getElementById('accountPhotoFeedback');
 const photoFile=document.getElementById('accountPhotoFile');
 const cameraFile=document.getElementById('accountPhotoCameraFile');
 const feedback=(message)=>{if(!photoFeedback)return;photoFeedback.hidden=!message;photoFeedback.textContent=message||'';};
 photoTrigger?.addEventListener('click',()=>{feedback('');const confirmation=document.getElementById('accountPhotoRemoveConfirm');if(confirmation)confirmation.hidden=true;photoDialog?.showModal();});
 document.getElementById('accountPhotoClose')?.addEventListener('click',()=>photoDialog?.close());
 photoDialog?.addEventListener('click',e=>{if(e.target===photoDialog)photoDialog.close();});
 document.getElementById('accountPhotoChoose')?.addEventListener('click',()=>photoFile?.click());
 document.getElementById('accountPhotoCamera')?.addEventListener('click',()=>cameraFile?.click());
 document.getElementById('accountPhotoAskRemove')?.addEventListener('click',()=>{const block=document.getElementById('accountPhotoRemoveConfirm');if(block){block.hidden=false;block.scrollIntoView({block:'nearest'});}});
 document.getElementById('accountPhotoKeep')?.addEventListener('click',()=>{const block=document.getElementById('accountPhotoRemoveConfirm');if(block)block.hidden=true;});
 const uploadMax=1024*1024;
 function jpegBlob(canvas,quality){return new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));}
 async function uploadPhoto(input){
   const source=input?.files?.[0];if(!source)return;
   const form=input.form;if(!form)return;
   const save=()=>{feedback('Saving photo…');form.requestSubmit();};
   const safeType=['image/jpeg','image/png','image/webp'].includes(source.type);
   if(safeType && source.size<=uploadMax){save();return;}
   if(source.size>25*1024*1024){feedback('This photo is too large. Please choose an image under 25 MB.');input.value='';return;}
   feedback('Preparing your photo…');
   let url=null;
   try{
     url=URL.createObjectURL(source);
     const image=new Image();
     await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=reject;image.src=url;});
     const width=image.naturalWidth,height=image.naturalHeight;
     if(!width||!height)throw new Error('Image has no dimensions');
     const canvas=document.createElement('canvas');
     const scale=Math.min(1,900/Math.max(width,height));
     canvas.width=Math.max(1,Math.round(width*scale));canvas.height=Math.max(1,Math.round(height*scale));
     const ctx=canvas.getContext('2d');if(!ctx)throw new Error('Cannot prepare image');
     ctx.drawImage(image,0,0,canvas.width,canvas.height);
     let blob=null;
     for(const quality of [.85,.72,.56]){blob=await jpegBlob(canvas,quality);if(blob&&blob.size<=uploadMax)break;}
     if(!blob||blob.size>uploadMax)throw new Error('Unable to resize photo');
     const cleaned=new File([blob],'neptune-profile.jpg',{type:'image/jpeg'});
     const transfer=new DataTransfer();transfer.items.add(cleaned);input.files=transfer.files;
     save();
   }catch(e){feedback('That photo could not be prepared. Try a JPG, PNG, or WebP from your device.');input.value='';}
   finally{if(url)URL.revokeObjectURL(url);}
 }
 photoFile?.addEventListener('change',()=>uploadPhoto(photoFile));
 cameraFile?.addEventListener('change',()=>uploadPhoto(cameraFile));
 const field=document.getElementById('accountOcean');
 if(field && !window.matchMedia('(prefers-reduced-motion: reduce)').matches){
  for(let i=0;i<(window.innerWidth<640?12:27);i++){
   const el=document.createElement('span');
   for(const [k,v] of Object.entries({'--x':(Math.random()*100)+'%','--size':(3+Math.random()*13)+'px','--duration':(11+Math.random()*20)+'s','--delay':(-Math.random()*30)+'s','--drift':((Math.random()-.5)*140)+'px','--alpha':(.15+Math.random()*.25).toString()}))el.style.setProperty(k,v);
   field.appendChild(el);
  }
 }
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php'; exit;
}

// Public Neptune GitHub repository.
$githubRepoUrl = 'https://github.com/blarkin13/neptune-frc-scouting';

$s=$pdo->prepare("SELECT e.*,g.name game_name,
 (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
 (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,
 (SELECT COUNT(*) FROM pre_scouting prs WHERE prs.event_id=e.id AND prs.organization_id=e.organization_id AND prs.status='complete') pre_complete,
 (SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete
 FROM events e JOIN games g ON g.id=e.game_id
 WHERE e.organization_id=? AND e.active=1
 ORDER BY e.is_current DESC,
 CASE e.event_status WHEN 'running' THEN 0 WHEN 'schedule_ready' THEN 1 WHEN 'pit_open' THEN 2 WHEN 'planned' THEN 3 ELSE 4 END,
 CASE WHEN CURDATE() BETWEEN e.start_date AND e.end_date THEN 0 WHEN e.start_date>=CURDATE() THEN 1 ELSE 2 END,
 ABS(DATEDIFF(COALESCE(e.start_date,CURDATE()),CURDATE())),e.id DESC LIMIT 1");
$s->execute([$org]);$event=$s->fetch()?:null;
$nextMatch=null;
if($event&&((int)$event['match_count'])>0){
    $s=$pdo->prepare("SELECT m.* FROM matches m WHERE m.organization_id=? AND m.event_id=? AND m.state IN ('running','paused','ready','scheduled') ORDER BY FIELD(m.state,'running','paused','ready','scheduled'),FIELD(m.comp_level,'qm','ef','qf','sf','f'),m.set_number,m.match_number LIMIT 1");
    $s->execute([$org,$event['id']]);$nextMatch=$s->fetch()?:null;
}
$pitPct=$event&&(int)$event['team_count']?round((int)$event['pit_complete']*100/(int)$event['team_count']):0;
$pageTitle='Home';$moduleName='NEPTUNE';include dirname(__DIR__).'/partials_header.php';
?>
<!-- Dashboard-only ocean atmosphere. Does not affect scouting screens or sign-in. -->
<style>
.nd-ocean {--nd-sea:var(--accent,#20b9dd);position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;isolation:isolate}
.nd-ocean__wash,.nd-ocean__rays,.nd-ocean__particles {position:absolute;inset:-15%;pointer-events:none}
.nd-ocean__wash {background:radial-gradient(ellipse at 50% -12%,color-mix(in srgb,var(--nd-sea) 20%,transparent),transparent 59%),linear-gradient(180deg,transparent 5%,color-mix(in srgb,var(--nd-sea) 6%,transparent) 55%,transparent);opacity:.8;transform:translate3d(0,calc(var(--nd-scroll,0px)*.06),0)}
.nd-ocean__rays {background:repeating-conic-gradient(from 191deg at 50% -10%, transparent 0deg 8deg,color-mix(in srgb,var(--nd-sea) 8%,transparent) 9deg 11deg,transparent 12deg 24deg);opacity:.5;filter:blur(14px);transform:translate3d(0,calc(var(--nd-scroll,0px)*.14),0);animation:nd-current 20s ease-in-out infinite alternate}
.nd-ocean__particles {transform:translate3d(0,calc(var(--nd-scroll,0px)*-.09),0)}
.nd-ocean__bubble {position:absolute;bottom:-15vh;left:var(--x);height:var(--size);width:var(--size);border:1px solid color-mix(in srgb,var(--nd-sea) 65%,white 15%);border-radius:50%;background:radial-gradient(circle at 28% 23%,color-mix(in srgb,var(--nd-sea) 22%,white 10%),transparent 45%);box-shadow:inset -1px -2px 5px color-mix(in srgb,var(--nd-sea) 18%,transparent),0 0 9px color-mix(in srgb,var(--nd-sea) 14%,transparent);opacity:0;animation:nd-rise var(--duration) linear var(--delay) infinite;will-change:transform,opacity}
@keyframes nd-rise {0%{opacity:0;transform:translate3d(0,0,0) scale(.65)}12%{opacity:var(--alpha)}86%{opacity:var(--alpha)}100%{opacity:0;transform:translate3d(var(--drift),-130vh,0) scale(1.1)}}
@keyframes nd-current{from{translate:-1.5% 0}to{translate:1.5% 0}}
/* Dashboard content stays above decorative water, with all controls interactive. */
body:has(.nd-ocean) main,body:has(.nd-ocean) .container {position:relative;z-index:1}
body:has(.nd-ocean) .home-hero,body:has(.nd-ocean) .module-card {box-shadow:0 12px 40px color-mix(in srgb,var(--nd-sea) 9%,transparent)}
@media(max-width:640px){.nd-ocean__rays{opacity:.32}.nd-ocean__bubble:nth-child(n+16){display:none}}
@media(prefers-reduced-motion:reduce){.nd-ocean__rays,.nd-ocean__bubble{animation:none!important}.nd-ocean__bubble{display:none!important}}
</style>
<div class="nd-ocean" aria-hidden="true"><div class="nd-ocean__wash"></div><div class="nd-ocean__rays"></div><div class="nd-ocean__particles" id="ndBubbleField"></div></div>
<script>
(function(){
  const field=document.getElementById('ndBubbleField');
  const heroField=document.querySelector('.nd-hero-bubbles');
  if(!field||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  const count=window.matchMedia('(max-width: 640px)').matches?14:32;
  for(let i=0;i<count;i++){
    const b=document.createElement('span');b.className='nd-ocean__bubble';
    b.style.setProperty('--x',(Math.random()*100).toFixed(2)+'%');
    b.style.setProperty('--size',(3+Math.random()*15).toFixed(1)+'px');
    b.style.setProperty('--duration',(12+Math.random()*20).toFixed(2)+'s');
    b.style.setProperty('--delay',(-Math.random()*32).toFixed(2)+'s');
    b.style.setProperty('--drift',((Math.random()-.5)*170).toFixed(1)+'px');
    b.style.setProperty('--alpha',(.11+Math.random()*.33).toFixed(2));
    field.appendChild(b);
    if(heroField && i < (count>20?18:10)){ const h=b.cloneNode(false); h.style.setProperty('--duration',(9+Math.random()*13).toFixed(2)+'s'); h.style.setProperty('--delay',(-Math.random()*22).toFixed(2)+'s'); heroField.appendChild(h); }
  }
  let ticking=false;
  window.addEventListener('scroll',()=>{
    if(ticking)return;ticking=true;
    requestAnimationFrame(()=>{document.querySelector('.nd-ocean')?.style.setProperty('--nd-scroll',Math.min(window.scrollY,3500)+'px');ticking=false});
  },{passive:true});
})();
</script>

<style id="neptune-home-metal-logo-switch">
.home-hero .home-hero-logo{display:block!important;width:clamp(260px,31vw,440px)!important;height:clamp(260px,31vw,440px)!important;max-width:100%!important;margin:0 auto 20px!important;object-fit:contain!important;object-position:center!important;background:transparent!important;border:0!important;box-shadow:none!important}
@media(max-width:640px){.home-hero .home-hero-logo{width:min(78vw,340px)!important;height:min(78vw,340px)!important}}

/* The signed-in homepage hero uses the current Neptune theme, not OS preference. */



.home-hero .home-hero-detail{max-width:68ch;margin:12px auto 0;line-height:1.65;text-wrap:pretty}
.home-hero h1.neptune-deep-title{font-size:clamp(2.8rem,6.1vw,5.6rem);line-height:1.04;letter-spacing:-.045em;font-weight:850;max-width:none;margin:0 auto 12px;text-wrap:balance}
.home-hero .neptune-deep-title em{font-style:italic;color:var(--accent,#47b8f3);font-weight:750}
.home-hero h2.neptune-hero-subtitle{font-size:clamp(1.35rem,2.65vw,2.1rem);line-height:1.2;margin:0 auto 18px;max-width:36ch;text-wrap:balance}
.home-hero .home-hero-logo{margin-bottom:8px!important}
@media(max-width:640px){.home-hero h1.neptune-deep-title{font-size:clamp(2.55rem,11vw,4rem)}}
/* Dashboard hero: theme-aware translucent surface, with bubbles above its tint. */
body:has(.nd-ocean) .home-hero.card {
  background: color-mix(in srgb, var(--surface, var(--card-bg, #14283d)) 24%, transparent) !important;
  background-image: none !important;
  backdrop-filter: none !important;
  -webkit-backdrop-filter: none !important;
  isolation: isolate;
}
body:has(.nd-ocean) .home-hero::before { opacity: .24 !important; }
body:has(.nd-ocean) .home-hero::after { content:none !important; }
/* Bubble overlay stays inside the hero, visible regardless of page background. */
.home-hero .nd-hero-bubbles { position:absolute !important; inset:0; overflow:hidden; pointer-events:none; z-index:0 !important; }
.home-hero .nd-hero-bubbles .nd-ocean__bubble { bottom:-60px; opacity:0; }
.home-hero > :not(.nd-hero-bubbles) { position:relative; z-index:1; }
@media(prefers-reduced-motion:reduce){.home-hero .nd-hero-bubbles{display:none}}
/* Mission-control dashboard refinements, scoped to this page. */
/* Header breadcrumbs remain visible on dashboard. */
.home-hero{display:grid!important;grid-template-columns:minmax(0,1.45fr) minmax(295px,.85fr)!important;gap:clamp(20px,3vw,48px)!important;align-items:center!important;padding:clamp(26px,3.4vw,54px)!important;position:relative;overflow:hidden}
.home-hero::before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 16% 12%,color-mix(in srgb,var(--accent,#47b8f3) 11%,transparent),transparent 53%);opacity:.8}
.home-hero>*{position:relative;z-index:1;min-width:0}
.nd-hero-kicker{font-size:.76rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--accent,#47b8f3);margin-bottom:12px}
.home-hero h1.neptune-deep-title{font-size:clamp(2.7rem,5vw,4.9rem)!important;line-height:1.05!important;margin:0 0 18px!important;max-width:100%;white-space:normal}
.nd-hero-intro{display:flex;align-items:center;gap:clamp(14px,2vw,32px)}
.home-hero .home-hero-logo{flex:0 0 auto;width:clamp(180px,19vw,285px)!important;height:clamp(180px,19vw,285px)!important;margin:0!important;max-width:42%!important;object-fit:contain!important}
.nd-hero-copy{min-width:0;max-width:48ch}
.home-hero h2.neptune-hero-subtitle{font-size:clamp(1.3rem,2vw,1.85rem)!important;line-height:1.16;margin:0 0 13px!important;max-width:unset}
.home-hero .home-hero-detail{font-size:clamp(.97rem,1.1vw,1.12rem);line-height:1.65;margin:0!important;max-width:55ch}
.home-hero .status-panel{align-self:center;min-width:0}
.nd-quick-access{margin-top:20px;margin-bottom:26px}
.nd-quick-access .home-links-heading{margin:0 0 14px}
.nd-quick-access .quick-grid{margin:0}
@media(max-width:1120px){.home-hero{grid-template-columns:minmax(0,1fr)!important}.home-hero .status-panel{width:100%;max-width:none}}
@media(max-width:700px){.home-hero{padding:22px!important;gap:24px!important}.nd-hero-intro{flex-direction:column;align-items:flex-start}.home-hero .home-hero-logo{width:min(70vw,260px)!important;height:min(70vw,260px)!important;max-width:100%!important;align-self:center}.home-hero h1.neptune-deep-title{font-size:clamp(2.5rem,10vw,4rem)!important}.nd-hero-copy{max-width:none}}
</style>
<section class="card home-hero">
  <div class="nd-hero-bubbles" aria-hidden="true"></div>
  <div class="nd-hero-main">
    <div class="nd-hero-kicker"><i class="fa-solid fa-water" aria-hidden="true"></i> NEPTUNE / MISSION CONTROL</div>
    <h1 class="neptune-deep-title">Explore the <em>deep.</em></h1>
    <div class="nd-hero-intro">
      <img class="home-hero-logo" data-neptune-brand="square" src="data:image/avif;base64,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" alt="Neptune" decoding="async">
      <div class="nd-hero-copy">
        <h2 class="neptune-hero-subtitle">FRC Scouting &amp; Strategy Platform</h2>
        <p class="home-hero-detail">From pit scouting to alliance selection, Neptune connects robot intelligence, live match operations, scouting assignments, offline synchronization, and analytics—so your entire team can make informed decisions from the same data.</p>
      </div>
    </div>
  </div>
  <div class="status-panel">
    <?php if($event):?>
      <div class="muted"><?=$event['is_current']?'Current event':'Current / upcoming event'?></div><h2><?=e($event['name'])?></h2><div class="muted"><?=e($event['game_name'])?> · <?=e(str_replace('_',' ',strtoupper($event['event_status'])))?></div>
      <div class="home-event-metrics"><div><b><?=e($event['team_count'])?></b><span>Teams</span></div><div><b><?=e($event['pre_complete'])?></b><span>Pre-scout</span></div><div><b><?=e($event['pit_complete'])?></b><span>Pit complete</span></div><div><b><?=e($event['match_count'])?></b><span>Matches</span></div></div>
      <?php if((int)$event['team_count']>0):?><div class="progress-track"><span style="width:<?=$pitPct?>%"></span></div><?php endif;?>
      <?php if($nextMatch):?><div style="margin-top:16px" class="muted">Match status</div><div class="match-big"><?=e(neptune_match_label($nextMatch))?></div><span class="pill"><?=e(strtoupper($nextMatch['state']))?></span>
      <?php elseif((int)$event['match_count']===0):?><div class="notice" style="margin-top:14px"><i class="fa-solid fa-clipboard-list"></i> Pit scouting is available now. Match schedule has not been loaded yet.</div>
      <?php else:?><div class="notice" style="margin-top:14px">No upcoming matches remain in the loaded schedule.</div><?php endif;?>
    <?php else:?><h2>No current event</h2><p class="muted">Command can create an event before the schedule exists, add/import its roster, and open pit scouting immediately.</p><?php endif;?>
  </div>
</section>

<section class="nd-quick-access" aria-label="Quick access">
<div class="home-links-heading">
  <div class="section-kicker"><i class="fa-solid fa-table-cells-large"></i> APPLICATIONS</div>
  <h2>Choose where you want to work</h2>
</div>
<div class="quick-grid">
  <a class="quick-link" href="<?=e(base_url('scouting.php'))?>"><span class="quick-icon"><i class="fa-solid fa-crosshairs"></i></span><span><b>Scouting</b><span class="muted">TRIDENT · Choose Match Scouting, Pit Scouting, or Pre-Scouting.</span></span></a>
  <a class="quick-link" href="<?=e(base_url('analytics/index.php'))?>"><span class="quick-icon"><i class="fa-solid fa-chart-line"></i></span><span><b>Analytics &amp; Strategy</b><span class="muted">AUGUR · Open Robot Intelligence or the Match Board.</span></span></a>
  <a class="quick-link" href="<?=e(base_url('admin/index.php'))?>"><span class="quick-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span><b>Command Center</b><span class="muted">SATURN · Browse all operations, builders, organization tools, and MERCURY maintenance. Access remains role-gated.</span></span></a>
</div>

</section>

<section class="platform-modules" aria-labelledby="moduleMapTitle">
  <div class="home-links-heading module-map-heading">
    <div class="section-kicker"><i class="fa-solid fa-diagram-project"></i> NEPTUNE MODULES</div>
    <h2 id="moduleMapTitle">One platform. Six focused systems.</h2>
  </div>
  <div class="module-grid">
    <a class="module-card" href="<?=e(base_url('scouting.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-crosshairs"></i></span><span><b>SCOUTING</b><small>TRIDENT</small><em>Match · Pit · Pre-Scouting</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-tower-broadcast"></i></span><span><b>COMMAND CENTER</b><small>SATURN</small><em>Event Operations · Organization · Role-gated tools</em></span></a>
    <a class="module-card" href="<?=e(base_url('analytics/index.php'))?>"><span class="module-card-access"><i class="fa-solid fa-user-group"></i> All users</span><span class="module-card-icon"><i class="fa-solid fa-chart-line"></i></span><span><b>ANALYTICS &amp; STRATEGY</b><small>AUGUR</small><em>Robot Intelligence · Match Board · Match Strategy</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php#mercury'))?>"><span class="module-card-access"><i class="fa-solid fa-lock"></i> Role-gated</span><span class="module-card-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span><span><b>SYSTEM MAINTENANCE</b><small>MERCURY</small><em>Health · Styling · Files · Updates · Diagnostics</em></span></a>
    <a class="module-card" href="<?=e(base_url('admin/index.php#vulcan'))?>"><span class="module-card-access"><i class="fa-solid fa-user-shield"></i> Admin+</span><span class="module-card-icon"><i class="fa-solid fa-hammer"></i></span><span><b>BUILDERS &amp; CONFIGURATION</b><small>VULCAN</small><em>Game Builder · Pit Form · Pre-Scout Form</em></span></a>
    <?php if($canDatabaseLab):?><a class="module-card" href="<?=e(base_url('dashboard/data.php'))?>"><span class="module-card-access"><i class="fa-solid fa-chart-line"></i> Strategy+</span><span class="module-card-icon"><i class="fa-solid fa-database"></i></span><span><b>DATA LAYER</b><small>SALT</small><em>Organization Data · Database Lab</em></span></a><?php endif;?>
  </div>
</section>

<section class="dashboard-intro-grid" aria-label="About and setup">
  <article class="card platform-about">
    <div class="section-kicker"><i class="fa-solid fa-water"></i> ABOUT THE PLATFORM</div>
    <h2>Built as a connected FRC operating system</h2>
    <p>Neptune is the platform. TRIDENT handles scouting, SATURN runs event operations, AUGUR turns observations into strategy, MERCURY maintains the platform, VULCAN builds the season configuration, and SALT stores the data. The modules share one organization, event, game, and robot intelligence model.</p>
    <div class="platform-feature-row">
      <span><i class="fa-solid fa-cloud-arrow-down"></i> TBA event data</span>
      <span><i class="fa-solid fa-crosshairs"></i> TRIDENT scouting</span>
      <span><i class="fa-solid fa-tower-broadcast"></i> SATURN command</span>
      <span><i class="fa-solid fa-chart-line"></i> AUGUR intelligence</span>
      <span><i class="fa-solid fa-screwdriver-wrench"></i> MERCURY maintenance</span>
      <span><i class="fa-solid fa-hammer"></i> VULCAN configuration</span>
      <span><i class="fa-solid fa-database"></i> SALT data</span>
    </div>
    <button class="btn" type="button" id="openHowToNeptune"><i class="fa-solid fa-circle-play"></i> How to Use Neptune</button>
  </article>

  <article class="card platform-setup">
    <div class="section-kicker"><i class="fa-solid fa-code"></i> RUN NEPTUNE YOURSELF</div>
    <h2>Hosted or local</h2>
    <p class="muted">Neptune is designed to run on a normal PHP/MySQL server or on a local development machine. The application stays under <code>public_html/Neptune</code> while credentials and API keys stay outside the web root in <code>neptune_secure</code>.</p>
    <div class="setup-methods">
      <div>
        <i class="fa-solid fa-server setup-method-icon"></i>
        <div><b>Hosted</b><span>Upload Neptune to your web server, create the MySQL database, import <code>sql/neptune_schema.sql</code>, configure <code>neptune_secure/config.php</code>, and add your TBA API key.</span></div>
      </div>
      <div>
        <i class="fa-solid fa-laptop-code setup-method-icon"></i>
        <div><b>Local</b><span>Use PHP 8+, MySQL/MariaDB, and a local web server. Keep the same public/private folder structure so local testing matches production.</span></div>
      </div>
    </div>
    <div class="toolbar setup-links">
      <button class="btn secondary" type="button" id="openSetupNeptune"><i class="fa-solid fa-book"></i> Setup Instructions</button>
      <?php if($githubRepoUrl!==''):?>
        <a class="btn secondary" href="<?=e($githubRepoUrl)?>" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub Repository</a>
      <?php else:?>
        <span class="btn secondary disabled" title="Set $githubRepoUrl in dashboard/index.php when the repository is ready"><i class="fa-brands fa-github"></i> GitHub Repository — coming soon</span>
      <?php endif;?>
    </div>
  </article>
</section>


<dialog class="dashboard-info-dialog" id="howToNeptuneDialog" aria-labelledby="howToNeptuneTitle">
  <div class="dashboard-dialog-shell">
    <div class="dashboard-dialog-toolbar">
      <span>NEPTUNE GUIDE</span>
      <button class="btn secondary dashboard-dialog-close" type="button" data-close-dialog aria-label="Close guide"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dashboard-dialog-body">
      <div class="dialog-title-icon"><i class="fa-solid fa-compass"></i></div>
      <h2 id="howToNeptuneTitle">How to Use Neptune</h2>
      <p class="muted">A typical competition moves through Neptune in this order.</p>
      <div class="howto-steps">
        <div><span>1</span><div><b>VULCAN · Build the game</b><p>Configure the season's match actions, button layout, scoring values, timing, and pit questions. Save the game definition for reuse at every event that season.</p></div></div>
        <div><span>2</span><div><b>SATURN · Open the event</b><p>Create the event before matches begin. Load the team roster manually or from The Blue Alliance and make it Neptune's Current Event.</p></div></div>
        <div><span>3</span><div><b>TRIDENT · Pre-scout teams</b><p>Before the event, contact teams from the roster. Neptune reuses known answers from earlier events in the same game and adds TBA team information.</p></div></div>
        <div><span>4</span><div><b>TRIDENT · Pit scout</b><p>At the event, scouts confirm robot capabilities, autonomous options, endgame abilities, reliability notes, and photos.</p></div></div>
        <div><span>5</span><div><b>SATURN · Sync the schedule</b><p>When FIRST publishes the schedule, refresh from The Blue Alliance. Neptune fills every match with the official Red and Blue field stations.</p></div></div>
        <div><span>6</span><div><b>SATURN + TRIDENT · Run live scouting</b><p>Command makes the next match ready and starts it. Agents select their assigned field station and record actions in real time while Neptune keeps the match timer synchronized.</p></div></div>
        <div><span>7</span><div><b>AUGUR · Use the intelligence</b><p>Review Robot Cards, the Pit Match Board, individual robot details, past match data, cycle times, success rates, defense, pit information, and shared scouting data.</p></div></div>
      </div>
      <div class="notice"><i class="fa-solid fa-rotate"></i> If an official match is restarted, use <b>Re-scout / Ready Again</b>. Neptune voids the superseded scouting run so only the new run contributes to analytics.</div>
    </div>
  </div>
</dialog>

<dialog class="dashboard-info-dialog" id="setupNeptuneDialog" aria-labelledby="setupNeptuneTitle">
  <div class="dashboard-dialog-shell">
    <div class="dashboard-dialog-toolbar">
      <span>INSTALLATION</span>
      <button class="btn secondary dashboard-dialog-close" type="button" data-close-dialog aria-label="Close setup instructions"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="dashboard-dialog-body">
      <div class="dialog-title-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div>
      <h2 id="setupNeptuneTitle">Set Up Neptune</h2>
      <div class="setup-guide-grid">
        <section>
          <h3><i class="fa-solid fa-server"></i> Hosted server</h3>
          <ol>
            <li>Use a server with PHP 8+, MySQL/MariaDB, HTTPS, and a normal PHP web root.</li>
            <li>Place the public application in <code>public_html/Neptune/</code>.</li>
            <li>Place <code>neptune_secure/</code> beside <code>public_html</code>, never inside it.</li>
            <li>Create the Neptune database and import <code>sql/neptune_schema.sql</code> as the baseline schema.</li>
            <li>Copy <code>neptune_secure/config.example.php</code> to <code>neptune_secure/config.php</code> and enter the database credentials and TBA API key.</li>
            <li>Open <code>/Neptune/register.php</code> (or use <b>Register an organization</b> on the sign-in page) to create the first organization and owner. Neptune does not use <code>admin/install.php</code>.</li>
            <li>Sign in as the first owner and run <b>System Check</b>. The first organization is the platform organization and receives platform-only administration/maintenance tools.</li>
            <li>Future schema-changing Neptune packages self-apply tracked migrations. Review them in <b>Maintenance Console → DB migration history</b>; do not manually replay old migration files.</li>
          </ol>
        </section>
        <section>
          <h3><i class="fa-solid fa-laptop-code"></i> Local development</h3>
          <ol>
            <li>Install PHP 8+, MySQL/MariaDB, and Apache/Nginx, or use PHP's built-in development server.</li>
            <li>Keep <code>public_html/</code> and <code>neptune_secure/</code> as sibling folders just like production.</li>
            <li>Create a local Neptune database and import <code>sql/neptune_schema.sql</code>.</li>
            <li>Create a local <code>neptune_secure/config.php</code> with local database credentials.</li>
            <li>If using PHP's built-in server from the project root, run <code>php -S localhost:8080 -t public_html</code> and open <code>http://localhost:8080/Neptune/</code>.</li>
            <li>Create the first organization through <code>/Neptune/register.php</code>. There is no browser installer under <code>admin/</code>.</li>
          </ol>
        </section>
      </div>
      <div class="notice"><i class="fa-brands fa-github"></i> Source code, installation files, and updates are available from the Neptune GitHub repository.</div>
    </div>
  </div>
</dialog>

<script>
(function(){
  const pairs=[
    [document.getElementById('openHowToNeptune'),document.getElementById('howToNeptuneDialog')],
    [document.getElementById('openSetupNeptune'),document.getElementById('setupNeptuneDialog')]
  ];
  pairs.forEach(([open,dialog])=>{
    if(!open||!dialog)return;
    open.addEventListener('click',()=>{if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','')});
    dialog.querySelectorAll('[data-close-dialog]').forEach(btn=>btn.addEventListener('click',()=>dialog.close()));
    dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close()});
  });
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
