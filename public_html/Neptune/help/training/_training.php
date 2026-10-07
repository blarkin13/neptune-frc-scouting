<?php
declare(strict_types=1);

function neptune_training_courses(): array {
    static $courses = null;
    if ($courses === null) $courses = require __DIR__ . '/_courses.php';
    return $courses;
}

function neptune_training_course(string $code): ?array {
    $courses = neptune_training_courses();
    return $courses[$code] ?? null;
}

function neptune_training_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $sql = "CREATE TABLE IF NOT EXISTS training_attempts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      organization_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL,
      display_name_snapshot VARCHAR(150) NOT NULL,
      username_snapshot VARCHAR(120) NULL,
      role_snapshot VARCHAR(32) NULL,
      course_code VARCHAR(32) NOT NULL,
      course_version VARCHAR(20) NOT NULL,
      attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
      status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
      question_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      correct_count SMALLINT UNSIGNED NULL,
      score_percent DECIMAL(5,2) NULL,
      passing_score DECIMAL(5,2) NOT NULL DEFAULT 85.00,
      passed TINYINT(1) NULL,
      question_ids_json LONGTEXT NOT NULL,
      option_order_json LONGTEXT NOT NULL,
      results_json LONGTEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      duration_seconds INT UNSIGNED NULL,
      client_ip VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      PRIMARY KEY (id),
      KEY idx_training_org_course (organization_id, course_code, status),
      KEY idx_training_user_course (user_id, course_code, status),
      KEY idx_training_completed (organization_id, completed_at),
      KEY idx_training_passed (organization_id, course_code, passed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    $done = true;
}

function neptune_training_role_allowed(array $u, array $course): bool {
    return in_array((string)($u['role'] ?? ''), $course['access_roles'] ?? [], true);
}

function neptune_training_shuffle_ids(array $ids): array {
    $ids = array_values($ids);
    for ($i = count($ids)-1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    }
    return $ids;
}

function neptune_training_question_map(array $course): array {
    $map = [];
    foreach (($course['questions'] ?? []) as $q) $map[(string)$q['id']] = $q;
    return $map;
}

function neptune_training_create_attempt(PDO $pdo, array $u, array $course): int {
    neptune_training_ensure_schema($pdo);
    $org = (int)$u['organization_id'];
    $uid = (int)$u['id'];
    $qmap = neptune_training_question_map($course);
    $ids = neptune_training_shuffle_ids(array_keys($qmap));
    $count = min((int)$course['question_count'], count($ids));
    $ids = array_slice($ids, 0, $count);
    $orders = [];
    foreach ($ids as $qid) $orders[$qid] = neptune_training_shuffle_ids(array_keys($qmap[$qid]['options']));

    $s = $pdo->prepare("SELECT COALESCE(MAX(attempt_number),0)+1 FROM training_attempts WHERE organization_id=? AND user_id=? AND course_code=?");
    $s->execute([$org,$uid,(string)$course['code']]);
    $attemptNo = max(1,(int)$s->fetchColumn());

    $s = $pdo->prepare("INSERT INTO training_attempts
        (organization_id,user_id,display_name_snapshot,username_snapshot,role_snapshot,course_code,course_version,attempt_number,status,question_count,passing_score,question_ids_json,option_order_json,started_at,client_ip,user_agent)
        VALUES(?,?,?,?,?,?,?,?, 'in_progress', ?,?,?,?,UTC_TIMESTAMP(),?,?)");
    $s->execute([
        $org,$uid,(string)($u['display_name']??$u['username']??'User'),(string)($u['username']??''),(string)($u['role']??''),
        (string)$course['code'],(string)$course['version'],$attemptNo,$count,(float)$course['passing_score'],
        json_encode($ids,JSON_UNESCAPED_SLASHES),json_encode($orders,JSON_UNESCAPED_SLASHES),
        substr((string)($_SERVER['REMOTE_ADDR']??''),0,64),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)
    ]);
    return (int)$pdo->lastInsertId();
}

function neptune_training_get_attempt(PDO $pdo, int $org, int $attemptId): ?array {
    $s=$pdo->prepare("SELECT * FROM training_attempts WHERE id=? AND organization_id=? LIMIT 1");
    $s->execute([$attemptId,$org]);
    $row=$s->fetch();
    return $row ?: null;
}

function neptune_training_grade_attempt(PDO $pdo, array $u, int $attemptId, array $answers): array {
    neptune_training_ensure_schema($pdo);
    $org=(int)$u['organization_id'];$uid=(int)$u['id'];
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT * FROM training_attempts WHERE id=? AND organization_id=? AND user_id=? FOR UPDATE");
        $s->execute([$attemptId,$org,$uid]);
        $attempt=$s->fetch();
        if(!$attempt) throw new RuntimeException('Training attempt not found.');
        if((string)$attempt['status']!=='in_progress') throw new RuntimeException('This attempt has already been submitted.');
        $course=neptune_training_course((string)$attempt['course_code']);
        if(!$course) throw new RuntimeException('Training course is no longer available.');
        $qmap=neptune_training_question_map($course);
        $ids=json_decode((string)$attempt['question_ids_json'],true);if(!is_array($ids))$ids=[];
        $orders=json_decode((string)$attempt['option_order_json'],true);if(!is_array($orders))$orders=[];
        $results=[];$correct=0;
        foreach($ids as $qid){
            $qid=(string)$qid;$q=$qmap[$qid]??null;if(!$q)continue;
            $selected=isset($answers[$qid])?(string)$answers[$qid]:'';
            $valid=array_keys($q['options']);
            if(!in_array($selected,$valid,true))$selected='';
            $isCorrect=hash_equals((string)$q['correct'],$selected);
            if($isCorrect)$correct++;
            $results[]=[
                'id'=>$qid,'question'=>$q['question'],'selected'=>$selected,'correct'=>(string)$q['correct'],'is_correct'=>$isCorrect,
                'options'=>$q['options'],'option_order'=>$orders[$qid]??$valid,'explanation'=>$q['explanation']
            ];
        }
        $total=max(1,count($ids));$score=round($correct*100/$total,2);$passing=(float)$attempt['passing_score'];$passed=$score+0.0001 >= $passing;
        $s=$pdo->prepare("UPDATE training_attempts SET status='completed',correct_count=?,score_percent=?,passed=?,results_json=?,completed_at=UTC_TIMESTAMP(),duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,UTC_TIMESTAMP())) WHERE id=? AND organization_id=? AND user_id=?");
        $s->execute([$correct,$score,$passed?1:0,json_encode($results,JSON_UNESCAPED_SLASHES),$attemptId,$org,$uid]);
        $pdo->commit();
        return ['score'=>$score,'correct'=>$correct,'total'=>$total,'passed'=>$passed];
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function neptune_training_duration(?int $seconds): string {
    if(!$seconds)return '—';
    $m=intdiv($seconds,60);$s=$seconds%60;
    return $m>0 ? $m.'m '.$s.'s' : $s.'s';
}

function neptune_training_score_label($score): string {
    return $score===null||$score==='' ? '—' : number_format((float)$score,1).'%';
}
