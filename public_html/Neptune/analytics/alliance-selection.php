<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_alliance_helpers.php';

$u=require_login();
$org=(int)$u['organization_id'];
$canEdit=in_array((string)$u['role'],['owner','admin','strategy'],true);
$events=neptune_event_list($pdo,$org);
$eventId=(int)($_GET['event_id']??0);
if($eventId<1 && $events) $eventId=(int)$events[0]['id'];
$event=$eventId?alliance_event($pdo,$org,$eventId):null;
if($eventId && !$event){$eventId=0;$event=null;}
$schemaReady=alliance_schema_ready($pdo);

$pageTitle='Alliance Selection';
$moduleName='AUGUR';
$pageStyles=['assets/css/alliance-selection.css'];
include dirname(__DIR__).'/partials_header.php';
?>
<section class="alliance-page" id="allianceSelectionApp">
  <header class="alliance-page-head">
    <div>
      <div class="module-eyebrow"><span>AUGUR</span><small>Strategy Workspace</small></div>
      <h1>Alliance Selection</h1>
      <p>Use official TBA qualification rankings for captains, track live selections, and estimate alliance offensive potential from your scouting data.</p>
    </div>
    <div class="alliance-head-actions">
      <span class="alliance-online-pill" id="allianceOnlinePill"><i class="fa-solid fa-wifi"></i> Online</span>
    </div>
  </header>

  <section class="card alliance-event-card">
    <form method="get" class="alliance-event-form">
      <div>
        <label for="allianceEvent">Event</label>
        <select id="allianceEvent" name="event_id" onchange="this.form.submit()">
          <?php if(!$events):?><option value="">No events available</option><?php endif;?>
          <?php foreach($events as $row):?>
            <option value="<?=e($row['id'])?>" <?=$eventId===(int)$row['id']?'selected':''?>><?=e(($row['game_name']??'').' · '.$row['name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <?php if($event):?>
      <div class="alliance-event-meta">
        <span><i class="fa-solid fa-calendar"></i> <?=e($event['season_year'])?></span>
        <span><i class="fa-solid fa-gamepad"></i> <?=e($event['game_name'])?></span>
        <?php if(!empty($event['tba_event_key'])):?><span><i class="fa-solid fa-link"></i> <?=e($event['tba_event_key'])?></span><?php endif;?>
      </div>
      <?php endif;?>
    </form>
  </section>

  <?php if(!$schemaReady):?>
    <div class="notice bad" style="margin-top:16px">
      <b>Alliance Selection database migration required.</b><br>
      Import <code>sql/2026-09-21_alliance-selection-state.sql</code>, then reload this page.
    </div>
  <?php elseif(!$event):?>
    <div class="notice" style="margin-top:16px">Create or import an event before using Alliance Selection.</div>
  <?php else:?>

  <div class="alliance-workspace-shell" id="allianceWorkspaceShell">
  <section class="alliance-commandbar card">
    <div class="alliance-drafts" id="draftTabs" aria-label="Alliance selection scenarios"></div>
    <div class="alliance-command-actions">
      <?php if($canEdit):?>
      <button type="button" class="secondary compact" id="newDraftBtn"><i class="fa-solid fa-plus"></i> Scenario</button>
      <button type="button" class="secondary compact" id="cloneDraftBtn"><i class="fa-regular fa-copy"></i> Clone</button>
      <button type="button" class="secondary compact" id="draftMenuBtn"><i class="fa-solid fa-ellipsis"></i></button>
      <button type="button" class="secondary compact" id="seedCaptainsBtn"><i class="fa-solid fa-rotate"></i> Refresh TBA Rankings</button>
      <button type="button" class="secondary compact" id="undoDraftBtn" title="Undo"><i class="fa-solid fa-rotate-left"></i></button>
      <button type="button" class="secondary compact" id="redoDraftBtn" title="Redo"><i class="fa-solid fa-rotate-right"></i></button>
      <?php endif;?>
      <button type="button" class="secondary compact" id="alliancePickDrawerBtn" aria-controls="alliancePickDrawer" aria-expanded="false" title="Open alliance pick lists"><i class="fa-solid fa-list-ol"></i> Pick Lists</button>
      <button type="button" class="secondary compact alliance-offline-button" id="prepareOfflineBtn" title="Download this event's Alliance Selection data for offline use"><i class="fa-solid fa-download"></i> Prepare Offline</button>
      <button type="button" class="secondary compact alliance-fullscreen-button" id="allianceFullscreenBtn" title="Open the Alliance Board and Team Pool in full screen"><i class="fa-solid fa-expand"></i> <span id="allianceFullscreenLabel">Full Screen</span></button>
      <span class="alliance-offline-status" id="allianceOfflineStatus" hidden></span>
      <span class="alliance-mode-pill" id="draftModePill">Scenario</span>
    </div>
  </section>

  <section class="alliance-dashboard-grid alliance-dashboard-grid-two-column" aria-label="Alliance selection workspace">
    <section class="card alliance-dashboard-card alliance-board-card">
      <div class="alliance-card-head">
        <div>
          <h2>Alliance Board</h2>
          <p class="muted">Select a slot, then choose a team. Use Potential for the alliance subtotal or VS to compare two alliances.</p>
        </div>
        <?php if($canEdit):?>
        <div class="toolbar" style="margin:0">
          <button type="button" class="secondary compact" id="modeBtn"><i class="fa-solid fa-tower-broadcast"></i> Make Live</button>
        </div>
        <?php endif;?>
      </div>
      <div class="alliance-card-scroll alliance-board-scroll">
        <div class="alliance-board" id="allianceBoard" aria-live="polite"></div>
      </div>
    </section>

    <section class="card alliance-dashboard-card alliance-pool-card">
      <div class="alliance-card-head alliance-pool-head">
        <div>
          <h2>Team Pool</h2>
          <p class="muted" id="teamPoolSummary">Event teams</p>
        </div>
        <button type="button" class="alliance-rule-toggle" id="captainPickToggle" role="switch" aria-checked="true" title="Allow eligible lower-seeded alliance captains to be selected by higher-seeded alliances" <?= $canEdit ? '' : 'disabled' ?>>
          <i class="fa-solid fa-anchor" aria-hidden="true"></i>
          <span class="alliance-rule-toggle-copy">Captain picks</span>
          <span class="alliance-rule-toggle-state" id="captainPickToggleLabel">On</span>
        </button>
      </div>
      <div class="alliance-filter-tabs" role="tablist" aria-label="Team filters">
        <button type="button" data-filter="all">All</button>
        <button type="button" class="active" data-filter="available">Available</button>
        <button type="button" data-filter="favorite"><i class="fa-regular fa-star"></i> Favorites</button>
      </div>
      <label class="alliance-search-label" for="teamSearch"><i class="fa-solid fa-magnifying-glass"></i><input id="teamSearch" type="search" placeholder="Search team number or name…" autocomplete="off"></label>
      <div class="alliance-team-list" id="teamList"></div>
    </section>
  </section>
  </div>


  <aside class="alliance-pick-drawer" id="alliancePickDrawer" aria-hidden="true" aria-label="Alliance pick lists">
    <div class="alliance-pick-drawer-head">
      <div><small>Strategy board</small><h2>Pick Lists</h2></div>
      <button type="button" class="secondary compact" id="alliancePickDrawerCloseBtn" aria-label="Close pick lists"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <p class="alliance-pick-drawer-help">Drag a robot from the Team Pool or Alliance Board into a slot. This only copies the robot into your pick list; it does not change the alliance board.</p>
    <div class="alliance-pick-lists" id="alliancePickLists"></div>
  </aside>

  <dialog class="alliance-team-dialog alliance-team-intelligence-dialog" id="teamActionBar" aria-labelledby="selectedTeamLabel">
    <div class="alliance-team-dialog-shell alliance-team-intelligence-shell">
      <div class="alliance-team-dialog-toolbar alliance-team-intelligence-toolbar">
        <div class="alliance-team-title-block">
          <small>Team Intelligence</small>
          <b id="selectedTeamLabel">Team</b>
        </div>
        <div class="alliance-team-toolbar-actions">
          <span class="alliance-selected-team-status alliance-selected-team-status-inline"><span>Status</span><b id="selectedTeamStatus">Available</b></span>
          <span class="pill"><i class="fa-solid fa-database"></i> TBA + Neptune</span>
          <button type="button" class="secondary alliance-team-dialog-close" id="selectedTeamCloseBtn" aria-label="Close team intelligence"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>

      <div class="alliance-team-modal-tabs alliance-team-modal-tabs-wide" id="selectedTeamModalTabs" role="tablist" aria-label="Team intelligence views">
        <button type="button" class="active" role="tab" aria-selected="true" data-selected-modal-tab="overview"><i class="fa-solid fa-chart-line"></i> Overview</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="tags"><i class="fa-solid fa-tags"></i> Alliance Tags</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="matches"><i class="fa-solid fa-circle-play"></i> Matches</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="event">Event Stats</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="statbotics">EPA</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="offense">Offense</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="defense">Defense</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="game">Game Data</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="pit">Pit Scouting</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="spot">Spot Scouting</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="photos">Robot Photos</button>
        <button type="button" role="tab" aria-selected="false" data-selected-modal-tab="history">Alliance History</button>
      </div>

      <div class="alliance-team-dialog-body alliance-team-intelligence-body">
        <section class="alliance-team-modal-panel active" id="selectedTeamOverviewPanel" data-selected-modal-panel="overview">
          <section class="alliance-team-decision" id="selectedTeamDecision" aria-live="polite">
            <div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading decision data…</div>
          </section>
        </section>
        <section class="alliance-team-modal-panel alliance-team-tags-panel" id="selectedTeamTagsPanel" data-selected-modal-panel="tags" hidden>
          <div class="alliance-team-decision-loading"><i class="fa-solid fa-tags"></i> Loading alliance tags…</div>
        </section>
        <section class="alliance-team-modal-panel" id="selectedTeamMatchesPanel" data-selected-modal-panel="matches" hidden>
          <div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading match history…</div>
        </section>
        <section class="alliance-team-modal-panel alliance-team-detail-panel" id="selectedTeamDetailPanel" data-selected-modal-panel="detail" hidden>
          <div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot intelligence…</div>
        </section>
      </div>

      <div class="alliance-team-dialog-footer">
        <?php if($canEdit):?>
        <div class="alliance-selection-actions">
          <button type="button" class="good" id="makeSelectionBtn"><i class="fa-solid fa-user-plus"></i> Make Selection</button>
          <div class="alliance-status-actions">
            <button type="button" class="secondary compact" id="favoriteBtn"><i class="fa-regular fa-star"></i> Favorite</button>
            <button type="button" class="secondary compact alliance-decline" id="declineBtn"><i class="fa-solid fa-circle-xmark"></i> Decline</button>
            <button type="button" class="secondary compact alliance-dnp" id="dnpBtn"><i class="fa-solid fa-ban"></i> Do Not Pick</button>
            <button type="button" class="secondary compact alliance-broken" id="brokenBtn"><i class="fa-solid fa-screwdriver-wrench"></i> Broken</button>
          </div>
        </div>
        <?php endif;?>
        <p class="alliance-team-dialog-hint" id="selectedTeamHint">Choose a slot on the Alliance Board, then select this team.</p>
      </div>
    </div>
  </dialog>

  <dialog class="alliance-potential-dialog" id="alliancePotentialDialog" aria-label="Alliance offensive potential">
    <div class="alliance-potential-dialog-shell">
      <section class="alliance-potential-pane alliance-potential-modal-pane" id="alliancePotentialPane" aria-label="Alliance offensive potential">
        <div class="alliance-potential-head">
          <div><span>Alliance 1</span><h2>Offensive Potential</h2></div>
          <div class="toolbar" style="margin:0">
            <button type="button" class="secondary compact" data-potential-model-settings title="View or update the shared AUGUR model"><i class="fa-solid fa-sliders"></i> Model Settings</button>
            <span class="pill" title="Neptune EPA"><i class="fa-solid fa-chart-line"></i> Nep. EPA</span>
            <button type="button" class="secondary compact alliance-potential-close" data-potential-close aria-label="Close offensive potential"><i class="fa-solid fa-xmark"></i></button>
          </div>
        </div>
        <div class="alliance-intel-empty alliance-potential-empty"><i class="fa-solid fa-chart-line"></i><b>Alliance 1 Potential</b><span>Add teams to the alliance to estimate offensive output.</span></div>
      </section>
    </div>
  </dialog>

  <dialog class="alliance-matchup-dialog" id="matchupDialog" aria-labelledby="matchupDialogTitle">
    <div class="alliance-matchup-shell">
      <div class="alliance-matchup-toolbar">
        <div>
          <small>Projected matchup</small>
          <h2 id="matchupDialogTitle">Alliance matchup</h2>
        </div>
        <div class="alliance-matchup-toolbar-actions">
          <button type="button" class="secondary compact" id="matchupSettingsBtn" title="Tune matchup model"><i class="fa-solid fa-sliders"></i> Model</button>
          <button type="button" class="secondary compact" id="matchupCloseBtn" aria-label="Close matchup"><i class="fa-solid fa-xmark"></i></button>
        </div>
      </div>
      <div class="alliance-matchup-body" id="matchupBody">
        <div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Calculating matchup…</div>
      </div>
    </div>
  </dialog>

  <dialog class="alliance-matchup-settings-dialog" id="matchupSettingsDialog" aria-labelledby="matchupSettingsTitle">
    <form method="dialog" class="alliance-matchup-settings-shell" id="matchupSettingsForm">
      <div class="alliance-matchup-toolbar">
        <div>
          <small>Shared AUGUR model</small>
          <h2 id="matchupSettingsTitle">AUGUR prediction settings</h2>
        </div>
        <button type="button" class="secondary compact" id="matchupSettingsCloseBtn" aria-label="Close settings"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="alliance-matchup-settings-body">
        <p class="muted">Weights are event-wide and shared across your organization. Offensive weights and trend recalculate the Neptune EPA shown in the Team Pool and Current Subtotal.</p>
        <section class="matchup-settings-section">
          <h3>Offensive projection</h3>
          <div class="matchup-settings-grid">
            <label><span>Full-event offense <b data-setting-output="event_offense_weight"></b></span><input type="range" min="0" max="100" step="1" name="event_offense_weight"></label>
            <label><span>Recent offense <b data-setting-output="recent_offense_weight"></b></span><input type="range" min="0" max="100" step="1" name="recent_offense_weight"></label>
            <label><span>High-end output <b data-setting-output="ceiling_weight"></b></span><input type="range" min="0" max="100" step="1" name="ceiling_weight"></label>
            <label><span>Public EPA baseline <b data-setting-output="epa_weight"></b></span><input type="range" min="0" max="100" step="1" name="epa_weight"></label>
            <label><span>Trend strength <b data-setting-output="trend_strength"></b></span><input type="range" min="0" max="4" step="0.1" name="trend_strength"></label>
            <label><span>Qualification form influence <b data-setting-output="tba_form_strength"></b></span><input type="range" min="0" max="20" step="1" name="tba_form_strength"></label>
          </div>
        </section>
        <section class="matchup-settings-section">
          <h3>Defensive adjustment</h3>
          <div class="matchup-settings-grid">
            <label><span>Opponent suppression <b data-setting-output="defense_suppression_weight"></b></span><input type="range" min="0" max="100" step="1" name="defense_suppression_weight"></label>
            <label><span>Recorded defense activity <b data-setting-output="defense_activity_weight"></b></span><input type="range" min="0" max="100" step="1" name="defense_activity_weight"></label>
            <label><span>Defense-action value <b data-setting-output="defense_action_points"></b></span><input type="range" min="0" max="8" step="0.1" name="defense_action_points"></label>
            <label><span>Maximum defense adjustment <b data-setting-output="max_defense_adjustment_pct"></b></span><input type="range" min="0" max="40" step="1" name="max_defense_adjustment_pct"></label>
          </div>
        </section>
        <div class="matchup-settings-note"><i class="fa-solid fa-circle-info"></i><span><b>Subtotal vs. matchup:</b> Full-event offense, recent offense, high-end output, Public EPA baseline, and trend determine each robot's Neptune EPA. Defense and qualification-form settings are applied when AUGUR predicts one alliance against another; they do not change the offensive Current Subtotal.</span></div>
      </div>
      <div class="alliance-matchup-settings-actions">
        <button type="button" class="secondary" id="matchupSettingsResetBtn"><i class="fa-solid fa-arrow-rotate-left"></i> Defaults</button>
        <?php if($canEdit):?><button type="button" class="good" id="matchupSettingsSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Save model</button><?php endif;?>
      </div>
    </form>
  </dialog>

  <dialog class="alliance-offline-dialog" id="allianceOfflineDialog" aria-labelledby="allianceOfflineTitle">
    <div class="alliance-offline-shell">
      <div class="alliance-matchup-toolbar">
        <div>
          <small>Event cache</small>
          <h2 id="allianceOfflineTitle">Prepare Alliance Selection Offline</h2>
        </div>
        <button type="button" class="secondary compact" id="allianceOfflineCloseBtn" aria-label="Close offline download"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="alliance-offline-body">
        <p>Download the current event roster, TBA team information, Public EPA, Neptune EPA, scouting intelligence, pit and pre-scouting data, Spot Scouting, alliance history, scenarios, and the Alliance Selection page shell to this device.</p>
        <div class="alliance-offline-size-card">
          <div>
            <small>Estimated download</small>
            <b id="allianceOfflineSize">Not calculated</b>
            <span id="allianceOfflineSizeNote">Calculate the package size before downloading. This reads the event data to measure it, but does not make the page available offline until you press Download Event Data.</span>
          </div>
          <button type="button" class="secondary compact" id="allianceOfflineSizeBtn"><i class="fa-solid fa-calculator"></i> Calculate Size</button>
        </div>
        <label class="alliance-offline-option">
          <input type="checkbox" id="allianceOfflinePhotos">
          <span><b>Include robot and Spot Scouting media</b><small>Photos and videos can make the offline copy much larger.</small></span>
        </label>
        <div class="alliance-offline-progress" id="allianceOfflineProgress" hidden>
          <div><span id="allianceOfflineProgressLabel">Preparing…</span><b id="allianceOfflineProgressCount">0%</b></div>
          <div class="alliance-offline-progress-track"><span id="allianceOfflineProgressBar" style="width:0%"></span></div>
        </div>
        <div class="alliance-offline-ready" id="allianceOfflineReady"></div>
        <div class="notice alliance-offline-private-note"><i class="fa-solid fa-shield-halved"></i><span>This stores organization-private scouting data on this browser. Use <b>Remove Offline Data</b> before handing a shared device to another organization.</span></div>
      </div>
      <div class="alliance-offline-actions">
        <button type="button" class="secondary" id="allianceOfflineClearBtn"><i class="fa-solid fa-trash-can"></i> Remove Offline Data</button>
        <button type="button" class="good" id="allianceOfflineDownloadBtn"><i class="fa-solid fa-download"></i> Download Event Data</button>
      </div>
    </div>
  </dialog>

  <input type="hidden" id="allianceCsrf" value="<?=e(csrf_token())?>">
  <script>
  window.NeptuneAllianceConfig=<?=json_encode([
      'eventId'=>$eventId,
      'canEdit'=>$canEdit,
      'stateEndpoint'=>base_url('api/alliance-selection.php'),
      'detailEndpoint'=>base_url('api/alliance-team-detail.php'),
      'matchupEndpoint'=>base_url('api/alliance-matchup.php'),
      'spotPage'=>base_url('spot/index.php'),
      'offlineWorker'=>base_url('analytics/alliance-selection-sw.js'),
      'csrf'=>csrf_token(),
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  </script>
  <script defer src="<?=e(base_url('assets/js/alliance-selection.js'))?>?v=<?=@filemtime(dirname(__DIR__).'/assets/js/alliance-selection.js')?:1?>"></script>
  <?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
