"""Review videos for detector training runs: what the new model does on the held-out match, uploaded with the run.

Robot detector: each held-out frame with the people's boxes and the new detector's boxes, colour-coded:
green = bumper found, red = bumper missed, yellow = box where nobody marked a bumper. Thin blue = the detector
in use, for comparison. Field detector: detected scoring targets and corners against the marked ones.
"""
from pathlib import Path

GREEN, RED, YELLOW, BLUE, WHITE, DARK = (80, 200, 90), (60, 60, 230), (40, 210, 240), (230, 150, 40), (255, 255, 255), (28, 22, 16)
WIDTH, HEIGHT, FPS, HOLD = 1280, 720, 2, 3      # each frame shown HOLD/FPS seconds


def writer(path, fps=FPS):
    """H.264 where the platform has it (macOS), else MPEG-4 Part 2; YouTube accepts both."""
    import cv2
    for code in ('avc1', 'mp4v'):
        w = cv2.VideoWriter(str(path), cv2.VideoWriter_fourcc(*code), fps, (WIDTH, HEIGHT))
        if w.isOpened():
            return w
    raise RuntimeError('No video encoder available')


def fit(image):
    import cv2
    return cv2.resize(image, (WIDTH, HEIGHT))


def plain(text):
    """OpenCV's built-in font is ASCII only."""
    return str(text).replace('·', '-').replace('—', '-').replace('’', "'").encode('ascii', 'replace').decode()


def card(lines, sub=()):
    """A title card: big lines, then smaller ones."""
    import cv2
    import numpy as np
    img = np.full((HEIGHT, WIDTH, 3), DARK, np.uint8); y = 160
    for text in lines:
        cv2.putText(img, plain(text), (70, y), cv2.FONT_HERSHEY_SIMPLEX, 1.25, WHITE, 3, cv2.LINE_AA); y += 70
    y += 20
    for text in sub:
        cv2.putText(img, plain(text), (70, y), cv2.FONT_HERSHEY_SIMPLEX, .8, (200, 200, 200), 2, cv2.LINE_AA); y += 46
    return img


def banner(img, text, legend):
    import cv2
    cv2.rectangle(img, (0, 0), (WIDTH, 44), DARK, -1)
    cv2.putText(img, plain(text), (14, 30), cv2.FONT_HERSHEY_SIMPLEX, .75, WHITE, 2, cv2.LINE_AA)
    x = WIDTH-14
    for label, colour in reversed(legend):
        size = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, .6, 2)[0][0]; x -= size+30
        cv2.rectangle(img, (x, 14), (x+16, 30), colour, -1)
        cv2.putText(img, label, (x+22, 29), cv2.FONT_HERSHEY_SIMPLEX, .6, WHITE, 2, cv2.LINE_AA); x -= 14
    return img


def box(img, b, colour, thick=3):
    import cv2
    cv2.rectangle(img, (int(b[0]*WIDTH), int(b[1]*HEIGHT)), (int(b[2]*WIDTH), int(b[3]*HEIGHT)), colour, thick)


def match(truth, pred, iou, threshold=.3):
    """One-to-one: (found truth indexes, matched prediction indexes)."""
    found, used = set(), set()
    for i, t in enumerate(truth):
        best = max(((iou(t, p), j) for j, p in enumerate(pred) if j not in used), default=(0, None))
        if best[0] >= threshold:
            found.add(i); used.add(best[1])
    return found, used


def robot_video(out, frames, new_weights, old_weights, report):
    """frames: [(image path, truth xyxy boxes normalized)]. Returns the video path."""
    import cv2
    from ultralytics import YOLO
    from neptune_robot_jobs import detect, iou
    new = YOLO(str(new_weights)); old = YOLO(str(old_weights)) if old_weights else None
    xyxy = lambda bs: [[b['x'], b['y'], b['x']+b['w'], b['y']+b['h']] for b in bs]
    n, c = report.get('new') or {}, report.get('current')
    w = writer(out)
    pct = lambda v: '%d%%' % round(100*v) if v is not None else '-'
    intro = card(['Robot detector training run', 'Held-out match: %s' % report.get('holdout_match', '?')],
                 ['New: found %s of bumpers, %s of its boxes real (F1 %.2f)' % (pct(n.get('recall')), pct(n.get('precision')), n.get('f1', 0)),
                  ('In use: F1 %.2f' % c['f1']) if c else 'In use: the built-in detector',
                  'Result: %s' % ('switched to the new detector' if report.get('adopted') else 'kept the detector in use'),
                  'Learned from %s frames; this match was never trained on.' % report.get('train_frames', '?')])
    for _ in range(FPS*4):
        w.write(intro)
    for k, (path, truth) in enumerate(frames):
        image = cv2.imread(str(path))
        if image is None:
            continue
        pred = xyxy(detect(new, image)); found, used = match(truth, pred, iou)
        img = fit(image)
        if old:
            for b in xyxy(detect(old, image)):
                box(img, b, BLUE, 1)
        for i, t in enumerate(truth):
            box(img, t, GREEN if i in found else RED)
        for j, p in enumerate(pred):
            if j not in used:
                box(img, p, YELLOW, 2)
        banner(img, 'Frame %d of %d · found %d of %d bumpers' % (k+1, len(frames), len(found), len(truth)),
               [('found', GREEN), ('missed', RED), ('extra box', YELLOW)] + ([('in use', BLUE)] if old else []))
        for _ in range(HOLD):
            w.write(img)
    w.release()
    return Path(out)


