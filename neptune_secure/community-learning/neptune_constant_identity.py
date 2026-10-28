"""One identity per robot for the whole match, carried along tracker tracklets.

Seeds are the existing trusted labels only: OCR-verified tracker segments
(`yolo_botsort_ocr`), the roster solver's OCR-verified points and its proven
inferences. Each seed labels its whole tracklet; tracklets end at gaps, jumps,
scene cuts and same-alliance contact, so a tracker swap during a collision
never carries a number to the wrong robot. Unlabeled tracklets are then linked
to a just-ended (or just-starting) labeled tracklet in the same camera shot when
the motion gate, colour and number reads agree and no rival is close. Last, a
moving tracklet takes the third roster team of its alliance when the other two
are visibly labeled in that camera for its whole life. A still-unnamed wide-view
tracklet may then take the team its look clearly matches among that match's named
tracklets (strict margin; close-ups never). Nothing crosses a camera
cut by motion: after a cut only OCR or the roster can name a robot again.
Any two tracklets claiming one team in one camera at the same time lose the
carried labels; reviewed points are never an input.
"""
import collections
import math

VERSION = 'constant-identity-v4'
MAX_GAP = 1.0           # seconds without a sighting that still counts as one tracklet
LINK_GAP = 1.5          # longest unseen gap a motion/colour link may bridge (2 s carried a 5261 box onto 6672 in QM1)
APPEARANCE_LIMIT = .11  # same scale as neptune_identity bridges
RIVAL_MARGIN = .35
SEED_METHODS = ('yolo_botsort_ocr',)
SEED_REASONS = ('verified_ocr_segment', 'same_number_before_and_after_contact')
# Roster-only inferences seed a tracklet only when it moves: a still false box
# (a field element, a cart) can win the roster by elimination.
ROSTER_REASONS = ('bidirectional_motion_and_roster',)
FULL_FIELD_CAMERA = 'wide'
# Look fallback: an unnamed wide-view tracklet takes the team whose already-named tracklets
# in this match look clearly closest. With half the OCR hidden, a .06 margin named 9 of 36
# QM4 boxes wrong; .08 was the first clean margin, so .1 keeps a step in hand. Close-ups
# were near chance and never use it.
LOOK_MARGIN = .1
LOOK_LIMIT = 2*APPEARANCE_LIMIT


def _center(box):
    return box[0]+box[2]/2, box[1]+box[3]/2


def _overlap(a, b):
    x, y, w, h = a; c, d, e, f = b
    return max(0, min(x+w, c+e)-max(x, c))*max(0, min(y+h, d+f)-max(y, d))/max(1e-9, min(w*h, e*f))


def _appearance(a, b):
    if not isinstance(a, list) or not isinstance(b, list) or len(a) != 24 or len(b) != 24:
        return None
    return sum(abs(x-y) for x, y in zip(a, b))/6


def tracklets(observations):
    frames = collections.defaultdict(list)
    for p in observations:
        frames[(p['camera'], str(p['scene']), p['alliance'], p['t'])].append(p)
    contact = set()
    for rows in frames.values():
        for p in rows:
            if p.get('occluded') or any(q is not p and _overlap(p['box'], q['box']) > .2 for q in rows):
                contact.add(id(p))
    groups = collections.defaultdict(list)
    for p in observations:
        if p.get('track_id') is None or p.get('box_source') == 'optical_flow':
            continue
        groups[(p['camera'], str(p['scene']), p['alliance'], str(p['track_id']))].append(p)
    out = []
    for group, points in sorted(groups.items()):
        run = []
        for p in sorted(points, key=lambda q: q['t']):
            split = False
            if run:
                prev = run[-1]; dt = p['t']-prev['t']
                (ax, ay), (bx, by) = _center(prev['box']), _center(p['box'])
                jump = math.hypot(ax-bx, ay-by) > max(.12, 3*max(prev['box'][2], p['box'][2]))
                # A collision can swap tracker numbers: what follows contact is a new tracklet.
                split = dt > MAX_GAP or dt <= 0 or jump or (id(p) in contact) != (id(prev) in contact)
            if split:
                out.append(run); run = []
            run.append(p)
        if run:
            out.append(run)
    return [{'camera': r[0]['camera'], 'scene': str(r[0]['scene']), 'alliance': r[0]['alliance'], 'points': r,
             'contact': all(id(p) in contact for p in r), 'start': r[0]['t'], 'end': r[-1]['t'], 'team': None, 'how': None}
            for r in out]


def _seed_index(tracking, reasons=SEED_REASONS):
    seeds = collections.defaultdict(set)
    for p in tracking.get('localizations', []) if reasons is SEED_REASONS else ():
        if p.get('identity_method') in SEED_METHODS and p.get('team') is not None:
            seeds[(p.get('camera'), round(p['t'], 3), str(p.get('track_id')))].add(p['team'])
    review = tracking.get('identity_review') or {}
    for p in review.get('points', []):
        if p.get('team') is not None and p.get('identity_status') in ('verified', 'inferred') and p.get('reason') in reasons:
            seeds[(p.get('camera'), round(p['t'], 3), str(p.get('track_id')))].add(p['team'])
    return seeds


