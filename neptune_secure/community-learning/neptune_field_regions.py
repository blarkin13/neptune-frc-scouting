"""Source-bound field geometry supporting action predictions, never proving scores."""
import bisect,hashlib,json,math,re
from pathlib import Path
VERSION='source-bound-field-context-v1'
KINDS={'scoring_target','scoring_zone','feeding_zone','collection_zone','field_boundary','field_calibration','field_reference'}

def validate(d):
    if d.get('contract')!='neptune-field-regions-v1' or d.get('schema_version')!=1 or d.get('coordinate_system')!='normalized_full_video_frame' or d.get('time_basis')!='video_elapsed_ms':raise ValueError('Unsupported field-region contract')
    if not isinstance(d.get('regions'),list) or len(d['regions'])>500:raise ValueError('Invalid region count')
    for r in d['regions']:
        ps=r.get('points',[])
        if r.get('kind') not in KINDS or r.get('camera_view') not in {'wide','closeup-left','closeup-right','other'} or r.get('alliance') not in {'red','blue','neutral'} or r.get('reviewed') is not True:raise ValueError('Invalid region metadata')
        if r.get('kind')=='field_reference':
            # A point or a two-point line on the field, named after a game-pack field reference.
            if not 1<=len(ps)<=2 or not isinstance(r.get('reference'),str) or not re.fullmatch(r'[a-z0-9_]{1,64}',r['reference']):raise ValueError('Invalid field reference')
        elif not 3<=len(ps)<=100:raise ValueError('Invalid normalized polygon')
        if any(not isinstance(p,list) or len(p)!=2 or any(type(x) not in (int,float) or not math.isfinite(x) or not 0<=x<=1 for x in p) for p in ps):raise ValueError('Invalid normalized polygon')
        for key in ('corner_indices', 'inferred_corner_indices'):
            if key in r:
                indices = r[key]
                if r['kind'] not in {'field_boundary', 'field_calibration'} or not isinstance(indices, list) or any(type(i) is not int or not 0 <= i < len(ps) for i in indices) or len(set(indices)) != len(indices):
                    raise ValueError('Invalid corner vertex indices')
        if set(r.get('corner_indices', [])) & set(r.get('inferred_corner_indices', [])):
            raise ValueError('A corner cannot be both confirmed and inferred')
        ts=[r.get(k) for k in ('start_ms','reference_ms','end_ms')]
        if any(type(t) not in (int,float) or not math.isfinite(t) for t in ts) or not 0<=ts[0]<=ts[1]<=ts[2]:raise ValueError('Invalid time interval')
        if not isinstance(r.get('game_key'),str) or not r['game_key']:raise ValueError('Missing game key')
    from neptune_homography import calibrate
    for r in d['regions']:
        if r['kind']=='field_calibration':calibrate(r['points'])
    return d

def inside(point,polygon):
    x,y=point;hit=False
    for a,b in zip(polygon,polygon[1:]+polygon[:1]):
        ax,ay=a;bx,by=b
        cross=(x-ax)*(by-ay)-(y-ay)*(bx-ax)
        if abs(cross)<1e-10 and min(ax,bx)<=x<=max(ax,bx) and min(ay,by)<=y<=max(ay,by):return True
        if (ay>y)!=(by>y) and x<(bx-ax)*(y-ay)/(by-ay)+ax:hit=not hit
    return hit

def event_key(match_key):
    return str(match_key or '').split('_')[0]


def match_key_of(facts):
    """The match this video was verified to show (overlay title + roster), or None."""
    checks=facts.get('identity',{}).get('checks',{}).get('match_number',{})
    return checks.get('claimed_key') if checks.get('status')=='match' else None


def side_order(points):
    """'red_left' / 'red_right' from mean x positions, or None when either colour is missing or too close."""
    red=[x for c,x in points if c=='red'];blue=[x for c,x in points if c=='blue']
    if len(red)<3 or len(blue)<3:return None
    a,b=sum(red)/len(red),sum(blue)/len(blue)
    if abs(a-b)<.08:return None
    return 'red_left' if a<b else 'red_right'


