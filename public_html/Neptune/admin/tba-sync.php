<?php
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/tba.php';
require_once dirname(__DIR__).'/analytics/_augur_epa.php';

$u=require_role(['owner','admin','strategy']);
$org=(int)$u['organization_id'];
$msg='';
$error='';
$found=[];

$s=$pdo->prepare('SELECT * FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number');
$s->execute([$org]);
$teams=$s->fetchAll();

$s=$pdo->prepare("SELECT g.id,g.name,g.season_year,g.current_revision_id,gr.revision_number FROM games g JOIN game_revisions gr ON gr.id=g.current_revision_id AND gr.status='published' WHERE g.organization_id=? AND g.is_archived=0 ORDER BY g.season_year DESC,g.name");
$s->execute([$org]);
$games=$s->fetchAll();

$team=(int)($_POST['team_number']??($teams[0]['frc_team_number']??0));
$year=(int)($_POST['year']??date('Y'));
$game=(int)($_POST['game_id']??($games[0]['id']??0));

function tba_optional(string $path,int $ttl=60): array {
    try {
        return tba_get($path,$ttl);
    } catch(Throwable $e) {
        if(str_contains($e->getMessage(),'HTTP 404')) return [];
        throw $e;
    }
}

function neptune_event_name_key(string $name): string {
    $name=strtolower(trim($name));
    $name=preg_replace('/\b(first|frc|event|competition|tournament|offseason|off-season|presented by)\b/i',' ',$name);
    $name=preg_replace('/[^a-z0-9]+/',' ',$name);
    return trim(preg_replace('/\s+/',' ',$name));
}

function neptune_event_match_score(array $manual,array $tba): int {
    $score=0;

    $manualName=neptune_event_name_key((string)($manual['name']??''));
    $tbaName=neptune_event_name_key((string)($tba['name']??''));

    if($manualName!=='' && $tbaName!=='') {
        if($manualName===$tbaName) {
            $score+=100;
        } else {
            similar_text($manualName,$tbaName,$pct);
            $score+=(int)round($pct/4); // up to 25
            if(str_contains($tbaName,$manualName) || str_contains($manualName,$tbaName)) $score+=25;
        }
    }

    $ms=(string)($manual['start_date']??'');
    $me=(string)($manual['end_date']??'');
    $ts=(string)($tba['start_date']??'');
    $te=(string)($tba['end_date']??'');

    if($ms!=='' && $ts!=='' && $ms===$ts) $score+=60;
    if($me!=='' && $te!=='' && $me===$te) $score+=25;

    if($ms!=='' && $ts!=='') {
        $delta=abs((int)((strtotime($ms)-strtotime($ts))/86400));
        if($delta===1) $score+=20;
        elseif($delta<=3) $score+=8;
    }

    if(!empty($manual['is_current'])) $score+=3;

    return $score;
}

