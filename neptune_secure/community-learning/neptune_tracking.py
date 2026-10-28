"""Robot tracking in the broadcast wide field shot. Pure Python + FFmpeg; no numpy, no AI model.

Stage A  Decode the wide shot (between the overlay and the close-up cameras) at a few frames per second as
         YUV planes. Classify pixels as red bumper, blue bumper or fuel with byte lookup tables and big-integer
         masks, so the per-pixel work runs inside CPython's C code.
Stage B  Background: pixels red or blue in a fifth of the frames or more are static field elements (hub
         ramps, alliance walls, driver-station signs) and are ignored. The field floor is the band of rows where
         fuel comes and goes; the audience above it is ignored. Static hub ramps give each alliance's hub line.
Stage C  Moving red/blue blobs on the field, re-joined where a bumper number splits them and shaped like a
         bumper strip, become detections; detections are linked into tracks by colour, distance and size.
Stage D  Identities: the facts pipeline's wide-shot bumper reads, plus OCR of each track's own bumper crop once a
         second, vote for tracks (once per moment); a track needs two moments. Each team holds at most one track
         at a time. Unread fragments that continue an identified one inherit it; short gaps are bridged.
Stage E  Positions relative to the robot's alliance: own zone / neutral / opponent zone from the two hub lines,
         and along-field distance to the own hub line.

Times are video seconds. Detections are in work pixels (640 px wide); track_match() reports fractions.
"""
import math
import re
import subprocess
from array import array
from collections import Counter, defaultdict
from pathlib import Path

FRAME_DECODER_REVISION = 'explicit-rawvideo-geometry-v2'

TRACKING_VERSION = 'yolo-botsort-1'

# YUV thresholds (BT.601 limited range, as decoded from broadcast H.264), set from Week 0 and championship
# frames. Red bumper: Cr >= 148 with near-neutral Cb (orange and yellow have Cb far below 100). Blue bumper: Cb >= 145, Cr below neutral (includes navy).
# Yellow fuel has a strongly negative Cb, which keeps it out of red; it also marks the field floor.
RED_V, RED_U = 148, (100, 134)
BLUE_U, BLUE_V_MAX = 145, 126
YELLOW_U_MAX, YELLOW_V, YELLOW_Y = 72, 130, 130
STATIC_SHARE = 0.2     # a pixel red/blue in more than this share of frames is a static field element (ramps stay
                       # static even when staff at the rail hide them most of the match)
FIELD_SHARE = 0.01     # a pixel showing fuel in more than this share of frames is on the field floor
RED, BLUE, YELLOW = 1, 2, 4


def _lut(test):
    return bytes(255 if test(v) else 0 for v in range(256))


LUTS = {
    'red_v': _lut(lambda v: v >= RED_V), 'red_u': _lut(lambda u: RED_U[0] <= u <= RED_U[1]),
    'blue_u': _lut(lambda u: u >= BLUE_U), 'blue_v': _lut(lambda v: v <= BLUE_V_MAX),
    'yellow_u': _lut(lambda u: u <= YELLOW_U_MAX), 'yellow_v': _lut(lambda v: v >= YELLOW_V), 'yellow_y': _lut(lambda y: y >= YELLOW_Y),
}


def _big(b):
    return int.from_bytes(b, 'big')


def classify(y, u, v):
    """YUV planes -> (red, blue, yellow) as big-integer masks with 0xFF per matching pixel."""
    t = LUTS
    red = _big(v.translate(t['red_v'])) & _big(u.translate(t['red_u']))
    blue = _big(u.translate(t['blue_u'])) & _big(v.translate(t['blue_v']))
    yellow = _big(u.translate(t['yellow_u'])) & _big(v.translate(t['yellow_v'])) & _big(y.translate(t['yellow_y']))
    return red, blue, yellow


def wide_band(profile):
    """(top, bottom) of the wide field shot as fractions of the frame height."""
    top = profile['camera_top']
    bottom = top + (1 - top) * profile.get('closeup_top', 0.56)
    return top, bottom - 0.015   # stay off the seam above the close-up cameras


def decode(video, top, bottom, start, end, fps, width, left=0., right=1.):
    """Yields (t, W, H, Y, U, V) for frames at start + k/fps; planes are bytes of W*H."""
    start = max(0.0, start)
    probe = subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height',
                            '-of', 'csv=p=0', str(video)], capture_output=True, text=True, check=True, timeout=60)
    sw, sh = (int(x) for x in probe.stdout.strip().split(',')[:2])
    height = int(round(sh * (bottom - top) * width / (sw*(right-left)) / 2)) * 2 or 2
    # Rawvideo has no frame headers. FFmpeg rounds crop and automatic scale
    # dimensions independently; even a two-row mismatch shifts every following
    # Y/U/V plane. Explicitly enforce the exact geometry used by this reader.
    vf = 'fps=%g:round=up,crop=iw*%.5f:ih*%.5f:iw*%.5f:ih*%.5f,scale=%d:%d' % (fps, right-left, bottom-top, left, top, width, height)
    cmd = ['ffmpeg', '-nostdin', '-v', 'error', '-threads', '2', '-ss', '%.3f' % start, '-t', '%.3f' % max(0.1, end - start),
           '-i', str(video), '-vf', vf, '-pix_fmt', 'yuv444p', '-f', 'rawvideo', '-']
    n = width * height
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
    try:
        k = 0
        while True:
            buf = proc.stdout.read(3 * n)
            if len(buf) < 3 * n:
                break
            yield start + k / fps, width, height, buf[:n], buf[n:2 * n], buf[2 * n:]
            k += 1
    finally:
        proc.stdout.close()
        proc.wait(timeout=60)


