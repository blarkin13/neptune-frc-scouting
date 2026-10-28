"""Durable, source-bound human identity supervision and portable association learning.

Only explicitly corrected samples supervise action crops or association fitting.
OCR/roster/motion suggestions never bootstrap themselves into human truth.
"""
import collections,contextlib,copy,hashlib,json,math,os,re,tempfile
from pathlib import Path
from neptune_identity import apply_corrections,digest,roster_check
VERSION='review-learning-v1'

def home():
    return Path(os.environ.get('NEPTUNE_REVIEW_LIBRARY',Path.home()/'.neptune-trainer/review-learning'))

def atomic(path,value):
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,name=tempfile.mkstemp(prefix='.review-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:json.dump(value,f,allow_nan=False,separators=(',',':'));f.flush();os.fsync(f.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def reserved(match,source):
    from neptune_training import HELD_OUT_MATCHES,HELD_OUT_SOURCES
    return match in HELD_OUT_MATCHES or source in HELD_OUT_SOURCES

@contextlib.contextmanager
def library_lock(root):
    root=Path(root);root.mkdir(parents=True,exist_ok=True,mode=0o700)
    path=root/'library.lock';fd=os.open(path,os.O_RDWR|os.O_CREAT,0o600)
    with os.fdopen(fd,'r+b') as lock:
        if os.fstat(lock.fileno()).st_size==0:lock.write(b'0');lock.flush()
        lock.seek(0)
        if os.name=='nt':
            import msvcrt
            msvcrt.locking(lock.fileno(),msvcrt.LK_LOCK,1)
        else:
            import fcntl
            fcntl.flock(lock.fileno(),fcntl.LOCK_EX)
        try:yield
        finally:
            lock.seek(0)
            if os.name=='nt':msvcrt.locking(lock.fileno(),msvcrt.LK_UNLCK,1)
            else:fcntl.flock(lock.fileno(),fcntl.LOCK_UN)

def publish(review,root=None,video=None,expected_revision="unchecked"):
    root=Path(root) if root is not None else home()
    with library_lock(root):return _publish(review,root,video,expected_revision)

def _publish(review,root=None,video=None,expected_revision="unchecked"):
    """Snapshot reviewed data, without video, credentials, or original action labels."""
    root=Path(root) if root is not None else home();source=review.get('source_sha256','');match=review.get('match_key','')
    if not re.fullmatch('[a-f0-9]{64}',source) or not re.fullmatch(r'\d{4}[A-Za-z0-9_-]+',match):raise ValueError('Reviewed video needs its match key and source fingerprint')
    previous=load(source,root)
    if previous and (previous['points'] or previous.get('has_review_history')) and expected_revision!='unchecked' and expected_revision!=previous['revision'] and digest(review.get('corrections',[]))!=previous.get('correction_digest'):
        raise ValueError('Another review changed this video’s training corrections. The local review is saved; reopen the latest review before replacing shared learning data.')
    corrected=apply_corrections(review,review.get('corrections',[]));roster_check(review['roster'])
    revision=digest({'corrections':review.get('corrections',[]),'points':review['points'],'version':VERSION})
    points=[p for p in corrected['points'] if p['identity_status'] in ('corrected','excluded')]
    if video is not None:enrich(points,video)
    document={'schema_version':1,'version':VERSION,'source_sha256':source,'match_key':match,'roster':review['roster'],'revision':revision,
              'points':points,'has_review_history':bool(review.get('corrections')) or bool(previous and (previous['points'] or previous.get('has_review_history'))),'correction_digest':digest(review.get('corrections',[])),'evaluation_only':reserved(match,source)}
    atomic(root/'reviews'/(source+'.json'),document)
    return {'revision':revision,'reviewed_samples':sum(p.get('team') is not None for p in points),'excluded_samples':sum(p['identity_status']=='excluded' for p in points),
            'evaluation_only':document['evaluation_only'],'action_training':'reviewed_visible_samples_only','association_training':'separate_match_validation_required'}

def load(source,root=None):
    if not isinstance(source,str) or not re.fullmatch('[a-f0-9]{64}',source):return None
    path=(Path(root) if root is not None else home())/'reviews'/(source+'.json')
    if not path.is_file():return None
    doc=json.loads(path.read_text())
    if doc.get('source_sha256')!=source or doc.get('version')!=VERSION:raise ValueError('Saved identity supervision fingerprint changed')
    roster_check(doc['roster']);return doc

def revision(source,root=None):
    doc=load(source,root);return doc['revision'] if doc else None

def apply_to_facts(facts,root=None):
    """Apply human-reviewed samples even after original temporary tracking was deleted.

    A review provides an independent, source-bound localization. It overrides stale
    automatic boxes in that camera/time; it never establishes a scoring/action label.
    """
    source=facts.get('source',{}).get('sha256');doc=load(source,root)
    if not doc:return facts
    identities=facts.get('bumpers',{}).get('identities',{})
    roster={c:sorted(int(t) for t,v in identities.items() if v.get('alliance')==c) for c in ('red','blue')}
    if roster!={c:sorted(doc['roster'][c]) for c in ('red','blue')}:raise ValueError('Reviewed identities do not match this verified roster')
    out=copy.deepcopy(facts);track=out.setdefault('tracking',{})
    # Reapplying/replacing a review cannot retain old human assignments.
    original=track.get('automatic_localizations',track.get('localizations',[]));track['automatic_localizations']=copy.deepcopy(original)
    points=doc['points']
    # Human review overrides only overlapping competing detections, preserving teammates.
    kept=[]
    for p in original:
        clashes=[q for q in points if q['camera']==p.get('camera') and abs(q['t']-p['t'])<.08]
        def overlap(q):
            x,y,w,h=p['box'];a,b,c,d=q['box'];return max(0,min(x+w,a+c)-max(x,a))*max(0,min(y+h,b+d)-max(y,b))/max(1e-9,min(w*h,c*d))
        if not any(overlap(q)>.35 or q.get('team')==p.get('team') for q in clashes):kept.append(p)
    accepted=sorted([p for p in points if p.get('team') is not None and p['identity_status']=='corrected'],key=lambda p:(p['camera'],str(p['scene']),p['team'],p['t']))
    last={};segments=collections.Counter()
    for p in accepted:
        key=(p['camera'],str(p['scene']),p['team']);previous=last.get(key)
        if previous is None or p['t']-previous['t']>.5:segments[key]+=1
        segment=digest([source,key,segments[key]])[:24]
        # Review boxes represent the robot crop, not a manually labeled bumper.
        kept.append(dict(p,track_id='review:'+segment,identity_segment=segment,identity_method='human_reviewed_track',
                         identity_evidence={'human_reviewed':True,'training_eligible':True,'review_revision':doc['revision'],'point_id':p['point_id']}))
        last[key]=p
    track['localizations']=sorted(kept,key=lambda p:(p['t'],p.get('camera',''),p['team']))
    track['review_supervision']={'version':VERSION,'revision':doc['revision'],'reviewed_samples':len(accepted),'excluded_samples':len(points)-len(accepted),'evaluation_only':doc['evaluation_only'],'overrides':[{k:p.get(k) for k in ('t','camera','box','team')} for p in points]}
    return out

def descriptor(image,box):
    """Small appearance signature; no team IDs, OCR, score or game labels."""
    import numpy as np
    x,y,w,h=map(int,box);crop=image[max(0,y):max(0,y+h),max(0,x):max(0,x+w)]
    if crop.size<48:return None
    # Normalized histograms tolerate moderate crop movement. Shape is separate.
    values=[]
    for c in range(3):values.extend((np.histogram(crop[:,:,c],bins=8,range=(0,256))[0]/(crop.shape[0]*crop.shape[1])).tolist())
    return [round(float(v),5) for v in values]

def pair_features(a,b):
    dt=abs(b['t']-a['t']);ax,ay,aw,ah=a['box'];bx,by,bw,bh=b['box'];scale=max(.015,aw,bw)
    x=[min(10.,math.hypot(ax+aw/2-bx-bw/2,ay+ah/2-by-bh/2)/max(scale,4*scale*dt)),
       min(4.,abs(math.log(max(1e-6,aw*ah)/max(1e-6,bw*bh)))),min(3.,dt),float(a['track_id']!=b['track_id'])]
    va,vb=a.get('appearance'),b.get('appearance')
    if not va or not vb or len(va)!=24 or len(vb)!=24:return None
    if not all(type(v) in (int,float) and math.isfinite(v) and 0<=v<=1 for v in va+vb):return None
    return x+[sum(abs(u-v) for u,v in zip(va,vb))/6]

def pairs(doc):
    if doc.get('evaluation_only') or reserved(doc['match_key'],doc['source_sha256']):return []
    pts=[p for p in doc['points'] if p.get('identity_status')=='corrected' and p.get('team') is not None and not p.get('occluded')]
    frames=collections.defaultdict(list)
    for p in pts:frames[(p['camera'],str(p['scene']),p['alliance'])].append(p)
    result=[]
    for rows in frames.values():
        rows.sort(key=lambda p:p['t']);bins=collections.defaultdict(list)
        for p in rows:bins[round(p['t']*2)].append(p)
        # At most one observation per team per half-second; avoid duplicate frames dominating fitting.
        sampled=[]
        for _,ps in sorted(bins.items()):
            seen=set()
            for p in ps:
                if p['team'] not in seen:sampled.append(p);seen.add(p['team'])
        for i,a in enumerate(sampled):
            for b in sampled[i+1:]:
                if b['t']-a['t']>1.5:break
                if b['t']-a['t']<.15:continue
                x=pair_features(a,b)
                if x is not None:result.append({'x':x,'y':int(a['team']==b['team']),'match_key':doc['match_key'],'source':doc['source_sha256']})
    return result[:1200]

def fit(rows):
    if not rows or len({r['y'] for r in rows})<2:return None
    weights=[0.]*5;bias=0.;counts=collections.Counter(r['y'] for r in rows)
    for _ in range(150):
        gw=[0.]*5;gb=0.
        for r in rows:
            z=max(-30,min(30,bias+sum(w*x for w,x in zip(weights,r['x']))));e=(1/(1+math.exp(-z))-r['y'])/counts[r['y']]/2
            gb+=e
            for j,x in enumerate(r['x']):gw[j]+=e*x
        bias-=.15*gb
        for j in range(5):weights[j]-=.15*(gw[j]+.01*weights[j])
    return {'weights':weights,'bias':bias}

def probability(model,x):
    return 1/(1+math.exp(-max(-30,min(30,model['bias']+sum(w*v for w,v in zip(model['weights'],x))))))

def train_association(root=None):
    root=Path(root) if root is not None else home();docs=[]
    for p in sorted((root/'reviews').glob('*.json')):
        doc=json.loads(p.read_text())
        if doc.get('version')==VERSION:docs.append(doc)
    rows=[r for d in docs for r in pairs(d)];matches=sorted({r['match_key'] for r in rows})
    report={'version':VERSION,'pairs':len(rows),'matches':matches,'status':'needs_reviewed_matches','active':False,'reason':'Need at least three reviewed matches with positive and same-alliance negative pairs.'}
    model=None
    if len(matches)>=3 and len(rows)>=100:
        # Leave-one-match-out, and exclude duplicate source bytes as well.
        tp=fp=fn=tn=0;folds=0
        for match in matches:
            test=[r for r in rows if r['match_key']==match];sources={r['source'] for r in test};train=[r for r in rows if r['match_key']!=match and r['source'] not in sources];head=fit(train)
            if head is None or len({r['y'] for r in test})<2:continue
            folds+=1
            for r in test:
                y=probability(head,r['x'])>=.9
                tp+=int(y and r['y']);fp+=int(y and not r['y']);fn+=int(not y and r['y']);tn+=int(not y and not r['y'])
        precision=tp/(tp+fp) if tp+fp else 0.;recall=tp/(tp+fn) if tp+fn else 0.
        # Pair accuracy is necessary but not an end-to-end identity benchmark.
        report.update(status='candidate',reason='Independent identity-point benchmark still required before activation.',validation={'folds':folds,'tp':tp,'fp':fp,'fn':fn,'tn':tn,'precision':precision,'recall':recall},eligible_for_identity_benchmark=folds>=3 and tp>=30 and fp==0 and recall>=.5)
        model=fit(rows)
    candidate={**report,'model':model,'training_revision':digest([(d['source_sha256'],d['revision']) for d in docs if not d.get('evaluation_only')])}
    atomic(root/'association-candidate.json',candidate);return report

def enrich(points,video):
    """Recover signatures for reviews created before descriptors existed; no images kept."""
    missing=[p for p in points if p.get('team') is not None and not p.get('appearance')]
    if not missing:return
    import numpy as np
    import cv2
    from neptune_tracking import decode
    start=max(0,min(p['t'] for p in missing)-.1);end=max(p['t'] for p in missing)+.2
    by_time=collections.defaultdict(list)
    for p in missing:by_time[round(p['t']*6)].append(p)
    for t,w,h,Y,U,V in decode(video,0.,1.,start,end,6.,960):
        near=[p for k in (round(t*6)-1,round(t*6),round(t*6)+1) for p in by_time.get(k,[]) if abs(p['t']-t)<.09]
        if not near:continue
        image=cv2.cvtColor(np.stack([np.frombuffer(a,dtype=np.uint8).reshape(h,w) for a in (Y,U,V)],axis=2),cv2.COLOR_YUV2BGR)
        for p in near:
            x,y,bw,bh=p['box'];p['appearance']=descriptor(image,[x*w,y*h,bw*w,bh*h])

def active_association(root=None):
    root=Path(root) if root is not None else home();p=root/'association-active.json'
    if not p.is_file():return None
    d=json.loads(p.read_text());m=d.get('model')
    if d.get('version')!=VERSION or d.get('independent_identity_validation',{}).get('passed') is not True:return None
    if not isinstance(m,dict) or len(m.get('weights',[]))!=5 or not all(type(v) in (int,float) and math.isfinite(v) and abs(v)<100 for v in m['weights']+[m.get('bias')]):raise ValueError('Invalid learned association model')
    docs=[json.loads(p.read_text()) for p in sorted((root/'reviews').glob('*.json'))]
    current=digest([(v['source_sha256'],v['revision']) for v in docs if not v.get('evaluation_only')])
    if d.get('training_revision')!=current:return None
    return m

def evaluate_association(audit,root=None,activate=False):
    """Audit contains independent human point identities, never automatic suggestions.

    Each review supplies raw detections. The baseline and candidate see identical
    OCR anchors but neither receives the audit identities during reconciliation.
    """
    root=Path(root) if root is not None else home();candidate=json.loads((root/'association-candidate.json').read_text())
    if not candidate.get('model') or not candidate.get('eligible_for_identity_benchmark'):raise ValueError('Candidate has not passed separate-match pair validation')
    document=json.loads(Path(audit).read_text());rows=document.get('points',[])
    if document.get('evidence_type')!='independent_human_identity_audit' or len(rows)<300:raise ValueError('Provide at least 300 independent human-reviewed identity points')
    from neptune_identity import reconcile
    seen=set();counts={k:collections.Counter() for k in ('baseline','candidate')};matches=set();sources=set();cache={}
    for row in rows:
        path=Path(audit).parent/row['review_file'];review=json.loads(path.read_text());match=review['match_key'];source=review['source_sha256'];key=(source,row['point_id'])
        if key in seen:raise ValueError('Duplicate identity audit point')
        seen.add(key)
        if match in candidate['matches']:raise ValueError('Training match cannot evaluate its own association model')
        training_sources={json.loads(p.read_text())['source_sha256'] for p in (root/'reviews').glob('*.json') if json.loads(p.read_text()).get('match_key') in candidate['matches']}
        if source in training_sources:raise ValueError('Training source cannot evaluate its own association model')
        if row.get('certainty')!='observed' or type(row.get('team'))!=int:raise ValueError('Audit identities must be independently observed')
        roster_check(review['roster'])
        if source not in cache:
            cache[source]={name:{p['point_id']:p for p in reconcile(review['points'],review['roster'],source,association=model)['points']} for name,model in [('baseline',None),('candidate',candidate['model'])]}
        matches.add(match);sources.add(source)
        for name in counts:
            p=cache[source][name][row['point_id']]
            if row['team'] not in review['roster'][p['alliance']]:raise ValueError('Audit team not in alliance roster')
            counts[name]['correct' if p['team']==row['team'] else 'unknown' if p['team'] is None else 'wrong']+=1
    c,b=counts['candidate'],counts['baseline'];precision=c['correct']/max(1,c['correct']+c['wrong'])
    passed=len(matches)>=2 and len(sources)>=2 and precision>=.98 and c['correct']>=150 and c['correct']>=b['correct'] and c['wrong']<=b['wrong']
    report={'passed':passed,'points':len(rows),'matches':sorted(matches),'precision':precision,'baseline':dict(b),'candidate':dict(c),'audit_sha256':hashlib.sha256(Path(audit).read_bytes()).hexdigest()}
    atomic(root/'association-identity-validation.json',report)
    if activate:
        if not passed:raise ValueError('Candidate failed independent identity validation; previous model kept')
        atomic(root/'association-active.json',{**candidate,'independent_identity_validation':report,'active':True})
    return report

def _import_document(value,root=None):
    """Explicit portable transfer between helpers, bound to identical video bytes."""
    if not isinstance(value,dict) or value.get('schema_version')!=1 or value.get('version')!=VERSION:raise ValueError('Choose a Neptune reviewed-tracking learning file')
    source=value.get('source_sha256');match=value.get('match_key');roster_check(value.get('roster'))
    if not isinstance(source,str) or not re.fullmatch('[a-f0-9]{64}',source) or not isinstance(match,str) or not re.fullmatch(r'\d{4}[A-Za-z0-9_-]+',match):raise ValueError('Invalid video or match identity')
    points=value.get('points')
    if not isinstance(points,list) or len(points)>20000:raise ValueError('Invalid reviewed sample count')
    # Reconcile validates boxes, roster colours, times, and source bindings.
    from neptune_identity import reconcile
    raw=[];status={};keys=set()
    for p in points:
        if p.get('identity_status') not in ('corrected','excluded'):raise ValueError('Only explicit human-reviewed samples may be transferred')
        if p['identity_status']=='corrected' and (type(p.get('team'))!=int or p['team'] not in value['roster'].get(p.get('alliance'),[])):raise ValueError('Reviewed team is not in the alliance roster')
        key=(p.get('camera'),str(p.get('scene')),p.get('t'),p.get('team'))
        if p['identity_status']=='corrected' and key in keys:raise ValueError('Duplicate human identity in a camera frame')
        keys.add(key);q=dict(p,verified_team=None,read_teams=[]);raw.append(q)
    checked=reconcile(raw,value['roster'],source)['points'];by_id={p['point_id']:p for p in points}
    if len(checked)!=len(by_id) or any(p['point_id'] not in by_id for p in checked):raise ValueError('Reviewed point fingerprints changed')
    for p in checked:
        old=by_id[p['point_id']];p.update(team=old.get('team') if old['identity_status']=='corrected' else None,identity_status=old['identity_status'],review_track=old['review_track'])
    document={'schema_version':1,'version':VERSION,'source_sha256':source,'match_key':match,'roster':value['roster'],'points':checked,'revision':digest([VERSION,source,checked]),'evaluation_only':reserved(match,source),'has_review_history':bool(checked),'correction_digest':None}
    root=Path(root) if root is not None else home();old=load(source,root)
    fields=('point_id','t','box','camera','scene','track_id','alliance','team','identity_status')
    def semantic(points):return [{k:p.get(k) for k in fields} for p in sorted(points,key=lambda p:p['point_id'])]
    if old and old['points'] and semantic(old['points'])!=semantic(checked):raise ValueError('This helper already has different corrections for the video; keep or export them before importing another review')
    atomic(root/'reviews'/(source+'.json'),document)
    return {'reviewed_samples':sum(p.get('team') is not None for p in checked),'evaluation_only':document['evaluation_only']}


def import_document(value,root=None):
    root=Path(root) if root is not None else home()
    with library_lock(root):return _import_document(value,root)
