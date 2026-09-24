<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner']);
$platformOrgId = max(1, (int)($config['app']['platform_organization_id'] ?? 1));
if ((int)($u['organization_id'] ?? 0) !== $platformOrgId) {
    access_denied('File Manager is reserved for the Neptune platform owner.');
}

$pageTitle = 'File Manager';
$moduleName = 'MERCURY';

$FM_ROOT = realpath('/var/www/neptune');
$FM_DEFAULT_REL = 'public_html/Neptune';
$FM_BACKUP_ROOT = '/var/www/neptune/file-manager-backups';
$FM_MAX_EDIT_BYTES = 2 * 1024 * 1024;
$FM_MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

if ($FM_ROOT === false || !is_dir($FM_ROOT)) {
    http_response_code(500);
    exit('Neptune file root could not be resolved.');
}

function fm_clean_rel(string $raw): string {
    $raw = str_replace('\\', '/', trim($raw));
    $raw = ltrim($raw, '/');
    if ($raw === '') return '';

    $parts = [];
    foreach (explode('/', $raw) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..' || str_contains($part, "\0")) {
            throw new RuntimeException('Invalid path.');
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function fm_inside_root(string $absolute): bool {
    global $FM_ROOT;
    return $absolute === $FM_ROOT || str_starts_with($absolute, $FM_ROOT . DIRECTORY_SEPARATOR);
}

function fm_protected_rel(string $rel): bool {
    $rel = fm_clean_rel($rel);
    return $rel === 'file-manager-backups'
        || str_starts_with($rel, 'file-manager-backups/');
}

function fm_existing(string $rel): string {
    global $FM_ROOT;
    $rel = fm_clean_rel($rel);
    if (fm_protected_rel($rel)) {
        throw new RuntimeException('That Neptune maintenance area is intentionally hidden from the browser file manager.');
    }

    $candidate = $rel === '' ? $FM_ROOT : $FM_ROOT . '/' . $rel;
    if (is_link($candidate)) {
        throw new RuntimeException('Symbolic links are blocked in the browser file manager.');
    }

    $real = realpath($candidate);
    if ($real === false || !fm_inside_root($real)) {
        throw new RuntimeException('The requested file or folder does not exist or is outside the Neptune file area.');
    }
    return $real;
}

function fm_dir(string $rel): string {
    $path = fm_existing($rel);
    if (!is_dir($path)) throw new RuntimeException('The requested path is not a folder.');
    return $path;
}

function fm_name(string $name): string {
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        throw new RuntimeException('Enter a valid file or folder name.');
    }
    return $name;
}

function fm_join_rel(string $parent, string $name): string {
    $parent = fm_clean_rel($parent);
    $name = fm_name($name);
    return $parent === '' ? $name : $parent . '/' . $name;
}

function fm_parent_rel(string $rel): string {
    $rel = fm_clean_rel($rel);
    if ($rel === '' || !str_contains($rel, '/')) return '';
    return dirname($rel) === '.' ? '' : str_replace('\\', '/', dirname($rel));
}

function fm_redirect(string $path, array $extra = []): never {
    $query = array_merge(['path' => fm_clean_rel($path)], $extra);
    header('Location: ' . base_url('admin/file-manager.php') . '?' . http_build_query($query));
    exit;
}

function fm_flash(string $type, string $message): void {
    $_SESSION['fm_flash'] = ['type' => $type, 'message' => $message];
}

function fm_take_flash(): ?array {
    $flash = $_SESSION['fm_flash'] ?? null;
    unset($_SESSION['fm_flash']);
    return is_array($flash) ? $flash : null;
}

function fm_format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $i => $unit) {
        if ($value < 1024 || $i === count($units) - 1) {
            return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

function fm_is_text_file(string $path): bool {
    global $FM_MAX_EDIT_BYTES;
    if (!is_file($path) || filesize($path) > $FM_MAX_EDIT_BYTES) return false;
    $sample = @file_get_contents($path, false, null, 0, 8192);
    if ($sample === false) return false;
    return !str_contains($sample, "\0");
}

function fm_backup_file(string $absolute, string $rel): void {
    global $FM_BACKUP_ROOT;
    if (!is_file($absolute)) return;
    if (!is_dir($FM_BACKUP_ROOT) || !is_writable($FM_BACKUP_ROOT)) {
        throw new RuntimeException('Backup directory is not writable. No destructive change was made.');
    }

    $rel = fm_clean_rel($rel);
    $subdir = fm_parent_rel($rel);
    $destDir = rtrim($FM_BACKUP_ROOT, '/') . ($subdir !== '' ? '/' . $subdir : '');
    if (!is_dir($destDir) && !mkdir($destDir, 0770, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create the backup folder.');
    }

    $dest = $destDir . '/' . basename($rel) . '.' . date('Ymd-His') . '.' . bin2hex(random_bytes(2)) . '.bak';
    if (!copy($absolute, $dest)) {
        throw new RuntimeException('Could not create a backup copy. No destructive change was made.');
    }
    @chmod($dest, 0660);
}

function fm_audit(string $action, string $rel): void {
    global $FM_BACKUP_ROOT, $u;
    if (!is_dir($FM_BACKUP_ROOT) || !is_writable($FM_BACKUP_ROOT)) return;
    $who = (string)($u['username'] ?? $u['display_name'] ?? 'owner');
    $line = sprintf("%s\t%s\t%s\t%s\n", date('c'), str_replace(["\t", "\n"], ' ', $who), $action, str_replace(["\t", "\n"], ' ', $rel));
    @file_put_contents($FM_BACKUP_ROOT . '/activity.log', $line, FILE_APPEND | LOCK_EX);
}

function fm_ext_icon(string $name, bool $isDir): string {
    if ($isDir) return 'fa-solid fa-folder';
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'php' => 'fa-brands fa-php',
        'json' => 'fa-solid fa-code',
        'js' => 'fa-brands fa-js',
        'css' => 'fa-brands fa-css3-alt',
        'html', 'htm' => 'fa-brands fa-html5',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg' => 'fa-solid fa-image',
        'zip', 'gz', 'tar', 'rar', '7z' => 'fa-solid fa-file-zipper',
        'sql' => 'fa-solid fa-database',
        'md', 'txt', 'log' => 'fa-solid fa-file-lines',
        'pdf' => 'fa-solid fa-file-pdf',
        default => 'fa-solid fa-file',
    };
}

function fm_selected_items(array $rawItems): array {
    $items = [];
    foreach ($rawItems as $raw) {
        $rel = fm_clean_rel((string)$raw);
        if ($rel === '' || fm_protected_rel($rel)) continue;
        $items[$rel] = $rel;
    }
    if (!$items) throw new RuntimeException('Select at least one file or folder.');
    return array_values($items);
}

function fm_assert_deletable_tree(string $absolute, string $rel): void {
    if (!fm_inside_root($absolute) || fm_protected_rel($rel)) {
        throw new RuntimeException('That item cannot be deleted.');
    }
    if (is_link($absolute)) {
        throw new RuntimeException('Delete stopped because the selection contains a symbolic link.');
    }
    if (is_dir($absolute)) {
        foreach (scandir($absolute) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $childAbs = $absolute . '/' . $name;
            $childRel = fm_join_rel($rel, $name);
            fm_assert_deletable_tree($childAbs, $childRel);
        }
    }
}

function fm_delete_tree(string $absolute, string $rel): void {
    if (is_dir($absolute)) {
        foreach (scandir($absolute) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $childAbs = $absolute . '/' . $name;
            $childRel = fm_join_rel($rel, $name);
            fm_delete_tree($childAbs, $childRel);
        }
        if (!rmdir($absolute)) throw new RuntimeException('Could not delete folder ' . basename($rel) . '.');
        return;
    }

    fm_backup_file($absolute, $rel);
    if (!unlink($absolute)) throw new RuntimeException('Could not delete file ' . basename($rel) . '.');
}

function fm_zip_add(ZipArchive $zip, string $absolute, string $zipRel): void {
    if (is_link($absolute)) return;
    if (is_dir($absolute)) {
        $zip->addEmptyDir(rtrim($zipRel, '/'));
        foreach (scandir($absolute) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            fm_zip_add($zip, $absolute . '/' . $name, rtrim($zipRel, '/') . '/' . $name);
        }
        return;
    }
    $zip->addFile($absolute, $zipRel);
}

function fm_stream_selection_zip(array $rels): never {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP support is not installed on this server.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'neptune-fm-');
    if ($tmp === false) throw new RuntimeException('Could not create a temporary archive.');
    $zipPath = $tmp . '.zip';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the ZIP archive.');
    }

    foreach ($rels as $rel) {
        $absolute = fm_existing($rel);
        fm_zip_add($zip, $absolute, basename($rel));
    }
    $zip->close();

    $filename = count($rels) === 1
        ? preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($rels[0])) . '.zip'
        : 'neptune-selection-' . date('Ymd-His') . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// Authenticated image preview stream.