def field_video(out, frames, new_weights, doc, report):
    """frames: [(t, image)] of the held-out match; doc: its marked regions."""
    import cv2
    import neptune_field_detector as fd
    from ultralytics import YOLO
    model = YOLO(str(new_weights))
    fmt = lambda p: 'none found' if not p or not p.get('found') else '%d of %d within %.1f%% of the marks' % (p['found'], p['expected'], 100*(p['mean_error'] or 0))
    w = writer(out)
    intro = card(['Field detector training run · %s' % report.get('game_key', ''), 'Held-out match: %s' % report.get('holdout_match', '?')],
                 ['New: %s' % fmt(report.get('new')), 'In use: %s' % (fmt(report.get('current')) if report.get('current') else 'none'),
                  'Result: %s' % ('switched to the new detector' if report.get('adopted') else 'kept the detector in use'),
                  'Learned from %s marked matches; this match was never trained on.' % len(report.get('trained_on') or [])])
    for _ in range(FPS*4):
        w.write(intro)
    marked = [fd.box_of(r['points']) for r in doc.get('regions', []) if r['kind'] == 'scoring_target']
    corners = fd.boundary_corners(doc) or []
    for k, item in enumerate(frames[:40]):
        t,image=item[:2];frame_doc=item[2] if len(item)>2 else doc
        marked=[fd.box_of(r['points']) for r in frame_doc.get('regions',[]) if r['kind']=='scoring_target']
        corners=fd.boundary_corners(frame_doc) or []
        h, wd = image.shape[:2]
        r = model.predict(image, imgsz=fd.FRAME_WIDTH, conf=fd.MIN_CONFIDENCE, verbose=False, device='cpu')[0]
        img = fit(image)
        for b in marked:
            box(img, b, GREEN, 1)
        for x, y in corners:
            cv2.circle(img, (int(x*WIDTH), int(y*HEIGHT)), 9, GREEN, 2)
        for b, c in zip(r.boxes.xyxy.tolist(), r.boxes.cls.tolist()):
            nb = [b[0]/wd, b[1]/h, b[2]/wd, b[3]/h]
            if fd.CLASSES[int(c)] == 'field_corner':
                cv2.circle(img, (int((nb[0]+nb[2])/2*WIDTH), int((nb[1]+nb[3])/2*HEIGHT)), 6, YELLOW, -1)
            else:
                box(img, nb, YELLOW, 3)
        banner(img, 'Frame %d · %.0f s into the video' % (k+1, t), [('marked', GREEN), ('detected', YELLOW)])
        for _ in range(HOLD):
            w.write(img)
    w.release()
    return Path(out)


def describe(kind, report, engine):
    """YouTube title and description for a run."""
    pct = lambda v: '%d%%' % round(100*v) if v is not None else '-'
    held = report.get('holdout_match', '?')
    if kind == 'train':
        n, c = report.get('new') or {}, report.get('current')
        title = 'Robot detector run · %s · F1 %.2f%s' % (held, n.get('f1', 0), ' vs %.2f' % c['f1'] if c else '')
        body = ['Neptune Auto Scout · Robots pillar · robot (bumper) detector training run.', '',
                'Held-out match (never trained on, the same every run): %s' % held,
                'New detector: found %s of the bumpers people boxed; %s of its boxes were real bumpers (F1 %.2f).' % (pct(n.get('recall')), pct(n.get('precision')), n.get('f1', 0)),
                'Detector in use: %s' % ('F1 %.2f' % c['f1'] if c else 'the built-in one'),
                'Result: %s.' % ('switched to the new detector' if report.get('adopted') else 'kept the detector in use'),
                'Learned from %s frames in %d matches.' % (report.get('train_frames', '?'), len(report.get('matches') or [])), '',
                'Colours: green = bumper found, red = bumper missed, yellow = a box where nobody marked a bumper, thin blue = detector in use.']
    else:
        title = 'Field detector run · %s · %s' % (report.get('game_key', ''), held)
        body = ['Neptune Auto Scout · Field pillar · field detector (scoring targets and field corners) training run.', '',
                'Game: %s · held-out match (never trained on, the same every run): %s' % (report.get('game_key', ''), held),
                'Result: %s.' % ('switched to the new detector' if report.get('adopted') else 'kept the detector in use'), '',
                'Colours: green = marked by people, yellow = detected by the new model.']
    body += ['', 'Engine %s. Match footage: FIRST Robotics Competition broadcast.' % engine]
    return title[:100], '\n'.join(body)[:4900]


