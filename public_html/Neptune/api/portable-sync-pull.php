<?php
declare(strict_types=1);
/** Neptune cloud -> portable read API. Never mutates scouting data. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function npp_reply(array $data, int $code=200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') npp_reply(['ok'=>false,'error'=>'POST required'],405);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/portable.php';
require_once dirname(__DIR__).'/scout/_tag_synthetic.php';
if (!isset($pdo) || !($pdo instanceof PDO)) npp_reply(['ok'=>false,'error'=>'Database unavailable'],503);
$data=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($data)) npp_reply(['ok'=>false,'error'=>'Invalid JSON'],400);
$org=(int)($data['organization_id']??0);
$event=(int)($data['event_id']??0);
$section=(string)($data['section']??'');
$after=$data['after_id']??0;
$limit=$data['limit']??150;
$token=trim((string)($_SERVER['HTTP_X_NEPTUNE_PORTABLE_TOKEN']??''));
$sections=['tag_definitions','scout_sessions','scouting_actions','tag_observations','tag_match_data'];
if($org<=0 || $event<=0 || !in_array($section,$sections,true)
   || !is_numeric($after) || (int)$after<0 || (string)(int)$after !== (string)$after
   || !is_numeric($limit) || (int)$limit<1 || (int)$limit>200) {
    npp_reply(['ok'=>false,'error'=>'Invalid sync request'],422);
}
try {
    // Authenticate BEFORE returning whether event or organization exists.
    neptune_portable_authenticate_token($pdo,$token,$org,$event);
} catch (Throwable $e) { npp_reply(['ok'=>false,'error'=>'Unauthorized'],401); }
$eventCheck=$pdo->prepare('SELECT id FROM events WHERE id=? AND organization_id=? LIMIT 1');
$eventCheck->execute([$event,$org]);
if(!$eventCheck->fetchColumn()) npp_reply(['ok'=>false,'error'=>'Event not available'],422);
$after=(int)$after; $limit=(int)$limit;
try {
    $joins=''; $where=''; $params=[];
    switch($section){
        case 'tag_definitions':
            $sql='SELECT t.* FROM tag_scouting_tags t WHERE (t.organization_id=? OR t.organization_id IS NULL) AND t.id>? ORDER BY t.id ASC LIMIT '.$limit;
            $params=[$org,$after]; break;
        case 'scout_sessions':
            $sql='SELECT s.*, m.comp_level AS match_comp_level,m.set_number AS match_set_number,m.match_number AS match_number,m.field_id AS match_field_id
                  FROM scout_sessions s JOIN matches m ON m.id=s.match_id AND m.organization_id=s.organization_id AND m.event_id=s.event_id
                  WHERE s.organization_id=? AND s.event_id=? AND s.id>? ORDER BY s.id ASC LIMIT '.$limit;
            $params=[$org,$event,$after]; break;
        case 'scouting_actions':
            $sql='SELECT a.*, ss.uuid AS scout_session_uuid, m.comp_level AS match_comp_level,m.set_number AS match_set_number,m.match_number AS match_number,m.field_id AS match_field_id
                  FROM scouting_actions a JOIN matches m ON m.id=a.match_id AND m.organization_id=a.organization_id AND m.event_id=a.event_id
                  LEFT JOIN scout_sessions ss ON ss.id=a.scout_session_id AND ss.organization_id=a.organization_id
                  WHERE a.organization_id=? AND a.event_id=? AND a.id>? ORDER BY a.id ASC LIMIT '.$limit;
            $params=[$org,$event,$after]; break;
        case 'tag_observations':
            $sql='SELECT o.*, m.comp_level AS match_comp_level,m.set_number AS match_set_number,m.match_number AS match_number,m.field_id AS match_field_id
                  FROM tag_scouting_observations o LEFT JOIN matches m ON m.id=o.match_id AND m.organization_id=o.organization_id
                  WHERE o.organization_id=? AND (o.event_id=? OR o.event_id IS NULL) AND o.id>? AND '.neptune_tag_human_observation_sql('o').' ORDER BY o.id ASC LIMIT '.$limit;
            $params=[$org,$event,$after]; break;
        case 'tag_match_data':
            $sql='SELECT d.*,o.uuid AS observation_uuid,m.comp_level AS match_comp_level,m.set_number AS match_set_number,m.match_number AS match_number,m.field_id AS match_field_id
                  FROM tag_scouting_match_data d LEFT JOIN tag_scouting_observations o ON o.id=d.observation_id AND o.organization_id=d.organization_id
                  JOIN matches m ON m.id=d.match_id AND m.organization_id=d.organization_id AND m.event_id=d.event_id
                  WHERE d.organization_id=? AND d.event_id=? AND d.id>? AND '.neptune_tag_human_match_data_sql('d').' ORDER BY d.id ASC LIMIT '.$limit;
            $params=[$org,$event,$after]; break;
    }
    if(!neptune_portable_table_exists($pdo, match($section) {
        'tag_definitions'=>'tag_scouting_tags','tag_observations'=>'tag_scouting_observations','tag_match_data'=>'tag_scouting_match_data',default=>$section,
    })) npp_reply(['ok'=>false,'error'=>'Source section unavailable: '.$section],503);
    $s=$pdo->prepare($sql);$s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if($section==='tag_observations' && $rows) {
        $tags=$pdo->prepare('SELECT t.organization_id AS tag_organization_id,t.seed_key,t.slug,ot.weight
          FROM tag_scouting_observation_tags ot JOIN tag_scouting_tags t ON t.id=ot.tag_id
          WHERE ot.observation_id=? AND (t.organization_id=? OR t.organization_id IS NULL)');
        foreach($rows as &$row){$tags->execute([(int)$row['id'],$org]);$row['tag_links']=$tags->fetchAll(PDO::FETCH_ASSOC);}unset($row);
    }
    // The next cursor is never synthesized; it always comes from a source ID.
    $next=$rows?(int)$rows[count($rows)-1]['id']:$after;
    npp_reply(['ok'=>true,'organization_id'=>$org,'event_id'=>$event,'section'=>$section,
        'after_id'=>$after,'next_id'=>$next,'more'=>count($rows)===$limit,'rows'=>$rows]);
} catch(Throwable $e) {
    error_log('[Neptune cloud pull] '.$section.' '.$e->getMessage());
    npp_reply(['ok'=>false,'error'=>'Cloud pull query failed'],500);
}
