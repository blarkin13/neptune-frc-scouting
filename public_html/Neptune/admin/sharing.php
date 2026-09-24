<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$isConfigAdmin=in_array($u['role'],['owner','admin'],true);
$msg='';
$error='';

$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? ORDER BY frc_team_number');
$s->execute([$org]);
$mine=$s->fetchAll();

$s=$pdo->prepare('SELECT id,name FROM organizations WHERE id<>? ORDER BY name');
$s->execute([$org]);
$otherOrgs=$s->fetchAll();

$s=$pdo->prepare("SELECT g.id,g.name,g.season_year,gr.revision_number FROM games g JOIN game_revisions gr ON gr.id=g.current_revision_id AND gr.status='published' WHERE g.organization_id=? ORDER BY g.season_year DESC,g.name");
$s->execute([$org]);
$ownedGames=$s->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $kind=(string)($_POST['kind']??'data_share');

    try{
        if($kind==='data_share'){
            $ownerTeam=(int)($_POST['owner_team_id']??0);
            $ownerCheck=$pdo->prepare('SELECT id FROM teams WHERE id=? AND organization_id=?');
            $ownerCheck->execute([$ownerTeam,$org]);
            if(!$ownerCheck->fetchColumn()) throw new RuntimeException('The sharing source team must belong to your organization.');

            $recipient=(int)($_POST['recipient_team_number']??0);
            $s=$pdo->prepare('SELECT id FROM teams WHERE frc_team_number=? ORDER BY id LIMIT 1');
            $s->execute([$recipient]);
            $rid=$s->fetchColumn();
            if(!$rid){
                throw new RuntimeException('Recipient team must already have a Neptune team account.');
            }

            $q="INSERT INTO sharing_relationships(
                    owner_team_id,recipient_team_id,status,
                    share_match_data,share_pit_data,share_notes,share_raw_actions,share_analytics
                ) VALUES(?,?,'active',?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    status='active',
                    share_match_data=VALUES(share_match_data),
                    share_pit_data=VALUES(share_pit_data),
                    share_notes=VALUES(share_notes),
                    share_raw_actions=VALUES(share_raw_actions),
                    share_analytics=VALUES(share_analytics)";
            $pdo->prepare($q)->execute([
                $ownerTeam,$rid,
                isset($_POST['match'])?1:0,
                isset($_POST['pit'])?1:0,
                isset($_POST['notes'])?1:0,
                isset($_POST['raw'])?1:0,
                isset($_POST['analytics'])?1:0
            ]);
            $msg='Data sharing permissions saved.';
        } elseif($kind==='game_config_share'){
            if(!$isConfigAdmin) throw new RuntimeException('Admin access is required to share game configurations.');

            $gameId=(int)($_POST['game_id']??0);
            $recipientOrg=(int)($_POST['recipient_organization_id']??0);

            $s=$pdo->prepare('SELECT g.id,g.name,g.current_revision_id,gr.revision_number FROM games g LEFT JOIN game_revisions gr ON gr.id=g.current_revision_id WHERE g.id=? AND g.organization_id=?');
            $s->execute([$gameId,$org]);
            $game=$s->fetch();
            if(!$game) throw new RuntimeException('You can only share a game configuration owned by your organization.');
            if((int)($game['current_revision_id']??0)<=0) throw new RuntimeException('Publish the game configuration before sharing it.');

            if($recipientOrg<=0 || $recipientOrg===$org) throw new RuntimeException('Choose another Neptune organization.');
            $s=$pdo->prepare('SELECT id,name FROM organizations WHERE id=?');
            $s->execute([$recipientOrg]);
            $recipientOrgRow=$s->fetch();
            if(!$recipientOrgRow) throw new RuntimeException('Recipient organization was not found.');

            $q="INSERT INTO game_config_shares(game_id,recipient_organization_id,shared_by)
                VALUES(?,?,?)
                ON DUPLICATE KEY UPDATE shared_by=VALUES(shared_by),created_at=CURRENT_TIMESTAMP";
            $pdo->prepare($q)->execute([$gameId,$recipientOrg,$u['id']]);
            $msg='Game configuration shared read-only with '.$recipientOrgRow['name'].'.';
        } elseif($kind==='revoke_game_config'){
            if(!$isConfigAdmin) throw new RuntimeException('Admin access is required to revoke game configuration sharing.');

            $shareId=(int)($_POST['share_id']??0);
            $s=$pdo->prepare(
                'DELETE gcs
                 FROM game_config_shares gcs
                 JOIN games g ON g.id=gcs.game_id
                 WHERE gcs.id=? AND g.organization_id=?'
            );
            $s->execute([$shareId,$org]);
            $msg=$s->rowCount()?'Game configuration sharing revoked.':'Share not found.';
        } else {
            throw new RuntimeException('Unknown sharing action.');
        }
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$q="SELECT sr.*,a.frc_team_number owner_num,b.frc_team_number recipient_num
    FROM sharing_relationships sr
    JOIN teams a ON a.id=sr.owner_team_id
    JOIN teams b ON b.id=sr.recipient_team_id
    WHERE a.organization_id=?
    ORDER BY a.frc_team_number,b.frc_team_number";
$s=$pdo->prepare($q);
$s->execute([$org]);
$rels=$s->fetchAll();

$s=$pdo->prepare(
    "SELECT gcs.id,gcs.game_id,g.name,g.season_year,gr.revision_number,o.name recipient_org_name,
            u.display_name shared_by_name,gcs.created_at
     FROM game_config_shares gcs
     JOIN games g ON g.id=gcs.game_id
     JOIN game_revisions gr ON gr.id=g.current_revision_id
     JOIN organizations o ON o.id=gcs.recipient_organization_id
     LEFT JOIN users u ON u.id=gcs.shared_by
     WHERE g.organization_id=?
     ORDER BY g.season_year DESC,g.name,o.name"
);
$s->execute([$org]);
$configShares=$s->fetchAll();

$s=$pdo->prepare(
    "SELECT gcs.id,g.id game_id,g.name,g.season_year,gr.revision_number,o.name owner_org_name,gcs.created_at
     FROM game_config_shares gcs
     JOIN games g ON g.id=gcs.game_id
     JOIN game_revisions gr ON gr.id=g.current_revision_id
     JOIN organizations o ON o.id=g.organization_id
     WHERE gcs.recipient_organization_id=?
     ORDER BY g.season_year DESC,g.name,o.name"
);
$s->execute([$org]);
$receivedConfigShares=$s->fetchAll();

$pageTitle='Data Sharing';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>
<div class="module-eyebrow"><span>SATURN</span><small>Administration</small></div>
<h1>Data Sharing</h1>
<p class="muted">Share scouting data between teams and share game configurations between organizations. Shared game configurations are always read-only; recipients must clone a configuration before using or changing their own copy.</p>

<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>

<div class="grid">
  <div class="card">
    <h2>Scouting Data Sharing</h2>
    <p class="muted">Existing team-to-team sharing controls for match, pit, notes, raw actions, and analytics.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="kind" value="data_share">
      <div class="grid">
        <div>
          <label>Our team</label>
          <select name="owner_team_id">
            <?php foreach($mine as $t):?><option value="<?=$t['id']?>">#<?=e($t['frc_team_number'])?></option><?php endforeach;?>
          </select>
        </div>
        <div>
          <label>Recipient FRC team number</label>
          <input type="number" name="recipient_team_number" required>
        </div>
      </div>
      <div class="toolbar">
        <label><input style="width:auto" type="checkbox" name="match" checked> Match data</label>
        <label><input style="width:auto" type="checkbox" name="raw" checked> Raw actions</label>
        <label><input style="width:auto" type="checkbox" name="analytics" checked> Analytics</label>
        <label><input style="width:auto" type="checkbox" name="pit"> Pit</label>
        <label><input style="width:auto" type="checkbox" name="notes"> Notes</label>
      </div>
      <button>Save data sharing</button>
    </form>
  </div>

  <div class="card">
    <h2>Game Configuration Sharing</h2>
    <p class="muted">Shares the current published Match, Pit, Pre-Scout, and field configuration as one read-only game. Draft revisions are never exposed to recipients.</p>
    <?php if(!$isConfigAdmin):?>
      <div class="notice"><i class="fa-solid fa-lock"></i> Admin access is required to change configuration sharing.</div>
    <?php elseif(!$ownedGames):?>
      <div class="notice">Your organization does not own any game configurations yet.</div>
    <?php elseif(!$otherOrgs):?>
      <div class="notice">There are no other Neptune organizations to share with yet.</div>
    <?php else:?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="kind" value="game_config_share">
        <label>Our game configuration</label>
        <select name="game_id" required>
          <?php foreach($ownedGames as $g):?><option value="<?=$g['id']?>"><?=e($g['season_year'].' · '.$g['name'].' · Rev '.$g['revision_number'])?></option><?php endforeach;?>
        </select>
        <label>Recipient organization</label>
        <select name="recipient_organization_id" required>
          <option value="">Choose organization…</option>
          <?php foreach($otherOrgs as $o):?><option value="<?=$o['id']?>"><?=e($o['name'])?></option><?php endforeach;?>
        </select>
        <div class="notice" style="margin-top:10px"><i class="fa-solid fa-eye"></i> Recipient access is read-only. They must use <b>Clone to My Organization</b> before editing or using the configuration in an event.</div>
        <button><i class="fa-solid fa-share-nodes"></i> Share Configuration</button>
      </form>
    <?php endif;?>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h2>Active Data Relationships</h2>
  <?php if(!$rels):?>
    <p class="muted">No team-to-team data sharing relationships.</p>
  <?php else:?>
    <div class="table-wrap">
      <table class="table">
        <tr><th>Owner</th><th>Recipient</th><th>Match</th><th>Raw</th><th>Analytics</th><th>Pit</th><th>Notes</th></tr>
        <?php foreach($rels as $r):?>
          <tr>
            <td>#<?=e($r['owner_num'])?></td>
            <td>#<?=e($r['recipient_num'])?></td>
            <td><?=$r['share_match_data']?'Yes':'No'?></td>
            <td><?=$r['share_raw_actions']?'Yes':'No'?></td>
            <td><?=$r['share_analytics']?'Yes':'No'?></td>
            <td><?=$r['share_pit_data']?'Yes':'No'?></td>
            <td><?=$r['share_notes']?'Yes':'No'?></td>
          </tr>
        <?php endforeach;?>
      </table>
    </div>
  <?php endif;?>
</div>

<div class="grid" style="margin-top:16px">
  <div class="card">
    <h2>Configurations We Share</h2>
    <?php if(!$configShares):?>
      <p class="muted">No game configurations are currently shared with another organization.</p>
    <?php else:?>
      <div class="table-wrap">
        <table class="table">
          <tr><th>Game</th><th>Recipient</th><th>Access</th><th></th></tr>
          <?php foreach($configShares as $r):?>
            <tr>
              <td><b><?=e($r['season_year'].' · '.$r['name'])?></b></td>
              <td><?=e($r['recipient_org_name'])?></td>
              <td><span class="pill"><i class="fa-solid fa-eye"></i> Read-only</span></td>
              <td>
                <?php if($isConfigAdmin):?>
                  <form method="post" data-confirm="Revoke this shared game configuration?" data-confirm-title="Revoke configuration share" data-confirm-button="Revoke" data-confirm-danger="1">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="kind" value="revoke_game_config">
                    <input type="hidden" name="share_id" value="<?=$r['id']?>">
                    <button class="danger" title="Revoke"><i class="fa-solid fa-link-slash"></i></button>
                  </form>
                <?php endif;?>
              </td>
            </tr>
          <?php endforeach;?>
        </table>
      </div>
    <?php endif;?>
  </div>

  <div class="card">
    <h2>Configurations Shared With Us</h2>
    <?php if(!$receivedConfigShares):?>
      <p class="muted">No other organization has shared a game configuration with you.</p>
    <?php else:?>
      <div class="table-wrap">
        <table class="table">
          <tr><th>Game</th><th>Owner</th><th>Access</th><th></th></tr>
          <?php foreach($receivedConfigShares as $r):?>
            <tr>
              <td><b><?=e($r['season_year'].' · '.$r['name'])?></b></td>
              <td><?=e($r['owner_org_name'])?></td>
              <td><span class="pill"><i class="fa-solid fa-lock"></i> Read-only</span></td>
              <td><?php if($isConfigAdmin):?><a class="btn secondary" href="<?=e(base_url('admin/game-builder.php?game_id='.$r['game_id']))?>"><i class="fa-solid fa-copy"></i> View / Clone</a><?php endif;?></td>
            </tr>
          <?php endforeach;?>
        </table>
      </div>
    <?php endif;?>
  </div>
</div>

<?php include dirname(__DIR__).'/partials_footer.php';