function neptune_manual_events(PDO $pdo,int $org,int $game): array {
    $s=$pdo->prepare(
        "SELECT e.*,
            (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
            (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,
            (SELECT COUNT(*) FROM pre_scouting p WHERE p.event_id=e.id AND p.organization_id=e.organization_id) pre_count,
            (SELECT COUNT(*) FROM pit_scouting p WHERE p.event_id=e.id AND p.organization_id=e.organization_id) pit_count
         FROM events e
         WHERE e.organization_id=?
           AND e.game_id=?
           AND (e.tba_event_key IS NULL OR e.tba_event_key='')
         ORDER BY e.is_current DESC,COALESCE(e.start_date,'9999-12-31'),e.name"
    );
    $s->execute([$org,$game]);
    return $s->fetchAll();
}

function neptune_rank_manual_events(array $manualEvents,array $tbaEvent): array {
    foreach($manualEvents as &$ev) {
        $ev['_match_score']=neptune_event_match_score($ev,$tbaEvent);
    }
    unset($ev);

    usort($manualEvents,static function($a,$b){
        $cmp=((int)$b['_match_score'])<=>((int)$a['_match_score']);
        if($cmp!==0) return $cmp;
        return strcasecmp((string)$a['name'],(string)$b['name']);
    });

    return $manualEvents;
}

/**
 * Find a confident TBA match for one manually created Neptune event.
 *
 * Searches the complete TBA season event list, not only events currently
 * associated with one of our teams.
 */
function neptune_find_tba_match_for_manual(array $manual,array $seasonEvents): array {
    $manualName=neptune_event_name_key((string)($manual['name']??''));
    $ranked=[];

    foreach($seasonEvents as $event) {
        if(!is_array($event) || empty($event['key'])) continue;

        $tbaName=neptune_event_name_key((string)($event['name']??''));
        if($tbaName==='') continue;

        $pct=0.0;
        if($manualName!=='' && $tbaName!=='') {
            similar_text($manualName,$tbaName,$pct);
        }

        $nameRelated=
            ($manualName!=='' && $manualName===$tbaName) ||
            ($manualName!=='' && str_contains($tbaName,$manualName)) ||
            ($tbaName!=='' && str_contains($manualName,$tbaName)) ||
            $pct>=55.0;

        if(!$nameRelated) continue;

        $ranked[]=[
            'event'=>$event,
            'score'=>neptune_event_match_score($manual,$event),
            'name_similarity'=>$pct
        ];
    }

    usort($ranked,static function($a,$b){
        $cmp=((int)$b['score'])<=>((int)$a['score']);
        if($cmp!==0) return $cmp;
        return ((float)$b['name_similarity'])<=>((float)$a['name_similarity']);
    });

    if(!$ranked) return ['status'=>'none'];

    $best=$ranked[0];
    $second=$ranked[1]??null;

    if((int)$best['score']<70) {
        return ['status'=>'none','best'=>$best];
    }

    if($second && ((int)$best['score']-(int)$second['score'])<15) {
        return ['status'=>'ambiguous','best'=>$best,'second'=>$second];
    }

    return ['status'=>'found','best'=>$best];
}

/**
 * Import/refresh a TBA event.
 *
 * When $preferredEventId is supplied, Neptune attaches the official TBA event
 * to that EXISTING manual event row. Its primary key does not change, so
 * pre-scouting, pit scouting, notes, and other event references stay attached.
 */
function import_tba_event(PDO $pdo,int $org,int $game,string $key,?int $preferredEventId=null): array {
    $grs=$pdo->prepare('SELECT current_revision_id FROM games WHERE id=? AND organization_id=? AND is_archived=0');
    $grs->execute([$game,$org]);
    $gameRevisionId=(int)$grs->fetchColumn();
    if($gameRevisionId<=0)throw new RuntimeException('Publish the selected game configuration before importing events.');
    $ev=tba_get("event/{$key}",120);
    $name=(string)($ev['name']??$key);

    $eventId=0;
    $linkedExisting=false;
    $previousName='';

    // A TBA key must belong to only one Neptune event in this organization.
    $s=$pdo->prepare('SELECT id,name FROM events WHERE organization_id=? AND tba_event_key=? LIMIT 1');
    $s->execute([$org,$key]);
    if($existing=$s->fetch()) {
        $eventId=(int)$existing['id'];
        $previousName=(string)$existing['name'];

        if($preferredEventId!==null && $preferredEventId>0 && $preferredEventId!==$eventId) {
            throw new RuntimeException(
                'That TBA event is already linked to Neptune event #'.$eventId.' ('.$previousName.').'
            );
        }
    }

    // Explicitly link a manual Neptune event selected by the admin.
    if(!$eventId && $preferredEventId!==null && $preferredEventId>0) {
        $s=$pdo->prepare(
            "SELECT id,name,tba_event_key
             FROM events
             WHERE id=? AND organization_id=? AND game_id=?
             LIMIT 1"
        );
        $s->execute([$preferredEventId,$org,$game]);
        $manual=$s->fetch();

        if(!$manual) {
            throw new RuntimeException('The selected Neptune event no longer exists for this game.');
        }

        $existingKey=trim((string)($manual['tba_event_key']??''));
        if($existingKey!=='' && $existingKey!==$key) {
            throw new RuntimeException('The selected Neptune event is already linked to another TBA event.');
        }

        $eventId=(int)$manual['id'];
        $previousName=(string)$manual['name'];
        $linkedExisting=true;
    }

    // Safe automatic match: exact official name within this game.
    if(!$eventId) {
        $s=$pdo->prepare(
            'SELECT id,name FROM events WHERE organization_id=? AND game_id=? AND name=? LIMIT 1'
        );
        $s->execute([$org,$game,$name]);
        if($sameName=$s->fetch()) {
            $eventId=(int)$sameName['id'];
            $previousName=(string)$sameName['name'];
            $linkedExisting=true;
        }
    }

    $eventRevisionId=$gameRevisionId;
    if($eventId){
        $ers=$pdo->prepare('SELECT game_id,game_revision_id FROM events WHERE id=? AND organization_id=? LIMIT 1');
        $ers->execute([$eventId,$org]);
        if($existingEventRevision=$ers->fetch()){
            if((int)$existingEventRevision['game_id']===$game && (int)$existingEventRevision['game_revision_id']>0){
                $eventRevisionId=(int)$existingEventRevision['game_revision_id'];
            }
        }
    }

    if($eventId) {
        // Avoid a UNIQUE(org,game,name) collision when linking an event whose
        // manual name differs from TBA's official name.
        $s=$pdo->prepare(
            'SELECT id FROM events WHERE organization_id=? AND game_id=? AND name=? AND id<>? LIMIT 1'
        );
        $s->execute([$org,$game,$name,$eventId]);
        $nameConflict=(int)$s->fetchColumn();

        if($nameConflict) {
            throw new RuntimeException(
                'Another Neptune event (#'.$nameConflict.') already uses the official TBA name "'.$name.'". Merge or rename that duplicate first.'
            );
        }

        $pdo->prepare(
            "UPDATE events
             SET game_id=?,
                 game_revision_id=?,
                 name=?,
                 event_code=?,
                 tba_event_key=?,
                 start_date=?,
                 end_date=?,
                 active=1,
                 last_tba_sync_at=UTC_TIMESTAMP()
             WHERE id=? AND organization_id=?"
        )->execute([
            $game,
            $eventRevisionId,
            $name,
            $ev['event_code']??null,
            $key,
            $ev['start_date']??null,
            $ev['end_date']??null,
            $eventId,
            $org
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO events
             (organization_id,game_id,game_revision_id,name,event_code,tba_event_key,start_date,end_date,active,event_status,last_tba_sync_at)
             VALUES(?,?,?,?,?,?,?,?,1,'planned',UTC_TIMESTAMP())"
        )->execute([
            $org,
            $game,
            $gameRevisionId,
            $name,
            $ev['event_code']??null,
            $key,
            $ev['start_date']??null,
            $ev['end_date']??null
        ]);
        $eventId=(int)$pdo->lastInsertId();
    }

    $eventTeams=tba_optional("event/{$key}/teams/simple",120);
    $teamCount=0;

    foreach($eventTeams as $t) {
        $tn=(int)($t['team_number']??0);
        if(!$tn) continue;

        $pdo->prepare(
            'INSERT INTO event_teams
             (event_id,frc_team_number,nickname,city,state_prov,country,tba_team_key)
             VALUES(?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               nickname=VALUES(nickname),
               city=VALUES(city),
               state_prov=VALUES(state_prov),
               country=VALUES(country),
               tba_team_key=VALUES(tba_team_key)'
        )->execute([
            $eventId,
            $tn,
            $t['nickname']??null,
            $t['city']??null,
            $t['state_prov']??null,
            $t['country']??null,
            $t['key']??null
        ]);
        $teamCount++;
    }

    $matches=tba_optional("event/{$key}/matches/simple",30);
    $matchCount=0;

    foreach($matches as $m) {
        $level=$m['comp_level']??'qm';
        $set=max(1,(int)($m['set_number']??1));
        $num=max(1,(int)($m['match_number']??1));

        $sched=!empty($m['predicted_time'])
            ? gmdate('Y-m-d H:i:s',(int)$m['predicted_time'])
            : (!empty($m['time']) ? gmdate('Y-m-d H:i:s',(int)$m['time']) : null);

        $rs=$m['alliances']['red']['score']??null;
        $bs=$m['alliances']['blue']['score']??null;
        $rs=is_numeric($rs)&&(int)$rs>=0?(int)$rs:null;
        $bs=is_numeric($bs)&&(int)$bs>=0?(int)$bs:null;

        $winner='Unknown';
        if($rs!==null&&$bs!==null) {
            $winner=$rs===$bs?'Tie':($rs>$bs?'Red':'Blue');
        }

        $importState=($rs!==null&&$bs!==null)?'ended':'scheduled';

        $q="INSERT INTO matches
              (organization_id,event_id,game_id,tba_match_key,comp_level,set_number,match_number,field_id,scheduled_time,red_score,blue_score,winning_alliance,state)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              tba_match_key=VALUES(tba_match_key),
              scheduled_time=VALUES(scheduled_time),
              red_score=VALUES(red_score),
              blue_score=VALUES(blue_score),
              winning_alliance=VALUES(winning_alliance),
              state=IF(VALUES(red_score) IS NOT NULL AND state='scheduled','ended',state)";

        $pdo->prepare($q)->execute([
            $org,
            $eventId,
            $game,
            $m['key']??null,
            $level,
            $set,
            $num,
            1,
            $sched,
            $rs,
            $bs,
            $winner,
            $importState
        ]);

        $ms=$pdo->prepare(
            'SELECT id FROM matches
             WHERE organization_id=? AND event_id=? AND comp_level=? AND set_number=? AND match_number=? AND field_id=1'
        );
        $ms->execute([$org,$eventId,$level,$set,$num]);
        $matchId=(int)$ms->fetchColumn();

        foreach(['red'=>'Red','blue'=>'Blue'] as $ak=>$av) {
            foreach(($m['alliances'][$ak]['team_keys']??[]) as $i=>$tk) {
                $tn=(int)preg_replace('/\D/','',$tk);
                if(!$tn) continue;

                $pdo->prepare(
                    'INSERT INTO match_teams(match_id,frc_team_number,alliance,station)
                     VALUES(?,?,?,?)
                     ON DUPLICATE KEY UPDATE frc_team_number=VALUES(frc_team_number)'
                )->execute([$matchId,$tn,$av,$i+1]);

                $pdo->prepare(
                    'INSERT INTO event_teams(event_id,frc_team_number,tba_team_key)
                     VALUES(?,?,?)
                     ON DUPLICATE KEY UPDATE tba_team_key=COALESCE(tba_team_key,VALUES(tba_team_key))'
                )->execute([$eventId,$tn,'frc'.$tn]);
            }
        }

        $matchCount++;
    }

    $s=$pdo->prepare('SELECT COUNT(*) FROM event_teams WHERE event_id=?');
    $s->execute([$eventId]);
    $teamCount=(int)$s->fetchColumn();

    if($teamCount>0) {
        $pdo->prepare('UPDATE events SET roster_synced_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);
    }

    if($matchCount>0) {
        $pdo->prepare(
            "UPDATE events
             SET schedule_synced_at=UTC_TIMESTAMP(),
                 event_status=IF(event_status IN ('planned','pit_open'),'schedule_ready',event_status)
             WHERE id=?"
        )->execute([$eventId]);
    } elseif($teamCount>0) {
        $pdo->prepare(
            "UPDATE events
             SET event_status=IF(event_status='planned','pit_open',event_status)
             WHERE id=?"
        )->execute([$eventId]);
    }

    $pdo->prepare('UPDATE events SET last_tba_sync_at=UTC_TIMESTAMP() WHERE id=?')->execute([$eventId]);

    $s=$pdo->prepare('SELECT COUNT(*) FROM events WHERE organization_id=? AND is_current=1');
    $s->execute([$org]);
    if((int)$s->fetchColumn()===0) {
        $pdo->prepare('UPDATE events SET is_current=1 WHERE id=? AND organization_id=?')->execute([$eventId,$org]);
    }

    // Keep Neptune's public EPA ratings current whenever TBA Sync refreshes
    // an event. If the ratings migration is not installed yet, or no completed
    // qualification matches exist, normal TBA Sync still succeeds.
    $augurRatings=null;
    if(function_exists('augur_epa_tables_ready') && augur_epa_tables_ready($pdo)){
        try{$augurRatings=augur_epa_ensure_neptune_event($pdo,$org,$eventId,false);}catch(Throwable $ignored){}
    }

    return [
        'event_id'=>$eventId,
        'name'=>$name,
        'previous_name'=>$previousName,
        'linked_existing'=>$linkedExisting,
        'teams'=>$teamCount,
        'matches'=>$matchCount,
        'schedule_available'=>$matchCount>0,
        'augur_epa'=>$augurRatings
    ];
}

if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $op=$_POST['op']??'find';

    try {
        $gs=$pdo->prepare('SELECT current_revision_id FROM games WHERE id=? AND organization_id=? AND is_archived=0');
        $gs->execute([$game,$org]);
        if((int)$gs->fetchColumn()<=0) {
            throw new RuntimeException('TBA Sync requires an organization-owned game with a published revision. Clone shared games first, then publish your copy.');
        }

        if($op==='find') {
            $found=tba_get("team/frc{$team}/events/{$year}/simple",120);
        } elseif($op==='import_event') {
            $key=trim($_POST['event_key']??'');
            if($key==='') throw new RuntimeException('Missing TBA event key.');

            $r=import_tba_event($pdo,$org,$game,$key,null);

            $msg=$r['schedule_available']
                ? "{$r['name']} synced: {$r['teams']} roster teams and {$r['matches']} matches."
                : "{$r['name']} is ready for pit scouting: {$r['teams']} roster teams imported. The match schedule is not published yet.";

            $found=$team?tba_get("team/frc{$team}/events/{$year}/simple",120):[];
        } elseif($op==='sync_manual_event') {
            $manualEventId=(int)($_POST['manual_event_id']??0);

            if($manualEventId<=0) {
                throw new RuntimeException('Missing Neptune event.');
            }

            $s=$pdo->prepare(
                "SELECT e.*,g.season_year
                 FROM events e
                 JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
                 WHERE e.id=?
                   AND e.organization_id=?
                   AND e.game_id=?
                 LIMIT 1"
            );
            $s->execute([$manualEventId,$org,$game]);
            $manual=$s->fetch();

            if(!$manual) {
                throw new RuntimeException('That Neptune event was not found.');
            }

            if(trim((string)($manual['tba_event_key']??''))!=='') {
                throw new RuntimeException('That Neptune event is already linked to TBA.');
            }

            $season=(int)($manual['season_year']??$year);
            if($season<=0) $season=$year;

            $seasonEvents=tba_get("events/{$season}/simple",300);
            $match=neptune_find_tba_match_for_manual($manual,$seasonEvents);

            if(($match['status']??'none')==='none') {
                $bestName=(string)($match['best']['event']['name']??'');
                $extra=$bestName!=='' ? ' Closest TBA result was "'.$bestName.'", but it was not strong enough to auto-link safely.' : '';
                $msg='No confident TBA event was found yet for "'.(string)$manual['name'].'". Nothing was changed.'.$extra;
            } elseif(($match['status']??'')==='ambiguous') {
                $a=(string)($match['best']['event']['name']??'');
                $b=(string)($match['second']['event']['name']??'');
                $msg='TBA has more than one possible match for "'.(string)$manual['name'].'": "'.$a.'" and "'.$b.'". Nothing was linked automatically; use Find Events to choose the correct one.';
            } else {
                $tbaEvent=$match['best']['event'];
                $key=(string)$tbaEvent['key'];

                $pdo->beginTransaction();
                try {
                    $r=import_tba_event($pdo,$org,$game,$key,$manualEventId);

                    $meta=json_encode([
                        'tba_event_key'=>$key,
                        'previous_name'=>$r['previous_name'],
                        'official_name'=>$r['name'],
                        'teams'=>$r['teams'],
                        'matches'=>$r['matches'],
                        'automatic_match_score'=>(int)($match['best']['score']??0),
                        'source'=>'manual_card_sync'
                    ],JSON_UNESCAPED_SLASHES);

                    $a=$pdo->prepare(
                        "INSERT INTO audit_log
                         (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address)
                         VALUES(?,?,'auto_link_event_to_tba','event',?,?,?)"
                    );
                    $a->execute([
                        $org,
                        (int)($u['id']??0) ?: null,
                        (string)$r['event_id'],
                        $meta,
                        $_SERVER['REMOTE_ADDR']??null
                    ]);

                    $pdo->commit();
                } catch(Throwable $e) {
                    if($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $msg='Found "'.$r['name'].'" on TBA and linked it to Neptune event #'.$r['event_id'].'. '.
                     $r['teams'].' roster teams'.
                     ($r['schedule_available']
                        ? ' and '.$r['matches'].' matches were synced.'
                        : ' were synced. TBA has not published a match schedule yet.');
            }

            $found=$team?tba_get("team/frc{$team}/events/{$year}/simple",120):[];
        } elseif($op==='link_event') {
            $key=trim($_POST['event_key']??'');
            $existingEventId=(int)($_POST['existing_event_id']??0);

            if($key==='') throw new RuntimeException('Missing TBA event key.');
            if($existingEventId<=0) throw new RuntimeException('Choose the existing Neptune event to link.');

            $pdo->beginTransaction();
            try {
                $r=import_tba_event($pdo,$org,$game,$key,$existingEventId);

                // Log the administrative link without depending on a helper.
                $meta=json_encode([
                    'tba_event_key'=>$key,
                    'previous_name'=>$r['previous_name'],
                    'official_name'=>$r['name'],
                    'teams'=>$r['teams'],
                    'matches'=>$r['matches']
                ],JSON_UNESCAPED_SLASHES);

                $a=$pdo->prepare(
                    "INSERT INTO audit_log
                     (organization_id,user_id,action,entity_type,entity_id,metadata_json,ip_address)
                     VALUES(?,?,'link_event_to_tba','event',?,?,?)"
                );
                $a->execute([
                    $org,
                    (int)($u['id']??0) ?: null,
                    (string)$r['event_id'],
                    $meta,
                    $_SERVER['REMOTE_ADDR']??null
                ]);

                $pdo->commit();
            } catch(Throwable $e) {
                if($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $msg="Linked existing Neptune event #{$r['event_id']} ".
                 ($r['previous_name']!=='' ? '"'.$r['previous_name'].'" ' : '').
                 "to TBA event {$key} as \"{$r['name']}\". The Neptune event ID was preserved. ".
                 "{$r['teams']} roster teams".
                 ($r['schedule_available'] ? " and {$r['matches']} matches were synced." : " were synced; the TBA match schedule is not published yet.");

            $found=$team?tba_get("team/frc{$team}/events/{$year}/simple",120):[];
        }
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$s=$pdo->prepare(
    "SELECT e.*,g.name game_name,
       (SELECT COUNT(*) FROM event_teams et WHERE et.event_id=e.id) team_count,
       (SELECT COUNT(*) FROM matches m WHERE m.event_id=e.id) match_count,
       (SELECT COUNT(*) FROM pre_scouting prs WHERE prs.event_id=e.id AND prs.organization_id=e.organization_id AND prs.status='complete') pre_complete,
       (SELECT COUNT(*) FROM pit_scouting ps WHERE ps.event_id=e.id AND ps.organization_id=e.organization_id AND ps.status='complete') pit_complete
     FROM events e
     JOIN games g ON g.id=e.game_id AND g.organization_id=e.organization_id
     WHERE e.organization_id=? AND e.tba_event_key IS NOT NULL AND e.tba_event_key<>''
     ORDER BY e.is_current DESC,COALESCE(e.start_date,'1900-01-01') DESC"
);
$s->execute([$org]);
$imported=$s->fetchAll();

$byKey=[];
foreach($imported as $x) $byKey[$x['tba_event_key']]=$x;

$manualEvents=neptune_manual_events($pdo,$org,$game);

$pageTitle='TBA Sync';
$moduleName='SATURN';
include dirname(__DIR__).'/partials_header.php';
?>

<style>
.tba-link-box{margin-top:10px;padding:10px;border:1px solid var(--line);border-radius:6px;background:var(--panel2)}
.tba-link-title{display:flex;align-items:center;gap:7px;font-size:.8rem;font-weight:900;margin-bottom:7px}
.tba-link-form{display:grid;grid-template-columns:minmax(240px,1fr) auto;gap:8px;align-items:end}
.tba-link-form select{min-width:0}
.tba-link-note{font-size:.74rem;color:var(--muted);margin-top:6px;line-height:1.35}
.manual-event-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;margin-top:10px}
.manual-event-card{padding:10px;border:1px solid var(--line);border-radius:6px;background:var(--panel2)}
.manual-event-card b{display:block;margin-bottom:3px}
.manual-meta{font-size:.76rem;color:var(--muted);line-height:1.4}
@media(max-width:720px){.tba-link-form{grid-template-columns:1fr}}
</style>

<div class="toolbar" style="justify-content:space-between">
    <div>
        <div class="module-eyebrow"><span>SATURN</span><small>Event Data</small></div>
        <h1 style="margin-bottom:4px">The Blue Alliance Sync</h1>
        <div class="muted">Import TBA events, or link an official TBA event to a manually created Neptune event without changing its event ID.</div>
    </div>
    <a class="btn secondary" href="events.php"><i class="fa-solid fa-calendar-days"></i> Event Setup</a>
</div>

<?php if($msg):?><div class="notice good"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="notice bad"><?=e($error)?></div><?php endif;?>
<?php if(!$games):?><div class="notice bad"><b>No organization-owned game is available.</b> Clone a shared configuration or create a game before importing TBA events.</div><?php endif;?>

<div class="card">
    <form method="post" class="analytics-toolbar">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="op" value="find">

        <div>
            <label>Our FRC team</label>
            <select name="team_number">
                <?php foreach($teams as $t):?>
                    <option value="<?=$t['frc_team_number']?>" <?=$team===(int)$t['frc_team_number']?'selected':''?>>
                        #<?=e($t['frc_team_number'].' '.($t['display_name']?:$t['nickname']))?>
                    </option>
                <?php endforeach;?>
            </select>
        </div>

        <div>
            <label>Season</label>
            <input type="number" name="year" value="<?=$year?>">
        </div>

        <div style="min-width:300px">
            <label>Neptune game</label>
            <select name="game_id">
                <?php foreach($games as $g):?>
                    <option value="<?=$g['id']?>" <?=$game===(int)$g['id']?'selected':''?>>
                        <?=e($g['season_year'].' '.$g['name'].' · Rev '.$g['revision_number'])?>
                    </option>
                <?php endforeach;?>
            </select>
        </div>

        <button><i class="fa-solid fa-magnifying-glass"></i> Find Events</button>
    </form>
</div>

<?php if($manualEvents):?>
<div class="card" style="margin-top:16px">
    <h2>Manual Events Waiting for a TBA Link</h2>
    <div class="muted">These stay usable for pre-scouting and pit scouting now. Click <b>Sync with TBA</b> on a card at any time. Neptune will search the full season event list and link the existing event automatically when it finds one confident match.</div>

    <div class="manual-event-grid">
        <?php foreach($manualEvents as $ev):?>
            <div class="manual-event-card">
                <b><?=e($ev['name'])?><?php if($ev['is_current']):?> <span class="pill">Current</span><?php endif;?></b>
                <div class="manual-meta">
                    Neptune event #<?=e($ev['id'])?><br>
                    <?=e(($ev['start_date']?:'No start date').' – '.($ev['end_date']?:'No end date'))?><br>
                    <?=e($ev['team_count'])?> teams · <?=e($ev['match_count'])?> matches · <?=e($ev['pre_count'])?> pre records · <?=e($ev['pit_count'])?> pit records
                </div>

                <form method="post" style="margin-top:10px">
                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                    <input type="hidden" name="op" value="sync_manual_event">
                    <input type="hidden" name="manual_event_id" value="<?=e($ev['id'])?>">
                    <input type="hidden" name="team_number" value="<?=$team?>">
                    <input type="hidden" name="year" value="<?=$year?>">
                    <input type="hidden" name="game_id" value="<?=$game?>">
                    <button type="submit" class="secondary">
                        <i class="fa-solid fa-rotate"></i> Sync with TBA
                    </button>
                </form>
            </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<?php if($found):?>
<div class="card" style="margin-top:16px">
    <h2><?=$year?> Events for #<?=e($team)?></h2>

    <div class="match-list">
        <?php foreach($found as $f):
            $key=$f['key']??'';
            $known=$byKey[$key]??null;
            $candidates=$known?[]:neptune_rank_manual_events($manualEvents,$f);
            $suggestedId=0;
            $suggestedScore=0;
            if($candidates){
                $suggestedScore=(int)($candidates[0]['_match_score']??0);
                if($suggestedScore>=30) $suggestedId=(int)$candidates[0]['id'];
            }
        ?>
        <div class="match-row">
            <div class="match-heading" style="width:100%">
                <div style="width:100%">
                    <b><?=e($f['name']??$key)?></b>
                    <div class="muted"><?=e(($f['start_date']??'').' – '.($f['end_date']??''))?> · <?=e($key)?></div>

                    <?php if($known):?>
                        <div class="match-summary">
                            <span><b><?=e($known['team_count'])?></b> teams</span>
                            <span><b><?=e($known['match_count'])?></b> matches</span>
                            <span><b><?=e($known['pre_complete'])?></b> pre-scout</span>
                            <span><b><?=e($known['pit_complete'])?></b> pit complete</span>
                        </div>

                        <form method="post" style="margin-top:10px">
                            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                            <input type="hidden" name="op" value="import_event">
                            <input type="hidden" name="event_key" value="<?=e($key)?>">
                            <input type="hidden" name="team_number" value="<?=$team?>">
                            <input type="hidden" name="year" value="<?=$year?>">
                            <input type="hidden" name="game_id" value="<?=$game?>">
                            <button><i class="fa-solid fa-rotate"></i> Refresh Roster / Schedule</button>
                        </form>
                    <?php else:?>
                        <div class="toolbar" style="margin:10px 0 0">
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                                <input type="hidden" name="op" value="import_event">
                                <input type="hidden" name="event_key" value="<?=e($key)?>">
                                <input type="hidden" name="team_number" value="<?=$team?>">
                                <input type="hidden" name="year" value="<?=$year?>">
                                <input type="hidden" name="game_id" value="<?=$game?>">
                                <button class="secondary"><i class="fa-solid fa-plus"></i> Import as New Event</button>
                            </form>
                        </div>

                        <?php if($candidates):?>
                            <div class="tba-link-box">
                                <div class="tba-link-title"><i class="fa-solid fa-link"></i> Link to an existing manual Neptune event</div>

                                <form method="post" class="tba-link-form">
                                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                                    <input type="hidden" name="op" value="link_event">
                                    <input type="hidden" name="event_key" value="<?=e($key)?>">
                                    <input type="hidden" name="team_number" value="<?=$team?>">
                                    <input type="hidden" name="year" value="<?=$year?>">
                                    <input type="hidden" name="game_id" value="<?=$game?>">

                                    <div>
                                        <label>Existing Neptune event</label>
                                        <select name="existing_event_id" required>
                                            <option value="">— Choose manual event —</option>
                                            <?php foreach($candidates as $candidate):
                                                $score=(int)($candidate['_match_score']??0);
                                                $isSuggested=((int)$candidate['id']===$suggestedId);
                                            ?>
                                                <option value="<?=e($candidate['id'])?>" <?=$isSuggested?'selected':''?>>
                                                    <?=e($candidate['name'])?> · #<?=e($candidate['id'])?> · <?=e($candidate['start_date']?:'no date')?><?=$isSuggested?' · SUGGESTED':''?>
                                                </option>
                                            <?php endforeach;?>
                                        </select>
                                    </div>

                                    <button><i class="fa-solid fa-link"></i> Link &amp; Sync</button>
                                </form>

                                <div class="tba-link-note">
                                    Keeps the selected Neptune event ID and all existing pre-scouting/pit data, then adds the official TBA key, official name/dates, roster, and schedule.
                                </div>
                            </div>
                        <?php endif;?>
                    <?php endif;?>
                </div>
            </div>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<div class="card" style="margin-top:16px">
    <h2>Imported TBA Events</h2>

    <div class="table-wrap">
        <table class="table">
            <tr>
                <th>Event</th>
                <th>TBA</th>
                <th>Stage</th>
                <th>Roster</th>
                <th>Schedule</th>
                <th>Pre</th>
                <th>Pit</th>
                <th>Last check</th>
                <th></th>
            </tr>

            <?php foreach($imported as $ev):?>
            <tr>
                <td>
                    <b><?=e($ev['name'])?></b>
                    <div class="muted">Neptune #<?=e($ev['id'])?></div>
                    <?php if($ev['is_current']):?>
                        <div><span class="pill"><i class="fa-solid fa-location-dot"></i> Current</span></div>
                    <?php endif;?>
                </td>

                <td><span class="pill"><i class="fa-solid fa-link"></i> <?=e($ev['tba_event_key'])?></span></td>
                <td><?=e(str_replace('_',' ',strtoupper($ev['event_status'])))?></td>
                <td><?=e($ev['team_count'])?> teams</td>

                <td>
                    <?php if((int)$ev['match_count']>0):?>
                        <span class="success"><i class="fa-solid fa-circle-check"></i> <?=e($ev['match_count'])?> matches</span>
                    <?php else:?>
                        <span class="muted"><i class="fa-solid fa-clock"></i> Not published</span>
                    <?php endif;?>
                </td>

                <td><?=e($ev['pre_complete'])?> / <?=e($ev['team_count'])?></td>
                <td><?=e($ev['pit_complete'])?> / <?=e($ev['team_count'])?></td>
                <td><?=e($ev['last_tba_sync_at']?:'—')?> UTC</td>

                <td>
                    <div class="toolbar" style="margin:0">
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                            <input type="hidden" name="op" value="import_event">
                            <input type="hidden" name="event_key" value="<?=e($ev['tba_event_key'])?>">
                            <input type="hidden" name="team_number" value="<?=$team?>">
                            <input type="hidden" name="year" value="<?=$year?>">
                            <input type="hidden" name="game_id" value="<?=$ev['game_id']?>">
                            <button class="secondary"><i class="fa-solid fa-rotate"></i> <?=$ev['match_count']?'Refresh':'Check Schedule'?></button>
                        </form>

                        <a class="btn secondary" href="<?=e(base_url('prescout/index.php?event_id='.$ev['id']))?>">
                            <i class="fa-solid fa-envelope-open-text"></i> Pre
                        </a>

                        <a class="btn secondary" href="<?=e(base_url('pit/index.php?event_id='.$ev['id']))?>">
                            <i class="fa-solid fa-clipboard-list"></i> Pit
                        </a>

                        <?php if($ev['match_count']):?>
                            <a class="btn secondary" href="match-control.php?event_id=<?=$ev['id']?>">
                                <i class="fa-solid fa-tower-broadcast"></i> Matches
                            </a>
                        <?php endif;?>
                    </div>
                </td>
            </tr>
            <?php endforeach;?>
        </table>
    </div>
</div>

<?php include dirname(__DIR__).'/partials_footer.php';
