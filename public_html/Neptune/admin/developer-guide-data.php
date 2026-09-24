<?php
return [
    [
        'id' => 'architecture',
        'title' => 'How Neptune Fits Together',
        'icon' => 'fa-diagram-project',
        'summary' => 'A map of the application, shared bootstrap, public app, protected code, subsystems, APIs, and the safest places to make changes.',
        'tags' => ['architecture','folders','bootstrap','SATURN','TRIDENT','VULCAN','AUGUR','API','shared code'],
        'files' => [
            'neptune_secure/bootstrap.php — authentication, roles, CSRF, base_url(), shared helpers and game-revision helpers',
            'public_html/Neptune/partials_header.php — shared page head, navigation, CSS/JS loading and theme initialization',
            'public_html/Neptune/partials_footer.php — shared page footer',
            'public_html/Neptune/admin/index.php — SATURN Command Center navigation',
            'public_html/Neptune/scouting.php — TRIDENT launch page',
            'public_html/Neptune/analytics/index.php — AUGUR launch page',
            'public_html/Neptune/api/ — JSON/action endpoints used by browser pages',
            'public_html/Neptune/assets/ — shared CSS and JavaScript',
        ],
        'search' => ['require_once dirname(__DIR__,3)', 'require_login()', 'require_role(', 'base_url(', 'partials_header.php', 'partials_footer.php', '$moduleName'],
        'sections' => [
            ['title'=>'Mental model','html'=>'<div class="dg-flow"><div><b>Protected core</b><span><code>neptune_secure/</code> contains bootstrap, database connection, TBA, Statbotics and image helpers.</span></div><div><b>Web app</b><span><code>public_html/Neptune/</code> contains pages, APIs, assets and uploads.</span></div><div><b>Subsystems</b><span>TRIDENT scouts, SATURN operates/administers, VULCAN builds game/form configuration, AUGUR analyzes and predicts.</span></div><div><b>Shared data</b><span>Pages and APIs use the same MySQL connection through <code>bootstrap.php</code>.</span></div></div>'],
            ['title'=>'Before changing a page','html'=>'<ol><li>Find whether the logic already exists in a shared helper before copying it into a page.</li><li>Identify the page role with <code>require_login()</code> or <code>require_role([...])</code>.</li><li>Check whether every query is organization-scoped directly or through an organization-owned parent.</li><li>Use <code>base_url()</code> for Neptune routes rather than hard-coding deployment paths.</li><li>Keep reusable UI in shared assets instead of adding another one-off implementation when possible.</li></ol>'],
            ['title'=>'When changing this, also check','html'=>'<p><span class="dg-chip">partials_header.php</span> <span class="dg-chip">admin/index.php</span> <span class="dg-chip">analytics/index.php</span> <span class="dg-chip">scouting.php</span> <span class="dg-chip">mobile layout</span> <span class="dg-chip">light mode</span></p>'],
        ],
    ],
    [
        'id' => 'prediction-model',
        'title' => 'Update the AUGUR Prediction Model',
        'icon' => 'fa-brain',
        'summary' => 'Where the shared matchup model lives, how Public EPA becomes Neptune EPA, where settings are stored, and every current caller that needs regression testing.',
        'tags' => ['prediction','AUGUR','EPA','Neptune EPA','win probability','predicted score','matchup','model weights','trend','defense'],
        'files' => [
            'public_html/Neptune/analytics/_augur_prediction_model.php — shared prediction engine',
            'public_html/Neptune/analytics/_augur_prediction_settings.php — defaults, compatibility aliases and validation ranges',
            'public_html/Neptune/analytics/_augur_epa.php — local public TBA-derived EPA archive and augur_epa_rating_map()',
            'public_html/Neptune/analytics/match-strategy.php — pre-match and current-match prediction UI/calls',
            'public_html/Neptune/analytics/robots.php — robot analytics prediction call',
            'public_html/Neptune/api/alliance-matchup.php — Alliance Selection head-to-head prediction',
            'public_html/Neptune/api/alliance-selection.php — Alliance Selection prediction data/state',
            'public_html/Neptune/api/augur-prediction-settings.php — read/save shared event model settings',
            'public_html/Neptune/analytics/alliance-selection.php — model settings UI',
            'public_html/Neptune/assets/js/alliance-selection.js — browser defaults and settings behavior',
        ],
        'search' => ['augur_prediction_run(', 'augur_prediction_offense_blend(', 'augur_prediction_team_metrics(', 'augur_prediction_alliance_summary(', 'augur_prediction_apply_opponent(', 'augur_epa_rating_map(', 'epa_weight', 'use_epa', 'matchup_model', 'neptune_epa', 'augur_epa'],
        'sections' => [
            ['title'=>'What “Public EPA” and “Neptune EPA” mean here','html'=>'<p><b>Public EPA</b> is an input baseline read from Neptune\'s local AUGUR-managed TBA-derived EPA archive. Its adjustable model setting is <code>epa_weight</code>, currently labeled <b>EPA baseline</b> or <b>Public EPA baseline</b> in the UI.</p><p><b>Neptune EPA</b> is AUGUR\'s organization-private blended offensive estimate after Public EPA, Neptune scouting and recent trend are combined. The prediction engine exposes <code>neptune_epa</code> as the canonical metric key while retaining <code>augur_epa</code> as a compatibility alias for older clients. There is no separate Neptune-EPA weight slider because <code>epa_weight</code> controls the Public EPA baseline inside that blend.</p>'],
            ['title'=>'Why the <code>augur_epa_*</code> tables keep their names','html'=>'<p>The <code>augur_epa_*</code> tables are AUGUR\'s Public EPA data infrastructure: archive years/events, public event and season ratings, phase mappings, samples, and the team directory. They are <b>not</b> named after the blended Neptune EPA metric, so they intentionally keep their existing names. This separates system ownership (<b>AUGUR</b>) from the derived metric (<b>Neptune EPA</b>).</p>'],
            ['title'=>'Current calculation path','html'=>'<ol><li><code>augur_prediction_run()</code> loads eligible qualification matches, scouting actions, prior-event Neptune observations and the public EPA map.</li><li><code>augur_prediction_offense_blend()</code> blends current-event average, weighted recent scoring, the 75th percentile and public EPA. Current-event scouting gradually gains influence as samples accumulate.</li><li><code>augur_prediction_team_metrics()</code> applies trend, volatility, defense suppression, success rate, record and confidence calculations.</li><li><code>augur_prediction_alliance_summary()</code> aggregates robot metrics into an alliance.</li><li><code>augur_prediction_apply_opponent()</code> applies opponent/defense effects.</li><li>The score difference and combined uncertainty are converted to win probability, then pulled toward 50/50 when model confidence is thin.</li></ol>'],
            ['title'=>'To change a model weight','html'=>'<ol><li>Edit the default in <code>analytics/_augur_prediction_settings.php</code>.</li><li>If the setting appears in the UI, update the matching control in <code>analytics/match-strategy.php</code> and/or <code>analytics/alliance-selection.php</code>.</li><li>Update the mirrored browser defaults in <code>assets/js/alliance-selection.js</code>.</li><li>If it is a new setting, add its validation/clamp rule in <code>augur_prediction_settings()</code>.</li><li>Use the setting inside the shared model—not inside just one caller—so all prediction surfaces stay consistent.</li></ol>'],
            ['title'=>'To change the prediction algorithm','html'=>'<p>Make the core change in <code>_augur_prediction_model.php</code>. Avoid implementing a separate calculation in Match Strategy or Alliance Selection. Both current callers explicitly enable <code>use_epa =&gt; true</code>, and the model itself defaults EPA on unless a caller disables it.</p><div class="dg-callout warn"><b>Historical backtesting:</b> Match Strategy can pass a <code>cutoff_match</code>. Any new data source must respect that cutoff or it can leak future match information into a historical prediction.</div>'],
            ['title'=>'Settings storage','html'=>'<p>Event-specific matchup settings are stored in the Alliance Selection workspace under <code>workspace.settings.matchup_model</code>. <code>api/augur-prediction-settings.php</code> reads and writes the same settings used by the shared model. Defaults apply when an event has no saved override.</p>'],
            ['title'=>'Regression checklist','html'=>'<ul><li>Match Strategy prediction before a match.</li><li>Match Strategy “current” prediction after more scouting data exists.</li><li>Alliance Selection matchup dialog.</li><li>Robot analytics / robot list values.</li><li>A team with public EPA but no Neptune scouting.</li><li>A team with Neptune scouting but missing public EPA.</li><li>Early event with one or two matches.</li><li>Historical match cutoff/backtest.</li><li>Missing/partial alliance data.</li><li>Saved custom settings versus defaults.</li></ul>'],
        ],
    ],
    [
        'id' => 'new-feature',
        'title' => 'Add a New Neptune Feature',
        'icon' => 'fa-puzzle-piece',
        'summary' => 'A safe page/API pattern covering access, organization isolation, CSRF, navigation, shared styling and testing.',
        'tags' => ['new feature','new page','API','PHP','permissions','roles','organization_id','CSRF','navigation','Command Center'],
        'files' => [
            'neptune_secure/bootstrap.php — shared app helpers',
            'public_html/Neptune/admin/system-check.php — small authenticated page example',
            'public_html/Neptune/api/augur-prediction-settings.php — authenticated GET/POST API example',
            'public_html/Neptune/admin/index.php — Command Center cards',
            'public_html/Neptune/partials_header.php — pageStyles and shared chrome',
            'public_html/Neptune/partials_footer.php — closing chrome',
        ],
        'search' => ['require_role(', 'require_login()', 'verify_csrf()', 'csrf_token()', 'json_response(', 'organization_id=?', 'base_url(', '$pageStyles', 'neptune-hub-card'],
        'sections' => [
            ['title'=>'Minimal page pattern','html'=>'<pre><code>&lt;?php
require_once dirname(__DIR__,3).\'/neptune_secure/bootstrap.php\';
$u = require_role([\'owner\',\'admin\']);
$org = (int)$u[\'organization_id\'];
$pageTitle = \'My Feature\';
$moduleName = \'SATURN\';
$pageStyles = [\'assets/css/my-feature.css\'];
include dirname(__DIR__).\'/partials_header.php\';
?&gt;
...page...
&lt;?php include dirname(__DIR__).\'/partials_footer.php\';</code></pre>'],
            ['title'=>'Access and tenant isolation','html'=>'<ul><li>Use <code>require_login()</code> for normal authenticated pages and <code>require_role([...])</code> when a role minimum is required.</li><li>Take the organization ID from the logged-in user, not from a request parameter.</li><li>For organization-owned tables, include <code>organization_id=?</code> in reads, updates and deletes.</li><li>For tables scoped through a parent, join through the organization-owned parent instead of trusting a bare child ID.</li><li>Platform-only tools should follow the existing pattern: require <code>owner</code>, compare the user organization to <code>$config[\'app\'][\'platform_organization_id\']</code>, then return 403 when it does not match.</li></ul>'],
            ['title'=>'POST/API safety','html'=>'<ul><li>Use <code>csrf_token()</code> in forms/request configuration and call <code>verify_csrf()</code> before state changes.</li><li>Use prepared PDO statements.</li><li>For JSON endpoints, return through <code>json_response()</code> with meaningful HTTP status codes.</li><li>Do not place secrets or DB credentials under <code>public_html</code>.</li></ul>'],
            ['title'=>'Add it to Neptune navigation','html'=>'<p>Put the feature in the subsystem that owns it rather than linking it everywhere. SATURN administrative/operations features usually get a card in <code>admin/index.php</code>; analytics features belong in <code>analytics/index.php</code>; scouting tools belong in <code>scouting.php</code>. Match the existing access badge to the real authorization check.</p>'],
            ['title'=>'Feature completion checklist','html'=>'<ul><li>Direct URL access enforces the same role shown on the card.</li><li>Organization A cannot read or mutate Organization B data.</li><li>POST without a valid CSRF token fails.</li><li>Dark and light themes are readable.</li><li>Mobile width is usable.</li><li>Back/refresh does not duplicate a destructive action.</li><li>Errors use Neptune UI rather than raw browser alerts when practical.</li><li>PHP files pass <code>php -l</code>.</li></ul>'],
        ],
    ],
    [
        'id' => 'css-themes',
        'title' => 'Change CSS, Colors and Themes',
        'icon' => 'fa-palette',
        'summary' => 'Where Neptune’s palette lives, what Interface Styling edits, how light/dark mode works, and when to use app.css versus neptune-ui.css versus page CSS.',
        'tags' => ['CSS','theme','dark mode','light mode','colors','palette','variables','icons','responsive','Font Awesome'],
        'files' => [
            'public_html/Neptune/assets/css/app.css — core palette, global elements and older component rules',
            'public_html/Neptune/assets/css/neptune-ui.css — current shared Neptune card/header/toast/readability layer',
            'public_html/Neptune/admin/styling.php — platform-owner Interface Styling editor and presets',
            'public_html/Neptune/partials_header.php — initializes data-theme and loads shared CSS',
        ],
        'search' => ['NEPTUNE THEME PALETTE:START', ':root{', 'html[data-theme="light"]', '--module-', '--access-', '--panel', '--text', '--muted', 'color-mix(', '@media(max-width'],
        'sections' => [
            ['title'=>'Single palette source','html'=>'<p>The editable palette is at the top of <code>assets/css/app.css</code> between <code>NEPTUNE THEME PALETTE:START</code> and <code>NEPTUNE THEME PALETTE:END</code>. <code>admin/styling.php</code> rewrites that block. Dark values live in <code>:root</code>; light overrides live in <code>html[data-theme="light"]</code>.</p><div class="dg-callout"><b>Rule:</b> If a color should be theme-configurable, reference a CSS variable. Do not hard-code a hex value in a component unless it truly must never change.</div>'],
            ['title'=>'Which stylesheet to edit','html'=>'<ul><li><b>app.css:</b> color variables, body, controls, generic cards/tables/buttons and legacy shared styles.</li><li><b>neptune-ui.css:</b> current Neptune navigation, hub cards, breadcrumbs, toasts, subsystem/access accents and light-mode readability corrections.</li><li><b>page stylesheet:</b> feature-specific layout with no reason to affect the rest of Neptune. Set <code>$pageStyles</code> before including the shared header.</li><li><b>inline page CSS:</b> still exists in several pages; use it only for tightly local behavior, and move repeated patterns to an asset.</li></ul>'],
            ['title'=>'Light-mode icon readability','html'=>'<p>Shared hub cards already use a derived <code>--card-accent-readable</code> in light mode so module colors are mixed toward <code>--text</code>. When a colored icon disappears on a light surface, first check whether the component is drawing the raw accent instead of its readable form or a text variable.</p>'],
            ['title'=>'Theme switching','html'=>'<p><code>partials_header.php</code> reads <code>localStorage.getItem(\'neptune-theme\')</code> and applies it to <code>document.documentElement.dataset.theme</code> before CSS renders. Search for <code>neptune-theme</code> when changing the toggle behavior.</p>'],
            ['title'=>'CSS regression checklist','html'=>'<ul><li>Dark mode and light mode.</li><li>Command Center, Analytics &amp; Strategy, Scouting and the public/dashboard surfaces.</li><li>Primary buttons, secondary buttons, destructive buttons and status pills.</li><li>Font Awesome icons on both panel and page backgrounds.</li><li>Phone width, tablet width and desktop.</li><li>Hover, focus-visible and disabled states.</li></ul>'],
        ],
    ],
    [
        'id' => 'javascript',
        'title' => 'How Neptune JavaScript Works',
        'icon' => 'fa-code',
        'summary' => 'Shared UI helpers, page-specific scripts, fetch conventions, event delegation, offline queues and the places most likely to affect multiple screens.',
        'tags' => ['JavaScript','JS','fetch','event listener','toast','modal','offline queue','localStorage','NeptuneUI','DOM'],
        'files' => [
            'public_html/Neptune/assets/js/neptune-ui.js — global toast, confirm and prompt UI plus shared event delegation',
            'public_html/Neptune/assets/js/alliance-selection.js — large stateful page module, API calls, offline queue and prediction settings',
            'public_html/Neptune/scout/match.php — inline match-scout timing/state behavior',
            'public_html/Neptune/pit/scout.php — pointer/canvas drawing, image choice and photo deletion behavior',
            'public_html/Neptune/prescout/team.php — pointer/canvas drawing behavior',
        ],
        'search' => ['window.NeptuneUI', 'NeptuneUI.toast', 'NeptuneUI.confirm', 'addEventListener(', 'fetch(', 'DOMContentLoaded', 'localStorage', 'navigator.onLine', 'pointerdown', 'data-confirm'],
        'sections' => [
            ['title'=>'Shared UI layer','html'=>'<p><code>assets/js/neptune-ui.js</code> creates <code>window.NeptuneUI</code> with <code>toast()</code>, <code>confirm()</code> and <code>prompt()</code>. It also converts surviving <code>alert()</code> calls into Neptune toasts and supports declarative form confirmation through <code>data-confirm</code>.</p><pre><code>NeptuneUI.toast(\'Saved.\', \'good\');
const ok = await NeptuneUI.confirm(\'Delete this item?\', { danger: true });</code></pre>'],
            ['title'=>'Fetch/API convention','html'=>'<p>Page scripts generally send requests to Neptune API endpoints, request JSON, check both HTTP status and the returned <code>ok</code>/<code>status</code> field, then update the page or show a toast. State-changing requests include the session CSRF token.</p>'],
            ['title'=>'Alliance Selection is the biggest JS module','html'=>'<p><code>alliance-selection.js</code> owns client state, rendering, mutations, matchup requests and model-setting interactions. It uses an event-specific localStorage queue named <code>neptune-alliance-queue:&lt;eventId&gt;</code>. Network failures for queueable mutations are stored locally, reflected immediately in UI state and replayed when the browser fires <code>online</code>.</p>'],
            ['title'=>'Pointer and drawing code','html'=>'<p>Pit and Pre-Scout autonomous-path drawing use pointer events (<code>pointerdown</code>, <code>pointermove</code>, <code>pointerup</code>) rather than separate mouse/touch handlers. Preserve <code>touch-action:none</code> on the canvas when changing that interaction.</p>'],
            ['title'=>'When adding JavaScript','html'=>'<ul><li>Prefer event delegation for repeated/dynamic elements.</li><li>Use optional chaining for elements that are legitimately absent.</li><li>Do not create another global helper if it belongs in <code>NeptuneUI</code>.</li><li>For destructive actions, use Neptune confirmation UI rather than native <code>confirm()</code>.</li><li>Keep server authorization authoritative; hiding a button in JS is not security.</li><li>Test connection loss if the feature claims any offline behavior.</li></ul>'],
        ],
    ],
    [
        'id' => 'database',
        'title' => 'Database Changes and Organization Scoping',
        'icon' => 'fa-database',
        'summary' => 'How Neptune reaches MySQL, the tenant-isolation rules to preserve, and a migration checklist for new tables and columns.',
        'tags' => ['database','MySQL','PDO','SQL','organization_id','multi-org','tenant','migration','schema','foreign key'],
        'files' => [
            'neptune_secure/connection.php — primary PDO connection',
            'neptune_secure/bootstrap.php — loads the connection and application helpers',
            'public_html/Neptune/sql/ — SQL migrations included with app updates',
            'public_html/Neptune/sql/2026-09-22_spot-scouting-v1.sql — current migration example in this source package',
        ],
        'search' => ['organization_id=?', 'JOIN events', 'JOIN matches', '$pdo->prepare(', 'beginTransaction()', 'commit()', 'rollBack()', 'CREATE TABLE', 'ALTER TABLE'],
        'sections' => [
            ['title'=>'Tenant rule','html'=>'<div class="dg-callout warn"><b>Every organization-owned feature must be scoped server-side.</b> Never rely on a hidden field, URL parameter or JavaScript filter to isolate organizations.</div><ul><li>Tables with <code>organization_id</code>: filter by the logged-in user\'s organization in every relevant SELECT/UPDATE/DELETE.</li><li>Child tables without a direct organization column: scope through an organization-owned parent such as event, match or team.</li><li>Global reference data should be deliberately identified as global rather than accidentally left unscoped.</li></ul>'],
            ['title'=>'Adding a schema change','html'=>'<ol><li>Create a dated SQL migration under <code>public_html/Neptune/sql/</code> (or the deployment SQL location used by the target installer).</li><li>Make the migration safe for the intended deployment path and document whether it is one-time.</li><li>Add indexes for organization/event/match keys used by the new queries.</li><li>Update PHP code only after defining the old-schema behavior: hard fail with a useful message, feature-detect, or migrate first.</li><li>Test an existing organization and a second organization.</li><li>Back up before applying production schema changes.</li></ol>'],
            ['title'=>'PDO pattern','html'=>'<pre><code>$stmt = $pdo-&gt;prepare(
    \'SELECT * FROM example WHERE organization_id=? AND event_id=?\'
);
$stmt-&gt;execute([$org, $eventId]);</code></pre><p>For multi-step writes, use a transaction and roll back on exceptions.</p>'],
            ['title'=>'Isolation test','html'=>'<p>After adding a feature, sign in as a second organization and attempt direct URLs/API calls with IDs from the first organization. The correct result is an empty/not-found/forbidden response—not data from the other tenant.</p>'],
        ],
    ],
    [
        'id' => 'offline',
        'title' => 'Offline and Field-Server Behavior',
        'icon' => 'fa-tower-broadcast',
        'summary' => 'Distinguishes browser-side offline behavior from Neptune’s local event server and cloud synchronization.',
        'tags' => ['offline','field server','LAN','sync','localStorage','cloud sync','network','router','PWA','manifest'],
        'files' => [
            'scripts/field-server/NETWORK.md — recommended event LAN layout',
            'scripts/field-server/install-field-server.sh — installs a standalone local Neptune host',
            'scripts/field-server/sync-to-cloud.php — local-to-cloud synchronization logic',
            'scripts/field-server/sync-to-cloud.sh — sync launcher',
            'public_html/Neptune/api/offline-sync.php — cloud-side sync receiver',
            'public_html/Neptune/api/offline-status.php — sync/health endpoint',
            'public_html/Neptune/assets/js/alliance-selection.js — browser localStorage queue',
            'public_html/Neptune/scout/match.php — last-known/local match-clock behavior',
            'public_html/Neptune/manifest.webmanifest — install metadata; not by itself a service worker',
        ],
        'search' => ['NEPTUNE_FIELD_MODE', 'NEPTUNE_OFFLINE_SYNC_KEY', 'offline-sync', 'navigator.onLine', 'neptune-alliance-queue:', 'window.addEventListener(\'online\'', 'Offline —', 'manifest.webmanifest'],
        'sections' => [
            ['title'=>'There are three different offline concepts','html'=>'<ol><li><b>Event LAN:</b> the field laptop runs Apache/MariaDB locally. Scout devices use the router LAN even when the venue has no Internet.</li><li><b>Cloud synchronization:</b> field-server scripts send local data back to the cloud endpoint when connectivity is available.</li><li><b>Browser resilience:</b> some pages have local behavior. Alliance Selection queues queueable mutations in localStorage; Match Scouting can use last-known timing state. This is not the same as a full application-wide service-worker cache.</li></ol>'],
            ['title'=>'Current PWA note','html'=>'<div class="dg-callout"><b>Do not assume the manifest means full offline PWA support.</b> In this source package, <code>manifest.webmanifest</code> is linked, but no application service-worker file is present. A future service worker would need its own cache/version/update strategy and careful testing around authenticated/API responses.</div>'],
            ['title'=>'When changing offline behavior','html'=>'<ul><li>Test venue LAN with WAN/Internet physically disconnected.</li><li>Test browser network drop during an active match.</li><li>Test duplicate/replayed sync requests.</li><li>Keep sync secrets outside <code>public_html</code>.</li><li>Do not cache authenticated API responses broadly without an explicit security design.</li><li>Run the field-server health check and a backup before competition begins.</li></ul>'],
        ],
    ],
    [
        'id' => 'deployment',
        'title' => 'Package, Deploy and Roll Back an Update',
        'icon' => 'fa-box-open',
        'summary' => 'How the Maintenance Console accepts patch ZIPs, validates PHP, backs up replaced files and rolls back the last install.',
        'tags' => ['deploy','update','patch','ZIP','Maintenance Console','rollback','backup','php -l','permissions','GitHub','AWS'],
        'files' => [
            'public_html/Neptune/admin/maintenance.php — browser patch installer, validation and rollback',
            'public_html/Neptune/admin/system-check.php — host capability checks',
            'public_html/Neptune/README_UI_REFRESH.txt — example update notes',
        ],
        'search' => ['nm_target_for_entry(', 'nm_zip_entries(', 'nm_validate_php_in_zip(', 'nm_install_zip(', 'nm_rollback_latest(', 'maintenance-backups', 'php -l'],
        'sections' => [
            ['title'=>'Patch ZIP layout','html'=>'<p>The Maintenance Console accepts Neptune-relative paths such as <code>admin/</code>, <code>analytics/</code>, <code>api/</code>, <code>assets/</code>, <code>dashboard/</code>, <code>images/</code>, <code>pit/</code>, <code>prescout/</code>, <code>scout/</code>, <code>spot/</code> and root app files. It also supports full server-relative prefixes such as <code>public_html/Neptune/</code>, <code>neptune_secure/</code>, <code>scripts/</code> and <code>sql/</code>.</p><p>It ignores Finder metadata (<code>__MACOSX</code>, <code>.DS_Store</code>) and can strip one harmless wrapper directory when every resulting file is a valid Neptune path.</p>'],
            ['title'=>'Protected paths','html'=>'<p>The installer explicitly blocks credentials such as <code>neptune_secure/config.php</code>, <code>/etc/scout/db.env</code>, <code>/etc/scout/offline-sync.env</code> and credential-like paths. Keep deployment packages free of secrets.</p>'],
            ['title'=>'Install sequence','html'=>'<ol><li>Build the patch with only the files that should change.</li><li>Lint every changed PHP file locally with <code>php -l</code>.</li><li>Upload the ZIP to Maintenance Console.</li><li>Review the package file list before install.</li><li>Install. Neptune validates PHP source and creates a backup manifest before overwriting.</li><li>Smoke-test affected pages and APIs.</li><li>If needed, use the Maintenance Console rollback for the latest file patch.</li></ol>'],
            ['title'=>'Important rollback limit','html'=>'<div class="dg-callout warn"><b>File rollback is not database rollback.</b> A ZIP that includes a schema migration needs its own database rollback/forward-fix plan. Do not assume the Maintenance Console file backup reverses SQL already applied.</div>'],
        ],
    ],
    [
        'id' => 'tba-external',
        'title' => 'TBA and External Data',
        'icon' => 'fa-cloud-arrow-down',
        'summary' => 'Where Neptune talks to The Blue Alliance and how AUGUR keeps public EPA data local once it has been ingested.',
        'tags' => ['TBA','The Blue Alliance','Statbotics','external data','event sync','EPA archive','API'],
        'files' => [
            'neptune_secure/tba.php — The Blue Alliance helper/client code',
            'neptune_secure/statbotics.php — Statbotics helper code where still used',
            'public_html/Neptune/admin/tba-sync.php — event/team/match synchronization UI',
            'public_html/Neptune/analytics/_augur_epa.php — AUGUR-managed Public EPA archive ingestion/calculation',
            'public_html/Neptune/admin/augur-epa-archive.php — archive administration UI',
            'public_html/Neptune/api/augur-epa-admin.php — archive maintenance actions',
        ],
        'search' => ['tba_event_key', 'tba.php', 'augur_epa_archive_events', 'augur_epa_event_ratings', 'augur_epa_season_ratings', 'augur_epa_rating_map(', 'statbotics'],
        'sections' => [
            ['title'=>'Prediction requests use local EPA data','html'=>'<p>The shared prediction engine calls <code>augur_epa_rating_map()</code>. Its own comment explicitly states that prediction requests do not call TBA/OPR as a live fallback; missing public EPA falls back to already stored Neptune scouting and observed field data. This keeps match predictions from depending on a live external request.</p>'],
            ['title'=>'When changing an external integration','html'=>'<ul><li>Keep API keys in protected configuration, never browser JavaScript.</li><li>Handle the external service being unavailable.</li><li>Preserve existing local scouting when relinking/syncing an event.</li><li>Separate “fetch/ingest” from “read/use” where possible so competition-day analytics can operate from local data.</li><li>Rate-limit or batch large archive jobs.</li></ul>'],
        ],
    ],
];
