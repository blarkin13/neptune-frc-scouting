<?php
declare(strict_types=1);

/**
 * Neptune Google/OIDC + user lifecycle helpers.
 *
 * Google authenticates identity only. Neptune's users table remains the source
 * of truth for organization membership, active status, team assignment, and role.
 */

function neptune_auth_env(): array {
    static $env = null;
    if (is_array($env)) return $env;
    $env = [];
    $path = '/etc/scout/neptune.env';
    if (is_readable($path)) {
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (is_array($parsed)) $env = $parsed;
    }
    return $env;
}

function neptune_google_settings(): array {
    $env = neptune_auth_env();
    return [
        'client_id' => trim((string)($env['GOOGLE_OAUTH_CLIENT_ID'] ?? '')),
        'client_secret' => trim((string)($env['GOOGLE_OAUTH_CLIENT_SECRET'] ?? '')),
        'redirect_uri' => trim((string)($env['GOOGLE_OAUTH_REDIRECT_URI'] ?? '')),
    ];
}

function neptune_google_expected_redirect_uri(): string {
    $settings = neptune_google_settings();
    if ($settings['redirect_uri'] !== '') return $settings['redirect_uri'];

    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $proto = $https ? 'https' : 'http';
    return $proto . '://' . $host . base_url('auth/google-callback.php');
}

function neptune_google_enabled(): bool {
    $s = neptune_google_settings();
    return $s['client_id'] !== '' && $s['client_secret'] !== '' && neptune_google_expected_redirect_uri() !== '';
}

function neptune_auth_column_exists(PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $s->execute([$table, $column]);
    return (int)$s->fetchColumn() > 0;
}

function neptune_auth_index_exists(PDO $pdo, string $table, string $index): bool {
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
    );
    $s->execute([$table, $index]);
    return (int)$s->fetchColumn() > 0;
}

function neptune_auth_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    if (function_exists('neptune_platform_ensure_schema')) neptune_platform_ensure_schema($pdo);

    neptune_migration_apply(
        $pdo,
        'auth.google-membership-invites.v1',
        'Neptune_Google_Routing_Invites_Auth_v4_Maintenance_Console.zip',
        function() use ($pdo): void {
            if (!neptune_auth_column_exists($pdo, 'users', 'google_sub')) {
                $pdo->exec('ALTER TABLE users ADD COLUMN google_sub VARCHAR(255) NULL AFTER email');
            }
            if (!neptune_auth_column_exists($pdo, 'users', 'google_email')) {
                $pdo->exec('ALTER TABLE users ADD COLUMN google_email VARCHAR(190) NULL AFTER google_sub');
            }
            if (!neptune_auth_column_exists($pdo, 'users', 'google_linked_at')) {
                $pdo->exec('ALTER TABLE users ADD COLUMN google_linked_at DATETIME NULL AFTER google_email');
            }
            if (!neptune_auth_index_exists($pdo, 'users', 'uq_org_google_sub')) {
                $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_org_google_sub (organization_id, google_sub)');
            }

            if (!neptune_auth_column_exists($pdo, 'organizations', 'google_signin_mode')) {
                $pdo->exec("ALTER TABLE organizations ADD COLUMN google_signin_mode VARCHAR(16) NOT NULL DEFAULT 'optional'");
            }
            if (!neptune_auth_column_exists($pdo, 'organizations', 'google_workspace_domain')) {
                $pdo->exec('ALTER TABLE organizations ADD COLUMN google_workspace_domain VARCHAR(190) NULL');
            }
            if (!neptune_auth_column_exists($pdo, 'organizations', 'google_auto_provision')) {
                $pdo->exec('ALTER TABLE organizations ADD COLUMN google_auto_provision TINYINT(1) NOT NULL DEFAULT 0');
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS neptune_auth_invites (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                organization_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                label VARCHAR(120) NULL,
                invite_email VARCHAR(190) NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'scout',
                created_by_user_id BIGINT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                used_by_user_id BIGINT UNSIGNED NULL,
                revoked_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_neptune_auth_invite_token (token_hash),
                KEY idx_neptune_auth_invite_org_status (organization_id,expires_at,used_at,revoked_at),
                CONSTRAINT fk_neptune_auth_invite_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                CONSTRAINT fk_neptune_auth_invite_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_neptune_auth_invite_used_by FOREIGN KEY (used_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!neptune_auth_column_exists($pdo, 'neptune_auth_invites', 'invite_email')) {
                $pdo->exec('ALTER TABLE neptune_auth_invites ADD COLUMN invite_email VARCHAR(190) NULL AFTER label');
            }
        }
    );

    $done = true;
}

function neptune_google_normalize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = ltrim($domain, '@');
    $domain = rtrim($domain, '.');
    if ($domain === '') return '';
    if (strlen($domain) > 190 || !preg_match('/^(?=.{1,190}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        throw new RuntimeException('Enter a valid Workspace domain, such as example.org.');
    }
    return $domain;
}

function neptune_google_org_policy(PDO $pdo, int $organizationId): array {
    neptune_auth_ensure_schema($pdo);
    $s = $pdo->prepare('SELECT id,name,slug,platform_status,google_signin_mode,google_workspace_domain,google_auto_provision FROM organizations WHERE id=? LIMIT 1');
    $s->execute([$organizationId]);
    $row = $s->fetch();
    if (!$row) throw new RuntimeException('Organization not found.');
    if (strtolower((string)($row['platform_status'] ?? 'active')) !== 'active') throw new RuntimeException('This Neptune organization is suspended.');
    $mode = strtolower(trim((string)($row['google_signin_mode'] ?? 'optional')));
    if (!in_array($mode, ['disabled','optional','required'], true)) $mode = 'optional';
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'slug' => (string)$row['slug'],
        'mode' => $mode,
        'workspace_domain' => strtolower(trim((string)($row['google_workspace_domain'] ?? ''))),
        'auto_provision' => !empty($row['google_auto_provision']),
    ];
}

