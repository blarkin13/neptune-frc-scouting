"""Game Builder label contract v1. No game files or action codes are rewritten.

A gb label is a distinct timestamped observation, not a legacy scoring-sequence
onset or a duration. Definition revisions are provenance, not inferred quantities.
"""
import re

CONTRACT = 'game-builder-moments-v1'
KEY = re.compile(r'gb:[A-Za-z0-9_][A-Za-z0-9_.:-]{0,119}\Z')
REVISION = re.compile(r'neptune-game-(?:v1|rev-[1-9][0-9]*)-[a-f0-9]{32}\Z')


def is_game_action(tag):
    return isinstance(tag, str) and tag.startswith('gb:')


def error(row, game=None):
    if not isinstance(row.get('tag_key'), str) or not KEY.fullmatch(row['tag_key']):
        return 'invalid_game_action_code'
    if row.get('schema_version') != 2 or row.get('evidence_type') != 'human_video_label':
        return 'unsupported_game_action_contract'
    if not isinstance(row.get('definition_version'), str) or not REVISION.fullmatch(row['definition_version']):
        return 'invalid_game_definition_revision'
    if row.get('time_basis') != 'video_elapsed_ms' or row.get('label_scope') != 'moment':
        return 'game_action_requires_video_moment'
    if type(row.get('start_ms')) is not int or row['start_ms'] < 0 or row.get('end_ms') != row['start_ms']:
        return 'invalid_game_action_timestamp'
    if row.get('scoring') is not None:
        return 'game_action_quantity_comes_from_definition'
    season = row.get('season')
    if type(season) is not int or not isinstance(row.get('game_key'), str) or not row['game_key'].startswith(str(season)+'-'):
        return 'invalid_game_action_season'
    if game and (season != game['season'] or row['game_key'] != game['game_key']):
        return 'game_action_game_mismatch'
    if not all(isinstance(row.get(k), str) and 0 < len(row[k]) <= 240 for k in ('tag_label', 'tag_category')):
        return 'invalid_game_action_description'
    return None


def definitions(rows):
    result = {}
    for row in rows:
        if not is_game_action(row.get('tag_key')):
            continue
        problem = error(row)
        if problem:
            raise ValueError(problem)
        tag = row['tag_key']
        item = result.setdefault(tag, {'contract': CONTRACT, 'game_key': row['game_key'],
            'label_scope': 'moment', 'time_basis': 'video_elapsed_ms', 'action_code': tag[3:], 'variants': []})
        variant = {key: row[key] for key in ('definition_version', 'tag_label', 'tag_category')}
        if variant not in item['variants']:
            item['variants'].append(variant)
    for item in result.values():
        item['variants'].sort(key=lambda r: (r['definition_version'], r['tag_label'], r['tag_category']))
    return result


def valid_definition(tag, definition, game_key):
    if not KEY.fullmatch(tag) or not isinstance(definition, dict):
        return False
    if (definition.get('contract') != CONTRACT or definition.get('game_key') != game_key
            or definition.get('action_code') != tag[3:] or definition.get('label_scope') != 'moment'
            or definition.get('time_basis') != 'video_elapsed_ms'):
        return False
    variants = definition.get('variants')
    return (isinstance(variants, list) and 0 < len(variants) <= 1000
        and all(isinstance(v, dict) and isinstance(v.get('definition_version'), str)
            and REVISION.fullmatch(v['definition_version']) and all(isinstance(v.get(k), str)
            and 0 < len(v[k]) <= 240 for k in ('tag_label', 'tag_category')) for v in variants))
