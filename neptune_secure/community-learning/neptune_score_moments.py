"""Scoreboard score changes for Watch & tag: when an alliance's displayed score went up, by how much.

A tagging aid only. They point people at moments worth looking at; they are never training labels or model inputs
(official scores are for evaluation only, AGENTS.md rule 1). Saved when a match's scoreboard is read (training
deletes the match's facts afterwards), then sent to Neptune when the helper is idle.
"""
import json
from pathlib import Path

HOME = Path.home()/'.neptune-trainer'
FOLDER = HOME/'score-moments'


def changes(facts):
    """[{t, alliance, from, to, delta}] in video seconds, from the accepted (filtered) scoreboard readings."""
    out = []
    for side in ('red', 'blue'):
        reads = sorted(facts.get('accepted_scores', {}).get(side) or [], key=lambda r: r['t'])
        last = None
        for r in reads:
            value = r.get('value')
            if not isinstance(value, int):
                continue
            if last is not None and value > last:
                out.append({'t': round(float(r['t']), 1), 'alliance': side, 'from': last, 'to': value, 'delta': value-last})
            if last is None or value > last:
                last = value
    return sorted(out, key=lambda e: (e['t'], e['alliance']))


def bursts(facts, gap=3.0, grace=5.0):
    """Changes grouped into scoring bursts per alliance (increases under `gap` s apart), inside auto/teleop only:
    [{start, end, alliance, points, phase}]. After teleop the display adds fouls and endgame tallies; those are left out."""
    windows = [(k, v['start']-grace, v['end']+grace) for k, v in periods(facts).items() if k in ('auto', 'teleop')]
    phase = lambda t: next((k for k, a, b in windows if a <= t <= b), None)
    out = []
    for side in ('red', 'blue'):
        for e in (x for x in changes(facts) if x['alliance'] == side and phase(x['t'])):
            last = out[-1] if out and out[-1]['alliance'] == side else None
            if last and e['t']-last['end'] <= gap and phase(e['t']) == last['phase']:
                last['end'] = e['t']; last['points'] += e['delta']
            else:
                out.append({'start': e['t'], 'end': e['t'], 'alliance': side, 'points': e['delta'], 'phase': phase(e['t'])})
    return sorted(out, key=lambda b: (b['start'], b['alliance']))


def periods(facts):
    p = facts.get('periods') or {}
    return {k: {'start': round(v['start'], 1), 'end': round(v['end'], 1)} for k, v in p.items() if isinstance(v, dict) and 'start' in v}


def save(video_id, match_key, facts):
    """Keep this match's score changes until they are sent (never fails the caller)."""
    try:
        if len(str(video_id)) != 11 or not facts.get('accepted_scores'):
            return
        (FOLDER/'pending').mkdir(parents=True, exist_ok=True)
        if (FOLDER/'sent'/(video_id+'.json')).exists():
            return
        doc = {'video_id': video_id, 'match_key': match_key, 'bursts': bursts(facts), 'events': changes(facts), 'periods': periods(facts),
               'source_sha256': (facts.get('source') or {}).get('sha256')}
        tmp = FOLDER/'pending'/(video_id+'.pending'); tmp.write_text(json.dumps(doc)); tmp.replace(FOLDER/'pending'/(video_id+'.json'))
    except (OSError, ValueError, KeyError, TypeError):
        pass


def backfill():
    """Matches already read for tracking review keep their facts; queue those once."""
    for path in (HOME/'identity-reviews').glob('*/facts.json'):
        try:
            facts = json.loads(path.read_text()); vid = (facts.get('source') or {}).get('video_id') or ''
            media = next((p.stem for p in (path.parent/'media').glob('*.mp4')), '') if (path.parent/'media').is_dir() else ''
            save(vid or media, path.parent.name, facts)
        except (OSError, ValueError):
            continue


def send_pending(config, api):
    """Send queued score changes to Neptune; each is sent once."""
    sent = 0
    for path in sorted((FOLDER/'pending').glob('*.json')):
        try:
            doc = json.loads(path.read_text())
            api(config, 'score_put', doc)
        except Exception:
            break        # offline or refused: try again later
        (FOLDER/'sent').mkdir(parents=True, exist_ok=True); path.replace(FOLDER/'sent'/path.name); sent += 1
    return sent
