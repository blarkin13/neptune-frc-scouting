"""Ingest public human video tags (community/training-labels.php?export=1, schema 2) into the learning data.

Human video tags are contributed immediately, without review (see docs/community-analysis/video-tagging.md).
This module never treats them as verified ground truth and never trains anything. It:

  ingest   stores each export (or a community_video_labels SQL dump) as a read-only snapshot and updates an
           append-only ledger keyed by label ID. Private columns in a SQL dump (contributor_key, user_id,
           ip_key) are dropped on read and never stored. Labels withdrawn in a dump, or present in an earlier
           export but missing from the latest one, stay in the ledger with that state and are excluded from
           datasets.
  verify   retrieves each source video itself (yt-dlp, or a local file named <video_id>.mp4), computes its
           SHA-256 and duration, and checks every label's browser-reported duration and timestamps against
           it. The export's source_sha256 is null and its duration is browser-reported, so nothing is
           extracted from an unverified video.
  build    writes a dataset: one row per contributed label with its original ID, source references, certainty
           exactly as submitted (observed/uncertain), note and definition version; frames extracted from the
           verified video at the label's elapsed-video times; explicit duplicate groups (originals kept);
           and a train/evaluation split by event or match so a match never appears on both sides.

Label meaning carried into the dataset: times are elapsed source-video milliseconds, not the match clock;
"scoring" marks the start of a scoring sequence (not pieces, points or cycles; definition_version
neptune-match-tags-v1 is kept as is); whole-match/phase absence assessments have null times; missing labels
mean unknown, so unlabeled intervals are never emitted as negative examples.
"""
import argparse
import datetime
import hashlib
import json
import re
import shutil
import stat
import ssl
import subprocess
import sys
import urllib.request
from pathlib import Path

EXPORT_URL = 'https://neptunefrc.com/community/training-labels.php?export=1'
PRIVATE_COLUMNS = ('contributor_key', 'user_id', 'ip_key')
REQUIRED = ('id', 'match_id', 'match_key', 'source_url', 'video_id', 'roster', 'team_number', 'tag_key', 'tag_label',
            'tag_category', 'definition_version', 'label_scope', 'start_ms', 'end_ms', 'video_duration_ms', 'certainty',
            'note', 'review_state', 'created_at')
USABLE_STATES = ('contributed', 'verified')
CONTENT_FIELDS = ('match_id', 'source_url', 'video_id', 'roster', 'team_number', 'tag_key', 'tag_label', 'tag_category', 'definition_version',
                  'label_scope', 'start_ms', 'end_ms', 'video_duration_ms', 'certainty', 'note', 'created_at', 'polarity', 'scoring', 'replaces_id','time_basis','evidence_type','external','schema_version','season','game_key')
DURATION_TOLERANCE_MS = 2000
DUPLICATE_WINDOW_MS = 1500
FRAME_OFFSETS_MS = (-1000, 0, 1000)


def now():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')


def sha256_bytes(data):
    return hashlib.sha256(data).hexdigest()


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def read_only(path):
    Path(path).chmod(Path(path).stat().st_mode & ~(stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH))


# ------------------------------------------------------------------ reading