function neptune_google_username_for_email(PDO $pdo, int $organizationId, string $email): string {
    $local = strtolower((string)strtok($email, '@'));
    $base = preg_replace('/[^a-z0-9._-]+/', '.', $local) ?: 'scout';
    $base = trim($base, '._-');
    if (strlen($base) < 2) $base = 'scout.' . $base;
    $base = substr($base, 0, 64);
    $candidate = $base;
    $n = 2;
    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE organization_id=? AND username=?');
    while (true) {
        $check->execute([$organizationId, $candidate]);
        if ((int)$check->fetchColumn() === 0) return $candidate;
        $suffix = '.' . $n++;
        $candidate = substr($base, 0, max(2, 80 - strlen($suffix))) . $suffix;
        if ($n > 9999) throw new RuntimeException('Could not create a unique Neptune username.');
    }
}

function neptune_auth_audit(PDO $pdo, ?int $orgId, ?int $userId, string $action, string $entityType='user', ?string $entityId=null, array $meta=[]): void {
    try {
        $s = $pdo->prepare(
            'INSERT INTO audit_log (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address,created_at)
             VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $s->execute([
            $orgId ?: null,
            $userId ?: null,
            $action,
            $entityType ?: null,
            $entityId,
            $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $ignored) {
        // Auditing must never block authentication or account recovery.
    }
}

function neptune_start_user_session(PDO $pdo, array $row, string $method='password'): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'organization_id' => (int)$row['organization_id'],
        'organization_name' => (string)$row['organization_name'],
        'organization_slug' => (string)$row['organization_slug'],
        'username' => (string)$row['username'],
        'display_name' => (string)$row['display_name'],
        'role' => (string)$row['role'],
        // Google authentication is already strong authentication. A pending
        // temporary local password should not block a Google-authenticated session.
        'must_change_password' => $method === 'google' ? 0 : (int)($row['must_change_password'] ?? 0),
        'auth_method' => $method,
    ];
    $orgId=(int)$row['organization_id'];
    if (!headers_sent()) {
        setcookie('neptune_last_org',(string)$orgId,[
            'expires'=>time()+31536000,
            'path'=>base_url('') ?: '/',
            'secure'=>!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off',
            'httponly'=>true,
            'samesite'=>'Lax',
        ]);
    }
    $pdo->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$row['id']]);
}

function neptune_random_temp_password(): string {
    $raw = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'AZ'), '=');
    return 'Npt-' . substr($raw, 0, 16) . '!7';
}

