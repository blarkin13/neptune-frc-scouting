<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_login();
$org=(int)$u['organization_id'];

function scout_match_label(array $m): string {
    $level=['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final'][$m['comp_level']]??strtoupper((string)$m['comp_level']);
    return $m['comp_level']==='qm'
        ? $level.' '.$m['match_number']
        : $level.' '.$m['set_number'].'-'.$m['match_number'];
}

function scout_load_matches(PDO $pdo,int $org): array {
    $s=$pdo->prepare("SELECT m.id,m.match_number,m.set_number,m.comp_level,m.field_id,m.state,m.run_number,e.name event_name,g.name game_name
      FROM matches m
      JOIN events e ON e.id=m.event_id
      JOIN games g ON g.id=m.game_id
      WHERE m.organization_id=? AND m.state IN ('ready','running','paused')
      ORDER BY FIELD(m.state,'running','paused','ready'),m.id DESC");
    $s->execute([$org]);
    $matches=$s->fetchAll();

    $teamStmt=$pdo->prepare("SELECT alliance,station,frc_team_number
      FROM match_teams
      WHERE match_id=?
      ORDER BY FIELD(alliance,'Red','Blue'),station");

    foreach($matches as &$m){
        $teamStmt->execute([(int)$m['id']]);
        $m['teams']=$teamStmt->fetchAll();
        $m['label']=scout_match_label($m);
    }
    unset($m);

    return $matches;
}

if(($_GET['poll']??'')==='1'){
    $matches=scout_load_matches($pdo,$org);

    $payload=[];
    foreach($matches as $m){
        $teams=[];
        foreach(($m['teams']??[]) as $t){
            $teams[]=[
                'alliance'=>($t['alliance']??'')==='Blue'?'Blue':'Red',
                'station'=>max(1,min(3,(int)($t['station']??1))),
                'frc_team_number'=>(int)($t['frc_team_number']??0),
            ];
        }

        $payload[]=[
            'id'=>(int)$m['id'],
            'label'=>(string)$m['label'],
            'event_name'=>(string)$m['event_name'],
            'game_name'=>(string)$m['game_name'],
            'state'=>(string)$m['state'],
            'run_number'=>(int)($m['run_number']??1),
            'teams'=>$teams,
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode([
        'status'=>'success',
        'matches'=>$payload,
        'server_time'=>gmdate('c'),
    ],JSON_UNESCAPED_SLASHES);
    exit;
}

$matches=scout_load_matches($pdo,$org);

$pageTitle='Match Scouting';
$moduleName='TRIDENT';
include dirname(__DIR__).'/partials_header.php';
?>
<h1>Match Scouting</h1>
<p class="muted">Select the field station you are assigned to. Trident gets the robot number directly from Neptune's imported match schedule.</p>

<div
  class="notice"
  id="scoutWaiting"
  role="status"
  aria-live="polite"
  style="<?=$matches?'display:none;':''?>"
>
  <span id="scoutWaitingText">Command has not made a match ready yet.</span>
  <span class="muted" id="scoutWaitingSub" style="display:block;margin-top:4px">This page checks automatically.</span>
</div>

<div id="scoutMatches">
<?php foreach($matches as $m):?>
  <div class="card" style="margin-bottom:16px" data-match-id="<?=e($m['id'])?>">
    <div class="toolbar" style="justify-content:space-between">
      <div><h2 style="margin:0"><?=e($m['label'])?></h2><div class="muted"><?=e($m['event_name'])?></div></div>
      <div class="toolbar" style="margin:0">
        <span class="pill"><?=e($m['state'])?></span>
        <?php if(($m['run_number']??1)>1):?><span class="pill"><i class="fa-solid fa-rotate-right"></i> Run <?=e($m['run_number'])?></span><?php endif;?>
      </div>
    </div>
    <?php if(!$m['teams']):?>
      <div class="notice">No robots are attached to this match. Ask Command to refresh the TBA schedule.</div>
    <?php else:?>
      <div class="station-grid">
        <?php foreach($m['teams'] as $t):
          $alliance=$t['alliance']==='Blue'?'Blue':'Red';
          $url='match.php?match_id='.(int)$m['id'].'&alliance='.rawurlencode($alliance).'&station='.(int)$t['station'];
        ?>
          <a class="station-card <?=strtolower($alliance)?>" href="<?=e($url)?>">
            <span class="station-name"><?=e($alliance.' '.$t['station'])?></span>
            <strong>#<?=e($t['frc_team_number'])?></strong>
            <span class="muted">Scout this robot</span>
          </a>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>
<?php endforeach;?>
</div>

<script>
(() => {
  'use strict';

  const matchesEl=document.getElementById('scoutMatches');
  const waitingEl=document.getElementById('scoutWaiting');
  const waitingText=document.getElementById('scoutWaitingText');
  const waitingSub=document.getElementById('scoutWaitingSub');

  let polling=false;
  let lastSignature=null;
  let lastConnected=true;

  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#39;'
  }[ch]));

  function matchUrl(matchId,alliance,station){
    const q=new URLSearchParams({
      match_id:String(matchId),
      alliance:String(alliance),
      station:String(station)
    });
    return 'match.php?'+q.toString();
  }

  function signature(matches){
    return JSON.stringify((matches||[]).map(m=>[
      Number(m.id||0),
      String(m.state||''),
      Number(m.run_number||1),
      (m.teams||[]).map(t=>[
        String(t.alliance||''),
        Number(t.station||0),
        Number(t.frc_team_number||0)
      ])
    ]));
  }

  function render(matches){
    matches=Array.isArray(matches)?matches:[];
    const sig=signature(matches);

    if(sig===lastSignature){
      if(lastConnected){
        waitingSub.textContent='This page checks automatically.';
      }
      return;
    }
    lastSignature=sig;

    if(!matches.length){
      matchesEl.innerHTML='';
      waitingEl.style.display='';
      waitingText.textContent='Command has not made a match ready yet.';
      waitingSub.textContent='This page checks automatically.';
      return;
    }

    waitingEl.style.display='none';

    matchesEl.innerHTML=matches.map(m=>{
      const run=Number(m.run_number||1);
      const teams=Array.isArray(m.teams)?m.teams:[];
      const teamHtml=teams.length
        ? `<div class="station-grid">${teams.map(t=>{
            const alliance=String(t.alliance)==='Blue'?'Blue':'Red';
            const station=Math.max(1,Math.min(3,Number(t.station||1)));
            const robot=Number(t.frc_team_number||0);
            return `<a class="station-card ${alliance.toLowerCase()}" href="${esc(matchUrl(m.id,alliance,station))}">
              <span class="station-name">${esc(alliance+' '+station)}</span>
              <strong>#${esc(robot)}</strong>
              <span class="muted">Scout this robot</span>
            </a>`;
          }).join('')}</div>`
        : '<div class="notice">No robots are attached to this match. Ask Command to refresh the TBA schedule.</div>';

      return `<div class="card" style="margin-bottom:16px" data-match-id="${Number(m.id||0)}">
        <div class="toolbar" style="justify-content:space-between">
          <div><h2 style="margin:0">${esc(m.label||'Match')}</h2><div class="muted">${esc(m.event_name||'')}</div></div>
          <div class="toolbar" style="margin:0">
            <span class="pill">${esc(m.state||'')}</span>
            ${run>1?`<span class="pill"><i class="fa-solid fa-rotate-right"></i> Run ${run}</span>`:''}
          </div>
        </div>
        ${teamHtml}
      </div>`;
    }).join('');
  }

  async function poll(){
    if(polling || document.hidden) return;
    polling=true;

    try{
      const r=await fetch('index.php?poll=1&_='+Date.now(),{
        cache:'no-store',
        credentials:'same-origin',
        headers:{'Accept':'application/json'}
      });

      if(!r.ok) throw new Error('HTTP '+r.status);

      const d=await r.json();
      if(d.status!=='success' || !Array.isArray(d.matches)){
        throw new Error('Invalid response');
      }

      lastConnected=true;
      render(d.matches);
    }catch(err){
      lastConnected=false;
      waitingEl.style.display='';
      waitingText.textContent='Connection lost — waiting for Neptune.';
      waitingSub.textContent='This page will resume checking automatically when the connection returns.';
    }finally{
      polling=false;
    }
  }

  lastSignature=signature(<?=json_encode(array_map(static function($m){
      return [
          'id'=>(int)$m['id'],
          'state'=>(string)$m['state'],
          'run_number'=>(int)($m['run_number']??1),
          'teams'=>array_map(static fn($t)=>[
              'alliance'=>($t['alliance']??'')==='Blue'?'Blue':'Red',
              'station'=>(int)($t['station']??1),
              'frc_team_number'=>(int)($t['frc_team_number']??0),
          ],$m['teams']??[]),
      ];
  },$matches),JSON_UNESCAPED_SLASHES)?>);

  window.addEventListener('online',poll);
  document.addEventListener('visibilitychange',()=>{
    if(!document.hidden) poll();
  });

  poll();
  setInterval(poll,1000);
})();
</script>
<?php include dirname(__DIR__).'/partials_footer.php';