class Background:
    """Per-pixel share of frames in which a pixel was red, blue or carpet."""

    def __init__(self, n):
        self.n = n
        self.frames = 0
        self.counts = {key: array('H', bytes(2 * n)) for key in (RED, BLUE, YELLOW)}
        self._acc = {key: [] for key in (RED, BLUE, YELLOW)}

    def add(self, masks):
        for key, m in zip((RED, BLUE, YELLOW), masks):
            self._acc[key].append(m)
        self.frames += 1
        if len(self._acc[RED]) == 255:
            self._flush()

    def _flush(self):
        # Up to 255 masks of 0/1 bytes add as big integers without carries between pixels.
        ones = _big(b'\x01' * self.n)
        for key, ms in self._acc.items():
            if ms:
                total = 0
                for m in ms:
                    total += (m >> 7) & ones   # 0xFF -> 0x01 in every byte
                acc = self.counts[key]
                for i, c in enumerate(total.to_bytes(self.n, 'big')):
                    if c:
                        acc[i] += c
            self._acc[key] = []

    def masks(self):
        """(keep_red, keep_blue, share_yellow): big-integer masks of pixels where a red/blue blob can be a moving
        robot, and the per-pixel share of frames showing fuel (array of floats)."""
        self._flush()
        f = max(1, self.frames)
        keep = [_big(bytes(0 if c > STATIC_SHARE * f else 255 for c in self.counts[key])) for key in (RED, BLUE)]
        return keep[0], keep[1], [c / f for c in self.counts[YELLOW]]


