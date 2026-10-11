<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$u=current_user();
$pageTitle='NTX Quick Start';
$moduleName='NEPTUNE';
$bodyClass='ntx-quick-start-page';

$ntxPortableMode=!empty($config['app']['portable_mode']);

include dirname(__DIR__).'/partials_header.php';

$role=(string)($u['role']??'');
$canManageUsers=in_array($role,['strategy','admin','owner'],true);
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

/* NTX event overview — directly on the Quick Start page; inspired by the Neptune landing page. */
.ntx-start{max-width:1440px}
.ntx-showcase{--ntx-teal:#52e7d2;--ntx-aqua:#66d9ff;position:relative;isolation:isolate;overflow:hidden;
  display:flex;flex-direction:column;gap:clamp(10px,1.25vh,18px);
  min-height:clamp(510px,calc(100svh - 115px),820px);margin:14px 0 22px;
  padding:clamp(15px,1.7vw,26px);border:1px solid rgba(100,208,238,.32);border-radius:23px;
  color:#e8f7ff;background:radial-gradient(ellipse at 10% 8%,rgba(23,147,181,.19),transparent 52%),
   radial-gradient(ellipse at 92% 86%,rgba(69,86,183,.19),transparent 49%),#061624;
  box-shadow:0 16px 55px rgba(0,0,0,.18)}
