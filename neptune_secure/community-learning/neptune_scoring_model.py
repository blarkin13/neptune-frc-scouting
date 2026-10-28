"""Experimental whole-match scoring regression from human contribution estimates.

Targets are weak human estimates, not official per-robot scores. Inputs contain only
identified robot video crops, never human tags, team IDs or scoreboard pixels.
"""
import collections
import math
import statistics

VERSION='robot-score-kernel-v1'
POINT_VERSION='robot-score-kernel-v2'
DIM=288
MAX_SUPPORT=256


def target(row):
    s=row.get('scoring')
    if not isinstance(s,dict) or s.get('mode')!='alliance_share':return None
    if row.get('tag_key')!='scoring' or row.get('label_scope')!='match' or row.get('polarity','present')!='present':return None
    if s.get('source')!='human_video_contribution' or s.get('score_basis')!='full_final_alliance_score':return None
    if (s.get('method'),row.get('certainty')) not in (('estimated','uncertain'),('counted','observed')):return None
    points,total=s.get('robot_points'),s.get('alliance_score')
    if type(points) is not int or type(total) is not int or not 0<=points<=total<=100000 or total<=0:return None
    return points/total


def label_rows(store,game):
    rows=[];excluded=collections.Counter()
    for entry in store.load('ledger.json',{}).values():
        row=(entry.get('revisions') or [{}])[-1].get('record',entry['original'])
        from neptune_training import HELD_OUT_MATCHES
        if row.get('match_key') in HELD_OUT_MATCHES:excluded['independent_benchmark_reserved']+=1;continue
        if not isinstance(row.get('scoring'),dict) or row['scoring'].get('mode')!='alliance_share':continue
        if not str(row.get('match_key','')).startswith(str(game['season'])):continue
        if entry['state'] not in ('contributed','verified'):excluded['withdrawn_or_unavailable']+=1;continue
        if target(row) is None and not __import__('neptune_video_labels').external_score_label(row):excluded['invalid_or_unknown_score_target']+=1;continue
        rows.append(row)
    return rows,dict(excluded)


def full_match(facts):
    periods=facts.get('periods',{});auto=periods.get('auto') or {};tele=periods.get('teleop') or {}
    return bool(auto.get('start_observed') and auto.get('end_observed') and tele.get('start_observed') and tele.get('end_observed'))


def small(vector):
    if len(vector)!=1296 or not all(type(x) in (int,float) and math.isfinite(x) for x in vector):raise ValueError('Invalid robot video features.')
    # Spatial 12x12 -> 4x4 pooling, for RGB and the two motion-difference samples.
    out=[]
    for block in range(3):
        for y in range(0,12,3):
            for x in range(0,12,3):
                for c in range(3):out.append(sum(vector[block*432+(yy*12+xx)*3+c] for yy in range(y,y+3) for xx in range(x,x+3))/9)
    return out