def validate_label(r):
    """Returns a reason string if the record cannot be used, else None. Nothing is corrected silently."""
    missing = [k for k in REQUIRED if k not in r]
    if missing:
        return 'missing fields: ' + ', '.join(missing)
    if not re.fullmatch(r'[0-9a-f]{32}', str(r['id'])):
        return 'invalid label id'
    if not re.fullmatch(r'[A-Za-z0-9_-]{11}', str(r['video_id'])):
        return 'invalid video id'
    roster = r['roster'] if isinstance(r['roster'], dict) else None
    teams = (roster or {}).get('red', []) + (roster or {}).get('blue', [])
    if r['team_number'] not in teams:
        return 'robot is not on the roster snapshot'
    if r.get('polarity','present') not in ('present','absent'):
        return 'unknown label polarity'
    from neptune_action_labels import is_game_action,error as action_error
    if is_game_action(r.get('tag_key')):
        problem=action_error(r)
        if problem:return problem
    scoring=r.get('scoring')
    if scoring is not None:
        if not isinstance(scoring,dict) or scoring.get('schema_version')!=1 or (scoring.get('source')!='human_video_contribution' and not external_score_label(r)) or r['tag_key']!='scoring' or r.get('polarity','present')!='present':return 'invalid scoring evidence'
        if scoring.get('method') not in ('counted','estimated','unknown'):return 'invalid scoring amount method'
        lo,hi=scoring.get('count_min'),scoring.get('count_max')
        if scoring.get('mode')=='alliance_share':
            points=scoring.get('robot_points');total=scoring.get('alliance_score')
            if r['label_scope']!='match' or scoring.get('score_basis')!='full_final_alliance_score' or type(points) is not int or points<0 or (total is not None and (type(total) is not int or total<points)):return 'invalid whole-match contribution'
            if lo is not None or hi is not None:return 'whole-match points are not piece counts'
            if scoring['method']=='estimated' and r['certainty']!='uncertain':return 'estimated contribution must retain uncertainty'
        elif scoring['method']=='unknown':
            if lo is not None or hi is not None:return 'unknown scoring amount must stay null'
        elif type(lo) is not int or type(hi) is not int or lo<0 or hi<lo or hi>1000 or (scoring['method']=='counted' and lo!=hi):return 'invalid scoring count range'
    if r['certainty'] not in ('observed', 'uncertain'):
        return 'unknown certainty'
    if r['label_scope'] == 'match':
        if r['start_ms'] is not None or r['end_ms'] is not None:
            return 'whole-match label with timestamps'
    elif r['label_scope'] in ('moment', 'interval'):
        if not isinstance(r['start_ms'], int) or not isinstance(r['end_ms'], int) or r['start_ms'] < 0 or r['end_ms'] < r['start_ms']:
            return 'invalid timestamps'
        if r['label_scope'] == 'moment' and r['start_ms'] != r['end_ms']:
            return 'moment label with an interval'
    else:
        return 'unknown scope'
    return None


def external_score_label(r):
    e=r.get('external') or {};s=r.get('scoring') or {};components=e.get('components') or {}
    keys=('auto_fuel_score','tele_fuel_score','auto_climb_level','tele_climb_level')
    if any(type(components.get(k)) is not int or components[k]<0 for k in keys):return False
    if components['auto_climb_level']>1 or components['tele_climb_level']>3 or max(components['auto_fuel_score'],components['tele_fuel_score'])>10000:return False
    points=components['auto_fuel_score']+components['tele_fuel_score']+15*components['auto_climb_level']+(0,10,20,30)[components['tele_climb_level']]
    return (r.get('evidence_type')=='external_scouting_label' and r.get('definition_version')=='viper-2026-points-v1'
            and e.get('adapter_version')=='viper-2026-points-v1' and e.get('provider')=='viperscout'
            and e.get('kind')=='reported_score_total' and e.get('weak_supervision') is True
            and r.get('tag_key')=='scoring' and r.get('label_scope')=='match' and r.get('certainty')=='uncertain'
            and r.get('polarity')=='present' and r.get('start_ms') is None and r.get('end_ms') is None
            and r.get('time_basis')=='video_elapsed_ms' and s.get('source')=='external_scouting_estimate'
            and s.get('mode')=='alliance_share' and s.get('method')=='estimated' and s.get('robot_points')==points
            and s.get('score_basis')=='full_final_alliance_score')