function neptune_google_http(string $url, string $method='GET', array $form=[], array $headers=[]): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Google sign-in requires the PHP cURL extension.');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '') throw new RuntimeException('Google sign-in network error.');
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $endpoint = (string)(parse_url($url, PHP_URL_HOST) ?: 'google');
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $googleError = is_array($json) ? trim((string)($json['error'] ?? '')) : '';
        $googleDescription = is_array($json) ? trim((string)($json['error_description'] ?? $json['error_description'] ?? '')) : '';
        // Do not log request form data, authorization codes, client secrets, access tokens, or response bodies.
        error_log(sprintf(
            'Neptune Google OAuth HTTP error: endpoint=%s%s status=%d error=%s description=%s',
            $endpoint,
            $path,
            $status,
            $googleError !== '' ? substr($googleError, 0, 80) : 'none',
            $googleDescription !== '' ? substr(preg_replace('/\s+/', ' ', $googleDescription), 0, 240) : 'none'
        ));
        $detail = $googleError !== '' ? $googleError : ('HTTP '.$status);
        if ($googleDescription !== '') $detail .= ': '.$googleDescription;
        throw new RuntimeException('Google sign-in returned an unexpected response ('.$detail.').');
    }
    return $json;
}

function neptune_google_membership_select_sql(): string {
    return "SELECT u.*,o.name organization_name,o.slug organization_slug,o.logo_path organization_logo_path,o.google_signin_mode,o.platform_status
            FROM users u JOIN organizations o ON o.id=u.organization_id
            WHERE u.active=1
              AND COALESCE(o.platform_status,'active')='active'";
}

function neptune_google_memberships_by_sub(PDO $pdo,string $sub): array {
    $sql=neptune_google_membership_select_sql()." AND u.google_sub=? AND COALESCE(o.google_signin_mode,'optional')<>'disabled' ORDER BY o.name,u.id";
    $s=$pdo->prepare($sql);$s->execute([$sub]);
    return $s->fetchAll();
}

/**
 * Link a verified Google identity to existing active Neptune accounts that have
 * the same email. Ambiguous duplicate emails inside one organization are not
 * linked automatically; that organization must be repaired by an admin.
 */
function neptune_google_memberships_by_verified_email(PDO $pdo,string $sub,string $email): array {
    $sql=neptune_google_membership_select_sql()." AND LOWER(u.email)=? AND COALESCE(o.google_signin_mode,'optional')<>'disabled' ORDER BY o.name,u.id";
    $s=$pdo->prepare($sql);$s->execute([strtolower($email)]);$rows=$s->fetchAll();
    if(!$rows)return [];

    $byOrg=[];
    foreach($rows as $row)$byOrg[(int)$row['organization_id']][]=$row;
    $eligible=[];
    foreach($byOrg as $orgId=>$orgRows){
        if(count($orgRows)!==1){
            error_log('Neptune Google OAuth: verified email is ambiguous inside organization '.$orgId.'; automatic link skipped.');
            continue;
        }
        $row=$orgRows[0];
        $existing=trim((string)($row['google_sub']??''));
        if($existing!==''&&!hash_equals($existing,$sub))continue;
        if($existing===''){
            $pdo->prepare('UPDATE users SET google_sub=?,google_email=?,google_linked_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND (google_sub IS NULL OR google_sub="")')
                ->execute([$sub,strtolower($email),(int)$row['id'],$orgId]);
            $row['google_sub']=$sub;$row['google_email']=strtolower($email);$row['google_linked_at']=gmdate('Y-m-d H:i:s');
            neptune_auth_audit($pdo,$orgId,(int)$row['id'],'google_account_linked','user',(string)$row['id'],['email'=>strtolower($email),'method'=>'verified_email_global']);
        } elseif(strtolower((string)($row['google_email']??''))!==strtolower($email)){
            $pdo->prepare('UPDATE users SET google_email=? WHERE id=? AND organization_id=?')->execute([strtolower($email),(int)$row['id'],$orgId]);
            $row['google_email']=strtolower($email);
        }
        $eligible[]=$row;
    }
    return $eligible;
}

