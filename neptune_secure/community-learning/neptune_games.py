"""Versioned game packs; selected from the match season, never from the previous run."""
import hashlib
import os
import json
import re
from pathlib import Path

HOME = Path.home() / '.neptune-helper'
BUILTIN = Path(__file__).with_name('season_rules.json')
TAG_KEYS = ('auto_no_attempt','auto_partial','auto_completed','auto_failed','no_scoring','scoring','slow_cycles','fast_cycles','no_defense','attempted_defense','mechanical_issues','disabled','recovered','end_no_attempt','end_partial','end_completed','end_failed','strong_support','penalty_risk')


def validate(profile, year):
    p = dict(profile)
    if not re.fullmatch(r'20\d{2}', str(year)) or not re.fullmatch(str(year)+r'-[a-z0-9-]{1,48}', p.get('game_key', '')):
        raise ValueError('A game pack needs a season and a matching versioned game key.')
    if not isinstance(p.get('game'), str) or not p['game'].strip():
        raise ValueError('Name the game.')
    if p.get('configured', True):
        for key in ('auto_seconds', 'teleop_seconds'):
            if type(p.get(key)) is not int or not 1 <= p[key] <= 300:
                raise ValueError('Configure positive autonomous and teleop lengths from the game manual.')
        if not p.get('source'):
            raise ValueError('Record the source for the game rules.')
    for key, definition in p.get('tags', {}).items():
        if key not in TAG_KEYS or not isinstance(definition, str) or not definition:
            raise ValueError('Use existing Neptune tags with explicit game-specific definitions.')
    for name, layout in p.get('layout_profiles', {}).items():
        if not isinstance(name, str) or layout.get('season') != int(year) or not 0 < layout.get('band', 0) <= 1 or not 0 <= layout.get('camera_top', -1) < 1:
            raise ValueError('Invalid broadcast layout season or crop bounds.')
        required={'clock','red_score','blue_score','title','red_roster','blue_roster','red_label','blue_label','shift','marker'}
        if not isinstance(layout.get('marker'),str) or not required.issubset(layout.get('regions',{})):raise ValueError('A broadcast layout needs its marker and all named OCR regions.')
        for region in layout.get('regions', {}).values():
            if len(region) != 4 or not all(type(x) in (int,float) and 0 <= x <= 1 for x in region) or region[0] >= region[1] or region[2] >= region[3]:
                raise ValueError('Broadcast regions must be normalized nonempty rectangles.')
    return p


def profiles(home=HOME):
    if home == HOME and os.environ.get("NEPTUNE_GAMES_HOME"):
        home = Path(os.environ["NEPTUNE_GAMES_HOME"])
    result = {y: validate(p,y) for y,p in json.loads(BUILTIN.read_text())['seasons'].items()}
    for file in sorted((Path(home)/'games').glob('*.json')):
        data=json.loads(file.read_text());year=str(data['season']);data=validate(data,year)
        # A saved copy of the same game can predate newer broadcast layouts; fill in the ones it lacks.
        built=result.get(year) or {}
        if built.get('game_key')==data.get('game_key') and built.get('layout_profiles'):
            data['layout_profiles']={**built['layout_profiles'],**data.get('layout_profiles',{})}
        result[year]=data
    return result


def fingerprint_content(value):
    # JSON servers can serialize 0.0 as 0. A coordinate is the same rule either way.
    if isinstance(value,dict):return {k:fingerprint_content(v) for k,v in value.items() if k!='fingerprint'}
    if isinstance(value,list):return [fingerprint_content(v) for v in value]
    if type(value)is float and value.is_integer():return int(value)
    return value


def select(match_key, home=HOME):
    m=re.match(r'^(20\d{2})', match_key or '')
    if not m: raise ValueError('A match key must identify its season.')
    p=profiles(home).get(m[1])
    if not p or not p.get('configured', True):
        raise ValueError('The %s game is not configured. Import its game pack after the rules are published; rules from another season will not be used.' % m[1])
    p=dict(p,season=int(m[1]))
    p['fingerprint']=hashlib.sha256(json.dumps(fingerprint_content(p),sort_keys=True,separators=(',',':')).encode()).hexdigest()
    return p


def install(file, home=HOME):
    data=json.loads(Path(file).read_text());year=str(data.get('season',''));data=validate(data,year)
    folder=Path(home)/'games';folder.mkdir(parents=True,exist_ok=True)
    target=folder/(year+'.json');tmp=target.with_suffix('.tmp');tmp.write_text(json.dumps(data,indent=2));tmp.replace(target)
    return data
