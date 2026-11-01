"""Learned scoreboard layouts: overlays the generic reader has settled, remembered by how they look.

A broadcast no layout profile matches is read by position (neptune_facts.parse_overlay_generic). Its sides
(red left or right) come from a vote of roster numbers and RED/BLUE words. When that vote passes its gates
and the match reads cleanly, the overlay is remembered here: a small colour thumbnail of the scoreboard
band, the clock's position and the sides. Later videos with the same overlay can take the sides from here
when their own vote is too thin, e.g. a broadcast that never shows the team numbers.

An entry is provisional until a second, different match confirms it with its own passing vote. Only
confirmed entries ever decide sides, and a learned entry never overrides a passing vote that disagrees; the
disagreement is recorded instead. Entries are kept apart from the game pack, so learning one leaves the
game fingerprint (and every model trained on it) unchanged. Nothing here names a season or overlay design.
"""
import json
import os
import subprocess
import time
from pathlib import Path

HOME = Path.home() / '.neptune-helper'
THUMB = (24, 4)          # columns x rows of the colour thumbnail of the scoreboard band
SIGNATURE_FRAMES = 8     # band frames averaged into one signature
MATCH_DISTANCE = 18.0    # mean absolute RGB difference (0-255) under which two thumbnails are one overlay
CLOCK_SHIFT = 0.04       # largest clock-centre move (fraction of the band) for the same overlay
CONFIRM_MATCHES = 2


def folder(home=None):
    if home is None:
        home = Path(os.environ['NEPTUNE_GAMES_HOME']) if os.environ.get('NEPTUNE_GAMES_HOME') else HOME
    return Path(home) / 'learned-layouts'


def thumbnail(path):
    w, h = THUMB
    raw = subprocess.check_output(['ffmpeg', '-nostdin', '-v', 'error', '-i', str(path), '-vf', 'scale=%d:%d:flags=area' % (w, h),
                                   '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], timeout=60)
    if len(raw) != w * h * 3:
        raise ValueError('Unexpected thumbnail size for %s.' % path)
    return list(raw)


def signature(paths):
    """Average thumbnail of up to SIGNATURE_FRAMES band frames, spread over the given ones."""
    paths = list(paths)
    if not paths:
        return None
    step = max(1, len(paths) // SIGNATURE_FRAMES)
    thumbs = [thumbnail(p) for p in paths[::step][:SIGNATURE_FRAMES]]
    return [round(sum(v) / len(thumbs)) for v in zip(*thumbs)]


def distance(a, b):
    return sum(abs(x - y) for x, y in zip(a, b)) / len(a)


def load(season, home=None):
    path = folder(home) / ('%s.json' % season)
    return json.loads(path.read_text())['layouts'] if path.is_file() else []


def save(season, entries, home=None):
    target = folder(home) / ('%s.json' % season)
    target.parent.mkdir(parents=True, exist_ok=True)
    tmp = target.with_suffix('.tmp')
    tmp.write_text(json.dumps({'season': int(season), 'layouts': entries}, indent=1))
    tmp.replace(target)


def clock_centre(box):
    return box[0] + box[2] / 2, box[1] + box[3] / 2


def find(season, sig, clock, home=None):
    """The nearest learned entry that looks like this overlay and has its clock in the same place, or None."""
    if sig is None or clock is None:
        return None
    cx, cy = clock_centre(clock)
    best = None
    for e in load(season, home):
        if len(e['signature']) != len(sig):
            continue
        ex, ey = clock_centre(e['clock'])
        d = distance(e['signature'], sig)
        if d <= MATCH_DISTANCE and abs(ex - cx) <= CLOCK_SHIFT and abs(ey - cy) <= CLOCK_SHIFT and (best is None or d < best[0]):
            best = (d, e)
    return dict(best[1], distance=round(best[0], 2)) if best else None


def confirmed(entry):
    return bool(entry) and len(entry['matches']) >= CONFIRM_MATCHES


def learn(season, sig, clock, sides, camera_top, match_key, home=None):
    """Record a clean generic read whose sides came from its own passing vote. Returns the entry.

    The same overlay gains the match; a disagreeing vote is recorded as a conflict and the entry stops
    deciding sides. A new overlay starts provisional."""
    entries = load(season, home)
    known = find(season, sig, clock, home)
    now = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    if known:
        e = next(x for x in entries if x['id'] == known['id'])
        if e['sides'] != sides:
            e.setdefault('conflicts', [])
            if match_key not in e['conflicts']:
                e['conflicts'].append(match_key)
        elif match_key not in e['matches']:
            e['matches'].append(match_key)
        e['updated'] = now
    else:
        e = {'id': 'learned-%s-%d' % (season, len(entries) + 1), 'season': int(season), 'sides': sides,
             'clock': [round(v, 4) for v in clock], 'camera_top': camera_top, 'signature': sig,
             'matches': [match_key], 'conflicts': [], 'learned': now, 'updated': now}
        entries.append(e)
    save(season, entries, home)
    return e


def usable(entry):
    """Only a confirmed entry without conflicts may decide sides."""
    return confirmed(entry) and not entry.get('conflicts')
