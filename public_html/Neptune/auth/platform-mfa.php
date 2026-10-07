<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-mfa.php';

neptune_mfa_ensure_schema($pdo);

$esc=static fn(string $v): string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

if(isset($_SESSION['platform_mfa_recovery_codes'])&&is_array($_SESSION['platform_mfa_recovery_codes'])){
    $codes=$_SESSION['platform_mfa_recovery_codes'];
    $continue=$_SESSION['platform_mfa_continue']??base_url('dashboard/index.php');

    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        unset($_SESSION['platform_mfa_recovery_codes'],$_SESSION['platform_mfa_continue']);
        header('Location: '.$continue);
        exit;
    }

    $pageMode='recovery';
    $error='';
    $displayName=(string)($_SESSION['user']['display_name']??'Platform Owner');
    $secret='';
    $otpauth='';
}else{
    $pending=neptune_platform_mfa_pending();
    if(!$pending){
        $_SESSION['auth_error']='Your Platform Owner verification session expired. Sign in again.';
        header('Location: '.base_url('index.php'));
        exit;
    }

    $s=$pdo->prepare(
        "SELECT u.*,o.name organization_name,o.slug organization_slug,
                COALESCE(o.platform_status,'active') platform_status,o.google_signin_mode
         FROM users u
         JOIN organizations o ON o.id=u.organization_id
         WHERE u.id=? AND u.organization_id=? AND u.active=1
         LIMIT 1"
    );
    $s->execute([(int)$pending['user_id'],(int)$pending['organization_id']]);
    $row=$s->fetch(PDO::FETCH_ASSOC)?:null;

    if(!$row||!neptune_platform_owner_is($row)||strtolower((string)$row['platform_status'])!=='active'){
        unset($_SESSION['platform_mfa_pending'],$_SESSION['platform_mfa_setup_secret']);
        $_SESSION['auth_error']='Unable to continue Platform Owner verification.';
        header('Location: '.base_url('index.php'));
        exit;
    }

    $displayName=(string)$row['display_name'];
    $mfa=neptune_mfa_record($pdo,(int)$row['id']);
    $enrolled=$mfa!==null&&trim((string)$mfa['totp_secret_ciphertext'])!=='';
    $pageMode=$enrolled?'challenge':'setup';
    $error='';
    $secret='';
    $otpauth='';

    if(!$enrolled){
        $secret=(string)($_SESSION['platform_mfa_setup_secret']??'');
        if($secret===''){
            $secret=neptune_mfa_generate_secret();
            $_SESSION['platform_mfa_setup_secret']=$secret;
        }
        $issuer='Neptune';
        $account=(string)$row['organization_slug'].':'.(string)$row['username'];
        $otpauth='otpauth://totp/'.rawurlencode($issuer.':'.$account)
            .'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();

        $input=trim((string)($_POST['code']??''));
        $verified=false;
        $verifyMethod='totp';
        $usedStep=null;

        try{
            if($pageMode==='setup'){
                $usedStep=neptune_totp_verify($secret,$input,null);
                $verified=$usedStep!==null;
            }else{
                $storedSecret=neptune_mfa_decrypt((string)$mfa['totp_secret_ciphertext']);
                $usedStep=neptune_totp_verify(
                    $storedSecret,
                    $input,
                    isset($mfa['last_totp_step'])&&$mfa['last_totp_step']!==null
                        ? (int)$mfa['last_totp_step']
                        : null
                );
                if($usedStep!==null){
                    $verified=true;
                }elseif(neptune_mfa_use_recovery_code($pdo,$mfa,$input)){
                    $verified=true;
                    $verifyMethod='recovery';
                }
            }

            if(!$verified){
                $failures=neptune_platform_mfa_fail();
                if($failures>=8){
                    unset($_SESSION['platform_mfa_pending'],$_SESSION['platform_mfa_setup_secret']);
                    $_SESSION['auth_error']='Too many authenticator attempts. Sign in again to retry.';
                    header('Location: '.base_url('index.php'));
                    exit;
                }
                $error=$pageMode==='setup'
                    ? 'That Google Authenticator code did not match. Check the phone time and try the current 6-digit code.'
                    : 'That Google Authenticator or recovery code was not accepted.';
            }else{
                $recoveryCodes=[];

                if($pageMode==='setup'){
                    $recoveryCodes=neptune_mfa_recovery_codes(10);
                    $cipher=neptune_mfa_encrypt($secret);
                    $hashes=neptune_mfa_hash_recovery_codes($recoveryCodes);

                    $pdo->prepare(
                        "INSERT INTO neptune_user_mfa
                            (user_id,organization_id,totp_secret_ciphertext,recovery_code_hashes,last_totp_step,enabled_at,updated_at)
                         VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE
                            organization_id=VALUES(organization_id),
                            totp_secret_ciphertext=VALUES(totp_secret_ciphertext),
                            recovery_code_hashes=VALUES(recovery_code_hashes),
                            last_totp_step=VALUES(last_totp_step),
                            enabled_at=UTC_TIMESTAMP(),
                            updated_at=UTC_TIMESTAMP()"
                    )->execute([
                        (int)$row['id'],
                        (int)$row['organization_id'],
                        $cipher,
                        $hashes,
                        $usedStep,
                    ]);
                }elseif($verifyMethod==='totp'){
                    $pdo->prepare(
                        'UPDATE neptune_user_mfa SET last_totp_step=?,updated_at=UTC_TIMESTAMP() WHERE user_id=?'
                    )->execute([$usedStep,(int)$row['id']]);
                }

                neptune_start_user_session($pdo,$row,'password');
                neptune_platform_mfa_mark_verified($verifyMethod);

                neptune_auth_audit(
                    $pdo,
                    (int)$row['organization_id'],
                    (int)$row['id'],
                    $pageMode==='setup'?'platform_mfa_enrolled':'platform_mfa_verified',
                    'user',
                    (string)$row['id'],
                    ['method'=>$verifyMethod]
                );

                unset($_SESSION['platform_mfa_pending'],$_SESSION['platform_mfa_setup_secret']);

                $continue=base_url(!empty($row['must_change_password'])
                    ?'change-password.php'
                    :'dashboard/index.php');

                if($pageMode==='setup'){
                    $_SESSION['platform_mfa_recovery_codes']=$recoveryCodes;
                    $_SESSION['platform_mfa_continue']=$continue;
                    header('Location: '.base_url('auth/platform-mfa.php'));
                    exit;
                }

                header('Location: '.$continue);
                exit;
            }
        }catch(Throwable $e){
            error_log('[Neptune platform MFA] '.$e->getMessage());
            $error='Neptune could not complete Platform Owner verification. Try again or use Google sign-in.';
        }
    }
}

