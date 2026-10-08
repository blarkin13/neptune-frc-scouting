<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$u=current_user();
$pageTitle='NTX Quick Start';
$moduleName='NEPTUNE';
$bodyClass='ntx-quick-start-page';

include dirname(__DIR__).'/partials_header.php';

$role=(string)($u['role']??'');
$canManageUsers=in_array($role,['strategy','admin','owner'],true);
$ntxPortableMode=!empty($config['app']['portable_mode']);
?>
<style>
.ntx-start{max-width:1180px;margin:0 auto;padding-bottom:34px}
.ntx-hero{position:relative;overflow:hidden;margin:18px 0 22px;padding:clamp(24px,5vw,48px);border:1px solid var(--line);border-radius:22px;background:linear-gradient(135deg,color-mix(in srgb,var(--panel) 92%,var(--accent) 8%),var(--panel2));box-shadow:0 20px 60px rgba(0,0,0,.16)}
.ntx-hero:after{content:"";position:absolute;width:320px;height:320px;right:-150px;bottom:-180px;border-radius:50%;background:color-mix(in srgb,var(--accent) 13%,transparent);pointer-events:none}
.ntx-kicker{display:inline-flex;align-items:center;gap:8px;font-size:.76rem;font-weight:950;letter-spacing:.13em;text-transform:uppercase;color:var(--accent)}
.ntx-hero h1{position:relative;z-index:1;margin:8px 0 10px;font-size:clamp(2.35rem,6vw,4.5rem);line-height:.98;letter-spacing:-.045em}
.ntx-hero p{position:relative;z-index:1;max-width:760px;margin:0;color:var(--muted);font-size:clamp(1rem,2vw,1.18rem);line-height:1.65}
.ntx-actions{position:relative;z-index:1;display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.ntx-actions .btn{min-height:46px}
.ntx-owner-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:0 0 20px}
.ntx-step{min-width:0;padding:18px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}
.ntx-step-num{display:grid;place-items:center;width:34px;height:34px;margin-bottom:13px;border-radius:10px;background:color-mix(in srgb,var(--accent) 15%,var(--panel));color:var(--accent);font-weight:950}
.ntx-step h2{margin:0 0 7px;font-size:1rem}.ntx-step p{margin:0;color:var(--muted);font-size:.84rem;line-height:1.5}.ntx-step a{display:inline-flex;margin-top:11px;font-size:.8rem;font-weight:900}
.ntx-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:18px;align-items:start}
.ntx-card{padding:22px;border:1px solid var(--line);border-radius:18px;background:var(--panel)}
.ntx-card h2{margin:0 0 8px}.ntx-card p{color:var(--muted);line-height:1.6}
.ntx-checks{display:grid;gap:10px;margin-top:16px}
.ntx-check{display:grid;grid-template-columns:32px minmax(0,1fr);gap:10px;align-items:start}
.ntx-check i{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:color-mix(in srgb,var(--good) 13%,var(--panel2));color:var(--good)}
.ntx-check b{display:block;margin-bottom:2px;font-size:.9rem}.ntx-check span{display:block;color:var(--muted);font-size:.82rem;line-height:1.45}
.ntx-qr-wrap{display:grid;justify-items:center;text-align:center}
.ntx-qr{display:grid;place-items:center;width:264px;min-height:264px;margin-top:14px;padding:12px;border-radius:16px;background:#fff}
.ntx-qr img,.ntx-qr canvas{display:block!important;width:240px!important;height:240px!important;max-width:240px!important}
.ntx-url{display:block;margin-top:10px;max-width:100%;overflow-wrap:anywhere;font-size:.78rem;color:var(--accent)}
.ntx-note{margin-top:16px;padding:12px 14px;border:1px solid color-mix(in srgb,var(--accent) 30%,var(--line));border-radius:12px;background:color-mix(in srgb,var(--accent) 8%,var(--panel));font-size:.84rem;line-height:1.5}
.ntx-signin{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:12px}
.ntx-footer-note{margin-top:18px;text-align:center;color:var(--muted);font-size:.78rem}
@media(max-width:980px){.ntx-owner-flow{grid-template-columns:1fr 1fr}.ntx-grid{grid-template-columns:1fr}}
@media(max-width:620px){.ntx-start{padding:0 4px 26px}.ntx-owner-flow{grid-template-columns:1fr}.ntx-hero{padding:24px 20px;border-radius:18px}.ntx-actions{display:grid}.ntx-actions .btn{width:100%}.ntx-card{padding:18px}.ntx-qr{width:232px;min-height:232px}.ntx-qr img,.ntx-qr canvas{width:208px!important;height:208px!important;max-width:208px!important}}
</style>

<section class="module-page ntx-start">
  <header class="ntx-hero">
    <div class="ntx-kicker"><i class="fa-solid fa-satellite-dish"></i> NTX · START HERE</div>
    <h1>Get your team on Neptune.</h1>
    <p>Create your organization, add your scouts, connect your event, and start using Neptune without reading the full manual first.</p>
    <div class="ntx-actions">
      <?php if(!$u):?>
        <a class="btn" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-building-circle-check"></i> Register Your Organization</a>
        <a class="btn secondary" href="<?=e(base_url('index.php'))?>"><i class="fa-solid fa-right-to-bracket"></i> Already Registered? Sign In</a>
      <?php else:?>
        <a class="btn" href="<?=e(base_url('dashboard/index.php'))?>"><i class="fa-solid fa-house"></i> Open Neptune</a>
        <?php if($canManageUsers):?><a class="btn secondary" href="<?=e(base_url('admin/teams.php'))?>"><i class="fa-solid fa-users-gear"></i> Teams &amp; Users</a><?php endif;?>
      <?php endif;?>
    </div>
  </header>

  <div class="ntx-owner-flow">
    <article class="ntx-step"><div class="ntx-step-num">1</div><h2>Register</h2><p>Create the organization, first Owner account, and your FRC team.</p><?php if(!$u):?><a href="<?=e(base_url('register.php'))?>">Register organization →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">2</div><h2>Sign in</h2><p>Use your organization name/slug and Neptune account, or Google when your organization supports it.</p><?php if(!$u):?><a href="<?=e(base_url('index.php'))?>">Open sign in →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">3</div><h2>Add your scouts</h2><p>Open Teams &amp; Users, create accounts or invitations, and assign the right roles.</p><?php if($u && $canManageUsers):?><a href="<?=e(base_url('admin/teams.php'))?>">Teams &amp; Users →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">4</div><h2>Connect the event</h2><p>Create/select the event, load the roster, then use TBA Sync when the official schedule is available.</p><?php if($u && $canManageUsers):?><a href="<?=e(base_url('admin/events.php'))?>">Event Setup →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">5</div><h2>Start scouting</h2><p>Pit scout immediately. Once the schedule is ready, use Match Control and live Match Scouting.</p><?php if($u):?><a href="<?=e(base_url('scouting.php'))?>">Open Scouting →</a><?php endif;?></article>
  </div>

  <div class="ntx-grid">
    <article class="ntx-card">
      <div class="ntx-kicker"><i class="fa-solid fa-stopwatch"></i> EVENT-DAY CHECKLIST</div>
      <h2>Ready in a few minutes</h2>
      <p>The Owner or Strategy lead should handle setup. Scouts only need their account and assigned scouting workflow.</p>
      <div class="ntx-checks">
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>Organization + Owner created</b><span>Keep the organization sign-in slug handy for password users.</span></div></div>
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>Scout accounts created</b><span>Use Teams &amp; Users for accounts, invitations, roles, and primary-team assignment.</span></div></div>
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>Current event selected</b><span>You can begin pit scouting before the match schedule exists.</span></div></div>
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>TBA roster/schedule synced</b><span>When TBA publishes the schedule, sync it into the existing Neptune event.</span></div></div>
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>One practice pass completed</b><span>Have one scout sign in on the device they will actually use and verify the expected workflow.</span></div></div>
      </div>
      <div class="ntx-note"><b>Need the full training?</b> Once signed in, use the footer <b>Walkthrough</b> button or open the full User Guide / CBT training.</div>
      <?php if($u):?>
        <div class="ntx-signin">
          <span class="pill"><i class="fa-solid fa-circle-check"></i> Signed in as <?=e($u['display_name']??$u['username']??'Neptune user')?></span>
          <?php if($canManageUsers):?><a class="btn secondary" href="<?=e(base_url('admin/tba-sync.php'))?>"><i class="fa-solid fa-arrows-rotate"></i> TBA Sync</a><?php endif;?>
          <a class="btn secondary" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open"></i> Full Guide</a>
        </div>
      <?php endif;?>
    </article>

    <aside class="ntx-card ntx-qr-wrap">
      <div class="ntx-kicker"><i class="fa-solid fa-qrcode"></i> SHARE THIS PAGE</div>
      <h2>Scan to start</h2>
      <p>Put this page on a laptop or pit display and let another team scan it with their phone.</p>
      <div class="ntx-qr" id="ntxQuickStartQr" aria-label="QR code for the Neptune NTX Quick Start page"></div>
      <a class="ntx-url" id="ntxQuickStartUrl" href="<?=e(base_url('help/ntx-start.php'))?>"><?=e(base_url('help/ntx-start.php'))?></a>
      <a class="btn" style="margin-top:14px" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-rocket"></i> Register Organization</a>
    </aside>
  </div>

  <div class="ntx-footer-note">Neptune is an independent community FRC scouting platform and is not affiliated with or endorsed by FIRST® or The Blue Alliance.</div>
</section>

<?php if(!$ntxPortableMode):?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif;?>
<script>
(function(){
  const host=document.getElementById('ntxQuickStartQr');
  const link=document.getElementById('ntxQuickStartUrl');
  if(!host||!link)return;

  const target=new URL(<?=json_encode(base_url('help/ntx-start.php'))?>,window.location.origin).href;
  const portableQr=<?=json_encode($ntxPortableMode?base_url('assets/portable/neptune-ntx-qr.png'):'')?>;
  link.href=target;
  link.textContent=target;

  if(portableQr){
    const img=document.createElement('img');
    img.src=portableQr+'?v='+Date.now();
    img.alt='QR code for this Neptune event server';
    host.appendChild(img);
    return;
  }

  if(typeof window.QRCode!=='function'){
    host.innerHTML='<div style="color:#111;padding:18px;font-size:.82rem;line-height:1.4">QR rendering is unavailable. Use the link below.</div>';
    return;
  }

  new QRCode(host,{
    text:target,
    width:240,
    height:240,
    colorDark:'#000000',
    colorLight:'#ffffff',
    correctLevel:QRCode.CorrectLevel.M
  });
})();
</script>

<?php include dirname(__DIR__).'/partials_footer.php';
