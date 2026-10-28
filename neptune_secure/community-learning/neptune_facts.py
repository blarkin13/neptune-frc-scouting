"""Facts pipeline: turns a match video into evidence-backed facts with local OCR. No AI model.

Stage 1  Broadcast overlay, once per second: layout profile, match title, roster, clock, live alliance
         scores and the shift indicator. Verifies match identity and anchors the match periods from
         validated clock sequences and the season's rules (season_rules.json).
Stage 2  Displayed-score timeline: outlier-tolerant filtering with every rejected reading kept,
         coverage gaps, largest displayed increases, lead changes in the readable samples, and the
         final results screen (with its own arithmetic check).
Stage 3  Bumper numbers in the camera views below the overlay. An exact roster number on a bumper of
         the team's alliance colour, seen in two separate frames, confirms a robot; everything else stays
         a candidate or is rejected with a reason.
Summary  Written only from accepted facts, carrying uncertainty and coverage gaps into the wording.

Raw OCR readings are written to the work folder (raw-*.jsonl). The facts dict separates accepted facts
from rejected readings; every fact carries evidence: video time, frame or crop file, OCR text,
recognition confidence, box and any correction applied. The source hash and OCR backend/version are
recorded once at the top of the facts.
"""
import difflib
import json
import re
import statistics
import subprocess
from bisect import bisect_right
from collections import Counter
from pathlib import Path

import neptune_ocr
import neptune_games

PIPELINE_VERSION = 'facts-4.2-roster-sides'
RULES = json.loads(Path(__file__).with_name('season_rules.json').read_text())
CLOCK = re.compile(r'^(\d{1,2}):([0-5]\d)$')
TITLE = re.compile(r'\b(Qualification|Practice|Playoff|Semifinal|Quarterfinal|Final|Upper Bracket|Lower Bracket|Einstein|Match)\s*(?:Match\s*)?(\d+)', re.I)
LOOKALIKE = {'O': '0', 'o': '0', 'D': '0', 'Q': '0', 'I': '1', 'l': '1', 'i': '1', '|': '1', 'Z': '2', 'S': '5', 's': '5', 'B': '8', 'g': '9', 'q': '9'}

# Broadcast layout profiles. Regions are (x0, x1, y0, y1) bounds for an item's centre, as fractions of
# the overlay band (the top `band` of the frame). A video is analysed only when one profile matches.
LAYOUTS = {
    'frc-2026-field-overlay-v1': {
        # closeup_top: close-up camera panels start this far down the camera area (frame y 0.665). Lit
        # team-number displays at the driver stations only appear in the wide field shot above it.
        'season': 2026, 'band': 0.30, 'camera_top': 0.24, 'closeup_top': 0.56, 'marker': 'REBUILT',
        'regions': {'clock': (0.42, 0.58, 0.45, 0.90), 'red_score': (0.28, 0.46, 0.45, 0.90),
                    'blue_score': (0.54, 0.72, 0.45, 0.90), 'title': (0.25, 0.75, 0.0, 0.30),
                    'red_roster': (0.0, 0.33, 0.24, 0.52), 'blue_roster': (0.67, 1.0, 0.24, 0.52),
                    'red_label': (0.33, 0.46, 0.24, 0.52), 'blue_label': (0.54, 0.67, 0.24, 0.52),
                    'shift': (0.42, 0.58, 0.20, 0.52), 'marker': (0.70, 1.0, 0.0, 0.32)}},
}
# Tried after the game pack's own profiles. They live in the engine, not the pack, so adding one leaves the
# game fingerprint (and every model trained on it) unchanged.
EXTRA_LAYOUTS = {
    # The FIRST AGE overlay at full height (FIT District Fort Worth, 2026): blue left, RED/BLUE words over the
    # scores, the clock in the lower half of the band and the shift above it. Measured on Fort Worth qm10.
    'frc-2026-age-large-v1': {
        'season': 2026, 'band': 0.30, 'camera_top': 0.235, 'closeup_top': 0.63, 'marker': 'REBUILT',
        'regions': {'clock': (0.44, 0.56, 0.50, 0.80), 'red_score': (0.58, 0.66, 0.50, 0.80),
                    'blue_score': (0.34, 0.42, 0.50, 0.80), 'title': (0.38, 0.62, 0.02, 0.22),
                    'red_roster': (0.68, 0.99, 0.28, 0.48), 'blue_roster': (0.02, 0.32, 0.28, 0.48),
                    'red_label': (0.58, 0.66, 0.28, 0.48), 'blue_label': (0.34, 0.42, 0.28, 0.48),
                    'shift': (0.44, 0.56, 0.25, 0.48), 'marker': (0.82, 0.96, 0.0, 0.25)}},
}
MIN_LAYOUT_FRAMES = 20
# Bumper tiles over the camera area (fractions of the area below camera_top); they overlap so a number
# on a tile edge is still whole in one tile. Overlapping reads of one frame count once.
TILES = [(max(0.0, c / 3 - 0.05), min(1.0, (c + 1) / 3 + 0.05), y0, y1)
         for (y0, y1) in ((0.0, 0.56), (0.44, 1.0)) for c in range(3)]
TILE_NAMES = ['upper-left', 'upper-middle', 'upper-right', 'lower-left', 'lower-middle', 'lower-right']
DISPLAY_SATURATION, DISPLAY_BRIGHTNESS = 0.45, 190   # see glyph_light(); set from facts-2 evaluation crops
SCORE_GAIN = (30.0, 30.0)   # largest plausible displayed increase: 30 points + 30 per second
SCORE_DROP = 80             # larger drops are treated as misreads, never as corrections
DROP_PENALTY = 1.5          # a decrease is accepted only when at least two later readings sustain it
FRESH = 2.5                 # a displayed score older than this (seconds) is unknown, not carried forward


class FactsError(ValueError):
    """The video cannot be turned into trustworthy facts; nothing is invented instead."""


def ocr_available() -> bool:
    try:
        neptune_ocr.select_backend()
        return True
    except neptune_ocr.OcrUnavailable:
        return False


# ---------------------------------------------------------------- helpers

def season_rules(match_key):
    try:
        game=neptune_games.select(match_key)
        return game['season'],game
    except ValueError as error:
        raise FactsError(str(error)) from None


def centre(item):
    return item['x'] + item['w'] / 2, item['y'] + item['h'] / 2


def in_region(item, region):
    cx, cy = centre(item)
    return region[0] <= cx <= region[1] and region[2] <= cy <= region[3]


def evidence(item, frame, t, correction=None, **extra):
    out = {'t': round(t, 2), 'frame': frame, 'text': item['t'], 'confidence': None if item.get('c') is None else round(float(item['c']), 3),
           'box': [round(item[k], 4) for k in ('x', 'y', 'w', 'h')], 'correction': correction}
    out.update(extra)
    return out