def marked_sides(d):
    """Which side the reference match had red on, from where its red and blue wide-view regions were drawn."""
    pts=[(r['alliance'],sum(q[0] for q in r['points'])/len(r['points'])) for r in d['regions']
         if r['camera_view']=='wide' and r['alliance'] in ('red','blue')]
    red=[x for c,x in pts if c=='red'];blue=[x for c,x in pts if c=='blue']
    if not red or not blue:return None
    return side_order([(c,x) for c,x in pts for _ in range(3)])


def match_sides(facts,seconds=5.0):
    """Which side red starts on in this match: wide-view bumper positions in the first seconds of auto."""
    auto=(facts.get('periods') or {}).get('auto') or {}
    if type(auto.get('start')) not in (int,float):return None
    a=auto['start']
    pts=[(o.get('alliance'),o['box'][0]+o['box'][2]/2) for o in facts.get('tracking',{}).get('observations',[])
         if o.get('camera')=='wide' and a<=o.get('t',-1)<=a+seconds and len(o.get('box') or [])==4]
    return side_order(pts)


def event_document(facts,game_key,docs):
    """Apply an event's marked regions to another match at that event.

    Region times belong to the reference video, so only the camera view is kept. Alliance labels swap
    when red starts on the other side; when this match's sides can't be read, nothing is applied.
    """
    for d in docs:
        d=validate(d)
        regions=[r for r in d['regions'] if r['game_key']==game_key]
        if not regions:continue
        reference,here=marked_sides(dict(d,regions=regions)),match_sides(facts)
        coloured=any(r['alliance']!='neutral' for r in regions)
        if coloured and (reference is None or here is None):
            return {'status':'sides_unknown','regions':[],'source_sha256':facts['source']['sha256']}
        flip=coloured and reference!=here
        swap={'red':'blue','blue':'red','neutral':'neutral'}
        applied=[dict(r,alliance=swap[r['alliance']] if flip else r['alliance'],event_template=True,start_ms=0,reference_ms=0,end_ms=10**9) for r in regions]
        return dict(d,regions=applied,source_sha256=facts['source']['sha256'],application='event_template',
                    reference_source=d.get('source',{}).get('name'),sides=here,sides_swapped=bool(flip))
    return None


def load(facts,game_key,root=None,allow_detected=False):
    """Regions marked on this exact video, else the event's marked regions, else (only when asked for) regions the
    field detector found in this video, else None. Scouting does not use detected regions: an unmarked event runs
    without scoring-zone hints until one of its matches is marked."""
    sha=facts.get('source',{}).get('sha256','')
    if len(sha)!=64 or any(c not in '0123456789abcdef' for c in sha):return None
    folder=Path(root) if root else Path.home()/'.neptune-trainer/field-regions'
    path=folder/(sha+'.json')
    if not root and not path.is_file():path=Path(__file__).with_name('field-regions')/(sha+'.json')
    if path.is_file():
        d=validate(json.loads(path.read_text()))
        if d.get('source_sha256')!=sha:raise ValueError('Region source checksum mismatch')
        return dict(d,regions=[r for r in d['regions'] if r['game_key']==game_key])
    event=event_key(match_key_of(facts))
    events=folder/'events'/(event+'.json')
    if event and events.is_file():
        d=event_document(facts,game_key,json.loads(events.read_text()))
        if d and d.get('regions'):return d
    detected=folder/'detected'/(sha+'.json')
    if allow_detected and detected.is_file():
        d=json.loads(detected.read_text())
        if d.get('source_sha256')==sha and d.get('regions'):return dict(d,regions=[r for r in d['regions'] if r['game_key']==game_key])
    return None


def detect_if_unmarked(video,facts,game_key,progress=lambda s:None):
    """Run the learned field detector for a video with no marked regions; cache its result for this video."""
    if load(facts,game_key,allow_detected=False):return {'status':'marked_regions_available'}
    from neptune_field_detector import detect,reference
    ref=reference(game_key)
    if not ref:return {'status':'no_field_detector'}
    progress('Finding scoring areas and field corners')
    try:result=detect(video,facts,ref,game_key=game_key)
    except Exception as error:return {'status':'detector_failed','error_type':type(error).__name__}
    if result.get('document'):
        folder=Path.home()/'.neptune-trainer/field-regions/detected';folder.mkdir(parents=True,exist_ok=True)
        target=folder/(facts['source']['sha256']+'.json');temp=target.with_suffix('.pending')
        temp.write_text(json.dumps(result['document'],allow_nan=False));temp.replace(target)
    return {k:v for k,v in result.items() if k!='document'}

