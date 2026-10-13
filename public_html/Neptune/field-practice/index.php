<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';

$cfg = neptune_field_practice_config();
neptune_field_practice_schema($pdo);

$errors = [];
$success = null;
if (!empty($_SESSION['field_practice_success']) && is_array($_SESSION['field_practice_success'])) {
    $success = $_SESSION['field_practice_success'];
    unset($_SESSION['field_practice_success']);
}

$form = [
    'practice_date' => '',
    'team_number' => '',
    'team_name' => '',
    'contact_name' => '',
    'contact_email' => '',
    'contact_phone' => '',
    'attendee_count' => '6',
    'arrival_window' => '5:00 PM',
    'focus' => [],
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['practice_date'] = trim((string)($_POST['practice_date'] ?? ''));
    $form['team_number'] = trim((string)($_POST['team_number'] ?? ''));
    $form['team_name'] = neptune_field_practice_clean_line((string)($_POST['team_name'] ?? ''), 160);
    $form['contact_name'] = neptune_field_practice_clean_line((string)($_POST['contact_name'] ?? ''), 160);
    $form['contact_email'] = strtolower(neptune_field_practice_clean_line((string)($_POST['contact_email'] ?? ''), 190));
    $form['contact_phone'] = neptune_field_practice_clean_line((string)($_POST['contact_phone'] ?? ''), 64);
    $form['attendee_count'] = trim((string)($_POST['attendee_count'] ?? ''));
    $form['arrival_window'] = neptune_field_practice_clean_line((string)($_POST['arrival_window'] ?? ''), 64);
    $focus = $_POST['focus'] ?? [];
    $form['focus'] = is_array($focus) ? array_values(array_map(fn($v) => neptune_field_practice_clean_line((string)$v, 80), $focus)) : [];
    $form['notes'] = trim((string)($_POST['notes'] ?? ''));
    if (function_exists('mb_substr')) $form['notes'] = mb_substr($form['notes'], 0, 2000); else $form['notes'] = substr($form['notes'], 0, 2000);

    // Simple bot trap. Humans never see or fill this field.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        http_response_code(400);
        $errors[] = 'The signup could not be submitted.';
    }

    $practiceDate = neptune_field_practice_parse_date($form['practice_date'], $cfg);
    if (!$practiceDate) {
        $errors[] = 'Choose a Sunday during the 2027 season.';
    } elseif (!neptune_field_practice_is_future_or_today($practiceDate)) {
        $errors[] = 'Choose an upcoming Sunday.';
    }

    $teamNumber = filter_var($form['team_number'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99999]]);
    if ($teamNumber === false) $errors[] = 'Enter a valid FRC team number.';
    if ($form['team_name'] === '') $errors[] = 'Enter your team or organization name.';
    if ($form['contact_name'] === '') $errors[] = 'Enter a contact name.';
    if (!filter_var($form['contact_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid contact email address.';

    $attendeeCount = filter_var($form['attendee_count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($attendeeCount === false) $errors[] = 'Estimated attendance must be between 1 and 100.';

    $allowedArrivals = neptune_field_practice_arrival_options();
    if (!in_array($form['arrival_window'], $allowedArrivals, true)) $errors[] = 'Choose an arrival time.';

    $allowedFocus = neptune_field_practice_focus_options();
    $form['focus'] = array_values(array_intersect($allowedFocus, $form['focus']));

    if (!$errors && $practiceDate) {
        $practiceKey = neptune_field_practice_key_for_date($practiceDate, $cfg);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO field_practice_signups
                (practice_key, team_number, team_name, contact_name, contact_email, contact_phone, attendee_count, arrival_window, practice_focus, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $practiceKey,
                (int)$teamNumber,
                $form['team_name'],
                $form['contact_name'],
                $form['contact_email'],
                $form['contact_phone'],
                (int)$attendeeCount,
                $form['arrival_window'],
                implode(', ', $form['focus']),
                $form['notes'],
            ]);

            $_SESSION['field_practice_success'] = [
                'team_number' => (int)$teamNumber,
                'team_name' => $form['team_name'],
                'practice_date' => $practiceDate->format('Y-m-d'),
                'date_label' => neptune_field_practice_date_label($practiceDate),
            ];
            header('Location: '.base_url('field-practice/?registered=1'), true, 303);
            exit;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $errors[] = 'Team #'.(int)$teamNumber.' is already registered for '.neptune_field_practice_date_label($practiceDate).'.';
            } else {
                $errors[] = 'The signup could not be saved. Please try again.';
            }
        }
    }
}

$like = neptune_field_practice_season_like($cfg);
$countStmt = $pdo->prepare("SELECT COUNT(*) AS registrations, COUNT(DISTINCT team_number) AS teams, COALESCE(SUM(attendee_count),0) AS people FROM field_practice_signups WHERE practice_key LIKE ? AND status='active'");
$countStmt->execute([$like]);
$counts = $countStmt->fetch() ?: ['registrations' => 0, 'teams' => 0, 'people' => 0];

$user = current_user();
$canManage = $user && in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string)$cfg['address']);