def external_label(r):
    if external_score_label(r):return True
    e=r.get('external') or {}
    if r.get('definition_version') == 'mapped-actions-v1':
        return (r.get('evidence_type') == 'external_scouting_label' and r.get('time_basis') == 'phase_elapsed_ms'
                and e.get('adapter_version') == 'mapped-actions-v1' and e.get('provider') == 'mapped'
                and e.get('phase') in ('auto', 'teleop') and e.get('weak_supervision') is True
                and e.get('kind') == 'reported_action' and type(r.get('start_ms')) is int
                and 0 <= r['start_ms'] <= (20000 if e['phase'] == 'auto' else 140000)
                and r.get('end_ms') == r['start_ms'] and r.get('label_scope') == 'moment'
                and r.get('scoring') is None and r.get('polarity') == 'present')
    return (r.get('evidence_type')=='external_scouting_label' and r.get('time_basis')=='phase_elapsed_ms' and r.get('definition_version')=='viper-2026-activity-v1' and e.get('adapter_version')=='viper-2026-activity-v1' and e.get('provider')=='viperscout' and e.get('phase') in ('auto','teleop') and e.get('weak_supervision') is True and ((r.get('tag_key')=='scoring' and e.get('kind')=='counted_scoring_activity') or (r.get('tag_key')=='end_completed' and e.get('kind')=='reported_climb_completion')) and type(r.get('start_ms')) is int and 0<=r['start_ms']<=(20000 if e.get('phase')=='auto' else 140000) and r.get('end_ms')==r['start_ms'] and r.get('label_scope')=='moment' and r.get('scoring') is None)


def read_export(text):
    """Parse schema-2 NDJSON. Returns (labels, problems); problems keep the line number and reason."""
    labels, problems = [], []
    for n, line in enumerate(text.splitlines(), 1):
        if not line.strip():
            continue
        try:
            r = json.loads(line)
        except ValueError:
            problems.append({'line': n, 'reason': 'not JSON'})
            continue
        if r.get('schema_version') != 2 or r.get('evidence_type') not in ('human_video_label','external_scouting_label'):
            problems.append({'line': n, 'id': r.get('id'), 'reason': 'unsupported schema_version/evidence_type'})
            continue
        if r.get('evidence_type')=='external_scouting_label' and not external_label(r):
            problems.append({'line':n,'id':r.get('id'),'reason':'unsupported outside scouting adapter or target'});continue
        if r.get('time_basis') != 'video_elapsed_ms' and not external_label(r):
            problems.append({'line': n, 'id': r.get('id'), 'reason': 'unsupported time basis'})
            continue
        reason = validate_label(r)
        if reason:
            problems.append({'line': n, 'id': r.get('id'), 'reason': reason})
            continue
        labels.append(r)
    return labels, problems


def sql_rows(sql):
    """Rows of community_video_labels INSERT statements in a mysqldump/phpMyAdmin export, as dicts."""
    rows = []
    for m in re.finditer(r"INSERT INTO `community_video_labels` \(([^)]*)\) VALUES\s*", sql):
        cols = [c.strip().strip('`') for c in m.group(1).split(',')]
        i, s = m.end(), sql
        while i < len(s):
            while i < len(s) and s[i] in ' \t\r\n,':
                i += 1
            if i >= len(s) or s[i] != '(':
                break
            i += 1
            row, cur, quoted, inq = [], '', False, False
            while True:
                ch = s[i]
                if inq:
                    if ch == '\\':
                        cur += {'n': '\n', 't': '\t', 'r': '\r', '0': '\0'}.get(s[i + 1], s[i + 1])
                        i += 2
                        continue
                    if ch == "'":
                        if s[i + 1:i + 2] == "'":
                            cur += "'"
                            i += 2
                            continue
                        inq = False
                        i += 1
                        continue
                    cur += ch
                    i += 1
                    continue
                if ch == "'":
                    inq = quoted = True
                    cur = ''   # drop the separator space before an opening quote
                    i += 1
                    continue
                if ch in ',)':
                    v = cur if quoted else cur.strip()
                    row.append(None if (not quoted and v == 'NULL') else v)
                    cur, quoted = '', False
                    i += 1
                    if ch == ')':
                        break
                    continue
                cur += ch
                i += 1
            if len(row) == len(cols):
                rows.append(dict(zip(cols, row)))
            while i < len(s) and s[i] in ' \t\r\n':
                i += 1
            if i < len(s) and s[i] == ';':
                break
    return rows