def clock_text(seconds):
    seconds = max(0, int(round(seconds)))
    return '%d:%02d' % (seconds // 60, seconds % 60)


def mmss(t):
    sign='−' if t<0 else '';t=abs(t)
    return sign+'%d:%04.1f' % (int(t // 60), t % 60)


def ffmpeg(args, timeout=1800):
    subprocess.run(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '2'] + args, check=True, timeout=timeout)


def clear(folder, pattern):
    folder.mkdir(parents=True, exist_ok=True)
    for old in folder.glob(pattern):
        old.unlink()


def frames_at(folder, pattern, start, fps):
    # Extraction uses fps=N:round=up, which takes the source frame at exactly start + k/N (the default
    # rounding takes one about half an interval later).
    return [(start + i / fps, p) for i, p in enumerate(sorted(folder.glob(pattern)))]


def save_raw(path, frames, texts, root):
    with open(path, 'w') as out:
        for t, p in frames:
            out.write(json.dumps({'t': round(t, 2), 'frame': str(Path(p).relative_to(root)), 'items': texts.get(str(p), [])}) + '\n')


# ---------------------------------------------------------------- stage 1: overlay

def team_token(item):
    """A roster number, also when OCR reads a team logo as one or two symbols in front of it ("* 3676")."""
    m = re.fullmatch(r'(?:[^\w\s]{1,2}\s*)?(\d{1,5})', item['t'].strip())
    return (item if m.group(1) == item['t'].strip() else dict(item, t=m.group(1))) if m else None


def split_numbers(items):
    """Items like "5025 10147 2584" (a reader joining a row of team numbers) -> one item per number, placed by
    character offset. Other items are unchanged."""
    out = []
    for i in items:
        words = i['t'].split()
        if len(words) < 2 or not all(re.fullmatch(r'\d{1,5}', w) for w in words):
            out.append(i)
            continue
        per = i['w'] / max(1, len(i['t']))
        at = 0
        for w in words:
            k = i['t'].index(w, at)
            out.append(dict(i, t=w, x=i['x'] + k * per, w=len(w) * per))
            at = k + len(w)
    return out


def parse_overlay(items, profile):
    """One overlay frame -> raw readings (items, not yet accepted)."""
    R = profile['regions']
    items = split_numbers(items)

    def pick(region, pattern):
        found = [i for i in items if in_region(i, R[region]) and re.fullmatch(pattern, i['t'].strip())]
        return max(found, key=lambda i: i['h']) if found else None

    def word(region, text):
        return any(in_region(i, R[region]) and i['t'].strip().upper() == text for i in items)

    out = {'clock': pick('clock', CLOCK.pattern[1:-1]), 'red': pick('red_score', r'\d{1,3}'), 'blue': pick('blue_score', r'\d{1,3}'),
           'title': next((i for i in items if in_region(i, R['title']) and TITLE.search(i['t'])), None),
           'labels': word('red_label', 'RED') and word('blue_label', 'BLUE'),
           'marker': any(in_region(i, R['marker']) and difflib.SequenceMatcher(None, re.sub(r'[^A-Z]', '', i['t'].upper())[:len(profile['marker'])], profile['marker']).ratio() >= 0.8 for i in items),
           'roster': {side: sorted([team_token(i) for i in items if in_region(i, R[side + '_roster']) and team_token(i)], key=lambda i: i['x'])
                      for side in ('red', 'blue')},
           'shift': None}
    # Week 0's compact overlay has coloured score blocks but no RED/BLUE words.
    # Require the title and at least two separate roster numbers on each side;
    # the exact six-team roster is verified before any outside label is used.
    if profile.get('marker_regex'):
        out['marker'] = any(in_region(i, R['marker']) and re.search(profile['marker_regex'], i['t'], re.I) for i in items)
    if profile.get('label_mode') == 'roster-and-title':
        out['labels'] = bool(out['title']) and all(len({i['t'].strip() for i in out['roster'][side]}) >= 2 for side in ('red', 'blue'))
    if out['clock'] is not None:
        m = CLOCK.match(out['clock']['t'].strip())
        out['clock_value'] = int(m.group(1)) * 60 + int(m.group(2))
    shift_text = ' '.join(i['t'] for i in sorted((i for i in items if in_region(i, R['shift'])), key=lambda i: i['x']))
    m = re.search(r'([1-9])\s*/\s*([1-9])\s*:?\s*:(\d\d)|([1-9])\s*/\s*([1-9])\s+:?(\d\d)', shift_text)
    if m:
        g = [x for x in m.groups() if x is not None]
        out['shift'] = {'shift': int(g[0]), 'of': int(g[1]), 'seconds_left': int(g[2]), 'text': shift_text}
    return out


def parse_overlay_generic(items, roster=None, sides=None):
    """Scoreboard read without a layout profile, for broadcasts no profile matches.

    The clock is the tallest M:SS text; each alliance score is the nearest 1-3 digit number of similar height
    on the clock's row, one on each side. Which side is red comes from where the match's own roster numbers
    (or the words RED and BLUE) appear on the overlay; when neither shows, the scores stay unread. Returns
    None without a clock. Game-independent: nothing here names a season or an overlay design. `sides`
    ('red-left' or 'blue-left'), once settled for the whole video, overrides the per-frame vote."""
    clocks = [i for i in items if CLOCK.match(i['t'].strip().replace('O', '0').replace('o', '0'))]
    if not clocks:
        return None
    clock = max(clocks, key=lambda i: i['h'])
    m = CLOCK.match(clock['t'].strip().replace('O', '0').replace('o', '0'))
    cx, cy = clock['x'] + clock['w'] / 2, clock['y'] + clock['h'] / 2
    out = {'clock': clock, 'clock_value': int(m.group(1)) * 60 + int(m.group(2)), 'red': None, 'blue': None,
           'red_value': None, 'blue_value': None, 'sides': None}
    near = [i for i in items if re.fullmatch(r'\d{1,3}', i['t'].strip()) and i['h'] >= 0.6 * clock['h']
            and abs(i['y'] + i['h'] / 2 - cy) <= 0.5 * clock['h'] and abs(i['x'] + i['w'] / 2 - cx) <= 4 * clock['w']]
    left = max((i for i in near if i['x'] + i['w'] / 2 < cx), key=lambda i: i['x'], default=None)
    right = min((i for i in near if i['x'] + i['w'] / 2 > cx), key=lambda i: i['x'], default=None)
    vote = 0   # > 0: red on the left
    if roster:
        for side, sign in (('red', 1), ('blue', -1)):
            for t in roster.get(side, []):
                for i in items:
                    if i['t'].strip() == str(t):
                        vote += sign if i['x'] + i['w'] / 2 < cx else -sign
    if sides:
        vote = 1 if sides == 'red-left' else -1
    if vote == 0:
        for i in items:
            word = i['t'].strip().upper()
            if word in ('RED', 'BLUE'):
                vote += (1 if word == 'RED' else -1) * (1 if i['x'] + i['w'] / 2 < cx else -1)
    if vote:
        red, blue = (left, right) if vote > 0 else (right, left)
        out.update(red=red, blue=blue, sides='red-left' if vote > 0 else 'blue-left',
                   red_value=int(red['t']) if red else None, blue_value=int(blue['t']) if blue else None)
    return out


def overlay_clock_context(reading,profile):
    return reading['labels'] or (profile.get('label_mode')=='roster-and-title'
             and reading['marker'] and all(reading['roster'][side] for side in ('red','blue')))


def roster_sides(reading, roster):
    """(numbers in each roster region that belong to that alliance, numbers that belong to the other one)."""
    own = cross = 0
    for side, other in (('red', 'blue'), ('blue', 'red')):
        seen = {i['t'].strip() for i in reading['roster'][side]}
        own += sum(str(t) in seen for t in roster.get(side, []))
        cross += sum(str(t) in seen for t in roster.get(other, []))
    return own, cross


def detect_layout(frames, texts, season, layouts=None, roster=None):
    """Returns (profile id, profile, stats). Raises FactsError for an unsupported layout.

    With the match roster, a profile whose roster regions show the other alliance's teams more often than their
    own is skipped: two overlays can share one geometry with red and blue on opposite sides, and the wrong one
    would swap every score."""
    layouts=LAYOUTS if layouts is None else layouts
    report = {}
    for pid, profile in layouts.items():
        overlay = marker = own = cross = 0
        for t, p in frames:
            r = parse_overlay(texts.get(str(p), []), profile)
            if overlay_clock_context(r,profile) and r['clock'] is not None:
                overlay += 1
                marker += r['marker']
                if roster:
                    a, b = roster_sides(r, roster)
                    own += a
                    cross += b
        report[pid] = {'overlay_frames': overlay, 'marker_frames': marker, 'season': profile['season']}
        if roster:
            report[pid]['roster_numbers_on_own_side'], report[pid]['roster_numbers_on_other_side'] = own, cross
            if cross > own:
                continue
        if overlay >= MIN_LAYOUT_FRAMES and marker >= 0.3 * overlay:
            if profile['season'] != season:
                raise FactsError('The video uses the %d broadcast layout but the match is from %d. Wrong video or season.' % (profile['season'], season))
            return pid, profile, report[pid]
    raise FactsError('Unsupported broadcast layout: no known overlay profile matched (%s). The facts pipeline needs a layout profile for this broadcast.' % ', '.join(
        '%s: %d overlay frames, %d with the %s marker%s' % (k, v['overlay_frames'], v['marker_frames'], layouts[k]['marker'],
            ', red and blue on the other sides' if v.get('roster_numbers_on_other_side', 0) > v.get('roster_numbers_on_own_side', 0) else '') for k, v in report.items()))


def generic_layout(frames, texts, roster, band):
    """Settle a broadcast no profile matched: (sides, camera_top, stats), or None.

    Needs MIN_LAYOUT_FRAMES clocks and, among frames whose roster numbers or RED/BLUE words show the sides,
    at least 90% agreeing; otherwise the video stays unsupported. camera_top is just below the clock row."""
    clocks, votes, bottoms = 0, Counter(), []
    for _, p in frames:
        r = parse_overlay_generic(texts.get(str(p), []), roster)
        if r is None:
            continue
        clocks += 1
        bottoms.append(r['clock']['y'] + r['clock']['h'])
        if r['sides']:
            votes[r['sides']] += 1
    stats = {'overlay_frames': clocks, 'sided_frames': sum(votes.values())}
    if clocks < MIN_LAYOUT_FRAMES or not votes:
        return None
    sides, n = votes.most_common(1)[0]
    stats['agreeing_frames'] = n
    if n < MIN_LAYOUT_FRAMES or n < 0.9 * sum(votes.values()):
        return None
    camera_top = round(min(band, sorted(bottoms)[len(bottoms) // 2] * band + 0.01), 3)
    return sides, camera_top, stats


def generic_samples(band, texts, sides, rules, root):
    """overlay_samples() for a broadcast read by parse_overlay_generic with settled sides."""
    samples, clock_rejects = [], []
    for t, p in band:
        items = split_numbers(texts.get(str(p), []))
        r = parse_overlay_generic(items, sides=sides)
        frame = str(Path(p).relative_to(root))
        s = {'t': t, 'frame': frame, 'roster': {'red': [], 'blue': []}, 'shift': None, 'labels': r is not None}
        title = next((i for i in items if TITLE.search(i['t'])), None)
        if title:
            s['title_text'] = TITLE.search(title['t']).group(0)
            s['title_ev'] = evidence(title, frame, t)
        if r is None:
            samples.append(s)
            continue
        cx = r['clock']['x'] + r['clock']['w'] / 2
        for i in items:
            tok = team_token(i)
            if tok and i is not r['red'] and i is not r['blue']:
                left = i['x'] + i['w'] / 2 < cx
                s['roster']['red' if left == (sides == 'red-left') else 'blue'].append(tok)
        if r['clock_value'] <= rules['teleop_seconds'] + 1:
            s['clock'] = r['clock_value']
            s['ev'] = evidence(r['clock'], frame, t)
        else:
            clock_rejects.append(dict(evidence(r['clock'], frame, t), reason='clock above the teleop length'))
        for side in ('red', 'blue'):
            if r[side] is not None:
                s[side] = {'value': r[side + '_value'], 't': t, 'ev': evidence(r[side], frame, t)}
        samples.append(s)
    return samples, clock_rejects


def overlay_samples(band, texts, profile, rules, root):
    """Raw overlay readings per frame (clock, scores, title, roster, shift) with evidence."""
    samples, clock_rejects = [], []
    for t, p in band:
        r = parse_overlay(texts.get(str(p), []), profile)
        frame = str(Path(p).relative_to(root))
        s = {'t': t, 'frame': frame, 'roster': {k: v for k, v in r['roster'].items()}, 'shift': r['shift'], 'labels': r['labels']}
        if r['title']:
            s['title_text'] = TITLE.search(r['title']['t']).group(0)
            s['title_ev'] = evidence(r['title'], frame, t)
        # Layout detection already established these fixed regions. A roster/title
        # OCR miss must not erase a clear clock: require the game marker and
        # visible roster structure on both sides instead. This does not verify
        # the six-team roster or accept unrelated score pixels.
        clock_context = overlay_clock_context(r,profile)
        if r['clock'] is not None and clock_context:
            if r['clock_value'] <= rules['teleop_seconds'] + 1:
                s['clock'] = r['clock_value']
                s['ev'] = evidence(r['clock'], frame, t)
            else:
                clock_rejects.append(dict(evidence(r['clock'], frame, t), reason='clock above the teleop length'))
        for side in ('red', 'blue'):
            if r[side] is not None and r['labels']:
                s[side] = {'value': int(r[side]['t']), 't': t, 'ev': evidence(r[side], frame, t)}
        samples.append(s)
    return samples, clock_rejects


SCORE_REREAD_SCALE = 3   # single-digit scores the whole-band read misses are re-read from the enlarged score box


def score_region(profile, side, margin=0.02):
    """The score box of one side as (x, y, w, h) fractions of the overlay band, with a small margin."""
    x0, x1, y0, y1 = profile['regions'][side + '_score']
    x0, y0, x1, y1 = max(0, x0 - margin), max(0, y0 - margin), min(1, x1 + margin), min(1, y1 + margin)
    return x0, y0, x1 - x0, y1 - y0


def pick_score(items):
    """The tallest 1-3 digit text in a score-box crop, or None. Inside the score box alone, a lone round
    letter is the zero Apple Vision reads as O (measured on the OCR benchmark)."""
    found = [dict(i, t='0') if i['t'].strip() in ('O', 'o', 'D', 'Q') else i for i in items]
    found = [i for i in found if re.fullmatch(r'\d{1,3}', i['t'].strip())]
    return max(found, key=lambda i: i['h']) if found else None


def reread_scores(samples, profile, backend, root):
    """Fill scores the whole-band read missed on frames with an accepted clock and both alliance labels."""
    jobs = []
    folder = Path(root) / 'score-reread'
    for s in samples:
        if 'clock' not in s or not s['labels']:
            continue
        for side in ('red', 'blue'):
            if side in s:
                continue
            x, y, w, h = score_region(profile, side)
            out = folder / ('%s-%s.png' % (Path(s['frame']).stem, side))
            jobs.append((s, side, out, (x, y, w, h)))
    if not jobs:
        return 0
    clear(folder, '*.png')
    for s, side, out, (x, y, w, h) in jobs:
        ffmpeg(['-i', str(Path(root) / s['frame']), '-vf', 'crop=iw*%g:ih*%g:iw*%g:ih*%g,scale=iw*%d:ih*%d:flags=bicubic' % (w, h, x, y, SCORE_REREAD_SCALE, SCORE_REREAD_SCALE),
                str(out)], timeout=60)
    texts = backend.recognize([str(j[2]) for j in jobs])
    filled = 0
    for s, side, out, region in jobs:
        item = pick_score(texts.get(str(out), []))
        if item is not None:
            s[side] = {'value': int(item['t'].strip()), 't': s['t'], 'ev': evidence(item, s['frame'], s['t'], correction='score box re-read at %dx' % SCORE_REREAD_SCALE, crop=[round(v, 4) for v in region])}
            filled += 1
    return filled


def clock_segments(samples):
    """Split clock readings into countdown sequences; an upward reset or a long gap starts a new one."""
    segs, cur = [], []
    for s in samples:
        if cur and (s['clock'] > cur[-1]['clock'] + 3 or s['t'] - cur[-1]['t'] > 15):
            segs.append(cur)
            cur = []
        cur.append(s)
    if cur:
        segs.append(cur)
    return segs


def fit_countdown(seg):
    """Validate one countdown: readings must agree on when the clock reaches zero (t + clock)."""
    ends = [s['t'] + s['clock'] for s in seg]
    med = statistics.median(ends)
    inliers = [s for s, e in zip(seg, ends) if abs(e - med) <= 1.5]
    if len(inliers) < 5:
        return None
    e = [s['t'] + s['clock'] for s in inliers]
    zero = statistics.mean(e)
    return {'zero': zero, 'spread': max(e) - min(e), 'inliers': inliers, 'outliers': [s for s in seg if s not in inliers],
            'max_clock': max(s['clock'] for s in inliers), 'min_clock': min(s['clock'] for s in inliers),
            'first_t': inliers[0]['t'], 'last_t': inliers[-1]['t']}


def anchor_periods(samples, rules, duration):
    """Auto and teleop periods from validated clock sequences; lengths come from the season rules."""
    A, T = rules['auto_seconds'], rules['teleop_seconds']
    fits, rejected = [], []
    for seg in clock_segments(samples):
        f = fit_countdown(seg)
        if f is None:
            rejected.append({'t': seg[0]['t'], 'readings': len(seg), 'reason': 'too few consistent clock readings'})
            continue
        if f['max_clock'] > T + 1:
            rejected.append({'t': f['first_t'], 'readings': len(seg), 'reason': 'clock above the %d s teleop length in the season rules' % T})
            continue
        fits.append(f)

    def period(f, name, length):
        u = max(1.0, f['spread'] / 2 + 0.5)
        start = f['zero'] - length
        return {'name': name, 'length_rule': length, 'start': start, 'end': f['zero'], 'uncertainty_s': round(u, 2),
                'start_observed': f['max_clock'] >= length - 2 and f['first_t'] <= start + length - f['max_clock'] + 3,
                'end_observed': f['min_clock'] <= 2 and f['zero'] <= duration,
                'clock_readings': len(f['inliers']), 'clock_outliers': [{'t': s['t'], 'clock': s['clock'], 'frame': s['frame']} for s in f['outliers']],
                'clock_samples': [{'t': s['t'], 'remaining': s['clock'], 'frame': s['frame']} for s in f['inliers']],
                'evidence': [{'t': s['t'], 'frame': s['frame'], 'text': s['ev']['text'], 'confidence': s['ev']['confidence']} for s in (f['inliers'][0], f['inliers'][-1])]}

    teleops = [f for f in fits if f['max_clock'] > A + 1]
    teleop = max(teleops, key=lambda f: len(f['inliers'])) if teleops else None
    for f in teleops:
        if f is not teleop:
            rejected.append({'t': f['first_t'], 'readings': len(f['inliers']), 'reason': 'second teleop-length countdown; the longest one is used'})
    auto = None
    for f in fits:
        if f in teleops:
            continue
        if teleop is not None:
            gap = (teleop['zero'] - T) - f['zero']
            if 0 <= gap <= rules['max_auto_to_teleop_gap_seconds']:
                auto = f
                continue
        elif f['max_clock'] >= A - 2:
            auto = f
            continue
        rejected.append({'t': f['first_t'], 'readings': len(f['inliers']), 'reason': 'short countdown that is not followed by teleop within the rule gap'})
    if teleop is None and auto is None:
        raise FactsError('No validated match clock was found: the overlay clock never counted down consistently.')
    periods = {'auto': period(auto, 'auto', A) if auto else None, 'teleop': period(teleop, 'teleop', T) if teleop else None}
    return periods, rejected


def endgame_boundary(periods, samples, rules):
    tele = periods['teleop']
    if tele is None or not rules.get('endgame'):
        return None
    eg = rules['endgame']['seconds']
    out = {'start': tele['end'] - eg, 'end': tele['end'], 'uncertainty_s': tele['uncertainty_s'], 'rule': rules['endgame']['definition'],
           'shift_indicator': None, 'status': 'rule'}
    shifts = rules.get('teleop_shifts_seconds') or []
    est = [s['t'] - (eg - s['shift']['seconds_left']) for s in samples
           if s.get('shift') and s['shift']['shift'] == len(shifts) == s['shift']['of'] and s['shift']['seconds_left'] <= eg
           and tele['start'] <= s['t'] <= tele['end'] + 1]
    if est:
        m = statistics.median(est)
        agree = abs(m - out['start'])
        out['shift_indicator'] = {'estimate': round(m, 2), 'readings': len(est), 'difference_s': round(agree, 2)}
        out['status'] = 'corroborated' if agree <= 2.5 else 'uncertain'
        if agree > 2.5:
            out['uncertainty_s'] = round(max(out['uncertainty_s'], agree), 2)
    return out


def verify_identity(samples, match, metadata_title, results):
    """Each check is match / mismatch / unreadable / unavailable. Mismatches stop the analysis."""
    checks = {}
    key = match.get('key') or ''
    km = re.search(r'_(qm|ef|qf|sf|f)(\d+)(?:m(\d+))?$', key)
    titles = Counter(s['title_text'] for s in samples if s.get('title_text'))
    if not titles:
        checks['match_number'] = {'status': 'unreadable', 'detail': 'no match title on the overlay'}
    else:
        text, n = titles.most_common(1)[0]
        tm = TITLE.search(text)
        status = 'unavailable'
        if km and tm:
            level, a, b = km.group(1), int(km.group(2)), km.group(3)
            word, num = tm.group(1).lower(), int(tm.group(2))
            if level == 'qm':
                status = 'match' if word == 'qualification' and num == a else 'mismatch'
            elif word == 'qualification':
                status = 'mismatch'
            else:
                status = 'unreadable'   # playoff overlays need their own profile before they can be checked
        checks['match_number'] = {'status': status, 'overlay_title': text, 'frames': n, 'claimed_key': key,
                                  'evidence': [s['title_ev'] for s in samples if s.get('title_text') == text][:2]}
    # Presence, not position: team logos can contain small numbers that add or split tokens.
    claimed = {'red': list(match['roster']['red']), 'blue': list(match['roster']['blue'])}
    stations = []
    for side in ('red', 'blue'):
        frames = [{i['t'].strip() for i in s['roster'][side]} for s in samples if s.get('labels') and s['roster'][side]]
        others = Counter(t for f in frames for t in f if len(t) >= 3 and not any(t in str(c) or str(c) in t for c in claimed['red'] + claimed['blue']))
        other, other_n = others.most_common(1)[0] if others else (None, 0)
        for k, team in enumerate(claimed[side]):
            present = sum(str(team) in f for f in frames)
            if present >= 5:
                st = 'match'
            elif other_n >= max(5, 0.5 * len(frames)):
                st = 'mismatch'
            else:
                st = 'unreadable'
            stations.append({'station': '%s %d' % (side, k + 1), 'claimed': team, 'frames_present': present, 'frames_read': len(frames),
                             'overlay': team if st == 'match' else other, 'status': st})
    sts = {s['status'] for s in stations}
    roster_status = 'mismatch' if 'mismatch' in sts else 'match' if sts == {'match'} else 'partial'
    checks['roster'] = {'status': roster_status, 'stations': stations}
    event = (match.get('title') or '').split(' · ')[0].strip()
    if not metadata_title:
        checks['event'] = {'status': 'unavailable', 'detail': 'the video has no title metadata; the overlay does not show the event or division'}
    elif not event:
        checks['event'] = {'status': 'unavailable', 'detail': 'the claim has no event name'}
    else:
        norm = lambda s: re.sub(r'[^a-z0-9]+', ' ', s.lower()).strip()
        div = lambda s: set(re.findall(r'([a-z]+) division', norm(s)))
        if div(event) and div(metadata_title) and div(event) != div(metadata_title):
            st = 'mismatch'
        elif norm(event) in norm(metadata_title):
            st = 'match'
        else:
            st = 'unreadable'
        num = re.search(r'\bqm(\d+)\b|Qualification (\d+)', metadata_title)
        if km and km.group(1) == 'qm' and num and int(num.group(1) or num.group(2)) != int(km.group(2)):
            st = 'mismatch'
        checks['event'] = {'status': st, 'source': 'video title metadata (not read from the image)', 'video_title': metadata_title, 'claimed_event': event}
    if results and results.get('title_text'):
        rt = TITLE.search(results['title_text'])
        if km and rt and km.group(1) == 'qm':
            checks['results_screen_title'] = {'status': 'match' if int(rt.group(2)) == int(km.group(2)) else 'mismatch', 'text': results['title_text']}
    bad = [k for k, v in checks.items() if v['status'] == 'mismatch']
    if bad:
        raise FactsError('Wrong video for this match: %s.' % '; '.join(describe_check(k, checks[k]) for k in bad))
    missing = [k for k in ('match_number', 'roster', 'event') if checks[k]['status'] != 'match']
    return {'status': 'verified' if not missing else 'incomplete', 'missing': missing, 'checks': checks}


def describe_check(name, c):
    if name == 'roster':
        return 'the overlay roster does not show %s; it consistently shows %s' % (', '.join(str(s['claimed']) for s in c['stations'] if s['status'] == 'mismatch'),
                                                                               ', '.join(sorted({str(s['overlay']) for s in c['stations'] if s['status'] == 'mismatch'})))
    if name == 'match_number':
        return 'overlay title "%s" does not match %s' % (c.get('overlay_title'), c.get('claimed_key'))
    if name == 'event':
        return 'video title "%s" does not match %s' % (c.get('video_title'), c.get('claimed_event'))
    return '%s: %s' % (name, c)


# ---------------------------------------------------------------- stage 2: displayed scores

def filter_scores(readings):
    """Choose the most consistent reading sequence. Rises are capped by a rate; a decrease is accepted only
    when later readings sustain it. Returns (accepted, rejected-with-reason, decreases)."""
    n = len(readings)
    if not n:
        return [], [], []
    best, prev = [1.0] * n, [None] * n
    for i in range(n):
        for j in range(max(0, i - 90), i):
            dt = readings[i]['t'] - readings[j]['t']
            d = readings[i]['value'] - readings[j]['value']
            if d > SCORE_GAIN[0] + SCORE_GAIN[1] * dt or d < -SCORE_DROP:
                continue
            s = best[j] + 1 - (DROP_PENALTY if d < 0 else 0)
            if s > best[i]:
                best[i], prev[i] = s, j
    i = max(range(n), key=lambda k: (best[k], k))
    keep = []
    while i is not None:
        keep.append(i)
        i = prev[i]
    keep = set(keep)
    accepted = [r for k, r in enumerate(readings) if k in keep]
    times = [r['t'] for r in accepted]
    rejected = []
    for k, r in enumerate(readings):
        if k in keep:
            continue
        a = bisect_right(times, r['t'])
        before = accepted[a - 1] if a else None
        after = accepted[a] if a < len(accepted) else None
        if before and r['value'] < before['value']:
            reason = 'lower than the accepted %d at %s and not sustained by later readings' % (before['value'], mmss(before['t']))
        elif after and r['value'] > after['value']:
            reason = 'higher than the next accepted %d at %s (likely misread)' % (after['value'], mmss(after['t']))
        elif before and r['value'] - before['value'] > SCORE_GAIN[0] + SCORE_GAIN[1] * (r['t'] - before['t']):
            reason = 'implausibly fast rise from %d' % before['value']
        else:
            reason = 'inconsistent with the surrounding readings'
        rejected.append(dict(r['ev'], value=r['value'], reason=reason))
    decreases = [{'t': b['t'], 'from': a['value'], 'to': b['value'], 'evidence': [a['ev'], b['ev']]}
                 for a, b in zip(accepted, accepted[1:]) if b['value'] < a['value']]
    return accepted, rejected, decreases


def score_at(series, t, max_age=FRESH):
    """Latest accepted reading at or before t, or None when there is none within max_age (unknown)."""
    times = [r['t'] for r in series]
    k = bisect_right(times, t + 0.01)
    if not k:
        return None
    r = series[k - 1]
    return r if t - r['t'] <= max_age else None


def value_at(series, b, u):
    """Displayed score at a boundary b with uncertainty ±u: accepted, uncertain (changed inside ±u) or unknown."""
    at = score_at(series, b)
    if at is None:
        return {'value': None, 'status': 'unknown', 'reason': 'no readable score within %.1f s before %s' % (FRESH, mmss(b)), 'evidence': []}
    window = [r['value'] for r in series if b - u < r['t'] <= b + u]
    base = score_at(series, b - u)
    values = set(window) | {at['value']} | ({base['value']} if base else set())
    out = {'value': at['value'], 'status': 'accepted' if len(values) == 1 else 'uncertain', 'evidence': [at['ev']]}
    if len(values) > 1:
        out['range'] = [min(values), max(values)]
        out['reason'] = 'the displayed score changed within ±%.1f s of the boundary' % u
    return out


def difference(a, b):
    if a['value'] is None or b['value'] is None:
        return {'value': None, 'status': 'unknown', 'evidence': a['evidence'] + b['evidence']}
    st = 'accepted' if a['status'] == b['status'] == 'accepted' else 'uncertain'
    return {'value': b['value'] - a['value'], 'status': st, 'evidence': a['evidence'] + b['evidence']}


def coverage(series, t0, t1, gap=5.0):
    """Share of whole seconds in [t0, t1] with a fresh accepted reading, and the gaps of `gap` s or more."""
    secs = [t0 + i for i in range(int(t1 - t0) + 1)]
    known = [score_at(series, s) is not None for s in secs]
    gaps, start = [], None
    for s, k in zip(secs + [None], known + [True]):
        if not k and start is None:
            start = s
        elif k and start is not None:
            end = s if s is not None else secs[-1]
            if end - start >= gap:
                gaps.append([round(start, 1), round(end, 1)])
            start = None
    return {'share': round(sum(known) / len(known), 3) if known else 0.0, 'gaps': gaps}


def largest_increase(series, t0, t1, window=15.0):
    """Largest rise of the displayed score over `window` s, using only windows with good coverage."""
    best = None
    t = t0
    while t + window <= t1 + 0.01:
        a, b = score_at(series, t, 1.5), score_at(series, t + window, 1.5)
        n = sum(1 for r in series if t <= r['t'] <= t + window)
        if a and b and n >= 0.7 * window:
            d = b['value'] - a['value']
            if best is None or d > best['value']:
                best = {'start': t, 'end': t + window, 'value': d, 'from': a['value'], 'to': b['value'], 'evidence': [a['ev'], b['ev']]}
        t += 1.0
    return best


def lead_changes(red, blue, t0, t1):
    changes, leader, last_known, unknown = [], None, None, []
    t, gap_start = t0, None
    while t <= t1 + 0.01:
        r, b = score_at(red, t, 1.5), score_at(blue, t, 1.5)
        if r and b:
            if gap_start is not None:
                if t - gap_start >= 5:
                    unknown.append([round(gap_start, 1), round(t, 1)])
                gap_start = None
            now = 'red' if r['value'] > b['value'] else 'blue' if b['value'] > r['value'] else None
            if now and leader and now != leader:
                changes.append({'t': round(t, 1), 'leader': now, 'red': r['value'], 'blue': b['value'],
                                'after_gap': last_known is not None and t - last_known > 2, 'evidence': [r['ev'], b['ev']]})
            if now:
                leader = now
            last_known = t
        elif gap_start is None:
            gap_start = t
        t += 1.0
    if gap_start is not None and t1 - gap_start >= 5:
        unknown.append([round(gap_start, 1), round(t1, 1)])
    span = max(1.0, t1 - t0)
    return {'changes': changes, 'unreadable_intervals': unknown,
            'readable_share': round(1 - sum(b - a for a, b in unknown) / span, 3)}


# ---------------------------------------------------------------- final results screen

def parse_results(items, rules):
    """Final results screen: alliance totals plus the component rows, checked by arithmetic."""
    up = lambda i: i['t'].strip().upper()
    words = {w for i in items for w in re.findall(r'[A-Z]+', up(i))}
    # The live overlay (with its running clock) stays up after the buzzer; only the results screen counts.
    if any(CLOCK.match(i['t'].strip()) for i in items) or not ({'WINNER', 'TIE'} & words or {'AUTO', 'TELEOP'} <= words):
        return None
    red_l = [i for i in items if up(i) == 'RED']
    blue_l = [i for i in items if up(i) == 'BLUE']
    nums = [i for i in items if re.fullmatch(r'\d{1,4}', i['t'].strip())]
    if not red_l or not blue_l or not nums:
        return None
    rl, bl = min(red_l, key=lambda i: i['y']), min(blue_l, key=lambda i: i['y'])
    if rl['x'] >= bl['x']:
        return None

    def total(label):
        cx = centre(label)[0]
        below = [i for i in nums if 0 < i['y'] - label['y'] < 0.25 and abs(centre(i)[0] - cx) < 0.12 and i['h'] > 1.5 * label['h']]
        return max(below, key=lambda i: i['h']) if below else None

    tr, tb = total(rl), total(bl)
    if tr is None or tb is None:
        return None
    out = {'red': {'total': int(tr['t']), 'total_item': tr}, 'blue': {'total': int(tb['t']), 'total_item': tb},
           'title_text': next((i['t'] for i in items if TITLE.search(i['t'])), None), 'components': None}
    heads = {up(i): i for i in items if up(i) in ('AUTO', 'TELEOP')}
    comp = rules.get('results_screen_components') or {}
    if 'AUTO' in heads and 'TELEOP' in heads and comp:
        rows = {'red': {}, 'blue': {}}
        for label in [i for i in items if up(i) in {w for ws in comp.values() for w in ws}]:
            ly = centre(label)[1]
            section = 'penalty' if up(label) in comp.get('penalty', []) else 'teleop' if ly > centre(heads['TELEOP'])[1] else 'auto' if ly > centre(heads['AUTO'])[1] else None
            if section is None:
                continue
            # Values sit in the breakdown column next to the label; team numbers and ranking points on the
            # same row are further out and must not fill in for a value the reader missed.
            same = [i for i in nums if abs(centre(i)[1] - ly) <= 0.7 * label['h'] and abs(centre(i)[0] - centre(label)[0]) <= 0.2 and i is not tr and i is not tb]
            left = [i for i in same if i['x'] + i['w'] <= label['x']]
            right = [i for i in same if i['x'] >= label['x'] + label['w']]
            for side, cands, pickf in (('red', left, max), ('blue', right, min)):
                if cands:
                    rows[side][section if section == up(label).lower() else '%s %s' % (section, up(label).lower())] = int(pickf(cands, key=lambda i: i['x'])['t'])
        out['components'] = rows   # fields that could not be read are simply absent (unknown)
    return out


def read_results_screen(frames, texts, rules, root):
    reads = []
    for t, p in frames:
        r = parse_results(texts.get(str(p), []), rules)
        if r:
            reads.append((t, str(Path(p).relative_to(root)), r))
    if not reads:
        return {'status': 'unknown', 'reason': 'no final results screen was found after the match'}
    key = lambda r: (r['red']['total'], r['blue']['total'])
    votes = Counter(key(r) for _, _, r in reads)
    best, n = votes.most_common(1)[0]
    chosen = [(t, f, r) for t, f, r in reads if key(r) == best]
    t, frame, r = chosen[0]
    fields = [sec if sec == lab.lower() else f'{sec} {lab.lower()}' for sec, labs in (rules.get('results_screen_components') or {}).items() for lab in labs]
    components, conflicts = {}, []
    for side in ('red', 'blue'):
        components[side] = {}
        for field in fields:
            seen = Counter(c[2]['components'][side][field] for c in chosen if c[2]['components'] and field in c[2]['components'][side])
            if seen:
                value, k = seen.most_common(1)[0]
                components[side][field] = {'value': value, 'frames': k}
                if len(seen) > 1:
                    conflicts.append({'side': side, 'field': field, 'readings': dict(seen)})
    complete = bool(fields) and all(len(components[s]) == len(fields) for s in components)
    checks = {s: (sum(v['value'] for v in components[s].values()) == best[i]) if fields and len(components[s])==len(fields) else None for i, s in enumerate(('red', 'blue'))}
    status = 'accepted' if n >= 2 and False not in checks.values() else 'uncertain'
    out = {'status': status, 'frames_agreeing': n, 'frames_read': len(reads), 'red': best[0], 'blue': best[1],
           'components': components if fields else None, 'components_complete': complete, 'component_conflicts': conflicts,
           'missing_components': {s: [f for f in fields if f not in components[s]] for s in components},
           'checksum_by_alliance':checks,'checksum_ok': False if False in checks.values() else None if not complete else True, 'title_text': r['title_text'],
           'evidence': [evidence(r['red']['total_item'], frame, t), evidence(r['blue']['total_item'], frame, t)],
           'disagreeing_reads': [{'t': round(tt, 2), 'frame': ff, 'red': rr['red']['total'], 'blue': rr['blue']['total']} for tt, ff, rr in reads if key(rr) != best][:10]}
    if status == 'uncertain':
        out['reason'] = 'seen in only one frame' if n < 2 else 'components do not add up to the total'
    return out


# ---------------------------------------------------------------- stage 3: bumper numbers

def roster_match(token, teams):
    """OCR token -> ('exact', team, None) | ('corrected', team, correction) | ('ambiguous', None, candidates) | None."""
    s = token.strip().strip('.,:;-•*#')
    if not re.fullmatch(r'[0-9A-Za-z|]{2,6}', s):
        return None
    if s.isdigit() and int(s) in teams:
        return ('exact', int(s), None)
    fixed = ''.join(LOOKALIKE.get(ch, ch) for ch in s)
    if not fixed.isdigit():
        return None
    changes = ['%s→%s' % (a, b) for a, b in zip(s, fixed) if a != b]
    if int(fixed) in teams:
        return ('corrected', int(fixed), 'lookalike characters ' + ', '.join(changes))
    if len(fixed) < 4:
        return None   # too short to correct safely
    found = set()
    for t in teams:
        ts = str(t)
        if len(ts) == len(fixed) and sum(a != b for a, b in zip(ts, fixed)) == 1:
            found.add(t)
        elif len(ts) == len(fixed) + 1 and any(ts[:k] + ts[k + 1:] == fixed for k in range(len(ts))):
            found.add(t)
        elif len(ts) + 1 == len(fixed) and any(fixed[:k] + fixed[k + 1:] == ts for k in range(len(fixed))):
            found.add(t)
    if len(found) == 1:
        team = found.pop()
        return ('corrected', team, 'one character: read %s as %d' % (s, team) + (' after ' + ', '.join(changes) if changes else ''))
    if len(found) > 1:
        return ('ambiguous', None, sorted(found))
    return None


def read_ppm(path):
    data = Path(path).read_bytes()
    fields, pos = [], 0
    while len(fields) < 4:
        while data[pos:pos + 1].isspace():
            pos += 1
        start = pos
        while not data[pos:pos + 1].isspace():
            pos += 1
        fields.append(data[start:pos])
    if fields[0] != b'P6' or fields[3] != b'255':
        raise FactsError('Unexpected colour sample format.')
    return int(fields[1]), int(fields[2]), data[pos + 1:]


def background_colour(image, box):
    """Classify the pixels around a number (box in fractions of the camera area) as red, blue or unclear."""
    width, height, pixels = image
    x, y, w, h = box
    x0, x1 = int(max(0, x - 0.25 * w) * width), int(min(1, x + 1.25 * w) * width)
    y0, y1 = int(max(0, y - 0.4 * h) * height), int(min(1, y + 1.4 * h) * height)
    red = blue = total = 0
    for row in range(y0, max(y0 + 1, y1)):
        base = row * width * 3
        for col in range(x0, max(x0 + 1, x1)):
            i = base + col * 3
            if i + 2 >= len(pixels):
                continue
            r, g, b = pixels[i], pixels[i + 1], pixels[i + 2]
            total += 1
            if r > 80 and r > 1.5 * g and r > 1.5 * b:
                red += 1
            elif b > 50 and b > 1.4 * r and b > 1.1 * g:   # includes dark navy bumpers
                blue += 1
    if not total:
        return 'unclear', {'red': 0.0, 'blue': 0.0}
    fr, fb = red / total, blue / total
    verdict = 'red' if fr >= 0.15 and fr >= 3 * fb else 'blue' if fb >= 0.15 and fb >= 3 * fr else 'unclear'
    return verdict, {'red': round(fr, 3), 'blue': round(fb, 3)}


def glyph_light(image, box):
    """Brightness and colour saturation of the brightest pixels inside a number's box. Bumper numbers are
    white (or black) on fabric; lit team-number displays (driver station signs) have bright saturated digits."""
    width, height, pixels = image
    x, y, w, h = box
    x0, x1 = int(max(0, x) * width), int(min(1, x + w) * width)
    y0, y1 = int(max(0, y) * height), int(min(1, y + h) * height)
    px = []
    for row in range(y0, max(y0 + 1, y1)):
        base = row * width * 3
        for col in range(x0, max(x0 + 1, x1)):
            i = base + col * 3
            if i + 2 < len(pixels):
                px.append((pixels[i], pixels[i + 1], pixels[i + 2]))
    if not px:
        return None
    px.sort(key=lambda p: -(p[0] + p[1] + p[2]))
    top = px[:max(1, len(px) // 7)]
    saturation = sum((max(p) - min(p)) / max(1, max(p)) for p in top) / len(top)
    brightness = sum(sum(p) / 3 for p in top) / len(top)
    return {'saturation': round(saturation, 3), 'brightness': round(brightness, 1),
            'lit_display': saturation >= DISPLAY_SATURATION and brightness >= DISPLAY_BRIGHTNESS}


def bumper_frames(video, work, top, start, end, every):
    start = max(0.0, start)   # a video that begins after auto started has a negative auto start
    folder = work / 'bumpers'
    clear(folder, '*')
    n = len(TILES)
    fc = '[0:v]fps=%g:round=up,crop=iw:ih*%g:0:ih*%g,split=%d%s' % (1 / every, 1 - top, top, n + 1, ''.join('[s%d]' % i for i in range(n + 1)))
    for i, (x0, x1, y0, y1) in enumerate(TILES):
        fc += ';[s%d]crop=iw*%g:ih*%g:iw*%g:ih*%g,scale=2*trunc(iw):-2[t%d]' % (i, x1 - x0, y1 - y0, x0, y0, i)
    # Full-resolution colour sample; one frame is held in memory at a time. The scale filter keeps the RGB
    # conversion inside this branch: a bare format=rgb24 is negotiated back through split and changes the tiles.
    fc += ';[s%d]scale=iw:ih,format=rgb24[c]' % n
    # -t before -i limits the input, so every output stops at `end` (after -i it would bind only to the first output).
    args = ['-ss', '%.3f' % start, '-t', '%.3f' % max(0.1, end - start), '-i', str(video), '-filter_complex', fc]
    for i in range(n):
        args += ['-map', '[t%d]' % i, '-q:v', '3', str(folder / ('k%d_%%05d.jpg' % i))]
    args += ['-map', '[c]', str(folder / 'c_%05d.ppm')]
    ffmpeg(args)
    out = []
    for i in range(n):
        for k, (t, p) in enumerate(frames_at(folder, 'k%d_*.jpg' % i, start, 1 / every)):
            out.append((t, i, p, folder / ('c_%05d.ppm' % (k + 1))))
    return out


def decide_identities(raw, roster, closeup_top=None):
    """Per-team decision from raw reads. Confirmed needs exact reads on the team's alliance colour in two
    separate frames (several crops of one frame count once), at least one of them in a close-up camera view
    when the layout has one (driver-station number displays appear only in the wide shot). Corrected reads,
    unclear colours, single frames and wide-shot-only reads stay candidates; reads on the other alliance colour
    or on a recognised lit display are rejected."""
    alliance = {t: 'red' for t in roster['red']}
    alliance.update({t: 'blue' for t in roster['blue']})
    identities = {}
    for team in roster['red'] + roster['blue']:
        reads = [r for r in raw if r.get('team') == team]
        verified = [r for r in reads if r['kind'] == 'exact' and r.get('background') == alliance[team]]
        frames = sorted({r['t'] for r in verified})
        closeup = [r for r in verified if closeup_top is None or r['frame_box'][1] + r['frame_box'][3] / 2 >= closeup_top]
        wrong = [r for r in reads if r.get('background') in ('red', 'blue') and r['background'] != alliance[team]]
        display = [r for r in reads if r.get('background') == 'lit_display']
        usable = [r for r in reads if r not in wrong and r not in display]
        if len(frames) >= 2 and closeup:
            status = 'confirmed'
        elif usable:
            status = 'candidate'
        else:
            status = 'not_identified'
        reasons = []
        if status != 'confirmed':
            if verified and len(frames) < 2:
                reasons.append('exact number on a %s bumper in only %d frame' % (alliance[team], len(frames)))
            elif verified and not closeup:
                reasons.append('seen only in the wide field shot, where driver-station number displays also appear; needs a close-up camera read')
            if any(r['kind'] == 'corrected' for r in usable):
                reasons.append('%d corrected read(s), which stay candidates' % sum(r['kind'] == 'corrected' for r in usable))
            if any(r['kind'] == 'exact' and r.get('background') == 'unclear' for r in usable):
                reasons.append('exact reads where the bumper colour was unclear')
        if wrong:
            reasons.append('%d read(s) on the other alliance colour, rejected' % len(wrong))
        if display:
            reasons.append('%d read(s) from a lit number display (not a robot), rejected' % len(display))
        rep = []
        ordered = (closeup + [r for r in verified if r not in closeup]) if verified else usable
        for r in sorted(ordered, key=lambda r: (r not in closeup, -(r.get('confidence') or 0), r['t'])):
            if r['t'] not in {x['t'] for x in rep}:
                rep.append(r)
            if len(rep) == 4:
                break
        identities[team] = {'status': status, 'alliance': alliance[team], 'verified_frames': frames,
                            'exact_reads': sum(r['kind'] == 'exact' for r in reads), 'corrected_reads': sum(r['kind'] == 'corrected' for r in reads),
                            'display_reads': len(display), 'distinct_frames_any_read': len({r['t'] for r in reads}), 'reasons': reasons,
                            'evidence': sorted(rep, key=lambda r: r['t'])}
    return identities


def read_bumpers(video, work, backend, roster, start, end, every=2.0, top=0.24, closeup_top=None):
    """Raw bumper reads (every roster-like token, with evidence) and per-team identity decisions."""
    teams = set(roster['red'] + roster['blue'])
    alliance = {t: 'red' for t in roster['red']}
    alliance.update({t: 'blue' for t in roster['blue']})
    tiles = bumper_frames(video, work, top, start, end, every)
    texts = backend.recognize([p for _, _, p, _ in tiles])
    save_raw(work / 'raw-bumpers.jsonl', [(t, p) for t, _, p, _ in tiles], texts, work)
    raw, loaded = [], (None, None)
    for t, i, p, ppm in sorted(tiles, key=lambda x: (x[0], x[1])):
        x0, x1, y0, y1 = TILES[i]
        for item in texts.get(str(p), []):
            for token in re.findall(r'\S+', item['t']):
                m = roster_match(token, teams)
                if not m:
                    continue
                fb = [x0 + item['x'] * (x1 - x0), y0 + item['y'] * (y1 - y0), item['w'] * (x1 - x0), item['h'] * (y1 - y0)]
                rec = evidence(item, str(p.relative_to(work)), t, correction=m[2] if m[0] == 'corrected' else None,
                               token=token, kind=m[0], team=m[1], view=TILE_NAMES[i], frame_box=[round(v, 4) for v in fb])
                if m[0] == 'ambiguous':
                    rec['reason'] = 'matches more than one roster team: %s' % ', '.join(map(str, m[2]))
                    rec['correction'] = None
                else:
                    if loaded[0] != ppm:   # one colour sample in memory at a time
                        loaded = (ppm, read_ppm(ppm) if ppm.is_file() else None)
                    if loaded[1]:
                        rec['background'], rec['background_share'] = background_colour(loaded[1], fb)
                        rec['glyph'] = glyph_light(loaded[1], fb)
                        if rec['glyph'] and rec['glyph']['lit_display']:
                            rec['background'] = 'lit_display'
                            rec['reason'] = 'bright coloured digits like a lit team-number display (driver station sign), not a bumper'
                    else:
                        rec['background'], rec['background_share'] = 'unclear', None
                    rec['alliance'] = alliance[m[1]]
                raw.append(rec)
    for ppm in {c for _, _, _, c in tiles}:
        ppm.unlink(missing_ok=True)   # colour samples are large; the JPEG crops remain as evidence
    identities = decide_identities(raw, roster, closeup_top)
    rejected = [r for r in raw if r['kind'] == 'ambiguous' or r.get('background') == 'lit_display'
                or (r.get('background') in ('red', 'blue') and r['background'] != r.get('alliance'))]
    return {'identities': identities, 'reads': raw, 'rejected': rejected, 'frames_sampled': len({t for t, *_ in tiles}), 'every_s': every}


# ---------------------------------------------------------------- pipeline

def refresh_score_timeline(facts, samples, match):
    periods=facts['periods'];duration=facts['source']['duration_s'];results=facts['results_screen']
    first=periods['auto'] or periods['teleop'];last=periods['teleop'] or periods['auto']
    match_t0=first['start'];match_t1=min(last['end'],duration)
    scores, facts['rejected']['score_readings'], facts['score_decreases'] = {}, {}, {}
    for side in ('red', 'blue'):
        reads = [s[side] for s in samples if side in s and match_t0 - 5 <= s['t'] <= duration]
        acc, rej, dec = filter_scores(reads)
        scores[side] = acc
        facts['rejected']['score_readings'][side] = rej
        facts['score_decreases'][side] = dec
    facts['accepted_scores'] = {side: [dict(r['ev'], value=r['value']) for r in scores[side]] for side in ('red', 'blue')}
    facts['coverage'] = {side: coverage(scores[side], match_t0, match_t1) for side in ('red', 'blue')}
    tele, auto, eg = periods['teleop'], periods['auto'], facts['endgame']
    displayed = {}
    for side in ('red', 'blue'):
        d = {}
        if tele:
            d['at_teleop_start'] = value_at(scores[side], tele['start'], tele['uncertainty_s'])
            d['at_endgame_start'] = value_at(scores[side], eg['start'], eg['uncertainty_s'])
            if tele['end_observed']:
                d['at_teleop_end'] = value_at(scores[side], tele['end'], tele['uncertainty_s'])
            else:
                d['at_teleop_end'] = {'value': None, 'status': 'unknown', 'reason': 'the video ends before teleop ends', 'evidence': []}
            d['teleop_increase_before_endgame'] = difference(d['at_teleop_start'], d['at_endgame_start'])
            d['endgame_increase'] = difference(d['at_endgame_start'], d['at_teleop_end'])
        if auto and auto['start_observed']:
            d['at_auto_start'] = value_at(scores[side], auto['start'], auto['uncertainty_s'])
        after = [r for r in scores[side] if r['t'] >= last['end']]
        last_live = after[-1] if after else (scores[side][-1] if scores[side] else None)
        d['last_live'] = {'value': last_live['value'], 't': last_live['t'], 'status': 'accepted', 'evidence': [last_live['ev']]} if last_live else {'value': None, 'status': 'unknown', 'evidence': []}
        d['largest_15s_increase'] = largest_increase(scores[side], match_t0, match_t1)
        displayed[side] = d
    facts['displayed'] = displayed
    facts['lead'] = lead_changes(scores['red'], scores['blue'], match_t0, match_t1)
    if results.get('status') != 'unknown':
        facts['reconciliation'] = {side: {'last_live': displayed[side]['last_live']['value'], 'results_screen': results[side],
                                          'difference': None if displayed[side]['last_live']['value'] is None else results[side] - displayed[side]['last_live']['value']}
                                   for side in ('red', 'blue')}
        if match.get('official'):
            for side in ('red', 'blue'):
                facts['reconciliation'][side]['official'] = match['official'].get(side)


def recognize_with_progress(backend, paths, stage, label):
    """Bound OCR batches so a long match reports progress instead of appearing stuck."""
    paths=list(paths)
    batch_size=16 if getattr(backend,'name',None)=='rapidocr' else 48
    results={}
    for start in range(0,len(paths),batch_size):
        end=min(start+batch_size,len(paths))
        stage[0]='%s · frames %d–%d of %d'%(label,start+1,end,len(paths))
        results.update(backend.recognize(paths[start:end]))
    return results


def analyze_video(video, match, work, stage=None, duration=None, metadata_title=None, backend=None, every=2.0, source_sha256=None):
    """Returns the facts dict. Raises FactsError for unsupported layouts, wrong videos or unreadable clocks."""
    stage = stage if stage is not None else ['']
    work = Path(work)
    backend = backend or neptune_ocr.select_backend()
    season, rules = season_rules(match.get('key'))
    roster = match['roster']
    if duration is None:
        duration = float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', str(video)], timeout=30))
    facts = {'pipeline': PIPELINE_VERSION, 'source': {'sha256': source_sha256, 'duration_s': round(duration, 3), 'metadata_title': metadata_title},
             'ocr': backend.describe(), 'season': season, 'rules': {k: rules[k] for k in ('game', 'auto_seconds', 'teleop_seconds', 'endgame', 'source')}}
    # ---- Stage 1: overlay once per second ----
    stage[0] = 'Extracting scoreboard frames'
    layouts=rules.get('layout_profiles',LAYOUTS)
    if not layouts:raise FactsError('%s rules are configured, but its broadcast reader needs a validated layout profile. Import a calibrated game pack; another season’s layout will not be reused.' % rules['game'])
    layouts=dict(layouts,**{k:v for k,v in EXTRA_LAYOUTS.items() if v['season']==season and k not in layouts})
    band_crop = max(p['band'] for p in layouts.values())
    folder = work / 'overlay'
    clear(folder, 'ov_*.jpg')
    ffmpeg(['-i', str(video), '-vf', 'fps=1:round=up,crop=iw:ih*%g:0:0' % band_crop, '-q:v', '3', str(folder / 'ov_%05d.jpg')])
    band = frames_at(folder, 'ov_*.jpg', 0.0, 1.0)
    texts = recognize_with_progress(backend,[p for _, p in band],stage,'Reading the scoreboard')
    save_raw(work / 'raw-overlay.jsonl', band, texts, work)
    try:
        layout_id, profile, layout_stats = detect_layout(band, texts, season, layouts, roster)
        samples, clock_rejects = overlay_samples(band, texts, profile, rules, work)
        facts['score_rereads'] = reread_scores(samples, profile, backend, work)
    except FactsError:
        # No profile matched: read the clock and scores by position, sides from the roster. Identity checks are unchanged.
        found = generic_layout(band, texts, roster, band_crop)
        if not found:
            raise
        sides, top, layout_stats = found
        layout_id, profile = 'generic-' + sides, {'camera_top': top}
        samples, clock_rejects = generic_samples(band, texts, sides, rules, work)
    facts['layout'] = dict(layout_stats, profile=layout_id,camera_top=profile['camera_top'])
    clocked = [s for s in samples if 'clock' in s]
    periods, period_rejects = anchor_periods(clocked, rules, duration)
    facts['periods'] = periods
    facts['endgame'] = endgame_boundary(periods, samples, rules)
    facts['rejected'] = {'clock_readings': clock_rejects, 'clock_sequences': period_rejects}
    first = periods['auto'] or periods['teleop']
    last = periods['teleop'] or periods['auto']
    match_t0, match_t1 = first['start'], min(last['end'], duration)
    # ---- Final results screen (needed for the identity check, so read before deciding) ----
    stage[0] = 'Reading the results screen'
    rfolder = work / 'results'
    clear(rfolder, 'rs_*.jpg')
    rs_start = last['end'] + 2
    results = {'status': 'unknown', 'reason': 'the video ends before a results screen could appear'}
    if duration - rs_start > 1:
        ffmpeg(['-ss', '%.3f' % rs_start, '-i', str(video), '-vf', 'fps=1:round=up', '-q:v', '3', str(rfolder / 'rs_%05d.jpg')])
        rframes = frames_at(rfolder, 'rs_*.jpg', rs_start, 1.0)
        rtexts = recognize_with_progress(backend,[p for _, p in rframes],stage,'Reading the results screen')
        save_raw(work / 'raw-results.jsonl', rframes, rtexts, work)
        results = read_results_screen(rframes, rtexts, rules, work)
    facts['results_screen'] = results
    facts['identity'] = verify_identity(samples, match, metadata_title, results if results.get('title_text') else None)
    stage[0] = 'Building the scoring timeline'
    refresh_score_timeline(facts, samples, match)
    # ---- Stage 3: bumper numbers ----
    stage[0] = 'Reading bumper numbers'
    facts['bumpers'] = read_bumpers(video, work, backend, roster, match_t0, match_t1, every, profile['camera_top'], profile.get('closeup_top'))
    facts['timeline_samples'] = len(band)
    return facts


# ---------------------------------------------------------------- payload and summary

def phase_at(facts, t):
    p = facts['periods']
    if p['auto'] and t < p['auto']['end'] + 0.5:
        return 'Auto ' + clock_text(p['auto']['end'] - t)
    if p['teleop'] and t < p['teleop']['start']:
        return 'between periods'
    if p['teleop']:
        return 'Teleop ' + clock_text(p['teleop']['end'] - t)
    return 'video ' + mmss(t)


def ev_note(evs):
    parts = []
    for e in evs[:2]:
        parts.append('%s at video %s, OCR "%s"%s%s' % (e['frame'], mmss(e['t']), e['text'],
                     '' if e.get('confidence') is None else ' conf %.2f' % e['confidence'], ', ' + e['correction'] if e.get('correction') else ''))
    return ' Evidence: ' + '; '.join(parts) + '.' if parts else ''


def build_payload(facts, match, source_sha256, duration_ms):
    """Schema v1 submission. Uncertain or partial facts are submitted as 'uncertain'; unknown facts are stated as unknown."""
    roster = match['roster']
    ms = lambda t: max(0, min(duration_ms, int(round(t * 1000))))
    obs = []

    def add(a, b, note, team=None, certain=True):
        obs.append({'start_ms': ms(a), 'end_ms': max(ms(a), ms(b)), 'team': team, 'certainty': 'observed' if certain else 'uncertain', 'note': note[:2000]})

    P, idn = facts['periods'], facts['identity']
    first = P['auto'] or P['teleop']
    last = P['teleop'] or P['auto']
    c = idn['checks']
    add(first['start'], last['end'], 'Match identity %s: overlay title %s; roster %s; event/division %s.%s' % (
        idn['status'], c['match_number']['status'], c['roster']['status'], c['event']['status'],
        (' Not verified: ' + ', '.join(idn['missing']) + '.') if idn['missing'] else ''), certain=idn['status'] == 'verified')
    for name, p in P.items():
        if p:
            add(p['start'], p['end'], '%s period anchored from %d clock readings: video %s to %s (±%.1f s; %d s by the %d season rules; start %s, end %s).' % (
                name.capitalize(), p['clock_readings'], mmss(p['start']), mmss(p['end']), p['uncertainty_s'], p['length_rule'], facts['season'],
                'seen' if p['start_observed'] else 'not seen, inferred from the rule length', 'seen' if p['end_observed'] else 'not seen'),
                certain=p['start_observed'] and p['end_observed'])
        else:
            add(0, 0, '%s period not found in this video; its facts are unknown.' % name.capitalize(), certain=False)
    D = facts['displayed']
    for key, label in (('at_teleop_start', 'when teleop began'), ('at_endgame_start', 'when the endgame began (%s)' % (facts['endgame'] or {}).get('rule', '')),
                       ('at_teleop_end', 'at the end of teleop')):
        r, b = D['red'].get(key), D['blue'].get(key)
        if not r:
            continue
        t = {'at_teleop_start': P['teleop']['start'], 'at_endgame_start': facts['endgame']['start'], 'at_teleop_end': P['teleop']['end']}[key]
        add(t, t, 'Displayed score %s: Red %s, Blue %s.%s' % (label, fmt(r), fmt(b), ev_note(r['evidence'] + b['evidence'])),
            certain=r['status'] == b['status'] == 'accepted')
    for side in ('red', 'blue'):
        inc = D[side].get('largest_15s_increase')
        if inc:
            add(inc['start'], inc['end'], 'Largest 15-second rise in the displayed %s score: +%d (%d to %d), %s to %s. This is a scoreboard change, not points credited to a robot.' % (
                side.capitalize(), inc['value'], inc['from'], inc['to'], phase_at(facts, inc['start']), phase_at(facts, inc['end'])))
        for dcr in facts['score_decreases'][side]:
            add(dcr['t'], dcr['t'], 'The displayed %s score decreased from %d to %d and later readings kept the lower value.%s' % (side.capitalize(), dcr['from'], dcr['to'], ev_note(dcr['evidence'])))
    L = facts['lead']
    for ch in L['changes'][:8]:
        add(ch['t'], ch['t'], '%s took the displayed lead at %s (Red %d, Blue %d)%s.' % (ch['leader'].capitalize(), phase_at(facts, ch['t']), ch['red'], ch['blue'],
            ', after an unreadable stretch, so the exact time is uncertain' if ch['after_gap'] else ''), certain=not ch['after_gap'])
    for a, b in L['unreadable_intervals']:
        add(a, b, 'Scores were unreadable from %s to %s; lead changes in this stretch would be missed.' % (phase_at(facts, a), phase_at(facts, b)), certain=False)
    R = facts['results_screen']
    if R.get('status') != 'unknown':
        comp = ''
        if R.get('components'):
            comp = ' Components on screen: Red %s; Blue %s.' % (breakdown(R, 'red'), breakdown(R, 'blue'))
        t = R['evidence'][0]['t']
        add(t, t, 'Final results screen: Red %d, Blue %d (%d agreeing frame%s, arithmetic check %s).%s%s' % (
            R['red'], R['blue'], R['frames_agreeing'], '' if R['frames_agreeing'] == 1 else 's',
            '; '.join(side.capitalize()+' '+{True:'passed',False:'failed',None:'unknown'}[R.get('checksum_by_alliance',{}).get(side)] for side in ('red','blue')), comp, ev_note(R['evidence'])), certain=R['status'] == 'accepted')
    for team, ident in sorted(facts['bumpers']['identities'].items()):
        if ident['status'] == 'not_identified':
            continue
        e = ident['evidence']
        if ident['status'] == 'confirmed':
            add(e[0]['t'], e[-1]['t'] + 1, 'Robot %d confirmed by its bumper number on a %s bumper in %d separate frames (%s to %s).%s' % (
                team, ident['alliance'], len(ident['verified_frames']), phase_at(facts, ident['verified_frames'][0]), phase_at(facts, ident['verified_frames'][-1]), ev_note(e)), team=team)
        else:
            add(e[0]['t'], e[-1]['t'] + 1, 'Unconfirmed candidate for robot %d: %s.%s' % (team, '; '.join(ident['reasons']) or 'not enough evidence', ev_note(e)), team=team, certain=False)
    obs.sort(key=lambda o: (o['start_ms'], o['end_ms']))
    o = facts['ocr']
    runtime = ' '.join('%s %s' % (k, v) for k, v in o.items() if k != 'models' and k != 'backend')
    return {'schema_version': 1, 'source_sha256': source_sha256, 'summary': readable_summary(facts, roster),
            'model': 'ocr:' + o['backend'], 'runtime': ('local %s %s' % (o['backend'], runtime))[:200], 'prompt_version': PIPELINE_VERSION,
            'inference_location': 'local', 'observations': obs[:1000]}


def breakdown(R, side):
    have = R['components'][side]
    return ', '.join(['%s %d' % (k, v['value']) for k, v in have.items()] + ['%s unknown' % k for k in R['missing_components'][side]])


def fmt(v):
    if v is None or v.get('value') is None:
        return 'unknown'
    if v['status'] == 'uncertain' and v.get('range'):
        return '%d (between %d and %d around the boundary)' % (v['value'], v['range'][0], v['range'][1])
    return str(v['value'])


def summarize(facts, roster):
    """Plain-language summary from accepted facts only; unknown or uncertain facts are worded as such."""
    idn, P, D, R, L = facts['identity'], facts['periods'], facts['displayed'], facts['results_screen'], facts['lead']
    title = idn['checks']['match_number'].get('overlay_title') or 'This match'
    parts = ['%s: Red %s vs Blue %s.' % (title, ', '.join(map(str, roster['red'])), ', '.join(map(str, roster['blue'])))]
    if idn['status'] != 'verified':
        parts.append('Match identity is only partly verified (not confirmed: %s).' % ', '.join(n.replace('_', ' ') for n in idn['missing']))
    if R.get('status') == 'accepted':
        w = 'Red' if R['red'] > R['blue'] else 'Blue' if R['blue'] > R['red'] else None
        s = ('%s won %d–%d on the final results screen.' % (w, max(R['red'], R['blue']), min(R['red'], R['blue']))) if w else 'The final results screen shows a %d–%d tie.' % (R['red'], R['blue'])
        if R.get('components'):
            s += ' Its breakdown%s: Red %s; Blue %s.' % (' (adds up to the totals)' if R.get('checksum_ok') else ' (partly unreadable; unreadable fields are unknown)',
                                                       *(breakdown(R, x) for x in ('red', 'blue')))
        parts.append(s)
    elif R.get('status') == 'uncertain':
        parts.append('A results screen showing Red %d, Blue %d was read but not confirmed (%s).' % (R['red'], R['blue'], R.get('reason')))
    else:
        parts.append('No final results screen was readable, so the final score is unknown from this video.')
    for key, label in (('at_teleop_start', 'When teleop began'), ('at_teleop_end', 'At the end of teleop')):
        r, b = D['red'].get(key), D['blue'].get(key)
        if r and b and (r['value'] is not None or b['value'] is not None):
            parts.append('%s the scoreboard showed Red %s, Blue %s.' % (label, fmt(r), fmt(b)))
    if R.get('status') == 'accepted':
        diffs = [(s, facts['reconciliation'][s]['difference']) for s in ('red', 'blue') if facts['reconciliation'][s]['difference']]
        if diffs:
            parts.append('The results screen differs from the last live scoreboard reading (%s); this pipeline does not determine why beyond the breakdown shown on the results screen.' % ', '.join('%s %+d' % (s.capitalize(), d) for s, d in diffs))
    runs = [(s, D[s]['largest_15s_increase']) for s in ('red', 'blue') if D[s].get('largest_15s_increase')]
    if runs:
        parts.append('Largest 15-second rise in the displayed score: ' + '; '.join('%s +%d (%s to %s)' % (s.capitalize(), r['value'], phase_at(facts, r['start']), phase_at(facts, r['end'])) for s, r in runs) + '.')
    n = len(L['changes'])
    readable = L['readable_share']
    if readable >= 0.999:
        parts.append(('The displayed lead changed %d time%s.' % (n, '' if n == 1 else 's')) if n else 'No lead changes were detected; scores were readable throughout.')
    else:
        parts.append('%s in the readable samples (%.0f%% of the match; unreadable stretches could hide others).' % (
            ('%d lead change%s detected' % (n, '' if n == 1 else 's')) if n else 'No lead changes were detected', readable * 100))
    for s in ('red', 'blue'):
        if facts['score_decreases'][s]:
            parts.append('The displayed %s score decreased %d time%s during the match.' % (s.capitalize(), len(facts['score_decreases'][s]), '' if len(facts['score_decreases'][s]) == 1 else 's'))
    ids = facts['bumpers']['identities']
    confirmed = [t for t in roster['red'] + roster['blue'] if ids.get(t, {}).get('status') == 'confirmed']
    if confirmed:
        parts.append('Robots confirmed by bumper number on camera: %s.' % ', '.join(map(str, confirmed)))
    rest = [t for t in roster['red'] + roster['blue'] if t not in confirmed]
    if rest:
        parts.append('Not confirmed on camera: %s.' % ', '.join(map(str, rest)))
    gaps = [g for s in ('red', 'blue') for g in facts['coverage'][s]['gaps']]
    if gaps:
        parts.append('Scoreboard coverage gaps: %s.' % ', '.join('%s–%s' % (phase_at(facts, a), phase_at(facts, b)) for a, b in sorted(set(map(tuple, gaps)))[:4]))
    if not (P['auto'] and P['auto']['start_observed']) or not (P['teleop'] and P['teleop']['end_observed']):
        parts.append('The video does not show the whole match, so some periods are unknown.')
    parts.append('Scores are alliance scoreboard readings; this pipeline does not assign points to individual robots.')
    return ' '.join(parts)


def readable_summary(facts,roster):
    """Reader-facing narrative; the original full evidence report stays available in facts/evidence."""
    R=facts['results_screen'];P=facts['periods'];D=facts['displayed'];L=facts['lead'];parts=[]
    title=facts['identity']['checks']['match_number'].get('overlay_title') or 'This match'
    parts.append('%s: Red %s vs Blue %s.'%(title,', '.join(map(str,roster['red'])),', '.join(map(str,roster['blue']))))
    if R.get('status')=='accepted':
        if R['red']==R['blue']:parts.append('The final results screen shows a %d–%d tie.'% (R['red'],R['blue']))
        else:parts.append('%s won %d–%d on the final results screen.'%('Red' if R['red']>R['blue'] else 'Blue',max(R['red'],R['blue']),min(R['red'],R['blue'])))
    else:parts.append('The final result could not be confirmed from this clip.')
    tele=P.get('teleop')
    if tele and tele['start']<0:
        parts.append('The clip begins with %s remaining in teleop; autonomous and the opening %d seconds of teleop are not included.'%(clock_text(tele['end']),round(-tele['start'])))
    elif not P.get('auto'):parts.append('Autonomous was not detected; whether the clip includes it is unresolved.')
    r=D['red'].get('at_teleop_start');b=D['blue'].get('at_teleop_start')
    if tele and tele.get('start_observed') and r and b and r.get('value') is not None and b.get('value') is not None:parts.append('Entering teleop, the displayed score was Red %d, Blue %d.'%(r['value'],b['value']))
    if L.get('changes'):
        last=L['changes'][-1];parts.append('%d lead changes were visible in readable scoreboard samples. %s took the last observed lead at %s.'%(len(L['changes']),last['leader'].capitalize(),phase_at(facts,last['t'])))
    if facts['identity']['status']!='verified':parts.append('The event or match identity remains only partly verified.')
    parts.append('Robot actions and individual scoring contributions are unknown unless supported by separate tag evidence.')
    return ' '.join(parts)


def replay_alliance_layout(previous, match, rules, source_sha256):
    """Reparse immutable OCR for the known layout correction; camera geometry and phase lengths must match."""
    import copy
    previous=Path(previous);old=json.loads(previous.read_text())
    if old.get('source',{}).get('sha256')!=source_sha256 or old.get('layout',{}).get('profile') not in ('frc-2026-week0-overlay-v1','frc-2026-field-overlay-v1','frc-2026-age-blue-left-v1'):return None
    if old.get('rules',{}).get('auto_seconds')!=rules['auto_seconds'] or old.get('rules',{}).get('teleop_seconds')!=rules['teleop_seconds']:return None
    raw=previous.parent/'raw-overlay.jsonl'
    if not raw.is_file():return None
    rows=[json.loads(line) for line in raw.read_text().splitlines() if line.strip()]
    frames=[(r['t'],previous.parent/r['frame']) for r in rows];texts={str(previous.parent/r['frame']):r['items'] for r in rows}
    pid,profile,stats=detect_layout(frames,texts,2026,rules['layout_profiles'],match.get('roster'))
    if pid not in ('frc-2026-age-blue-left-v1',old['layout']['profile']) or profile['camera_top']!=old['layout']['camera_top']:return None
    facts=copy.deepcopy(old);samples,rejects=overlay_samples(frames,texts,profile,rules,previous.parent)
    facts['layout']=dict(stats,profile=pid,camera_top=profile['camera_top']);facts['pipeline']=PIPELINE_VERSION
    results=facts['results_screen'];facts['identity']=verify_identity(samples,match,old['source'].get('metadata_title'),results if results.get('title_text') else None)
    facts['rejected']['clock_readings']=rejects;facts['rules']=rules
    facts['periods'],facts['rejected']['clock_sequences']=anchor_periods([s for s in samples if 'clock' in s],rules,facts['source']['duration_s'])
    facts['endgame']=endgame_boundary(facts['periods'],samples,rules)
    refresh_score_timeline(facts,samples,match)
    facts['cache_replay']={'kind':'verified-alliance-layout-reparse','original_facts':str(previous),'original_pipeline':old.get('pipeline'),'raw_ocr_unchanged':True}
    return facts
