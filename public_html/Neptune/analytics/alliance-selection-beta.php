<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_alliance_beta_helpers.php';
$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$canInstall=in_array((string)$u['role'],['owner','admin'],true);
$events=neptune_event_list($pdo,$org);
$teams=alliance_beta_org_teams($pdo,$org);
$eventId=(int)($_GET['event_id']??0);if($eventId<1&&$events)$eventId=(int)$events[0]['id'];
$strategyTeam=(int)($_GET['strategy_team']??0);
if($strategyTeam<1){$primaryId=primary_team_id($pdo,(int)$u['id'],$org);foreach($teams as $t)if((int)$t['id']===$primaryId){$strategyTeam=(int)$t['frc_team_number'];break;}if($strategyTeam<1&&$teams)$strategyTeam=(int)$teams[0]['frc_team_number'];}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='install_beta_schema'){
    verify_csrf();
    if(!$canInstall){http_response_code(403);$error='Admin access required.';}else{try{alliance_beta_install_schema($pdo);$message='Alliance Selection Beta tables installed.';}catch(Throwable $e){$error=$e->getMessage();}}
}
$schemaReady=alliance_beta_schema_ready($pdo);
$pageTitle='Alliance Selection Beta';$moduleName='AUGUR';$pageStyles=['assets/css/alliance-selection-beta.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<section class="asb-page" id="allianceBetaApp" data-event="<?=e($eventId)?>" data-strategy-team="<?=e($strategyTeam)?>">
  <header class="asb-head">
    <div><div class="module-eyebrow"><span>AUGUR</span><small>Beta Lab</small></div><h1>Alliance Selection <span class="asb-beta">BETA 3</span></h1><p>Scenario-driven alliance-selection planning: Neptune estimates the role this team is likely to occupy, explains why, and changes the planning workflow around that scenario while preserving shared live draft facts and private team strategy.</p></div>
    <a class="secondary" href="<?=e(base_url('analytics/alliance-selection.php?event_id='.$eventId))?>"><i class="fa-solid fa-arrow-left"></i> Production Alliance Selection</a>
  </header>

  <?php if($message):?><div class="notice good"><?=e($message)?></div><?php endif;?>
  <?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>

  <section class="card asb-controls">
    <form method="get">
      <label>Event<select name="event_id" onchange="this.form.submit()"><?=neptune_event_options_html($events,$eventId)?></select></label><div><?=neptune_history_toggle_html(neptune_selector_show_history(),'events')?></div>
      <label>Strategy for<select name="strategy_team" onchange="this.form.submit()"><?php foreach($teams as $t):$n=(int)$t['frc_team_number'];$name=trim((string)($t['display_name']?:$t['nickname']));?><option value="<?=e($n)?>" <?=$n===$strategyTeam?'selected':''?>>#<?=e($n)?><?= $name!==''?' · '.e($name):'' ?></option><?php endforeach;?></select></label>
    </form>
    <div class="asb-controls-note"><i class="fa-solid fa-shield-halved"></i> Beta data is isolated from production Alliance Selection.</div>
  </section>

  <?php if(!$schemaReady):?>
    <section class="card asb-install"><i class="fa-solid fa-database"></i><div><h2>Beta tables are not installed</h2><p>This beta uses two isolated state tables and does not modify production Alliance Selection data.</p><?php if($canInstall):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="install_beta_schema"><button class="primary" type="submit"><i class="fa-solid fa-flask"></i> Install Beta Tables</button></form><?php else:?><p><b>Ask an owner/admin to install the beta tables.</b></p><?php endif;?></div></section>
  <?php elseif(!$events||!$teams):?>
    <div class="notice">Alliance Selection Beta needs at least one event and one active organization team.</div>
  <?php else:?>
    <div class="asb-status" id="asbStatus">Loading event intelligence…</div>

    <section class="card asb-scenario" id="asbScenarioAdvisor">
      <div class="asb-card-head">
        <div><span class="asb-kicker">Step 1 · Where are we likely to land?</span><h2>Scenario Advisor</h2><p>Neptune projects the selected team's likely alliance-selection role from current rankings, remaining qualification matches, and event strength. Treat this as guidance, not a guarantee.</p></div>
        <span class="asb-projection-confidence" id="asbProjectionConfidence">Calculating…</span>
      </div>
      <div class="asb-scenario-grid">
        <div class="asb-projection-card" id="asbProjectionCard"></div>
        <div class="asb-probability-card">
          <h3>Projected role</h3>
          <div id="asbRoleProbabilities" class="asb-role-probs"></div>
        </div>
        <div class="asb-reason-card">
          <h3>Why Neptune thinks this</h3>
          <ul id="asbProjectionWhy"></ul>
        </div>
      </div>
      <div class="asb-scenario-actions">
        <strong>Planning mode</strong>
        <button type="button" data-scenario="auto" class="primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Use Neptune Recommendation</button>
        <button type="button" data-scenario="captain" class="secondary">Captain</button>
        <button type="button" data-scenario="first_pick" class="secondary">First Pick</button>
        <button type="button" data-scenario="second_pick" class="secondary">Second Pick</button>
        <button type="button" data-scenario="bubble" class="secondary">Bubble / Dual Prep</button>
      </div>
      <div class="asb-projection-change" id="asbProjectionChange" hidden></div>
    </section>

    <section class="card asb-plan" id="asbScenarioPlan">
      <div class="asb-card-head">
        <div><span class="asb-kicker">Step 2 · Plan for the role</span><h2 id="asbPlanTitle">Planning Workspace</h2><p id="asbPlanSubtitle">Neptune will tailor this workspace after the projection loads.</p></div>
        <span class="asb-mode-badge" id="asbModeBadge">AUTO</span>
      </div>
      <div class="asb-plan-grid">
        <section class="asb-plan-panel" id="asbScenarioFocus"></section>
        <section class="asb-plan-panel" id="asbAllianceNeeds"></section>
        <section class="asb-plan-panel" id="asbScenarioCandidates"></section>
      </div>
      <section class="asb-plan-checklist">
        <div><h3>Tonight's planning checklist</h3><p>Use this as the handoff from Friday-night analysis to the live draft.</p></div>
        <div id="asbPlanningChecklist"></div>
      </section>
    </section>

    <section class="card asb-guide" aria-label="Alliance selection decision guide">
      <div class="asb-card-head">
        <div><span class="asb-kicker">Step 3 · Candidate board</span><h2 id="asbGuideTitle">Who should we consider?</h2><p id="asbGuideSubtitle">Neptune will rank the most relevant available robots for the active planning scenario.</p></div>
        <div class="asb-guide-legend">
          <span><b>Q Rank</b> official qualification position</span>
          <span><b id="asbRankingLegend">Rank Score</b> official qualification ranking score</span>
          <span><b>Nep. EPA</b> Neptune's estimated offensive contribution</span>
        </div>
      </div>
      <div class="asb-recommendations" id="asbRecommendations"></div>
      <div class="asb-guide-warning" id="asbGuideWarning" hidden></div>
    </section>

    <section class="asb-scope-bar">
      <div><span class="asb-scope shared"><i class="fa-solid fa-people-group"></i> Shared event facts</span><span>captains, drafted teams, declines, broken robots</span></div>
      <div><span class="asb-scope private"><i class="fa-solid fa-lock"></i> #<?=e($strategyTeam)?> strategy</span><span>pick lists, favorites, unavailable, do-not-pick</span></div>
    </section>

    <section class="asb-workspace">
      <article class="card asb-board-card">
        <div class="asb-card-head">
          <div><span class="asb-scope shared">Shared</span><h2>Live Draft Board</h2><p>Alliance captains automatically come from qualification rankings. Click an empty pick slot to choose the next robot.</p></div>
          <button class="secondary compact" id="asbRefreshCaptains" type="button"><i class="fa-solid fa-rotate"></i> Refresh Captains</button>
        </div>
        <div class="asb-alliance-board" id="asbAllianceBoard"></div>
      </article>

      <article class="card asb-picks-card">
        <div class="asb-card-head">
          <div><span class="asb-scope private">Team private</span><h2 id="asbPrivateTitle">Pick List</h2><p>Only the selected FRC team's strategy changes here.</p></div>
          <div class="asb-private-actions">
            <button class="secondary compact" id="asbImportProduction" type="button" hidden><i class="fa-solid fa-file-import"></i> Import Current Strategy</button>
            <button class="secondary compact" id="asbResetTeam" type="button"><i class="fa-solid fa-rotate-left"></i> Reset</button>
          </div>
        </div>
        <nav class="asb-tier-tabs" id="asbTierTabs" aria-label="Pick list tier">
          <button type="button" class="active" data-tier-tab="1">1st</button><button type="button" data-tier-tab="2">2nd</button><button type="button" data-tier-tab="3">3rd</button><button type="button" data-tier-tab="4">4th</button>
        </nav>
        <div class="asb-pick-lists" id="asbPickLists"></div>
      </article>
    </section>

    <section class="card asb-pool-card">
      <div class="asb-card-head asb-pool-head">
        <div><h2>Available Robots</h2><p id="asbPoolSummary">Qualification data + Neptune scouting + strategy tags</p></div>
        <div class="asb-pool-tools">
          <label class="asb-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="asbSearch" placeholder="Search team…"></label>
          <select id="asbPoolSort" aria-label="Sort team pool"><option value="best">Best Available</option><option value="rank">Qualification Rank</option><option value="epa">Neptune EPA</option><option value="team">Team Number</option></select>
        </div>
      </div>
      <div class="asb-filter-tabs" id="asbFilterTabs"><button type="button" class="active" data-filter="available">Available</button><button type="button" data-filter="all">All</button><button type="button" data-filter="favorite"><i class="fa-regular fa-star"></i> Favorites</button></div>
      <div class="asb-team-pool" id="asbTeamPool"></div>
    </section>

    <section class="card asb-live-flags">
      <div><h2>Live field updates</h2><p>If the representative hears something during selection, mark it here. These facts are shared across both organization teams.</p></div>
      <div class="asb-inline"><select id="asbDraftElsewhereTeam"><option value="">Choose event team…</option></select><button class="secondary" id="asbDraftElsewhereBtn" type="button">Mark Drafted Elsewhere</button></div>
      <div class="asb-chip-list" id="asbDraftElsewhereList"></div>
    </section>

    <div class="asb-modal" id="asbChooser" hidden>
      <div class="asb-modal-backdrop" data-close-chooser></div>
      <section class="asb-modal-card" role="dialog" aria-modal="true">
        <header><div><small id="asbChooserEyebrow">Choose robot</small><h2 id="asbChooserTitle">Choose a team</h2><p>Sorted by qualification score, then Neptune EPA.</p></div><button class="secondary compact" type="button" data-close-chooser><i class="fa-solid fa-xmark"></i></button></header>
        <label class="asb-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="asbChooserSearch" placeholder="Search event roster…"></label>
        <div class="asb-chooser-list" id="asbChooserList"></div>
      </section>
    </div>

    <div class="asb-modal" id="asbTeamDetail" hidden>
      <div class="asb-modal-backdrop" data-close-detail></div>
      <section class="asb-modal-card asb-detail-card" role="dialog" aria-modal="true">
        <header><div><small>Decision card</small><h2 id="asbDetailTitle">Team</h2><p id="asbDetailSubtitle"></p></div><button class="secondary compact" type="button" data-close-detail><i class="fa-solid fa-xmark"></i></button></header>
        <div id="asbDetailBody"></div>
        <div class="asb-detail-actions" id="asbDetailActions"></div>
      </section>
    </div>

    <script>window.NeptuneAllianceBeta={
      api:<?=json_encode(base_url('api/alliance-selection-beta.php'))?>,
      robotLookup:<?=json_encode(base_url('analytics/robot-lookup.php'))?>,
      matchStrategy:<?=json_encode(base_url('analytics/match-strategy.php'))?>,
      spotMedia:<?=json_encode(base_url('scout/tag-media.php'))?>,
      eventId:<?=json_encode($eventId)?>,
      strategyTeam:<?=json_encode($strategyTeam)?>
    };</script>
    <script src="<?=e(base_url('assets/js/alliance-selection-beta.js'))?>?v=3"></script>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
