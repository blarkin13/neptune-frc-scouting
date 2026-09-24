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

$pageTitle = 'Font Awesome Upgrade';
$moduleName = 'SATURN';

const NFA_VERSION = '7.3.1';
const NFA_WEB_SHA256 = '35bc95eb1e5f9f04902ba2f5b2d04bf72178fa5bb4b5b5d08deb10115e3eb465';
const NFA_APP_ROOT = '/var/www/neptune/public_html/Neptune';
const NFA_VENDOR_ROOT = NFA_APP_ROOT . '/assets/vendor/fontawesome';
const NFA_BACKUP_ROOT = '/var/www/neptune/maintenance-backups/fontawesome';

function nfa_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nfa_current_version(): string {
    $css = NFA_VENDOR_ROOT . '/css/all.min.css';
    if (!is_readable($css)) return 'Not installed';
    $head = (string)file_get_contents($css, false, null, 0, 1024);
    if (preg_match('/Font Awesome Free\s+([0-9.]+)/i', $head, $m)) return $m[1];
    return 'Unknown';
}

function nfa_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) throw new RuntimeException('Could not remove ' . $path);
        return;
    }
    $items = scandir($path);
    if ($items === false) throw new RuntimeException('Could not read ' . $path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        nfa_remove_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    if (!@rmdir($path)) throw new RuntimeException('Could not remove directory ' . $path);
}

function nfa_copy_tree(string $from, string $to): void {
    if (!is_dir($from)) throw new RuntimeException('Missing staging directory: ' . $from);
    if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
        throw new RuntimeException('Could not create ' . $to);
    }
    $items = scandir($from);
    if ($items === false) throw new RuntimeException('Could not read ' . $from);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $from . DIRECTORY_SEPARATOR . $item;
        $dst = $to . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) nfa_copy_tree($src, $dst);
        elseif (!@copy($src, $dst)) throw new RuntimeException('Could not install ' . $dst);
    }
}

function nfa_download(string $url, string $dest): void {
    $fp = @fopen($dest, 'wb');
    if (!$fp) throw new RuntimeException('Could not create temporary download file.');
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_USERAGENT => 'Neptune-FRC/' . NFA_VERSION . ' FontAwesome-Upgrader',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (!$ok) throw new RuntimeException('Download failed' . ($code ? ' (HTTP ' . $code . ')' : '') . ': ' . $err);
            return;
        }
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('PHP cURL is unavailable and allow_url_fopen is disabled.');
        }
        $ctx = stream_context_create(['http' => [
            'follow_location' => 1,
            'timeout' => 180,
            'user_agent' => 'Neptune-FRC/' . NFA_VERSION . ' FontAwesome-Upgrader',
        ]]);
        $src = @fopen($url, 'rb', false, $ctx);
        if (!$src) throw new RuntimeException('Could not open remote Font Awesome package.');
        stream_copy_to_stream($src, $fp);
        fclose($src);
    } finally {
        fclose($fp);
    }
}

function nfa_download_with_fallback(array $urls, string $dest): string {
    $errors = [];
    foreach ($urls as $url) {
        try {
            @unlink($dest);
            nfa_download($url, $dest);
            if (!is_file($dest) || filesize($dest) < 1024) throw new RuntimeException('Downloaded file was unexpectedly small.');
            return $url;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    throw new RuntimeException('All Font Awesome download sources failed: ' . implode(' | ', $errors));
}

function nfa_zip_add_tree(ZipArchive $zip, string $root, string $prefix = ''): void {
    $items = scandir($root);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $root . DIRECTORY_SEPARATOR . $item;
        $rel = $prefix === '' ? $item : $prefix . '/' . $item;
        if (is_dir($path)) {
            nfa_zip_add_tree($zip, $path, $rel);
        } elseif (is_file($path)) {
            $zip->addFile($path, $rel);
        }
    }
}

function nfa_backup_current(): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is required.');
    if (!is_dir(NFA_VENDOR_ROOT)) throw new RuntimeException('Current Font Awesome directory is missing.');
    if (!is_dir(NFA_BACKUP_ROOT) && !@mkdir(NFA_BACKUP_ROOT, 0775, true) && !is_dir(NFA_BACKUP_ROOT)) {
        throw new RuntimeException('Could not create Font Awesome backup directory.');
    }
    $version = preg_replace('/[^0-9A-Za-z._-]+/', '-', nfa_current_version());
    $path = NFA_BACKUP_ROOT . '/fontawesome-' . $version . '-' . gmdate('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create Font Awesome rollback ZIP.');
    }
    nfa_zip_add_tree($zip, NFA_VENDOR_ROOT);
    $zip->close();
    if (!is_file($path) || filesize($path) < 1024) throw new RuntimeException('Font Awesome backup verification failed.');
    return $path;
}

