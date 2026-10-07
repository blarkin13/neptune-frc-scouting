<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
require_once dirname(__DIR__, 3) . '/neptune_secure/google-auth.php';
require_once dirname(__DIR__, 3) . '/neptune_secure/public-auth-security.php';
require_once dirname(__DIR__, 3) . '/neptune_secure/platform-security.php';
$u = require_role(['owner']);

$platformOrgId = 1;
if (isset($config) && is_array($config)) {
    $platformOrgId = (int)($config['app']['platform_organization_id'] ?? 1);
}
if ((int)($u['organization_id'] ?? 0) !== $platformOrgId) {
    http_response_code(403);
    exit('Platform owner access required.');
}
neptune_require_platform_owner_google($u);

// Maintenance Console deliberately initializes the small global migration
// registry and the core self-migrations so update status is visible in one place.
neptune_migrations_ensure_table($pdo);
neptune_auth_ensure_schema($pdo);
neptune_public_auth_ensure_schema($pdo);

$pageTitle = 'Maintenance Console';
$moduleName = 'SATURN';

const NM_ROOT = '/var/www/neptune';
const NM_APP_ROOT = '/var/www/neptune/public_html/Neptune';
const NM_UPLOAD_ROOT = '/var/www/neptune/maintenance-uploads';
const NM_BACKUP_ROOT = '/var/www/neptune/maintenance-backups';
const NM_MAX_PATCH_BYTES = 50 * 1024 * 1024;
const NM_PROD_BACKUP_STATUS = '/var/lib/neptune-backup/status.json';

function nm_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nm_flash(string $type, string $message, string $detail = ''): void {
    $_SESSION['nm_flash'] = ['type' => $type, 'message' => $message, 'detail' => $detail];
}

function nm_take_flash(): ?array {
    $flash = $_SESSION['nm_flash'] ?? null;
    unset($_SESSION['nm_flash']);
    return is_array($flash) ? $flash : null;
}

function nm_redirect(array $params = [], string $fragment = ''): never {
    $url = base_url('admin/maintenance.php');
    if ($params) $url .= '?' . http_build_query($params);
    if ($fragment !== '') $url .= '#' . rawurlencode($fragment);
    header('Location: ' . $url);
    exit;
}

function nm_format_bytes(int|float $bytes): string {
    $bytes = max(0, (float)$bytes);
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : ($bytes >= 10 ? 1 : 2)) . ' ' . $units[$i];
}

function nm_production_backup_status(): array {
    $defaults = [
        'status' => 'not-configured',
        'last_attempt_at' => '',
        'last_success_at' => '',
        'last_success_tag' => '',
        'last_success_size_bytes' => 0,
        'last_error' => '',
        'repository' => '',
        'schedule_utc' => '08:15',
        'next_scheduled_at' => '',
    ];
    if (!is_readable(NM_PROD_BACKUP_STATUS)) return $defaults;
    $raw = @file_get_contents(NM_PROD_BACKUP_STATUS);
    if ($raw === false) return $defaults;
    $data = json_decode($raw, true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function nm_backup_when(string $iso): string {
    if ($iso === '') return 'Not yet';
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone('America/Chicago'));
        return $dt->format('M j, Y g:i A T');
    } catch (Throwable) {
        return $iso;
    }
}

function nm_tail(string $path, int $lines = 80): array {
    if (!is_readable($path) || !is_file($path)) return [];
    $data = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($data)) return [];
    return array_slice($data, -$lines);
}


function nm_cleanup_candidates(): array {
    return [
        'admin/install-maintenance-card.php',
        'admin/install-maintenance-console.php',
        'admin/install-maintenance-console-fixed.php',
        'admin/install-maintenance-console-v3.php',
        'admin/install-maintenance-console-v4.php',
        'admin/maintenance-v3.php',
        'admin/maintenance-v4.php',
        'admin/install-connection-guard.php',
        'admin/index.php.bak-theme',
        'admin/install.php.disabled',
        'admin/Neptune_Maintenance_Console_v1.zip',
        'admin/Neptune_Maintenance_Console_v2.zip',
        'admin/Neptune_Maintenance_Console_v3.zip',
        'admin/Neptune_Maintenance_Console_v4.zip',
        'admin/Neptune_Temporary_Connection_Guard_Browser_v2.zip',
        'admin/Neptune_Temporary_Connection_Guard_Browser_v3.zip',
    ];
}

function nm_cleanup_installers(bool $delete): string {
    $lines = [];
    $found = 0;
    $removed = 0;

    foreach (nm_cleanup_candidates() as $rel) {
        $path = NM_APP_ROOT . '/' . $rel;

        // The cleanup list is intentionally exact and confined to admin/.
        if (!str_starts_with($path, NM_APP_ROOT . '/admin/')) {
            $lines[] = 'SKIP  ' . $rel . ' (outside allowed cleanup area)';
            continue;
        }
        if (is_link($path)) {
            $lines[] = 'SKIP  ' . $rel . ' (symbolic link)';
            continue;
        }
        if (!is_file($path)) {
            $lines[] = 'MISS  ' . $rel;
            continue;
        }

        $found++;
        if (!$delete) {
            $lines[] = 'FOUND ' . $rel . ' · ' . nm_format_bytes((int)(filesize($path) ?: 0));
            continue;
        }

        if (!is_writable($path)) {
            $lines[] = 'KEEP  ' . $rel . ' (not writable by PHP)';
            continue;
        }
        if (@unlink($path)) {
            $removed++;
            $lines[] = 'DELETED ' . $rel;
        } else {
            $lines[] = 'FAILED  ' . $rel;
        }
    }

    array_unshift(
        $lines,
        $delete
            ? 'Neptune installer cleanup: ' . $removed . ' of ' . $found . ' found files deleted.'
            : 'Neptune installer cleanup preview: ' . $found . ' removable file(s) found.'
    );
    $lines[] = '';
    $lines[] = 'Protected live files were not targeted:';
    $lines[] = '  admin/index.php';
    $lines[] = '  admin/maintenance.php';
    $lines[] = '  admin/connection-guard.php';
    return implode("\n", $lines);
}

function nm_command_available(string $name): bool {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists($name) && !in_array($name, $disabled, true);
}

function nm_lint_php_source(string $source, string $label = 'PHP source'): array {
    try {
        token_get_all($source, TOKEN_PARSE);
        return [true, $label . ': syntax OK'];
    } catch (ParseError $e) {
        return [false, $label . ': ' . $e->getMessage()];
    }
}

function nm_lint_php(string $path): array {
    if (!is_file($path) || !is_readable($path)) return [false, 'File not readable: ' . $path];
    $source = file_get_contents($path);
    if ($source === false) return [false, 'Could not read: ' . $path];
    return nm_lint_php_source($source, basename($path));
}