def _reads_allow(n, team):
    return not any(p.get('read_teams') and team not in p['read_teams'] for p in n['points']) and \
        not any(p.get('verified_team') not in (None, team) for p in n['points'])


def _busy(nodes, n, team):
    return any(m is not n and m['team'] == team and m['camera'] == n['camera'] and
               m['start'] <= n['end'] and n['start'] <= m['end'] for m in nodes)


def _mean_appearance(points):
    rows = [p['appearance'] for p in points if isinstance(p.get('appearance'), list) and len(p['appearance']) == 24]
    return [sum(v[i] for v in rows)/len(rows) for i in range(24)] if rows else None


def _link_score(a, b):
    """a ends before b starts in one camera shot; lower is better, None is impossible."""
    gap = b['start']-a['end']
    if a['scene'] != b['scene'] or a['camera'] != b['camera'] or a['alliance'] != b['alliance'] or not 0 < gap <= LINK_GAP:
        return None
    p, q = a['points'][-1], b['points'][0]
    (ax, ay), (bx, by) = _center(p['box']), _center(q['box'])
    distance = math.hypot(ax-bx, ay-by); scale = max(p['box'][2], q['box'][2])
    size = abs(math.log(max(1e-6, p['box'][2]*p['box'][3])/max(1e-6, q['box'][2]*q['box'][3])))
    if distance > max(.04, 1.6*scale*max(gap, .34)) or size > .7:
        return None
    look = _appearance(_mean_appearance(a['points'][-3:]), _mean_appearance(b['points'][:3]))
    if look is not None and look > APPEARANCE_LIMIT:
        return None
    return distance/max(.02, scale*max(gap, .34))+4*(look or APPEARANCE_LIMIT)+size


def _moves(n):
    xs = [_center(p['box']) for p in n['points']]
    reach = max(math.hypot(a[0]-b[0], a[1]-b[1]) for a in xs for b in xs[:1]+xs[-1:])
    return reach >= .08*max(p['box'][2] for p in n['points'])


def _roster_seed_ok(n):
    # Elimination among three teams only holds when the whole field is in view;
    # a close-up shows part of it, so there a roster guess carries only with a read.
    return n['camera'] == FULL_FIELD_CAMERA or any(p.get('read_teams') for p in n['points'])


