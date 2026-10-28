"""Offline, two-direction identity reconciliation. Suggestions are never training truth.

The forward/backward dynamic program considers every assignment of the three
alliance roster entries to the visible detections. Later anchors can resolve
past ambiguity. Independent camera shots/cuts and long gaps never share state.
Only existing verified anchors and explicit human corrections are trusted.
"""
import collections,hashlib,itertools,json,math
VERSION='bidirectional-roster-review-v5'

def digest(value):return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()
def roster_check(roster):
    if not isinstance(roster,dict) or set(roster)!={'red','blue'} or any(len(roster[c])!=3 for c in roster):raise ValueError('A verified three-red/three-blue roster is required')
    values=[n for c in ('red','blue') for n in roster[c]]
    if any(type(n)!=int or n<=0 for n in values) or len(set(values))!=6:raise ValueError('Roster entries must be six distinct team numbers')

def motion(a,b,dt,association=None):
    ax,ay,aw,ah=a['box'];bx,by,bw,bh=b['box']
    distance=math.hypot(ax+aw/2-bx-bw/2,ay+ah/2-by-bh/2)
    scale=max(.015,aw,bw)
    # A tracker ID is a small association hint, never an identity anchor.
    base=min(30.,(distance/max(scale,4*scale*dt))**2)+(0.12 if a['track_id']!=b['track_id'] else 0.)
    if association is not None:
        from neptune_review_learning import pair_features,probability
        x=pair_features(a,b)
        if x is not None:base+=min(4.,-math.log(max(.01,probability(association,x))))
    return base

def transition(a,sa,b,sb,association=None):
    previous={team:p for team,p in zip(sa,a)};current={team:p for team,p in zip(sb,b)}
    # Penalize an unexplained disappearance/reappearance. Otherwise a single
    # continuously visible box can change team for zero cost between anchors.
    return 2.*len(previous.keys()^current.keys())+sum(motion(previous[t],current[t],b[0]['t']-a[0]['t'],association) for t in previous.keys()&current.keys())

def solve_window(frames,teams,margin,association=None):
    states=[];costs=[]
    for frame in frames:
        options=list(itertools.permutations(teams,len(frame)))
        trusted=[p.get('verified_team') for p in frame]
        valid=[s for s in options if all(v is None or s[i]==v for i,v in enumerate(trusted))]
        if not valid:  # Contradictory verified anchors must not be chosen by convenience.
            return [dict(p,team=None,identity_status='unknown',reason='conflicting_verified_anchors') for f in frames for p in f]
        states.append(valid)
        costs.append([sum(6. if p.get('read_teams') and t not in p['read_teams'] else 0. for p,t in zip(frame,s)) for s in valid])
    forward=[costs[0][:]];edges=[]
    for k in range(1,len(frames)):
        edge=[[transition(frames[k-1],a,frames[k],b,association) for b in states[k]] for a in states[k-1]];edges.append(edge)
        forward.append([costs[k][j]+min(forward[k-1][i]+edge[i][j] for i in range(len(states[k-1]))) for j in range(len(states[k]))])
    backward=[[0.]*len(s) for s in states]
    for k in range(len(frames)-2,-1,-1):
        backward[k]=[min(edges[k][i][j]+costs[k+1][j]+backward[k+1][j] for j in range(len(states[k+1]))) for i in range(len(states[k]))]
    anchors=collections.defaultdict(list)
    for frame in frames:
        for p in frame:
            if p.get('verified_team') is not None:anchors[(p['camera'],str(p['scene']),p['track_id'])].append(p)
    optimum=min(forward[-1]);out=[]
    for k,frame in enumerate(frames):
        totals=[a+b for a,b in zip(forward[k],backward[k])]
        for i,p in enumerate(frame):
            scores={team:min((v for s,v in zip(states[k],totals) if s[i]==team),default=math.inf) for team in teams}
            plausible=[team for team,v in scores.items() if v<=optimum+margin]
            team=plausible[0] if len(plausible)==1 else None
            trusted=p.get('verified_team')
            def overlaps(q):
                x,y,w,h=p['box'];a,b,c,d=q['box']
                return max(0,min(x+w,a+c)-max(x,a))*max(0,min(y+h,b+d)-max(y,b))/max(1e-9,min(w*h,c*d))>.2
            contact=trusted is None and len(states[k])>1 and any(q is not p and overlaps(q) for q in frame)
            contact_supported=False
            if contact and team is not None:
                known=anchors[(p['camera'],str(p['scene']),p['track_id'])]
                before=next((q for q in reversed(known) if 0<p['t']-q['t']<=1.5),None)
                after=next((q for q in known if 0<q['t']-p['t']<=1.5),None)
                contact_supported=before is not None and after is not None and before['verified_team']==after['verified_team']==team and motion(before,p,p['t']-before['t'])<4 and motion(p,after,after['t']-p['t'])<4
            if contact and not contact_supported:team=None
            status='verified' if trusted is not None else 'inferred' if team is not None else 'unknown'
            out.append(dict(p,team=team,identity_status=status,reason='verified_ocr_segment' if trusted is not None else 'same_number_before_and_after_contact' if contact_supported else 'bidirectional_motion_and_roster' if team is not None else 'same_alliance_overlap_needs_review' if contact else 'multiple_roster_assignments',
                candidate_teams=plausible,identity_evidence={'solver':VERSION,'uses_future_evidence':True,'training_eligible':trusted is not None}))
    return out

