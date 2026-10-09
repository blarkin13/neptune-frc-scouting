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
