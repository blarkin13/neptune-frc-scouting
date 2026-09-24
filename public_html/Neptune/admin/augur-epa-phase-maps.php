<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

$u=require_role(['owner','admin','strategy']);
if(!augur_epa_tables_ready($pdo)){
    http_response_code(503);
    exit('AUGUR Public EPA Archive is not installed.');
}

function epm_parse_terms(string $text): array {
    $terms=[];
    foreach(preg_split('/\R/',trim($text))?:[] as $line){
        $line=trim($line);
        if($line===''||str_starts_with($line,'#'))continue;
        if(preg_match('/^([+-]?(?:\d+(?:\.\d+)?|\.\d+))\s*\*\s*([A-Za-z0-9_.-]+)$/',$line,$m)){
            $terms[]=['path'=>$m[2],'weight'=>(float)$m[1]];
        }elseif(preg_match('/^[A-Za-z0-9_.-]+$/',$line)){
            $terms[]=['path'=>$line,'weight'=>1.0];
        }else{
            throw new RuntimeException("Invalid mapping line: {$line}. Use field.path or 2 * field.path.");
        }
    }
    return $terms;
}
function epm_terms_text(array $terms): string {
    $lines=[];
    foreach($terms as $term){
        if(is_string($term)){$lines[]=$term;continue;}
        if(!is_array($term))continue;
        $path=trim((string)($term['path']??''));
        if($path==='')continue;
        $weight=is_numeric($term['weight']??null)?(float)$term['weight']:1.0;
        $lines[]=abs($weight-1.0)<0.000001?$path:($weight.' * '.$path);
    }
    return implode("\n",$lines);
}
function epm_flatten_numeric(array $row,string $prefix=''): array {
    $out=[];
    foreach($row as $k=>$v){
        $path=$prefix===''?(string)$k:$prefix.'.'.$k;
        if(is_array($v))$out+=epm_flatten_numeric($v,$path);
        elseif(is_numeric($v))$out[$path]=(float)$v;
    }
    ksort($out);
    return $out;
}

$current=(int)date('Y');
$year=(int)($_GET['year']??$_POST['year']??$current);
$year=max(1992,min($current+1,$year));
$flash=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        if(!augur_epa_phase_maps_ready($pdo))throw new RuntimeException('Run the phase-map SQL migration first.');

        if(($_POST['action']??'')==='delete'){
            $s=$pdo->prepare('DELETE FROM augur_epa_phase_maps WHERE season_year=?');
            $s->execute([$year]);
            augur_epa_phase_map($pdo,$year,true);
            $flash=['good','Custom phase mapping removed. AUGUR will use its historical/automatic fallback for '.$year.'.'];
        }else{
            $allowed=['sum','remainder','unavailable'];
            $map=['version'=>1];
            foreach(['auto','teleop','endgame'] as $phase){
                $mode=(string)($_POST[$phase.'_mode']??'sum');
                if(!in_array($mode,$allowed,true))$mode='sum';
                $map[$phase]=[
                    'mode'=>$mode,
                    'terms'=>$mode==='sum'?epm_parse_terms((string)($_POST[$phase.'_terms']??'')):[],
                ];
            }
            $map['modeled_subtract']=epm_parse_terms((string)($_POST['modeled_subtract']??''));
            $reconcile=(string)($_POST['reconcile']??'teleop');
            $map['reconcile']=in_array($reconcile,['','auto','teleop','endgame'],true)?$reconcile:'teleop';

            $json=json_encode($map,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if($json===false)throw new RuntimeException('Could not encode phase mapping.');

            $uid=(int)($u['id']??0);$uid=$uid>0?$uid:null;
            $s=$pdo->prepare("INSERT INTO augur_epa_phase_maps
                (season_year,mapping_json,enabled,notes,updated_by)
                VALUES(?,?,1,?,?)
                ON DUPLICATE KEY UPDATE mapping_json=VALUES(mapping_json),enabled=1,notes=VALUES(notes),
                    updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $s->execute([$year,$json,trim((string)($_POST['notes']??'')),$uid]);
            augur_epa_phase_map($pdo,$year,true);
            $flash=['good','Phase mapping saved for '.$year.'. Run the EPA CLI with --recalc-only for this year to apply it to archived matches.'];
        }
    }catch(Throwable $e){
        $flash=['bad',$e->getMessage()];
    }
}

