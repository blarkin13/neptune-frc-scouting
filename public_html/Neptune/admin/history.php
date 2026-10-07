<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/_event_selection.php';
$u=require_role(['owner','admin']);
$org=(int)$u['organization_id'];$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $gameId=(int)($_POST['game_id']??0);$archive=(int)($_POST['archive']??0)===1?1:0;
        $s=$pdo->prepare('UPDATE games SET is_archived=? WHERE id=? AND organization_id=?');$s->execute([$archive,$gameId,$org]);
        if(!$s->rowCount())throw new RuntimeException('Game not found or no change was required.');
        $msg=$archive?'Game archived. It will stay out of normal game selectors.':'Game restored to normal game selectors.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$s=$pdo->prepare("SELECT g.id,g.name,g.season_year,g.is_archived,g.updated_at,
    COUNT(DISTINCT e.id) event_count,MAX(COALESCE(e.end_date,e.start_date)) latest_event_date,
    SUM(CASE WHEN e.is_current=1 THEN 1 ELSE 0 END) current_events
    FROM games g LEFT JOIN events e ON e.game_id=g.id AND e.organization_id=g.organization_id
    WHERE g.organization_id=? GROUP BY g.id,g.name,g.season_year,g.is_archived,g.updated_at
    ORDER BY g.season_year DESC,g.name");$s->execute([$org]);$games=$s->fetchAll();
$pageTitle='Game & Event History';$moduleName='MERCURY';include dirname(__DIR__).'/partials_header.php';
?>
<div class="toolbar" style="justify-content:space-between;align-items:flex-start">
  <div><div class="module-eyebrow"><span>MERCURY</span><small>Selection History</small></div><h1 style="margin-bottom:4px">Game &amp; Event History</h1><div class="muted">Normal event selectors show current/upcoming events plus the last <?=e(NEPTUNE_EVENT_RECENT_MONTHS)?> months. Use <b>Show full history</b> on a selector when you need older events. Archived games stay out of normal game-builder selectors until restored here.</div></div>
  <a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
</div>
<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<div class="card" style="margin-top:16px">
  <div class="toolbar" style="justify-content:space-between"><div><h2 style="margin:0">Game archive</h2><div class="muted">Archiving a game does not delete events, matches, scouting, or analytics. It only removes that game from normal configuration selectors.</div></div><span class="pill"><?=count($games)?> games</span></div>
  <div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>Season</th><th>Game</th><th>Events</th><th>Latest event</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($games as $g):?><tr><td><b><?=e($g['season_year'])?></b></td><td><?=e($g['name'])?></td><td><?=e($g['event_count'])?><?=$g['current_events']?' · current':''?></td><td><?=e($g['latest_event_date']?:'—')?></td><td><?=$g['is_archived']?'<span class="pill">Archived</span>':'<span class="pill">Active</span>'?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="game_id" value="<?=e($g['id'])?>"><input type="hidden" name="archive" value="<?=$g['is_archived']?0:1?>"><button class="secondary"><i class="fa-solid <?=$g['is_archived']?'fa-box-open':'fa-box-archive'?>"></i> <?=$g['is_archived']?'Restore':'Archive'?></button></form></td></tr><?php endforeach;?>
  <?php if(!$games):?><tr><td colspan="6">No games found.</td></tr><?php endif;?></tbody></table></div>
</div>
<div class="card" style="margin-top:16px"><h2>How event history works</h2><p class="muted">Events are not deleted or permanently archived by age. Neptune simply keeps old completed events out of routine dropdowns after <?=e(NEPTUNE_EVENT_RECENT_MONTHS)?> months. Current and upcoming events always remain visible. The <b>Show full history</b> control temporarily expands the selector to every event your organization still has.</p></div>
<?php include dirname(__DIR__).'/partials_footer.php';
