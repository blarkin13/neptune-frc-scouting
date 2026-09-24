<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner']);

$platformOrgId = 1;
if (isset($config) && is_array($config)) {
    $platformOrgId = (int)($config['app']['platform_organization_id'] ?? 1);
}
if ((int)($u['organization_id'] ?? 0) !== $platformOrgId) {
    http_response_code(403);
    exit('Platform owner access required.');
}

$pageTitle = 'Maintenance Console';
$moduleName = 'SATURN';

const NM_ROOT = '/var/www/neptune';
const NM_APP_ROOT = '/var/www/neptune/public_html/Neptune';
const NM_UPLOAD_ROOT = '/var/www/neptune/maintenance-uploads';
const NM_BACKUP_ROOT = '/var/www/neptune/maintenance-backups';
const NM_MAX_PATCH_BYTES = 50 * 1024 * 1024;

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

function nm_redirect(array $params = []): never {
    $url = base_url('admin/maintenance.php');
    if ($params) $url .= '?' . http_build_query($params);
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

    // Finder may rename an extracted public_html folder to public_html-2,
    // public_html-3, etc. Treat that as the intended Neptune app root.
    if (preg_match('~^public_html-\d+/Neptune/(.+)$~', $entry, $m)) {
        $entry = 'public_html/Neptune/' . $m[1];
    }

    $serverPrefixes = ['public_html/Neptune/', 'neptune_secure/', 'scripts/', 'sql/'];
    foreach ($serverPrefixes as $prefix) {
        if (str_starts_with($entry, $prefix)) return NM_ROOT . '/' . $entry;
    }

    $appPrefixes = [
        'admin/','analytics/','api/','assets/','dashboard/','errors/','games/','help/','images/',
        'pit/','prescout/','scout/','spot/','strategy/','uploads/'
    ];
    foreach ($appPrefixes as $prefix) {
        if (str_starts_with($entry, $prefix)) return NM_APP_ROOT . '/' . $entry;
    }

    if (in_array($entry, ['README.md','SHA256SUMS.txt'], true)) return NM_ROOT . '/' . $entry;

    // Allow normal Neptune files that live directly in the application root,
    // such as scouting.php or index.php. Credential/config locations above
    // remain explicitly blocked.
    if (!str_contains($entry, '/') && preg_match('/\.(?:php|js|css|json|webmanifest|txt|xml)$/i', $entry)) {
        return NM_APP_ROOT . '/' . $entry;
    }

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

    // First collect sanitized ZIP entries without resolving destinations. This lets
    // Neptune recognize the single wrapper folder macOS Finder commonly adds when
    // a user re-zips a folder that Safari automatically expanded after download.
    $rawEntries = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;
            $raw = (string)($stat['name'] ?? '');
            $name = nm_safe_zip_name($raw);
            if ($name === '__MACOSX/' || str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
            $rawEntries[] = [
                'index' => $i,
                'name' => $name,
                'size' => (int)($stat['size'] ?? 0),
                'dir' => str_ends_with($name, '/'),
            ];
        }
    } finally {
        $zip->close();
    }

    $files = array_values(array_filter($rawEntries, static fn($e) => !$e['dir']));
    if (!$files) throw new RuntimeException('Patch ZIP contains no deployable files.');

    // If the package already contains supported Neptune paths, keep it unchanged.
    $directOk = true;
    foreach ($files as $file) {
        try {
            nm_target_for_entry((string)$file['name']);
        } catch (RuntimeException $e) {
            $directOk = false;
            break;
        }
    }

    $wrapper = null;
    if (!$directOk) {
        $tops = [];
        foreach ($files as $file) {
            $parts = explode('/', (string)$file['name'], 2);
            if (count($parts) < 2 || $parts[0] === '') {
                $tops = [];
                break;
            }
            $tops[$parts[0]] = true;
        }

        // Strip exactly one common top-level directory only when every resulting
        // file is a valid Neptune patch path. Security/path validation still runs
        // through nm_target_for_entry() after stripping.
        if (count($tops) === 1) {
            $candidate = (string)array_key_first($tops) . '/';
            $strippedOk = true;
            foreach ($files as $file) {
                $name = (string)$file['name'];
                if (!str_starts_with($name, $candidate)) {
                    $strippedOk = false;
                    break;
                }
                $stripped = substr($name, strlen($candidate));
                if ($stripped === '') {
                    $strippedOk = false;
                    break;
                }
                try {
                    nm_target_for_entry($stripped);
                } catch (RuntimeException $e) {
                    $strippedOk = false;
                    break;
                }
            }
            if ($strippedOk) $wrapper = $candidate;
        }
    }

    $entries = [];
    foreach ($rawEntries as $entry) {
        $name = (string)$entry['name'];
        if ($wrapper !== null && str_starts_with($name, $wrapper)) {
            $name = substr($name, strlen($wrapper));
        }
        if ($name === '') continue; // wrapper directory itself

        $isDir = (bool)$entry['dir'];
        $target = $isDir ? null : nm_target_for_entry($name);
        $entries[] = [
            'index' => (int)$entry['index'],
            'name' => $name,
            'size' => (int)$entry['size'],
            'dir' => $isDir,
            'target' => $target,
        ];
    }
    return $entries;
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
        'package' => $originalName,
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

    return [$info, $backupDir, $lintMessages];
}

