"""Live readout for Neptune's Running now console: system meters, training counters and the last log lines.

A background reporter sends them every 5 s while a job runs (15 s while idle) with the My computers check-in,
so the site shows GPU/CPU use and pass/batch/speed even while the job loop is busy.
Nothing here affects training; every probe is best-effort and returns None when it cannot measure.
"""
import collections
import re
import shutil
import subprocess
import sys
import threading
import time

LOG = collections.deque(maxlen=14)            # (unix time, line)
STATE = {}                                    # pass, passes, batch, batches, it_s, step: set by the running job
EXTRA = {}                                    # merged into every check-in: remote_update (can update on request) and update (its last update message)
_lock = threading.Lock()
_listeners = []
_reply_listeners = []


def subscribe(callback):
    """Deliver the same local meter/job snapshot shown on Neptune to a desktop view."""
    with _lock:
        _listeners.append(callback)
    def remove():
        with _lock:
            if callback in _listeners: _listeners.remove(callback)
    return remove


def on_reply(callback):
    """Deliver each check-in reply (the owner's settings for this computer) to a desktop view."""
    with _lock:
        _reply_listeners.append(callback)
    def remove():
        with _lock:
            if callback in _reply_listeners: _reply_listeners.remove(callback)
    return remove


def replied(reply):
    with _lock:
        listeners = list(_reply_listeners)
    for callback in listeners:
        try: callback(reply)
        except Exception: pass


def notify(payload):
    with _lock:
        listeners = list(_listeners)
    for callback in listeners:
        try: callback(payload)
        except Exception: pass   # a display problem must never affect a running job


def note(line):
    """Add a console line (also called for every helper status message)."""
    line = ' '.join(str(line).split())[:220]
    counter = re.search(r'\[(\d+)/(\d+)\]', line)        # Actions: "[3/40] Verifying …" = match 3 of 40
    if counter:
        update(match=int(counter[1]), matches=int(counter[2]))
    with _lock:
        if line and (not LOG or LOG[-1][1] != line):
            LOG.append((int(time.time()), line))


def update(**values):
    with _lock:
        STATE.update(values)


def clear():
    with _lock:
        STATE.clear()


def snapshot():
    with _lock:
        return dict(STATE), [list(x) for x in LOG]


def batch_counter(label, passes):
    """For a YOLO trainer: counts batches and speed into STATE; returns the callback."""
    seen = {'epoch': None, 'batch': 0, 'start': time.monotonic()}
    def on_batch(trainer):
        if seen['epoch'] != trainer.epoch:
            seen.update(epoch=trainer.epoch, batch=0, start=time.monotonic())
        seen['batch'] += 1
        took = time.monotonic()-seen['start']
        batches = len(trainer.train_loader) if getattr(trainer, 'train_loader', None) is not None else None
        update(step=label, passes=passes, batches=batches, it_s=round(seen['batch']/took, 2) if took > 0 else None,
               **{'pass': trainer.epoch+1, 'batch': seen['batch']})
    return on_batch


def epoch_line(label, trainer, passes):
    """One console line per finished pass: losses and the check score, like a training terminal."""
    parts = ['%s pass %d/%d' % (label, trainer.epoch+1, passes)]
    try:
        names = ('box', 'cls', 'dfl')
        loss = [float(x) for x in trainer.tloss]
        parts += ['%s %.3f' % (n, v) for n, v in zip(names, loss)]
    except Exception:
        pass
    metrics = getattr(trainer, 'metrics', None) or {}
    for key, name in (('metrics/mAP50(B)', 'mAP50'), ('metrics/precision(B)', 'P'), ('metrics/recall(B)', 'R')):
        if key in metrics:
            parts.append('%s %.3f' % (name, float(metrics[key])))
    note(' · '.join(parts))


# --- system meters -------------------------------------------------------------------------------------------
def _run(command):
    try:
        return subprocess.run(command, capture_output=True, text=True, timeout=5).stdout
    except (OSError, subprocess.SubprocessError):
        return ''


_last_gpu = [None, 0.]


def _smi():
    found = shutil.which('nvidia-smi')
    if found or sys.platform != 'win32':
        return found
    import os
    for folder in (os.environ.get('SystemRoot', r'C:\Windows')+r'\System32', os.environ.get('ProgramFiles', r'C:\Program Files')+r'\NVIDIA Corporation\NVSMI'):
        if os.path.isfile(folder+r'\nvidia-smi.exe'):
            return folder+r'\nvidia-smi.exe'
    return None


def nvidia():
    """NVIDIA use, memory and temperature. nvidia-smi can be slow on a busy card: allow 15 s and reuse the last
    good reading for up to a minute rather than dropping the meter."""
    smi = _smi()
    if not smi:
        return None
    try:
        out = subprocess.run([smi, '--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu', '--format=csv,noheader,nounits'],
                             capture_output=True, text=True, timeout=15).stdout.strip().splitlines()
        name, util, used, total, temp = [x.strip() for x in out[0].split(',')]
        num = lambda v: int(float(v)) if v.replace('.', '', 1).isdigit() else None
        reading = {'name': name, 'util': num(util), 'mem_used_mb': num(used), 'mem_total_mb': num(total), 'temp_c': num(temp)}
        _last_gpu[:] = [reading, time.monotonic()]
        return reading
    except (OSError, subprocess.SubprocessError, IndexError, ValueError):
        return _last_gpu[0] if time.monotonic()-_last_gpu[1] < 60 else None


def apple_gpu():
    if sys.platform != 'darwin':
        return None
    out = _run(['ioreg', '-r', '-d', '1', '-w', '0', '-c', 'IOAccelerator'])
    util = re.search(r'"Device Utilization %"=(\d+)', out)
    mem = re.search(r'"In use system memory"=(\d+)', out)
    if not util:
        return None
    return {'name': 'Apple GPU', 'util': int(util[1]), 'mem_used_mb': int(mem[1])//2**20 if mem else None, 'mem_total_mb': None, 'temp_c': None}


def system():
    stats = {'gpu': nvidia() or apple_gpu()}
    try:
        import psutil
        stats['cpu'] = round(psutil.cpu_percent(interval=None))
        vm = psutil.virtual_memory()
        stats['ram_used_mb'] = (vm.total-vm.available)//2**20; stats['ram_total_mb'] = vm.total//2**20
        stats['cores'] = psutil.cpu_count()
    except Exception:
        stats['cpu'] = None
    return stats


class Reporter(threading.Thread):
    """Checks in with Neptune on a timer; `send(payload)` returns the reply (settings)."""
    def __init__(self, send, payload, on_reply, closing):
        super().__init__(daemon=True)
        self.send, self.payload, self.on_reply, self.closing = send, payload, on_reply, closing

    def run(self):
        try:
            import psutil; psutil.cpu_percent(interval=None)    # first reading primes the counter
        except Exception:
            pass
        while not self.closing.is_set():
            base = self.payload()
            state, log = snapshot()
            payload = dict(base, **EXTRA, stats=system(), counters=state, log=log)
            notify(payload)
            try:
                self.on_reply(self.send(payload))
            except Exception:
                pass
            pause = time.monotonic()+(5 if base.get('busy') else 15)
            while time.monotonic() < pause and not self.closing.is_set():
                time.sleep(.5)