function neptune_google_workspace_orgs(PDO $pdo,string $hostedDomain): array {
    $hostedDomain=strtolower(trim($hostedDomain));
    if($hostedDomain==='')return [];
    $s=$pdo->prepare("SELECT id,name,slug,logo_path,google_signin_mode,google_workspace_domain,google_auto_provision
                     FROM organizations
                     WHERE google_auto_provision=1
                       AND COALESCE(platform_status,'active')='active'
                       AND COALESCE(google_signin_mode,'optional')<>'disabled'
                       AND LOWER(google_workspace_domain)=?
                     ORDER BY name");
    $s->execute([$hostedDomain]);
    return $s->fetchAll();
}

function neptune_google_create_workspace_user(PDO $pdo,array $org,string $sub,string $email,string $displayName,string $hostedDomain): array {
    $orgId=(int)$org['id'];
    $username=neptune_google_username_for_email($pdo,$orgId,$email);
    if(trim($displayName)==='')$displayName=$username;
    $randomPassword=bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO users(organization_id,username,email,password_hash,display_name,role,active,must_change_password,google_sub,google_email,google_linked_at)
                   VALUES(?,?,?,?,?,'scout',1,0,?,?,UTC_TIMESTAMP())")
        ->execute([$orgId,$username,strtolower($email),password_hash($randomPassword,PASSWORD_DEFAULT),$displayName,$sub,strtolower($email)]);
    $uid=(int)$pdo->lastInsertId();
    $teams=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND active=1 ORDER BY id LIMIT 2');
    $teams->execute([$orgId]);$teamIds=$teams->fetchAll(PDO::FETCH_COLUMN);
    if(count($teamIds)===1){
        $pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_primary=1')->execute([$uid,(int)$teamIds[0]]);
    }
    neptune_auth_audit($pdo,$orgId,$uid,'google_user_auto_provisioned','user',(string)$uid,['email'=>strtolower($email),'hosted_domain'=>$hostedDomain,'role'=>'scout']);
    $s=$pdo->prepare(neptune_google_membership_select_sql().' AND u.id=? AND u.organization_id=? LIMIT 1');
    $s->execute([$uid,$orgId]);
    $row=$s->fetch();
    if(!$row)throw new RuntimeException('The new Neptune account could not be loaded.');
    return $row;
}

function neptune_google_store_membership_choice(array $rows,string $sub,string $email,string $intent='login'): void {
    $allowed=[];
    foreach($rows as $row){
        $allowed[]=['user_id'=>(int)$row['id'],'organization_id'=>(int)$row['organization_id']];
    }
    $_SESSION['google_membership_choice']=[
        'allowed'=>$allowed,
        'google_sub'=>$sub,
        'google_email'=>strtolower($email),
        'intent'=>$intent,
        'expires_at'=>time()+600,
    ];
}

function neptune_google_finish_user(PDO $pdo,array $row,string $sub,string $intent='login'): string {
    $orgId=(int)$row['organization_id'];$uid=(int)$row['id'];
    if(function_exists('neptune_organization_platform_status') && neptune_organization_platform_status($pdo,$orgId)!=='active'){
        throw new RuntimeException('This Neptune organization is suspended.');
    }
    if($intent==='reset_password'){
        $_SESSION['password_reset_google']=[
            'user_id'=>$uid,
            'organization_id'=>$orgId,
            'google_sub'=>$sub,
            'expires_at'=>time()+600,
        ];
        neptune_auth_audit($pdo,$orgId,$uid,'password_reset_google_verified','user',(string)$uid);
        return base_url('reset-password.php');
    }
    neptune_start_user_session($pdo,$row,'google');
    neptune_auth_audit($pdo,$orgId,$uid,'login_google','user',(string)$uid);
    return base_url('dashboard/index.php');
}

function neptune_auth_invite_token_hash(string $token): string {
    return hash('sha256',$token);
}

function neptune_auth_invite_generate_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
}

function neptune_auth_invite_load(PDO $pdo,string $token,bool $forUpdate=false): ?array {
    neptune_auth_ensure_schema($pdo);
    if($token===''||strlen($token)>180)return null;
    $sql="SELECT i.*,o.name organization_name,o.slug organization_slug,o.logo_path organization_logo_path,o.google_signin_mode,o.platform_status
          FROM neptune_auth_invites i JOIN organizations o ON o.id=i.organization_id
          WHERE i.token_hash=?
            AND COALESCE(o.platform_status,'active')='active'
          LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([neptune_auth_invite_token_hash($token)]);$row=$s->fetch();
    return $row?:null;
}

