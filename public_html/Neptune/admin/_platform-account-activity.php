<?php
/** Platform-owner-only account activity tab partial. Include only after platform auth. */
if (!defined('NEPTUNE_PLATFORM_ACCOUNT_PANEL') || NEPTUNE_PLATFORM_ACCOUNT_PANEL !== true) {http_response_code(403); exit('Forbidden');}
$actor = $u; $actorOrg=(int)$u['organization_id']; $allOrgs = true; $isPlatformOwner = true;
$sql = <<<SQL
SELECT u.id, u.organization_id, u.username, u.email, u.display_name,
       u.role, u.active, u.last_login_at, u.google_sub,
       o.name AS organization_name, o.platform_status AS organization_status,
       (SELECT GROUP_CONCAT(DISTINCT CONCAT('#',t.frc_team_number)
                 ORDER BY t.frc_team_number SEPARATOR ', ')
        FROM user_teams ut
        JOIN teams t ON t.id=ut.team_id AND t.organization_id=u.organization_id
        WHERE ut.user_id=u.id) AS assigned_teams
FROM users u
JOIN organizations o ON o.id=u.organization_id
SQL;
$params = [];
if (!$allOrgs) {
    $sql .= ' WHERE u.organization_id = ?';
    $params[] = $actorOrg;
}
$sql .= ' ORDER BY u.active DESC, (u.last_login_at IS NULL) ASC, u.last_login_at DESC, u.display_name, u.id';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$accounts = $statement->fetchAll(PDO::FETCH_ASSOC);

