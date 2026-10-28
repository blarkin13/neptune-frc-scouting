"""Learning data for the facts pipeline: sealed originals, separate reviewer corrections, exports.

Nothing in this module trains, tunes or updates any model or parser, and nothing runs automatically.
Improvements are made by a developer from reviewed examples, must pass the held-out match evaluation
(evaluation/evaluate_facts.py), and ship as a new versioned helper release (PIPELINE_VERSION).

Bundle (one folder per analysis run, never reused):
  raw-overlay.jsonl, raw-results.jsonl, raw-bumpers.jsonl   original OCR readings, per frame or crop
  overlay/, results/, bumpers/                               the frames and crops those readings came from
  facts.json                                                 parser output: accepted facts and rejected readings
  draft-payload.json                                         the summary and observations exactly as generated
  manifest.json                                              versions and SHA-256 of every original file
  corrections.jsonl                                          append-only reviewer corrections, kept apart from
                                                             the originals (which are made read-only)
"""
import argparse
import datetime
import hashlib
import json
import os
import shutil
import stat
import sys
import uuid
from pathlib import Path

HERE = Path(__file__).resolve().parent
ORIGINALS = ('raw-overlay.jsonl', 'raw-results.jsonl', 'raw-bumpers.jsonl', 'facts.json', 'draft-payload.json','tag-predictions.json','evidence-report.txt')
MEDIA = ('overlay', 'results', 'bumpers')
TARGETS = ('match_identity', 'period', 'score_reading', 'results_screen', 'bumper_read', 'identity', 'observation', 'summary')
VERDICTS = ('correct', 'incorrect', 'corrected', 'unreadable')


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def versions(facts):
    import neptune_facts
    return {'pipeline': neptune_facts.PIPELINE_VERSION,
            'parser_sha256': sha256(HERE / 'neptune_facts.py'),
            'ocr_interface_sha256': sha256(HERE / 'neptune_ocr.py'),
            'season_rules_sha256': sha256(HERE / 'season_rules.json'),
            'layout_profile': (facts.get('layout') or {}).get('profile'),
            'ocr': facts.get('ocr'),
            'model': facts.get('tag_model')}   # no AI model is used by the facts pipeline


def now():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def seal(bundle, facts, payload, match):
    """Write the parser output and draft, hash every original, and make the originals read-only."""
    bundle = Path(bundle)
    if (bundle / 'manifest.json').exists():
        raise FileExistsError('This analysis bundle is already sealed; start a new run folder instead.')
    (bundle / 'facts.json').write_text(json.dumps(facts, indent=1, default=str))
    (bundle / 'draft-payload.json').write_text(json.dumps(payload, indent=1))
    files = {}
    for name in ORIGINALS:
        if (bundle / name).is_file():
            files[name] = sha256(bundle / name)
    for folder in MEDIA:
        for p in sorted((bundle / folder).glob('*')) if (bundle / folder).is_dir() else []:
            files[str(p.relative_to(bundle))] = sha256(p)
    manifest = {'bundle_id': uuid.uuid4().hex, 'created_at': now(), 'match_key': match.get('key'), 'match_id': match.get('id'),
                'roster': match.get('roster'), 'source_sha256': payload.get('source_sha256'), 'source_url': match.get('source_url'),
                'versions': versions(facts), 'files': files,
                'policy': 'Originals are read-only. Corrections go to corrections.jsonl. No automatic retraining.'}
    (bundle / 'manifest.json').write_text(json.dumps(manifest, indent=1))
    for rel in list(files) + ['manifest.json']:
        p = bundle / rel
        p.chmod(p.stat().st_mode & ~(stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH))
    return manifest


def load(bundle):
    bundle = Path(bundle)
    manifest = json.loads((bundle / 'manifest.json').read_text())
    facts = json.loads((bundle / 'facts.json').read_text())
    payload = json.loads((bundle / 'draft-payload.json').read_text())
    return manifest, facts, payload


def verify(bundle):
    """List originals that are missing or changed since sealing."""
    manifest = json.loads((Path(bundle) / 'manifest.json').read_text())
    return [rel for rel, h in manifest['files'].items() if not (Path(bundle) / rel).is_file() or sha256(Path(bundle) / rel) != h]


def find_original(facts, payload, target, ref):
    """The original fact or reading a correction refers to (copied into the correction record)."""
    if target == 'match_identity':
        return facts['identity']
    if target == 'period':
        p = facts['periods'].get(ref['name'])
        return None if p is None else {k: p[k] for k in ('start', 'end', 'uncertainty_s', 'start_observed', 'end_observed')}
    if target == 'score_reading':
        side, t = ref['side'], float(ref['t'])
        for s in (facts.get('accepted_scores') or {}).get(side, []) + facts['rejected']['score_readings'][side]:
            if abs(s['t'] - t) < 0.01:
                return s
        return None
    if target == 'results_screen':
        return {k: facts['results_screen'].get(k) for k in ('status', 'red', 'blue', 'components')}
    if target == 'bumper_read':
        for r in facts['bumpers']['reads']:
            if r['frame'] == ref['frame'] and r['token'] == ref['token'] and abs(r['t'] - float(ref['t'])) < 0.01:
                return r
        return None
    if target == 'identity':
        return facts['bumpers']['identities'].get(str(ref['team'])) or facts['bumpers']['identities'].get(int(ref['team']))
    if target == 'observation':
        i = int(ref['index'])
        return payload['observations'][i] if 0 <= i < len(payload['observations']) else None
    if target == 'summary':
        return payload['summary']
    return None


