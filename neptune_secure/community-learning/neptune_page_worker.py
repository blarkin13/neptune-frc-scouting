"""Execute work chosen on Neptune's single training page, on a helper or server."""
import hashlib
import json
from pathlib import Path
import re
import threading


def evaluate(config,job,progress,transport,home):
    import neptune_games as games
    import neptune_training as training
    from neptune_helper import download_video
    from neptune_video_labels import sha256_file,probe_duration_ms
    from neptune_facts import analyze_video
    folder=Path(home)/'evaluations'/job['id'];folder.mkdir(parents=True,exist_ok=True)
    pack=dict(job['game_pack'],season=job['season']);pack_file=folder/'game.json';training.write(pack_file,pack);games.install(pack_file,folder)
    game=games.select(str(job['season'])+'evaluation',folder)
    import os;os.environ['NEPTUNE_GAMES_HOME']=str(folder)
    training.model_valid(job['model'],game)
    training.write(folder/'model.json',job['model']);training.write(folder/'examples-provenance.json',[])
    reference=json.loads(json.dumps(job['request']));total=len(reference['matches']);done=threading.Event();lost=[];state={'message':'Preparing independent reference videos'}
    payload={'job_id':job['id'],'lease':job['lease']}
    def beat(checkpoint=None):
        p=dict(payload,message=state['message'])
        if checkpoint:p['checkpoint']=checkpoint
        return transport(config,'page_heartbeat',p)
    def status(message):
        if lost:raise lost[0]
        state['message']=str(message)[:900];progress(message)
    def heartbeat():
        while not done.wait(5):
            try:beat()
            except Exception as error:lost.append(error);return
    thread=threading.Thread(target=heartbeat,daemon=True);thread.start()
    try:
        site=hashlib.sha256(config['site'].rstrip('/').encode()).hexdigest()[:24];cache=Path(home)/'cache'/site
        pending=[]
        def prepare_match(match,index):
            url=match['source_url'];found=re.fullmatch(r'https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})',url)
            if not found:raise ValueError('Evaluation needs an individual YouTube match URL.')
            video=cache/'videos'/(found[1]+'.mp4');video.parent.mkdir(parents=True,exist_ok=True)
            work=cache/'evaluations'/match['source_sha256']/game['fingerprint']/training.digest({'key':match['match_key'],'roster':match['roster']});work.mkdir(parents=True,exist_ok=True)
            pending[:]=[(found[1],match['source_sha256'],work)]
            class Stage(list):
                def __setitem__(self,key,value):super().__setitem__(key,value);status(str(index+1)+' of '+str(total)+' · '+value)
            record={'key':match['match_key'],'roster':match['roster'],'source_url':url,'title':match['match_key']}
            from neptune_cache import require_space
            require_space(cache)
            status('Checking evaluation match '+match['match_key'])
            if not video.exists():download_video(record,video,Stage(['Downloading reference video']))
            if video.stat().st_size>512*1024**2 or sha256_file(video)!=match['source_sha256']:raise ValueError('Reference video fingerprint does not match '+match['match_key'])
            duration=probe_duration_ms(video)
            if not 1000<=duration<=600000 or abs(duration-match['duration_ms'])>2000:raise ValueError('Reference duration does not match '+match['match_key'])

            facts_file=work/'facts.json'
            if facts_file.exists():facts=json.loads(facts_file.read_text())
            else:
                facts=analyze_video(video,record,work,Stage(['Reading reference match']),duration=duration/1000,source_sha256=match['source_sha256']);training.write(facts_file,facts)
            facts=training.ensure_tracking(video,facts,game,work,status);training.write(facts_file,facts)
            match['video']=str(video.resolve());match['facts']=str(facts_file.resolve())
        def after_match(match,index):
            from neptune_cache import cleanup
            for video_id,sha,work in pending:cleanup(cache,video_id,sha,work,status)
            pending.clear()
            reply=beat({'matches_done':index+1,'matches_total':total,'current_match':match['match_key'],'phase':'evaluating'})
            if reply.get('stop_requested'):raise ValueError('Evaluation stopped after the current match. Saved match results are kept; no incomplete evaluation can release a model.')
        manifest=folder/'reference.json';training.write(manifest,reference)
        report=training.evaluate_benchmark(folder,folder,manifest,status,prepare_match=prepare_match,after_match=after_match);training.write(folder/'result.json',report)
        return transport(config,'page_evaluation_finish',dict(payload,benchmark=report))
    finally:
        done.set();thread.join(timeout=2)
        if 'pending' in locals():
            from neptune_cache import cleanup
            for video_id,sha,work in pending:cleanup(cache,video_id,sha,work,progress)



def execute(config,job,hardware,progress,stop=None,transport=None,max_matches=None):
    import neptune_trainer as trainer
    from neptune_cache import session
    cache=trainer.HOME/'cache'/hashlib.sha256(config['site'].rstrip('/').encode()).hexdigest()[:24]
    with session(cache):
        return _execute(config,job,hardware,progress,stop,transport,max_matches)


def _execute(config,job,hardware,progress,stop=None,transport=None,max_matches=None):
    import neptune_trainer as trainer
    call=transport or trainer.api
    kind=job.get('kind','training')
    if kind=='training':return trainer.run_job(config,job,hardware,progress,should_pause=stop,transport=call,max_matches=max_matches)
    if kind=='trial':
        from neptune_scoring_model import run_trial
        return run_trial(config,job['season'],job['request']['match_id'],progress,job=job,transport=call)
    if kind=='evaluation':return evaluate(config,job,progress,call,trainer.HOME)
    raise ValueError('Unsupported Neptune work request.')
