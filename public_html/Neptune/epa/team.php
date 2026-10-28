<?php
/**
 * Neptune Public Statistics — enriched, database-only team profile.
 *
 * Shared AUGUR EPA archive and cached public TBA metadata only. This page
 * deliberately does not read organization-owned scouting/event tables, and
 * it never calls tba_get() or another remote service during page rendering.
 */
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

function ns_team_table_exists(PDO $pdo, string $table): bool {
    static $found = [];
    if (array_key_exists($table, $found)) return $found[$table];
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$table]);
        return $found[$table] = ((int)$s->fetchColumn() > 0);
    } catch (Throwable $ignored) {
        return $found[$table] = false;
    }
}

/** Only previously cached TBA responses. Expired entries are historical snapshots, not live facts. */
function ns_team_cached_tba(PDO $pdo, string $path): array {
    if (!ns_team_table_exists($pdo, 'tba_cache')) return [];
    try {
        $s = $pdo->prepare('SELECT response_json, fetched_at FROM tba_cache WHERE cache_key = ? LIMIT 1');
        $s->execute(['tba:' . sha1($path)]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return [];
        $data = json_decode((string)$r['response_json'], true);
        return is_array($data) ? ['data' => $data, 'fetched_at' => (string)$r['fetched_at']] : [];
    } catch (Throwable $ignored) {
        return [];
    }
}

function ns_team_wlt($wins, $losses, $ties): string {
    return (int)$wins . '–' . (int)$losses . '–' . (int)$ties;
}
function ns_team_pct($v): string {
    return is_numeric($v) ? number_format((float)$v, 0) . '%' : '—';
}
function ns_team_date($date): string {
    if (!$date) return '';
    $t = strtotime((string)$date);
    return $t ? date('M j, Y', $t) : (string)$date;
}
function ns_team_numbers(array $r, string $prefix = 'team'): array {
    $names = [];
    foreach (['1', '2', '3'] as $num) {
        $v = (int)($r[$prefix . $num] ?? 0);
        if ($v > 0) $names[] = $v;
    }
    return array_values(array_unique($names));
}
function ns_team_event_link(string $key, int $year): string {
    return '/epa/events.php?' . http_build_query(['year' => $year, 'event' => $key]);
}
function ns_team_match_label(array $m): string {
    $level = strtolower((string)($m['comp_level'] ?? 'qm'));
    $set = (int)($m['set_number'] ?? 1);
    $num = (int)($m['match_number'] ?? 0);
    $labels = ['qm' => 'Qual', 'ef' => 'Eighth', 'qf' => 'Quarter', 'sf' => 'Semi', 'f' => 'Final'];
    $label = $labels[$level] ?? strtoupper($level);
    return $level === 'qm' ? $label . ' ' . $num : $label . ' ' . $set . '-' . $num;
}
function ns_team_svg_points(array $numbers, int $width = 820, int $height = 180): string {
    if (!$numbers) return '';
    $values = array_map('floatval', $numbers);
    $lo = min($values); $hi = max($values);
    if ($hi - $lo < 1) { $lo -= 1; $hi += 1; }
    $left = 12; $right = $width - 12; $top = 15; $bottom = $height - 18;
    $points = [];
    foreach ($values as $i => $v) {
        $x = $left + ($right - $left) * ($i / max(1, count($values) - 1));
        $y = $bottom - ($bottom - $top) * (($v - $lo) / ($hi - $lo));
        $points[] = round($x, 1) . ',' . round($y, 1);
    }
    return implode(' ', $points);
}

$years = ns_years($pdo);
$year = ns_year($years);
$teamInput = filter_input(INPUT_GET, 'team', FILTER_VALIDATE_INT);
$team = ($teamInput && $teamInput > 0 && $teamInput <= 999999) ? (int)$teamInput : 0;
$allMatches = isset($_GET['matches']) && $_GET['matches'] === 'all';
$matchLimit = $allMatches ? 160 : 24;

$history = [];
$rating = null;
$seasonRank = null;
$rankPool = 0;
$directory = [];
$events = [];
$matches = [];
$officialStatuses = [];
$awards = [];
$cachedTeam = [];
$latestCached = '';
$archiveReady = augur_epa_tables_ready($pdo);

if ($team > 0) {
    if ($archiveReady) {
        $s = $pdo->prepare('SELECT season_year, rating, auto_rating, teleop_rating, endgame_rating, trend, sigma, confidence, events_played, matches_played, wins, losses, ties, last_event_key, updated_at FROM augur_epa_season_ratings WHERE frc_team_number = ? ORDER BY season_year DESC');
        $s->execute([$team]);
        $history = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($history as $r) {
            if ((int)$r['season_year'] === $year) { $rating = $r; break; }
        }
        if ($rating) {
            $s = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN rating > ? THEN 1 ELSE 0 END) AS ahead FROM augur_epa_season_ratings WHERE season_year = ?');
            $s->execute([$rating['rating'], $year]);
            $position = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $rankPool = (int)($position['total'] ?? 0);
            $seasonRank = 1 + (int)($position['ahead'] ?? 0);
        }

        $s = $pdo->prepare('SELECT r.tba_event_key, r.season_year, r.rating, r.auto_rating, r.teleop_rating, r.endgame_rating, r.trend, r.confidence, r.matches_played, r.wins, r.losses, r.ties, e.name, e.city, e.state_prov, e.country, e.start_date, e.end_date, e.is_complete, (SELECT COUNT(*) + 1 FROM augur_epa_event_ratings r2 WHERE r2.tba_event_key = r.tba_event_key AND r2.rating > r.rating) AS epa_rank FROM augur_epa_event_ratings r JOIN augur_epa_archive_events e ON e.tba_event_key = r.tba_event_key WHERE r.frc_team_number = ? AND r.season_year = ? ORDER BY COALESCE(e.start_date, e.end_date) DESC, e.tba_event_key DESC LIMIT 80');
        $s->execute([$team, $year]);
        $events = $s->fetchAll(PDO::FETCH_ASSOC);

        // Only rated public matches; join public alliance scores to show partners/opponents.
        // The alliance points shown below are NOT individual robot contributions.
        $s = $pdo->prepare('SELECT m.tba_match_key, m.tba_event_key, m.season_year, m.match_number, m.alliance, m.pre_rating, m.post_rating, m.rating_delta, m.post_auto_rating, m.post_teleop_rating, m.post_endgame_rating, e.name AS event_name, e.start_date, mine.comp_level, mine.set_number, mine.team1 AS own1, mine.team2 AS own2, mine.team3 AS own3, mine.official_score AS own_score, mine.auto_score AS own_auto, mine.teleop_score AS own_teleop, mine.endgame_score AS own_endgame, other.team1 AS opp1, other.team2 AS opp2, other.team3 AS opp3, other.official_score AS other_score, other.auto_score AS opp_auto, other.teleop_score AS opp_teleop, other.endgame_score AS opp_endgame, CASE WHEN mine.id IS NOT NULL AND other.id IS NOT NULL THEN 1 ELSE 0 END AS has_result FROM augur_epa_match_ratings m JOIN augur_epa_archive_events e ON e.tba_event_key = m.tba_event_key LEFT JOIN augur_epa_alliance_samples mine ON mine.tba_match_key = m.tba_match_key AND mine.alliance = m.alliance LEFT JOIN augur_epa_alliance_samples other ON other.tba_match_key = m.tba_match_key AND other.alliance <> m.alliance WHERE m.frc_team_number = ? AND m.season_year = ? ORDER BY COALESCE(e.start_date, e.end_date) DESC, m.tba_event_key DESC, m.match_number DESC LIMIT ' . (int)$matchLimit);
        $s->execute([$team, $year]);
        $matches = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    if (ns_team_table_exists($pdo, 'augur_epa_team_directory')) {
        try {
            // Database installations may predate the optional rookie_year column.
            $s = $pdo->prepare('SELECT * FROM augur_epa_team_directory WHERE frc_team_number = ? ORDER BY (season_year = ?) DESC, season_year DESC LIMIT 1');
            $s->execute([$team, $year]);
            $directory = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $ignored) { $directory = []; }
    }

    // Optional cached TBA data, never fetched live. No organization-scoped records.
    $teamCache = ns_team_cached_tba($pdo, 'team/frc' . $team);
    $cachedTeam = $teamCache['data'] ?? [];
    $statusCache = $year > 0 ? ns_team_cached_tba($pdo, 'team/frc' . $team . '/events/' . $year . '/statuses') : [];
    $officialStatuses = $statusCache['data'] ?? [];
    $awardsCache = $year > 0 ? ns_team_cached_tba($pdo, 'team/frc' . $team . '/awards/' . $year) : [];
    $awards = $awardsCache['data'] ?? [];
    if (!array_is_list($awards)) $awards = [];
    $latestCached = (string)($statusCache['fetched_at'] ?? $teamCache['fetched_at'] ?? '');
}

$nickname = trim((string)($directory['nickname'] ?? $cachedTeam['nickname'] ?? ''));
$formalName = trim((string)($directory['name'] ?? $cachedTeam['name'] ?? ''));
$city = trim((string)($directory['city'] ?? $cachedTeam['city'] ?? ''));
$region = trim((string)($directory['state_prov'] ?? $cachedTeam['state_prov'] ?? ''));
$country = trim((string)($directory['country'] ?? $cachedTeam['country'] ?? ''));
$school = trim((string)($cachedTeam['school_name'] ?? ''));
$rookieYear = (int)($directory['rookie_year'] ?? $cachedTeam['rookie_year'] ?? 0);
$teamWebsite = trim((string)($cachedTeam['website'] ?? ''));
if (!filter_var($teamWebsite, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($teamWebsite, PHP_URL_SCHEME)), ['http', 'https'], true)) $teamWebsite = '';
$location = implode(', ', array_filter([$city, $region, $country], static fn($v) => $v !== ''));

