"""Local retrainable tag baseline. No paid API. Public tags are positives; sampled footage stays unlabeled.

The first feature backend uses short robot-localized RGB/motion clips. It is an experimental
appearance classifier, not a validated replacement for six observers. Each game has separate
artifacts. Identity-ambiguous crops, uncertain/withdrawn labels and conflicting labels are excluded.
Only models passing calibration and a separate full-match six-robot benchmark may be activated.
"""
import argparse
import collections
import datetime
import hashlib
import json
import math
import os
import statistics
import subprocess
from pathlib import Path
import neptune_games as games
import neptune_video_labels as labels
import neptune_action_labels as actions

FEATURE_VERSION='robot-rgb-motion-v1'
ACTION_FEATURE_VERSION='robot-r3d18-clip-v1'
VIDEOMAE_FEATURE_VERSION='robot-videomae-base-clip-v1'
FEATURE_DIMENSIONS={FEATURE_VERSION:1296,ACTION_FEATURE_VERSION:512,VIDEOMAE_FEATURE_VERSION:768}

def selected_action_version():
    name=os.environ.get('NEPTUNE_ACTION_BACKEND','r3d18')
    if name not in ('r3d18','videomae'):raise ValueError('Unknown action encoder backend.')
    return VIDEOMAE_FEATURE_VERSION if name=='videomae' else ACTION_FEATURE_VERSION

def action_encoder_document(version):
    if version==VIDEOMAE_FEATURE_VERSION:
        from neptune_videomae import encoder_document
    elif version==ACTION_FEATURE_VERSION:
        from neptune_temporal import encoder_document
    else:raise ValueError('Unknown temporal action encoder.')
    return encoder_document()

def prepare_action_encoder(progress=print):
    version=selected_action_version()
    if version==VIDEOMAE_FEATURE_VERSION:
        from neptune_videomae import verify_weights
        verify_weights()
        progress('Verified local VideoMAE evaluation weights. Game actions use separate Neptune heads.')
    else:
        from neptune_temporal import prepare_weights
        prepare_weights(progress)
    return version

def action_features(video,sight,duration,facts,version=None):
    version=version or selected_action_version()
    if version==VIDEOMAE_FEATURE_VERSION:
        from neptune_videomae import features as temporal_features
    elif version==ACTION_FEATURE_VERSION:
        from neptune_temporal import features as temporal_features
    else:raise ValueError('Unknown temporal action encoder.')
    return temporal_features(video,sight,duration,facts)

ABSENCE_NEGATIVES={'no_scoring':['scoring'],'no_defense':['attempted_defense'],
                  'auto_no_attempt':['auto_partial','auto_completed','auto_failed'],
                  'end_no_attempt':['end_partial','end_completed','end_failed']}
ABSENCE=set(ABSENCE_NEGATIVES)
HELD_OUT_MATCHES={'2026txcmp1_qm7'}
HELD_OUT_SOURCES={'9e031cb6637fbd661624124d019b587ff81ca5f5a9fd20c76ff737404380e2bc'}


def write(path, value):
    path=Path(path);path.parent.mkdir(parents=True,exist_ok=True)
    tmp=path.with_suffix('.tmp');tmp.write_text(json.dumps(value,indent=1,allow_nan=False));tmp.replace(path)


def digest(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True,separators=(',',':')).encode()).hexdigest()


def corpus(store, game, audit=None):
    out=[];excluded=collections.Counter()
    for id,entry in sorted(store.load('ledger.json',{}).items()):
        row=(entry.get('revisions') or [{}])[-1].get('record',entry['original'])
        if row.get('match_key') in HELD_OUT_MATCHES:excluded['independent_benchmark_reserved']+=1;continue
        if entry['state'] not in labels.USABLE_STATES:excluded['withdrawn_or_unavailable']+=1;continue
        if not str(row.get('match_key','')).startswith(str(game['season'])):continue
        if isinstance(row.get('scoring'),dict) and row['scoring'].get('mode')=='alliance_share':continue
        if row.get('certainty')!='observed':excluded['uncertain']+=1;continue
        if actions.is_game_action(row.get('tag_key')):
            problem=actions.error(row,game)
            if problem:
                excluded[problem]+=1
                if audit is not None:audit.append({'label_id':row.get('id'),'match_key':row.get('match_key'),'tag_key':row.get('tag_key'),'reason':problem})
                continue
        else:
            if row.get('definition_version')!='neptune-match-tags-v1' and not labels.external_label(row):excluded['unsupported_definition']+=1;continue
            if row.get('tag_key') not in games.TAG_KEYS:excluded['unsupported_label']+=1;continue
        if row.get('polarity','present') not in ('present','absent'):excluded['unsupported_label']+=1;continue
        out.append(dict(row,polarity=row.get('polarity','present')))
    return out,dict(excluded)


def inventory(rows, game, excluded=None):
    counts={tag:{'present':0,'absent':0} for tag in sorted(set(games.TAG_KEYS)|{r['tag_key'] for r in rows})}
    for r in rows:counts[r['tag_key']][r['polarity']]+=1
    return {'game_key':game['game_key'],'game':game['game'],'labels':len(rows),'matches':len({r['match_key'] for r in rows}),
            'events':len({labels.event_key(r['match_key']) for r in rows}),'counts':counts,'excluded':excluded or {},
            'action_definitions':actions.definitions(rows),
            'external_activity_labels':sum(labels.external_label(r) for r in rows),'readiness':'Needs identifiable robot clips and independent complete-match evaluation. Outside activity is weak supervision; missing tags are unknown. Counts are not accuracy.'}


def split_group(key):
    n=int(hashlib.sha256(key.encode()).hexdigest()[:8],16)%10
    return 'training' if n<6 else 'calibration' if n<8 else 'test'


def sightings(facts):
    """One exact, alliance-colored robot read per team/frame. Wide-shot-only IDs stay excluded."""
    bump=facts.get('bumpers',{});seen=set();out=[];top=(facts.get('layout') or {}).get('camera_top',.24)
    for r in bump.get('reads',[]):
        identity=bump.get('identities',{}).get(str(r.get('team'))) or bump.get('identities',{}).get(r.get('team')) or {}
        key=(r.get('team'),r.get('t'))
        if key in seen or identity.get('status')!='confirmed' or r.get('kind')!='exact' or r.get('background')!=identity.get('alliance') or r.get('background') not in ('red','blue'):continue
        b=r.get('frame_box')
        if not b or len(b)!=4:continue
        x,y,w,h=b;y=top+(1-top)*y;h=(1-top)*h
        width=min(.5,max(.10,w*5));height=min(.6,max(.16,h*10))
        box=[max(0,min(1-width,x+w/2-width/2)),max(top,min(1-height,y-height*.75)),width,height]
        # Refuse a crop containing another roster robot's readable number.
        ambiguous=False
        for other in bump.get('reads',[]):
            ob=other.get('frame_box')
            if other.get('team')==r['team'] or other.get('t')!=r['t'] or not ob:continue
            cx=ob[0]+ob[2]/2;cy=top+(1-top)*(ob[1]+ob[3]/2)
            if box[0]<=cx<=box[0]+box[2] and box[1]<=cy<=box[1]+box[3]:ambiguous=True;break
        if not ambiguous:out.append({'team':r['team'],'t':r['t'],'box':box,'identity_evidence':r['frame']});seen.add(key)
    # Short, directly OCR-anchored track segments add identity continuity. Inherited
    # fragments and interpolated positions never become training crops.
    for sight in facts.get('tracking',{}).get('localizations',[]):
        key=(sight['team'],round(sight['t']))
        if sight.get('identity_method') not in ('direct_vote_track','yolo_botsort_ocr','human_reviewed_track','roster_inferred'):continue
        if sight.get('identity_method')!='human_reviewed_track' and any(other['team']!=sight['team'] and abs(other['t']-sight['t'])<.2
               and boxes_overlap(sight['box'],other['box'])>.3 for other in facts.get('tracking',{}).get('localizations',[])):continue
        out.append(sight);seen.add(key)
    reviewed=facts.get('tracking',{}).get('review_supervision')
    if reviewed:
        bins=collections.defaultdict(list)
        for p in reviewed.get('overrides',[]):bins[round(p['t']*10)].append(p)
        def overridden(s):
            candidates=[p for k in range(round(s['t']*10)-1,round(s['t']*10)+2) for p in bins.get(k,[])]
            return any(abs(p['t']-s['t'])<.08 and (p.get('camera')==s.get('camera') or not s.get('camera')) and (boxes_overlap(p['box'],s['box'])>.35 or p.get('team')==s['team']) for p in candidates)
        out=[s for s in out if s.get('identity_method')=='human_reviewed_track' or not overridden(s)]
    return out


def boxes_overlap(a,b):
    intersection=max(0,min(a[0]+a[2],b[0]+b[2])-max(a[0],b[0]))*max(0,min(a[1]+a[3],b[1]+b[3])-max(a[1],b[1]))
    return intersection/max(1e-9,min(a[2]*a[3],b[2]*b[3]))


