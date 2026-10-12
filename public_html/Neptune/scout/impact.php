<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once __DIR__ . '/_tag_scouting.php';
require_once dirname(__DIR__) . '/_event_selection.php';
require_once dirname(__DIR__) . '/analytics/_team_logo_cache.php';
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

function impact_default_tag_keys(): array {
    return array_fill_keys(['auto_no_attempt','auto_partial','auto_completed','auto_failed','no_scoring','scoring','slow_cycles','fast_cycles','no_defense','attempted_defense','mechanical_issues','disabled','recovered','end_no_attempt','end_partial','end_completed','end_failed','strong_support','penalty_risk'],true);
}
function impact_tags(): array {
    global $tagCatalog;
    return $tagCatalog;
}

function impact_ajax_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Legacy approved tags retain their original database seed keys and IDs.
// Resolve them to the stable Match observation codes rather than discarding them.
function impact_match_row_key(array $row): string {
    $seed=(string)($row['seed_key']??'');
    $slug=(string)($row['slug']??'');
    $legacy=['fast-cycles'=>'fast_cycles','slow-cycles'=>'slow_cycles',
             'recovered'=>'recovered','penalty-risk'=>'penalty_risk'];
    if(isset($legacy[$seed]))return $legacy[$seed];
    if($seed==='local:auto_failed' || $slug==='structured-auto-failed')return 'auto_failed';
    if(str_starts_with($seed,'match:'))return substr($seed,6);
    // Organization-created tags are supported without requiring a global seed.
    if(($row['organization_id']??null)!==null && $seed==='')return str_replace('-','_',$slug);
    return '';
}
$settingsCatalog=tagobs_visible_tags($pdo,$org,false);
$matchTagRows=array_values(array_filter(tagobs_visible_tags($pdo,$org),static function($t){
    if((int)$t['match_enabled']!==1)return false;
    $key=impact_match_row_key($t);
    return $key!=='' && (isset(impact_default_tag_keys()[$key]) || (($t['organization_id']??null)!==null && (string)($t['seed_key']??'')===''));
}));
$tagCatalog=[];$matchTagByKey=[];
$matchTagRows=tagobs_ordered_tags($pdo,$org,'match',$matchTagRows);
foreach($matchTagRows as $tagRow){
    $key=impact_match_row_key($tagRow);
    if($key===''||isset($tagCatalog[$key]))continue;
    $tagCatalog[$key]=[(string)$tagRow['label'],(string)$tagRow['icon']];
    $matchTagByKey[$key]=$tagRow;
}
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

// Both TBA and FIRST populate the same scoped matches/match_teams tables.
// Keep every qualification match with robots, but do not offer playoff fixtures
// to Tag scouts until all six station assignments have actually been published.
$matches = [];
$pendingPlayoffs = 0;
if ($eventId) {
    $s = $pdo->prepare("SELECT m.id,m.event_id,m.game_id,m.comp_level,m.set_number,m.match_number,m.field_id,m.state,m.scheduled_time,m.tba_match_key,m.red_score,m.blue_score,
            (SELECT COUNT(*) FROM match_teams mt WHERE mt.match_id=m.id) AS assigned_stations,
            (SELECT COUNT(DISTINCT mt.frc_team_number) FROM match_teams mt WHERE mt.match_id=m.id) AS assigned_robots
        FROM matches m WHERE m.event_id=?
        ORDER BY CASE m.comp_level WHEN 'qm' THEN 1 WHEN 'ef' THEN 2 WHEN 'qf' THEN 3 WHEN 'sf' THEN 4 WHEN 'f' THEN 5 ELSE 6 END,
                 m.set_number,m.match_number,m.field_id");
    $s->execute([$eventId]);
    foreach ($s->fetchAll() as $row) {
        $isPlayoff = !in_array(strtolower((string)$row['comp_level']), ['qm','legacy'], true);
        // A partly decided bracket should not be mistaken for a scoutable match.
        if ($isPlayoff && ((int)$row['assigned_stations'] !== 6 || (int)$row['assigned_robots'] !== 6)) {
            $pendingPlayoffs++;
            continue;
        }
        if ((int)$row['assigned_stations'] < 1) continue;
        $matches[] = $row;
    }
}

$matchId = (int)($_GET['match_id'] ?? $_POST['match_id'] ?? 0);
$validMatchIds = array_map(static fn($x)=>(int)$x['id'], $matches);
if ($matchId && !in_array($matchId, $validMatchIds, true)) $matchId = 0;

// Prefer an active match, then a remaining qualification, then a ready
// playoff match. Do not strand a live playoff scout on an old incomplete qual.
// Coverage remains organization-wide and never rewrites existing observations.
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
        if (in_array((string)$m['state'], ['ready','running','paused'], true)) {
            $matchId=(int)$m['id'];
            break;
        }
    }
    // Once qualifications have finished, prioritize playoff scouting over
    // abandoned qualification gaps. Otherwise finish the current qualifiers.
    if (!$matchId) {
        $qualificationsOpen = false;
        foreach ($matches as $m) {
            if ((string)$m['comp_level']==='qm' && (string)$m['state']!=='ended') {
                $qualificationsOpen = true;
                break;
            }
        }
        $candidatePhases = $qualificationsOpen ? [true,false] : [false,true];
        foreach ($candidatePhases as $qualPhase) {
            foreach ($matches as $m) {
                if (((string)$m['comp_level']==='qm')!==$qualPhase) continue;
                $id=(int)$m['id'];
                $coverage=$coverageByMatch[$id]??['robot_count'=>0,'observed_count'=>0];
                if ($coverage['robot_count']>0 && $coverage['observed_count']<$coverage['robot_count']) {
                    $matchId=$id;
                    break 2;
                }
            }
        }
    }
    if (!$matchId) {
        for ($i=count($matches)-1;$i>=0;$i--) {
            $id=(int)$matches[$i]['id'];
            if (($coverageByMatch[$id]['robot_count']??0)>0) {$matchId=$id;break;}
        }
    }
    if (!$matchId) $matchId=(int)$matches[0]['id'];
}

$robots = [];
$match = null;
$manualScores = null;
$officialScores = ['red'=>null,'blue'=>null,'provider'=>'Official'];
$summaryByTeam = [];
$tagConsensus = [];
$mineByTeam = [];
if ($matchId) {
    foreach ($matches as $m) if ((int)$m['id'] === $matchId) { $match = $m; break; }
    if($match){
        require_once __DIR__ . '/_manual_match_scores.php';
        $officialScores = neptune_manual_scores_official_display($pdo,$org,$eventId,$match);
    }
    try {
        require_once __DIR__ . '/_manual_match_scores.php';
        $manualScores = neptune_manual_scores_row($pdo,$org,$eventId,$matchId);
    } catch (Throwable $ignored) { $manualScores = null; }

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
$activeMatchIndex = -1;
$qualificationCount = 0;
$playoffCount = 0;
foreach ($matches as $index=>$fixture) {
    if ((int)$fixture['id']===$matchId) $activeMatchIndex=$index;
    if ((string)$fixture['comp_level']==='qm') $qualificationCount++; else $playoffCount++;
}
$prevMatch = $activeMatchIndex>0 ? $matches[$activeMatchIndex-1] : null;
$nextMatch = $activeMatchIndex>=0 && isset($matches[$activeMatchIndex+1]) ? $matches[$activeMatchIndex+1] : null;
$activeRound = $match && (string)$match['comp_level']==='qm' ? 'Qualification' : 'Playoffs';
$matchPageUrl = static function(?array $fixture) use ($eventId): string {
    return $fixture ? '?'.http_build_query(['event_id'=>$eventId,'match_id'=>(int)$fixture['id']]) : '#';
};

// Use Neptune's existing cached team logos; do not make network requests while scouting.
$impactLogoMap = neptune_team_logo_map($org, (int)($event['season_year'] ?? date('Y')), array_map(static fn($r)=>(int)$r['frc_team_number'], $robots));
$impactLogoUrl = static function(int $team) use ($impactLogoMap): string {
    $info = $impactLogoMap[$team] ?? [];
    return !empty($info['exists']) && !empty($info['path']) ? base_url((string)$info['path']) : '';
};
$robotsForJs = [];
foreach ($robots as $r) {
    $team = (int)$r['frc_team_number'];
    $robotsForJs[$team] = [
        'team' => $team,
        'logo' => $impactLogoUrl($team),
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

$defaultGroupIcons=['Autonomous'=>'fa-solid fa-rocket','Scoring'=>'fa-solid fa-bolt','Defense'=>'fa-solid fa-shield-halved','Reliability'=>'fa-solid fa-screwdriver-wrench','Endgame'=>'fa-solid fa-flag-checkered','Alliance Play'=>'fa-solid fa-people-group'];
$tagGroups=[];
foreach($matchTagByKey as $key=>$row){
    $category=(string)$row['category'];
    if(!isset($tagGroups[$category]))$tagGroups[$category]=['label'=>$category,'icon'=>$defaultGroupIcons[$category]??'fa-solid fa-tags','tags'=>[]];
    $tagGroups[$category]['tags'][]=$key;
}
$tagGroups=array_values($tagGroups);

$negativeTags = ['auto_no_attempt', 'auto_failed', 'no_scoring', 'no_defense', 'disabled', 'mechanical_issues', 'end_no_attempt', 'end_failed', 'penalty_risk'];

$pageTitle = 'Tag Scouting';
$moduleName = 'TRIDENT';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
html,body{max-width:100%;overflow-x:hidden}
.tag-unified-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}.tag-unified-tab{display:flex;align-items:center;justify-content:center;gap:7px;min-height:46px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);color:var(--muted);text-decoration:none;font-weight:900}.tag-unified-tab.active{color:var(--accent);border-color:var(--accent);background:color-mix(in srgb,var(--accent) 8%,var(--panel2));box-shadow:inset 0 0 0 1px var(--accent)}
.impact-shell{--impact-red:var(--c1-red);--impact-blue:var(--alliance-blue);--impact-cyan:var(--accent);--impact-glow:color-mix(in srgb,var(--accent) 42%,transparent);position:relative}
.impact-hero{position:relative;overflow:hidden;padding:22px;border:1px solid var(--line);border-radius:24px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel) 86%,var(--accent) 14%),var(--panel));box-shadow:0 18px 55px var(--shadow)}
.impact-hero:before,.impact-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none;filter:blur(3px)}.impact-hero:before{width:320px;height:320px;right:-110px;top:-180px;background:radial-gradient(circle,var(--impact-glow),transparent 68%)}.impact-hero:after{width:220px;height:220px;left:-120px;bottom:-160px;background:radial-gradient(circle,color-mix(in srgb,var(--impact-cyan) 26%,transparent),transparent 70%)}
.impact-kicker{display:flex;align-items:center;gap:8px;font-size:.75rem;letter-spacing:.16em;font-weight:950;text-transform:uppercase;color:var(--muted)}
.impact-title{font-size:clamp(2.15rem,7vw,4.8rem);line-height:.9;margin:10px 0 12px;letter-spacing:-.065em}.impact-title span{background:linear-gradient(90deg,var(--accent),var(--impact-cyan));-webkit-background-clip:text;background-clip:text;color:transparent}.impact-sub{max-width:820px;margin:0;color:var(--muted);font-size:1rem;line-height:1.55}
.impact-selectors{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:19px}.impact-selectors label{display:grid;gap:7px;font-weight:900;font-size:.76rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted)}.impact-selectors select{width:100%;min-height:54px;border-radius:15px;font-size:1rem;font-weight:850}
.impact-alliance-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px}.impact-alliance{border:1px solid var(--line);border-radius:22px;padding:14px;background:color-mix(in srgb,var(--panel) 95%,transparent)}.impact-alliance.red{box-shadow:inset 0 4px 0 var(--impact-red)}.impact-alliance.blue{box-shadow:inset 0 4px 0 var(--impact-blue)}.impact-alliance-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.impact-alliance-head b{font-size:.84rem;letter-spacing:.12em;text-transform:uppercase}.impact-robots{display:grid;grid-template-columns:repeat(3,1fr);grid-auto-rows:1fr;align-items:stretch;gap:10px}
.impact-robot{display:flex;flex-direction:column;width:100%;height:100%;box-sizing:border-box;text-decoration:none;text-align:left;font:inherit;color:inherit;border:1px solid var(--line);border-radius:19px;padding:14px;min-height:154px;background:linear-gradient(180deg,color-mix(in srgb,var(--panel) 95%,var(--text) 5%),var(--panel));transition:.16s transform,.16s border-color,.16s box-shadow,.16s background;position:relative;overflow:hidden;cursor:pointer;touch-action:manipulation}.impact-robot:before{content:"";position:absolute;inset:0;opacity:0;background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 17%,transparent),transparent 58%);transition:.16s opacity}.impact-robot:hover,.impact-robot.active{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 14px 38px var(--shadow),0 0 0 2px var(--impact-glow)}.impact-robot.active:before{opacity:1}.impact-robot.active:after{content:"ACTIVE";position:absolute;right:9px;top:9px;font-size:.58rem;letter-spacing:.09em;font-weight:1000;padding:4px 6px;border-radius:999px;background:var(--accent);color:var(--solid-text)}
.impact-team{position:relative;font-size:clamp(1.55rem,3vw,2.35rem);font-weight:1000;letter-spacing:-.05em}.impact-nick{position:relative;font-size:.77rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.impact-meta{position:relative;display:flex;gap:6px;flex-wrap:wrap;align-content:flex-start;margin-top:auto;padding-top:11px;min-height:62px}.impact-mini{font-size:.68rem;border:1px solid var(--line);border-radius:999px;padding:5px 7px;color:var(--muted);font-weight:850}.impact-mini.local{display:none}.impact-robot.has-local .impact-mini.local{display:inline-flex;align-items:center;gap:4px;color:var(--good);border-color:color-mix(in srgb,var(--good) 45%,var(--line));background:color-mix(in srgb,var(--good) 10%,transparent)}.impact-robot.has-pending .impact-mini.local{color:var(--warn);border-color:color-mix(in srgb,var(--warn) 48%,var(--line));background:color-mix(in srgb,var(--warn) 10%,transparent)}
.impact-main{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr);gap:16px;margin-top:16px}.impact-editor,.impact-consensus{border:1px solid var(--line);border-radius:22px;padding:18px;background:var(--panel)}.impact-editor{overflow:hidden}.impact-editor-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.impact-editor-head h2{margin:2px 0 0;font-size:clamp(1.65rem,4vw,2.15rem);letter-spacing:-.035em}.impact-station{font-size:.8rem;color:var(--muted);margin-top:3px}
.impact-save-state{display:flex;align-items:center;gap:8px;flex-wrap:wrap;border:1px solid var(--line);border-radius:15px;padding:10px 12px;margin-bottom:16px;background:color-mix(in srgb,var(--panel2,var(--panel)) 84%,transparent);font-size:.8rem}.impact-save-state i{width:16px;text-align:center}.impact-save-state .pending{margin-left:auto;color:var(--muted);font-weight:850}.impact-save-state.synced i{color:var(--good)}.impact-save-state.local i,.impact-save-state.offline i{color:var(--warn)}.impact-save-state.error i{color:var(--bad)}
.impact-section-label{margin:20px 0 10px;font-size:.72rem;font-weight:1000;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.impact-tag-groups{display:grid;gap:12px}.impact-tag-group{border:1px solid var(--line);border-radius:18px;padding:12px;background:color-mix(in srgb,var(--panel2,var(--panel)) 42%,transparent)}.impact-tag-group-head{display:flex;align-items:center;gap:8px;margin-bottom:9px;font-size:.72rem;letter-spacing:.11em;text-transform:uppercase;font-weight:1000;color:var(--muted)}.impact-tag-group-head i{color:var(--accent)}.impact-tag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:8px}
.impact-tag{cursor:pointer;position:relative;min-width:0;touch-action:manipulation}.impact-tag input{position:absolute;opacity:0;pointer-events:none}.impact-tag span{position:relative;display:flex;align-items:center;gap:9px;min-height:58px;width:100%;border:1px solid var(--line);border-radius:15px;padding:10px 38px 10px 12px;font-size:.88rem;font-weight:900;line-height:1.15;transition:.14s transform,.14s border-color,.14s background,.14s box-shadow;background:color-mix(in srgb,var(--panel) 96%,var(--text) 4%);user-select:none}.impact-tag span>i{font-size:1rem;color:var(--muted);min-width:18px;text-align:center;transition:.14s color,.14s transform}.impact-tag span:after{content:"✓";position:absolute;right:10px;top:50%;transform:translateY(-50%) scale(.55);opacity:0;width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:var(--c1-blue);color:var(--solid-text);font-weight:1000;transition:.14s opacity,.14s transform}.impact-tag:active span{transform:scale(.975)}.impact-tag input:checked+span{border-color:var(--accent);background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 22%,var(--panel)),color-mix(in srgb,var(--accent) 8%,var(--panel)));box-shadow:0 0 0 2px var(--impact-glow),0 10px 24px var(--shadow);animation:impact-pop .18s ease-out}.impact-tag input:checked+span>i{color:var(--accent);transform:scale(1.08)}.impact-tag input:checked+span:after{opacity:1;transform:translateY(-50%) scale(1)}.impact-tag.danger input:checked+span{border-color:var(--bad);background:linear-gradient(135deg,color-mix(in srgb,var(--bad) 20%,var(--panel)),color-mix(in srgb,var(--bad) 7%,var(--panel)));box-shadow:0 0 0 2px color-mix(in srgb,var(--bad) 28%,transparent)}.impact-tag.danger input:checked+span>i{color:var(--bad)}.impact-tag.danger input:checked+span:after{background:var(--bad)}
@keyframes impact-pop{0%{transform:scale(.97)}70%{transform:scale(1.018)}100%{transform:scale(1)}}
.impact-sliders{display:grid;grid-template-columns:1fr;gap:12px}.impact-slider{--slider-color:var(--accent);border:1px solid var(--line);border-radius:18px;padding:14px;background:linear-gradient(145deg,color-mix(in srgb,var(--panel2,var(--panel)) 52%,transparent),transparent);overflow:hidden;transition:.16s border-color,.16s box-shadow,.16s opacity}.impact-slider.score{--slider-color:var(--accent)}.impact-slider:not(.inactive){border-color:color-mix(in srgb,var(--slider-color) 52%,var(--line));box-shadow:0 0 0 1px color-mix(in srgb,var(--slider-color) 16%,transparent)}.impact-slider-top{display:flex;justify-content:space-between;gap:10px;align-items:center}.impact-slider-title{display:flex;align-items:center;gap:8px;font-weight:900;font-size:.9rem}.impact-slider-title i{color:var(--slider-color);font-size:1rem}.impact-rating-actions{display:flex;align-items:center;gap:6px}.impact-value{display:inline-flex;align-items:center;justify-content:center;min-width:88px;min-height:38px;border-radius:13px;padding:6px 9px;background:color-mix(in srgb,var(--slider-color) 16%,var(--panel));border:1px solid color-mix(in srgb,var(--slider-color) 42%,var(--line));font-size:1.2rem;font-weight:1000;letter-spacing:-.03em;box-shadow:0 0 22px color-mix(in srgb,var(--slider-color) 12%,transparent)}.impact-slider.inactive .impact-value{font-size:.72rem;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);background:color-mix(in srgb,var(--panel2,var(--panel)) 75%,transparent);border-color:var(--line);box-shadow:none}.impact-clear{border:1px solid var(--line);border-radius:11px;padding:7px 9px;background:transparent;color:var(--muted);font-size:.68rem;font-weight:950;text-transform:uppercase;letter-spacing:.05em;cursor:pointer;opacity:1;transition:.14s opacity,.14s border-color,.14s color}.impact-slider.inactive .impact-clear{opacity:.38;pointer-events:none}.impact-clear:hover{color:var(--text);border-color:var(--slider-color)}
.impact-block-control{position:relative;margin:15px 0 6px;padding:5px 0}.impact-blocks{display:grid;grid-template-columns:repeat(var(--blocks),1fr);gap:3px;height:34px;pointer-events:none}.impact-blocks span{border-radius:4px;background:color-mix(in srgb,var(--line) 72%,transparent);border:1px solid color-mix(in srgb,var(--line) 86%,transparent);transform:scaleY(.72);transition:.12s transform,.12s background,.12s box-shadow,.12s opacity}.impact-slider:not(.inactive) .impact-blocks span.on{background:var(--slider-color);border-color:var(--slider-color);transform:scaleY(1);box-shadow:0 0 10px color-mix(in srgb,var(--slider-color) 24%,transparent)}.impact-slider:not(.inactive) .impact-blocks span.current{transform:scaleY(1.18);box-shadow:0 0 0 2px color-mix(in srgb,var(--text) 70%,transparent),0 0 16px color-mix(in srgb,var(--slider-color) 36%,transparent)}.impact-slider.inactive .impact-blocks span{opacity:.52}.impact-block-control input[type=range]{position:absolute;inset:-8px 0;width:100%;height:54px;margin:0;opacity:0;cursor:pointer;touch-action:pan-y}.impact-block-control:focus-within .impact-blocks{outline:2px solid color-mix(in srgb,var(--slider-color) 65%,transparent);outline-offset:4px;border-radius:6px}.impact-slider-scale{display:flex;justify-content:space-between;font-size:.65rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.impact-rating-help{margin-top:8px;font-size:.68rem;color:var(--muted);line-height:1.35}.impact-slider.inactive .impact-rating-help:before{content:"Tap or drag to rate · ";font-weight:900;color:var(--text)}
.impact-note textarea{width:100%;min-height:96px;border-radius:15px;resize:vertical;font-size:1rem;padding:12px}.impact-auto-note{margin-top:10px;font-size:.76rem;color:var(--muted);display:flex;align-items:center;gap:7px}

.impact-section-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:20px 0 10px}.impact-section-row .impact-section-label{margin:0}.impact-advanced-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none;color:var(--muted);font-size:.72rem;font-weight:950;letter-spacing:.06em;text-transform:uppercase}.impact-advanced-toggle input{position:absolute;opacity:0;pointer-events:none}.impact-toggle-track{width:46px;height:26px;padding:3px;border-radius:999px;border:1px solid var(--line);background:color-mix(in srgb,var(--panel2,var(--panel)) 75%,transparent);transition:.16s background,.16s border-color,.16s box-shadow}.impact-toggle-knob{display:block;width:18px;height:18px;border-radius:50%;background:var(--muted);transition:.16s transform,.16s background}.impact-advanced-toggle input:checked+.impact-toggle-track{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 18%,var(--panel));box-shadow:0 0 0 2px var(--impact-glow)}.impact-advanced-toggle input:checked+.impact-toggle-track .impact-toggle-knob{transform:translateX(20px);background:var(--accent)}.impact-advanced-hint{margin:-3px 0 10px;color:var(--muted);font-size:.74rem;line-height:1.4}.impact-advanced-hint[hidden]{display:none!important}
.impact-tag-weight{display:none;position:absolute;right:38px;top:7px;min-width:24px;height:22px;padding:0 6px;border-radius:999px;align-items:center;justify-content:center;font-style:normal;font-size:.63rem;font-weight:1000;background:var(--accent);color:var(--solid-text);box-shadow:0 4px 12px var(--shadow)}.impact-tag-weight.unweighted{background:var(--muted);color:var(--panel)}.impact-shell.advanced-mode .impact-tag input:checked+span .impact-tag-weight:not([hidden]){display:inline-flex}

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
.impact-consensus-tag>i{color:var(--accent);flex:0 0 auto}
.impact-consensus-tag>span{min-width:0;overflow-wrap:anywhere}
.impact-consensus-tag>b{display:inline-grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:color-mix(in srgb,var(--accent) 17%,var(--panel));color:var(--text);font-size:.68rem;font-weight:1000}
.impact-consensus-empty{margin:0;color:var(--muted);font-size:.82rem;line-height:1.45}
.impact-consensus-note{display:flex;gap:10px;align-items:flex-start;margin-top:16px;padding:12px;border:1px solid var(--line);border-radius:15px;background:color-mix(in srgb,var(--panel2,var(--panel)) 55%,transparent)}
.impact-consensus-note>i{color:var(--accent);margin-top:2px}.impact-consensus-note div{display:grid;gap:2px}.impact-consensus-note b{font-size:.78rem}.impact-consensus-note span{color:var(--muted);font-size:.72rem;line-height:1.4}

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
  background:var(--overlay-strong)!important;
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
  background:var(--panel)!important;
  color:var(--text)!important;
  box-shadow:0 28px 90px var(--shadow)!important;
}
.impact-weight-card *{box-sizing:border-box;min-width:0}
.impact-weight-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.impact-weight-head>div{flex:1 1 auto;min-width:0}
.impact-weight-head h3{margin:4px 0 0;font-size:1.4rem;line-height:1.08;overflow-wrap:anywhere}
.impact-weight-cancel{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border:1px solid var(--line);border-radius:13px;background:transparent;color:var(--muted);padding:0;font-size:1rem;font-weight:1000;cursor:pointer}
.impact-weight-copy{display:none!important}
.impact-weight-slider{--slider-color:var(--accent);width:100%!important;max-width:100%!important;margin-top:14px!important}
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
    background:var(--accent)!important;
    box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent),0 0 12px var(--impact-glow)!important;
    color:transparent!important;font-size:0!important;line-height:0!important;letter-spacing:0!important;
  }
}
@media(prefers-reduced-motion:reduce){.impact-robot,.impact-tag span,.impact-tag span>i,.impact-tag span:after{transition:none}.impact-tag input:checked+span{animation:none}}