function nm_rollback_latest(): string {
    $dir = nm_latest_backup_dir();
    if ($dir === null) throw new RuntimeException('No maintenance backup is available to roll back.');
    $manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) throw new RuntimeException('Backup manifest is invalid.');

    $count = 0;
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
        $count++;
    }
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

function nm_patch_meta_path(string $zipPath): string {
    return $zipPath . '.meta.json';
}

function nm_pretty_patch_label(string $filename, array $info = []): string {
    $base = pathinfo(basename($filename), PATHINFO_FILENAME);
    $base = preg_replace('/^\d{8}-\d{6}-[a-f0-9]{4}-/i', '', $base) ?: $base;
    $pretty = trim((string)preg_replace('/[_-]+/', ' ', $base));
    $pretty = preg_replace('/\b(?:mac safe|maintenance console|safari download|download|install)\b/i', '', $pretty) ?: $pretty;
    $pretty = trim((string)preg_replace('/\s+/', ' ', $pretty));
    $pretty = preg_replace('/^Neptune\s+/i', '', $pretty) ?: $pretty;

    $generic = strtolower(trim($pretty));
    $genericNames = ['neptune','patch','update','admin','analytics','api','assets',''];
    $isGeneric = in_array($generic, $genericNames, true) || (bool)preg_match('/^public html(?: \d+)?$/', $generic);
    if (!$isGeneric) return $pretty;

    $names = array_values(array_filter(array_map(
        static fn($entry) => !$entry['dir'] ? (string)$entry['name'] : '',
        $info['entries'] ?? []
    )));

    $joined = strtolower(implode("\n", $names));
    if (str_contains($joined, 'admin/maintenance.php')) return 'Maintenance Console Update';
    if (str_contains($joined, 'analytics/alliance-selection.php')) return 'Alliance Selection Update';
    if (str_contains($joined, 'augur-ratings.php') || str_contains($joined, 'augur_epa')) return 'AUGUR Public EPA Update';
    if (str_contains($joined, 'admin/file-manager.php')) return 'File Manager Update';
    if (preg_match('~(^|/)spot/~m', $joined)) return 'Spot Scouting Update';
    if (preg_match('~(^|/)prescout/~m', $joined)) return 'Pre-Scouting Update';
    if (preg_match('~(^|/)pit/~m', $joined)) return 'Pit Scouting Update';
    if (preg_match('~(^|/)strategy/~m', $joined)) return 'Strategy Update';
    if (preg_match('~(^|/)scout/~m', $joined)) return 'Match Scouting Update';

    $first = $names[0] ?? 'Neptune Patch';
    $first = preg_replace('~^public_html(?:-\d+)?/Neptune/~i', '', $first) ?: $first;
    $top = explode('/', $first, 2)[0] ?? 'Neptune';
    $top = trim((string)preg_replace('/[_-]+/', ' ', $top));
    return ucwords($top) . ' Update';
}

