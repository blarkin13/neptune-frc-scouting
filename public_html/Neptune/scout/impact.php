<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once __DIR__ . '/_tag_scouting.php';
require_once dirname(__DIR__) . '/_event_selection.php';
$u = require_login();
$org = (int)($u['organization_id'] ?? 0);
$userId = (int)($u['id'] ?? 0);

tag_unified_ensure_schema($pdo);

function impact_optional_int(mixed $raw, int $max): ?int {
    if ($raw === null || $raw === '') return null;
    $value = (int)$raw;
    if ($value < 0) return null;
    return max(0, min($max, $value));
}

function impact_nullable_float(mixed $value): ?float {
    return $value === null ? null : (float)$value;
}

function impact_match_label(array $m): string {
    $level = ['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final'][$m['comp_level'] ?? ''] ?? strtoupper((string)($m['comp_level'] ?? 'Match'));
    return ($m['comp_level'] ?? '') === 'qm'
        ? $level . ' ' . (int)$m['match_number']
        : $level . ' ' . (int)$m['set_number'] . '-' . (int)$m['match_number'];
}

function impact_tags(): array {
    return [
        'scoring_a_lot'      => ['Scoring a Lot', 'fa-solid fa-bolt'],
        'fast_cycles'        => ['Fast Cycles', 'fa-solid fa-gauge-high'],
        'hard_to_defend'     => ['Hard to Defend', 'fa-solid fa-shield-halved'],
        'impressive_auton'   => ['Impressive Auton', 'fa-solid fa-rocket'],
        'consistent_auton'   => ['Consistent Auton', 'fa-solid fa-repeat'],
        'no_auton'           => ['No Auton', 'fa-solid fa-circle-xmark'],
        'good_defense'       => ['Good Defense', 'fa-solid fa-shield'],
        'elite_defense'      => ['Elite Defense', 'fa-solid fa-user-shield'],
        'counter_defense'    => ['Good Counter-Defense', 'fa-solid fa-person-running'],
        'no_defense'         => ['No Defense', 'fa-solid fa-circle-xmark'],
        'smart_driver'       => ['Smart Driver', 'fa-solid fa-brain'],
        'smooth_driver'      => ['Smooth Driver', 'fa-solid fa-route'],
        'great_partner'      => ['Great Alliance Partner', 'fa-solid fa-people-group'],
        'feeder_support'     => ['Strong Feeder / Support', 'fa-solid fa-arrows-turn-to-dots'],
        'strong_endgame'     => ['Strong Endgame', 'fa-solid fa-trophy'],
        'reliable_endgame'   => ['Reliable Endgame', 'fa-solid fa-flag-checkered'],
        'slow_endgame'       => ['Slow Endgame', 'fa-solid fa-hourglass-half'],
        'failed_endgame'     => ['Failed Endgame', 'fa-solid fa-circle-xmark'],
        'clutch'             => ['Clutch', 'fa-solid fa-fire'],
        'consistent'         => ['Consistent', 'fa-solid fa-circle-check'],
        'versatile'          => ['Versatile', 'fa-solid fa-shuffle'],
        'inconsistent'       => ['Inconsistent', 'fa-solid fa-wave-square'],
        'penalty_risk'       => ['Penalty Risk', 'fa-solid fa-triangle-exclamation'],
        'mechanical_issues'  => ['Mechanical Issues', 'fa-solid fa-screwdriver-wrench'],
        'disabled'           => ['Disabled / Dead', 'fa-solid fa-power-off'],
        'weak_auton'         => ['Weak Auton', 'fa-solid fa-forward-step'],
        'struggles_defense'  => ['Struggles Under Defense', 'fa-solid fa-shield-virus'],
    ];
}