$logo=$esc(base_url('images/logo.png'));
$login=$esc(base_url('index.php'));
$logout=$esc(base_url('logout.php'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Platform Owner Verification · Neptune</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:#070b12;color:#eef4ff;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.card{width:min(720px,100%);background:#101722;border:1px solid #273449;border-radius:22px;padding:32px;box-shadow:0 24px 70px rgba(0,0,0,.38)}
.brand{display:flex;align-items:center;gap:14px;margin-bottom:22px}.brand img{width:54px;height:54px;object-fit:contain}
.kicker{font-size:.78rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:#8fb9ff}
h1{font-size:clamp(1.65rem,4vw,2.3rem);line-height:1.1;margin:7px 0 12px}
p{line-height:1.6;color:#c4cfdf;margin:0 0 14px}
.user{display:inline-flex;padding:7px 11px;border:1px solid #33445f;border-radius:999px;background:#0b111b;margin:0 0 18px;font-weight:700}
.notice{padding:12px 14px;border-radius:12px;margin:14px 0;background:#29151b;border:1px solid #70404b;color:#ffd9e0}
.setup{background:#0b111b;border:1px solid #273449;border-radius:15px;padding:18px;margin:18px 0}
.label{display:block;font-size:.82rem;color:#93a4bb;margin-bottom:7px;font-weight:800}
.qr-wrap{display:flex;flex-direction:column;align-items:center;gap:10px;margin:18px 0 16px}
.qr-box{display:grid;place-items:center;width:264px;min-height:264px;padding:12px;background:#fff;border-radius:14px;border:1px solid #d7deea}
.qr-box canvas,.qr-box img{display:block;max-width:240px!important;width:240px!important;height:240px!important}
.manual-setup{margin-top:14px;border-top:1px solid #26364d;padding-top:14px}
.manual-setup summary{cursor:pointer;font-weight:800;color:#dce8f8}
.secret{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.secret code{display:block;overflow-wrap:anywhere;background:#05080d;border:1px solid #2f4058;border-radius:10px;padding:10px 12px;color:#f2f6ff}
input{width:100%;font:inherit;font-size:1.2rem;letter-spacing:.08em;background:#080d15;color:#f5f8ff;border:1px solid #40536f;border-radius:12px;padding:13px 14px;outline:none}
input:focus{border-color:#8fb9ff;box-shadow:0 0 0 3px rgba(143,185,255,.15)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
button,.btn{font:inherit;display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border-radius:11px;border:1px solid #edf4ff;background:#edf4ff;color:#07101d;text-decoration:none;font-weight:800;cursor:pointer}
.btn.secondary,button.secondary{background:transparent;color:#dce8f8;border-color:#40536f}
.codes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:18px 0}
.codes code{display:block;background:#05080d;border:1px solid #2f4058;padding:10px 12px;border-radius:10px;font-size:1rem;text-align:center;user-select:all}
.small{font-size:.9rem;color:#94a3b8}
@media(max-width:560px){.card{padding:22px}.codes{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="<?=$logo?>" alt="">
    <div><div class="kicker">Neptune Security</div><strong>Platform Owner</strong></div>
  </div>

  <?php if($pageMode==='recovery'):?>
    <h1>Save your recovery codes.</h1>
    <div class="user"><?=$esc($displayName)?></div>
    <p>Your authenticator is now enabled. Each recovery code can be used once if you cannot access your authenticator app.</p>
    <div class="codes" id="recoveryCodes">
      <?php foreach($codes as $code):?><code><?=$esc((string)$code)?></code><?php endforeach;?>
    </div>
    <p class="small">Store these somewhere separate from this server. Neptune only stores one-way hashes of the recovery codes and cannot show this set again.</p>
    <div class="actions">
      <button type="button" class="secondary" id="copyCodes">Copy recovery codes</button>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?=$esc(csrf_token())?>">
        <button type="submit">Continue to Neptune</button>
      </form>
    </div>
    <script>
    document.getElementById('copyCodes')?.addEventListener('click',async()=>{
      const text=[...document.querySelectorAll('#recoveryCodes code')].map(el=>el.textContent.trim()).join('\n');
      try{
        await navigator.clipboard.writeText(text);
        const b=document.getElementById('copyCodes');
        b.textContent='Copied';
        setTimeout(()=>b.textContent='Copy recovery codes',1400);
      }catch(e){
        document.getElementById('copyCodes').textContent='Select and copy the codes';
      }
    });
    </script>

  <?php elseif($pageMode==='setup'):?>
    <h1>Set up Google Authenticator.</h1>
    <div class="user"><?=$esc($displayName)?></div>
    <p>Because this is a Platform Owner account, password sign-in requires a second factor. Scan the QR code below with Google Authenticator. Google sign-in is the alternate strong-authentication method.</p>

    <?php if($error!==''):?><div class="notice"><?=$esc($error)?></div><?php endif;?>

    <div class="setup">
      <span class="label">On your phone</span>
      <p><strong>Open Google Authenticator → tap + → Scan a QR code.</strong></p>

      <div class="qr-wrap">
        <div id="totpQr" class="qr-box" aria-label="Google Authenticator setup QR code"></div>
        <div id="qrFallback" class="small" hidden>
          The QR code could not be generated. Use the setup key below instead.
        </div>
      </div>

      <details class="manual-setup">
        <summary>Can't scan the QR code? Enter a setup key instead</summary>
        <div style="margin-top:14px">
          <span class="label">Google Authenticator setup key</span>
          <div class="secret">
            <code id="setupSecret"><?=$esc($secret)?></code>
            <button type="button" class="secondary" id="copySecret">Copy key</button>
          </div>
          <p class="small" style="margin-top:10px">Choose <strong>Time based</strong>. Google Authenticator will generate a new 6-digit code every 30 seconds.</p>
        </div>
      </details>
    </div>

    <form method="post" autocomplete="one-time-code">
      <input type="hidden" name="csrf" value="<?=$esc(csrf_token())?>">
      <label class="label" for="code">Enter the current 6-digit code from your authenticator</label>
      <input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,8}" maxlength="8" required autofocus>
      <div class="actions">
        <button type="submit">Verify and enable</button>
        <a class="btn secondary" href="<?=$logout?>">Cancel</a>
      </div>
    </form>
    <script
      src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
      integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA=="
      crossorigin="anonymous"
      referrerpolicy="no-referrer"></script>
    <script>
    (() => {
      const uri = <?=json_encode($otpauth,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
      const target = document.getElementById('totpQr');
      const fallback = document.getElementById('qrFallback');

      try {
        if (!window.QRCode || !target) throw new Error('QR library unavailable');
        new QRCode(target, {
          text: uri,
          width: 240,
          height: 240,
          colorDark: '#000000',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {
        if (target) target.hidden = true;
        if (fallback) fallback.hidden = false;
      }

      document.getElementById('copySecret')?.addEventListener('click',async()=>{
        const text=document.getElementById('setupSecret').textContent.trim();
        try{
          await navigator.clipboard.writeText(text);
          const b=document.getElementById('copySecret');
          b.textContent='Copied';
          setTimeout(()=>b.textContent='Copy key',1400);
        }catch(e){
          document.getElementById('copySecret').textContent='Select and copy the key';
        }
      });
    })();
    </script>

  <?php else:?>
    <h1>Enter your authenticator code.</h1>
    <div class="user"><?=$esc($displayName)?></div>
    <p>Password verified. Enter the current 6-digit code from Google Authenticator to complete Platform Owner sign-in.</p>

    <?php if($error!==''):?><div class="notice"><?=$esc($error)?></div><?php endif;?>

    <form method="post" autocomplete="one-time-code">
      <input type="hidden" name="csrf" value="<?=$esc(csrf_token())?>">
      <label class="label" for="code">Google Authenticator code or recovery code</label>
      <input id="code" name="code" autocomplete="one-time-code" required autofocus>
      <div class="actions">
        <button type="submit">Verify</button>
        <a class="btn secondary" href="<?=$logout?>">Cancel</a>
      </div>
    </form>
    <p class="small" style="margin-top:17px">A recovery code can be used once in place of the authenticator code.</p>
  <?php endif;?>
</main>
</body>
</html>
