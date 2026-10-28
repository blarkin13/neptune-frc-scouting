"""Per-match training-data and output quality reports. Read-only: never changes labels or models.

Two checks:
  clock_report  How trustworthy the game-clock join is for one match video, and what happens to each
                outside-scouting row when it is joined. A bad join silently turns into bad positives.
  count_report  Counts per (team, action) from the model against reference labels within a time
                tolerance, and the points those counts add up to per alliance, compared with the
                score bug the video itself shows. Official totals may be passed for checking only.

neptune_training uses clock_report's verdict to skip outside rows from `exclude` matches.
"""
import argparse
import collections
import json
import math
import statistics
import sys
from pathlib import Path

from neptune_clock import align

VERSION = 'match-quality-v1'

# Verdict thresholds. A match is "usable" for outside-label training only when all hold.
MIN_ALIGNED_RATE = .8          # share of outside rows that join to a unique video time
MAX_FIT_P90_S = 1.5            # 90th-percentile clock residual against a 1 s/s running clock
MAX_OUTLIER_RATE = .2          # clock readings OCR rejected
MARGINAL_ALIGNED_RATE = .5

DEFAULT_TOLERANCE_MS = 3000    # same bound as the imported-click lookback in neptune_training


def _num(v):
    return type(v) in (int, float) and math.isfinite(v)


def _count(v):
    return len(v) if isinstance(v, list) else v if type(v) is int else 0


