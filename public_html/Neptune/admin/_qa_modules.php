<?php
/** Event 1 modules beyond Traditional/Tag: Assignments, Analytics fallback, Data Quality, plus "exit a test".
 *  Verifiers read the database (and call Neptune's own analytics code); QA only writes to the dedicated QA events. */
declare(strict_types=1);

function nq_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!array_key_exists($key, $cache)) {
        $q = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $q->execute([$table]);
        $cache[$key] = (bool)$q->fetchColumn();
    }
    return $cache[$key];
}
/** All saved debrief/check answers for a run, as key => value. Safe before the table exists. */
function nq_check_answers(PDO $pdo, int $runId): array {
    if (!nq_table_exists($pdo, 'neptune_qa_responses')) { return []; }
    $q = $pdo->prepare('SELECT question_key,answer_value FROM neptune_qa_responses WHERE qa_run_id=?');
    $q->execute([$runId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string)$r['question_key']] = (string)$r['answer_value']; }
    return $out;
}
function nq_ts(?string $utc): int { return $utc ? (int)strtotime($utc . ' UTC') : 0; }
function nq_baseline(array $run): array { $b = json_decode((string)($run['baseline_json'] ?? ''), true); return is_array($b) ? $b : []; }
function nq_max_id(PDO $pdo, string $table, string $where = '1=1', array $params = []): int {
    if (!nq_table_exists($pdo, $table)) { return 0; }
    $q = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM `$table` WHERE $where");
    $q->execute($params);
    return (int)$q->fetchColumn();
}
function nq_uses_traditional_scouting(string $scenario): bool { return in_array($scenario, ['traditional', 'assignment', 'connection_guard'], true); }

/* ---------- target validation (before the run exists) and automatic setup (after) ---------- */

/** The Data Quality page treats a human Tag report as coverage when it has a score, tags or a note. */
const NQ_TAG_MEANINGFUL = "(scoring_contribution IS NOT NULL OR (tags_json IS NOT NULL AND tags_json NOT IN ('','[]','null')) OR (note IS NOT NULL AND TRIM(note)<>''))";
function nq_tag_covered(PDO $pdo, int $org, int $eventId, int $matchId, int $robot): bool {
    if (!nq_table_exists($pdo, 'tag_scouting_match_data')) { return false; }
    $q = $pdo->prepare("SELECT COUNT(*) FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND user_id<>900000001 AND (note IS NULL OR note NOT LIKE 'Synthetic Tag Scouting estimate%') AND " . NQ_TAG_MEANINGFUL);
    $q->execute([$org, $eventId, $matchId, $robot]);
    return (int)$q->fetchColumn() > 0;
}
/** One query pair for the whole QA event set: which robot-matches have Traditional, scored-Tag, or any coverage evidence. */
function nq_evidence_map(PDO $pdo, int $org, array $eventIds): array {
    $map = ['trad' => [], 'tagscore' => [], 'covered' => []];
    $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
    if (!$eventIds) { return $map; }
    $in = implode(',', array_fill(0, count($eventIds), '?'));
    $q = $pdo->prepare("SELECT DISTINCT a.match_id,a.frc_team_number FROM scouting_actions a JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id WHERE a.organization_id=? AND a.event_id IN ($in) AND a.match_run_number=m.run_number AND a.deleted_at IS NULL");
    $q->execute(array_merge([$org], $eventIds));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $map['trad'][(int)$r['match_id']][(int)$r['frc_team_number']] = true; $map['covered'][(int)$r['match_id']][(int)$r['frc_team_number']] = true; }
    if (nq_table_exists($pdo, 'tag_scouting_match_data')) {
        $q = $pdo->prepare("SELECT match_id,frc_team_number,(scoring_contribution IS NOT NULL) scored FROM tag_scouting_match_data WHERE organization_id=? AND event_id IN ($in) AND user_id<>900000001 AND (note IS NULL OR note NOT LIKE 'Synthetic Tag Scouting estimate%') AND " . NQ_TAG_MEANINGFUL);
        $q->execute(array_merge([$org], $eventIds));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map['covered'][(int)$r['match_id']][(int)$r['frc_team_number']] = true;
            if ((int)$r['scored'] === 1) { $map['tagscore'][(int)$r['match_id']][(int)$r['frc_team_number']] = true; }
        }
    }
    return $map;
}
/** Which (match, robot) rows make sense to offer for a module in the assign form. */
function nq_filter_targets(string $scenario, array $targets, array $evidence): array {
    if (nq_uses_traditional_scouting($scenario)) { return array_values(array_filter($targets, static fn($t) => $t['state'] === 'ready')); }
    if ($scenario === 'analytics') {
        return array_values(array_filter($targets, static fn($t) => isset($evidence['trad'][(int)$t['id']][(int)$t['frc_team_number']]) || isset($evidence['tagscore'][(int)$t['id']][(int)$t['frc_team_number']])));
    }
    if ($scenario === 'data_quality') {
        $seen = []; $out = [];
        foreach ($targets as $t) {
            $mid = (int)$t['id'];
            if ($t['state'] !== 'ended' || isset($seen[$mid])) { continue; }
            $covered = count($evidence['covered'][$mid] ?? []);
            if ($covered < 1 || $covered >= 6) { continue; }
            if (!isset($evidence['covered'][$mid][(int)$t['frc_team_number']])) { continue; }
            $seen[$mid] = true; $out[] = $t;
        }
        return $out;
    }
    return $targets;
}