def read_sql_dump(sql):
    """Labels from a community_video_labels dump in export form. Private columns are dropped here."""
    labels, problems = [], []
    for raw in sql_rows(sql):
        for k in PRIVATE_COLUMNS:
            raw.pop(k, None)
        try:
            r = {'id': raw['id'], 'match_id': int(raw['match_id']), 'match_key': None, 'source_url': raw['source_url'], 'video_id': raw['video_id'],
                 'roster': json.loads(raw['roster_json']), 'team_number': int(raw['team_number']), 'tag_key': raw['tag_key'],
                 'tag_label': raw['tag_label'], 'tag_category': raw['tag_category'], 'definition_version': raw['definition_version'],
                 'label_scope': raw['label_scope'], 'start_ms': None if raw['start_ms'] is None else int(raw['start_ms']),
                 'end_ms': None if raw['end_ms'] is None else int(raw['end_ms']), 'video_duration_ms': int(raw['video_duration_ms']),
                 'scoring':json.loads(raw['scoring_json']) if raw.get('scoring_json') else None, 'replaces_id':raw.get('replaces_id'),
                 'polarity':raw.get('polarity','present'), 'certainty': raw['certainty'], 'note': raw['note'], 'review_state': raw['review_state'], 'created_at': int(raw['created_at']),
                 'schema_version': 2, 'evidence_type': 'human_video_label', 'time_basis': 'video_elapsed_ms', 'source_sha256': None}
        except (KeyError, ValueError, TypeError) as error:
            problems.append({'id': raw.get('id'), 'reason': 'unreadable row: %s' % type(error).__name__})
            continue
        reason = validate_label(dict(r, match_key=r['match_key'] or ''))
        if reason:
            problems.append({'id': r['id'], 'reason': reason})
            continue
        labels.append(r)
    return labels, problems


# ------------------------------------------------------------------ ledger

class Store:
    """Folder holding read-only snapshots, the label ledger and verified sources."""

    def __init__(self, root):
        self.root = Path(root)
        (self.root / 'snapshots').mkdir(parents=True, exist_ok=True)

    def load(self, name, default):
        p = self.root / name
        return json.loads(p.read_text()) if p.is_file() else default

    def save(self, name, data):
        tmp = self.root / (name + '.tmp')
        tmp.write_text(json.dumps(data, indent=1, sort_keys=True))
        tmp.replace(self.root / name)

    def snapshot(self, kind, data_bytes, labels, problems, origin):
        stamp, digest = now(), sha256_bytes(data_bytes)
        base = self.root / 'snapshots' / ('%s-%s-%s' % (kind, stamp, digest[:12]))
        n = 1
        while base.with_suffix('.ndjson').exists():   # same content ingested again within a second
            n += 1
            base = self.root / 'snapshots' / ('%s-%s-%s-%d' % (kind, stamp, digest[:12], n))
        body = base.with_suffix('.ndjson')
        body.write_text(''.join(json.dumps(r, sort_keys=True) + '\n' for r in labels))
        meta = {'kind': kind, 'ingested_at': stamp, 'origin': origin, 'source_sha256': digest, 'labels': len(labels), 'problems': problems,
                'private_columns_dropped': list(PRIVATE_COLUMNS) if kind == 'sqldump' else [],
                'note': 'SQL dumps are stored only after private columns are dropped; the original dump file is not copied.' if kind == 'sqldump' else 'Public export stored as received (valid lines).'}
        base.with_suffix('.meta.json').write_text(json.dumps(meta, indent=1))
        read_only(body)
        read_only(base.with_suffix('.meta.json'))
        return body.name, meta

    def ingest(self, kind, labels, snapshot_name):
        """Update the ledger. Original records are never overwritten; changes are kept as revisions."""
        ledger = self.load('ledger.json', {})
        seen = {r['id'] for r in labels}
        for r in labels:
            entry = ledger.get(r['id'])
            state = 'withdrawn' if r['review_state'] == 'withdrawn' else 'rejected' if r['review_state'] == 'rejected' else r['review_state']
            if entry is None:
                ledger[r['id']] = {'first_seen': snapshot_name, 'last_seen': snapshot_name, 'state': state, 'original': r, 'revisions': [],
                                   'seen_in': [snapshot_name]}
                continue
            entry['last_seen'] = snapshot_name
            entry['seen_in'].append(snapshot_name)
            if entry['original'].get('match_key') is None and r.get('match_key'):
                entry['original']['match_key'] = r['match_key']   # SQL dumps carry no match key; fill it, nothing else
            content = lambda x: {k: x.get(k) for k in CONTENT_FIELDS}
            if content(r) != content(entry['original']):
                entry['revisions'].append({'snapshot': snapshot_name, 'record': r})
            withdrawn_now = state in ('withdrawn', 'rejected') and state != entry['state']
            back_in_export = kind == 'export' and entry['state'] == 'absent_from_latest_export'
            if withdrawn_now or back_in_export:
                entry.setdefault('state_history', []).append({'snapshot': snapshot_name, 'from': entry['state'], 'to': state})
                entry['state'] = state
        if kind == 'export':
            for label_id, entry in ledger.items():
                if label_id not in seen and entry['state'] in USABLE_STATES and any(s.startswith('export-') for s in entry['seen_in']):
                    entry.setdefault('state_history', []).append({'snapshot': snapshot_name, 'from': entry['state'], 'to': 'absent_from_latest_export'})
                    entry['state'] = 'absent_from_latest_export'   # withdrawn or rejected upstream; kept, not used
        self.save('ledger.json', ledger)
        return ledger


