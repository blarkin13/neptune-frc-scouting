<?php
declare(strict_types=1);

/**
 * Robot Recall event competition helpers.
 *
 * Keeps test/production classification in a small sidecar table so the existing
 * Robot Recall schema does not need to be altered. All rooms that existed before the v4.2 migration are marked test once per organization.
 * New rooms are explicitly classified when they are created. Event standings are calculated from the live Robot Recall player
 * table, so historical completed rooms can appear without a backfill job.
 */

if (!function_exists('rrc_ensure_schema')) {
    function rrc_ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_session_flags (
            session_id BIGINT UNSIGNED NOT NULL,
            organization_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            play_mode VARCHAR(16) NOT NULL DEFAULT 'production',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id),
            KEY idx_rr_flags_org_test (organization_id,is_test),
            KEY idx_rr_flags_org_event (organization_id,event_id,is_test),
            KEY idx_rr_flags_org_mode (organization_id,play_mode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $has=$pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='robot_recall_session_flags' AND COLUMN_NAME='play_mode' LIMIT 1");
        $has->execute([$db]);
        if (!$has->fetchColumn()) {
            $pdo->exec("ALTER TABLE robot_recall_session_flags ADD COLUMN play_mode VARCHAR(16) NOT NULL DEFAULT 'production' AFTER is_test");
        }
        $pdo->exec("UPDATE robot_recall_session_flags SET play_mode=CASE WHEN is_test=1 THEN 'test' ELSE 'production' END WHERE play_mode IS NULL OR play_mode='' OR play_mode NOT IN ('production','test','practice')");
        $pdo->exec("UPDATE robot_recall_session_flags SET play_mode='test' WHERE is_test=1 AND play_mode='production'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_public_hosts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            organization_id BIGINT UNSIGNED NOT NULL,
            service_user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            creator_fingerprint CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_rr_public_host_session (session_id),
            UNIQUE KEY uq_rr_public_host_token (token_hash),
            KEY idx_rr_public_host_expiry (expires_at),
            KEY idx_rr_public_host_fingerprint (creator_fingerprint,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('rrc_db_name')) {
    function rrc_db_name(PDO $pdo): string
    {
        return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    }
}

if (!function_exists('rrc_robot_tables')) {
    function rrc_robot_tables(PDO $pdo): array
    {
        static $cache = [];
        $db = rrc_db_name($pdo);
        $key = spl_object_id($pdo) . ':' . $db;
        if (isset($cache[$key])) return $cache[$key];
        $stmt = $pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE 'robot_recall_%'
            ORDER BY TABLE_NAME,ORDINAL_POSITION");
        $stmt->execute([$db]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string)$row['TABLE_NAME'];
            $col = (string)$row['COLUMN_NAME'];
            if (!str_starts_with($table,'robot_recall_') || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) continue;
            $out[$table][$col] = true;
        }
        return $cache[$key] = $out;
    }
}

if (!function_exists('rrc_player_table')) {
    function rrc_player_table(PDO $pdo): ?array
    {
        $tables = rrc_robot_tables($pdo);
        $nameCandidates = ['nickname','player_name','display_name','name'];
        $scoreCandidates = ['score','points','total_score'];

        // Prefer the canonical player table. The original generic discovery could
        // select another Robot Recall table first if a future table happened to
        // contain similarly named columns.
        $ordered = [];
        if (isset($tables['robot_recall_players'])) $ordered['robot_recall_players'] = $tables['robot_recall_players'];
        foreach ($tables as $table => $cols) {
            if ($table === 'robot_recall_players') continue;
            $ordered[$table] = $cols;
        }

        foreach ($ordered as $table => $cols) {
            if ($table === 'robot_recall_sessions' || !isset($cols['session_id'])) continue;
            $nameCol = null;
            foreach ($nameCandidates as $c) {
                if (isset($cols[$c])) { $nameCol = $c; break; }
            }
            $scoreCol = null;
            foreach ($scoreCandidates as $c) {
                if (isset($cols[$c])) { $scoreCol = $c; break; }
            }
            if ($nameCol !== null && $scoreCol !== null) {
                return [
                    'table'=>$table,
                    'name'=>$nameCol,
                    'score'=>$scoreCol,
                    'has_id'=>isset($cols['id']),
                ];
            }
        }
        return null;
    }
}

if (!function_exists('rrc_mark_session_mode')) {
    function rrc_mark_session_mode(PDO $pdo, int $sessionId, int $orgId, ?int $eventId, string $playMode): void
    {
        rrc_ensure_schema($pdo);
        $playMode=strtolower(trim($playMode));
        if (!in_array($playMode,['production','test','practice'],true)) $playMode='production';
        $isTest=$playMode==='test' ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO robot_recall_session_flags
            (session_id,organization_id,event_id,is_test,play_mode)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              organization_id=VALUES(organization_id),
              event_id=VALUES(event_id),
              is_test=VALUES(is_test),
              play_mode=VALUES(play_mode),
              updated_at=CURRENT_TIMESTAMP");
        $stmt->execute([$sessionId,$orgId,$eventId && $eventId > 0 ? $eventId : null,$isTest,$playMode]);
    }
}

if (!function_exists('rrc_mark_session')) {
    function rrc_mark_session(PDO $pdo, int $sessionId, int $orgId, ?int $eventId, bool $isTest): void
    {
        rrc_mark_session_mode($pdo,$sessionId,$orgId,$eventId,$isTest?'test':'production');
    }
}

if (!function_exists('rrc_session_flag')) {
    function rrc_session_flag(PDO $pdo, int $sessionId, int $orgId): array
    {
        rrc_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT session_id,organization_id,event_id,is_test,play_mode FROM robot_recall_session_flags WHERE session_id=? AND organization_id=? LIMIT 1");
        $stmt->execute([$sessionId,$orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['session_id'=>$sessionId,'organization_id'=>$orgId,'event_id'=>null,'is_test'=>0,'play_mode'=>'production'];
        $mode=(string)($row['play_mode']??'');
        if (!in_array($mode,['production','test','practice'],true)) $mode=((int)($row['is_test']??0)===1?'test':'production');
        $row['play_mode']=$mode;
        return $row;
    }
}

if (!function_exists('rrc_flags_for_sessions')) {
    function rrc_flags_for_sessions(PDO $pdo, array $sessionIds, int $orgId): array
    {
        rrc_ensure_schema($pdo);
        $ids = array_values(array_unique(array_filter(array_map('intval',$sessionIds), static fn($v)=>$v>0)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $stmt = $pdo->prepare("SELECT session_id,is_test FROM robot_recall_session_flags WHERE organization_id=? AND session_id IN ($ph)");
        $stmt->execute(array_merge([$orgId],$ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['session_id']] = (int)$row['is_test'] === 1;
        return $out;
    }
}


if (!function_exists('rrc_modes_for_sessions')) {
    function rrc_modes_for_sessions(PDO $pdo, array $sessionIds, int $orgId): array
    {
        rrc_ensure_schema($pdo);
        $ids=array_values(array_unique(array_filter(array_map('intval',$sessionIds),static fn($v)=>$v>0)));
        if(!$ids)return [];
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT session_id,is_test,play_mode FROM robot_recall_session_flags WHERE organization_id=? AND session_id IN ($ph)");
        $stmt->execute(array_merge([$orgId],$ids));
        $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $mode=(string)($row['play_mode']??'');
            if(!in_array($mode,['production','test','practice'],true))$mode=((int)($row['is_test']??0)===1?'test':'production');
            $out[(int)$row['session_id']]=$mode;
        }
        return $out;
    }
}


if (!function_exists('rrc_mark_existing_sessions_test_once')) {
    function rrc_mark_existing_sessions_test_once(PDO $pdo, int $orgId): int
    {
        if ($orgId <= 0) return 0;
        rrc_ensure_schema($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS robot_recall_competition_migrations (
            organization_id BIGINT UNSIGNED NOT NULL,
            migration_key VARCHAR(80) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (organization_id,migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $migrationKey='v42_mark_existing_sessions_test';
        $check=$pdo->prepare("SELECT 1 FROM robot_recall_competition_migrations WHERE organization_id=? AND migration_key=? LIMIT 1");
        $check->execute([$orgId,$migrationKey]);
        if ($check->fetchColumn()) return 0;

        $tables=rrc_robot_tables($pdo);
        if (!isset($tables['robot_recall_sessions'])) return 0;
        $cols=$tables['robot_recall_sessions'];
        if (!isset($cols['id'],$cols['organization_id'])) return 0;

        $pdo->beginTransaction();
        try {
            $countStmt=$pdo->prepare("SELECT COUNT(*) FROM robot_recall_sessions WHERE organization_id=?");
            $countStmt->execute([$orgId]);
            $count=(int)$countStmt->fetchColumn();

            $eventExpr=isset($cols['event_id'])?'s.event_id':'NULL';
            $sql="INSERT INTO robot_recall_session_flags
                (session_id,organization_id,event_id,is_test,play_mode)
                SELECT s.id,s.organization_id,$eventExpr,1,'test'
                FROM robot_recall_sessions s
                WHERE s.organization_id=?
                ON DUPLICATE KEY UPDATE
                  organization_id=VALUES(organization_id),
                  event_id=VALUES(event_id),
                  is_test=1,
                  play_mode='test',
                  updated_at=CURRENT_TIMESTAMP";
            $pdo->prepare($sql)->execute([$orgId]);

            $mark=$pdo->prepare("INSERT INTO robot_recall_competition_migrations (organization_id,migration_key) VALUES (?,?)");
            $mark->execute([$orgId,$migrationKey]);
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('rrc_test_session_count')) {
    function rrc_test_session_count(PDO $pdo, int $orgId): int
    {
        rrc_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM robot_recall_session_flags WHERE organization_id=? AND is_test=1");
        $stmt->execute([$orgId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('rrc_event_top10_details')) {
    function rrc_event_top10_details(PDO $pdo, int $orgId, int $eventId): array
    {
        $out = [
            'rows'=>[],
            'meta'=>[
                'event_rooms'=>0,
                'test_rooms'=>0,
                'practice_rooms'=>0,
                'production_rooms'=>0,
                'ranked_rooms'=>0,
                'completed_production_games'=>0,
                'completed_ranked_games'=>0,
                'scored_player_rows'=>0,
                'unique_players'=>0,
                'player_source'=>'',
            ],
            'message'=>'',
        ];
        if ($orgId <= 0 || $eventId <= 0) {
            $out['message']='Event standings require an event-linked room.';
            return $out;
        }

        rrc_ensure_schema($pdo);
        $tables = rrc_robot_tables($pdo);
        if (!isset($tables['robot_recall_sessions'])) {
            $out['message']='Robot Recall sessions table was not found.';
            return $out;
        }
        $sessionCols = $tables['robot_recall_sessions'];
        if (!isset($sessionCols['id'],$sessionCols['organization_id'])) {
            $out['message']='Robot Recall session columns are incomplete.';
            return $out;
        }

        $player = rrc_player_table($pdo);
        if (!$player) {
            $out['message']='Robot Recall player scores could not be located.';
            return $out;
        }
        $out['meta']['player_source']=(string)$player['table'];

        // Some early rooms were linked to an event only in the sidecar flag row.
        // Prefer the session event id, but fall back to the flag's event id.
        $eventExpr = isset($sessionCols['event_id'])
            ? 'COALESCE(NULLIF(s.event_id,0),f.event_id)'
            : 'f.event_id';
        $finishedExpr = isset($sessionCols['status'])
            ? "LOWER(TRIM(COALESCE(s.status,''))) IN ('finished','complete','completed','ended')"
            : '1=1';

        $countSql = "SELECT
                COUNT(*) AS event_rooms,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)='test' THEN 1 ELSE 0 END) AS test_rooms,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)='practice' THEN 1 ELSE 0 END) AS practice_rooms,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)='production' THEN 1 ELSE 0 END) AS production_rooms,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)<>'practice' THEN 1 ELSE 0 END) AS ranked_rooms,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)='production' AND $finishedExpr THEN 1 ELSE 0 END) AS completed_production_games,
                SUM(CASE WHEN COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)<>'practice' AND $finishedExpr THEN 1 ELSE 0 END) AS completed_ranked_games
            FROM robot_recall_sessions s
            LEFT JOIN robot_recall_session_flags f
              ON f.session_id=s.id AND f.organization_id=s.organization_id
            WHERE s.organization_id=? AND $eventExpr=?";
        $countStmt=$pdo->prepare($countSql);
        $countStmt->execute([$orgId,$eventId]);
        $counts=$countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['event_rooms','test_rooms','practice_rooms','production_rooms','ranked_rooms','completed_production_games','completed_ranked_games'] as $key) {
            $out['meta'][$key]=(int)($counts[$key]??0);
        }

        $pt = '`'.$player['table'].'`';
        $pn = '`'.$player['name'].'`';
        $ps = '`'.$player['score'].'`';

        $scoreCountSql="SELECT COUNT(*) AS scored_player_rows, COUNT(DISTINCT p.$pn) AS unique_players
            FROM $pt p
            JOIN robot_recall_sessions s ON s.id=p.session_id
            LEFT JOIN robot_recall_session_flags f
              ON f.session_id=s.id AND f.organization_id=s.organization_id
            WHERE s.organization_id=? AND $eventExpr=?
              AND COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)<>'practice'
              AND $finishedExpr
              AND TRIM(COALESCE(p.$pn,''))<>''";
        $scoreCount=$pdo->prepare($scoreCountSql);
        $scoreCount->execute([$orgId,$eventId]);
        $scoreCounts=$scoreCount->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['meta']['scored_player_rows']=(int)($scoreCounts['scored_player_rows']??0);
        $out['meta']['unique_players']=(int)($scoreCounts['unique_players']??0);

        $sql = "SELECT p.$pn AS nickname,
                       MAX(COALESCE(p.$ps,0)) AS best_score,
                       COUNT(DISTINCT p.session_id) AS games
            FROM $pt p
            JOIN robot_recall_sessions s ON s.id=p.session_id
            LEFT JOIN robot_recall_session_flags f
              ON f.session_id=s.id AND f.organization_id=s.organization_id
            WHERE s.organization_id=? AND $eventExpr=?
              AND COALESCE(NULLIF(f.play_mode,''),CASE WHEN COALESCE(f.is_test,0)=1 THEN 'test' ELSE 'production' END)<>'practice'
              AND $finishedExpr
              AND TRIM(COALESCE(p.$pn,''))<>''
            GROUP BY p.$pn
            ORDER BY best_score DESC, games ASC, nickname ASC
            LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$orgId,$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rank = 0;
        foreach ($rows as &$row) {
            $row['rank'] = ++$rank;
            $row['best_score'] = (int)round((float)$row['best_score']);
            $row['games'] = (int)$row['games'];
        }
        unset($row);
        $out['rows']=$rows;

        if ($rows) {
            $out['message']='Best completed score per player. Production and test games are ranked; practice games are excluded.';
        } elseif ($out['meta']['completed_ranked_games'] <= 0) {
            if ($out['meta']['practice_rooms'] > 0 && $out['meta']['ranked_rooms'] <= 0) {
                $out['message']='Only practice games exist for this event so far. Practice scores are excluded from Event Standings.';
            } else {
                $out['message']='No completed production or test games for this event yet.';
            }
        } elseif ($out['meta']['scored_player_rows'] <= 0) {
            $out['message']='Completed ranked games exist, but no scored player rows were found.';
        } else {
            $out['message']='No eligible event standings are available yet.';
        }
        return $out;
    }
}

if (!function_exists('rrc_event_top10')) {
    function rrc_event_top10(PDO $pdo, int $orgId, int $eventId): array
    {
        return rrc_event_top10_details($pdo,$orgId,$eventId)['rows'];
    }
}

if (!function_exists('rrc_public_host_fingerprint')) {
    function rrc_public_host_fingerprint(): string
    {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,180);
        return hash('sha256',$ip.'|'.$ua);
    }
}