def add_correction(bundle, target, ref, verdict, reviewer, corrected=None, note=''):
    """Append one reviewer correction. The originals are never edited."""
    if target not in TARGETS:
        raise ValueError('Unknown correction target: %s' % target)
    if verdict not in VERDICTS:
        raise ValueError('Verdict must be one of: ' + ', '.join(VERDICTS))
    if verdict == 'corrected' and corrected is None:
        raise ValueError('A "corrected" verdict needs the corrected value.')
    if not reviewer:
        raise ValueError('Corrections must name the reviewer.')
    manifest, facts, payload = load(bundle)
    original = find_original(facts, payload, target, ref or {})
    if original is None:
        raise ValueError('The referenced %s was not found in this bundle.' % target)
    record = {'id': uuid.uuid4().hex, 'bundle_id': manifest['bundle_id'], 'match_key': manifest['match_key'], 'target': target,
              'ref': ref or {}, 'original': original, 'verdict': verdict, 'corrected': corrected, 'reviewer': reviewer,
              'reviewed_at': now(), 'note': note, 'versions_reviewed': manifest['versions']}
    with open(Path(bundle) / 'corrections.jsonl', 'a') as out:
        out.write(json.dumps(record, default=str) + '\n')
    return record


def mark_reviewed(bundle, reviewer, scope='complete', note=''):
    """Record that a reviewer finished reviewing this bundle (scope: complete, identities, scores ...)."""
    manifest, _, _ = load(bundle)
    with open(Path(bundle) / 'corrections.jsonl', 'a') as out:
        out.write(json.dumps({'id': uuid.uuid4().hex, 'bundle_id': manifest['bundle_id'], 'target': 'review_complete', 'scope': scope,
                              'reviewer': reviewer, 'reviewed_at': now(), 'note': note}) + '\n')


def corrections(bundle):
    p = Path(bundle) / 'corrections.jsonl'
    return [json.loads(l) for l in p.read_text().splitlines() if l.strip()] if p.is_file() else []


def review_status(bundle):
    c = corrections(bundle)
    scopes = {r['scope'] for r in c if r['target'] == 'review_complete'}
    if 'complete' in scopes:
        return 'reviewed'
    return 'partially_reviewed' if c else 'unreviewed'


def latest(cs, target, match):
    hits = [c for c in cs if c['target'] == target and all(str(c['ref'].get(k)) == str(v) for k, v in match.items())]
    return hits[-1] if hits else None