$total = count($accounts);
$enabled = 0;
$recent = 0;
$never = 0;
$orgNames = [];
$now = time();
foreach ($accounts as $account) {
    $canSignIn = (int)$account['active'] === 1 && strtolower((string)$account['organization_status']) !== 'suspended';
    if ($canSignIn) ++$enabled;
    $orgNames[(string)$account['organization_name']] = true;
    if (empty($account['last_login_at'])) { ++$never; continue; }
    $signedIn = strtotime((string)$account['last_login_at'].' UTC');
    if ($signedIn !== false && $signedIn >= $now - 7 * 86400) ++$recent;
}
$organizationNames = array_keys($orgNames);
sort($organizationNames, SORT_NATURAL | SORT_FLAG_CASE);
?>
<style>
/* All styles are confined to this page. Neptune's light/dark theme is preserved. */
.naa {--naa-edge:var(--line,#2e4661);--naa-surface:var(--panel,#101e34);--naa-soft:var(--panel2,#172840);--naa-text:var(--text,#f5f8ff);--naa-muted:var(--muted,#aab7cb);--naa-teal:#40d9eb;--naa-green:var(--good,#36ce9c);--naa-amber:#f6bc65;max-width:1440px;margin:0 auto;padding:0 0 48px;min-width:0}
.naa *{box-sizing:border-box}.naa-head{position:relative;overflow:hidden;border:1px solid var(--naa-edge);border-radius:24px;padding:clamp(19px,4vw,35px);margin:14px 0 17px;background:radial-gradient(ellipse at 95% 3%,rgba(61,209,230,.15),transparent 55%),var(--naa-surface)}
.naa-head:after{content:"";position:absolute;width:240px;height:240px;border:1px solid rgba(76,215,239,.18);border-radius:50%;right:-90px;top:-120px;pointer-events:none;box-shadow:0 0 0 35px rgba(76,215,239,.035),0 0 0 80px rgba(76,215,239,.025)}
.naa-eyebrow{display:flex;align-items:center;gap:8px;letter-spacing:.16em;text-transform:uppercase;font-weight:850;font-size:.71rem;color:var(--naa-teal)}
.naa-head h1{font-weight:900;font-size:clamp(1.7rem,4vw,2.65rem);line-height:1.12;margin:10px 0 9px;color:var(--naa-text)}
.naa-head p{color:var(--naa-muted);line-height:1.55;max-width:76ch;margin:0}
.naa-toplinks{display:flex;gap:9px;flex-wrap:wrap;margin-top:20px;position:relative;z-index:1}
.naa-link{display:inline-flex;align-items:center;justify-content:center;gap:9px;border:1px solid var(--naa-edge);border-radius:11px;padding:10px 14px;min-height:43px;text-decoration:none;font-weight:800;background:var(--naa-soft);color:var(--naa-text)!important}
.naa-link:hover,.naa-link:focus-visible{border-color:var(--naa-teal);color:var(--naa-text)}
.naa-link.primary{background:color-mix(in srgb,var(--naa-teal) 14%,var(--naa-soft));border-color:color-mix(in srgb,var(--naa-teal) 50%,var(--naa-edge))}
.naa-pills{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:17px 0}
.naa-metric{background:var(--naa-surface);border:1px solid var(--naa-edge);border-radius:17px;padding:17px;min-width:0}
.naa-metric b{font-size:clamp(1.5rem,3.6vw,2.15rem);line-height:1.12;display:block;color:var(--naa-text)}
.naa-metric span{font-size:.78rem;color:var(--naa-muted);display:block;margin-top:6px;font-weight:700}
.naa-controls{display:grid;grid-template-columns:minmax(170px,2.2fr) repeat(2,minmax(126px,1fr));gap:10px;margin:18px 0 12px}
.naa-controls.all{grid-template-columns:minmax(150px,2.5fr) repeat(3,minmax(125px,1fr))}
.naa-field{display:grid;gap:6px;min-width:0}.naa-field label{color:var(--naa-muted);font-weight:800;font-size:.77rem}
.naa-field input,.naa-field select{width:100%;min-width:0;min-height:47px;padding:0 13px;border-radius:12px;background:var(--naa-surface);border:1px solid var(--naa-edge);color:var(--naa-text);font-size:16px}
.naa-field input:focus-visible,.naa-field select:focus-visible{outline:2px solid var(--naa-teal);outline-offset:2px}
.naa-result{display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--naa-muted);font-size:.86rem;margin:13px 0 10px;flex-wrap:wrap}
.naa-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.naa-person{border:1px solid var(--naa-edge);border-radius:18px;background:var(--naa-surface);padding:17px;min-width:0;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
@media(hover:hover) and (prefers-reduced-motion:no-preference){.naa-person:hover{transform:translateY(-3px);border-color:rgba(64,217,235,.6);box-shadow:0 10px 30px rgba(0,0,0,.13)}}
.naa-person[hidden]{display:none!important}.naa-person-head{display:flex;align-items:flex-start;gap:12px;min-width:0}
.naa-avatar{display:grid;place-items:center;flex:0 0 43px;width:43px;height:43px;border-radius:13px;background:color-mix(in srgb,var(--naa-teal) 15%,var(--naa-soft));border:1px solid color-mix(in srgb,var(--naa-teal) 27%,var(--naa-edge));font-size:1rem;font-weight:900;color:var(--naa-text)}
.naa-person-name{flex:1;min-width:0}.naa-person-name strong{display:block;font-size:1rem;line-height:1.3;overflow-wrap:anywhere;color:var(--naa-text)}
.naa-person-name small{display:block;color:var(--naa-muted);margin-top:3px;font-size:.78rem;overflow-wrap:anywhere}
.naa-badge{font-size:.71rem;font-weight:850;white-space:nowrap;border-radius:999px;padding:5px 8px;border:1px solid var(--naa-edge);background:var(--naa-soft);color:var(--naa-text)}
.naa-badge.live{color:var(--naa-green);border-color:color-mix(in srgb,var(--naa-green) 40%,var(--naa-edge))}
.naa-badge.off{color:var(--naa-amber)}
.naa-person-data{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}.naa-tag{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:5px 9px;font-size:.75rem;border:1px solid var(--naa-edge);border-radius:9px;color:var(--naa-muted);font-weight:700;overflow-wrap:anywhere}
.naa-activity{display:grid;grid-template-columns:1fr auto;align-items:center;gap:12px;border-top:1px solid var(--naa-edge);margin-top:15px;padding-top:14px}
.naa-activity small{display:block;color:var(--naa-muted);font-size:.74rem;font-weight:750}.naa-activity b{display:block;color:var(--naa-text);font-size:.96rem;margin-top:3px;overflow-wrap:anywhere}
.naa-activity time{display:block;color:var(--naa-muted);font-size:.74rem;margin-top:4px}
.naa-relative{font-size:.75rem;font-weight:850;color:var(--naa-teal);text-align:right;max-width:100px}
.naa-relative.never{color:var(--naa-amber)}.naa-empty{display:none;text-align:center;padding:40px;border:1px dashed var(--naa-edge);border-radius:18px;color:var(--naa-muted)}
.naa-empty.show{display:block}.naa-note{font-size:.8rem;color:var(--naa-muted);line-height:1.5;margin:18px 0}
@media(max-width:950px){.naa-controls.all{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){.naa-pills{grid-template-columns:repeat(2,minmax(0,1fr))}.naa-grid{grid-template-columns:minmax(0,1fr)}.naa-controls{grid-template-columns:1fr 1fr}.naa-controls .naa-search{grid-column:1/-1}.naa-toplinks .naa-link{flex:1}}
@media(max-width:420px){.naa-head{border-radius:18px}.naa-pills{gap:8px}.naa-metric{padding:13px}.naa-controls.all{grid-template-columns:1fr 1fr}.naa-controls.all .naa-search,.naa-controls.all .naa-organization{grid-column:1/-1}.naa-person{padding:14px}.naa-person-head{gap:9px}.naa-avatar{width:38px;height:38px;flex-basis:38px}.naa-activity{grid-template-columns:1fr}.naa-relative{text-align:left;max-width:none}}
@media(prefers-reduced-motion:reduce){.naa-person{transition:none}}
</style>
<div class="naa" id="neptuneAccountAccess">
  <div class="module-eyebrow"><span>PLATFORM ACCOUNT ACCESS</span><small>Read-only · all organizations</small></div>
  <h2 style="margin:7px 0 4px">Account Access &amp; Activity</h2>
  <p class="muted" style="margin:0 0 16px">All registered accounts, roles, access status, and most recent successful sign-in across Neptune organizations.</p>
  <section class="naa-pills" aria-label="Account summary">
    <div class="naa-metric"><b><?=number_format($total)?></b><span>Registered accounts</span></div>
    <div class="naa-metric"><b><?=number_format($enabled)?></b><span>Can sign in</span></div>
    <div class="naa-metric"><b><?=number_format($recent)?></b><span>Signed in past 7 days</span></div>
    <div class="naa-metric"><b><?=number_format($never)?></b><span>Never signed in</span></div>
  </section>
  <section class="naa-controls <?=$allOrgs?'all':''?>" aria-label="Search and filter accounts">
    <div class="naa-field naa-search"><label for="naaSearch">Search people</label><input id="naaSearch" type="search" inputmode="search" placeholder="Name, username, email or team" autocomplete="off"></div>
    <?php if($allOrgs):?>
      <div class="naa-field naa-organization"><label for="naaOrg">Organization</label><select id="naaOrg"><option value="">All organizations</option><?php foreach($organizationNames as $name):?><option value="<?=e(strtolower($name))?>"><?=e($name)?></option><?php endforeach;?></select></div>
    <?php endif;?>
    <div class="naa-field"><label for="naaRole">Role</label><select id="naaRole"><option value="">All roles</option><option value="owner">Owner</option><option value="admin">Admin</option><option value="strategy">Strategy</option><option value="scout">Scout</option></select></div>
    <div class="naa-field"><label for="naaStatus">Status</label><select id="naaStatus"><option value="">Any status</option><option value="enabled">Can sign in</option><option value="disabled">Access disabled</option><option value="never">Never signed in</option></select></div>
  </section>
  <div class="naa-result"><span id="naaCount" role="status" aria-live="polite">Showing <?=number_format($total)?> accounts</span><span>Sorted by recent sign-in · UTC</span></div>
  <section class="naa-grid" id="naaGrid" aria-label="Registered Neptune accounts">
    <?php foreach($accounts as $account):
      $display=trim((string)$account['display_name']);
      if ($display==='') $display=(string)$account['username'];
      $parts=preg_split('/\s+/u',$display)?:[];
      $initials='';
      foreach(array_slice($parts,0,2) as $part){if($part!=='')$initials.=strtoupper(substr($part,0,1));}
      $initials=$initials?:'?';
      $orgSuspended=strtolower((string)$account['organization_status'])==='suspended';
      $allowed=(int)$account['active']===1 && !$orgSuspended;
      $last=(string)($account['last_login_at']??'');
      $neverSignedIn=$last==='';
      $lastIso=$neverSignedIn?'':str_replace(' ','T',$last).'Z';
      $assigned=(string)($account['assigned_teams']??'');
      $linked=!empty($account['google_sub']);
      $searchKey=strtolower(implode(' ',[$display,(string)$account['username'],(string)($account['email']??''),(string)$account['organization_name'],$assigned]));
    ?>
    <article class="naa-person" data-search="<?=e($searchKey)?>" data-org="<?=e(strtolower((string)$account['organization_name']))?>" data-role="<?=e((string)$account['role'])?>" data-status="<?=$allowed?'enabled':'disabled'?>" data-never="<?=$neverSignedIn?'1':'0'?>">
      <div class="naa-person-head">
        <div class="naa-avatar" aria-hidden="true"><?=e($initials)?></div>
        <div class="naa-person-name"><strong><?=e($display)?></strong><small>@<?=e($account['username'])?></small><?php if($account['email']):?><small><?=e($account['email'])?></small><?php endif;?></div>
        <span class="naa-badge <?=$allowed?'live':'off'?>"><?=$allowed?'Active':($orgSuspended?'Org suspended':'Disabled')?></span>
      </div>
      <div class="naa-person-data">
        <span class="naa-tag"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> <?=e(ucfirst((string)$account['role']))?></span>
        <?php if($allOrgs):?><span class="naa-tag"><i class="fa-solid fa-building" aria-hidden="true"></i> <?=e($account['organization_name'])?></span><?php endif;?>
        <?php if($assigned!==''):?><span class="naa-tag"><i class="fa-solid fa-robot" aria-hidden="true"></i> <?=e($assigned)?></span><?php endif;?>
        <span class="naa-tag"><i class="fa-solid <?=$linked?'fa-link':'fa-key'?>" aria-hidden="true"></i> <?=$linked?'Google linked':'Password / email'?></span>
      </div>
      <div class="naa-activity">
        <div><small>Last successful sign-in</small>
          <?php if(!$neverSignedIn):?><b><?=e(gmdate('M j, Y \a\t g:i A',strtotime($last.' UTC')?:0))?> UTC</b><time datetime="<?=e($lastIso)?>" data-signed-in="<?=e($lastIso)?>"><?=e($last)?> UTC</time><?php else:?><b>Never recorded</b><?php endif;?>
        </div>
        <span class="naa-relative <?=$neverSignedIn?'never':''?>" data-relative="<?=e($lastIso)?>"><?=$neverSignedIn?'No sign-in yet':'Recorded'?></span>
      </div>
    </article>
    <?php endforeach;?>
  </section>
  <div class="naa-empty <?=$total===0?'show':''?>" id="naaEmpty"><i class="fa-solid fa-user-slash" aria-hidden="true"></i><p>No accounts match these filters.</p></div>
  <p class="naa-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> “Last successful sign-in” uses Neptune’s existing <code>users.last_login_at</code> field. It is not continuous activity tracking; an open session does not update this time. This QA pilot does not modify the database, credentials, or login behavior.</p>
</div>
<script>
(()=>{'use strict';
const root=document.getElementById('neptuneAccountAccess');if(!root)return;
const search=root.querySelector('#naaSearch'),role=root.querySelector('#naaRole'),status=root.querySelector('#naaStatus'),org=root.querySelector('#naaOrg');
const cards=[...root.querySelectorAll('.naa-person')],count=root.querySelector('#naaCount'),empty=root.querySelector('#naaEmpty');
function apply(){const q=search.value.trim().toLocaleLowerCase();let shown=0;for(const card of cards){const match=(!q||card.dataset.search.includes(q))&&(!role.value||card.dataset.role===role.value)&&(!org?.value||card.dataset.org===org.value)&&(!status.value||card.dataset.status===status.value||(status.value==='never'&&card.dataset.never==='1'));card.hidden=!match;if(match)shown++;}count.textContent=`Showing ${shown.toLocaleString()} of ${cards.length.toLocaleString()} accounts`;empty.classList.toggle('show',shown===0);}
for(const control of [search,role,status,org])if(control){control.addEventListener('input',apply);control.addEventListener('change',apply);}
function relative(iso){if(!iso)return null;const at=Date.parse(iso),secs=(Date.now()-at)/1000;if(!Number.isFinite(secs))return 'Time unavailable';if(secs<0)return 'Just now';if(secs<60)return 'Just now';if(secs<3600)return `${Math.floor(secs/60)}m ago`;if(secs<86400)return `${Math.floor(secs/3600)}h ago`;if(secs<604800)return `${Math.floor(secs/86400)}d ago`;if(secs<2592000)return `${Math.floor(secs/604800)}w ago`;if(secs<31536000)return `${Math.floor(secs/2592000)}mo ago`;return `${Math.floor(secs/31536000)}y ago`;}
root.querySelectorAll('[data-relative]').forEach(el=>{const r=relative(el.dataset.relative);if(r)el.textContent=r;});apply();
})();
</script>