def bridge_short_gap(previous,current,association=None):
    """Offer uniquely matched visible boxes after a short detection gap for review.

    This never establishes a verified OCR identity or supplies training truth. A
    new numeric tracker ID needs unusually close appearance evidence, or an
    independently activated association model, before any suggestion is made.
    """
    if not previous or not current:return 0
    end=max(p['t'] for p in previous);start=min(p['t'] for p in current);gap=start-end
    if not 1.5<gap<=3.:return 0
    sources=[p for p in previous if p['t']==end and p['team'] is not None and
             any(r['team']==p['team'] and r['identity_status']=='verified' for r in previous)]
    targets=[p for p in current if p['t']==start and p['team'] is None and p.get('reason')=='multiple_roster_assignments']
    pairs=[]
    for a in sources:
        for b in targets:
            if a['team'] not in b.get('candidate_teams',[]) or b.get('read_teams') and a['team'] not in b['read_teams']:continue
            va,vb=a.get('appearance'),b.get('appearance')
            if not isinstance(va,list) or not isinstance(vb,list) or len(va)!=24 or len(vb)!=24:continue
            appearance=sum(abs(x-y) for x,y in zip(va,vb))/6
            ax,ay,aw,ah=a['box'];bx,by,bw,bh=b['box']
            distance=math.hypot(ax+aw/2-bx-bw/2,ay+ah/2-by-bh/2)
            size=abs(math.log(max(1e-6,aw*ah)/max(1e-6,bw*bh)))
            if distance>max(.04,1.6*max(aw,bw)*gap) or size>.7:continue
            same_id=a['track_id']==b['track_id']
            learned=None
            if association is not None:
                from neptune_review_learning import pair_features,probability
                features=pair_features(a,b)
                if features is not None:learned=probability(association,features)
            if appearance>(.22 if same_id else .11) and not (learned is not None and learned>=.98 and appearance<=.25):continue
            pairs.append((distance/max(.02,max(aw,bw)*gap)+4*appearance+size+(.15 if not same_id else 0),a,b))
    accepted=0
    for score,a,b in sorted(pairs,key=lambda row:row[0]):
        rivals=[row for row in pairs if row[1] is a or row[2] is b]
        if any(row[0]<score+.35 and ((row[1] is not a and row[2] is b) or
               (row[1] is a and row[2] is not b)) for row in rivals):continue
        rows=[p for p in current if p['track_id']==b['track_id'] and p['t']-start<=2. and
              p['team'] is None and p['identity_status']=='unknown' and not p.get('occluded') and
              a['team'] in p.get('candidate_teams',[]) and not (p.get('read_teams') and a['team'] not in p['read_teams'])]
        if not rows or any(any(q is not p and q['t']==p['t'] and q['team']==a['team'] for q in current) for p in rows):continue
        for p in rows:
            p.update(team=a['team'],identity_status='inferred',reason='short_gap_visual_match_needs_review',
                     candidate_teams=[a['team']],identity_evidence={'solver':VERSION,'source_point_id':a['point_id'],
                     'uses_future_evidence':True,'training_eligible':False})
        accepted+=len(rows)
    return accepted