if (isset($_GET['image'])) {
    try {
        $imageRel = fm_clean_rel((string)$_GET['image']);
        $imagePath = fm_existing($imageRel);
        if (!is_file($imagePath) || !is_readable($imagePath)) throw new RuntimeException('Image is not available.');

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $allowed = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
        ];
        if (!isset($allowed[$ext])) throw new RuntimeException('Unsupported image type.');

        header('Content-Type: ' . $allowed[$ext]);
        header('Content-Length: ' . filesize($imagePath));
        header('Cache-Control: private, max-age=60');
        header('X-Content-Type-Options: nosniff');
        readfile($imagePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Preview unavailable.');
    }
}

// Authenticated single-file download stream.
if (isset($_GET['download'])) {
    try {
        $downloadRel = fm_clean_rel((string)$_GET['download']);
        $downloadPath = fm_existing($downloadRel);
        if (!is_file($downloadPath) || !is_readable($downloadPath)) throw new RuntimeException('File is not available for download.');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addcslashes(basename($downloadPath), '"\\') . '"');
        header('Content-Length: ' . filesize($downloadPath));
        header('X-Content-Type-Options: nosniff');
        readfile($downloadPath);
        exit;
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Download unavailable.');
    }
}

// Small authenticated folder browser used by the Move dialog.
if (isset($_GET['folder_list'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rel = fm_clean_rel((string)$_GET['folder_list']);
        $dir = fm_dir($rel);
        $folders = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '__MACOSX' || $name === 'file-manager-backups') continue;
            $candidate = $dir . '/' . $name;
            if (is_link($candidate) || !is_dir($candidate)) continue;
            $folders[] = ['name' => $name, 'rel' => fm_join_rel($rel, $name)];
        }
        usort($folders, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        echo json_encode([
            'ok' => true,
            'rel' => $rel,
            'absolute' => $dir,
            'parent' => $rel === '' ? null : fm_parent_rel($rel),
            'folders' => $folders,
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$rawCurrent = array_key_exists('path', $_GET)
    ? (string)$_GET['path']
    : (array_key_exists('path', $_POST) ? (string)$_POST['path'] : $FM_DEFAULT_REL);

$currentRel = '';
try {
    $currentRel = fm_clean_rel($rawCurrent);
    fm_dir($currentRel);
} catch (Throwable $e) {
    try {
        $currentRel = fm_clean_rel($FM_DEFAULT_REL);
        fm_dir($currentRel);
    } catch (Throwable $ignored) {
        $currentRel = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        $currentRel = fm_clean_rel((string)($_POST['path'] ?? $currentRel));
        $currentDir = fm_dir($currentRel);

        if ($action === 'create_folder') {
            $name = fm_name((string)($_POST['name'] ?? ''));
            $targetRel = fm_join_rel($currentRel, $name);
            $target = $currentDir . '/' . $name;
            if (file_exists($target) || is_link($target)) throw new RuntimeException('A file or folder with that name already exists.');
            if (!mkdir($target, 0775)) throw new RuntimeException('Could not create the folder. Check permissions.');
            @chmod($target, 0775);
            fm_audit('mkdir', $targetRel);
            fm_flash('good', 'Folder created: ' . $name);
            fm_redirect($currentRel);
        }

        if ($action === 'create_file') {
            $name = fm_name((string)($_POST['name'] ?? ''));
            $targetRel = fm_join_rel($currentRel, $name);
            $target = $currentDir . '/' . $name;
            if (file_exists($target) || is_link($target)) throw new RuntimeException('A file or folder with that name already exists.');
            $handle = @fopen($target, 'x');
            if ($handle === false) throw new RuntimeException('Could not create the file. Check permissions.');
            fclose($handle);
            @chmod($target, 0664);
            fm_audit('create', $targetRel);
            fm_flash('good', 'File created: ' . $name);
            fm_redirect($currentRel, ['edit' => $targetRel]);
        }

        if ($action === 'upload') {
            if (empty($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
                throw new RuntimeException('Choose at least one file to upload.');
            }

            $pending = [];
            $seenNames = [];
            foreach ($_FILES['files']['name'] as $i => $rawName) {
                $err = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('An upload failed with error code ' . $err . '.');

                $size = (int)($_FILES['files']['size'][$i] ?? 0);
                if ($size > $FM_MAX_UPLOAD_BYTES) {
                    throw new RuntimeException('Upload is too large. Maximum per file is ' . fm_format_bytes($FM_MAX_UPLOAD_BYTES) . '.');
                }

                $name = fm_name(basename((string)$rawName));
                $key = strtolower($name);
                if (isset($seenNames[$key])) throw new RuntimeException('The upload contains more than one file named ' . $name . '.');
                $seenNames[$key] = true;

                $target = $currentDir . '/' . $name;
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('Upload stopped because ' . $name . ' already exists. Rename or delete the existing file first.');
                }

                $tmp = (string)($_FILES['files']['tmp_name'][$i] ?? '');
                if (!is_uploaded_file($tmp)) throw new RuntimeException('Could not verify uploaded file ' . $name . '.');
                $pending[] = ['name' => $name, 'tmp' => $tmp, 'target' => $target, 'rel' => fm_join_rel($currentRel, $name)];
            }

            if (!$pending) throw new RuntimeException('Choose at least one file to upload.');
            foreach ($pending as $file) {
                if (!move_uploaded_file($file['tmp'], $file['target'])) throw new RuntimeException('Could not save uploaded file ' . $file['name'] . '.');
                @chmod($file['target'], 0664);
                fm_audit('upload', $file['rel']);
            }

            fm_flash('good', count($pending) . ' file' . (count($pending) === 1 ? '' : 's') . ' uploaded.');
            fm_redirect($currentRel);
        }

        if ($action === 'save_file') {
            $fileRel = fm_clean_rel((string)($_POST['file'] ?? ''));
            $file = fm_existing($fileRel);
            if (!is_file($file) || !fm_is_text_file($file)) throw new RuntimeException('This file cannot be edited in the browser.');

            $content = (string)($_POST['content'] ?? '');
            if (strlen($content) > $FM_MAX_EDIT_BYTES) throw new RuntimeException('Edited file exceeds the browser editor limit.');
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
                json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON was not saved: ' . json_last_error_msg());
            }

            fm_backup_file($file, $fileRel);
            if (file_put_contents($file, $content, LOCK_EX) === false) throw new RuntimeException('Could not save the file. Check permissions.');
            fm_audit('save', $fileRel);
            fm_flash('good', 'Saved ' . basename($fileRel) . '. A backup copy was created first.');
            fm_redirect(fm_parent_rel($fileRel), ['edit' => $fileRel]);
        }

        if ($action === 'rename') {
            $itemRel = fm_clean_rel((string)($_POST['item'] ?? ''));
            if ($itemRel === '') throw new RuntimeException('The Neptune root cannot be renamed.');
            $item = fm_existing($itemRel);
            $newName = fm_name((string)($_POST['new_name'] ?? ''));
            $parentRel = fm_parent_rel($itemRel);
            $parent = fm_dir($parentRel);
            $targetRel = fm_join_rel($parentRel, $newName);
            $target = $parent . '/' . $newName;
            if ($targetRel === $itemRel) {
                fm_redirect($currentRel);
            }
            if (file_exists($target) || is_link($target)) throw new RuntimeException('A file or folder with that name already exists.');
            if (!rename($item, $target)) throw new RuntimeException('Could not rename the item.');
            fm_audit('rename ' . $itemRel . ' ->', $targetRel);
            fm_flash('good', 'Renamed to ' . $newName . '.');
            fm_redirect($currentRel);
        }

        if ($action === 'batch_delete') {
            $items = fm_selected_items((array)($_POST['items'] ?? []));
            $resolved = [];
            foreach ($items as $rel) {
                $absolute = fm_existing($rel);
                fm_assert_deletable_tree($absolute, $rel);
                $resolved[] = [$rel, $absolute];
            }
            foreach ($resolved as [$rel, $absolute]) {
                fm_delete_tree($absolute, $rel);
                fm_audit('delete', $rel);
            }
            fm_flash('good', count($resolved) . ' item' . (count($resolved) === 1 ? '' : 's') . ' deleted. File backups were created first.');
            fm_redirect($currentRel);
        }

        if ($action === 'batch_move') {
            $items = fm_selected_items((array)($_POST['items'] ?? []));
            $destinationRel = fm_clean_rel((string)($_POST['destination'] ?? ''));
            $destination = fm_dir($destinationRel);
            if (!is_writable($destination)) throw new RuntimeException('The destination folder is not writable.');

            $plan = [];
            foreach ($items as $rel) {
                $source = fm_existing($rel);
                if ($rel === '') throw new RuntimeException('The Neptune root cannot be moved.');
                if (is_dir($source) && ($destinationRel === $rel || str_starts_with($destinationRel . '/', $rel . '/'))) {
                    throw new RuntimeException('A folder cannot be moved into itself.');
                }

                $targetRel = fm_join_rel($destinationRel, basename($rel));
                $target = $destination . '/' . basename($rel);
                if ($targetRel === $rel) continue;
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException(basename($rel) . ' already exists in the destination folder.');
                }
                $plan[] = [$rel, $source, $targetRel, $target];
            }

            if (!$plan) {
                fm_flash('good', 'The selected item is already in that folder.');
                fm_redirect($currentRel);
            }

            foreach ($plan as [$rel, $source, $targetRel, $target]) {
                if (!rename($source, $target)) throw new RuntimeException('Could not move ' . basename($rel) . '.');
                fm_audit('move ' . $rel . ' ->', $targetRel);
            }
            fm_flash('good', count($plan) . ' item' . (count($plan) === 1 ? '' : 's') . ' moved.');
            fm_redirect($currentRel);
        }

        if ($action === 'batch_download') {
            $items = fm_selected_items((array)($_POST['items'] ?? []));
            if (count($items) === 1) {
                $single = fm_existing($items[0]);
                if (is_file($single) && is_readable($single)) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . addcslashes(basename($single), '"\\') . '"');
                    header('Content-Length: ' . filesize($single));
                    header('X-Content-Type-Options: nosniff');
                    readfile($single);
                    exit;
                }
            }
            fm_stream_selection_zip($items);
        }

        throw new RuntimeException('Unknown file-manager action.');
    } catch (Throwable $e) {
        fm_flash('bad', $e->getMessage());
        fm_redirect($currentRel);
    }
}