def refresh_cached_facts(path,facts,match,game,source_sha,video=None,progress=lambda s:None):
    from neptune_facts import PIPELINE_VERSION,replay_alliance_layout,analyze_video
    if not facts.get('pipeline') or facts.get('pipeline')==PIPELINE_VERSION:return facts
    original=Path(path);sources=[original]
    # Prior game fingerprints can hold the immutable OCR which a newer prepared
    # record references. Try only sibling evidence for the identical source SHA.
    for parent in original.parents:
        root=parent/'localizations'/source_sha
        if root.is_dir():sources.extend(sorted(root.glob('*/facts.json')));break
    replayed=None
    for source in dict.fromkeys(sources):
        if not source.is_file() or not (source.parent/'raw-overlay.jsonl').is_file():continue
        replayed=replay_alliance_layout(source,match,game,source_sha)
        if replayed is not None:break
    archive=original.with_name('facts-before-'+str(facts.get('pipeline','unknown')).replace('/','_')+'.json')
    if not archive.exists():write(archive,facts)
    if replayed is None:
        if video is None:raise ValueError('Saved video evidence needs fresh OCR with this engine; cached readings could not be reprocessed.')
        # A changed crop cannot be recovered from old OCR. Re-read the verified
        # video into a new evidence directory; never overwrite the old raw OCR.
        work=original.parent/'ocr-refresh'/PIPELINE_VERSION/game['fingerprint']
        work.mkdir(parents=True,exist_ok=True)
        progress('Refreshing video OCR because the saved camera layout changed')
        class Stage(list):
            def __setitem__(self,key,value):super().__setitem__(key,value);progress(value)
        replayed=analyze_video(video,match,work,Stage(['']),duration=facts['source']['duration_s'],source_sha256=source_sha)
        if replayed.get('source',{}).get('sha256')!=source_sha:raise ValueError('Refreshed source fingerprint changed.')
        replayed['cache_refresh']={'kind':'fresh-ocr','original_pipeline':facts.get('pipeline'),'original_evidence_preserved':True}
        write(work/'facts.json',replayed)
    return replayed


def roster_of(facts):
    identities=(facts.get('bumpers') or {}).get('identities') or {}
    return {c:[int(t) for t,v in identities.items() if v.get('alliance')==c] for c in ('red','blue')}


def ensure_tracking(video,facts,game,work,progress=lambda s:None):
    from neptune_tracking import track_match,TRACKING_VERSION,FRAME_DECODER_REVISION
    from neptune_yolo import fingerprint,CONTINUITY_REVISION
    if facts.get('tracking',{}).get('version')==TRACKING_VERSION and facts.get('tracking',{}).get('model_fingerprint')==fingerprint() and facts.get('tracking',{}).get('camera_engine')=='independent-camera-views-v4' and facts.get('tracking',{}).get('decoder_revision')==FRAME_DECODER_REVISION and facts.get('tracking',{}).get('continuity_revision')==CONTINUITY_REVISION:
        from neptune_identity import VERSION as identity_revision,reconcile
        tracking=facts['tracking']
        from neptune_persistent_identity import build,VERSION as persistent_revision
        if tracking.get('observations') and tracking.get('persistent_identity',{}).get('version')!=persistent_revision:
            roster={c:[int(t) for t,v in facts['bumpers']['identities'].items() if v['alliance']==c] for c in ('red','blue')}
            tracking['persistent_identity']=build(tracking['observations'],roster,facts['source']['sha256'])
        from neptune_review_learning import active_association
        association=active_association()
        if tracking.get('observations') and (tracking.get('identity_review',{}).get('solver')!=identity_revision or tracking.get('association_sha')!=digest(association)):
            roster={c:[int(t) for t,v in facts['bumpers']['identities'].items() if v['alliance']==c] for c in ('red','blue')}
            tracking['identity_review']=reconcile(tracking['observations'],roster,facts['source']['sha256'],association=association)
            tracking['association_sha']=digest(association)
        from neptune_identity import with_roster_inferred
        from neptune_constant_identity import with_constant_identity
        facts=dict(facts,tracking=with_constant_identity(with_roster_inferred(tracking),roster_of(facts)))
        from neptune_review_learning import apply_to_facts
        return apply_to_facts(facts)
    profile=game.get('layout_profiles',{}).get(facts.get('layout',{}).get('profile'))
    if not profile or not any(facts.get('periods',{}).values()):
        from neptune_review_learning import apply_to_facts
        return apply_to_facts(facts)
    progress('Tracking robot identity between bumper readings')
    from neptune_ocr import select_backend
    try:
        facts=dict(facts,tracking=track_match(video,facts,profile,select_backend(),work,progress=progress))
    except InterruptedError:
        # A stopped review or job is not a tracking result; leave the match to be tracked next time.
        raise
    except (ValueError,RuntimeError,ImportError,subprocess.SubprocessError,OSError) as error:
        facts=dict(facts,tracking={'status':'unavailable','reason':str(error)[:300]})
    if facts['tracking'].get('status')=='measured':
        from neptune_identity import with_roster_inferred
        from neptune_constant_identity import with_constant_identity
        facts=dict(facts,tracking=with_constant_identity(with_roster_inferred(facts['tracking']),roster_of(facts)))
    from neptune_review_learning import apply_to_facts
    return apply_to_facts(facts)


def features(video, sighting, duration_ms):
    """Three local 12x12 RGB samples + temporal differences; no team ID, score, clock or tag input."""
    import tempfile
    from neptune_facts import read_ppm
    x,y,w,h=sighting['box'];frames=[]
    # Use the already-bundled PPM encoder, including the minimal macOS FFmpeg build.
    with tempfile.TemporaryDirectory(prefix='neptune-training-') as temp:
        for i,dt in enumerate((-.5,0,.5)):
            t=max(0,min(duration_ms/1000-.04,sighting['t']+dt))
            vf='crop=iw*%.6f:ih*%.6f:iw*%.6f:ih*%.6f,scale=12:12'%(w,h,x,y)
            image=Path(temp)/('%d.ppm'%i)
            subprocess.run(['ffmpeg','-nostdin','-v','error','-threads','1','-ss',str(t),'-i',str(video),'-frames:v','1','-vf',vf,str(image)],check=True,timeout=60)
            width,height,raw=read_ppm(image)
            if width!=12 or height!=12 or len(raw)!=432:raise ValueError('A robot clip could not be read.')
            frames.append([v/255 for v in raw])

    return frames[1]+[abs(b-a) for a,b in zip(frames[0],frames[1])]+[abs(b-a) for a,b in zip(frames[1],frames[2])]


def outside_video_time(row, phase, game_length):
    """Join a source phase clock to nearby observed video-clock readings, without extrapolation.

    Broadcasts can drift or pause relative to wall time. A single inferred period start
    is not enough in that case. Sparse/missing clocks stay unavailable.
    """
    from neptune_clock import align
    joined=align(phase,row['start_ms'],game_length)
    return joined.get('video_ms') if joined['status']=='aligned' else None


def outside_video_alignment(row,phase,game_length):
    """Compatibility with the deployed clock-alignment API; common engine is authoritative."""
    from neptune_clock import align
    joined=align(phase,row.get('start_ms'),game_length)
    return joined if joined['status']=='aligned' else None


def video_aligned_label(row,facts):
    return aligned_record(row,facts)[0]


def aligned_record(row, facts):
    if not labels.external_label(row):return row,None
    name=row['external']['phase'];phase=facts.get('periods',{}).get(name) or {}
    length=(facts.get('rules') or {}).get(name+'_seconds') or phase.get('length_rule')
    if not length:return None,'external_clock_length_unavailable'
    from neptune_clock import align
    start=align(phase,row['start_ms'],length)
    end=align(phase,row.get('end_ms') if row.get('end_ms') is not None else row['start_ms'],length)
    if start['status']!='aligned' or end['status']!='aligned':
        return None,'external_clock_'+(start.get('reason') or end.get('reason') or 'ambiguous')
    if end['video_ms']<start['video_ms']:return None,'external_clock_nonmonotonic_interval'
    # Clock alignment locates the recorded click, not necessarily the action.
    # A bounded reaction-delay allowance is a weak-supervision assumption,
    # never a measured correction to this scout's timestamps.
    reaction_start=max(round(phase['start']*1000),start['video_ms']-3000)
    return dict(row,start_ms=start['video_ms'],end_ms=end['video_ms'],clock_alignment=start,
                action_time_window={'start_ms':reaction_start,'end_ms':end['video_ms'],
                    'reaction_delay_max_ms':3000,'policy':'imported-click-lookback-v1',
                    'assumed_not_measured':True,'clock_uncertainty_ms':max(start['uncertainty_ms'],end['uncertainty_ms'])}),None


def clock_excluded(rows, facts):
    """True when the match's clock join is too poor to place any outside-scouting row (neptune_quality)."""
    external=[r for r in rows if labels.external_label(r)]
    if not external:return False
    from neptune_quality import clock_report
    return clock_report(facts,external)['verdict']=='exclude'