def descriptor(video,facts,team,duration_ms,extractor=None):
    from neptune_training import sightings,features
    checks=facts.get('identity',{}).get('checks',{})
    if any(checks.get(k,{}).get('status')!='match' for k in ('match_number','roster')):return None,'match_identity_incomplete'
    if not full_match(facts):return None,'incomplete_match_video'
    p=facts['periods'];start=p['auto']['start'];end=p['teleop']['end']
    if end<=start:return None,'match_clock_unavailable'
    seen=sorted([s for s in sightings(facts) if s['team']==team and start<=s['t']<=end],key=lambda s:s['t'])
    bins={min(3,int(4*(s['t']-start)/(end-start))) for s in seen}
    if len(seen)<6 or len(bins)<3 or seen[-1]['t']-seen[0]['t']<(end-start)*.5:return None,'insufficient_robot_sightings'
    if len(seen)>24:seen=[seen[i*(len(seen)-1)//23] for i in range(24)]
    vectors=[small((extractor or features)(video,s,duration_ms)) for s in seen]
    means=[statistics.mean(v[i] for v in vectors) for i in range(144)]
    std=[math.sqrt(statistics.mean((v[i]-means[i])**2 for v in vectors)) for i in range(144)]
    return {'x':means+std,'sightings':len(seen),'time_bins':len(bins),'first_sighting_s':seen[0]['t'],'last_sighting_s':seen[-1]['t']},None


def examples(rows,fact_map,sources,videos,extractor=None,audit=None):
    from pathlib import Path
    from neptune_video_labels import event_key
    from neptune_training import split_group
    grouped=collections.defaultdict(list);skipped=collections.Counter();out=[];source_groups={}
    for row in rows:grouped[(row['video_id'],row['team_number'])].append(row)
    def reject(items,reason):
        skipped[reason]+=len(items)
        if audit is not None:
            for row in items:audit.append({'label_id':row['id'],'match_key':row['match_key'],'team':row['team_number'],'category':'score_estimate','status':'rejected','reason':reason})
    for (vid,team),items in sorted(grouped.items()):
        src=sources.get(vid,{});facts=fact_map.get(vid)
        from neptune_training import HELD_OUT_SOURCES,HELD_OUT_MATCHES
        if src.get('sha256') in HELD_OUT_SOURCES or any(r['match_key'] in HELD_OUT_MATCHES for r in items):reject(items,'independent_benchmark_reserved');continue
        if src.get('status')!='verified' or not facts or facts.get('source',{}).get('sha256')!=src.get('sha256') or any(src.get('label_checks',{}).get(r['id'],'ok')!='ok' for r in items):reject(items,'source_not_verified');continue
        from neptune_video_labels import external_score_label
        imported=[r for r in items if external_score_label(r)]
        human=[r for r in items if not external_score_label(r)]
        if human and imported:skipped['imported_duplicate_superseded_by_human']+=len(imported);items=human;imported=[]
        answers=[r['scoring']['robot_points'] for r in items if target(r) is not None or external_score_label(r)]
        if not answers:continue
        if max(answers)-min(answers)>max(10,.1*max(answers)):reject(items,'conflicting_score_estimates');continue
        desc,reason=descriptor(Path(videos)/src['file'],facts,team,src['duration_ms'],extractor)
        if not desc:reject(items,reason);continue
        if audit is not None:
            for row in items:audit.append({'label_id':row['id'],'match_key':row['match_key'],'team':team,'category':'score_estimate','status':'accepted','sightings':desc['sightings'],'time_bins':desc['time_bins']})
        group=source_groups.setdefault(src['sha256'],event_key(items[0]['match_key']))
        points=statistics.median(answers);total=items[0]['scoring'].get('alliance_score')
        out.append(dict(desc,y=points/total if total else None,points=points,weight=.25 if imported else (1.0 if any(r['scoring']['method']=='counted' for r in items) else .5),
                        label_ids=sorted(r['id'] for r in items),source_sha256=src['sha256'],group=group,split=split_group(group),
                        match_key=items[0]['match_key'],team=team,alliance_score=total,target_kind='imported_reported_points' if imported else 'human_contribution',methods=sorted({r['scoring']['method'] for r in items})))
    return out,dict(skipped)


def kernel(a,b):return math.exp(-sum((x-y)**2 for x,y in zip(a,b))/DIM)


def solve(matrix,values):
    n=len(values);a=[list(row)+[value] for row,value in zip(matrix,values)]
    for i in range(n):
        pivot=max(range(i,n),key=lambda j:abs(a[j][i]));a[i],a[pivot]=a[pivot],a[i]
        if abs(a[i][i])<1e-12:raise ValueError('Scoring fit is singular.')
        scale=a[i][i];a[i]=[v/scale for v in a[i]]
        for j in range(n):
            if j==i:continue
            scale=a[j][i]
            for k in range(i,n+1):a[j][k]-=scale*a[i][k]
    return [row[-1] for row in a]


def fit(rows, point_mode=False):
    if point_mode:rows=[dict(r,y=r['points']) for r in rows]
    if len(rows)<2:return None,{'status':'needs_two_usable_robot_scores','examples':len(rows),'evaluation':None}
    # A source or event may never cross a training/test boundary.
    for key in ('source_sha256','group'):
        partitions=collections.defaultdict(set)
        for r in rows:partitions[r[key]].add(r['split'])
        if any(len(s)>1 for s in partitions.values()):raise ValueError('Scoring training/evaluation leakage: '+key)
    training=[r for r in rows if r['split']=='training'];development=False
    if len(training)<2:
        groups=sorted({r['group'] for r in rows});group=next((g for g in groups if sum(r['group']==g for r in rows)>=2),None)
        training=[r for r in rows if r['group']==group] if group else [];development=True
    if len(training)<2:return None,{'status':'needs_two_usable_robot_scores_in_training','examples':len(rows),'evaluation':None}
    if len({round(r['y'],6) for r in training})<2:return None,{'status':'needs_varied_score_examples','examples':len(rows),'evaluation':None}
    training=sorted(training,key=lambda r:(r['source_sha256'],r['team']))
    if len(training)>MAX_SUPPORT:training=[training[i*(len(training)-1)//(MAX_SUPPORT-1)] for i in range(MAX_SUPPORT)]
    mean=[statistics.mean(r['x'][i] for r in training) for i in range(DIM)]
    scale=[max(.05,math.sqrt(statistics.mean((r['x'][i]-mean[i])**2 for r in training))) for i in range(DIM)]
    centers=[[(v-m)/s for v,m,s in zip(r['x'],mean,scale)] for r in training]
    bias=statistics.mean(r['y'] for r in training);matrix=[[kernel(a,b)+(.1/training[i]['weight'] if i==j else 0) for j,b in enumerate(centers)] for i,a in enumerate(centers)]
    coefficients=solve(matrix,[r['y']-bias for r in training])
    head={'version':POINT_VERSION if point_mode else VERSION,'feature_dimension':DIM,'target':'individual_robot_points' if point_mode else 'share_of_full_final_alliance_score','basis':'includes_penalties',
          'mean':mean,'scale':scale,'centers':centers,'coefficients':coefficients,'bias':bias,'training_examples':len(training),
          'training_groups':sorted({r['group'] for r in training}),'training_sources':sorted({r['source_sha256'] for r in training}),
          'used_label_ids':sorted({id for r in training for id in r['label_ids']}),'development_partition':development,'release_ready':False}
    holdout=[r for r in rows if r['split']=='test' and r['group'] not in head['training_groups'] and r['source_sha256'] not in head['training_sources']]
    evaluation=None
    if holdout:evaluation={'kind':'held_out_observer_estimates' if point_mode else 'held_out_human_estimates','robot_examples':len(holdout),'matches':len({r['match_key'] for r in holdout}),
                           ('mean_absolute_point_error' if point_mode else 'mean_absolute_share_error'):statistics.mean(abs(predict_share(head,r['x'])-r['y']) for r in holdout)}
    return head,{'status':'candidate_needs_independent_score_evaluation','examples':len(rows),'training_robots':len(training),
                 'training_matches':len({r['match_key'] for r in training}),'development_partition':development,'evaluation':evaluation,
                 'target_note':'Learns attributed observer point estimates, including imported scored-fuel/climb reports. Agreement with estimates is not official scoring accuracy.'}


def score_alliance(row):
    roster=row.get('roster') or {}
    return next((a for a in ('red','blue') if row.get('team_number') in (roster.get(a) or [])),None)


def apportion(weights, total):
    """Largest-remainder whole points that sum exactly to the alliance total."""
    if sum(weights)<=0:weights=[1.]*len(weights)
    raw=[total*w/sum(weights) for w in weights];parts=[int(math.floor(v)) for v in raw]
    for i in sorted(range(len(raw)),key=lambda i:(parts[i]-raw[i],i))[:total-sum(parts)]:parts[i]+=1
    return parts


def validate_video(rows):
    """Leave-one-match-out point error of the video regressor versus the training mean."""
    matches=sorted({r['match_key'] for r in rows});video=[];mean=[]
    for match_key in matches:
        train=[dict(r,split='training',group='g',source_sha256='s') for r in rows if r['match_key']!=match_key]
        if len(train)<2 or len({r['points'] for r in train})<2:continue
        head,_=fit(train,point_mode=True)
        if not head:continue
        baseline=statistics.mean(r['points'] for r in train)
        for r in rows:
            if r['match_key']==match_key:video.append(abs(predict_share(head,r['x'])-r['points']));mean.append(abs(baseline-r['points']))
    if len(video)<6 or len(matches)<3:return {'status':'needs_three_matches_of_video_targets','robots':len(video)},0.
    detail={'status':'evaluated','method':'leave-one-match-out','robots':len(video),'point_mae_video':round(statistics.mean(video),3),'point_mae_training_mean':round(statistics.mean(mean),3)}
    # Video only moves the allocation when it beats "everyone scores the average" on unseen matches.
    return detail,(.5 if detail['point_mae_video']<=.9*detail['point_mae_training_mean'] else 0.)


def valid(head):
    if not isinstance(head,dict) or (head.get('version'),head.get('target')) not in ((VERSION,'share_of_full_final_alliance_score'),(POINT_VERSION,'individual_robot_points')) or head.get('feature_dimension')!=DIM or head.get('basis')!='includes_penalties':raise ValueError('Unsupported scoring model.')
    for key in ('mean','scale'):
        if not isinstance(head.get(key),list) or len(head[key])!=DIM:raise ValueError('Invalid scoring feature transform.')
    centers=head.get('centers');coeff=head.get('coefficients')
    if not isinstance(centers,list) or not 2<=len(centers)<=MAX_SUPPORT or not isinstance(coeff,list) or len(coeff)!=len(centers) or any(not isinstance(v,list) or len(v)!=DIM for v in centers):raise ValueError('Invalid scoring model support vectors.')
    values=head['mean']+head['scale']+coeff+[head.get('bias')]+[x for row in centers for x in row]
    if any(type(v) not in (int,float) or not math.isfinite(v) for v in values) or any(s<=0 for s in head['scale']):raise ValueError('Nonfinite scoring model values.')
    for key in ('used_label_ids','training_sources','training_groups'):
        if not isinstance(head.get(key),list) or not head[key] or not all(isinstance(v,str) and v for v in head[key]):raise ValueError('Scoring model provenance is missing.')


def predict_share(head,vector):
    x=[(v-m)/s for v,m,s in zip(vector,head['mean'],head['scale'])]
    return min(100000. if head.get('version')==POINT_VERSION else 1.,max(0.,head['bias']+sum(c*kernel(x,center) for c,center in zip(head['coefficients'],head['centers']))))


def video_ready(head):
    validation=((head or {}).get('validation') or {}).get('video') or {}
    return (validation.get('status')=='evaluated' and validation.get('robots',0)>=6
            and validation.get('point_mae_video',float('inf'))<=.9*validation.get('point_mae_training_mean',0))


def predict(video,facts,head,roster,totals=None,extractor=None):
    """Return independently gated video estimates; never force an alliance allocation.

    Observed points require counted, robot-attributed scoring evidence. This
    appearance regressor supplies estimates only, and cannot certify those events.
    Team history is retained in old artifacts for provenance, never used here.
    """
    valid(head);out=[]
    if totals is None:
        result=facts.get('results_screen',{})
        totals={a:result.get(a) for a in ('red','blue')} if result.get('status')=='accepted' else {}
    validated=video_ready(head)
    for alliance in ('red','blue'):
        total=totals.get(alliance)
        if type(total) is not int or not 0<=total<=100000:total=None
        for team in roster[alliance]:
            desc,reason=descriptor(video,facts,team,round(facts['source']['duration_s']*1000),extractor if validated else (lambda *args:[0.]*1296))
            row={'team':team,'alliance':alliance,'status':'unknown','robot_points':None,'share_percent':None,
                 'certainty':'uncertain','evidence_type':'model_score_prediction','score_basis':'full_final_alliance_score',
                 'reason':reason,'alliance_score':total,'observed_points':None,'alliance_constraint_applied':False}
            if desc:
                row.update(sightings=desc['sightings'],time_bins=desc['time_bins'])
                if not validated:row['reason']='video_regressor_has_not_beaten_held_out_baseline'
                elif head.get('version')!=POINT_VERSION and total is None:row['reason']='final_alliance_score_unavailable'
                else:
                    points=predict_share(head,desc['x'])
                    if head.get('version')!=POINT_VERSION:points*=total
                    if total is not None and points>total:
                        row['reason']='video_estimate_exceeds_alliance_total'
                    else:
                        row.update(status='predicted',robot_points=round(points),
                                   share_percent=round(100*round(points)/total,3) if total else None,
                                   reason=None,allocation_basis='identified_video_only')
            out.append(row)
    # Over-allocation signals a bad model; normalization would conceal the error.
    for alliance in ('red','blue'):
        group=[r for r in out if r['alliance']==alliance];total=group[0]['alliance_score'] if group else None
        if total is not None and sum(r['robot_points'] or 0 for r in group)>total:
            for row in group:
                if row['status']=='predicted':row.update(status='unknown',robot_points=None,share_percent=None,reason='inconsistent_alliance_predictions')
    return out


def evaluate_match(video,facts,head,reference):
    roster=reference['roster'];targets=reference.get('robot_scores',{});totals=reference.get('alliance_scores',{});expected=[]
    complete=True
    for alliance in ('red','blue'):
        total=totals.get(alliance)
        if type(total) is not int or not 0<=total<=100000:complete=False
        sum_points=0
        for team in roster[alliance]:
            row=targets.get(str(team),{});points=row.get('robot_points')
            valid_target=type(points) is int and total is not None and 0<=points<=total and row.get('method')=='counted' and row.get('complete') is True
            if not valid_target:complete=False
            else:sum_points+=points;expected.append((team,points,total))
        if type(total) is int and sum_points>total:complete=False
    if reference.get('score_basis')!='full_final_alliance_score':complete=False
    if not complete:return {'match_key':reference['match_key'],'expected':6,'predicted':0,'complete_reference':False,'point_errors':[],'share_errors':[]}
    output=predict(video,facts,head,roster,totals);by_team={r['team']:r for r in output};errors=[];shares=[]
    for team,points,total in expected:
        pred=by_team[team]
        if pred['status']=='predicted':errors.append(abs(pred['robot_points']-points));shares.append(abs(pred['robot_points']-points)/total if total else 0.)
    return {'match_key':reference['match_key'],'expected':6,'predicted':len(errors),'complete_reference':True,'point_errors':errors,'share_errors':shares,'predictions':output}


def evaluation_report(rows,events):
    errors=[e for row in rows for e in row['point_errors']];shares=[e for row in rows for e in row['share_errors']]
    count=sum(r['expected'] for r in rows);coverage=len(errors)/count if count else 0
    point_mae=statistics.mean(errors) if errors else None;share_mae=statistics.mean(shares) if shares else None
    ready=len(rows)>=10 and events>=2 and all(r['complete_reference'] for r in rows) and coverage>=.9 and point_mae is not None and point_mae<=20 and share_mae<=.1
    return {'schema_version':1,'matches':len(rows),'events':events,'reference_robots':count,'predicted_robots':len(errors),'prediction_coverage':coverage,
            'point_mae':point_mae,'share_mae':share_mae,'complete_reference':all(r['complete_reference'] for r in rows),'release_ready':ready,
            'criteria':{'min_matches':10,'min_events':2,'min_prediction_coverage':.9,'max_point_mae':20,'max_share_mae':.1},
            'note':'Release thresholds are engineering checks, not a six-scout accuracy claim. Missing predictions count against coverage.'}


def send_test_video(config,job,video,facts,tags,scores,game_key,model_id,title,status):
    """The match test as a video (robots boxed with team numbers, scoring areas, predicted actions), sent to Neptune,
    which uploads it to YouTube. Never fails the test."""
    try:
        import base64
        import neptune_review_video as rv
        from neptune_robot_jobs import api,HOME,VERSION
        status('Making the match test video')
        out=HOME/'review-videos'/('test-'+job['id']+'.mp4');out.parent.mkdir(parents=True,exist_ok=True)
        rv.match_test_video(out,video,facts,tags,scores,game_key,model_id,title)
        if out.stat().st_size>45*1024*1024:out.unlink();return {'status':'too_large'}
        name,description=rv.describe_test(title,tags,scores,model_id,VERSION)
        status('Sending the match test video to Neptune')
        reply=api(config,'trial_video_put',{'job_id':job['id'],'match_key':job['request'].get('match_key'),'title':name,'description':description,'video_b64':base64.b64encode(out.read_bytes()).decode()},timeout=900)
        out.unlink()
        return reply.get('review') or {'status':'sent'}
    except Exception as error:
        return {'status':'failed','error':str(error)[:200]}


def run_trial(config,season,match_id,progress,job=None,transport=None):
    """Run owner-requested tags + score predictions, without generating a summary."""
    import hashlib,json,threading
    from pathlib import Path
    import neptune_trainer as trainer
    import neptune_games as games
    import neptune_training as training
    from neptune_helper import download_video
    from neptune_video_labels import probe_duration_ms,sha256_file
    from neptune_facts import analyze_video
    call=transport or trainer.api
    job=job or call(config,'trial_start',{'season':int(season),'match_id':int(match_id)})
    request=job['request'];folder=trainer.HOME/'trials'/job['id'];folder.mkdir(parents=True,exist_ok=True)
    pack=dict(job['game_pack'],season=season);pack_file=folder/'game.json';pack_file.write_text(json.dumps(pack));games.install(pack_file,folder);game=games.select(str(season)+'trial',folder)
    import os;os.environ['NEPTUNE_GAMES_HOME']=str(folder)
    model=job['model']
    state={'message':'Preparing scoring test'};done=threading.Event();lost=[]
    payload={'job_id':job['id'],'lease':job['lease']}
    def status(message):
        if lost:raise lost[0]
        state['message']=str(message)[:900];progress(message)
    def heartbeat():
        while not done.wait(20):
            try:call(config,'page_heartbeat' if job.get('control') else 'trial_heartbeat',dict(payload,message=state['message']))
            except Exception as error:lost.append(error);return
    thread=threading.Thread(target=heartbeat,daemon=True);thread.start()
    cache=video=work=sha=None
    try:
        training.model_valid(model,game)
        class Stage(list):
            def __setitem__(self,key,value):super().__setitem__(key,value);status(value)
        site_key=hashlib.sha256(config['site'].rstrip('/').encode()).hexdigest()[:24];cache=trainer.HOME/'cache'/site_key
        vid=request['source_url'].split('v=')[-1];video=cache/'videos'/(vid+'.mp4');video.parent.mkdir(parents=True,exist_ok=True)
        match={'key':request['match_key'],'title':request['title'],'roster':request['roster'],'source_url':request['source_url'],'source_sha256':None}
        from neptune_cache import require_space
        require_space(cache)
        status('Reading '+request['title'])
        if not video.exists():download_video(match,video,Stage(['Downloading match video']))
        if video.stat().st_size>512*1024**2:raise ValueError('Individual match video exceeds the size limit.')
        sha=sha256_file(video);duration=probe_duration_ms(video)
        if not 1000<=duration<=600000:raise ValueError('Choose an individual match video of at most ten minutes.')
        work=cache/'trials'/sha/game['fingerprint']/training.digest({'key':match['key'],'roster':match['roster']});work.mkdir(parents=True,exist_ok=True)
        facts_file=work/'facts.json'
        if facts_file.exists():facts=json.loads(facts_file.read_text())
        else:
            # Reuse the established localization cache only when its roster and match checks agree.
            prior=cache/'localizations'/sha/game['fingerprint']/'facts.json';facts=None
            if prior.exists():
                candidate=json.loads(prior.read_text());checks=candidate.get('identity',{}).get('checks',{});stations=checks.get('roster',{}).get('stations',[])
                claimed=sorted(s.get('claimed') for s in stations if isinstance(s.get('claimed'),int))
                if checks.get('match_number',{}).get('claimed_key')==match['key'] and claimed==sorted(request['roster']['red']+request['roster']['blue']):facts=candidate
            if facts is None:facts=analyze_video(video,match,work,Stage(['Reading match video']),duration=duration/1000,source_sha256=sha)
            training.write(facts_file,facts)
        if facts.get('source',{}).get('sha256')!=sha:raise ValueError('Cached video evidence changed.')
        facts=training.refresh_cached_facts(facts_file,facts,match,game,sha)
        facts=training.ensure_tracking(video,facts,game,work,status)
        training.write(facts_file,facts)
        from neptune_field_regions import sync,sync_events,event_key,match_key_of,load as load_regions
        field_sync=sync(config,video,facts)
        field_sync['event']=sync_events(config,[event_key(match_key_of(facts))])
        # Zones come only from marks (this video or its event); the field detector's guesses are not used.
        field_sync['detection']={'status':'marked_regions_available' if load_regions(facts,game['game_key']) else 'unmarked_event'}
        status('Predicting robot action tags')
        tags=training.predict(video,facts,model,game)
        from neptune_scouting_rules import summarize
        scouting_rules=summarize(facts,tags,model['model_id'],facts.get('scouting_rule_settings'))
        from neptune_field_regions import observations
        field_observations=observations(facts,game['game_key'])
        from neptune_homography import trajectories
        field_trajectories=trajectories(facts,game['game_key'])
        # New observational tags are retained in structured rules output; existing
        # server tag vocabulary receives only supported inferred tags.
        tags=sorted(tags+[r for r in scouting_rules['events'] if r['tag_key'] not in ('stationary','disabled')],key=lambda r:(r['start_ms'],r['team']))
        status('Predicting robot score contributions')
        scores=predict(video,facts,model['scoring_head'],request['roster'],request.get('alliance_scores',{})) if model.get('scoring_head') else [dict(team=t,alliance=a,status='unknown',robot_points=None,share_percent=None,reason='scoring_model_unavailable',certainty='uncertain') for a,teams in request['roster'].items() for t in teams]
        scores=apply_recorded_contributions(scores,tags)
        from neptune_engine import identity
        output={'model_id':model['model_id'],'source_sha256':sha,'duration_s':duration/1000,'scores':scores,'tags':tags,'scouting_rules':scouting_rules,'field_observations':field_observations,'field_trajectories':field_trajectories,'field_region_sync':field_sync,
                'diagnostics':{'engine':identity()['version'],'action_heads_available':len(training.usable_heads(model)),'scoring_video_validated':video_ready(model.get('scoring_head')),'blocked_action_heads':sorted(set(model['heads'])-set(training.usable_heads(model))),
                               'identity_coverage':facts.get('tracking',{}).get('coverage',{}),'tracking_status':facts.get('tracking',{}).get('status','measured')}}
        training.write(folder/'result.json',output)
        send_test_video(config,job,video,facts,tags,scores,game['game_key'],model['model_id'],request.get('title') or request['match_key'],status)
        status('Saving robot tags and score estimates on Neptune')
        return call(config,'trial_finish',dict(payload,result=output))
    except Exception as error:
        try:call(config,'trial_finish',dict(payload,error=str(error)[:900]))
        except Exception:pass
        raise
    finally:
        done.set();thread.join(timeout=2)
        if cache is not None and video is not None:
            from neptune_cache import cleanup
            cleanup(cache,video.stem,sha,work,progress)



def is_scoring_action(key):
    """Count scoring observations, never feeding/defense/movement as scoring.

    Game Builder codes stay distinct. Attempts and failures are not successes.
    Generic legacy scoring remains a sequence observation, not a fuel count.
    """
    import re
    return (key in ('scoring','end_completed') or isinstance(key,str) and
            bool(re.fullmatch(r'gb:(?:score_[A-Za-z0-9_.:-]+|climb_lv_[1-3])',key)) and
            not any(word in key.lower() for word in ('attempt','miss','fail')))


def apply_recorded_contributions(scores,tags):
    """Owner-requested allocation, distinct from share of the final scoreboard.

    No recorded scoring => zero contribution by policy, even if coverage is poor.
    That policy is not independent evidence that a robot actually scored zero.
    """
    import copy
    result=copy.deepcopy(scores)
    counts={r['team']:0 for r in result}
    for event in tags:
        if is_scoring_action(event.get('tag_key')) and event.get('team') in counts:counts[event['team']]+=1
    for row in result:
        row['recorded_scoring_events']=counts[row['team']]
        if not counts[row['team']]:
            row.update(status='predicted',robot_points=0,share_percent=0 if row.get('alliance_score',0) else None,
                       reason='no_recorded_scoring',observed_points=None,zero_by_policy=True)
    for alliance in ('red','blue'):
        group=[r for r in result if r['alliance']==alliance]
        active=[r for r in group if r['recorded_scoring_events']]
        point_weights=bool(active) and all(type(r.get('robot_points')) is int and r['robot_points']>0 for r in active)
        weights=[(r['robot_points'] if point_weights else r['recorded_scoring_events']) if r['recorded_scoring_events'] else 0 for r in group]
        total=sum(weights);units=apportion(weights,100000) if total else [0]*len(group)
        for row,value in zip(group,units):
            row.update(contribution_percent=value/1000,contribution_basis='recorded_scoring_point_estimates' if point_weights else 'recorded_scoring_event_count',
                       contribution_status='normalized' if total else 'no_scoring_recorded')
    return result