function impact_ajax_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$tagCatalog = impact_tags();
$message = '';
$error = '';
$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST') && ((string)($_POST['impact_ajax'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $eventId = (int)($_POST['event_id'] ?? 0);
        $matchId = (int)($_POST['match_id'] ?? 0);
        $team = (int)($_POST['frc_team_number'] ?? 0);

        $s = $pdo->prepare("SELECT m.id,m.event_id,m.game_id,mt.alliance,mt.station
            FROM matches m
            JOIN events e ON e.id=m.event_id AND e.organization_id=?
            JOIN match_teams mt ON mt.match_id=m.id
            WHERE m.id=? AND m.event_id=? AND mt.frc_team_number=?
            LIMIT 1");
        $s->execute([$org, $matchId, $eventId, $team]);
        $target = $s->fetch();
        if (!$target) throw new RuntimeException('That robot is not assigned to the selected match.');

        $scoring = impact_optional_int($_POST['scoring_contribution'] ?? null, 100);
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

        $selectedTags = $_POST['tags'] ?? [];
        if (!is_array($selectedTags)) $selectedTags = [];
        $selectedTags = array_values(array_unique(array_filter(array_map('strval', $selectedTags), static fn($tag) => isset(impact_tags()[$tag]))));
        $tagsJson = json_encode($selectedTags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($tagsJson === false) $tagsJson = '[]';

        $rawWeights = json_decode((string)($_POST['tag_weights_json'] ?? '{}'), true);
        if (!is_array($rawWeights)) $rawWeights = [];
        $binaryTags = ['no_auton'=>true, 'no_defense'=>true, 'failed_endgame'=>true];
        $tagWeights = [];
        foreach ($selectedTags as $tag) {
            if (isset($binaryTags[$tag])) continue; // Binary facts are never weighted.
            $weight = array_key_exists($tag, $rawWeights) ? (int)$rawWeights[$tag] : -1;
            if ($weight >= 1 && $weight <= 5) $tagWeights[$tag] = $weight;
        }
        $tagWeightsJson = json_encode($tagWeights, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($tagWeightsJson === false) $tagWeightsJson = '{}';

        $sql = "INSERT INTO tag_scouting_match_data
            (organization_id,event_id,match_id,game_id,user_id,frc_team_number,alliance,station,scoring_contribution,tags_json,tag_weights_json,note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                event_id=VALUES(event_id),game_id=VALUES(game_id),alliance=VALUES(alliance),station=VALUES(station),
                scoring_contribution=VALUES(scoring_contribution),tags_json=VALUES(tags_json),tag_weights_json=VALUES(tag_weights_json),note=VALUES(note),updated_at=CURRENT_TIMESTAMP";
        $pdo->beginTransaction();
        try{
            $pdo->prepare($sql)->execute([
                $org,$eventId,$matchId,(int)$target['game_id'],$userId,$team,$target['alliance'],$target['station'],
                $scoring,$tagsJson,$tagWeightsJson,$note !== '' ? $note : null
            ]);
            $q=$pdo->prepare("SELECT * FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 1");
            $q->execute([$org,$matchId,$userId,$team]);
            $savedRow=$q->fetch();
            if(!$savedRow)throw new RuntimeException('Could not reload the saved Tag observation.');
            tag_unified_sync_match_observation($pdo,$savedRow,$selectedTags,$tagWeights);
            $pdo->commit();
        }catch(Throwable $saveError){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $saveError;
        }

        if ($isAjax) {
            $q = $pdo->prepare("SELECT updated_at FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND user_id=? AND frc_team_number=? LIMIT 1");
            $q->execute([$org,$matchId,$userId,$team]);
            $updatedAt = (string)($q->fetchColumn() ?: gmdate('Y-m-d H:i:s'));
            $q = $pdo->prepare("SELECT COUNT(*) observers,
                COUNT(scoring_contribution) scoring_ratings,
                ROUND(AVG(scoring_contribution),1) avg_scoring
                FROM tag_scouting_match_data
                WHERE organization_id=? AND match_id=? AND frc_team_number=?");
            $q->execute([$org,$matchId,$team]);
            $summary = $q->fetch() ?: ['observers'=>0,'scoring_ratings'=>0,'avg_scoring'=>null];
            $q = $pdo->prepare("SELECT tags_json FROM tag_scouting_match_data WHERE organization_id=? AND match_id=? AND frc_team_number=?");
            $q->execute([$org,$matchId,$team]);
            $tagCounts = [];
            foreach ($q->fetchAll() as $tagRow) {
                $tags = json_decode((string)$tagRow['tags_json'], true);
                if (!is_array($tags)) continue;
                foreach ($tags as $tag) if (isset($tagCatalog[$tag])) $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
            impact_ajax_response([
                'ok' => true,
                'team' => $team,
                'updated_at' => $updatedAt,
                'summary' => [
                    'observers'=>(int)$summary['observers'],
                    'scoring_ratings'=>(int)$summary['scoring_ratings'],
                    'avg_scoring'=>impact_nullable_float($summary['avg_scoring']),
                ],
                'tag_counts' => $tagCounts,
            ]);
        }

        $message = 'Tag observation saved for Team ' . $team . '.';
    } catch (Throwable $e) {
        if ($isAjax) impact_ajax_response(['ok'=>false,'error'=>$e->getMessage()], 422);
        $error = $e->getMessage();
    }
}

$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$events = neptune_event_selector_rows($pdo,$org,$eventId,neptune_selector_show_history(),true);
$validEventIds = array_map(static fn($x)=>(int)$x['id'], $events);
if ($eventId && !in_array($eventId, $validEventIds, true)) $eventId = 0;
if (!$eventId && $events) $eventId = (int)$events[0]['id'];

$matches = [];
if ($eventId) {
    $s = $pdo->prepare("SELECT id,event_id,game_id,comp_level,set_number,match_number,field_id,state,scheduled_time,red_score,blue_score
        FROM matches WHERE event_id=?
        ORDER BY CASE comp_level WHEN 'qm' THEN 1 WHEN 'ef' THEN 2 WHEN 'qf' THEN 3 WHEN 'sf' THEN 4 WHEN 'f' THEN 5 ELSE 6 END,
                 set_number,match_number,field_id");
    $s->execute([$eventId]);
    $matches = $s->fetchAll();
}

$matchId = (int)($_GET['match_id'] ?? $_POST['match_id'] ?? 0);
$validMatchIds = array_map(static fn($x)=>(int)$x['id'], $matches);
if ($matchId && !in_array($matchId, $validMatchIds, true)) $matchId = 0;

// With no explicit match selected, open the first match that is not fully covered.
// Coverage is organization-wide: a robot counts as covered once any scout has a saved
// observation row for that robot in that match. A partially scouted match therefore
// remains the default until every scheduled robot has at least one observation.
if (!$matchId && $matches) {
    $coverageByMatch = [];
    $s = $pdo->prepare("SELECT m.id,
            COUNT(DISTINCT mt.frc_team_number) robot_count,
            COUNT(DISTINCT CASE WHEN io.id IS NOT NULL THEN mt.frc_team_number END) observed_count
        FROM matches m
        LEFT JOIN match_teams mt ON mt.match_id=m.id
        LEFT JOIN tag_scouting_match_data io
          ON io.organization_id=?
         AND io.match_id=m.id
         AND io.frc_team_number=mt.frc_team_number
        WHERE m.event_id=?
        GROUP BY m.id");
    $s->execute([$org,$eventId]);
    foreach ($s->fetchAll() as $row) {
        $coverageByMatch[(int)$row['id']] = [
            'robot_count'=>(int)$row['robot_count'],
            'observed_count'=>(int)$row['observed_count'],
        ];
    }

    foreach ($matches as $m) {
        $id = (int)$m['id'];
        $coverage = $coverageByMatch[$id] ?? ['robot_count'=>0,'observed_count'=>0];
        if ($coverage['robot_count'] > 0 && $coverage['observed_count'] < $coverage['robot_count']) {
            $matchId = $id;
            break;
        }
    }

    // If every scheduled match is already covered, stay on the latest match with robots.
    if (!$matchId) {
        for ($i=count($matches)-1; $i>=0; $i--) {
            $id = (int)$matches[$i]['id'];
            if (($coverageByMatch[$id]['robot_count'] ?? 0) > 0) { $matchId = $id; break; }
        }
    }
    if (!$matchId) $matchId = (int)$matches[0]['id'];
}

$robots = [];
$match = null;
$summaryByTeam = [];
$tagConsensus = [];
$mineByTeam = [];
if ($matchId) {
    foreach ($matches as $m) if ((int)$m['id'] === $matchId) { $match = $m; break; }

    $s = $pdo->prepare("SELECT mt.frc_team_number,mt.alliance,mt.station,et.nickname
        FROM match_teams mt
        JOIN matches m ON m.id=mt.match_id
        LEFT JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=mt.frc_team_number
        WHERE mt.match_id=? AND m.event_id=?
        ORDER BY FIELD(mt.alliance,'Red','Blue'),mt.station");
    $s->execute([$matchId,$eventId]);
    $robots = $s->fetchAll();

    $s = $pdo->prepare("SELECT frc_team_number,COUNT(*) observers,
        COUNT(scoring_contribution) scoring_ratings,
        ROUND(AVG(scoring_contribution),1) avg_scoring
        FROM tag_scouting_match_data
        WHERE organization_id=? AND match_id=?
        GROUP BY frc_team_number");
    $s->execute([$org,$matchId]);
    foreach ($s->fetchAll() as $row) $summaryByTeam[(int)$row['frc_team_number']] = $row;

    $s = $pdo->prepare("SELECT frc_team_number,tags_json FROM tag_scouting_match_data WHERE organization_id=? AND match_id=?");
    $s->execute([$org,$matchId]);
    foreach ($s->fetchAll() as $row) {
        $team = (int)$row['frc_team_number'];
        $tags = json_decode((string)$row['tags_json'], true);
        if (!is_array($tags)) continue;
        foreach ($tags as $tag) if (isset($tagCatalog[$tag])) $tagConsensus[$team][$tag] = ($tagConsensus[$team][$tag] ?? 0) + 1;
    }

    $s = $pdo->prepare("SELECT frc_team_number,scoring_contribution,tags_json,tag_weights_json,note,updated_at
        FROM tag_scouting_match_data
        WHERE organization_id=? AND match_id=? AND user_id=?");
    $s->execute([$org,$matchId,$userId]);
    foreach ($s->fetchAll() as $row) {
        $tags = json_decode((string)$row['tags_json'], true);
        $tagWeights = json_decode((string)($row['tag_weights_json'] ?? ''), true);
        $mineByTeam[(int)$row['frc_team_number']] = [
            'scoring_contribution' => $row['scoring_contribution'] === null ? -1 : (int)$row['scoring_contribution'],
            'tags' => is_array($tags) ? array_values($tags) : [],
            'tag_weights' => is_array($tagWeights) ? $tagWeights : [],
            'note' => (string)($row['note'] ?? ''),
            'serverUpdatedAt' => (string)$row['updated_at'],
        ];
    }
}

$selectedTeam = (int)($_GET['robot'] ?? $_POST['frc_team_number'] ?? 0);
$selectedRobot = null;
foreach ($robots as $r) if ((int)$r['frc_team_number'] === $selectedTeam) { $selectedRobot = $r; break; }
if (!$selectedRobot && $robots) { $selectedRobot = $robots[0]; $selectedTeam = (int)$selectedRobot['frc_team_number']; }
if (!$selectedRobot) $selectedRobot = ['frc_team_number'=>0,'alliance'=>'','station'=>'','nickname'=>''];

$robotSummary = $summaryByTeam[$selectedTeam] ?? ['observers'=>0,'scoring_ratings'=>0,'avg_scoring'=>null];
$robotTagConsensus = $tagConsensus[$selectedTeam] ?? [];
arsort($robotTagConsensus);

$robotsForJs = [];
foreach ($robots as $r) {
    $team = (int)$r['frc_team_number'];
    $robotsForJs[$team] = [
        'team' => $team,
        'alliance' => (string)$r['alliance'],
        'station' => (int)$r['station'],
        'nickname' => (string)($r['nickname'] ?: 'Robot '.$team),
    ];
}
$summaryForJs = [];
foreach ($summaryByTeam as $team=>$row) {
    $summaryForJs[(int)$team] = [
        'observers'=>(int)$row['observers'],
        'scoring_ratings'=>(int)$row['scoring_ratings'],
        'avg_scoring'=>impact_nullable_float($row['avg_scoring']),
    ];
}
$tagConsensusForJs = [];
foreach ($tagConsensus as $team=>$rows) $tagConsensusForJs[(int)$team] = $rows;

$tagGroups = [
    ['label'=>'Auto', 'icon'=>'fa-solid fa-rocket', 'tags'=>['impressive_auton','consistent_auton','weak_auton','no_auton']],
    ['label'=>'Offense', 'icon'=>'fa-solid fa-bolt', 'tags'=>['scoring_a_lot','fast_cycles','hard_to_defend','struggles_defense']],
    ['label'=>'Defense', 'icon'=>'fa-solid fa-shield-halved', 'tags'=>['good_defense','elite_defense','counter_defense','no_defense']],
    ['label'=>'Endgame', 'icon'=>'fa-solid fa-flag-checkered', 'tags'=>['strong_endgame','reliable_endgame','slow_endgame','failed_endgame']],
    ['label'=>'Driver', 'icon'=>'fa-solid fa-gamepad', 'tags'=>['smart_driver','smooth_driver','clutch','versatile']],
    ['label'=>'Alliance', 'icon'=>'fa-solid fa-people-group', 'tags'=>['great_partner','feeder_support','consistent']],
    ['label'=>'Watch Out', 'icon'=>'fa-solid fa-triangle-exclamation', 'tags'=>['inconsistent','penalty_risk','mechanical_issues','disabled']],
];
$negativeTags = ['weak_auton','no_auton','no_defense','struggles_defense','slow_endgame','failed_endgame','inconsistent','penalty_risk','mechanical_issues','disabled'];

$pageTitle = 'Tag Scouting';
$moduleName = 'TRIDENT';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
html,body{max-width:100%;overflow-x:hidden}
.tag-unified-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}.tag-unified-tab{display:flex;align-items:center;justify-content:center;gap:7px;min-height:46px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);color:var(--muted);text-decoration:none;font-weight:900}.tag-unified-tab.active{color:var(--module-trident);border-color:var(--module-trident);background:color-mix(in srgb,var(--module-trident) 8%,var(--panel2));box-shadow:inset 0 0 0 1px var(--module-trident)}
.impact-shell{--impact-red:#ff4d67;--impact-blue:#4f8cff;--impact-cyan:#38d7ff;--impact-glow:color-mix(in srgb,var(--module-trident,var(--accent,#6f7cff)) 42%,transparent);position:relative}
.impact-hero{position:relative;overflow:hidden;padding:22px;border:1px solid var(--line);border-radius:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel,#111827) 86%,var(--module-trident,#0b79b7) 14%),var(--panel,#111827));box-shadow:0 18px 55px rgba(0,0,0,.14)}
.impact-hero:before,.impact-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(3px)}.impact-hero:before{width:320px;height:320px;right:-110px;top:-180px;background:radial-gradient(circle,var(--impact-glow),transparent 68%)}.impact-hero:after{width:220px;height:220px;left:-120px;bottom:-160px;background:radial-gradient(circle,color-mix(in srgb,var(--impact-cyan) 26%,transparent),transparent 70%)}
.impact-kicker{display:flex;align-items:center;gap:8px;font-size:.75rem;letter-spacing:.16em;font-weight:950;text-transform:uppercase;color:var(--muted)}
.impact-title{font-size:clamp(2.15rem,7vw,4.8rem);line-height:.9;margin:10px 0 12px;letter-spacing:-.065em}.impact-title span{background:linear-gradient(90deg,var(--module-trident,var(--accent,#7c8cff)),var(--impact-cyan));-webkit-background-clip:text;background-clip:text;color:transparent}.impact-sub{max-width:820px;margin:0;color:var(--muted);font-size:1rem;line-height:1.55}
.impact-selectors{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:19px}.impact-selectors label{display:grid;gap:7px;font-weight:900;font-size:.76rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted)}.impact-selectors select{width:100%;min-height:54px;border-radius:15px;font-size:1rem;font-weight:850}
.impact-alliance-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px}.impact-alliance{border:1px solid var(--line);border-radius:22px;padding:14px;background:color-mix(in srgb,var(--panel,#111827) 95%,transparent)}.impact-alliance.red{box-shadow:inset 0 4px 0 var(--impact-red)}.impact-alliance.blue{box-shadow:inset 0 4px 0 var(--impact-blue)}.impact-alliance-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.impact-alliance-head b{font-size:.84rem;letter-spacing:.12em;text-transform:uppercase}.impact-robots{display:grid;grid-template-columns:repeat(3,1fr);grid-auto-rows:1fr;align-items:stretch;gap:10px}
.impact-robot{display:flex;flex-direction:column;width:100%;height:100%;box-sizing:border-box;text-decoration:none;text-align:left;font:inherit;color:inherit;border:1px solid var(--line);border-radius:19px;padding:14px;min-height:154px;background:linear-gradient(180deg,color-mix(in srgb,var(--panel,#111827) 95%,white 5%),var(--panel,#111827));transition:.16s transform,.16s border-color,.16s box-shadow,.16s background;position:relative;overflow:hidden;cursor:pointer;touch-action:manipulation}.impact-robot:before{content:"";position:absolute;inset:0;opacity:0;background:linear-gradient(135deg,color-mix(in srgb,var(--module-trident,var(--accent)) 17%,transparent),transparent 58%);transition:.16s opacity}.impact-robot:hover,.impact-robot.active{transform:translateY(-2px);border-color:var(--module-trident,var(--accent,#7c8cff));box-shadow:0 14px 38px rgba(0,0,0,.18),0 0 0 2px var(--impact-glow)}.impact-robot.active:before{opacity:1}.impact-robot.active:after{content:"ACTIVE";position:absolute;right:9px;top:9px;font-size:.58rem;letter-spacing:.09em;font-weight:1000;padding:4px 6px;border-radius:999px;background:var(--module-trident,var(--accent,#7c8cff));color:var(--solid-text,#fff)}
.impact-team{position:relative;font-size:clamp(1.55rem,3vw,2.35rem);font-weight:1000;letter-spacing:-.05em}.impact-nick{position:relative;font-size:.77rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.impact-meta{position:relative;display:flex;gap:6px;flex-wrap:wrap;align-content:flex-start;margin-top:auto;padding-top:11px;min-height:62px}.impact-mini{font-size:.68rem;border:1px solid var(--line);border-radius:999px;padding:5px 7px;color:var(--muted);font-weight:850}.impact-mini.local{display:none}.impact-robot.has-local .impact-mini.local{display:inline-flex;align-items:center;gap:4px;color:var(--good);border-color:color-mix(in srgb,var(--good) 45%,var(--line));background:color-mix(in srgb,var(--good) 10%,transparent)}.impact-robot.has-pending .impact-mini.local{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 48%,var(--line));background:color-mix(in srgb,var(--warn) 10%,transparent)}
.impact-main{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr);gap:16px;margin-top:16px}.impact-editor,.impact-consensus{border:1px solid var(--line);border-radius:22px;padding:18px;background:var(--panel,#111827)}.impact-editor{overflow:hidden}.impact-editor-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.impact-editor-head h2{margin:2px 0 0;font-size:clamp(1.65rem,4vw,2.15rem);letter-spacing:-.035em}.impact-station{font-size:.8rem;color:var(--muted);margin-top:3px}
.impact-save-state{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border:1px solid var(--line);border-radius:15px;padding:10px 12px;margin-bottom:16px;background:color-mix(in srgb,var(--panel2,var(--panel)) 84%,transparent);font-size:.8rem}.impact-save-state i{width:16px;text-align:center}.impact-save-state .pending{margin-left:auto;color:var(--muted);font-weight:850}.impact-save-state.synced i{color:var(--good)}.impact-save-state.local i,.impact-save-state.offline i{color:var(--warn)}.impact-save-state.error i{color:var(--bad)}
.impact-section-label{margin:20px 0 10px;font-size:.72rem;font-weight:1000;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.impact-tag-groups{display:grid;gap:12px}.impact-tag-group{border:1px solid var(--line);border-radius:18px;padding:12px;background:color-mix(in srgb,var(--panel2,var(--panel)) 42%,transparent)}.impact-tag-group-head{display:flex;align-items:center;gap:8px;margin-bottom:9px;font-size:.72rem;letter-spacing:.11em;text-transform:uppercase;font-weight:1000;color:var(--muted)}.impact-tag-group-head i{color:var(--module-trident,var(--accent))}.impact-tag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:8px}
.impact-tag{cursor:pointer;position:relative;min-width:0;touch-action:manipulation}.impact-tag input{position:absolute;opacity:0;pointer-events:none}.impact-tag span{position:relative;display:flex;align-items:center;gap:9px;min-height:58px;width:100%;border:1px solid var(--line);border-radius:15px;padding:10px 38px 10px 12px;font-size:.88rem;font-weight:900;line-height:1.15;transition:.14s transform,.14s border-color,.14s background,.14s box-shadow;background:color-mix(in srgb,var(--panel,#111827) 96%,white 4%);user-select:none}.impact-tag span>i{font-size:1rem;color:var(--muted);min-width:18px;text-align:center;transition:.14s color,.14s transform}.impact-tag span:after{content:"✓";position:absolute;right:10px;top:50%;transform:translateY(-50%) scale(.55);opacity:0;width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:var(--module-trident,var(--accent));color:var(--solid-text,#fff);font-weight:1000;transition:.14s opacity,.14s transform}.impact-tag:active span{transform:scale(.975)}.impact-tag input:checked+span{border-color:var(--module-trident,var(--accent,#7c8cff));background:linear-gradient(135deg,color-mix(in srgb,var(--module-trident,var(--accent)) 22%,var(--panel,#111827)),color-mix(in srgb,var(--module-trident,var(--accent)) 8%,var(--panel,#111827)));box-shadow:0 0 0 2px var(--impact-glow),0 10px 24px rgba(0,0,0,.11);animation:impact-pop .18s ease-out}.impact-tag input:checked+span>i{color:var(--module-trident,var(--accent));transform:scale(1.08)}.impact-tag input:checked+span:after{opacity:1;transform:translateY(-50%) scale(1)}.impact-tag.danger input:checked+span{border-color:var(--bad);background:linear-gradient(135deg,color-mix(in srgb,var(--bad) 20%,var(--panel,#111827)),color-mix(in srgb,var(--bad) 7%,var(--panel,#111827)));box-shadow:0 0 0 2px color-mix(in srgb,var(--bad) 28%,transparent)}.impact-tag.danger input:checked+span>i{color:var(--bad)}.impact-tag.danger input:checked+span:after{background:var(--bad)}
@keyframes impact-pop{0%{transform:scale(.97)}70%{transform:scale(1.018)}100%{transform:scale(1)}}
.impact-sliders{display:grid;grid-template-columns:1fr;gap:12px}.impact-slider{--slider-color:var(--module-trident,var(--accent,#7c8cff));border:1px solid var(--line);border-radius:18px;padding:14px;background:linear-gradient(145deg,color-mix(in srgb,var(--panel2,var(--panel)) 52%,transparent),transparent);overflow:hidden;transition:.16s border-color,.16s box-shadow,.16s opacity}.impact-slider.score{--slider-color:var(--module-trident,var(--accent,#0b79b7))}.impact-slider:not(.inactive){border-color:color-mix(in srgb,var(--slider-color) 52%,var(--line));box-shadow:0 0 0 1px color-mix(in srgb,var(--slider-color) 16%,transparent)}.impact-slider-top{display:flex;justify-content:space-between;gap:10px;align-items:center}.impact-slider-title{display:flex;align-items:center;gap:8px;font-weight:900;font-size:.9rem}.impact-slider-title i{color:var(--slider-color);font-size:1rem}.impact-rating-actions{display:flex;align-items:center;gap:6px}.impact-value{display:inline-flex;align-items:center;justify-content:center;min-width:88px;min-height:38px;border-radius:13px;padding:6px 9px;background:color-mix(in srgb,var(--slider-color) 16%,var(--panel,#111827));border:1px solid color-mix(in srgb,var(--slider-color) 42%,var(--line));font-size:1.2rem;font-weight:1000;letter-spacing:-.03em;box-shadow:0 0 22px color-mix(in srgb,var(--slider-color) 12%,transparent)}.impact-slider.inactive .impact-value{font-size:.72rem;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--panel2,var(--panel)) 75%,transparent);border-color:var(--line);box-shadow:none}.impact-clear{border:1px solid var(--line);border-radius:11px;padding:7px 9px;background:transparent;color:var(--muted);font-size:.68rem;font-weight:950;text-transform:uppercase;letter-spacing:.05em;cursor:pointer;opacity:1;transition:.14s opacity,.14s border-color,.14s color}.impact-slider.inactive .impact-clear{opacity:.38;pointer-events:none}.impact-clear:hover{color:var(--text);border-color:var(--slider-color)}
.impact-block-control{position:relative;margin:15px 0 6px;padding:5px 0}.impact-blocks{display:grid;grid-template-columns:repeat(var(--blocks),1fr);gap:3px;height:34px;pointer-events:none}.impact-blocks span{border-radius:4px;background:color-mix(in srgb,var(--line) 72%,transparent);border:1px solid color-mix(in srgb,var(--line) 86%,transparent);transform:scaleY(.72);transition:.12s transform,.12s background,.12s box-shadow,.12s opacity}.impact-slider:not(.inactive) .impact-blocks span.on{background:var(--slider-color);border-color:var(--slider-color);transform:scaleY(1);box-shadow:0 0 10px color-mix(in srgb,var(--slider-color) 24%,transparent)}.impact-slider:not(.inactive) .impact-blocks span.current{transform:scaleY(1.18);box-shadow:0 0 0 2px color-mix(in srgb,var(--solid-text,#fff) 70%,transparent),0 0 16px color-mix(in srgb,var(--slider-color) 36%,transparent)}.impact-slider.inactive .impact-blocks span{opacity:.52}.impact-block-control input[type=range]{position:absolute;inset:-8px 0;width:100%;height:54px;margin:0;opacity:0;cursor:pointer;touch-action:pan-y}.impact-block-control:focus-within .impact-blocks{outline:2px solid color-mix(in srgb,var(--slider-color) 65%,transparent);outline-offset:4px;border-radius:6px}.impact-slider-scale{display:flex;justify-content:space-between;font-size:.65rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.impact-rating-help{margin-top:8px;font-size:.68rem;color:var(--muted);line-height:1.35}.impact-slider.inactive .impact-rating-help:before{content:"Tap or drag to rate · ";font-weight:900;color:var(--text)}
.impact-note textarea{width:100%;min-height:96px;border-radius:15px;resize:vertical;font-size:1rem;padding:12px}.impact-auto-note{margin-top:10px;font-size:.76rem;color:var(--muted);display:flex;align-items:center;gap:7px}

.impact-section-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:20px 0 10px}.impact-section-row .impact-section-label{margin:0}.impact-advanced-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none;color:var(--muted);font-size:.72rem;font-weight:950;letter-spacing:.06em;text-transform:uppercase}.impact-advanced-toggle input{position:absolute;opacity:0;pointer-events:none}.impact-toggle-track{width:46px;height:26px;padding:3px;border-radius:999px;border:1px solid var(--line);background:color-mix(in srgb,var(--panel2,var(--panel)) 75%,transparent);transition:.16s background,.16s border-color,.16s box-shadow}.impact-toggle-knob{display:block;width:18px;height:18px;border-radius:50%;background:var(--muted);transition:.16s transform,.16s background}.impact-advanced-toggle input:checked+.impact-toggle-track{border-color:var(--module-trident,var(--accent));background:color-mix(in srgb,var(--module-trident,var(--accent)) 18%,var(--panel));box-shadow:0 0 0 2px var(--impact-glow)}.impact-advanced-toggle input:checked+.impact-toggle-track .impact-toggle-knob{transform:translateX(20px);background:var(--module-trident,var(--accent))}.impact-advanced-hint{margin:-3px 0 10px;color:var(--muted);font-size:.74rem;line-height:1.4}.impact-advanced-hint[hidden]{display:none!important}
.impact-tag-weight{display:none;position:absolute;right:38px;top:7px;min-width:24px;height:22px;padding:0 6px;border-radius:999px;align-items:center;justify-content:center;font-style:normal;font-size:.63rem;font-weight:1000;background:var(--module-trident,var(--accent));color:var(--solid-text,#fff);box-shadow:0 4px 12px rgba(0,0,0,.16)}.impact-tag-weight.unweighted{background:var(--muted);color:var(--panel,#111827)}.impact-shell.advanced-mode .impact-tag input:checked+span .impact-tag-weight:not([hidden]){display:inline-flex}

.impact-consensus-title{margin:6px 0 0;font-size:1.55rem;letter-spacing:-.025em}
.impact-consensus-summary{display:grid;grid-template-columns:minmax(150px,.8fr) minmax(0,1.2fr);gap:10px;margin-top:14px}
.impact-consensus-score,.impact-stat{border:1px solid var(--line);border-radius:16px;background:color-mix(in srgb,var(--panel2,var(--panel)) 58%,transparent)}
.impact-consensus-score{display:flex;flex-direction:column;justify-content:center;align-items:flex-start;padding:14px;min-height:118px}
.impact-consensus-score>span,.impact-stat>span{font-size:.66rem;font-weight:950;text-transform:uppercase;letter-spacing:.09em;color:var(--muted)}
.impact-consensus-score strong{display:block;margin-top:4px;font-size:2rem;line-height:1;font-weight:1000;letter-spacing:-.04em;color:var(--text)}
.impact-consensus-score small,.impact-stat small{display:block;margin-top:5px;color:var(--muted);font-size:.68rem;line-height:1.25}
.impact-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.impact-stat{display:flex;flex-direction:column;justify-content:center;padding:12px;min-width:0}
.impact-stat b{display:block;margin-top:4px;font-size:1.55rem;line-height:1;font-weight:1000}
.impact-consensus-label{margin-top:18px}
.impact-consensus-tags{display:flex;flex-wrap:wrap;gap:7px}
.impact-consensus-tag{display:inline-flex;align-items:center;gap:7px;max-width:100%;border:1px solid var(--line);border-radius:999px;padding:7px 8px 7px 10px;background:color-mix(in srgb,var(--panel2,var(--panel)) 62%,transparent);font-size:.76rem;font-weight:850;line-height:1.1}
.impact-consensus-tag>i{color:var(--module-trident,var(--accent));flex:0 0 auto}
.impact-consensus-tag>span{min-width:0;overflow-wrap:anywhere}
.impact-consensus-tag>b{display:inline-grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:color-mix(in srgb,var(--module-trident,var(--accent)) 17%,var(--panel,#111827));color:var(--text);font-size:.68rem;font-weight:1000}
.impact-consensus-empty{margin:0;color:var(--muted);font-size:.82rem;line-height:1.45}
.impact-consensus-note{display:flex;gap:10px;align-items:flex-start;margin-top:16px;padding:12px;border:1px solid var(--line);border-radius:15px;background:color-mix(in srgb,var(--panel2,var(--panel)) 55%,transparent)}
.impact-consensus-note>i{color:var(--module-trident,var(--accent));margin-top:2px}.impact-consensus-note div{display:grid;gap:2px}.impact-consensus-note b{font-size:.78rem}.impact-consensus-note span{color:var(--muted);font-size:.72rem;line-height:1.4}

.impact-weight-modal[hidden]{display:none!important}
.impact-weight-modal{
  position:fixed!important;
  left:var(--impact-vv-left,0px)!important;
  top:var(--impact-vv-top,0px)!important;
  right:auto!important;
  bottom:auto!important;
  z-index:2147483000!important;
  display:grid!important;
  place-items:center!important;
  width:var(--impact-vv-width,100vw)!important;
  max-width:none!important;
  height:var(--impact-vv-height,100dvh)!important;
  max-height:none!important;
  margin:0!important;
  padding:12px!important;
  box-sizing:border-box!important;
  overflow:auto!important;
  background:rgba(0,0,0,.82)!important;
  backdrop-filter:none!important;
}
.impact-weight-card{
  position:relative!important;
  width:min(440px,100%)!important;
  max-width:440px!important;
  min-width:0!important;
  max-height:calc(var(--impact-vv-height,100dvh) - 24px)!important;
  margin:auto!important;
  padding:16px!important;
  box-sizing:border-box!important;
  overflow-x:hidden!important;
  overflow-y:auto!important;
  border:1px solid var(--line)!important;
  border-radius:22px!important;
  background:var(--panel,#111827)!important;
  color:var(--text)!important;
  box-shadow:0 28px 90px rgba(0,0,0,.5)!important;
}
.impact-weight-card *{box-sizing:border-box;min-width:0}
.impact-weight-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.impact-weight-head>div{flex:1 1 auto;min-width:0}
.impact-weight-head h3{margin:4px 0 0;font-size:1.4rem;line-height:1.08;overflow-wrap:anywhere}
.impact-weight-cancel{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border:1px solid var(--line);border-radius:13px;background:transparent;color:var(--muted);padding:0;font-size:1rem;font-weight:1000;cursor:pointer}
.impact-weight-copy{display:none!important}
.impact-weight-slider{--slider-color:var(--module-trident,var(--accent,#0b79b7));width:100%!important;max-width:100%!important;margin-top:14px!important}
.impact-weight-slider .impact-slider-top{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important;flex-wrap:wrap!important}
.impact-weight-slider .impact-slider-title{flex:1 1 130px!important}
.impact-weight-slider .impact-rating-actions{display:flex!important;align-items:center!important;gap:6px!important;margin-left:auto!important;width:auto!important}
.impact-weight-slider .impact-value{min-width:74px!important}
.impact-weight-slider .impact-block-control,.impact-weight-slider .impact-blocks{width:100%!important;max-width:100%!important;min-width:0!important}
.impact-weight-slider .impact-blocks{grid-template-columns:repeat(5,minmax(0,1fr))!important;height:38px!important;gap:3px!important}
.impact-weight-slider .impact-blocks span{min-width:0!important}
.impact-weight-slider input[type=range]{width:100%!important;max-width:100%!important;min-width:0!important}
.impact-weight-remove{width:100%;margin-top:10px;border:1px solid color-mix(in srgb,var(--bad) 45%,var(--line));border-radius:14px;padding:11px;background:color-mix(in srgb,var(--bad) 8%,transparent);color:var(--bad);font-weight:950;cursor:pointer}.impact-weight-remove[hidden]{display:none!important}
.impact-tag-weight.unweighted{display:none!important}
body.impact-modal-open{overflow:hidden!important}
@media(max-width:900px){
  .impact-main,.impact-alliance-grid{grid-template-columns:1fr}
  .impact-robots{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:640px){
  .impact-hero{padding:16px;border-radius:19px}
  .impact-selectors,.impact-sliders{grid-template-columns:1fr}
  .impact-alliance{padding:10px}
  .impact-robots{gap:7px}
  .impact-robot{padding:11px;height:174px;min-height:174px;border-radius:16px}
  .impact-meta{min-height:78px;padding-top:8px}
  .impact-team{font-size:1.5rem}
  .impact-mini{font-size:.6rem;padding:4px 5px}
  .impact-editor,.impact-consensus{padding:14px;border-radius:18px}
  .impact-editor-head{align-items:flex-start}
  .impact-editor-head>.pill{display:none}
  .impact-save-state .pending{width:100%;margin-left:24px}
  .impact-tag-group{padding:10px}
  .impact-tag-grid{grid-template-columns:1fr 1fr;gap:7px}
  .impact-tag span{min-height:62px;padding:9px 33px 9px 10px;font-size:.82rem}
  .impact-tag span>i{font-size:.95rem}
  .impact-slider{padding:13px}
  .impact-blocks{height:38px;gap:2px}
  .impact-block-control input[type=range]{height:60px;inset:-10px 0}
  .impact-value{min-width:82px}
  .impact-note textarea{min-height:90px}
  .impact-section-row{align-items:center}
  .impact-advanced-toggle{font-size:.64rem}
  .impact-consensus-title{font-size:1.4rem}
  .impact-consensus-summary{grid-template-columns:1fr;gap:8px}
  .impact-consensus-score{min-height:0;padding:12px;display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;column-gap:12px;align-items:center}
  .impact-consensus-score>span{grid-column:1;grid-row:1}.impact-consensus-score>strong{grid-column:2;grid-row:1 / span 2;margin:0;font-size:1.8rem}.impact-consensus-score>small{grid-column:1;grid-row:2;margin-top:3px}
  .impact-stat-grid{grid-template-columns:1fr 1fr;gap:8px}
  .impact-stat{padding:11px}.impact-stat b{font-size:1.35rem}
  .impact-consensus-tags{display:grid;grid-template-columns:1fr;gap:7px}
  .impact-consensus-tag{width:100%;border-radius:13px;padding:9px 9px 9px 11px;font-size:.78rem}
  .impact-consensus-tag>b{margin-left:auto}
  .impact-consensus-note{padding:11px;margin-top:14px}
}
@media(max-width:390px){
  .impact-tag-grid{grid-template-columns:1fr}
  .impact-robots{grid-template-columns:repeat(3,minmax(0,1fr))}
  .impact-robot{padding:9px;height:176px;min-height:176px}
  .impact-team{font-size:1.32rem}
  .impact-mini{font-size:.57rem;padding:4px}
}
@media(max-width:640px){
  .impact-weight-modal{padding:12px!important}
  .impact-weight-card{width:100%!important;max-width:420px!important;padding:14px!important;border-radius:20px!important}
  .impact-weight-head h3{font-size:1.3rem!important}
  .impact-weight-slider{padding:12px!important}
  .impact-weight-slider .impact-slider-top{flex-wrap:wrap!important}
  .impact-weight-slider .impact-rating-actions{margin-left:0!important}
  .impact-robot.active:after{
    content:""!important;
    width:10px!important;height:10px!important;min-width:10px!important;
    padding:0!important;right:10px!important;top:10px!important;border-radius:50%!important;
    background:var(--module-trident,var(--accent,#7c8cff))!important;
    box-shadow:0 0 0 3px color-mix(in srgb,var(--module-trident,var(--accent,#7c8cff)) 18%,transparent),0 0 12px var(--impact-glow)!important;
    color:transparent!important;font-size:0!important;line-height:0!important;letter-spacing:0!important;
  }
}
@media(prefers-reduced-motion:reduce){.impact-robot,.impact-tag span,.impact-tag span>i,.impact-tag span:after{transition:none}.impact-tag input:checked+span{animation:none}}
</style>
<div class="impact-shell">
  <?=tag_unified_tabs_html('match',$eventId,$selectedTeam)?>
  <section class="impact-hero">
    <div class="impact-kicker"><i class="fa-solid fa-satellite-dish"></i> TRIDENT · HUMAN SIGNAL</div>
    <h1 class="impact-title">Tag <span>Scouting</span></h1>
    <p class="impact-sub">Watch what matters. Tag standout behavior, estimate scoring contribution when you can, and move on. Each robot is saved independently on this device and synced to Neptune in the background.</p>
    <div class="impact-selectors">
      <label>Event
        <select id="impactEvent">
          <?=neptune_event_options_html($events,$eventId)?>
        </select>
      </label>
      <label>Match
        <select id="impactMatch" <?=$eventId?'':'disabled'?>>
          <?php foreach($matches as $m):?><option value="<?=(int)$m['id']?>" <?=$matchId===(int)$m['id']?'selected':''?>><?=e(impact_match_label($m))?><?=!empty($m['field_id'])?' · Field '.e($m['field_id']):''?></option><?php endforeach;?>
        </select>
      </label>
    </div>
  </section>

  <?php if($message):?><div class="notice good" style="margin-top:14px"><i class="fa-solid fa-circle-check"></i> <?=e($message)?></div><?php endif;?>
  <?php if($error):?><div class="notice bad" style="margin-top:14px"><i class="fa-solid fa-triangle-exclamation"></i> <?=e($error)?></div><?php endif;?>

  <?php if(!$events):?>
    <div class="card impact-empty" style="margin-top:16px"><i class="fa-solid fa-calendar-xmark fa-2x"></i><h2>No usable event schedules found</h2><p>Tag Scouting shows events that have both matches and assigned match robots.</p></div>
  <?php elseif(!$robots):?>
    <div class="card impact-empty" style="margin-top:16px"><i class="fa-solid fa-robot fa-2x"></i><h2>No robots in this match</h2><p>Refresh the event schedule so Neptune can load the six match robots.</p></div>
  <?php else:?>
    <div class="impact-alliance-grid">
      <?php foreach(['Red','Blue'] as $side):?>
      <section class="impact-alliance <?=strtolower($side)?>">
        <div class="impact-alliance-head"><b><?=$side?> Alliance</b><span class="pill"><?=count(array_filter($robots,fn($r)=>$r['alliance']===$side))?> robots</span></div>
        <div class="impact-robots">
          <?php foreach($robots as $r): if($r['alliance']!==$side)continue; $team=(int)$r['frc_team_number'];$sum=$summaryByTeam[$team]??null; ?>
            <button type="button" class="impact-robot <?=$selectedTeam===$team?'active':''?>" data-impact-team="<?=$team?>">
              <div class="impact-team">#<?=$team?></div>
              <div class="impact-nick"><?=e($r['nickname'] ?: 'Robot '.$team)?></div>
              <div class="impact-meta">
                <span class="impact-mini">Station <?=e($r['station'])?></span>
                <span class="impact-mini" data-card-observers><i class="fa-solid fa-eye"></i> <span data-card-observer-count><?=e($sum['observers']??0)?></span></span>
                <span class="impact-mini" data-card-score <?=(!$sum || (int)($sum['scoring_ratings']??0)<1)?'hidden':''?>><i class="fa-solid fa-chart-pie"></i> <span data-card-score-value><?=e(($sum && (int)($sum['scoring_ratings']??0)>0) ? $sum['avg_scoring'].'%' : '')?></span></span>
                <span class="impact-mini local"><i class="fa-solid fa-mobile-screen"></i> <span data-local-label>Saved</span></span>
              </div>
            </button>
          <?php endforeach;?>
        </div>
      </section>
      <?php endforeach;?>
    </div>

    <div class="impact-main">
      <section class="impact-editor">
        <div class="impact-editor-head">
          <div><div class="impact-kicker">YOUR OBSERVATION</div><h2 id="impactRobotTitle">#<?=$selectedTeam?> <?=e($selectedRobot['nickname'] ?: '')?></h2><div class="impact-station" id="impactRobotStation"><?=e($selectedRobot['alliance'])?> Station <?=e($selectedRobot['station'])?><?= $match ? ' · '.e(impact_match_label($match)) : '' ?></div></div>
          <span class="pill" id="impactObservationPill"><i class="fa-solid fa-mobile-screen"></i> Local-first</span>
        </div>

        <div class="impact-save-state local" id="impactSaveState"><i class="fa-solid fa-circle"></i><b id="impactSaveText">Opening device cache…</b><span class="pending" id="impactPendingText"></span></div>

        <form method="post" id="impactForm" autocomplete="off">
          <input type="hidden" name="csrf" id="impactCsrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="event_id" value="<?=$eventId?>">
          <input type="hidden" name="match_id" value="<?=$matchId?>">
          <input type="hidden" name="frc_team_number" id="impactTeamInput" value="<?=$selectedTeam?>">

          <div class="impact-section-row">
            <div class="impact-section-label">Tap what stood out</div>
            <label class="impact-advanced-toggle" title="Remembered on this device">
              <input type="checkbox" id="impactAdvancedMode">
              <span class="impact-toggle-track"><span class="impact-toggle-knob"></span></span>
              <span>Advanced</span>
            </label>
          </div>
          <div class="impact-advanced-hint" id="impactAdvancedHint" hidden>Advanced Mode: tapping a tag opens a 1–5 weight picker. Leave it unweighted when the tag itself is enough.</div>
          <div class="impact-tag-groups">
            <?php foreach($tagGroups as $group):?>
              <section class="impact-tag-group">
                <div class="impact-tag-group-head"><i class="<?=e($group['icon'])?>"></i> <?=e($group['label'])?></div>
                <div class="impact-tag-grid">
                  <?php foreach($group['tags'] as $key): $tag=$tagCatalog[$key];?>
                    <label class="impact-tag <?=in_array($key,$negativeTags,true)?'danger':''?>"><input type="checkbox" name="tags[]" value="<?=e($key)?>"><span><i class="<?=e($tag[1])?>"></i><?=e($tag[0])?><em class="impact-tag-weight" data-tag-weight-badge="<?=e($key)?>" hidden></em></span></label>
                  <?php endforeach;?>
                </div>
              </section>
            <?php endforeach;?>
          </div>

          <div class="impact-section-label">Estimated scoring share · optional</div>
          <div class="impact-sliders">
            <div class="impact-slider score inactive" data-rating="scoring_contribution" data-block-count="21" data-active="0"><div class="impact-slider-top"><span class="impact-slider-title"><i class="fa-solid fa-chart-pie"></i> Scoring Contribution</span><span class="impact-rating-actions"><span class="impact-value" data-output="scoring_contribution">Not rated</span><button type="button" class="impact-clear" data-clear-rating="scoring_contribution">Clear</button></span></div><div class="impact-block-control"><div class="impact-blocks" aria-hidden="true"></div><input type="range" name="scoring_contribution" min="0" max="100" step="1" value="0" aria-label="Scoring Contribution"></div><div class="impact-slider-scale"><span>0%</span><span>50%</span><span>100%</span></div><div class="impact-rating-help">Estimate this robot's share of its alliance scoring. Leave it unrated if you were not confident.</div></div>
          </div>

          <div class="impact-section-label">Optional note</div>
          <div class="impact-note"><textarea name="note" maxlength="500" placeholder="Only add a note if the tags don't tell the story."></textarea></div>
          <div class="impact-auto-note"><i class="fa-solid fa-cloud-arrow-up"></i> No submit button. Changes are saved on this device immediately and synced automatically.</div>
        </form>
      </section>

      <aside class="impact-consensus">
        <div class="impact-kicker">NEPTUNE CONSENSUS</div>
        <h2 class="impact-consensus-title">What observers see</h2>

        <div class="impact-consensus-summary">
          <div class="impact-consensus-score" id="impactScoreRing" style="--score:<?=max(0,min(100,(float)($robotSummary['avg_scoring']??0)))?>">
            <span>Average scoring share</span>
            <strong id="consensusScoringRing"><?=e(($robotSummary['scoring_ratings']??0) ? $robotSummary['avg_scoring'].'%' : '—')?></strong>
            <small id="consensusScoringRingCount"><?=e(($robotSummary['scoring_ratings']??0) ? $robotSummary['scoring_ratings'].' rating'.($robotSummary['scoring_ratings']==1?'':'s') : 'Not rated yet')?></small>
          </div>
          <div class="impact-stat-grid">
            <div class="impact-stat"><span>Observers</span><b id="consensusObservers"><?=e($robotSummary['observers'])?></b><small>saved observations</small></div>
            <div class="impact-stat"><span>Score estimates</span><b id="consensusScoringCount"><?=e((int)($robotSummary['scoring_ratings']??0))?></b><small>independent ratings</small></div>
          </div>
        </div>

        <div class="impact-section-label impact-consensus-label">Consensus tags</div>
        <div id="impactConsensusTags">
          <?php if(!$robotTagConsensus):?><p class="impact-consensus-empty">No tags yet. Your observation can be the first signal.</p><?php else:?><div class="impact-consensus-tags"><?php foreach(array_slice($robotTagConsensus,0,10,true) as $key=>$count):?><span class="impact-consensus-tag"><i class="<?=e($tagCatalog[$key][1])?>"></i><span><?=e($tagCatalog[$key][0])?></span><b><?=$count?></b></span><?php endforeach;?></div><?php endif;?>
        </div>
        <div class="impact-consensus-note"><i class="fa-solid fa-people-group"></i><div><b>Independent by design</b><span>Each scout keeps their own observation. Neptune averages scoring-share estimates and counts tag agreement.</span></div></div>
      </aside>
    </div>
  <?php endif;?>

    <div class="impact-weight-modal" id="impactWeightModal" role="dialog" aria-modal="true" aria-labelledby="impactWeightTitle" aria-hidden="true" hidden>
      <section class="impact-weight-card">
        <div class="impact-weight-head">
          <div><div class="impact-kicker">ADVANCED TAG WEIGHT</div><h3 id="impactWeightTitle">Tag</h3></div>
          <button type="button" class="impact-weight-cancel" data-weight-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="impact-slider score impact-weight-slider inactive" id="impactWeightSlider" data-active="0">
          <div class="impact-slider-top">
            <span class="impact-slider-title"><i class="fa-solid fa-hashtag"></i> Tag Weight</span>
            <span class="impact-rating-actions">
              <span class="impact-value" id="impactWeightValue">Not rated</span>
              <button type="button" class="impact-clear" id="impactWeightClear">Clear</button>
            </span>
          </div>
          <div class="impact-block-control">
            <div class="impact-blocks" id="impactWeightBlocks" style="--blocks:5" aria-hidden="true">
              <?php for($weight=1;$weight<=5;$weight++):?><span></span><?php endfor;?>
            </div>
            <input type="range" id="impactWeightRange" min="1" max="5" step="1" value="1" aria-label="Tag weight">
          </div>
          <div class="impact-slider-scale"><span>1</span><span>5</span></div>
          <div class="impact-rating-help">1 = slight · 5 = exceptionally strong</div>
        </div>
        <button type="button" class="impact-weight-remove" id="impactWeightRemove" hidden><i class="fa-solid fa-trash-can"></i> Remove this tag</button>
      </section>
    </div>
</div>
<?php if($robots):?>
<script>
(() => {
  'use strict';

  const CONFIG = <?=json_encode([
      'orgId'=>$org,
      'userId'=>$userId,
      'eventId'=>$eventId,
      'matchId'=>$matchId,
      'selectedTeam'=>$selectedTeam,
      'matchLabel'=>$match ? impact_match_label($match) : '',
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const ROBOTS = <?=json_encode($robotsForJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const SERVER_STATES = <?=json_encode($mineByTeam, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const SUMMARIES = <?=json_encode($summaryForJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const TAG_COUNTS = <?=json_encode($tagConsensusForJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const TAG_CATALOG = <?=json_encode($tagCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;
  const BINARY_TAGS = new Set(['no_auton','no_defense','failed_endgame']);

  const DB_NAME = 'neptune-impact-scouting';
  const DB_VERSION = 4;
  const STORE = 'impact_states';
  const PREF_STORE = 'impact_preferences';
  const form = document.getElementById('impactForm');
  const eventSelect = document.getElementById('impactEvent');
  const matchSelect = document.getElementById('impactMatch');
  const teamInput = document.getElementById('impactTeamInput');
  const titleEl = document.getElementById('impactRobotTitle');
  const stationEl = document.getElementById('impactRobotStation');
  const statusEl = document.getElementById('impactSaveState');
  const statusText = document.getElementById('impactSaveText');
  const pendingText = document.getElementById('impactPendingText');
  const robotButtons = [...document.querySelectorAll('[data-impact-team]')];
  const sliderInputs = [...form.querySelectorAll('.impact-slider input[type="range"]')];
  const clearRatingButtons = [...form.querySelectorAll('[data-clear-rating]')];
  const tagInputs = [...form.querySelectorAll('input[name="tags[]"]')];
  const noteInput = form.querySelector('textarea[name="note"]');
  const shell = document.querySelector('.impact-shell');
  const advancedToggle = document.getElementById('impactAdvancedMode');
  const advancedHint = document.getElementById('impactAdvancedHint');
  const weightModal = document.getElementById('impactWeightModal');
  // Keep the full-screen overlay outside Neptune layout containers.
  // Chrome mobile can expose a visual viewport that is smaller/offset from the CSS layout viewport,
  // so size the overlay to the actual visible screen in pixels instead of relying on 100vh/100dvh.
  if (weightModal && weightModal.parentElement !== document.body) document.body.appendChild(weightModal);
  function syncWeightModalViewport() {
    if (!weightModal || weightModal.hidden) return;
    const vv = window.visualViewport;
    const width = vv ? vv.width : window.innerWidth;
    const height = vv ? vv.height : window.innerHeight;
    const left = vv ? vv.offsetLeft : 0;
    const top = vv ? vv.offsetTop : 0;
    weightModal.style.setProperty('--impact-vv-width', Math.max(1, Math.round(width)) + 'px');
    weightModal.style.setProperty('--impact-vv-height', Math.max(1, Math.round(height)) + 'px');
    weightModal.style.setProperty('--impact-vv-left', Math.round(left) + 'px');
    weightModal.style.setProperty('--impact-vv-top', Math.round(top) + 'px');
  }
  window.visualViewport?.addEventListener('resize', syncWeightModalViewport);
  window.visualViewport?.addEventListener('scroll', syncWeightModalViewport);
  window.addEventListener('resize', syncWeightModalViewport);
  const weightTitle = document.getElementById('impactWeightTitle');
  const weightRemove = document.getElementById('impactWeightRemove');
  const weightSlider = document.getElementById('impactWeightSlider');
  const weightRange = document.getElementById('impactWeightRange');
  const weightValue = document.getElementById('impactWeightValue');
  const weightClear = document.getElementById('impactWeightClear');
  const weightBlocks = [...document.querySelectorAll('#impactWeightBlocks span')];
  const weightCloseButtons = [...document.querySelectorAll('[data-weight-close]')];
  let currentTeam = Number(CONFIG.selectedTeam || robotButtons[0]?.dataset.impactTeam || 0);
  let selectionToken = 0;
  let syncTimer = 0;
  let noteTimer = 0;
  let db = null;
  let cacheAvailable = true;
  let loadedFingerprint = '';
  let advancedMode = false;
  let activeWeightInput = null;
  let tagWeights = {};
  const ADVANCED_PREF_KEY = ['advanced-mode', CONFIG.orgId, CONFIG.userId].join(':');

  function recordKey(team) {
    return [CONFIG.orgId, CONFIG.eventId, CONFIG.matchId, CONFIG.userId, Number(team)].join(':');
  }

  function defaultState(team) {
    return {
      key: recordKey(team),
      orgId: CONFIG.orgId,
      eventId: CONFIG.eventId,
      matchId: CONFIG.matchId,
      userId: CONFIG.userId,
      team: Number(team),
      tags: [],
      tag_weights: {},
      scoring_contribution: -1,
      note: '',
      dirty: false,
      touched: false,
      updatedAt: 0,
      serverUpdatedAt: null
    };
  }

  function fromServer(team) {
    const s = SERVER_STATES[String(team)] || SERVER_STATES[Number(team)];
    if (!s) return null;
    return {
      ...defaultState(team),
      tags: Array.isArray(s.tags) ? s.tags : [],
      tag_weights: s.tag_weights && typeof s.tag_weights === 'object' ? s.tag_weights : {},
      scoring_contribution: Number(s.scoring_contribution ?? -1),
      note: String(s.note ?? ''),
      dirty: false,
      touched: true,
      serverUpdatedAt: s.serverUpdatedAt || null
    };
  }

  function openDb() {
    return new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) return reject(new Error('IndexedDB is not available.'));
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const d = req.result;
        if (!d.objectStoreNames.contains(STORE)) {
          const store = d.createObjectStore(STORE, { keyPath: 'key' });
          store.createIndex('dirty', 'dirty', { unique: false });
          store.createIndex('updatedAt', 'updatedAt', { unique: false });
        }
        if (!d.objectStoreNames.contains(PREF_STORE)) {
          d.createObjectStore(PREF_STORE, { keyPath: 'key' });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error('Could not open IndexedDB.'));
    });
  }

  function idbGet(key) {
    if (!db) return Promise.resolve(null);
    return new Promise((resolve, reject) => {
      const req = db.transaction(STORE, 'readonly').objectStore(STORE).get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  function idbPut(record) {
    if (!db) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(record);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error || new Error('Local save was aborted.'));
    });
  }

  function idbAll() {
    if (!db) return Promise.resolve([]);
    return new Promise((resolve, reject) => {
      const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  }

  function idbPrefGet(key) {
    if (!db || !db.objectStoreNames.contains(PREF_STORE)) return Promise.resolve(null);
    return new Promise((resolve, reject) => {
      const req = db.transaction(PREF_STORE, 'readonly').objectStore(PREF_STORE).get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  function idbPrefPut(key, value) {
    if (!db || !db.objectStoreNames.contains(PREF_STORE)) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(PREF_STORE, 'readwrite');
      tx.objectStore(PREF_STORE).put({ key, value, updatedAt: Date.now() });
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  function serverIsNewer(local, server) {
    if (!server?.serverUpdatedAt || !local?.serverUpdatedAt) return false;
    const a = Date.parse(String(server.serverUpdatedAt).replace(' ', 'T') + 'Z');
    const b = Date.parse(String(local.serverUpdatedAt).replace(' ', 'T') + 'Z');
    return Number.isFinite(a) && Number.isFinite(b) && a > b;
  }

  async function getState(team) {
    const server = fromServer(team);
    if (!db) return server || defaultState(team);
    let local = await idbGet(recordKey(team));
    if (!local) {
      local = server || defaultState(team);
      if (server) await idbPut(local);
      return local;
    }
    if (!local.dirty && server && serverIsNewer(local, server)) {
      await idbPut(server);
      return server;
    }
    return { ...defaultState(team), ...local };
  }

  function ratingValue(name) {
    const input = form.elements[name];
    const card = input?.closest('.impact-slider');
    return card && card.dataset.active === '1' ? Number(input.value) : -1;
  }

  function normalizeTagWeight(value) {
    const n = Number(value);
    return Number.isInteger(n) && n >= 1 && n <= 5 ? n : -1;
  }

  function uiValues(team = currentTeam) {
    const tags = tagInputs.filter(x => x.checked).map(x => x.value);
    const weights = {};
    tags.forEach(tag => {
      if (BINARY_TAGS.has(tag)) return;
      const weight = normalizeTagWeight(tagWeights[tag]);
      if (weight >= 1) weights[tag] = weight;
    });
    return {
      tags,
      tag_weights: weights,
      scoring_contribution: ratingValue('scoring_contribution'),
      note: String(noteInput.value || '').slice(0, 500)
    };
  }

  function fingerprint(values) {
    const tags = [...(values.tags || [])].sort();
    const weights = {};
    tags.forEach(tag => {
      if (BINARY_TAGS.has(tag)) return;
      const weight = normalizeTagWeight(values.tag_weights?.[tag]);
      if (weight >= 1) weights[tag] = weight;
    });
    return JSON.stringify({
      tags,
      tag_weights: weights,
      scoring_contribution: Number(values.scoring_contribution ?? -1),
      note: String(values.note || '')
    });
  }

  function uiHasChanges() {
    return fingerprint(uiValues()) !== loadedFingerprint;
  }

  function readUi(team = currentTeam) {
    return {
      ...defaultState(team),
      ...uiValues(team),
      dirty: true,
      touched: true,
      updatedAt: Date.now()
    };
  }

  function ensureBlocks() {
    form.querySelectorAll('.impact-slider').forEach(card => {
      const host = card.querySelector('.impact-blocks');
      const count = Math.max(2, Number(card.dataset.blockCount || 11));
      host.style.setProperty('--blocks', String(count));
      if (!host.children.length) for (let i=0;i<count;i++) host.appendChild(document.createElement('span'));
    });
  }

  function setRatingState(name, value) {
    const input = form.elements[name];
    const card = input?.closest('.impact-slider');
    if (!input || !card) return;
    const active = Number(value) >= 0;
    card.dataset.active = active ? '1' : '0';
    card.classList.toggle('inactive', !active);
    if (active) input.value = String(Math.max(Number(input.min), Math.min(Number(input.max), Number(value))));
    else input.value = String(input.min || 0);
  }

  function paintSliderOutputs() {
    sliderInputs.forEach(input => {
      const card = input.closest('.impact-slider');
      const out = form.querySelector('[data-output="' + input.name + '"]');
      const active = card?.dataset.active === '1';
      const value = Number(input.value || 0);
      if (out) out.textContent = active ? (input.name === 'scoring_contribution' ? value + '%' : value + '/10') : 'Not rated';
      const blocks = [...(card?.querySelectorAll('.impact-blocks span') || [])];
      const min = Number(input.min || 0), max = Number(input.max || 10);
      const index = active && max > min ? Math.round(((value - min) / (max - min)) * Math.max(0, blocks.length - 1)) : -1;
      blocks.forEach((block, i) => {
        block.classList.toggle('on', active && i <= index);
        block.classList.toggle('current', active && i === index);
      });
    });
  }

  function paintTagWeights() {
    tagInputs.forEach(input => {
      const badge = document.querySelector('[data-tag-weight-badge="' + CSS.escape(input.value) + '"]');
      if (!badge) return;
      const weight = normalizeTagWeight(tagWeights[input.value]);
      badge.textContent = weight >= 1 ? String(weight) : '';
      badge.classList.remove('unweighted');
      badge.hidden = !(advancedMode && input.checked && !BINARY_TAGS.has(input.value) && weight >= 1);
    });
  }

  async function setAdvancedMode(enabled, persist = true) {
    advancedMode = !!enabled;
    if (advancedToggle) advancedToggle.checked = advancedMode;
    shell?.classList.toggle('advanced-mode', advancedMode);
    if (advancedHint) advancedHint.hidden = !advancedMode;
    paintTagWeights();
    if (persist) {
      try { await idbPrefPut(ADVANCED_PREF_KEY, advancedMode); }
      catch (_) { try { localStorage.setItem('neptune-' + ADVANCED_PREF_KEY, advancedMode ? '1' : '0'); } catch (_) {} }
    }
  }

  async function loadAdvancedMode() {
    let enabled = false;
    try {
      const pref = await idbPrefGet(ADVANCED_PREF_KEY);
      if (pref) enabled = !!pref.value;
      else {
        const fallback = localStorage.getItem('neptune-' + ADVANCED_PREF_KEY);
        enabled = fallback === '1';
      }
    } catch (_) {}
    await setAdvancedMode(enabled, false);
  }

  function paintWeightSlider(value) {
    const weight = normalizeTagWeight(value);
    const active = weight >= 1;
    if (weightSlider) {
      weightSlider.dataset.active = active ? '1' : '0';
      weightSlider.classList.toggle('inactive', !active);
    }
    if (weightRange) weightRange.value = String(active ? weight : 1);
    if (weightValue) weightValue.textContent = active ? String(weight) : 'Unweighted';
    weightBlocks.forEach((block, i) => {
      block.classList.toggle('on', active && i < weight);
      block.classList.toggle('current', active && i === weight - 1);
    });
  }

  function openWeightModal(input) {
    if (!input || !weightModal) return;
    activeWeightInput = input;
    const meta = TAG_CATALOG[input.value] || [input.value, ''];
    weightTitle.textContent = meta[0];
    paintWeightSlider(normalizeTagWeight(tagWeights[input.value]));
    weightRemove.hidden = !input.checked;
    weightModal.hidden = false;
    weightModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('impact-modal-open');
    syncWeightModalViewport();
    requestAnimationFrame(() => weightRange?.focus({preventScroll:true}));
  }

  function closeWeightModal() {
    if (!weightModal) return;
    weightModal.hidden = true;
    weightModal.setAttribute('aria-hidden', 'true');
    activeWeightInput = null;
    document.body.classList.remove('impact-modal-open');
    // VisualViewport geometry is modal-only. Removing it prevents Chrome Android
    // from keeping a document width based on the temporary fixed overlay.
    ['--impact-vv-width','--impact-vv-height','--impact-vv-left','--impact-vv-top']
      .forEach(name => weightModal.style.removeProperty(name));
    requestAnimationFrame(() => {
      document.documentElement.scrollLeft = 0;
      document.body.scrollLeft = 0;
      window.scrollTo(0, window.scrollY);
    });
  }

  function applyState(state) {
    tagWeights = {};
    const stateWeights = state.tag_weights && typeof state.tag_weights === 'object' ? state.tag_weights : {};
    tagInputs.forEach(input => {
      input.checked = state.tags.includes(input.value);
      if (input.checked && !BINARY_TAGS.has(input.value)) {
        const weight = normalizeTagWeight(stateWeights[input.value]);
        if (weight >= 1) tagWeights[input.value] = weight;
      }
    });
    setRatingState('scoring_contribution', Number(state.scoring_contribution ?? -1));
    noteInput.value = state.note || '';
    paintSliderOutputs();
    paintTagWeights();
    loadedFingerprint = fingerprint({ ...state, tag_weights: tagWeights });
  }

  function setStatus(kind, text, pending = null) {
    statusEl.classList.remove('synced','local','offline','error');
    statusEl.classList.add(kind);
    const icon = statusEl.querySelector('i');
    if (icon) icon.className = kind === 'synced' ? 'fa-solid fa-circle-check' : kind === 'error' ? 'fa-solid fa-triangle-exclamation' : kind === 'offline' ? 'fa-solid fa-wifi' : 'fa-solid fa-mobile-screen';
    statusText.textContent = text;
    if (pending !== null) pendingText.textContent = pending > 0 ? pending + ' change' + (pending === 1 ? '' : 's') + ' waiting' : '';
  }

  async function pendingCount() {
    if (!db) return 0;
    const all = await idbAll();
    return all.filter(r => r && r.dirty && Number(r.orgId) === Number(CONFIG.orgId) && Number(r.userId) === Number(CONFIG.userId)).length;
  }

  async function refreshStatus(fallbackKind = null, fallbackText = null) {
    const pending = await pendingCount().catch(() => 0);
    if (!cacheAvailable) return setStatus('error', 'Device cache unavailable', pending);
    if (pending > 0) {
      if (navigator.onLine === false) return setStatus('offline', 'Offline · saved on this device', pending);
      return setStatus(fallbackKind || 'local', fallbackText || 'Saved locally · syncing…', pending);
    }
    setStatus('synced', 'Synced with Neptune', 0);
  }

  async function saveCurrentLocal() {
    if (!currentTeam) return null;
    const previous = await getState(currentTeam).catch(() => defaultState(currentTeam));
    const next = { ...previous, ...readUi(currentTeam), serverUpdatedAt: previous.serverUpdatedAt || null };
    if (db) await idbPut(next);
    loadedFingerprint = fingerprint(next);
    markLocalCards();
    await refreshStatus('local','Saved locally · syncing…');
    return next;
  }

  function formDataFor(record) {
    const fd = new FormData();
    fd.append('impact_ajax','1');
    fd.append('csrf', document.getElementById('impactCsrf').value);
    fd.append('event_id', String(record.eventId));
    fd.append('match_id', String(record.matchId));
    fd.append('frc_team_number', String(record.team));
    fd.append('scoring_contribution', String(record.scoring_contribution));
    fd.append('note', record.note || '');
    fd.append('tag_weights_json', JSON.stringify(record.tag_weights || {}));
    (record.tags || []).forEach(tag => fd.append('tags[]', tag));
    return fd;
  }

  async function syncRecord(record) {
    if (!record?.dirty) return true;
    if (navigator.onLine === false) return false;
    try {
      const response = await fetch(location.pathname, {
        method: 'POST',
        body: formDataFor(record),
        credentials: 'same-origin',
        headers: { 'Accept':'application/json', 'X-Requested-With':'TagScouting' }
      });
      const type = response.headers.get('content-type') || '';
      if (!response.ok || !type.includes('application/json')) throw new Error('Neptune sync failed.');
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Neptune sync failed.');

      const latest = await idbGet(record.key);
      if (latest && Number(latest.updatedAt) === Number(record.updatedAt)) {
        latest.dirty = false;
        latest.serverUpdatedAt = payload.updated_at || latest.serverUpdatedAt || null;
        await idbPut(latest);
      }
      if (payload.summary) {
        SUMMARIES[String(record.team)] = payload.summary;
        renderRobotCardSummary(record.team);
      }
      if (payload.tag_counts) TAG_COUNTS[String(record.team)] = payload.tag_counts;
      if (Number(currentTeam) === Number(record.team)) renderConsensus(record.team);
      return true;
    } catch (err) {
      return false;
    }
  }

  async function syncPending() {
    if (!db) return;
    const all = await idbAll();
    const pending = all.filter(r => r && r.dirty && Number(r.orgId) === Number(CONFIG.orgId) && Number(r.userId) === Number(CONFIG.userId));
    if (!pending.length) return refreshStatus();
    if (navigator.onLine === false) return refreshStatus('offline','Offline · saved on this device');
    setStatus('local','Saved locally · syncing…', pending.length);
    let failed = false;
    for (const record of pending) if (!(await syncRecord(record))) failed = true;
    markLocalCards();
    if (failed) {
      const count = await pendingCount().catch(() => pending.length);
      return setStatus('offline','Connection unavailable · saved on this device',count);
    }
    await refreshStatus();
  }

  function scheduleSync(delay = 250) {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(() => syncPending(), delay);
  }

  async function saveAndSchedule(delay = 250) {
    const record = await saveCurrentLocal();
    if (!record) return;
    if (!db) {
      const ok = await syncRecord(record);
      setStatus(ok ? 'synced' : 'error', ok ? 'Synced with Neptune' : 'Device cache unavailable · sync failed', 0);
      return;
    }
    scheduleSync(delay);
  }

  function renderRobotCardSummary(team) {
    const s = SUMMARIES[String(team)] || SUMMARIES[Number(team)] || {observers:0,scoring_ratings:0,avg_scoring:null};
    const btn = robotButtons.find(button => Number(button.dataset.impactTeam) === Number(team));
    if (!btn) return;
    const observerCount = btn.querySelector('[data-card-observer-count]');
    if (observerCount) observerCount.textContent = String(Number(s.observers || 0));
    const scoreChip = btn.querySelector('[data-card-score]');
    const scoreValue = btn.querySelector('[data-card-score-value]');
    const scoringCount = Number(s.scoring_ratings || 0);
    if (scoreChip) scoreChip.hidden = scoringCount < 1;
    if (scoreValue) scoreValue.textContent = scoringCount > 0 && s.avg_scoring !== null ? String(s.avg_scoring) + '%' : '';
  }

  function renderConsensus(team) {
    const s = SUMMARIES[String(team)] || SUMMARIES[Number(team)] || {observers:0,scoring_ratings:0,avg_scoring:null};
    const observers = Number(s.observers || 0);
    const scoringCount = Number(s.scoring_ratings || 0);
    const plural = n => n + ' rating' + (n === 1 ? '' : 's');
    document.getElementById('consensusObservers').textContent = String(observers);
    document.getElementById('consensusScoringRing').textContent = scoringCount ? String(s.avg_scoring) + '%' : '—';
    document.getElementById('consensusScoringRingCount').textContent = scoringCount ? plural(scoringCount) : 'not rated';
    document.getElementById('consensusScoringCount').textContent = String(scoringCount);
    document.getElementById('impactScoreRing').style.setProperty('--score', scoringCount ? String(Math.max(0,Math.min(100,Number(s.avg_scoring || 0)))) : '0');

    const counts = TAG_COUNTS[String(team)] || TAG_COUNTS[Number(team)] || {};
    const entries = Object.entries(counts).sort((a,b)=>Number(b[1])-Number(a[1])).slice(0,10);
    const host = document.getElementById('impactConsensusTags');
    if (!entries.length) {
      host.innerHTML = '<p class="impact-consensus-empty">No tags yet. Your observation can be the first signal.</p>';
      return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'impact-consensus-tags';
    entries.forEach(([key,count]) => {
      if (!TAG_CATALOG[key]) return;
      const span = document.createElement('span');
      span.className = 'impact-consensus-tag';
      const icon = document.createElement('i');
      icon.className = TAG_CATALOG[key][1];
      const label = document.createElement('span'); label.textContent = TAG_CATALOG[key][0];
      span.append(icon, label);
      const b = document.createElement('b'); b.textContent = String(count); span.appendChild(b);
      wrap.appendChild(span);
    });
    host.replaceChildren(wrap);
  }

  async function selectRobot(team, savePrevious = true) {
    team = Number(team);
    if (!ROBOTS[String(team)] && !ROBOTS[team]) return;
    const token = ++selectionToken;
    if (savePrevious && currentTeam && currentTeam !== team && uiHasChanges()) {
      try { await saveCurrentLocal(); scheduleSync(0); } catch (_) {}
    }
    currentTeam = team;
    teamInput.value = String(team);
    robotButtons.forEach(btn => btn.classList.toggle('active', Number(btn.dataset.impactTeam) === team));
    const r = ROBOTS[String(team)] || ROBOTS[team];
    titleEl.textContent = '#' + team + ' ' + (r.nickname || '');
    stationEl.textContent = r.alliance + ' Station ' + r.station + (CONFIG.matchLabel ? ' · ' + CONFIG.matchLabel : '');
    renderRobotCardSummary(team);
    renderConsensus(team);
    const state = await getState(team);
    if (token !== selectionToken) return;
    applyState(state);
    await refreshStatus();
  }

  async function markLocalCards() {
    if (!db) return;
    for (const btn of robotButtons) {
      const state = await idbGet(recordKey(Number(btn.dataset.impactTeam))).catch(()=>null);
      const hasLocal = !!(state && state.touched);
      const isPending = !!(hasLocal && state.dirty);
      btn.classList.toggle('has-local', hasLocal);
      btn.classList.toggle('has-pending', isPending);
      const label = btn.querySelector('[data-local-label]');
      if (label) label.textContent = isPending ? 'Waiting' : 'Saved';
    }
  }

  async function cleanupOldCache() {
    if (!db) return;
    const cutoff = Date.now() - (30 * 24 * 60 * 60 * 1000);
    const all = await idbAll();
    const old = all.filter(r => !r.dirty && r.updatedAt && Number(r.updatedAt) < cutoff);
    if (!old.length) return;
    await new Promise((resolve,reject) => {
      const tx = db.transaction(STORE,'readwrite');
      const store = tx.objectStore(STORE);
      old.forEach(r => store.delete(r.key));
      tx.oncomplete=()=>resolve(); tx.onerror=()=>reject(tx.error);
    }).catch(()=>{});
  }

  eventSelect?.addEventListener('change', async () => {
    if (uiHasChanges()) { try { await saveCurrentLocal(); } catch (_) {} }
    scheduleSync(0);
    location.href='?event_id='+encodeURIComponent(eventSelect.value);
  });
  matchSelect?.addEventListener('change', async () => {
    if (uiHasChanges()) { try { await saveCurrentLocal(); } catch (_) {} }
    scheduleSync(0);
    location.href='?event_id='+encodeURIComponent(eventSelect.value)+'&match_id='+encodeURIComponent(matchSelect.value);
  });
  const tapFeedback = () => { try { if (navigator.vibrate) navigator.vibrate(8); } catch (_) {} };
  robotButtons.forEach(btn => btn.addEventListener('click', () => { tapFeedback(); selectRobot(Number(btn.dataset.impactTeam)); }));
  const exclusiveTagGroups = [
    ['no_auton', ['impressive_auton','consistent_auton','weak_auton']],
    ['no_defense', ['good_defense','elite_defense','counter_defense']],
    ['failed_endgame', ['strong_endgame','reliable_endgame']]
  ];
  function clearTag(tag) {
    const input = tagInputs.find(item => item.value === tag);
    if (input) input.checked = false;
    delete tagWeights[tag];
  }

  function enforceTagExclusivity(changed) {
    if (!changed?.checked) return;
    for (const [noneTag, peers] of exclusiveTagGroups) {
      if (changed.value === noneTag) {
        peers.forEach(clearTag);
      } else if (peers.includes(changed.value)) {
        clearTag(noneTag);
      }
    }
    paintTagWeights();
  }

  document.querySelectorAll('.impact-tag').forEach(label => label.addEventListener('click', event => {
    if (!advancedMode) return;
    event.preventDefault();
    const input = label.querySelector('input[name="tags[]"]');
    if (!input) return;
    tapFeedback();

    // No Auton / No Defense / Failed Endgame are binary facts. Toggle them immediately even in
    // Advanced Mode; asking for a strength would not add meaningful information.
    if (BINARY_TAGS.has(input.value)) {
      input.checked = !input.checked;
      delete tagWeights[input.value];
      if (input.checked) enforceTagExclusivity(input);
      paintTagWeights();
      saveAndSchedule(0);
      return;
    }

    openWeightModal(input);
  }));

  tagInputs.forEach(input => input.addEventListener('change', () => {
    if (advancedMode) return;
    tapFeedback();
    delete tagWeights[input.value]; // Basic Mode tags are intentionally unweighted.
    if (input.checked) enforceTagExclusivity(input);
    paintTagWeights();
    saveAndSchedule(0);
  }));

  advancedToggle?.addEventListener('change', () => setAdvancedMode(advancedToggle.checked, true));
  weightCloseButtons.forEach(button => button.addEventListener('click', closeWeightModal));
  weightRange?.addEventListener('input', () => {
    if (!activeWeightInput) return;
    const weight = normalizeTagWeight(weightRange.value);
    paintWeightSlider(weight);
    activeWeightInput.checked = true;
    tagWeights[activeWeightInput.value] = weight;
    enforceTagExclusivity(activeWeightInput);
    paintTagWeights();
    saveAndSchedule(250);
  });
  weightRange?.addEventListener('change', tapFeedback);
  weightClear?.addEventListener('click', () => {
    if (!activeWeightInput) return;
    tapFeedback();
    activeWeightInput.checked = true;
    delete tagWeights[activeWeightInput.value];
    enforceTagExclusivity(activeWeightInput);
    paintWeightSlider(-1);
    paintTagWeights();
    saveAndSchedule(0);
  });
  weightRemove?.addEventListener('click', () => {
    if (!activeWeightInput) return;
    tapFeedback();
    activeWeightInput.checked = false;
    delete tagWeights[activeWeightInput.value];
    paintTagWeights();
    saveAndSchedule(0);
    closeWeightModal();
  });
  weightModal?.addEventListener('click', event => { if (event.target === weightModal) closeWeightModal(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && weightModal && !weightModal.hidden) closeWeightModal(); });
  sliderInputs.forEach(input => input.addEventListener('input', () => {
    const card = input.closest('.impact-slider');
    if (card) { card.dataset.active = '1'; card.classList.remove('inactive'); }
    paintSliderOutputs();
    saveAndSchedule(300);
  }));
  clearRatingButtons.forEach(button => button.addEventListener('click', () => {
    tapFeedback();
    setRatingState(String(button.dataset.clearRating), -1);
    paintSliderOutputs();
    saveAndSchedule(0);
  }));
  noteInput.addEventListener('input', () => {
    clearTimeout(noteTimer);
    noteTimer = setTimeout(() => saveAndSchedule(700), 120);
  });
  form.addEventListener('submit', e => e.preventDefault());
  window.addEventListener('online', () => syncPending());
  window.addEventListener('offline', () => refreshStatus('offline','Offline · saved on this device'));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) syncPending(); });
  window.addEventListener('pagehide', () => { if (uiHasChanges()) saveCurrentLocal().catch(()=>{}); });

  ensureBlocks();
  paintSliderOutputs();

  (async () => {
    try {
      db = await openDb();
      await loadAdvancedMode();
      await cleanupOldCache();
      await markLocalCards();
      await selectRobot(currentTeam, false);
      await syncPending();
      setInterval(() => syncPending(), 5000);
    } catch (err) {
      cacheAvailable = false;
      try { await setAdvancedMode(localStorage.getItem('neptune-' + ADVANCED_PREF_KEY) === '1', false); } catch (_) {}
      setStatus('error','Device cache unavailable · changes require connection',0);
      const state = fromServer(currentTeam) || defaultState(currentTeam);
      applyState(state);
    }
  })();
})();
</script>
<?php endif;?>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
