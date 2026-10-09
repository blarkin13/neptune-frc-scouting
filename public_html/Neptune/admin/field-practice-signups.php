<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/field-practice/_helpers.php';
$u = require_role(['owner','admin']);
$cfg = neptune_field_practice_config();
neptune_field_practice_schema($pdo);
$like = neptune_field_practice_season_like($cfg);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare('SELECT practice_key,team_number,team_name,contact_name,contact_email,contact_phone,attendee_count,arrival_window,practice_focus,notes,status,created_at FROM field_practice_signups WHERE practice_key LIKE ? ORDER BY practice_key, status, team_number');
    $stmt->execute([$like]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="msa-2027-sunday-field-practice-signups.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Sunday','Team','Team Name','Contact','Email','Phone','Attendees','Arrival','Practice Focus','Notes','Status','Submitted']);
    foreach ($stmt->fetchAll() as $row) {
        $date = neptune_field_practice_date_from_key((string)$row['practice_key'], $cfg);
        fputcsv($out, [
            $date ? $date->format('Y-m-d') : $row['practice_key'],
            $row['team_number'], $row['team_name'], $row['contact_name'], $row['contact_email'], $row['contact_phone'],
            $row['attendee_count'], $row['arrival_window'], $row['practice_focus'], $row['notes'], $row['status'], $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($id > 0 && in_array($action, ['cancel','restore'], true)) {
        $status = $action === 'cancel' ? 'cancelled' : 'active';
        $stmt = $pdo->prepare('UPDATE field_practice_signups SET status=? WHERE id=? AND practice_key LIKE ?');
        $stmt->execute([$status, $id, $like]);
    }
    header('Location: '.base_url('admin/field-practice-signups.php'), true, 303);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM field_practice_signups WHERE practice_key LIKE ? ORDER BY practice_key ASC, status ASC, team_number ASC');
$stmt->execute([$like]);
$rows = $stmt->fetchAll();
$active = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'active'));
$registrations = count($active);
$teams = count(array_unique(array_map(fn($r) => (int)($r['team_number'] ?? 0), $active)));
$people = array_sum(array_map(fn($r) => (int)($r['attendee_count'] ?? 0), $active));

$byDate = [];
foreach ($rows as $row) {
    $date = neptune_field_practice_date_from_key((string)$row['practice_key'], $cfg);
    $dateKey = $date ? $date->format('Y-m-d') : (string)$row['practice_key'];
    $byDate[$dateKey]['date'] = $date;
    $byDate[$dateKey]['rows'][] = $row;
}

$pageTitle = '2027 Sunday Field Practice Signups';
$moduleName = 'SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="neptune-page-hero neptune-page-hero--saturn">
  <div class="neptune-page-hero-copy">
    <div class="module-eyebrow"><span>SATURN</span><small>Public Field Practice</small></div>
    <h1>Sunday Field Practice Signups</h1>
    <p>2027 FRC season · Sundays · <?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?> · <?=e($cfg['location_name'])?></p>
    <div class="neptune-page-hero-steps">
      <span><i class="fa-solid fa-robot"></i> <?=$teams?> teams</span>
      <span><i class="fa-solid fa-calendar-check"></i> <?=$registrations?> registrations</span>
      <span><i class="fa-solid fa-people-group"></i> <?=$people?> people</span>
    </div>
  </div>
  <div class="neptune-page-hero-actions">
    <a class="btn secondary" href="<?=e(base_url('field-practice/'))?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public page</a>
    <a class="btn secondary" href="<?=e(base_url('field-practice/qr.php'))?>" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode"></i> QR flyer</a>
    <a class="btn secondary" href="?export=csv"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
  </div>
</section>

<?php if(!$rows):?>
<div class="card" style="margin-top:16px"><div class="notice">No 2027 Sunday field-practice registrations yet.</div></div>
<?php else:?>
  <?php foreach($byDate as $dateKey => $group):
    $date = $group['date'];
    $groupRows = $group['rows'];
    $groupActive = array_values(array_filter($groupRows, fn($r) => ($r['status'] ?? '') === 'active'));
    $groupPeople = array_sum(array_map(fn($r) => (int)($r['attendee_count'] ?? 0), $groupActive));
  ?>
  <div class="card" style="margin-top:16px">
    <div class="toolbar" style="justify-content:space-between;align-items:flex-start">
      <div>
        <h2 style="margin:0"><?=e($date ? neptune_field_practice_date_label($date) : $dateKey)?></h2>
        <p class="muted" style="margin:6px 0 0"><?=count($groupActive)?> active team registration<?=count($groupActive)===1?'':'s'?> · <?=$groupPeople?> estimated attendees</p>
      </div>
      <span class="pill">5:00–8:00 PM</span>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Team</th><th>Contact</th><th>Attendance</th><th>Practice plan</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach($groupRows as $row):?>
          <tr style="<?=$row['status']==='cancelled'?'opacity:.55':''?>">
            <td><b>#<?=e($row['team_number'])?></b><br><?=e($row['team_name'])?></td>
            <td><b><?=e($row['contact_name'])?></b><br><a href="mailto:<?=e($row['contact_email'])?>"><?=e($row['contact_email'])?></a><?php if($row['contact_phone']):?><br><a href="tel:<?=e(preg_replace('/[^0-9+]/','',$row['contact_phone']))?>"><?=e($row['contact_phone'])?></a><?php endif;?></td>
            <td><b><?=e($row['attendee_count'])?> people</b><br><span class="muted">Arrive <?=e($row['arrival_window'])?></span></td>
            <td><?=e(trim((string)$row['practice_focus']) !== '' ? $row['practice_focus'] : '—')?><?php if($row['notes']):?><br><small class="muted"><?=nl2br(e($row['notes']))?></small><?php endif;?></td>
            <td><?=e($row['created_at'])?></td>
            <td><span class="pill"><?=e(strtoupper($row['status']))?></span></td>
            <td>
              <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($row['id'])?>">
                <?php if($row['status']==='active'):?><button class="secondary" name="action" value="cancel"><i class="fa-solid fa-ban"></i> Cancel</button><?php else:?><button class="secondary" name="action" value="restore"><i class="fa-solid fa-rotate-left"></i> Restore</button><?php endif;?>
              </form>
            </td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach;?>
<?php endif;?>
<?php include dirname(__DIR__).'/partials_footer.php';
