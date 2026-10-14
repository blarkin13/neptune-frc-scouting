<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner', 'admin', 'strategy']);
$org = (int)($u['organization_id'] ?? 0);

function data_lab_json(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Fixed, reviewed analytics catalog. Do not use schema discovery as authorization.
 * Even a future organization_id table stays invisible until explicitly reviewed.
 * Exclude authentication, personnel, contact, IP, note and arbitrary JSON payloads.
 */
function data_lab_approved_columns(): array {
    return [
        'teams' => ['id','organization_id','frc_team_number','nickname','display_name','tba_key','active','created_at'],
        'events' => ['id','organization_id','game_id','name','event_code','tba_event_key','start_date','end_date','active','event_status','is_current','roster_synced_at','schedule_synced_at','created_at'],
        'matches' => ['id','organization_id','event_id','game_id','tba_match_key','comp_level','set_number','match_number','field_id','scheduled_time','started_at','ended_at','paused_at','total_pause_seconds','run_number','red_score','blue_score','winning_alliance','state','created_at'],
        'scouting_actions' => ['id','organization_id','event_id','match_id','game_id','frc_team_number','alliance','action_code','action_name','action_type','location','result','points','match_time_sec','match_run_number','phase','source','recorded_at','deleted_at'],
        'scout_sessions' => ['id','organization_id','event_id','match_id','frc_team_number','alliance','station','field_id','match_run_number','status','created_at'],
        'robot_season_profiles' => ['id','organization_id','game_id','frc_team_number','last_event_id','created_at','updated_at'],
        'pre_scouting' => ['id','organization_id','event_id','game_id','frc_team_number','contact_status','status','completed_at','created_at','updated_at'],
        'pit_scouting' => ['id','organization_id','event_id','frc_team_number','status','started_at','completed_at','created_at','updated_at'],
        'pit_scouting_photos' => ['id','organization_id','pit_scouting_id','category','created_at'],
        'impact_scouting_observations' => ['id','organization_id','event_id','match_id','game_id','frc_team_number','alliance','station','scoring_contribution','overall_impact','auton_impact','defense_impact','created_at','updated_at'],
    ];
}

/** Only existing columns on explicit tenant-scoped tables are exposed. */
function data_lab_catalog(PDO $pdo): array {
    $policy = data_lab_approved_columns();
    $stmt = $pdo->query("SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.COLUMNS c
      JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
      WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
      ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION");
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $t = (string)$col['TABLE_NAME'];
        if (!array_key_exists($t, $policy)) continue;
        $found[$t][(string)$col['COLUMN_NAME']] = true;
    }
    $catalog = [];
    foreach ($policy as $table => $columns) {
        if (empty($found[$table]['organization_id'])) continue;
        $allowed = array_values(array_filter($columns, static fn($c) => isset($found[$table][$c])));
        if (count($allowed) < 2) continue;
        $catalog[$table] = array_fill_keys($allowed, true);
    }
    return $catalog;
}

/** Only simple database-declared, single-column foreign keys are available for joining. */
function data_lab_fk_pairs(PDO $pdo, array $catalog): array {
    $stmt = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION");
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = (string)$row['TABLE_NAME'] . ':' . (string)$row['CONSTRAINT_NAME'];
        $groups[$k][] = $row;
    }
    $pairs = [];
    foreach ($groups as $rows) {
        if (count($rows) !== 1) continue;
        $row = $rows[0];
        $t = (string)$row['TABLE_NAME']; $c = (string)$row['COLUMN_NAME'];
        $rt = (string)$row['REFERENCED_TABLE_NAME']; $rc = (string)$row['REFERENCED_COLUMN_NAME'];
        if (!isset($catalog[$t][$c], $catalog[$rt][$rc])) continue;
        $pairs[] = ['table'=>$t,'column'=>$c,'refTable'=>$rt,'refColumn'=>$rc,'constraint'=>(string)$row['CONSTRAINT_NAME']];
    }
    return $pairs;
}

