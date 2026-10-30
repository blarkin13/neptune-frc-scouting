<?php
declare(strict_types=1);

/** Shared robot-identity (tracking association) model for every helper of an organization.
 *
 * The helper where tracking is reviewed trains the model from its reviews and reports here: its latest status always,
 * and the model itself only once it has passed the independent identity check on that helper. Every helper downloads
 * the shared model, so robot names stay consistent whichever computer processes a match.
 * Files live under neptune_secure/tracking-identity/<org>/: status.json (latest report), shared.json (model in use).
 */
final class TrackingIdentityShare {
 private string $root;
 public function __construct(string $secure,int $org){$this->root=$secure.'/tracking-identity/'.$org;}
 private function dir():string{if(!is_dir($this->root)&&!mkdir($this->root,0700,true))throw new RuntimeException('Tracking storage unavailable');return $this->root;}
 private static function write(string $file,array $data):void{
  $tmp=tempnam(dirname($file),'pending-');if($tmp===false||file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save');
  chmod($tmp,0600);if(!rename($tmp,$file))throw new RuntimeException('Could not commit save');
 }
 private function read(string $name):?array{$f=$this->root.'/'.$name;return is_file($f)?(json_decode((string)file_get_contents($f),true)?:null):null;}
 private static function matches(mixed $v):array{return array_values(array_filter(array_map('strval',is_array($v)?array_slice($v,0,200):[]),fn($m)=>(bool)preg_match('/^\d{4}[A-Za-z0-9_-]{1,40}$/D',$m)));}
 private static function counts(mixed $v):array{$out=[];foreach(is_array($v)?$v:[] as $k=>$x)if(is_string($k)&&preg_match('/^[a-z_]{1,24}$/D',$k)&&(is_int($x)||is_float($x))&&is_finite((float)$x))$out[$k]=$x;return $out;}
 private static function model(mixed $m):?array{
  if(!is_array($m)||!is_array($m['weights']??null)||count($m['weights'])!==5)return null;
  foreach(array_merge($m['weights'],[$m['bias']??null]) as $v)if(!(is_int($v)||is_float($v))||!is_finite((float)$v)||abs((float)$v)>=100)return null;
  return ['weights'=>array_map('floatval',$m['weights']),'bias'=>(float)$m['bias']];
 }
 /** A helper's report after it trains: status always; the model only when it passed the independent identity check there. */
 public function put(array $computer,array $p):array{
  $r=is_array($p['report']??null)?$p['report']:[];$version=(string)($r['version']??'');
  if(!preg_match('/^[a-z0-9.\-]{1,40}$/D',$version))throw new InvalidArgumentException('Invalid tracking report');
  $report=['version'=>$version,'status'=>in_array($r['status']??'',['needs_reviewed_matches','candidate','active'],true)?$r['status']:'candidate',
   'reason'=>mb_substr((string)($r['reason']??''),0,300),'pairs'=>(int)($r['pairs']??0),'matches'=>self::matches($r['matches']??[]),
   'validation'=>self::counts($r['validation']??[]),'identity_validation'=>self::counts($r['identity_validation']??[])];
  $this->dir();self::write($this->root.'/status.json',['computer'=>(string)$computer['id'],'at'=>time(),'report'=>$report]);
  $model=self::model($p['model']??null);$check=is_array($r['identity_validation']??null)?$r['identity_validation']:[];
  if($report['status']==='active'&&$model&&!empty($check['passed'])){
   self::write($this->root.'/shared.json',['version'=>$version,'model'=>$model,'matches'=>$report['matches'],'identity_validation'=>self::counts($check)+['passed'=>1],'trained_by'=>(string)$computer['id'],'at'=>time()]);
  }
  return ['ok'=>true,'shared'=>$this->read('shared.json')!==null];
 }
 /** The shared model for a helper to use, unless it already has this one. */
 public function current(?string $have):array{
  $s=$this->read('shared.json');if(!$s)return ['model'=>null];
  $sha=hash('sha256',json_encode([$s['version'],$s['model']],JSON_THROW_ON_ERROR));
  return $have===$sha?['unchanged'=>true,'sha256'=>$sha]:['sha256'=>$sha,'version'=>$s['version'],'model'=>$s['model'],'trained_by'=>$s['trained_by'],'at'=>$s['at'],'matches'=>$s['matches']];
 }
 /** For the Tracking pages: the latest report, the shared model (without weights) and the reviews saved on Neptune. */
 public function summary(PDO $pdo,int $org,array $names):array{
  $status=$this->read('status.json');$shared=$this->read('shared.json');
  $q=$pdo->prepare('SELECT match_key,updated_at FROM community_identity_reviews WHERE organization_id=? ORDER BY updated_at DESC');
  try{$q->execute([$org]);$reviews=array_map(fn($r)=>['match_key'=>$r['match_key'],'updated_at'=>(int)$r['updated_at']],$q->fetchAll(PDO::FETCH_ASSOC));}catch(PDOException){$reviews=[];}
  return ['report'=>$status?$status['report']+['computer'=>$names[$status['computer']]??'a helper','at'=>$status['at']]:null,
   'shared'=>$shared?['version'=>$shared['version'],'matches'=>$shared['matches'],'identity_validation'=>$shared['identity_validation'],'trained_by'=>$names[$shared['trained_by']]??'a helper','at'=>$shared['at']]:null,
   'reviews'=>$reviews];
 }
}