function nfa_latest_backup(): ?string {
    $files = glob(NFA_BACKUP_ROOT . '/fontawesome-*.zip') ?: [];
    if (!$files) return null;
    usort($files, static fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    return $files[0] ?? null;
}

function nfa_extract_archive(string $zipPath, string $stage): array {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('Could not open downloaded Font Awesome ZIP.');
    $root = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (str_ends_with($name, '/css/all.min.css')) {
            $root = substr($name, 0, -strlen('css/all.min.css'));
            break;
        }
    }
    if ($root === '') {
        $zip->close();
        throw new RuntimeException('Font Awesome archive layout was not recognized.');
    }
    @mkdir($stage . '/css', 0775, true);
    @mkdir($stage . '/webfonts', 0775, true);
    $copiedFonts = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!str_starts_with($name, $root)) continue;
        $rel = substr($name, strlen($root));
        if ($rel === 'css/all.min.css' || $rel === 'LICENSE.txt') {
            $data = $zip->getFromIndex($i);
            if ($data === false) continue;
            $dest = $stage . '/' . $rel;
            @mkdir(dirname($dest), 0775, true);
            file_put_contents($dest, $data);
        } elseif (str_starts_with($rel, 'webfonts/') && !str_ends_with($rel, '/')) {
            $base = basename($rel);
            if (!preg_match('/\.(?:woff2|ttf)$/i', $base)) continue;
            $data = $zip->getFromIndex($i);
            if ($data === false) continue;
            file_put_contents($stage . '/webfonts/' . $base, $data);
            $copiedFonts++;
        }
    }
    $zip->close();
    $css = $stage . '/css/all.min.css';
    if (!is_readable($css) || !str_contains((string)file_get_contents($css, false, null, 0, 1024), 'Font Awesome Free ' . NFA_VERSION)) {
        throw new RuntimeException('Downloaded stylesheet did not identify itself as Font Awesome Free ' . NFA_VERSION . '.');
    }
    foreach (['fa-solid-900.woff2','fa-regular-400.woff2','fa-brands-400.woff2'] as $required) {
        if (!is_file($stage . '/webfonts/' . $required)) throw new RuntimeException('Required webfont missing from package: ' . $required);
    }
    file_put_contents($stage . '/VERSION.txt', NFA_VERSION . "\n");
    return [$root, $copiedFonts];
}

function nfa_parse_categories(string $yaml): array {
    $current = null;
    $label = null;
    $icons = [];
    $byIcon = [];
    $flush = static function() use (&$current, &$label, &$icons, &$byIcon): void {
        if ($current === null) return;
        $finalLabel = $label ?: ucwords(str_replace('-', ' ', $current));
        foreach ($icons as $icon) $byIcon[$icon][] = $finalLabel;
        $current = null; $label = null; $icons = [];
    };
    foreach (preg_split('/\R/', $yaml) as $line) {
        if (preg_match('/^([a-z0-9-]+):\s*$/i', $line, $m)) {
            $flush(); $current = $m[1]; continue;
        }
        if ($current === null) continue;
        if (preg_match('/^\s{2}label:\s*(.+?)\s*$/', $line, $m)) {
            $label = trim($m[1], " \t\"'"); continue;
        }
        if (preg_match('/^\s{4}-\s+(.+?)\s*$/', $line, $m)) {
            $icons[] = trim($m[1], " \t\"'");
        }
    }
    $flush();
    foreach ($byIcon as &$cats) {
        $cats = array_values(array_unique($cats));
        natcasesort($cats); $cats = array_values($cats);
    }
    unset($cats);
    return $byIcon;
}

