<?php
declare(strict_types=1);

require_once __DIR__ . '/google-auth.php';
require_once __DIR__ . '/platform-mfa.php';

function neptune_platform_strong_auth_required_page(array $user): never {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $esc=static fn(string $value): string =>
        htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

    $logoutUrl=$esc(base_url('logout.php'));
    $homeUrl=$esc(base_url('dashboard/index.php'));
    $logoUrl=$esc(base_url('images/logo.png'));
    $displayName=$esc((string)($user['display_name']??$user['username']??'Platform owner'));

    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Strong Authentication Required · Neptune</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#070b12;color:#eef4ff;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.card{width:min(700px,100%);background:#101722;border:1px solid #273449;border-radius:22px;padding:32px;box-shadow:0 24px 70px rgba(0,0,0,.38)}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:24px}.brand img{width:54px;height:54px;object-fit:contain}
.kicker{font-size:.78rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:#8fb9ff}
h1{font-size:clamp(1.7rem,4vw,2.35rem);line-height:1.08;margin:8px 0 14px}
p{line-height:1.65;color:#c4cfdf;margin:0 0 15px}
.user{display:inline-flex;align-items:center;margin:4px 0 18px;padding:8px 12px;border:1px solid #33445f;border-radius:999px;background:#0b111b;color:#e7eef9;font-weight:700}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border-radius:11px;text-decoration:none;font-weight:800;background:#edf4ff;color:#07101d;border:1px solid #edf4ff}
.btn.secondary{background:transparent;color:#dce8f8;border-color:#40536f}
.note{font-size:.92rem;color:#94a3b8;margin-top:20px}
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="'.$logoUrl.'" alt="">
    <div><div class="kicker">Neptune Security</div><strong>Platform Owner Protection</strong></div>
  </div>

  <h1>Strong authentication is required for this area.</h1>
  <div class="user">'.$displayName.'</div>
  <p>This Platform Owner session has not completed the extra authentication required for File Manager, Maintenance Console, Platform Administration, and other high-value platform tools.</p>
  <p>Sign out and sign in again. A password login will require your authenticator code. Google sign-in also satisfies this requirement.</p>

  <div class="actions">
    <a class="btn" href="'.$logoutUrl.'">Sign out</a>
    <a class="btn secondary" href="'.$homeUrl.'">Back to Neptune</a>
  </div>

  <div class="note">HTTP 403 · Access was intentionally blocked; this is not an application error.</div>
</main>
</body>
</html>';
    exit;
}

/**
 * Kept under the original function name so existing protected pages remain
 * compatible. The rule is now Google OR password + TOTP/recovery code.
 */
function neptune_require_platform_owner_google(array $user): void {
    global $config;

    if(!neptune_platform_owner_is($user))return;

    $mode=strtolower(trim((string)($config['app']['platform_owner_auth']??'strong')));
    if($mode==='google'){
        if(strtolower((string)($user['auth_method']??''))==='google')return;
        neptune_platform_strong_auth_required_page($user);
    }

    // "strong" is the Public 1.0 default. Historical "auto" and "password"
    // values are treated as strong rather than allowing password-only access.
    if(neptune_platform_mfa_session_is_strong($user))return;

    neptune_platform_strong_auth_required_page($user);
}