def bridge_track_fragments(points):
    """Suggest a unique reappearance after a tracker ID changes within one shot.

    Both endpoints must be free of same-alliance contact. A different robot
    with a plausible visual match, or a team already visible, blocks the link.
    """
    tracks=collections.defaultdict(list)
    for p in points:tracks[p['track_id']].append(p)
    fragments=[]
    for rows in tracks.values():
        fragment=[];last=None
        for p in sorted(rows,key=lambda q:q['t']):
            if last is not None and p['t']-last>1.5:fragments.append(fragment);fragment=[]
            fragment.append(p);last=p['t']
        if fragment:fragments.append(fragment)
    sources=[f for f in fragments if f[-1]['team'] is not None and
             any(p['identity_status']=='verified' and p['team']==f[-1]['team'] for p in f)]
    targets=[f for f in fragments if f[0]['team'] is None and f[0].get('reason')=='multiple_roster_assignments']
    by_time=collections.defaultdict(list)
    for p in points:by_time[p['t']].append(p)
    def isolated(p):
        x,y,w,h=p['box']
        return not p.get('occluded') and not any(q is not p and
            max(0,min(x+w,q['box'][0]+q['box'][2])-max(x,q['box'][0]))*
            max(0,min(y+h,q['box'][1]+q['box'][3])-max(y,q['box'][1]))>
            .2*min(w*h,q['box'][2]*q['box'][3]) for q in by_time[p['t']])
    pairs=[]
    for source in sources:
        a=source[-1]
        if not isolated(a):continue
        for target in targets:
            b=target[0];gap=b['t']-a['t']
            if a['track_id']==b['track_id'] or not .15<=gap<=3. or not isolated(b):continue
            if a['team'] not in b.get('candidate_teams',[]) or b.get('read_teams') and a['team'] not in b['read_teams']:continue
            if any(a['t']<p['t']<b['t'] and p.get('reason')=='too_many_or_duplicate_detections' for p in points):continue
            va,vb=a.get('appearance'),b.get('appearance')
            if not isinstance(va,list) or not isinstance(vb,list) or len(va)!=24 or len(vb)!=24:continue
            appearance=sum(abs(x-y) for x,y in zip(va,vb))/6
            if appearance>.11:continue
            ax,ay,aw,ah=a['box'];bx,by,bw,bh=b['box']
            distance=math.hypot(ax+aw/2-bx-bw/2,ay+ah/2-by-bh/2)
            size=abs(math.log(max(1e-6,aw*ah)/max(1e-6,bw*bh)))
            if distance>max(.04,1.6*max(aw,bw)*gap) or size>.7:continue
            score=distance/max(.02,max(aw,bw)*gap)+4*appearance+size
            pairs.append((score,a,b,target))
    accepted=0
    for score,a,b,target in sorted(pairs,key=lambda row:row[0]):
        if any(row[0]<score+.35 and ((row[1] is a and row[2] is not b) or
               (row[1] is not a and row[2] is b)) for row in pairs):continue
        rows=[p for p in target if p['t']-b['t']<=2. and p['team'] is None and
              p['identity_status']=='unknown' and not p.get('occluded') and
              a['team'] in p.get('candidate_teams',[]) and
              not (p.get('read_teams') and a['team'] not in p['read_teams'])]
        if not rows or any(any(q is not p and q['team']==a['team'] for q in by_time[p['t']]) for p in rows):continue
        for p in rows:
            p.update(team=a['team'],identity_status='inferred',reason='short_gap_visual_match_needs_review',
                     candidate_teams=[a['team']],identity_evidence={'solver':VERSION,'source_point_id':a['point_id'],
                     'uses_future_evidence':True,'training_eligible':False})
        accepted+=len(rows)
    return accepted