def export(bundles, out, splits=None):
    """Evaluation/training export. One JSONL file per example type; each row keeps the original reading,
    the reviewer label (or null), whether it was reviewed, the source references and every version."""
    out = Path(out)
    if out.exists() and any(out.iterdir()):
        raise FileExistsError('Export folder is not empty.')
    (out / 'media').mkdir(parents=True, exist_ok=True)
    splits = splits or {}
    files = {name: open(out / ('%s.jsonl' % name), 'w') for name in ('bumper_reads', 'identities', 'score_readings', 'results_screens', 'periods', 'drafts')}
    summary = []
    try:
        for bundle in map(Path, bundles):
            manifest, facts, payload = load(bundle)
            changed = verify(bundle)
            cs = corrections(bundle)
            status = review_status(bundle)
            base = {'bundle_id': manifest['bundle_id'], 'match_key': manifest['match_key'], 'source_sha256': manifest['source_sha256'],
                    'split': splits.get(manifest['match_key'], 'unassigned'), 'bundle_review_status': status, 'versions': manifest['versions']}
            media = out / 'media' / manifest['bundle_id']

            def copy(rel):
                target = media / rel
                if not target.exists():
                    target.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(bundle / rel, target)
                return str(target.relative_to(out))

            def label(c):
                if c is None:
                    return {'label_status': 'unreviewed', 'verdict': None, 'label': None, 'reviewer': None}
                return {'label_status': 'reviewed', 'verdict': c['verdict'], 'label': c.get('corrected'), 'reviewer': c['reviewer'], 'correction_id': c['id']}

            for r in facts['bumpers']['reads']:
                c = latest(cs, 'bumper_read', {'frame': r['frame'], 'token': r['token'], 't': r['t']})
                files['bumper_reads'].write(json.dumps(dict(base, original=r, image=copy(r['frame']), **label(c))) + '\n')
            for team, ident in facts['bumpers']['identities'].items():
                c = latest(cs, 'identity', {'team': team})
                files['identities'].write(json.dumps(dict(base, team=int(team), original=ident, images=[copy(e['frame']) for e in ident['evidence']], **label(c))) + '\n')
            for side in ('red', 'blue'):
                for kind, rows in (('accepted', (facts.get('accepted_scores') or {}).get(side, [])), ('rejected', facts['rejected']['score_readings'][side])):
                    for s in rows:
                        c = latest(cs, 'score_reading', {'side': side, 't': s['t']})
                        files['score_readings'].write(json.dumps(dict(base, side=side, parser_decision=kind, original=s, image=copy(s['frame']), **label(c))) + '\n')
            R = facts['results_screen']
            files['results_screens'].write(json.dumps(dict(base, original=R, images=[copy(e['frame']) for e in R.get('evidence', [])], **label(latest(cs, 'results_screen', {})))) + '\n')
            for name, p in facts['periods'].items():
                files['periods'].write(json.dumps(dict(base, period=name, original=p, **label(latest(cs, 'period', {'name': name})))) + '\n')
            files['drafts'].write(json.dumps(dict(base, original_summary=payload['summary'], original_observations=payload['observations'],
                                                  summary_review=label(latest(cs, 'summary', {})),
                                                  observation_reviews=[c for c in cs if c['target'] == 'observation'])) + '\n')
            summary.append(dict(base, corrections=len([c for c in cs if c['target'] != 'review_complete']), originals_changed=changed))
    finally:
        for f in files.values():
            f.close()
    (out / 'export-manifest.json').write_text(json.dumps({'created_at': now(), 'bundles': summary,
        'notes': 'label_status "reviewed" rows carry a human verdict; "unreviewed" rows are parser output only and must not be used as ground truth. '
                 'Splits are by match; keep held-out matches out of any tuning. Exporting does not train anything.'}, indent=1))
    return summary


def main():
    ap = argparse.ArgumentParser(description='Neptune facts learning data: corrections and export (no training).')
    sub = ap.add_subparsers(dest='cmd', required=True)
    c = sub.add_parser('correct', help='append a reviewer correction to a bundle')
    c.add_argument('--bundle', required=True)
    c.add_argument('--target', required=True, choices=TARGETS)
    c.add_argument('--ref', default='{}', help='JSON reference, e.g. {"team": 9128} or {"side": "red", "t": 41.0}')
    c.add_argument('--verdict', required=True, choices=VERDICTS)
    c.add_argument('--corrected', help='JSON corrected value')
    c.add_argument('--reviewer', required=True)
    c.add_argument('--note', default='')
    d = sub.add_parser('reviewed', help='mark a bundle review complete')
    d.add_argument('--bundle', required=True)
    d.add_argument('--reviewer', required=True)
    d.add_argument('--scope', default='complete')
    i = sub.add_parser('import-labels', help='turn an identity review labels file into corrections')
    i.add_argument('--labels', required=True)
    i.add_argument('--bundles', required=True, help='folder holding one bundle folder per match key')
    i.add_argument('--reviewer', required=True)
    e = sub.add_parser('export', help='write an evaluation/training export')
    e.add_argument('--bundles', nargs='+', required=True)
    e.add_argument('--out', required=True)
    e.add_argument('--splits', help='JSON {match_key: split}')
    s = sub.add_parser('status', help='review status and integrity of bundles')
    s.add_argument('--bundles', nargs='+', required=True)
    a = ap.parse_args()
    if a.cmd == 'correct':
        print(json.dumps(add_correction(a.bundle, a.target, json.loads(a.ref), a.verdict, a.reviewer,
                                        None if a.corrected is None else json.loads(a.corrected), a.note)))
    elif a.cmd == 'reviewed':
        mark_reviewed(a.bundle, a.reviewer, a.scope)
    elif a.cmd == 'import-labels':
        labels = json.loads(Path(a.labels).read_text())
        verdict = {'correct': 'correct', 'wrong_robot': 'incorrect', 'not_a_robot': 'incorrect', 'unreadable': 'unreadable'}
        for key, teams in labels.items():
            for team, v in teams.items():
                if v.get('label'):
                    add_correction(Path(a.bundles) / key, 'identity', {'team': team}, verdict[v['label']], a.reviewer, note=v['label'])
            mark_reviewed(Path(a.bundles) / key, a.reviewer, 'identities')
    elif a.cmd == 'export':
        splits = json.loads(Path(a.splits).read_text()) if a.splits else None
        print(json.dumps(export(a.bundles, a.out, splits), indent=1))
    elif a.cmd == 'status':
        for b in a.bundles:
            print(b, review_status(b), 'changed: ' + ', '.join(verify(b)) if verify(b) else 'originals intact')


if __name__ == '__main__':
    sys.path.insert(0, str(HERE))
    main()
