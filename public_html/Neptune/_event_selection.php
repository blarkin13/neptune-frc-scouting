<?php
/**
 * Neptune shared game/event selector helpers.
 *
 * Goal: every local Neptune selector speaks the same language:
 *   2026
 *     FIT District Amarillo Event
 *
 * Site-wide event selectors default to every active organization event in the
 * rolling 18-month window, plus the current/explicitly selected event when needed.
 * Events are grouped by season year and sorted newest-first. ?history=1 reveals
 * the complete organization history using the same year grouping.
 */

if(!defined('NEPTUNE_EVENT_RECENT_MONTHS')) define('NEPTUNE_EVENT_RECENT_MONTHS',18);

function neptune_selector_show_history(): bool {
    return isset($_GET['history']) && (string)$_GET['history']==='1';
}

function neptune_selector_year_game_label(array $row): string {
    $year=(int)($row['season_year']??0);
    $game=trim((string)($row['game_name']??$row['name']??''));
    return trim(($year>0?$year.' · ':'').$game);
}

function neptune_event_selector_rows(PDO $pdo,int $org,int $selectedEventId=0,?bool $showHistory=null,bool $requireMatches=false): array {
    $showHistory=$showHistory??neptune_selector_show_history();
    $params=[$org];
    $where="e.organization_id=? AND e.active=1";
    // Match-driven screens can opt in without changing the site-wide 18-month rule.
    // event_id is the tenant-safe relationship: once the event belongs to this org,
    // legacy/imported match rows remain usable even if their redundant organization_id
    // value predates a migration or organization reassignment.
    if($requireMatches){
        $where.=" AND EXISTS(SELECT 1 FROM matches mx JOIN match_teams mxt ON mxt.match_id=mx.id WHERE mx.event_id=e.id)";
    }
    if(!$showHistory){
        // One site-wide rule: all active events in the rolling 18-month window.
        // Future events naturally qualify because their date is newer than the cutoff.
        // Keep the current event and an explicitly selected event visible even when an
        // old/manual record has incomplete dates, so navigation never strands the user.
        $where.=" AND (e.is_current=1 OR COALESCE(e.end_date,e.start_date,DATE(e.created_at))>=DATE_SUB(CURDATE(),INTERVAL ".NEPTUNE_EVENT_RECENT_MONTHS." MONTH)";
        if($selectedEventId>0){$where.=" OR e.id=?";$params[]=$selectedEventId;}
        $where.=')';
    }
    $sql="SELECT e.*,g.name game_name,g.season_year,g.is_archived game_is_archived,
                 (SELECT COUNT(*) FROM matches mc WHERE mc.event_id=e.id) match_count
          FROM events e
          JOIN games g ON g.id=e.game_id
          WHERE {$where}
          ORDER BY g.season_year DESC,COALESCE(e.start_date,e.end_date,DATE(e.created_at)) DESC,e.name ASC,e.id DESC";
    $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
}

function neptune_game_selector_rows(PDO $pdo,int $org,bool $includeShared=false,?bool $showHistory=null): array {
    $showHistory=$showHistory??neptune_selector_show_history();
    if($includeShared){
        $sql="SELECT g.*,o.name owner_org_name,CASE WHEN g.organization_id=? THEN 1 ELSE 0 END is_owned,
                    cr.revision_number current_revision_number,dr.revision_number draft_revision_number
              FROM games g
              JOIN organizations o ON o.id=g.organization_id
              LEFT JOIN game_revisions cr ON cr.id=g.current_revision_id
              LEFT JOIN game_revisions dr ON dr.id=g.draft_revision_id
              WHERE (g.organization_id=? OR EXISTS(SELECT 1 FROM game_config_shares gcs WHERE gcs.game_id=g.id AND gcs.recipient_organization_id=?))".
              ($showHistory?'':" AND g.is_archived=0")."
              ORDER BY g.season_year DESC,g.name,g.id DESC";
        $s=$pdo->prepare($sql);$s->execute([$org,$org,$org]);return $s->fetchAll();
    }
    $sql="SELECT g.*,o.name owner_org_name,1 is_owned,cr.revision_number current_revision_number,dr.revision_number draft_revision_number
          FROM games g
          JOIN organizations o ON o.id=g.organization_id
          LEFT JOIN game_revisions cr ON cr.id=g.current_revision_id
          LEFT JOIN game_revisions dr ON dr.id=g.draft_revision_id
          WHERE g.organization_id=?".($showHistory?'':" AND g.is_archived=0")."
          ORDER BY g.season_year DESC,g.name,g.id DESC";
    $s=$pdo->prepare($sql);$s->execute([$org]);return $s->fetchAll();
}

function neptune_event_options_html(array $events,int $selected=0): string {
    $out='';$group=null;
    foreach($events as $ev){
        $year=(int)($ev['season_year']??0);
        if($year<=0){
            $date=(string)($ev['start_date']??$ev['end_date']??'');
            $year=$date!==''?(int)substr($date,0,4):0;
        }
        $g=$year>0?(string)$year:'Other';
        if($g!==$group){
            if($group!==null)$out.='</optgroup>';
            $out.='<optgroup label="'.htmlspecialchars($g,ENT_QUOTES,'UTF-8').'">';
            $group=$g;
        }
        $label=((int)($ev['is_current']??0)===1?'★ ':'').trim((string)($ev['name']??'Event'));
        if((int)($ev['game_is_archived']??0)===1)$label.=' · archived game';
        $out.='<option value="'.(int)$ev['id'].'"'.($selected===(int)$ev['id']?' selected':'').'>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</option>';
    }
    if($group!==null)$out.='</optgroup>';
    return $out;
}

function neptune_game_options_html(array $games,int $selected=0,bool $showRevision=false): string {
    $out='';$group=null;
    foreach($games as $g){
        $year=(int)($g['season_year']??0);
        $groupLabel=$year>0?(string)$year:'Other';
        if($groupLabel!==$group){if($group!==null)$out.='</optgroup>';$out.='<optgroup label="'.htmlspecialchars($groupLabel,ENT_QUOTES,'UTF-8').'">';$group=$groupLabel;}
        $label=trim((string)($g['name']??'Game'));
        if(isset($g['is_owned']) && !(int)$g['is_owned'])$label.=' · shared by '.trim((string)($g['owner_org_name']??'another org'));
        if($showRevision){
            $draft=$g['draft_revision_number']??null;$pub=$g['current_revision_number']??($g['revision_number']??null);
            $label.=$draft?' · Draft r'.$draft:($pub?' · Published r'.$pub:'');
        }
        if((int)($g['is_archived']??0)===1)$label.=' · archived';
        $out.='<option value="'.(int)$g['id'].'"'.($selected===(int)$g['id']?' selected':'').'>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</option>';
    }
    if($group!==null)$out.='</optgroup>';
    return $out;
}

function neptune_history_toggle_url(bool $showHistory,array $extra=[]): string {
    $q=$_GET;
    foreach($extra as $k=>$v){if($v===null)unset($q[$k]);else $q[$k]=$v;}
    if($showHistory) unset($q['history']); else $q['history']='1';
    $qs=http_build_query($q);
    return $qs!==''?'?'.$qs:'?';
}

function neptune_history_toggle_html(bool $showHistory,string $noun='events'): string {
    $label=$showHistory?'Recent '.$noun:'Show full history';
    $icon=$showHistory?'fa-clock':'fa-box-archive';
    return '<a class="btn secondary" href="'.htmlspecialchars(neptune_history_toggle_url($showHistory),ENT_QUOTES,'UTF-8').'"><i class="fa-solid '.$icon.'"></i> '.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
}