function data_lab_ident(string $identifier): string {
    // Values always come from the explicit catalog, never directly from user input.
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function data_lab_require_column(array $catalog, array $active, string $table, string $column): void {
    if (!isset($active[$table]) || !isset($catalog[$table][$column])) {
        throw new InvalidArgumentException('Selected table or column is not available in the approved scouting catalog.');
    }
}

/**
 * Compiles validated, structured builder input into a parameterized SELECT.
 * Every table is independently restricted to the signed-in organization in a
 * derived table. No caller-provided SQL is ever executed.
 */
function data_lab_compile(array $spec, int $org, array $catalog, array $fkPairs): array {
    if ($org <= 0) throw new InvalidArgumentException('Organization is not selected.');
    $base = $spec['from'] ?? null;
    if (!is_string($base) || !isset($catalog[$base])) {
        throw new InvalidArgumentException('Choose an approved scouting table first.');
    }
    $parts = [];
    $args = [];
    $scopeTable = static function(string $table) use ($catalog, $org, &$args): string {
        // Explicit projections mean newly added sensitive fields are never included automatically.
        $projection = implode(',', array_map('data_lab_ident', array_keys($catalog[$table])));
        $args[] = $org;
        return '(SELECT ' . $projection . ' FROM ' . data_lab_ident($table)
            . ' WHERE `organization_id` = ?) AS ' . data_lab_ident($table);
    };
    $parts[] = 'FROM ' . $scopeTable($base);
    $active = [$base => true];
    $joins = $spec['joins'] ?? [];
    if (!is_array($joins) || !array_is_list($joins) || count($joins) > 3) {
        throw new InvalidArgumentException('At most three foreign-key joins are supported.');
    }
    foreach ($joins as $j) {
        if (!is_array($j)) throw new InvalidArgumentException('Invalid join definition.');
        $type = $j['type'] ?? null;
        $table = $j['table'] ?? null;
        $leftTable = $j['leftTable'] ?? null;
        $leftColumn = $j['leftColumn'] ?? null;
        $rightColumn = $j['rightColumn'] ?? null;
        if (!in_array($type, ['LEFT JOIN','INNER JOIN'], true) || !is_string($table) || isset($active[$table]) || !isset($catalog[$table])) {
            throw new InvalidArgumentException('Only INNER and LEFT joins to approved, unused tables are supported.');
        }
        if (!is_string($leftTable) || !is_string($leftColumn) || !is_string($rightColumn)) {
            throw new InvalidArgumentException('Choose a valid foreign-key join.');
        }
        data_lab_require_column($catalog,$active,$leftTable,$leftColumn);
        if (!isset($catalog[$table][$rightColumn])) throw new InvalidArgumentException('Join target column is unavailable.');
        $valid = false;
        foreach ($fkPairs as $fk) {
            if (($fk['table'] === $table && $fk['column'] === $rightColumn && $fk['refTable'] === $leftTable && $fk['refColumn'] === $leftColumn)
                || ($fk['refTable'] === $table && $fk['refColumn'] === $rightColumn && $fk['table'] === $leftTable && $fk['column'] === $leftColumn)) {
                $valid = true; break;
            }
        }
        if (!$valid) throw new InvalidArgumentException('This join is not a declared, approved foreign-key relationship.');
        $parts[] = $type . ' ' . $scopeTable($table) . ' ON ' . data_lab_ident($leftTable) . '.' . data_lab_ident($leftColumn)
            . ' = ' . data_lab_ident($table) . '.' . data_lab_ident($rightColumn);
        $active[$table] = true;
    }
    $selected = $spec['columns'] ?? [];
    if (!is_array($selected) || !array_is_list($selected) || count($selected) > 32) {
        throw new InvalidArgumentException('Select at most 32 columns.');
    }
    $projection = [];
    $seen = [];
    if (!$selected) {
        foreach (array_keys($catalog[$base]) as $col) $projection[] = data_lab_ident($base) . '.' . data_lab_ident($col);
    } else {
        foreach ($selected as $item) {
            if (!is_array($item) || !is_string($item['table'] ?? null) || !is_string($item['column'] ?? null)) {
                throw new InvalidArgumentException('Invalid selected column.');
            }
            $t=$item['table']; $c=$item['column'];
            data_lab_require_column($catalog,$active,$t,$c);
            if (isset($seen[$t.'|'.$c])) continue;
            $seen[$t.'|'.$c] = true;
            $expr = data_lab_ident($t).'.'.data_lab_ident($c);
            $projection[] = count($active)>1 ? $expr.' AS '.data_lab_ident($t.'.'.$c) : $expr;
        }
    }
    if (!$projection) throw new InvalidArgumentException('Select at least one approved column.');
    $sql = 'SELECT ' . implode(', ', $projection) . "\n" . implode("\n",$parts);
    $filters = $spec['filters'] ?? [];
    if (!is_array($filters) || !array_is_list($filters) || count($filters) > 8) {
        throw new InvalidArgumentException('At most eight filters are supported.');
    }
    $conditions=[];
    foreach ($filters as $f) {
        if (!is_array($f) || !is_string($f['table'] ?? null) || !is_string($f['column'] ?? null)) {
            throw new InvalidArgumentException('Invalid filter.');
        }
        $t=$f['table'];$c=$f['column'];$op=$f['operator']??null;
        data_lab_require_column($catalog,$active,$t,$c);
        if (!is_string($op) || !in_array($op,['=','!=','>','>=','<','<=','LIKE','NOT LIKE','IS NULL','IS NOT NULL'],true)) {
            throw new InvalidArgumentException('Unsupported filter operator.');
        }
        $expr=data_lab_ident($t).'.'.data_lab_ident($c).' '.$op;
        if (!in_array($op,['IS NULL','IS NOT NULL'],true)) {
            if (!is_string($f['value'] ?? null) || strlen($f['value'])>300) {
                throw new InvalidArgumentException('Filter values must be text under 300 bytes.');
            }
            $expr.=' ?'; $args[]=$f['value'];
        }
        $conditions[]=$expr;
    }
    if ($conditions) $sql.="\nWHERE ".implode(' AND ', $conditions);
    $sorts=$spec['sorts']??[];
    if (!is_array($sorts) || !array_is_list($sorts) || count($sorts)>4) {
        throw new InvalidArgumentException('At most four sort columns are supported.');
    }
    $orders=[];
    foreach ($sorts as $sort) {
        if (!is_array($sort) || !is_string($sort['table']??null) || !is_string($sort['column']??null) || !in_array($sort['direction']??null,['ASC','DESC'],true)) {
            throw new InvalidArgumentException('Invalid sort.');
        }
        data_lab_require_column($catalog,$active,$sort['table'],$sort['column']);
        $orders[]=data_lab_ident($sort['table']).'.'.data_lab_ident($sort['column']).' '.$sort['direction'];
    }
    if ($orders) $sql.="\nORDER BY ".implode(', ',$orders);
    $rawLimit=$spec['limit']??100;
    if (!is_int($rawLimit) && !(is_string($rawLimit) && preg_match('/^[1-9][0-9]{0,2}$/D',$rawLimit))) {
        throw new InvalidArgumentException('Invalid result limit.');
    }
    $limit=(int)$rawLimit;
    if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Result limit must be between 1 and 500.');
    // Fetch one extra row to distinguish an exact-limit result from truncation.
    $sql.="\nLIMIT ".($limit+1);
    return [$sql,$args,$limit];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_query') {
    $expectedCsrf = (string)($_SESSION['csrf'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, (string)($_POST['csrf'] ?? ''))) {
        data_lab_json(['ok'=>false,'error'=>'Invalid CSRF token. Refresh the page and try again.'],419);
    }
    // Never fall back to evaluating posted SQL. Old clients are rejected outright.
    $raw=$_POST['query'] ?? null;
    if (!is_string($raw) || $raw==='' || strlen($raw)>12000) {
        data_lab_json(['ok'=>false,'error'=>'Submit a structured query from the Database Lab builder. Raw SQL execution is disabled.'],400);
    }
    try {
        $spec=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($spec) || array_is_list($spec)) throw new InvalidArgumentException('Invalid query definition.');
        $catalog=data_lab_catalog($pdo);
        $fkPairs=data_lab_fk_pairs($pdo,$catalog);
        [$sql,$args,$limit]=data_lab_compile($spec,$org,$catalog,$fkPairs);
    } catch (InvalidArgumentException|JsonException $e) {
        data_lab_json(['ok'=>false,'error'=>$e->getMessage()],400);
    } catch (Throwable $e) {
        error_log('Neptune Data Lab catalog failure: '.get_class($e));
        data_lab_json(['ok'=>false,'error'=>'Scouting catalog temporarily unavailable.'],503);
    }
    try {
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
        }
        $started=microtime(true);
        $stmt=$pdo->prepare($sql);
        $stmt->execute($args);
        $columns=[];
        for ($i=0;$i<$stmt->columnCount();$i++) {
            $meta=$stmt->getColumnMeta($i);
            $columns[]=(string)($meta['name']??('column_'.($i+1)));
        }
        $rows=[];
        $truncated=false;
        while (($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false) {
            if (count($rows)>=$limit) {$truncated=true;break;}
            foreach ($row as $k=>$v) {
                if (is_resource($v)) $row[$k]='[resource]';
                elseif (is_string($v)&&strlen($v)>5000) $row[$k]=substr($v,0,5000).'…';
            }
            $rows[]=$row;
        }
        $stmt->closeCursor();
        data_lab_json(['ok'=>true,'columns'=>$columns,'rows'=>$rows,'row_count'=>count($rows),
            'truncated'=>$truncated,'elapsed_ms'=>round((microtime(true)-$started)*1000,1)]);
    } catch (Throwable $e) {
        error_log('Neptune Data Lab query failed: '.get_class($e));
        data_lab_json(['ok'=>false,'error'=>'Query could not be completed. Check selected fields and try again.'],400);
    }
}

