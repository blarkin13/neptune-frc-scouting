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

$pageTitle = 'Neptune | FRC Scouting Platform';

include __DIR__ . '/partials_header.php';
include __DIR__ . '/landing.php';
include __DIR__ . '/partials_footer.php';