function nm_safe_zip_name(string $name): string {
    $name = str_replace('\\', '/', $name);
    while (str_starts_with($name, './')) $name = substr($name, 2);
    if ($name === '' || str_contains($name, "\0")) throw new RuntimeException('Invalid ZIP entry.');
    if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) throw new RuntimeException('Absolute ZIP paths are blocked.');
    foreach (explode('/', $name) as $part) {
        if ($part === '..') throw new RuntimeException('Parent-directory ZIP paths are blocked.');
    }
    return ltrim($name, '/');
}

function nm_target_for_entry(string $entry): ?string {
    $entry = nm_safe_zip_name($entry);
    if (str_ends_with($entry, '/')) return null;

    $blocked = [
        'neptune_secure/config.php',
        'etc/scout/db.env',
        'etc/scout/offline-sync.env',
    ];
    if (in_array($entry, $blocked, true) || preg_match('~(^|/)(\.env|\.aws|credentials)(/|$)~i', $entry)) {
        throw new RuntimeException('Protected credential/config path blocked: ' . $entry);
    }

    $serverPrefixes = ['public_html/Neptune/', 'neptune_secure/', 'scripts/', 'sql/'];
    foreach ($serverPrefixes as $prefix) {
        if (str_starts_with($entry, $prefix)) return NM_ROOT . '/' . $entry;
    }

    $appPrefixes = [
        'admin/','analytics/','api/','assets/','dashboard/','errors/','games/','help/','images/',
        'pit/','play/','prescout/','scout/','spot/','strategy/','uploads/'
    ];
    foreach ($appPrefixes as $prefix) {
        if (str_starts_with($entry, $prefix)) return NM_APP_ROOT . '/' . $entry;
    }

    if (in_array($entry, ['README.md','SHA256SUMS.txt'], true)) return NM_ROOT . '/' . $entry;

    throw new RuntimeException('Unsupported patch path: ' . $entry);
}

function nm_real_parent_inside(string $target): bool {
    $parent = dirname($target);
    $probe = $parent;
    while (!file_exists($probe) && $probe !== '/' && $probe !== '.') $probe = dirname($probe);
    $real = realpath($probe);
    $root = realpath(NM_ROOT);
    return $real !== false && $root !== false && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR));
}

function nm_zip_entries(string $zipPath): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is not installed.');
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Could not open ZIP package.');
    $entries = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;
            $raw = (string)($stat['name'] ?? '');
            $name = nm_safe_zip_name($raw);
            if ($name === '__MACOSX/' || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
            $isDir = str_ends_with($name, '/');
            $target = $isDir ? null : nm_target_for_entry($name);
            $entries[] = [
                'index' => $i,
                'name' => $name,
                'size' => (int)($stat['size'] ?? 0),
                'dir' => $isDir,
                'target' => $target,
            ];
        }
    } finally {
        $zip->close();
    }
    return $entries;
}

function nm_original_package_name(string $name): string {
    $base=basename($name);
    return preg_replace('/^\d{8}-\d{6}-[a-f0-9]{4}-/i','',$base) ?: $base;
}

function nm_migrations_for_package(PDO $pdo,string $package): array {
    neptune_migrations_ensure_table($pdo);
    $package=nm_original_package_name($package);
    $stmt=$pdo->prepare(
        'SELECT migration_key,applied_at,package
         FROM neptune_migrations
         WHERE package=?
         ORDER BY applied_at ASC,migration_key ASC'
    );
    $stmt->execute([$package]);
    return $stmt->fetchAll();
}

function nm_recent_installations(PDO $pdo,int $limit=8): array {
    if(!is_dir(NM_BACKUP_ROOT))return [];
    $dirs=array_filter(glob(NM_BACKUP_ROOT.'/*')?:[],static fn($p)=>is_dir($p)&&is_file($p.'/manifest.json'));
    usort($dirs,static fn($a,$b)=>filemtime($b)<=>filemtime($a));
    $out=[];
    foreach(array_slice($dirs,0,max(1,$limit)) as $dir){
        $manifest=json_decode((string)@file_get_contents($dir.'/manifest.json'),true);
        if(!is_array($manifest))continue;
        $package=nm_original_package_name((string)($manifest['package']??basename($dir)));
        $files=is_array($manifest['files']??null)?count($manifest['files']):0;
        $migrations=nm_migrations_for_package($pdo,$package);
        $out[]=[
            'backup'=>basename($dir),
            'created_at'=>(string)($manifest['created_at']??''),
            'package'=>$package,
            'files'=>$files,
            'migrations'=>$migrations,
        ];
    }
    return $out;
}

function nm_package_info(string $zipPath): array {
    $entries = nm_zip_entries($zipPath);
    $files = array_values(array_filter($entries, static fn($e) => !$e['dir']));
    $phpCount = count(array_filter($files, static fn($e) => strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)) === 'php'));
    $sqlCount = count(array_filter($files, static fn($e) => strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)) === 'sql'));
    $bytes = array_sum(array_column($files, 'size'));
    return ['entries' => $entries, 'files' => count($files), 'php' => $phpCount, 'sql' => $sqlCount, 'bytes' => $bytes];
}

function nm_validate_php_in_zip(string $zipPath, array $entries): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return [false, ['Could not reopen ZIP for PHP validation.']];
    $errors = [];
    $checked = 0;
    try {
        foreach ($entries as $entry) {
            if ($entry['dir'] || strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) !== 'php') continue;
            $content = $zip->getFromIndex($entry['index']);
            if ($content === false) {
                $errors[] = $entry['name'] . ': could not read from ZIP.';
                continue;
            }
            [$ok, $detail] = nm_lint_php_source($content, $entry['name']);
            $checked++;
            if ($ok === false) $errors[] = $detail;
        }
    } finally {
        $zip->close();
    }
    if ($errors) return [false, $errors];
    return [true, ['Validated ' . $checked . ' PHP file' . ($checked === 1 ? '' : 's') . ' with PHP TOKEN_PARSE.']];
}