// Strategy+ organization-scoped schema explorer.
$dataLabCatalog = data_lab_catalog($pdo);
$tableModes = array_fill_keys(array_keys($dataLabCatalog), 'organization');
$visibleTableNames = array_keys($tableModes);
$visibleLookup = array_fill_keys($visibleTableNames, true);

$schemaStmt = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS
                           FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE()
                           ORDER BY TABLE_NAME");
$tables = array_values(array_filter(
    $schemaStmt->fetchAll(),
    static fn($row) => isset($visibleLookup[(string)$row['TABLE_NAME']])
));

$columnStmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, ORDINAL_POSITION
                           FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                           ORDER BY TABLE_NAME, ORDINAL_POSITION");
$columnsByTable = [];
foreach ($columnStmt->fetchAll() as $column) {
    if (!isset($dataLabCatalog[(string)$column['TABLE_NAME']][(string)$column['COLUMN_NAME']])) continue;
    $columnsByTable[$column['TABLE_NAME']][] = $column;
}

$foreignKeysForJs = data_lab_fk_pairs($pdo, $dataLabCatalog);

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
/* Query mode and inline SQL preview. Existing WHERE blocks are unchanged. */
.builder-preview{margin-top:12px;padding:10px 12px;border:1px solid var(--line);border-radius:7px;background:var(--panel2)}
.builder-preview summary{cursor:pointer;font-size:.82rem;font-weight:750}
.builder-preview pre{font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;overflow-wrap:anywhere;max-height:250px;overflow:auto;margin:9px 0 0}
</style>

