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
    if (!$form['focus']) $errors[] = 'Choose at least one practice goal.';

    if (!neptune_field_practice_is_open($cfg)) $errors[] = 'Registration for this practice is closed.';

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO field_practice_signups
                (practice_key, team_number, team_name, contact_name, contact_email, contact_phone, attendee_count, arrival_window, practice_focus, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $cfg['practice_key'],
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
            ];
            header('Location: '.base_url('field-practice/?registered=1'), true, 303);
            exit;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $errors[] = 'Team #'.(int)$teamNumber.' is already registered for this practice.';
            } else {
                $errors[] = 'The signup could not be saved. Please try again.';
            }
        }
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS teams, COALESCE(SUM(attendee_count),0) AS people FROM field_practice_signups WHERE practice_key=? AND status='active'");
$countStmt->execute([$cfg['practice_key']]);
$counts = $countStmt->fetch() ?: ['teams' => 0, 'people' => 0];
$isOpen = neptune_field_practice_is_open($cfg);
$user = current_user();
$canManage = $user && in_array((string)($user['role'] ?? ''), ['owner','admin'], true);
$mapsUrl = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string)$cfg['address']);
$calendarUrl = base_url('field-practice/?calendar=1');

if (isset($_GET['calendar'])) {
    $start = new DateTimeImmutable((string)$cfg['start_at']);
    $end = new DateTimeImmutable((string)$cfg['end_at']);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="ntx-sunday-field-practice.ics"');
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Neptune//Field Practice//EN\r\nBEGIN:VEVENT\r\n";
    echo 'UID:'.rawurlencode((string)$cfg['practice_key'])."@neptune.mckinneysteamacademy.org\r\n";
    echo 'DTSTAMP:'.gmdate('Ymd\\THis\\Z')."\r\n";
    echo 'DTSTART:'.$start->format('Ymd\\THis')."\r\n";
    echo 'DTEND:'.$end->format('Ymd\\THis')."\r\n";
    echo 'SUMMARY:'.str_replace(["\r","\n"], '', (string)$cfg['title'])."\r\n";
    echo 'LOCATION:'.str_replace(["\r","\n",','], ['', '', '\\,'], (string)$cfg['address'])."\r\n";
    echo "DESCRIPTION:Open field practice hosted by McKinney STEM Academy.\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
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
<title><?=e($cfg['title'])?> | McKinney STEM Academy</title>
<meta name="description" content="Sign up for NTX Sunday field practice at McKinney STEM Academy, <?=e($cfg['date_label'])?> from <?=e($cfg['start_label'])?> to <?=e($cfg['end_label'])?>.">
<meta property="og:title" content="<?=e($cfg['title'])?>">
<meta property="og:description" content="Open field practice at McKinney STEM Academy · <?=e($cfg['date_label'])?> · <?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?>">
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
    <span><strong>NEPTUNE</strong><small>McKinney STEM Academy</small></span>
  </a>
  <?php if($canManage):?><a class="fp-admin-link" href="<?=e(base_url('admin/field-practice-signups.php'))?>"><i class="fa-solid fa-list-check"></i> Manage signups</a><?php endif;?>
</header>

<main>
  <section class="fp-hero">
    <div class="fp-hero-copy">
      <div class="fp-eyebrow"><i class="fa-solid fa-robot"></i> NORTH TEXAS · OPEN FIELD PRACTICE</div>
      <h1>Sunday night field practice.</h1>
      <p>McKinney STEM Academy is opening the shop for NTX teams to get field time before the next competition. Bring your robot, drive team, and whatever you want to work on.</p>
      <div class="fp-facts">
        <div><i class="fa-solid fa-calendar-day"></i><span><small>Date</small><strong><?=e($cfg['date_label'])?></strong></span></div>
        <div><i class="fa-solid fa-clock"></i><span><small>Time</small><strong><?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?></strong></span></div>
        <div><i class="fa-solid fa-location-dot"></i><span><small>Location</small><strong><?=e($cfg['address'])?></strong></span></div>
      </div>
      <div class="fp-hero-actions">
        <a class="fp-btn fp-btn-primary" href="#signup"><i class="fa-solid fa-clipboard-check"></i> Sign up your team</a>
        <a class="fp-btn" href="<?=e($mapsUrl)?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i> Directions</a>
        <a class="fp-btn" href="<?=e($calendarUrl)?>"><i class="fa-solid fa-calendar-plus"></i> Add to calendar</a>
      </div>
    </div>
    <aside class="fp-qr-card">
      <img src="<?=e(base_url($cfg['qr_image']))?>" width="520" height="520" alt="QR code for the NTX Sunday Field Practice signup page">
      <strong>Scan to sign up</strong>
      <span>neptune.mckinneysteamacademy.org/field-practice/</span>
      <a href="<?=e(base_url('field-practice/qr.php'))?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Printable QR flyer</a>
    </aside>
  </section>

  <section class="fp-grid">
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-flag-checkered"></i></div>
      <h2>What this is</h2>
      <p>An informal, shared field-practice block. Use the time for driver reps, autonomous testing, cycles, defense, endgame work, or full-match practice.</p>
    </article>
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-people-group"></i></div>
      <h2>Plan ahead</h2>
      <p>Please submit one registration per FRC team so we can estimate attendance and organize field time.</p>
    </article>
    <article class="fp-card">
      <div class="fp-card-icon"><i class="fa-solid fa-shield-halved"></i></div>
      <h2>Come field-ready</h2>
      <p>Teams are responsible for their own students, mentors, robot, batteries, tools, and normal shop/field safety practices.</p>
    </article>
  </section>

  <section class="fp-signup" id="signup">
    <div class="fp-section-head">
      <div>
        <div class="fp-eyebrow"><i class="fa-solid fa-clipboard-list"></i> TEAM REGISTRATION</div>
        <h2>Reserve a practice slot</h2>
        <p>One signup per team. This gives us enough information to plan the night and share the field fairly.</p>
      </div>
      <div class="fp-counts" aria-label="Current registration totals">
        <div><strong><?=e((int)$counts['teams'])?></strong><span>teams registered</span></div>
        <div><strong><?=e((int)$counts['people'])?></strong><span>estimated attendees</span></div>
      </div>
    </div>

    <?php if($success):?>
      <div class="fp-success">
        <i class="fa-solid fa-circle-check"></i>
        <div><strong>Team #<?=e((int)$success['team_number'])?> is registered.</strong><span>We have <?=e((string)$success['team_name'])?> on the list for Sunday night.</span></div>
      </div>
    <?php endif;?>

    <?php if($errors):?>
      <div class="fp-errors" role="alert">
        <strong>Please fix the following:</strong>
        <ul><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul>
      </div>
    <?php endif;?>

    <?php if($isOpen):?>
    <form method="post" class="fp-form" autocomplete="on">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <div class="fp-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>

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
        <legend>What do you want to practice? *</legend>
        <div class="fp-choice-grid">
          <?php foreach(neptune_field_practice_focus_options() as $option):?>
          <label class="fp-choice"><input type="checkbox" name="focus[]" value="<?=e($option)?>" <?=in_array($option,$form['focus'],true)?'checked':''?>><span><i class="fa-solid fa-check"></i><?=e($option)?></span></label>
          <?php endforeach;?>
        </div>
      </fieldset>

      <label class="fp-notes"><span>Anything else we should know? <small>optional</small></span><textarea name="notes" rows="4" maxlength="2000" placeholder="Special field needs, timing constraints, another team you want to run with, etc."><?=e($form['notes'])?></textarea></label>

      <div class="fp-submit-row">
        <p>By submitting, you’re giving McKinney STEM Academy a headcount and contact for this practice night.</p>
        <button class="fp-btn fp-btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Register team</button>
      </div>
    </form>
    <?php else:?>
      <div class="fp-closed"><i class="fa-solid fa-lock"></i><div><strong>Registration is closed.</strong><span>This practice window has ended.</span></div></div>
    <?php endif;?>
  </section>
</main>

<footer class="fp-footer"><img src="<?=e(base_url('images/logo.png'))?>" alt="" width="36" height="36"><span>McKinney STEM Academy · Powered by Neptune</span></footer>
</div>
</body>
</html>
