<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_login();
$org = (int)($u['organization_id'] ?? 0);
$isDatabaseOwner = in_array(($u['role'] ?? ''), ['owner', 'admin', 'strategy'], true);

function data_lab_json(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function data_lab_query_is_safe(string $sql): array {
    $sql = trim($sql);
    if ($sql === '') {
        return [false, 'Enter a query first.'];
    }
    if (strlen($sql) > 20000) {
        return [false, 'Query is too long.'];
    }

    // Allow one optional trailing semicolon, but never multiple statements.
    $sql = preg_replace('/;\s*$/', '', $sql, 1);
    if (str_contains($sql, ';')) {
        return [false, 'Only one SQL statement can be run at a time.'];
    }

    // Keep this page intentionally read-only.
    if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', ltrim($sql))) {
        return [false, 'Data Lab is read-only. Use SELECT, SHOW, DESCRIBE, DESC, or EXPLAIN.'];
    }

    // Reject comments and server-side features that can be abused even in SELECTs.
    $blocked = [
        '/--/',
        '/#/',
        '/\/\*/',
        '/\*\//',
        '/\bINTO\b/i',
        '/\bOUTFILE\b/i',
        '/\bDUMPFILE\b/i',
        '/\bLOAD_FILE\s*\(/i',
        '/\bSLEEP\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        '/\bGET_LOCK\s*\(/i',
        '/\bRELEASE_LOCK\s*\(/i',
        '/\bIS_FREE_LOCK\s*\(/i',
        '/\bIS_USED_LOCK\s*\(/i',
        '/\bMASTER_POS_WAIT\s*\(/i',
        '/\bWAIT_FOR_EXECUTED_GTID_SET\s*\(/i',
        '/\bFOR\s+UPDATE\b/i',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
    ];
    foreach ($blocked as $pattern) {
        if (preg_match($pattern, $sql)) {
            return [false, 'That SQL feature is disabled in Data Lab.'];
        }
    }

    if (preg_match('/^EXPLAIN\b/i', $sql) && !preg_match('/^EXPLAIN(?:\s+FORMAT\s*=\s*(?:JSON|TREE|TRADITIONAL))?\s+SELECT\b/i', $sql)) {
        return [false, 'EXPLAIN is limited to SELECT queries here.'];
    }

    return [true, $sql];
}

if ($isDatabaseOwner && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_query') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        data_lab_json(['ok' => false, 'error' => 'Invalid CSRF token. Refresh the page and try again.'], 419);
    }

    [$safe, $value] = data_lab_query_is_safe((string)($_POST['sql'] ?? ''));
    if (!$safe) {
        data_lab_json(['ok' => false, 'error' => $value], 400);
    }
    $sql = $value;

    try {
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $started = microtime(true);
        $stmt = $pdo->query($sql);
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = (string)($meta['name'] ?? ('column_' . ($i + 1)));
        }

        $rows = [];
        $truncated = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= 500) {
                $truncated = true;
                break;
            }
            foreach ($row as $k => $v) {
                if (is_resource($v)) {
                    $row[$k] = '[resource]';
                } elseif (is_string($v) && strlen($v) > 5000) {
                    $row[$k] = substr($v, 0, 5000) . '…';
                }
            }
            $rows[] = $row;
        }
        $stmt->closeCursor();
        $elapsedMs = round((microtime(true) - $started) * 1000, 1);

        data_lab_json([
            'ok' => true,
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
            'elapsed_ms' => $elapsedMs,
        ]);
    } catch (Throwable $e) {
        data_lab_json(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

// Keep the existing safe raw-scouting view for users below Strategy+.
if (!$isDatabaseOwner) {
    $q = "SELECT a.*,e.name event_name,m.comp_level,m.set_number,m.match_number
          FROM scouting_actions a
          JOIN events e ON e.id=a.event_id
          JOIN matches m ON m.id=a.match_id
          WHERE a.deleted_at IS NULL
            AND (
                a.organization_id=?
                OR a.owner_team_id IN (
                    SELECT sr.owner_team_id
                    FROM sharing_relationships sr
                    JOIN teams rt ON rt.id=sr.recipient_team_id
                    WHERE rt.organization_id=?
                      AND sr.status='active'
                      AND sr.share_raw_actions=1
                      AND (sr.starts_at IS NULL OR sr.starts_at<=UTC_TIMESTAMP())
                      AND (sr.expires_at IS NULL OR sr.expires_at>=UTC_TIMESTAMP())
                )
            )
          ORDER BY a.id DESC
          LIMIT 500";
    $s = $pdo->prepare($q);
    $s->execute([$org, $org]);
    $rows = $s->fetchAll();
    $pageTitle = 'Scouting Data';
    $moduleName = 'SALT';
    include dirname(__DIR__) . '/partials_header.php';
    ?>
    <div class="toolbar" style="justify-content:space-between">
        <div>
            <div class="module-eyebrow"><span>SALT</span><small>Data Storage</small></div>
            <h1 style="margin-bottom:4px">Raw Scouting Data</h1>
            <div class="muted">Most recent 500 actions, including permitted shared raw data.</div>
        </div>
        <a class="btn secondary" href="<?=e(base_url('analytics/index.php'))?>"><i class="fa-solid fa-chart-column"></i> Augur</a>
    </div>
    <div class="card"><div class="table-wrap"><table class="table">
        <tr><th>Event</th><th>Match</th><th>Run</th><th>Robot</th><th>Time</th><th>Action</th><th>Result</th><th>Pts</th><th>Source</th></tr>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?=e($r['event_name'])?></td>
                <td><?=e(neptune_match_label($r))?></td>
                <td><?=e($r['match_run_number'] ?? 1)?></td>
                <td>#<?=e($r['frc_team_number'])?></td>
                <td><?=e($r['match_time_sec'])?></td>
                <td><?=e($r['action_name'] ?: $r['action_code'])?></td>
                <td><?=e($r['result'])?></td>
                <td><?=e($r['points'])?></td>
                <td><?=e($r['source'])?></td>
            </tr>
        <?php endforeach; ?>
    </table></div></div>
    <?php
    include dirname(__DIR__) . '/partials_footer.php';
    exit;
}

// Strategy+ schema explorer.
$schemaStmt = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS
                           FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE()
                           ORDER BY TABLE_NAME");
$tables = $schemaStmt->fetchAll();

$columnStmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, ORDINAL_POSITION
                           FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                           ORDER BY TABLE_NAME, ORDINAL_POSITION");
$columnsByTable = [];
foreach ($columnStmt->fetchAll() as $column) {
    $columnsByTable[$column['TABLE_NAME']][] = $column;
}

$fkStmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
                       FROM information_schema.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND REFERENCED_TABLE_NAME IS NOT NULL
                       ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION");
$foreignKeysForJs = array_map(static fn($fk) => [
    'table' => (string)$fk['TABLE_NAME'],
    'column' => (string)$fk['COLUMN_NAME'],
    'refTable' => (string)$fk['REFERENCED_TABLE_NAME'],
    'refColumn' => (string)$fk['REFERENCED_COLUMN_NAME'],
    'constraint' => (string)$fk['CONSTRAINT_NAME'],
], $fkStmt->fetchAll());

$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$organizationCount = (int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$schemaForJs = [];
foreach ($tables as $table) {
    $name = (string)$table['TABLE_NAME'];
    $schemaForJs[$name] = array_map(static fn($c) => [
        'name' => (string)$c['COLUMN_NAME'],
        'type' => (string)$c['COLUMN_TYPE'],
        'nullable' => ((string)$c['IS_NULLABLE'] === 'YES'),
        'key' => (string)$c['COLUMN_KEY'],
        'extra' => (string)$c['EXTRA'],
    ], $columnsByTable[$name] ?? []);
}

$pageTitle = 'Database Lab';
$moduleName = 'SALT';
$bodyClass = 'data-lab-page';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.data-lab-shell{display:grid;grid-template-columns:minmax(250px,310px) minmax(0,1fr);gap:14px;align-items:start}.schema-panel{position:sticky;top:96px;max-height:calc(100vh - 118px);overflow:hidden;display:flex;flex-direction:column;padding:0}.schema-head{padding:14px;border-bottom:1px solid var(--line)}.schema-head h2{margin:0 0 5px;font-size:1.05rem}.schema-search{margin-top:10px}.schema-list{overflow:auto;padding:8px}.schema-table{border:1px solid var(--line);border-radius:6px;margin-bottom:8px;background:var(--panel2);overflow:hidden}.schema-table-head{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;padding:9px 10px;cursor:pointer}.schema-table-head:hover{background:color-mix(in srgb,var(--accent) 8%,var(--panel2))}.schema-table-name{display:flex;align-items:center;gap:8px;min-width:0;font-weight:900}.schema-table-name span{overflow:hidden;text-overflow:ellipsis}.schema-actions{display:flex;gap:3px}.schema-mini{width:31px;height:31px;padding:0;background:transparent;color:var(--muted);border-color:transparent}.schema-mini:hover{color:var(--text);border-color:var(--line);background:var(--panel)}.schema-columns{display:none;border-top:1px solid var(--line);padding:6px}.schema-table.open .schema-columns{display:block}.schema-column{display:flex;align-items:center;gap:7px;padding:7px 8px;border-radius:4px;cursor:grab;font-size:.82rem}.schema-column:hover{background:var(--panel)}.schema-column i{width:14px;text-align:center;color:var(--muted)}.schema-column b{font-weight:850}.schema-column small{margin-left:auto;color:var(--muted);font-size:.68rem;max-width:110px;overflow:hidden;text-overflow:ellipsis}.schema-column.dragging{opacity:.45}.lab-main{display:grid;gap:14px;min-width:0}.lab-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.lab-title-row h1{margin:2px 0 5px}.lab-tabs{display:flex;gap:5px;padding:4px;border:1px solid var(--line);border-radius:6px;background:var(--panel2)}.lab-tab{padding:8px 12px;background:transparent;color:var(--muted);border-color:transparent}.lab-tab.active{background:var(--c1-blue);color:#fff}.builder-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.builder-block{min-height:118px;border:1px solid var(--line);border-radius:7px;background:var(--panel2);padding:12px}.builder-block.is-over{outline:2px solid var(--accent);outline-offset:1px}.builder-label{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:9px}.builder-label b{display:flex;align-items:center;gap:7px;font-size:.82rem;letter-spacing:.06em;text-transform:uppercase}.builder-label small{color:var(--muted)}.drop-hint{display:grid;place-items:center;min-height:62px;border:1px dashed var(--line);border-radius:5px;color:var(--muted);font-size:.82rem;text-align:center;padding:10px}.block-chip-wrap{display:flex;flex-wrap:wrap;gap:6px}.query-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 8px;border:1px solid var(--line);border-radius:5px;background:var(--panel);font:700 .78rem ui-monospace,SFMono-Regular,Menlo,monospace}.query-chip button{width:22px;height:22px;padding:0;border:0;background:transparent;color:var(--muted)}.query-chip button:hover{color:var(--bad)}.filter-row,.sort-row{display:grid;grid-template-columns:minmax(135px,1.2fr) minmax(90px,.65fr) minmax(120px,1fr) auto;gap:6px;align-items:center;margin-top:6px}.sort-row{grid-template-columns:minmax(160px,1fr) 105px auto}.filter-row input,.filter-row select,.sort-row select{padding:8px}.join-row{display:grid;grid-template-columns:118px minmax(130px,.9fr) minmax(180px,1.25fr) 28px minmax(180px,1.25fr) auto;gap:6px;align-items:center;margin-top:7px;padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--panel)}.join-row select{padding:8px;min-width:0}.join-eq{text-align:center;font:900 1rem ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--accent)}.join-auto{font-size:.7rem;color:var(--muted);margin-top:5px}.row-remove{width:34px;height:34px;padding:0;background:transparent;color:var(--muted);border-color:var(--line)}.sql-area{position:relative}.sql-editor{min-height:190px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;tab-size:2}.sql-help{display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:var(--muted);font-size:.78rem;margin-top:7px}.results-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}.results-wrap{max-height:58vh;overflow:auto;border:1px solid var(--line);border-radius:6px}.results-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:.82rem}.results-table th,.results-table td{padding:8px 9px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);text-align:left;vertical-align:top;max-width:460px;white-space:pre-wrap;overflow-wrap:anywhere}.results-table th{position:sticky;top:0;z-index:3;background:var(--panel2);color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}.results-table td.null{color:var(--muted);font-style:italic}.lab-empty{padding:42px 16px;text-align:center;color:var(--muted)}.lab-status{min-height:22px}.history-menu{display:none;position:absolute;right:0;top:44px;width:min(520px,90vw);max-height:340px;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:7px;box-shadow:0 18px 45px var(--shadow);z-index:12;padding:8px}.history-menu.open{display:block}.history-item{padding:8px;border-radius:5px;cursor:pointer}.history-item:hover{background:var(--panel2)}.history-item code{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);font-size:.75rem}.history-item small{color:var(--muted)}.lab-banner{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:5px;background:var(--panel2);font-size:.84rem;line-height:1.4}.lab-banner.warn{border-left-color:var(--warn)}.quick-query{display:flex;gap:6px;flex-wrap:wrap}.quick-query button{padding:7px 9px;font-size:.78rem}.table-count{font-size:.68rem;color:var(--muted);font-weight:700}.mode-panel[hidden]{display:none!important}@media(max-width:980px){.data-lab-shell{grid-template-columns:1fr}.schema-panel{position:relative;top:auto;max-height:440px}.builder-grid{grid-template-columns:1fr}}@media(max-width:760px){.join-row{grid-template-columns:1fr 1fr}.join-eq{display:none}.join-row .row-remove{justify-self:end}}@media(max-width:620px){.filter-row{grid-template-columns:1fr 1fr}.filter-row input{grid-column:1/-1}.filter-row .row-remove{grid-column:2;justify-self:end}.sort-row{grid-template-columns:1fr 100px auto}.lab-title-row{display:block}.lab-tabs{margin-top:10px;width:100%}.lab-tab{flex:1}}
</style>

<div class="toolbar" style="justify-content:space-between;margin-top:0">
    <div>
        <div class="module-eyebrow"><span>SALT</span><small>Database Lab</small></div>
        <h1 style="margin-bottom:4px">Database Lab</h1>
        <div class="muted">Explore tables and build read-only queries with blocks or SQL.</div>
    </div>
    <div class="toolbar" style="margin:0">
        <span class="pill"><i class="fa-solid fa-database"></i> <?=e($databaseName)?></span>
        <span class="pill"><?=count($tables)?> tables</span>
    </div>
</div>

<?php if ($organizationCount > 1): ?>
<div class="lab-banner warn" style="margin-bottom:14px">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div><b>Multi-organization database.</b> Database Lab shows the physical database and does not apply Neptune's normal organization filters. It is therefore restricted to Strategy+ users.</div>
</div>
<?php else: ?>
<div class="lab-banner" style="margin-bottom:14px">
    <i class="fa-solid fa-shield-halved"></i>
    <div><b>Read-only by design.</b> This page runs SELECT/SHOW/DESCRIBE/EXPLAIN only. INSERT, UPDATE, DELETE, DROP and other changes are blocked.</div>
</div>
<?php endif; ?>

<div class="data-lab-shell">
    <aside class="card schema-panel">
        <div class="schema-head">
            <h2><i class="fa-solid fa-table-list"></i> Tables &amp; Columns</h2>
            <div class="muted" style="font-size:.78rem">Click a column to add it. Drag works on desktop.</div>
            <input class="schema-search" id="schemaSearch" type="search" placeholder="Find table or column…" autocomplete="off">
        </div>
        <div class="schema-list" id="schemaList">
            <?php foreach ($tables as $table): $tableName = (string)$table['TABLE_NAME']; $cols = $columnsByTable[$tableName] ?? []; ?>
                <section class="schema-table" data-table="<?=e($tableName)?>" data-search="<?=e(strtolower($tableName . ' ' . implode(' ', array_column($cols, 'COLUMN_NAME'))))?>">
                    <div class="schema-table-head" tabindex="0">
                        <div class="schema-table-name"><i class="fa-solid fa-table"></i><span><?=e($tableName)?></span></div>
                        <div class="schema-actions">
                            <span class="table-count"><?=isset($table['TABLE_ROWS']) ? '~'.number_format((int)$table['TABLE_ROWS']) : ''?></span>
                            <button type="button" class="schema-mini join-table" data-table="<?=e($tableName)?>" title="Add as JOIN"><i class="fa-solid fa-link"></i></button>
                            <button type="button" class="schema-mini browse-table" data-table="<?=e($tableName)?>" title="Browse"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="schema-columns">
                        <?php foreach ($cols as $column): ?>
                            <div class="schema-column" draggable="true" data-table="<?=e($tableName)?>" data-column="<?=e($column['COLUMN_NAME'])?>" title="<?=e($column['COLUMN_TYPE'])?>">
                                <i class="fa-solid <?=($column['COLUMN_KEY'] === 'PRI' ? 'fa-key' : 'fa-grip-vertical')?>"></i>
                                <b><?=e($column['COLUMN_NAME'])?></b>
                                <small><?=e($column['COLUMN_TYPE'])?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="lab-main">
        <div class="card">
            <div class="lab-title-row">
                <div>
                    <h2 style="margin:0 0 5px">Query Builder</h2>
                    <div class="muted">App-Inventor-style blocks generate the SQL for you.</div>
                </div>
                <div class="lab-tabs" role="tablist">
                    <button type="button" class="lab-tab active" data-mode="blocks"><i class="fa-solid fa-cubes"></i> Blocks</button>
                    <button type="button" class="lab-tab" data-mode="sql"><i class="fa-solid fa-code"></i> SQL</button>
                </div>
            </div>

            <div class="mode-panel" id="blocksPanel" style="margin-top:14px">
                <div class="builder-grid">
                    <div class="builder-block drop-zone" data-drop="from">
                        <div class="builder-label"><b><i class="fa-solid fa-table"></i> From</b><small>base table</small></div>
                        <div id="fromBlock"><div class="drop-hint">Drop a table/column here, or click a table on the left.</div></div>
                    </div>
                    <div class="builder-block drop-zone" data-drop="join">
                        <div class="builder-label"><b><i class="fa-solid fa-link"></i> Join</b><button type="button" class="secondary" id="addJoinBtn" style="padding:5px 8px;font-size:.72rem"><i class="fa-solid fa-plus"></i> Join</button></div>
                        <div id="joinBlocks"><div class="drop-hint">Drop a column from another table here, or use the link button beside a table. Foreign keys are matched automatically when possible.</div></div>
                    </div>
                    <div class="builder-block drop-zone" data-drop="select">
                        <div class="builder-label"><b><i class="fa-solid fa-list-check"></i> Select</b><small>columns</small></div>
                        <div class="block-chip-wrap" id="selectBlocks"><div class="drop-hint" style="width:100%">Drop or click columns. Empty means SELECT *</div></div>
                    </div>
                    <div class="builder-block drop-zone" data-drop="where">
                        <div class="builder-label"><b><i class="fa-solid fa-filter"></i> Where</b><button type="button" class="secondary" id="addFilterBtn" style="padding:5px 8px;font-size:.72rem"><i class="fa-solid fa-plus"></i> Filter</button></div>
                        <div id="filterBlocks"><div class="drop-hint">Drop a column here to create a filter.</div></div>
                    </div>
                    <div class="builder-block drop-zone" data-drop="order">
                        <div class="builder-label"><b><i class="fa-solid fa-arrow-down-wide-short"></i> Order</b><button type="button" class="secondary" id="addSortBtn" style="padding:5px 8px;font-size:.72rem"><i class="fa-solid fa-plus"></i> Sort</button></div>
                        <div id="sortBlocks"><div class="drop-hint">Drop a column here to sort results.</div></div>
                    </div>
                </div>
                <div class="toolbar" style="margin-bottom:0">
                    <label style="margin:0;display:flex;align-items:center;gap:7px">Limit
                        <select id="limitSelect" style="width:auto;padding:8px 30px 8px 10px">
                            <option>25</option><option selected>100</option><option>250</option><option>500</option>
                        </select>
                    </label>
                    <button type="button" class="secondary" id="clearBuilder"><i class="fa-solid fa-eraser"></i> Clear</button>
                </div>
            </div>

            <div class="mode-panel" id="sqlPanel" hidden style="margin-top:14px">
                <div class="lab-banner" style="margin-bottom:10px"><i class="fa-solid fa-circle-info"></i><div>Use SQL for advanced joins, subqueries, aggregates, or anything the blocks do not cover. The server still enforces read-only commands.</div></div>
            </div>

            <div class="sql-area" style="margin-top:12px">
                <div class="builder-label">
                    <b><i class="fa-solid fa-terminal"></i> SQL</b>
                    <div class="quick-query">
                        <button type="button" class="secondary" id="historyBtn"><i class="fa-solid fa-clock-rotate-left"></i> History</button>
                        <button type="button" class="secondary" id="copySqlBtn"><i class="fa-regular fa-copy"></i> Copy</button>
                    </div>
                </div>
                <textarea class="sql-editor" id="sqlEditor" spellcheck="false" placeholder="Choose a table or type a SELECT query…"></textarea>
                <div class="history-menu" id="historyMenu"></div>
                <div class="sql-help"><span>Run: <b>Ctrl/Cmd + Enter</b></span><span>•</span><span>Maximum 500 returned rows</span><span>•</span><span>5-second server limit</span></div>
            </div>

            <div class="toolbar" style="justify-content:space-between;margin-bottom:0">
                <div class="lab-status muted" id="labStatus"></div>
                <button type="button" id="runQueryBtn"><i class="fa-solid fa-play"></i> Run Query</button>
            </div>
        </div>

        <div class="card">
            <div class="toolbar" style="justify-content:space-between;margin-top:0">
                <div>
                    <h2 style="margin:0 0 4px">Results</h2>
                    <div class="results-meta muted" id="resultsMeta">No query run yet.</div>
                </div>
                <button type="button" class="secondary" id="exportCsvBtn" disabled><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            </div>
            <div id="resultsArea"><div class="lab-empty"><i class="fa-solid fa-table" style="font-size:2rem;display:block;margin-bottom:10px"></i>Build a query above and run it.</div></div>
        </div>
    </section>
</div>

<script>
(() => {
    const schema = <?=json_encode($schemaForJs, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)?>;
    const foreignKeys = <?=json_encode($foreignKeysForJs, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)?>;
    const csrf = <?=json_encode(csrf_token())?>;
    const endpoint = <?=json_encode(base_url('dashboard/data.php'))?>;
    const sqlEditor = document.getElementById('sqlEditor');
    const statusEl = document.getElementById('labStatus');
    const resultsArea = document.getElementById('resultsArea');
    const resultsMeta = document.getElementById('resultsMeta');
    const runBtn = document.getElementById('runQueryBtn');
    const exportBtn = document.getElementById('exportCsvBtn');
    let baseTable = '';
    let joins = [];
    let selectedColumns = [];
    let filters = [];
    let sorts = [];
    let latestResult = null;
    let builderSync = true;

    const qid = s => '`' + String(s).replaceAll('`','``') + '`';
    const qcol = (t,c) => qid(t) + '.' + qid(c);
    const escHtml = v => String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[c]));
    const sqlValue = v => "'" + String(v).replaceAll("'", "''") + "'";
    const colValue = (t,c) => encodeURIComponent(t) + '|' + encodeURIComponent(c);
    const parseColValue = v => {
        const p=String(v).split('|');
        return {table:decodeURIComponent(p[0]||''),column:decodeURIComponent(p[1]||'')};
    };

    function activeTables(joinLimit=joins.length){
        const list = baseTable ? [baseTable] : [];
        joins.slice(0,joinLimit).forEach(j=>{ if(j.table && !list.includes(j.table)) list.push(j.table); });
        return list;
    }

    function currentColumns(table=baseTable){ return table && schema[table] ? schema[table] : []; }

    function firstUsefulColumn(table){
        const cols=currentColumns(table);
        return (cols.find(c=>c.key==='PRI') || cols.find(c=>c.name==='id') || cols[0] || {}).name || '';
    }

    function columnOptions(selectedTable='', selectedColumn='', tables=activeTables()){
        return tables.map(t=>{
            const opts=currentColumns(t).map(c=>{
                const val=colValue(t,c.name);
                const selected=(t===selectedTable && c.name===selectedColumn)?'selected':'';
                return `<option value="${escHtml(val)}" ${selected}>${escHtml(t+'.'+c.name)}</option>`;
            }).join('');
            return `<optgroup label="${escHtml(t)}">${opts}</optgroup>`;
        }).join('');
    }

    function joinTableOptions(selected='', joinIndex=joins.length){
        const already = activeTables(joinIndex);
        return Object.keys(schema).filter(t=>t===selected || !already.includes(t)).map(t=>
            `<option value="${escHtml(t)}" ${t===selected?'selected':''}>${escHtml(t)}</option>`
        ).join('');
    }

    function joinRightColumnOptions(table, selected=''){
        return currentColumns(table).map(c=>
            `<option value="${escHtml(c.name)}" ${c.name===selected?'selected':''}>${escHtml(table+'.'+c.name)}</option>`
        ).join('');
    }

    function showStatus(message, bad=false){
        statusEl.textContent = message || '';
        statusEl.style.color = bad ? 'var(--bad)' : '';
    }

    function relationshipForJoin(table, joinIndex=joins.length){
        if(!table) return null;
        const leftTables=activeTables(joinIndex);
        for(const fk of foreignKeys){
            if(fk.table===table && leftTables.includes(fk.refTable)){
                return {leftTable:fk.refTable,leftColumn:fk.refColumn,rightColumn:fk.column,constraint:fk.constraint};
            }
            if(fk.refTable===table && leftTables.includes(fk.table)){
                return {leftTable:fk.table,leftColumn:fk.column,rightColumn:fk.refColumn,constraint:fk.constraint};
            }
        }
        return null;
    }

    function pruneState(){
        const allowed=activeTables();
        selectedColumns=selectedColumns.filter(x=>allowed.includes(x.table));
        filters=filters.filter(x=>allowed.includes(x.table));
        sorts=sorts.filter(x=>allowed.includes(x.table));
    }

    function setBaseTable(table){
        if(!schema[table]) return;
        if(baseTable && baseTable !== table){
            joins = [];
            selectedColumns = [];
            filters = [];
            sorts = [];
        }
        baseTable = table;
        renderBuilder();
        syncSql();
    }

    function addJoin(table=''){
        if(!baseTable){ showStatus('Choose a FROM table first.', true); return false; }

        const candidates=Object.keys(schema).filter(t=>!activeTables().includes(t));
        if(!table){
            table=candidates.find(t=>relationshipForJoin(t)) || candidates[0] || '';
        }
        if(!table || !schema[table]) return false;
        if(activeTables().includes(table)){
            showStatus(`${table} is already part of this query.`, true);
            return false;
        }

        const rel=relationshipForJoin(table);
        const leftTable=rel?.leftTable || baseTable;
        const leftColumn=rel?.leftColumn || firstUsefulColumn(leftTable);
        const rightColumn=rel?.rightColumn || firstUsefulColumn(table);

        joins.push({
            type:'LEFT JOIN',
            table,
            leftTable,
            leftColumn,
            rightColumn,
            autoConstraint:rel?.constraint || ''
        });

        renderBuilder();
        syncSql();

        if(rel){
            showStatus(`JOIN added using ${rel.constraint}: ${rel.leftTable}.${rel.leftColumn} = ${table}.${rel.rightColumn}`);
        }else{
            showStatus(`JOIN added for ${table}. No foreign key was found, so verify the ON columns.`, true);
        }
        return true;
    }

    function ensureTableActive(table){
        if(!baseTable){ setBaseTable(table); return true; }
        if(activeTables().includes(table)) return true;
        return addJoin(table);
    }

    function addColumn(table,column){
        if(!ensureTableActive(table)) return;
        if(!selectedColumns.some(x=>x.table===table && x.column===column)){
            selectedColumns.push({table,column});
        }
        renderBuilder();
        syncSql();
    }

    function addFilter(table='', column=''){
        if(!baseTable){ showStatus('Choose a FROM table first.', true); return; }
        const t=table || activeTables()[0] || '';
        const c=column || currentColumns(t)[0]?.name || '';
        if(!t || !c) return;
        if(!activeTables().includes(t) && !ensureTableActive(t)) return;
        filters.push({table:t,column:c,operator:'=',value:''});
        renderBuilder(); syncSql();
    }

    function addSort(table='', column=''){
        if(!baseTable){ showStatus('Choose a FROM table first.', true); return; }
        const t=table || activeTables()[0] || '';
        const c=column || currentColumns(t)[0]?.name || '';
        if(!t || !c) return;
        if(!activeTables().includes(t) && !ensureTableActive(t)) return;
        sorts.push({table:t,column:c,direction:'ASC'});
        renderBuilder(); syncSql();
    }

    function renderBuilder(){
        const from = document.getElementById('fromBlock');
        from.innerHTML = baseTable
            ? `<div class="query-chip"><i class="fa-solid fa-table"></i>${escHtml(baseTable)}<button type="button" data-remove="from" title="Clear"><i class="fa-solid fa-xmark"></i></button></div>`
            : '<div class="drop-hint">Drop a table/column here, or click a table on the left.</div>';

        const joinBox=document.getElementById('joinBlocks');
        joinBox.innerHTML=joins.length ? joins.map((j,i)=>{
            const leftTables=activeTables(i);
            return `<div class="join-row" data-join-row="${i}">
                <select data-join-type="${i}">
                    <option value="INNER JOIN" ${j.type==='INNER JOIN'?'selected':''}>INNER</option>
                    <option value="LEFT JOIN" ${j.type==='LEFT JOIN'?'selected':''}>LEFT</option>
                </select>
                <select data-join-table="${i}">${joinTableOptions(j.table,i)}</select>
                <select data-join-left="${i}">${columnOptions(j.leftTable,j.leftColumn,leftTables)}</select>
                <div class="join-eq">=</div>
                <select data-join-right="${i}">${joinRightColumnOptions(j.table,j.rightColumn)}</select>
                <button type="button" class="row-remove" data-remove-join="${i}" title="Remove this JOIN and any JOINs after it"><i class="fa-solid fa-xmark"></i></button>
                ${j.autoConstraint?`<div class="join-auto" style="grid-column:1/-1"><i class="fa-solid fa-wand-magic-sparkles"></i> FK: ${escHtml(j.autoConstraint)}</div>`:''}
            </div>`;
        }).join('') : '<div class="drop-hint">Drop a column from another table here, or use the link button beside a table. Foreign keys are matched automatically when possible.</div>';

        const select = document.getElementById('selectBlocks');
        select.innerHTML = selectedColumns.length
            ? selectedColumns.map((c,i)=>`<div class="query-chip"><i class="fa-solid fa-grip-lines"></i>${escHtml(c.table+'.'+c.column)}<button type="button" data-remove-select="${i}" title="Remove"><i class="fa-solid fa-xmark"></i></button></div>`).join('')
            : '<div class="drop-hint" style="width:100%">Drop or click columns. Empty means SELECT base_table.*</div>';

        const filterBox = document.getElementById('filterBlocks');
        filterBox.innerHTML = filters.length ? filters.map((f,i)=>{
            const noValue = ['IS NULL','IS NOT NULL'].includes(f.operator);
            return `<div class="filter-row" data-filter-row="${i}">
                <select data-filter-col="${i}">${columnOptions(f.table,f.column)}</select>
                <select data-filter-op="${i}">
                    ${['=','!=','>','>=','<','<=','LIKE','NOT LIKE','IS NULL','IS NOT NULL'].map(op=>`<option ${op===f.operator?'selected':''}>${op}</option>`).join('')}
                </select>
                <input data-filter-value="${i}" value="${escHtml(f.value)}" placeholder="value" ${noValue?'disabled':''}>
                <button type="button" class="row-remove" data-remove-filter="${i}" title="Remove"><i class="fa-solid fa-xmark"></i></button>
            </div>`;
        }).join('') : '<div class="drop-hint">Drop a column here to create a filter.</div>';

        const sortBox = document.getElementById('sortBlocks');
        sortBox.innerHTML = sorts.length ? sorts.map((x,i)=>`<div class="sort-row" data-sort-row="${i}">
            <select data-sort-col="${i}">${columnOptions(x.table,x.column)}</select>
            <select data-sort-dir="${i}"><option ${x.direction==='ASC'?'selected':''}>ASC</option><option ${x.direction==='DESC'?'selected':''}>DESC</option></select>
            <button type="button" class="row-remove" data-remove-sort="${i}" title="Remove"><i class="fa-solid fa-xmark"></i></button>
        </div>`).join('') : '<div class="drop-hint">Drop a column here to sort results.</div>';
    }

    function buildSql(){
        if(!baseTable) return '';

        const select = selectedColumns.length
            ? selectedColumns.map(c=>{
                const expr=qcol(c.table,c.column);
                return joins.length ? expr+' AS '+qid(c.table+'.'+c.column) : expr;
              }).join(',\n  ')
            : qid(baseTable)+'.*';

        let sql = `SELECT\n  ${select}\nFROM ${qid(baseTable)}`;

        joins.forEach(j=>{
            sql += `\n${j.type} ${qid(j.table)} ON ${qcol(j.leftTable,j.leftColumn)} = ${qcol(j.table,j.rightColumn)}`;
        });

        if(filters.length){
            sql += '\nWHERE ' + filters.map((f,i)=>{
                const left=qcol(f.table,f.column);
                if(['IS NULL','IS NOT NULL'].includes(f.operator)) return (i?'  AND ':'') + left + ' ' + f.operator;
                return (i?'  AND ':'') + left + ' ' + f.operator + ' ' + sqlValue(f.value);
            }).join('\n');
        }

        if(sorts.length){
            sql += '\nORDER BY ' + sorts.map(x=>qcol(x.table,x.column)+' '+x.direction).join(', ');
        }

        sql += '\nLIMIT ' + document.getElementById('limitSelect').value;
        return sql;
    }

    function syncSql(){ if(builderSync) sqlEditor.value = buildSql(); }

    document.getElementById('schemaList').addEventListener('click', e => {
        const browse = e.target.closest('.browse-table');
        if(browse){ e.stopPropagation(); const t=browse.dataset.table; setBaseTable(t); joins=[]; selectedColumns=[]; filters=[]; sorts=[]; renderBuilder(); sqlEditor.value=`SELECT *\nFROM ${qid(t)}\nLIMIT 100`; runQuery(); return; }

        const joinBtn=e.target.closest('.join-table');
        if(joinBtn){ e.stopPropagation(); const t=joinBtn.dataset.table; if(!baseTable) setBaseTable(t); else addJoin(t); return; }

        const col = e.target.closest('.schema-column');
        if(col){ addColumn(col.dataset.table,col.dataset.column); return; }

        const head = e.target.closest('.schema-table-head');
        if(head){
            const box=head.closest('.schema-table');
            box.classList.toggle('open');
            if(!baseTable) setBaseTable(box.dataset.table);
        }
    });
    document.getElementById('schemaList').addEventListener('keydown', e => {
        if((e.key==='Enter'||e.key===' ') && e.target.classList.contains('schema-table-head')){e.preventDefault();e.target.click();}
    });
    document.getElementById('schemaSearch').addEventListener('input', e => {
        const q=e.target.value.trim().toLowerCase();
        document.querySelectorAll('.schema-table').forEach(el=>{
            const match=!q || el.dataset.search.includes(q);
            el.hidden=!match;
            if(q && match) el.classList.add('open');
        });
    });

    document.querySelectorAll('.schema-column').forEach(el=>{
        el.addEventListener('dragstart', e=>{
            el.classList.add('dragging');
            e.dataTransfer.setData('application/json', JSON.stringify({table:el.dataset.table,column:el.dataset.column,type:'column'}));
            e.dataTransfer.effectAllowed='copy';
        });
        el.addEventListener('dragend', ()=>el.classList.remove('dragging'));
    });
    document.querySelectorAll('.drop-zone').forEach(zone=>{
        zone.addEventListener('dragover', e=>{e.preventDefault();zone.classList.add('is-over');});
        zone.addEventListener('dragleave', ()=>zone.classList.remove('is-over'));
        zone.addEventListener('drop', e=>{
            e.preventDefault(); zone.classList.remove('is-over');
            try{
                const d=JSON.parse(e.dataTransfer.getData('application/json'));
                if(zone.dataset.drop==='from') setBaseTable(d.table);
                else if(zone.dataset.drop==='join'){ if(!baseTable) setBaseTable(d.table); else addJoin(d.table); }
                else if(zone.dataset.drop==='select') addColumn(d.table,d.column);
                else if(zone.dataset.drop==='where'){ if(!ensureTableActive(d.table)) return; addFilter(d.table,d.column); }
                else if(zone.dataset.drop==='order'){ if(!ensureTableActive(d.table)) return; addSort(d.table,d.column); }
            }catch(err){}
        });
    });

    document.getElementById('blocksPanel').addEventListener('click', e=>{
        const r=e.target.closest('[data-remove="from"]'); if(r){baseTable='';joins=[];selectedColumns=[];filters=[];sorts=[];renderBuilder();syncSql();return;}
        const rj=e.target.closest('[data-remove-join]'); if(rj){joins=joins.slice(0,+rj.dataset.removeJoin);pruneState();renderBuilder();syncSql();return;}
        const sc=e.target.closest('[data-remove-select]'); if(sc){selectedColumns.splice(+sc.dataset.removeSelect,1);renderBuilder();syncSql();return;}
        const rf=e.target.closest('[data-remove-filter]'); if(rf){filters.splice(+rf.dataset.removeFilter,1);renderBuilder();syncSql();return;}
        const rs=e.target.closest('[data-remove-sort]'); if(rs){sorts.splice(+rs.dataset.removeSort,1);renderBuilder();syncSql();return;}
    });
    document.getElementById('blocksPanel').addEventListener('input', e=>{
        const i=e.target.dataset.filterValue; if(i!==undefined){filters[+i].value=e.target.value;syncSql();}
    });
    document.getElementById('blocksPanel').addEventListener('change', e=>{
        let i;
        if((i=e.target.dataset.joinType)!==undefined){joins[+i].type=e.target.value;joins[+i].autoConstraint='';syncSql();}
        if((i=e.target.dataset.joinTable)!==undefined){
            i=+i;
            const table=e.target.value;
            joins=joins.slice(0,i+1);
            joins[i].table=table;
            const rel=relationshipForJoin(table,i);
            joins[i].leftTable=rel?.leftTable || activeTables(i)[0] || baseTable;
            joins[i].leftColumn=rel?.leftColumn || firstUsefulColumn(joins[i].leftTable);
            joins[i].rightColumn=rel?.rightColumn || firstUsefulColumn(table);
            joins[i].autoConstraint=rel?.constraint || '';
            pruneState();renderBuilder();syncSql();
        }
        if((i=e.target.dataset.joinLeft)!==undefined){
            const x=parseColValue(e.target.value);
            joins[+i].leftTable=x.table;joins[+i].leftColumn=x.column;joins[+i].autoConstraint='';syncSql();
        }
        if((i=e.target.dataset.joinRight)!==undefined){joins[+i].rightColumn=e.target.value;joins[+i].autoConstraint='';syncSql();}
        if((i=e.target.dataset.filterCol)!==undefined){const x=parseColValue(e.target.value);filters[+i].table=x.table;filters[+i].column=x.column;syncSql();}
        if((i=e.target.dataset.filterOp)!==undefined){filters[+i].operator=e.target.value;renderBuilder();syncSql();}
        if((i=e.target.dataset.sortCol)!==undefined){const x=parseColValue(e.target.value);sorts[+i].table=x.table;sorts[+i].column=x.column;syncSql();}
        if((i=e.target.dataset.sortDir)!==undefined){sorts[+i].direction=e.target.value;syncSql();}
    });
    document.getElementById('addJoinBtn').addEventListener('click',()=>addJoin());
    document.getElementById('addFilterBtn').addEventListener('click',()=>addFilter());
    document.getElementById('addSortBtn').addEventListener('click',()=>addSort());
    document.getElementById('limitSelect').addEventListener('change',syncSql);
    document.getElementById('clearBuilder').addEventListener('click',()=>{baseTable='';joins=[];selectedColumns=[];filters=[];sorts=[];builderSync=true;renderBuilder();sqlEditor.value='';showStatus('');});

    document.querySelectorAll('.lab-tab').forEach(btn=>btn.addEventListener('click',()=>{
        document.querySelectorAll('.lab-tab').forEach(b=>b.classList.toggle('active',b===btn));
        const blocks=btn.dataset.mode==='blocks';
        document.getElementById('blocksPanel').hidden=!blocks;
        document.getElementById('sqlPanel').hidden=blocks;
        builderSync=blocks;
        if(blocks && baseTable) syncSql();
    }));
    sqlEditor.addEventListener('input',()=>{ if(document.getElementById('sqlPanel').hidden===false) builderSync=false; });
    sqlEditor.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();runQuery();}});

    async function runQuery(){
        const sql=sqlEditor.value.trim();
        if(!sql){showStatus('Choose a table or enter SQL first.',true);return;}
        runBtn.disabled=true; runBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Running'; showStatus('Running query…');
        const fd=new FormData(); fd.append('action','run_query'); fd.append('csrf',csrf); fd.append('sql',sql);
        try{
            const res=await fetch(endpoint,{method:'POST',body:fd,credentials:'same-origin'});
            const data=await res.json();
            if(!res.ok||!data.ok) throw new Error(data.error||`HTTP ${res.status}`);
            latestResult=data; renderResults(data); saveHistory(sql); showStatus(`Done in ${data.elapsed_ms} ms.`);
        }catch(err){latestResult=null;exportBtn.disabled=true;resultsMeta.textContent='Query failed.';resultsArea.innerHTML=`<div class="notice bad">${escHtml(err.message)}</div>`;showStatus(err.message,true);}
        finally{runBtn.disabled=false;runBtn.innerHTML='<i class="fa-solid fa-play"></i> Run Query';}
    }
    function renderResults(data){
        exportBtn.disabled=!data.rows.length;
        resultsMeta.innerHTML=`<span class="pill">${data.row_count} row${data.row_count===1?'':'s'}</span><span class="pill">${data.elapsed_ms} ms</span>${data.truncated?'<span class="pill"><i class="fa-solid fa-scissors"></i> first 500 shown</span>':''}`;
        if(!data.columns.length){resultsArea.innerHTML='<div class="lab-empty">Query completed with no result columns.</div>';return;}
        let h='<div class="results-wrap"><table class="results-table"><thead><tr>'+data.columns.map(c=>`<th>${escHtml(c)}</th>`).join('')+'</tr></thead><tbody>';
        if(!data.rows.length){h+=`<tr><td colspan="${data.columns.length}" class="muted">No rows returned.</td></tr>`;}
        else data.rows.forEach(row=>{h+='<tr>'+data.columns.map(c=>{const v=row[c];return v===null?'<td class="null">NULL</td>':`<td>${escHtml(v)}</td>`;}).join('')+'</tr>';});
        h+='</tbody></table></div>'; resultsArea.innerHTML=h;
    }
    function saveHistory(sql){
        try{let h=JSON.parse(localStorage.getItem('neptune-data-lab-history')||'[]');h=h.filter(x=>x.sql!==sql);h.unshift({sql,at:Date.now()});h=h.slice(0,20);localStorage.setItem('neptune-data-lab-history',JSON.stringify(h));}catch(e){}
    }
    function loadHistory(){
        let h=[];try{h=JSON.parse(localStorage.getItem('neptune-data-lab-history')||'[]')}catch(e){}
        const menu=document.getElementById('historyMenu');
        menu.innerHTML=h.length?h.map((x,i)=>`<div class="history-item" data-history="${i}"><code>${escHtml(x.sql.replace(/\s+/g,' '))}</code><small>${new Date(x.at).toLocaleString()}</small></div>`).join(''):'<div class="muted" style="padding:12px">No query history yet.</div>';
        menu.dataset.history=JSON.stringify(h);
    }
    document.getElementById('historyBtn').addEventListener('click',()=>{loadHistory();document.getElementById('historyMenu').classList.toggle('open');});
    document.getElementById('historyMenu').addEventListener('click',e=>{const item=e.target.closest('[data-history]');if(!item)return;const h=JSON.parse(e.currentTarget.dataset.history||'[]');const x=h[+item.dataset.history];if(x){sqlEditor.value=x.sql;builderSync=false;document.querySelector('[data-mode="sql"]').click();}e.currentTarget.classList.remove('open');});
    document.addEventListener('click',e=>{const m=document.getElementById('historyMenu');if(!m.contains(e.target)&&!document.getElementById('historyBtn').contains(e.target))m.classList.remove('open');});
    document.getElementById('copySqlBtn').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(sqlEditor.value);showStatus('SQL copied.')}catch(e){sqlEditor.select();document.execCommand('copy');showStatus('SQL copied.')}});
    runBtn.addEventListener('click',runQuery);

    exportBtn.addEventListener('click',()=>{
        if(!latestResult||!latestResult.rows.length)return;
        const csvCell=v=>{if(v===null)return '';const s=String(v);return /[",\n\r]/.test(s)?'"'+s.replaceAll('"','""')+'"':s;};
        const lines=[latestResult.columns.map(csvCell).join(',')];
        latestResult.rows.forEach(r=>lines.push(latestResult.columns.map(c=>csvCell(r[c])).join(',')));
        const blob=new Blob([lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
        const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='neptune-query-'+new Date().toISOString().replace(/[:.]/g,'-')+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500);
    });

    renderBuilder();
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>