$currentDir = fm_dir($currentRel);
$entries = [];
foreach (scandir($currentDir) ?: [] as $name) {
    if ($name === '.' || $name === '..') continue;
    if ($name === '__MACOSX' || $name === '.DS_Store' || $name === 'Thumbs.db') continue;
    if ($currentRel === '' && $name === 'file-manager-backups') continue;

    $rawPath = $currentDir . '/' . $name;
    $rel = fm_join_rel($currentRel, $name);
    $isLink = is_link($rawPath);
    $blocked = $isLink;
    $isDir = !$blocked && is_dir($rawPath);
    $isText = !$blocked && !$isDir && is_file($rawPath) && fm_is_text_file($rawPath);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $isPreview = !$blocked && !$isDir && in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true);

    $entries[] = [
        'name' => $name,
        'rel' => $rel,
        'path' => $rawPath,
        'is_dir' => $isDir,
        'is_link' => $isLink,
        'blocked' => $blocked,
        'is_text' => $isText,
        'is_preview' => $isPreview,
        'size' => (!$isDir && is_file($rawPath)) ? (int)filesize($rawPath) : 0,
        'mtime' => (int)(@filemtime($rawPath) ?: 0),
        'writable' => is_writable($rawPath),
    ];
}

usort($entries, static function (array $a, array $b): int {
    if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

$editRel = '';
$editContent = null;
$editError = '';
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    try {
        $editRel = fm_clean_rel((string)$_GET['edit']);
        $editPath = fm_existing($editRel);
        if (!is_file($editPath)) throw new RuntimeException('Only files can be edited.');
        if (!fm_is_text_file($editPath)) throw new RuntimeException('This file looks binary or is larger than ' . fm_format_bytes($FM_MAX_EDIT_BYTES) . '. Use Download instead.');
        $editContent = file_get_contents($editPath);
        if ($editContent === false) throw new RuntimeException('Could not read the file.');
    } catch (Throwable $e) {
        $editError = $e->getMessage();
        $editRel = '';
        $editContent = null;
    }
}

$previewRel = '';
if (isset($_GET['preview']) && $_GET['preview'] !== '') {
    try {
        $candidate = fm_clean_rel((string)$_GET['preview']);
        $candidatePath = fm_existing($candidate);
        $ext = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));
        if (is_file($candidatePath) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true)) $previewRel = $candidate;
    } catch (Throwable $e) {
        $previewRel = '';
    }
}

$flash = fm_take_flash();
$currentWritable = is_writable($currentDir);
$csrf = csrf_token();
$baseManagerUrl = base_url('admin/file-manager.php');