function nfa_build_catalog(string $iconsJson, string $categoriesYaml): array {
    $raw = json_decode($iconsJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($raw)) throw new RuntimeException('Font Awesome metadata was not a JSON object.');
    $categoryMap = nfa_parse_categories($categoriesYaml);
    $icons = [];
    $allCategories = [];
    $styleOptions = 0;
    foreach ($raw as $name => $meta) {
        if (!is_array($meta)) continue;
        $styles = $meta['free'] ?? [];
        if (!is_array($styles) || !$styles) continue;
        $styles = array_values(array_intersect(['solid','regular','brands'], array_map('strval', $styles)));
        if (!$styles) continue;
        $terms = $meta['search']['terms'] ?? [];
        if (!is_array($terms)) $terms = [];
        $aliases = [];
        if (isset($meta['aliases']['names']) && is_array($meta['aliases']['names'])) {
            $aliases = $meta['aliases']['names'];
        } elseif (isset($meta['aliases']) && is_array($meta['aliases']) && array_is_list($meta['aliases'])) {
            $aliases = $meta['aliases'];
        }
        $cats = $categoryMap[(string)$name] ?? [];
        foreach ($cats as $cat) $allCategories[$cat] = true;
        $icons[] = [
            'name' => (string)$name,
            'label' => (string)($meta['label'] ?? ucwords(str_replace('-', ' ', (string)$name))),
            'styles' => $styles,
            'terms' => array_values(array_unique(array_map('strval', $terms))),
            'aliases' => array_values(array_unique(array_map('strval', $aliases))),
            'categories' => $cats,
        ];
        $styleOptions += count($styles);
    }
    usort($icons, static fn($a, $b) => strnatcasecmp($a['label'], $b['label']) ?: strnatcasecmp($a['name'], $b['name']));
    $categories = array_keys($allCategories);
    natcasesort($categories); $categories = array_values($categories);
    return [
        'version' => NFA_VERSION,
        'count' => count($icons),
        'styleOptions' => $styleOptions,
        'icons' => $icons,
        'categories' => $categories,
    ];
}