function nm_write_patch_meta(string $zipPath, string $displayName, string $originalName): void {
    $meta = [
        'display_name' => $displayName,
        'original_name' => $originalName,
        'uploaded_at' => date(DATE_ATOM),
    ];
    @file_put_contents(nm_patch_meta_path($zipPath), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod(nm_patch_meta_path($zipPath), 0660);
}

function nm_patch_meta(string $zipPath): array {
    $metaPath = nm_patch_meta_path($zipPath);
    if (is_file($metaPath)) {
        $decoded = json_decode((string)@file_get_contents($metaPath), true);
        if (is_array($decoded)) return $decoded;
    }

    // Older uploads predate sidecar metadata. Derive a useful label without
    // changing the stored archive.
    try {
        $info = nm_package_info($zipPath);
        return [
            'display_name' => nm_pretty_patch_label(basename($zipPath), $info),
            'original_name' => basename($zipPath),
            'uploaded_at' => date(DATE_ATOM, (int)(filemtime($zipPath) ?: time())),
        ];
    } catch (Throwable $e) {
        return [
            'display_name' => pathinfo(basename($zipPath), PATHINFO_FILENAME),
            'original_name' => basename($zipPath),
            'uploaded_at' => '',
        ];
    }
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
            $displayName = nm_pretty_patch_label($name, $info);
            nm_write_patch_meta($dest, $displayName, $name);
            nm_flash('good', 'Patch uploaded and validated.', $displayName . ' · ' . $info['files'] . ' files · ' . nm_format_bytes($info['bytes']) . ' unpacked');
            nm_redirect(['inspect' => basename($dest)]);
        }

        if ($action === 'install_patch') {
            $name = basename((string)($_POST['package'] ?? ''));
            $path = NM_UPLOAD_ROOT . '/' . $name;
            if (!is_file($path)) throw new RuntimeException('Patch package not found.');
            $patchMeta = nm_patch_meta($path);
            $packageLabel = (string)($patchMeta['display_name'] ?? $name);
            [$info, $backupDir, $lintMessages] = nm_install_zip($path, $packageLabel);
            nm_flash('good', 'Patch installed.', $packageLabel . ' · ' . $info['files'] . ' files installed. Backup: ' . basename($backupDir) . '. SQL files are copied but not executed automatically.');
            nm_redirect();
        }

        if ($action === 'delete_patch') {
            $name = basename((string)($_POST['package'] ?? ''));
            $path = NM_UPLOAD_ROOT . '/' . $name;
            if (!is_file($path)) throw new RuntimeException('Patch package not found.');
            if (!unlink($path)) throw new RuntimeException('Could not delete patch package.');
            $metaPath = nm_patch_meta_path($path);
            if (is_file($metaPath)) @unlink($metaPath);
            nm_flash('good', 'Uploaded patch deleted.');
            nm_redirect();
        }

        if ($action === 'rollback') {
            nm_flash('good', 'Rollback completed.', nm_rollback_latest());
            nm_redirect();
        }

        if ($action === 'opcache') {
            if (!function_exists('opcache_reset')) throw new RuntimeException('OPcache reset is not available in this PHP build.');
            $ok = opcache_reset();
            if (!$ok) throw new RuntimeException('OPcache did not confirm reset.');
            nm_flash('good', 'PHP OPcache cleared.');
            nm_redirect();
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
            nm_redirect(['terminal' => 1]);
        }

        throw new RuntimeException('Unknown maintenance action.');
    } catch (Throwable $e) {
        nm_flash('bad', 'Maintenance action failed.', $e->getMessage());
        nm_redirect();
    }
}

$flash = nm_take_flash();
$checks = nm_server_checks($pdo);
$patches = nm_list_patches();
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
$inspectMeta = ($inspectName !== '' && is_file(NM_UPLOAD_ROOT . '/' . $inspectName))
    ? nm_patch_meta(NM_UPLOAD_ROOT . '/' . $inspectName)
    : null;
$latestBackup = nm_latest_backup_dir();
$commandOutput = (string)($_SESSION['nm_command_output'] ?? '');
unset($_SESSION['nm_command_output']);

