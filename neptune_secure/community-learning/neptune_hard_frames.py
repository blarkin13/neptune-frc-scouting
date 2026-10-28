"""Pick frames worth boxing on the Robots page from saved tracking reviews.

Tracking reviews cannot train the bumper detector themselves (their boxes are widened chassis crops the detector
already found, and a missed robot leaves no trace). They do show where the detector goes wrong: boxes a person
marked "not a robot", and moments where a reviewed robot's track drops out. Those timestamps are sent to the
labelling page so the owner boxes every visible bumper in them.
"""
from neptune_training import HELD_OUT_MATCHES, HELD_OUT_SOURCES

LOST_GAP_S = 1.0          # a reviewed track missing this long inside one camera view
PER_FALSE_TRACK = 3       # first, middle and last moment of each "not a robot" track
MAX_FRAMES = 40
MIN_SPACING_S = 1.0


def picks(review, holdout=()):
    """review: a saved tracking review. Returns [{'t_ms', 'why', 'camera'}] sorted by time."""
    match = review.get('match_key')
    if match in HELD_OUT_MATCHES or review.get('source_sha256') in HELD_OUT_SOURCES:
        raise ValueError('The reserved benchmark match is never labelled for training.')
    if match in set(holdout):
        raise ValueError('The held-out check match is never labelled for training.')
    points = review.get('points') or []
    tracks = {}
    for p in points:
        tracks.setdefault((p['identity_status'], p.get('review_track')), []).append(p)
    found = []
    for (status, _), pts in sorted(tracks.items(), key=lambda kv: (kv[0][0], str(kv[0][1]))):
        pts = sorted(pts, key=lambda p: p['t'])
        if status == 'excluded':
            for p in {id(q): q for q in (pts[0], pts[len(pts)//2], pts[-1])}.values():
                found.append({'t_ms': round(p['t']*1000), 'why': 'false_positive', 'camera': p['camera']})
        elif status == 'corrected':
            for a, b in zip(pts, pts[1:]):
                if a['scene'] == b['scene'] and b['t']-a['t'] >= LOST_GAP_S:
                    found.append({'t_ms': round((a['t']+b['t'])*500), 'why': 'robot_lost', 'camera': a['camera']})
    # Lost robots first (recall is the larger gap), then false positives; keep frames a second apart.
    found.sort(key=lambda f: (f['why'] != 'robot_lost', f['t_ms']))
    kept = []
    for f in found:
        if len(kept) < MAX_FRAMES and all(abs(f['t_ms']-k['t_ms']) >= MIN_SPACING_S*1000 for k in kept):
            kept.append(f)
    return sorted(kept, key=lambda f: f['t_ms'])


def main():
    import argparse, json
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('reviews', nargs='+')
    parser.add_argument('--holdout', action='append', default=[])
    args = parser.parse_args()
    for path in args.reviews:
        review = json.load(open(path))
        try:
            rows = picks(review, args.holdout)
        except ValueError as error:
            print(review.get('match_key'), 'skipped:', error); continue
        print(json.dumps({'match_key': review.get('match_key'), 'times_ms': [r['t_ms'] for r in rows], 'frames': rows}))


if __name__ == '__main__':
    main()