if (!function_exists('rrc_public_service_user_id')) {
    function rrc_public_service_user_id(PDO $pdo, int $orgId): int
    {
        if ($orgId<=0) return 0;
        $db=rrc_db_name($pdo);
        $stmt=$pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users'");
        $stmt->execute([$db]);
        $cols=array_fill_keys(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)),true);
        if (!isset($cols['id'],$cols['organization_id'],$cols['role'])) return 0;
        $where="organization_id=? AND role IN ('owner','admin','strategy')";
        if (isset($cols['active'])) $where.=' AND active=1';
        elseif (isset($cols['is_active'])) $where.=' AND is_active=1';
        $sql="SELECT id FROM users WHERE $where ORDER BY CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END,id LIMIT 1";
        $q=$pdo->prepare($sql);$q->execute([$orgId]);
        return (int)($q->fetchColumn()?:0);
    }
}

if (!function_exists('rrc_public_host_rate_ok')) {
    function rrc_public_host_rate_ok(PDO $pdo, string $fingerprint): bool
    {
        rrc_ensure_schema($pdo);
        $q=$pdo->prepare("SELECT COUNT(*) FROM robot_recall_public_hosts WHERE creator_fingerprint=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
        $q->execute([$fingerprint]);
        if ((int)$q->fetchColumn()>=6) return false;
        $q=$pdo->prepare("SELECT COUNT(*) FROM robot_recall_public_hosts WHERE creator_fingerprint=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)");
        $q->execute([$fingerprint]);
        return (int)$q->fetchColumn()<20;
    }
}

if (!function_exists('rrc_create_public_host')) {
    function rrc_create_public_host(PDO $pdo, int $sessionId, int $orgId, int $serviceUserId, string $fingerprint): string
    {
        rrc_ensure_schema($pdo);
        $token=bin2hex(random_bytes(24));
        $hash=hash('sha256',$token);
        $q=$pdo->prepare("INSERT INTO robot_recall_public_hosts (session_id,organization_id,service_user_id,token_hash,creator_fingerprint,expires_at) VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))");
        $q->execute([$sessionId,$orgId,$serviceUserId,$hash,$fingerprint]);
        return $token;
    }
}

if (!function_exists('rrc_public_host_by_token')) {
    function rrc_public_host_by_token(PDO $pdo, string $token): ?array
    {
        rrc_ensure_schema($pdo);
        $token=trim($token);
        if (!preg_match('/^[a-f0-9]{48}$/i',$token)) return null;
        $q=$pdo->prepare("SELECT * FROM robot_recall_public_hosts WHERE token_hash=? AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1");
        $q->execute([hash('sha256',$token)]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }
}

if (!function_exists('rrc_collect_ids')) {
    function rrc_collect_ids(PDO $pdo, string $table, string $whereColumn, array $ids): array
    {
        if (!$ids || !preg_match('/^[A-Za-z0-9_]+$/',$table) || !preg_match('/^[A-Za-z0-9_]+$/',$whereColumn)) return [];
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE `$whereColumn` IN ($ph)");
        $stmt->execute($ids);
        return array_values(array_unique(array_filter(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)), static fn($v)=>$v>0)));
    }
}

