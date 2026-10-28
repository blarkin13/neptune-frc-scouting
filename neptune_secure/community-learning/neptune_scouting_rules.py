"""Game-independent summaries of observed robot evidence; never score attribution."""
import math
import statistics

VERSION = 'shared-scouting-rules-v1'
DEFAULTS = dict(stationary_seconds=5.0, stationary_radius_widths=.20,
                max_tracking_gap_seconds=1.1, recovery_seconds=1.0,
                recovery_distance_widths=.5, scoring_visit_gap_seconds=3.0)
TRUSTED = {'direct_vote_track', 'yolo_botsort_ocr', 'human_reviewed_track', 'roster_inferred', 'constant_identity'}


def policy(overrides=None):
    result = dict(DEFAULTS)
    for key, value in (overrides or {}).items():
        if key not in result or type(value) not in (int, float) or not math.isfinite(value) or value <= 0:
            raise ValueError('Invalid scouting rule setting: ' + str(key))
        result[key] = float(value)
    return result


def scoring(tag):
    # Climb outcomes and attempted/missed scores are not scoring visits.
    return isinstance(tag, str) and (tag == 'scoring' or tag.startswith('gb:score_')) and not any(
        word in tag.lower() for word in ('attempt', 'miss', 'fail'))


def verified(p):
    b = p.get('box', [])
    return (p.get('identity_method') in TRUSTED and not p.get('occluded') and
            p.get('box_source') != 'optical_flow' and len(b) == 4 and
            all(type(v) in (int, float) and math.isfinite(v) for v in b) and min(b[2:]) > 0)


def stream_key(p):
    return (p['team'], p.get('camera', 'wide'), str(p.get('scene')),
            p.get('identity_segment', p.get('track_id')))


def union(intervals):
    out = []
    for a, b in sorted(intervals):
        if b <= a:
            continue
        if out and a <= out[-1][1]:
            out[-1][1] = max(out[-1][1], b)
        else:
            out.append([a, b])
    return out


def summarize(facts, tags, model_id, overrides=None):
    from neptune_events import stationary_disabled
    from neptune_clock import phase_at
    cfg = policy(overrides)
    # Existing detector checks continuity, scale changes, and a progressing live clock.
    stationary = stationary_disabled(facts, model_id,
        threshold=cfg['stationary_seconds'], radius_widths=cfg['stationary_radius_widths'],
        max_gap=cfg['max_tracking_gap_seconds'])
    points = [p for p in facts.get('tracking', {}).get('localizations', []) if verified(p)]
    events = []
    for old in stationary:
        event = dict(old, tag_key='stationary', rule=VERSION,
                     assessment='observed_stationary_not_disabled', evidence_type='rule_observation')
        events.append(event)
        a, b, team = event['start_ms']/1000, event['end_ms']/1000, event['team']
        # Lack of an action prediction is NOT evidence of lack of purposeful activity.
        absent = any(r.get('team') == team and r.get('assessment') == 'no_purposeful_activity'
                     and r.get('start_ms', math.inf) <= a*1000 and r.get('end_ms', -1) >= b*1000
                     and r.get('evidence_type') in ('human_review', 'verified_activity_assessment')
                     for r in facts.get('activity_assessments', []))
        active = any(t.get('team') == team and t.get('tag_key') not in
                     ('disabled', 'stationary', 'mechanical_issues', 'recovered') and
                     t.get('start_ms', math.inf) <= b*1000 and t.get('end_ms', t.get('start_ms', -1)) >= a*1000
                     for t in tags)
        if not absent or active:
            continue
        disabled = dict(event, tag_key='disabled', assessment='suspected_disabled',
                        evidence_type='rule_inference')
        events.append(disabled)
        anchors = [p for p in points if p['team'] == team and p.get('camera', 'wide') == event['camera']
                   and abs(p['t']-b) < .01]
        if len(anchors) != 1:
            continue
        anchor = anchors[0]; box = anchor['box']; moving = []
        for p in sorted((p for p in points if stream_key(p) == stream_key(anchor) and p['t'] > b), key=lambda p:p['t']):
            previous = moving[-1] if moving else anchor
            if p['t']-previous['t'] > cfg['max_tracking_gap_seconds'] or phase_at(facts,p['t'])[0] != 'teleop':
                break
            q=p['box']; distance=math.hypot(q[0]+q[2]/2-box[0]-box[2]/2,q[1]+q[3]/2-box[1]-box[3]/2)
            if distance < cfg['recovery_distance_widths']*box[2]:
                if moving: break
                anchor=p
                continue
            moving.append(p)
            if p['t']-moving[0]['t'] >= cfg['recovery_seconds']:
                # Require observed clock progression through recovery as well.
                phase=phase_at(facts,p['t'])[1]
                clocks=sorted((c['t'],c['remaining']) for c in phase.get('clock_samples',[])
                              if moving[0]['t'] <= c.get('t',-1) <= p['t'] and type(c.get('remaining')) in (int,float))
                if len(clocks)<2 or clocks[0][1]-clocks[-1][1]<=0 or abs((clocks[-1][0]-clocks[0][0])-(clocks[0][1]-clocks[-1][1]))>1.5:
                    continue
                events.append(dict(event, tag_key='recovered', start_ms=round(moving[0]['t']*1000),
                    end_ms=round(p['t']*1000), assessment='sustained_movement_after_suspected_disabled', evidence_type='rule_inference'))
                events.append(dict(disabled, tag_key='mechanical_issues',
                    assessment='temporary_interruption_suspected_mechanical_issue', diagnosis_confirmed=False))
                break
    summaries = []
    teams = {p['team'] for p in points} | {t['team'] for t in tags}
    for team in sorted(teams):
        streams = {}
        for p in points:
            if p['team'] == team and phase_at(facts,p['t'])[0] == 'teleop':
                streams.setdefault(stream_key(p), []).append(p)
        intervals=[]
        for rows in streams.values():
            rows=sorted(rows,key=lambda p:p['t'])
            intervals += [(a['t'],b['t']) for a,b in zip(rows,rows[1:])
                          if 0 < b['t']-a['t'] <= cfg['max_tracking_gap_seconds']]
        covered=union(intervals); duration=sum(b-a for a,b in covered)
        episodes=[]
        for t in sorted((t for t in tags if t['team']==team and scoring(t.get('tag_key'))),key=lambda t:t['start_ms']):
            a=t['start_ms']/1000;b=t.get('end_ms',t['start_ms'])/1000
            if not any(x<=a<=y for x,y in covered):continue
            if episodes and a-episodes[-1][1] <= cfg['scoring_visit_gap_seconds']:
                episodes[-1][1]=max(episodes[-1][1],b)
            else:episodes.append([a,b])
        cycles=[b[0]-a[0] for a,b in zip(episodes,episodes[1:])
                if any(x<=a[0] and b[0]<=y for x,y in covered)]
        summaries.append(dict(team=team,phase='teleop',observed_eligible_seconds=round(duration,3),
            scoring_visits=len(episodes),observed_seconds_per_visit=round(duration/len(episodes),3) if episodes else None,
            cycle_intervals_seconds=cycles,mean_cycle_seconds=statistics.mean(cycles) if cycles else None,
            status='partial_observation' if duration else 'insufficient_evidence',
            interpretation='Candidate scoring visits; unobserved gaps excluded. Not piece counts or point estimates.'))
    return dict(version=VERSION,policy=cfg,events=sorted(events,key=lambda e:(e['start_ms'],e['team'],e['tag_key'])),robots=summaries)
