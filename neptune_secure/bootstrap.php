<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// AWS deployments commonly terminate HTTPS at an Application Load Balancer or
// CloudFront. Only honor X-Forwarded-Proto when the deployment explicitly
// enables trusted-proxy handling.
$trustProxy = !empty($config['app']['trust_proxy']);
$forwardedProto = '';
if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $forwardedProto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
}
$isHttps = ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $forwardedProto === 'https');

session_name($config['app']['session_name'] ?? 'NEPTUNESESSID');
if (session_status() !== PHP_SESSION_ACTIVE) {
    $basePath = parse_url((string)($config['app']['base_url'] ?? '/'), PHP_URL_PATH) ?: '/';
    $cookiePath = '/' . trim($basePath, '/');
    if ($cookiePath !== '/') $cookiePath .= '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once __DIR__ . '/connection.php';

function base_url(string $path=''): string {
    global $config;
    return rtrim($config['app']['base_url'] ?? '/Neptune','/') . '/' . ltrim($path,'/');
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_response(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function uuidv4(): string {
    $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $u=current_user();
    if(!$u){ header('Location: '.base_url('index.php')); exit; }
    if(!empty($u['must_change_password'])){
        $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
        if(!in_array($script,['change-password.php','logout.php'],true)){
            header('Location: '.base_url('change-password.php'));
            exit;
        }
    }
    return $u;
}
function access_denied(string $message='Your account does not have permission to access this area.'): never {
    $u=current_user();
    $script=(string)($_SERVER['SCRIPT_NAME']??'');
    $accept=(string)($_SERVER['HTTP_ACCEPT']??'');
    $isApi=str_contains($script,'/api/') || str_contains(strtolower($accept),'application/json');

    if($isApi){
        json_response([
            'ok'=>false,
            'error'=>'forbidden',
            'message'=>$message,
        ],403);
    }

    http_response_code(403);
    $accessDeniedMessage=$message;
    $accessDeniedUser=$u;

    $basePath=parse_url(base_url(),PHP_URL_PATH) ?: '/Neptune';
    $basePath=trim((string)$basePath,'/');
    $documentRoot=rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/');
    $candidates=[];
    if($documentRoot!=='') $candidates[]=$documentRoot.'/'.$basePath.'/errors/403.php';
    $candidates[]=dirname(__DIR__).'/public_html/'.$basePath.'/errors/403.php';

    foreach($candidates as $errorPage){
        if(is_file($errorPage)){
            require $errorPage;
            exit;
        }
    }

    exit('Forbidden');
}
function require_role(array $roles): array {
    $u=require_login();
    if(!in_array($u['role'],$roles,true)){
        access_denied('Your Neptune account does not have permission to access this page.');
    }
    return $u;
}
function csrf_token(): string {
    if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')) { http_response_code(419); exit('Invalid CSRF token'); }
}
function game_timing(array|string|null $config): array {
    if(is_string($config)) $config=json_decode($config,true)?:[];
    if(!is_array($config)) $config=[];
    $t=is_array($config['timing']??null)?$config['timing']:[];
    $auton=max(1,(int)($t['autonSeconds']??15));
    $teleop=max(1,(int)($t['teleopSeconds']??135));
    $transition=max(0,(int)($t['transitionPauseSeconds']??0));
    $endgame=max(0,min($teleop,(int)($t['endgameSeconds']??15)));
    return ['auton'=>$auton,'teleop'=>$teleop,'transition'=>$transition,'endgame'=>$endgame,'duration'=>$auton+$teleop];
}
function phase_for_game_time(?int $sec, array|string|null $config=null): string {
    if($sec===null) return 'unknown';
    $t=game_timing($config);
    if($sec<$t['auton']) return 'auton';
    if($sec>=($t['duration']-$t['endgame'])) return 'endgame';
    return 'teleop';
}
function phase_for_time(?int $sec): string { return phase_for_game_time($sec,null); }
function neptune_match_label(array $m): string {
    $level=['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final','legacy'=>'Legacy'][$m['comp_level']??'']??strtoupper((string)($m['comp_level']??''));
    if(($m['comp_level']??'')==='qm' || ($m['comp_level']??'')==='legacy') return trim($level.' '.($m['match_number']??''));
    return trim($level.' '.($m['set_number']??1).'-'.($m['match_number']??''));
}
function primary_team_id(PDO $pdo, int $userId, int $organizationId): ?int {
    $s=$pdo->prepare('SELECT ut.team_id FROM user_teams ut JOIN teams t ON t.id=ut.team_id WHERE ut.user_id=? AND t.organization_id=? ORDER BY ut.is_primary DESC,ut.team_id LIMIT 1');
    $s->execute([$userId,$organizationId]);
    $id=(int)$s->fetchColumn();
    if($id>0) return $id;
    $s=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number LIMIT 1');
    $s->execute([$organizationId]);
    $id=(int)$s->fetchColumn();
    return $id>0?$id:null;
}

/* -------------------------------------------------------------------------
 * Game Configuration v2: database-backed, immutable published revisions.
 * ------------------------------------------------------------------------- */
function neptune_public_root(): string {
    return dirname(__DIR__) . '/public_html/Neptune';
}

function neptune_revision_by_id(PDO $pdo, int $revisionId): ?array {
    if ($revisionId <= 0) return null;
    $s = $pdo->prepare('SELECT * FROM game_revisions WHERE id=? LIMIT 1');
    $s->execute([$revisionId]);
    return $s->fetch() ?: null;
}

function neptune_current_game_revision(PDO $pdo, int $gameId): ?array {
    if ($gameId <= 0) return null;
    $s = $pdo->prepare(
        'SELECT gr.*
         FROM games g
         JOIN game_revisions gr ON gr.id=g.current_revision_id AND gr.game_id=g.id
         WHERE g.id=?
         LIMIT 1'
    );
    $s->execute([$gameId]);
    return $s->fetch() ?: null;
}

function neptune_draft_game_revision(PDO $pdo, int $gameId): ?array {
    if ($gameId <= 0) return null;
    $s = $pdo->prepare(
        'SELECT gr.*
         FROM games g
         JOIN game_revisions gr ON gr.id=g.draft_revision_id AND gr.game_id=g.id
         WHERE g.id=?
         LIMIT 1'
    );
    $s->execute([$gameId]);
    return $s->fetch() ?: null;
}

function neptune_revision_field_path(array $revision, int $gameId, int $organizationId=0): string {
    $publicRoot = neptune_public_root();
    $stored = ltrim(str_replace('\\', '/', trim((string)($revision['field_image_path'] ?? ''))), '/');
    if ($stored !== '' && !str_contains($stored, '../') && is_file($publicRoot . '/' . $stored)) {
        return $stored;
    }

    // Compatibility fallback for pre-v2 installations. Existing field images
    // remain untouched during migration and are picked up until the first draft
    // is created, at which point Neptune snapshots them into the revision tree.
    $dirs = [];
    if ($organizationId > 0) $dirs[] = 'uploads/fields/org-' . $organizationId . '/' . $gameId;
    $dirs[] = 'uploads/fields/' . $gameId;
    foreach ($dirs as $relDir) {
        foreach (['avif','webp','jpg','jpeg','png'] as $ext) {
            $rel = $relDir . '/background.' . $ext;
            if (is_file($publicRoot . '/' . $rel)) return $rel;
        }
    }
    return '';
}

function neptune_revision_checksum(string $matchJson, ?string $pitJson, ?string $preScoutJson, ?string $fieldImagePath=''): string {
    $fieldHash = '';
    $rel = ltrim(str_replace('\\', '/', trim((string)$fieldImagePath)), '/');
    if ($rel !== '' && !str_contains($rel, '../')) {
        $abs = neptune_public_root() . '/' . $rel;
        if (is_file($abs)) $fieldHash = hash_file('sha256', $abs) ?: '';
    }
    return hash('sha256', $matchJson . "\x1e" . (string)$pitJson . "\x1e" . (string)$preScoutJson . "\x1e" . $fieldHash);
}

function neptune_copy_revision_field_image(string $sourceRel, int $gameId, int $revisionId): string {
    $sourceRel = ltrim(str_replace('\\', '/', trim($sourceRel)), '/');
    if ($sourceRel === '' || str_contains($sourceRel, '../')) return '';
    $publicRoot = neptune_public_root();
    $source = $publicRoot . '/' . $sourceRel;
    if (!is_file($source)) return '';

    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    if (!in_array($ext, ['avif','webp','jpg','jpeg','png'], true)) return '';

    $relDir = 'uploads/fields/game-' . $gameId . '/draft-' . $revisionId;
    $destDir = $publicRoot . '/' . $relDir;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create the revision field-image directory.');
    }
    $dest = $destDir . '/background.' . $ext;
    if (!copy($source, $dest)) throw new RuntimeException('Could not snapshot the field background into the draft revision.');
    @chmod($dest, 0664);
    return $relDir . '/background.' . $ext;
}

function neptune_ensure_game_draft_revision(PDO $pdo, int $gameId, int $organizationId, int $userId): array {
    if ($gameId <= 0 || $organizationId <= 0) throw new RuntimeException('A valid organization-owned game is required.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $s = $pdo->prepare('SELECT * FROM games WHERE id=? AND organization_id=? FOR UPDATE');
        $s->execute([$gameId, $organizationId]);
        $game = $s->fetch();
        if (!$game) throw new RuntimeException('Only the organization that owns this game can edit its configuration.');

        $draftId = (int)($game['draft_revision_id'] ?? 0);
        if ($draftId > 0) {
            $draft = neptune_revision_by_id($pdo, $draftId);
            if ($draft && (int)$draft['game_id'] === $gameId && $draft['status'] === 'draft') {
                if ($ownsTransaction) $pdo->commit();
                return $draft;
            }
            $pdo->prepare('UPDATE games SET draft_revision_id=NULL WHERE id=? AND organization_id=?')->execute([$gameId, $organizationId]);
        }

        $current = null;
        $currentId = (int)($game['current_revision_id'] ?? 0);
        if ($currentId > 0) $current = neptune_revision_by_id($pdo, $currentId);

        $matchJson = $current ? (string)$current['match_config_json'] : (string)($game['config_json'] ?? '{}');
        $pitJson = $current ? $current['pit_config_json'] : ($game['pit_config_json'] ?? null);
        $preJson = $current ? $current['pre_scout_config_json'] : ($game['pre_scout_config_json'] ?? null);
        if (trim($matchJson) === '') $matchJson = '{}';

        $s = $pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM game_revisions WHERE game_id=?');
        $s->execute([$gameId]);
        $revisionNumber = max(1, (int)$s->fetchColumn());
        $checksum = neptune_revision_checksum($matchJson, $pitJson, $preJson, null);

        $s = $pdo->prepare(
            "INSERT INTO game_revisions
             (organization_id,game_id,revision_number,status,match_config_json,pit_config_json,pre_scout_config_json,field_image_path,checksum,created_by)
             VALUES(?,?,?,'draft',?,?,?,?,?,?)"
        );
        $s->execute([$organizationId,$gameId,$revisionNumber,$matchJson,$pitJson,$preJson,null,$checksum,$userId ?: null]);
        $revisionId = (int)$pdo->lastInsertId();

        $sourceField = $current
            ? neptune_revision_field_path($current, $gameId, $organizationId)
            : neptune_revision_field_path([], $gameId, $organizationId);
        $draftField = $sourceField !== '' ? neptune_copy_revision_field_image($sourceField, $gameId, $revisionId) : '';
        if ($draftField !== '') {
            $checksum = neptune_revision_checksum($matchJson, $pitJson, $preJson, $draftField);
            $pdo->prepare('UPDATE game_revisions SET field_image_path=?,checksum=? WHERE id=?')->execute([$draftField,$checksum,$revisionId]);
        }

        $pdo->prepare('UPDATE games SET draft_revision_id=? WHERE id=? AND organization_id=?')->execute([$revisionId,$gameId,$organizationId]);
        $draft = neptune_revision_by_id($pdo, $revisionId);
        if (!$draft) throw new RuntimeException('Could not load the newly-created draft revision.');

        if ($ownsTransaction) $pdo->commit();
        return $draft;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function neptune_publish_game_draft(PDO $pdo, int $gameId, int $organizationId, int $userId): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $s = $pdo->prepare('SELECT * FROM games WHERE id=? AND organization_id=? FOR UPDATE');
        $s->execute([$gameId,$organizationId]);
        $game = $s->fetch();
        if (!$game) throw new RuntimeException('Game not found for this organization.');

        $draftId = (int)($game['draft_revision_id'] ?? 0);
        if ($draftId <= 0) throw new RuntimeException('There is no draft revision to publish.');

        $s = $pdo->prepare("SELECT * FROM game_revisions WHERE id=? AND game_id=? AND organization_id=? AND status='draft' FOR UPDATE");
        $s->execute([$draftId,$gameId,$organizationId]);
        $draft = $s->fetch();
        if (!$draft) throw new RuntimeException('The draft revision could not be found.');

        $cfg = json_decode((string)$draft['match_config_json'], true);
        if (!is_array($cfg) || empty($cfg['buttons']) || !is_array($cfg['buttons'])) {
            throw new RuntimeException('The draft must contain at least one scouting action before it can be published.');
        }

        $fieldRel = neptune_revision_field_path($draft, $gameId, $organizationId);
        if ($fieldRel !== '') {
            $publicRoot = neptune_public_root();
            $source = $publicRoot . '/' . $fieldRel;
            $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
            $relDir = 'uploads/fields/game-' . $gameId . '/revision-' . (int)$draft['revision_number'];
            $destDir = $publicRoot . '/' . $relDir;
            if (!is_dir($destDir) && !mkdir($destDir,0775,true) && !is_dir($destDir)) {
                throw new RuntimeException('Could not create the published revision field-image directory.');
            }
            $dest = $destDir . '/background.' . $ext;
            if (realpath($source) !== realpath($dest)) {
                if (!copy($source,$dest)) throw new RuntimeException('Could not publish the field background snapshot.');
                @chmod($dest,0664);
            }
            $fieldRel = $relDir . '/background.' . $ext;
        }

        $checksum = neptune_revision_checksum(
            (string)$draft['match_config_json'],
            $draft['pit_config_json'],
            $draft['pre_scout_config_json'],
            $fieldRel
        );

        $pdo->prepare(
            "UPDATE game_revisions
             SET status='published',field_image_path=?,checksum=?,published_by=?,published_at=UTC_TIMESTAMP(),updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND status='draft'"
        )->execute([$fieldRel !== '' ? $fieldRel : null,$checksum,$userId ?: null,$draftId]);

        // Keep the legacy JSON columns synchronized with the latest published
        // revision. This provides a safe rollback path while all runtime pages
        // transition to game_revisions.
        $pdo->prepare(
            'UPDATE games
             SET current_revision_id=?,draft_revision_id=NULL,
                 config_json=?,pit_config_json=?,pre_scout_config_json=?,updated_at=CURRENT_TIMESTAMP
             WHERE id=? AND organization_id=?'
        )->execute([
            $draftId,
            (string)$draft['match_config_json'],
            $draft['pit_config_json'],
            $draft['pre_scout_config_json'],
            $gameId,$organizationId
        ]);

        $published = neptune_revision_by_id($pdo,$draftId);
        if (!$published) throw new RuntimeException('Could not load the published revision.');
        if ($ownsTransaction) $pdo->commit();
        return $published;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function neptune_event_revision(PDO $pdo, int $eventId, int $organizationId): ?array {
    $s = $pdo->prepare(
        'SELECT gr.*,e.game_id,e.organization_id event_organization_id,g.name game_name,g.season_year
         FROM events e
         JOIN games g ON g.id=e.game_id
         JOIN game_revisions gr ON gr.id=e.game_revision_id AND gr.game_id=e.game_id
         WHERE e.id=? AND e.organization_id=?
         LIMIT 1'
    );
    $s->execute([$eventId,$organizationId]);
    return $s->fetch() ?: null;
}