include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.fm-shell{display:grid;gap:14px}.fm-topbar{padding:12px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.fm-breadcrumbs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:.92rem}.fm-breadcrumbs a,.fm-breadcrumbs span{display:inline-flex;align-items:center;gap:6px}.fm-breadcrumbs .sep{color:var(--muted)}.fm-path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.fm-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.fm-toolbar .btn,.fm-toolbar button{white-space:nowrap}.fm-toolbar-spacer{flex:1}.fm-grid-wrap{padding:14px;min-height:260px}.fm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:10px}.fm-item{position:relative;min-height:138px;border:1px solid var(--line);border-radius:10px;background:var(--panel);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;padding:18px 10px 12px;cursor:default;user-select:none;transition:border-color .14s ease,transform .14s ease,background .14s ease}.fm-item:hover{border-color:var(--accent);transform:translateY(-1px)}.fm-item.is-selected{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 8%,var(--panel))}.fm-item.is-blocked{opacity:.58}.fm-item-icon{font-size:2.65rem;color:var(--accent);line-height:1}.fm-item-name{font-size:.86rem;font-weight:700;text-align:center;line-height:1.25;overflow-wrap:anywhere;max-width:100%}.fm-item-meta{font-size:.72rem;color:var(--muted);text-align:center}.fm-select{position:absolute;top:8px;left:8px;width:20px;height:20px;accent-color:var(--accent);cursor:pointer}.fm-parent{color:inherit;text-decoration:none}.fm-parent .fm-item-icon{font-size:2.2rem}.fm-empty{padding:42px 20px;text-align:center;color:var(--muted)}.fm-floating{position:fixed;z-index:1200;left:50%;bottom:24px;transform:translateX(-50%) translateY(140%);display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--panel);box-shadow:0 14px 40px rgba(0,0,0,.25);transition:transform .18s ease;max-width:calc(100vw - 24px)}.fm-floating.is-visible{transform:translateX(-50%) translateY(0)}.fm-selected-count{font-weight:800;padding:0 6px}.fm-modal{position:fixed;inset:0;z-index:1400;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(4,13,22,.64);backdrop-filter:blur(3px)}.fm-modal.is-open{display:flex}.fm-modal-card{width:min(560px,100%);max-height:calc(100vh - 36px);overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.38);padding:18px}.fm-modal-card.wide{width:min(1100px,100%)}.fm-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.fm-modal-head h2{margin:0}.fm-modal-close{border:0;background:transparent;font-size:1.25rem;padding:5px 8px;cursor:pointer}.fm-modal-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:16px}.fm-action-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:14px}.fm-action-list .btn,.fm-action-list button{justify-content:center}.fm-detail-icon{text-align:center;font-size:3.4rem;color:var(--accent);margin:4px 0 12px}.fm-detail-meta{display:grid;grid-template-columns:110px 1fr;gap:7px 12px;font-size:.86rem}.fm-detail-meta dt{font-weight:800}.fm-detail-meta dd{margin:0;overflow-wrap:anywhere}.fm-drop-zone{border:2px dashed var(--line);border-radius:12px;padding:34px 18px;text-align:center;transition:border-color .15s ease,background .15s ease}.fm-drop-zone.is-over{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 7%,transparent)}.fm-drop-icon{font-size:2.4rem;color:var(--accent);margin-bottom:10px}.fm-upload-list{margin-top:12px;max-height:180px;overflow:auto}.fm-upload-file{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid var(--line);font-size:.82rem}.fm-folder-browser{border:1px solid var(--line);border-radius:10px;overflow:hidden}.fm-folder-browser-head{display:flex;gap:8px;align-items:center;padding:9px;border-bottom:1px solid var(--line);background:var(--panel2)}.fm-folder-browser-path{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;overflow-wrap:anywhere;flex:1}.fm-folder-list{max-height:280px;overflow:auto;padding:6px}.fm-folder-choice{width:100%;text-align:left;border:0;background:transparent;padding:9px 10px;border-radius:7px;cursor:pointer;display:flex;gap:9px;align-items:center}.fm-folder-choice:hover{background:var(--panel2)}.fm-warning{border-left:4px solid #d59b2b}.fm-editor{width:100%;min-height:66vh;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;font-size:.9rem;line-height:1.5;tab-size:4;white-space:pre}.fm-preview{display:grid;place-items:center;min-height:360px}.fm-preview img{max-width:100%;max-height:72vh;object-fit:contain;border:1px solid var(--line);border-radius:8px;background:repeating-conic-gradient(var(--panel2) 0 25%,var(--panel) 0 50%) 50%/20px 20px}.fm-danger-note{font-size:.8rem;color:var(--muted);margin-top:8px}.fm-toast-host{position:fixed;z-index:2600;top:18px;right:18px;width:min(390px,calc(100vw - 36px));display:grid;gap:10px;pointer-events:none}.fm-toast{pointer-events:auto;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:start;padding:12px 13px;border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:11px;background:var(--panel);box-shadow:0 16px 42px rgba(0,0,0,.28);transform:translateX(115%);opacity:0;transition:transform .2s ease,opacity .2s ease}.fm-toast.is-visible{transform:translateX(0);opacity:1}.fm-toast.is-good{border-left-color:#2f9d62}.fm-toast.is-bad{border-left-color:#c84d4d}.fm-toast.is-warning{border-left-color:#d59b2b}.fm-toast-icon{padding-top:2px}.fm-toast-body{min-width:0}.fm-toast-title{font-weight:800;line-height:1.25}.fm-toast-message{margin-top:3px;font-size:.84rem;line-height:1.4;color:var(--muted);overflow-wrap:anywhere}.fm-toast-close{border:0!important;background:transparent!important;padding:2px 4px!important;min-width:0!important;color:var(--muted);cursor:pointer}.fm-toast-actions{grid-column:2/4;display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap;margin-top:2px}.fm-toast-actions button{padding:7px 10px;font-size:.8rem}.fm-hidden{display:none!important}body.fm-modal-open{overflow:hidden}@media(max-width:760px){.fm-topbar{align-items:stretch}.fm-toolbar{width:100%}.fm-toolbar .btn,.fm-toolbar button{flex:1}.fm-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.fm-item{min-height:126px}.fm-action-list{grid-template-columns:1fr}.fm-floating{bottom:10px;width:calc(100vw - 20px)}.fm-floating .btn,.fm-floating button{flex:1}.fm-selected-count{width:100%;text-align:center}.fm-detail-meta{grid-template-columns:90px 1fr}.fm-toast-host{top:10px;right:10px;width:calc(100vw - 20px)}}
</style>

<section class="module-page command-page">
  <header class="module-page-header">
    <div>
      <div class="module-code">MERCURY</div>
      <h1>File Manager</h1>
      <p>Owner-only browser access to the Neptune server application tree.</p>
    </div>
    <a class="btn secondary" href="<?=e(base_url('admin/index.php'))?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
  </header>

  <div class="fm-toast-host" id="fmToastHost" aria-live="polite" aria-atomic="false"
       data-flash-type="<?=e((string)($flash['type'] ?? ''))?>"
       data-flash-message="<?=e((string)($flash['message'] ?? ''))?>"
       data-edit-error="<?=e($editError)?>"></div>
  <?php if (!$currentWritable): ?><div class="notice fm-warning" style="margin-bottom:14px"><b>Read-only here.</b> PHP cannot write to this folder.</div><?php endif; ?>

  <div class="fm-shell">
    <div class="card fm-topbar">
      <div>
        <div class="fm-breadcrumbs">
          <i class="fa-solid fa-hard-drive"></i>
          <a href="<?=e($baseManagerUrl . '?path=')?>"><b>Neptune</b></a>
          <?php $crumbPath = ''; foreach ($currentRel === '' ? [] : explode('/', $currentRel) as $crumb): $crumbPath = $crumbPath === '' ? $crumb : $crumbPath . '/' . $crumb; ?>
            <span class="sep">/</span><a href="<?=e($baseManagerUrl . '?' . http_build_query(['path' => $crumbPath]))?>"><?=e($crumb)?></a>
          <?php endforeach; ?>
        </div>
        <div class="muted fm-path" style="margin-top:7px"><?=e($currentDir)?></div>
      </div>
      <div class="fm-toolbar">
        <?php if ($currentRel !== $FM_DEFAULT_REL): ?><a class="btn secondary" href="<?=e($baseManagerUrl)?>"><i class="fa-solid fa-house"></i> App Home</a><?php endif; ?>
        <?php if ($currentWritable): ?>
          <button type="button" class="secondary" data-open-modal="newFileModal"><i class="fa-solid fa-file-circle-plus"></i> New File</button>
          <button type="button" class="secondary" data-open-modal="newFolderModal"><i class="fa-solid fa-folder-plus"></i> New Folder</button>
          <button type="button" data-open-modal="uploadModal"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
        <?php endif; ?>
        <button type="button" class="secondary" id="selectAllBtn"><i class="fa-solid fa-check-double"></i> Select All</button>
        <a class="btn secondary" href="<?=e($baseManagerUrl . '?' . http_build_query(['path' => $currentRel]))?>" title="Refresh"><i class="fa-solid fa-rotate-right"></i></a>
      </div>
    </div>

    <div class="card fm-grid-wrap">
      <?php if ($entries || $currentRel !== ''): ?>
      <div class="fm-grid" id="fmGrid">
        <?php if ($currentRel !== ''): ?>
          <a class="fm-item fm-parent" href="<?=e($baseManagerUrl . '?' . http_build_query(['path' => fm_parent_rel($currentRel)]))?>">
            <div class="fm-item-icon"><i class="fa-solid fa-turn-up"></i></div>
            <div class="fm-item-name">..</div>
            <div class="fm-item-meta">Parent folder</div>
          </a>
        <?php endif; ?>

        <?php foreach ($entries as $entry):
          $openUrl = $entry['is_dir']
              ? $baseManagerUrl . '?' . http_build_query(['path' => $entry['rel']])
              : ($entry['is_text']
                  ? $baseManagerUrl . '?' . http_build_query(['path' => $currentRel, 'edit' => $entry['rel']])
                  : ($entry['is_preview'] ? $baseManagerUrl . '?' . http_build_query(['path' => $currentRel, 'preview' => $entry['rel']]) : ''));
        ?>
          <div class="fm-item<?= $entry['blocked'] ? ' is-blocked' : '' ?>"
               tabindex="0"
               role="button"
               aria-label="<?=e($entry['name'])?>"
               data-rel="<?=e($entry['rel'])?>"
               data-name="<?=e($entry['name'])?>"
               data-dir="<?=$entry['is_dir'] ? '1' : '0'?>"
               data-editable="<?=$entry['is_text'] ? '1' : '0'?>"
               data-preview="<?=$entry['is_preview'] ? '1' : '0'?>"
               data-writable="<?=$entry['writable'] && $currentWritable ? '1' : '0'?>"
               data-blocked="<?=$entry['blocked'] ? '1' : '0'?>"
               data-size="<?=e($entry['is_dir'] ? 'Folder' : fm_format_bytes($entry['size']))?>"
               data-modified="<?=e($entry['mtime'] ? date('M j, Y g:i A', $entry['mtime']) : '—')?>"
               data-open-url="<?=e($openUrl)?>">
            <?php if (!$entry['blocked']): ?><input class="fm-select" type="checkbox" tabindex="-1" aria-label="Select <?=e($entry['name'])?>"><?php endif; ?>
            <div class="fm-item-icon"><i class="<?=e($entry['blocked'] ? 'fa-solid fa-link-slash' : fm_ext_icon($entry['name'], $entry['is_dir']))?>"></i></div>
            <div class="fm-item-name"><?=e($entry['name'])?></div>
            <div class="fm-item-meta"><?= $entry['is_dir'] ? 'Folder' : e(fm_format_bytes($entry['size'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="fm-empty"><i class="fa-regular fa-folder-open" style="font-size:2rem;margin-bottom:10px"></i><br>This folder is empty.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="notice" style="margin-top:16px">
    <b>Protected area:</b> only Neptune owners can open this page. The manager is locked to <span class="fm-path"><?=e($FM_ROOT)?></span>; paths and symlinks cannot escape it. Destructive file changes create backups under <span class="fm-path"><?=e($FM_BACKUP_ROOT)?></span>.
  </div>
</section>

<!-- Multi-select floating action bar -->
<div class="fm-floating" id="selectionBar" aria-live="polite">
  <span class="fm-selected-count" id="selectedCount">0 selected</span>
  <button type="button" class="secondary" id="batchRenameBtn"><i class="fa-solid fa-i-cursor"></i> Rename</button>
  <button type="button" class="secondary" id="batchMoveBtn"><i class="fa-solid fa-folder-tree"></i> Move</button>
  <button type="button" class="secondary" id="batchDownloadBtn"><i class="fa-solid fa-download"></i> Download</button>
  <button type="button" class="danger" id="batchDeleteBtn"><i class="fa-solid fa-trash"></i> Delete</button>
  <button type="button" class="secondary" id="clearSelectionBtn"><i class="fa-solid fa-xmark"></i> Clear</button>
</div>

<!-- Item actions modal -->
<div class="fm-modal" id="itemModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head">
      <div><div class="module-code">ITEM</div><h2 id="itemModalTitle">File</h2></div>
      <button type="button" class="fm-modal-close" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="fm-detail-icon" id="itemModalIcon"><i class="fa-solid fa-file"></i></div>
    <dl class="fm-detail-meta">
      <dt>Type</dt><dd id="itemModalType">File</dd>
      <dt>Size</dt><dd id="itemModalSize">—</dd>
      <dt>Modified</dt><dd id="itemModalModified">—</dd>
      <dt>Path</dt><dd class="fm-path" id="itemModalPath">—</dd>
    </dl>
    <div class="fm-action-list">
      <a class="btn secondary fm-hidden" id="itemOpenBtn" href="#"><i class="fa-solid fa-folder-open"></i> Open</a>
      <a class="btn secondary fm-hidden" id="itemEditBtn" href="#"><i class="fa-solid fa-pen"></i> Edit</a>
      <a class="btn secondary fm-hidden" id="itemPreviewBtn" href="#"><i class="fa-solid fa-eye"></i> Preview</a>
      <button type="button" class="secondary" id="itemRenameBtn"><i class="fa-solid fa-i-cursor"></i> Rename</button>
      <button type="button" class="secondary" id="itemMoveBtn"><i class="fa-solid fa-folder-tree"></i> Move</button>
      <button type="button" class="secondary" id="itemDownloadBtn"><i class="fa-solid fa-download"></i> Download</button>
      <button type="button" class="danger" id="itemDeleteBtn"><i class="fa-solid fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<!-- New file -->
<div class="fm-modal" id="newFileModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head"><div><div class="module-code">CREATE</div><h2>New File</h2></div><button type="button" class="fm-modal-close" data-close-modal><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="create_file"><input type="hidden" name="path" value="<?=e($currentRel)?>">
      <label for="newFileName">File name</label>
      <input id="newFileName" name="name" placeholder="example.php" required autocomplete="off">
      <div class="fm-modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit"><i class="fa-solid fa-plus"></i> Create &amp; Edit</button></div>
    </form>
  </div>
</div>

<!-- New folder -->
<div class="fm-modal" id="newFolderModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head"><div><div class="module-code">CREATE</div><h2>New Folder</h2></div><button type="button" class="fm-modal-close" data-close-modal><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="create_folder"><input type="hidden" name="path" value="<?=e($currentRel)?>">
      <label for="newFolderName">Folder name</label>
      <input id="newFolderName" name="name" placeholder="folder-name" required autocomplete="off">
      <div class="fm-modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit"><i class="fa-solid fa-plus"></i> Create Folder</button></div>
    </form>
  </div>
</div>

<!-- Upload -->
<div class="fm-modal" id="uploadModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head"><div><div class="module-code">UPLOAD</div><h2>Upload Files</h2><div class="muted fm-path"><?=e($currentDir)?></div></div><button type="button" class="fm-modal-close" data-close-modal><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="path" value="<?=e($currentRel)?>">
      <input class="fm-hidden" id="uploadInput" type="file" name="files[]" multiple required>
      <div class="fm-drop-zone" id="dropZone">
        <div class="fm-drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <h3 style="margin:0 0 5px">Drop files here</h3>
        <div class="muted">or use the file browser</div>
        <button type="button" class="secondary" id="browseFilesBtn" style="margin-top:14px"><i class="fa-solid fa-folder-open"></i> Browse Files</button>
        <div class="muted" style="font-size:.78rem;margin-top:10px">Maximum <?=e(fm_format_bytes($FM_MAX_UPLOAD_BYTES))?> per file.</div>
      </div>
      <div class="fm-upload-list" id="uploadList"></div>
      <div class="fm-modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit" id="uploadSubmitBtn" disabled><i class="fa-solid fa-upload"></i> Upload Here</button></div>
    </form>
  </div>
</div>

<!-- Rename -->
<div class="fm-modal" id="renameModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head"><div><div class="module-code">RENAME</div><h2>Rename Item</h2></div><button type="button" class="fm-modal-close" data-close-modal><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post" id="renameForm">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="path" value="<?=e($currentRel)?>"><input type="hidden" name="item" id="renameItem">
      <label for="renameName">New name</label>
      <input id="renameName" name="new_name" required autocomplete="off">
      <div class="fm-modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit"><i class="fa-solid fa-check"></i> Rename</button></div>
    </form>
  </div>
</div>

<!-- Move -->
<div class="fm-modal" id="moveModal" aria-hidden="true">
  <div class="fm-modal-card">
    <div class="fm-modal-head"><div><div class="module-code">MOVE</div><h2>Choose Destination</h2></div><button type="button" class="fm-modal-close" data-close-modal><i class="fa-solid fa-xmark"></i></button></div>
    <form method="post" id="moveForm">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="batch_move"><input type="hidden" name="path" value="<?=e($currentRel)?>"><input type="hidden" name="destination" id="moveDestination" value="<?=e($currentRel)?>">
      <div id="moveItems"></div>
      <div class="fm-folder-browser">
        <div class="fm-folder-browser-head"><button type="button" class="secondary" id="moveUpBtn" title="Up"><i class="fa-solid fa-arrow-up"></i></button><div class="fm-folder-browser-path" id="movePath">/var/www/neptune/<?=e($currentRel)?></div></div>
        <div class="fm-folder-list" id="moveFolderList"><div class="fm-empty" style="padding:24px">Loading…</div></div>
      </div>
      <div class="fm-modal-actions"><button type="button" class="secondary" data-close-modal>Cancel</button><button type="submit"><i class="fa-solid fa-folder-tree"></i> Move Here</button></div>
    </form>
  </div>
</div>

<!-- Generic POST form for selection downloads/deletes -->
<form method="post" id="batchActionForm" class="fm-hidden">
  <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="path" value="<?=e($currentRel)?>"><input type="hidden" name="action" id="batchActionName"><div id="batchActionItems"></div>
</form>

<?php if ($previewRel): ?>
<div class="fm-modal is-open" id="previewModal" aria-hidden="false">
  <div class="fm-modal-card wide">
    <div class="fm-modal-head"><div><div class="module-code">PREVIEW</div><h2><?=e(basename($previewRel))?></h2><div class="muted fm-path"><?=e($previewRel)?></div></div><a class="btn secondary" href="<?=e($baseManagerUrl . '?' . http_build_query(['path' => $currentRel]))?>"><i class="fa-solid fa-xmark"></i> Close</a></div>
    <div class="fm-preview"><img src="<?=e($baseManagerUrl . '?' . http_build_query(['image' => $previewRel]))?>" alt="Preview of <?=e(basename($previewRel))?>"></div>
  </div>
</div>
<?php endif; ?>

<?php if ($editRel !== '' && $editContent !== null): ?>
<div class="fm-modal is-open" id="editorModal" aria-hidden="false">
  <div class="fm-modal-card wide">
    <form method="post" id="fmEditorForm">
      <input type="hidden" name="csrf" value="<?=e($csrf)?>"><input type="hidden" name="action" value="save_file"><input type="hidden" name="path" value="<?=e($currentRel)?>"><input type="hidden" name="file" value="<?=e($editRel)?>">
      <div class="fm-modal-head">
        <div><div class="module-code">EDITOR</div><h2><?=e(basename($editRel))?></h2><div class="muted fm-path"><?=e($editRel)?></div></div>
        <div class="fm-toolbar"><a class="btn secondary" href="<?=e($baseManagerUrl . '?' . http_build_query(['path' => $currentRel]))?>"><i class="fa-solid fa-xmark"></i> Close</a><button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
      </div>
      <?php if (strtolower(pathinfo($editRel, PATHINFO_EXTENSION)) === 'php'): ?><div class="notice fm-warning" style="margin-bottom:10px"><b>PHP file:</b> saving takes effect immediately. A timestamped backup is created before every save.</div><?php endif; ?>
      <?php if (strtolower(pathinfo($editRel, PATHINFO_EXTENSION)) === 'json'): ?><div class="notice good" style="margin-bottom:10px">JSON is validated server-side before it is saved.</div><?php endif; ?>
      <textarea class="fm-editor" name="content" spellcheck="false" autocapitalize="off" autocomplete="off"><?=e($editContent)?></textarea>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const managerUrl = <?=json_encode($baseManagerUrl)?>;
  const rootPath = '/var/www/neptune';
  const currentRel = <?=json_encode($currentRel)?>;
  const cards = Array.from(document.querySelectorAll('.fm-item[data-rel]'));
  const selected = new Set();
  const selectionBar = document.getElementById('selectionBar');
  const selectedCount = document.getElementById('selectedCount');
  const batchRenameBtn = document.getElementById('batchRenameBtn');
  let lastSelectedIndex = null;
  let clickTimer = null;
  let activeItem = null;
  const toastHost = document.getElementById('fmToastHost');

  function toastIcon(type){
    if(type==='good') return 'fa-solid fa-circle-check';
    if(type==='bad') return 'fa-solid fa-circle-exclamation';
    if(type==='warning') return 'fa-solid fa-triangle-exclamation';
    return 'fa-solid fa-circle-info';
  }

  function dismissToast(toast){
    if(!toast) return;
    toast.classList.remove('is-visible');
    setTimeout(()=>toast.remove(),220);
  }

  function showToast(message,{type='info',title='',duration=4500,actions=[]}={}){
    if(!toastHost || !message) return null;
    const toast=document.createElement('div');
    toast.className=`fm-toast is-${type}`;
    toast.setAttribute('role',type==='bad'?'alert':'status');

    const icon=document.createElement('div');
    icon.className='fm-toast-icon';
    icon.innerHTML=`<i class="${toastIcon(type)}"></i>`;

    const body=document.createElement('div');
    body.className='fm-toast-body';
    if(title){ const heading=document.createElement('div'); heading.className='fm-toast-title'; heading.textContent=title; body.appendChild(heading); }
    const msg=document.createElement('div');
    msg.className='fm-toast-message';
    msg.textContent=message;
    body.appendChild(msg);

    const close=document.createElement('button');
    close.type='button';
    close.className='fm-toast-close';
    close.setAttribute('aria-label','Dismiss');
    close.innerHTML='<i class="fa-solid fa-xmark"></i>';
    close.addEventListener('click',()=>dismissToast(toast));

    toast.append(icon,body,close);

    if(actions.length){
      const actionRow=document.createElement('div');
      actionRow.className='fm-toast-actions';
      actions.forEach(action=>{
        const btn=document.createElement('button');
        btn.type='button';
        btn.className=action.className||'secondary';
        btn.textContent=action.label;
        btn.addEventListener('click',()=>{
          if(action.onClick) action.onClick();
          dismissToast(toast);
        });
        actionRow.appendChild(btn);
      });
      toast.appendChild(actionRow);
    }

    toastHost.appendChild(toast);
    requestAnimationFrame(()=>toast.classList.add('is-visible'));
    if(duration>0) setTimeout(()=>dismissToast(toast),duration);
    return toast;
  }

  function showConfirmToast(message,onConfirm,{title='Confirm action',confirmLabel='Confirm',type='warning'}={}){
    return showToast(message,{
      type, title, duration:0,
      actions:[
        {label:'Cancel',className:'secondary'},
        {label:confirmLabel,className:type==='bad'?'danger':'',onClick:onConfirm}
      ]
    });
  }

  if(toastHost){
    const flashMessage=toastHost.dataset.flashMessage||'';
    const flashType=toastHost.dataset.flashType==='bad'?'bad':'good';
    if(flashMessage) showToast(flashMessage,{type:flashType,title:flashType==='bad'?'Could not complete action':'Done'});
    const editError=toastHost.dataset.editError||'';
    if(editError) showToast(editError,{type:'bad',title:'Editor error',duration:6500});
  }

  function openModal(id){
    const modal = typeof id === 'string' ? document.getElementById(id) : id;
    if(!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('fm-modal-open');
    const focusable = modal.querySelector('input:not([type="hidden"]),textarea,button:not([data-close-modal])');
    if(focusable) setTimeout(()=>focusable.focus(),30);
  }

  function closeModal(modal){
    if(!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    if(!document.querySelector('.fm-modal.is-open')) document.body.classList.remove('fm-modal-open');
  }

  document.querySelectorAll('[data-open-modal]').forEach(btn=>btn.addEventListener('click',()=>openModal(btn.dataset.openModal)));
  document.querySelectorAll('[data-close-modal]').forEach(btn=>btn.addEventListener('click',()=>closeModal(btn.closest('.fm-modal'))));
  document.querySelectorAll('.fm-modal').forEach(modal=>modal.addEventListener('mousedown',e=>{ if(e.target===modal) closeModal(modal); }));
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      const open = Array.from(document.querySelectorAll('.fm-modal.is-open')).pop();
      if(open && !['editorModal','previewModal'].includes(open.id)) closeModal(open);
    }
  });

  function setSelected(card, value){
    const rel = card.dataset.rel;
    const box = card.querySelector('.fm-select');
    if(!box) return;
    if(value){ selected.add(rel); box.checked=true; card.classList.add('is-selected'); card.setAttribute('aria-selected','true'); }
    else { selected.delete(rel); box.checked=false; card.classList.remove('is-selected'); card.setAttribute('aria-selected','false'); }
    updateSelectionBar();
  }

  function updateSelectionBar(){
    const n = selected.size;
    selectedCount.textContent = `${n} selected`;
    selectionBar.classList.toggle('is-visible', n>0);
    batchRenameBtn.disabled = n!==1;
  }

  function clearSelection(){
    cards.forEach(card=>setSelected(card,false));
    lastSelectedIndex=null;
  }

  function selectedItems(){ return Array.from(selected); }

  function toggleWithRange(card, e){
    const idx = cards.indexOf(card);
    if(e.shiftKey && lastSelectedIndex!==null){
      const from=Math.min(lastSelectedIndex,idx), to=Math.max(lastSelectedIndex,idx);
      for(let i=from;i<=to;i++) setSelected(cards[i],true);
    } else {
      setSelected(card,!selected.has(card.dataset.rel));
      lastSelectedIndex=idx;
    }
  }

  cards.forEach(card=>{
    const box=card.querySelector('.fm-select');
    if(box){
      box.addEventListener('click',e=>{e.stopPropagation(); toggleWithRange(card,e);});
    }
    card.addEventListener('click',e=>{
      if(card.dataset.blocked==='1') return;
      if(e.ctrlKey||e.metaKey||e.shiftKey){ toggleWithRange(card,e); return; }
      clearTimeout(clickTimer);
      clickTimer=setTimeout(()=>showItemModal(card),220);
    });
    card.addEventListener('dblclick',e=>{
      e.preventDefault();
      clearTimeout(clickTimer);
      if(card.dataset.openUrl) window.location.href=card.dataset.openUrl;
      else showItemModal(card);
    });
    card.addEventListener('keydown',e=>{
      if(e.key==='Enter'){
        e.preventDefault();
        if(card.dataset.openUrl) window.location.href=card.dataset.openUrl;
        else showItemModal(card);
      }
      if(e.key===' '){ e.preventDefault(); setSelected(card,!selected.has(card.dataset.rel)); }
    });
  });

  document.getElementById('selectAllBtn')?.addEventListener('click',()=>{
    const allSelected = cards.length>0 && cards.every(c=>selected.has(c.dataset.rel));
    cards.forEach(c=>setSelected(c,!allSelected));
  });
  document.getElementById('clearSelectionBtn')?.addEventListener('click',clearSelection);

  function itemIconClass(card){
    const icon=card.querySelector('.fm-item-icon i');
    return icon ? icon.className : 'fa-solid fa-file';
  }

  function showItemModal(card){
    activeItem=card;
    const isDir=card.dataset.dir==='1';
    const editable=card.dataset.editable==='1';
    const preview=card.dataset.preview==='1';
    const writable=card.dataset.writable==='1';
    const rel=card.dataset.rel;
    const name=card.dataset.name;

    document.getElementById('itemModalTitle').textContent=name;
    document.getElementById('itemModalIcon').innerHTML=`<i class="${itemIconClass(card)}"></i>`;
    document.getElementById('itemModalType').textContent=isDir?'Folder':(editable?'Editable file':'File');
    document.getElementById('itemModalSize').textContent=card.dataset.size||'—';
    document.getElementById('itemModalModified').textContent=card.dataset.modified||'—';
    document.getElementById('itemModalPath').textContent=`${rootPath}/${rel}`;

    const openBtn=document.getElementById('itemOpenBtn');
    const editBtn=document.getElementById('itemEditBtn');
    const previewBtn=document.getElementById('itemPreviewBtn');
    openBtn.classList.toggle('fm-hidden',!isDir); if(isDir) openBtn.href=card.dataset.openUrl;
    editBtn.classList.toggle('fm-hidden',!editable); if(editable) editBtn.href=card.dataset.openUrl;
    previewBtn.classList.toggle('fm-hidden',!preview); if(preview) previewBtn.href=card.dataset.openUrl;
    document.getElementById('itemRenameBtn').disabled=!writable;
    document.getElementById('itemMoveBtn').disabled=!writable;
    document.getElementById('itemDeleteBtn').disabled=!writable;
    openModal('itemModal');
  }

  function openRenameFor(rel,name){
    document.getElementById('renameItem').value=rel;
    const input=document.getElementById('renameName');
    input.value=name;
    closeModal(document.getElementById('itemModal'));
    openModal('renameModal');
    setTimeout(()=>{input.focus();input.select();},40);
  }

  document.getElementById('itemRenameBtn')?.addEventListener('click',()=>{ if(activeItem) openRenameFor(activeItem.dataset.rel,activeItem.dataset.name); });
  batchRenameBtn?.addEventListener('click',()=>{
    if(selected.size!==1) return;
    const rel=selectedItems()[0];
    const card=cards.find(c=>c.dataset.rel===rel);
    if(card) openRenameFor(rel,card.dataset.name);
  });

  function fillItemInputs(container,items){
    container.innerHTML='';
    items.forEach(rel=>{
      const input=document.createElement('input');
      input.type='hidden'; input.name='items[]'; input.value=rel;
      container.appendChild(input);
    });
  }

  function submitBatch(action,items){
    const form=document.getElementById('batchActionForm');
    document.getElementById('batchActionName').value=action;
    fillItemInputs(document.getElementById('batchActionItems'),items);
    form.requestSubmit();
  }

  document.getElementById('batchDownloadBtn')?.addEventListener('click',()=>submitBatch('batch_download',selectedItems()));
  document.getElementById('batchDeleteBtn')?.addEventListener('click',()=>{
    const items=selectedItems(); if(!items.length)return;
    showConfirmToast(
      `Delete ${items.length} selected item${items.length===1?'':'s'}? Folders will be deleted recursively. File backups are created first.`,
      ()=>submitBatch('batch_delete',items),
      {title:'Delete selected items?',confirmLabel:'Delete',type:'bad'}
    );
  });
  document.getElementById('itemDownloadBtn')?.addEventListener('click',()=>{ if(activeItem) submitBatch('batch_download',[activeItem.dataset.rel]); });
  document.getElementById('itemDeleteBtn')?.addEventListener('click',()=>{
    if(!activeItem)return;
    const rel=activeItem.dataset.rel;
    const name=activeItem.dataset.name;
    closeModal(document.getElementById('itemModal'));
    showConfirmToast(
      `Delete ${name}? Folders will be deleted recursively. File backups are created first.`,
      ()=>submitBatch('batch_delete',[rel]),
      {title:'Delete item?',confirmLabel:'Delete',type:'bad'}
    );
  });

  let moveBrowseRel=currentRel;
  async function loadMoveFolder(rel){
    const list=document.getElementById('moveFolderList');
    list.innerHTML='<div class="fm-empty" style="padding:24px">Loading…</div>';
    try{
      const response=await fetch(`${managerUrl}?folder_list=${encodeURIComponent(rel)}`,{credentials:'same-origin'});
      const data=await response.json();
      if(!data.ok) throw new Error(data.error||'Could not load folders.');
      moveBrowseRel=data.rel;
      document.getElementById('moveDestination').value=data.rel;
      document.getElementById('movePath').textContent=data.absolute;
      const up=document.getElementById('moveUpBtn');
      up.disabled=data.parent===null;
      up.dataset.parent=data.parent??'';
      list.innerHTML='';
      if(!data.folders.length){ list.innerHTML='<div class="fm-empty" style="padding:24px">No subfolders here.</div>'; return; }
      data.folders.forEach(folder=>{
        const btn=document.createElement('button');
        btn.type='button'; btn.className='fm-folder-choice';
        btn.innerHTML=`<i class="fa-solid fa-folder"></i><span></span>`;
        btn.querySelector('span').textContent=folder.name;
        btn.addEventListener('click',()=>loadMoveFolder(folder.rel));
        list.appendChild(btn);
      });
    }catch(err){ const message=String(err.message||err); list.innerHTML='<div class="fm-empty" style="padding:24px">Could not load folders.</div>'; showToast(message,{type:'bad',title:'Move failed',duration:6500}); }
  }

  function openMove(items){
    if(!items.length)return;
    fillItemInputs(document.getElementById('moveItems'),items);
    closeModal(document.getElementById('itemModal'));
    openModal('moveModal');
    loadMoveFolder(currentRel);
  }
  document.getElementById('batchMoveBtn')?.addEventListener('click',()=>openMove(selectedItems()));
  document.getElementById('itemMoveBtn')?.addEventListener('click',()=>{ if(activeItem) openMove([activeItem.dataset.rel]); });
  document.getElementById('moveUpBtn')?.addEventListener('click',e=>{ if(!e.currentTarget.disabled) loadMoveFolder(e.currentTarget.dataset.parent||''); });

  const uploadInput=document.getElementById('uploadInput');
  const browseBtn=document.getElementById('browseFilesBtn');
  const dropZone=document.getElementById('dropZone');
  const uploadList=document.getElementById('uploadList');
  const uploadSubmitBtn=document.getElementById('uploadSubmitBtn');

  function renderUploads(){
    if(!uploadInput)return;
    const files=Array.from(uploadInput.files||[]);
    uploadList.innerHTML='';
    files.forEach(file=>{
      const row=document.createElement('div'); row.className='fm-upload-file';
      const name=document.createElement('span'); name.textContent=file.name;
      const size=document.createElement('span'); size.className='muted'; size.textContent=formatBytes(file.size);
      row.append(name,size); uploadList.appendChild(row);
    });
    uploadSubmitBtn.disabled=files.length===0;
  }

  function formatBytes(bytes){
    if(bytes<1024)return `${bytes} B`;
    const units=['KB','MB','GB']; let value=bytes/1024, unit=0;
    while(value>=1024&&unit<units.length-1){value/=1024;unit++;}
    return `${value>=10?value.toFixed(1):value.toFixed(2)} ${units[unit]}`;
  }

  browseBtn?.addEventListener('click',()=>uploadInput.click());
  uploadInput?.addEventListener('change',renderUploads);
  if(dropZone&&uploadInput){
    ['dragenter','dragover'].forEach(type=>dropZone.addEventListener(type,e=>{e.preventDefault();dropZone.classList.add('is-over');}));
    ['dragleave','drop'].forEach(type=>dropZone.addEventListener(type,e=>{e.preventDefault();dropZone.classList.remove('is-over');}));
    dropZone.addEventListener('drop',e=>{
      if(!e.dataTransfer?.files?.length)return;
      const dt=new DataTransfer();
      Array.from(e.dataTransfer.files).forEach(file=>dt.items.add(file));
      uploadInput.files=dt.files;
      renderUploads();
    });
  }

  const editor=document.querySelector('.fm-editor');
  const editorForm=document.getElementById('fmEditorForm');
  if(editor&&editorForm){
    document.body.classList.add('fm-modal-open');
    editor.addEventListener('keydown',function(e){
      if(e.key==='Tab'){
        e.preventDefault();
        const start=this.selectionStart,end=this.selectionEnd;
        this.value=this.value.substring(0,start)+'    '+this.value.substring(end);
        this.selectionStart=this.selectionEnd=start+4;
      }
      if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){
        e.preventDefault(); editorForm.requestSubmit();
      }
    });
  }
  if(document.getElementById('previewModal')) document.body.classList.add('fm-modal-open');
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php';

