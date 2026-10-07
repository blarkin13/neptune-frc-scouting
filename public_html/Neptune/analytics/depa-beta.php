<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_augur_depa.php';
$viewer=current_user();$platformOrgId=(int)($config['app']['platform_organization_id']??1);$canManage=$viewer&&((string)($viewer['role']??'')==='owner')&&((int)($viewer['organization_id']??0)===$platformOrgId);$ready=augur_depa_tables_ready($pdo);
$seasonPublicColumns=false;$defenseAttemptColumns=false;
if($ready){
    try{
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_depa_public_season_ratings' AND COLUMN_NAME IN ('public_depa','public_matches')");
        $q->execute();
        $seasonPublicColumns=((int)$q->fetchColumn()===2);
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_depa_network_match_evidence' AND COLUMN_NAME IN ('defense_failures','defense_attempts')");
        $q->execute();$defenseAttemptColumns=((int)$q->fetchColumn()===2);
    }catch(Throwable $e){}
}
function dr_num($v,int $d=1): string{return $v===null||$v===''?'—':number_format((float)$v,$d);}function dr_signed($v,int $d=1): string{if($v===null||$v==='')return'—';$x=(float)$v;return($x>0?'+':'').number_format($x,$d);}function dr_abs_url(string $path): string{$base='https://neptune.mckinneysteamacademy.org';if($path==='')return$base.'/';if(preg_match('~^https?://~i',$path))return$path;return$base.'/'.ltrim($path,'/');}
$years=[];if($ready)$years=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM augur_depa_public_season_ratings ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));if(!$years&&$ready)$years=array_map('intval',$pdo->query('SELECT DISTINCT season_year FROM augur_epa_archive_events ORDER BY season_year DESC')->fetchAll(PDO::FETCH_COLUMN));$year=(int)($_GET['year']??($years[0]??date('Y')));$eventKey=trim((string)($_GET['event']??''));
$events=[];if($ready&&$year){$s=$pdo->prepare("SELECT e.*,(SELECT COUNT(*) FROM augur_depa_public_event_ratings r WHERE r.tba_event_key=e.tba_event_key) rating_count FROM augur_epa_archive_events e WHERE e.season_year=? ORDER BY COALESCE(e.start_date,e.end_date),e.name");$s->execute([$year]);$events=$s->fetchAll();if($eventKey!==''&&!in_array($eventKey,array_map(static fn($e)=>(string)$e['tba_event_key'],$events),true))$eventKey='';}
$rows=[];$viewEvent=null;if($ready){if($eventKey!==''){$s=$pdo->prepare("SELECT r.*,e.name event_name,e.start_date,e.end_date FROM augur_depa_public_event_ratings r JOIN augur_epa_archive_events e ON e.tba_event_key=r.tba_event_key WHERE r.tba_event_key=? ORDER BY r.neptune_depa IS NULL,r.neptune_depa DESC,r.macro_depa DESC,r.confidence_score DESC,r.frc_team_number");$s->execute([$eventKey]);$rows=$s->fetchAll();$s=$pdo->prepare('SELECT * FROM augur_epa_archive_events WHERE tba_event_key=? LIMIT 1');$s->execute([$eventKey]);$viewEvent=$s->fetch()?:null;}elseif($year){$sql=$seasonPublicColumns?"SELECT * FROM augur_depa_public_season_ratings WHERE season_year=? ORDER BY neptune_depa IS NULL,neptune_depa DESC,public_depa DESC,confidence_score DESC,frc_team_number":"SELECT r.*,NULL AS public_depa,0 AS public_matches FROM augur_depa_public_season_ratings r WHERE season_year=? ORDER BY neptune_depa IS NULL,neptune_depa DESC,confidence_score DESC,frc_team_number";$s=$pdo->prepare($sql);$s->execute([$year]);$rows=$s->fetchAll();}}
$teamMeta=[];$directoryReady=false;try{$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='augur_epa_team_directory'");$q->execute();$directoryReady=((int)$q->fetchColumn()>0);}catch(Throwable $e){}if($year>0){if($directoryReady){$s=$pdo->prepare('SELECT frc_team_number,nickname,name,city,state_prov,country FROM augur_epa_team_directory WHERE season_year=?');$s->execute([$year]);foreach($s->fetchAll() as $m)$teamMeta[(int)$m['frc_team_number']]=$m;}try{$s=$pdo->prepare("SELECT et.frc_team_number,MAX(NULLIF(et.nickname,'')) nickname,MAX(NULLIF(et.nickname,'')) name,MAX(NULLIF(et.city,'')) city,MAX(NULLIF(et.state_prov,'')) state_prov,MAX(NULLIF(et.country,'')) country FROM event_teams et JOIN events e ON e.id=et.event_id JOIN games g ON g.id=e.game_id WHERE g.season_year=? GROUP BY et.frc_team_number");$s->execute([$year]);foreach($s->fetchAll() as $m){$n=(int)$m['frc_team_number'];if(!isset($teamMeta[$n]))$teamMeta[$n]=$m;else foreach(['nickname','city','state_prov','country'] as $k)if(empty($teamMeta[$n][$k])&&!empty($m[$k]))$teamMeta[$n][$k]=$m[$k];}}catch(Throwable $e){}}

$defenseEvidenceByTeam=[];
if($ready&&$year){
    try{
        $attemptSelect=$defenseAttemptColumns?'defense_failures,defense_attempts':'0 AS defense_failures,defense_actions AS defense_attempts';
        $sql="SELECT tba_match_key,tba_event_key,match_number,frc_team_number,alliance,raw_depa,
                     expected_alliance_score,expected_opponent_score,actual_opponent_score,
                     defense_status,defense_actions,{$attemptSelect},spot_great_defense,spot_weak_defense,
                     observing_orgs,verified_orgs,possible_orgs
              FROM augur_depa_network_match_evidence
              WHERE season_year=?
                AND (defense_actions>0 ".($defenseAttemptColumns?'OR defense_failures>0 ':'')."OR spot_great_defense>0 OR spot_weak_defense>0)";
        $params=[$year];
        if($eventKey!==''){$sql.=" AND tba_event_key=?";$params[]=$eventKey;}
        $sql.=" ORDER BY frc_team_number,tba_event_key,match_number,tba_match_key";
        $q=$pdo->prepare($sql);$q->execute($params);
        foreach($q->fetchAll() as $ev){
            $team=(int)$ev['frc_team_number'];
            if($team<=0)continue;
            $defenseEvidenceByTeam[$team][]=[
                'tba_match_key'=>(string)$ev['tba_match_key'],
                'tba_event_key'=>(string)$ev['tba_event_key'],
                'match_number'=>(int)$ev['match_number'],
                'alliance'=>(string)$ev['alliance'],
                'raw_depa'=>(float)$ev['raw_depa'],
                'expected_alliance_score'=>$ev['expected_alliance_score']===null?null:(float)$ev['expected_alliance_score'],
                'expected_opponent_score'=>$ev['expected_opponent_score']===null?null:(float)$ev['expected_opponent_score'],
                'actual_opponent_score'=>$ev['actual_opponent_score']===null?null:(float)$ev['actual_opponent_score'],
                'defense_status'=>(string)$ev['defense_status'],
                'defense_actions'=>(int)$ev['defense_actions'],'defense_failures'=>(int)$ev['defense_failures'],'defense_attempts'=>(int)$ev['defense_attempts'],
                'spot_great_defense'=>(int)$ev['spot_great_defense'],
                'spot_weak_defense'=>(int)$ev['spot_weak_defense'],
                'observing_orgs'=>(int)$ev['observing_orgs'],
                'verified_orgs'=>(int)$ev['verified_orgs'],
                'possible_orgs'=>(int)$ev['possible_orgs'],
                'used_in_neptune_depa'=>((string)$ev['defense_status']==='Verified'),
            ];
        }
    }catch(Throwable $e){}
}

$api=base_url('api/augur-depa.php');$epaPage=base_url('analytics/augur-ratings.php?year='.$year.($eventKey!==''?'&event='.rawurlencode($eventKey):''));$publicHome=base_url('index.php');$canonicalPath='analytics/depa-beta.php?year='.rawurlencode((string)$year);if($eventKey!=='')$canonicalPath.='&event='.rawurlencode($eventKey);$canonical=dr_abs_url($canonicalPath);
if($viewEvent){$seoTitle=$viewEvent['name'].' '.$year.' FRC Neptune D-EPA Ratings | Neptune AUGUR';$seoDescription='Neptune D-EPA defensive ratings for '.$viewEvent['name'].' ('.$year.'), built from public match results and deduplicated scouting observations contributed across Neptune organizations.';}else{$seoTitle=$year.' FRC Neptune D-EPA Defensive Ratings | Neptune AUGUR';$seoDescription='Browse '.$year.' Neptune D-EPA defensive ratings built from public match results and organization-agnostic, deduplicated scouting observations contributed across Neptune.';}$ogImage=dr_abs_url('images/neptune-og.png');$datasetName=$viewEvent?$viewEvent['name'].' '.$year.' Neptune D-EPA Ratings':$year.' Neptune D-EPA Ratings';$lastUpdated='';foreach($rows as $r){$u=(string)($r['calculated_at']??'');if($u>$lastUpdated)$lastUpdated=$u;}
$structuredData=['@context'=>'https://schema.org','@graph'=>[['@type'=>'WebPage','@id'=>$canonical.'#webpage','url'=>$canonical,'name'=>$seoTitle,'description'=>$seoDescription,'isPartOf'=>['@type'=>'WebSite','name'=>'Neptune FRC Scouting Platform','url'=>dr_abs_url('')],'mainEntity'=>['@id'=>$canonical.'#dataset']],['@type'=>'Dataset','@id'=>$canonical.'#dataset','name'=>$datasetName,'description'=>$seoDescription,'url'=>$canonical,'creator'=>['@type'=>'Organization','name'=>'Neptune','url'=>dr_abs_url('')],'temporalCoverage'=>(string)$year,'isAccessibleForFree'=>true,'keywords'=>['FIRST Robotics Competition','FRC','D-EPA','defense','robotics scouting','Neptune'],'variableMeasured'=>['D-EPA (all matches)','Neptune D-EPA (verified defense)','Verified defense matches','Defense confidence'],'distribution'=>[['@type'=>'DataDownload','encodingFormat'=>'application/json','contentUrl'=>dr_abs_url('api/augur-depa.php?year='.rawurlencode((string)$year))]]]]];
if($viewer){$pageTitle='Neptune D-EPA Ratings';$moduleName='AUGUR';include dirname(__DIR__).'/partials_header.php';}else{?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#05080b"><title><?=e($seoTitle)?></title><meta name="description" content="<?=e($seoDescription)?>"><meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1"><link rel="canonical" href="<?=e($canonical)?>"><link rel="alternate" type="application/json" title="Neptune D-EPA Ratings API" href="<?=e(dr_abs_url('api/augur-depa.php?year='.rawurlencode((string)$year)))?>"><meta property="og:type" content="website"><meta property="og:site_name" content="Neptune"><meta property="og:title" content="<?=e($seoTitle)?>"><meta property="og:description" content="<?=e($seoDescription)?>"><meta property="og:url" content="<?=e($canonical)?>"><meta property="og:image" content="<?=e($ogImage)?>"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?=e($seoTitle)?>"><meta name="twitter:description" content="<?=e($seoDescription)?>"><meta name="twitter:image" content="<?=e($ogImage)?>"><script type="application/ld+json"><?=json_encode($structuredData,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><script>try{document.documentElement.dataset.theme=localStorage.getItem('neptune-theme')||'dark'}catch(e){}</script><link rel="icon" type="image/png" sizes="64x64" href="/images/favicon.png"><link rel="apple-touch-icon" href="/images/app-icon.png"><link rel="manifest" href="/manifest.webmanifest"><?php $fa=@filemtime(dirname(__DIR__).'/assets/vendor/fontawesome/css/all.min.css')?:1;?><link rel="stylesheet" href="<?=e(base_url('assets/vendor/fontawesome/css/all.min.css'))?>?v=<?=$fa?>"><?php $fc=@filemtime(dirname(__DIR__).'/assets/css/fontawesome-v7-compat.css')?:1;?><link rel="stylesheet" href="<?=e(base_url('assets/css/fontawesome-v7-compat.css'))?>?v=<?=$fc?>"><link rel="stylesheet" href="<?=e(base_url('assets/css/app.css'))?>"><link rel="stylesheet" href="<?=e(base_url('assets/css/neptune-ui.css'))?>"></head><body><main class="dr-public-wrap"><?php }?>
<style>.dr-public-wrap{max-width:1440px;margin:auto;padding:22px}.dr-page{width:100%}.dr-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:18px}.dr-brand{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);font-weight:900}.dr-head h1{font-size:clamp(2rem,4vw,3.25rem);margin:4px 0}.dr-head p{max-width:900px;color:var(--muted);margin:0;line-height:1.55}.dr-actions{display:flex;gap:8px;flex-wrap:wrap}.dr-public-nav{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 0 18px;border-bottom:1px solid var(--line);margin-bottom:22px}.dr-logo{display:inline-flex;align-items:center;gap:9px;color:var(--text);text-decoration:none;font-weight:900;font-size:1.05rem}.dr-filter{display:grid;grid-template-columns:180px minmax(280px,1fr) auto;gap:10px;align-items:end}.dr-table{width:100%;border-collapse:collapse;table-layout:fixed}.dr-table th,.dr-table td{padding:10px 7px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle;overflow-wrap:anywhere}.dr-table th{font-size:clamp(.58rem,.62vw,.68rem);text-transform:uppercase;letter-spacing:.045em;color:var(--muted);background:var(--panel2);position:sticky;top:0;z-index:1}.dr-table td{font-size:clamp(.76rem,.78vw,.95rem)}.dr-rank{font-weight:900;color:var(--accent)}.dr-location{color:var(--muted)}.dr-api{margin-top:18px;padding:16px}.dr-api code{display:block;white-space:normal;word-break:break-all;margin-top:7px}.dr-note{font-size:.75rem;color:var(--muted);line-height:1.5}.dr-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.dr-meta span{font-size:.7rem;padding:4px 7px;border:1px solid var(--line);border-radius:999px;color:var(--muted)}.dr-footer{text-align:center;color:var(--muted);font-size:.72rem;margin:24px 0}@media(max-width:820px){.dr-public-wrap{padding:14px}.dr-head{display:block}.dr-actions{margin-top:12px}.dr-filter{grid-template-columns:1fr}.dr-filter button{width:100%}.dr-table,.dr-table tbody,.dr-table tr,.dr-table td{display:block;width:100%}.dr-table thead{display:none}.dr-table tr{padding:10px 12px;border-bottom:1px solid var(--line)}.dr-table td{display:grid;grid-template-columns:120px minmax(0,1fr);gap:10px;padding:5px 0;border:0;font-size:.82rem}.dr-table td::before{content:attr(data-label);font-size:.65rem;font-weight:900;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}} 
.sort-mark{font-size:.72em;opacity:.7;margin-left:3px}
th[data-sortable]{cursor:pointer;user-select:none}
th[data-sortable]:hover .sort-label,th[data-sortable]:focus .sort-label{color:var(--text)}
th[data-sortable]:focus{outline:2px solid var(--accent);outline-offset:-2px}
.ratings-mobile-sort{display:none;gap:8px;align-items:end;margin:0 0 10px}
.ratings-mobile-sort label{flex:1;margin:0}
.ratings-mobile-sort select{width:100%}
@media(max-width:820px){.ratings-mobile-sort{display:flex}}