def label(tracking, roster, source_sha256=None):
    nodes = [n for n in tracklets(tracking.get('observations', [])) if n['alliance'] in roster]
    seeds, roster_seeds = _seed_index(tracking), _seed_index(tracking, ROSTER_REASONS)
    for n in nodes:
        teams = collections.Counter()
        for index in (seeds, roster_seeds) if _roster_seed_ok(n) else (seeds,):
            for p in n['points']:
                for team in index.get((p['camera'], round(p['t'], 3), str(p['track_id'])), ()):
                    teams[team] += 1
        if len(teams) == 1:
            team = next(iter(teams))
            if team in roster[n['alliance']] and _reads_allow(n, team):
                n.update(team=team, how='seeded_tracklet')
    # A seed that disagrees with another seed in one camera at once is not trusted for carrying.
    for n in nodes:
        if n['how'] == 'seeded_tracklet' and _busy(nodes, n, n['team']):
            n['conflict'] = True
    for n in nodes:
        if n.get('conflict'):
            n.update(team=None, how=None)
    by_shot = collections.defaultdict(list)
    for n in nodes:
        by_shot[(n['camera'], n['scene'], n['alliance'])].append(n)
    changed = True
    while changed:
        changed = False
        every = []
        for shot in by_shot.values():
            for a in shot:
                for b in shot:
                    score = _link_score(a, b)
                    if score is not None:
                        every.append((score, a, b))
        pairs = [r for r in every if (r[1]['team'] is None) != (r[2]['team'] is None) and not r[1]['contact'] and not r[2]['contact']]
        for score, a, b in sorted(pairs, key=lambda r: r[0]):
            known, unknown = (a, b) if a['team'] is not None else (b, a)
            if unknown['team'] is not None or not _reads_allow(unknown, known['team']) or _busy(nodes, unknown, known['team']):
                continue
            # A close rival on either end means the link is a guess, whether or not that
            # rival is named or in contact: in a scrum an unnamed robot is the likelier match.
            rivals = [r for r in every if r[0] < score+RIVAL_MARGIN and r[1:] != (a, b) and (r[1] is a or r[2] is b)]
            if rivals:
                continue
            unknown.update(team=known['team'], how='motion_colour_link'); changed = True
    # A contact stretch keeps the number of the same tracker track next to it. One named side
    # is enough (hidden-OCR check: 210 carried, 0 wrong over QM1/3/4/6); the two sides
    # naming different teams means a swap, and the stretch stays unnamed.
    for shot in by_shot.values():
        for n in shot:
            if not n['contact'] or n['team'] is not None:
                continue
            tid = str(n['points'][0]['track_id'])
            before = [m for m in shot if m['team'] is not None and str(m['points'][0]['track_id']) == tid and 0 < n['start']-m['end'] <= LINK_GAP]
            after = [m for m in shot if m['team'] is not None and str(m['points'][0]['track_id']) == tid and 0 < m['start']-n['end'] <= LINK_GAP]
            teams = {m['team'] for m in before+after}
            if (before or after) and len(teams) == 1:
                team = next(iter(teams))
                if _reads_allow(n, team) and not _busy(nodes, n, team):
                    n.update(team=team, how='same_track_through_contact')
    for shot in by_shot.values():
        if shot[0]['camera'] != FULL_FIELD_CAMERA:
            continue
        teams = roster[shot[0]['alliance']]
        for n in shot:
            if n['team'] is not None or n['contact'] or len(n['points']) < 3 or not _moves(n):
                continue
            free = []
            for team in teams:
                covering = [m for m in shot if m is not n and m['team'] == team]
                seen = sum(any(m['start'] <= p['t'] <= m['end'] for m in covering) for p in n['points'])
                if seen < len(n['points']):
                    free.append(team)
            rivals = [m for m in shot if m is not n and m['team'] is None and m['start'] <= n['end'] and n['start'] <= m['end']]
            if len(free) == 1 and not rivals and _reads_allow(n, free[0]):
                n.update(team=free[0], how='roster_elimination')
    # Gallery is fixed before the pass, so one look guess never seeds another.
    named = [m for m in nodes if m['team'] is not None]
    for shot in by_shot.values():
        if shot[0]['camera'] != FULL_FIELD_CAMERA:
            continue
        teams = roster[shot[0]['alliance']]
        for n in shot:
            if n['team'] is not None or n['contact'] or len(n['points']) < 3:
                continue
            look = _mean_appearance(n['points'])
            if look is None:
                continue
            best = {}
            for m in named:
                if m['camera'] == n['camera'] and m['alliance'] == n['alliance']:
                    d = _appearance(look, _mean_appearance(m['points']))
                    if d is not None and d < best.get(m['team'], 9):
                        best[m['team']] = d
            if sorted(best) != sorted(teams):
                continue
            (team, near), (_, second) = sorted(best.items(), key=lambda r: r[1])[:2]
            if near <= LOOK_LIMIT and second-near >= LOOK_MARGIN and _reads_allow(n, team) and not _busy(nodes, n, team):
                n.update(team=team, how='look_fallback')
    points = []
    for n in nodes:
        for p in n['points']:
            points.append(dict(p, team=n['team'], identity_how=n['how'],
                               tracklet='%s:%s:%s:%s' % (n['camera'], n['scene'], p['track_id'], round(n['start'], 3))))
    labeled = [n for n in nodes if n['team'] is not None]
    return {'version': VERSION, 'points': sorted(points, key=lambda p: (p['t'], p['camera'])),
            'summary': {'tracklets': len(nodes), 'labeled_tracklets': len(labeled),
                        'by_method': dict(collections.Counter(n['how'] for n in labeled)),
                        'labeled_points': sum(len(n['points']) for n in labeled)}}


def with_constant_identity(tracking, roster, source_sha256=None):
    """Add carried labels to localizations (identity_method 'constant_identity') where no trusted label exists."""
    base = [p for p in tracking.get('localizations', []) if p.get('identity_method') != 'constant_identity']
    from neptune_identity import roster_check
    try:
        roster_check(roster)
    except ValueError:
        return dict(tracking, localizations=base)
    result = label(tracking, roster, source_sha256)
    taken = collections.defaultdict(list)
    for p in base:
        taken[(p.get('camera'), p['team'])].append(p['t'])
    busy = {(p.get('camera'), round(p['t'], 3), str(p.get('track_id'))) for p in base}
    added = []
    for p in result['points']:
        if p['team'] is None:
            continue
        if p.get('occluded') or (p.get('camera'), round(p['t'], 3), str(p.get('track_id'))) in busy:
            continue
        if any(abs(t-p['t']) < .2 for t in taken[(p.get('camera'), p['team'])]):
            continue
        added.append({'t': p['t'], 'box': p['box'], 'camera': p.get('camera'), 'camera_bounds': p.get('camera_bounds'), 'scene': p.get('scene'),
                      'track_id': p.get('track_id'), 'team': p['team'], 'detection_confidence': p.get('detection_confidence'),
                      'identity_method': 'constant_identity', 'identity_segment': 'constant:'+p['tracklet'],
                      'identity_evidence': {'solver': VERSION, 'how': p['identity_how'], 'training_eligible': False}})
    return dict(tracking, localizations=sorted(base+added, key=lambda q: (q['t'], str(q.get('camera')))),
                constant_identity=dict(result['summary'], added=len(added)))
