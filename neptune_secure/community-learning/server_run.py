"""Private, low priority server runner for browser-controlled training and tests."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sys
import time

os.environ.update(OMP_NUM_THREADS='1',OPENBLAS_NUM_THREADS='1',MKL_NUM_THREADS='1',ORT_NUM_THREADS='1',NEPTUNE_HELPER_PIPELINE='facts',NEPTUNE_TRACKING_DEVICE='cpu')
private_tools=Path(__file__).resolve().parents[2]/'maintenance-uploads/community-learning-tools/bin'
os.environ.setdefault('NEPTUNE_ACTION_MODEL_DIR',str(private_tools.parent/'action-encoder'))
os.environ['PATH']=str(private_tools)+os.pathsep+os.environ.get('PATH',os.defpath)


def check():
    missing=[]
    if sys.version_info<(3,11):missing.append('Python 3.11 or newer')
    for name in ('ffmpeg','ffprobe'):
        if not shutil.which(name):missing.append(name)
    if not any(shutil.which(x) for x in ('deno','node','qjs')):missing.append('a YouTube JavaScript runtime')
    for name in ('rapidocr','onnxruntime','cv2','yt_dlp','yt_dlp_ejs','certifi','ultralytics','torch','torchvision','lap'):
        try:found=importlib.util.find_spec(name)
        except (ImportError,ValueError):found=None
        if not found:missing.append(name)
    if not missing:
        for name in ('cv2','onnxruntime','rapidocr'):
            try:importlib.import_module(name)
            except Exception:missing.append(name+' could not start')
    try:
        from neptune_yolo import bundle
        bundle()
        from neptune_temporal import verify_weights
        verify_weights()
    except (ValueError,OSError) as error:missing.append(str(error))
    ready=not missing
    return {'ready':ready,'missing':missing,'message':'Neptune is ready for background training.' if ready else 'Neptune needs one-time server setup: '+', '.join(missing)+'. You can use a helper computer while the server is being prepared.'}


def main():
    p=argparse.ArgumentParser();p.add_argument('--check',action='store_true');p.add_argument('--setup-tracking',action='store_true');p.add_argument('--check-video',action='store_true');p.add_argument('--job',type=Path);p.add_argument('--root',type=Path);p.add_argument('--output',type=Path);p.add_argument('--commands',type=Path);p.add_argument('--progress',type=Path);a=p.parse_args()
    if a.setup_tracking:
        from neptune_yolo import prepare_runtime
        prepare_runtime(device='cpu')
        from neptune_temporal import prepare_weights
        prepare_weights();return
    if a.check:print(json.dumps(check()));return
    if a.check_video:
        try:os.setsid()
        except OSError:pass
        import yt_dlp
        class Quiet:
            def debug(self,msg):pass
            def warning(self,msg):pass
            def error(self,msg):pass
        try:
            settings={'quiet':True,'no_warnings':True,'noplaylist':True,'socket_timeout':8,'retries':0,'extractor_retries':0,'skip_download':True,'logger':Quiet()}
            deno=shutil.which('deno')
            if deno:settings['js_runtimes']={'deno':{'path':deno}}
            with yt_dlp.YoutubeDL(settings) as reader:reader.extract_info('https://www.youtube.com/watch?v=T1K71dqCJA4',download=False)
            out={'ready':True,'checked_at':time.time()}
        except Exception as error:
            reason="provider_sign_in" if "confirm you’re not a bot" in str(error) or "confirm you\'re not a bot" in str(error) else "video_unavailable"
            out={'ready':False,'reason':reason,'checked_at':time.time(),'message':'The video provider blocked or could not serve the server access check. Use a helper computer, or check again later.'}
        print(json.dumps(out));return
    if not all((a.job,a.root,a.output,a.commands,a.progress)):p.error('Job, root, output, commands and progress files are required.')
    # A process group lets the parent give the entire OCR/video tree a small duty cycle.
    try:os.setsid()
    except OSError:pass
    try:
        import resource
        os.nice(15);resource.setrlimit(resource.RLIMIT_CPU,(7200,7200))
    except (ImportError,ValueError,OSError):pass
    import neptune_trainer as trainer
    import neptune_training as training
    from neptune_page_worker import execute
    trainer.HOME=a.root;a.root.mkdir(parents=True,exist_ok=True,mode=0o700)
    job=json.loads(a.job.read_text());state={'message':'Starting work on Neptune','checkpoint':None}
    def progress(message):
        state['message']=str(message)[:900];training.write(a.progress,dict(state,updated_at=time.time()));print(state['message'],flush=True)
    def transport(config,action,payload):
        if action in ('heartbeat','page_heartbeat','trial_heartbeat'):
            state['message']=payload.get('message',state['message']);state['checkpoint']=payload.get('checkpoint') or state['checkpoint'];training.write(a.progress,dict(state,updated_at=time.time()))
            try:commands=json.loads(a.commands.read_text())
            except (OSError,ValueError):commands={}
            return {'ok':True,'stop_requested':bool(commands.get('stop_requested'))}
        training.write(a.output,{'action':action,'payload':payload})
        if action=='pause':return {'ok':True,'state':'server_paused'}
        if action=='trial_finish':return {'ok':True,'state':'test_complete','report_url':'/autoscout/train.php#scoring-trials'}
        if action=='page_evaluation_finish':return {'ok':True,'state':'evaluation_complete'}
        return {'ok':True,'state':'candidate'}
    try:
        if shutil.disk_usage(a.root).free<2*1024**3 and job.get('kind','training')=='training':
            transport({},'pause',{'job_id':job['id'],'lease':job['lease'],'message':'Neptune needs more disk space before preparing another match. Saved work is kept. You can resume this batch on a helper computer.'});return
        if shutil.disk_usage(a.root).free<2*1024**3:raise ValueError('Neptune needs more disk space. Use a helper computer for this test; saved files are kept.')
        health=check()
        if not health['ready']:raise ValueError(health['message'])
        execute({'site':'https://neptunefrc.com','token':'SERVER-TRANSPORT-NOT-A-CREDENTIAL'},job,{'device':'cpu','name':'Neptune background CPU','training_protocol':'page-v2'},progress,transport=transport,max_matches=1)
    except Exception as error:
        training.write(a.output,{'action':'page_fail','payload':{'job_id':job['id'],'lease':job['lease'],'message':str(error)[:900]}});raise


if __name__=='__main__':main()