.ntx-showcase>:not(.ntx-ocean){position:relative;z-index:1}
.ntx-ocean{position:absolute;inset:0;z-index:0;overflow:hidden;pointer-events:none;opacity:.93}
.ntx-ocean::before{content:'';position:absolute;inset:0;background:repeating-conic-gradient(from 183deg at 50% -35%,transparent 0deg 10deg,rgba(60,170,210,.055) 11deg 12deg,transparent 13deg 22deg);mask-image:linear-gradient(#000,transparent 80%)}
.ntx-ocean-bubble{position:absolute;left:var(--x);bottom:-35px;width:var(--size);height:var(--size);border:1px solid rgba(122,220,247,.45);border-radius:50%;opacity:0;
  background:radial-gradient(circle at 30% 26%,rgba(136,234,255,.29),transparent 38%);
  box-shadow:inset -2px -2px 5px rgba(44,188,224,.23),0 0 12px rgba(53,190,234,.2);
  animation:ntx-bubble-rise var(--duration) linear var(--delay) infinite;will-change:transform,opacity}
@keyframes ntx-bubble-rise{0%{transform:translate3d(0,0,0) scale(.75);opacity:0}10%{opacity:var(--alpha)}85%{opacity:var(--alpha)}100%{transform:translate3d(var(--drift),-850px,0) scale(1.1);opacity:0}}
.ntx-showcase-bar{display:flex;align-items:center;justify-content:space-between;gap:12px}
.ntx-showcase-brand{font-size:clamp(1.05rem,1.9vw,1.55rem);font-weight:950;letter-spacing:.15em;white-space:nowrap}
.ntx-showcase-brand i{color:var(--ntx-aqua);margin-right:5px}.ntx-showcase-brand span{display:block;margin:2px 0 0 25px;color:#87a9bd;letter-spacing:.12em;font-size:.58rem;text-transform:uppercase}
.ntx-event-flag{padding:8px 12px;border:1px solid rgba(82,231,210,.32);border-radius:50px;background:rgba(28,164,153,.15);color:#a4f7ec;font-size:.72rem;font-weight:850;letter-spacing:.1em;white-space:nowrap}
.ntx-showcase-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(235px,27%);gap:clamp(12px,1.6vw,24px);flex:1;min-height:0}
.ntx-showcase-content{display:flex;flex-direction:column;gap:clamp(12px,1.4vh,20px);min-width:0}
.ntx-showcase .ntx-kicker{color:var(--ntx-teal);font-size:.74rem;letter-spacing:.16em}
.ntx-showcase h1{font-size:clamp(2.3rem,4.15vw,4.3rem);line-height:1;letter-spacing:-.055em;margin:5px 0 8px;color:#effaff}
.ntx-showcase h1 em{font-style:normal;color:var(--ntx-aqua)}
.ntx-showcase-intro{color:#bcd5e3;max-width:850px;font-size:clamp(.86rem,1.16vw,1.07rem);line-height:1.45;margin:0}
.ntx-features{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:clamp(9px,1vw,15px);flex:1}
.ntx-feature{display:flex;align-items:flex-start;gap:11px;padding:clamp(11px,1.25vw,20px);border:1px solid rgba(131,210,244,.23);border-radius:15px;background:rgba(10,32,51,.89);min-width:0}
.ntx-feature-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:11px;background:rgba(42,170,203,.14);border:1px solid rgba(82,217,246,.3);color:#73ebed;font-size:1rem}
.ntx-feature h2{margin:0 0 6px;font-size:clamp(.95rem,1.4vw,1.35rem);font-weight:850;color:#fff}
.ntx-feature p{margin:0;color:#b9d5e5;line-height:1.42;font-size:clamp(.77rem,.95vw,.94rem)}
.ntx-showcase-qr{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;padding:clamp(14px,1.7vw,24px);border:1px solid rgba(105,210,240,.37);border-radius:20px;background:linear-gradient(145deg,rgba(30,94,115,.69),rgba(7,31,53,.93));min-width:0;text-align:center}
.ntx-qr-heading{font-size:.68rem;font-weight:900;letter-spacing:.14em;color:var(--ntx-teal)}
.ntx-showcase-qr h2{margin:0;font-size:clamp(1.4rem,2.15vw,2.15rem);line-height:1.1;color:white}
.ntx-showcase-qr p{margin:0;color:#c0d8e7;font-size:clamp(.79rem,.96vw,.95rem);line-height:1.4}
.ntx-showcase .ntx-qr{display:grid;place-items:center;width:clamp(156px,17.4vw,230px);min-height:0;aspect-ratio:1;margin:4px 0;padding:9px;border-radius:14px;background:#fff;box-shadow:0 0 0 5px rgba(105,230,250,.08)}
.ntx-showcase .ntx-qr img,.ntx-showcase .ntx-qr canvas{width:100%!important;height:auto!important;max-width:100%!important;aspect-ratio:1;object-fit:contain}
.ntx-showcase .ntx-url{font-size:.73rem;font-weight:800;color:#a3f0f2;margin:0}
.ntx-qr-steps{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;padding-top:10px;border-top:1px solid rgba(145,221,240,.19);width:100%;font-size:.74rem;font-weight:800;color:#d5f5ff}
.ntx-qr-steps i{color:#59d7d3;font-size:.68rem}
.ntx-qr-button{width:100%;justify-content:center;min-height:38px!important;margin-top:4px}
.ntx-showcase-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;color:#8aaabc;font-size:.77rem;border-top:1px solid rgba(130,206,239,.19);padding-top:10px}
.ntx-showcase-footer a{color:#a5efef;font-weight:800;text-decoration:none;white-space:nowrap}
.ntx-showcase-footer a:hover{text-decoration:underline}
.ntx-owner-flow{scroll-margin-top:20px}
.ntx-next-links{display:grid;gap:11px;margin-top:16px}.ntx-next-links .btn{justify-content:center}
@media(min-width:1051px) and (max-height:820px){.ntx-showcase{min-height:0}.ntx-showcase .ntx-qr{width:clamp(145px,13vw,180px)}.ntx-showcase-qr{gap:5px;padding:12px}.ntx-showcase-qr h2{font-size:1.55rem}.ntx-showcase h1{font-size:clamp(2.3rem,3.6vw,3.6rem)}.ntx-feature{padding:12px}.ntx-showcase-footer{padding-top:6px}}
@media(max-width:1050px){.ntx-showcase{min-height:0}.ntx-showcase-body{grid-template-columns:minmax(0,1fr) minmax(220px,30%)}.ntx-showcase-qr{gap:7px}.ntx-showcase .ntx-qr{width:180px}}
@media(max-width:720px){.ntx-showcase{min-height:0}.ntx-showcase-body{grid-template-columns:1fr}.ntx-showcase-qr{padding:16px}.ntx-features{grid-template-columns:repeat(2,minmax(0,1fr))}.ntx-showcase .ntx-qr{width:185px}.ntx-showcase-footer{flex-wrap:wrap}}
@media(max-width:480px){.ntx-features{grid-template-columns:1fr}.ntx-feature{padding:13px}.ntx-showcase h1{font-size:2.55rem}.ntx-showcase-brand span{font-size:.48rem}.ntx-event-flag{font-size:.57rem;padding:6px 9px}}
@media(prefers-reduced-motion:reduce){.ntx-ocean-bubble{animation:none;opacity:.2;transform:translateY(-60px)}}

</style>

<section class="module-page ntx-start">
  <header class="ntx-showcase" aria-label="About Neptune and NTX Quick Start">
    <div class="ntx-ocean" aria-hidden="true"></div>
    <div class="ntx-showcase-bar">
      <span class="ntx-showcase-brand"><i class="fa-solid fa-water" aria-hidden="true"></i> NEPTUNE <span>FRC Scouting &amp; Strategy</span></span>
      <span class="ntx-event-flag">NTX · MEET NEPTUNE</span>
    </div>
    <div class="ntx-showcase-body">
      <div class="ntx-showcase-content">
        <div>
          <div class="ntx-kicker"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i> ABOUT NEPTUNE</div>
          <h1>Scout smarter.<br><em>Compete stronger.</em></h1>
          <p class="ntx-showcase-intro">Event-ready scouting, analytics, and strategy. Neptune brings robot observations and official FIRST/TBA match results together to help teams make better decisions.</p>
        </div>
        <div class="ntx-features">
          <div class="ntx-feature"><span class="ntx-feature-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><div><h2>Scout</h2><p>Match &amp; Tag Scouting, pit profiles, robot photos, and multiple observers.</p></div></div>
          <div class="ntx-feature"><span class="ntx-feature-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><div><h2>Analyze</h2><p>AUGUR, Neptune EPA, trends, defense, and robot intelligence.</p></div></div>
          <div class="ntx-feature"><span class="ntx-feature-icon"><i class="fa-solid fa-people-arrows" aria-hidden="true"></i></span><div><h2>Strategize</h2><p>Match Board, robot comparisons, and Alliance Selection.</p></div></div>
          <div class="ntx-feature"><span class="ntx-feature-icon"><i class="fa-solid fa-cloud" aria-hidden="true"></i></span><div><h2>Event-ready</h2><p>Multi-organization support with cloud and portable offline options.</p></div></div>
        </div>
      </div>
      <aside class="ntx-showcase-qr">
        <div class="ntx-qr-heading">SCAN TO GET STARTED</div>
        <h2>Join Neptune</h2>
        <p>Open this quick-start guide on your phone and register your team.</p>
        <div class="ntx-qr" id="ntxQuickStartQr" aria-label="QR code for the Neptune NTX Quick Start page"></div>
        <a class="ntx-url" id="ntxQuickStartUrl" href="<?=e(base_url('help/ntx-start.php'))?>"><?=e(base_url('help/ntx-start.php'))?></a>
        <div class="ntx-qr-steps"><span>Register</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><span>Add scouts</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><span>Scout</span></div>
        <?php if(!$u):?>
          <a class="btn ntx-qr-button" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-rocket" aria-hidden="true"></i> Register Organization</a>
        <?php else:?>
          <a class="btn ntx-qr-button" href="<?=e(base_url('dashboard/index.php'))?>"><i class="fa-solid fa-house" aria-hidden="true"></i> Open Neptune</a>
        <?php endif;?>
      </aside>
    </div>
    <div class="ntx-showcase-footer"><span>Built for scouts, strategists, and drive teams.</span><a href="#ntx-setup-steps">NTX setup instructions <i class="fa-solid fa-arrow-down" aria-hidden="true"></i></a></div>
  </header>

  <div class="ntx-owner-flow" id="ntx-setup-steps">
    <article class="ntx-step"><div class="ntx-step-num">1</div><h2>Register</h2><p>Create the organization, first Owner account, and your FRC team.</p><?php if(!$u):?><a href="<?=e(base_url('register.php'))?>">Register organization →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">2</div><h2>Sign in</h2><p>Use your organization name/slug and Neptune account, or Google when your organization supports it.</p><?php if(!$u):?><a href="<?=e(base_url('index.php'))?>">Open sign in →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">3</div><h2>Add your scouts</h2><p>Open Teams &amp; Users, create accounts or invitations, and assign the right roles.</p><?php if($u && $canManageUsers):?><a href="<?=e(base_url('admin/teams.php'))?>">Teams &amp; Users →</a><?php endif;?></article>
    <article class="ntx-step"><div class="ntx-step-num">4</div><h2>Connect the event</h2><p>Create/select the event, load the roster, then sync FIRST or TBA when the official schedule is available.</p><?php if($u && $canManageUsers):?><a href="<?=e(base_url('admin/events.php'))?>">Event Setup →</a><?php endif;?></article>
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
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>FIRST/TBA roster and schedule synced</b><span>When FIRST or TBA publishes the schedule, sync it into the existing Neptune event.</span></div></div>
        <div class="ntx-check"><i class="fa-solid fa-check"></i><div><b>One practice pass completed</b><span>Have one scout sign in on the device they will actually use and verify the expected workflow.</span></div></div>
      </div>
      <div class="ntx-note"><b>Need the full training?</b> Once signed in, use the footer <b>Walkthrough</b> button or open the full User Guide / CBT training.</div>
      <?php if($u):?>
        <div class="ntx-signin">
          <span class="pill"><i class="fa-solid fa-circle-check"></i> Signed in as <?=e($u['display_name']??$u['username']??'Neptune user')?></span>
          <?php if($canManageUsers):?><a class="btn secondary" href="<?=e(base_url('admin/first-sync.php'))?>"><i class="fa-solid fa-arrows-rotate"></i> FIRST Sync</a><a class="btn secondary" href="<?=e(base_url('admin/tba-sync.php'))?>"><i class="fa-solid fa-arrows-rotate"></i> TBA Sync</a><?php endif;?>
          <a class="btn secondary" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open"></i> Full Guide</a>
        </div>
      <?php endif;?>
    </article>

    <aside class="ntx-card ntx-next-steps">
      <div class="ntx-kicker"><i class="fa-solid fa-compass" aria-hidden="true"></i> QUICK LINKS</div>
      <h2>Ready when you are</h2>
      <p>Follow the five setup steps above, then open Neptune and start collecting data.</p>
      <div class="ntx-next-links">
      <?php if(!$u):?>
        <a class="btn" href="<?=e(base_url('register.php'))?>"><i class="fa-solid fa-user-plus"></i> Register Organization</a>
        <a class="btn secondary" href="<?=e(base_url('index.php'))?>"><i class="fa-solid fa-right-to-bracket"></i> Sign In</a>
      <?php else:?>
        <a class="btn" href="<?=e(base_url('dashboard/index.php'))?>"><i class="fa-solid fa-house"></i> Open Neptune</a>
        <?php if($canManageUsers):?><a class="btn secondary" href="<?=e(base_url('admin/first-sync.php'))?>"><i class="fa-solid fa-arrows-rotate"></i> FIRST Sync</a><?php endif;?>
      <?php endif;?>
        <a class="btn secondary" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open"></i> Full User Guide</a>
      </div>
    </aside>
  </div>

  <div class="ntx-footer-note">Neptune is an independent community FRC scouting platform and is not affiliated with or endorsed by FIRST® or The Blue Alliance.</div>
</section>

<?php if(!$ntxPortableMode):?>
<script src="<?=e(base_url("assets/js/qrcode-v4l.js"))?>"></script>
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

  if(!window.NeptuneQR || typeof window.NeptuneQR.draw!=='function'){
    host.innerHTML='<div style="color:#111;padding:18px;font-size:.82rem;line-height:1.4">QR rendering is unavailable. Use the link below.</div>';
    return;
  }

  try {
    const canvas=document.createElement('canvas');
    canvas.setAttribute('aria-label','QR code to open the NTX Quick Start guide');
    canvas.setAttribute('role','img');
    window.NeptuneQR.draw(canvas,target,{quiet:4,scale:8});
    host.appendChild(canvas);
  } catch(err) {
    host.innerHTML='<div style="color:#111;padding:12px;font-size:.8rem;line-height:1.4">Open the guide with the link below.</div>';
  }
})();
</script>

<script>
(function(){
  'use strict';
  var host=document.querySelector('.ntx-showcase .ntx-ocean');
  if(!host)return;
  var reduced=window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var count=window.innerWidth<700?18:42;
  var frag=document.createDocumentFragment();
  for(var i=0;i<count;i++){
    var el=document.createElement('i');
    el.className='ntx-ocean-bubble';
    el.style.setProperty('--x',(Math.random()*100).toFixed(2)+'%');
    el.style.setProperty('--size',(4+Math.pow(Math.random(),1.5)*15).toFixed(1)+'px');
    el.style.setProperty('--duration',(12+Math.random()*18).toFixed(1)+'s');
    el.style.setProperty('--delay',(-Math.random()*29).toFixed(1)+'s');
    el.style.setProperty('--drift',(Math.random()*140-70).toFixed(1)+'px');
    el.style.setProperty('--alpha',(.28+Math.random()*.43).toFixed(2));
    frag.appendChild(el);
  }
  host.appendChild(frag);
})();
</script>

<?php include dirname(__DIR__).'/partials_footer.php';