def ingest(store, path=None, url=None, kind='export'):
    if url:
        if not url.startswith('https://'):
            raise ValueError('Exports are fetched over HTTPS only.')
        try:
            import certifi
            context=ssl.create_default_context(cafile=certifi.where())
        except ImportError:
            context=ssl.create_default_context()
        with urllib.request.urlopen(url, timeout=60,context=context) as response:
            data = response.read(50 * 1024 * 1024)
        origin = url
    else:
        data = Path(path).read_bytes()
        origin = str(Path(path).name)
    text = data.decode('utf-8')
    labels, problems = read_export(text) if kind == 'export' else read_sql_dump(text)
    name, meta = store.snapshot(kind, data, labels, problems, origin)
    ledger = store.ingest(kind, labels, name)
    return {'snapshot': name, 'video_order':list(dict.fromkeys(r.get('video_id') for r in labels)), 'labels': len(labels), 'problems': len(problems),
            'states': {s: sum(1 for e in ledger.values() if e['state'] == s) for s in sorted({e['state'] for e in ledger.values()})}}


# ------------------------------------------------------------------ source verification

def probe_duration_ms(video):
    out = subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', str(video)], timeout=60)
    return int(round(float(out) * 1000))


def fetch_video(video_id, folder):
    """Download one YouTube video (1080p H.264, video only, as the helper does)."""
    target = Path(folder) / ('%s.mp4' % video_id)
    if target.is_file():
        return target
    url = 'https://www.youtube.com/watch?v=%s' % video_id
    try:
        from yt_dlp import YoutubeDL
        from yt_dlp.utils import DownloadError
        from neptune_setup import video_js_runtime
        options = {'format': 'bv[ext=mp4][vcodec^=avc1][height<=1080]/b[ext=mp4][height<=1080]', 'outtmpl': str(target),
                        'js_runtimes': video_js_runtime(), 'remote_components': [],
                        'socket_timeout': 30, 'retries': 2,
                        'quiet': True, 'no_warnings': True, 'noplaylist': True, 'max_filesize': 512 * 1024 * 1024}
        # A media URL can expire during a download. Retry once with freshly extracted
        # metadata, retaining the partial file; permanent restrictions still fail.
        for attempt in range(2):
            try:
                with YoutubeDL(options) as y:
                    y.extract_info(url, download=True)
                break
            except DownloadError as error:
                if attempt or '403' not in str(error):
                    raise
    except ImportError:
        subprocess.run(['yt-dlp', '-q', '-f', 'bv[ext=mp4][vcodec^=avc1][height<=1080]/b[ext=mp4][height<=1080]', '-o', str(target), url], check=True, timeout=1800)
    if not target.is_file():
        raise ValueError('Video %s could not be retrieved.' % video_id)
    return target


