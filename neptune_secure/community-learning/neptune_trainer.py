"""Owner-connected desktop trainer. GPU fits the current baseline; video/OCR remains separate."""
import hashlib
import json
import os
from pathlib import Path
import platform
import queue
import shutil
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.error

HOME=Path.home()/'.neptune-trainer'

class TrainingApiError(ValueError):
    def __init__(self,message,status):
        super().__init__(message);self.status=status


def connection_error(error):
    if isinstance(error,TrainingApiError):return error.status in (408,429,500,502,503,504)
    if isinstance(error,urllib.error.HTTPError):return error.code in (408,429,500,502,503,504)
    if isinstance(error,(TimeoutError,ConnectionError)):return True
    if isinstance(error,urllib.error.URLError):
        import ssl
        return not isinstance(error.reason,ssl.SSLCertVerificationError)
    return False


def api(config,action,payload):
    # Claim/start requests are not replayed blindly: a timed-out response may already
    # have reserved work. Heartbeats and existing lease-bound result writes are safe.
    repeatable=action in {'status','heartbeat','page_heartbeat','trial_heartbeat','finish','pause','trial_finish','page_evaluation_finish','page_fail','review_list','review_get','review_put'}
    for attempt in range(3 if repeatable else 1):
        try:return _api_once(config,action,payload)
        except Exception as error:
            if not repeatable or not connection_error(error) or attempt==2:raise
            time.sleep(1+attempt*2)


def _api_once(config,action,payload):
    from neptune_helper import public_https,NoRedirect
    url=public_https(config['site'].rstrip('/')+'/api/community-training.php?action='+action)
    request=urllib.request.Request(url,data=json.dumps(payload,allow_nan=False).encode(),headers={'Content-Type':'application/json','Authorization':'Bearer '+config['token']})
    try:
        with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect()).open(request,timeout=60) as response:
            raw=response.read(32*1024**2+1)
            if len(raw)>32*1024**2:raise ValueError('Training snapshot exceeds this client’s 32 MB limit.')
            return json.loads(raw)
    except urllib.error.HTTPError as error:
        try:message=json.loads(error.read(4096)).get('error')
        except (ValueError,AttributeError):message=None
        raise TrainingApiError(message or 'Neptune rejected the training request (%s).'%error.code,error.code) from None

def engine_version():
    try:
        from neptune_engine import VERSION
        return 'engine '+VERSION
    except Exception:return ''

def helper_version():
    """The installed helper's version (Windows and Linux builds ship build-version.json next to this file; the Mac app passes its own)."""
    if os.environ.get('NEPTUNE_HELPER_VERSION'):return os.environ['NEPTUNE_HELPER_VERSION'][:40]
    try:return json.loads(Path(__file__).with_name('build-version.json').read_text())['version']
    except (OSError,ValueError,KeyError):return ''

def setup_ocr_gpu(progress):
    """Swap the CPU onnxruntime for the CUDA 13 build so number reading runs on the NVIDIA card. A failure leaves reading on the CPU."""
    # Read the package list, not the module: importing onnxruntime here would lock its files on Windows.
    from importlib.metadata import version,PackageNotFoundError
    try:version('onnxruntime-gpu');return
    except PackageNotFoundError:pass
    try:version('onnxruntime')
    except PackageNotFoundError:return
    progress('Installing GPU number reading (one-time download)…')
    flags={'creationflags':subprocess.CREATE_NO_WINDOW} if sys.platform=='win32' else {}
    pip=[sys.executable,'-m','pip']
    try:
        subprocess.run(pip+['uninstall','-y','onnxruntime'],check=True,capture_output=True,**flags)
        process=subprocess.Popen(pip+['install','onnxruntime-gpu[cuda,cudnn]==1.31.0'],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,**flags)
        for line in process.stdout:progress(line.strip()[:300])
        if process.wait()!=0:raise OSError('pip failed')
    except (OSError,subprocess.SubprocessError) as error:
        progress('GPU number reading could not be installed (%s); reading stays on the CPU.'%str(error)[:120])
        subprocess.run(pip+['install','onnxruntime==1.31.0'],capture_output=True,**flags)