def active(r,t,camera,facts):
    if r['camera_view']!=camera or not r['start_ms']<=t*1000<=r['end_ms']:return False
    # Event regions come from another video: only the camera view carries over, not its cut timeline.
    if r.get('event_template'):return True
    cuts=facts.get('tracking',{}).get('camera_cuts',{}).get(camera)
    if cuts is None:return False
    # Fixed geometry never carries across a detected camera cut.
    return bisect.bisect_right(sorted(cuts),r['reference_ms']/1000)==bisect.bisect_right(sorted(cuts),t)

def context(p,d,facts):
    if p.get('occluded') or p.get('box_source')=='optical_flow':return None
    if p.get('identity_method') not in {'direct_vote_track','yolo_botsort_ocr','human_reviewed_track','roster_inferred','constant_identity'}:return None
    camera=p.get('camera');box=p.get('box',[])
    if len(box)!=4:return None
    foot=[box[0]+box[2]/2,box[1]+box[3]]
    alliance=(facts.get('bumpers',{}).get('identities',{}).get(str(p['team'])) or {}).get('alliance')
    applicable=[r for r in d['regions'] if active(r,p['t'],camera,facts) and r['alliance'] in ('neutral',alliance)]
    zones=[{'id':r['id'],'kind':r['kind'],'label':r.get('label',''),'alliance':r['alliance']} for r in applicable if r['kind'] not in ('scoring_target','field_reference') and inside(foot,r['points'])]
    targets=[]
    for r in applicable:
        if r['kind']!='scoring_target':continue
        centre=[sum(q[i] for q in r['points'])/len(r['points']) for i in (0,1)]
        targets.append({'id':r['id'],'alliance':r['alliance'],'normalized_distance':round(math.dist(foot,centre),5)})
    return {'version':VERSION,'camera':camera,'position_basis':'estimated_box_bottom_center','zones':zones,'targets':targets,'scoring_proven':False}

def scoring_reach(sight,d,facts):
    """True if the robot stands inside its alliance's (or a neutral) marked scoring zone, False if such zones
    exist for this view and it is outside them, None if no zones apply.

    Measured on 2026txcmp1 QM3/QM4: 96-100% of hand-labelled scoring moments were inside the zone versus
    81-89% of all sightings, so outside is a sign of a misplaced label; inside proves nothing. Distance to
    the scoring target did not separate them (robots shoot from range), so it is not used.
    """
    if not d or sight.get('occluded') or sight.get('box_source')=='optical_flow':return None
    box=sight.get('box') or [];camera=sight.get('camera','wide')
    if len(box)!=4:return None
    foot=[box[0]+box[2]/2,box[1]+box[3]]
    alliance=(facts.get('bumpers',{}).get('identities',{}).get(str(sight.get('team'))) or {}).get('alliance')
    zones=[r for r in d['regions'] if r['kind']=='scoring_zone' and r['alliance'] in ('neutral',alliance) and active(r,sight['t'],camera,facts)]
    if not zones:return None
    return any(inside(foot,r['points']) for r in zones)


def enrich(tags,facts,game_key,root=None):
    d=load(facts,game_key,root)
    if not d:return list(tags),{'status':'no_matching_source_annotations','version':VERSION}
    sightings={}
    for p in facts.get('tracking',{}).get('localizations',[]):sightings.setdefault(p['team'],[]).append(p)
    result=[];matched=0
    for tag in tags:
        row=dict(tag);t=tag['start_ms']/1000
        candidates=[p for p in sightings.get(tag['team'],[]) if abs(p['t']-t)<=.3]
        evidence=[(abs(p['t']-t),context(p,d,facts)) for p in candidates]
        evidence=[(dt,c) for dt,c in evidence if c and (c['zones'] or c['targets'])]
        if evidence:
            row['field_context']=min(evidence,key=lambda v:v[0])[1];matched+=1
        result.append(row)
    return result,{'status':'applied_as_supporting_context','version':VERSION,'regions':len(d['regions']),'tags_with_context':matched,'source_sha256':d['source_sha256'],'limitation':'Fixed camera geometry; does not establish scoring or change model probabilities.'}