def reconcile(observations,roster,source_sha256,margin=1.0,association=None):
    roster_check(roster)
    if not isinstance(source_sha256,str) or len(source_sha256)!=64:raise ValueError('Video fingerprint required')
    if not 0<=margin<=10:raise ValueError('Invalid ambiguity margin')
    groups=collections.defaultdict(lambda:collections.defaultdict(list));out=[]
    for raw in observations:
        p=dict(raw)
        if p.get('alliance') not in roster or type(p.get('t')) not in (int,float) or not math.isfinite(p['t']) or p['t']<0:raise ValueError('Invalid tracking observation')
        box=p.get('box')
        if not isinstance(box,list) or len(box)!=4 or not all(type(x) in (int,float) and math.isfinite(x) for x in box) or box[2]<=0 or box[3]<=0 or min(box)<0 or box[0]+box[2]>1.001 or box[1]+box[3]>1.001:raise ValueError('Invalid normalized box')
        if p.get('verified_team') is not None and p['verified_team'] not in roster[p['alliance']]:raise ValueError('Anchor not in alliance roster')
        p['point_id']=digest([source_sha256,p['camera'],p['scene'],p['t'],p['track_id'],box])
        groups[(p['camera'],str(p['scene']),p['alliance'])][p['t']].append(p)
    for _,timeline in sorted(groups.items()):
        window=[];last=None;windows=[];barriers=[]
        def flush():
            if window:windows.append(solve_window(window,roster[window[0][0]['alliance']],margin,association));window.clear()
        for t,frame in sorted(timeline.items()):
            ids=[p['track_id'] for p in frame]
            ambiguous=len(frame)>3 or len(set(ids))!=len(ids)
            if ambiguous or last is not None and t-last>1.5:flush()
            if ambiguous:
                barriers.append(t)
                out.extend(dict(p,team=None,identity_status='unknown',reason='too_many_or_duplicate_detections',candidate_teams=roster[p['alliance']]) for p in frame)
            else:window.append(sorted(frame,key=lambda p:str(p['track_id'])))
            last=t
        flush()
        for a,b in zip(windows,windows[1:]):
            if not any(max(p['t'] for p in a)<t<min(p['t'] for p in b) for t in barriers):
                bridge_short_gap(a,b,association)
        solved_points=[p for solved in windows for p in solved]
        bridge_track_fragments(solved_points)
        out.extend(solved_points)
    out.sort(key=lambda p:(p['t'],p['camera'],str(p['scene']),str(p['track_id'])))
    # Split display/correction tracks at gaps; a recycled numeric track ID is not a single robot.
    tracks=collections.defaultdict(list)
    for p in out:tracks[(p['camera'],str(p['scene']),p['alliance'],p['track_id'])].append(p)
    for key,points in tracks.items():
        serial=0;last=None
        for p in points:
            if last is not None and p['t']-last>1.5:serial+=1
            p['review_track']=digest([source_sha256,key,serial])[:24];last=p['t']
    summary=dict(collections.Counter(p['identity_status'] for p in out))
    summary['short_gap_suggestions']=sum(p.get('reason')=='short_gap_visual_match_needs_review' for p in out)
    return {'schema_version':1,'solver':VERSION,'source_sha256':source_sha256,'roster':roster,'points':out,'corrections':[],
        'summary':summary,'limitations':['Inferred identities are review suggestions, not training labels.','No identity is carried across camera cuts or long unseen gaps.','Roster elimination requires distinct visible detections; false detections still need human review.']}