function neptune_auth_invite_is_usable(array $invite): bool {
    if(!empty($invite['used_at'])||!empty($invite['revoked_at']))return false;
    $expires=strtotime((string)($invite['expires_at']??''));
    return $expires!==false&&$expires>time();
}

function neptune_google_accept_invite(PDO $pdo,string $token,string $sub,string $email,string $displayName): array {
    $pdo->beginTransaction();
    try{
        $invite=neptune_auth_invite_load($pdo,$token,true);
        if(!$invite||!neptune_auth_invite_is_usable($invite))throw new RuntimeException('This invitation is invalid, expired, revoked, or has already been used.');
        if(strtolower((string)($invite['google_signin_mode']??'optional'))==='disabled')throw new RuntimeException('Google sign-in is disabled for this organization.');
        $restrictedEmail=strtolower(trim((string)($invite['invite_email']??'')));
        if($restrictedEmail!==''&&!hash_equals($restrictedEmail,strtolower($email)))throw new RuntimeException('This invitation was issued for a different Google account. Ask the organization administrator for an invitation addressed to your email.');
        $orgId=(int)$invite['organization_id'];
        $select=neptune_google_membership_select_sql().' AND u.organization_id=? AND ';
        $s=$pdo->prepare($select.'u.google_sub=? LIMIT 1');$s->execute([$orgId,$sub]);$row=$s->fetch();
        if(!$row){
            $s=$pdo->prepare($select.'LOWER(u.email)=? LIMIT 2');$s->execute([$orgId,strtolower($email)]);$matches=$s->fetchAll();
            if(count($matches)>1)throw new RuntimeException('That email matches more than one account in this organization. Ask an administrator to correct the duplicate accounts.');
            if(count($matches)===1){
                $row=$matches[0];$existing=trim((string)($row['google_sub']??''));
                if($existing!==''&&!hash_equals($existing,$sub))throw new RuntimeException('That Neptune account is already linked to a different Google account.');
                if($existing===''){
                    $pdo->prepare('UPDATE users SET google_sub=?,google_email=?,google_linked_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?')->execute([$sub,strtolower($email),(int)$row['id'],$orgId]);
                    $row['google_sub']=$sub;$row['google_email']=strtolower($email);
                }
            }else{
                $username=neptune_google_username_for_email($pdo,$orgId,$email);
                if(trim($displayName)==='')$displayName=$username;
                $randomPassword=bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO users(organization_id,username,email,password_hash,display_name,role,active,must_change_password,google_sub,google_email,google_linked_at)
                               VALUES(?,?,?,?,?,'scout',1,0,?,?,UTC_TIMESTAMP())")
                    ->execute([$orgId,$username,strtolower($email),password_hash($randomPassword,PASSWORD_DEFAULT),$displayName,$sub,strtolower($email)]);
                $uid=(int)$pdo->lastInsertId();
                $teams=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND active=1 ORDER BY id LIMIT 2');$teams->execute([$orgId]);$teamIds=$teams->fetchAll(PDO::FETCH_COLUMN);
                if(count($teamIds)===1)$pdo->prepare('INSERT INTO user_teams(user_id,team_id,is_primary) VALUES(?,?,1) ON DUPLICATE KEY UPDATE is_primary=1')->execute([$uid,(int)$teamIds[0]]);
                $s=$pdo->prepare(neptune_google_membership_select_sql().' AND u.id=? AND u.organization_id=? LIMIT 1');$s->execute([$uid,$orgId]);$row=$s->fetch();
                if(!$row)throw new RuntimeException('The invited Neptune account could not be loaded.');
                neptune_auth_audit($pdo,$orgId,$uid,'google_user_invited','user',(string)$uid,['email'=>strtolower($email),'role'=>'scout']);
            }
        }
        $uid=(int)$row['id'];
        $upd=$pdo->prepare('UPDATE neptune_auth_invites SET used_at=UTC_TIMESTAMP(),used_by_user_id=? WHERE id=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');
        $upd->execute([$uid,(int)$invite['id']]);
        if($upd->rowCount()!==1)throw new RuntimeException('This invitation was already used or expired.');
        neptune_auth_audit($pdo,$orgId,$uid,'organization_invite_accepted','invite',(string)$invite['id'],['email'=>strtolower($email)]);
        $pdo->commit();
        return $row;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

