<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_manual_match_scores.php';
$u=require_login(); // Every logged-in scout in the same organization may add or edit.
$org=(int)($u['organization_id']??0);
$user=(int)($u['id']??0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function nms_json(array $data,int $code=200): never {
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$event=(int)($_POST['event_id']??$_GET['event_id']??0);
$match=(int)($_POST['match_id']??$_GET['match_id']??0);
if($org<1 || $user<1 || $event<1 || $match<1)nms_json(['ok'=>false,'error'=>'A valid event and match are required.'],422);
$s=$pdo->prepare('SELECT m.id,m.tba_match_key,m.red_score,m.blue_score FROM matches m JOIN events e ON e.id=m.event_id AND e.organization_id=m.organization_id WHERE m.id=? AND m.event_id=? AND m.organization_id=? LIMIT 1');
$s->execute([$match,$event,$org]);
$matchRow=$s->fetch(PDO::FETCH_ASSOC);
if(!$matchRow)nms_json(['ok'=>false,'error'=>'Match not found in your organization.'],404);
try{neptune_manual_scores_ensure_schema($pdo);}catch(Throwable $e){error_log('[Tag manual scores schema] '.$e->getMessage());nms_json(['ok'=>false,'error'=>'Score storage unavailable; ask an administrator to install the score tables.'],503);}
$view=static function()use($pdo,$org,$event,$match,$matchRow): array {
    $row=neptune_manual_scores_row($pdo,$org,$event,$match);
    return [
        'ok'=>true,'event_id'=>$event,'match_id'=>$match,
        'manual'=>$row?[
            'red_score'=>(int)$row['red_score'],'blue_score'=>(int)$row['blue_score'],
            'revision'=>(int)$row['revision'],'updated_at'=>(string)$row['updated_at']
        ]:null,
        'official'=>neptune_manual_scores_official_display($pdo,$org,$event,$matchRow),
        'effective'=>neptune_manual_scores_effective($matchRow,$row),
    ];
};
if($_SERVER['REQUEST_METHOD']==='GET')nms_json($view());
if($_SERVER['REQUEST_METHOD']!=='POST')nms_json(['ok'=>false,'error'=>'Method not allowed.'],405);
verify_csrf();
$validScore=static function(mixed $raw): ?int {
    if(!is_scalar($raw))return null;
    $value=trim((string)$raw);
    if(!preg_match('/^\d{1,4}$/D',$value))return null;
    $number=(int)$value;
    return $number<=2000?$number:null;
};
$red=$validScore($_POST['red_score']??null);
$blue=$validScore($_POST['blue_score']??null);
$rawRev=$_POST['expected_revision']??null;
if($red===null||$blue===null)nms_json(['ok'=>false,'error'=>'Enter both final scores as whole numbers between 0 and 2000.'],422);
if(!is_scalar($rawRev)||!preg_match('/^\d{1,9}$/D',(string)$rawRev))nms_json(['ok'=>false,'error'=>'Missing score revision; reload the match scores.'],422);
$expected=(int)$rawRev;
try{
    $pdo->beginTransaction();
    $s=$pdo->prepare('SELECT revision,red_score,blue_score FROM tag_manual_alliance_scores WHERE organization_id=? AND event_id=? AND match_id=? FOR UPDATE');
    $s->execute([$org,$event,$match]);
    $current=$s->fetch(PDO::FETCH_ASSOC);
    $revision=$current?(int)$current['revision']:0;
    if($revision!==$expected){$pdo->rollBack();nms_json(['ok'=>false,'error'=>'Another scout changed these scores. Review the latest values before saving.','conflict'=>true,'current'=>$view()],409);}
    $next=$revision+1;
    if($current){
        $s=$pdo->prepare('UPDATE tag_manual_alliance_scores SET red_score=?,blue_score=?,revision=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE organization_id=? AND event_id=? AND match_id=?');
        $s->execute([$red,$blue,$next,$user,$org,$event,$match]);
    }else{
        $s=$pdo->prepare('INSERT INTO tag_manual_alliance_scores (organization_id,event_id,match_id,red_score,blue_score,revision,updated_by) VALUES (?,?,?,?,?,?,?)');
        $s->execute([$org,$event,$match,$red,$blue,$next,$user]);
    }
    $s=$pdo->prepare('INSERT INTO tag_manual_alliance_score_history (organization_id,event_id,match_id,revision,red_score,blue_score,changed_by) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$org,$event,$match,$next,$red,$blue,$user]);
    $pdo->commit();
    nms_json($view());
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    // An insert race also becomes a safe edit conflict, never last-writer-wins.
    if($e instanceof PDOException && (string)$e->getCode()==='23000')nms_json(['ok'=>false,'error'=>'Another scout saved these scores. Review the latest values.','conflict'=>true,'current'=>$view()],409);
    error_log('[Tag manual score save] '.$e->getMessage());
    nms_json(['ok'=>false,'error'=>'Could not save the alliance scores. Try again.'],500);
}