TEST_FPS = 4


def match_test_video(out, video, facts, tags, scores, game_key, model_id, title):
    """A whole match test: every tracked robot boxed with its team number, scoring areas outlined, each predicted
    action shown on its robot for 2.5 s, then an end card with each robot's predicted points."""
    import subprocess
    import cv2
    import numpy as np
    from neptune_robot_jobs import ffmpeg
    alliance = {}
    for t, info in ((facts.get('bumpers') or {}).get('identities') or {}).items():
        if isinstance(info, dict) and info.get('alliance') in ('red', 'blue'):
            alliance[str(t)] = info['alliance']
    for s_ in scores or []:
        if s_.get('team') is not None and s_.get('alliance') in ('red', 'blue'):
            alliance.setdefault(str(s_['team']), s_['alliance'])
    colour = lambda team: RED if alliance.get(str(team)) == 'red' else BLUE if alliance.get(str(team)) == 'blue' else WHITE
    locs = sorted(((facts.get('tracking') or {}).get('localizations') or []), key=lambda r: r['t'])
    times = [r['t'] for r in locs]
    try:
        from neptune_field_regions import load
        regions = [r for r in ((load(facts, game_key) or {}).get('regions') or []) if r.get('kind') == 'scoring_target']
    except Exception:
        regions = []
    periods = [p for p in ((facts.get('periods') or {}).get(k) for k in ('auto', 'teleop')) if p]
    start = max(0., (periods[0]['start'] if periods else 0.)-2); end = (periods[-1]['end'] if periods else start+160)+2
    cmd = [ffmpeg(), '-v', 'error', '-ss', '%.3f' % start, '-t', '%.3f' % (end-start), '-i', str(video),
           '-vf', 'fps=%d,scale=%d:%d' % (TEST_FPS, WIDTH, HEIGHT), '-f', 'rawvideo', '-pix_fmt', 'bgr24', '-']
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
    w = writer(out, TEST_FPS)
    intro = card([plain(title), 'Match test - model %s' % str(model_id)[:12]],
                 ['Boxes: robots the tracker followed, labelled with the team number it read.',
                  'Yellow outlines: scoring areas. Yellow labels: actions the model predicted.',
                  'Robots with no box at a moment were not tracked then.'])
    for _ in range(TEST_FPS*4):
        w.write(intro)
    import bisect
    k = 0; size = WIDTH*HEIGHT*3
    while True:
        raw = proc.stdout.read(size)
        if len(raw) < size:
            break
        t = start+k/TEST_FPS; k += 1
        img = np.frombuffer(raw, np.uint8).reshape(HEIGHT, WIDTH, 3).copy()
        for r in regions:
            if r.get('start_ms', 0) <= t*1000 <= r.get('end_ms', 10**9):
                pts = np.array([[int(x*WIDTH), int(y*HEIGHT)] for x, y in r['points']], np.int32)
                cv2.polylines(img, [pts], True, YELLOW, 1)
        lo, hi = bisect.bisect_left(times, t-.35), bisect.bisect_right(times, t+.35)
        nearest = {}
        for r in locs[lo:hi]:
            key = (r.get('team'), r.get('camera'))
            if key not in nearest or abs(r['t']-t) < abs(nearest[key]['t']-t):
                nearest[key] = r
        boxes = {}
        for r in nearest.values():
            x, y, bw, bh = r['box']; c = colour(r.get('team'))
            p0, p1 = (int(x*WIDTH), int(y*HEIGHT)), (int((x+bw)*WIDTH), int((y+bh)*HEIGHT))
            cv2.rectangle(img, p0, p1, c, 2)
            label = str(r.get('team') or '?')
            cv2.putText(img, label, (p0[0], max(14, p0[1]-5)), cv2.FONT_HERSHEY_SIMPLEX, .55, c, 2, cv2.LINE_AA)
            boxes.setdefault(r.get('team'), p0)
        active = [g for g in tags or [] if g.get('start_ms', -1) <= t*1000 <= g.get('start_ms', -1)+2500]
        for i, g in enumerate(active[:6]):
            text = plain('%s - %s' % (g.get('team'), g.get('tag_key', '').replace('gb:', '').replace('_', ' ')))
            at = boxes.get(g.get('team'))
            if at:
                cv2.putText(img, text, (at[0], at[1]+(-24 if at[1] > 40 else 40)), cv2.FONT_HERSHEY_SIMPLEX, .6, YELLOW, 2, cv2.LINE_AA)
            cv2.putText(img, text, (14, HEIGHT-20-24*i), cv2.FONT_HERSHEY_SIMPLEX, .6, YELLOW, 2, cv2.LINE_AA)
        banner(img, '%s  %d:%02d' % (plain(title)[:60], int(t)//60, int(t) % 60), [('red', RED), ('blue', BLUE), ('predicted', YELLOW)])
        w.write(img)
    proc.stdout.close(); proc.wait(timeout=60)
    lines = []
    for s_ in sorted(scores or [], key=lambda x: (x.get('alliance') or '', -(x.get('robot_points') or 0))):
        pts = s_.get('robot_points')
        lines.append('%s %s: %s' % ((s_.get('alliance') or '').title(), s_.get('team'), ('%.0f predicted points' % pts) if isinstance(pts, (int, float)) else 'unknown (%s)' % s_.get('reason', s_.get('status', ''))))
    end_card = card(['Predicted robot points'], lines[:8] or ['No scoring model in this test.'])
    for _ in range(TEST_FPS*6):
        w.write(end_card)
    w.release()
    return Path(out)


def describe_test(title, tags, scores, model_id, engine):
    counts = {}
    for g in tags or []:
        counts[g.get('tag_key', '')] = counts.get(g.get('tag_key', ''), 0)+1
    body = ['Neptune Auto Scout - match test of the current models (Robots, Field and Actions pillars).', '', 'Match: '+title,
            'Actions model: %s' % model_id, 'Predicted actions: ' + (', '.join('%s x%d' % (k.replace('gb:', ''), v) for k, v in sorted(counts.items())) or 'none'), '']
    for s_ in scores or []:
        pts = s_.get('robot_points')
        body.append('%s %s: %s' % ((s_.get('alliance') or '').title(), s_.get('team'), ('%.0f predicted points' % pts) if isinstance(pts, (int, float)) else 'unknown'))
    body += ['', 'A single match test shows what the models do; it is not an accuracy measure.',
             'Boxes: tracked robots and the team number read. Yellow: scoring areas and predicted actions.', '',
             'Engine %s. Match footage: FIRST Robotics Competition broadcast.' % engine]
    return ('Match test - ' + title)[:100], '\n'.join(body)[:4900]


# Review-tracking palette in BGR: #69deb4 (verified), #48d9ed (constant/inferred), #ffc773 (unknown)
RT_VERIFIED = (180, 222, 105)
RT_CONSTANT = (237, 217, 72)
RT_UNKNOWN = (115, 199, 255)


def _dashed_rect(img, p0, p1, colour, thick=2, dash=8, gap=5):
    import cv2
    x0, y0 = p0; x1, y1 = p1
    for a, b in (((x0, y0), (x1, y0)), ((x1, y0), (x1, y1)), ((x1, y1), (x0, y1)), ((x0, y1), (x0, y0))):
        dx, dy = b[0]-a[0], b[1]-a[1]
        length = int((dx*dx+dy*dy)**.5)
        if length <= 0:
            continue
        pos = 0
        while pos < length:
            end = min(length, pos+dash)
            s = (int(a[0]+dx*pos/length), int(a[1]+dy*pos/length))
            e = (int(a[0]+dx*end/length), int(a[1]+dy*end/length))
            cv2.line(img, s, e, colour, thick, cv2.LINE_AA)
            pos += dash+gap


def _homography_calibrations(facts, game_key):
    """Return ([(region, H)], status_label) for top-down mapping, with wide-band fallback when unmarked."""
    from neptune_homography import calibrate, field_references, reference_map
    from neptune_field_regions import load
    d = load(facts, game_key)
    calibrations = []
    mode = 'uncalibrated'
    if d:
        calibrations = [(r, calibrate(r['points'])) for r in d.get('regions', []) if r.get('kind') == 'field_calibration' and len(r.get('points', [])) == 4]
        if calibrations:
            mode = 'calibrated (4 corners)'
        if not calibrations:
            from neptune_field_detector import ordered_quad
            for r in d.get('regions', []):
                if r.get('kind') == 'field_boundary' and r.get('camera_view') == 'wide' and len(r.get('points', [])) == 4 and r.get('corner_indices', list(range(4))) == list(range(4)) and not r.get('inferred_corner_indices'):
                    try:
                        calibrations.append((r, calibrate(ordered_quad(r['points']))))
                        mode = 'calibrated (boundary)'
                    except ValueError:
                        pass
        if not any(r.get('camera_view') == 'wide' for r, _ in calibrations):
            shots = {}
            for r in d.get('regions', []):
                if r.get('kind') == 'field_reference' and r.get('camera_view') == 'wide':
                    shots.setdefault(r.get('shot_id', 'legacy'), []).append(r)
            refs = field_references(game_key) if shots else None
            for shot, marks in shots.items():
                h = reference_map(marks, refs)
                if h:
                    calibrations.append((dict(marks[0], id='field-references:'+shot), h))
                    mode = 'calibrated (floor refs)'
    if not calibrations:
        top = (facts.get('layout') or {}).get('camera_top', .24)
        bot = top + (1. - top) * .56
        try:
            h = calibrate([[.12, top+.05], [.88, top+.05], [.98, bot-.02], [.02, bot-.02]])
            calibrations = [({'id': 'wide-approx', 'camera_view': 'wide', 'start_ms': 0, 'end_ms': 10**9}, h)]
            mode = 'wide-band perspective'
        except ValueError:
            pass
    return calibrations, mode


def robot_test_stats(facts, game_key):
    """Compute merged frame points, top-down homography projections, and summary metrics for a robot test."""
    from neptune_homography import project
    from neptune_field_regions import active
    tracking = facts.get('tracking') or {}
    review_pts = (tracking.get('identity_review') or {}).get('points') or []
    locs = tracking.get('localizations') or []
    const_idx = {}
    for p in locs:
        if p.get('identity_method') == 'constant_identity' and p.get('team') is not None:
            const_idx[(p.get('camera', 'wide'), round(float(p['t']), 2), str(p.get('track_id')))] = p['team']
    calibrations, homography_mode = _homography_calibrations(facts, game_key)
    merged = []
    counts = {'verified': 0, 'constant': 0, 'inferred': 0, 'unknown': 0, 'ocr_reads': 0}
    source = review_pts if review_pts else tracking.get('observations') or []
    for p in source:
        if p.get('identity_status') == 'excluded' or p.get('box_source') == 'optical_flow':
            continue
        t = float(p['t'])
        cam = p.get('camera', 'wide')
        tid = str(p.get('track_id'))
        status = p.get('identity_status', 'unknown')
        team = p.get('team')
        const_team = const_idx.get((cam, round(t, 2), tid))
        if status == 'verified':
            kind = 'verified'; counts['verified'] += 1
        elif const_team is not None:
            kind = 'constant'; team = const_team; counts['constant'] += 1
        elif status == 'inferred' and team is not None:
            kind = 'inferred'; counts['inferred'] += 1
        else:
            kind = 'unknown'; counts['unknown'] += 1
        reads = [int(x) for x in (p.get('read_teams') or []) if isinstance(x, (int, float))]
        if reads:
            counts['ocr_reads'] += 1
        fx = fy = None
        if cam == 'wide' and calibrations and not p.get('occluded'):
            choices = [(r, h) for r, h in calibrations if r.get('id') == 'wide-approx' or active(r, t, cam, facts)]
            if choices:
                _, h = choices[0]
                bx, by, bw, bh = p['box']
                q = project(h, [bx + bw/2, by + bh])
                if q and all(-.08 <= v <= 1.08 for v in q):
                    fx, fy = max(0., min(1., q[0])), max(0., min(1., q[1]))
        merged.append({'t': t, 'camera': cam, 'track_id': tid, 'alliance': p.get('alliance'),
                       'box': p['box'], 'team': team, 'kind': kind, 'reads': reads,
                       'field_x': fx, 'field_y': fy})
    roster = {'red': [], 'blue': []}
    for team_s, info in ((facts.get('bumpers') or {}).get('identities') or {}).items():
        if isinstance(info, dict) and info.get('alliance') in ('red', 'blue'):
            try:
                roster[info['alliance']].append(int(team_s))
            except ValueError:
                pass
    for a in ('red', 'blue'):
        roster[a] = sorted(set(roster[a]))[:3]
    summary = {
        'verified_points': counts['verified'],
        'constant_points': counts['constant'],
        'inferred_points': counts['inferred'],
        'unknown_points': counts['unknown'],
        'ocr_read_frames': counts['ocr_reads'],
        'homography_status': homography_mode,
        'roster': roster,
    }
    return merged, summary


def robot_test_video(out, video, facts, game_key, title):
    """Render the Robot Detection + Bumper OCR + Constant Identity + Top-Down Homography test video."""
    import bisect
    import collections
    import subprocess
    import cv2
    import numpy as np
    from neptune_robot_jobs import ffmpeg
    merged, summary = robot_test_stats(facts, game_key)
    by_time = collections.defaultdict(list)
    for p in merged:
        by_time[round(p['t'], 3)].append(p)
    sample_times = sorted(by_time)
    roster = summary['roster']
    all_teams = [('red', t) for t in roster.get('red', [])] + [('blue', t) for t in roster.get('blue', [])]
    team_timeline = {t: [] for _, t in all_teams}
    for p in merged:
        if p['team'] in team_timeline:
            team_timeline[p['team']].append((p['t'], p['kind']))

    periods = [p for p in ((facts.get('periods') or {}).get(k) for k in ('auto', 'teleop')) if p]
    start = max(0., (periods[0]['start'] if periods else 0.)-2)
    end = (periods[-1]['end'] if periods else min(float((facts.get('source') or {}).get('duration_s') or 160.), 160.))+2
    duration = max(1., end - start)
    VW, VH = 880, 495
    VX, VY = 8, 44
    cmd = [ffmpeg(), '-v', 'error', '-ss', '%.3f' % start, '-t', '%.3f' % duration, '-i', str(video),
           '-vf', 'fps=%d,scale=%d:%d' % (TEST_FPS, VW, VH), '-f', 'rawvideo', '-pix_fmt', 'bgr24', '-']
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
    w = writer(out, TEST_FPS)
    intro = card([plain(title), 'Robot detection, bumper OCR & homography test'],
                 ['Original view: green = OCR verified, cyan = constant identity, dashed = inferred, amber = unknown.',
                  'Top-right: live top-down homography field map (%s).' % summary['homography_status'],
                  'Bottom: full-match constant robot identity timeline across all six teams.'])
    for _ in range(TEST_FPS*3):
        w.write(intro)
    k = 0; frame_bytes = VW*VH*3
    while True:
        raw = proc.stdout.read(frame_bytes)
        if len(raw) < frame_bytes:
            break
        t = start + k/TEST_FPS; k += 1
        canvas = np.full((HEIGHT, WIDTH, 3), (18, 15, 12), np.uint8)
        frame = np.frombuffer(raw, np.uint8).reshape(VH, VW, 3).copy()
        idx = bisect.bisect_left(sample_times, t)
        cand = [sample_times[j] for j in (idx-1, idx) if 0 <= j < len(sample_times)]
        near_t = min(cand, key=lambda s: abs(s-t)) if cand else None
        pts = by_time[near_t] if near_t is not None and abs(near_t-t) <= .36 else []

        for p in pts:
            bx, by, bw, bh = p['box']
            x0, y0 = int(bx*VW), int(by*VH)
            x1, y1 = int((bx+bw)*VW), int((by+bh)*VH)
            col = RT_VERIFIED if p['kind'] == 'verified' else RT_CONSTANT if p['kind'] in ('constant', 'inferred') else RT_UNKNOWN
            if p['kind'] == 'inferred':
                _dashed_rect(frame, (x0, y0), (x1, y1), col, 2)
            else:
                cv2.rectangle(frame, (x0, y0), (x1, y1), col, 2)
            lbl = str(p['team']) if p['team'] is not None else 'Unknown'
            if p['kind'] == 'constant':
                lbl += ' CONST'
            elif p['kind'] == 'inferred':
                lbl += ' ?'
            if p['reads']:
                lbl += ' [OCR %s]' % '/'.join(str(r) for r in p['reads'][:2])
            lbl = plain(lbl)
            (tw, th), _ = cv2.getTextSize(lbl, cv2.FONT_HERSHEY_SIMPLEX, .44, 1)
            ly = max(th+6, y0)
            cv2.rectangle(frame, (max(0, x0), ly-th-6), (min(VW-1, x0+tw+8), ly), col, -1)
            cv2.putText(frame, lbl, (max(2, x0+4), ly-4), cv2.FONT_HERSHEY_SIMPLEX, .44, (12, 18, 24), 1, cv2.LINE_AA)
        canvas[VY:VY+VH, VX:VX+VW] = frame
        cv2.rectangle(canvas, (VX, VY), (VX+VW, VY+VH), (60, 75, 95), 1)

        mx0, my0, mw, mh = 896, 44, 374, 214
        cv2.rectangle(canvas, (mx0, my0), (mx0+mw, my0+mh), (28, 34, 44), -1)
        cv2.rectangle(canvas, (mx0, my0), (mx0+mw, my0+mh), (70, 90, 115), 1)
        cv2.putText(canvas, plain('TOP-DOWN HOMOGRAPHY (%s)' % summary['homography_status'][:22]),
                    (mx0+10, my0+20), cv2.FONT_HERSHEY_SIMPLEX, .44, WHITE, 1, cv2.LINE_AA)
        fx0, fy0, fw, fh = mx0+12, my0+30, mw-24, mh-40
        cv2.rectangle(canvas, (fx0, fy0), (fx0+fw, fy0+fh), (38, 46, 52), -1)
        cv2.rectangle(canvas, (fx0, fy0), (fx0+int(fw*.24), fy0+fh), (58, 42, 32), -1)
        cv2.rectangle(canvas, (fx0+int(fw*.76), fy0), (fx0+fw, fy0+fh), (36, 36, 62), -1)
        cv2.line(canvas, (fx0+fw//2, fy0), (fx0+fw//2, fy0+fh), (90, 105, 120), 1)
        cv2.rectangle(canvas, (fx0, fy0), (fx0+fw, fy0+fh), (130, 145, 165), 1)
        lo_idx = bisect.bisect_left(sample_times, t-3.0)
        hi_idx = bisect.bisect_right(sample_times, t)
        trails = collections.defaultdict(list)
        for st in sample_times[lo_idx:hi_idx]:
            for p in by_time[st]:
                if p['field_x'] is not None and p['field_y'] is not None:
                    key = p['team'] or ('%s:%s' % (p['alliance'], p['track_id']))
                    trails[key].append((int(fx0+p['field_x']*fw), int(fy0+p['field_y']*fh), p['alliance']))
        for tr_pts in trails.values():
            if len(tr_pts) >= 2:
                tcol = (90, 90, 220) if tr_pts[-1][2] == 'red' else (220, 140, 60)
                for a, b in zip(tr_pts, tr_pts[1:]):
                    cv2.line(canvas, (a[0], a[1]), (b[0], b[1]), tcol, 2, cv2.LINE_AA)
        for p in pts:
            if p['field_x'] is None or p['field_y'] is None:
                continue
            px, py = int(fx0+p['field_x']*fw), int(fy0+p['field_y']*fh)
            dot_col = RED if p.get('alliance') == 'red' else BLUE
            ring_col = RT_VERIFIED if p['kind'] == 'verified' else RT_CONSTANT if p['kind'] in ('constant', 'inferred') else RT_UNKNOWN
            cv2.circle(canvas, (px, py), 7, dot_col, -1, cv2.LINE_AA)
            cv2.circle(canvas, (px, py), 8, ring_col, 2, cv2.LINE_AA)
            if p['team'] is not None:
                cv2.putText(canvas, str(p['team']), (max(fx0+2, min(fx0+fw-34, px-14)), max(fy0+12, py-10)),
                            cv2.FONT_HERSHEY_SIMPLEX, .40, WHITE, 1, cv2.LINE_AA)

        rx0, ry0, rw, rh = 896, 266, 374, 273
        cv2.rectangle(canvas, (rx0, ry0), (rx0+rw, ry0+rh), (24, 30, 40), -1)
        cv2.rectangle(canvas, (rx0, ry0), (rx0+rw, ry0+rh), (70, 90, 115), 1)
        cv2.putText(canvas, 'CONSTANT ROBOT IDENTITY STATUS', (rx0+10, ry0+22), cv2.FONT_HERSHEY_SIMPLEX, .46, WHITE, 1, cv2.LINE_AA)
        for idx_t, (al, team) in enumerate(all_teams):
            yy = ry0 + 46 + idx_t*36
            vis = [p for p in pts if p['team'] == team]
            best_kind = 'verified' if any(p['kind'] == 'verified' for p in vis) else \
                        'constant' if any(p['kind'] == 'constant' for p in vis) else \
                        'inferred' if any(p['kind'] == 'inferred' for p in vis) else None
            badge_col = RT_VERIFIED if best_kind == 'verified' else RT_CONSTANT if best_kind in ('constant', 'inferred') else (120, 130, 145)
            al_col = RED if al == 'red' else BLUE
            cv2.rectangle(canvas, (rx0+10, yy-14), (rx0+18, yy+8), al_col, -1)
            cv2.putText(canvas, str(team), (rx0+26, yy+3), cv2.FONT_HERSHEY_SIMPLEX, .55, WHITE, 2, cv2.LINE_AA)
            state_txt = 'OCR verified' if best_kind == 'verified' else 'Constant ID' if best_kind == 'constant' else 'Inferred ?' if best_kind == 'inferred' else 'Unobserved'
            cv2.putText(canvas, state_txt, (rx0+105, yy+3), cv2.FONT_HERSHEY_SIMPLEX, .46, badge_col, 1, cv2.LINE_AA)
            seen_n = len(team_timeline.get(team, []))
            cv2.putText(canvas, '%d pts' % seen_n, (rx0+285, yy+3), cv2.FONT_HERSHEY_SIMPLEX, .44, (180, 190, 205), 1, cv2.LINE_AA)

        tx0, ty0, tw_bar, th_bar = 8, 547, 1262, 165
        cv2.rectangle(canvas, (tx0, ty0), (tx0+tw_bar, ty0+th_bar), (22, 26, 34), -1)
        cv2.rectangle(canvas, (tx0, ty0), (tx0+tw_bar, ty0+th_bar), (60, 75, 95), 1)
        cv2.putText(canvas, 'FULL-MATCH CONSTANT IDENTITY TIMELINE (GREEN = OCR VERIFIED, CYAN = CONSTANT / INFERRED)',
                    (tx0+10, ty0+18), cv2.FONT_HERSHEY_SIMPLEX, .42, (200, 210, 225), 1, cv2.LINE_AA)
        bar_x0, bar_w = tx0+80, tw_bar-96
        for idx_t, (al, team) in enumerate(all_teams):
            by_row = ty0 + 30 + idx_t*21
            cv2.putText(canvas, str(team), (tx0+12, by_row+11), cv2.FONT_HERSHEY_SIMPLEX, .44, RED if al == 'red' else BLUE, 1, cv2.LINE_AA)
            cv2.rectangle(canvas, (bar_x0, by_row), (bar_x0+bar_w, by_row+13), (38, 44, 54), -1)
            for pt_t, pt_kind in team_timeline.get(team, ()):
                if start <= pt_t <= end:
                    px = bar_x0 + int((pt_t - start)/duration * bar_w)
                    c = RT_VERIFIED if pt_kind == 'verified' else RT_CONSTANT
                    cv2.line(canvas, (px, by_row+1), (px, by_row+12), c, 2)
        play_x = bar_x0 + int(max(0., min(1., (t - start)/duration)) * bar_w)
        cv2.line(canvas, (play_x, ty0+24), (play_x, ty0+th_bar-6), WHITE, 2)

        banner(canvas, '%s  %d:%02d' % (plain(title)[:52], int(t)//60, int(t) % 60),
               [('OCR verified', RT_VERIFIED), ('Constant / inferred', RT_CONSTANT), ('Unknown', RT_UNKNOWN)])
        w.write(canvas)
    proc.stdout.close(); proc.wait(timeout=60)
    end_lines = [
        'OCR verified points: %d  |  Constant identity carried: %d' % (summary['verified_points'], summary['constant_points']),
        'Inferred points: %d  |  Unresolved detections: %d' % (summary['inferred_points'], summary['unknown_points']),
        'Bumper OCR read frames: %d  |  Top-down map: %s' % (summary['ocr_read_frames'], summary['homography_status']),
    ]
    end_card = card(['Robot detection & constant identity summary'], end_lines)
    for _ in range(TEST_FPS*5):
        w.write(end_card)
    w.release()
    return Path(out), summary


def describe_robot_test(title, match_key, summary, engine):
    """Pre-filled editable YouTube title and description for a robot detection match test."""
    yt_title = ('Robot detection & constant ID test · %s' % (title or match_key))[:100]
    roster = summary.get('roster') or {}
    body = [
        'Neptune Auto Scout · 1 · Robots pillar — robot detection, bumper OCR, constant identity, and top-down homography test.',
        '',
        'Match: %s (%s)' % (title, match_key),
        'Red roster: %s  |  Blue roster: %s' % (', '.join(map(str, roster.get('red', []))) or '-', ', '.join(map(str, roster.get('blue', []))) or '-'),
        'OCR-verified robot sightings: %s' % summary.get('verified_points', 0),
        'Constant identity carried sightings: %s' % summary.get('constant_points', 0),
        'Inferred sightings: %s  |  Unresolved bumper detections: %s' % (summary.get('inferred_points', 0), summary.get('unknown_points', 0)),
        'Frames with direct bumper OCR reads: %s' % summary.get('ocr_read_frames', 0),
        'Top-down homography view: %s' % summary.get('homography_status', 'uncalibrated'),
        '',
        'Legend:',
        '- Solid green box: OCR-verified robot number',
        '- Cyan box: Constant identity carried / inferred robot number',
        '- Amber box: Unresolved bumper detection',
        '- Top-right inset: 2D top-down homography field map with 3s motion trails',
        '- Bottom strip: Full-match constant identity timeline for all six robots',
        '',
        'Engine %s. Match footage: FIRST Robotics Competition broadcast.' % engine,
    ]
    return yt_title, '\n'.join(body)[:4900]