def clock_fit(phase, length):
    """How well the observed clock behaves like one continuously running clock.

    While a clock runs, remaining + video_time is constant. Residuals from the median of that
    sum measure OCR noise, pauses and replays; long runs of one value mark a frozen clock.
    """
    samples = []
    for s in phase.get('clock_samples') or [] if isinstance(phase.get('clock_samples'), list) else []:
        t, r = s.get('t'), s.get('remaining')
        if _num(t) and _num(r) and 0 <= r <= length + 1:
            samples.append((float(t), float(r)))
    samples.sort()
    # Rejected reads of the held start/end value (2:20 before the start, 0:00 after the end) are
    # expected; only rejections while the clock should be running indicate OCR or overlay trouble.
    raw = phase.get('clock_outliers') if isinstance(phase.get('clock_outliers'), list) else []
    running_rejects = [o for o in raw if _num(o.get('clock')) and 0 < o['clock'] < length]
    readings = _count(phase.get('clock_readings'))
    seen = readings + len(running_rejects)
    out = {'samples': len(samples), 'readings': readings, 'outliers': _count(phase.get('clock_outliers')),
           'running_rejects': len(running_rejects),
           'outlier_rate': round(len(running_rejects) / seen, 3) if seen else None,
           'start_observed': phase.get('start_observed'), 'end_observed': phase.get('end_observed')}
    if len(samples) < 3:
        return dict(out, status='insufficient_clock_samples')
    # Interior samples only: the clock legitimately holds at its first and last value.
    running = [(t, r) for t, r in samples if 0 < r < length] or samples
    sums = [t + r for t, r in running]
    centre = statistics.median(sums)
    residuals = sorted(abs(v - centre) for v in sums)
    p90 = residuals[min(len(residuals) - 1, int(.9 * len(residuals)))]
    frozen, run_start = 0., None
    for (ta, ra), (tb, rb) in zip(running, running[1:]):
        if ra == rb and 0 < ra < length:
            run_start = ta if run_start is None else run_start
            frozen = max(frozen, tb - run_start)
        else:
            run_start = None
    span = running[-1][0] - running[0][0]
    return dict(out, status='measured', residual_median_s=round(residuals[len(residuals) // 2], 3),
                residual_p90_s=round(p90, 3), longest_frozen_s=round(frozen, 3),
                coverage_s=round(span, 1), coverage_rate=round(min(1., span / length), 3))


def _phase_length(facts, name, phase):
    return (facts.get('rules') or {}).get(name + '_seconds') or phase.get('length_rule')


def clock_report(facts, rows=()):
    """Clock-join quality for one match video plus the fate of each outside-scouting row."""
    phases = {}
    for name, phase in (facts.get('periods') or {}).items():
        if name in ('auto', 'teleop') and isinstance(phase, dict):
            length = _phase_length(facts, name, phase)
            phases[name] = clock_fit(phase, length) if length else {'status': 'phase_length_unavailable'}
    joins, statuses, by_phase = [], collections.Counter(), collections.defaultdict(list)
    for row in rows:
        ext = row.get('external') or {}
        name = ext.get('phase')
        phase = (facts.get('periods') or {}).get(name) or {}
        length = _phase_length(facts, name, phase) if name else None
        if not phase:
            result = {'status': 'unavailable', 'reason': 'phase_not_in_video'}
        elif not length:
            result = {'status': 'unavailable', 'reason': 'phase_length_unknown'}
        else:
            result = align(phase, row.get('start_ms'), length)
        key = result['status'] if result['status'] == 'aligned' else result['status'] + ':' + result.get('reason', '')
        statuses[key] += 1
        joins.append({'label_id': row.get('id'), 'team': row.get('team_number'), 'tag_key': row.get('tag_key'),
                      'phase': name, 'source_ms': row.get('start_ms'), 'status': result['status'],
                      'reason': result.get('reason'), 'video_ms': result.get('video_ms')})
        if result['status'] == 'aligned':
            by_phase[name].append((row.get('start_ms'), result['video_ms']))
    # Scouting time order must survive the join; an inversion means a replay or a wrong clock read.
    inversions = 0
    for pairs in by_phase.values():
        pairs.sort()
        inversions += sum(1 for (_, a), (_, b) in zip(pairs, pairs[1:]) if b < a)
    total = len(joins)
    aligned = statuses.get('aligned', 0)
    report = {'version': VERSION, 'match_key': (facts.get('identity') or {}).get('match_key'),
              'phases': phases, 'outside_rows': total, 'aligned': aligned,
              'aligned_rate': round(aligned / total, 3) if total else None,
              'join_outcomes': dict(statuses), 'order_inversions': inversions, 'joins': joins}
    report['verdict'], report['problems'] = verdict(report)
    return report


def verdict(report):
    problems = []
    measured = [p for p in report['phases'].values() if p.get('status') == 'measured']
    if not measured:
        problems.append('no phase has a measurable clock')
    for name, p in report['phases'].items():
        if p.get('status') != 'measured':
            problems.append(f'{name}: {p.get("status")}')
            continue
        if p['residual_p90_s'] > MAX_FIT_P90_S:
            problems.append(f'{name}: clock residual p90 {p["residual_p90_s"]} s > {MAX_FIT_P90_S} s')
        if p['outlier_rate'] is not None and p['outlier_rate'] > MAX_OUTLIER_RATE:
            problems.append(f'{name}: {p["outlier_rate"]:.0%} of clock reads rejected')
    rate = report['aligned_rate']
    if rate is not None and rate < MIN_ALIGNED_RATE:
        problems.append(f'only {rate:.0%} of outside rows joined to a unique video time')
    if report['order_inversions']:
        problems.append(f'{report["order_inversions"]} joined rows are out of scouting time order')
    # Exclude only on evidence of a wrong join: a measured clock that does not run steadily, rows that
    # lose their time order, or most rows failing to join. Too few readings to measure is "marginal".
    bad_fit = any(p['residual_p90_s'] > MAX_FIT_P90_S for p in measured)
    if bad_fit or report['order_inversions'] or (rate is not None and rate < MARGINAL_ALIGNED_RATE) \
            or (not measured and not report['aligned']):
        return 'exclude', problems
    return ('usable' if not problems else 'marginal'), problems


def match_events(predicted, reference, tolerance_ms=DEFAULT_TOLERANCE_MS):
    """Greedy one-to-one time matching per (team, tag). Returns per-key counts."""
    def group(rows):
        g = collections.defaultdict(list)
        for r in rows:
            if r.get('polarity', 'present') == 'present' and _num(r.get('start_ms')):
                g[(str(r.get('team_number', r.get('team'))), r['tag_key'])].append(r['start_ms'])
        return g
    pred, ref = group(predicted), group(reference)
    out = {}
    for key in sorted(set(pred) | set(ref)):
        p, r = sorted(pred.get(key, [])), sorted(ref.get(key, []))
        used, hits = set(), 0
        for t in p:
            best = min((abs(t - u), i) for i, u in enumerate(r) if i not in used) if len(used) < len(r) else None
            if best and best[0] <= tolerance_ms:
                used.add(best[1]); hits += 1
        out[key] = {'predicted': len(p), 'reference': len(r), 'matched': hits,
                    'missed': len(r) - hits, 'extra': len(p) - hits}
    return out


def final_display(facts):
    """Last accepted score-bug value per alliance: what the video itself says the score is."""
    out = {}
    for alliance in ('red', 'blue'):
        values = [s for s in (facts.get('accepted_scores') or {}).get(alliance) or [] if type(s.get('value')) is int]
        out[alliance] = max(values, key=lambda s: s['t'])['value'] if values else None
    return out


def count_report(predicted, reference, roster, points, facts=None, official=None, tolerance_ms=DEFAULT_TOLERANCE_MS):
    """Counts and summed points per alliance. `official` is for comparison only, never an input."""
    per_key = match_events(predicted, reference, tolerance_ms)
    team_alliance = {str(t): a for a in ('red', 'blue') for t in (roster or {}).get(a, [])}
    alliances = {a: {'predicted_points': 0, 'reference_points': 0, 'unpriced_tags': set()} for a in ('red', 'blue')}
    for (team, tag), c in per_key.items():
        a = team_alliance.get(team)
        if a is None:
            continue
        if tag not in points:
            alliances[a]['unpriced_tags'].add(tag)
            continue
        alliances[a]['predicted_points'] += c['predicted'] * points[tag]
        alliances[a]['reference_points'] += c['reference'] * points[tag]
    display = final_display(facts or {})
    for a, row in alliances.items():
        row['unpriced_tags'] = sorted(row['unpriced_tags'])
        row['video_score_bug'] = display.get(a)
        row['official_total'] = (official or {}).get(a)
        for name in ('video_score_bug', 'official_total'):
            if type(row[name]) is int:
                row['predicted_minus_' + name] = row['predicted_points'] - row[name]
    totals = collections.Counter()
    for c in per_key.values():
        totals.update(c)
    ref, pred = totals['reference'], totals['predicted']
    return {'version': VERSION, 'tolerance_ms': tolerance_ms,
            'recall': round(totals['matched'] / ref, 3) if ref else None,
            'precision': round(totals['matched'] / pred, 3) if pred else None,
            'totals': dict(totals), 'alliances': alliances,
            'per_team_action': [dict(team=k[0], tag_key=k[1], **v) for k, v in per_key.items()],
            'note': 'Score bug and official totals include penalties and actions with no tag; '
                    'a gap is expected and is not forced to zero.'}


def _ndjson(path):
    return [json.loads(line) for line in Path(path).read_text().splitlines() if line.strip()]


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    c = sub.add_parser('clock', help='clock-join quality for one match')
    c.add_argument('--facts', required=True)
    c.add_argument('--labels', help='labels.ndjson; outside-scouting rows for this video are joined')
    n = sub.add_parser('counts', help='model counts and points against reference labels')
    n.add_argument('--facts', required=True)
    n.add_argument('--predicted', required=True, help='ndjson of decoded events (neptune_events.decode output)')
    n.add_argument('--labels', required=True, help='reference labels.ndjson (video-time rows)')
    n.add_argument('--points', required=True, help='JSON {tag_key: points}')
    n.add_argument('--official', help='JSON {"red": int, "blue": int}, for checking only')
    n.add_argument('--tolerance-ms', type=int, default=DEFAULT_TOLERANCE_MS)
    a = ap.parse_args(argv)
    facts = json.loads(Path(a.facts).read_text())
    match_key = (facts.get('identity') or {}).get('match_key')
    labels = _ndjson(a.labels) if a.labels else []
    if match_key:
        labels = [r for r in labels if r.get('match_key') == match_key] or labels
    if a.cmd == 'clock':
        report = clock_report(facts, [r for r in labels if r.get('external')])
    else:
        roster = next((r['roster'] for r in labels if r.get('roster')), None) or (facts.get('identity') or {}).get('roster')
        report = count_report(_ndjson(a.predicted), labels, roster, json.loads(Path(a.points).read_text()), facts,
                              json.loads(Path(a.official).read_text()) if a.official else None, a.tolerance_ms)
    json.dump(report, sys.stdout, indent=1, default=str)
    print()


if __name__ == '__main__':
    main()