if (isset($_GET['calendar'])) {
    $calendarDate = neptune_field_practice_parse_date((string)$_GET['calendar'], $cfg);
    if (!$calendarDate) {
        http_response_code(400);
        exit('Invalid practice date.');
    }
    $start = $calendarDate->setTime(17, 0);
    $end = $calendarDate->setTime(20, 0);
    $key = neptune_field_practice_key_for_date($calendarDate, $cfg);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="msa-sunday-field-practice-'.$calendarDate->format('Y-m-d').'.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Neptune//Field Practice//EN\r\nBEGIN:VEVENT\r\n";
    echo 'UID:'.rawurlencode($key)."@neptune.mckinneysteamacademy.org\r\n";
    echo 'DTSTAMP:'.gmdate('Ymd\\THis\\Z')."\r\n";
    echo 'DTSTART:'.$start->format('Ymd\\THis')."\r\n";
    echo 'DTEND:'.$end->format('Ymd\\THis')."\r\n";
    echo "SUMMARY:MSA Sunday Field Practice\r\n";
    echo 'LOCATION:'.str_replace(["\r","\n",','], ['', '', '\\,'], (string)$cfg['address'])."\r\n";
    echo "DESCRIPTION:Sunday field practice hosted by McKinney STEAM Academy for NTX FRC teams.\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    exit;
}

$cssVersion = @filemtime(dirname(__DIR__).'/assets/css/field-practice.css') ?: 1;
$fontAwesomeVersion = @filemtime(dirname(__DIR__).'/assets/vendor/fontawesome/css/all.min.css') ?: 1;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#05080b">
<title><?=e($cfg['title'])?> | McKinney STEAM Academy</title>
<meta name="description" content="NTX FRC teams can sign up for Sunday evening field practice at McKinney STEAM Academy during the 2027 season, 5:00–8:00 PM.">
<meta property="og:title" content="MSA Sunday Field Practice · 2027 Season">
<meta property="og:description" content="Sunday field practice for NTX FRC teams at McKinney STEAM Academy · 5:00–8:00 PM · Choose a Sunday and sign up your team.">
<meta property="og:type" content="website">
<meta property="og:url" content="<?=e($cfg['public_url'])?>">
<link rel="icon" type="image/png" href="<?=e(base_url('images/favicon.png'))?>">
<link rel="stylesheet" href="<?=e(base_url('assets/vendor/fontawesome/css/all.min.css'))?>?v=<?=$fontAwesomeVersion?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/field-practice.css'))?>?v=<?=$cssVersion?>">
<script>
/* Neptune core branding: select the highest-visibility uploaded AVIF artwork for the actual surface.
 * Only official Neptune logo images are changed. Organization/team logos are never modified.
 * Original img src is retained as a fallback when the browser cannot load the AVIF.
 */