def observations(facts,game_key,root=None):
    d=load(facts,game_key,root)
    if not d:return {'status':'no_matching_source_annotations','robots':[]}
    robots={}
    seen=set()
    for p in facts.get('tracking',{}).get('localizations',[]):
        c=context(p,d,facts)
        if not c:continue
        row=robots.setdefault(p['team'],{'team':p['team'],'zone_observations':{}})
        for z in c['zones']:
            key=(p['team'],z['id'],round(p['t'],3))
            if key in seen:continue
            seen.add(key);row['zone_observations'][z['kind']]=row['zone_observations'].get(z['kind'],0)+1
    return {'status':'observed_context_only','source_sha256':d['source_sha256'],'regions':len(d['regions']),'robots':list(robots.values()),'scoring_proven':False}

def sync(config,video,facts):
    """Fetch this owner's saved geometry through the existing helper bearer auth."""
    import urllib.request
    from neptune_helper import public_https,NoRedirect
    sha=facts['source']['sha256'];video=Path(video)
    try:
        # A missing connection or video is "unavailable", like a network failure; never fatal.
        url=public_https(config['site'].rstrip('/')+'/api/field-regions.php')
        request=urllib.request.Request(url,data=json.dumps({'video_name':video.name,'video_size':video.stat().st_size,'source_sha256':sha}).encode(),headers={'Content-Type':'application/json','Authorization':'Bearer '+config['token']})
        with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect()).open(request,timeout=20) as response:
            raw=response.read(2097153)
        if len(raw)>2097152:raise ValueError('Region document too large')
        document=json.loads(raw).get('document')
        if document is None:return {'status':'no_cloud_annotations'}
        d=validate(document)
        # Regions marked on the YouTube stream have no file size; the video ID (file name) binds them.
        size=d['source'].get('size')
        if d['source']['name']!=video.name or (size is not None and size!=video.stat().st_size) or d.get('source_sha256',sha)!=sha:raise ValueError('Region source mismatch')
        d=dict(d,source_sha256=sha,binding_method='verified_local_video_and_authenticated_owner_export')
        # User-owned data goes outside the signed app bundle.
        folder=Path.home()/'.neptune-trainer/field-regions';folder.mkdir(parents=True,exist_ok=True)
        target=folder/(sha+'.json');temp=target.with_suffix('.pending');temp.write_text(json.dumps(d,allow_nan=False));temp.replace(target)
        return {'status':'synced','regions':len(d['regions'])}
    except Exception as error:
        return {'status':'cloud_sync_unavailable','error_type':type(error).__name__}


def sync_events(config,events):
    """Download this owner's saved regions for each event so training and tests can apply them."""
    import urllib.request
    from neptune_helper import public_https,NoRedirect
    out={}
    for event in sorted({e for e in events if e and re.fullmatch(r'[0-9]{4}[a-z0-9]{1,20}',e)}):
        try:
            url=public_https(config['site'].rstrip('/')+'/api/field-regions.php')
            request=urllib.request.Request(url,data=json.dumps({'event':event}).encode(),headers={'Content-Type':'application/json','Authorization':'Bearer '+config['token']})
            with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect()).open(request,timeout=20) as response:
                raw=response.read(8388609)
            if len(raw)>8388608:raise ValueError('Region documents too large')
            docs=[validate(d) for d in json.loads(raw).get('documents',[])]
            folder=Path.home()/'.neptune-trainer/field-regions/events';folder.mkdir(parents=True,exist_ok=True)
            target=folder/(event+'.json');temp=target.with_suffix('.pending');temp.write_text(json.dumps(docs,allow_nan=False));temp.replace(target)
            out[event]={'status':'synced','documents':len(docs)}
        except Exception as error:
            out[event]={'status':'cloud_sync_unavailable','error_type':type(error).__name__}
    return out