def field_rows(yellow_share, width, height, low=0.01, high=0.6, margin=0.03, above=0.07):
    """Field floor extent per row, from where fuel came and went: [(x0, x1) or None] in pixels.

    Fuel on the floor moves during a match; yellow walls and banners do not, so only pixels that were yellow in
    between `low` and `high` of the frames count. The field is the longest band of rows dense with such pixels
    (gaps of up to 4 rows bridged); the audience above is a shorter band, separated from it by the far wall. Each row spans the 2nd-98th percentile of its
    neighbourhood's fuel columns, widened by a margin."""
    cols = [[x for x in range(width) if low < yellow_share[row * width + x] < high] for row in range(height)]
    counts = sorted(len(c) for c in cols)
    dense = max(3, 0.35 * counts[int(0.9 * (height - 1))])
    good = [len(c) >= dense for c in cols]
    bands, start, last = [], None, None
    for r in range(height):
        if not good[r]:
            continue
        if start is not None and r - last > 4:
            bands.append((start, last))
            start = None
        start = r if start is None else start
        last = r
    if start is not None:
        bands.append((start, last))
    best = max(bands, key=lambda b: b[1] - b[0], default=None)
    if best is None or best[1] - best[0] < 0.2 * height:
        return [None] * height
    first = best[0]
    pad = int(margin * width)
    out = []
    for row in range(height):
        if row < first - int(above * height):
            out.append(None)
            continue
        near = sorted(x for r in range(max(first, row - 8), min(height, row + 9)) for x in cols[r])
        if len(near) < 5:
            out.append(out[-1] if out else None)
            continue
        out.append((max(0, near[len(near) // 50] - pad), min(width - 1, near[-1 - len(near) // 50] + pad)))
    return out


def field_mask(rows, width):
    """Big-integer mask of 0xFF over the field extent of each row."""
    data = bytearray(width * len(rows))
    for row, span in enumerate(rows):
        if span:
            data[row * width + span[0]:row * width + span[1] + 1] = b'\xff' * (span[1] - span[0] + 1)
    return _big(bytes(data))


RUN = re.compile(rb'\xff(?:\x00{0,3}\xff)*')   # a bumper strip broken by digits or a frame still counts as one run


def blobs(mask, width, height, min_pixels=6):
    """Connected red or blue regions in a 0x00/0xFF byte mask: [{'x0','x1','y0','y1','n','cx','cy'}] in pixels.

    Runs in each row bridge gaps of up to 3 pixels; runs in neighbouring rows join when they overlap."""
    parent = {}

    def find(a):
        while parent[a] != a:
            parent[a] = parent[parent[a]]
            a = parent[a]
        return a

    runs, prev = [], []
    for row in range(height):
        line = mask[row * width:(row + 1) * width]
        cur = []
        for m in RUN.finditer(line):
            k = len(runs)
            runs.append((row, m.start(), m.end() - 1, line.count(b'\xff', m.start(), m.end())))
            parent[k] = k
            for j in prev:
                _, a0, a1, _ = runs[j]
                if a0 <= m.end() and m.start() - 1 <= a1:
                    ra, rb = find(j), find(k)
                    if ra != rb:
                        parent[rb] = ra
            cur.append(k)
        prev = cur
    groups = defaultdict(list)
    for k in range(len(runs)):
        groups[find(k)].append(runs[k])
    out = []
    for rs in groups.values():
        n = sum(r[3] for r in rs)
        if n < min_pixels:
            continue
        x0, x1 = min(r[1] for r in rs), max(r[2] for r in rs)
        y0, y1 = min(r[0] for r in rs), max(r[0] for r in rs)
        cx = sum((r[1] + r[2]) / 2 * r[3] for r in rs) / n
        out.append({'x0': x0, 'x1': x1, 'y0': y0, 'y1': y1, 'n': n, 'cx': cx, 'cy': y1})
    return out


# ---------------------------------------------------------------- detections and tracks

def bumper_like(b):
    """Bumpers are horizontal strips: reject single specks and tall shapes (shirts, posts, hub lights)."""
    w, h = b['x1'] - b['x0'] + 1, b['y1'] - b['y0'] + 1
    return b['n'] >= 8 and w >= 4 and h <= 0.75 * w + 3


def merge_split(found):
    """Join pieces of one bumper split by its white number: same colour, overlapping rows, and a horizontal gap
    no wider than about three digit widths (2.5 x the strip height)."""
    found = sorted(found, key=lambda b: b['x0'])
    merged = True
    while merged:
        merged = False
        for i, a in enumerate(found):
            for j in range(i + 1, len(found)):
                b = found[j]
                if a['colour'] != b['colour']:
                    continue
                ha, hb = a['y1'] - a['y0'] + 1, b['y1'] - b['y0'] + 1
                overlap = min(a['y1'], b['y1']) - max(a['y0'], b['y0']) + 1
                gap = b['x0'] - a['x1'] - 1
                if overlap >= 0.5 * min(ha, hb) and gap <= 2.5 * max(ha, hb):
                    n = a['n'] + b['n']
                    found[i] = {'x0': min(a['x0'], b['x0']), 'x1': max(a['x1'], b['x1']), 'y0': min(a['y0'], b['y0']),
                                'y1': max(a['y1'], b['y1']), 'n': n, 'cx': (a['cx'] * a['n'] + b['cx'] * b['n']) / n,
                                'cy': max(a['y1'], b['y1']), 'colour': a['colour']}
                    del found[j]
                    merged = True
                    break
            if merged:
                break
    return found


def background(video, profile, start, end, fps=3.0, width=640):
    """One decoding pass: (width, height, keep_red, keep_blue, field_rows, frames)."""
    top, bottom = wide_band(profile)
    bg, size = None, None
    for t, w, h, y, u, v in decode(video, top, bottom, start, end, fps, width):
        if bg is None:
            bg, size = Background(w * h), (w, h)
        bg.add(classify(y, u, v))
    if bg is None:
        raise ValueError('The wide field shot could not be decoded.')
    keep_red, keep_blue, yellow = bg.masks()
    return size[0], size[1], keep_red, keep_blue, field_rows(yellow, size[0], size[1]), bg.frames


def hub_line(keep, rows, width, min_run=4, reach=0.07):
    """Line x = a + b*row through an alliance's static hub and bump ramps, which lie across the field on the
    hub line. Seeded at the column holding the most static pixels of that colour on the field (the hub body)
    within the middle 70% of the shot,
    then each row contributes its static run nearest the current line (within `reach` of the width), weighted by
    run length, and the line is refitted. Posts, towers and parked robots away from the hub do not pull it.
    None when fewer than 8 rows support it."""
    static = (~keep & ((1 << (8 * width * len(rows))) - 1)).to_bytes(width * len(rows), 'big')
    runs = []
    for r, span in enumerate(rows):
        if not span:
            continue
        line = static[r * width + span[0]:r * width + span[1] + 1]
        for m in re.finditer(rb'\xff+', line):
            if m.end() - m.start() >= min_run:
                runs.append((r, span[0] + (m.start() + m.end() - 1) / 2, m.end() - m.start()))
    if not runs:
        return None
    hist = [0.0] * width
    for r, c, n in runs:
        hist[int(c)] += n
    bin_ = max(4, width // 40)
    # Hubs sit inside the middle of the shot; climbing towers and driver-station walls are at the edges.
    best = max(range(int(0.15 * width), int(0.85 * width)), key=lambda x: sum(hist[max(0, x - bin_):x + bin_ + 1]))
    a, b = float(best), 0.0
    limit = reach * width
    pts = []
    for _ in range(4):
        by_row = {}
        for r, c, n in runs:
            d = abs(c - (a + b * r))
            if d <= limit and (r not in by_row or d < by_row[r][0]):
                by_row[r] = (d, c, n)
        pts = [(r, c, n) for r, (_, c, n) in by_row.items()]
        if len(pts) < 8:
            return None
        sw = sum(p[2] for p in pts)
        mr = sum(p[0] * p[2] for p in pts) / sw
        mx = sum(p[1] * p[2] for p in pts) / sw
        var = sum(p[2] * (p[0] - mr) ** 2 for p in pts)
        b = sum(p[2] * (p[0] - mr) * (p[1] - mx) for p in pts) / var if var else 0.0
        b = max(-2.5, min(2.5, b))
        a = mx - b * mr
    return {'a': round(a, 3), 'b': round(b, 4), 'rows': len(pts), 'weight': sum(p[2] for p in pts)}


def detect(video, profile, start, end, fps=3.0, width=640):
    """Decodes the wide shot twice (background, then detections) and returns
    {'width','height','fps','band','field','hubs','frames': [(t, [detection])]} with detections in pixels."""
    top, bottom = wide_band(profile)
    w, h, keep_red, keep_blue, rows, nbg = background(video, profile, start, end, fps, width)
    n = w * h
    field = field_mask(rows, w)
    frames = [];cuts=[];previous=None
    for t, _, _, y, u, v in decode(video, top, bottom, start, end, fps, width):
        signature=list(y[::max(1,len(y)//1024)])
        if previous and sum(abs(a-b) for a,b in zip(previous,signature))/len(signature)>30:
            cuts.append(round(t,3))
        previous=signature
        red, blue, _ = classify(y, u, v)
        found = []
        for colour, m, keep in (('red', red, keep_red), ('blue', blue, keep_blue)):
            for b in blobs((m & keep & field).to_bytes(n, 'big'), w, h):
                b['colour'] = colour
                found.append(b)
        frames.append((round(t, 3), [b for b in merge_split(found) if bumper_like(b)]))
    return {'width': w, 'height': h, 'fps': fps, 'band': [top, bottom], 'field': rows, 'frames': frames,
            'hubs': {'red': hub_line(keep_red, rows, w), 'blue': hub_line(keep_blue, rows, w)}, 'background_frames': nbg, 'scene_cuts':cuts}


def link(frames, fps, max_gap_s=1.5, min_len=3, speed=60.0, scene_cuts=()):
    """Greedy nearest-neighbour linking per colour with constant-velocity prediction and short gaps.

    `speed` is the largest plausible movement in px/s at the 640 px working width (about 2 m/s across the field;
    faster moves are usually a jump to another blob). Returns tracks: {'id','colour','points':
    [(t, cx, cy, x0, y0, x1, y1, n)]}; cy is the bumper's bottom edge (where the robot meets the floor)."""
    active, done, next_id = [], [], 0
    epoch=0
    for t, dets in frames:
        current=sum(c<=t for c in scene_cuts)
        if current!=epoch:
            done.extend(active);active=[];epoch=current
        for tr in list(active):
            if t - tr['points'][-1][0] > max_gap_s + 1e-6:
                active.remove(tr)
                done.append(tr)
        pairs = []
        for i, d in enumerate(dets):
            dw = d['x1'] - d['x0'] + 1
            for tr in active:
                if tr['colour'] != d['colour']:
                    continue
                p = tr['points'][-1]
                dt = t - p[0]
                vx, vy = tr.get('v', (0.0, 0.0))
                px, py = p[1] + vx * dt, p[2] + vy * dt
                dist = ((d['cx'] - px) ** 2 + (d['cy'] - py) ** 2) ** 0.5
                gate = max(8.0, 0.6 * max(dw, p[5] - p[3] + 1)) + speed * dt
                ratio = max(d['n'], p[7]) / max(1, min(d['n'], p[7]))
                if dist <= gate and ratio <= 4:
                    # Prefer the nearest blob of similar size: a robot rarely halves or doubles between frames.
                    pairs.append((dist / gate + 0.25 * math.log(ratio), i, tr))
        used_d, used_t = set(), set()
        for cost, i, tr in sorted(pairs, key=lambda x: x[0]):
            if i in used_d or id(tr) in used_t:
                continue
            # Crowded same-colour crossings do not have a defensible identity join.
            rivals=[c for c,j,other in pairs if (j==i and other is not tr) or (other is tr and j!=i)]
            if rivals and min(rivals)-cost<.15:continue
            used_d.add(i)
            used_t.add(id(tr))
            d, p = dets[i], tr['points'][-1]
            dt = max(1e-3, t - p[0])
            v = ((d['cx'] - p[1]) / dt, (d['cy'] - p[2]) / dt)
            ov = tr.get('v', v)
            tr['v'] = (0.5 * ov[0] + 0.5 * v[0], 0.5 * ov[1] + 0.5 * v[1])
            tr['points'].append((t, d['cx'], d['cy'], d['x0'], d['y0'], d['x1'], d['y1'], d['n']))
        for i, d in enumerate(dets):
            if i not in used_d:
                active.append({'id': next_id, 'scene':epoch, 'colour': d['colour'], 'points': [(t, d['cx'], d['cy'], d['x0'], d['y0'], d['x1'], d['y1'], d['n'])]})
                next_id += 1
    done.extend(active)
    out = []
    for tr in done:
        if len(tr['points']) < min_len:
            continue
        xs = [p[1] for p in tr['points']]
        ys = [p[2] for p in tr['points']]
        tr['travel'] = round(max(xs) - min(xs) + max(ys) - min(ys), 1)
        tr.pop('v', None)
        out.append(tr)
    return sorted(out, key=lambda tr: tr['points'][0][0])


# ---------------------------------------------------------------- identities

def read_position(read, band, camera_top, width, height):
    """Centre of an OCR read (frame_box in camera-area fractions) in wide-shot pixels, or None outside it."""
    x, y, w, h = read['frame_box']
    fy = camera_top + (1 - camera_top) * (y + h / 2)
    top, bottom = band
    if not top <= fy <= bottom:
        return None
    return (x + w / 2) * width, (fy - top) / (bottom - top) * height


def point_at(track, t, tolerance):
    best = None
    for p in track['points']:
        d = abs(p[0] - t)
        if d <= tolerance and (best is None or d < abs(best[0] - t)):
            best = p
    return best


def assign_identities(tracks, reads, roster, band, camera_top, width, height, tolerance=0.2, pad=3, own_reads=(), min_votes=2):
    """Votes from wide-shot bumper reads onto tracks, then one track per team at any time.

    A read votes for a track when the track has a detection within `tolerance` s of the read whose box (padded by
    `pad` px and a tenth of its width, and one strip height upwards) contains the read's centre, and the track's
    colour is the team's alliance. Exact reads count 1, corrected reads 0.5, once per moment however many tiles or
    readers saw it; ambiguous reads, lit displays and reads on the other alliance's colour do not vote. A track needs
    `min_votes` (two separate moments) for an identity. Teams are assigned greedily by votes; a team never holds two
    tracks that overlap in time."""
    alliance = {t: 'red' for t in roster['red']}
    alliance.update({t: 'blue' for t in roster['blue']})
    sightings = defaultdict(dict)   # (track id, team) -> {time: weight}; one vote per moment, whichever reader saw it
    evidence = defaultdict(list);exact_moments=defaultdict(set)

    def vote(tid, team, r, frame):
        w = 1.0 if r['kind'] == 'exact' else 0.5
        at = round(r['t'], 1)
        if r['kind']=='exact':exact_moments[(tid,team)].add(at)
        if w > sightings[(tid, team)].get(at, 0):
            sightings[(tid, team)][at] = w
            evidence[(tid, team)].append({'t': r['t'], 'text': r.get('text'), 'kind': r['kind'], 'frame': frame})

    for r in reads:
        team = r.get('team')
        if team not in alliance or r.get('kind') not in ('exact', 'corrected'):
            continue
        if r.get('background') == 'lit_display' or r.get('background') in ('red', 'blue') and r['background'] != alliance[team]:
            continue
        pos = read_position(r, band, camera_top, width, height)
        if pos is None:
            continue
        hits = []
        for tr in tracks:
            if tr['colour'] != alliance[team]:
                continue
            p = point_at(tr, r['t'], tolerance)
            if p is None:
                continue
            m = pad + 0.1 * (p[5] - p[3] + 1)
            if p[3] - m <= pos[0] <= p[5] + m and p[4] - m - (p[6] - p[4] + 1) <= pos[1] <= p[6] + m:
                hits.append(tr)
        if len(hits) == 1:   # a read on two overlapping same-colour tracks is ambiguous
            vote(hits[0]['id'], team, r, r.get('frame'))
    # Track-guided reads (track_reads) already belong to one track; only the alliance colour is checked.
    # They are matched back by their detection (time and box), so relinking with other settings keeps them.
    owner = {(p[0], tuple(p[3:7])): tr for tr in tracks for p in tr['points']}
    for r in own_reads:
        team, tr = r['team'], owner.get((r['at'], tuple(r['box'])))
        if team in alliance and tr and tr['colour'] == alliance[team]:
            vote(tr['id'], team, r, r.get('crop'))
    votes = defaultdict(Counter)
    for (tid, team), seen in sightings.items():
        votes[tid][team] = sum(seen.values())
    span = {tr['id']: (tr['points'][0][0], tr['points'][-1][0]) for tr in tracks}
    held = defaultdict(list)
    assigned = {}
    for tid, team, n in sorted(((tid, team, n) for tid, c in votes.items() for team, n in c.items()), key=lambda x: (-x[2], x[0])):
        if tid in assigned or n < min_votes or len(exact_moments[(tid,team)])<2 or n < 2*max([v for other,v in votes[tid].items() if other!=team] or [0]):
            continue
        a0, a1 = span[tid]
        if any(not (a1 < b0 or b1 < a0) for b0, b1 in held[team]):
            continue
        total = sum(votes[tid].values())
        assigned[tid] = {'team': team, 'votes': n, 'other_votes': total - n, 'evidence': evidence[(tid, team)][:4]}
        held[team].append((a0, a1))
    return assigned, votes


def extend_identities(tracks, assigned, max_gap=1.0, speed=60.0, rounds=1):
    """Pass identities to unread track fragments that continue an identified fragment.

    A fragment inherits team X when it starts within `max_gap` s after an X fragment ends (or ends just before
    one starts), close enough to have driven there, no other fragment of that colour is a closer continuation,
    and X is not already placed elsewhere during it. Inherited identities are marked 'inherited' with the
    fragment they came from, so they can be audited or dropped."""
    out = dict(assigned)
    by_id = {tr['id']: tr for tr in tracks}

    def span(tr):
        return tr['points'][0][0], tr['points'][-1][0]

    for _ in range(rounds):
        changed = False
        placed = defaultdict(list)
        for tid, a in out.items():
            placed[a['team']].append(span(by_id[tid]))
        for tr in tracks:
            if tr['id'] in out:
                continue
            s0, s1 = span(tr)
            options = []
            for tid, a in out.items():
                src = by_id[tid]
                if src['colour'] != tr['colour'] or src.get('scene',0)!=tr.get('scene',0) or a.get('inherited') is not None:
                    continue
                a0, a1 = span(src)
                if 0 <= s0 - a1 <= max_gap:      # continues after src
                    gap, p, q = s0 - a1, src['points'][-1], tr['points'][0]
                elif 0 <= a0 - s1 <= max_gap:    # leads into src
                    gap, p, q = a0 - s1, tr['points'][-1], src['points'][0]
                else:
                    continue
                dist = ((p[1] - q[1]) ** 2 + (p[2] - q[2]) ** 2) ** 0.5
                if dist <= 8 + 0.6 * (p[5] - p[3] + 1) + speed * gap:
                    options.append((dist, a['team'], tid))
            options.sort()
            if len(options)>1 and options[1][0]-options[0][0]<8:continue
            for dist, team, tid in options[:1]:
                # The source must also have a unique plausible destination.
                src=by_id[tid]
                rivals=[other for other in tracks if other['id'] not in (tr['id'],tid) and other['colour']==tr['colour'] and other.get('scene',0)==tr.get('scene',0) and abs(span(other)[0]-span(src)[1])<=max_gap and math.hypot(other['points'][0][1]-src['points'][-1][1],other['points'][0][2]-src['points'][-1][2])<=dist+8]
                if rivals:continue
                if any(not (s1 < b0 or b1 < s0) for b0, b1 in placed[team]):
                    continue
                # Another unread fragment that continues the same source more closely wins on a later round.
                out[tr['id']] = {'team': team, 'votes': 0, 'other_votes': 0, 'inherited': tid}
                placed[team].append((s0, s1))
                changed = True
        if not changed:
            break
    return out


def team_points(tracks, assigned, roster):
    """{team: sorted [(t, cx, cy)]} from identified tracks."""
    pts = {t: [] for t in roster['red'] + roster['blue']}
    for tr in tracks:
        a = assigned.get(tr['id'])
        if a:
            pts[a['team']].extend((p[0], p[1], p[2]) for p in tr['points'])
    for v in pts.values():
        v.sort()
    return pts


def bridge(points, max_gap=3.0, speed=60.0, step=1 / 3):
    """Fill gaps of up to `max_gap` s between consecutive identified positions by linear interpolation, when the
    robot could have driven the distance. Returns [(t, cx, cy, observed)]."""
    out = []
    for k, p in enumerate(points):
        out.append((p[0], p[1], p[2], True))
        if k + 1 < len(points):
            q = points[k + 1]
            gap = q[0] - p[0]
            if step * 1.5 < gap <= max_gap and ((q[1] - p[1]) ** 2 + (q[2] - p[2]) ** 2) ** 0.5 <= 10 + speed * gap:
                n = int(gap / step)
                for i in range(1, n):
                    f = i / n
                    out.append((p[0] + f * gap, p[1] + f * (q[1] - p[1]), p[2] + f * (q[2] - p[2]), False))
    return out


def coverage(points, t0, t1, step=1.0, tolerance=0.5):
    """Share of match seconds [t0, t1] with a position within `tolerance` s, for each team's [(t, ...)] list."""
    secs = [t0 + i * step for i in range(int((t1 - t0) / step) + 1)]
    out = {}
    for team, pts in points.items():
        ts = [p[0] for p in pts]
        hit = j = 0
        for s_ in secs:
            while j < len(ts) and ts[j] < s_ - tolerance:
                j += 1
            if j < len(ts) and ts[j] <= s_ + tolerance:
                hit += 1
        out[team] = round(hit / len(secs), 3) if secs else 0.0
    return out


# ---------------------------------------------------------------- track-guided bumper reads

def write_bmp(path, width, height, bgr_rows):
    """24-bit uncompressed BMP from top-down rows of BGR bytes (readable by Apple Vision and OpenCV)."""
    pad = (4 - width * 3 % 4) % 4
    size = 54 + (width * 3 + pad) * height
    with open(path, 'wb') as f:
        f.write(b'BM' + size.to_bytes(4, 'little') + bytes(4) + (54).to_bytes(4, 'little'))
        f.write((40).to_bytes(4, 'little') + width.to_bytes(4, 'little') + height.to_bytes(4, 'little', signed=True)
                + (1).to_bytes(2, 'little') + (24).to_bytes(2, 'little') + bytes(4) + (size - 54).to_bytes(4, 'little') + bytes(16))
        for row in reversed(bgr_rows):
            f.write(row + bytes(pad))


def read_targets(tracks, start, every=1.0, tolerance=0.2, min_points=3):
    """One sample per track per `every` seconds on the grid start + k*every: {k: [(track_id, point)]}."""
    out = defaultdict(list)
    for tr in tracks:
        if len(tr['points']) < min_points:
            continue
        best = {}
        for p in tr['points']:
            k = round((p[0] - start) / every)
            if abs(start + k * every - p[0]) <= tolerance and (k not in best or abs(start + k * every - p[0]) < abs(start + k * every - best[k][0])):
                best[k] = p
        for k, p in best.items():
            out[k].append((tr['id'], p))
    return out


def crop_box(p, work_width, full_width, full_height):
    """Bumper region around a detection (work pixels) -> (x, y, w, h) in the upscaled full-resolution band."""
    s = full_width / work_width
    w, h = p[5] - p[3] + 1, p[6] - p[4] + 1
    x0, x1 = p[3] - 0.35 * w - 4, p[5] + 0.35 * w + 4
    y0, y1 = p[4] - 1.2 * h - 4, p[6] + 0.6 * h + 3
    x0, y0 = max(0, int(x0 * s)), max(0, int(y0 * s))
    x1, y1 = min(full_width, int(x1 * s)), min(full_height, int(y1 * s))
    return x0, y0, max(1, x1 - x0), max(1, y1 - y0)


def track_reads(video, band, start, end, tracks, work_width, folder, backend, roster, every=1.0, upscale=4):
    """OCR crops of every track's bumper once per `every` s. Returns raw reads
    [{'t','track','text','kind','team','confidence','box'}] for bumpers read as one of the match's teams
    (neptune_ocr.read_team, limited to the track's alliance for corrections).

    The band is decoded once at `upscale` x the source resolution (bicubic); each crop is a tight window around
    the bumper strip (neptune_ocr.bumper_window), taken at the scale that brings the strip to about
    BUMPER_TARGET_PX. The reader first sees the white-digit map of every crop; crops without an exact read are
    read again in colour (black numbers, and the cases the map loses)."""
    import neptune_ocr
    folder = Path(folder)
    folder.mkdir(parents=True, exist_ok=True)
    for old in list(folder.glob('tr_*.bmp')) + list(folder.glob('tc_*.bmp')):
        old.unlink()
    targets = read_targets(tracks, start, every)
    if not targets:
        return []
    colour = {tr['id']: tr.get('colour') for tr in tracks}
    top, bottom = band
    probe = subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height',
                            '-of', 'csv=p=0', str(video)], capture_output=True, text=True, check=True, timeout=60)
    sw, sh = (int(x) for x in probe.stdout.strip().split(',')[:2])
    fw = sw * upscale
    bh = int(round(sh * (bottom - top)))
    fh = bh * upscale
    vf = 'fps=%g:round=up,crop=iw:%d:0:ih*%.5f,scale=%d:%d:flags=bicubic' % (1 / every, bh, top, fw, fh)
    cmd = ['ffmpeg', '-nostdin', '-v', 'error', '-threads', '2', '-ss', '%.3f' % max(0.0, start), '-t', '%.3f' % max(0.1, end - start),
           '-i', str(video), '-vf', vf, '-pix_fmt', 'bgr24', '-f', 'rawvideo', '-']
    crops = []
    s = sw / work_width   # work pixels -> source pixels
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
    try:
        k = 0
        size = fw * fh * 3
        while True:
            buf = proc.stdout.read(size)
            if len(buf) < size:
                break
            for tid, p in targets.get(k, []):
                box = (p[3] * s, p[4] * s, (p[5] - p[3] + 1) * s, (p[6] - p[4] + 1) * s)
                (x0, y0, x1, y1), scale, inner = neptune_ocr.bumper_window(box, sw, bh)
                step = max(1, upscale // min(scale, upscale))   # sample the enlarged frame at the crop's scale
                rows = []
                for y in range(y0 * upscale, y1 * upscale, step):
                    row = buf[y * fw * 3 + x0 * upscale * 3:y * fw * 3 + x1 * upscale * 3]
                    rows.append(row if step == 1 else b''.join(row[i:i + 3] for i in range(0, len(row), 3 * step)))
                w = len(rows[0]) // 3 if rows else 0
                if not w:
                    continue
                path = folder / ('tr_%05d_%d.bmp' % (k, tid))
                write_bmp(path, w, len(rows), neptune_ocr.white_rows(rows))
                write_bmp(folder / ('tc_%05d_%d.bmp' % (k, tid)), w, len(rows), rows)
                crops.append((start + k * every, tid, path, inner, p))
            k += 1
    finally:
        proc.stdout.close()
        proc.wait(timeout=60)
    texts = backend.recognize([c[2] for c in crops])
    first = {c[2]: neptune_ocr.read_team(texts.get(str(c[2]), []), roster, colour.get(c[1]), c[3]) for c in crops}
    retry = [c[2].with_name(c[2].name.replace('tr_', 'tc_')) for c in crops if not first[c[2]] or first[c[2]]['kind'] != 'exact']
    second = backend.recognize(retry) if retry else {}
    out = []
    for t, tid, path, inner, p in crops:
        r = first[path]
        col = str(path.with_name(path.name.replace('tr_', 'tc_')))
        if (not r or r['kind'] != 'exact') and col in second:
            r = neptune_ocr.read_team(texts.get(str(path), []) + second[col], roster, colour.get(tid), inner)
        if r and r['kind'] in ('exact', 'corrected'):
            out.append({'t': round(t, 2), 'track': tid, 'at': p[0], 'box': list(p[3:7]), 'text': r['text'], 'kind': r['kind'], 'team': r['team'],
                        'confidence': None if r.get('confidence') is None else round(float(r['confidence']), 3),
                        'crop': path.name, 'correction': r.get('correction')})
    return out


# ---------------------------------------------------------------- alliance-relative positions

HUB_SPACING_M = 8.48   # REBUILT: hub centres 158.6 in from each alliance wall on a 651.2 in field (approximate)


def hub_x(line, row):
    return line['a'] + line['b'] * row


def own_side(hubs):
    """{'red': -1|1, 'blue': -1|1}: -1 when that alliance's hub is the left one in the image."""
    left = 'red' if hub_x(hubs['red'], 0) < hub_x(hubs['blue'], 0) else 'blue'
    return {left: -1, 'red' if left == 'blue' else 'blue': 1}


def position(cx, cy, alliance, hubs):
    """Alliance-relative position of a bumper's floor point (work pixels).

    u is 0 on the own hub line and 1 on the opponent's, measured along the field at the robot's image row, so
    perspective cancels; u < 0 is the own alliance zone, 0..1 the neutral zone, u > 1 the opponent's zone.
    hub_m is the along-field distance to the own hub line in metres (lateral offset is not modelled)."""
    other = 'blue' if alliance == 'red' else 'red'
    xo, xp = hub_x(hubs[alliance], cy), hub_x(hubs[other], cy)
    if abs(xp - xo) < 1:
        return None
    u = (cx - xo) / (xp - xo)
    zone = 'own' if u < 0 else 'opponent' if u > 1 else 'neutral'
    return {'u': round(u, 3), 'zone': zone, 'hub_m': round(abs(u) * HUB_SPACING_M, 2)}


# ---------------------------------------------------------------- one match

def legacy_track_match(video, facts, profile, backend, work, fps=3.0, width=640, read_every=1.0):
    """Tracks, identities and alliance-relative positions for one analysed match video.

    `facts` is the facts-pipeline output (periods, roster via bumper identities, wide-shot bumper reads).
    Returns a JSON-ready dict; nothing here changes the facts themselves."""
    periods = facts['periods']
    first, last = periods['auto'] or periods['teleop'], periods['teleop'] or periods['auto']
    start, end = max(0.0, first['start']), min(last['end'], facts['source']['duration_s'])
    ids = facts['bumpers']['identities']
    roster = {c: [int(t) for t, v in ids.items() if v['alliance'] == c] for c in ('red', 'blue')}
    found = detect(video, profile, start, end, fps, width)
    tracks = link(found['frames'], fps, scene_cuts=found.get('scene_cuts',()))
    own = track_reads(video, found['band'], start, end, tracks, found['width'], Path(work) / 'track-crops', backend, roster, read_every)
    voted, _ = assign_identities(tracks, facts['bumpers']['reads'], roster, found['band'], profile['camera_top'],
                                 found['width'], found['height'], own_reads=own)
    assigned = extend_identities(tracks, voted)
    points = team_points(tracks, assigned, roster)
    hubs = found['hubs'] if all(found['hubs'].values()) else None
    robots = {}
    for team, pts in points.items():
        alliance = 'red' if team in roster['red'] else 'blue'
        seq = []
        for t, cx, cy, observed in bridge(pts):
            row = {'t': round(t, 2), 'x': round(cx / found['width'], 4), 'y': round(cy / found['height'], 4), 'observed': observed}
            if hubs:
                row.update(position(cx, cy, alliance, hubs) or {})
            seq.append(row)
        robots[str(team)] = {'alliance': alliance, 'positions': seq,
                             'tracks': sorted(tid for tid, a in assigned.items() if a['team'] == team),
                             'read_votes': sum(a['votes'] for a in assigned.values() if a['team'] == team)}
    localizations=[]
    for tr in tracks:
        a=voted.get(tr['id'])
        if not a:continue
        for pt in tr['points']:
            x0,y0,x1,y1=pt[3:7];bw=(x1-x0+1)/found['width'];bh=(y1-y0+1)/found['height']
            # Bumper strip expands up to include the chassis; never cross panel seams.
            top,bottom=found['band'];w=min(.5,max(.08,bw*2));h=min(bottom-top,max(.12,bh*5*(bottom-top)))
            x=max(0,min(1-w,(x0+x1)/2/found['width']-w/2));y=max(top,min(bottom-h,top+(bottom-top)*y0/found['height']-h*.65))
            localizations.append({'team':a['team'],'t':pt[0],'box':[x,y,w,h], 'identity_evidence':a['evidence'], 'track_id':tr['id'],'identity_method':'direct_vote_track','scene':tr.get('scene',0)})
    # Training coverage excludes inherited identities and interpolated positions.
    reliable=team_points(tracks,voted,roster)
    cover = coverage(reliable, start, end)
    estimated_cover=coverage({t: bridge(p) for t,p in points.items()},start,end)
    return {'version': TRACKING_VERSION, 'localizations':localizations,'scene_cuts':found.get('scene_cuts',[]), 'fps': fps, 'band': found['band'], 'hubs': hubs, 'start': start, 'end': end,
            'coverage': {str(t): c for t, c in cover.items()},'estimated_position_coverage':{str(t):c for t,c in estimated_cover.items()}, 'robots': robots,
            'identities': {str(tid): a for tid, a in assigned.items()}, 'track_reads': len(own), 'tracks': len(tracks),
            'limitations': ['Positions are image-plane estimates from the wide shot; lateral distance to the hub is not modelled.',
                            'Robots hidden behind the close-up seam, hubs or people have no position at those times.']}


def track_match(video, facts, profile, backend, work, fps=6.0, width=960, read_every=1.0, progress=None):
    """The production replacement. No silent fallback to the colour-blob linker."""
    from neptune_yolo import track_match as yolo_track_match
    return yolo_track_match(video, facts, profile, backend, work, fps, width, read_every, progress)