/* Manual final scores are shared per match, not per robot. */
.manual-scores-launch{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:9px 0 2px}
.manual-scores-launch button{padding:10px 14px;border:1px solid var(--accent);border-radius:12px;background:color-mix(in srgb,var(--accent) 12%,var(--panel));color:var(--text);font-weight:900;cursor:pointer}
.manual-scores-launch button:hover{filter:brightness(1.16)}
.manual-score-caption{font-size:.76rem;color:var(--muted);line-height:1.5}
.manual-score-dialog{border:1px solid var(--line);background:var(--panel);color:var(--text);border-radius:20px;width:min(94vw,480px);max-height:calc(100dvh - 32px);overflow:auto;padding:22px;box-shadow:0 30px 100px var(--shadow);box-sizing:border-box}
.manual-score-dialog::backdrop{background:var(--overlay-strong)}
.manual-score-dialog h3{margin:4px 0 10px;font-size:1.55rem}
.manual-score-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:17px 0}
.manual-score-fields label{font-size:.83rem;font-weight:900;display:grid;gap:8px}
.manual-score-fields input{width:100%;box-sizing:border-box;min-height:54px;border:1px solid var(--line);border-radius:12px;padding:8px 12px;font-size:1.25rem;font-weight:950;background:var(--panel2,var(--panel));color:var(--text)}
.manual-score-fields label:first-child{color:var(--c1-red)}.manual-score-fields label:last-child{color:var(--alliance-blue)}
.manual-score-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.manual-score-actions button{min-height:46px;padding:10px 16px;border-radius:12px;font-weight:900;cursor:pointer;background:var(--panel2);color:var(--text);border:1px solid var(--line)}
.manual-score-actions button[type=submit]{background:var(--accent);color:var(--solid-text)}
.manual-score-actions button:disabled{opacity:.5;cursor:wait}
.manual-score-status{font-size:.82rem;line-height:1.45;min-height:20px;margin:12px 0;color:var(--muted)}
.manual-score-status.error{color:var(--bad)}.manual-score-status.good{color:var(--good)}
.manual-score-conflict{border:1px solid var(--warn);border-radius:12px;padding:10px;margin:10px 0;font-size:.82rem;line-height:1.5}
.manual-score-conflict[hidden]{display:none}
.manual-official-box{margin-top:14px;padding:13px;border:1px solid var(--line);border-radius:14px;background:var(--panel2,var(--panel));}
.manual-official-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.manual-official-header strong{font-size:.85rem}
.manual-official-source{font-size:.72rem;font-weight:900;letter-spacing:.04em;border:1px solid var(--accent);border-radius:999px;padding:4px 9px;color:var(--text)}
.manual-official-scores{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.manual-official-score{border:1px solid var(--line);border-radius:10px;padding:9px 11px;min-width:0}
.manual-official-score span{display:block;font-size:.77rem;font-weight:800;color:var(--muted)}
.manual-official-score b{display:block;font-size:1.45rem;margin-top:3px;line-height:1.2}
.manual-official-score.red b{color:var(--c1-red)}.manual-official-score.blue b{color:var(--alliance-blue)}
.manual-official-score .unavailable{font-size:.85rem;font-weight:700;color:var(--muted)}
.manual-official-note{font-size:.76rem;color:var(--muted);line-height:1.5;margin:9px 0 0}

/* Keep long-press robot navigation from selecting text on touch devices.
   Native form fields remain editable and selectable. */
.impact-shell, .impact-shell * { -webkit-user-select:none; user-select:none; -webkit-touch-callout:none; }
.impact-shell input, .impact-shell textarea, .impact-shell select,
.impact-shell [contenteditable=""], .impact-shell [contenteditable="true"] {
  -webkit-user-select:text; user-select:text; -webkit-touch-callout:default;
}
/* Mobile-first scouting UI — inherits the global Neptune palette, including organization presets. */
.impact-shell{
  --impact-red:var(--c1-red);
  --impact-blue:var(--alliance-blue);
  --impact-glow:color-mix(in srgb,var(--accent) 22%,transparent);
  color:var(--text);
  background:linear-gradient(155deg,color-mix(in srgb,var(--bg2) 65%,var(--panel)),var(--bg));
  border:1px solid var(--line);border-radius:24px;
  padding:clamp(12px,2.4vw,28px);max-width:1500px;margin:0 auto 32px;
  box-shadow:0 18px 64px var(--shadow);
  font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
}
.impact-shell *, .impact-shell *::before,.impact-shell *::after{box-sizing:border-box}
.impact-shell .tag-unified-tab{border-radius:14px;background:var(--panel2);color:var(--muted);border-color:var(--line);min-height:45px;letter-spacing:.015em}
.impact-shell .tag-unified-tab.active{color:var(--text);background:color-mix(in srgb,var(--accent) 15%,var(--panel));border-color:var(--accent);box-shadow:0 6px 22px var(--shadow)}
.impact-shell .impact-hero{padding:clamp(18px,3vw,32px);border-radius:21px;background:radial-gradient(ellipse at 90% 0%,color-mix(in srgb,var(--accent) 22%,transparent),transparent 66%),linear-gradient(150deg,color-mix(in srgb,var(--accent) 11%,var(--panel)),var(--panel) 78%);border-color:var(--line);box-shadow:inset 0 1px 0 color-mix(in srgb,var(--text) 6%,transparent),0 16px 40px var(--shadow)}
.impact-shell .impact-title{font-size:clamp(2rem,5vw,3.5rem);line-height:1;letter-spacing:-.055em;margin:12px 0}
.impact-shell .impact-title span{background:linear-gradient(95deg,var(--accent),color-mix(in srgb,var(--accent) 58%,var(--text)));-webkit-background-clip:text;background-clip:text}
.impact-shell .impact-sub{font-size:.93rem;max-width:670px;color:var(--muted)}
.impact-hero-badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.impact-hero-badges span{display:inline-flex;align-items:center;gap:7px;border-radius:30px;border:1px solid color-mix(in srgb,var(--accent) 36%,var(--line));background:color-mix(in srgb,var(--accent) 9%,var(--panel));padding:6px 11px;color:var(--text);font-size:.75rem;font-weight:780}
.impact-shell .impact-selectors{margin-top:20px;grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.impact-shell .impact-selectors label{color:var(--muted)}
.impact-shell .impact-selectors select{background:var(--panel2);color:var(--text);border:1px solid var(--line);border-radius:13px;min-height:51px;font-weight:800;padding:0 14px}
.impact-match-nav{display:grid;grid-template-columns:minmax(80px,.25fr) minmax(0,1fr) minmax(80px,.25fr);align-items:stretch;gap:10px;padding:11px;margin-top:14px;border:1px solid var(--line);border-radius:19px;background:linear-gradient(100deg,color-mix(in srgb,var(--accent) 9%,var(--panel2)),var(--panel));touch-action:pan-y;box-shadow:0 10px 28px var(--shadow)}
.impact-nav-arrow{display:flex;align-items:center;justify-content:center;gap:8px;min-height:80px;border:1px solid var(--line);border-radius:13px;background:var(--panel2);color:var(--text);text-decoration:none!important;font-size:.84rem;font-weight:850;transition:background .14s,transform .14s}
.impact-nav-arrow:not(.disabled):active{background:color-mix(in srgb,var(--accent) 16%,var(--panel2));transform:scale(.975)}.impact-nav-arrow.disabled{opacity:.28;pointer-events:none}
.impact-match-now{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:0;text-align:center;gap:3px}
.impact-nav-status{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.impact-round-badge{font-size:.66rem;font-weight:950;letter-spacing:.09em;text-transform:uppercase;border-radius:100px;padding:4px 9px;background:color-mix(in srgb,var(--accent) 13%,var(--panel2));color:var(--text);border:1px solid color-mix(in srgb,var(--accent) 36%,var(--line))}
.impact-round-badge.playoff{background:color-mix(in srgb,var(--warn) 13%,var(--panel2));color:var(--warn);border-color:color-mix(in srgb,var(--warn) 50%,var(--line))}
/* v62: Match classification belongs with the selector, not between swipe arrows. */
.impact-match-selector-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-width:0}
.impact-match-selector-heading .impact-round-badge{display:inline-flex;align-items:center;justify-content:center;flex:none;padding:3px 9px;font-size:.59rem;letter-spacing:.06em;line-height:1.2}
.impact-match-nav .impact-nav-status{min-height:16px}
.impact-match-progress{color:var(--muted);font-size:.72rem;font-weight:800}.impact-match-now strong{font-size:clamp(1.3rem,3vw,1.8rem);line-height:1.2;color:var(--text);letter-spacing:-.035em}.impact-round-hint{font-size:.7rem;color:var(--muted)}
.impact-bracket-note{margin-top:9px;padding:10px 13px;border-radius:12px;border:1px solid color-mix(in srgb,var(--warn) 47%,var(--line));color:var(--text);background:color-mix(in srgb,var(--warn) 10%,var(--panel));font-size:.77rem;line-height:1.4;display:flex;align-items:center;gap:9px}
.impact-shell .impact-alliance-grid{gap:12px;margin-top:14px}.impact-shell .impact-alliance{border-radius:18px;background:var(--panel);border:1px solid var(--line);overflow:hidden}
.impact-shell .impact-alliance.red{box-shadow:inset 0 3px 0 var(--c1-red)}.impact-shell .impact-alliance.blue{box-shadow:inset 0 3px 0 var(--alliance-blue)}
.impact-shell .impact-alliance-head{padding:0 1px 6px}.impact-shell .impact-alliance.red .impact-alliance-head b{color:var(--c1-red)}.impact-shell .impact-alliance.blue .impact-alliance-head b{color:var(--alliance-blue)}
.impact-shell .impact-robots{gap:8px}.impact-shell .impact-robot{min-height:112px;padding:13px;border-radius:14px;background:var(--panel2);border-color:var(--line);box-shadow:none}
.impact-shell .impact-robot.active{transform:translateY(-1px);background:color-mix(in srgb,var(--accent) 16%,var(--panel));border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent),0 8px 22px var(--shadow)}
.impact-shell .impact-team{font-size:clamp(1.35rem,2.6vw,2rem);color:var(--text)}.impact-shell .impact-nick{color:var(--muted)}.impact-shell .impact-meta{min-height:0;gap:4px}.impact-shell .impact-mini{font-size:.6rem;padding:4px 6px;border-color:var(--line);color:var(--muted)}
.impact-shell .impact-main{gap:13px;margin-top:14px}.impact-shell .impact-editor,.impact-shell .impact-consensus{background:var(--panel);border-color:var(--line);border-radius:19px}
.impact-shell .impact-editor-head{align-items:center;border-bottom:1px solid var(--line);padding-bottom:14px;touch-action:pan-y}.impact-shell .impact-editor-head h2{font-size:clamp(1.35rem,3vw,2.1rem);color:var(--text);line-height:1.12}
.impact-robot-actions{display:flex;align-items:center;gap:7px;flex:none}.impact-robot-actions button{width:41px;height:42px;min-height:42px;display:grid;place-items:center;border-radius:11px;background:var(--panel2);color:var(--text);border:1px solid var(--line);cursor:pointer}.impact-robot-actions button:disabled{opacity:.3;cursor:not-allowed}.impact-robot-actions span{font-size:.7rem;color:var(--muted);font-weight:900;white-space:nowrap}
.impact-category-rail{display:flex;gap:7px;overflow-x:auto;scrollbar-width:none;padding:5px 1px 14px;margin:4px 0 0;position:relative}.impact-category-rail::-webkit-scrollbar{display:none}
.impact-category-rail button{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;flex:none;min-height:42px;border-radius:100px;border:1px solid var(--line);background:var(--panel2);color:var(--text);padding:9px 13px;font-size:.78rem;font-weight:850;cursor:pointer}
.impact-category-rail button.active{background:var(--c1-blue);border-color:var(--c1-blue);color:var(--solid-text);box-shadow:0 4px 18px var(--shadow)}
.impact-shell .impact-tag-groups{gap:10px}.impact-shell .impact-tag-group{background:var(--panel2);border:1px solid var(--line);border-radius:15px}.impact-tag-group[hidden]{display:none!important}.impact-shell .impact-tag-group-head{color:var(--muted)}.impact-shell .impact-tag-group-head i{color:var(--accent)}.impact-shell .impact-tag span{border-radius:12px;min-height:54px}
.impact-shell .impact-section-label{color:var(--muted)}.impact-shell .impact-save-state{border-radius:12px}
/* v63: Retry is optional; a dirty observation remains in IndexedDB until HTTP confirms it. */
.impact-retry-sync{border:1px solid var(--line);border-radius:9px;min-height:34px;padding:6px 10px;background:var(--panel);color:var(--text);font-weight:850;font-size:.73rem;cursor:pointer}
.impact-retry-sync[hidden]{display:none!important}
.impact-retry-sync:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.impact-shell .impact-consensus{background:linear-gradient(170deg,color-mix(in srgb,var(--accent) 6%,var(--panel)),var(--panel))}
.impact-shell .impact-robot:focus-visible,.impact-nav-arrow:focus-visible,.impact-category-rail button:focus-visible,.impact-robot-actions button:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.impact-tag:focus-within span{outline:2px solid var(--accent);outline-offset:2px}
.impact-mobile-dock{display:none}
@media(max-width:800px){
  .impact-shell{padding:9px 9px 96px;border-radius:14px}
  .impact-shell .impact-hero{padding:18px;border-radius:15px}
  .impact-shell .impact-selectors{grid-template-columns:1fr 1fr;gap:8px}
  .impact-shell .impact-selectors select{min-height:48px;font-size:.86rem;padding:8px 7px}
  .impact-shell .impact-title{font-size:2.2rem}
  .impact-shell .impact-alliance-grid{grid-template-columns:1fr;gap:8px}
  .impact-shell .impact-robots{grid-template-columns:repeat(3,minmax(0,1fr));gap:5px}
  .impact-shell .impact-alliance{padding:7px;border-radius:13px}
  .impact-shell .impact-robot{padding:8px;height:105px;min-height:105px;border-radius:10px}
  .impact-shell .impact-robot.active:after{right:6px;top:6px}
  .impact-shell .impact-team{font-size:1.35rem}
  .impact-shell .impact-nick{font-size:.65rem}
  .impact-shell .impact-meta{padding-top:6px}.impact-shell .impact-mini{font-size:.56rem;padding:2px 4px}
  .impact-shell .impact-main{grid-template-columns:1fr}
  .impact-nav-arrow{min-height:68px}
  .impact-match-nav{grid-template-columns:minmax(56px,72px) minmax(0,1fr) minmax(56px,72px);gap:5px;padding:6px}
  .impact-nav-arrow span{display:none}.impact-nav-arrow i{font-size:1.05rem}
  .impact-round-hint{font-size:.62rem}.impact-round-hint i{display:none}
  .impact-mobile-dock{display:flex;align-items:stretch;gap:8px;position:fixed;z-index:500;bottom:calc(8px + env(safe-area-inset-bottom,0px));left:50%;transform:translateX(-50%);width:min(calc(100vw - 24px),620px);background:color-mix(in srgb,var(--panel) 95%,transparent);backdrop-filter:blur(16px);padding:7px;border:1px solid var(--line);border-radius:16px;margin:0;box-shadow:0 12px 35px var(--shadow)}
  .impact-mobile-dock a,.impact-mobile-dock button{display:flex;align-items:center;justify-content:center;gap:6px;flex:1;border:0;border-radius:11px;text-decoration:none;color:var(--text);background:var(--panel2);border:1px solid var(--line);font-weight:850;font-size:.78rem;padding:11px 5px;min-height:52px;cursor:pointer}
  .impact-mobile-dock button{background:var(--c1-blue);color:var(--solid-text);flex:1.4}.impact-mobile-dock .disabled{opacity:.32;pointer-events:none}
  .impact-shell .impact-editor,.impact-shell .impact-consensus{padding:13px}
}
@media(max-width:440px){
  .impact-shell .impact-sub{font-size:.83rem}.impact-hero-badges{gap:5px;margin-top:11px}.impact-hero-badges span{font-size:.61rem;padding:5px 7px}
  .impact-shell .impact-alliance-grid{gap:7px}.impact-shell .impact-alliance{padding:8px}
  .impact-shell .impact-robots{gap:4px}.impact-shell .impact-robot{min-height:108px;height:108px;padding:9px}
  .impact-shell .impact-team{font-size:1.5rem}.impact-shell .impact-mini{font-size:.52rem}.impact-shell .impact-meta{padding-top:4px}
  .impact-shell .impact-tag-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
  .impact-shell .impact-tag span{font-size:.76rem;min-height:64px;padding:7px 25px 7px 8px}
  .impact-shell .impact-editor-head{gap:7px}.impact-robot-actions{gap:3px}.impact-robot-actions button{width:34px;height:37px;min-height:37px}.impact-robot-actions span{font-size:.6rem}
  .impact-round-hint{font-size:.58rem}
}
@media(prefers-reduced-motion:reduce){.impact-shell *{scroll-behavior:auto!important;transition:none!important}}

/* Non-blocking navigation feedback, styled entirely with Neptune theme tokens. */
.impact-navigation-toast{position:fixed;z-index:12000;left:50%;bottom:calc(88px + env(safe-area-inset-bottom,0px));transform:translate(-50%,10px);max-width:min(90vw,420px);min-width:180px;padding:11px 17px;border-radius:14px;border:1px solid var(--line);background:var(--panel);color:var(--text);box-shadow:0 12px 38px var(--shadow);text-align:center;font-weight:750;font-size:.93rem;line-height:1.4;opacity:0;pointer-events:none;transition:opacity .18s ease,transform .18s ease}
.impact-navigation-toast.visible{opacity:1;transform:translate(-50%,0)}
@media(min-width:800px){.impact-navigation-toast{bottom:28px}}
@media(prefers-reduced-motion:reduce){.impact-navigation-toast{transition:none}}

/* Touch-friendly robot quick picker; colors always follow Neptune's theme. */
/* The quick picker is appended to body, outside .impact-shell. */
.impact-quick-picker, .impact-quick-picker *,
.impact-quick-picker *::before, .impact-quick-picker *::after {
  -webkit-user-select:none!important;
  user-select:none!important;
  -webkit-touch-callout:none!important;
}
.impact-quick-picker-team, .impact-quick-picker-close {
  touch-action:manipulation;
  -webkit-tap-highlight-color:transparent;
}
.impact-quick-picker-team:active { transform:scale(.985); }
.impact-quick-picker-team { transition:transform .11s ease,background .11s ease; }
.impact-quick-picker{border:1px solid var(--line);border-radius:20px 20px 0 0;padding:0;width:min(100%,520px);max-width:100%;max-height:min(76dvh,620px);margin:auto auto 0;background:var(--panel);color:var(--text);box-shadow:0 -12px 48px var(--shadow);overflow:hidden}
.impact-quick-picker::backdrop{background:rgba(0,0,0,.52)}
.impact-quick-picker-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 18px 12px;border-bottom:1px solid var(--line)}
.impact-quick-picker-head b{font-size:1.05rem}.impact-quick-picker-head small{display:block;color:var(--muted);margin-top:3px}
.impact-quick-picker-close{border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:12px;width:42px;height:42px;display:grid;place-items:center;cursor:pointer}
.impact-quick-picker-list{display:grid;gap:9px;overflow:auto;max-height:calc(min(76dvh,620px) - 92px);padding:14px 16px calc(18px + env(safe-area-inset-bottom,0px))}
.impact-quick-picker-team{min-height:65px;border:1px solid var(--line);border-radius:14px;background:var(--panel2);color:var(--text);padding:11px 13px;text-align:left;display:flex;align-items:center;gap:12px;cursor:pointer;touch-action:manipulation}
.impact-quick-picker-team[aria-current="true"]{border-color:var(--accent);box-shadow:inset 3px 0 0 var(--accent)}
.impact-quick-picker-team .number{font-weight:850;font-size:1.13rem;min-width:78px}.impact-quick-picker-team .details{display:flex;flex-direction:column;gap:4px;min-width:0}.impact-quick-picker-team .details b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.impact-quick-picker-team .details small{color:var(--muted)}
@media(min-width:800px){.impact-quick-picker{margin:auto;border-radius:20px;max-height:610px}.impact-quick-picker-list{max-height:510px}}

