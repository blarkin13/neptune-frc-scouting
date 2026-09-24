<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

$u=require_role(['owner','admin','strategy']);
$ready=augur_epa_tables_ready($pdo);
$summary=$ready?augur_epa_archive_summary($pdo):[];
$current=(int)date('Y');
$defaultStart=max(1992,$current-19);

$yearRows=[];
if($ready){
    $yearRows=$pdo->query("SELECT y.*,
        (SELECT COUNT(*) FROM augur_epa_archive_events e WHERE e.season_year=y.season_year) events_archived,
        (SELECT COUNT(*) FROM augur_epa_archive_events e WHERE e.season_year=y.season_year AND e.source_status='ready') source_ready,
        (SELECT COUNT(*) FROM augur_epa_archive_events e WHERE e.season_year=y.season_year AND e.ratings_calculated_at IS NOT NULL) rated_events,
        (SELECT COUNT(*) FROM augur_epa_alliance_samples s WHERE s.season_year=y.season_year) alliance_samples
        FROM augur_epa_archive_years y ORDER BY y.season_year DESC")->fetchAll();
}

$pageTitle='AUGUR · Public EPA Archive';$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page">
<header class="module-page-header"><div><div class="module-code">AUGUR</div><h1>Public EPA Archive</h1><p>Build and maintain Neptune's shared TBA-derived EPA history. Completed events are downloaded once; model recalculation uses the local archive.</p></div><div class="toolbar"><a class="btn secondary" href="<?=e(base_url('admin/augur-epa-phase-maps.php'))?>"><i class="fa-solid fa-diagram-project"></i> Phase Mapping</a><a class="btn secondary" href="<?=e(base_url('analytics/augur-ratings.php'))?>"><i class="fa-solid fa-globe"></i> Public EPA Ratings</a></div></header>

<?php if(!$ready):?>
<div class="notice bad"><b>Public EPA Archive is not installed.</b> Run <code>sql/2026-09-21_augur-epa-archive-v3.sql</code> first.</div>
<?php else:?>
<style>
.epa-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.epa-summary .card{padding:14px}.epa-summary span{display:block;color:var(--muted);font-size:.72rem}.epa-summary b{font-size:1.5rem}.epa-controls{display:grid;grid-template-columns:140px 140px auto auto;gap:10px;align-items:end}.epa-log{margin-top:12px;max-height:260px;overflow:auto;background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:10px;font:12px ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap}.epa-progress{margin-top:10px;height:9px;background:var(--panel2);border-radius:999px;overflow:hidden;border:1px solid var(--line)}.epa-progress>div{height:100%;width:0;background:var(--accent);transition:width .2s}.epa-year-table{width:100%;border-collapse:collapse}.epa-year-table th,.epa-year-table td{padding:10px;border-bottom:1px solid var(--line);text-align:left}.epa-year-table th{font-size:.7rem;text-transform:uppercase;color:var(--muted)}@media(max-width:800px){.epa-summary{grid-template-columns:1fr 1fr}.epa-controls{grid-template-columns:1fr 1fr}.epa-controls button{width:100%}}@media(max-width:520px){.epa-summary,.epa-controls{grid-template-columns:1fr}}
</style>
<div class="epa-summary">
  <div class="card"><span>Years discovered</span><b><?=e($summary['years']??0)?></b></div>
  <div class="card"><span>Events archived</span><b><?=e($summary['events']??0)?></b></div>
  <div class="card"><span>Alliance samples</span><b><?=e($summary['samples']??0)?></b></div>
  <div class="card"><span>Event-team ratings</span><b><?=e($summary['event_team_ratings']??0)?></b></div>
</div>

<section class="card" style="padding:14px">
  <div class="epa-controls">
    <div><label>Start year</label><input type="number" id="epaStartYear" min="1992" max="<?=$current+1?>" value="<?=$defaultStart?>"></div>
    <div><label>End year</label><input type="number" id="epaEndYear" min="1992" max="<?=$current+1?>" value="<?=$current?>"></div>
    <button type="button" id="epaBackfillBtn"><i class="fa-solid fa-box-archive"></i> Backfill missing</button>
    <button type="button" class="secondary" id="epaStopBtn" disabled><i class="fa-solid fa-stop"></i> Stop</button>
  </div>
  <div class="epa-progress"><div id="epaProgressBar"></div></div>
  <div class="epa-log" id="epaLog">Ready. Backfill is resumable; already archived completed events are skipped.</div>
  <p class="muted" style="margin:10px 0 0">For a 20-year archive, keep this page open while it runs. Closing the page is safe; start it again later and Neptune resumes from the first missing event.</p>
</section>

<section class="card" style="margin-top:14px;overflow:auto">
<table class="epa-year-table"><thead><tr><th>Year</th><th>TBA events</th><th>Source ready</th><th>Rated</th><th>Alliance samples</th><th>Last discovery</th></tr></thead><tbody>
<?php foreach($yearRows as $r):?><tr><td><b><?=e($r['season_year'])?></b></td><td><?=e($r['events_archived'])?></td><td><?=e($r['source_ready'])?></td><td><?=e($r['rated_events'])?></td><td><?=e($r['alliance_samples'])?></td><td><?=e($r['last_discovered_at']?:'—')?></td></tr><?php endforeach;?>
<?php if(!$yearRows):?><tr><td colspan="6">No years discovered yet.</td></tr><?php endif;?>
</tbody></table>
</section>

<script>
(()=>{
  const endpoint=<?=json_encode(base_url('api/augur-epa-admin.php'))?>;
  const csrf=<?=json_encode(csrf_token())?>;
  const logEl=document.getElementById('epaLog'), bar=document.getElementById('epaProgressBar');
  const startBtn=document.getElementById('epaBackfillBtn'), stopBtn=document.getElementById('epaStopBtn');
  let stopped=false,running=false;
  const log=(msg)=>{logEl.textContent += "\n"+msg;logEl.scrollTop=logEl.scrollHeight;};
  const post=async(data)=>{
    const body=new URLSearchParams({...data,csrf});
    const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});
    const j=await r.json();
    if(!r.ok||!j.ok)throw new Error(j.message||`HTTP ${r.status}`);
    return j;
  };
  stopBtn.addEventListener('click',()=>{stopped=true;log('Stop requested. Current request will finish, then the run will pause.');});
  startBtn.addEventListener('click',async()=>{
    if(running)return;
    let a=Number(document.getElementById('epaStartYear').value),b=Number(document.getElementById('epaEndYear').value);
    if(a>b)[a,b]=[b,a];
    stopped=false;running=true;startBtn.disabled=true;stopBtn.disabled=false;logEl.textContent='Starting EPA archive backfill…';
    const totalYears=Math.max(1,b-a+1);let yearIndex=0;
    try{
      for(let year=a;year<=b;year++){
        if(stopped)break;
        log(`\n${year}: discovering/checking events…`);
        let processed=0;
        while(!stopped){
          const step=await post({action:'process_year_step',year});
          if(step.done)break;
          processed++;
          log(`${year} · ${step.event_key} · ${step.event_name} · remaining ${step.remaining}`);
          await new Promise(r=>setTimeout(r,350));
        }
        if(stopped)break;
        log(`${year}: recalculating the season from locally stored samples…`);
        const recalc=await post({action:'recalculate_year',year});
        log(`${year}: complete · ${recalc.result.events} rated events · ${recalc.result.matches} qualification matches.`);
        yearIndex++;
        bar.style.width=`${Math.round(yearIndex/totalYears*100)}%`;
      }
      log(stopped?'Backfill paused. Run it again to resume.':'Backfill complete.');
    }catch(e){log(`ERROR: ${e.message}`);}
    running=false;startBtn.disabled=false;stopBtn.disabled=true;
  });
})();
</script>
<?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';