def verify_sources(store, videos, download=False, only_video_ids=None):
    """Hash and measure each referenced video; check every label against the verified duration."""
    ledger = store.load('ledger.json', {})
    sources = store.load('sources.json', {})
    videos = Path(videos)
    videos.mkdir(parents=True, exist_ok=True)
    by_video = {}
    for label_id, e in ledger.items():
        by_video.setdefault(e['original']['video_id'], []).append(label_id)
    for video_id, ids in sorted(by_video.items()):
        if only_video_ids is not None and video_id not in only_video_ids:continue
        local = videos / ('%s.mp4' % video_id)
        if not local.is_file():
            if not download:
                sources.setdefault(video_id, {'status': 'not_retrieved'})
                continue
            try:
                local = fetch_video(video_id, videos)
            except Exception as error:   # recorded, never guessed around
                sources[video_id] = {'status': 'retrieval_failed', 'reason': str(error)[:200]}
                continue
        stat=local.stat();stamp=[stat.st_dev,stat.st_ino,stat.st_size,stat.st_mtime_ns,stat.st_ctime_ns]
        prior=sources.get(video_id,{})
        reuse=prior.get('status')=='verified' and prior.get('file_stat')==stamp
        duration = prior['duration_ms'] if reuse else probe_duration_ms(local)
        source_sha = prior['sha256'] if reuse else sha256_file(local)
        reported = sorted({ledger[i]['original']['video_duration_ms'] for i in ids})
        checks = {}
        for i in ids:
            o = ledger[i]['original']
            problems = []
            if not external_label(o) and abs(o['video_duration_ms'] - duration) > DURATION_TOLERANCE_MS:
                problems.append('browser-reported duration %d ms differs from the verified %d ms' % (o['video_duration_ms'], duration))
            if not external_label(o) and o['end_ms'] is not None and o['end_ms'] > duration:
                problems.append('label ends after the verified video')
            checks[i] = problems
        sources[video_id] = {'status': 'verified', 'file': local.name, 'sha256': source_sha, 'duration_ms': duration, 'file_stat':stamp,
                             'verified_at': now(), 'browser_reported_durations_ms': reported,
                             'label_checks': {i: (p or 'ok') for i, p in checks.items()}}
    store.save('sources.json', sources)
    return sources


# ------------------------------------------------------------------ dataset

def event_key(match_key):
    return (match_key or '').split('_')[0]


def duplicate_groups(rows):
    """Group labels that likely describe the same action: same video, robot, tag and scope, and (for timed
    labels) starting within DUPLICATE_WINDOW_MS of the previous one. Every original stays in the dataset."""
    groups = {}
    keyed = {}
    for r in rows:
        keyed.setdefault((r['video_id'], r['team_number'], r['tag_key'], r['label_scope']), []).append(r)
    for key, items in keyed.items():
        items.sort(key=lambda r: (r['start_ms'] if r['start_ms'] is not None else -1, r['id']))
        current, last = [], None
        for r in items:
            t = r['start_ms']
            if current and (t is None or last is None or t - last <= DUPLICATE_WINDOW_MS):
                current.append(r)
            else:
                if current:
                    for x in current:
                        groups[x['id']] = current
                current = [r]
            last = t
        for x in current:
            groups[x['id']] = current
    return {i: {'duplicate_group': sha256_bytes(','.join(sorted(x['id'] for x in g)).encode())[:16], 'group_size': len(g)} for i, g in groups.items()}


def assign_split(key, eval_fraction, explicit):
    if key in explicit:
        return explicit[key]
    bucket = int(sha256_bytes(key.encode())[:8], 16) / 0xFFFFFFFF
    return 'evaluation' if bucket < eval_fraction else 'training'


