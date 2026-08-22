<?php
require_once dirname(__DIR__, 2) . '/neptune_secure/bootstrap.php';

if ($existingUser = current_user()) {
    header('Location: ' . base_url(!empty($existingUser['must_change_password']) ? 'change-password.php' : 'dashboard/index.php'));
    exit;
}

$error = '';
$selectedOrganizationId = isset($_POST['organization_id']) ? (int)$_POST['organization_id'] : 0;

$organizations = $pdo->query(
    'SELECT id, name, slug FROM organizations ORDER BY name ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $organizationId = (int)($_POST['organization_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($organizationId <= 0 || $username === '' || $password === '') {
        $error = 'Select an organization and enter your username and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT
                u.*,
                o.name AS organization_name,
                o.slug AS organization_slug
             FROM users u
             JOIN organizations o ON o.id = u.organization_id
             WHERE u.organization_id = ?
               AND u.username = ?
               AND u.active = 1
             LIMIT 1'
        );
        $stmt->execute([$organizationId, $username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => $row['id'],
                'organization_id' => $row['organization_id'],
                'organization_name' => $row['organization_name'],
                'organization_slug' => $row['organization_slug'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'role' => $row['role'],
                'must_change_password' => (int)($row['must_change_password'] ?? 0),
            ];

            $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$row['id']]);

            header('Location: ' . base_url(!empty($row['must_change_password']) ? 'change-password.php' : 'dashboard/index.php'));
            exit;
        }

        $error = 'Invalid organization, username, or password.';
    }
}

$pageTitle = 'Login';
include __DIR__ . '/partials_header.php';
?>
<div class="card auth-card">
    <div class="auth-brand">
        <img src="<?= e(base_url('images/logo.png')) ?>" alt="Neptune" class="auth-logo">
    </div>
    <h1 class="auth-title">Sign in</h1>
    <p class="muted auth-subtitle">FRC scouting, command, and intelligence.</p>

    <?php if ($error): ?>
        <div class="notice"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$organizations): ?>
        <div class="notice">
            Neptune has not been initialized yet.
            <a href="<?= e(base_url('admin/install.php')) ?>">Run first-time setup</a>.
        </div>
    <?php else: ?>
        <form method="post" autocomplete="on">
            <label for="organization_id">Organization</label>
            <select id="organization_id" name="organization_id" required>
                <option value="">Select organization…</option>
                <?php foreach ($organizations as $organization): ?>
                    <option
                        value="<?= (int)$organization['id'] ?>"
                        <?= $selectedOrganizationId === (int)$organization['id'] ? 'selected' : '' ?>
                    ><?= e($organization['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="username">Username</label>
            <input id="username" name="username" required autocomplete="username">

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">

            <div class="toolbar">
                <button type="submit">Sign in</button>
            </div>
        </form>
        <div class="public-links"><a href="<?= e(base_url('register.php')) ?>"><i class="fa-solid fa-building-circle-check"></i> Register another organization</a></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php';