$mapping=null;$notes='';
if(augur_epa_phase_maps_ready($pdo)){
    $s=$pdo->prepare('SELECT mapping_json,notes FROM augur_epa_phase_maps WHERE season_year=? LIMIT 1');
    $s->execute([$year]);
    if($r=$s->fetch()){
        $mapping=json_decode((string)$r['mapping_json'],true);
        if(!is_array($mapping))$mapping=null;
        $notes=(string)($r['notes']??'');
    }
}
$mapping=$mapping?:[
    'version'=>1,
    'auto'=>['mode'=>'sum','terms'=>[]],
    'teleop'=>['mode'=>'sum','terms'=>[]],
    'endgame'=>['mode'=>'sum','terms'=>[]],
    'modeled_subtract'=>[],
    'reconcile'=>'teleop',
];

$sample=[];
$s=$pdo->prepare("SELECT score_breakdown_json,tba_event_key,tba_match_key
    FROM augur_epa_alliance_samples
    WHERE season_year=? AND score_breakdown_json IS NOT NULL AND score_breakdown_json<>''
    ORDER BY updated_at DESC LIMIT 1");
$s->execute([$year]);
if($r=$s->fetch()){
    $decoded=json_decode((string)$r['score_breakdown_json'],true);
    if(is_array($decoded)){
        $sample=epm_flatten_numeric($decoded);
        $sampleMeta=['event'=>$r['tba_event_key'],'match'=>$r['tba_match_key']];
    }
}

$pageTitle='AUGUR · Public EPA Phase Mapping';$moduleName='AUGUR';
include dirname(__DIR__).'/partials_header.php';
?>
<section class="module-page">
<header class="module-page-header">
  <div><div class="module-code">AUGUR</div><h1>EPA Phase Mapping</h1><p>Map TBA score-breakdown fields into Auto, Teleop, and Endgame without changing the EPA engine.</p></div>
  <div class="toolbar">
    <a class="btn secondary" href="<?=e(base_url('admin/augur-epa-archive.php'))?>"><i class="fa-solid fa-box-archive"></i> Public EPA Archive</a>
    <a class="btn secondary" href="<?=e(base_url('analytics/augur-ratings.php'))?>"><i class="fa-solid fa-globe"></i> Public Ratings</a>
  </div>
</header>

<?php if($flash):?><div class="notice <?=$flash[0]==='bad'?'bad':'good'?>"><?=e($flash[1])?></div><?php endif;?>

<style>
.epm-year{display:grid;grid-template-columns:minmax(180px,260px) auto;gap:10px;align-items:end}
.epm-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.epm-box{border:1px solid var(--line);border-radius:9px;padding:12px;background:var(--panel2)}
.epm-box textarea{min-height:155px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem}
.epm-fields{max-height:420px;overflow:auto;border:1px solid var(--line);border-radius:8px}
.epm-fields table{width:100%;border-collapse:collapse}.epm-fields th,.epm-fields td{padding:7px 9px;border-bottom:1px solid var(--line);text-align:left;font-size:.75rem}.epm-fields th{position:sticky;top:0;background:var(--panel2)}
.epm-help{font-size:.74rem;color:var(--muted);line-height:1.45}
@media(max-width:900px){.epm-grid{grid-template-columns:1fr}.epm-year{grid-template-columns:1fr}}
</style>

<div class="card" style="padding:14px">
  <form method="get" class="epm-year">
    <div><label>Season</label><input type="number" name="year" min="1992" max="<?=$current+1?>" value="<?=$year?>"></div>
    <button type="submit"><i class="fa-solid fa-calendar"></i> Load Season</button>
  </form>
</div>

<?php if(!augur_epa_phase_maps_ready($pdo)):?>
<div class="notice bad" style="margin-top:14px"><b>Phase mapping storage is not installed.</b> Run <code>sql/2026-09-21_augur-epa-phase-maps-v5.sql</code>.</div>
<?php else:?>
<form method="post" class="card" style="padding:14px;margin-top:14px">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <input type="hidden" name="year" value="<?=$year?>">
  <input type="hidden" name="action" value="save">

  <div class="epm-grid">
    <?php foreach(['auto'=>'Auto','teleop'=>'Teleop','endgame'=>'Endgame'] as $key=>$label):
      $spec=is_array($mapping[$key]??null)?$mapping[$key]:[];
      $mode=(string)($spec['mode']??'sum');
    ?>
    <div class="epm-box">
      <h2 style="margin-top:0"><?=$label?></h2>
      <label>Calculation</label>
      <select name="<?=$key?>_mode">
        <option value="sum" <?=$mode==='sum'?'selected':''?>>Sum mapped fields</option>
        <option value="remainder" <?=$mode==='remainder'?'selected':''?>>Remainder of modeled score</option>
        <option value="unavailable" <?=$mode==='unavailable'?'selected':''?>>Unavailable</option>
      </select>
      <label>Fields <span class="muted">one per line</span></label>
      <textarea name="<?=$key?>_terms" spellcheck="false" placeholder="field.path&#10;2 * anotherField"><?=e(epm_terms_text(is_array($spec['terms']??null)?$spec['terms']:[]))?></textarea>
    </div>
    <?php endforeach;?>
  </div>

  <div class="epm-grid" style="margin-top:12px">
    <div class="epm-box">
      <h3 style="margin-top:0">Modeled-score deductions</h3>
      <p class="epm-help">Optional public score fields that should be removed before EPA attribution, such as certain score-based bonus points.</p>
      <textarea name="modeled_subtract" spellcheck="false" placeholder="bonusPoints&#10;2 * anotherBonus"><?=e(epm_terms_text(is_array($mapping['modeled_subtract']??null)?$mapping['modeled_subtract']:[]))?></textarea>
    </div>
    <div class="epm-box">
      <h3 style="margin-top:0">Reconciliation</h3>
      <p class="epm-help">If the three phase totals do not exactly equal the modeled alliance score, put the residual into one phase. Statbotics commonly reconciles to Teleop.</p>
      <label>Residual phase</label>
      <select name="reconcile">
        <?php foreach([''=>'Do not reconcile','auto'=>'Auto','teleop'=>'Teleop','endgame'=>'Endgame'] as $v=>$label):?>
          <option value="<?=e($v)?>" <?=($mapping['reconcile']??'teleop')===$v?'selected':''?>><?=e($label)?></option>
        <?php endforeach;?>
      </select>
      <label>Notes</label>
      <textarea name="notes" rows="5" placeholder="Source / explanation for this season's mapping"><?=e($notes)?></textarea>
    </div>
    <div class="epm-box">
      <h3 style="margin-top:0">After saving</h3>
      <p class="epm-help">The raw TBA JSON is already archived. You do not need to download matches again. Recalculate this season from SSH:</p>
      <code style="display:block;white-space:pre-wrap">php admin/augur-epa-backfill-cli.php <?=$year?> <?=$year?> --recalc-only</code>
      <p class="epm-help" style="margin-top:10px">Overall EPA works even without a phase map. This mapping only controls Auto / Teleop / Endgame decomposition.</p>
    </div>
  </div>

  <div class="toolbar" style="margin-top:14px">
    <button type="submit"><i class="fa-solid fa-floppy-disk"></i> Save <?=$year?> Mapping</button>
  </div>
</form>

<?php if($sample):?>
<section class="card" style="padding:14px;margin-top:14px">
  <h2 style="margin-top:0">Available TBA fields</h2>
  <p class="muted">Sample from <?=e($sampleMeta['event']??'')?> · <?=e($sampleMeta['match']??'')?>. Copy the field path exactly into a phase box above.</p>
  <div class="epm-fields"><table><thead><tr><th>Field path</th><th>Sample value</th></tr></thead><tbody>
    <?php foreach($sample as $path=>$value):?><tr><td><code><?=e($path)?></code></td><td><?=e($value)?></td></tr><?php endforeach;?>
  </tbody></table></div>
</section>
<?php else:?>
<div class="notice" style="margin-top:14px">No archived TBA score-breakdown sample exists for <?=$year?> yet. Overall EPA can still run from alliance scores; phase mapping can be configured after the first event is archived.</div>
<?php endif;?>

<form method="post" style="margin-top:14px" onsubmit="return confirm('Remove the custom phase mapping for <?=$year?>?');">
  <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
  <input type="hidden" name="year" value="<?=$year?>">
  <input type="hidden" name="action" value="delete">
  <button type="submit" class="secondary"><i class="fa-solid fa-rotate-left"></i> Use Historical / Automatic Fallback</button>
</form>
<?php endif;?>
</section>
<?php include dirname(__DIR__).'/partials_footer.php';
