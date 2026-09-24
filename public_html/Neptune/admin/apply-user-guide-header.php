<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner']);

$target = dirname(__DIR__) . '/partials_header.php';
$backupRoot = '/var/www/neptune/file-manager-backups';
$message = '';
$error = '';
$applied = false;

function nhh_lint_php(string $path): array {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        return [true, 'PHP lint skipped because exec() is disabled.'];
    }

    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    return [$code === 0, implode("\n", $out)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (!is_file($target) || !is_readable($target)) {
            throw new RuntimeException('Current partials_header.php could not be read.');
        }
        if (!is_writable($target)) {
            throw new RuntimeException('Current partials_header.php is not writable by the web process.');
        }

        $original = (string)file_get_contents($target);
        if ($original === '') {
            throw new RuntimeException('Current partials_header.php is empty or could not be read.');
        }

        if (str_contains($original, 'aria-label="Neptune User Guide"')) {
            $message = 'The Neptune User Guide icon is already installed.';
            $applied = true;
        } else {
            $pattern = '/<(?:button|a)\b[^>]*\bid=["\']themeToggle["\'][^>]*>/is';
            if (!preg_match($pattern, $original, $match, PREG_OFFSET_CAPTURE)) {
                throw new RuntimeException('Could not find the existing themeToggle control. No changes were made.');
            }

            $insertAt = (int)$match[0][1];

            $snippet = <<<'HTML'
<!-- NEPTUNE USER GUIDE HEADER LINK -->
<a
  href="<?=e(base_url('help/'))?>"
  class="neptune-help-link"
  title="Neptune User Guide"
  aria-label="Neptune User Guide"
  style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;padding:0;border:1px solid var(--line);border-radius:8px;background:var(--panel2);color:var(--text);text-decoration:none;flex:0 0 auto"
><i class="fa-solid fa-circle-info" aria-hidden="true"></i></a>

HTML;

            $updated = substr($original, 0, $insertAt) . $snippet . substr($original, $insertAt);

            $tmp = tempnam(sys_get_temp_dir(), 'neptune-header-');
            if ($tmp === false) {
                throw new RuntimeException('Could not create a temporary validation file.');
            }

            try {
                if (file_put_contents($tmp, $updated, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write the temporary validation file.');
                }

                [$lintOk, $lintDetail] = nhh_lint_php($tmp);
                if (!$lintOk) {
                    throw new RuntimeException("The updated header did not pass PHP syntax validation:\n" . $lintDetail);
                }
            } finally {
                @unlink($tmp);
            }

            if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
                throw new RuntimeException('Could not create the Neptune backup directory. No changes were made.');
            }

            $backup = $backupRoot . '/partials_header.php.before-user-guide-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.bak';
            if (!copy($target, $backup)) {
                throw new RuntimeException('Could not back up partials_header.php. No changes were made.');
            }

            if (file_put_contents($target, $updated, LOCK_EX) === false) {
                @copy($backup, $target);
                throw new RuntimeException('Could not update partials_header.php. The backup was restored.');
            }

            @opcache_invalidate($target, true);
            $_SESSION['neptune_help_header_backup'] = $backup;
            $message = 'User Guide icon added to the Neptune header.';
            $applied = true;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$alreadyInstalled = false;
if (is_file($target) && is_readable($target)) {
    $current = (string)file_get_contents($target);
    $alreadyInstalled = str_contains($current, 'aria-label="Neptune User Guide"');
}

$home = base_url('admin/index.php');
$guide = base_url('help/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Neptune Header User Guide Update</title>
<style>
:root{color-scheme:dark}
body{margin:0;background:#081018;color:#f3f7fa;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:760px;margin:0 auto;padding:32px 18px}
.card{background:#101b25;border:1px solid #2b3b49;border-radius:14px;padding:22px}
h1{margin:0 0 8px}p{line-height:1.6;color:#b7c4cf}
.notice{padding:12px 14px;border-radius:9px;margin:14px 0;background:#122b20;border:1px solid #2e7b55}
.notice.bad{background:#30191b;border-color:#9d4148;white-space:pre-wrap}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
button,a.btn{appearance:none;border:1px solid #3c5366;background:#183044;color:#fff;border-radius:9px;padding:10px 14px;font-weight:700;text-decoration:none;cursor:pointer}
button.primary{background:#126fa7;border-color:#1685b5}
small{color:#8395a5}
</style>
</head>
<body>
<main>
  <div class="card">
    <small>NEPTUNE MAINTENANCE UPDATE</small>
    <h1>User Guide header icon</h1>
    <p>This update makes a backup of the current <code>partials_header.php</code>, then surgically inserts a circled information icon immediately before Neptune's existing theme control. The icon links to <code>/help/</code>.</p>

    <?php if ($message): ?><div class="notice"><?=e($message)?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice bad"><?=e($error)?></div><?php endif; ?>

    <?php if (!$alreadyInstalled): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <div class="actions">
          <button class="primary" type="submit">Apply header update</button>
          <a class="btn" href="<?=e($home)?>">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <div class="actions">
        <a class="btn" href="<?=e($home)?>">Command Center</a>
        <a class="btn" href="<?=e($guide)?>">Open User Guide</a>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