/** Human Match-Tag reports with a scoring share for this robot-match. */
function nq_human_tag_rows(PDO $pdo, int $org, int $eventId, int $matchId, int $robot): array {
    if (!nq_table_exists($pdo, 'tag_scouting_match_data')) { return []; }
    $q = $pdo->prepare("SELECT id,user_id,scoring_contribution,alliance,updated_at FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND user_id<>900000001 AND scoring_contribution IS NOT NULL AND (note IS NULL OR note NOT LIKE 'Synthetic Tag Scouting estimate%')");
    $q->execute([$org, $eventId, $matchId, $robot]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function nq_active_action_count(PDO $pdo, int $org, int $eventId, int $matchId, int $robot, ?int $runNumber = null): int {
    $sql = 'SELECT COUNT(*) FROM scouting_actions WHERE organization_id=? AND event_id=? AND match_id=? AND frc_team_number=? AND deleted_at IS NULL';
    $params = [$org, $eventId, $matchId, $robot];
    if ($runNumber !== null) { $sql .= ' AND match_run_number=?'; $params[] = $runNumber; }
    $q = $pdo->prepare($sql); $q->execute($params);
    return (int)$q->fetchColumn();
}
/** Robots with scouting evidence in a match, using the same notion of "covered" as the Data Quality page. */
function nq_coverage(PDO $pdo, int $org, array $match): array {
    $q = $pdo->prepare('SELECT frc_team_number FROM match_teams WHERE match_id=? ORDER BY alliance,station');
    $q->execute([(int)$match['id']]);
    $teams = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $covered = [];
    foreach ($teams as $t) {
        $trad = nq_active_action_count($pdo, $org, (int)$match['event_id'], (int)$match['id'], $t, (int)$match['run_number']) > 0;
        $tag = nq_tag_covered($pdo, $org, (int)$match['event_id'], (int)$match['id'], $t);
        if ($trad || $tag) { $covered[] = $t; }
    }
    return ['teams' => $teams, 'covered' => $covered, 'missing' => array_values(array_diff($teams, $covered))];
}
/** Throws a helpful InvalidArgumentException when the chosen match cannot support the module yet. */
function nq_validate_module_target(PDO $pdo, int $org, string $scenario, array $match, int $robot): void {
    $m = ['id' => (int)$match['id'], 'event_id' => (int)$match['event_id'], 'run_number' => (int)$match['run_number']];
    if (nq_uses_traditional_scouting($scenario)) {
        if ($match['state'] !== 'ready') { throw new InvalidArgumentException('This module starts from a READY QA match. Reset the match in section 6 (or pick another), then assign it again.'); }
        return;
    }
    if ($scenario === 'analytics') {
        $hasTrad = nq_active_action_count($pdo, $org, $m['event_id'], $m['id'], $robot, $m['run_number']) > 0;
        $hasTag = nq_human_tag_rows($pdo, $org, $m['event_id'], $m['id'], $robot) !== [];
        if (!$hasTrad && !$hasTag) { throw new InvalidArgumentException('No Traditional or Tag scouting exists yet for team #' . $robot . ' in this QA match. Run the Traditional or Tag module on it first, then assign Analytics.'); }
    } elseif ($scenario === 'data_quality') {
        if ($match['state'] !== 'ended') { throw new InvalidArgumentException('Data Quality reviews an ENDED match. Finish a Traditional QA run on this match first.'); }
        $cov = nq_coverage($pdo, $org, $m + ['id' => $m['id']]);
        if (!$cov['covered'] || !$cov['missing']) { throw new InvalidArgumentException('This match has no partial-coverage finding (' . count($cov['covered']) . ' of ' . count($cov['teams']) . ' robots scouted). Pick an ended match where some, but not all, robots were scouted.'); }
    }
}
/** Admin-level setup the QA system performs for the tester. Called inside nq_create's transaction. Returns baseline data. */
function nq_prepare_module(PDO $pdo, int $org, int $actor, int $tester, string $scenario, array $match, int $robot): array {
    $eventId = (int)$match['event_id']; $mid = (int)$match['id'];
    if ($scenario === 'assignment') { return nq_prepare_assignment($pdo, $org, $actor, $tester, $match); }
    if ($scenario === 'analytics') { return nq_prepare_manual_score($pdo, $org, $actor, $eventId, $mid); }
    if ($scenario === 'data_quality') {
        return ['audit_base' => nq_max_id($pdo, 'neptune_quality_review_audit', 'organization_id=? AND event_id=?', [$org, $eventId]),
                'fingerprint' => nq_data_fingerprint($pdo, $org, $eventId, $mid)];
    }
    return [];
}
function nq_data_fingerprint(PDO $pdo, int $org, int $eventId, int $matchId): string {
    $q = $pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(points),0) pts,COALESCE(MAX(id),0) maxid,SUM(deleted_at IS NOT NULL) voided FROM scouting_actions WHERE organization_id=? AND event_id=? AND match_id=?');
    $q->execute([$org, $eventId, $matchId]); $a = $q->fetch(PDO::FETCH_ASSOC);
    $t = ['n' => 0, 'maxid' => 0, 'upd' => ''];
    if (nq_table_exists($pdo, 'tag_scouting_match_data')) {
        $q = $pdo->prepare('SELECT COUNT(*) n,COALESCE(MAX(id),0) maxid,COALESCE(MAX(updated_at),\'\') upd FROM tag_scouting_match_data WHERE organization_id=? AND event_id=? AND match_id=?');
        $q->execute([$org, $eventId, $matchId]); $t = $q->fetch(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode([$a, $t]));
}
/** Mirrors the Assignment Center's slot save (slot_key, audit row, notification). */
function nq_prepare_assignment(PDO $pdo, int $org, int $actor, int $tester, array $match): array {
    require_once __DIR__ . '/_assignment_center.php';
    $mid = (int)$match['id']; $eventId = (int)$match['event_id'];
    $key = sac_match_slot($mid, (string)$match['alliance'], (int)$match['station']);
    $baseHist = nq_max_id($pdo, 'scouting_assignment_history', 'organization_id=?', [$org]);
    $baseNote = nq_max_id($pdo, 'scouting_assignment_notifications', 'organization_id=?', [$org]);
    $s = $pdo->prepare('SELECT * FROM scouting_assignments WHERE organization_id=? AND slot_key=? FOR UPDATE');
    $s->execute([$org, $key]); $existing = $s->fetch(PDO::FETCH_ASSOC);
    $audit = $pdo->prepare('INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    if (!$existing) {
        $pdo->prepare("INSERT INTO scouting_assignments (organization_id,event_id,kind,slot_key,match_id,alliance,station,scouting_mode,frc_team_number,assignee_user_id,status,created_by,updated_by,assigned_at) VALUES (?,?,'match',?,?,?,?,'traditional',NULL,?,'assigned',?,?,UTC_TIMESTAMP())")
            ->execute([$org, $eventId, $key, $mid, $match['alliance'], (int)$match['station'], $tester, $actor, $actor]);
        $id = (int)$pdo->lastInsertId();
        $audit->execute([$org, $eventId, $id, 'created', null, $tester, null, 'assigned', $actor, 'Created automatically by the QA system']);
    } else {
        $id = (int)$existing['id'];
        $pdo->prepare("UPDATE scouting_assignments SET assignee_user_id=?,scouting_mode='traditional',status='assigned',assigned_at=UTC_TIMESTAMP(),acknowledged_at=NULL,acknowledged_by=NULL,completed_at=NULL,completed_by=NULL,cancelled_at=NULL,updated_by=? WHERE id=? AND organization_id=? AND event_id=?")
            ->execute([$tester, $actor, $id, $org, $eventId]);
        $audit->execute([$org, $eventId, $id, 'reassigned', (int)$existing['assignee_user_id'] ?: null, $tester, (string)$existing['status'], 'assigned', $actor, 'Reset automatically by the QA system']);
    }
    $lab = $pdo->prepare('SELECT comp_level,set_number,match_number FROM matches WHERE id=?'); $lab->execute([$mid]); $label = sac_match_label($lab->fetch(PDO::FETCH_ASSOC) ?: []);
    $body = $label . ' · ' . $match['alliance'] . ' ' . strtoupper(substr((string)$match['alliance'], 0, 1)) . (int)$match['station'] . ' · Traditional scouting · QA Test Event (QA test)';
    $pdo->prepare('INSERT INTO scouting_assignment_notifications (organization_id,recipient_user_id,event_id,assignment_id,kind,title,body,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$org, $tester, $eventId, $id, 'match', 'New match assignment', mb_substr($body, 0, 500)]);
    return ['slot_key' => $key, 'assignment_id' => $id, 'hist_base' => $baseHist, 'notif_base' => $baseNote];
}
/** A Tag estimate needs an alliance score; QA matches have none, so the QA system enters a manual one (like Manual Scores does). */
function nq_prepare_manual_score(PDO $pdo, int $org, int $actor, int $eventId, int $matchId): array {
    $q = $pdo->prepare('SELECT red_score,blue_score FROM matches WHERE id=? AND organization_id=?'); $q->execute([$matchId, $org]); $official = $q->fetch(PDO::FETCH_ASSOC);
    if ($official && $official['red_score'] !== null && $official['blue_score'] !== null) { return ['alliance_score' => 'official']; }
    if (!nq_table_exists($pdo, 'tag_manual_alliance_scores')) { return ['alliance_score' => 'unavailable']; }
    $q = $pdo->prepare('SELECT red_score,blue_score,revision FROM tag_manual_alliance_scores WHERE organization_id=? AND match_id=? FOR UPDATE'); $q->execute([$org, $matchId]);
    if ($q->fetch(PDO::FETCH_ASSOC)) { return ['alliance_score' => 'manual_existing']; }
    $pdo->prepare('INSERT INTO tag_manual_alliance_scores (organization_id,event_id,match_id,red_score,blue_score,revision,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$org, $eventId, $matchId, 84, 71, $actor]);
    if (nq_table_exists($pdo, 'tag_manual_alliance_score_history')) {
        $pdo->prepare('INSERT INTO tag_manual_alliance_score_history (organization_id,event_id,match_id,revision,red_score,blue_score,changed_by) VALUES (?,?,?,?,?,?,?)')->execute([$org, $eventId, $matchId, 1, 84, 71, $actor]);
    }
    return ['alliance_score' => 'manual_set_by_qa', 'red' => 84, 'blue' => 71];
}

/* ---------- verifiers ---------- */

function nq_eval_assignment(PDO $pdo, array $run, array $match, array &$evidence): array {
    require_once __DIR__ . '/_assignment_center.php';
    $org = (int)$run['organization_id']; $mid = (int)$run['match_id']; $uid = (int)$run['tester_user_id']; $robot = (int)$run['frc_team_number'];
    $base = nq_baseline($run); $runNumber = (int)$run['baseline_run'];
    $key = sac_match_slot($mid, (string)$run['alliance'], (int)$run['station']);
    $q = $pdo->prepare('SELECT * FROM scouting_assignments WHERE organization_id=? AND slot_key=? LIMIT 1'); $q->execute([$org, $key]); $a = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $right = $a && (int)$a['assignee_user_id'] === $uid && (int)$a['match_id'] === $mid && (int)$a['event_id'] === (int)$run['event_id'] && $a['kind'] === 'match'
        && (string)$a['alliance'] === (string)$run['alliance'] && (int)$a['station'] === (int)$run['station'] && in_array((string)$a['scouting_mode'], ['traditional', 'either'], true);
    $hist = [];
    if ($a) {
        $q = $pdo->prepare('SELECT id,action,actor_user_id,new_user_id,previous_status,new_status,notes,created_at FROM scouting_assignment_history WHERE organization_id=? AND assignment_id=? AND id>? ORDER BY id ASC LIMIT 50');
        $q->execute([$org, (int)$a['id'], (int)($base['hist_base'] ?? 0)]); $hist = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $created = null; $acked = null; $completed = null;
    foreach ($hist as $h) {
        if ($created === null && in_array($h['action'], ['created', 'reassigned'], true) && (int)$h['new_user_id'] === $uid) { $created = $h; }
        if ($h['action'] === 'acknowledged' && (int)$h['actor_user_id'] === $uid) { $acked = $acked ?? $h; }
        if ($h['action'] === 'completed') { $completed = $h; }
    }
    $note = null;
    if ($a) {
        $q = $pdo->prepare('SELECT id,read_at FROM scouting_assignment_notifications WHERE organization_id=? AND recipient_user_id=? AND assignment_id=? AND id>? ORDER BY id ASC LIMIT 1');
        $q->execute([$org, $uid, (int)$a['id'], (int)($base['notif_base'] ?? 0)]); $note = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    // Traditional scouting evidence for the assigned station (same keys the Assignment Center live monitor uses).
    $q = $pdo->prepare('SELECT id,status,last_seen_at,alliance,station FROM scout_sessions WHERE organization_id=? AND event_id=? AND match_id=? AND match_run_number=? AND frc_team_number=? AND user_id=? AND id>? ORDER BY id DESC LIMIT 1');
    $q->execute([$org, (int)$run['event_id'], $mid, $runNumber, $robot, $uid, (int)$run['baseline_session_id']]); $session = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $sessionOk = $session && (string)$session['alliance'] === (string)$run['alliance'] && (int)$session['station'] === (int)$run['station'];
    $actionCount = 0; $lastAction = null; $actionIds = [];
    if ($sessionOk) {
        $q = $pdo->prepare('SELECT id,recorded_at FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND created_by=? AND deleted_at IS NULL ORDER BY id ASC LIMIT 250');
        $q->execute([$org, (int)$session['id'], $runNumber, $uid]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $actionIds[] = (int)$r['id']; $lastAction = $r['recorded_at']; }
        $actionCount = count($actionIds);
    }
    $recentAction = $lastAction && nq_ts((string)$lastAction) >= time() - 120;
    $recentSeen = $session && in_array((string)$session['status'], ['connected', 'scouting'], true) && nq_ts((string)$session['last_seen_at']) >= time() - 120;
    $monitorState = ($recentAction) ? 'scouting' : ($recentSeen ? 'connected' : ($actionCount ? 'finished' : 'idle'));
    $started = !empty($match['started_at']) && nq_ts((string)$match['started_at']) >= nq_ts((string)$run['created_at']);
    $ended = $match['state'] === 'ended' && $started;
    $endedAgo = $ended && !empty($match['ended_at']) ? max(0, time() - nq_ts((string)$match['ended_at'])) : 0;
    $isDone = $a && (string)$a['status'] === 'completed' && (int)$a['completed_by'] === $uid && $completed && (int)$completed['actor_user_id'] === $uid && str_starts_with((string)$completed['notes'], 'Auto-completed');
    $autoFailed = $ended && $sessionOk && $actionCount > 0 && $a && !$isDone && $endedAgo > 20 && (string)$a['status'] !== 'completed';
    $evidence['assignment'] = $a ? ['id' => (int)$a['id'], 'slot' => $key, 'status' => $a['status'], 'assignee' => (int)$a['assignee_user_id'], 'mode' => $a['scouting_mode'],
        'assigned_at' => $a['assigned_at'], 'acknowledged_at' => $a['acknowledged_at'], 'completed_at' => $a['completed_at'], 'completed_by' => $a['completed_by'],
        'history' => array_map(static fn($h) => $h['action'] . ' by user ' . $h['actor_user_id'] . ' (' . $h['previous_status'] . '→' . $h['new_status'] . ')', $hist)] : null;
    $evidence['live_monitor'] = ['session' => $session['id'] ?? null, 'state_now' => $monitorState, 'active_actions' => $actionCount, 'last_action_at' => $lastAction];
    $evidence['action_ids'] = $actionIds;
    $evidence['active_actions'] = $actionCount;
    $steps = [];
    $steps[] = nq_step('assigned', 'Neptune assigns the tester ' . $run['alliance'] . ' station ' . (int)$run['station'] . ' (automatic)', (bool)($right && $created),
        $right ? 'Assignment #' . $a['id'] . ' · ' . $key . ' · audited as ' . ($created['action'] ?? 'unknown') . ' by user ' . ($created['actor_user_id'] ?? '?') : 'Waiting for the assignment record', (bool)$a && !$right && (string)$a['status'] !== 'cancelled' && (int)$a['assignee_user_id'] !== $uid);
    $steps[] = nq_step('notified', 'Tester gets an in-app notification (check the bell or My Account)', (bool)$note, $note ? 'Notification #' . $note['id'] . ($note['read_at'] ? ' · read' : ' · unread') : 'No notification row for the tester yet');
    $steps[] = nq_step('acknowledged', 'Tester acknowledges the assignment in My Account → Assignments', (bool)$acked && $a && (int)$a['acknowledged_by'] === $uid,
        $acked ? 'Acknowledged at ' . ($a['acknowledged_at'] ?? '?') . ' (audited)' : 'Open My Account, find the assignment, press Acknowledge. Status now: ' . ($a['status'] ?? 'missing'), (bool)$a && (string)$a['status'] === 'cancelled');
    $steps[] = nq_step('start', 'QA match is started (the Start button does this automatically)', $started, 'Match state: ' . $match['state']);
    $steps[] = nq_step('monitor', 'Live monitor sees the tester scouting this station', (bool)($sessionOk && $actionCount > 0), $sessionOk ? 'Session #' . $session['id'] . ' · ' . $actionCount . ' saved action(s) · monitor would show “' . $monitorState . '”' : 'Waiting for the tester to join ' . $run['alliance'] . ' ' . (int)$run['station'] . ' and save at least one action');
    $steps[] = nq_step('end', 'QA match ends (Neptune ends it automatically once the tester has acknowledged and scouted)', $ended, 'Match state: ' . $match['state']);
    $steps[] = nq_step('autoclose', 'Assignment closes by itself when the match ends', (bool)$isDone,
        $isDone ? 'Completed at ' . $a['completed_at'] . ' · audit note “' . $completed['notes'] . '”' : ($autoFailed ? 'The match ended ' . $endedAgo . 's ago with saved actions but the assignment is still ' . $a['status'] . '. Auto-completion did not run.' : 'Waiting for the match to end'), $autoFailed);
    return $steps;
}

function nq_eval_analytics(PDO $pdo, array $run, array $match, array &$evidence): array {
    $org = (int)$run['organization_id']; $mid = (int)$run['match_id']; $eventId = (int)$run['event_id']; $robot = (int)$run['frc_team_number']; $uid = (int)$run['tester_user_id'];
    $runNumber = (int)$run['baseline_run'];
    $helper = dirname(__DIR__) . '/analytics/_helpers.php';
    if (!is_file($helper)) { throw new RuntimeException('Neptune analytics code was not found.'); }
    require_once $helper;
    $stats = (neptune_event_robot_stats($pdo, $org, $eventId))[$robot] ?? null;
    $tagByMatch = (neptune_scoring_tag_match_points($pdo, $org, $eventId))[$robot] ?? [];
    $q = $pdo->prepare("SELECT COUNT(*) n,COALESCE(SUM(points),0) pts,COALESCE(SUM(action_type='offense' AND result='Success'),0) succ,COALESCE(SUM(action_type='offense' AND result='Failure'),0) fail,COALESCE(SUM(action_type='defense' AND result='Success'),0) def,COUNT(DISTINCT match_id,match_run_number) runs FROM scouting_actions WHERE organization_id=? AND event_id=? AND frc_team_number=? AND deleted_at IS NULL");
    $q->execute([$org, $eventId, $robot]); $raw = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare("SELECT DISTINCT match_id FROM scouting_actions WHERE organization_id=? AND event_id=? AND frc_team_number=? AND deleted_at IS NULL AND ((action_type='offense' AND result IN ('Success','Failure')) OR points<>0)");
    $q->execute([$org, $eventId, $robot]); $tradScored = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $tradHere = nq_active_action_count($pdo, $org, $eventId, $mid, $robot, $runNumber);
    $tagRows = nq_human_tag_rows($pdo, $org, $eventId, $mid, $robot);
    $q = $pdo->prepare('SELECT red_score,blue_score FROM matches WHERE id=? AND organization_id=?'); $q->execute([$mid, $org]); $off = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $manual = null;
    if (nq_table_exists($pdo, 'tag_manual_alliance_scores')) { $q = $pdo->prepare('SELECT red_score,blue_score FROM tag_manual_alliance_scores WHERE organization_id=? AND match_id=?'); $q->execute([$org, $mid]); $manual = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    $col = $run['alliance'] === 'Red' ? 'red_score' : 'blue_score';
    $scoreSource = ($off[$col] ?? null) !== null ? 'official' : (($manual[$col] ?? null) !== null ? 'manual' : 'none');
    // Independent expectation of the documented rule: traditional scoring wins; Tag fills only robot-matches with no traditional scoring.
    $expTagMatches = 0; $expTagPoints = 0.0;
    foreach ($tagByMatch as $tm => $row) { if (!in_array((int)$tm, $tradScored, true)) { $expTagMatches++; $expTagPoints += (float)$row['points']; } }
    $expPoints = (float)$raw['pts'] + $expTagPoints;
    $expScoringMatches = count($tradScored) + $expTagMatches;
    $expPpm = $expScoringMatches ? $expPoints / $expScoringMatches : 0.0;
    $diffs = [];
    if (!$stats) { $diffs[] = 'robot missing from analytics output'; }
    else {
        if ((int)$stats['actions'] !== (int)$raw['n']) { $diffs[] = 'actions ' . $stats['actions'] . ' vs raw ' . $raw['n']; }
        if ((int)$stats['successes'] !== (int)$raw['succ']) { $diffs[] = 'successes ' . $stats['successes'] . ' vs raw ' . $raw['succ']; }
        if ((int)$stats['failures'] !== (int)$raw['fail']) { $diffs[] = 'failures ' . $stats['failures'] . ' vs raw ' . $raw['fail']; }
        if ((int)$stats['defense'] !== (int)$raw['def']) { $diffs[] = 'defense ' . $stats['defense'] . ' vs raw ' . $raw['def']; }
        if ((int)$stats['scouted_matches'] !== (int)$raw['runs']) { $diffs[] = 'scouted matches ' . $stats['scouted_matches'] . ' vs raw ' . $raw['runs']; }
    }
    $precDiffs = [];
    if ($stats) {
        if (abs((float)$stats['points'] - $expPoints) > 0.011) { $precDiffs[] = 'points ' . round((float)$stats['points'], 2) . ' vs expected ' . round($expPoints, 2); }
        if ((int)$stats['tag_estimated_matches'] !== $expTagMatches) { $precDiffs[] = 'Tag-estimated matches ' . $stats['tag_estimated_matches'] . ' vs expected ' . $expTagMatches; }
    }
    $ppmOk = $stats && abs((float)$stats['ppm'] - $expPpm) <= 0.011;
    $expStar = $stats && (int)$stats['tag_estimated_matches'] > 0 ? 'yes' : 'no';
    $answer = nq_check_answers($pdo, (int)$run['id'])['k_star'] ?? null;
    $evidence['analytics'] = ['robot' => $robot, 'traditional_here' => $tradHere, 'human_tag_reports_here' => count($tagRows), 'alliance_score_source' => $scoreSource,
        'raw' => $raw, 'traditional_scored_matches' => $tradScored, 'tag_matches_expected_to_fill' => $expTagMatches, 'expected_points' => round($expPoints, 3), 'expected_ppm' => round($expPpm, 3),
        'function_output' => $stats ? array_intersect_key($stats, array_flip(['actions', 'points', 'ppm', 'matches', 'scouted_matches', 'tag_estimated_matches', 'scoring_matches', 'points_source', 'activity_display_source'])) : null];
    $evidence['active_actions'] = $tradHere;
    $steps = [];
    $steps[] = nq_step('evidence', 'This robot-match has scouting evidence to analyze', $tradHere > 0 || $tagRows !== [], 'Traditional actions here: ' . $tradHere . ' · human Tag reports here: ' . count($tagRows));
    $steps[] = nq_step('score', 'An alliance score exists for Tag estimates (QA sets one automatically)', $scoreSource !== 'none', 'Alliance score source: ' . $scoreSource . ($scoreSource === 'manual' ? ' (' . $manual[$col] . ' pts for ' . $run['alliance'] . ')' : ''));
    $steps[] = nq_step('totals', 'Analytics Traditional totals equal the raw database records', !$diffs && (bool)$stats, $diffs ? 'Differences: ' . implode('; ', $diffs) : 'Actions ' . ($stats['actions'] ?? 0) . ' · successes ' . ($stats['successes'] ?? 0) . ' · failures ' . ($stats['failures'] ?? 0) . ' · defense ' . ($stats['defense'] ?? 0) . ' all match raw rows', (bool)$diffs);
    $steps[] = nq_step('fallback', 'Tag estimate fills in only where Traditional scoring is missing (no double counting)', !$precDiffs && (bool)$stats,
        $precDiffs ? 'Differences: ' . implode('; ', $precDiffs) : 'Points ' . round((float)($stats['points'] ?? 0), 2) . ' = traditional ' . round((float)$raw['pts'], 2) . ' + Tag fill-in ' . round($expTagPoints, 2) . ' (' . $expTagMatches . ' Tag-only match(es))', (bool)$precDiffs);
    $steps[] = nq_step('ppm', 'Points per match equals total points ÷ scoring matches', (bool)$ppmOk, 'Analytics ' . round((float)($stats['ppm'] ?? 0), 3) . ' vs expected ' . round($expPpm, 3) . ' (' . $expScoringMatches . ' scoring match(es))', $stats && !$ppmOk);
    $steps[] = nq_step('view', 'Open Robot Stats Table and report whether a * appears next to Pts/Match for this robot', $answer !== null && $answer === $expStar,
        $answer === null ? 'Use the “Checks” box below to answer after viewing the table' : ($answer === $expStar ? 'Your answer matches the database (' . $expStar . ')' : 'Your answer (' . $answer . ') does not match what the database says should appear. Look again and re-answer.'));
    return $steps;
}

function nq_eval_data_quality(PDO $pdo, array $run, array $match, array &$evidence): array {
    $org = (int)$run['organization_id']; $mid = (int)$run['match_id']; $eventId = (int)$run['event_id']; $uid = (int)$run['tester_user_id'];
    $base = nq_baseline($run);
    $key = substr(hash('sha256', 'coverage:' . $mid), 0, 48);
    $cov = nq_coverage($pdo, $org, ['id' => $mid, 'event_id' => $eventId, 'run_number' => (int)$match['run_number']]);
    $partial = $cov['covered'] && $cov['missing'];
    $audit = [];
    if (nq_table_exists($pdo, 'neptune_quality_review_audit')) {
        $q = $pdo->prepare('SELECT id,old_status,new_status,note,actor_user_id,created_at,TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP()) age_s FROM neptune_quality_review_audit WHERE organization_id=? AND event_id=? AND flag_key=? AND id>? ORDER BY id ASC LIMIT 50');
        $q->execute([$org, $eventId, $key, (int)($base['audit_base'] ?? 0)]); $audit = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $follow = null; $resolve = null;
    foreach ($audit as $row) {
        if ($follow === null && (int)$row['actor_user_id'] === $uid && $row['new_status'] === 'needs-correction' && trim((string)$row['note']) !== '') { $follow = $row; continue; }
        if ($follow !== null && $resolve === null && (int)$row['actor_user_id'] === $uid && $row['old_status'] === 'needs-correction' && in_array($row['new_status'], ['reviewed', 'dismissed'], true) && trim((string)$row['note']) !== '') { $resolve = $row; }
    }
    $chain = true; $prev = null;
    foreach ($audit as $row) { if ($prev !== null && $row['old_status'] !== $prev['new_status']) { $chain = false; } $prev = $row; }
    $review = null;
    if (nq_table_exists($pdo, 'neptune_quality_reviews')) { $q = $pdo->prepare('SELECT status,note,reviewed_by FROM neptune_quality_reviews WHERE organization_id=? AND event_id=? AND flag_key=?'); $q->execute([$org, $eventId, $key]); $review = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    $trailOk = $follow && $resolve && $chain && $review && $prev && $review['status'] === $prev['new_status'] && (int)$review['reviewed_by'] === (int)$prev['actor_user_id'];
    $intact = nq_data_fingerprint($pdo, $org, $eventId, $mid) === (string)($base['fingerprint'] ?? '');
    $warnings = [];
    if ($audit) { $newest = end($audit); if (abs((int)$newest['age_s']) > 600 && abs((int)$newest['age_s']) < 86400) { $warnings[] = 'Platform note, not a tester error: Data Quality audit timestamps are about ' . round(abs((int)$newest['age_s']) / 3600, 1) . ' h away from UTC, i.e. stored in server-local time instead of UTC.'; } }
    $evidence['data_quality'] = ['flag_key' => $key, 'covered' => $cov['covered'], 'missing' => $cov['missing'], 'audit' => array_map(static fn($r) => $r['old_status'] . '→' . $r['new_status'] . ' by user ' . $r['actor_user_id'], $audit), 'review' => $review, 'data_unchanged' => $intact];
    $evidence['data_quality']['warnings'] = $warnings;
    $evidence['warnings'] = $warnings;
    $steps = [];
    $steps[] = nq_step('finding', 'Neptune flags this match as an Incomplete match (' . count($cov['covered']) . '/' . count($cov['teams']) . ' robots covered)', (bool)($partial || $audit), $partial ? 'Missing: #' . implode(', #', $cov['missing']) : 'The finding no longer applies because every robot (or none) is now covered', !$partial && !$audit);
    $steps[] = nq_step('followup', 'Save a “Needs follow-up” decision with a specific note', (bool)$follow, $follow ? 'Saved ' . $follow['created_at'] . ' · “' . mb_substr((string)$follow['note'], 0, 80) . '”' : 'Open Data Quality for QA Test Event 1, find “Incomplete Qual ' . (int)$match['match_number'] . '”, choose Needs follow-up, write a note, save');
    $steps[] = nq_step('resolve', 'Then resolve it: Reviewed or Dismissed, with a reason', (bool)$resolve, $resolve ? 'Resolved as ' . $resolve['new_status'] . ' · “' . mb_substr((string)$resolve['note'], 0, 80) . '”' : 'Come back to the same finding, change the decision, explain why, save');
    $steps[] = nq_step('audit_trail', 'Audit history is complete and consistent', (bool)$trailOk, $trailOk ? count($audit) . ' audit entries chain correctly and match the current review' : ($audit ? 'Waiting for both decisions' : 'No audit entries yet'), $follow && $resolve && !$trailOk);
    $steps[] = nq_step('integrity', 'Reviewing did not change any scouting data', $intact && (bool)$resolve, $intact ? 'Scouting actions and Tag reports are unchanged since the test began' : 'Scouting data CHANGED during the review', !$intact);
    return $steps;
}

/* ---------- Connection Guard: offline and reconnect ---------- */

/** Seconds of extra delay (beyond the device's normal clock offset) that marks an action as queued, not sent live. */
const NQ_GUARD_QUEUED_SECONDS = 10;

/**
 * Compares what the tester's device says it sent (Connection Guard ledger, reported by the
 * QA page) with server receipts and stored actions. The device clock may be offset from the
 * server, so "queued" is measured against the smallest send-to-receipt delay seen on this run.
 */
function nq_eval_connection_guard(PDO $pdo, array $run, array $match, array &$evidence): array {
    $org = (int)$run['organization_id']; $mid = (int)$run['match_id']; $uid = (int)$run['tester_user_id']; $robot = (int)$run['frc_team_number'];
    $runNumber = (int)$run['baseline_run'];
    $q = $pdo->prepare('SELECT id,alliance,station FROM scout_sessions WHERE organization_id=? AND event_id=? AND match_id=? AND match_run_number=? AND frc_team_number=? AND user_id=? AND id>? ORDER BY id DESC LIMIT 1');
    $q->execute([$org, (int)$run['event_id'], $mid, $runNumber, $robot, $uid, (int)$run['baseline_session_id']]); $session = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $sessionOk = $session && (string)$session['alliance'] === (string)$run['alliance'] && (int)$session['station'] === (int)$run['station'];
    $sid = $sessionOk ? (int)$session['id'] : 0;

    // Stored actions for this session, and the Connection Guard receipt (if any) that reported each one.
    $actions = [];
    if ($sid) {
        $q = $pdo->prepare('SELECT id FROM scouting_actions WHERE organization_id=? AND scout_session_id=? AND match_run_number=? AND created_by=? ORDER BY id ASC LIMIT 500');
        $q->execute([$org, $sid, $runNumber, $uid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { $actions[(int)$id] = null; }
    }
    $receipts = [];
    if ($actions && nq_table_exists($pdo, 'action_request_receipts')) {
        $q = $pdo->prepare("SELECT request_uuid,response_body,created_at FROM action_request_receipts WHERE created_at >= ? AND response_code BETWEEN 200 AND 299");
        $q->execute([(string)$run['created_at']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $body = json_decode((string)$r['response_body'], true);
            $aid = is_array($body) ? (int)($body['id'] ?? 0) : 0;
            if ($aid && array_key_exists($aid, $actions)) {
                $u = strtolower((string)$r['request_uuid']);
                $receipts[$u] = ['action_id' => $aid, 'at' => nq_ts((string)$r['created_at'])];
                $actions[$aid] = $u;
            }
        }
    }

    // Device report: ledger entries for this session, and what is still waiting in the device queue.
    $report = json_decode((string)($run['client_report_json'] ?? ''), true);
    $report = is_array($report) ? $report : [];
    $reportAge = isset($report['received_epoch']) ? time() - (int)$report['received_epoch'] : null;
    $reportFresh = $reportAge !== null && $reportAge <= 60;
    $ledger = array_values(array_filter((array)($report['ledger'] ?? []), static fn($e) => $sid && (int)($e['session_id'] ?? 0) === $sid));
    $pending = [];
    foreach ((array)($report['pending'] ?? []) as $p) { $pending[strtolower((string)($p['request_uuid'] ?? ''))] = $p; }

    $rows = [];
    foreach ($ledger as $e) {
        $u = strtolower((string)$e['request_uuid']);
        $sent = strtotime((string)$e['created_at']) ?: 0;
        $rows[] = ['uuid' => $u, 'sent' => $sent, 'label' => (string)($e['label'] ?? ''),
            'stored' => isset($receipts[$u]), 'delay' => isset($receipts[$u]) ? $receipts[$u]['at'] - $sent : null,
            'pending' => $pending[$u] ?? null];
    }
    $delays = array_values(array_filter(array_column($rows, 'delay'), static fn($d) => $d !== null));
    $offset = $delays ? min($delays) : 0;
    $online = 0; $queued = 0; $replayed = 0; $waiting = 0; $refused = 0; $lost = [];
    foreach ($rows as $r) {
        if ($r['stored']) {
            if ($r['delay'] - $offset >= NQ_GUARD_QUEUED_SECONDS) { $queued++; $replayed++; } else { $online++; }
        } elseif ($r['pending']) {
            $queued++;
            if (!empty($r['pending']['blocked'])) { $refused++; } else { $waiting++; }
        } elseif ($reportFresh && $r['sent'] && time() - $r['sent'] > 30) {
            $lost[] = $r['uuid'] . ($r['label'] !== '' ? ' (' . $r['label'] . ')' : '');
        }
    }
    $ledgerUuids = array_flip(array_column($rows, 'uuid'));
    $bypass = array_keys(array_filter($actions, static fn($u) => $u === null));
    $unreported = array_values(array_filter($actions, static fn($u) => $u !== null && !isset($ledgerUuids[$u])));

    $started = !empty($match['started_at']) && nq_ts((string)$match['started_at']) >= nq_ts((string)$run['created_at']);
    $ended = $match['state'] === 'ended' && $started;
    $evidence['connection_guard'] = ['session' => $sid ?: null, 'device' => mb_substr((string)($report['device'] ?? ''), 0, 120),
        'report_age_s' => $reportAge, 'ledger_entries' => count($rows), 'stored_actions' => count($actions), 'receipts' => count($receipts),
        'online' => $online, 'queued' => $queued, 'replayed' => $replayed, 'waiting' => $waiting, 'refused' => $refused,
        'lost' => $lost, 'sent_without_guard' => $bypass, 'not_in_ledger' => $unreported, 'clock_offset_s' => $offset];
    $evidence['action_ids'] = array_keys($actions);
    $evidence['active_actions'] = count($actions);

    $allQueuedBack = $queued >= 3 && $waiting === 0 && $refused === 0 && $replayed === $queued;
    $clean = $sid && $rows && !$lost && !$bypass && !$unreported && $refused === 0 && $waiting === 0;
    $steps = [];
    $steps[] = nq_step('report', 'Keep this QA page open: it reads the device\'s Connection Guard ledger (automatic)', $reportFresh,
        $reportFresh ? 'Device report ' . $reportAge . 's ago · ' . count($rows) . ' ledger entr' . (count($rows) === 1 ? 'y' : 'ies') . ' for this session' : 'No recent report from this device. Keep this page open on the same device and browser as the scouting window.');
    $steps[] = nq_step('start', 'QA match is started (the Start button does this automatically)', $started, 'Match state: ' . $match['state']);
    $steps[] = nq_step('connected', 'Tester joins ' . (string)$run['alliance'] . ' station ' . (int)$run['station'], (bool)$sessionOk,
        $sessionOk ? 'Session #' . $sid : 'Waiting for the tester to join the assigned robot and station', (bool)$session && !$sessionOk);
    $steps[] = nq_step('online', 'Record at least 3 actions with the connection ON', $online >= 3, $online . ' action(s) saved live');
    $steps[] = nq_step('offline', 'Turn Wi-Fi OFF, keep scouting: record at least 3 actions (badge shows CONNECTION LOST)', $queued >= 3,
        $queued . ' action(s) queued on the device while offline' . ($waiting ? ' · ' . $waiting . ' still waiting' : ''));
    $steps[] = nq_step('replayed', 'Turn Wi-Fi back ON: the queued actions send by themselves (badge shows SYNCED)', $allQueuedBack,
        $refused ? $refused . ' queued action(s) were REFUSED by the server and kept on the device' : ($waiting ? $waiting . ' action(s) still waiting on the device' : $replayed . ' of ' . $queued . ' queued action(s) stored after reconnecting'), $refused > 0);
    $problems = [];
    if ($lost) { $problems[] = count($lost) . ' LOST (sent but neither stored nor queued)'; }
    if ($bypass) { $problems[] = count($bypass) . ' stored without Connection Guard'; }
    if ($unreported) { $problems[] = count($unreported) . ' stored but missing from the device ledger'; }
    $steps[] = nq_step('accounted', 'Every action the device sent is stored exactly once (automatic)', (bool)($clean && $allQueuedBack),
        $problems ? implode(' · ', $problems) : count($rows) . ' sent · ' . count($actions) . ' stored · ' . count($receipts) . ' receipts', (bool)($lost || $bypass));
    $steps[] = nq_step('end', 'QA match ends (Neptune ends it automatically once every other step has passed)', $ended, 'Match state: ' . $match['state']);
    return $steps;
}

/* ---------- exit a test ---------- */

function nq_can_exit(array $run): bool {
    if (in_array((string)$run['status'], ['passed', 'abandoned'], true)) { return false; }
    // A failed run whose match has since been reset is final; there is nothing left to exit.
    return !((string)$run['status'] === 'failed' && isset($run['current_run']) && (int)$run['current_run'] !== (int)$run['baseline_run']);
}
/** Marks a run as exited, tidies up what the QA system started for it, and records why. */
function nq_exit(PDO $pdo, int $org, array $run, int $actor, string $reason, string $note): void {
    require_once __DIR__ . '/_qa_questions.php';
    if (!nq_can_exit($run)) { throw new InvalidArgumentException((string)$run['status'] === 'passed' ? 'This test already passed, so there is nothing to exit.' : 'This test was already exited.'); }
    if (!array_key_exists($reason, nq_exit_reasons_choosable())) { throw new InvalidArgumentException('Choose why you are leaving the test.'); }
    $note = trim($note);
    if (mb_strlen($note) > 500) { throw new InvalidArgumentException('The note is limited to 500 characters.'); }
    if ($reason === 'other' && $note === '') { throw new InvalidArgumentException('Add a short note when the reason is “Another reason”.'); }
    $runId = (int)$run['id'];
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("UPDATE neptune_qa_runs SET status='abandoned',exit_reason=?,exit_note=?,exited_at=UTC_TIMESTAMP(),completed_at=COALESCE(completed_at,UTC_TIMESTAMP()) WHERE id=? AND organization_id=? AND status NOT IN ('passed','abandoned')");
        $q->execute([$reason, $note !== '' ? $note : null, $runId, $org]);
        if ($q->rowCount() !== 1) { throw new InvalidArgumentException('This test can no longer be exited.'); }
        $snap = json_encode(['status' => 'abandoned', 'reason' => $reason, 'by' => $actor], JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT IGNORE INTO neptune_qa_checkpoints (organization_id,qa_run_id,evidence_hash,result_status,snapshot_json,checked_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())")
            ->execute([$org, $runId, hash('sha256', 'exit:' . $runId), 'abandoned', $snap]);
        if ((string)$run['scenario'] === 'assignment') {
            $s = $pdo->prepare("SELECT id,status,assignee_user_id,event_id FROM scouting_assignments WHERE organization_id=? AND match_id=? AND alliance=? AND station=? AND assignee_user_id=? AND status IN ('assigned','acknowledged') FOR UPDATE");
            $s->execute([$org, (int)$run['match_id'], $run['alliance'], (int)$run['station'], (int)$run['tester_user_id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $pdo->prepare("UPDATE scouting_assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_by=? WHERE id=? AND organization_id=?")->execute([$actor, (int)$a['id'], $org]);
                $pdo->prepare('INSERT INTO scouting_assignment_history (organization_id,event_id,assignment_id,action,previous_user_id,new_user_id,previous_status,new_status,actor_user_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')
                    ->execute([$org, (int)$a['event_id'], (int)$a['id'], 'cancelled', (int)$a['assignee_user_id'], (int)$a['assignee_user_id'], (string)$a['status'], 'cancelled', $actor, 'QA test exited; assignment cancelled by the QA system']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    // A match this run started must not stay running after the tester leaves (best effort; QA matches only).
    if (nq_uses_traditional_scouting((string)$run['scenario'])) {
        try {
            $q = $pdo->prepare('SELECT state,run_number FROM matches WHERE id=? AND organization_id=?'); $q->execute([(int)$run['match_id'], $org]); $m = $q->fetch(PDO::FETCH_ASSOC);
            if ($m && $m['state'] === 'running' && (int)$m['run_number'] === (int)$run['baseline_run']) { nq_control_match($pdo, $org, (int)$run['match_id'], (int)$run['baseline_run'], 'end'); }
        } catch (Throwable $e) { error_log('[Neptune QA exit] could not end the QA match: ' . $e->getMessage()); }
    }
}