<div class="toolbar" style="justify-content:space-between;margin-top:0">
    <div>
        <div class="module-eyebrow"><span>SALT</span><small>Database Lab</small></div>
        <h1 style="margin-bottom:4px">Database Lab</h1>
        <div class="muted">Explore approved scouting data using a server-validated visual query builder.</div>
    </div>
    <div class="toolbar" style="margin:0">
        <span class="pill"><i class="fa-solid fa-shield-halved"></i> Scoped data</span>
        <span class="pill"><?=count($tables)?> tables</span>
    </div>
</div>

<div class="lab-banner" style="margin-bottom:14px">
    <i class="fa-solid fa-shield-halved"></i>
    <div><b>Strategy+ · organization scoped.</b> Every table is restricted to <b><?=e($u['organization_name'] ?? ('Organization '.$org))?></b> by the server. Authentication, contact, private notes and arbitrary JSON fields are excluded. Only approved read-only queries are supported.</div>
</div>

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
                            <span class="table-count">Approved</span>
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
                    <div class="muted">Use visual Blocks or type a supported SELECT in Code. Both are validated and organization-scoped by the server.</div>
                </div>
                <div class="lab-tabs" role="tablist" aria-label="Database Lab query mode">
                    <button type="button" class="lab-tab active" data-mode="blocks" id="labBlocksTab" role="tab" aria-controls="blocksPanel" aria-selected="true"><i class="fa-solid fa-cubes"></i> Blocks</button>
                    <button type="button" class="lab-tab" data-mode="code" id="labCodeTab" role="tab" aria-controls="sqlPanel" aria-selected="false"><i class="fa-solid fa-code"></i> Code</button>
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
                <details class="builder-preview"><summary>View SQL generated by Blocks</summary><pre id="blocksSqlPreview">Select a table to preview the query.</pre></details>
            </div>

            <div class="mode-panel" id="sqlPanel" hidden style="margin-top:14px">
                <div class="lab-banner"><i class="fa-solid fa-shield-halved"></i><div><b>Protected SQL editor:</b> Single SELECT queries on approved scouting tables. Supports columns, declared foreign-key JOINs, AND filters, ordering and LIMIT (1–500). Code is translated into an approved query specification; arbitrary SQL is never executed. No INSERT, UPDATE, DELETE, UNION, subqueries, or multi-statement scripts.</div></div>
                <div class="sql-area" style="margin-top:12px">
                <div class="builder-label">
                    <b><i class="fa-solid fa-code"></i> SQL code editor</b>
                    <div class="quick-query">
                        <button type="button" class="secondary" id="copySqlBtn"><i class="fa-regular fa-copy"></i> Copy</button>
                    </div>
                </div>
                <textarea class="sql-editor" id="sqlEditor" spellcheck="false" aria-label="SQL code editor" placeholder="SELECT match_number, state FROM matches WHERE match_number = 1 LIMIT 100;"></textarea>
                <div class="sql-help"><span>Ctrl/Cmd + Enter to run</span><span>•</span><span>Read-only SELECT</span><span>•</span><span>500-row max · 5-second server limit</span></div>
                </div>
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
                    <div class="results-meta muted" id="resultsMeta">No query run yet. Select an approved table to begin.</div>
                </div>
                <button type="button" class="secondary" id="exportCsvBtn" disabled><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            </div>
            <div id="resultsArea"><div class="lab-empty"><i class="fa-solid fa-table" style="font-size:2rem;display:block;margin-bottom:10px"></i>Build a query above and run it.</div></div>
        </div>
    </section>
