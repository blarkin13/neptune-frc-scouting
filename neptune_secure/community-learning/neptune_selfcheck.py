"""Can this computer train on its GPU? A self-check run when the helper starts, shown in its window and on Neptune.

Checks, in order: the NVIDIA card and driver, a CUDA build of PyTorch that can use it, torchvision from the same
build, the robot detector running on the GPU, and how much faster the GPU is than the CPU on the same work.
Each check says what is wrong in plain words; nothing here changes the computer.
"""
import json
import platform
import shutil
import subprocess
import time
from pathlib import Path

HOME = Path.home()/'.neptune-trainer'
VERSION = 'gpu-selfcheck-v1'


def run(progress=lambda s: None):
    checks, summary = [], {}

    def check(name, ok, detail):
        checks.append({'check': name, 'ok': bool(ok), 'detail': str(detail)[:300]})
        progress('Self-check · %s: %s' % (name, ('OK · ' if ok else 'PROBLEM · ')+str(detail)[:200]))
        return ok

    # 1. The card and driver
    gpu = None
    if shutil.which('nvidia-smi'):
        try:
            out = subprocess.run(['nvidia-smi', '--query-gpu=name,driver_version,memory.total', '--format=csv,noheader'],
                                 capture_output=True, text=True, timeout=20).stdout.strip().splitlines()
            gpu = out[0] if out else None
        except (OSError, subprocess.SubprocessError):
            pass
    # No NVIDIA card is not a problem (training uses the CPU); a card PyTorch cannot use is (checked next).
    nvidia = bool(gpu)
    check('NVIDIA card', True, gpu or ('None found: training uses the CPU. If this PC has an NVIDIA card, install its driver from nvidia.com.'
                                       if platform.system() != 'Darwin' else 'Not an NVIDIA computer: training uses the CPU.'))
    summary['gpu'] = gpu.split(',')[0].strip() if gpu else None
    # 2. PyTorch with CUDA
    try:
        import torch
        cuda = torch.cuda.is_available()
        check('PyTorch', cuda or not nvidia, 'PyTorch %s, CUDA %s%s' % (torch.__version__, torch.version.cuda or 'none',
              '' if cuda or not nvidia else ' — this is the CPU-only build; reinstall the helper or choose NVIDIA GPU as the training device'))
    except Exception as error:
        check('PyTorch', False, 'PyTorch did not load: %s' % error); return finish(checks, summary)
    summary['device'] = 'cuda' if cuda else 'cpu'; summary['cpu_threads'] = torch.get_num_threads()
    # 3. torchvision from the same build (a mismatch fails here, not halfway through training)
    try:
        import torchvision
        from torchvision.ops import nms
        dev = 'cuda' if cuda else 'cpu'
        boxes = torch.tensor([[0., 0., 10., 10.], [1., 1., 11., 11.]], device=dev)
        nms(boxes, torch.tensor([.9, .8], device=dev), .5)
        check('torchvision', True, 'torchvision %s matches PyTorch' % torchvision.__version__)
    except Exception as error:
        check('torchvision', False, 'torchvision does not match PyTorch (%s). Reinstall the helper.' % str(error)[:120])
    # 4. The robot detector on the GPU, and 5. how much faster it is
    try:
        import os
        os.environ.setdefault('YOLO_OFFLINE', '1')
        import numpy as np
        from ultralytics import YOLO
        weights = Path(__file__).with_name('robot-detector')/'bumper_wide.pt'
        model = YOLO(str(weights)); image = np.random.randint(0, 255, (720, 1280, 3), np.uint8)

        def timed(device, n):
            model.predict(image, imgsz=1280, device=device, verbose=False)        # warm-up
            t = time.perf_counter()
            for _ in range(n):
                model.predict(image, imgsz=1280, device=device, verbose=False)
            return (time.perf_counter()-t)/n
        cpu_s = timed('cpu', 3)
        summary['cpu_ms'] = round(cpu_s*1000)
        if cuda:
            gpu_s = timed(0, 10)
            summary['gpu_ms'] = round(gpu_s*1000); summary['speedup'] = round(cpu_s/gpu_s, 1)
            check('Robot detector on the GPU', True, '%d ms per frame on the GPU vs %d ms on the CPU: %.1fx faster' % (summary['gpu_ms'], summary['cpu_ms'], summary['speedup']))
        else:
            check('Robot detector', True, '%d ms per frame on the CPU' % summary['cpu_ms'])
    except Exception as error:
        check('Robot detector', False, 'The detector could not run: %s' % str(error)[:150])
    return finish(checks, summary)


def finish(checks, summary):
    ok = all(c['ok'] for c in checks)
    gpu_ready = summary.get('device') == 'cuda' and ok
    summary['verdict'] = ('GPU ready · %s · %sx faster than CPU' % (summary.get('gpu'), summary.get('speedup')) if gpu_ready else
                          'CPU only · no NVIDIA GPU' if not summary.get('gpu') and ok else
                          'Needs attention · ' + next((c['detail'] for c in checks if not c['ok']), 'see checks'))
    result = {'version': VERSION, 'checked_at': int(time.time()), 'ok': ok, 'summary': summary, 'checks': checks,
              'platform': platform.system()+' '+platform.machine()}
    try:
        HOME.mkdir(parents=True, exist_ok=True); (HOME/'selfcheck.json').write_text(json.dumps(result, indent=1))
    except OSError:
        pass
    return result