/* Persistent thumb-accessible robot wheel. Theme variables only. */
/* The six station choices appear as a vertically rotating-style scroll wheel. */
.impact-quick-picker-list{display:block!important;scroll-snap-type:y mandatory;overscroll-behavior:contain;padding:75px 16px!important;max-height:min(52dvh,370px)!important;overflow-y:auto!important;scrollbar-width:thin;touch-action:pan-y}
.impact-quick-picker-team{width:100%;min-height:70px;margin:0 0 8px;scroll-snap-align:center;scroll-snap-stop:always;transition:opacity .17s ease,transform .17s ease,border-color .17s ease}
.impact-quick-picker-team[aria-current="true"]{background:color-mix(in srgb,var(--accent) 15%,var(--panel2));border-color:var(--accent)}
.impact-quick-picker{max-height:min(78dvh,550px)}
.impact-quick-picker-head{position:relative;z-index:1;background:var(--panel)}
.impact-quick-picker-list::before,.impact-quick-picker-list::after{content:'';pointer-events:none;position:sticky;display:block;height:0}

/* Scoring-share explanation and navigation warning use the selected Neptune theme. */
.impact-scoring-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:2px 0 8px}
.impact-scoring-heading .impact-section-label{margin:0}
.impact-scoring-info{display:inline-flex;align-items:center;gap:7px;min-height:34px;padding:6px 11px;background:var(--panel2);color:var(--accent);border:1px solid var(--line);border-radius:999px;font-size:.77rem;font-weight:850;cursor:pointer}
.impact-scoring-info:focus-visible,.impact-scoring-dialog button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.impact-scoring-dialog{width:min(92vw,540px);max-height:min(85dvh,720px);padding:0;overflow:auto;border:1px solid var(--line);border-radius:20px;background:var(--panel);color:var(--text);box-shadow:0 18px 55px var(--shadow)}
.impact-scoring-dialog::backdrop{background:color-mix(in srgb,var(--text) 52%,transparent)}
.impact-scoring-dialog-head{padding:20px 20px 13px;display:flex;align-items:start;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line)}
.impact-scoring-dialog-head h2{margin:4px 0 0;font-size:1.25rem}.impact-scoring-dialog-close{flex-shrink:0;display:grid;place-items:center;width:38px;height:38px;border:1px solid var(--line);border-radius:11px;background:var(--panel2);color:var(--text);cursor:pointer}
.impact-scoring-dialog-body{padding:14px 20px;color:var(--text);line-height:1.55;font-size:.91rem}.impact-scoring-dialog-body p{margin:0 0 14px}.impact-scoring-dialog-body h3{margin:16px 0 6px;font-size:1rem}.impact-scoring-example{display:grid;gap:6px;padding:13px;border-left:3px solid var(--accent);border-radius:11px;background:var(--panel2)}.impact-scoring-example span{font-weight:800;color:var(--accent);font-size:.75rem;text-transform:uppercase}.impact-scoring-dialog-foot{padding:10px 20px 18px;display:flex;justify-content:flex-end}.impact-scoring-dialog-foot button{border:0;border-radius:11px;background:var(--accent);color:var(--panel);padding:11px 18px;font-weight:850;cursor:pointer}
.impact-navigation-toast.warning{border-color:var(--warn,var(--accent));box-shadow:inset 3px 0 0 var(--warn,var(--accent)),0 12px 38px var(--shadow)}

/* A single centered app-style navigation dock replaces the redundant floating button. */
.impact-wheel-dock{justify-content:space-between;align-items:center;gap:12px;overflow:visible}
.impact-wheel-dock .impact-dock-match{flex:1;min-width:0;display:flex;align-items:center;gap:7px}
/* Long-press robot navigation should not be stolen by browser panning/zoom. */
#impactDockNextRobot,#impactNextRobot{touch-action:none!important;-webkit-touch-callout:none!important;-webkit-user-select:none!important;user-select:none!important}
.impact-wheel-dock .impact-dock-robot-wheel{flex:0 0 76px!important;width:76px;height:76px;min-height:76px!important;border-radius:50%!important;border:2px solid var(--accent)!important;background:var(--panel)!important;color:var(--text)!important;box-shadow:0 3px 16px var(--shadow),inset 0 0 0 4px var(--panel2);padding:4px!important;display:flex;flex-direction:column;gap:0;align-items:center;justify-content:center;touch-action:manipulation;-webkit-user-select:none;user-select:none;-webkit-touch-callout:none}
.impact-wheel-logo{width:37px;height:37px;display:grid;place-items:center;overflow:hidden;border-radius:50%;color:var(--accent);font-size:1.25rem}
.impact-wheel-logo img,.impact-picker-avatar img,.impact-team-logo img{display:block;width:100%;height:100%;object-fit:contain}
.impact-wheel-number{font-size:.68rem;font-weight:900;line-height:1.4}
.impact-picker-avatar{width:46px;height:46px;flex:0 0 46px;display:grid;place-items:center;border-radius:12px;background:var(--panel);border:1px solid var(--line);overflow:hidden;color:var(--accent);font-size:1.4rem}
.impact-quick-picker-team .number{min-width:65px}
.impact-team-logo{width:40px;height:40px;margin:0 auto 7px;display:grid;place-items:center;border:1px solid var(--line);border-radius:12px;overflow:hidden;color:var(--accent);background:var(--panel2)}
@media(min-width:801px){.impact-wheel-dock{display:flex!important;width:min(380px,calc(100vw - 32px));bottom:16px}.impact-shell{padding-bottom:110px}}
@media(max-width:800px){.impact-wheel-dock{padding:6px 10px;bottom:calc(8px + env(safe-area-inset-bottom,0px))}.impact-wheel-dock .impact-dock-match{padding:12px 8px}.impact-wheel-dock .impact-dock-robot-wheel{margin-top:-17px;margin-bottom:-5px}}

