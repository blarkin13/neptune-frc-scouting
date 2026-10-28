<?php
/**
 * "Rank robots" button for any scout. Expects $pdo, the signed-in $u, $nrrMatchId,
 * and optionally $nrrAlliance (the alliance the scout is watching) and $nrrMatchLabel.
 */
require_once dirname(__DIR__) . '/analytics/_robot_ranking.php';
$nrrConfig = null;
try {
    $nrrMatch = (int)($nrrMatchId ?? 0);
    if ($nrrMatch > 0) {
        $nrrOrg = (int)$u['organization_id'];
        $s = $pdo->prepare("SELECT mt.frc_team_number team, mt.alliance, mt.station, COALESCE(et.nickname,'') nickname
            FROM match_teams mt JOIN matches m ON m.id=mt.match_id JOIN events e ON e.id=m.event_id AND e.organization_id=?
            LEFT JOIN event_teams et ON et.event_id=m.event_id AND et.frc_team_number=mt.frc_team_number
            WHERE mt.match_id=? ORDER BY FIELD(mt.alliance,'Red','Blue'), mt.station");
        $s->execute([$nrrOrg, $nrrMatch]);
        $nrrTeams = array_map(static fn($r) => ['team' => (int)$r['team'], 'alliance' => (string)$r['alliance'], 'station' => (int)$r['station'], 'nickname' => (string)($r['nickname'] ?: 'Robot ' . $r['team'])], $s->fetchAll(PDO::FETCH_ASSOC));
        if ($nrrTeams) $nrrConfig = ['orgId' => $nrrOrg, 'userId' => (int)$u['id'], 'matchId' => $nrrMatch, 'matchLabel' => (string)($nrrMatchLabel ?? ''),
            'alliance' => ($nrrAlliance ?? 'Red') === 'Blue' ? 'Blue' : 'Red', 'teams' => $nrrTeams,
            'mine' => nrr_mine($pdo, $nrrOrg, (int)$u['id'], $nrrMatch), 'api' => base_url('api/robot-ranking.php')];
    }
} catch (Throwable $e) { error_log('[Neptune ranking widget] ' . $e->getMessage()); }
if ($nrrConfig): ?>
<style>
.nrr-wrap{margin:14px 0 6px}
.nrr-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}
.nrr-bar .nrr-open{display:inline-flex;align-items:center;gap:7px}
.nrr-status{color:var(--muted);font-size:.72rem}
.nrr-modal{position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:12px}
.nrr-sheet{background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:14px;width:min(620px,100%);max-height:100%;overflow:auto;padding:14px 16px;box-shadow:0 24px 70px rgba(0,0,0,.5)}
.nrr-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.nrr-head small{display:block;color:var(--muted);font-size:.74rem;margin-top:3px}
.nrr-alliances{display:flex;gap:8px;margin:12px 0}.nrr-alliances button{flex:1}
.nrr-alliances button[data-alliance="Red"].on{outline:2px solid #d97979}.nrr-alliances button[data-alliance="Blue"].on{outline:2px solid #75a7e8}
.nrr-quality{border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:10px;background:var(--panel2)}
.nrr-quality h3{margin:0;font-size:.95rem}.nrr-hint{margin:2px 0 8px;color:var(--muted);font-size:.72rem}
.nrr-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid var(--line)}
.nrr-team b{font-size:1rem}.nrr-team small{display:block;color:var(--muted);font-size:.7rem}
.nrr-picks{display:flex;gap:6px}.nrr-picks button{min-width:52px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--text);font-weight:800;cursor:pointer}
.nrr-picks button.on{background:var(--accent);border-color:var(--accent);color:#fff}
.nrr-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px}.nrr-msg{color:var(--muted)}
</style>
<div class="nrr-wrap" id="neptuneRanking"></div>
<script>window.NeptuneRankingConfig=<?=json_encode($nrrConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)?>;</script>
<script defer src="<?=e(base_url('assets/js/neptune-robot-ranking.js'))?>?v=<?=@filemtime(dirname(__DIR__) . '/assets/js/neptune-robot-ranking.js') ?: 1?>"></script>
<?php endif; ?>
