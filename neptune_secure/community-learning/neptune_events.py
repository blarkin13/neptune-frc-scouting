"""Conservative temporal decoding of classifier candidates, not scoring facts."""
from neptune_clock import eligible

VERSION = 'phase-episode-decoder-v2'


def decode(samples, facts, model_id, action_definitions=None):
    """Require two nearby positives, emit an onset once, rearm only on observed lows.

    Missing sightings do not prove an action stopped. Outcome heads compete once
    per phase; generic appearance never proves a successful score or climb.
    """
    groups = {}
    for s in samples:
        if eligible(s['tag_key'], facts, s['t']):
            groups.setdefault((s['team'], s['tag_key']), []).append(s)
    out = []
    for (team, tag), rows in groups.items():
        active = False; positives = []; lows = []; emitted = []
        for row in sorted(rows, key=lambda r: r['t']):
            if row['score'] >= row['threshold']:
                lows = []
                if active:
                    continue
                if positives and row['t'] - positives[-1]['t'] > 3:
                    positives = []
                if positives and row['t']-positives[-1]['t']<.5:
                    continue # two crops of one video moment are not two observations
                positives.append(row)
                if len(positives) < 2:
                    continue
                first = positives[0]; active = True
                emitted.append(dict(first, support_samples=len(positives)))
            else:
                positives = []; lows.append(row['t'])
                if len(lows) >= 2 and lows[-1] - lows[0] >= 1:
                    active = False
        if tag.startswith(('auto_', 'end_')) and emitted:
            emitted = [max(emitted, key=lambda r: r['score'] - r['threshold'])]
        for row in emitted:
            out.append({'team': team, 'tag_key': tag, 'start_ms': round(row['t']*1000),
                        'end_ms': round(row['t']*1000), 'certainty': 'uncertain',
                        'model_id': model_id, 'identity_evidence': row['identity_evidence'],
                        'evidence_type': 'model_prediction', 'score': row['score'],
                        'score_is_probability': False, 'support_samples': row['support_samples'],
                        'decoder_version': VERSION, 'assessment': 'candidate_requires_video_confirmation'})
    # Mutually exclusive outcome heads cannot all be emitted for the same phase.
    winners = {}; actions = []
    for row in out:
        family = 'auto' if row['tag_key'].startswith('auto_') else 'end' if row['tag_key'].startswith('end_') else None
        if family:
            key = (row['team'], family)
            if key not in winners or row['score'] > winners[key]['score']: winners[key] = row
        else: actions.append(row)
    result=sorted(actions + list(winners.values()), key=lambda r: (r['start_ms'], r['team']))
    for row in result:
        definition=(action_definitions or {}).get(row['tag_key'])
        if definition:
            row.update(schema_version=2,game_key=definition['game_key'],label_scope='moment',time_basis='video_elapsed_ms',action_code=definition['action_code'],definition_variants=definition['variants'])
            if len(definition['variants'])==1:row.update(definition['variants'][0])
    return result

# Owner-defined scouting convention. This labels observed inactivity, not a
# diagnosis of a mechanical fault. Missing identity/visibility is never inactivity.
DISABLED_AFTER_SECONDS = 5.0
STATIONARY_RADIUS_IN_BUMPER_WIDTHS = .20


def stationary_disabled(facts, model_id, threshold=DISABLED_AFTER_SECONDS, radius_widths=STATIONARY_RADIUS_IN_BUMPER_WIDTHS, max_gap=1.1):
    import math
    from neptune_clock import phase_at
    groups = {}
    for p in facts.get('tracking', {}).get('localizations', []):
        if p.get('identity_method') not in ('direct_vote_track','yolo_botsort_ocr','human_reviewed_track','roster_inferred','constant_identity'):
            continue
        if p.get('occluded') or p.get('box_source') == 'optical_flow':continue
        if phase_at(facts,p['t'])[0] != 'teleop':continue
        groups.setdefault((p['team'],p.get('camera','wide'),str(p.get('scene')),p.get('identity_segment',p.get('track_id'))), []).append(p)
    events=[]
    for (team,camera,scene,segment),rows in groups.items():
        run=[];emitted=False
        for p in sorted(rows,key=lambda p:p['t']):
            b=p.get('box',[])
            if len(b)!=4 or not all(type(v) in (int,float) and math.isfinite(v) for v in b) or min(b[2:])<=0:run=[];emitted=False;continue
            if run and (p['t']-run[-1]['t']<=0 or p['t']-run[-1]['t']>max_gap):run=[];emitted=False
            if run:
                first=run[0]['box'];radius=radius_widths*min(first[2],b[2])
                if math.hypot(b[0]+b[2]/2-first[0]-first[2]/2,b[1]+b[3]/2-first[1]-first[3]/2)>radius or not .8<=b[2]/first[2]<=1.25:
                    run=[];emitted=False
            run.append(p)
            if emitted:
                events[-1]['end_ms']=round(p['t']*1000);continue
            if p['t']-run[0]['t']<=threshold:continue
            # A replay/frozen video cannot establish five seconds of live play.
            phase=phase_at(facts,p['t'])[1]
            clocks=[c for c in phase.get('clock_samples',[]) if run[0]['t']<=c.get('t',-1)<=p['t'] and type(c.get('remaining')) in (int,float)]
            clocks.sort(key=lambda c:c['t'])
            if len(clocks)<2 or clocks[-1]['t']-clocks[0]['t']<p['t']-run[0]['t']-2 or clocks[-1]['remaining']>=clocks[0]['remaining'] or abs((clocks[-1]['t']-clocks[0]['t'])-(clocks[0]['remaining']-clocks[-1]['remaining']))>1.5:continue
            events.append({'team':team,'tag_key':'disabled','start_ms':round(run[0]['t']*1000),'end_ms':round(p['t']*1000),
                'certainty':'uncertain','model_id':model_id,'evidence_type':'model_prediction','identity_evidence':p.get('identity_evidence','visible_tracked_identity'),
                'assessment':'owner_defined_stationary_rule','rule':'teleop_stationary_over_5_seconds_v1','support_samples':len(run),'camera':camera})
            emitted=True
    # Multiple broadcast views cannot duplicate one episode.
    unique=[]
    for event in sorted(events,key=lambda e:(e['start_ms'],e['team'])):
        if not any(e['team']==event['team'] and e['start_ms']<=event['end_ms'] and event['start_ms']<=e['end_ms'] for e in unique):unique.append(event)
    return unique