/* v11: Compact native-feeling match dock and a six-item, no-scroll picker. */
.impact-wheel-dock{display:flex!important;position:fixed!important;left:50%!important;right:auto!important;top:auto!important;bottom:calc(10px + env(safe-area-inset-bottom,0px))!important;transform:translateX(-50%)!important;width:min(96vw,390px)!important;min-height:70px!important;padding:7px 9px!important;gap:6px!important;align-items:center!important;justify-content:space-between!important;border-radius:20px!important;box-sizing:border-box}
.impact-wheel-dock .impact-dock-match{min-width:0!important;flex:1 1 0!important;min-height:52px!important;justify-content:center!important;padding:8px 7px!important;gap:6px!important;font-size:.78rem!important;white-space:nowrap}
.impact-wheel-dock .impact-dock-robot-wheel{flex:0 0 70px!important;width:70px!important;height:70px!important;min-height:70px!important;margin:0!important;padding:5px!important}
.impact-wheel-dock .impact-wheel-logo{width:34px;height:34px}
.impact-shell{padding-bottom:105px}
/* Modal: six stations are always visible, with left-aligned logo, number and name. */
.impact-quick-picker{box-sizing:border-box;width:min(94vw,640px)!important;max-width:640px!important;height:auto!important;max-height:calc(100dvh - 24px)!important;margin:auto!important;border-radius:20px!important;display:none;flex-direction:column}
.impact-quick-picker[open]{display:flex!important}
.impact-quick-picker-head{flex:0 0 auto;padding:12px 16px!important}
.impact-quick-picker-list{display:grid!important;grid-template-columns:minmax(0,1fr)!important;grid-template-rows:repeat(6,minmax(0,1fr))!important;gap:6px!important;min-height:0!important;max-height:none!important;height:auto!important;overflow:hidden!important;overflow-y:hidden!important;scroll-snap-type:none!important;padding:9px 12px 12px!important;scrollbar-width:none!important;touch-action:manipulation!important}
.impact-quick-picker-team{box-sizing:border-box;display:flex!important;justify-content:flex-start!important;align-items:center!important;width:100%!important;min-height:0!important;height:clamp(46px,8.5dvh,64px)!important;margin:0!important;padding:5px 10px!important;gap:12px!important;text-align:left!important;scroll-snap-align:none!important;transform:none}
.impact-quick-picker-team .number{flex:0 0 auto;min-width:66px!important;text-align:left!important;font-size:1rem!important}
.impact-quick-picker-team .details{flex:1 1 auto;min-width:0!important;text-align:left!important;align-items:flex-start!important;gap:2px!important}
.impact-quick-picker-team .details b{max-width:100%;font-size:.91rem;line-height:1.15;white-space:normal!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.impact-quick-picker-team .details small{font-size:.73rem}
.impact-picker-avatar{width:40px!important;height:40px!important;flex:0 0 40px!important}
@media(max-width:800px){
 .impact-wheel-dock{width:min(calc(100vw - 20px),430px)!important}
 .impact-shell .impact-alliance{padding:9px!important}
 .impact-shell .impact-robots{grid-template-columns:repeat(3,minmax(0,1fr));gap:7px!important}
 .impact-shell .impact-robot{min-width:0;min-height:148px!important;height:auto!important;padding:9px 6px!important;align-items:center;text-align:center;gap:4px}
 .impact-shell .impact-team-logo{flex:0 0 auto;width:39px;height:39px;margin:1px auto 0}
 .impact-shell .impact-team{font-size:clamp(1.2rem,5.4vw,1.75rem)!important;line-height:1.14}
 .impact-shell .impact-nick{white-space:normal!important;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.2;overflow:hidden!important;min-height:2.4em;font-size:.7rem!important;overflow-wrap:anywhere}
 .impact-shell .impact-meta{min-height:0!important;justify-content:center;padding-top:4px!important;gap:4px}
 .impact-shell .impact-mini{font-size:.59rem!important;padding:3px 5px!important}
 .impact-shell .impact-robot.active:after{font-size:.45rem;top:3px;right:3px;padding:2px 4px}
}
@media(max-width:360px){.impact-wheel-dock .impact-dock-match span{display:none}.impact-shell .impact-robot{min-height:154px!important}.impact-picker-avatar{width:34px!important;height:34px!important;flex-basis:34px!important}.impact-quick-picker-team{gap:8px!important}}
@media(max-height:590px){.impact-quick-picker-head{padding:7px 12px!important}.impact-quick-picker-team{height:clamp(34px,7.8dvh,48px)!important}.impact-picker-avatar{height:32px!important;width:32px!important;flex-basis:32px!important}}
/* v12: thumb-reachable two-alliance picker, sized to its content and anchored over dock. */
.impact-quick-picker[open]{position:fixed!important;inset:auto 50% calc(91px + env(safe-area-inset-bottom,0px)) auto!important;transform:translateX(50%)!important;margin:0!important;width:min(calc(100vw - 16px),620px)!important;max-width:620px!important;max-height:calc(100dvh - 112px - env(safe-area-inset-bottom,0px))!important;height:fit-content!important;overflow:hidden!important;border-radius:18px!important}
.impact-quick-picker-head{padding:10px 13px!important;min-height:0!important}
.impact-quick-picker-head b{font-size:1rem}
.impact-quick-picker-list{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(3,minmax(0,auto))!important;grid-auto-flow:column!important;gap:6px!important;padding:9px!important;max-height:none!important;overflow:hidden!important;scroll-snap-type:none!important;touch-action:manipulation!important}
.impact-quick-picker-team{min-width:0!important;width:100%!important;height:clamp(58px,9dvh,76px)!important;padding:6px 8px!important;gap:7px!important;border-radius:12px!important;flex-wrap:nowrap!important}
.impact-quick-picker-team[data-alliance="Red"]{border-left:3px solid var(--red,var(--danger,#d74b4b))}
.impact-quick-picker-team[data-alliance="Blue"]{border-left:3px solid var(--blue,#377ec5)}
.impact-quick-picker-team .number{min-width:0!important;flex:0 0 auto!important;font-size:clamp(.72rem,2.7vw,1rem)!important}
.impact-quick-picker-team .details{flex:1 1 auto!important;overflow:hidden!important}
.impact-quick-picker-team .details b{font-size:clamp(.7rem,2.45vw,.91rem)!important;line-height:1.16!important;white-space:normal!important;overflow-wrap:anywhere!important}
.impact-quick-picker-team .details small{font-size:.65rem!important}
.impact-quick-picker .impact-picker-avatar{width:clamp(25px,7vw,38px)!important;height:clamp(25px,7vw,38px)!important;flex:0 0 clamp(25px,7vw,38px)!important}
@media(max-height:650px){.impact-quick-picker[open]{bottom:calc(82px + env(safe-area-inset-bottom,0px))!important}.impact-quick-picker-team{height:clamp(45px,8dvh,57px)!important}.impact-quick-picker-head{padding:6px 10px!important}}
@media(min-width:801px){.impact-quick-picker[open]{bottom:108px!important}}

/* v13: fixed, truly two-column thumb picker. Undo inherited wheel/list styles. */
.impact-quick-picker[open]{display:flex!important;flex-direction:column!important;box-sizing:border-box!important;left:50%!important;right:auto!important;top:auto!important;bottom:calc(91px + env(safe-area-inset-bottom,0px))!important;transform:translateX(-50%)!important;margin:0!important;width:min(calc(100vw - 20px),560px)!important;max-width:560px!important;height:auto!important;max-height:calc(100dvh - 110px - env(safe-area-inset-bottom,0px))!important;overflow:hidden!important}
.impact-quick-picker-head{flex:none!important;padding:10px 13px!important}
.impact-quick-picker-list{box-sizing:border-box!important;display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;grid-template-rows:repeat(3,minmax(0,1fr))!important;grid-auto-flow:row!important;gap:7px!important;width:100%!important;min-width:0!important;height:auto!important;max-height:none!important;min-height:0!important;overflow:hidden!important;overflow-y:hidden!important;padding:10px!important;scroll-snap-type:none!important;touch-action:manipulation!important}
.impact-quick-picker-team{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;justify-content:flex-start!important;align-items:center!important;box-sizing:border-box!important;min-width:0!important;max-width:100%!important;width:100%!important;height:clamp(76px,11dvh,100px)!important;min-height:0!important;margin:0!important;padding:8px!important;gap:9px!important;text-align:left!important;overflow:hidden!important}
.impact-quick-picker-team .impact-picker-avatar{display:grid!important;place-items:center!important;flex:0 0 clamp(45px,12vw,65px)!important;width:clamp(45px,12vw,65px)!important;height:clamp(45px,12vw,65px)!important;margin:0!important;border-radius:11px!important}
.impact-quick-picker-team .number{display:block!important;flex:none!important;min-width:0!important;font-weight:900!important;font-size:clamp(.85rem,3.5vw,1.25rem)!important;line-height:1.05!important}
.impact-quick-picker-team .details{display:flex!important;flex-direction:column!important;align-items:flex-start!important;justify-content:center!important;flex:1 1 auto!important;min-width:0!important;overflow:hidden!important;gap:4px!important}
.impact-quick-picker-team .details b{display:none!important}
.impact-quick-picker-team .details small{display:block!important;color:var(--muted)!important;font-size:clamp(.65rem,2.5vw,.78rem)!important;line-height:1.1!important;white-space:normal!important}
@media(max-width:400px){.impact-quick-picker-team{gap:5px!important;padding:6px!important}.impact-quick-picker-team .impact-picker-avatar{flex-basis:clamp(37px,10vw,47px)!important;width:clamp(37px,10vw,47px)!important;height:clamp(37px,10vw,47px)!important}.impact-quick-picker-team .details small{font-size:.62rem!important}}
@media(max-height:620px){.impact-quick-picker-team{height:clamp(52px,10dvh,68px)!important}.impact-quick-picker-team .impact-picker-avatar{flex-basis:36px!important;width:36px!important;height:36px!important}}

/* v14: Each selection is strictly TWO internal columns:
   [large logo] [team number / station beneath]. */
.impact-quick-picker-team{
  display:grid!important;
  grid-template-columns:clamp(52px,13vw,74px) minmax(0,1fr)!important;
  grid-template-rows:1fr!important;
  align-items:center!important;
  justify-items:start!important;
  column-gap:10px!important;
  height:clamp(78px,11dvh,100px)!important;
  padding:8px!important;
}
.impact-quick-picker-team .impact-picker-avatar{
  grid-column:1!important;
  grid-row:1!important;
  width:100%!important;
  height:auto!important;
  aspect-ratio:1!important;
  min-width:0!important;
  max-width:none!important;
  flex:none!important;
  margin:0!important;
}
.impact-picker-identity{
  grid-column:2!important;
  grid-row:1!important;
  display:flex!important;
  flex-direction:column!important;
  justify-content:center!important;
  align-items:flex-start!important;
  min-width:0!important;
  width:100%!important;
  gap:5px!important;
  text-align:left!important;
}
.impact-picker-identity .number{
  display:block!important;
  width:100%!important;
  font-size:clamp(.95rem,3.6vw,1.36rem)!important;
  line-height:1.15!important;
  font-weight:900!important;
  white-space:nowrap!important;
}
.impact-picker-identity small{
  display:block!important;
  font-size:clamp(.6rem,2.25vw,.79rem)!important;
  line-height:1.2!important;
  color:var(--muted)!important;
  white-space:normal!important;
}
@media(max-width:400px){
  .impact-quick-picker-team{grid-template-columns:clamp(45px,12vw,54px) minmax(0,1fr)!important;column-gap:7px!important}
}
@media(max-height:620px){
  .impact-quick-picker-team{height:clamp(54px,9dvh,76px)!important;grid-template-columns:clamp(38px,9dvh,56px) minmax(0,1fr)!important}
}

/* V15: one active robot header; large touch-safe logo and prominent station. */
.impact-shell .impact-active-identity{display:flex;align-items:center;gap:15px;min-width:0;flex:1}
.impact-shell .impact-active-avatar{flex:0 0 76px;width:76px;height:76px;border:1px solid var(--line);border-radius:16px;background:var(--panel2);display:grid;place-items:center;overflow:hidden;color:var(--accent);font-size:30px}
.impact-shell .impact-active-avatar img{display:block;max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain}
.impact-shell .impact-active-details{min-width:0;flex:1}
.impact-shell .impact-station-prominent{display:inline-block;border:1px solid var(--line);border-radius:9px;padding:6px 9px;margin-top:8px;color:var(--text);font-size:.78rem;font-weight:900;background:var(--panel2);line-height:1.4}
.impact-shell .impact-station-preference{display:flex;align-items:center;gap:9px;font-size:.82rem;color:var(--muted);margin:0 0 13px;cursor:pointer;max-width:max-content}
.impact-shell .impact-station-preference input{width:18px;height:18px;accent-color:var(--accent);flex:none}
@media(max-width:600px){.impact-shell .impact-active-identity{gap:9px}.impact-shell .impact-active-avatar{flex-basis:54px;width:54px;height:54px;border-radius:11px}.impact-shell .impact-editor-head{align-items:center}.impact-shell .impact-active-details h2{font-size:clamp(1.12rem,4.7vw,1.5rem)}.impact-shell .impact-station-prominent{font-size:.68rem;padding:5px 7px}.impact-shell .impact-robot-actions{flex:none}}

/* v18: shared compact heading rhythm, consistent on phone/tablet/desktop. */
.impact-shell .impact-hero{padding:clamp(14px,1.8vw,22px)!important}
.impact-shell .impact-title{font-size:clamp(1.9rem,4vw,3rem)!important;line-height:1.08!important;margin:0 0 8px!important}
.impact-shell .impact-sub{font-size:clamp(.82rem,1vw,.94rem)!important;line-height:1.42!important;margin:0!important;max-width:680px}
.impact-shell .impact-selectors{margin-top:14px!important;gap:9px!important}
.impact-shell .impact-selectors label{gap:5px!important}
.impact-shell .impact-selectors select{min-height:46px!important}
/* Dock framing must not depend on the mobile breakpoint. */
.impact-mobile-dock.impact-wheel-dock{
 display:flex!important;z-index:500!important;
 background:color-mix(in srgb,var(--panel) 95%,transparent)!important;
 backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
 border:1px solid var(--line)!important;
 border-radius:20px!important;
 box-shadow:0 12px 35px var(--shadow)!important;
 padding:7px 9px!important;
}
.impact-mobile-dock.impact-wheel-dock .impact-dock-match{
 background:var(--panel2)!important;color:var(--text)!important;
 border:1px solid var(--line)!important;border-radius:13px!important;
 text-decoration:none!important;
}
.impact-mobile-dock.impact-wheel-dock .impact-dock-match.disabled{opacity:.32;pointer-events:none}
@media(max-width:600px){.impact-shell .impact-hero{padding:14px!important}.impact-shell .impact-title{font-size:clamp(1.85rem,7vw,2.25rem)!important}.impact-shell .impact-selectors{margin-top:12px!important}}

.impact-tag-guide-entry{padding:10px 0;border-bottom:1px solid var(--line);display:grid;gap:5px;line-height:1.45}.impact-tag-guide-entry strong{color:var(--text)}.impact-tag-guide-entry div{color:var(--muted);font-size:.87rem}.impact-tag-guide-entry b{color:var(--text)}

/* v20: responsive tag controls, on-device settings and press-and-hold help */
.impact-hero-settings{position:absolute;z-index:3;right:14px;top:12px;display:grid;place-items:center;width:42px;height:42px;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);font-size:1.2rem;cursor:pointer;box-shadow:0 3px 14px var(--shadow)}
.impact-hero-settings:hover,.impact-hero-settings:focus-visible{border-color:var(--accent);color:var(--accent)}
.impact-shell .impact-hero .impact-title{padding-right:50px}
.impact-shell .impact-tag span>i{font-size:clamp(1.65rem,2.5vw,2rem)!important;min-width:34px!important;line-height:1!important}
.impact-shell .impact-tag span{gap:12px;min-height:74px!important;padding:12px 37px 12px 13px!important}
.impact-shell.compact-tags .impact-tag span{min-height:52px!important;padding:7px 32px 7px 10px!important;gap:9px}
.impact-shell.compact-tags .impact-tag span>i{font-size:1.45rem!important;min-width:29px!important}
.impact-setting-option{display:flex;align-items:flex-start;gap:13px;padding:14px 0;border-bottom:1px solid var(--line);cursor:pointer;color:var(--text)}
.impact-setting-option input{flex:none;margin-top:3px;width:21px;height:21px;accent-color:var(--accent)}
.impact-setting-option span{display:grid;gap:4px}.impact-setting-option strong{font-size:.98rem}.impact-setting-option small{color:var(--muted);font-size:.82rem;line-height:1.4}
.impact-settings-note{font-size:.83rem!important;color:var(--muted);line-height:1.5}
.impact-tag{touch-action:pan-y;-webkit-touch-callout:none;user-select:none}


/* v38: neutral icons, severity on the tile edge only. */
.impact-shell .impact-tag span>i,.impact-shell .impact-tag.danger span>i{color:var(--muted)!important}
.impact-shell .impact-tag input:checked+span>i{color:var(--accent)!important}
.impact-shell .impact-tag.danger input:checked+span>i{color:var(--bad)!important}
.impact-shell .impact-tag:not(.danger) span{border-left:3px solid var(--good)}
.impact-shell .impact-tag.danger span{border-left:3px solid var(--bad)}
.impact-shell .impact-tag:not(.danger) input:checked+span{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 14%,var(--panel));box-shadow:0 0 0 2px var(--impact-glow)}
.impact-shell .impact-tag:not(.danger) input:checked+span:after{background:var(--accent)}
.impact-shell.compact-tags .impact-tag span{min-height:74px!important;padding:12px 37px 12px 13px!important;gap:12px!important}
.impact-settings-category{margin:16px 0;padding:12px;border:1px solid var(--line);border-radius:12px}
.impact-settings-category summary{font-weight:900;cursor:pointer}
.impact-settings-category button{display:block;width:100%;text-align:left;padding:9px;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--text);cursor:pointer}

/* v39: Severity is conveyed by borders, not by coloring all tag icons. */
.impact-shell .impact-tag span i,.impact-shell .impact-tag.danger span i,.impact-shell .impact-tag:not(.danger) span i{color:var(--muted)!important}
.impact-shell .impact-tag input:checked+span i{color:var(--text)!important}
</style>
<div class="impact-shell">
  <?=tag_unified_tabs_html('match',$eventId,$selectedTeam)?>
  <section class="impact-hero">
    <button type="button" class="impact-hero-settings" id="impactSettingsOpen" aria-label="Tag Scouting settings" title="Tag Scouting settings"><i class="fa-solid fa-gear" aria-hidden="true"></i></button>
    <h1 class="impact-title">Tag <span>Scouting</span></h1>
    <p class="impact-sub">Fast observations, one tap at a time. Switch robots instantly, then swipe to the next match. Changes save on this device and sync when connected.</p>
    <div class="impact-selectors">
      <label>Event
        <select id="impactEvent">
          <?=neptune_event_options_html($events,$eventId)?>
        </select>
      </label>
      <label>
        <span class="impact-match-selector-heading"><span>Match</span><?php if($match):?><span class="impact-round-badge <?=$activeRound==='Qualification'?'qual':'playoff'?>"><?=e($activeRound)?></span><?php endif;?></span>
        <select id="impactMatch" <?=$eventId?'':'disabled'?>>
          <?php $lastMatchGroup='';foreach($matches as $m):$group=(string)$m['comp_level']==='qm'?'Qualifications':'Playoffs';if($group!==$lastMatchGroup):if($lastMatchGroup!==''):?></optgroup><?php endif;?><optgroup label="<?=e($group)?>"><?php $lastMatchGroup=$group;endif;?><option value="<?=(int)$m['id']?>" <?=$matchId===(int)$m['id']?'selected':''?>><?=e(impact_match_label($m))?><?=!empty($m['field_id'])?' · Field '.e($m['field_id']):''?></option><?php endforeach;if($lastMatchGroup!==''):?></optgroup><?php endif;?>
        </select>
      </label>
    </div>
  </section>

  <?php if($matches && $match):?>
  <nav class="impact-match-nav" id="impactMatchGesture" aria-label="Match navigation">
    <a class="impact-nav-arrow <?=$prevMatch?'':'disabled'?>" href="<?=e($matchPageUrl($prevMatch))?>" data-impact-match-step="-1" <?=$prevMatch?'':'aria-disabled="true" tabindex="-1"'?> aria-label="Previous match"><i class="fa-solid fa-chevron-left"></i><span>Previous</span></a>
    <div class="impact-match-now">
      <div class="impact-nav-status"><span class="impact-match-progress"><?=($activeMatchIndex+1)?> of <?=count($matches)?> matches</span></div>
      <strong><?=e(impact_match_label($match))?></strong>
      <div class="impact-round-hint"><i class="fa-solid fa-arrows-left-right"></i> Swipe here to change matches <span aria-hidden="true">·</span> <?=$qualificationCount?> quals <span aria-hidden="true">·</span> <?=$playoffCount?> playoffs</div>
    </div>
    <a class="impact-nav-arrow <?=$nextMatch?'':'disabled'?>" href="<?=e($matchPageUrl($nextMatch))?>" data-impact-match-step="1" <?=$nextMatch?'':'aria-disabled="true" tabindex="-1"'?> aria-label="Next match"><span>Next</span><i class="fa-solid fa-chevron-right"></i></a>
  </nav>
  <?php if($pendingPlayoffs>0):?><div class="impact-bracket-note"><i class="fa-solid fa-clock"></i> <?=e($pendingPlayoffs)?> playoff <?= $pendingPlayoffs===1?'match is':'matches are' ?> awaiting complete published alliances. They will become scoutable after the next TBA or FIRST sync.</div><?php endif;?>
  <?php elseif($pendingPlayoffs>0):?><div class="impact-bracket-note"><i class="fa-solid fa-clock"></i> Playoff fixtures are awaiting official robot assignments from TBA or FIRST.</div><?php endif;?>

  <?php if($message):?><div class="notice good" style="margin-top:14px"><i class="fa-solid fa-circle-check"></i> <?=e($message)?></div><?php endif;?>
  <?php if($error):?><div class="notice bad" style="margin-top:14px"><i class="fa-solid fa-triangle-exclamation"></i> <?=e($error)?></div><?php endif;?>

  <?php if(!$events):?>
    <div class="card impact-empty" style="margin-top:16px"><i class="fa-solid fa-calendar-xmark fa-2x"></i><h2>No usable event schedules found</h2><p>Tag Scouting shows events that have both matches and assigned match robots.</p></div>
  <?php elseif(!$robots):?>
    <div class="card impact-empty" style="margin-top:16px"><i class="fa-solid fa-robot fa-2x"></i><h2>No robots in this match</h2><p>Refresh the event schedule so Neptune can load the six match robots.</p></div>
  <?php else:?>
    <div id="impactRobotOrderData" hidden aria-hidden="true">
      <?php foreach($robots as $r): ?>
        <span data-impact-team="<?=(int)$r['frc_team_number']?>"></span>
      <?php endforeach; ?>
    </div>

    <div class="impact-main">
      <section class="impact-editor">
        <div class="impact-editor-head" id="impactRobotGesture">
          <div class="impact-active-identity">
            <span class="impact-active-avatar" id="impactActiveRobotLogo"><?php if($impactLogoUrl($selectedTeam)!==''):?><img src="<?=e($impactLogoUrl($selectedTeam))?>" alt="" onerror="this.replaceWith(document.createTextNode('🤖'))"><?php else:?><i class="fa-solid fa-robot" aria-hidden="true"></i><?php endif;?></span>
            <div class="impact-active-details"><div class="impact-kicker">SCOUTING NOW · SWIPE TO SWITCH ROBOTS</div><h2 id="impactRobotTitle">#<?=$selectedTeam?> <?=e($selectedRobot['nickname'] ?: '')?></h2><div class="impact-station impact-station-prominent" id="impactRobotStation"><?=e(strtoupper((string)$selectedRobot['alliance']))?> ALLIANCE · STATION <?=e($selectedRobot['station'])?><?= $match ? ' · '.e(impact_match_label($match)) : '' ?></div></div>
          </div>
          <div class="impact-robot-actions"><button type="button" id="impactPrevRobot" aria-label="Previous robot"><i class="fa-solid fa-chevron-left"></i></button><span id="impactRobotIndex" aria-live="polite">1 / <?=count($robots)?></span><button type="button" id="impactNextRobot" aria-label="Next robot"><i class="fa-solid fa-chevron-right"></i></button></div>
        </div>


        <div class="impact-save-state local" id="impactSaveState" role="status" aria-live="polite"><i class="fa-solid fa-circle" aria-hidden="true"></i><b id="impactSaveText">Opening device cache…</b><span class="pending" id="impactPendingText"></span><button class="impact-retry-sync" id="impactRetrySync" type="button" hidden><i class="fa-solid fa-rotate" aria-hidden="true"></i> Retry sync</button></div>

        <form method="post" id="impactForm" autocomplete="off">
          <input type="hidden" name="csrf" id="impactCsrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="event_id" value="<?=$eventId?>">
          <input type="hidden" name="match_id" value="<?=$matchId?>">
          <input type="hidden" name="frc_team_number" id="impactTeamInput" value="<?=$selectedTeam?>">

          <div class="impact-section-row">
            <div class="impact-section-label">What happened in the match?</div>
            <label class="impact-advanced-toggle" title="Enable weighted tag observations">
              <input type="checkbox" id="impactAdvancedMode">
              <span class="impact-toggle-track"><span class="impact-toggle-knob"></span></span>
              <span>Advanced</span>
            </label>
          </div>
          <div class="impact-advanced-hint" id="impactAdvancedHint" hidden>Advanced Mode: tap a non-binary tag to set a 1–5 observation weight. Hold any tag to read its definition.</div>
          <div class="impact-category-rail" role="group" aria-label="Tag categories">
            <button type="button" class="active" data-impact-category="all" aria-pressed="true">All</button>
            <?php foreach($tagGroups as $g):?><button type="button" data-impact-category="<?=e(strtolower(str_replace(' ','-',$g['label'])))?>" aria-pressed="false"><i class="<?=e($g['icon'])?>"></i><?=e($g['label'])?></button><?php endforeach;?>
          </div>
          <div class="impact-tag-groups" id="impactTagGroups">
            <?php foreach($tagGroups as $group):?>
              <section class="impact-tag-group" data-impact-section="<?=e(strtolower(str_replace(' ','-',$group['label'])))?>">
                <div class="impact-tag-group-head"><i class="<?=e($group['icon'])?>"></i> <?=e($group['label'])?></div>
                <div class="impact-tag-grid">
                  <?php foreach($group['tags'] as $key): $tag=$tagCatalog[$key];?>
                    <label class="impact-tag <?=in_array($key,$negativeTags,true)?'danger':''?>" data-tag-area="<?=e($group['label'])?>"><input type="checkbox" name="tags[]" value="<?=e($key)?>"><span><i class="<?=e($tag[1])?>"></i><?=e($tag[0])?><em class="impact-tag-weight" data-tag-weight-badge="<?=e($key)?>" hidden></em></span></label>
                  <?php endforeach;?>
                </div>
              </section>
            <?php endforeach;?>
          </div>

          <div class="impact-scoring-heading"><div class="impact-section-label">Estimated scoring share</div><button type="button" class="impact-scoring-info" id="impactScoringInfoOpen" aria-haspopup="dialog" aria-controls="impactScoringInfoDialog" aria-label="What is estimated scoring share and why does it matter?"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Why it matters</span></button></div>
          <div class="impact-sliders">
            <div class="impact-slider score inactive" data-rating="scoring_contribution" data-block-count="21" data-active="0"><div class="impact-slider-top"><span class="impact-slider-title"><i class="fa-solid fa-chart-pie"></i> Scoring Contribution</span><span class="impact-rating-actions"><span class="impact-value" data-output="scoring_contribution">Not rated</span><button type="button" class="impact-clear" data-clear-rating="scoring_contribution">Clear</button></span></div><div class="impact-block-control"><div class="impact-blocks" aria-hidden="true"></div><input type="range" name="scoring_contribution" min="0" max="100" step="1" value="0" aria-label="Scoring Contribution"></div><div class="impact-slider-scale"><span>0%</span><span>50%</span><span>100%</span></div><div class="impact-rating-help">Estimate this robot's share of its alliance scoring. Leave it unrated if you were not confident.</div></div>
          </div>

          <div class="manual-scores-launch">
            <button type="button" id="manualScoresOpen"><i class="fa-solid fa-pen-to-square"></i> Enter alliance scores</button>
            <span class="manual-score-caption" id="manualScoresSummary">Scores belong to this match · All scouts may edit</span>
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
          <?php if(!$robotTagConsensus):?><p class="impact-consensus-empty">No tags yet. Your observation can be the first signal.</p><?php else:?><div class="impact-consensus-tags"><?php foreach(array_slice($robotTagConsensus,0,10,true) as $key=>$count):?><span class="impact-consensus-tag"><i class="<?=e(($tagCatalog[$key][1] ?? 'fa-solid fa-tag'))?>"></i><span><?=e(($tagCatalog[$key][0] ?? $key))?></span><b><?=$count?></b></span><?php endforeach;?></div><?php endif;?>
        </div>
        <div class="impact-consensus-note"><i class="fa-solid fa-people-group"></i><div><b>Independent by design</b><span>Each scout keeps their own observation. Neptune averages scoring-share estimates and counts tag agreement.</span></div></div>
      </aside>
    </div>
  <?php endif;?>
  <?php if($match && $robots):?>
    <div class="impact-mobile-dock impact-wheel-dock" aria-label="Match and robot navigation">
      <a href="<?=e($matchPageUrl($prevMatch))?>" data-impact-match-step="-1" class="impact-dock-match <?=$prevMatch?'':'disabled'?>" <?=$prevMatch?'':'aria-disabled="true" tabindex="-1"'?> aria-label="Previous match"><i class="fa-solid fa-chevron-left"></i><span>Match</span></a>
      <button type="button" id="impactDockNextRobot" class="impact-dock-robot-wheel" aria-label="Next robot; hold to choose a robot" title="Tap for next robot · hold for picker"><span class="impact-wheel-logo" id="impactWheelLogo"><?php if($impactLogoUrl($selectedTeam)!==''):?><img src="<?=e($impactLogoUrl($selectedTeam))?>" alt="" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><i class="fa-solid fa-robot" hidden></i><?php else:?><i class="fa-solid fa-robot"></i><?php endif;?></span><span class="impact-wheel-number" id="impactWheelNumber">#<?=$selectedTeam?></span></button>
      <a href="<?=e($matchPageUrl($nextMatch))?>" data-impact-match-step="1" class="impact-dock-match <?=$nextMatch?'':'disabled'?>" <?=$nextMatch?'':'aria-disabled="true" tabindex="-1"'?> aria-label="Next match"><span>Match</span><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  <?php endif;?>

    <dialog class="impact-scoring-dialog impact-settings-dialog" id="impactSettingsDialog" aria-labelledby="impactSettingsTitle">
      <div class="impact-scoring-dialog-head"><div><span class="impact-kicker">TRIDENT · PREFERENCES</span><h2 id="impactSettingsTitle">Scouting settings</h2></div><button type="button" class="impact-scoring-dialog-close" data-impact-settings-close aria-label="Close settings"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="impact-scoring-dialog-body">
        <p>Preferences are saved on this device and browser. They do not change scouting data.</p>
        <label class="impact-setting-option"><input type="checkbox" id="impactRememberStation" checked><span><strong>Remember alliance station</strong><small>When changing matches, select the same alliance color and station number.</small></span></label>

        <label class="impact-setting-option"><input type="checkbox" id="impactTagHaptics" checked><span><strong>Touch feedback</strong><small>Vibrate briefly on supported devices when selecting tags.</small></span></label>
        <p class="impact-settings-note">Tap a tag to select; hold it to read its guidance.</p><div id="impactSettingsTagSections"></div>
      </div>
      <div class="impact-scoring-dialog-foot"><button type="button" data-impact-settings-close>Done</button></div>
    </dialog>
    <dialog class="impact-scoring-dialog impact-tag-info-dialog" id="impactTagInfoDialog" aria-labelledby="impactTagInfoTitle">
      <div class="impact-scoring-dialog-head"><div><span class="impact-kicker" id="impactTagInfoArea">TAG INFORMATION</span><h2 id="impactTagInfoTitle">Tag</h2></div><button type="button" class="impact-scoring-dialog-close" data-impact-tag-info-close aria-label="Close tag information"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="impact-scoring-dialog-body"><h3>When to use it</h3><p id="impactTagInfoWhen"></p><h3>Why it matters</h3><p id="impactTagInfoWhy"></p><p class="impact-settings-note">Tag observations do not directly change EPA or D-EPA unless explicitly incorporated into a downstream model.</p></div>
      <div class="impact-scoring-dialog-foot"><button type="button" data-impact-tag-info-close>Close</button></div>
    </dialog>
    <dialog class="impact-scoring-dialog" id="impactScoringInfoDialog" aria-labelledby="impactScoringInfoTitle" aria-describedby="impactScoringInfoDescription">
      <div class="impact-scoring-dialog-head"><div><span class="impact-kicker">SCOUTING INTELLIGENCE</span><h2 id="impactScoringInfoTitle">Estimated Scoring Share</h2></div><button type="button" class="impact-scoring-dialog-close" data-impact-scoring-close aria-label="Close explanation"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="impact-scoring-dialog-body" id="impactScoringInfoDescription">
        <p>Estimate what percentage of the <strong>alliance's total scoring</strong> came from the robot you're watching during this match. This is an observation, not an official per-robot score.</p>
        <div class="impact-scoring-example"><span>Example</span><b>An alliance scores 100 points. If this robot contributed roughly 35, select 35%.</b></div>
        <h3>Why it matters</h3>
        <p>Alliance scores alone cannot tell Neptune how much each robot contributed. Your estimate helps distinguish primary scorers from support robots and improves comparisons for strategy, alliance selection, and future match planning.</p>
        <p>When multiple scouts provide independent estimates, Neptune can average them to reduce the impact of one person's judgment. <strong>Accuracy matters more than filling every field.</strong> If you did not observe enough to estimate fairly, leave it unrated.</p>
      </div>
      <div class="impact-scoring-dialog-foot"><button type="button" data-impact-scoring-close>Got it</button></div>
    </dialog>
    <dialog class="manual-score-dialog" id="manualScoresDialog" aria-labelledby="manualScoresTitle">
      <form id="manualScoresForm" autocomplete="off">
        <div class="impact-kicker">MANUAL FINAL RESULT · <?=e($match ? impact_match_label($match) : 'MATCH')?></div>
        <h3 id="manualScoresTitle">Alliance final scores</h3>
        <div class="manual-score-caption">Manual scores are shared with every scout in your organization. You can enter or correct them even when an official result has already arrived; official scores always take precedence in analytics.</div>
        <div class="manual-official-box" role="group" aria-label="Current official match scores">
          <div class="manual-official-header"><strong>Official match result</strong><span class="manual-official-source" id="manualOfficialSource">Checking…</span></div>
          <div class="manual-official-scores">
            <div class="manual-official-score red"><span>Red alliance</span><b id="manualOfficialRed">—</b></div>
            <div class="manual-official-score blue"><span>Blue alliance</span><b id="manualOfficialBlue">—</b></div>
          </div>
          <p class="manual-official-note" id="manualOfficialNotice">Checking for imported scores…</p>
        </div>
        <div class="manual-score-fields">
          <label>Red alliance <input type="number" id="manualRedScore" min="0" max="2000" step="1" required inputmode="numeric"></label>
          <label>Blue alliance <input type="number" id="manualBlueScore" min="0" max="2000" step="1" required inputmode="numeric"></label>
        </div>
        <div class="manual-score-status" id="manualScoresStatus" role="status" aria-live="polite"></div>
        <div class="manual-score-conflict" id="manualScoresConflict" hidden>
          <div id="manualScoresConflictText"></div>
          <button type="button" id="manualScoresOverwrite">Use my scores instead</button>
        </div>
        <div class="manual-score-actions">
          <button type="button" id="manualScoresCancel">Cancel</button>
          <button type="submit" id="manualScoresSave">Save final scores</button>
        </div>
      </form>
    </dialog>

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
<script src="<?=e(base_url('assets/js/neptune-realtime-v2.js'))?>?v=20261006-2"></script>
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
  // Match tags are all eligible for Advanced Mode weighting. The v29 blanket
  // binary classification prevented the weight dialog from opening for every tag.
  const BINARY_TAGS = new Set();
  const MANUAL_API = <?=json_encode(base_url('scout/manual-scores-api.php'))?>;
  const MANUAL_SERVER = <?=json_encode($manualScores ? ['red_score'=>(int)$manualScores['red_score'],'blue_score'=>(int)$manualScores['blue_score'],'revision'=>(int)$manualScores['revision']] : null)?>;
  let OFFICIAL_SCORES = <?=json_encode($officialScores, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)?>;


  const DB_NAME = 'neptune-impact-scouting';
  const DB_VERSION = 5;
  const MANUAL_STORE = 'manual_scores';
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
  const retrySyncButton = document.getElementById('impactRetrySync');
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
  let syncInProgress = false;
  let lastSyncFailure = null;
  let syncSocket = null;
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
        if (!d.objectStoreNames.contains(MANUAL_STORE)) d.createObjectStore(MANUAL_STORE, {keyPath:'key'});
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

  function setStatus(kind, text, pending = null, detail = '') {
    statusEl.classList.remove('synced','local','offline','error');
    statusEl.classList.add(kind);
    const icon = statusEl.querySelector('i');
    if (icon) icon.className = kind === 'synced' ? 'fa-solid fa-circle-check' : kind === 'error' ? 'fa-solid fa-triangle-exclamation' : kind === 'offline' ? 'fa-solid fa-wifi-slash' : 'fa-solid fa-mobile-screen';
    statusText.textContent = text;
    if (pending !== null) {
      pendingText.textContent = pending > 0 ? pending + ' change' + (pending === 1 ? '' : 's') + ' waiting' : '';
      retrySyncButton.hidden = pending < 1 || !cacheAvailable;
    }
    statusEl.title = detail ? detail.slice(0,300) : '';
  }

  async function pendingCount() {
    if (!db) return 0;
    const all = await idbAll();
    return all.filter(r => r && r.dirty && Number(r.orgId) === Number(CONFIG.orgId) && Number(r.userId) === Number(CONFIG.userId)).length;
  }

  async function refreshStatus(fallbackKind = null, fallbackText = null) {
    const pending = await pendingCount().catch(() => 0);
    if (!cacheAvailable) return setStatus('error', 'Device cache unavailable', pending);
    // navigator.onLine is only a browser hint, not proof that this server is
    // unreachable. A queued record is never marked synced until an HTTP ACK.
    if (pending > 0) return setStatus(fallbackKind || 'local', fallbackText || 'Saved on device · awaiting server confirmation', pending);
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

  // Neptune's MERCURY WebSocket carries notifications, not database writes.
  // A ready/poke event can wake the queue; authenticated HTTP POST is the
  // only success acknowledgement that can remove a durable local record.
  function startSyncSocket() {
    if (syncSocket || !window.NeptuneRealtime?.create) return;
    try {
      syncSocket = window.NeptuneRealtime.create({
        url: <?=json_encode(base_url('rr-realtime'))?>,
        authUrl: <?=json_encode(base_url('api/realtime-token.php'))?>,
        room: '',
        onStatus: status => { if (status === 'socket') scheduleSync(0); },
        onPoke: () => scheduleSync(0)
      });
    } catch (_) { /* HTTP retry remains available without MERCURY. */ }
  }

  async function syncRecord(record) {
    if (!record?.dirty) return true;
    // Even if Chrome reports offline, attempt the real POST. Some mobile
    // browsers have a stale navigator.onLine flag during a working session.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    try {
      const response = await fetch(location.pathname, {
        method: 'POST', body: formDataFor(record), credentials: 'same-origin',
        cache: 'no-store', signal: controller.signal,
        headers: { 'Accept':'application/json', 'X-Requested-With':'TagScouting' }
      });
      const type = response.headers.get('content-type') || '';
      const payload = type.includes('application/json') ? await response.json().catch(() => null) : null;
      if (!response.ok || !payload?.ok) {
        const detail = String(payload?.error || ('HTTP '+ response.status));
        const sessionProblem = response.status === 401 || response.status === 403 ||
          response.redirected || (!type.includes('application/json') && response.ok) ||
          /csrf|session|login|authentication/i.test(detail);
        lastSyncFailure = sessionProblem
          ? {kind:'error',text:'Session needs attention · saved on this device',detail}
          : {kind:'error',text:'Server rejected sync · saved on this device',detail};
        return false;
      }

      // Commit only if no newer edit was made while the POST was in flight.
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
      lastSyncFailure = {
        kind: 'offline',
        text: 'Connection failed · saved on this device',
        detail: err?.name === 'AbortError' ? 'Neptune did not respond within 12 seconds.' : String(err?.message || 'Network request failed.')
      };
      return false;
    } finally { clearTimeout(timer); }
  }

  async function syncPending() {
    if (!db || syncInProgress) return;
    syncInProgress = true;
    try {
      const all = await idbAll();
      const pending = all.filter(r => r && r.dirty && Number(r.orgId) === Number(CONFIG.orgId) && Number(r.userId) === Number(CONFIG.userId));
      if (!pending.length) { lastSyncFailure = null; return await refreshStatus(); }
      lastSyncFailure = null;
      setStatus('local','Saved on device · contacting Neptune…',pending.length);
      let failed = false;
      for (const record of pending) if (!(await syncRecord(record))) failed = true;
      await markLocalCards();
      if (failed) {
        const remaining = await pendingCount().catch(() => pending.length);
        const reason = lastSyncFailure || {kind:'offline',text:'Sync not confirmed · saved on this device',detail:''};
        return setStatus(reason.kind,reason.text,remaining,reason.detail);
      }
      await refreshStatus();
    } catch (err) {
      const count = await pendingCount().catch(() => 0);
      setStatus('error', 'Sync queue needs attention · device data retained',count,String(err?.message || 'Local sync queue error.'));
    } finally { syncInProgress = false; }
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
    if (typeof updateRobotPager === 'function') updateRobotPager();
    const r = ROBOTS[String(team)] || ROBOTS[team];
    titleEl.textContent = '#' + team + ' ' + (r.nickname || '');
    stationEl.textContent = String(r.alliance || '').toUpperCase() + ' ALLIANCE · STATION ' + r.station + (CONFIG.matchLabel ? ' · ' + CONFIG.matchLabel : '');
    const activeLogo = document.getElementById('impactActiveRobotLogo');
    if (activeLogo) {
      const fallback = document.createElement('i'); fallback.className = 'fa-solid fa-robot';
      activeLogo.replaceChildren();
      if (r.logo) {
        const image = document.createElement('img'); image.src = r.logo; image.alt = '';
        image.addEventListener('error', () => image.replaceWith(fallback), {once:true});
        activeLogo.appendChild(image);
      } else activeLogo.appendChild(fallback);
    }
    renderRobotCardSummary(team);
    renderConsensus(team);
    const state = await getState(team);
    if (token !== selectionToken) return;
    applyState(state);
    await refreshStatus();
  }

  // Restore the exact viewport after a match change, and announce the new match.
  // sessionStorage is per-tab, avoiding cross-tab navigation interference.
  const tagDefinitionsByKey = {"auto_no_attempt": {"category": "Autonomous", "title": "No Attempt", "when": "Use when the robot made no autonomous attempt.", "why": "Counts explicit non-participation in observed autonomous periods."}, "auto_partial": {"category": "Autonomous", "title": "Partial Completion", "when": "Use when the robot achieved some but not all of its autonomous objective.", "why": "Enables a partial-completion rate; this alone does not measure autonomous points."}, "auto_completed": {"category": "Autonomous", "title": "Completed Successfully", "when": "Use when the planned autonomous objective was completed.", "why": "Enables completion rate from observed outcomes; can be compared alongside autonomous EPA."}, "auto_failed": {"category": "Autonomous", "title": "Failed", "when": "Use when autonomous was attempted but did not achieve its objective.", "why": "Enables failure rate from observed outcomes."}, "no_scoring": {"category": "Scoring", "title": "No Scoring", "when": "Use only when you observed the robot make no scoring contribution.", "why": "Creates an explicit no-scoring observation rather than treating a blank as zero."}, "scoring": {"category": "Scoring", "title": "Scoring", "when": "Use when the robot visibly contributed to scoring.", "why": "Identifies scoring participation; estimated scoring share provides a separate numerical estimate."}, "slow_cycles": {"category": "Scoring", "title": "Slow Cycles", "when": "Use when the robot repeatedly completes scoring cycles slowly relative to peers in this match.", "why": "Qualitative speed indicator; timed cycles are needed for quantitative rates."}, "fast_cycles": {"category": "Scoring", "title": "Fast Cycles", "when": "Use when the robot repeatedly completes scoring cycles quickly relative to peers in this match.", "why": "Qualitative speed indicator; not a measured cycle time or proof of higher EPA."}, "no_defense": {"category": "Defense", "title": "No Defense", "when": "Use when the robot did not attempt to defend an opponent.", "why": "Tracks defensive participation; it does not mean poor defense."}, "attempted_defense": {"category": "Defense", "title": "Attempted Defense", "when": "Use when the robot actively attempted to obstruct an opponent.", "why": "Tracks defensive participation; D-EPA is a separate modeled estimate, not a direct measurement of this attempt."}, "mechanical_issues": {"category": "Reliability", "title": "Mechanical Issue", "when": "Use when an observable mechanical fault impaired performance.", "why": "Tracks mechanical-issue incidence, not the fault cause or severity."}, "disabled": {"category": "Reliability", "title": "Disabled", "when": "Use when the robot became unable to continue participating.", "why": "Counts disabled observations per robot-match, helping quantify reliability."}, "recovered": {"category": "Reliability", "title": "Recovered", "when": "Use when the robot returned to operation after a problem during the match.", "why": "Tracks observed recoveries; can coexist with Disabled or Mechanical Issue."}, "end_no_attempt": {"category": "Endgame", "title": "No Attempt", "when": "Use when the robot made no endgame attempt.", "why": "Counts explicit non-attempts."}, "end_partial": {"category": "Endgame", "title": "Partial Completion", "when": "Use when the endgame objective was only partly achieved.", "why": "Supports a partial-outcome rate."}, "end_completed": {"category": "Endgame", "title": "Completed Successfully", "when": "Use when the robot completed its intended endgame objective.", "why": "Supports endgame completion rate from observed outcomes."}, "end_failed": {"category": "Endgame", "title": "Failed", "when": "Use when the robot tried the endgame objective and did not complete it.", "why": "Supports failure-rate reporting."}, "strong_support": {"category": "Alliance Play", "title": "Strong Support", "when": "Use when the robot materially assisted an alliance partner through feeding, positioning, or other support.", "why": "Records support participation; assigning points or EPA credit requires additional evidence."}, "penalty_risk": {"category": "Alliance Play", "title": "Penalty Risk", "when": "Use after observing behavior likely to incur fouls or penalties, not merely aggressive driving.", "why": "Tracks potential penalty risk; use official foul data to quantify actual penalty points."}};
  const settingsTagHost=document.getElementById('impactSettingsTagSections');
  const settingsCatalog=<?=json_encode($settingsCatalog,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const settingsApprovedMatch=<?=json_encode(array_keys($matchTagByKey))?>;
  if(settingsTagHost){
    settingsTagHost.replaceChildren();
    for(const [context,label] of [['match','Match'],['pit','Pit'],['team','Alliance']]){
      const section=document.createElement('details');section.className='impact-settings-category';
      const rootTitle=document.createElement('summary');rootTitle.textContent=label+' tag guide';section.append(rootTitle);
      const groups=new Map();
      for(const tag of settingsCatalog){
        if(Number(tag.active)!==1||Number(tag[context+'_enabled'])!==1)continue;
        if(context==='match'&&!settingsApprovedMatch.includes(String(tag.seed_key||'').replace(/^match:/,'').replaceAll('-','_'))&&!settingsApprovedMatch.includes(String(tag.slug||'').replaceAll('-','_')))continue;
        const cat=tag.category||'General';if(!groups.has(cat))groups.set(cat,[]);groups.get(cat).push(tag);
      }
      for(const [category,items] of groups){
        const group=document.createElement('details');group.className='impact-settings-category';
        const title=document.createElement('summary');title.textContent=category+' · '+items.length+' tags';group.append(title);
        for(const tag of items){const button=document.createElement('button');button.type='button';button.textContent=tag.label;
          button.addEventListener('click',()=>{document.getElementById('impactTagInfoArea').textContent=category;
            document.getElementById('impactTagInfoTitle').textContent=tag.label;
            document.getElementById('impactTagInfoWhen').textContent=tag.when_to_use||tag.description||'No guidance provided';
            document.getElementById('impactTagInfoWhy').textContent=tag.why_important||'No rationale provided';
            document.getElementById('impactSettingsDialog')?.close();document.getElementById('impactTagInfoDialog')?.showModal();});group.append(button);}
        section.append(group);
      }
      settingsTagHost.append(section);
    }
  }

  const editedTagHelp = <?=json_encode(array_map(static fn($t)=>['when'=>(string)($t['when_to_use']??''),'why'=>(string)($t['why_important']??''),'description'=>(string)($t['description']??'')],$matchTagByKey),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
  const tagInfo = document.getElementById('impactTagInfoDialog');
  function openTagDescription(label) {
    const key=label.querySelector('input[name="tags[]"]')?.value;
    const fallback=tagDefinitionsByKey[key]||{};
    const edited=editedTagHelp[key]||{};
    document.getElementById('impactTagInfoArea').textContent=(label.dataset.tagArea||'').toUpperCase();
    document.getElementById('impactTagInfoTitle').textContent=TAG_CATALOG[key]?.[0]||key||'Tag';
    document.getElementById('impactTagInfoWhen').textContent=edited.when||edited.description||fallback.when||'Select when you directly observe this behavior.';
    document.getElementById('impactTagInfoWhy').textContent=edited.why||fallback.why||'Preserves an observation for strategy review.';
    tagInfo?.showModal();
  }
  document.querySelectorAll('[data-impact-tag-info-close]').forEach(b=>b.addEventListener('click',()=>tagInfo?.close()));
  tagInfo?.addEventListener('click', event=>{ if (event.target===tagInfo) tagInfo.close(); });
  // Long-press works with pointer/touch/pen. Prevent the subsequent synthesized click from tagging.
  document.querySelectorAll('.impact-tag').forEach(label => {
    let timer = null, origin = null, fired = false, swallow = false;
    const clear = () => { if (timer !== null) clearTimeout(timer); timer = null; };
    label.addEventListener('pointerdown', event => {
      if (event.button !== 0 || tagInfo?.open) return;
      clear(); fired=false; origin={x:event.clientX,y:event.clientY};
      timer=setTimeout(()=>{timer=null;fired=true;swallow=true;openTagDescription(label);}, 550);
    });
    label.addEventListener('pointermove',event=>{if(origin && Math.hypot(event.clientX-origin.x,event.clientY-origin.y)>12) clear();});
    label.addEventListener('pointerup', clear);
    label.addEventListener('pointercancel',()=>{clear();origin=null;});
    label.addEventListener('pointerleave',clear);
    label.addEventListener('contextmenu',event=>{event.preventDefault();clear();swallow=true;openTagDescription(label);});
    label.addEventListener('click',event=>{if(swallow || fired){event.preventDefault();event.stopImmediatePropagation();const checkbox=label.querySelector('input');if(checkbox)checkbox.checked=checkbox.dataset.beforeHold==='1';swallow=false;fired=false;}},true);
    label.addEventListener('pointerdown',()=>{const checkbox=label.querySelector('input');if(checkbox)checkbox.dataset.beforeHold=checkbox.checked?'1':'0';},true);
  });
  const settingsDialog = document.getElementById('impactSettingsDialog');
  document.getElementById('impactSettingsOpen')?.addEventListener('click',()=>settingsDialog?.showModal());
  document.querySelectorAll('[data-impact-settings-close]').forEach(b=>b.addEventListener('click',()=>settingsDialog?.close()));
  settingsDialog?.addEventListener('click',event=>{if(event.target===settingsDialog)settingsDialog.close();});
  function savedBoolean(key, fallback) { try {const v=localStorage.getItem(key);return v===null?fallback:v==='1';}catch(_){return fallback;} }
  function savedPreference(input, key, defaultValue, update) {
    if(!input)return;
    input.checked=savedBoolean(key,defaultValue);update(input.checked);
    input.addEventListener('change',()=>{try{localStorage.setItem(key,input.checked?'1':'0');}catch(_){}update(input.checked);});
  }
  shell?.classList.remove('compact-tags');
  try{localStorage.removeItem('neptune.tag.compact')}catch(_){}
  savedPreference(document.getElementById('impactTagHaptics'),'neptune.tag.haptics',true,()=>{});
  const scoringInfoDialog = document.getElementById('impactScoringInfoDialog');
  document.getElementById('impactScoringInfoOpen')?.addEventListener('click', () => {
    if (scoringInfoDialog?.showModal) scoringInfoDialog.showModal();
  });
  scoringInfoDialog?.querySelectorAll('[data-impact-scoring-close]').forEach(button => {
    button.addEventListener('click', () => scoringInfoDialog.close());
  });
  scoringInfoDialog?.addEventListener('click', event => {
    if (event.target === scoringInfoDialog) scoringInfoDialog.close();
  });
  const NAV_KEY = 'neptune.tag.matchNav';
  const STATION_PREF_KEY = 'neptune.tag.rememberStation';
  const rememberStationToggle = document.getElementById('impactRememberStation');
  let rememberStation = true;
  try { rememberStation = localStorage.getItem(STATION_PREF_KEY) !== '0'; } catch (_) {}
  if (rememberStationToggle) {
    rememberStationToggle.checked = rememberStation;
    rememberStationToggle.addEventListener('change', () => {
      rememberStation = rememberStationToggle.checked;
      try { localStorage.setItem(STATION_PREF_KEY, rememberStation ? '1' : '0'); } catch (_) {}
    });
  }
  let stationToRestore = null;

  let navigationToastTimer;
  let navigationToast;
  function showNavigationToast(message, type = 'normal') {
    if (!navigationToast) {
      navigationToast = document.createElement('div');
      navigationToast.className = 'impact-navigation-toast';
      navigationToast.setAttribute('role','status');
      navigationToast.setAttribute('aria-live','polite');
      document.body.appendChild(navigationToast);
    }
    clearTimeout(navigationToastTimer);
    navigationToast.classList.remove('visible');
    navigationToast.classList.toggle('warning', type === 'warning');
    navigationToast.textContent = message;
    requestAnimationFrame(() => navigationToast.classList.add('visible'));
    navigationToastTimer = setTimeout(() => navigationToast.classList.remove('visible'), 1700);
  }
  try {
    const savedNav = JSON.parse(sessionStorage.getItem(NAV_KEY) || 'null');
    sessionStorage.removeItem(NAV_KEY);
    if (savedNav && savedNav.path === location.pathname &&
        savedNav.match === String(CONFIG.matchId) &&
        Date.now() - savedNav.time < 20000) {
      if (savedNav.rememberStation && ['Red','Blue'].includes(savedNav.alliance) && Number(savedNav.station)>0) {
        stationToRestore = { alliance:savedNav.alliance, station:Number(savedNav.station) };
      }
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
      const restore = () => window.scrollTo({left:0, top: savedNav.y, behavior:'instant'});
      restore();
      requestAnimationFrame(restore);
      window.addEventListener('load', restore, {once:true});
      showNavigationToast('Now scouting ' + (CONFIG.matchLabel || 'selected match'));
    }
  } catch (_) { /* storage disabled: ordinary navigation still works */ }

  // Match/robot navigation is scoped to published station assignments.
  // Match switching commits the current unsaved UI to IndexedDB before leaving.
  const matchStepLinks = [...document.querySelectorAll('[data-impact-match-step]')];
  const ROBOT_ORDER = robotButtons.map(button => Number(button.dataset.impactTeam));
  const robotIndex = document.getElementById('impactRobotIndex');
  let matchNavigating = false;

  function updateRobotPager() {
    const activeRobot = ROBOTS[String(currentTeam)] || ROBOTS[currentTeam] || {};
    const wheelLogo = document.getElementById('impactWheelLogo');
    const wheelNumber = document.getElementById('impactWheelNumber');
    if (wheelNumber) wheelNumber.textContent = '#' + currentTeam;
    if (wheelLogo) {
      wheelLogo.replaceChildren();
      const fallback = document.createElement('i'); fallback.className='fa-solid fa-robot';
      if (activeRobot.logo) {
        const img = document.createElement('img'); img.src=activeRobot.logo; img.alt='';
        img.addEventListener('error', () => img.replaceWith(fallback), {once:true});
        wheelLogo.append(img);
      } else wheelLogo.append(fallback);
    }
    const idx = ROBOT_ORDER.indexOf(Number(currentTeam));
    if (robotIndex) robotIndex.textContent = (idx >= 0 ? idx + 1 : 0) + ' / ' + ROBOT_ORDER.length;
    document.getElementById('impactPrevRobot')?.toggleAttribute('disabled', idx <= 0);
    document.getElementById('impactNextRobot')?.toggleAttribute('disabled', idx < 0 || idx === ROBOT_ORDER.length - 1);
  }
  async function stepRobot(direction) {
    const idx = ROBOT_ORDER.indexOf(Number(currentTeam));
    const team = ROBOT_ORDER[idx + direction];
    if (!team) return;
    tapFeedback();
    await selectRobot(team);
    updateRobotPager();
    const header = document.getElementById('impactRobotGesture');
    const bounds = header?.getBoundingClientRect();
    if (!bounds || bounds.bottom <= 0 || bounds.top >= window.innerHeight) {
      const selected = ROBOTS[String(team)] || ROBOTS[team];
      showNavigationToast('Robot #' + team + (selected?.nickname ? ' · ' + selected.nickname : ''));
    }
  }
  async function navigateToMatch(url) {
    if (matchNavigating || !url || url === '#') return;
    matchNavigating = true;
    try {
      // A zero-percent score is a valid estimate; only -1 means not rated.
      // This warning is deliberately non-blocking and never discards observations.
      if (currentTeam && ratingValue('scoring_contribution') < 0) {
        const robot = ROBOTS[String(currentTeam)] || ROBOTS[currentTeam];
        showNavigationToast('No scoring share for #' + currentTeam + (robot?.nickname ? ' · ' + robot.nickname : ''), 'warning');
        await new Promise(resolve => setTimeout(resolve, 1150));
      }
      if (uiHasChanges()) {
        // Without IndexedDB, do not silently abandon pending observations.
        if (!db) {
          const record = await saveCurrentLocal();
          if (!record || !(await syncRecord(record))) throw new Error('Offline storage is unavailable.');
        } else {
          await saveCurrentLocal();
          // The record is durable locally; it may sync on a later visit.
          scheduleSync(0);
        }
      }
      try {
        const target = new URL(url, location.href);
        sessionStorage.setItem(NAV_KEY, JSON.stringify({
          path:target.pathname, match:target.searchParams.get('match_id'),
          y:window.scrollY, time:Date.now(), rememberStation,
          alliance:rememberStation ? (ROBOTS[String(currentTeam)] || ROBOTS[currentTeam] || {}).alliance : null,
          station:rememberStation ? (ROBOTS[String(currentTeam)] || ROBOTS[currentTeam] || {}).station : null
        }));
      } catch (_) { /* navigation must work even without storage */ }
      window.location.assign(url);
    } catch (error) {
      matchNavigating = false;
      setStatus('error', 'Could not safely save; match unchanged');
      window.alert('Your current observation could not be stored. Stay on this match and check the device connection.');
    }
  }
  matchStepLinks.forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    if (link.getAttribute('aria-disabled')==='true') return;
    navigateToMatch(link.href);
  }));
  // Press and hold Next Robot for a six-station quick picker. A short tap
  // retains the original next-robot behavior. Also works with a mouse or pen.
  let pickerDialog = null;
  async function showPickerRobotDetails(team,robot){
    pickerDialog?.close();
    let dialog=document.getElementById('impactRobotDetailDialog');
    if(!dialog){dialog=document.createElement('dialog');dialog.id='impactRobotDetailDialog';dialog.className='impact-quick-picker';document.body.appendChild(dialog);}
    dialog.replaceChildren();
    const head=document.createElement('div');head.className='impact-quick-picker-head';
    const title=document.createElement('b');title.textContent='#'+team+' · '+(robot.nickname||'Robot details');
    const close=document.createElement('button');close.type='button';close.className='impact-quick-picker-close';close.textContent='×';close.addEventListener('click',()=>dialog.close());head.append(title,close);
    const body=document.createElement('div');body.className='impact-quick-picker-list';body.style.cssText='display:block!important;overflow:auto!important;max-height:55dvh!important;padding:18px!important';
    const facts=document.createElement('div');body.append(facts);
    function render(r){facts.replaceChildren();for(const [label,value] of Object.entries({'Team':team,'Name':r.nickname||robot.nickname,'City':r.city,'State / Province':r.state_prov,'Country':r.country,'Rookie year':r.rookie_year,'School / organization':r.school_name,'Motto':r.motto,'Full name':r.full_name,'Alliance':robot.alliance,'Station':robot.station})){
      if(value===null||value===undefined||String(value).trim()==='')continue;
      const row=document.createElement('p');row.textContent=label+': '+value;facts.append(row);
    }if(r.website&&/^https?:\/\//i.test(r.website)){const a=document.createElement('a');a.href=r.website;a.target='_blank';a.rel='noopener noreferrer';a.textContent='Team website';body.append(a);}}
    render(robot);
    const status=document.createElement('p');status.className='impact-settings-note';status.textContent='Loading Team Lookup / TBA information…';body.append(status);
    dialog.append(head,body);dialog.showModal();
    const eventId=Number(document.getElementById('impactEvent')?.value||0);
    if(!eventId){status.textContent='Select an event to retrieve TBA information.';return;}
    try{const response=await fetch(<?=json_encode(base_url('scout/tag-api.php'))?>+'?action=robot_details&event_id='+encodeURIComponent(eventId)+'&team='+encodeURIComponent(team));
      const data=await response.json();if(!response.ok||data.status!=='success')throw new Error('Unavailable');
      if(dialog.open){render({...robot,...data.team});status.textContent='Public team facts from TBA / Neptune Team Lookup.';}
    }catch(error){status.textContent='Showing locally available details. TBA facts could not be loaded.';}
  }
  function openRobotQuickPicker() {
    if (!ROBOT_ORDER.length) return;
    if (!pickerDialog) {
      pickerDialog = document.createElement('dialog');
      pickerDialog.className = 'impact-quick-picker';
      pickerDialog.setAttribute('aria-label', 'Choose a robot in this match');
      pickerDialog.innerHTML = '<div class="impact-quick-picker-head"><div><b class="impact-picker-match-label"></b><small class="impact-picker-robot-label"></small></div><button type="button" class="impact-quick-picker-close" aria-label="Close robot picker"><i class="fa-solid fa-xmark"></i></button></div><div class="impact-quick-picker-list"></div>';
      document.body.appendChild(pickerDialog);
      // Suppress native selection/context menus in the body-mounted picker.
      // Normal editing of scouting notes outside this dialog is unaffected.
      pickerDialog.addEventListener('selectstart', event => event.preventDefault());
      pickerDialog.addEventListener('contextmenu', event => event.preventDefault());
      pickerDialog.addEventListener('dragstart', event => event.preventDefault());
      pickerDialog.addEventListener('pointerdown', event => {
        if (event.pointerType === 'touch' || event.pointerType === 'pen') {
          window.getSelection()?.removeAllRanges();
        }
      }, {passive:true});
      pickerDialog.querySelector('.impact-quick-picker-close').addEventListener('click', () => pickerDialog.close());
      pickerDialog.addEventListener('click', event => { if (event.target === pickerDialog) pickerDialog.close(); });
    }
    const selected = ROBOTS[String(currentTeam)] || ROBOTS[currentTeam] || {};
    pickerDialog.querySelector('.impact-picker-match-label').textContent = CONFIG.matchLabel || 'Current Match';
    const stationText = String(selected.alliance || '').trim();
    pickerDialog.querySelector('.impact-picker-robot-label').textContent = '#' + currentTeam + ' · ' + (stationText ? stationText + ' Alliance · ' : '') + 'Station ' + (selected.station || '—');
    const list = pickerDialog.querySelector('.impact-quick-picker-list');
    list.replaceChildren();
    const orderedPickerTeams = [...ROBOT_ORDER].sort((a,b) => {
      const ra = ROBOTS[String(a)] || ROBOTS[a] || {};
      const rb = ROBOTS[String(b)] || ROBOTS[b] || {};
      const rank = r => String(r.alliance || '').toLowerCase() === 'red' ? 0 : 1;
      return rank(ra) - rank(rb) || Number(ra.station || 0) - Number(rb.station || 0);
    });
    orderedPickerTeams.forEach(team => {
      const robot = ROBOTS[String(team)] || ROBOTS[team] || {};
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'impact-quick-picker-team';
      button.dataset.alliance = String(robot.alliance || '');
      button.setAttribute('aria-current', String(Number(currentTeam) === team));
      button.dataset.alliance = String(robot.alliance || '');
      const stationIndex = Math.max(1, Math.min(3, Number(robot.station) || 1));
      button.style.gridColumn = String(String(robot.alliance).toLowerCase() === 'blue' ? 2 : 1);
      button.style.gridRow = String(stationIndex);
      const number = document.createElement('span'); number.className = 'number'; number.textContent = '#' + team;
      const details = document.createElement('span'); details.className = 'details';
      const name = document.createElement('b'); name.textContent = robot.nickname || 'Team ' + team;
      const station = document.createElement('small'); station.textContent = (robot.alliance || '') + ' · Station ' + (robot.station || '—');
      details.append(name, station);
      const avatar = document.createElement('span'); avatar.className = 'impact-picker-avatar';
      if (robot.logo) {
        const image = document.createElement('img'); image.src = robot.logo; image.alt = ''; image.loading = 'lazy';
        image.addEventListener('error', () => { image.remove(); avatar.textContent = '🤖'; });
        avatar.append(image);
      } else { const fallback = document.createElement('i'); fallback.className = 'fa-solid fa-robot'; avatar.append(fallback); }
      const identity = document.createElement('span'); identity.className = 'impact-picker-identity';
      identity.append(number, station);
      button.append(avatar, identity);
      let robotHeld=false,robotHoldTimer=null,robotStartX=0,robotStartY=0;
      button.addEventListener('pointerdown',event=>{if(event.pointerType==='mouse'&&event.button!==0)return;robotHeld=false;robotStartX=event.clientX;robotStartY=event.clientY;clearTimeout(robotHoldTimer);robotHoldTimer=setTimeout(()=>{robotHeld=true;showPickerRobotDetails(team,robot);},550);});
      button.addEventListener('pointermove',event=>{if(Math.hypot(event.clientX-robotStartX,event.clientY-robotStartY)>16)clearTimeout(robotHoldTimer);});
      ['pointerup','pointerleave','pointercancel'].forEach(type=>button.addEventListener(type,()=>clearTimeout(robotHoldTimer)));
      button.addEventListener('contextmenu',event=>{event.preventDefault();robotHeld=true;showPickerRobotDetails(team,robot)});
      button.addEventListener('click', async () => {
        if(robotHeld){robotHeld=false;return;}

        pickerDialog.close();
        tapFeedback();
        await selectRobot(team);
        updateRobotPager();
        const header = document.getElementById('impactRobotGesture');
        const bounds = header?.getBoundingClientRect();
        if (!bounds || bounds.bottom <= 0 || bounds.top >= window.innerHeight) {
          showNavigationToast('Robot #' + team + (robot.nickname ? ' · ' + robot.nickname : ''));
        }
      });
      list.appendChild(button);
    });
    window.getSelection()?.removeAllRanges();
    if (!pickerDialog.open) pickerDialog.showModal();
    // All six targets fit within the bottom sheet; never scroll the picker.
  }
  function installRobotLongPress(button, shortTap) {
    if (!button) return;
    let timer = null;
    let tracking = false;
    let activePointer = null;
    let startX = 0, startY = 0;
    let lastHoldAt = 0;
    let suppressClick = false;
    const cancelTimer = () => { if (timer !== null) clearTimeout(timer); timer = null; };
    const openPickerFromHold = () => {
      if (!tracking || suppressClick) return;
      cancelTimer();
      suppressClick = true;
      lastHoldAt = Date.now();
      tracking = false;
      tapFeedback();
      openRobotQuickPicker();
    };
    button.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      if (!event.isPrimary) return;
      cancelTimer();
      tracking = true;
      suppressClick = false;
      activePointer = event.pointerId;
      startX = event.clientX;
      startY = event.clientY;
      // Capture keeps the press on the button when a finger wanders over its children.
      try { button.setPointerCapture(event.pointerId); } catch (_) {}
      timer = setTimeout(openPickerFromHold, 480);
    });
    button.addEventListener('pointermove', event => {
      if (!tracking || event.pointerId !== activePointer) return;
      // Natural touch jitter is greater than mouse movement. Only cancel a
      // deliberate drag, not a slight movement during a sustained press.
      const tolerance = event.pointerType === 'mouse' ? 14 : 30;
      if (Math.hypot(event.clientX - startX, event.clientY - startY) > tolerance) {
        tracking = false;
        cancelTimer();
      }
    });
    button.addEventListener('pointerup', event => {
      if (event.pointerId !== activePointer) return;
      tracking = false;
      cancelTimer();
    });
    button.addEventListener('pointercancel', event => {
      if (event.pointerId !== activePointer) return;
      tracking = false;
      cancelTimer();
    });
    button.addEventListener('lostpointercapture', () => {
      // Capture can be released when the dialog opens; never erase hold suppression.
      tracking = false;
      cancelTimer();
    });
    button.addEventListener('contextmenu', event => {
      event.preventDefault();
      // Some mobile browsers dispatch contextmenu before the 480ms timer.
      // Treat it as a long hold rather than silently cancelling the picker.
      if (tracking) openPickerFromHold();
    });
    button.addEventListener('click', event => {
      if (suppressClick || Date.now() - lastHoldAt < 850) {
        event.preventDefault();
        event.stopImmediatePropagation();
        suppressClick = false;
        return;
      }
      shortTap();
    });
    button.addEventListener('keydown', event => {
      if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
        event.preventDefault();
        openRobotQuickPicker();
      }
    });
  }
  document.getElementById('impactPrevRobot')?.addEventListener('click', () => stepRobot(-1));
  installRobotLongPress(document.getElementById('impactNextRobot'), () => stepRobot(1));
  installRobotLongPress(document.getElementById('impactDockNextRobot'), () => {
    const idx = ROBOT_ORDER.indexOf(Number(currentTeam));
    stepRobot(idx>=ROBOT_ORDER.length-1 ? -idx : 1);
  });
  robotButtons.forEach(btn => btn.addEventListener('click', updateRobotPager));

  // Horizontal swipes are limited to deliberate navigation surfaces, never to
  // sliders, tags, note fields, or other form controls.
  function registerSwipe(element, callback) {
    if (!element) return;
    let start = null;
    element.addEventListener('touchstart', e => {
      if (e.touches.length!==1 || e.target.closest('button, a, select, input, textarea')) {start=null;return;}
      start={x:e.touches[0].clientX,y:e.touches[0].clientY};
    }, {passive:true});
    element.addEventListener('touchend', e => {
      if (!start || !e.changedTouches.length) return;
      const dx=e.changedTouches[0].clientX-start.x;
      const dy=e.changedTouches[0].clientY-start.y;
      start=null;
      if (Math.abs(dx)<64 || Math.abs(dx)<Math.abs(dy)*1.3) return;
      callback(dx<0?1:-1);
    }, {passive:true});
    element.addEventListener('touchcancel',()=>{start=null;},{passive:true});
  }
  registerSwipe(document.getElementById('impactMatchGesture'), direction => {
    const link=document.querySelector('#impactMatchGesture [data-impact-match-step="'+direction+'"]');
    if (link && link.getAttribute('aria-disabled')!=='true') navigateToMatch(link.href);
  });
  registerSwipe(document.getElementById('impactRobotGesture'), direction => stepRobot(direction));
  document.querySelectorAll('[data-impact-category]').forEach(button => button.addEventListener('click',()=>{
    const category=button.dataset.impactCategory;
    document.querySelectorAll('[data-impact-category]').forEach(b=>{
      const active=b===button;b.classList.toggle('active',active);b.setAttribute('aria-pressed',String(active));
    });
    document.querySelectorAll('[data-impact-section]').forEach(section=>{
      section.hidden = category!=='all' && section.dataset.impactSection!==category;
    });
  }));
  document.addEventListener('keydown',event=>{
    if (event.altKey||event.ctrlKey||event.metaKey||event.target.closest('input,textarea,select,button,[contenteditable]')) return;
    if (event.key==='ArrowRight') stepRobot(1);
    if (event.key==='ArrowLeft') stepRobot(-1);
  });
  updateRobotPager();

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
  const tapFeedback = () => { try { if (navigator.vibrate) (savedBoolean('neptune.tag.haptics',true) && navigator.vibrate ? navigator.vibrate.bind(navigator) : ()=>{})(8); } catch (_) {} };
  robotButtons.forEach(btn => btn.addEventListener('click', () => {
    tapFeedback();
    const team = Number(btn.dataset.impactTeam);
    selectRobot(team);
    const bounds = document.getElementById('impactRobotGesture')?.getBoundingClientRect();
    if (!bounds || bounds.bottom <= 0 || bounds.top >= window.innerHeight) {
      const selected = ROBOTS[String(team)] || ROBOTS[team];
      showNavigationToast('Robot #' + team + (selected?.nickname ? ' · ' + selected.nickname : ''));
    }
  }));
  const exclusiveTagGroups = [
    ['auto_no_attempt',['auto_completed','auto_partial','auto_failed']],
    ['auto_completed',['auto_no_attempt','auto_partial','auto_failed']],
    ['auto_partial',['auto_no_attempt','auto_completed','auto_failed']],
    ['auto_failed',['auto_no_attempt','auto_completed','auto_partial']],
    ['end_no_attempt',['end_completed','end_partial','end_failed']],
    ['end_completed',['end_no_attempt','end_partial','end_failed']],
    ['end_partial',['end_no_attempt','end_completed','end_failed']],
    ['end_failed',['end_no_attempt','end_completed','end_partial']],
    ['no_defense',['attempted_defense']],
    ['attempted_defense',['no_defense']],
    ['no_scoring',['scoring','fast_cycles','slow_cycles']],
    ['scoring',['no_scoring']],
    ['fast_cycles',['no_scoring','slow_cycles']],
    ['slow_cycles',['no_scoring','fast_cycles']]
  ];
  function clearTag(tag) {
    const input = tagInputs.find(item => item.value === tag);
    if (input) input.checked = false;
    delete tagWeights[tag];
  }

  function enforceTagExclusivity(changed) {
    if (!changed?.checked) return;
    for (const [tag, peers] of exclusiveTagGroups) {
      if (changed.value === tag) peers.forEach(clearTag);
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
  retrySyncButton?.addEventListener('click', () => { scheduleSync(0); });
  window.addEventListener('online', () => scheduleSync(0));
  // Offline events are advisory; HTTP reachability still decides delivery.
  window.addEventListener('offline', () => scheduleSync(0));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) syncPending(); });
  window.addEventListener('pagehide', () => {
    if (uiHasChanges()) saveCurrentLocal().catch(()=>{});
    syncSocket?.close(); syncSocket = null;
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { startSyncSocket(); scheduleSync(0); } });


  // Separate offline queue: scores are match-wide, never part of a robot's Tag observation.
  const scoreDialog = document.getElementById('manualScoresDialog');
  const scoreOpen = document.getElementById('manualScoresOpen');
  const scoreForm = document.getElementById('manualScoresForm');
  const scoreRed = document.getElementById('manualRedScore');
  const scoreBlue = document.getElementById('manualBlueScore');
  const scoreStatus = document.getElementById('manualScoresStatus');
  const scoreSummary = document.getElementById('manualScoresSummary');
  const scoreOfficial = document.getElementById('manualOfficialNotice');
  const officialProvider = document.getElementById('manualOfficialSource');
  const officialRed = document.getElementById('manualOfficialRed');
  const officialBlue = document.getElementById('manualOfficialBlue');
  const scoreConflict = document.getElementById('manualScoresConflict');
  const scoreConflictText = document.getElementById('manualScoresConflictText');
  const scoreOverwrite = document.getElementById('manualScoresOverwrite');
  const scoreSave = document.getElementById('manualScoresSave');
  const scoreKey = [CONFIG.orgId,CONFIG.eventId,CONFIG.matchId,CONFIG.userId].join(':');
  let scoreRevision = Number(MANUAL_SERVER?.revision || 0);
  let scoreConflictServer = null;
  let scoreSyncBusy = false;
  let scoreQueued = null;
  function scoreNotify(text,kind='') {
    scoreStatus.textContent = text;
    scoreStatus.className = 'manual-score-status' + (kind ? ' '+kind : '');
  }
  function manualDbGet() {
    if(!db || !db.objectStoreNames.contains(MANUAL_STORE))return Promise.resolve(null);
    return new Promise((resolve,reject)=>{
      const request=db.transaction(MANUAL_STORE,'readonly').objectStore(MANUAL_STORE).get(scoreKey);
      request.onsuccess=()=>resolve(request.result || null);
      request.onerror=()=>reject(request.error);
    });
  }
  function manualDbPut(record) {
    if(!db || !db.objectStoreNames.contains(MANUAL_STORE))return Promise.reject(new Error('Device storage is unavailable.'));
    return new Promise((resolve,reject)=>{
      const tx=db.transaction(MANUAL_STORE,'readwrite');tx.objectStore(MANUAL_STORE).put(record);
      tx.oncomplete=()=>resolve();tx.onerror=()=>reject(tx.error);
    });
  }
  function manualDefault() {
    return {key:scoreKey,orgId:CONFIG.orgId,eventId:CONFIG.eventId,matchId:CONFIG.matchId,
      userId:CONFIG.userId,red:MANUAL_SERVER?.red_score ?? null,blue:MANUAL_SERVER?.blue_score ?? null,
      revision:Number(MANUAL_SERVER?.revision || 0),dirty:false,conflict:false,updatedAt:0};
  }
  function showManualRecord(r) {
    if(r?.red !== null && r?.red !== undefined)scoreRed.value=String(r.red);
    if(r?.blue !== null && r?.blue !== undefined)scoreBlue.value=String(r.blue);
    scoreRevision=Number(r?.revision || 0);
    if(r?.conflict){scoreNotify('Another scout updated this match. Review both scores before overwriting.','error');}
    else if(r?.dirty){scoreNotify('Saved on this device · awaiting server sync.');}
    else if(r?.red !== null && r?.red !== undefined){scoreNotify('Shared scores loaded · anyone on your team can edit.','good');}
    else scoreNotify('Enter the final score for each alliance.');
    if(r?.red !== null && r?.red !== undefined)scoreSummary.textContent='Manual: Red '+r.red+' · Blue '+r.blue+(r?.dirty?' · Waiting to sync':'');
    else scoreSummary.textContent='No manual final scores yet · All scouts may edit';
    renderOfficialScores();
  }
  function renderOfficialScores() {
    const present = OFFICIAL_SCORES.red !== null || OFFICIAL_SCORES.blue !== null;
    officialProvider.textContent = present ? String(OFFICIAL_SCORES.provider || 'Official') + ' final' : 'Not available';
    officialRed.textContent = OFFICIAL_SCORES.red !== null ? String(OFFICIAL_SCORES.red) : 'Awaiting score';
    officialBlue.textContent = OFFICIAL_SCORES.blue !== null ? String(OFFICIAL_SCORES.blue) : 'Awaiting score';
    officialRed.classList.toggle('unavailable', OFFICIAL_SCORES.red === null);
    officialBlue.classList.toggle('unavailable', OFFICIAL_SCORES.blue === null);
    if(OFFICIAL_SCORES.red !== null && OFFICIAL_SCORES.blue !== null){
      scoreOfficial.textContent = 'An official final is already available. Manual edits are saved as backup and will not override it.';
    }else if(present){
      scoreOfficial.textContent = 'Only part of the official result is available. A manual score is used for an alliance only if its official score is missing.';
    }else{
      scoreOfficial.textContent = 'No official final score imported yet. Manual scores will be used until FIRST/TBA results arrive.';
    }
  }
  async function getFreshManual() {
    const params=new URLSearchParams({event_id:String(CONFIG.eventId),match_id:String(CONFIG.matchId)});
    const response=await fetch(MANUAL_API+'?'+params.toString(),{credentials:'same-origin',cache:'no-store'});
    if(!response.ok)throw new Error('Score server unavailable');
    const data=await response.json();if(!data.ok)throw new Error(data.error || 'Score server unavailable');
    if(data.official && typeof data.official === 'object'){
      OFFICIAL_SCORES = {
        red: data.official.red ?? null,
        blue: data.official.blue ?? null,
        provider: String(data.official.provider || 'Official'),
      };
      renderOfficialScores();
    }
    return { ...manualDefault(),red:data.manual?.red_score ?? null,blue:data.manual?.blue_score ?? null,revision:Number(data.manual?.revision || 0)};
  }
  async function openManualScores() {
    scoreConflict.hidden=true;
    let current=await manualDbGet().catch(()=>null);
    if(!current)current=manualDefault();
    showManualRecord(current);
    scoreDialog.showModal();
    try{
      const remote=await getFreshManual();
      if(current.dirty){
        // Refresh official finals without discarding a queued offline edit.
        showManualRecord(current);
      }else{
        await manualDbPut(remote).catch(()=>{});
        showManualRecord(remote);
      }
    }catch(_){
      if(!current.dirty)scoreNotify('Server not reachable. Enter scores and save on this device.');
    }
  }
  async function syncManualPending() {
    if(scoreSyncBusy || !db)return;
    const record=await manualDbGet().catch(()=>null);
    if(!record?.dirty || record.conflict)return;
    scoreSyncBusy=true;
    try{
      const fd=new FormData();
      fd.set('csrf',document.getElementById('impactCsrf').value);
      fd.set('event_id',String(record.eventId));fd.set('match_id',String(record.matchId));
      fd.set('red_score',String(record.red));fd.set('blue_score',String(record.blue));
      fd.set('expected_revision',String(record.revision));
      const response=await fetch(MANUAL_API,{method:'POST',body:fd,credentials:'same-origin'});
      const payload=await response.json();
      if(response.status===409 && payload.conflict){
        record.conflict=true;scoreConflictServer=payload.current?.manual || null;
        await manualDbPut(record);
        if(scoreDialog.open){
          scoreNotify('Edit conflict · your values are safe on this device.','error');
          scoreConflictText.textContent='Another scout saved Red '+(scoreConflictServer?.red_score ?? '—')+' · Blue '+(scoreConflictServer?.blue_score ?? '—')+'. Your values: Red '+record.red+' · Blue '+record.blue+'.';
          scoreConflict.hidden=false;
        }
        return;
      }
      if(!response.ok || !payload.ok)throw new Error(payload.error || 'Sync failed');
      const latest=await manualDbGet();
      if(latest){
        latest.revision=Number(payload.manual?.revision || record.revision+1);
        if(latest.updatedAt===record.updatedAt){latest.dirty=false;latest.conflict=false;}
        // If edited again during the HTTP request, retain the newer draft and
        // advance its expected revision so it cannot conflict with itself.
        await manualDbPut(latest);
        if(scoreDialog.open)showManualRecord(latest);
        else scoreSummary.textContent='Manual: Red '+latest.red+' · Blue '+latest.blue+(latest.dirty?' · Waiting to sync':'');
      }
    }catch(_){if(scoreDialog.open)scoreNotify('Saved on this device; will retry when server is reachable.');}
    finally{scoreSyncBusy=false;}
  }
  async function saveManualScores(event){
    event.preventDefault();
    const red=Number(scoreRed.value),blue=Number(scoreBlue.value);
    if(!Number.isInteger(red)||!Number.isInteger(blue)||red<0||blue<0||red>2000||blue>2000||scoreRed.value===''||scoreBlue.value===''){
      scoreNotify('Enter two whole-number scores between 0 and 2000.','error');return;
    }
    scoreSave.disabled=true;
    try{
      const previous=await manualDbGet().catch(()=>null) || manualDefault();
      const next={...previous,red,blue,revision:scoreRevision,dirty:true,conflict:false,updatedAt:Date.now()};
      if(!db){
        if(navigator.onLine===false)throw new Error('Offline device storage unavailable. Scores were not saved.');
        throw new Error('Device storage unavailable. Please reload before entering scores.');
      }
      await manualDbPut(next);
      scoreConflict.hidden=true;showManualRecord(next);
      await syncManualPending();
    }catch(err){scoreNotify(err.message || 'Device save failed.','error');}
    finally{scoreSave.disabled=false;}
  }
  scoreOpen?.addEventListener('click',openManualScores);
  document.getElementById('manualScoresCancel')?.addEventListener('click',()=>scoreDialog.close());
  scoreForm?.addEventListener('submit',saveManualScores);
  scoreOverwrite?.addEventListener('click',async()=>{
    if(!scoreConflictServer)return;
    // Requires a deliberate second action; never silently overwrite a teammate.
    scoreRevision=Number(scoreConflictServer.revision || 0);
    scoreConflict.hidden=true;
    await saveManualScores({preventDefault(){}});
  });
  window.addEventListener('online',syncManualPending);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)syncManualPending();});

  ensureBlocks();
  paintSliderOutputs();

  (async () => {
    try {
      db = await openDb();
      await loadAdvancedMode();
      await cleanupOldCache();
      await markLocalCards();
      if (stationToRestore) {
        const matched = Object.keys(ROBOTS).find(team => {
          const r = ROBOTS[team];
          return r && r.alliance === stationToRestore.alliance && Number(r.station) === stationToRestore.station;
        });
        if (matched) currentTeam = Number(matched);
      }
      await selectRobot(currentTeam, false);
      if (stationToRestore) {
        const robot = ROBOTS[String(currentTeam)] || ROBOTS[currentTeam] || {};
        if (robot.alliance === stationToRestore.alliance && Number(robot.station) === stationToRestore.station) {
          showNavigationToast('Now scouting ' + robot.alliance + ' Station ' + robot.station + ' · #' + currentTeam);
        }
      }
      await syncPending();
      await syncManualPending();
      startSyncSocket();
      setInterval(() => {syncPending();syncManualPending();}, 5000);
    } catch (err) {
      cacheAvailable = false;
      try { await setAdvancedMode(false, false); } catch (_) {}
      setStatus('error','Device cache unavailable · changes require connection',0);
      if (stationToRestore) {
        const matched = Object.keys(ROBOTS).find(team => ROBOTS[team]?.alliance === stationToRestore.alliance && Number(ROBOTS[team]?.station) === stationToRestore.station);
        if (matched) { currentTeam = Number(matched); teamInput.value = String(currentTeam); updateRobotPager(); }
      }
      const state = fromServer(currentTeam) || defaultState(currentTeam);
      applyState(state);
    }
  })();
})();
</script>
<?php endif;?>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