</div>

<script>
(() => {
    const schema = <?=json_encode($schemaForJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE)?>;
    const foreignKeys = <?=json_encode($foreignKeysForJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE)?>;
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
    const escHtml = v => String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
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
        return Object.keys(schema).filter(t=>t===selected || (!already.includes(t) && relationshipForJoin(t,joinIndex))).map(t=>
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
        if(joins.length>=3){showStatus('Up to three approved joins are supported.',true);return false;}

        const candidates=Object.keys(schema).filter(t=>!activeTables().includes(t) && relationshipForJoin(t));
        if(!table){
            table=candidates.find(t=>relationshipForJoin(t)) || candidates[0] || '';
        }
        if(!table || !schema[table] || !relationshipForJoin(table)){showStatus('Only tables linked by an approved foreign key can be joined.',true);return false;}
        if(activeTables().includes(table)){
            showStatus(`${table} is already part of this query.`, true);
            return false;
        }

        const rel=relationshipForJoin(table);
        const leftTable=rel.leftTable;
        const leftColumn=rel.leftColumn;
        const rightColumn=rel.rightColumn;

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
                <select data-join-left="${i}" disabled title="Join columns are determined by verified foreign keys">${columnOptions(j.leftTable,j.leftColumn,leftTables)}</select>
                <div class="join-eq">=</div>
                <select data-join-right="${i}" disabled title="Join columns are determined by verified foreign keys">${joinRightColumnOptions(j.table,j.rightColumn)}</select>
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
            : currentColumns(baseTable).map(c=>qcol(baseTable,c.name)).join(',\n  ');

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

/**
 * Intentionally narrow client parser. This is only a convenience layer;
 * PHP data_lab_compile() remains the authorization and SQL-generation boundary.
 * Never send user-provided SQL text to the database.
 */
function parseApprovedSelect(text, catalog, foreignKeys) {
    if (typeof text !== 'string' || text.length > 12000) throw new Error('SQL exceeds the 12,000-character limit.');
    const tokens = [];
    for (let pos=0; pos<text.length;) {
        const rest=text.slice(pos);
        const whitespace=/^\s+/.exec(rest);
        if (whitespace){pos+=whitespace[0].length;continue;}
        const match=/^(`(?:``|[^`])*`|'(?:''|[^'])*'|(?:<=|>=|<>|!=)|(?:-?(?:\d+(?:\.\d+)?))|[A-Za-z_][A-Za-z_0-9]*|[.,*;=<>])/.exec(rest);
        if(!match) throw new Error('Unsupported SQL syntax near: '+rest.slice(0,28));
        const raw=match[0];
        let kind='symbol', value=raw;
        if(raw[0]==='`') {kind='identifier';value=raw.slice(1,-1).replaceAll('``','`');}
        else if(raw[0]==="'"){kind='literal';value=raw.slice(1,-1).replaceAll("''", "'");}
        else if(/^-?\d/.test(raw)) {kind='literal';value=raw;}
        else if(/^[A-Za-z_]/.test(raw)) {kind='word';value=raw;}
        tokens.push({kind,value});pos+=raw.length;
        if(tokens.length>750) throw new Error('SQL query is too long.');
    }
    let i=0;
    const peek=()=>tokens[i]||null;
    const kw=s=>peek()?.kind==='word' && peek().value.toUpperCase()===s;
    const eat=s=>{if(kw(s)){i++;return true;}return false;};
    const sym=s=>{if(peek()?.kind==='symbol'&&peek().value===s){i++;return true;}return false;};
    const expect=s=>{if(!eat(s)) throw new Error('Expected '+s+' in SELECT query.');};
    const identifier=()=>{const t=peek();if(!t||!['word','identifier'].includes(t.kind))throw new Error('Expected an approved table or column identifier.');i++;return t.value;};
    const ref=()=>{const first=identifier();return sym('.')?{qualifier:first,column:identifier()}:{qualifier:null,column:first};};
    const cols=[];
    expect('SELECT');
    let star=sym('*');
    if(!star){
        do {
            const c=ref();
            if(c.column==='*')throw new Error('Wildcard columns must use SELECT * alone.');
            let alias=null;
            if(eat('AS')) alias=identifier();
            cols.push({...c,alias});
            if(cols.length>32)throw new Error('Choose at most 32 columns.');
        }while(sym(','));
    }
    expect('FROM');
    const from=identifier();
    if(!Object.prototype.hasOwnProperty.call(catalog,from))throw new Error('Table '+from+' is not approved for Database Lab.');
    const aliases=new Map([[from,from]]);
    const active=[from];
    function parseAlias(table){
        const as=eat('AS');
        const token=peek();
        if(as|| (token && (token.kind==='identifier'|| (token.kind==='word'&&!['INNER','LEFT','RIGHT','FULL','CROSS','JOIN','WHERE','ORDER','LIMIT','ON','GROUP','HAVING','UNION'].includes(token.value.toUpperCase()))))){
            const alias=identifier();
            if(aliases.has(alias))throw new Error('Duplicate table alias '+alias+'.');
            aliases.set(alias,table);
        }
    }
    parseAlias(from);
    const resolveTable=s=>aliases.get(s)||null;
    function resolveCol(c, validActive=active) {
        if(c.qualifier){
            const t=resolveTable(c.qualifier);
            if(!t || !validActive.includes(t) || !(catalog[t]||[]).some(x=>x.name===c.column)) throw new Error('Unapproved column '+c.qualifier+'.'+c.column+'.');
            return {table:t,column:c.column};
        }
        const possibilities=validActive.filter(t=>(catalog[t]||[]).some(x=>x.name===c.column));
        if(possibilities.length!==1)throw new Error(possibilities.length ? 'Ambiguous column '+c.column+'. Qualify it with a table name.' : 'Unapproved column '+c.column+'.');
        return {table:possibilities[0],column:c.column};
    }
    const joins=[];
    while(kw('JOIN')||kw('INNER')||kw('LEFT')){
        let type='INNER JOIN';
        if(eat('LEFT')){eat('OUTER');expect('JOIN');type='LEFT JOIN';}
        else {eat('INNER');expect('JOIN');}
        const table=identifier();
        if(!Object.prototype.hasOwnProperty.call(catalog,table)||active.includes(table))throw new Error('JOIN table must be approved and appear only once.');
        aliases.set(table,table);
        parseAlias(table);
        expect('ON');
        const lhs=ref();
        if(!sym('='))throw new Error('JOIN requires an approved equality relationship.');
        const rhs=ref();
        const left=resolveCol(lhs,[...active,table]);const right=resolveCol(rhs,[...active,table]);
        let l,r;
        if(left.table===table && active.includes(right.table)){r=left;l=right;}
        else if(right.table===table && active.includes(left.table)){r=right;l=left;}
        else throw new Error('JOIN must link the new table with a previous table.');
        const approved=foreignKeys.some(f=>
            (f.table===table&&f.column===r.column&&f.refTable===l.table&&f.refColumn===l.column)||
            (f.refTable===table&&f.refColumn===r.column&&f.table===l.table&&f.column===l.column)
        );
        if(!approved)throw new Error('JOIN must use a verified foreign-key relationship.');
        joins.push({type,table,leftTable:l.table,leftColumn:l.column,rightColumn:r.column});
        active.push(table);
        if(joins.length>3)throw new Error('At most three JOINs are supported.');
    }
    if(star && joins.length) throw new Error('SELECT * with JOIN is not supported. Choose specific approved columns.');
    const selected=star?[]:cols.map(c=>{
        const col=resolveCol(c);
        if(c.alias && c.alias!==col.column && c.alias!==col.table+'.'+col.column)throw new Error('Renaming columns with AS is not supported.');
        return col;
    });
    const filters=[];
    if(eat('WHERE')){
        do{
            const c=resolveCol(ref());
            let op;
            if(eat('IS')){op=eat('NOT')?'IS NOT NULL':'IS NULL';expect('NULL');}
            else if(eat('NOT')){expect('LIKE');op='NOT LIKE';}
            else if(eat('LIKE'))op='LIKE';
            else {const t=peek();if(!t||t.kind!=='symbol'||!['=','!=','<>','>','>=','<','<='].includes(t.value))throw new Error('Unsupported WHERE operator.');op=t.value;i++;if(op==='<>')op='!=';}
            let value='';
            if(op!=='IS NULL' && op!=='IS NOT NULL'){
                const t=peek();if(!t||t.kind!=='literal')throw new Error('WHERE comparisons require a quoted string or numeric literal.');
                value=t.value;i++;
                if(value.length>300)throw new Error('WHERE value is longer than 300 characters.');
            }
            filters.push({...c,operator:op,value});
            if(filters.length>8)throw new Error('At most eight WHERE conditions are supported.');
        }while(eat('AND'));
    }
    const sorts=[];
    if(eat('ORDER')){
        expect('BY');
        do {
            const c=resolveCol(ref());
            const direction=eat('DESC')?'DESC':(eat('ASC'),'ASC');
            sorts.push({...c,direction});
            if(sorts.length>4)throw new Error('At most four ORDER columns are supported.');
        }while(sym(','));
    }
    let limit=100;
    if(eat('LIMIT')){
        const t=peek();if(!t||t.kind!=='literal'||!/^[1-9]\d{0,2}$/.test(t.value))throw new Error('LIMIT must be an integer from 1 to 500.');
        limit=Number(t.value);i++;
        if(limit>500)throw new Error('LIMIT cannot exceed 500.');
    }
    sym(';');
    if(i!==tokens.length)throw new Error('Only one supported SELECT statement is allowed. Unsupported SQL near '+tokens[i].value+'.');
    return {from,joins,columns:selected,filters,sorts,limit};
}

    function syncSql(){
        const sql=buildSql();
        document.getElementById('blocksSqlPreview').textContent=sql||'Select an approved table to preview the query.';
        if(builderSync) sqlEditor.value=sql;
    }

    document.getElementById('schemaList').addEventListener('click', e => {
        const browse = e.target.closest('.browse-table');
        if(browse){ e.stopPropagation(); const t=browse.dataset.table; setBaseTable(t); joins=[]; selectedColumns=[]; filters=[]; sorts=[]; renderBuilder(); syncSql(); document.getElementById('labBlocksTab').click(); runQuery(); return; }

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
            if(!rel){joins=joins.slice(0,i);showStatus('This table has no approved foreign-key join.',true);renderBuilder();syncSql();return;}
            joins[i].leftTable=rel.leftTable;
            joins[i].leftColumn=rel.leftColumn;
            joins[i].rightColumn=rel.rightColumn;
            joins[i].autoConstraint=rel.constraint;
            pruneState();renderBuilder();syncSql();
        }
        // Join column selectors are read-only: the server validates declared FK pairs.
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

    // Blocks and Code share exactly the same server-side compilation and authorization.
    let queryMode='blocks';
    document.querySelectorAll('.lab-tab[data-mode]').forEach(tab=>{
        tab.addEventListener('click',()=>{
            queryMode=tab.dataset.mode;
            document.getElementById('blocksPanel').hidden=queryMode!=='blocks';
            document.getElementById('sqlPanel').hidden=queryMode!=='code';
            document.querySelectorAll('.lab-tab[data-mode]').forEach(b=>{
                const active=b===tab;
                b.classList.toggle('active',active);
                b.setAttribute('aria-selected',String(active));
            });
            showStatus('');
        });
    });
    sqlEditor.addEventListener('input',()=>{builderSync=false;});
    sqlEditor.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();runQuery();}});

    async function runQuery(){
        let spec;
        try {
            if(queryMode==='code'){
                spec=parseApprovedSelect(sqlEditor.value,schema,foreignKeys);
            } else {
                if(!baseTable)throw new Error('Choose an approved table first.');
                spec={from:baseTable,joins,columns:selectedColumns,filters,sorts,limit:document.getElementById('limitSelect').value};
            }
        } catch(err){
            latestResult=null;exportBtn.disabled=true;resultsMeta.textContent='Query not run.';
            resultsArea.innerHTML='<div class="notice bad">'+escHtml(err.message)+'</div>';
            showStatus(err.message,true);return;
        }
        runBtn.disabled=true; runBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Running'; showStatus('Running query…');
        const fd=new FormData(); fd.append('action','run_query'); fd.append('csrf',csrf); fd.append('query',JSON.stringify(spec));
        try{
            const res=await fetch(endpoint,{method:'POST',body:fd,credentials:'same-origin'});
            const data=await res.json();
            if(!res.ok||!data.ok) throw new Error(data.error||`HTTP ${res.status}`);
            latestResult=data; renderResults(data); showStatus(`Done in ${data.elapsed_ms} ms.`);
        }catch(err){latestResult=null;exportBtn.disabled=true;resultsMeta.textContent='Query failed.';resultsArea.innerHTML=`<div class="notice bad">${escHtml(err.message)}</div>`;showStatus(err.message,true);}
        finally{runBtn.disabled=false;runBtn.innerHTML='<i class="fa-solid fa-play"></i> Run Query';}
    }
    function renderResults(data){
        exportBtn.disabled=!data.rows.length;
        resultsMeta.innerHTML=`<span class="pill">${data.row_count} row${data.row_count===1?'':'s'}</span><span class="pill">${data.elapsed_ms} ms</span>${data.truncated?'<span class="pill"><i class="fa-solid fa-scissors"></i> first '+data.row_count+' shown</span>':''}`;
        if(!data.columns.length){resultsArea.innerHTML='<div class="lab-empty">Query completed with no result columns.</div>';return;}
        let h='<div class="results-wrap"><table class="results-table"><thead><tr>'+data.columns.map(c=>`<th>${escHtml(c)}</th>`).join('')+'</tr></thead><tbody>';
        if(!data.rows.length){h+=`<tr><td colspan="${data.columns.length}" class="muted">No rows returned.</td></tr>`;}
        else data.rows.forEach(row=>{h+='<tr>'+data.columns.map(c=>{const v=row[c];return v===null?'<td class="null">NULL</td>':`<td>${escHtml(v)}</td>`;}).join('')+'</tr>';});
        h+='</tbody></table></div>'; resultsArea.innerHTML=h;
    }
    document.getElementById('copySqlBtn').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(sqlEditor.value);showStatus('SQL copied.')}catch(e){sqlEditor.select();document.execCommand('copy');showStatus('SQL copied.')}});
    runBtn.addEventListener('click',runQuery);

    exportBtn.addEventListener('click',()=>{
        if(!latestResult||!latestResult.rows.length)return;
        const csvCell=v=>{if(v===null)return '';let s=String(v);if(/^[\s]*[=+\-@]/.test(s))s="'"+s;return /[",\n\r]/.test(s)?'"'+s.replaceAll('"','""')+'"':s;};
        const lines=[latestResult.columns.map(csvCell).join(',')];
        latestResult.rows.forEach(r=>lines.push(latestResult.columns.map(c=>csvCell(r[c])).join(',')));
        const blob=new Blob([lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
        const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='neptune-query-'+new Date().toISOString().replace(/[:.]/g,'-')+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500);
    });

    renderBuilder();
    syncSql();
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>