function nm_latest_backup_dir(): ?string {
    if (!is_dir(NM_BACKUP_ROOT)) return null;
    $dirs = glob(NM_BACKUP_ROOT . '/*', GLOB_ONLYDIR) ?: [];
    usort($dirs, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($dirs as $dir) {
        if (is_file($dir . '/manifest.json')) return $dir;
    }
    return null;
}


function nm_invalidate_php_cache(array $paths): void {
    clearstatcache(true);
    if (!function_exists('opcache_invalidate')) return;
    foreach ($paths as $path) {
        if (!is_string($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') continue;
        @opcache_invalidate($path, true);
    }
}

function nm_install_zip(string $zipPath, string $originalName): array {
    $info = nm_package_info($zipPath);
    [$lintOk, $lintMessages] = nm_validate_php_in_zip($zipPath, $info['entries']);
    if ($lintOk === false) throw new RuntimeException("PHP validation failed:\n" . implode("\n", $lintMessages));

    if (!is_dir(NM_BACKUP_ROOT) && !mkdir(NM_BACKUP_ROOT, 0770, true) && !is_dir(NM_BACKUP_ROOT)) {
        throw new RuntimeException('Could not create maintenance backup folder.');
    }
    $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $backupDir = NM_BACKUP_ROOT . '/' . $stamp;
    if (!mkdir($backupDir, 0770, true)) throw new RuntimeException('Could not create patch backup directory.');

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Could not reopen ZIP for installation.');
    $manifest = [
        'created_at' => date(DATE_ATOM),
        'package' => nm_original_package_name($originalName),
        'uploaded_package' => $originalName,
        'installed_by' => (string)($_SESSION['user_id'] ?? ''),
        'files' => [],
    ];
    $written = [];

    try {
        foreach ($info['entries'] as $entry) {
            if ($entry['dir']) continue;
            $target = (string)$entry['target'];
            if (!nm_real_parent_inside($target)) throw new RuntimeException('Unsafe target path rejected: ' . $target);
            $content = $zip->getFromIndex($entry['index']);
            if ($content === false) throw new RuntimeException('Could not read ' . $entry['name'] . ' from ZIP.');

            $relTarget = ltrim(substr($target, strlen(NM_ROOT)), '/');
            $existed = is_file($target);
            $backupRel = null;
            if ($existed) {
                $backupRel = 'files/' . $relTarget;
                $backupPath = $backupDir . '/' . $backupRel;
                if (!is_dir(dirname($backupPath)) && !mkdir(dirname($backupPath), 0770, true) && !is_dir(dirname($backupPath))) {
                    throw new RuntimeException('Could not create backup folder for ' . $entry['name']);
                }
                if (!copy($target, $backupPath)) throw new RuntimeException('Could not back up ' . $entry['name']);
            }

            $parent = dirname($target);
            if ($existed) {
                // Existing Neptune files are commonly root:www-data 0664 while their
                // parent directories are intentionally not writable by Apache.
                // Overwrite the already-existing file directly after backup instead
                // of trying to create a temporary sibling in the protected folder.
                if (!is_writable($target)) {
                    throw new RuntimeException('Existing target is not writable by PHP: ' . $entry['name']);
                }
                if (file_put_contents($target, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Could not update existing file ' . $entry['name']);
                }
            } else {
                // Creating a brand-new file still requires write permission on the
                // destination directory. Keep this explicit rather than weakening
                // permissions across the Neptune application tree.
                if (!is_dir($parent)) {
                    if (!mkdir($parent, 0775, true) && !is_dir($parent)) {
                        throw new RuntimeException('Could not create target folder: ' . $parent);
                    }
                }
                if (!is_writable($parent)) {
                    throw new RuntimeException('Destination folder is not writable by PHP for new file: ' . $entry['name']);
                }
                if (file_put_contents($target, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Could not create new file ' . $entry['name']);
                }
                @chmod($target, 0664);
            }
            $manifest['files'][] = [
                'target' => $relTarget,
                'existed' => $existed,
                'backup' => $backupRel,
            ];
            $written[] = $target;
        }
    } catch (Throwable $e) {
        $zip->close();
        // Best-effort immediate file rollback.
        foreach (array_reverse($manifest['files']) as $file) {
            $target = NM_ROOT . '/' . $file['target'];
            if ($file['existed'] && $file['backup']) {
                @copy($backupDir . '/' . $file['backup'], $target);
            } elseif (!$file['existed']) {
                @unlink($target);
            }
        }
        throw $e;
    }
    $zip->close();

    file_put_contents($backupDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($backupDir . '/manifest.json', 0660);

    // Ensure updated PHP is executed on the very next request, even when
    // OPcache timestamp checks are disabled or infrequent. This is especially
    // important when the Maintenance Console updates itself.
    nm_invalidate_php_cache($written);

    return [$info, $backupDir, $lintMessages];
}

function nm_rollback_latest(): string {
    $dir = nm_latest_backup_dir();
    if ($dir === null) throw new RuntimeException('No maintenance backup is available to roll back.');
    $manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Backup manifest is invalid.');

    $count = 0;
    $changedPaths = [];
    foreach (array_reverse($manifest['files']) as $file) {
        $rel = (string)($file['target'] ?? '');
        if ($rel === '' || str_contains($rel, '..')) throw new RuntimeException('Invalid backup target.');
        $target = NM_ROOT . '/' . $rel;
        if (!nm_real_parent_inside($target)) throw new RuntimeException('Unsafe rollback target rejected.');
        if (!empty($file['existed'])) {
            $backup = (string)($file['backup'] ?? '');
            $source = $dir . '/' . $backup;
            if (!is_file($source)) throw new RuntimeException('Missing backup copy for ' . $rel);
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0775, true);
            if (!copy($source, $target)) throw new RuntimeException('Could not restore ' . $rel);
        } else {
            if (is_file($target) && !unlink($target)) throw new RuntimeException('Could not remove newly-installed file ' . $rel);
        }
        $changedPaths[] = $target;
        $count++;
    }
    nm_invalidate_php_cache($changedPaths);
    $rolled = $dir . '/ROLLED_BACK_' . date('Ymd-His');
    @file_put_contents($rolled, date(DATE_ATOM) . "\n");
    return 'Rolled back ' . $count . ' file' . ($count === 1 ? '' : 's') . ' from ' . basename($dir) . '.';
}

function nm_safe_upload_filename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'patch.zip';
    if (!str_ends_with(strtolower($name), '.zip')) throw new RuntimeException('Patch package must be a .zip file.');
    return $name;
}

function nm_list_patches(): array {
    if (!is_dir(NM_UPLOAD_ROOT)) return [];
    $files = glob(NM_UPLOAD_ROOT . '/*.zip') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

function nm_server_checks(PDO $pdo): array {
    $dbOk = false;
    $dbDetail = '';
    try {
        $dbOk = ((int)$pdo->query('SELECT 1')->fetchColumn() === 1);
        $dbDetail = $dbOk ? 'Connected' : 'Unexpected response';
    } catch (Throwable $e) {
        $dbDetail = $e->getMessage();
    }
    $rootFree = @disk_free_space(NM_ROOT);
    $rootTotal = @disk_total_space(NM_ROOT);
    $freePct = ($rootFree !== false && $rootTotal) ? ($rootFree / $rootTotal * 100) : null;
    return [
        ['label' => 'Database', 'ok' => $dbOk, 'detail' => $dbDetail],
        ['label' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION],
        ['label' => 'DB environment', 'ok' => is_readable('/etc/scout/db.env'), 'detail' => is_readable('/etc/scout/db.env') ? 'Readable by PHP' : 'Not readable'],
        ['label' => 'App root', 'ok' => is_dir(NM_APP_ROOT) && is_readable(NM_APP_ROOT), 'detail' => NM_APP_ROOT],
        ['label' => 'Patch storage', 'ok' => is_dir(NM_UPLOAD_ROOT) ? is_writable(NM_UPLOAD_ROOT) : is_writable(NM_ROOT), 'detail' => NM_UPLOAD_ROOT],
        ['label' => 'Disk free', 'ok' => $freePct === null ? false : $freePct > 10, 'detail' => $rootFree === false ? 'Unavailable' : nm_format_bytes($rootFree) . ' free' . ($freePct !== null ? ' (' . number_format($freePct, 1) . '%)' : '')],
    ];
}

if (!is_dir(NM_UPLOAD_ROOT)) @mkdir(NM_UPLOAD_ROOT, 0770, true);
if (!is_dir(NM_BACKUP_ROOT)) @mkdir(NM_BACKUP_ROOT, 0770, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload_patch') {
            $file = $_FILES['patch'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a ZIP patch package to upload.');
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0 || $size > NM_MAX_PATCH_BYTES) throw new RuntimeException('Patch must be between 1 byte and ' . nm_format_bytes(NM_MAX_PATCH_BYTES) . '.');
            $name = nm_safe_upload_filename((string)($file['name'] ?? 'patch.zip'));
            $dest = NM_UPLOAD_ROOT . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '-' . $name;
            if (!is_uploaded_file((string)$file['tmp_name']) || !move_uploaded_file((string)$file['tmp_name'], $dest)) throw new RuntimeException('Could not save uploaded patch.');
            @chmod($dest, 0660);
            $info = nm_package_info($dest);
            nm_flash('good', 'Patch uploaded and validated.', $info['files'] . ' files · ' . nm_format_bytes($info['bytes']) . ' unpacked');
            nm_redirect(['inspect' => basename($dest)], 'patch-ready');
        }

        if ($action === 'install_patch') {
            $name = basename((string)($_POST['package'] ?? ''));
            $path = NM_UPLOAD_ROOT . '/' . $name;
            if (!is_file($path)) throw new RuntimeException('Patch package not found.');
            [$info, $backupDir, $lintMessages] = nm_install_zip($path, $name);
            $packageName=nm_original_package_name($name);
            $migrationCount=count(nm_migrations_for_package($pdo,$packageName));
            nm_flash(
                'good',
                'Patch installed.',
                $info['files'].' files updated · '.$migrationCount.' DB migration'.($migrationCount===1?'':'s').' currently recorded for this package. Self-migrations that run on a later request will appear automatically. Backup: '.basename($backupDir).'.'
            );
            nm_redirect([], 'patch-manager');
        }

        if ($action === 'delete_patch') {
            $name = basename((string)($_POST['package'] ?? ''));
            $path = NM_UPLOAD_ROOT . '/' . $name;
            if (!is_file($path)) throw new RuntimeException('Patch package not found.');
            if (!unlink($path)) throw new RuntimeException('Could not delete patch package.');
            nm_flash('good', 'Uploaded patch deleted.');
            nm_redirect([], 'recent-packages');
        }

        if ($action === 'rollback') {
            nm_flash('good', 'Rollback completed.', nm_rollback_latest());
            nm_redirect([], 'recovery');
        }

        if ($action === 'opcache') {
            if (!function_exists('opcache_reset')) throw new RuntimeException('OPcache reset is not available in this PHP build.');
            $ok = opcache_reset();
            if (!$ok) throw new RuntimeException('OPcache did not confirm reset.');
            nm_flash('good', 'PHP OPcache cleared.');
            nm_redirect([], 'recovery');
        }

        if ($action === 'command') {
            $cmd = strtolower(trim((string)($_POST['command'] ?? '')));
            $_SESSION['nm_command_output'] = match ($cmd) {
                'status' => "Neptune root: " . NM_ROOT . "\nPHP: " . PHP_VERSION . "\nDB env readable: " . (is_readable('/etc/scout/db.env') ? 'yes' : 'no') . "\nServer: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
                'db' => (static function() use ($pdo) { try { return 'Database: OK · ' . (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { return 'Database: ERROR · ' . $e->getMessage(); } })(),
                'disk' => 'Disk free: ' . nm_format_bytes((float)(@disk_free_space(NM_ROOT) ?: 0)) . ' / ' . nm_format_bytes((float)(@disk_total_space(NM_ROOT) ?: 0)),
                'php' => 'PHP ' . PHP_VERSION . "\nSAPI: " . PHP_SAPI . "\nMemory limit: " . ini_get('memory_limit') . "\nUpload max: " . ini_get('upload_max_filesize'),
                'logs' => implode("\n", nm_tail('/var/log/apache2/error.log', 60)) ?: 'Apache error log is not readable by the web process.',
                'lint' => (static function() {
                    $targets = [NM_APP_ROOT . '/admin/index.php', NM_APP_ROOT . '/admin/maintenance.php', NM_ROOT . '/neptune_secure/bootstrap.php', NM_ROOT . '/neptune_secure/config.php'];
                    $out = [];
                    foreach ($targets as $target) { [$ok,$detail] = nm_lint_php($target); $out[] = $target . "\n" . $detail; }
                    return implode("\n\n", $out);
                })(),
                'cleanup-preview' => nm_cleanup_installers(false),
                'cleanup-installers' => nm_cleanup_installers(true),
                'help', '' => "Allowed commands:\n  status\n  db\n  disk\n  php\n  logs\n  lint\n  cleanup-preview\n  cleanup-installers\n\nArbitrary shell commands are intentionally disabled.",
                default => "Unknown maintenance command. Type: help",
            };
            nm_redirect(['terminal' => 1], 'advanced-tools');
        }

        throw new RuntimeException('Unknown maintenance action.');
    } catch (Throwable $e) {
        nm_flash('bad', 'Maintenance action failed.', $e->getMessage());
        nm_redirect([], 'patch-manager');
    }
}

$flash = nm_take_flash();
$checks = nm_server_checks($pdo);
$patches = nm_list_patches();
$recentPatches = array_slice($patches, 0, 5);
$hiddenPatchCount = max(0, count($patches) - count($recentPatches));
$inspectName = isset($_GET['inspect']) ? basename((string)$_GET['inspect']) : '';
$inspectInfo = null;
$inspectError = '';
if ($inspectName !== '') {
    try {
        $inspectPath = NM_UPLOAD_ROOT . '/' . $inspectName;
        if (!is_file($inspectPath)) throw new RuntimeException('Patch package not found.');
        $inspectInfo = nm_package_info($inspectPath);
    } catch (Throwable $e) {
        $inspectError = $e->getMessage();
    }
}
$latestBackup = nm_latest_backup_dir();
$productionBackup = nm_production_backup_status();
$migrationHistory = neptune_migration_recent($pdo, 20);
$installationHistory = nm_recent_installations($pdo, 8);
$latestInstallation = $installationHistory[0] ?? null;
$commandOutput = (string)($_SESSION['nm_command_output'] ?? '');
unset($_SESSION['nm_command_output']);

include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.nm-page{--nm-radius:14px;--nm-control-h:40px;scroll-behavior:smooth}
.nm-page [id]{scroll-margin-top:90px}
.nm-page .module-page-header{align-items:flex-start;margin-bottom:14px}
.nm-page .module-page-header>.btn{width:auto!important;min-width:0!important;align-self:flex-start}
.nm-page button,.nm-page .btn{width:auto;min-height:var(--nm-control-h);padding:8px 13px;border-radius:10px;font-size:.88rem;font-weight:800;line-height:1.15;display:inline-flex;align-items:center;justify-content:center;gap:7px}
.nm-page button.secondary,.nm-page .btn.secondary{background:var(--panel2);border:1px solid var(--line)}
.nm-page button.danger{background:color-mix(in srgb,var(--bad) 13%,var(--panel));border:1px solid color-mix(in srgb,var(--bad) 55%,var(--line));color:var(--text)}
.nm-flash{margin-bottom:14px}
.nm-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,.5fr);gap:14px;align-items:start}
.nm-section{margin-top:14px}
.nm-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}.nm-card-head h2{margin:3px 0 0}.nm-card-head p{margin:5px 0 0}
.nm-drop-zone{min-height:168px;border:2px dashed color-mix(in srgb,var(--accent) 50%,var(--line));border-radius:16px;background:linear-gradient(180deg,color-mix(in srgb,var(--accent) 8%,var(--panel2)),var(--panel2));display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:22px;cursor:pointer;transition:.16s ease}
.nm-drop-zone:hover,.nm-drop-zone.is-over{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,var(--panel2));transform:translateY(-1px)}
.nm-drop-zone input{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}.nm-drop-icon{width:50px;height:50px;border-radius:14px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 14%,var(--panel));color:var(--accent);font-size:1.3rem;margin-bottom:10px}.nm-drop-zone strong{font-size:1.05rem}.nm-drop-zone span{color:var(--muted);margin-top:4px}.nm-drop-zone small{color:var(--muted);margin-top:8px;font-size:.75rem}
.nm-upload-fallback{margin-top:10px}.js .nm-upload-fallback{display:none}
.nm-ready{margin-top:14px;border:1px solid color-mix(in srgb,var(--good) 48%,var(--line));border-radius:14px;background:color-mix(in srgb,var(--good) 6%,var(--panel));overflow:hidden}
.nm-ready-main{padding:14px}.nm-ready-title{display:flex;gap:10px;align-items:flex-start}.nm-ready-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex:none;background:color-mix(in srgb,var(--good) 16%,var(--panel2));color:var(--good)}.nm-ready h3{margin:0;font-size:1.05rem}.nm-ready-name{margin-top:2px;color:var(--muted);font-size:.78rem;overflow-wrap:anywhere}
.nm-ready-stats{display:flex;flex-wrap:wrap;gap:6px;margin-top:11px}.nm-ready-stats .pill{font-size:.73rem}
.nm-ready-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}.nm-ready-actions form{margin:0}.nm-ready-actions .install{min-width:132px}
.nm-package-details{border-top:1px solid var(--line)}.nm-package-details>summary{cursor:pointer;padding:11px 14px;font-weight:800;color:var(--muted);list-style:none}.nm-package-details>summary::-webkit-details-marker{display:none}.nm-package-details>summary:after{content:'+';float:right}.nm-package-details[open]>summary:after{content:'–'}
.nm-entry-list{max-height:230px;overflow:auto;border-top:1px solid var(--line)}.nm-entry{padding:8px 12px;border-bottom:1px solid var(--line);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.nm-entry:last-child{border-bottom:0}.nm-path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;overflow-wrap:anywhere}
.nm-inline-note{display:flex;gap:7px;align-items:flex-start;margin-top:10px;color:var(--muted);font-size:.78rem}.nm-inline-note i{margin-top:2px;color:var(--warn)}
.nm-recovery-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.nm-recovery-actions form{margin:0}.nm-backup-name{padding:9px 10px;border:1px solid var(--line);border-radius:10px;background:var(--panel2);font-size:.76rem;overflow-wrap:anywhere}
.nm-disclosure{border:1px solid var(--line);border-radius:14px;background:var(--panel);overflow:hidden}.nm-disclosure>summary{cursor:pointer;padding:13px 15px;display:flex;align-items:center;gap:9px;font-weight:900;list-style:none}.nm-disclosure>summary::-webkit-details-marker{display:none}.nm-disclosure>summary .muted{font-weight:600;margin-left:auto}.nm-disclosure-body{padding:0 14px 14px}
.nm-health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.nm-status{display:flex;gap:9px;align-items:flex-start;padding:11px 12px!important}.nm-dot{width:9px;height:9px;border-radius:50%;margin-top:5px;background:#a33;box-shadow:0 0 0 3px color-mix(in srgb,#a33 15%,transparent)}.nm-dot.ok{background:#26915d;box-shadow:0 0 0 3px color-mix(in srgb,#26915d 15%,transparent)}.nm-status b{display:block}.nm-status small{display:block;color:var(--muted);overflow-wrap:anywhere;font-size:.74rem}
.nm-recent-head{display:flex;align-items:flex-end;justify-content:space-between;gap:10px;margin-bottom:10px}.nm-recent-head h2{margin:3px 0 0}.nm-recent-list{display:grid;gap:7px}.nm-package-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px 11px;border:1px solid var(--line);border-radius:11px;background:var(--panel2)}.nm-package-row-main{min-width:0}.nm-package-row-name{font-weight:800;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.nm-package-row-meta{font-size:.72rem;color:var(--muted);margin-top:2px}.nm-package-row .btn{min-height:34px;padding:6px 10px;font-size:.78rem}.nm-limit-note{font-size:.73rem;color:var(--muted);margin-top:8px}
.nm-terminal{background:#07090c;color:#e8edf2;border:1px solid #222b34;border-radius:12px;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.nm-terminal pre{white-space:pre-wrap;overflow-wrap:anywhere;min-height:100px;margin:0 0 10px}.nm-terminal-row{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center}.nm-terminal input{margin:0;background:#0d1117;color:#fff;border-color:#2c3642;font-family:inherit}
.nm-advanced-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.5fr);gap:12px}
.nm-dialog{width:min(92vw,440px);border:1px solid var(--line);border-radius:16px;background:var(--panel);color:var(--text);padding:0;box-shadow:0 22px 70px #0008}.nm-dialog::backdrop{background:#000a;backdrop-filter:blur(3px)}.nm-dialog-body{padding:20px}.nm-dialog-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 14%,var(--panel2));margin-bottom:12px}.nm-dialog h3{margin:0 0 8px;font-size:1.2rem}.nm-dialog p{margin:0;color:var(--muted);line-height:1.45}.nm-dialog-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid var(--line);background:var(--panel2)}
.nm-drag-overlay{position:fixed;inset:0;z-index:99999;display:none;place-items:center;background:#05090ddd;backdrop-filter:blur(4px)}.nm-drag-overlay.show{display:grid}.nm-drag-overlay-inner{border:2px dashed var(--accent);border-radius:22px;padding:42px;min-width:min(88vw,520px);text-align:center;background:var(--panel);box-shadow:0 24px 90px #000b}.nm-drag-overlay-inner i{font-size:2rem;color:var(--accent);display:block;margin-bottom:12px}.nm-drag-overlay-inner b{font-size:1.25rem}
.nm-busy .nm-drop-zone{pointer-events:none;opacity:.72}.nm-busy .nm-drop-zone strong:after{content:' Uploading…';color:var(--accent)}
.nm-prod-backup-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.nm-prod-backup-item{padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}.nm-prod-backup-item small{display:block;color:var(--muted);font-size:.72rem;margin-bottom:4px}.nm-prod-backup-item b{display:block;overflow-wrap:anywhere}.nm-prod-backup-error{margin-top:10px}.nm-prod-backup-command{margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel2)}
.nm-update-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}.nm-update-stat{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}.nm-update-stat b{display:block;font-size:1.55rem}.nm-update-stat span{display:block;color:var(--muted);font-size:.78rem;margin-top:2px}.nm-migration-list{display:grid;gap:7px}.nm-migration-row{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,.9fr) auto;gap:10px;align-items:center;padding:10px 11px;border:1px solid var(--line);border-radius:11px;background:var(--panel2)}.nm-migration-key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;overflow-wrap:anywhere}.nm-migration-package{font-size:.76rem;color:var(--muted);overflow-wrap:anywhere}.nm-install-row{padding:11px;border:1px solid var(--line);border-radius:12px;background:var(--panel2)}.nm-install-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.nm-install-name{font-weight:850;overflow-wrap:anywhere}.nm-install-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.nm-install-migrations{margin-top:8px;font-size:.75rem;color:var(--muted);line-height:1.5}
@media(max-width:900px){.nm-layout,.nm-advanced-grid{grid-template-columns:1fr}.nm-health-grid{grid-template-columns:1fr 1fr}.nm-prod-backup-grid{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.nm-update-summary{grid-template-columns:1fr}.nm-migration-row{grid-template-columns:1fr}.nm-migration-row .pill{justify-self:start}}
@media(max-width:620px){.nm-health-grid{grid-template-columns:1fr}.nm-prod-backup-grid{grid-template-columns:1fr}.nm-entry{grid-template-columns:1fr}.nm-package-row{grid-template-columns:minmax(0,1fr) auto}.nm-page .module-page-header>.btn{width:auto!important}.nm-drop-zone{min-height:148px;padding:18px}.nm-ready-actions{align-items:stretch}.nm-ready-actions form{flex:1 1 140px}.nm-ready-actions button{width:100%}.nm-recovery-actions form{flex:1 1 150px}.nm-recovery-actions button{width:100%}.nm-disclosure>summary{align-items:flex-start;flex-wrap:wrap}.nm-disclosure>summary .muted{width:100%;margin-left:27px}.nm-terminal-row{grid-template-columns:auto 1fr}.nm-terminal-row button{grid-column:1/-1;width:100%}}
</style>
<script>document.documentElement.classList.add('js');</script>
<section class="module-page command-page nm-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">SATURN</div>
      <h1>Maintenance Console</h1>
      <p>Install Neptune patches, recover the latest change, and review system health.</p>
    </div>
    <a class="btn secondary" href="<?=nm_e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
  </header>

  <?php if ($flash): ?>
    <div class="notice <?=$flash['type']==='bad'?'bad':'good'?> nm-flash">
      <b><?=nm_e($flash['message'])?></b><?php if ($flash['detail'] !== ''): ?><div style="margin-top:5px;white-space:pre-wrap"><?=nm_e($flash['detail'])?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="nm-update-summary">
    <div class="nm-update-stat">
      <b><?= (int)($latestInstallation['files'] ?? 0) ?></b>
      <span>Files updated in the latest Maintenance Console install<?= $latestInstallation ? ' · '.nm_e($latestInstallation['package']) : '' ?></span>
    </div>
    <div class="nm-update-stat">
      <b><?= count($migrationHistory) ?></b>
      <span>DB migrations applied and tracked in <span class="nm-path">neptune_migrations</span></span>
    </div>
  </div>

  <div class="nm-layout">
    <div class="card" id="patch-manager">
      <div class="nm-card-head">
        <div>
          <div class="module-eyebrow"><span>PATCH MANAGER</span><small>ZIP packages</small></div>
          <h2>Install an update</h2>
          <p class="muted">Drop a Neptune ZIP anywhere on this page or tap below to choose it. Neptune validates it automatically, then shows one Install button.</p>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" id="nmUploadForm">
        <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>">
        <input type="hidden" name="action" value="upload_patch">
        <label class="nm-drop-zone" id="nmDropZone" for="nmPatchFile">
          <input id="nmPatchFile" type="file" name="patch" accept=".zip,application/zip" required>
          <span class="nm-drop-icon"><i class="fa-solid fa-file-arrow-up"></i></span>
          <strong>Drop patch ZIP here</strong>
          <span>or tap to choose a file</span>
          <small>ZIP only · up to <?=nm_e(nm_format_bytes(NM_MAX_PATCH_BYTES))?></small>
        </label>
        <button type="submit" class="secondary nm-upload-fallback"><i class="fa-solid fa-arrow-right"></i> Continue</button>
        <div class="notice bad" id="nmClientError" style="display:none;margin-top:10px"></div>
      </form>

      <?php if ($inspectError !== ''): ?><div class="notice bad" style="margin-top:12px"><?=nm_e($inspectError)?></div><?php endif; ?>
      <?php if ($inspectInfo): ?>
        <div class="nm-ready" id="patch-ready">
          <div class="nm-ready-main">
            <div class="nm-ready-title">
              <span class="nm-ready-icon"><i class="fa-solid fa-circle-check"></i></span>
              <div><h3>Ready to install</h3><div class="nm-ready-name"><?=nm_e($inspectName)?></div></div>
            </div>
            <div class="nm-ready-stats"><span class="pill"><?=$inspectInfo['files']?> files</span><span class="pill"><?=$inspectInfo['php']?> PHP</span><span class="pill"><?=$inspectInfo['sql']?> SQL</span><span class="pill"><?=nm_e(nm_format_bytes($inspectInfo['bytes']))?></span></div>
            <div class="nm-ready-actions">
              <form method="post">
                <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="install_patch"><input type="hidden" name="package" value="<?=nm_e($inspectName)?>">
                <button class="install" type="submit"><i class="fa-solid fa-box-open"></i> Install Patch</button>
              </form>
              <form method="post" data-nm-confirm="Delete this uploaded ZIP?" data-nm-title="Delete uploaded ZIP">
                <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="delete_patch"><input type="hidden" name="package" value="<?=nm_e($inspectName)?>">
                <button class="secondary" type="submit"><i class="fa-solid fa-trash"></i> Delete</button>
              </form>
            </div>
          </div>
          <details class="nm-package-details">
            <summary>View package contents</summary>
            <div class="nm-entry-list">
              <?php foreach ($inspectInfo['entries'] as $entry): if ($entry['dir']) continue; ?>
                <div class="nm-entry"><div><b><?=nm_e($entry['name'])?></b><div class="nm-path muted"><?=nm_e((string)$entry['target'])?></div></div><div><?=nm_e(nm_format_bytes($entry['size']))?></div></div>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
      <?php endif; ?>

      <div class="nm-inline-note"><i class="fa-solid fa-circle-info"></i><span>Existing files are backed up before installation. Schema changes remain self-migrating PHP and are recorded in <span class="nm-path">neptune_migrations</span>. SQL files are copied to the server but are not executed automatically.</span></div>
    </div>

    <div class="card" id="recovery">
      <div class="module-eyebrow"><span>RECOVERY</span><small>Last installed patch</small></div>
      <h2 style="margin:4px 0 10px">Rollback</h2>
      <?php if ($latestBackup): ?>
        <div class="muted" style="font-size:.78rem;margin-bottom:5px">Latest backup</div>
        <div class="nm-backup-name nm-path"><?=nm_e(basename($latestBackup))?></div>
        <div class="nm-recovery-actions">
          <form method="post" data-nm-confirm="Roll back the most recent Maintenance Console patch?" data-nm-title="Roll back patch">
            <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="rollback">
            <button class="danger" type="submit"><i class="fa-solid fa-rotate-left"></i> Roll Back</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="opcache">
            <button class="secondary" type="submit"><i class="fa-solid fa-broom"></i> Clear OPcache</button>
          </form>
        </div>
      <?php else: ?>
        <p class="muted">No Maintenance Console patch backup exists yet.</p>
        <form method="post" class="nm-recovery-actions">
          <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="opcache">
          <button class="secondary" type="submit"><i class="fa-solid fa-broom"></i> Clear OPcache</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php
    $prodState=(string)($productionBackup['status']??'not-configured');
    $prodGood=$prodState==='success';
    $prodBad=$prodState==='failure';
  ?>
  <div class="card nm-section" id="production-backup">
    <div class="nm-card-head">
      <div>
        <div class="module-eyebrow"><span>PRODUCTION BACKUP</span><small>Private GitHub Releases</small></div>
        <h2>Off-server backup</h2>
        <p class="muted">Nightly encrypted database + uploaded-file recovery point. Backup secrets and the encryption key stay off GitHub.</p>
      </div>
      <span class="pill">
        <i class="fa-solid <?=$prodGood?'fa-circle-check':($prodBad?'fa-triangle-exclamation':'fa-circle-info')?>"></i>
        <?=nm_e($prodGood?'Healthy':($prodBad?'Attention':'Not configured'))?>
      </span>
    </div>
    <div class="nm-prod-backup-grid">
      <div class="nm-prod-backup-item"><small>Last successful backup</small><b><?=nm_e(nm_backup_when((string)$productionBackup['last_success_at']))?></b></div>
      <div class="nm-prod-backup-item"><small>Encrypted size</small><b><?=nm_e(nm_format_bytes((int)$productionBackup['last_success_size_bytes']))?></b></div>
      <div class="nm-prod-backup-item"><small>Remote destination</small><b><?=nm_e((string)($productionBackup['repository']?:'Not configured'))?></b></div>
      <div class="nm-prod-backup-item"><small>Next scheduled</small><b><?=nm_e(nm_backup_when((string)$productionBackup['next_scheduled_at']))?></b></div>
    </div>
    <?php if(!empty($productionBackup['last_success_tag'])):?>
      <div class="nm-inline-note"><i class="fa-solid fa-box-archive"></i><span>Latest release: <span class="nm-path"><?=nm_e((string)$productionBackup['last_success_tag'])?></span> · Daily schedule: <?=nm_e((string)$productionBackup['schedule_utc'])?> UTC.</span></div>
    <?php endif;?>
    <?php if(!empty($productionBackup['last_error'])):?>
      <div class="notice bad nm-prod-backup-error"><b>Last backup failure</b><div><?=nm_e((string)$productionBackup['last_error'])?></div></div>
    <?php endif;?>
    <div class="nm-prod-backup-command"><div class="muted" style="font-size:.75rem;margin-bottom:4px">Documented restore command</div><span class="nm-path">sudo /opt/neptune/bin/neptune-restore-github latest --yes</span></div>
  </div>

  <?php $healthyCount=count(array_filter($checks,static fn($c)=>!empty($c['ok']))); ?>
  <details class="nm-disclosure nm-section" id="system-health">
    <summary><i class="fa-solid fa-heart-pulse"></i> System health <span class="muted"><?=$healthyCount?> / <?=count($checks)?> checks passing</span></summary>
    <div class="nm-disclosure-body">
      <div class="nm-health-grid">
        <?php foreach ($checks as $check): ?>
          <div class="card nm-status"><span class="nm-dot <?=$check['ok']?'ok':''?>"></span><div><b><?=nm_e($check['label'])?></b><small><?=nm_e($check['detail'])?></small></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </details>

  <div class="card nm-section" id="update-history">
    <div class="nm-recent-head">
      <div><div class="module-eyebrow"><span>UPDATE HISTORY</span><small>Files + database</small></div><h2>Installed updates</h2></div>
      <span class="pill"><?=count($installationHistory)?> shown</span>
    </div>
    <?php if(!$installationHistory):?>
      <p class="muted">No Maintenance Console installation history is available yet.</p>
    <?php else:?>
      <div class="nm-recent-list">
      <?php foreach($installationHistory as $install):?>
        <div class="nm-install-row">
          <div class="nm-install-top">
            <div>
              <div class="nm-install-name"><?=nm_e($install['package'])?></div>
              <div class="nm-package-row-meta"><?=nm_e($install['created_at']!==''?$install['created_at']:$install['backup'])?></div>
            </div>
            <span class="pill"><?=count($install['migrations'])?> DB migration<?=count($install['migrations'])===1?'':'s'?></span>
          </div>
          <div class="nm-install-meta">
            <span class="pill"><?=$install['files']?> files updated</span>
            <span class="pill"><?=nm_e($install['backup'])?></span>
          </div>
          <?php if($install['migrations']):?>
            <div class="nm-install-migrations">
              <?php foreach($install['migrations'] as $migration):?>
                <div><span class="nm-path"><?=nm_e($migration['migration_key'])?></span> · <?=nm_e($migration['applied_at'])?></div>
              <?php endforeach;?>
            </div>
          <?php endif;?>
        </div>
      <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>

  <details class="nm-disclosure nm-section" id="migration-history">
    <summary><i class="fa-solid fa-database"></i> DB migration history <span class="muted"><?=count($migrationHistory)?> recent migration<?=count($migrationHistory)===1?'':'s'?></span></summary>
    <div class="nm-disclosure-body">
      <?php if(!$migrationHistory):?>
        <p class="muted">No tracked migrations have been applied.</p>
      <?php else:?>
        <div class="nm-migration-list">
          <?php foreach($migrationHistory as $migration):?>
            <div class="nm-migration-row">
              <div class="nm-migration-key"><?=nm_e($migration['migration_key'])?></div>
              <div class="nm-migration-package"><?=nm_e($migration['package'])?></div>
              <span class="pill"><?=nm_e($migration['applied_at'])?></span>
            </div>
          <?php endforeach;?>
        </div>
      <?php endif;?>
      <div class="nm-inline-note"><i class="fa-solid fa-triangle-exclamation"></i><span>File rollback does not reverse database migrations. Migrations must be forward-safe and idempotent.</span></div>
    </div>
  </details>

  <?php if ($recentPatches): ?>
  <div class="card nm-section" id="recent-packages">
    <div class="nm-recent-head"><div><div class="module-eyebrow"><span>UPLOADS</span><small>Newest packages only</small></div><h2>Recent patch ZIPs</h2></div><span class="pill"><?=count($recentPatches)?> shown</span></div>
    <div class="nm-recent-list">
      <?php foreach ($recentPatches as $patch): $patchName=basename($patch); ?>
        <div class="nm-package-row">
          <div class="nm-package-row-main"><div class="nm-package-row-name"><?=nm_e($patchName)?></div><div class="nm-package-row-meta"><?=nm_e(nm_format_bytes((int)(filesize($patch)?:0)))?> · <?=nm_e(date('M j, g:i A', filemtime($patch)))?></div></div>
          <a class="btn secondary" href="<?=nm_e(base_url('admin/maintenance.php') . '?inspect=' . rawurlencode($patchName) . '#patch-ready')?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($hiddenPatchCount > 0): ?><div class="nm-limit-note">Showing the 5 newest packages. <?=nm_e((string)$hiddenPatchCount)?> older package<?= $hiddenPatchCount===1?' is':'s are' ?> hidden to keep this page compact.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <details class="nm-disclosure nm-section" id="advanced-tools" <?=isset($_GET['terminal'])?'open':''?>>
    <summary><i class="fa-solid fa-screwdriver-wrench"></i> Advanced maintenance <span class="muted">Terminal and patch-path reference</span></summary>
    <div class="nm-disclosure-body">
      <div class="nm-advanced-grid">
        <div>
          <h3 style="margin-top:0">Maintenance Terminal</h3>
          <div class="nm-terminal">
            <pre><?=nm_e($commandOutput !== '' ? $commandOutput : "Neptune Maintenance Console\nType help for allowed commands.")?></pre>
            <form method="post" class="nm-terminal-row">
              <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="command">
              <span>&gt;</span><input name="command" placeholder="help" autocomplete="off" autocapitalize="off"><button type="submit">Run</button>
            </form>
          </div>
        </div>
        <div>
          <h3 style="margin-top:0">Supported patch roots</h3>
          <p class="muted" style="font-size:.82rem;line-height:1.55">Packages may contain <span class="nm-path">public_html/Neptune/</span>, <span class="nm-path">neptune_secure/</span>, <span class="nm-path">scripts/</span>, <span class="nm-path">sql/</span>, or direct app folders such as <span class="nm-path">admin/</span>, <span class="nm-path">api/</span>, <span class="nm-path">assets/</span>, <span class="nm-path">play/</span>, <span class="nm-path">help/</span>, and <span class="nm-path">spot/</span>. Credential files are blocked.</p>
        </div>
      </div>
    </div>
  </details>

  <dialog class="nm-dialog" id="nmConfirmDialog">
    <div class="nm-dialog-body">
      <div class="nm-dialog-icon"><i class="fa-solid fa-shield-halved"></i></div>
      <h3 id="nmConfirmTitle">Confirm action</h3>
      <p id="nmConfirmMessage"></p>
    </div>
    <div class="nm-dialog-actions">
      <button type="button" class="secondary" id="nmConfirmCancel">Cancel</button>
      <button type="button" id="nmConfirmGo">Continue</button>
    </div>
  </dialog>
</section>
<div class="nm-drag-overlay" id="nmDragOverlay" aria-hidden="true"><div class="nm-drag-overlay-inner"><i class="fa-solid fa-file-arrow-up"></i><b>Drop Neptune patch ZIP</b><div class="muted" style="margin-top:6px">Release anywhere to upload and validate</div></div></div>
<script>
(()=>{
  const form=document.getElementById('nmUploadForm');
  const file=document.getElementById('nmPatchFile');
  const zone=document.getElementById('nmDropZone');
  const overlay=document.getElementById('nmDragOverlay');
  const clientError=document.getElementById('nmClientError');
  let dragDepth=0;
  const submitUpload=()=>{
    const f=file?.files?.[0];
    if(!form||!f) return;
    if(!/\.zip$/i.test(f.name)){
      file.value='';
      if(clientError){clientError.textContent='Choose a ZIP patch package.';clientError.style.display='block';}
      return;
    }
    if(clientError) clientError.style.display='none';
    form.classList.add('nm-busy');
    form.requestSubmit();
  };
  file?.addEventListener('change',submitUpload);
  ['dragenter','dragover'].forEach(type=>window.addEventListener(type,e=>{
    if(!Array.from(e.dataTransfer?.types||[]).includes('Files')) return;
    e.preventDefault();
    if(type==='dragenter') dragDepth++;
    overlay?.classList.add('show');
    zone?.classList.add('is-over');
  }));
  window.addEventListener('dragleave',e=>{
    if(!Array.from(e.dataTransfer?.types||[]).includes('Files')) return;
    dragDepth=Math.max(0,dragDepth-1);
    if(dragDepth===0){overlay?.classList.remove('show');zone?.classList.remove('is-over');}
  });
  window.addEventListener('drop',e=>{
    if(!e.dataTransfer?.files?.length) return;
    e.preventDefault();dragDepth=0;overlay?.classList.remove('show');zone?.classList.remove('is-over');
    const dropped=[...e.dataTransfer.files].find(f=>/\.zip$/i.test(f.name));
    if(!dropped) return;
    try{const dt=new DataTransfer();dt.items.add(dropped);file.files=dt.files;submitUpload();}catch(_){zone?.click();}
  });

  const dlg=document.getElementById('nmConfirmDialog');
  const title=document.getElementById('nmConfirmTitle');
  const msg=document.getElementById('nmConfirmMessage');
  const cancel=document.getElementById('nmConfirmCancel');
  const go=document.getElementById('nmConfirmGo');
  let pending=null;
  document.querySelectorAll('form[data-nm-confirm]').forEach(confirmForm=>{
    confirmForm.addEventListener('submit',ev=>{
      if(confirmForm.dataset.nmConfirmed==='1') return;
      ev.preventDefault();pending=confirmForm;
      title.textContent=confirmForm.dataset.nmTitle||'Confirm action';
      msg.textContent=confirmForm.dataset.nmConfirm||'Continue?';
      if(typeof dlg?.showModal==='function') dlg.showModal();
      else {confirmForm.dataset.nmConfirmed='1';confirmForm.submit();}
    });
  });
  cancel?.addEventListener('click',()=>{pending=null;dlg.close();});
  go?.addEventListener('click',()=>{if(!pending)return dlg.close();const f=pending;pending=null;dlg.close();f.dataset.nmConfirmed='1';f.submit();});
  dlg?.addEventListener('cancel',()=>{pending=null;});
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