include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.nm-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.nm-status{display:flex;gap:10px;align-items:flex-start}
.nm-dot{width:10px;height:10px;border-radius:50%;margin-top:5px;background:#a33;box-shadow:0 0 0 3px color-mix(in srgb,#a33 15%,transparent)}
.nm-dot.ok{background:#26915d;box-shadow:0 0 0 3px color-mix(in srgb,#26915d 15%,transparent)}
.nm-status b{display:block}.nm-status small{display:block;color:var(--muted);overflow-wrap:anywhere}
.nm-section{margin-top:16px}
.nm-two{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:14px}
.nm-terminal{background:#07090c;color:#e8edf2;border:1px solid #222b34;border-radius:7px;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
.nm-terminal pre{white-space:pre-wrap;overflow-wrap:anywhere;min-height:120px;margin:0 0 10px}
.nm-terminal-row{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center}
.nm-terminal input{margin:0;background:#0d1117;color:#fff;border-color:#2c3642;font-family:inherit}
.nm-patch-table td,.nm-patch-table th{vertical-align:top}
.nm-package-name{display:block;font-weight:900}.nm-package-file{display:block;margin-top:3px;color:var(--muted);font-size:.72rem;overflow-wrap:anywhere}
.nm-path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;overflow-wrap:anywhere}
.nm-entry-list{max-height:360px;overflow:auto;border:1px solid var(--line);border-radius:6px}
.nm-entry{padding:8px 10px;border-bottom:1px solid var(--line);display:grid;grid-template-columns:1fr auto;gap:10px}
.nm-entry:last-child{border-bottom:0}
.nm-actions{display:flex;gap:8px;flex-wrap:wrap}
.nm-warn{border-left:4px solid #d59b2b}.nm-danger{border-left:4px solid #b63b3b}

.nm-upload-form{margin-top:12px}
.nm-upload-picker{width:100%;border:1px dashed color-mix(in srgb,var(--accent,#2aa8ff) 45%,var(--line));border-radius:10px;background:color-mix(in srgb,var(--accent,#2aa8ff) 4%,var(--panel2));color:var(--text);padding:18px;display:flex;align-items:center;gap:13px;text-align:left;cursor:pointer;transition:border-color .15s ease,background .15s ease,transform .15s ease}
.nm-upload-picker:hover,.nm-upload-picker:focus-visible,.nm-upload-picker.is-dragover{border-color:var(--accent,#2aa8ff);background:color-mix(in srgb,var(--accent,#2aa8ff) 9%,var(--panel2))}
.nm-upload-picker:active{transform:translateY(1px)}
.nm-upload-picker i{display:grid;place-items:center;width:42px;height:42px;border-radius:9px;background:color-mix(in srgb,var(--accent,#2aa8ff) 12%,var(--panel));color:var(--accent,#2aa8ff);font-size:1.1rem}
.nm-upload-picker-copy{min-width:0}.nm-upload-picker-copy b{display:block;font-size:1rem}.nm-upload-picker-copy span{display:block;margin-top:3px;color:var(--muted);font-size:.76rem;line-height:1.35}
.nm-upload-status{display:none;align-items:center;gap:8px;margin-top:9px;color:var(--muted);font-size:.76rem}.nm-upload-status.show{display:flex}
.nm-upload-status i{color:var(--accent,#2aa8ff)}
.nm-hidden-file{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;opacity:0!important;pointer-events:none!important}

.nm-confirm-dialog{width:min(520px,calc(100vw - 28px));border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);padding:0;box-shadow:0 24px 80px rgba(0,0,0,.58)}
.nm-confirm-dialog::backdrop{background:rgba(0,0,0,.62);backdrop-filter:blur(2px)}
.nm-confirm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 16px;border-bottom:1px solid var(--line)}
.nm-confirm-head h2{margin:0;font-size:1.05rem}.nm-confirm-head button{min-width:38px}
.nm-confirm-body{padding:16px}.nm-confirm-body p{margin:0;color:var(--muted);line-height:1.5}
.nm-confirm-actions{display:flex;justify-content:flex-end;gap:8px;padding:0 16px 16px}
.nm-confirm-dialog[data-tone="danger"] .nm-confirm-icon{color:var(--bad)}
.nm-confirm-dialog[data-tone="warning"] .nm-confirm-icon{color:#d59b2b}

@media(max-width:900px){.nm-grid{grid-template-columns:1fr 1fr}.nm-two{grid-template-columns:1fr}}
@media(max-width:620px){
  .nm-grid{grid-template-columns:1fr}
  .nm-terminal-row{grid-template-columns:auto 1fr}.nm-terminal-row button{grid-column:1/-1}
  .nm-entry{grid-template-columns:1fr}
  .nm-upload-picker{padding:14px}
  .nm-confirm-actions{display:grid;grid-template-columns:1fr 1fr}
  .nm-confirm-actions button{width:100%}
}
</style>
<section class="module-page command-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">SATURN</div>
      <h1>Maintenance Console</h1>
      <p>Platform-owner server health, patch installation, rollback, and restricted maintenance commands.</p>
    </div>
    <a class="btn secondary" href="<?=nm_e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
  </header>

  <?php if ($flash): ?>
    <div class="notice <?=$flash['type']==='bad'?'bad':'good'?>" style="margin-bottom:14px">
      <b><?=nm_e($flash['message'])?></b><?php if ($flash['detail'] !== ''): ?><div style="margin-top:5px;white-space:pre-wrap"><?=nm_e($flash['detail'])?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="notice nm-warn" style="margin-bottom:14px">
    <b>Restricted console.</b> This page does not provide an unrestricted Linux shell. It exposes only Neptune maintenance operations and a fixed command set.
  </div>

  <div class="nm-grid">
    <?php foreach ($checks as $check): ?>
      <div class="card nm-status"><span class="nm-dot <?=$check['ok']?'ok':''?>"></span><div><b><?=nm_e($check['label'])?></b><small><?=nm_e($check['detail'])?></small></div></div>
    <?php endforeach; ?>
  </div>

  <div class="nm-two nm-section">
    <div class="card">
      <div class="module-eyebrow"><span>PATCH MANAGER</span><small>ZIP packages</small></div>
      <h2 style="margin-top:4px">Upload a Neptune Patch</h2>
      <p class="muted">Uploads are inspected for path traversal and protected credential files. PHP files are syntax-checked with PHP TOKEN_PARSE before installation; no shell execution is required. Existing files are backed up automatically.</p>
      <form method="post" enctype="multipart/form-data" class="nm-upload-form" id="nmUploadForm">
        <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>">
        <input type="hidden" name="action" value="upload_patch">
        <input class="nm-hidden-file" id="nmPatchInput" type="file" name="patch" accept=".zip,application/zip" required>
        <button class="nm-upload-picker" type="button" id="nmUploadPicker">
          <i class="fa-solid fa-cloud-arrow-up"></i>
          <span class="nm-upload-picker-copy">
            <b>Upload &amp; Inspect ZIP</b>
            <span>Click once to browse. Choosing a ZIP immediately uploads it and opens inspection. You can also drag a ZIP here.</span>
          </span>
        </button>
        <div class="nm-upload-status" id="nmUploadStatus"><i class="fa-solid fa-spinner fa-spin"></i><span>Preparing upload…</span></div>
      </form>
      <div class="notice nm-warn" style="margin-top:12px"><b>SQL:</b> SQL files can be installed into the server file tree, but this first console version deliberately does not execute database migrations automatically.</div>
    </div>

    <div class="card">
      <div class="module-eyebrow"><span>RECOVERY</span><small>Last installed patch</small></div>
      <h2 style="margin-top:4px">Rollback</h2>
      <?php if ($latestBackup): ?>
        <p>Latest backup:</p><div class="nm-path"><?=nm_e(basename($latestBackup))?></div>
        <form method="post" style="margin-top:12px" data-confirm-title="Roll back last patch?" data-confirm-message="This restores the files from the most recent Maintenance Console backup. Any changes made after that patch may be replaced." data-confirm-tone="danger" data-confirm-action="Roll Back">
          <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="rollback">
          <button class="danger" type="submit"><i class="fa-solid fa-rotate-left"></i> Roll Back Last Patch</button>
        </form>
      <?php else: ?><p class="muted">No Maintenance Console patch backup exists yet.</p><?php endif; ?>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="opcache">
        <button class="secondary" type="submit"><i class="fa-solid fa-broom"></i> Clear PHP OPcache</button>
      </form>
    </div>
  </div>

  <?php if ($inspectError !== ''): ?><div class="notice bad nm-section"><?=nm_e($inspectError)?></div><?php endif; ?>
  <?php if ($inspectInfo): ?>
    <div class="card nm-section">
      <div class="module-eyebrow"><span>INSPECT</span><small><?=nm_e((string)($inspectMeta['display_name'] ?? $inspectName))?></small></div>
      <h2 style="margin-top:4px">Package Contents</h2>
      <div class="nm-package-file" style="margin:-4px 0 10px">Archive: <?=nm_e($inspectName)?></div>
      <div class="toolbar" style="margin:0 0 12px"><span class="pill"><?=$inspectInfo['files']?> files</span><span class="pill"><?=$inspectInfo['php']?> PHP</span><span class="pill"><?=$inspectInfo['sql']?> SQL</span><span class="pill"><?=nm_e(nm_format_bytes($inspectInfo['bytes']))?></span></div>
      <div class="nm-entry-list">
        <?php foreach ($inspectInfo['entries'] as $entry): if ($entry['dir']) continue; ?>
          <div class="nm-entry"><div><b><?=nm_e($entry['name'])?></b><div class="nm-path muted"><?=nm_e((string)$entry['target'])?></div></div><div><?=nm_e(nm_format_bytes($entry['size']))?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="nm-actions" style="margin-top:12px">
        <form method="post" data-confirm-title="Install this patch?" data-confirm-message="Neptune will back up every existing file that is replaced before installing this package." data-confirm-tone="warning" data-confirm-action="Install Patch">
          <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="install_patch"><input type="hidden" name="package" value="<?=nm_e($inspectName)?>">
          <button type="submit"><i class="fa-solid fa-box-open"></i> Install Patch</button>
        </form>
        <form method="post" data-confirm-title="Delete uploaded ZIP?" data-confirm-message="This removes the stored patch ZIP from Maintenance. It does not roll back files already installed from it." data-confirm-tone="danger" data-confirm-action="Delete ZIP">
          <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="delete_patch"><input type="hidden" name="package" value="<?=nm_e($inspectName)?>">
          <button class="secondary" type="submit"><i class="fa-solid fa-trash"></i> Delete ZIP</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($patches): ?>
  <div class="card nm-section">
    <div class="module-eyebrow"><span>UPLOADS</span><small>Stored packages</small></div>
    <h2 style="margin-top:4px">Recent Patch ZIPs</h2>
    <div class="table-wrap"><table class="table nm-patch-table"><thead><tr><th>Package</th><th>Size</th><th>Uploaded</th><th></th></tr></thead><tbody>
      <?php foreach ($patches as $patch): $patchMeta = nm_patch_meta($patch); ?>
        <tr>
          <td><span class="nm-package-name"><?=nm_e((string)($patchMeta['display_name'] ?? basename($patch)))?></span><span class="nm-package-file"><?=nm_e((string)($patchMeta['original_name'] ?? basename($patch)))?></span></td>
          <td><?=nm_e(nm_format_bytes(filesize($patch)))?></td>
          <td><?=nm_e(date('M j, Y g:i A', filemtime($patch)))?></td>
          <td><a class="btn secondary" href="<?=nm_e(base_url('admin/maintenance.php') . '?inspect=' . rawurlencode(basename($patch)))?>"><i class="fa-solid fa-magnifying-glass"></i> Inspect</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>

  <div class="card nm-section">
    <div class="module-eyebrow"><span>CONSOLE</span><small>Restricted commands</small></div>
    <h2 style="margin-top:4px">Maintenance Terminal</h2>
    <div class="nm-terminal">
      <pre><?=nm_e($commandOutput !== '' ? $commandOutput : "Neptune Maintenance Console\nType help for allowed commands.")?></pre>
      <form method="post" class="nm-terminal-row">
        <input type="hidden" name="csrf" value="<?=nm_e(csrf_token())?>"><input type="hidden" name="action" value="command">
        <span>&gt;</span><input name="command" placeholder="help" autocomplete="off" autocapitalize="off"><button type="submit">Run</button>
      </form>
    </div>
  </div>

  <div class="notice nm-section">
    <b>Patch roots supported:</b> packages may contain direct Neptune app folders such as <span class="nm-path">admin/</span>, <span class="nm-path">analytics/</span>, <span class="nm-path">api/</span>, <span class="nm-path">assets/</span>, <span class="nm-path">spot/</span>, <span class="nm-path">strategy/</span>, and other scouting folders, plus root app files such as <span class="nm-path">scouting.php</span>. Full <span class="nm-path">public_html/Neptune/</span> packages are also accepted, including Finder-renamed <span class="nm-path">public_html-2/Neptune/</span> style paths. Credential files remain blocked.
  </div>
</section>

<dialog class="nm-confirm-dialog" id="nmConfirmDialog" data-tone="warning">
  <div class="nm-confirm-head">
    <h2><i class="fa-solid fa-triangle-exclamation nm-confirm-icon"></i> <span id="nmConfirmTitle">Confirm action</span></h2>
    <button type="button" class="secondary compact" id="nmConfirmClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="nm-confirm-body"><p id="nmConfirmMessage"></p></div>
  <div class="nm-confirm-actions">
    <button type="button" class="secondary" id="nmConfirmCancel">Cancel</button>
    <button type="button" id="nmConfirmSubmit">Continue</button>
  </div>
</dialog>

<script>
(()=>{
  const uploadForm=document.getElementById('nmUploadForm');
  const input=document.getElementById('nmPatchInput');
  const picker=document.getElementById('nmUploadPicker');
  const status=document.getElementById('nmUploadStatus');
  let uploadStarted=false;

  function startUpload(file){
    if(!file||uploadStarted)return;
    uploadStarted=true;
    status?.classList.add('show');
    const copy=status?.querySelector('span');
    if(copy)copy.textContent=`Uploading ${file.name} and opening inspection…`;
    if(picker){
      picker.disabled=true;
      picker.setAttribute('aria-busy','true');
    }
    uploadForm?.requestSubmit();
  }

  picker?.addEventListener('click',()=>input?.click());
  picker?.addEventListener('keydown',e=>{
    if(e.key==='Enter'||e.key===' '){e.preventDefault();input?.click();}
  });
  input?.addEventListener('change',()=>startUpload(input.files?.[0]));

  if(picker&&input&&window.DataTransfer){
    ['dragenter','dragover'].forEach(type=>picker.addEventListener(type,e=>{
      e.preventDefault();
      picker.classList.add('is-dragover');
    }));
    ['dragleave','drop'].forEach(type=>picker.addEventListener(type,e=>{
      e.preventDefault();
      picker.classList.remove('is-dragover');
    }));
    picker.addEventListener('drop',e=>{
      const files=Array.from(e.dataTransfer?.files||[]);
      const zip=files.find(file=>file.name.toLowerCase().endsWith('.zip'));
      if(!zip)return;
      const dt=new DataTransfer();
      dt.items.add(zip);
      input.files=dt.files;
      startUpload(zip);
    });
  }

  const dialog=document.getElementById('nmConfirmDialog');
  const title=document.getElementById('nmConfirmTitle');
  const message=document.getElementById('nmConfirmMessage');
  const close=document.getElementById('nmConfirmClose');
  const cancel=document.getElementById('nmConfirmCancel');
  const submit=document.getElementById('nmConfirmSubmit');
  let pendingForm=null;

  document.querySelectorAll('form[data-confirm-title]').forEach(form=>{
    form.addEventListener('submit',e=>{
      if(form.dataset.confirmed==='1'){
        form.dataset.confirmed='';
        return;
      }
      e.preventDefault();
      pendingForm=form;
      const tone=form.dataset.confirmTone||'warning';
      if(dialog)dialog.dataset.tone=tone;
      if(title)title.textContent=form.dataset.confirmTitle||'Confirm action';
      if(message)message.textContent=form.dataset.confirmMessage||'Continue with this action?';
      if(submit){
        submit.textContent=form.dataset.confirmAction||'Continue';
        submit.className=tone==='danger'?'danger':'';
      }
      dialog?.showModal();
    });
  });

  function closeConfirm(){
    pendingForm=null;
    dialog?.close();
  }
  close?.addEventListener('click',closeConfirm);
  cancel?.addEventListener('click',closeConfirm);
  dialog?.addEventListener('click',e=>{
    if(e.target===dialog)closeConfirm();
  });
  dialog?.addEventListener('cancel',e=>{
    e.preventDefault();
    closeConfirm();
  });
  submit?.addEventListener('click',()=>{
    if(!pendingForm)return;
    const form=pendingForm;
    pendingForm=null;
    dialog?.close();
    form.dataset.confirmed='1';
    form.requestSubmit();
  });
})();
</script>

<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