function nfa_upgrade(): array {
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is required.');
    $tmp = sys_get_temp_dir() . '/neptune-fa-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0775, true) && !is_dir($tmp)) throw new RuntimeException('Could not create temporary upgrade directory.');
    try {
        $zipPath = $tmp . '/fontawesome-web.zip';
        $source = nfa_download_with_fallback([
            'https://github.com/FortAwesome/Font-Awesome/releases/download/' . NFA_VERSION . '/fontawesome-free-' . NFA_VERSION . '-web.zip',
            'https://downloads.sourceforge.net/project/font-awesome.mirror/' . NFA_VERSION . '/fontawesome-free-' . NFA_VERSION . '-web.zip',
        ], $zipPath);
        $hash = hash_file('sha256', $zipPath);
        if (!hash_equals(NFA_WEB_SHA256, (string)$hash)) {
            throw new RuntimeException('Font Awesome package checksum mismatch. Expected ' . NFA_WEB_SHA256 . ' but received ' . $hash . '.');
        }
        $stage = $tmp . '/stage';
        @mkdir($stage, 0775, true);
        [, $fontCount] = nfa_extract_archive($zipPath, $stage);

        $iconsPath = $tmp . '/icons.json';
        $categoriesPath = $tmp . '/categories.yml';
        nfa_download('https://raw.githubusercontent.com/FortAwesome/Font-Awesome/' . NFA_VERSION . '/metadata/icons.json', $iconsPath);
        nfa_download('https://raw.githubusercontent.com/FortAwesome/Font-Awesome/' . NFA_VERSION . '/metadata/categories.yml', $categoriesPath);
        $catalog = nfa_build_catalog((string)file_get_contents($iconsPath), (string)file_get_contents($categoriesPath));
        file_put_contents(
            $stage . '/icon-catalog.json',
            json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        $backup = nfa_backup_current();
        if (is_dir(NFA_VENDOR_ROOT . '/css')) nfa_remove_tree(NFA_VENDOR_ROOT . '/css');
        if (is_dir(NFA_VENDOR_ROOT . '/webfonts')) nfa_remove_tree(NFA_VENDOR_ROOT . '/webfonts');
        if (!is_dir(NFA_VENDOR_ROOT) && !@mkdir(NFA_VENDOR_ROOT, 0775, true) && !is_dir(NFA_VENDOR_ROOT)) {
            throw new RuntimeException('Could not create Font Awesome vendor directory.');
        }
        nfa_copy_tree($stage, NFA_VENDOR_ROOT);
        clearstatcache(true, NFA_VENDOR_ROOT . '/css/all.min.css');
        if (function_exists('opcache_reset')) @opcache_reset();
        return [
            'source' => $source,
            'backup' => $backup,
            'icons' => (int)$catalog['count'],
            'styles' => (int)$catalog['styleOptions'],
            'fonts' => $fontCount,
        ];
    } finally {
        try { nfa_remove_tree($tmp); } catch (Throwable) {}
    }
}

function nfa_rollback(): string {
    $backup = nfa_latest_backup();
    if (!$backup) throw new RuntimeException('No Font Awesome rollback backup was found.');
    $tmp = sys_get_temp_dir() . '/neptune-fa-rollback-' . bin2hex(random_bytes(5));
    @mkdir($tmp, 0775, true);
    try {
        $zip = new ZipArchive();
        if ($zip->open($backup) !== true) throw new RuntimeException('Could not open rollback backup.');
        if (!$zip->extractTo($tmp)) { $zip->close(); throw new RuntimeException('Could not extract rollback backup.'); }
        $zip->close();
        if (!is_file($tmp . '/css/all.min.css')) throw new RuntimeException('Rollback backup is missing all.min.css.');
        if (is_dir(NFA_VENDOR_ROOT)) nfa_remove_tree(NFA_VENDOR_ROOT);
        @mkdir(NFA_VENDOR_ROOT, 0775, true);
        nfa_copy_tree($tmp, NFA_VENDOR_ROOT);
        if (function_exists('opcache_reset')) @opcache_reset();
        return $backup;
    } finally {
        try { nfa_remove_tree($tmp); } catch (Throwable) {}
    }
}

$message = null;
$error = null;
$details = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upgrade') {
            if (nfa_current_version() === NFA_VERSION) {
                $message = 'Font Awesome Free ' . NFA_VERSION . ' is already installed.';
            } else {
                $result = nfa_upgrade();
                $message = 'Font Awesome Free ' . NFA_VERSION . ' installed successfully.';
                $details[] = 'Local icon catalog: ' . number_format($result['icons']) . ' free icons / ' . number_format($result['styles']) . ' style options';
                $details[] = 'Installed webfont files: ' . number_format($result['fonts']);
                $details[] = 'Rollback backup: ' . $result['backup'];
            }
        } elseif ($action === 'rollback') {
            $backup = nfa_rollback();
            $message = 'Font Awesome rollback completed.';
            $details[] = 'Restored: ' . $backup;
        } else {
            throw new RuntimeException('Unknown Font Awesome maintenance action.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$currentVersion = nfa_current_version();
$latestBackup = nfa_latest_backup();
$catalogPath = NFA_VENDOR_ROOT . '/icon-catalog.json';
$catalogInfo = null;
if (is_readable($catalogPath)) {
    $decoded = json_decode((string)file_get_contents($catalogPath), true);
    if (is_array($decoded)) $catalogInfo = $decoded;
}

include dirname(__DIR__) . '/partials_header.php';
?>
<section class="page-head">
  <div>
    <div class="module-eyebrow"><span>SATURN</span><small>Maintenance</small></div>
    <h1>Font Awesome Upgrade</h1>
    <p>Upgrade Neptune's locally hosted Font Awesome Free files and Spot Tag Manager icon catalog.</p>
  </div>
</section>

<?php if ($message): ?>
<div class="notice good"><b><?=nfa_e($message)?></b><?php foreach ($details as $detail): ?><br><?=nfa_e($detail)?><?php endforeach;?></div>
<?php endif; ?>
<?php if ($error): ?><div class="notice bad"><b>Upgrade failed.</b><br><?=nfa_e($error)?></div><?php endif; ?>

<div class="card" style="max-width:980px">
  <div class="toolbar" style="justify-content:space-between;align-items:flex-start;gap:18px">
    <div>
      <h2 style="margin-top:0">Local Font Awesome</h2>
      <p class="muted">Neptune continues to load Font Awesome from <code>assets/vendor/fontawesome</code>; no CDN dependency is added.</p>
    </div>
    <span class="pill"><i class="fa-solid fa-icons"></i> <?=nfa_e($currentVersion)?></span>
  </div>

  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr));margin-top:16px">
    <div class="card"><span class="muted">Installed version</span><h3><?=nfa_e($currentVersion)?></h3></div>
    <div class="card"><span class="muted">Target version</span><h3><?=NFA_VERSION?></h3></div>
    <div class="card"><span class="muted">Icon picker catalog</span><h3><?=is_array($catalogInfo)?number_format((int)($catalogInfo['count']??0)).' icons':'Not found'?></h3></div>
    <div class="card"><span class="muted">Rollback backup</span><h3><?=$latestBackup?'Available':'None yet'?></h3></div>
  </div>

  <div class="notice" style="margin-top:18px">
    <b>Compatibility protection:</b> Neptune keeps Font Awesome 6-style automatic icon widths through a local compatibility stylesheet, while explicit <code>fa-fw</code> icons remain fixed width. Neptune also supplies its own <code>sr-only</code> helper because Font Awesome 7 removed it.
  </div>

  <div class="toolbar" style="margin-top:18px">
    <form method="post" data-confirm="Download the verified Font Awesome Free 7.3.1 web package, back up the current local Font Awesome folder, install 7.3.1, and rebuild the Spot icon catalog?" data-confirm-title="Upgrade Font Awesome" data-confirm-button="Upgrade">
      <input type="hidden" name="csrf" value="<?=nfa_e(csrf_token())?>">
      <input type="hidden" name="action" value="upgrade">
      <button class="good" type="submit" <?=$currentVersion===NFA_VERSION?'disabled':''?>><i class="fa-solid fa-cloud-arrow-down"></i> Upgrade to 7.3.1</button>
    </form>
    <?php if ($latestBackup): ?>
    <form method="post" data-confirm="Restore the most recent Font Awesome backup? This replaces the currently installed local Font Awesome files." data-confirm-title="Rollback Font Awesome" data-confirm-button="Rollback" data-confirm-danger="1">
      <input type="hidden" name="csrf" value="<?=nfa_e(csrf_token())?>">
      <input type="hidden" name="action" value="rollback">
      <button class="secondary" type="submit"><i class="fa-solid fa-rotate-left"></i> Roll Back</button>
    </form>
    <?php endif; ?>
    <a class="btn secondary" href="<?=nfa_e(base_url('admin/maintenance.php'))?>"><i class="fa-solid fa-arrow-left"></i> Maintenance</a>
  </div>

  <hr style="margin:22px 0">
  <h3>What the upgrade does</h3>
  <ul>
    <li>Downloads the official Font Awesome Free <?=NFA_VERSION?> WebFonts + CSS release.</li>
    <li>Verifies SHA-256 <code><?=NFA_WEB_SHA256?></code> before installing anything.</li>
    <li>Creates a rollback ZIP under <code>/var/www/neptune/maintenance-backups/fontawesome/</code>.</li>
    <li>Replaces the local CSS and webfonts without changing Neptune's icon class names.</li>
    <li>Downloads Font Awesome <?=NFA_VERSION?> metadata and rebuilds the local Spot Tag Manager icon catalog.</li>
  </ul>
</div>
<?php include dirname(__DIR__) . '/partials_footer.php'; ?>