def extract_frames(video, out_dir, label, duration_ms):
    """JPEG frames at elapsed-video offsets around a moment (or start/middle/end of an interval)."""
    if label['label_scope'] == 'match':
        return []
    if label['label_scope'] == 'moment':
        times = [label['start_ms'] + d for d in FRAME_OFFSETS_MS]
    else:
        times = [label['start_ms'], (label['start_ms'] + label['end_ms']) // 2, label['end_ms']]
    files = []
    out_dir.mkdir(parents=True, exist_ok=True)
    for t in times:
        if not 0 <= t < duration_ms:
            continue
        target = out_dir / ('%s_%+06d.jpg' % (label['id'], t - (label['start_ms'] or 0)))
        if not target.is_file():
            subprocess.run(['ffmpeg', '-nostdin', '-v', 'error', '-ss', '%.3f' % (t / 1000), '-i', str(video), '-frames:v', '1', '-q:v', '3', str(target)],
                           check=True, timeout=120)
        files.append({'t_ms': t, 'file': target.name})
    return files


def build(store, videos, out, split_by='event', eval_fraction=0.2, explicit=None, frames=True):
    """Dataset of contributed labels on verified videos. Nothing is labelled negative by omission."""
    out = Path(out)
    if out.exists() and any(out.iterdir()):
        raise FileExistsError('Dataset folder is not empty.')
    out.mkdir(parents=True, exist_ok=True)
    ledger = store.load('ledger.json', {})
    sources = store.load('sources.json', {})
    explicit = explicit or {}
    usable, excluded = [], []
    for label_id, e in sorted(ledger.items()):
        o = e['original']
        src = sources.get(o['video_id'], {})
        if e['state'] not in USABLE_STATES:
            excluded.append({'id': label_id, 'reason': 'state ' + e['state']})
        elif not o.get('match_key'):
            excluded.append({'id': label_id, 'reason': 'match key unknown (seen only in a SQL dump)'})
        elif src.get('status') != 'verified':
            excluded.append({'id': label_id, 'reason': 'source video not verified (%s)' % src.get('status', 'not_retrieved')})
        elif src['label_checks'].get(label_id, 'ok') != 'ok':
            excluded.append({'id': label_id, 'reason': '; '.join(src['label_checks'][label_id])})
        else:
            usable.append(o)
    dups = duplicate_groups(usable)
    split_keys = {}
    rows = []
    for o in usable:
        key = event_key(o['match_key']) if split_by == 'event' else o['match_key']
        split = assign_split(key, eval_fraction, explicit)
        split_keys.setdefault(key, set()).add(split)
        src = sources[o['video_id']]
        row = {'label_id': o['id'], 'match_id': o['match_id'], 'match_key': o['match_key'], 'event_key': event_key(o['match_key']),
               'video_id': o['video_id'], 'source_url': o['source_url'], 'source_sha256_verified': src['sha256'], 'source_duration_ms_verified': src['duration_ms'],
               'browser_reported_duration_ms': o['video_duration_ms'], 'roster': o['roster'], 'team_number': o['team_number'],
               'schema_version':o.get('schema_version'), 'season':o.get('season'), 'game_key':o.get('game_key'),
               'tag_key': o['tag_key'], 'tag_label': o['tag_label'], 'tag_category': o['tag_category'], 'definition_version': o['definition_version'],
               'label_scope': o['label_scope'], 'start_ms': o['start_ms'], 'end_ms': o['end_ms'], 'time_basis': 'video_elapsed_ms',
               'scoring':o.get('scoring'), 'replaces_id':o.get('replaces_id'),
               'polarity':o.get('polarity','present'), 'certainty': o['certainty'], 'note': o['note'], 'created_at': o['created_at'],
               'example_kind': 'explicit_negative' if o.get('polarity','present')=='absent' else 'absence_assessment' if o['label_scope'] == 'match' else 'positive_moment' if o['label_scope'] == 'moment' else 'positive_interval',
               'evidence': 'human_video_label (contributed, not independently verified)', 'split': split, 'split_by': split_by}
        row.update(dups.get(o['id'], {}))
        if frames:
            row['frames'] = [dict(f, file='media/%s/%s' % (o['video_id'], f['file'])) for f in
                             extract_frames(Path(videos) / src['file'], out / 'media' / o['video_id'], o, src['duration_ms'])]
        rows.append(row)
    leaks = sorted(k for k, s in split_keys.items() if len(s) > 1)
    if leaks:
        raise ValueError('Split leakage: %s appear in more than one split.' % ', '.join(leaks))
    with open(out / 'labels.jsonl', 'w') as f:
        for row in rows:
            f.write(json.dumps(row, sort_keys=True) + '\n')
    manifest = {'created_at': now(), 'rows': len(rows), 'excluded': excluded, 'split_by': split_by, 'eval_fraction': eval_fraction,
                'splits': {k: sorted(v)[0] for k, v in split_keys.items()},
                'counts': {s: sum(r['split'] == s for r in rows) for s in ('training', 'evaluation')},
                'duplicate_groups': len({r.get('duplicate_group') for r in rows}), 'snapshots': sorted(p.name for p in (store.root / 'snapshots').glob('*.ndjson')),
                'semantics': {'time_basis': 'elapsed source-video milliseconds, not the match clock',
                              'scoring': 'start of a scoring sequence; not game pieces, points or completed cycles (neptune-match-tags-v1 kept as recorded)',
                              'absence': 'whole-match/phase absence assessments have null timestamps',
                              'unlabeled': 'unknown; no negative examples are generated from unlabeled time or robots',
                              'certainty': 'observed/uncertain exactly as the contributor submitted',
                              'duplicates': 'near-duplicate labels share duplicate_group; all originals kept; deduplicate explicitly when training',
                              'review': 'public human tags are contributed without review; they are not independently verified ground truth'},
                'policy': 'Building a dataset does not train or update any model. Improvements require reviewed evaluation on held-out matches and a versioned release.'}
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=1))
    return manifest


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('command', choices=['ingest', 'verify', 'build', 'status'])
    ap.add_argument('--store', required=True, help='label store folder')
    ap.add_argument('--export', help='local NDJSON export file')
    ap.add_argument('--url', help='export URL (default: %s)' % EXPORT_URL)
    ap.add_argument('--sql', help='community_video_labels SQL dump (private columns are dropped on read)')
    ap.add_argument('--videos', help='folder of <video_id>.mp4 files')
    ap.add_argument('--download', action='store_true', help='retrieve missing videos with yt-dlp')
    ap.add_argument('--out', help='dataset folder')
    ap.add_argument('--split-by', choices=['event', 'match'], default='event')
    ap.add_argument('--eval-fraction', type=float, default=0.2)
    ap.add_argument('--splits', help='JSON {event_or_match_key: "training"|"evaluation"} overriding the hash split')
    ap.add_argument('--no-frames', action='store_true')
    a = ap.parse_args()
    store = Store(a.store)
    if a.command == 'ingest':
        if a.sql:
            print(json.dumps(ingest(store, path=a.sql, kind='sqldump')))
        else:
            print(json.dumps(ingest(store, path=a.export, url=None if a.export else (a.url or EXPORT_URL))))
    elif a.command == 'verify':
        print(json.dumps({k: {x: v.get(x) for x in ('status', 'sha256', 'duration_ms', 'browser_reported_durations_ms')} for k, v in verify_sources(store, a.videos, a.download).items()}, indent=1))
    elif a.command == 'build':
        explicit = json.loads(Path(a.splits).read_text()) if a.splits else None
        m = build(store, a.videos, a.out, a.split_by, a.eval_fraction, explicit, not a.no_frames)
        print(json.dumps({k: m[k] for k in ('rows', 'counts', 'splits', 'duplicate_groups')}, indent=1), '\nexcluded:', len(m['excluded']))
    else:
        ledger = store.load('ledger.json', {})
        print(json.dumps({s: sum(1 for e in ledger.values() if e['state'] == s) for s in sorted({e['state'] for e in ledger.values()})}))


if __name__ == '__main__':
    main()