def labeled_examples(rows, fact_map, sources, videos, progress=lambda s:None, extractor=None,audit=None,temporal_required=False,field_regions=None):
    examples={};excluded=collections.Counter();cache={};hash_groups={};clock_gate={}
    def reject(row,reason):
        excluded[reason]+=1
        if audit is not None:audit.append({'label_id':row['id'],'match_key':row['match_key'],'team':row['team_number'],'category':row['tag_key'],'status':'rejected','reason':reason})
    for original_row in rows:
        row=original_row
        src=sources.get(row['video_id'],{});facts=fact_map.get(row['video_id']);tag=row['tag_key']
        if src.get('status')!='verified' or not facts or src.get('label_checks',{}).get(row['id'],'ok')!='ok':reject(original_row,'video_not_verified_or_localized');continue
        if facts.get('source',{}).get('sha256')!=src['sha256']:reject(original_row,'source_fingerprint_mismatch');continue
        if src.get('sha256') in HELD_OUT_SOURCES:reject(original_row,'independent_benchmark_source_reserved');continue
        is_external=labels.external_label(row)
        if is_external:
            if any(facts.get('identity',{}).get('checks',{}).get(k,{}).get('status')!='match' for k in ('match_number','roster')):
                reject(original_row,'external_match_identity_unverified');continue
            row,reason=aligned_record(row,facts)
            if row is None:reject(original_row,reason);continue
            if original_row['video_id'] not in clock_gate:
                clock_gate[original_row['video_id']]=clock_excluded([r for r in rows if r['video_id']==original_row['video_id']],facts)
            if clock_gate[original_row['video_id']]:reject(original_row,'external_clock_match_excluded');continue
            if row['start_ms']<0 or row['end_ms']>src['duration_ms']:
                reject(original_row,'external_activity_outside_video');continue
        group=labels.event_key(row['match_key'])
        # Identical video bytes under different match IDs share one partition.
        group=hash_groups.setdefault(src['sha256'],group)
        from neptune_clock import eligible
        team_sights=[s for s in sightings(facts) if s['team']==row['team_number']]
        candidates=[s for s in team_sights if eligible(tag,facts,s['t'])]
        if tag in ABSENCE:
            if row['polarity']!='present':continue
            targets=ABSENCE_NEGATIVES[tag];ys=0
            phase='auto' if tag.startswith('auto_') else 'teleop' if tag.startswith('end_') else None
            if phase:
                p=facts.get('periods',{}).get(phase)
                if not p:reject(original_row,'absence_phase_unknown');continue
                candidates=[s for s in candidates if p['start']<=s['t']<=p['end']]
            candidates=candidates[::max(1,len(candidates)//12)] # cap redundant absence frames
        else:
            if row['label_scope']=='match':reject(original_row,'whole_match_action_has_no_local_time');continue
            targets=[tag];ys=0 if row['polarity']=='absent' else 1
            if row['label_scope']=='moment':
                window=row.get('action_time_window')
                low,high=(window['start_ms']/1000,window['end_ms']/1000) if window else (row['start_ms']/1000-1.1,row['start_ms']/1000+1.1)
                center=(low+high)/2
                # A sighting outside every match phase is a phase-gate rejection, not a
                # localization failure (e.g. a climb confirmed just after the buzzer).
                # Tracking and bumper OCR stop at the phase boundary, so test the label window itself.
                if not any(eligible(tag,facts,t) for t in (low,center,high)):
                    reject(original_row,'label_outside_eligible_phase');continue
                candidates=[s for s in candidates if low<=s['t']<=high]
                candidates=sorted(candidates,key=lambda s:(abs(s['t']-center),not bool(s.get('camera'))))
                if temporal_required:
                    from neptune_temporal import plan,ClipUnavailable
                    valid=[];reasons=[]
                    for candidate in candidates:
                        try:
                            clip=plan(facts,candidate)
                            if window and (clip['start']>low+.001 or clip['end']<high-.001):
                                reasons.append('external_reaction_window_not_covered');continue
                            valid.append(candidate);break
                        except ClipUnavailable as error:reasons.append(error.reason)
                    if candidates and not valid:
                        # Report the candidate nearest the label, not whichever was tried last.
                        reject(original_row,reasons[0])
                        if audit is not None:audit[-1]['candidate_reasons']=dict(collections.Counter(reasons))
                        continue
                    candidates=valid
                candidates=candidates[:1]
            else:candidates=[s for s in candidates if row['start_ms']/1000<=s['t']<=row['end_ms']/1000]
        if not candidates:reject(original_row,'robot_not_localized_at_label');continue
        # Marked scoring areas check scoring labels. Outside rows far from every scoring area are likely the
        # wrong robot or moment and are dropped; human labels are kept and only counted.
        reach=None
        if tag=='scoring' or str(tag).startswith('gb:score_'):
            from neptune_field_regions import scoring_reach
            results=[scoring_reach(c,(field_regions or {}).get(row['video_id']),facts) for c in candidates]
            reach=True if True in results else False if False in results else None
            if reach is False and is_external:reject(original_row,'external_scoring_away_from_scoring_area');continue
            if reach is False:excluded['human_scoring_away_from_scoring_area_kept']+=1
        if audit is not None:audit.append({'label_id':row['id'],'match_key':row['match_key'],'team':row['team_number'],'category':tag,'status':'localized','examples':len(candidates),'clock_alignment':row.get('clock_alignment'),'action_time_window':row.get('action_time_window')})
        for sight in candidates:
            ck=(row['video_id'],sight['team'],sight['t'],sight.get('camera'),tuple(sight['box']))
            if ck not in cache:
                progress('Extracting robot examples from '+row['match_key'])
                from neptune_temporal import ClipUnavailable
                try:cache[ck]=(extractor or features)(Path(videos)/src['file'],sight,src['duration_ms'])
                except ClipUnavailable as error:reject(original_row,error.reason);continue
            for target in targets:
                key=ck+(target,);item=examples.setdefault(key,{'tag':target,'x':cache[ck],'answers':set(),'label_ids':set(),
                    'match_key':row['match_key'],'team':sight['team'],'t_ms':round(sight['t']*1000),'source_sha256':src['sha256'],'group':group,'external_sources':[],'label_metadata':[]})
                if sight.get('temporal_clip'):item['temporal_clip']=sight['temporal_clip']
                item.setdefault('box',list(sight['box']));item.setdefault('camera',sight.get('camera'))
                if sight.get('identity_method')=='human_reviewed_track':item['identity_supervision']=sight['identity_evidence']
                item['answers'].add(ys);item['label_ids'].add(row['id'])
                metadata={k:row.get(k) for k in ('id','schema_version','season','game_key','tag_key','tag_label','tag_category','definition_version','label_scope','time_basis','start_ms','end_ms')}
                if metadata not in item['label_metadata']:item['label_metadata'].append(metadata)
                if is_external:item['external_sources'].append(dict(row['external'],label_id=row['id'],aligned_video_ms=row['start_ms'],clock_alignment=row.get('clock_alignment'),action_time_window=row.get('action_time_window'),exact_action_time_known=False))
    out=[]
    for item in examples.values():
        if len(item['answers'])!=1:
            excluded['conflicting_contributions']+=1
            if audit is not None:
                for label_id in item['label_ids']:audit.append({'label_id':label_id,'match_key':item['match_key'],'team':item['team'],'category':item['tag'],'status':'rejected','reason':'conflicting_contributions'})
            continue
        item['y']=next(iter(item.pop('answers')));item['label_ids']=sorted(item['label_ids']);item['split']=split_group(item['group']);out.append(item)
    return out,dict(excluded)


def robot_evidence_metrics(facts,team):
    """Count distinct OCR moments and union verified frame-support intervals across cameras.

    Seconds describe observed tracker samples, not interpolated identity through gaps.
    Overlapping camera samples count once; unsupported phases contribute nothing.
    """
    reads=[q for q in facts.get('bumpers',{}).get('reads',[]) if q.get('team')==team and q.get('kind')=='exact']
    moments={q['t'] for q in reads if type(q.get('t')) in (int,float) and math.isfinite(q['t'])
             and q.get('alliance') in ('red','blue') and q.get('background')==q['alliance']}
    tracking=facts.get('tracking',{});fps=tracking.get('fps');intervals=[]
    phases=[p for name,p in facts.get('periods',{}).items() if name in ('auto','teleop') and p]
    if type(fps) in (int,float) and math.isfinite(fps) and fps>0:
        for q in tracking.get('localizations',[]):
            t=q.get('t')
            if q.get('team')!=team or q.get('identity_method')!='yolo_botsort_ocr' or type(t) not in (int,float) or not math.isfinite(t):continue
            for phase in phases:
                if phase['start']<=t<=phase['end']:
                    intervals.append((t,min(t+1/fps,phase['end'])))
    seconds=0.;end=None
    for a,b in sorted(intervals):
        seconds+=max(0.,b-max(a,end if end is not None else a));end=max(b,end if end is not None else b)
    return {'direct_bumper_reads':len(reads),'direct_read_moments':len(moments),'verified_track_seconds':round(seconds,3)}


def reference_overlaps_label(sight, row):
    """A temporal reference must not contain a known label anywhere in its clip."""
    if row.get('start_ms') is None:return False
    window=row.get('action_time_window')
    padding=window['clock_uncertainty_ms'] if window else (2200 if labels.external_label(row) else 1100)
    start=row['start_ms'];end=row.get('end_ms') if row.get('end_ms') is not None else start
    if window:start,end=window['start_ms'],window['end_ms']
    clip=sight.get('temporal_clip')
    low,high=(clip['start']*1000,clip['end']*1000) if clip else (sight['t']*1000,sight['t']*1000)
    return start-padding<=high and low<=end+padding


def unlabeled_examples(rows, fact_map, sources, videos, progress=lambda s:None, limit_per_video=96, extractor=None, tag_keys=None,temporal_required=False):
    """Automatic reference crops, never saved as human negatives or used for accuracy metrics."""
    tags=sorted({r['tag_key'] for r in rows if r.get('polarity','present')=='present'
                 and r['tag_key'] not in ABSENCE and r.get('label_scope')!='match'})
    if tag_keys is not None:tags=sorted(tag_keys)
    out=[];groups={};emitted=set()
    for vid in sorted({r['video_id'] for r in rows}):
        src=sources.get(vid,{});facts=fact_map.get(vid)
        if src.get('sha256') in HELD_OUT_SOURCES:continue
        if src.get('status')!='verified' or not facts or facts.get('source',{}).get('sha256')!=src['sha256']:continue
        original=[r for r in rows if r['video_id']==vid]
        aligned=[aligned_record(r,facts) for r in original]
        records=[r for r,reason in aligned if r is not None]
        # Unalignable positives cannot safely supply references for that robot/tag.
        blocked={(r['team_number'],r['tag_key']) for r,(joined,reason) in zip(original,aligned) if joined is None}
        if clock_excluded(original,facts):
            blocked|={(r['team_number'],r['tag_key']) for r in original if labels.external_label(r)}
            records=[r for r in records if not labels.external_label(r)]
        group=groups.setdefault(src['sha256'],labels.event_key(original[0]['match_key']))
        # Labels stay distinct, but a known moment of a same-category action (Score 1 fuel,
        # Score 2 fuel, legacy Scoring) is not "unknown" footage for its siblings.
        category={}
        for r in rows:category.setdefault(r['tag_key'],set()).add(r.get('tag_category') or r['tag_key'])
        def same_family(a,b):return a==b or bool(category.get(a,{a})&category.get(b,{b}))
        candidates=sorted(sightings(facts),key=lambda q:(q['t'],q['team']))
        if temporal_required:
            from neptune_temporal import plan,ClipUnavailable
            verified=[]
            for sight in candidates:
                try:verified.append(dict(sight,temporal_clip=plan(facts,sight)))
                except ClipUnavailable:continue
            candidates=verified
        # Even sampling across the whole video, rather than only its first camera shot.
        if len(candidates)>limit_per_video:
            candidates=[candidates[i*(len(candidates)-1)//(limit_per_video-1)] for i in range(limit_per_video)]
        for sight in candidates:
            progress('Preparing unlabelled robot footage from '+original[0]['match_key'])
            from neptune_temporal import ClipUnavailable
            try:x=(extractor or features)(Path(videos)/src['file'],sight,src['duration_ms'])
            except ClipUnavailable:continue
            for tag in tags:
                from neptune_clock import eligible
                if (sight['team'],tag) in blocked or not eligible(tag,facts,sight['t']):continue
                # Keep known labelled intervals out of this tag's unknown reference pool.
                nearby=[r for r in records if r['team_number']==sight['team'] and same_family(r['tag_key'],tag)
                        and reference_overlaps_label(sight,r)]
                if nearby:continue
                # Phase/track boundaries can yield the identical clip for many
                # nearby centers. It is one reference, not repeated evidence.
                if sight.get('temporal_clip'):
                    key=(src['sha256'],sight['team'],tag,digest(sight['temporal_clip']))
                    if key in emitted:continue
                    emitted.add(key)
                out.append({'tag':tag,'x':x,'y':None,'label_ids':[],
                            'evidence_type':'unlabeled_reference','match_key':original[0]['match_key'],
                            'team':sight['team'],'t_ms':round(sight['t']*1000),'source_sha256':src['sha256'],
                            'group':group,'split':split_group(group),'box':list(sight['box']),'camera':sight.get('camera'),
                            **({'temporal_clip':sight['temporal_clip']} if sight.get('temporal_clip') else {})})
    return out


def fit_positive_unlabeled(rows, rounds=16):
    """Bagged linear P-vs-U ranking, adapted from PU bagging (Mordelet & Vert).

    U may contain positives. Contrast targets exist only inside each fit, and are
    never exported as observed non-actions. Scores are rankings, not probabilities.
    """
    import random
    positives=[r for r in rows if r['y']==1];pool=[r for r in rows if r['y'] is None]
    if len(positives)<2 or len(pool)<4:return None
    rng=random.Random(71409);heads=[]
    for _ in range(rounds):
        sampled=rng.sample(pool,min(len(pool),max(4,len(positives)*2)))
        contrast=[{'x':r['x'],'y':1,'w':EXTERNAL_WEIGHT if r.get('external_sources') else 1} for r in positives]+[{'x':r['x'],'y':0} for r in sampled]
        heads.append(fit_binary(contrast))
    dimension=heads[0]['feature_dimension']
    return {'weights':[sum(h['weights'][i] for h in heads)/rounds for i in range(dimension)],
            'bias':sum(h['bias'] for h in heads)/rounds,'feature_dimension':dimension,
            'learning_method':'positive-unlabeled-bagging-v1','bagging_rounds':rounds,
            'positive_examples':len(positives),'unlabeled_examples':len(pool),'score_is_probability':False}


# A PU head is only exported when footage it never saw still ranks its positives
# above ordinary footage. Below these, the 2026-10 Week 0 batch fired on 94% of
# all robot sightings while ranking held-out activity at chance (AUC 0.50).
PU_MIN_POSITIVES=8
PU_MIN_SOURCES=3
PU_MIN_HELD_OUT_AUC=.6
PU_MAX_FLAG_RATE=.35
# Outside scouting is approximate in time and kind; it trains at reduced weight.
EXTERNAL_WEIGHT=.5
# A head must out-rank a stand-in that sees only when, where on screen, which camera and which
# robot - never the clip. Otherwise its "signal" is shot, phase or robot identity, not the action.
NUISANCE_MIN_MARGIN=.05


def nuisance_features(rows):
    """Per-row vectors of non-action context: clip time, box position/size, camera, team."""
    cameras=sorted({str(r.get('camera')) for r in rows});teams=sorted({str(r.get('team')) for r in rows})
    out=[]
    for r in rows:
        box=list(r.get('box') or [0,0,0,0])[:4]
        v=[(r.get('t_ms') or 0)/180000]+[float(b) for b in box]
        v+=[1. if str(r.get('camera'))==c else 0. for c in cameras]+[1. if str(r.get('team'))==t else 0. for t in teams]
        out.append(dict(r,x=v))
    return out


def pu_auc(positive_scores, unlabeled_scores):
    """P-vs-U ranking AUC. Positives hidden in U pull it toward 0.5, so it understates true AUC."""
    if not positive_scores or not unlabeled_scores:return None
    ordered=sorted([(s,1) for s in positive_scores]+[(s,0) for s in unlabeled_scores])
    rank_sum=0.;i=0
    while i<len(ordered):
        j=i
        while j<len(ordered) and ordered[j][0]==ordered[i][0]:j+=1
        rank_sum+=sum(y for _,y in ordered[i:j])*(i+j+1)/2;i=j
    p=len(positive_scores);u=len(unlabeled_scores)
    return (rank_sum-p*(p+1)/2)/(p*u)


def pu_threshold(positive_scores, unlabeled_scores, max_rate=PU_MAX_FLAG_RATE):
    """Lee & Liu (2003) PU criterion recall^2 / P(flagged), on held-out scores, with a flag-rate cap.

    Returns the threshold and the fraction of all held-out examples it flags. The cap keeps a
    weak ranking from degenerating into tagging every sighting.
    """
    everything=sorted(positive_scores+unlabeled_scores,reverse=True);best=None
    for k,threshold in enumerate(everything,1):
        if k<len(everything) and everything[k]==threshold:continue
        rate=k/len(everything)
        if rate>max_rate:break
        recall=sum(s>=threshold for s in positive_scores)/len(positive_scores)
        value=recall*recall/rate
        if best is None or value>best[0]:best=(value,threshold,rate,recall)
    return (best[1],best[2],best[3]) if best else (None,0.,0.)


def fold_key(r):
    """One match is one fold unit, even when it was uploaded as more than one video."""
    return r.get('match_key') or r['source_sha256']


def cross_fit_scores(rows, count, fold_of):
    """Held-out scores: (score, row) for positives and unlabeled, each from a head that never saw its match."""
    scores_p=[];scores_u=[]
    for fold in range(count):
        head=fit_positive_unlabeled([r for r in rows if fold_of[fold_key(r)]!=fold])
        if not head:continue
        for r in rows:
            if fold_of[fold_key(r)]!=fold:continue
            if r['y']==1:scores_p.append((score(head,r['x']),r))
            elif r['y'] is None:scores_u.append(score(head,r['x']))
    return scores_p,scores_u


def validate_positive_unlabeled(rows, folds=5):
    """Cross-fit by match: every score below comes from a head that never saw that match."""
    positives=collections.defaultdict(list)
    for r in rows:
        if r['y']==1:positives[fold_key(r)].append(r)
    sources=sorted(positives)
    detail={'method':'grouped-cross-fit-by-match-v2','positive_examples':sum(map(len,positives.values())),'positive_sources':len(sources),
            'auc_vs_unlabeled':None,'folds':0,'note':'Held-out P-vs-U ranking. Unlabelled footage contains unmarked actions, so this understates true AUC. Not a six-robot accuracy result.'}
    if detail['positive_examples']<PU_MIN_POSITIVES or len(sources)<PU_MIN_SOURCES:
        detail['status']='needs_more_positive_examples_across_matches';return detail,None
    # Deterministic fold per source video; unlabeled footage always stays with its own video.
    count=min(folds,len(sources))
    fold_of={s:i%count for i,s in enumerate(sorted(sources,key=digest))}
    for s in sorted({fold_key(r) for r in rows}-set(sources)):fold_of[s]=int(digest(s)[:8],16)%count
    pairs,scores_u=cross_fit_scores(rows,count,fold_of);scores_p=[s for s,_ in pairs]
    detail['folds']=count;auc=pu_auc(scores_p,scores_u);detail['auc_vs_unlabeled']=None if auc is None else round(auc,4)
    # Reported per label source; hand labels are the precise reference.
    detail['auc_by_source']={}
    for name,keep in (('human',lambda r:not r.get('external_sources')),('outside_scouting',lambda r:bool(r.get('external_sources')))):
        part=[s for s,r in pairs if keep(r)]
        value=pu_auc(part,scores_u) if part else None
        detail['auc_by_source'][name]={'positives':len(part),'auc_vs_unlabeled':None if value is None else round(value,4)}
    if auc is None or auc<PU_MIN_HELD_OUT_AUC:
        detail['status']='no_held_out_signal';return detail,None
    stand_in_pairs,stand_in_u=cross_fit_scores(nuisance_features(rows),count,fold_of)
    stand_in=pu_auc([s for s,_ in stand_in_pairs],stand_in_u)
    detail['nuisance_auc']=None if stand_in is None else round(stand_in,4)
    if stand_in is not None and auc<stand_in+NUISANCE_MIN_MARGIN:
        detail['status']='no_signal_beyond_shot_time_or_robot';return detail,None
    threshold,rate,recall=pu_threshold(scores_p,scores_u)
    if threshold is None:detail['status']='no_held_out_signal';return detail,None
    detail.update(status='held_out_signal',held_out_flag_rate=round(rate,4),held_out_positive_recall=round(recall,4))
    return detail,rate


def fit_binary(rows):
    """Diagonal linear discriminant learned from known positive and negative examples.

    Rows may carry a weight 'w' (default 1); outside-scouting positives use EXTERNAL_WEIGHT.
    """
    rows=[r for r in rows if r['y'] is not None]
    pos=[r['x'] for r in rows if r['y']==1];neg=[r['x'] for r in rows if r['y']==0]
    if min(len(pos),len(neg))<2:return None
    import os
    device=os.environ.get('NEPTUNE_TRAIN_DEVICE','cpu')
    if any(r.get('w',1)!=1 for r in rows):device='cpu'
    if device in ('cuda','mps'):
        import torch
        with torch.no_grad():
            x=torch.tensor([r['x'] for r in rows],dtype=torch.float32,device=device)
            y=torch.tensor([r['y'] for r in rows],dtype=torch.bool,device=device)
            negative=x[~y].mean(0);positive=x[y].mean(0)
            means=torch.where(y[:,None],positive,negative)
            variance=((x-means)**2).mean(0).clamp_min(.01)
            weights=(positive-negative)/variance
            bias=-.5*((positive**2-negative**2)/variance).sum()
            return {'weights':weights.cpu().tolist(),'bias':float(bias.cpu()),'feature_dimension':x.shape[1]}
    # Plain float sums: statistics.mean's exact arithmetic is too slow for cross-fitting.
    d=len(pos[0]);weight=[[r.get('w',1) for r in rows if r['y']==y] for y in (0,1)]
    means=[[sum(w*v[i] for w,v in zip(weight[y],cls))/sum(weight[y]) for i in range(d)] for y,cls in ((0,neg),(1,pos))]
    total=sum(r.get('w',1) for r in rows)
    variance=[max(.01,sum(r.get('w',1)*(r['x'][i]-means[r['y']][i])**2 for r in rows)/total) for i in range(d)]
    weights=[(means[1][i]-means[0][i])/variance[i] for i in range(d)]
    bias=-.5*sum((means[1][i]**2-means[0][i]**2)/variance[i] for i in range(d))
    return {'weights':weights,'bias':bias,'feature_dimension':d}


def score(head,x):
    if len(x)!=head['feature_dimension']:raise ValueError('Model feature version mismatch.')
    return sum(a*b for a,b in zip(head['weights'],x))+head['bias']


def metrics(rows,head,threshold):
    tp=fp=fn=tn=0
    for r in rows:
        if r['y'] is None:continue
        predicted=score(head,r['x'])>=threshold
        if predicted and r['y']:tp+=1
        elif predicted:fp+=1
        elif r['y']:fn+=1
        else:tn+=1
    return {'tp':tp,'fp':fp,'fn':fn,'tn':tn,'precision':tp/(tp+fp) if tp+fp else None,'recall':tp/(tp+fn) if tp+fn else None}


def train_actions(examples, game, run_folder, label_inventory):
    folder=Path(run_folder);folder.mkdir(parents=True,exist_ok=False)
    heads={};report={};used=set()
    dimensions={len(r['x']) for r in examples}
    if len(dimensions)>1 or dimensions-set(FEATURE_DIMENSIONS.values()):raise ValueError('Mixed or unsupported action feature backends.')
    feature_version=next((k for k,v in FEATURE_DIMENSIONS.items() if dimensions=={v}),selected_action_version())
    # Reject manually assembled/leaky datasets too, rather than trusting callers.
    for key in ('source_sha256','group'):
        partitions=collections.defaultdict(set)
        for r in examples:partitions[r[key]].add(r['split'])
        if any(len(v)>1 for v in partitions.values()):raise ValueError('Training/evaluation leakage in '+key)
    vocabulary=label_inventory.get('action_definitions',{})
    for tag in sorted(set(games.TAG_KEYS)|set(vocabulary)|{r['tag'] for r in examples}):
        if tag not in games.TAG_KEYS and not actions.valid_definition(tag,vocabulary.get(tag),game['game_key']):
            raise ValueError('Unrecognized action has no valid Game Builder definition provenance: '+str(tag))
        rows=[r for r in examples if r['tag']==tag]
        splits={s:[r for r in rows if r['split']==s] for s in ('training','calibration','test')}
        detail={'examples':{s:{'present':sum(r['y']==1 for r in rs),'absent':sum(r['y']==0 for r in rs),'unlabeled':sum(r['y'] is None for r in rs)} for s,rs in splits.items()}}
        pu_rows=splits['training'];development=False
        if sum(r['y']==1 for r in pu_rows)<2 and any(r['y'] is None for r in rows):
            # A single event can develop a candidate, but cannot validate it. Keep
            # all its frames in one development partition; do not report test metrics.
            groups=sorted({r['group'] for r in rows})
            chosen=next((group for group in groups if sum(r['y']==1 and r['group']==group for r in rows)>=2),None)
            pu_rows=[r for r in rows if r['group']==chosen] if chosen else []
            development=True
        pu=fit_positive_unlabeled(pu_rows)
        if pu:
            positives=[r for r in pu_rows if r['y']==1]
            validation,rate=validate_positive_unlabeled(pu_rows)
            detail.update(learning_method=pu['learning_method'],positive_examples=len(positives),unlabeled_examples=pu['unlabeled_examples'],
                          development_partition=development,validation=validation,test=None)
            if rate is None:
                # Withheld: an unvalidated head would tag noise (the old minimum-positive
                # threshold flagged nearly every sighting).
                detail['status']=validation['status']
            else:
                # Carry the held-out flag rate over to the final head's own score scale.
                ranked=sorted((score(pu,r['x']) for r in pu_rows if r['y']!=0),reverse=True)
                pu['threshold']=ranked[max(0,math.ceil(rate*len(ranked))-1)]
                pu['threshold_status']='held_out_pu_calibrated';pu['validation']=validation
                pu['training_groups']=sorted({r['group'] for r in pu_rows})
                pu['training_sources']=sorted({r['source_sha256'] for r in pu_rows})
                heads[tag]=pu
                detail.update(status='candidate_needs_complete_match_evaluation',threshold_status=pu['threshold_status'])
                used.update(id for r in positives for id in r['label_ids'])
        else:
            head=fit_binary(splits['training'])
            if not head:detail['status']='needs_positive_and_negative_training_examples'
            elif min(sum(r['y']==y and not r.get('external_sources') for r in splits['calibration']) for y in (0,1))<2:detail['status']='needs_separate_calibration_matches'
            else:
                scored=sorted((score(head,r['x']),r['y']) for r in splits['calibration'] if r['y'] is not None and not r.get('external_sources'))
                tp=sum(y for _,y in scored);fp=len(scored)-tp;positive=tp;best=None;i=0
                while i<len(scored):
                    threshold=scored[i][0];precision=tp/(tp+fp) if tp+fp else 0;recall=tp/positive
                    if precision>=.9 and (best is None or (recall,threshold)>best[:2]):best=(recall,threshold)
                    while i<len(scored) and scored[i][0]==threshold:
                        if scored[i][1]:tp-=1
                        else:fp-=1
                        i+=1
                if best is None:detail['status']='no_reliable_calibration_threshold'
                else:
                    threshold=best[1]
                    head['threshold']=threshold;heads[tag]=head
                    detail.update(status='candidate',test=metrics([r for r in splits['test'] if not r.get('external_sources')],head,threshold))
                    used.update(id for r in rows for id in r['label_ids'])
        report[tag]=detail
    model={'schema_version':1,'game_key':game['game_key'],'game_fingerprint':game['fingerprint'],'game':game['game'],
           'feature_version':feature_version,'heads':heads,'action_definitions':vocabulary,'used_label_ids':sorted(used),'training_examples_digest':digest(examples),
           'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'release_ready':False,
           # Which reusable perception this game model was trained on, and its season layer.
           **(lambda p:{'perception':p.perception_identity(),'game_layer':p.game_identity(game,feature_version)})(__import__('neptune_perception')),
           'limitations':['Experimental appearance/motion baseline. No six-scout accuracy claim.',
                          'Only confirmed robot sightings are analyzed; camera gaps remain unknown.',
                          'Unlabeled crops are mixed reference footage, not verified negatives. Action heads ship only after match-held-out P-vs-U validation; thresholds remain provisional until full-match evaluation.',
                          'No point counts or scoring attribution are learned from scoring-start labels.']}
    if any(r.get('external_sources') for r in examples):model['limitations'].append('Outside scouting is weak activity supervision with approximate source timers. Counted-fuel clicks are not scoring-start annotations, individual points, verified negatives or accuracy references.')
    if feature_version!=FEATURE_VERSION:
        model['action_encoder']=action_encoder_document(feature_version)
        model['limitations'][0]='Pretrained temporal video encoder with season-specific action heads; independent FRC accuracy is unproven.'
    model['model_id']=digest(model)
    result={'model_id':model['model_id'],'inventory':label_inventory,'tags':report,'examples':len(examples),'release_ready':False,
            'external_activity_examples':sum(bool(r.get('external_sources')) for r in examples),'positive_examples':sum(r['y']==1 for r in examples),'unlabeled_examples':sum(r['y'] is None for r in examples),
            'next_step':'Evaluate on complete held-out six-robot reference matches. Label-level metrics alone do not establish full-match recall.'}
    write(folder/'model.json',model);write(folder/'report.json',result)
    write(folder/'examples-provenance.json',[{k:v for k,v in r.items() if k!='x'} for r in examples])
    import html as escape
    html='<html><meta charset="utf-8"><title>Neptune training report</title><body style="font:16px system-ui;max-width:1000px;margin:40px auto"><h1>'+escape.escape(game['game'])+' training report</h1><p>Candidate only. This is not evidence of six-scout accuracy.</p><pre style="white-space:pre-wrap">'
    import html as escape
    (folder/'report.html').write_text(html+escape.escape(json.dumps(result,indent=2))+'</pre></body></html>')
    return result


def train(examples,game,run_folder,label_inventory,scoring_examples=None,scoring_skipped=None,score_labels=None):
    import neptune_scoring_model as scoring
    result=train_actions(examples,game,run_folder,label_inventory)
    folder=Path(run_folder);model=json.loads((folder/'model.json').read_text())
    score_rows=scoring_examples or [];head,detail=scoring.fit(score_rows,point_mode=True)
    detail['excluded']=scoring_skipped or {};detail['saved_score_labels']=label_inventory.get('human_score_labels',label_inventory.get('score_labels',0));detail['imported_score_labels']=label_inventory.get('imported_score_labels',0)
    if head:
        # Points come from this match's video only; no team history is stored or validated.
        video_check,head['vision_weight']=scoring.validate_video(score_rows)
        head['validation']={'video':video_check}
        detail['validation']=head['validation'];detail['vision_weight']=head['vision_weight']
        model['scoring_head']=head
        model['used_label_ids']=sorted(set(model['used_label_ids'])|set(head['used_label_ids']))
        model['scoring_examples_digest']=digest(score_rows)
        detail['training_preview']=[]
        for r in score_rows:
            if r['source_sha256'] not in head['training_sources']:continue
            total=r['alliance_score'];predicted=scoring.predict_share(head,r['x'])
            detail['training_preview'].append({'team':r['team'],'match_key':r['match_key'],'human_share_percent':round(100*r['y'],3) if r['y'] is not None else None,
              'predicted_share_percent':round(100*predicted/total,3) if total else None,'human_points':round(r['points']),'predicted_points':round(predicted),
              'source':r['target_kind'],'kind':'in_sample_preview_not_accuracy'})
            if len(detail['training_preview'])>=60:break
        model['limitations'].append('Whole-match points are learned from human estimates and explicitly attributed imported scored-fuel/climb reports; imports are estimates, not official robot scores. Scoring-start clicks are not point labels. Scoring predictions require separate evaluation.')
        model['limitations'].append('Robot points require localized video and a video regressor that beats its held-out baseline. Team history and alliance totals do not create robot evidence. Estimates need independent evaluation; unknown contributions remain unassigned.')
    from neptune_engine import identity
    result['engine']=identity()
    from neptune_yolo import fingerprint
    model['robot_tracking']={'version':'yolo-botsort-1','detector_fingerprint':fingerprint()}
    result['robot_tracking']=model['robot_tracking']
    result['readiness']={'pipeline_completed':True,'fit_completed':bool(model['heads'] or head),'independent_accuracy_passed':False,'release_ready':False,'reason':('Independent complete-match evaluation is required; fit and PU ranking are not scouting accuracy.' if model['heads'] or head else 'No action or score head met the evidence and validation requirements. See per-match rejections and per-tag validation.')}
    result['scoring']=detail
    model.pop('model_id');model['model_id']=digest(model);result['model_id']=model['model_id']
    write(folder/'model.json',model);write(folder/'report.json',result)
    write(folder/'scoring-provenance.json',[{k:v for k,v in r.items() if k!='x'} for r in score_rows])
    import html
    (folder/'report.html').write_text('<html><meta charset="utf-8"><title>Neptune training report</title><h1>Action and scoring training</h1><p>Experimental candidate. Training previews are not accuracy.</p><pre>'+html.escape(json.dumps(result,indent=2))+'</pre></html>')
    return result


def model_valid(model,game):
    content={k:v for k,v in model.items() if k!='model_id'};content['release_ready']=False
    if digest(content)!=model.get('model_id'):raise ValueError('Model contents changed after training.')
    if model.get('schema_version')!=1 or model.get('game_key')!=game['game_key'] or model.get('game_fingerprint')!=game['fingerprint'] or model.get('feature_version') not in FEATURE_DIMENSIONS:raise ValueError('This model belongs to a different game or game-pack revision.')
    if model.get('robot_tracking'):
        from neptune_yolo import fingerprint
        if model['robot_tracking']['detector_fingerprint']!=fingerprint():raise ValueError('This action model used a different robot detector. Install its matching model bundle or retrain using your saved tags and imports.')
    if model.get('scoring_head'):
        import neptune_scoring_model;neptune_scoring_model.valid(model['scoring_head'])
    dimension=FEATURE_DIMENSIONS[model['feature_version']]
    if model['feature_version']!=FEATURE_VERSION:
        if model.get('action_encoder')!=action_encoder_document(model['feature_version']):raise ValueError('The action encoder version or weights do not match this model.')
    for key,head in model['heads'].items():
        if (key not in games.TAG_KEYS and not actions.valid_definition(key,model.get('action_definitions',{}).get(key),game['game_key'])) or len(head['weights'])!=dimension or head['feature_dimension']!=dimension or not all(math.isfinite(x) for x in head['weights']+[head['bias'],head['threshold']]):raise ValueError('Invalid model head.')


def usable_heads(model):
    """Older PU heads used the lowest training-positive score as threshold, which tagged almost
    every sighting. They stay in their saved model for provenance but are not run."""
    return {tag:head for tag,head in model['heads'].items() if head.get('threshold_status')!='provisional_training_positives'}


def predict(video,facts,model,game):
    model_valid(model,game);samples=[];duration_ms=round(facts['source']['duration_s']*1000);heads=usable_heads(model)
    from neptune_clock import eligible
    from neptune_events import decode
    for sight in sorted(sightings(facts),key=lambda s:s['t']):
        applicable={tag:head for tag,head in heads.items() if eligible(tag,facts,sight['t'])}
        if not applicable:continue
        from neptune_temporal import ClipUnavailable
        try:x=action_features(video,sight,duration_ms,facts,model['feature_version']) if model['feature_version']!=FEATURE_VERSION else features(video,sight,duration_ms)
        except ClipUnavailable:continue
        for tag,head in applicable.items():
            samples.append(dict(sight,tag_key=tag,score=score(head,x),threshold=head['threshold']))
    decoded=decode(samples,facts,model['model_id'],model.get('action_definitions',{}))
    from neptune_events import stationary_disabled
    rules=stationary_disabled(facts,model['model_id'])
    tags=sorted([r for r in decoded if r['tag_key']!='disabled']+rules,key=lambda r:(r['start_ms'],r['team']))
    from neptune_field_regions import enrich
    tags,_=enrich(tags,facts,game['game_key'])
    return tags


def event_metrics(predictions,reference,tolerance_ms=2000):
    """One-to-one robot/tag/time matching. Unmatched true actions count as missed, even without OCR."""
    remaining=list(reference);tp=fp=0
    for p in sorted(predictions,key=lambda p:p['start_ms']):
        candidates=[(i,abs(p['start_ms']-r['start_ms'])) for i,r in enumerate(remaining) if p['team']==r['team'] and p['tag_key']==r['tag_key'] and abs(p['start_ms']-r['start_ms'])<=tolerance_ms]
        if candidates:remaining.pop(min(candidates,key=lambda c:c[1])[0]);tp+=1
        else:fp+=1
    fn=len(remaining)
    return {'tp':tp,'fp':fp,'fn':fn,'precision':tp/(tp+fp) if tp+fp else None,'recall':tp/(tp+fn) if tp+fn else None}


def prepared_signature(source,selected,tags,game,engine_sha=None):
    from neptune_engine import identity
    from neptune_yolo import fingerprint
    return digest({'engine':engine_sha or identity()['sha256'],'version':'match-preparation-v9-imported-reaction-window','features':FEATURE_VERSION,'action_features':selected_action_version(),'game':game['fingerprint'],'robot_detector':fingerprint(),
                   'source':source.get('sha256'),'identity_review_revision':__import__('neptune_review_learning').revision(source.get('sha256')),'association':__import__('neptune_review_learning').active_association(),'labels':selected,'tags':tags})


def run(root,year,export_url=labels.EXPORT_URL,download=True,progress=print,export_path=None,cache_root=None,max_matches=None,should_pause=None,checkpoint=None):
    root=Path(root);game=games.select(str(year)+'training',root.parent);store=labels.Store(root/'labels')
    progress('Syncing public community tags');ingested=labels.ingest(store,path=export_path,url=None if export_path else export_url)
    contract_rejections=[]
    rows,excluded=corpus(store,game,audit=contract_rejections);inv=inventory(rows,game,excluded);inv['label_contract_rejections']=contract_rejections;inv['media_policy']='delete-used-media-v1'
    import neptune_scoring_model as scoring
    score_rows,score_excluded=scoring.label_rows(store,game);inv['score_labels']=len(score_rows);inv['human_score_labels']=sum(not labels.external_score_label(r) for r in score_rows);inv['imported_score_labels']=sum(labels.external_score_label(r) for r in score_rows);inv['score_label_exclusions']=score_excluded
    action_ids={r['id'] for r in rows};score_ids={r['id'] for r in score_rows}
    combined=list({r['id']:r for r in rows+score_rows}.values());write(root/('inventory-'+str(year)+'.json'),inv)
    # Do not spend hours downloading/OCR-ing a corpus which cannot train even one binary head.
    positive=collections.Counter();negative=collections.Counter()
    for row in rows:
        if row['polarity']=='absent':negative[row['tag_key']]+=1
        else:
            if row.get('label_scope')!='match':positive[row['tag_key']]+=1
            for tag in ABSENCE_NEGATIVES.get(row['tag_key'],[]):negative[tag]+=1
    possible=[tag for tag in positive if tag not in ABSENCE and positive[tag]>=2]
    if not possible and len({(r['video_id'],r['team_number']) for r in score_rows})<2:
        progress('No action has two timestamped observed examples yet; saving a data-readiness report. Ordinary action tags are sufficient; counterexamples are not required.')
        import uuid
        folder=root/'models'/game['game_key']/('needs-data-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+uuid.uuid4().hex[:5])
        result=train([],game,folder,inv);result['folder']=str(folder);return result
    cache_root=Path(cache_root) if cache_root is not None else root
    videos=cache_root.parent/'videos';fact_map={};paths=[];processed=0;done=0   # shared with detector jobs; kept 14 days
    from neptune_facts import analyze_video
    snapshot_order={vid:i for i,vid in enumerate(ingested.get('video_order',[]))}
    vids=sorted({r['video_id'] for r in combined},key=lambda vid:(snapshot_order.get(vid,10**9),vid))
    all_tags=sorted({r['tag_key'] for r in rows if r.get('polarity','present')=='present' and r['tag_key'] not in ABSENCE and r.get('label_scope')!='match'})
    def save_checkpoint(current='',phase='preparing'):
        info={'matches_done':done,'matches_total':len(vids),'current_match':current,'phase':phase}
        write(root/'checkpoint.json',info)
        if checkpoint:checkpoint(info)
    def pack_examples(path,examples,skipped,score_examples=None,score_skipped=None,localization_error=None,diagnostics=None):
        bank={};items=[];scores=[]
        for example in examples:
            key=digest(example['x']);bank.setdefault(key,example['x']);items.append(dict({k:v for k,v in example.items() if k!='x'},feature=key))
        for example in score_examples or []:
            key=digest(example['x']);bank.setdefault(key,example['x']);scores.append(dict({k:v for k,v in example.items() if k!='x'},feature=key))
        write(path,{'schema_version':2,'features':bank,'examples':items,'skipped':skipped,'scoring_examples':scores,'scoring_skipped':score_skipped or {},'localization_error':localization_error,'diagnostics':diagnostics or {}})
        write(path.with_suffix('.ready'),{'bytes':path.stat().st_size,'sha256':labels.sha256_file(path)})
    def cached_match(path):
        ready=path.with_suffix('.ready')
        if not path.is_file() or not ready.is_file():return False
        try:return json.loads(ready.read_text())['bytes']==path.stat().st_size
        except (ValueError,KeyError,TypeError):return False
    def prepared_file(source,selected):
        from neptune_engine import compatible_preparation_engines
        current=cache_root/'prepared'/game['fingerprint']/(prepared_signature(source,selected,all_tags,game)+'.json')
        for path in [current]+[cache_root/'prepared'/game['fingerprint']/(prepared_signature(source,selected,all_tags,game,sha)+'.json') for sha in compatible_preparation_engines()]:
            if cached_match(path):
                try:
                    if labels.sha256_file(path)==json.loads(path.with_suffix('.ready').read_text())['sha256']:return path
                except (OSError,ValueError,KeyError,TypeError):pass
        return current
    progress('Checking saved match preparation')
    for vid in vids:
        selected=[r for r in combined if r['video_id']==vid];record=selected[0]
        # Hash the current file and verify current labels even when clips are cached.
        if should_pause and should_pause():break
        # Removed media does not force another download when the exact current
        # labels/engine/game already have a checksummed feature bundle from a
        # previously verified video. A changed label or engine invalidates this key.
        known=store.load('sources.json',{}).get(vid,{})
        if known.get('status')=='verified' and isinstance(known.get('sha256'),str) and len(known['sha256'])==64 and not (videos/(vid+'.mp4')).exists():
            existing=prepared_file(known,selected)
            if cached_match(existing) and labels.sha256_file(existing)==json.loads(existing.with_suffix('.ready').read_text())['sha256']:
                progress('Reusing saved examples without downloading the removed video: '+record['match_key'])
                paths.append(existing);done+=1;save_checkpoint(record['match_key']);continue
        from neptune_cache import require_space
        try:require_space(cache_root)
        except ValueError as error:
            progress(str(error));save_checkpoint(phase='paused')
            return {'state':'paused','matches_done':done,'matches_total':len(vids),'folder':None,'message':str(error)}
        progress('[%d/%d] Verifying %s'%(done+1,len(vids),record['match_key']))
        sources=labels.verify_sources(store,videos,download,only_video_ids={vid});src=sources.get(vid,{})
        if src.get('status')!='verified':
            inv.setdefault('source_errors',[]).append({'match_key':record['match_key'],'status':src.get('status','unavailable'),'reason':str(src.get('reason','Source video could not be verified.'))[:300]})
            progress('Video unavailable for '+record['match_key']+': '+str(src.get('reason',src.get('status','unavailable')))[:300])
        signature=prepared_signature(src,selected,all_tags,game)
        prepared=prepared_file(src,selected)
        if not cached_match(prepared):
            processed+=1;examples=[];skipped={};score_examples=[];score_skipped={};localization_error=None;diagnostics={'labels':[],'robots':[]}
            if src.get('status')=='verified':
                cache=cache_root/'localizations'/src['sha256']/game['fingerprint'];cache.mkdir(parents=True,exist_ok=True)
                saved=cache/'facts.json'
                if not saved.exists():
                    from neptune_facts import replay_alliance_layout
                    for previous in sorted(cache.parent.glob('*/facts.json')):
                        if previous==saved:continue
                        replayed=replay_alliance_layout(previous,{'key':record['match_key'],'roster':record['roster'],'title':record.get('title','')},game,src['sha256'])
                        if replayed is not None:
                            write(saved,replayed);progress('Rechecked saved OCR and robot sightings with the current layout for '+record['match_key']);break

                class Stage(list):
                    def __setitem__(self,key,value):
                        super().__setitem__(key,value);progress(record['match_key']+' · '+value)
                try:
                    facts=json.loads(saved.read_text()) if saved.exists() else analyze_video(videos/src['file'],{'key':record['match_key'],'roster':record['roster']},cache,Stage(['']),duration=src['duration_ms']/1000,source_sha256=src['sha256'])
                    if facts.get('source',{}).get('sha256')!=src['sha256']:raise ValueError('Cached source fingerprint changed.')
                    facts=refresh_cached_facts(saved,facts,{'key':record['match_key'],'roster':record['roster']},game,src['sha256'],video=videos/src['file'],progress=progress)
                    facts=ensure_tracking(videos/src['file'],facts,game,cache,progress)
                    write(saved,facts)
                    from neptune_score_moments import save as save_score_moments
                    save_score_moments(vid,record['match_key'],facts)   # tagging aid for Watch & tag; facts are deleted after this match
                    def extractor(video,sight,duration):
                        key=digest([FEATURE_VERSION,src['sha256'],sight['box'],sight['t'],duration])
                        path=cache_root/'features'/FEATURE_VERSION/(key+'.json')
                        if path.exists():
                            vector=json.loads(path.read_text())
                            if len(vector)==1296 and all(isinstance(v,(int,float)) and math.isfinite(v) for v in vector):return vector
                        vector=features(video,sight,duration);write(path,vector);return vector
                    def temporal_extractor(video,sight,duration):
                        # Track changes invalidate the clip even when the center crop is unchanged.
                        track_digest=digest(facts.get('tracking',{}))
                        key=digest([selected_action_version(),src['sha256'],sight['team'],sight['t'],sight.get('camera'),sight['box'],track_digest])
                        path=cache_root/'features'/selected_action_version()/(key+'.json')
                        if path.exists():
                            value=json.loads(path.read_text());vector=value.get('x',[])
                            if len(vector)==FEATURE_DIMENSIONS[selected_action_version()] and all(type(v) in (int,float) and math.isfinite(v) for v in vector):
                                if value.get('clip'):sight['temporal_clip']=value['clip']
                                return vector
                        vector=action_features(video,sight,duration,facts);write(path,{'x':vector,'clip':sight.get('temporal_clip')});return vector
                    diagnostics['identity_review']=facts.get('tracking',{}).get('review_supervision')
                    diagnostics['tracking']={'status':facts.get('tracking',{}).get('status'),'reason':facts.get('tracking',{}).get('reason'),'version':facts.get('tracking',{}).get('version')}
                    diagnostics['action_encoder']=action_encoder_document(selected_action_version())
                    action_selected=[r for r in selected if r['id'] in action_ids]
                    from neptune_field_regions import load as load_regions
                    try:regions=load_regions(facts,game['game_key'],allow_detected=False)
                    except ValueError:regions=None
                    diagnostics.setdefault('field_regions',{})[record['match_key']]={'applied':bool(regions),'application':(regions or {}).get('application','exact_video' if regions else None),'sides':(regions or {}).get('sides'),'sides_swapped':(regions or {}).get('sides_swapped')}
                    examples,skipped=labeled_examples(action_selected,{vid:facts},sources,videos,progress,extractor=temporal_extractor,audit=diagnostics['labels'],temporal_required=True,field_regions={vid:regions})
                    # Include the same action vocabulary for each match, even when a
                    # particular action has only been labelled in another match.
                    references=unlabeled_examples(action_selected,{vid:facts},sources,videos,progress,extractor=temporal_extractor,tag_keys=all_tags,temporal_required=True)
                    examples+=references
                    progress('Preparing robot scoring examples from '+record['match_key'])
                    score_examples,score_skipped=scoring.examples([r for r in selected if r['id'] in score_ids],{vid:facts},sources,videos,extractor,audit=diagnostics['labels'])
                    observed=sightings(facts)
                    for team in record['roster']['red']+record['roster']['blue']:
                        seen=[q for q in observed if q['team']==team]
                        diagnostics['robots'].append({'match_key':record['match_key'],'team':team,'localized_samples':len(seen),'tracking_coverage':facts.get('tracking',{}).get('coverage',{}).get(str(team)), **robot_evidence_metrics(facts,team)})
                    diagnostics['clock']={'match_key':record['match_key'],'phases':{name:{'observed_samples':len((phase or {}).get('clock_samples',[])), 'start_observed':(phase or {}).get('start_observed',False),'end_observed':(phase or {}).get('end_observed',False)} for name,phase in facts.get('periods',{}).items()},'join_version':'observed-clock-join-v2'}
                except ValueError as error:
                    localization_error={'match_key':record['match_key'],'reason':str(error)[:600]}
                    skipped={'localization_unavailable':len(selected)};progress('Could not prepare '+record['match_key']+': '+str(error))
            else:skipped={'source_not_verified':len(selected)}
            pack_examples(prepared,examples,skipped,score_examples,score_skipped,localization_error,diagnostics)
        else:progress('Reusing completed match: '+record['match_key'])
        # Read back the saved feature bundle before discarding its source media.
        if labels.sha256_file(prepared)!=json.loads(prepared.with_suffix('.ready').read_text())['sha256']:
            raise ValueError('Saved match examples could not be verified; source media was kept.')
        from neptune_cache import cleanup
        source_sha=src.get('sha256')
        cleanup(cache_root,vid,source_sha,cache_root/'localizations'/source_sha/game['fingerprint'] if source_sha else None,progress)
        paths.append(prepared);done+=1;save_checkpoint(record['match_key'])
        if max_matches is not None and processed>=max_matches:break
    if done<len(vids):
        # Paths are intentionally not loaded into RAM while paused.
        save_checkpoint(phase='paused')
        return {'state':'paused','matches_done':done,'matches_total':len(vids),'folder':None}
    examples=[];skipped=collections.Counter();score_examples=[];score_skipped=collections.Counter()
    for path in paths:
        if labels.sha256_file(path)!=json.loads(path.with_suffix('.ready').read_text())['sha256']:
            path.with_suffix('.ready').unlink(missing_ok=True);raise ValueError('A saved match cache changed. Continue again to prepare it safely.')
        data=json.loads(path.read_text());inv.setdefault('match_diagnostics',[]).append(data.get('diagnostics',{}));skipped.update(data['skipped']);score_skipped.update(data.get('scoring_skipped',{}))
        if data.get('localization_error'):inv.setdefault('localization_errors',[]).append(data['localization_error'])
        for item in data.get('scoring_examples',[]):score_examples.append(dict({k:v for k,v in item.items() if k!='feature'},x=data['features'][item['feature']]))
        for item in data['examples']:examples.append(dict({k:v for k,v in item.items() if k!='feature'},x=data['features'][item['feature']]))
    inv['excluded_examples']=dict(skipped)
    # YouTube's bot check: stop with a clear message instead of "finishing" a run that learned from almost nothing.
    from neptune_robot_labels import youtube_blocked,BLOCKED
    blocked=[e for e in inv.get('source_errors',[]) if youtube_blocked(e.get('reason',''))]
    if blocked and len(blocked)*2>=len(vids):raise RuntimeError(BLOCKED+' (%d of %d match videos were refused.)'%(len(blocked),len(vids)))
    save_checkpoint(phase='fitting')
    progress('Fitting action candidates from human tags and unlabelled footage')
    import uuid
    run_id=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+digest(rows)[:8]+'-'+uuid.uuid4().hex[:5]
    folder=root/'models'/game['game_key']/run_id
    result=train(examples,game,folder,inv,score_examples,dict(score_skipped),score_rows);result['folder']=str(folder);return result


def active(root,game):
    root=Path(root);pointer=root/'models'/game['game_key']/'active.json'
    if not pointer.exists():return None
    p=json.loads(pointer.read_text());folder=(pointer.parent/p['run']).resolve()
    if folder.parent!=pointer.parent.resolve():raise ValueError('Invalid active-model path.')
    model=json.loads((folder/'model.json').read_text());model_valid(model,game)
    if not model.get('release_ready'):return None
    benchmark=folder/'benchmark.json'
    if not benchmark.exists():return None
    evaluated=json.loads(benchmark.read_text())
    if not evaluated.get('release_ready') or evaluated.get('model_id')!=model['model_id']:return None
    ledger=labels.Store(root/'labels').load('ledger.json',{})
    if any(ledger.get(i,{}).get('state') not in labels.USABLE_STATES for i in model['used_label_ids']):return None
    return model


def evaluate_benchmark(root,run_folder,manifest_file,progress=print,prepare_match=None,after_match=None):
    root=Path(root);folder=Path(run_folder);model=json.loads((folder/'model.json').read_text());manifest_path=Path(manifest_file)
    reference=json.loads(manifest_path.read_text());game=games.select(str(reference['season'])+'benchmark',root.parent);model_valid(model,game)
    if reference.get('game_key')!=game['game_key'] or not (model['heads'] or model.get('scoring_head')):raise ValueError('Benchmark game mismatch or no trained heads.')
    if set(reference.get('tags',[]))!=set(model['heads']):raise ValueError('The benchmark must cover every trained tag, including times when it did not occur.')
    provenance=json.loads((folder/'examples-provenance.json').read_text())
    forbidden={r['source_sha256'] for r in provenance if r['split']!='test'}
    forbidden_groups={r['group'] for r in provenance if r['split']!='test'}
    for head in model['heads'].values():
        forbidden_groups.update(head.get('training_groups',[]));forbidden.update(head.get('training_sources',[]))
    score_rows=[]
    if model.get('scoring_head'):
        forbidden.update(model['scoring_head']['training_sources']);forbidden_groups.update(model['scoring_head']['training_groups'])
    rows=[];seen=set();all_p=[];all_r=[]
    for match_index,m in enumerate(reference['matches']):
        if not str(m['match_key']).startswith(str(game['season'])) or labels.event_key(m['match_key']) in forbidden_groups or m['source_sha256'] in forbidden:raise ValueError('The benchmark overlaps training/calibration or another season.')
        if m['source_sha256'] in seen:raise ValueError('Duplicate benchmark video.')
        seen.add(m['source_sha256'])
        roster=m['roster']['red']+m['roster']['blue']
        if len(set(roster))!=6 or set(map(str,roster))!=set(m.get('complete_robot_labels',{})) or not all(m['complete_robot_labels'].values()):raise ValueError('Each benchmark needs complete annotations for all six robots; ordinary sparse tags cannot establish recall.')
        for r in m['events']:
            if r['team'] not in roster or r['tag_key'] not in model['heads'] or type(r['start_ms']) is not int or not 0<=r['start_ms']<=m['duration_ms']:raise ValueError('Invalid reference event.')
        if prepare_match:prepare_match(m,match_index)
        video=(manifest_path.parent/m['video']).resolve();facts_path=(manifest_path.parent/m['facts']).resolve()
        if labels.sha256_file(video)!=m['source_sha256']:raise ValueError('Reference video fingerprint changed.')
        facts=json.loads(facts_path.read_text())
        if facts['source']['sha256']!=m['source_sha256'] or abs(facts['source']['duration_s']*1000-m['duration_ms'])>2000:raise ValueError('Reference localization source mismatch.')
        progress('Evaluating all six robots: '+m['match_key']);pred=predict(video,facts,model,game)
        result=event_metrics(pred,m['events']);result['robots']={str(t):event_metrics([p for p in pred if p['team']==t],[r for r in m['events'] if r['team']==t]) for t in roster}
        result['tags']={tag:event_metrics([p for p in pred if p['tag_key']==tag],[r for r in m['events'] if r['tag_key']==tag]) for tag in model['heads']}
        if model.get('scoring_head'):
            import neptune_scoring_model as scoring
            score_rows.append(scoring.evaluate_match(video,facts,model['scoring_head'],m))
        rows.append(dict(result,match_key=m['match_key']));all_p.extend(dict(p,video=m['source_sha256']) for p in pred);all_r.extend(dict(r,video=m['source_sha256']) for r in m['events'])
        if after_match:
            write(folder/'evaluation-match-results.json',{'matches':rows,'scoring':score_rows})
            after_match(m,match_index)
    # Aggregate counts, never match predictions between different videos.
    total={k:sum(r[k] for r in rows) for k in ('tp','fp','fn')}
    total['precision']=total['tp']/(total['tp']+total['fp']) if total['tp']+total['fp'] else None
    total['recall']=total['tp']/(total['tp']+total['fn']) if total['tp']+total['fn'] else None
    events=len({labels.event_key(m['match_key']) for m in reference['matches']})
    def good(x):return (x.get('precision') or 0)>=.9 and (x.get('recall') or 0)>=.8
    by_tag={tag:{k:sum(r['tags'][tag][k] for r in rows) for k in ('tp','fp','fn')} for tag in model['heads']}
    for t in by_tag.values():t.update(precision=t['tp']/(t['tp']+t['fp']) if t['tp']+t['fp'] else None,recall=t['tp']/(t['tp']+t['fn']) if t['tp']+t['fn'] else None)
    robot_gate=all((r['tp']+r['fp']+r['fn']==0) or good(r) for match in rows for r in match['robots'].values())
    action_ready=(not model['heads']) or (robot_gate and good(total) and all(good(v) and v['tp']+v['fn']>=10 for v in by_tag.values()))
    score_evaluation=None
    if model.get('scoring_head'):
        score_evaluation=scoring.evaluation_report(score_rows,events)
    ready=len(rows)>=10 and events>=2 and action_ready and (not model.get('scoring_head') or score_evaluation['release_ready'])
    report={'model_id':model['model_id'],'matches':rows,'overall':total,'by_tag':by_tag,'events':events,'all_six_robot_gate':robot_gate,'release_ready':ready,
            'scoring':score_evaluation,'reference_sha256':labels.sha256_file(manifest_path),'gate':'At least 10 complete six-robot matches from 2 held-out events; precision >= .90 and recall >= .80 for every trained tag, with >=10 reference actions/tag. These are release criteria, not a claim that the criteria equal human performance.'}
    model['release_ready']=False;write(folder/'model.json',model)
    write(folder/'benchmark.json',report);return report


def activate(root,run_folder):
    root=Path(root);folder=Path(run_folder).resolve();model=json.loads((folder/'model.json').read_text());bench=json.loads((folder/'benchmark.json').read_text())
    if not bench.get('release_ready') or bench.get('model_id')!=model['model_id']:raise ValueError('This candidate has not passed its complete-match benchmark.')
    game=games.select(model['game_key'][:4]+'activate',root.parent);model_valid(model,game)
    parent=(root/'models'/game['game_key']).resolve()
    if folder.parent!=parent:raise ValueError('Activate a run inside this game’s local model library.')
    model['release_ready']=True;write(folder/'model.json',model)
    write(parent/'active.json',{'run':folder.name,'model_id':model['model_id']});return model['model_id']


def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('command',choices=['inventory','train']);ap.add_argument('--season',type=int,required=True);ap.add_argument('--root',type=Path,default=games.HOME/'training');ap.add_argument('--export');ap.add_argument('--url',default=labels.EXPORT_URL);ap.add_argument('--no-download',action='store_true');a=ap.parse_args()
    if a.command=='train':print(json.dumps(run(a.root,a.season,a.url,not a.no_download),indent=1));return
    store=labels.Store(a.root/'labels');labels.ingest(store,path=a.export,url=None if a.export else a.url)
    g=games.select(str(a.season)+'inventory',a.root.parent);rows,ex=corpus(store,g);r=inventory(rows,g,ex);write(a.root/('inventory-'+str(a.season)+'.json'),r);print(json.dumps(r,indent=1))

if __name__=='__main__':main()