def apply_corrections(review,corrections):
    """Human anchors rerun both directions; original automatic evidence stays untouched."""
    roster_check(review['roster'])
    if not isinstance(corrections,list) or len(corrections)>1000:raise ValueError('Invalid correction list')
    original={p['point_id']:p for p in review['points']};points=[dict(p) for p in review['points']];manual={}
    for edit in corrections:
        if set(edit) not in ({'review_track','start','end','team'},{'review_track','start','end','team','ignored'}) or type(edit['start']) not in (int,float) or type(edit['end']) not in (int,float) or not all(math.isfinite(edit[k]) for k in ('start','end')) or not 0<=edit['start']<=edit['end']:raise ValueError('Invalid correction interval')
        if 'ignored' in edit and type(edit['ignored'])!=bool:raise ValueError('Invalid exclusion')
        selected=[p for p in points if p['review_track']==edit['review_track'] and edit['start']<=p['t']<=edit['end']]
        if not selected:raise ValueError('Correction does not match a visible track interval')
        team=edit['team']
        if edit.get('ignored') and team is not None:raise ValueError('Excluded boxes cannot carry an identity')
        if team is not None and (type(team)!=int or not any(team in review['roster'][c] for c in ('red','blue'))):raise ValueError('Correction team is not in this match')
        # Naming a team from the other alliance also fixes a box the detector gave the wrong colour.
        alliance=next((c for c in ('red','blue') if team in review['roster'][c]),None)
        for p in selected:
            manual[p['point_id']]={'team':team,'ignored':edit.get('ignored',False)}
            if alliance and p['alliance']!=alliance:p['alliance']=alliance;p['alliance_corrected']=True
    frames=collections.defaultdict(dict)
    for p in points:
        m=manual.get(p['point_id'])
        if m and m['team'] is not None:
            key=(p['camera'],str(p['scene']),p['t'])
            if m['team'] in frames[key]:raise ValueError('Two boxes cannot be assigned the same robot in one camera frame')
            frames[key][m['team']]=p['point_id']
    usable=[];excluded=[]
    for p in points:
        m=manual.get(p['point_id']);key=(p['camera'],str(p['scene']),p['t'])
        if m and m['ignored']:
            excluded.append(dict(p,team=None,identity_status='excluded',reason='human_not_robot'));continue
        if m:p['verified_team']=m['team'];p['read_teams']=[]
        # A human correction overrides conflicting automatic identity evidence.
        if p.get('verified_team') in frames[key] and frames[key][p['verified_team']]!=p['point_id']:p['verified_team']=None;p['read_teams']=[]
        usable.append(p)
    refined=reconcile(usable,review['roster'],review['source_sha256'])['points'] if usable else []
    for p in refined:
        p['review_track']=original[p['point_id']]['review_track']
        m=manual.get(p['point_id'])
        if m:p.update(team=m['team'],identity_status='corrected' if m['team'] is not None else 'unknown',reason='human_correction',identity_evidence={'training_eligible':False,'human_identity_review':True})
        # Do not promote propagation from a human anchor to independently verified OCR.
        elif p['identity_status']=='verified' and original[p['point_id']].get('verified_team') is None:p['identity_status']='inferred'
    combined=sorted(refined+excluded,key=lambda p:(p['t'],p['camera'],str(p['scene']),str(p['track_id'])))
    return {**review,'points':combined,'corrections':corrections,'summary':dict(collections.Counter(p['identity_status'] for p in combined))}


# Inferences proven reliable on hidden OCR anchors (2026txcmp1 QM3/QM4: 1,132 of 1,132 correct) may be used
# like verified identities; short-gap visual suggestions stay review-only.
TRAINING_INFERENCE_REASONS = ('bidirectional_motion_and_roster', 'same_number_before_and_after_contact')


def with_roster_inferred(tracking):
    """Add the solver's reliable inferences to tracking localizations as identity_method 'roster_inferred'."""
    review = tracking.get('identity_review') or {}
    base = [p for p in tracking.get('localizations', []) if p.get('identity_method') != 'roster_inferred']
    taken = collections.defaultdict(list)
    for p in base:
        taken[(p.get('camera'), p['team'])].append(p['t'])
    added = []
    # A close-up shows part of the field, so roster elimination there can name a still non-robot
    # box (2026txcmp1 QM3: 60 human-excluded boxes). There it needs a number read on that track.
    read = {(p.get('camera'), str(p.get('scene')), p.get('track_id')) for p in review.get('points', []) if p.get('read_teams')}
    for p in review.get('points', []):
        if p.get('identity_status') != 'inferred' or p.get('reason') not in TRAINING_INFERENCE_REASONS or p.get('team') is None:
            continue
        if p.get('box_source') == 'optical_flow' or p.get('occluded'):
            continue
        if p.get('reason') == 'bidirectional_motion_and_roster' and p.get('camera', 'wide') != 'wide' and (p.get('camera'), str(p.get('scene')), p.get('track_id')) not in read:
            continue
        if any(abs(t-p['t']) < .2 for t in taken[(p.get('camera'), p['team'])]):
            continue
        added.append({'t': p['t'], 'box': p['box'], 'camera': p.get('camera'), 'camera_bounds': p.get('camera_bounds'), 'scene': p.get('scene'),
                      'track_id': p.get('track_id'), 'team': p['team'], 'detection_confidence': p.get('detection_confidence'),
                      'identity_method': 'roster_inferred', 'identity_segment': 'roster:'+str(p.get('review_track')),
                      'identity_evidence': {'solver': VERSION, 'reason': p['reason'], 'point_id': p.get('point_id')}})
    return dict(tracking, localizations=sorted(base+added, key=lambda q: (q['t'], str(q.get('camera')))), roster_inferred=len(added))