if (!function_exists('rrc_delete_test_data')) {
    function rrc_delete_test_data(PDO $pdo, int $orgId): int
    {
        rrc_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT session_id FROM robot_recall_session_flags WHERE organization_id=? AND is_test=1 ORDER BY session_id");
        $stmt->execute([$orgId]);
        $sessionIds = array_values(array_unique(array_filter(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)), static fn($v)=>$v>0)));
        if (!$sessionIds) return 0;

        $tables = rrc_robot_tables($pdo);
        $sessionPh = implode(',', array_fill(0,count($sessionIds),'?'));
        $playerIds = [];
        $questionIds = [];

        // Capture child IDs before deleting direct session rows, so answer/option tables
        // that only reference player_id or question_id can be cleaned too.
        foreach ($tables as $table => $cols) {
            if (!isset($cols['id'],$cols['session_id'])) continue;
            if (str_contains($table,'player')) $playerIds = array_merge($playerIds, rrc_collect_ids($pdo,$table,'session_id',$sessionIds));
            if (str_contains($table,'question')) $questionIds = array_merge($questionIds, rrc_collect_ids($pdo,$table,'session_id',$sessionIds));
        }
        $playerIds = array_values(array_unique($playerIds));
        $questionIds = array_values(array_unique($questionIds));

        $pdo->beginTransaction();
        try {
            // Series links may point to either side of a test room chain.
            if (isset($tables['robot_recall_series_links'])) {
                $cols = $tables['robot_recall_series_links'];
                $parts = [];$args = [];
                if (isset($cols['previous_session_id'])) { $parts[] = 'previous_session_id IN ('.$sessionPh.')'; $args = array_merge($args,$sessionIds); }
                if (isset($cols['next_session_id'])) { $parts[] = 'next_session_id IN ('.$sessionPh.')'; $args = array_merge($args,$sessionIds); }
                if ($parts) {
                    $orgClause = isset($cols['organization_id']) ? 'organization_id=? AND ' : '';
                    if ($orgClause) array_unshift($args,$orgId);
                    $pdo->prepare('DELETE FROM robot_recall_series_links WHERE '.$orgClause.'('.implode(' OR ',$parts).')')->execute($args);
                }
            }

            foreach ($tables as $table => $cols) {
                if (in_array($table,['robot_recall_sessions','robot_recall_session_flags','robot_recall_series_links'],true)) continue;
                if (!preg_match('/^[A-Za-z0-9_]+$/',$table)) continue;
                $ors = [];$args = [];
                if (isset($cols['session_id'])) { $ors[] = 'session_id IN ('.$sessionPh.')'; $args = array_merge($args,$sessionIds); }
                if ($playerIds && isset($cols['player_id'])) {
                    $ph = implode(',', array_fill(0,count($playerIds),'?'));
                    $ors[] = 'player_id IN ('.$ph.')';$args = array_merge($args,$playerIds);
                }
                if ($questionIds && isset($cols['question_id'])) {
                    $ph = implode(',', array_fill(0,count($questionIds),'?'));
                    $ors[] = 'question_id IN ('.$ph.')';$args = array_merge($args,$questionIds);
                }
                if (!$ors) continue;
                $pdo->prepare("DELETE FROM `$table` WHERE ".implode(' OR ',$ors))->execute($args);
            }

            if (isset($tables['robot_recall_sessions'])) {
                $cols = $tables['robot_recall_sessions'];
                $orgClause = isset($cols['organization_id']) ? 'organization_id=? AND ' : '';
                $args = $orgClause ? array_merge([$orgId],$sessionIds) : $sessionIds;
                $pdo->prepare('DELETE FROM robot_recall_sessions WHERE '.$orgClause.'id IN ('.$sessionPh.')')->execute($args);
            }
            $pdo->prepare('DELETE FROM robot_recall_session_flags WHERE organization_id=? AND is_test=1')->execute([$orgId]);
            $pdo->commit();
            return count($sessionIds);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