(()=>{
  'use strict';
  const ROOT=(()=>{const src=document.currentScript?.src||'';try{return new URL('../../',src).href;}catch(_){return '/';}})();
  const IMAGES={"logo-square-black.avif":"data:image/avif;base64,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","logo-rectangle-gray.avif":"data:image/avif;base64,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","logo-square-gray.avif":"data:image/avif;base64,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","logo-rectangle-black.avif":"data:image/avif;base64,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","logo-rectangle-blue.avif":"data:image/avif;base64,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","logo-square-blue.avif":"data:image/avif;base64,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"};
  const LUMA={gray:0.3774,blue:0.1867,black:0.00014};
  const variants=['gray','blue','black'];
  const registered=new WeakSet();
  const clamp=(v,a,b)=>Math.min(b,Math.max(a,v));
  const lum=(rgb)=>{
    const c=rgb.map(v=>{v=clamp(v,0,255)/255;return v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4;});
    return .2126*c[0]+.7152*c[1]+.0722*c[2];
  };
  const ratio=(a,b)=>(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
  function color(str){
    if(!str)return null;
    let m=str.trim().match(/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i);
    if(m){let s=m[1];if(s.length===3)s=s.split('').map(c=>c+c).join('');
      return [parseInt(s.slice(0,2),16),parseInt(s.slice(2,4),16),parseInt(s.slice(4,6),16),s.length===8?parseInt(s.slice(6,8),16)/255:1];}
    m=str.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)$/i);
    return m?[Number(m[1]),Number(m[2]),Number(m[3]),m[4]===undefined?1:Number(m[4])]:null;
  }
  function over(dst,paint){const a=clamp(paint[3],0,1);return dst.map((v,i)=>v*(1-a)+paint[i]*a);}
  // CSS colors in the layer stack, including average tones for gradient-backed cards.
  function gradientColor(value){
    if(!value||value==='none'||!/gradient\(/i.test(value))return null;
    const hits=value.match(/(?:rgba?\([^)]*\)|#[0-9a-f]{3,8}\b)/gi)||[];
    const colors=hits.map(color).filter(Boolean).filter(c=>c[3]>.1);
    if(!colors.length)return null;
    // Approximate the perceived surface. Transparency is respected.
    const count=Math.min(colors.length,6);const picked=colors.slice(0,count);
    const weight=picked.reduce((v,c)=>v+c[3],0);
    return [0,1,2].map(i=>picked.reduce((v,c)=>v+c[i]*c[3],0)/weight).concat([picked.reduce((v,c)=>v+c[3],0)/count]);
  }
  function surface(img){
    const isLight=document.documentElement.dataset.theme==='light'||document.documentElement.classList.contains('light');
    let out=isLight?[245,247,250]:[8,17,29];
    const layers=[];
    for(let el=img.parentElement;el;el=el.parentElement){
      const style=getComputedStyle(el);
      // CSS background image paints ON TOP OF the solid background color.
      const solid=color(style.backgroundColor);
      const grad=gradientColor(style.backgroundImage);
      if(grad&&grad[3]>.01)layers.push(grad);
      if(solid&&solid[3]>.01)layers.push(solid);
      if(el===document.documentElement)break;
    }
    for(let i=layers.length-1;i>=0;i--)out=over(out,layers[i]);
    return lum(out);
  }
  function official(img){
    if(img.dataset.neptuneBrand)return true;
    const initial=img.getAttribute('src')||'';
    try{
      const path=new URL(initial,document.baseURI).pathname;
      return /\/images\/(?:logo\.png|logoSilver(?:Dark|Horizontal)?\.png)$/i.test(path);
    }catch(_){return false;}
  }
  function shape(img){
    let s=img.dataset.neptuneBrand||'';
    if(s!=='square'&&s!=='rectangle'){
      const src=img.getAttribute('src')||'';
      s=/Horizontal|home-hero-logo/i.test(src+' '+img.className)?'rectangle':'square';
    }
    if(img.dataset.neptuneBrandMobile==='square'&&matchMedia('(max-width: 780px)').matches)return 'square';
    return s;
  }
  function choose(bg){
    const ranked=variants.map(k=>({key:k,contrast:ratio(bg,LUMA[k])})).sort((a,b)=>b.contrast-a.contrast);
    const blue=ratio(bg,LUMA.blue);
    // Use Neptune blue as an accent only when it is independently legible.
    // Otherwise take the strongest of the gray and black artwork.
    if(blue>=4.3&&blue>=ranked[0].contrast*.53)return 'blue';
    return ranked[0].key;
  }
  function update(img){
    if(!official(img)||img.dataset.neptuneBrandUnavailable==='1')return;
    if(!img.dataset.neptuneBrandOriginal)img.dataset.neptuneBrandOriginal=img.getAttribute('src')||'';
    const file='logo-'+shape(img)+'-'+choose(surface(img))+'.avif';
    const next=IMAGES[file];
    if(img.src!==next){img.dataset.neptuneBrandTarget=next;img.src=next;}
    img.classList.add('neptune-brand-variant');
  }
  function scan(){
    document.querySelectorAll('img').forEach(img=>{
      if(!official(img))return;
      if(!registered.has(img)){
        registered.add(img);
        img.addEventListener('error',()=>{
          if(!img.dataset.neptuneBrandTarget||img.src!==img.dataset.neptuneBrandTarget)return;
          img.dataset.neptuneBrandUnavailable='1';
          const original=img.dataset.neptuneBrandOriginal||'';
          img.removeAttribute('data-neptune-brand-target');
          img.src=original!==img.src?original:ROOT+'images/logo.png';
        });
      }
      update(img);
    });
  }
  let scheduled=false;
  function schedule(){if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;scan();});}
  function init(){
    scan();
    if(document.body)new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
    new MutationObserver(schedule).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme','class','style']});
    window.addEventListener('resize',schedule,{passive:true});
    window.matchMedia?.('(prefers-color-scheme: dark)')?.addEventListener?.('change',schedule);
    window.NeptuneBranding={refresh:schedule};
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

</script>
</head>
<body>
<div class="fp-shell">
<header class="fp-topbar">
  <a class="fp-brand" href="<?=e(base_url())?>" aria-label="Neptune home">
    <img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune" width="64" height="64">
    <span><strong>NEPTUNE</strong><small>McKinney STEAM Academy</small></span>
  </a>
  <div class="fp-top-actions">
    <a class="fp-site-link" href="https://www.mckinneysteamacademy.org/home" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> MSA website</a>
    <?php if($canManage):?><a class="fp-admin-link" href="<?=e(base_url('admin/field-practice-signups.php'))?>"><i class="fa-solid fa-list-check"></i> Manage signups</a><?php endif;?>
  </div>
</header>

<main>
  <section class="fp-hero">
    <div class="fp-hero-copy">
      <div class="fp-eyebrow"><i class="fa-solid fa-robot"></i> NORTH TEXAS · 2027 FRC SEASON</div>
      <div class="fp-host-lockup">
        <span class="fp-host-mark fp-host-mark-msa"><img src="<?=e(base_url('assets/images/msa-field-practice-logo.png'))?>" alt="McKinney STEAM Academy"></span>
        <div><small>HOSTED BY</small><strong>McKinney STEAM Academy</strong><span>Home of FRC 6369 Mercenary Robotics</span></div>
        <span class="fp-host-mark fp-host-mark-merc"><img src="<?=e(base_url('assets/images/mercenary-field-practice-logo.png'))?>" alt="Mercenary Robotics 6369"></span>
      </div>
      <h1>Sunday Night Field Practice</h1>
      <p>McKinney STEAM Academy is opening its practice field to North Texas FRC teams on Sunday evenings during the 2027 season. Choose the Sunday your team plans to attend so we can coordinate field time and shop capacity.</p>
      <div class="fp-facts">
        <div><i class="fa-solid fa-calendar-days"></i><span><small>When</small><strong>Sundays during the 2027 FRC season</strong></span></div>
        <div><i class="fa-solid fa-clock"></i><span><small>Time</small><strong><?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?></strong></span></div>
        <div><i class="fa-solid fa-location-dot"></i><span><small>Location</small><strong><?=e($cfg['address'])?></strong></span></div>
      </div>
      <div class="fp-hero-actions">
        <a class="fp-btn fp-btn-primary" href="#signup"><i class="fa-solid fa-clipboard-check"></i> Sign up your team</a>
        <a class="fp-btn" href="<?=e($mapsUrl)?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Directions</a>
      </div>
    </div>
    <aside class="fp-qr-card">
      <div class="fp-qr-kicker"><span>MSA FIELD PRACTICE</span><b>Registration powered by Neptune</b></div>
      <img src="<?=e(base_url($cfg['qr_image']))?>" width="520" height="520" alt="QR code for the MSA Sunday Field Practice signup page">
      <strong>One QR for the whole season</strong>
      <span>neptune.mckinneysteamacademy.org/field-practice/</span>
      <a href="<?=e(base_url('field-practice/qr.php'))?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Printable QR flyer</a>
    </aside>
  </section>

  <section class="fp-grid">
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-flag-checkered"></i></div>
      <h2>MSA practice field</h2>
      <p>Bring your robot and use MSA’s field for driver reps, autonomous testing, cycles, defense, endgame work, or full-match practice.</p>
    </article>
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-calendar-check"></i></div>
      <h2>Sign up before you come</h2>
      <p>Choose the Sunday your team plans to attend. Teams can register for more than one Sunday as the season progresses.</p>
    </article>
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-shield-halved"></i></div>
      <h2>Come field-ready</h2>
      <p>Teams are responsible for their own students, mentors, robot, batteries, tools, and normal shop and field safety practices.</p>
    </article>
  </section>

  <section class="fp-signup" id="signup">
    <div class="fp-section-head">
      <div>
        <div class="fp-eyebrow"><i class="fa-solid fa-clipboard-list"></i> 2027 TEAM REGISTRATION</div>
        <h2>Choose a Sunday</h2>
        <p>Submit one registration per team for each Sunday you plan to attend. This gives MSA a useful headcount and helps us coordinate shared field time.</p>
      </div>
      <div class="fp-counts" aria-label="2027 registration totals">
        <div><strong><?=e((int)$counts['teams'])?></strong><span>teams signed up</span></div>
        <div><strong><?=e((int)$counts['registrations'])?></strong><span>Sunday registrations</span></div>
      </div>
    </div>

    <?php if($success):?>
      <div class="fp-success">
        <i class="fa-solid fa-circle-check"></i>
        <div>
          <strong>Team #<?=e((int)$success['team_number'])?> is on the list for <?=e((string)$success['date_label'])?>.</strong>
          <span><?=e((string)$success['team_name'])?> can register again later for another Sunday.</span>
          <a class="fp-success-link" href="<?=e(base_url('field-practice/?calendar='.(string)$success['practice_date']))?>"><i class="fa-solid fa-calendar-plus"></i> Add this practice to calendar</a>
        </div>
      </div>
    <?php endif;?>

    <?php if($errors):?>
      <div class="fp-errors" role="alert">
        <strong>Please fix the following:</strong>
        <ul><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul>
      </div>
    <?php endif;?>

    <form method="post" class="fp-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <div class="fp-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>

      <div class="fp-practice-date">
        <label>
          <span>Which Sunday are you planning to attend? *</span>
          <input type="date" name="practice_date" required value="<?=e($form['practice_date'])?>" min="2027-01-01" max="2027-12-31">
          <small>Choose a Sunday in 2027. If MSA’s schedule changes for a particular week, we’ll use the contact information below to coordinate with registered teams.</small>
        </label>
      </div>

      <div class="fp-form-grid">
        <label><span>FRC team number *</span><input type="number" name="team_number" min="1" max="99999" inputmode="numeric" required value="<?=e($form['team_number'])?>" placeholder="6369"></label>
        <label><span>Team / organization name *</span><input type="text" name="team_name" maxlength="160" required value="<?=e($form['team_name'])?>" placeholder="Mercenary Robotics"></label>
        <label><span>Primary contact *</span><input type="text" name="contact_name" maxlength="160" required value="<?=e($form['contact_name'])?>" placeholder="Name"></label>
        <label><span>Email *</span><input type="email" name="contact_email" maxlength="190" required value="<?=e($form['contact_email'])?>" placeholder="name@example.com"></label>
        <label><span>Phone <small>optional</small></span><input type="tel" name="contact_phone" maxlength="64" value="<?=e($form['contact_phone'])?>" placeholder="(555) 555-5555"></label>
        <label><span>Estimated people attending *</span><input type="number" name="attendee_count" min="1" max="100" required value="<?=e($form['attendee_count'])?>"></label>
        <label><span>Planned arrival *</span><select name="arrival_window" required><?php foreach(neptune_field_practice_arrival_options() as $option):?><option value="<?=e($option)?>" <?=$form['arrival_window']===$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select></label>
      </div>

      <fieldset>
        <legend>What do you want to practice? <small>optional</small></legend>
        <div class="fp-choice-grid">
          <?php foreach(neptune_field_practice_focus_options() as $option):?>
          <label class="fp-choice"><input type="checkbox" name="focus[]" value="<?=e($option)?>" <?=in_array($option,$form['focus'],true)?'checked':''?>><span><i class="fa-solid fa-check"></i><?=e($option)?></span></label>
          <?php endforeach;?>
        </div>
      </fieldset>

      <label class="fp-notes"><span>Anything else we should know? <small>optional</small></span><textarea name="notes" rows="4" maxlength="2000" placeholder="Special field needs, timing constraints, another team you want to run with, etc."><?=e($form['notes'])?></textarea></label>

      <div class="fp-submit-row">
        <p>Submitting gives McKinney STEAM Academy the Sunday you plan to attend, a headcount, and a contact for coordinating practice.</p>
        <button class="fp-btn fp-btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Register for Sunday Practice</button>
      </div>
    </form>
  </section>
</main>

<footer class="fp-footer"><img src="<?=e(base_url('images/logo.png'))?>" alt="" width="36" height="36"><span><a href="https://www.mckinneysteamacademy.org/home" target="_blank" rel="noopener">McKinney STEAM Academy</a> · Field Practice registration powered by Neptune</span></footer>
</div>
<script>
(function(){
  const input=document.querySelector('input[name="practice_date"]');
  if(!input) return;
  input.addEventListener('change',function(){
    if(!this.value) return;
    const d=new Date(this.value+'T12:00:00');
    if(d.getDay()!==0){
      this.setCustomValidity('Please choose a Sunday.');
      this.reportValidity();
    } else {
      this.setCustomValidity('');
    }
  });
})();
</script>
</body>
</html>
