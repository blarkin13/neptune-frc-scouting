"""Match-long identity records over observed tracklets; unseen frames stay unknown.

OCR-supported tracklets reconnect to one stable roster identity across cameras and
long gaps. Camera-local appearance may propose unnumbered returns, but proposals
never become OCR anchors or training supervision. This offline layer sees later
observations; it does not claim to predict an off-screen robot's location/actions.
"""
import collections
import hashlib
import json
import math
import statistics

VERSION = 'persistent-roster-tracklets-v1'
MAX_TRACKLET_GAP = 1.0
APPEARANCE_MAX_DISTANCE = .08
APPEARANCE_MIN_MARGIN = .04


def key(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()[:24]


def visual_distance(a,b):
    if not isinstance(a,list) or not isinstance(b,list) or len(a)!=24 or len(b)!=24:
        return None
    if any(type(v) not in (int,float) or not math.isfinite(v) or not 0<=v<=1 for v in a+b):
        return None
    return sum(abs(x-y) for x,y in zip(a,b))/6


def fragments(observations,source):
    groups=collections.defaultdict(list)
    for original in observations:
        p=dict(original)
        if p.get('box_source')=='optical_flow':continue
        box=p.get('box')
        if (type(p.get('t')) not in (int,float) or not math.isfinite(p['t']) or p['t']<0
            or not isinstance(box,list) or len(box)!=4
            or any(type(v) not in (int,float) or not math.isfinite(v) for v in box)
            or min(box)<0 or min(box[2:])<=0 or box[0]+box[2]>1.001 or box[1]+box[3]>1.001):
            raise ValueError('Invalid persistent-identity observation')
        if p.get('track_id') is None:continue
        groups[(p['camera'],str(p['scene']),p['alliance'],str(p['track_id']))].append(p)
    out=[]
    for group,points in sorted(groups.items()):
        run=[]
        for p in sorted(points,key=lambda q:q['t']):
            split=False
            if run:
                prev=run[-1];dt=p['t']-prev['t']
                a,b=prev['box'],p['box'];dist=math.hypot(a[0]+a[2]/2-b[0]-b[2]/2,a[1]+a[3]/2-b[1]-b[3]/2)
                # A recycled track number or a jump across the frame isn't continuity.
                split=dt>MAX_TRACKLET_GAP or dt<=0 or (dt<=.5 and dist>max(.12,3*max(a[2],b[2])))
            if split:
                out.append({'id':key([source,group,run[0]['t']]),'group':group,'points':run});run=[]
            run.append(p)
        if run:out.append({'id':key([source,group,run[0]['t']]),'group':group,'points':run})
    return sorted(out,key=lambda n:(n['points'][0]['t'],n['id']))


def overlaps_time(a,b):
    if a['group'][0]!=b['group'][0]:return False
    # Independent tracker IDs cannot both represent the same robot in one camera.
    times={round(p['t']*3) for p in a['points']}
    return any(round(p['t']*3) in times for p in b['points'])


def build(observations,roster,source_sha256):
    from neptune_identity import roster_check
    roster_check(roster)
    if not isinstance(source_sha256,str) or len(source_sha256)!=64 or any(c not in '0123456789abcdef' for c in source_sha256):
        raise ValueError('A SHA-256 video fingerprint is required')
    nodes=fragments(observations,source_sha256)
    for n in nodes:
        ps=n['points'];alliance=n['group'][2]
        if alliance not in roster:raise ValueError('Invalid observation alliance')
        anchors={p['verified_team'] for p in ps if p.get('verified_team') is not None}
        if anchors-set(roster[alliance]):raise ValueError('Anchor outside alliance roster')
        n.update(team=None,status='unknown',reason='no_identity_evidence',training_eligible=False)
        if len(anchors)>1:n['reason']='conflicting_ocr_anchors'
        elif anchors:
            team=next(iter(anchors))
            # Conflicting single reads also stop whole-track propagation.
            contradictions=[p for p in ps if p.get('read_teams') and set(p['read_teams'])!={team}]
            if contradictions:n['reason']='conflicting_number_reads'
            else:n.update(team=team,status='ocr_supported',reason='ocr_supported_tracklet')
        usable=[p['appearance'] for p in ps if not p.get('occluded') and p.get('detection_confidence',0)>=.2 and visual_distance(p.get('appearance'),p.get('appearance')) is not None]
        n['appearance']=[statistics.median(v[i] for v in usable) for i in range(24)] if usable else None
    # Conflicting anchored tracklets remain a review item, never a convenient tie-break.
    for i,n in enumerate(nodes):
        if n['team'] is None:continue
        for other in nodes[i+1:]:
            if other['team']==n['team'] and overlaps_time(n,other):
                n['blocked_conflict']=other['blocked_conflict']=True
    for n in nodes:
        if n.get('blocked_conflict'):n.update(team=None,status='unknown',reason='simultaneous_identity_conflict')
    bank=collections.defaultdict(list)
    for n in nodes:
        if n['team'] is not None and n['appearance'] is not None:
            bank[(n['group'][0],n['team'])].append(n)
    proposals=[]
    for n in nodes:
        if n['team'] is not None or n['reason']!='no_identity_evidence' or n['appearance'] is None:continue
        if len(n['points'])<2 or n['points'][-1]['t']-n['points'][0]['t']<.3:continue
        scores=[]
        for team in roster[n['group'][2]]:
            donors=bank.get((n['group'][0],team),[])
            scored=[(visual_distance(n['appearance'],d['appearance']),d) for d in donors if not overlaps_time(n,d)]
            if scored:
                distance,donor=min(scored,key=lambda pair:pair[0]);scores.append((distance,team,donor))
        scores.sort(key=lambda row:(row[0],row[1]))
        n['candidate_scores']=[{'team':team,'distance':round(distance,5)} for distance,team,_ in scores]
        if len(scores)<2:n['reason']='insufficient_appearance_competitors';continue
        best,team,donor=scores[0];margin=scores[1][0]-best
        if best>APPEARANCE_MAX_DISTANCE or margin<APPEARANCE_MIN_MARGIN:
            n['reason']='ambiguous_appearance';continue
        # Any contradictory read blocks the candidate, even though it was not a seed.
        if any(p.get('read_teams') and set(p['read_teams'])!={team} for p in n['points']):
            n['reason']='appearance_contradicts_number_read';continue
        proposals.append((best,-margin,n,team,donor))
    competing=set()
    for i,(distance,_,n,team,_) in enumerate(proposals):
        for other_distance,_,other,other_team,_ in proposals[i+1:]:
            if team==other_team and overlaps_time(n,other) and abs(distance-other_distance)<APPEARANCE_MIN_MARGIN:
                competing.update((n['id'],other['id']))
    for distance,negmargin,n,team,donor in sorted(proposals,key=lambda row:(row[0],row[1],row[2]['id'])):
        if n['id'] in competing:
            n['reason']='competing_visual_candidates';continue
        if any(other['team']==team and overlaps_time(n,other) for other in nodes if other is not n):
            n['reason']='identity_already_visible';continue
        n.update(team=team,status='appearance_proposal',reason='camera_local_visual_reidentification',
                 evidence={'anchor_tracklet':donor['id'],'distance':round(distance,5),'margin':round(-negmargin,5),
                           'uses_future_evidence':donor['points'][0]['t']>n['points'][-1]['t']})
    records=[];points=[];returns=[]
    for alliance,teams in roster.items():
        for team in teams:
            identity=key([source_sha256,team]);assigned=[n for n in nodes if n['team']==team]
            spans=[]
            for n in assigned:
                span={'tracklet_id':n['id'],'camera':n['group'][0],'scene':n['group'][1],
                      'start':n['points'][0]['t'],'end':n['points'][-1]['t'],'status':n['status'],'reason':n['reason']}
                if n.get('evidence'):span['evidence']=n['evidence']
                spans.append(span)
                for p in n['points']:
                    points.append({**p,'team':team,'persistent_id':identity,'persistent_tracklet':n['id'],
                                   'persistent_status':n['status'],'training_eligible':False,'identity_anchor':False})
            for camera in sorted({span['camera'] for span in spans}):
                previous=None
                for span in sorted((v for v in spans if v['camera']==camera),key=lambda v:v['start']):
                    if previous and span['start']-previous['end']>MAX_TRACKLET_GAP:
                        returns.append({'team':team,'persistent_id':identity,'camera':camera,'last_seen':previous['end'],
                            'returned_at':span['start'],'unobserved_seconds':round(span['start']-previous['end'],3),
                            'from_tracklet':previous['tracklet_id'],'to_tracklet':span['tracklet_id'],
                            'status':'ocr_reconfirmed' if span['status']==previous['status']=='ocr_supported' else 'visual_match_needs_review',
                            'training_eligible':False,'gap_position_known':False})
                    previous=span
            records.append({'team':team,'alliance':alliance,'persistent_id':identity,'visible_spans':spans,
                            'last_seen':max((p['end'] for p in spans),default=None)})
    global_returns=[]
    for record in records:
        supported=sorted({p['t'] for p in points if p['team']==record['team'] and p['persistent_status']=='ocr_supported'})
        for a,b in zip(supported,supported[1:]):
            if b-a>MAX_TRACKLET_GAP:
                global_returns.append({'team':record['team'],'persistent_id':record['persistent_id'],
                    'last_seen':a,'returned_at':b,'unobserved_seconds':round(b-a,3),
                    'status':'ocr_reconfirmed','gap_position_known':False,'training_eligible':False})
    metadata=[{k:v for k,v in n.items() if k not in ('points','appearance')}|{'start':n['points'][0]['t'],'end':n['points'][-1]['t'],'samples':len(n['points'])} for n in nodes]
    return {'version':VERSION,'source_sha256':source_sha256,'roster':roster,'records':records,'points':sorted(points,key=lambda p:(p['t'],p['camera'],p['team'])),
            'tracklets':metadata,'global_returns':sorted(global_returns,key=lambda r:(r['returned_at'],r['team'])),'returns':sorted(returns,key=lambda r:(r['returned_at'],r['team'])),
            'summary':{'tracklets':len(nodes),'tracklet_statuses':dict(collections.Counter(n['status'] for n in nodes)),
                       'assigned_observations':len(points),'global_ocr_reconfirmed_returns':len(global_returns),'ocr_reconfirmed_returns':sum(r['status']=='ocr_reconfirmed' for r in returns),
                       'visual_return_proposals':sum(r['status']=='visual_match_needs_review' for r in returns)},
            'limitations':['Off-screen position and actions remain unknown.','Visual proposals use existing color descriptors, not a trained robot-specific ReID network.',
                            'No proposal is a training label or identity anchor.','Identity records persist across cameras; visual matching is camera-local.']}


def state_at(document,t,tolerance=.18,include_proposals=False):
    if type(t) not in (int,float) or not math.isfinite(t) or t<0:raise ValueError('Invalid video time')
    out=[]
    for record in document['records']:
        points=[p for p in document['points'] if p['team']==record['team'] and (include_proposals or p['persistent_status']=='ocr_supported')]
        visible=[p for p in points if abs(p['t']-t)<=tolerance]
        past=[p for p in points if p['t']<=t]
        out.append({'team':record['team'],'persistent_id':record['persistent_id'],
                    'state':'visible' if visible else 'not_observed','boxes':[{'camera':p['camera'],'box':p['box']} for p in visible],
                    'last_seen':max((p['t'] for p in past),default=None),'offscreen_actions':'unknown'})
    return out