.dr-team-link{color:inherit;text-decoration:none;font-weight:800}
.dr-team-link:hover,.dr-team-link:focus{text-decoration:underline;color:var(--accent)}
.dr-read-btn{appearance:none;border:0;background:transparent;color:var(--accent);padding:0;font:inherit;font-weight:800;cursor:pointer;text-decoration:underline;text-underline-offset:3px}
.dr-read-btn:hover,.dr-read-btn:focus{filter:brightness(1.12)}
.dr-modal[hidden]{display:none}
.dr-modal{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:18px}
.dr-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.dr-modal-card{position:relative;width:min(1100px,96vw);max-height:90vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.5);padding:18px}
.dr-modal-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px}
.dr-modal-head h2{margin:0 0 4px}
.dr-modal-close{min-width:42px}
.dr-modal-table{width:100%;border-collapse:collapse;min-width:900px}
.dr-modal-table th,.dr-modal-table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
.dr-modal-table th{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.dr-evidence-status{display:inline-flex;padding:3px 8px;border-radius:999px;border:1px solid var(--line);font-weight:800;font-size:.75rem}
.dr-evidence-status.Verified{background:rgba(31,157,85,.12)}
.dr-evidence-status.Possible{background:rgba(197,139,0,.12)}
.dr-video-link{display:inline-flex;align-items:center;gap:6px}
.dr-video-note{font-size:.75rem;color:var(--muted)}
body.dr-modal-open{overflow:hidden}
@media(max-width:700px){.dr-modal{padding:8px}.dr-modal-card{width:100%;max-height:94vh;padding:14px}}

</style>
<div class="dr-page"><?php if(!$viewer):?><nav class="dr-public-nav"><a class="dr-logo" href="<?=e($publicHome)?>"><i class="fa-solid fa-water"></i> Neptune</a><div class="dr-actions"><a class="btn secondary" href="<?=e($publicHome)?>"><i class="fa-solid fa-house"></i> Home</a><a class="btn" href="<?=e($publicHome)?>"><i class="fa-solid fa-right-to-bracket"></i> Log In</a></div></nav><?php endif;?>
<header class="dr-head"><div><h1>Neptune D-EPA Ratings</h1><p><b>D-EPA</b> is the all-match public suppression signal, so it is available even when Neptune has no recorded defense actions. <b>Neptune D-EPA</b> is the stronger evidence metric: the same suppression calculation restricted to canonical Verified defense matches contributed across <b>all Neptune organizations</b>.</p></div><div class="dr-actions"><a class="btn secondary" href="<?=e($epaPage)?>"><i class="fa-solid fa-chart-line"></i> EPA</a><a class="btn secondary" href="#developer-api"><i class="fa-solid fa-code"></i> Developer API</a><?php if($canManage):?><a class="btn secondary" href="<?=e(base_url('admin/augur-depa-beta.php'))?>"><i class="fa-solid fa-database"></i> D-EPA Data Lab</a><?php endif;?></div></header>
<?php if(!$ready):?><div class="notice bad"><b>Neptune D-EPA network archive is not installed yet.</b> Use the D-EPA Data Lab to install/upgrade it.</div><?php else:?><?php if($canManage&&(!$seasonPublicColumns||!$defenseAttemptColumns)):?><div class="notice bad" style="margin-bottom:14px"><b>D-EPA database upgrade required.</b> <a href="<?=e(base_url('admin/augur-depa-beta.php'))?>">Open the D-EPA Data Lab</a> and click <b>Install / Upgrade D-EPA Tables</b>.</div><?php endif;?><section class="card" style="padding:14px"><form method="get" class="dr-filter"><div><label>Season</label><select name="year" onchange="this.form.submit()"><?php foreach($years as $y):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endforeach;?></select></div><div><label>View</label><select name="event" onchange="this.form.submit()"><option value="">Season ratings</option><?php foreach($events as $ev):?><option value="<?=e($ev['tba_event_key'])?>" <?=$eventKey===(string)$ev['tba_event_key']?'selected':''?>><?=e($ev['name'])?><?=((int)$ev['rating_count']>0)?'':' · not rated'?></option><?php endforeach;?></select></div></form><?php if($viewEvent):?><div class="dr-meta"><span><?=e($viewEvent['tba_event_key'])?></span><span><?=e($viewEvent['qual_match_count'])?> qualification matches</span><span><?=count($rows)?> rated teams</span><span><?=e(AUGUR_DEPA_MODEL_VERSION)?></span></div><?php endif;?><p class="dr-note" style="margin:12px 0 0"><b>D-EPA</b> = projected opponent score minus actual modeled opponent score across all qualification matches. It is an inference signal, not proof that a robot played defense. <b>Neptune D-EPA</b> uses only Verified defense matches. Verified = one observing organization independently recorded 2+ defense attempts (success or failure), or a Spot defense tag. Overlapping organizations are deduplicated by TBA match + team. <b>Rank requires 2+ Verified defense matches;</b> one-match Neptune D-EPA values remain visible as Provisional but are not ranked.</p></section>
<section class="card ratings-sort-scope" style="margin-top:14px;overflow:hidden"><div class="ratings-mobile-sort"><label>Sort by<select data-sort-select></select></label><button type="button" class="btn secondary" data-sort-dir title="Reverse sort"><i class="fa-solid fa-arrow-down-up-across-line"></i></button></div><table class="dr-table" data-sortable-table><thead><tr><th data-sort-kind="number">Rank</th><th data-sort-kind="number">Team</th><th>Team Name</th><th>City</th><th>Country</th><th data-sort-kind="number">D-EPA</th><th data-sort-kind="number">Neptune D-EPA</th><th data-sort-kind="number">Confidence</th><th data-sort-kind="number">Verified</th><th data-sort-kind="number">Possible</th><th data-sort-kind="number">Defense S/A</th><th data-sort-kind="number">Observed</th><th data-sort-kind="number">Coverage</th><th>Read</th></tr></thead><tbody><?php $rank=0;foreach($rows as $r):$teamNo=(int)$r['frc_team_number'];$meta=$teamMeta[$teamNo]??[];$verifiedCount=(int)$r['verified_defense_matches'];$ranked=$r['neptune_depa']!==null&&$r['neptune_depa']!==''&&$verifiedCount>=AUGUR_DEPA_MIN_RATED_DEFENSE_MATCHES;if($ranked)$rank++;$coverage=$eventKey!==''?((int)($r['contributing_orgs']??0).' org'.((int)($r['contributing_orgs']??0)===1?'':'s')):((int)($r['events_observed']??0).' event'.((int)($r['events_observed']??0)===1?'':'s'));$publicDepa=$eventKey!==''?($r['macro_depa']??null):($r['public_depa']??null);?><tr><td class="dr-rank" data-label="Rank" data-sort="<?=$ranked?$rank:''?>"><?=$ranked?'#'.$rank:'—'?></td><?php $robotUrl=base_url('analytics/robot-lookup.php').'?team='.$teamNo;$teamDisplay=(($meta['nickname']??'')!==''?$meta['nickname']:(($meta['name']??'')!==''?$meta['name']:'—'));?>
<td data-label="Team" data-sort="<?=$teamNo?>"><a class="dr-team-link" href="<?=e($robotUrl)?>">#<?=$teamNo?></a></td><td data-label="Team Name"><a class="dr-team-link" href="<?=e($robotUrl)?>"><?=e($teamDisplay)?></a></td><td class="dr-location" data-label="City"><?=e($meta['city']??'—')?></td><td class="dr-location" data-label="Country"><?=e($meta['country']??'—')?></td><td data-label="D-EPA" data-sort="<?=e((string)($publicDepa??''))?>"><?=dr_signed($publicDepa,1)?></td><td data-label="Neptune D-EPA" data-sort="<?=e((string)($r['neptune_depa']??''))?>"><b><?=dr_signed($r['neptune_depa'],1)?></b></td><td data-label="Confidence" data-sort="<?=e((string)($r['confidence_score']??0))?>"><?=dr_num($r['confidence_score'],0)?>%</td><td data-label="Verified" data-sort="<?=$verifiedCount?>"><?=$verifiedCount?></td><td data-label="Possible" data-sort="<?=(int)$r['possible_defense_matches']?>"><?=(int)$r['possible_defense_matches']?></td><td data-label="Defense S/A" data-sort="<?=e((string)($r['defense_attempts']??$r['defense_actions']??0))?>"><?=(int)($r['defense_actions']??0)?> / <?=(int)($r['defense_attempts']??$r['defense_actions']??0)?></td><td data-label="Observed" data-sort="<?=(int)$r['observed_matches']?>"><?=(int)$r['observed_matches']?></td><td data-label="Coverage" data-sort="<?=e((string)($eventKey!==''?(int)($r['contributing_orgs']??0):(int)($r['events_observed']??0)))?>"><?=e($coverage)?></td><td data-label="Read"><?php $evidenceCount=count($defenseEvidenceByTeam[$teamNo]??[]);?><button type="button" class="dr-read-btn" data-depa-read data-team="<?=$teamNo?>" data-team-name="<?=e($teamDisplay)?>" data-read="<?=e((string)$r['validation_label'])?>" data-count="<?=$evidenceCount?>"><?=e((string)$r['validation_label'])?></button></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="14"><div class="notice">No D-EPA ratings are archived for this view yet.</div></td></tr><?php endif;?></tbody></table></section>

<div class="dr-modal" id="depaReadModal" hidden aria-hidden="true">
  <div class="dr-modal-backdrop" data-depa-close></div>
  <section class="dr-modal-card" role="dialog" aria-modal="true" aria-labelledby="depaReadTitle">
    <div class="dr-modal-head">
      <div>
        <div class="dr-brand">Neptune D-EPA · Match Evidence</div>
        <h2 id="depaReadTitle">Defense Match Detail</h2>
        <p class="dr-note" id="depaReadSubtitle" style="margin:0"></p>
      </div>
      <button type="button" class="btn secondary dr-modal-close" data-depa-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="notice" id="depaReadEmpty" hidden>No defensive-action or Spot-defense matches are stored for this team in the selected view.</div>
    <div class="table-wrap" id="depaReadTableWrap">
      <table class="dr-modal-table">
        <thead><tr><th>Match</th><th>Status</th><th>Used?</th><th>Success</th><th>Failure</th><th>Attempts</th><th>Success %</th><th>Spot</th><th>D-EPA</th><th>Projected Opp.</th><th>Actual Opp.</th><th>Coverage</th><th>Video</th></tr></thead>
        <tbody id="depaReadBody"></tbody>
      </table>
    </div>
    <p class="dr-note" style="margin:12px 0 0"><b>Used?</b> is Yes only for Verified matches. Verification is based on defensive role evidence: 2+ total defense attempts (success or failure), or a Spot defense tag. Success/failure shows effectiveness; match D-EPA shows the actual opponent-score suppression outcome.</p>
  </section>
</div>

<section class="card dr-api" id="developer-api"><h2 style="margin-top:0"><i class="fa-solid fa-code"></i> Developer API</h2><p class="dr-note">Read-only JSON exposes the canonical public ratings and coverage counts only. Organization IDs, scout identities, notes, IP addresses, and raw private scouting rows are never returned.</p><div class="dr-actions"><a class="btn secondary" href="<?=e($api.'?year='.$year)?>" target="_blank" rel="noopener"><i class="fa-solid fa-brackets-curly"></i> View <?=$year?> JSON</a><?php if($eventKey):?><a class="btn secondary" href="<?=e($api.'?event='.rawurlencode($eventKey))?>" target="_blank" rel="noopener"><i class="fa-solid fa-calendar-days"></i> View Event JSON</a><?php endif;?></div><code><?=e($api.'?events=1&year='.$year)?></code><code><?=e($api.'?year='.$year)?></code><?php if($eventKey):?><code><?=e($api.'?event='.rawurlencode($eventKey))?></code><?php endif;?><code><?=e($api.'?year='.$year.'&team=TEAM_NUMBER')?></code></section><p class="dr-note" style="margin-top:14px">Model: <?=e(AUGUR_DEPA_MODEL_VERSION)?>. Higher is better. D-EPA is an all-match inference signal and can be positive or negative even without confirmed defense. Neptune D-EPA uses only canonical Verified defense matches; confidence never changes either point estimate.<?php if($lastUpdated!==''):?> Ratings updated <?=e($lastUpdated)?>.<?php endif;?></p><?php endif;?>
<script>
window.NEPTUNE_DEPA_EVIDENCE=<?=json_encode($defenseEvidenceByTeam,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
window.NEPTUNE_DEPA_VIDEO_API=<?=json_encode(base_url('api/tba-match-videos.php'),JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
</script>
<script>
(function(){
  const modal=document.getElementById('depaReadModal');
  const body=document.getElementById('depaReadBody');
  const empty=document.getElementById('depaReadEmpty');
  const wrap=document.getElementById('depaReadTableWrap');
  const title=document.getElementById('depaReadTitle');
  const subtitle=document.getElementById('depaReadSubtitle');
  if(!modal||!body)return;

  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const signed=v=>{
    if(v===null||v===undefined||v==='')return '—';
    const n=Number(v); if(!Number.isFinite(n))return '—';
    return (n>0?'+':'')+n.toFixed(1);
  };
  const fmt=v=>{
    if(v===null||v===undefined||v==='')return '—';
    const n=Number(v); return Number.isFinite(n)?n.toFixed(1):'—';
  };
  const matchLabel=r=>{
    const key=String(r.tba_match_key||'');
    const m=key.match(/_(qm)(\d+)$/i);
    if(m)return 'Q'+m[2];
    return key.includes('_')?key.split('_').pop().toUpperCase():('Match '+r.match_number);
  };
  const tbaUrl=key=>'https://www.thebluealliance.com/match/'+encodeURIComponent(key);

  function close(){
    modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.classList.remove('dr-modal-open');
  }
  modal.querySelectorAll('[data-depa-close]').forEach(x=>x.addEventListener('click',close));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});

  async function loadVideos(rows){
    const keys=[...new Set(rows.map(r=>r.tba_match_key).filter(Boolean))];
    if(!keys.length)return;
    const cells=new Map();
    keys.forEach(k=>{
      const el=body.querySelector('[data-video-key="'+CSS.escape(k)+'"]');
      if(el)cells.set(k,el);
    });
    try{
      const url=window.NEPTUNE_DEPA_VIDEO_API+'?matches='+encodeURIComponent(keys.join(','));
      const res=await fetch(url,{headers:{'Accept':'application/json'}});
      if(!res.ok)throw new Error('video lookup failed');
      const data=await res.json();
      keys.forEach(k=>{
        const cell=cells.get(k); if(!cell)return;
        const item=data.matches&&data.matches[k]?data.matches[k]:null;
        if(item&&item.youtube_url){
          cell.innerHTML='<a class="dr-video-link" href="'+esc(item.youtube_url)+'" target="_blank" rel="noopener"><i class="fa-brands fa-youtube"></i> YouTube</a>';
        }else{
          const tba=item&&item.tba_url?item.tba_url:tbaUrl(k);
          cell.innerHTML='<a class="dr-video-link" href="'+esc(tba)+'" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> TBA Match</a><div class="dr-video-note">No YouTube video listed</div>';
        }
      });
    }catch(err){
      keys.forEach(k=>{
        const cell=cells.get(k); if(!cell)return;
        cell.innerHTML='<a class="dr-video-link" href="'+esc(tbaUrl(k))+'" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> TBA Match</a>';
      });
    }
  }

  document.addEventListener('click',e=>{
    const btn=e.target.closest('[data-depa-read]');
    if(!btn)return;
    const team=Number(btn.dataset.team||0);
    const name=btn.dataset.teamName||'';
    const read=btn.dataset.read||'';
    const rows=(window.NEPTUNE_DEPA_EVIDENCE&&window.NEPTUNE_DEPA_EVIDENCE[team])||[];
    title.textContent='#'+team+(name&&name!=='—'?' · '+name:'');
    subtitle.textContent=read+' · '+rows.length+' defensive-evidence match'+(rows.length===1?'':'es')+' in this view';
    body.innerHTML='';
    empty.hidden=rows.length>0;
    wrap.hidden=rows.length===0;

    rows.forEach(r=>{
      const spot=Number(r.spot_great_defense||0)+Number(r.spot_weak_defense||0);
      const used=r.used_in_neptune_depa===true;
      const tr=document.createElement('tr');
      tr.innerHTML=
        '<td><b>'+esc(matchLabel(r))+'</b><div class="dr-video-note">'+esc(r.tba_match_key)+'</div></td>'+
        '<td><span class="dr-evidence-status '+esc(r.defense_status)+'">'+esc(r.defense_status)+'</span></td>'+
        '<td><b>'+(used?'Yes':'No')+'</b></td>'+
        '<td>'+esc(r.defense_actions)+'</td>'+
        '<td>'+esc(r.defense_failures||0)+'</td>'+
        '<td>'+esc(r.defense_attempts||0)+'</td>'+
        '<td>'+((Number(r.defense_attempts||0)>0)?((Number(r.defense_actions||0)*100/Number(r.defense_attempts)).toFixed(0)+'%'):'—')+'</td>'+
        '<td>'+esc(spot)+'</td>'+
        '<td><b>'+signed(r.raw_depa)+'</b></td>'+
        '<td>'+fmt(r.expected_opponent_score)+'</td>'+
        '<td>'+fmt(r.actual_opponent_score)+'</td>'+
        '<td>'+esc(r.observing_orgs)+' org'+(Number(r.observing_orgs)===1?'':'s')+'</td>'+
        '<td data-video-key="'+esc(r.tba_match_key)+'"><span class="dr-video-note">Finding video…</span></td>';
      body.appendChild(tr);
    });

    modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.classList.add('dr-modal-open');
    modal.querySelector('.dr-modal-close')?.focus();
    loadVideos(rows);
  });
})();
</script>

<script>
(function(){
  function valueFor(td){
    if(!td) return {empty:true,num:null,text:''};
    const explicit=td.getAttribute('data-sort');
    const raw=(explicit!==null?explicit:td.textContent).trim();
    if(raw===''||raw==='—') return {empty:true,num:null,text:''};
    const cleaned=raw.replace(/[,#%+]/g,'').replace(/\s+(orgs?|events?|matches?)$/i,'').trim();
    if(/^[-+]?\d+(?:\.\d+)?$/.test(cleaned)) return {empty:false,num:Number(cleaned),text:raw.toLowerCase()};
    return {empty:false,num:null,text:raw.toLowerCase()};
  }
  function sortTable(table,col,dir){
    const tbody=table.tBodies[0]; if(!tbody) return;
    const rows=Array.from(tbody.rows).filter(r=>r.cells.length>1);
    rows.sort((a,b)=>{
      const av=valueFor(a.cells[col]), bv=valueFor(b.cells[col]);
      if(av.empty!==bv.empty) return av.empty?1:-1;
      let cmp=0;
      if(av.num!==null && bv.num!==null) cmp=av.num-bv.num;
      else cmp=av.text.localeCompare(bv.text,undefined,{numeric:true,sensitivity:'base'});
      return dir==='asc'?cmp:-cmp;
    });
    rows.forEach(r=>tbody.appendChild(r));
    table.querySelectorAll('th[data-sortable]').forEach((th,i)=>{
      th.setAttribute('aria-sort',i===col?(dir==='asc'?'ascending':'descending'):'none');
      const mark=th.querySelector('.sort-mark');
      if(mark) mark.textContent=i===col?(dir==='asc'?'▲':'▼'):'↕';
    });
    const select=table.closest('.ratings-sort-scope')?.querySelector('[data-sort-select]');
    if(select) select.value=String(col);
  }
  document.querySelectorAll('table[data-sortable-table]').forEach(table=>{
    const headers=Array.from(table.querySelectorAll('thead th'));
    headers.forEach((th,col)=>{
      th.setAttribute('data-sortable','1');
      th.setAttribute('tabindex','0');
      th.setAttribute('role','button');
      th.setAttribute('aria-sort','none');
      th.title='Sort by '+th.textContent.trim();
      th.innerHTML='<span class="sort-label">'+th.innerHTML+'</span> <span class="sort-mark" aria-hidden="true">↕</span>';
      const go=()=>{
        const current=table.getAttribute('data-sort-col');
        const currentDir=table.getAttribute('data-sort-dir')||'desc';
        const numeric=headers[col].getAttribute('data-sort-kind')==='number';
        const dir=(current===String(col))?(currentDir==='asc'?'desc':'asc'):(numeric?'desc':'asc');
        table.setAttribute('data-sort-col',String(col));
        table.setAttribute('data-sort-dir',dir);
        sortTable(table,col,dir);
      };
      th.addEventListener('click',go);
      th.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();go();}});
    });
    const scope=table.closest('.ratings-sort-scope');
    if(scope){
      const select=scope.querySelector('[data-sort-select]');
      const dirBtn=scope.querySelector('[data-sort-dir]');
      if(select){
        headers.forEach((th,i)=>{
          const o=document.createElement('option'); o.value=String(i); o.textContent=th.querySelector('.sort-label')?.textContent.trim()||th.textContent.trim(); select.appendChild(o);
        });
        select.addEventListener('change',()=>{
          const col=Number(select.value); const numeric=headers[col]?.getAttribute('data-sort-kind')==='number';
          const dir=numeric?'desc':'asc'; table.setAttribute('data-sort-col',String(col)); table.setAttribute('data-sort-dir',dir); sortTable(table,col,dir);
        });
      }
      if(dirBtn) dirBtn.addEventListener('click',()=>{
        const col=Number(table.getAttribute('data-sort-col')||select?.value||0);
        const dir=(table.getAttribute('data-sort-dir')||'desc')==='asc'?'desc':'asc';
        table.setAttribute('data-sort-col',String(col)); table.setAttribute('data-sort-dir',dir); sortTable(table,col,dir);
      });
    }
  });
})();
</script>

<footer class="dr-footer">Neptune D-EPA · AUGUR public defensive analytics</footer></div>
<?php if($viewer){include dirname(__DIR__).'/partials_footer.php';}else{?></main></body></html><?php }?>