def setup_device(choice,progress):
    device={'Mac GPU':'mps','NVIDIA GPU':'cuda','CPU':'cpu'}.get(choice)
    if not device:
        device='mps' if sys.platform=='darwin' and platform.machine()=='arm64' else 'cuda' if shutil.which('nvidia-smi') else 'cpu'
    if device=='cpu':return {'platform':platform.system(),'device':'cpu','name':'CPU (no GPU fitting selected)'}
    try:import numpy
    except ImportError:
        progress('Installing the numeric library required by GPU training…')
        subprocess.run([sys.executable,'-m','pip','install','numpy>=1.26,<3'],check=True)
    try:import torch
    except ImportError:
        progress('Installing the GPU training library. This is a large one-time download…')
        # torchvision must come from the same build as torch (a CPU torchvision with CUDA torch fails on import).
        command=[sys.executable,'-m','pip','install','torch==2.9.1','torchvision==0.24.1']
        if device=='cuda':command+=['--index-url','https://download.pytorch.org/whl/cu128']
        process=subprocess.Popen(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
        for line in process.stdout:progress(line.strip()[:300])
        if process.wait()!=0:raise ValueError('GPU library installation failed. Check free space and the setup messages, then retry. CPU mode is also available.')
        import importlib;importlib.invalidate_caches();import torch
    if device=='cuda' and not torch.cuda.is_available():raise ValueError('NVIDIA GPU unavailable. Install a compatible NVIDIA driver or explicitly choose CPU. See pytorch.org/get-started/locally/.')
    if device=='mps' and not torch.backends.mps.is_available():raise ValueError('Apple GPU unavailable. This training mode needs a supported Apple Silicon Mac; choose CPU otherwise.')
    if device=='cuda':setup_ocr_gpu(progress)
    # Confirm allocation and execution, not merely a reported device name.
    x=torch.ones((8,8),device=device);float((x@x).sum().cpu())
    return {'platform':platform.system(),'device':device,'name':torch.cuda.get_device_name(0) if device=='cuda' else 'Apple GPU (Metal)','torch_version':torch.__version__}

def run_job(config,job,hardware,progress,benchmark=None,max_matches=None,should_pause=None,transport=None):
    from neptune_training import run,evaluate_benchmark,activate
    import neptune_games as games
    call=transport or api
    root=HOME/'jobs'/job['id'];root.mkdir(parents=True,exist_ok=True)
    snapshot=job['snapshot'].encode()
    if hashlib.sha256(snapshot).hexdigest()!=job['snapshot_sha256']:raise ValueError('Training snapshot fingerprint mismatch.')
    prior_snapshot=root/'labels.ndjson'
    recovered=root/'result.json'
    # A finish rejection does not erase the fitted model. Retry the saved result
    # under the fresh reservation, without repeating downloads, OCR or fitting.
    if recovered.is_file() and prior_snapshot.is_file() and hashlib.sha256(prior_snapshot.read_bytes()).hexdigest()==job['snapshot_sha256'] and not (root/'receipt.json').exists():
        output=json.loads(recovered.read_text());progress('Recovering the saved model result — no video processing is needed.')
        reply=call(config,'finish',{'job_id':job['id'],'lease':job['lease'],'result':output})
        (root/'receipt.json').write_text(json.dumps(reply));
        if (root/'job-state.json').exists():(root/'job-state.json').unlink()
        return reply
    prior_snapshot.write_bytes(snapshot)
    state_file=root/'job-state.json'
    state_file.write_text(json.dumps({'id':job['id'],'site':config['site'],'state':'running'}));state_file.chmod(0o600)
    pack=dict(job['game_pack'],season=job['season']);pack_file=root/'game.json';pack_file.write_text(json.dumps(pack));games.install(pack_file,root)
    state={'message':'Preparing training','checkpoint':None,'stop_requested':False};done=threading.Event();lost=[]
    def send_heartbeat():
        payload={'job_id':job['id'],'lease':job['lease'],'message':state['message']}
        if state['checkpoint'] is not None:payload['checkpoint']=state['checkpoint']
        reply=call(config,'page_heartbeat' if job.get('control') else 'heartbeat',payload)
        state['stop_requested']=bool(reply.get('stop_requested'))
        return reply
    def heartbeat():
        while not done.wait(5 if job.get('control') else 20):
            try:send_heartbeat()
            except Exception as error:lost.append(error);return
    from neptune_steps import Steps
    steps=Steps('actions') if benchmark is None else None
    def status(message):
        if lost:raise lost[0]
        message=steps.describe(message) if steps else message
        state['message']=str(message)[:900];progress(message)
    thread=threading.Thread(target=heartbeat,daemon=True);thread.start()
    try:
        os.environ['NEPTUNE_TRAIN_DEVICE']=hardware['device']
        os.environ['NEPTUNE_GAMES_HOME']=str(root)
        site_key=hashlib.sha256(config['site'].rstrip('/').encode()).hexdigest()[:24]
        def checkpoint(info):
            state['checkpoint']=info;status('%d of %d matches prepared · %s'%(info['matches_done'],info['matches_total'],info['current_match'] or info['phase']))
            if job.get('control'):send_heartbeat()
        try:
            # Saved scoring areas for each event in this batch: training applies them to every match there.
            from neptune_field_regions import sync_events,event_key
            events={event_key(json.loads(line).get('match_key')) for line in (root/'labels.ndjson').read_text().splitlines() if line.strip()}
            sync_events(config,events)
        except (OSError,ValueError):pass
        result=run(root/'training',job['season'],export_path=root/'labels.ndjson',progress=status,
                   cache_root=HOME/'cache'/site_key,max_matches=max_matches,should_pause=lambda:state['stop_requested'] or bool(should_pause and should_pause()),checkpoint=checkpoint)
        if result.get('state')=='paused':
            # End heartbeat activity before changing the server job to paused.
            done.set();thread.join(timeout=65)
            if lost:raise lost[0]
            reply=call(config,'pause',{'job_id':job['id'],'lease':job['lease'],'matches_done':result['matches_done'],'matches_total':result['matches_total'],**({'message':result['message']} if result.get('message') else {})})
            state_file.write_text(json.dumps({'id':job['id'],'site':config['site'],'state':'paused'}))
            return dict(reply,matches_done=result['matches_done'],matches_total=result['matches_total'])
        folder=Path(result['folder']);model=json.loads((folder/'model.json').read_text());evaluated=None
        if benchmark and (model['heads'] or model.get('scoring_head')):
            evaluated=evaluate_benchmark(root/'training',folder,Path(benchmark),status)
            if evaluated.get('release_ready'):activate(root/'training',folder);model=json.loads((folder/'model.json').read_text())
        result['training_computer']=hardware
        output={'report':result,'model':model,'benchmark':evaluated}
        pending=root/'result.json';pending.write_text(json.dumps(output,allow_nan=False))
        if lost:raise lost[0]
        reply=call(config,'finish',{'job_id':job['id'],'lease':job['lease'],'result':output})
        (root/'receipt.json').write_text(json.dumps(reply));
        if (root/'job-state.json').exists():(root/'job-state.json').unlink()
        return reply
    finally:done.set();thread.join(timeout=2)

def watch_jobs(config,hardware,execute,progress,closing,pause,busy=lambda value:None,connection=lambda value:None,failed=lambda message:None,device_changed=lambda:False):
    """Keep transport interruptions separate from rejected jobs and credentials."""
    pending=None;connected=False;last_cleanup=None
    # Keep awake (set on Neptune → My computers) follows whether a job is running.
    from neptune_awake import shared
    awake=shared();show_busy=busy;show_progress=progress;last_message=['']
    import neptune_telemetry as telemetry
    def busy(value):
        awake.set_busy(value);show_busy(value)
        if not value:telemetry.clear()
    def progress(message):
        last_message[0]=str(message);show_progress(message)
        telemetry.note(str(message).split('\n')[-1] if 'Now:' not in str(message) else next((l[4:].strip() for l in str(message).split('\n') if l.startswith('Now:')),str(message)))
    # Check in for My computers and the Running now console every 5 s while working (15 s idle), from its own thread.
    import neptune_robot_jobs as robot_jobs
    form=telemetry.form_factor()
    if not getattr(telemetry,'reporter',None):telemetry.reporter=telemetry.Reporter(lambda payload:robot_jobs.api(config,'computer_poll',payload,timeout=20),
                       lambda:{'busy':awake.busy,'awake':awake.active,'message':last_message[0][:600],'version':helper_version() or engine_version(),'platform':'%s · %s'%(platform.system().replace('Darwin','Mac'),hardware.get('name','CPU')),'form':form},
                       lambda reply:(awake.set_mode((reply.get('settings') or {}).get('keep_awake','jobs')),telemetry.replied(reply)),closing).start()
    def housekeeping(force=False):
        # Startup, after every job, and hourly while idle: remove only re-downloadable media.
        nonlocal last_cleanup
        if not force and last_cleanup is not None and time.monotonic()-last_cleanup<3600:return
        from neptune_housekeeping import sweep
        try:sweep(HOME,progress=progress)
        except (OSError,ValueError):pass
        last_cleanup=time.monotonic()
    housekeeping(force=True)
    last_detector=[0.,0.,False]   # detector sync time, detector poll time, score-moments backfill done
    def detector_work():
        # Robot-detector requests from Auto Scout -> Robot labels, and the shared detector, while idle.
        import neptune_robot_jobs as robot_jobs
        now=time.monotonic()
        if now-last_detector[0]>3600:
            for sync in (robot_jobs.sync_detector,robot_jobs.sync_field_detector):
                try:sync(config)
                except Exception:pass
            last_detector[0]=now
        if now-last_detector[1]>30:
            last_detector[1]=now
            try:robot_jobs.run_pending(config,progress,busy)
            except Exception:pass
            # Scoreboard score changes for Watch & tag (a tagging aid, never a training input).
            try:
                import neptune_score_moments as moments
                if not last_detector[2]:moments.backfill();last_detector[2]=True
                moments.send_pending(config,robot_jobs.api)
            except Exception:pass
    while not closing.is_set():
        if not pending and device_changed():return
        try:
            if pending:
                api(config,'page_heartbeat',{'job_id':pending['id'],'lease':pending['lease'],'message':'Reconnected; recovering saved match work'})
                job=pending
            else:
                job=api(config,'page_claim',{'hardware':hardware}).get('job')
                if job:pause.clear()
            if not connected:connection(True);connected=True
            if not job:
                housekeeping()
                detector_work()
                busy(False);progress('Ready. Choose training or a match test on Neptune.')
                closing.wait(5);continue
            busy(True);pending=job
            reply=execute(config,job,hardware,progress,stop=pause.is_set)
            pending=None
            housekeeping(force=True)
            progress('Paused safely. Resume on Neptune.' if reply.get('state') in ('remote_paused','server_paused') else 'Finished. Results are saved on Neptune.')
            busy(False)
        except Exception as error:
            if connection_error(error):
                connected=False;connection(False)
                progress('Connection interrupted — reconnecting automatically. Saved match work is kept. Keep this helper open.')
                closing.wait(5);continue
            if pending:
                try:api(config,'page_fail',{'job_id':pending['id'],'lease':pending['lease'],'message':str(error)[:900]})
                except Exception:pass
                pending=None
            busy(False);failed(str(error))
            if isinstance(error,TrainingApiError) and error.status in (401,403):return
            closing.wait(5)


def result_message(state):
    return {
        'needs_data': 'Finished — no candidate could be fitted. Your report is saved on Neptune; check it for video or robot-localization gaps. Your tags are kept.',
        'remote_paused': 'Paused after the current match. Progress is saved. Choose Run 1 match or Continue to resume.',
        'candidate': 'Finished — a model candidate is saved on Neptune. It needs evaluation before release.',
    }.get(state, 'Finished — your report is saved on Neptune.')


def single_instance(path,wait=5):
    """Hold a lock for this helper's lifetime so only one copy runs per user; None if another copy still holds it after
    `wait` seconds (a reconnect or an update relaunch hands over well within that). The lock ends with the process."""
    handle=open(path,'a+')
    deadline=time.monotonic()+wait
    while True:
        try:
            if sys.platform=='win32':
                import msvcrt;handle.seek(0);msvcrt.locking(handle.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl;fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
            return handle
        except OSError:
            if time.monotonic()>deadline:handle.close();return None
            time.sleep(.25)


def start_reconnect_process(source, executable=None):
    """Hand a stopped helper to the installed owner-approval entry point."""
    source=Path(source)
    entry=source.with_name('neptune_trainer_entry.py')
    if not entry.is_file():raise FileNotFoundError('The installed reconnect entry point is missing. Reinstall the helper.')
    return subprocess.Popen([executable or sys.executable,str(entry),'--reconnect'],cwd=str(source.parent),close_fds=True)


def main():
    """The helper supplies compute; Neptune's web page owns every run control."""
    import tkinter as tk
    from tkinter import ttk,messagebox
    import webbrowser
    from neptune_setup import LocalSetup,configure_tools
    from neptune_page_worker import execute
    import neptune_ui as ui
    os.environ['NEPTUNE_HELPER_PIPELINE']='facts';configure_tools()
    source=Path(__file__).with_name('trainer-connection.json')
    if not source.is_file():raise ValueError('Download the connected training helper from Neptune.')
    config=json.loads(source.read_text());HOME.mkdir(mode=0o700,exist_ok=True)
    lock=single_instance(HOME/'helper.lock')
    if lock is None:
        # Another Hippocampus window is already open for this user: say so instead of starting a second worker.
        notice=tk.Tk();notice.withdraw();messagebox.showinfo('Hippocampus is already open','Hippocampus is already running on this computer. Use the window that is already open.');notice.destroy();return
    root=tk.Tk();root.title('Hippocampus - Neptune Training Helper');root.geometry('1000x760');root.minsize(640,460)
    ui.apply(root)
    if sys.platform == 'win32':
        try:root.iconbitmap(str(source.with_name('Neptune.ico')))
        except tk.TclError:pass
    else:
        try:root.iconphoto(True,tk.PhotoImage(file=str(source.with_name('Neptune.png'))))   # taskbar and title-bar icon on Linux
        except tk.TclError:pass
    # One window: a sidebar of pages (overview, the three models, computers, activity) beside a page of cards.
    emblem=Path(__file__).with_name('neptune-logo-square.png')
    view={'page':'overview','dash':{},'models':{}}
    ui.load_state(HOME/'ui-state.json')
    shell=ui.Shell(root,logo=Path(__file__).with_name('neptune-logo-wide.png'),on_page=lambda key:show_page(key));top_status=shell.status
    page=frame=shell.page
    from neptune_engine import VERSION as engine_version
    import neptune_telemetry
    computer_card=ui.ComputerCard(page,name=platform.node() or 'Training computer',logo=emblem,form=neptune_telemetry.form_factor());computer_card.pack_forget()
    status=tk.StringVar(value='Connecting to Neptune…');job_title=tk.StringVar(value='THIS COMPUTER');next_value=tk.StringVar(value='')
    job_outer=tk.Frame(page,bg=ui.LINE,padx=1,pady=1,height=62);job_outer.pack_propagate(False)
    job=tk.Frame(job_outer,bg=ui.PANEL,padx=12,pady=10);job.pack(fill='both',expand=True)
    tk.Label(job,textvariable=job_title,font=('Arial',9,'bold'),fg=ui.ACCENT,bg=ui.PANEL,anchor='w').pack(fill='x')
    status_label=tk.Label(job,textvariable=status,font=('Arial',10),fg=ui.TEXT,bg=ui.PANEL,anchor='nw',justify='left',height=1,wraplength=900);status_label.pack(fill='x',pady=(5,0))
    stage_value=tk.DoubleVar(value=0);stage_text=tk.StringVar(value='Waiting for stage counters')
    stage_box=tk.Frame(job,bg=ui.PANEL)
    tk.Label(stage_box,textvariable=stage_text,font=('Courier',9),fg=ui.MUTED,bg=ui.PANEL).pack(anchor='w')
    ttk.Progressbar(stage_box,variable=stage_value,maximum=100,style='Meter.Horizontal.TProgressbar').pack(fill='x')
    progress_box=tk.Frame(job,bg=ui.PANEL);progress_value=tk.DoubleVar(value=0);remaining=tk.StringVar(value='No assigned job')
    tk.Label(progress_box,textvariable=remaining,font=('Courier',9),fg=ui.MUTED,bg=ui.PANEL).pack(anchor='w')
    ttk.Progressbar(progress_box,variable=progress_value,maximum=100,style='Neptune.Horizontal.TProgressbar').pack(fill='x')
    next_label=tk.Label(job,textvariable=next_value,font=('Arial',9),fg=ui.MUTED,bg=ui.PANEL,anchor='w')
    def show_progress(text):
        lines=text.split('\n');line=next((x for x in lines if x.startswith('Progress: ')),None)
        if line:
            rest=line[len('Progress: '):];digits=''.join(ch for ch in rest.split('%')[0] if ch.isdigit())
            if digits:progress_value.set(float(digits));remaining.set(rest)
        job_title.set(lines[0] if any(x.startswith('Now:') for x in lines) else 'THIS COMPUTER')
        next_value.set(next((x for x in lines if x.startswith('Next:')),''))
        if text.startswith('Ready.') or text.startswith('Connected — ready.'):return 'No assigned job'
        return next((x for x in lines if x.startswith('Now:')),text)
    train_page=config['site'].rstrip('/')+'/autoscout/train.php'
    ui.Button(shell.right,'Open Neptune',lambda:webbrowser.open(train_page+'?connect=1'),kind='primary',icon_name='open').pack(side='right')
    # This computer's controls live on its own card.
    choice=tk.StringVar(value='Automatic');settings=tk.Frame(computer_card.inner,bg=ui.PANEL);settings.pack(fill='x',pady=(6,0))
    ttk.Label(settings,text='Training device',style='CardMuted.TLabel').pack(side='left',padx=(0,12))
    devices=['Automatic','NVIDIA GPU','CPU'] if sys.platform in ('win32','linux') else ['Automatic','Mac GPU','CPU']
    device=ttk.Combobox(settings,textvariable=choice,values=devices,state='readonly',width=18,style='Neptune.TCombobox');device.pack(side='left')
    activity_box=tk.Frame(page,bg=ui.INK);log=ui.activity(activity_box);log.configure(height=28)
    pinned={computer_card,job_outer,activity_box}
    def resize_helper():page.after_idle(page.relayout)
    # Connection, version and updates sit at the foot of the sidebar.
    tk.Label(shell.footer,text='v'+helper_version()+' · engine '+engine_version.split('-')[0],font=('Arial',9),fg=ui.MUTED,bg=ui.PANEL,anchor='w').pack(fill='x')
    actions=tk.Frame(shell.footer,bg=ui.PANEL);actions.pack(fill='x',pady=(6,0))
    messages=queue.Queue();closing=threading.Event();pause=threading.Event();busy={'value':False};reviewing=threading.Event();review_closing=threading.Event()
    import neptune_telemetry as telemetry
    unsubscribe_meters=telemetry.subscribe(lambda payload:messages.put(('telemetry',payload)))
    reconnect_requested={'value':False}
    def reconnect():
        if reconnect_requested['value']:return
        if busy['value'] and not messagebox.askyesno('Reconnect after this match?','Finish the current match and save progress before reconnecting this computer to Neptune?'):return
        reconnect_requested['value']=True
        reconnect_button.config(state='disabled')
        pause.set();closing.set();review_closing.set()
        status.set('Finishing this match, then opening browser approval…' if busy['value'] else 'Opening browser approval…')
    reconnect_button=ui.Button(shell.footer,'Reconnect',reconnect,icon_name='refresh',small=True)
    reconnect_button.pack(anchor='w',pady=(6,0))
    update={'release':None,'stage':None,'checking':False,'remote':False,'tried':-1e9}
    update_status=tk.StringVar(value='')
    # Windows always updates itself; Linux once its .deb launcher keeps a newer per-user copy (it sets NEPTUNE_LINUX_UPDATES).
    updatable=sys.platform=='win32' or (sys.platform=='linux' and os.environ.get('NEPTUNE_LINUX_UPDATES')=='1')
    if updatable:telemetry.EXTRA['remote_update']=True
    def update_note(text):update_status.set(text);telemetry.EXTRA['update']=text[:200]
    def remote_settings(settings):
        # The owner asked Neptune to update this computer, or turned on automatic updates: check, download, install when idle, reopen.
        wait=600 if settings.get('update_requested') else 3600
        if not updatable or not (settings.get('update_requested') or settings.get('auto_update')):return
        if update['checking'] or update['stage'] or update['remote'] or time.monotonic()-update['tried']<wait:return
        update['remote']=True;update['tried']=time.monotonic();check_updates()
    unsubscribe_replies=telemetry.on_reply(lambda reply:messages.put(('remote_settings',reply.get('settings') or {})))
    def check_updates():
        if not updatable or update['checking']:return
        update['checking']=True;update_button.config(state='disabled');update_note('Checking for updates…')
        def work():
            try:
                from neptune_windows_update import check
                messages.put(('update_check',check(source.parent)))
            except Exception as error:messages.put(('update_error',str(error)))
        threading.Thread(target=work,daemon=True).start()
    def install_update():
        if (busy['value'] and not update['remote']) or not update['release']:return   # a requested update downloads now and installs after the match
        update_button.config(state='disabled');update_note('Downloading and verifying '+update['release']['version']+'…')
        def work():
            try:
                from neptune_windows_update import prepare
                messages.put(('update_ready',prepare(source.parent,update['release'])))
            except Exception as error:messages.put(('update_error',str(error)))
        threading.Thread(target=work,daemon=True).start()
    update_button=ui.Button(actions,'Check for updates',lambda:install_update() if update['release'] else check_updates(),icon_name='download',small=True)
    if updatable:
        update_button.pack(anchor='w')
        tk.Label(actions,textvariable=update_status,wraplength=170,justify='left',font=('Arial',9),fg=ui.MUTED,bg=ui.PANEL,anchor='w').pack(fill='x',pady=(4,0))
        root.after(1500,check_updates)
    else:
        tk.Label(actions,text='Updates come from your package manager.' if sys.platform=='linux' else 'Updates are not available here.',wraplength=170,justify='left',font=('Arial',9),fg=ui.MUTED,bg=ui.PANEL,anchor='w').pack(fill='x')
    def progress(text):messages.put(('progress',str(text)))
    def stop_current():pause.set();progress('Finishing this match, then pausing. Progress stays saved on Neptune.')
    stop_button=ui.Button(settings,'Stop after this match',stop_current,small=True,state='disabled');stop_button.pack(side='left',padx=(12,0))
    def review_tracking():
        if busy['value']:messagebox.showinfo('Training is running','Finish or pause training before opening a tracking review.');return
        if reviewing.is_set():review_closing.set();return
        reviewing.set();review_closing.clear();review_button.config(text='  Close tracking review')
        def review_worker():
            try:
                from neptune_identity_review import choose
                choose(HOME/'jobs',HOME/'identity-reviews',review_closing,webbrowser.open,connection=config)
            except Exception as error:progress('Tracking review: '+str(error))
            finally:reviewing.clear();messages.put(('review_closed',None))
        threading.Thread(target=review_worker,daemon=True).start()
    review_button=ui.tool_button(shell,'Review tracking…',review_tracking,'review')
    selfchecked=[]
    def worker():
        hardware=None;selected=None
        while not closing.is_set():
            if reviewing.is_set():closing.wait(.5);continue
            try:
                api(config,'status',{'hardware':{'training_protocol':'page-setup-v1','name':'Preparing this computer','platform':platform.system()}})
                progress('Connected. Checking video tools…');LocalSetup().install(lambda text:progress('Video tools and text reader ready.' if str(text).startswith('This computer is ready') else text))
                if hardware is None or selected!=choice_value['value']:
                    selected=choice_value['value'];hardware=setup_device(selected,progress)
                from neptune_yolo import prepare_runtime, fingerprint
                prepare_runtime(progress, hardware['device'])
                # GPU self-check, once per session: shown here and on Neptune's Helpers & settings.
                if not selfchecked:
                    try:
                        import neptune_selfcheck,neptune_robot_jobs
                        neptune_robot_jobs.api(config,'selfcheck_put',neptune_selfcheck.run(progress))
                    except Exception as error:progress('Self-check could not finish: '+str(error)[:200])
                    selfchecked.append(True)
                from neptune_training import prepare_action_encoder
                hardware['action_features']=prepare_action_encoder(progress)
                hardware['robot_detector_sha256']=fingerprint();hardware['robot_tracking']='yolo-botsort-1'
                os.environ['NEPTUNE_TRACKING_DEVICE']='0' if hardware['device']=='cuda' else hardware['device']
                os.environ['NEPTUNE_TRAIN_DEVICE']=hardware['device']   # clip features in match tests too, not only in training
                os.environ['NEPTUNE_OCR_DEVICE']='cuda' if hardware['device']=='cuda' else 'cpu'   # number and scoreboard reading (RapidOCR)
                from neptune_engine import identity
                engine=identity();hardware['engine_version']=engine['version'];hardware['engine_sha256']=engine['sha256']
                hardware['label_contracts']=['game-builder-moments-v1'];hardware['training_protocol']='page-v2';hardware['outside_adapters']=['viper-2026-activity-v1','mapped-actions-v1','viper-2026-points-v1'];progress('Connected — ready. Choose what to run on Neptune.')
                watch_jobs(config,hardware,execute,progress,closing,pause,
                    connection=lambda value:messages.put(('connection',value)),
                    busy=lambda value:messages.put(('busy',value)),
                    failed=lambda message:progress('Needs attention: '+message),
                    device_changed=lambda:selected!=choice_value['value'] or reviewing.is_set())
            except Exception as error:
                progress('Connection needs attention: '+str(error)+' Open Neptune to check the connection. Your saved files are kept.')
                if closing.wait(15):break
        messages.put(('closed',None))
    def dashboard_worker():
        import neptune_robot_jobs
        rounds=0
        while not closing.is_set():
            try:messages.put(('dashboard',neptune_robot_jobs.api(config,'dashboard',timeout=20)))
            except Exception:messages.put(('dashboard',None))
            # Model status changes slowly: read it at start, then every sixth round (a minute).
            rounds+=1
            if rounds%6==1:
                try:messages.put(('models',neptune_robot_jobs.api(config,'models',timeout=30)))
                except Exception:pass
            if closing.wait(10):break
    self_id=config.get('computer_id')
    def open_page(key):show_page(key)
    def render():
        # A refresh of the same page keeps its scroll position; a new page starts at the top.
        key=view['page'];page.show_only(pinned,top=view.get('shown')!=key);view['shown']=key
        if key=='overview':
            page.add(computer_card)
            ui.render_overview(page,view['dash'],view['models'],self_id=self_id,busy=busy['value'],open_page=open_page,open_url=webbrowser.open,on_cancel=cancel_run)
        elif key in ('robots','field'):ui.render_model(page,'robot' if key=='robots' else 'field',view['models'].get('robot' if key=='robots' else 'field'),open_url=webbrowser.open,on_cancel=cancel_run)
        elif key=='actions':ui.render_actions(page,view['models'].get('actions'))
        elif key=='computers':ui.render_computers(page,view['dash'],self_id=self_id,on_update=change_update)
        else:page.add(activity_box)
    target=tk.StringVar(value='Any free helper')
    def targets():
        rows=[(c.get('name') or 'Computer')+(' (this computer)' if c.get('id')==self_id else '') for c in view['dash'].get('computers') or [] if c.get('online') and c.get('id')]
        ids=[c['id'] for c in view['dash'].get('computers') or [] if c.get('online') and c.get('id')]
        return ['Any free helper']+rows,dict(zip(rows,ids))
    def train(kind):
        names,ids=targets();chosen=ids.get(target.get())
        if not messagebox.askyesno('Train the %s detector?'%('robot' if kind=='train' else 'field'),'Runs on %s. It replaces the model in use only if it does better on the held-out match.'%(target.get() if chosen else 'the first free helper')):return
        def work():
            import neptune_robot_jobs
            try:neptune_robot_jobs.api(config,'queue_training',{'kind':kind,**({'computer':chosen} if chosen else {})},timeout=30);messages.put(('queued',None))
            except Exception as error:messages.put(('queued',str(error)))
        threading.Thread(target=work,daemon=True).start()
    def cancel_run(run):
        # Cancel or stop a run from the helper; Neptune refuses the helper doing it at its next update, at a safe point.
        question=('Stop this run after the current match? Progress is saved and it can be continued from Neptune.' if run.get('mode')=='stop'
                  else 'Cancel this run now? Saved video work and human labels are kept.')
        if not messagebox.askyesno('Stop the run?' if run.get('mode')=='stop' else 'Cancel the run?',question):return
        def work():
            import neptune_robot_jobs
            try:neptune_robot_jobs.api(config,'cancel_run',run,timeout=30);messages.put(('run_cancelled',None))
            except Exception as error:messages.put(('run_cancelled',str(error)))
        threading.Thread(target=work,daemon=True).start()
    def change_update(computer,change):
        # Owner's choice from the Computers page: Neptune passes it to that computer's helper at its next check-in.
        if computer=='all' and not messagebox.askyesno('Update every computer?','Each helper downloads the newest version, installs it once it has no job running, then reopens. Computers that are off update the next time the helper opens.'):return
        def work():
            import neptune_robot_jobs
            try:neptune_robot_jobs.api(config,'update_computer',{'computer':computer,**change},timeout=30);messages.put(('update_sent',None))
            except Exception as error:messages.put(('update_sent',str(error)))
        threading.Thread(target=work,daemon=True).start()
    def show_page(key):
        view['page']=key;shell.select(key)
        if key in ('robots','field'):
            names,_=targets()
            if target.get() not in names:target.set('Any free helper')
            ttk.Combobox(shell.actions,textvariable=target,values=names,state='readonly',width=24,style='Neptune.TCombobox').pack(side='left',padx=(0,8))
            ui.Button(shell.actions,'Train %s detector'%('robot' if key=='robots' else 'field'),lambda:train('train' if key=='robots' else 'train_field'),kind='primary',icon_name='play').pack(side='left')
        elif key=='actions':ui.Button(shell.actions,'Train on Neptune',lambda:webbrowser.open(train_page),kind='primary',icon_name='open').pack(side='left')
        elif key=='computers':ui.Button(shell.actions,'Update all',lambda:change_update('all',{'update':'request'}),icon_name='download').pack(side='left')
        elif key=='activity':
            def copy():root.clipboard_clear();root.clipboard_append(log.get('1.0','end-1c'))
            ui.Button(shell.actions,'Copy log',copy).pack(side='left')
        render()
    show_page('overview')
    choice_value={'value':choice.get()}
    def poll():
        choice_value['value']=choice.get()
        while not messages.empty():
            kind,message=messages.get()
            if kind=='telemetry':
                computer_card.update_readings(message);k=message.get('counters') or {}
                if k.get('passes'):
                    stage_value.set(100*((k.get('pass',1)-1)+(k.get('batch',0)/k['batches'] if k.get('batches') else 0))/k['passes']);stage_text.set('pass %s/%s · batch %s/%s%s' % (k.get('pass','?'),k['passes'],k.get('batch','?'),k.get('batches','?'),(' · '+str(k['it_s'])+' it/s') if k.get('it_s') else ''))
                elif k.get('matches'):
                    stage_value.set(100*k.get('match',0)/k['matches']);stage_text.set('match %s/%s' % (k.get('match',0),k['matches']))
                continue
            if kind=='connection':computer_card.set_connected(message);top_status.set('Connected' if message else 'Reconnecting…');continue
            if kind=='dashboard':
                if isinstance(message,dict) and isinstance(message.get('computers'),list):
                    top_status.set('Live · updated '+time.strftime('%H:%M'));view['dash']=message
                    if view['page'] in ('overview','computers'):render()
                else:top_status.set('Readouts unavailable · retrying')
                continue
            if kind=='closed':
                unsubscribe_meters()
                review_closing.set()
                if reconnect_requested['value']:
                    try:
                        start_reconnect_process(source)
                    except Exception as error:
                        reconnect_requested['value']=False
                        messagebox.showerror('Reconnect could not start',str(error))
                root.destroy();return
            if kind=='models':
                if isinstance(message,dict):view['models']=message
                if view['page'] in ('overview','robots','field','actions'):render()
                continue
            if kind=='queued':top_status.set('Run queued' if message is None else 'Couldn’t start the run: '+message);continue
            if kind=='run_cancelled':top_status.set('Run stopped or cancelled · updating…' if message is None else 'Couldn’t cancel the run: '+message);continue
            if kind=='update_sent':top_status.set('Update request sent' if message is None else 'Couldn’t send the update request: '+message);continue
            if kind=='review_closed':review_button.config(text='  Review tracking…');continue
            if kind=='remote_settings':remote_settings(message);continue
            if kind=='update_check':
                update['checking']=False;update['release']=message
                update_button.config(text=('Install update '+message['version']) if message else 'Check for updates',state='normal' if not busy['value'] else 'disabled')
                update_note(('Version '+message['version']+' is ready to install.') if message else 'You’re up to date.')
                if message and update['remote']:install_update()
                else:update['remote']=False
                continue
            if kind=='update_error':
                update['checking']=False;update['remote']=False;update_button.config(state='normal' if not busy['value'] else 'disabled')
                update_note('Update check: '+message);continue
            if kind=='update_ready':
                update['stage']=message
                if busy['value']:
                    update_note('Update downloaded. It will install when this match finishes.');continue
                try:
                    from neptune_windows_update import launch_apply
                    update_note('Installing; the helper reopens in a moment.')
                    launch_apply(source.parent,message);unsubscribe_replies();closing.set();review_closing.set();root.destroy();return
                except Exception as error:
                    update['remote']=False;update_note('Update install: '+str(error));update_button.config(state='normal');continue
            if kind=='busy':
                computer_card.set_working(message);job_outer.configure(height=154 if message else 62);status_label.configure(height=2 if message else 1)
                if message:
                    stage_box.pack(fill='x',pady=(4,0));progress_box.pack(fill='x',pady=(4,0));next_label.pack(fill='x',pady=(4,0))
                else:
                    stage_box.pack_forget();progress_box.pack_forget();next_label.pack_forget();job_title.set('THIS COMPUTER');status.set('No assigned job')
                if not message:progress_value.set(0);remaining.set('No job running')
                busy['value']=message
                if view['page']=='overview':render()
                review_button.config(state='disabled' if message else 'normal');stop_button.config(state='normal' if message else 'disabled');device.config(state='disabled' if message else 'readonly')
                if updatable:update_button.config(state='disabled' if message else 'normal')
                if not message and update['stage']:
                    stage=update['stage'];update['stage']=None;messages.put(('update_ready',stage))
                continue
            status.set(show_progress(message));log.config(state='normal');log.insert('end',time.strftime('%H:%M:%S')+'  '+message+'\n')
            if int(log.index('end-1c').split('.')[0])>250:log.delete('1.0','2.0')
            log.see('end');log.config(state='disabled')
        root.after(250,poll)
    def close():
        if busy['value'] and not messagebox.askyesno('Finish this match and close?','Finish the current match, save progress and close the helper?'):return
        closing.set()
        if busy['value']:pause.set();status.set('Finishing this match, saving progress, then closing…')
    root.protocol('WM_DELETE_WINDOW',close);threading.Thread(target=worker,daemon=True).start();threading.Thread(target=dashboard_worker,daemon=True).start();poll();root.mainloop()


if __name__=='__main__':main()