ns_head('Team Profiles');
?>
<link rel="stylesheet" href="/epa/team-profile.css?v=65">
<section class="page-intro team-intro">
  <span class="eyebrow">TEAM INTELLIGENCE</span>
  <h1><?= $team ? 'Team #' . $team : 'Find a' ?> <em><?= $team ? 'profile.' : 'team.' ?></em></h1>
  <p>Public FRC team information, season performance, event results and match-by-match EPA from Neptune's existing archive.</p>
  <form method="get" class="search" action="/epa/team.php">
    <label class="sr" for="team">FRC team number</label>
    <input type="number" min="1" max="999999" name="team" id="team" value="<?= $team ?: '' ?>" placeholder="Enter FRC team number" required>
    <?php if ($year > 0): ?><input type="hidden" name="year" value="<?= $year ?>"><?php endif; ?>
    <button type="submit">View team →</button>
  </form>
</section>
<?php if ($team > 0): ?>
  <?php if ($nickname !== '' || $location !== '' || $formalName !== '' || $rookieYear > 0 || $school !== ''): ?>
  <section class="panel team-identity">
    <div class="team-identity-top">
      <div>
        <span class="eyebrow">FRC #<?= $team ?></span>
        <h2><?= ns_h($nickname !== '' ? $nickname : ($formalName !== '' ? $formalName : 'Team #' . $team)) ?></h2>
        <?php if ($formalName !== '' && $formalName !== $nickname): ?><p class="muted team-identity-name"><?= ns_h($formalName) ?></p><?php endif; ?>
        <div class="team-facts">
          <?php if ($location !== ''): ?><span>⌖ <?= ns_h($location) ?></span><?php endif; ?>
          <?php if ($rookieYear > 0): ?><span>Rookie year <?= $rookieYear ?></span><?php endif; ?>
          <?php if ($school !== ''): ?><span><?= ns_h($school) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="team-links">
        <a href="https://www.thebluealliance.com/team/<?= $team ?>" target="_blank" rel="noopener noreferrer">The Blue Alliance ↗</a>
        <?php if ($teamWebsite !== ''): ?><a href="<?= ns_h($teamWebsite) ?>" target="_blank" rel="noopener noreferrer">Team website ↗</a><?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="section-top"><h2><?= $year ?> season snapshot</h2>
      <form class="filter team-year-filter" method="get" action="/epa/team.php">
        <input type="hidden" name="team" value="<?= $team ?>">
        <label for="year">Season</label>
        <select name="year" id="year"><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
        <button type="submit">Explore</button>
      </form>
    </div>
    <?php if ($rating): ?>
      <div class="metric-grid team-epa-grid">
        <?php foreach (['rating'=>'Overall EPA', 'auto_rating'=>'Autonomous', 'teleop_rating'=>'Teleop', 'endgame_rating'=>'Endgame'] as $k=>$label): ?>
          <div class="metric"><span><?= ns_h($label) ?></span><b><?= ns_n($rating[$k]) ?></b></div>
        <?php endforeach; ?>
      </div>
      <div class="team-stat-grid">
        <div><span>Season EPA rank</span><strong><?= $seasonRank !== null ? '#' . $seasonRank : '—' ?></strong><small><?= $rankPool > 0 ? 'of ' . number_format($rankPool) . ' rated teams' : 'No ranking available' ?></small></div>
        <div><span>Competition record</span><strong><?= ns_team_wlt($rating['wins'], $rating['losses'], $rating['ties']) ?></strong><small>W–L–T · archived rated events</small></div>
        <div><span>Competition activity</span><strong><?= (int)$rating['events_played'] ?> events</strong><small><?= (int)$rating['matches_played'] ?> rated matches</small></div>
        <div><span>Model confidence</span><strong><?= ns_team_pct($rating['confidence']) ?></strong><small>Trend <?= (float)$rating['trend'] >= 0 ? '+' : '' ?><?= ns_n($rating['trend']) ?> · σ <?= ns_n($rating['sigma']) ?></small></div>
      </div>
      <p class="team-note">EPA rank is calculated against all teams rated in Neptune's <?= $year ?> public archive, not an official FIRST ranking. Win–loss–tie records and confidence are copied from AUGUR's public ratings.</p>
    <?php else: ns_blank(); endif; ?>
  </section>

  <?php if ($matches): ?>
  <?php
    $chartRows = array_reverse($matches);
    $chartValues = array_values(array_map(static fn($r) => (float)$r['post_rating'], $chartRows));
    $chartFirst = $chartValues[0]; $chartLast = $chartValues[count($chartValues) - 1];
  ?>
  <section class="panel team-trend-section">
    <div class="section-top"><div><span class="eyebrow">MATCH-BY-MATCH</span><h2><?= $year ?> EPA progression</h2></div><span class="team-trend-delta <?= $chartLast >= $chartFirst ? 'up' : 'down' ?>"><?= $chartLast >= $chartFirst ? '+' : '' ?><?= ns_n($chartLast - $chartFirst) ?> EPA</span></div>
    <div class="team-trend-chart" role="img" aria-label="EPA progression across <?= count($chartValues) ?> archived matches, from <?= ns_n($chartFirst) ?> to <?= ns_n($chartLast) ?>">
      <svg viewBox="0 0 820 180" preserveAspectRatio="none" aria-hidden="true">
        <line x1="12" y1="55" x2="808" y2="55" class="team-chart-grid"/>
        <line x1="12" y1="112" x2="808" y2="112" class="team-chart-grid"/>
        <polyline points="<?= ns_team_svg_points($chartValues) ?>" class="team-chart-line"/>
      </svg>
    </div>
    <div class="team-chart-foot"><span><?= ns_n($chartFirst) ?> starting match EPA</span><span><?= ns_n($chartLast) ?> latest match EPA</span></div>
    <p class="team-note">Displays <?= count($chartValues) ?> most recent rated matches in chronological order<?= $allMatches ? ' (up to 160)' : '' ?>; this is a model estimate, not official match score.</p>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="section-top"><div><span class="eyebrow">COMPETITIONS</span><h2><?= $year ?> event performance</h2></div><span class="team-count"><?= count($events) ?> rated <?= count($events) === 1 ? 'event' : 'events' ?></span></div>
    <?php if ($events): ?>
    <div class="team-event-grid">
      <?php foreach ($events as $ev):
        $status = is_array($officialStatuses[$ev['tba_event_key']] ?? null) ? $officialStatuses[$ev['tba_event_key']] : [];
        $qualRank = $status['qual']['ranking']['rank'] ?? null;
        $allianceNumber = $status['alliance']['number'] ?? null;
        $alliancePick = $status['alliance']['pick'] ?? null;
        $place = implode(', ', array_filter([$ev['city'] ?? '', $ev['state_prov'] ?? '', $ev['country'] ?? '']));
      ?>
      <article class="team-event-card">
        <div class="team-event-header"><div><a class="team-event-title" href="<?= ns_h(ns_team_event_link((string)$ev['tba_event_key'], $year)) ?>"><?= ns_h($ev['name']) ?></a><p><?= ns_h(ns_team_date($ev['start_date'])) ?><?= $place !== '' ? ' · ' . ns_h($place) : '' ?></p></div><span class="team-event-rank">EPA #<?= (int)$ev['epa_rank'] ?></span></div>
        <div class="team-event-numbers"><div><small>Overall EPA</small><b><?= ns_n($ev['rating']) ?></b></div><div><small>Auto / Teleop / Endgame</small><b class="team-small-rating"><?= ns_n($ev['auto_rating']) ?> / <?= ns_n($ev['teleop_rating']) ?> / <?= ns_n($ev['endgame_rating']) ?></b></div></div>
        <div class="team-event-footer"><span><?= ns_team_wlt($ev['wins'], $ev['losses'], $ev['ties']) ?> W–L–T</span><span><?= (int)$ev['matches_played'] ?> rated matches</span>
          <?php if (is_numeric($qualRank) && (int)$qualRank > 0): ?><span>Official qual rank #<?= (int)$qualRank ?></span><?php endif; ?>
          <?php if (is_numeric($allianceNumber) && (int)$allianceNumber > 0): ?><span>Alliance #<?= (int)$allianceNumber ?><?= is_numeric($alliancePick) ? ' · pick ' . (int)$alliancePick : '' ?></span><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ns_blank(); endif; ?>
    <p class="team-note">The EPA position is within each event's rated teams. Official qualification ranks or alliance selection appear only when they were already stored in Neptune's cached TBA responses.</p>
  </section>

  <?php if ($awards): ?>
  <section class="panel"><span class="eyebrow">CACHED TBA HISTORY</span><h2><?= $year ?> awards</h2>
    <div class="team-award-list">
      <?php foreach (array_slice($awards, 0, 24) as $award): if (!is_array($award)) continue;
        $eventKey = (string)($award['event_key'] ?? '');
        $eventName = $eventKey;
        foreach ($events as $ev) if ((string)$ev['tba_event_key'] === $eventKey) { $eventName = (string)$ev['name']; break; }
      ?>
      <div class="team-award"><strong><?= ns_h($award['name'] ?? 'Award') ?></strong><?php if ($eventKey !== ''): ?><span><?= ns_h($eventName) ?></span><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
    <p class="team-note">Awards are shown only if a TBA awards response was previously cached in Neptune. This page never fetches awards live.</p>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="section-top"><div><span class="eyebrow">ARCHIVED MATCH RESULTS</span><h2><?= $year ?> match history</h2></div><span class="team-count"><?= count($matches) ?> <?= $allMatches ? 'latest (max 160)' : 'latest' ?> rated matches</span></div>
    <?php if ($matches): ?>
      <div class="team-match-grid">
        <?php foreach ($matches as $m):
          $ours = array_values(array_filter(ns_team_numbers($m, 'own'), static fn($v) => $v !== $team));
          $theirs = ns_team_numbers($m, 'opp');
          $hasResult = ((int)$m['has_result'] === 1);
          $ourScore = $hasResult ? (float)$m['own_score'] : null;
          $theirScore = $hasResult ? (float)$m['other_score'] : null;
          $outcome = !$hasResult ? 'No result' : ($ourScore > $theirScore ? 'Win' : ($ourScore < $theirScore ? 'Loss' : 'Tie'));
          $delta = (float)$m['rating_delta'];
        ?>
        <article class="team-match-card">
          <div class="team-match-heading"><div><small><?= ns_h($m['event_name']) ?></small><strong><?= ns_h(ns_team_match_label($m)) ?></strong></div><span class="team-result <?= strtolower(str_replace(' ', '-', $outcome)) ?>"><?= ns_h($outcome) ?></span></div>
          <div class="team-match-stats">
            <div><small><?= ns_h($m['alliance']) ?> alliance</small><b><?= $hasResult ? ns_n($ourScore) : '—' ?></b></div>
            <div><small>Opposing alliance</small><b><?= $hasResult ? ns_n($theirScore) : '—' ?></b></div>
            <div><small>EPA change</small><b class="<?= $delta >= 0 ? 'team-positive' : 'team-negative' ?>"><?= $delta > 0 ? '+' : '' ?><?= ns_n($delta) ?></b></div>
          </div>
          <div class="team-match-roster">
            <span>Partners: <?= $ours ? ns_h(implode(', ', array_map(static fn($n) => '#' . $n, $ours))) : '—' ?></span>
            <span>Opponents: <?= $theirs ? ns_h(implode(', ', array_map(static fn($n) => '#' . $n, $theirs))) : '—' ?></span>
          </div>
          <?php if ($hasResult && ($m['own_auto'] !== null || $m['own_teleop'] !== null || $m['own_endgame'] !== null)): ?>
          <div class="team-match-phases">Alliance phase points: Auto <?= ns_n($m['own_auto']) ?> · Teleop <?= ns_n($m['own_teleop']) ?> · Endgame <?= ns_n($m['own_endgame']) ?></div>
          <?php endif; ?>
        </article>
        <?php endforeach; ?>
      </div>
      <?php if (!$allMatches && count($matches) === $matchLimit): ?><p class="team-more"><a href="?<?= ns_h(http_build_query(['team' => $team, 'year' => $year, 'matches' => 'all'])) ?>">View up to 160 matches →</a></p><?php endif; ?>
    <?php else: ns_blank(); endif; ?>
    <p class="team-note">Match results, opponents and partners come from TBA-derived archived alliance samples. Phase points represent the <b>entire alliance</b>, not this robot's individual scoring. Matches without archived rated records are not shown.</p>
  </section>

  <section class="panel"><div class="section-top"><h2>Season history</h2><span class="team-count"><?= count($history) ?> rated seasons</span></div>
    <?php if ($history): ?><div class="table-scroller"><table><thead><tr><th>Season</th><th>EPA</th><th>Auto</th><th>Teleop</th><th>Endgame</th><th>Record</th><th>Events</th></tr></thead><tbody>
    <?php foreach ($history as $r): ?><tr><td><a href="?<?= ns_h(http_build_query(['team'=>$team,'year'=>(int)$r['season_year']])) ?>"><?= (int)$r['season_year'] ?></a></td><?php foreach (['rating','auto_rating','teleop_rating','endgame_rating'] as $k): ?><td><?= ns_n($r[$k]) ?></td><?php endforeach; ?><td><?= ns_team_wlt($r['wins'],$r['losses'],$r['ties']) ?></td><td><?= (int)$r['events_played'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ns_blank(); endif; ?>
  </section>
  <?php if ($latestCached): ?><p class="team-cache-caption">Optional cached TBA team/status information last retrieved <?= ns_h($latestCached) ?> UTC. It may be older than the public EPA archive.</p><?php endif; ?>
<?php endif; ?>
<section class="panel"><h2>How should I read these numbers?</h2><p>Neptune's public EPA ratings are estimates derived from archived public match information. They are not FIRST qualification points, and alliance scores are not attributed to individual robots.</p><a href="/epa/intelligence.php">How Neptune Works →</a></section>
<?php ns_foot(); ?>
