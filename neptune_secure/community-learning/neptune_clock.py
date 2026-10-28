"""Evidence-only clock joins shared by desktop and server training.

No extrapolation from a nominal phase start: a stopped/replayed clock is ambiguous.
All times returned here are elapsed source-video milliseconds.
"""
import math
import statistics

VERSION = 'observed-clock-join-v2'


def align(phase, elapsed_ms, length):
    if type(length) not in (int,float) or not math.isfinite(length) or length<=0:
        return {'status':'unavailable','reason':'invalid_phase_length'}
    if type(elapsed_ms) not in (int, float) or not math.isfinite(elapsed_ms) or not 0 <= elapsed_ms <= length * 1000:
        return {'status': 'unavailable', 'reason': 'source_time_outside_phase'}
    target = length - elapsed_ms / 1000
    samples=[]
    for sample in phase.get('clock_samples') or []:
        t,r=sample.get('t'),sample.get('remaining')
        if type(t) not in (int,float) or type(r) not in (int,float) or not math.isfinite(t) or not math.isfinite(r) or not 0<=r<=length+1:continue
        if type(phase.get('start')) in (int,float) and t<phase['start']-2:continue
        if type(phase.get('end')) in (int,float) and t>phase['end']+2:continue
        samples.append((float(t),float(r)))
    samples=sorted(set(samples))
    if len(samples)<2:return {'status':'unavailable','reason':'no_local_clock_bracket'}
    # An exact value shown over a pause or on a later replay has no unique join.
    exact = [t for t, r in samples if r == target]
    if exact:
        if max(exact) - min(exact) > 2:
            return {'status': 'ambiguous', 'reason': 'clock_repeated_or_frozen'}
        return {'status': 'aligned', 'video_ms': round(statistics.median(exact) * 1000),
                'uncertainty_ms': 1100, 'method': 'broadcast_clock_exact','phase_elapsed_ms':elapsed_ms,'clock_remaining_s':target,'anchor_count':len(exact)}
    joins = []
    for (ta, ra), (tb, rb) in zip(samples, samples[1:]):
        dc, dt = ra - rb, tb - ta
        if ra > target > rb and 0 < dc <= 4 and 0 < dt <= 5 and abs(dt - dc) <= 1.25:
            joins.append(ta + (ra - target) * dt / dc)
    if joins and max(joins) - min(joins) <= 1:
        return {'status': 'aligned', 'video_ms': round(statistics.median(joins) * 1000),
                'uncertainty_ms': 1100, 'method': 'local_observed_clock_bracket','phase_elapsed_ms':elapsed_ms,'clock_remaining_s':target,'anchor_count':2}
    return {'status': 'ambiguous' if joins else 'unavailable',
            'reason': 'multiple_clock_occurrences' if joins else 'no_local_clock_bracket'}


def phase_at(facts, t):
    matches = [(name, p) for name, p in facts.get('periods', {}).items()
               if name in ('auto', 'teleop') and p and p['start'] <= t <= p['end']]
    return matches[0] if len(matches) == 1 else (None, None)


def eligible(tag, facts, t):
    name, phase = phase_at(facts, t)
    if not phase:
        return False
    if tag.startswith('auto_'):
        return name == 'auto'
    if tag.startswith('end_'):
        # Use the game pack's configured window; never a season-independent constant.
        endgame = facts.get('rules', {}).get('endgame')
        window = endgame.get('seconds') if isinstance(endgame, dict) else None
        return name == 'teleop' and (endgame is None or isinstance(window, (int, float)) and phase['end'] - window <= t)
    return True
