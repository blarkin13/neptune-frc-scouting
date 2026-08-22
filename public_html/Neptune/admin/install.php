<?php
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';

$count = (int)$pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
if ($count > 0) {
    http_response_code(403);
    exit('Installer is locked because an organization already exists.');
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orgName = trim($_POST['organization'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($orgName === '' || $displayName === '' || $username === '' || strlen($password) < 10) {
        $msg = 'Complete all fields. Passwords must be at least 10 characters.';
    } else {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $orgName), '-'));

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO organizations(name, slug) VALUES(?, ?)')
                ->execute([$orgName, $slug]);
            $organizationId = $pdo->lastInsertId();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare(
                "INSERT INTO users(organization_id, username, password_hash, display_name, role, must_change_password, password_changed_at)
                 VALUES(?, ?, ?, ?, 'owner', 0, UTC_TIMESTAMP())"
            )->execute([$organizationId, $username, $hash, $displayName]);

            $pdo->commit();

            foreach (glob(dirname(__DIR__) . '/games/*.json') ?: [] as $gameFile) {
                $raw = file_get_contents($gameFile);
                $json = json_decode($raw, true);
                if (!is_array($json) || empty($json['game'])) {
                    continue;
                }

                $name = (string)$json['game'];
                preg_match('/(20\d{2})/', $name, $matches);
                $year = isset($matches[1]) ? (int)$matches[1] : (int)date('Y');
                $gameSlug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

                $pdo->prepare(
                    'INSERT INTO games(name, season_year, slug, json_filename, config_json)
                     VALUES(?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        config_json = VALUES(config_json),
                        json_filename = VALUES(json_filename)'
                )->execute([$name, $year, $gameSlug, basename($gameFile), $raw]);
            }

            $msg = 'Installed. Your organization and owner account are ready. Existing game JSON files were registered. Delete or rename admin/install.php now.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$pageTitle = 'Install';
include dirname(__DIR__) . '/partials_header.php';
?>
<div class="card setup-card">
    <div class="setup-brand">
        <img src="<?= e(base_url('images/logo.png')) ?>" alt="Neptune" class="setup-logo">
    </div>
    <h1>First Run</h1>

    <?php if ($msg): ?>
        <div class="notice"><?= e($msg) ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Organization</label>
        <input name="organization" required>

        <label>Owner display name</label>
        <input name="display_name" required>

        <label>Owner username</label>
        <input name="username" required autocomplete="username">

        <label>Password</label>
        <input type="password" name="password" required minlength="10" autocomplete="new-password">

        <div class="toolbar">
            <button type="submit">Initialize Neptune</button>
        </div>
    </form>
</div>
<?php include dirname(__DIR__) . '/partials_footer.php';
