"""Bundled or installer-provided tools and setup checks for macOS, Windows and Linux.

Supported platforms (see requirements.json):
  macos-arm64     Apple Silicon, macOS 14+. Facts pipeline with Apple Vision; optional local AI engine.
  windows-x86_64  Windows 10/11 64-bit. Facts pipeline with RapidOCR.
  linux-x86_64,
  linux-aarch64   Ubuntu 24.04 LTS, Debian 12. Facts pipeline with RapidOCR.
Intel Macs and Windows on ARM are not supported.

The facts pipeline (local OCR, no AI model) is the default everywhere. The local AI engine and its 3.2 GB
vision model are only started on Apple Silicon when NEPTUNE_HELPER_PIPELINE=ai is set.
"""
import json
import os
from pathlib import Path
import platform
import shutil
import socket
import subprocess
import sys
import time
import urllib.request


def resources():
    if getattr(sys, '_MEIPASS', None):
        return Path(sys._MEIPASS)
    local = Path(__file__).resolve().parent
    return local if (local / 'vendor').exists() else local.parent / '.helper-build' / 'bundle'


def requirements():
    path = resources() / 'requirements.json'
    if not path.exists():
        path = Path(__file__).with_name('requirements.json')
    manifest = json.loads(path.read_text())
    if manifest['schema_version'] != 1:
        raise ValueError('This requirements document needs an updated launcher.')
    return manifest


REQUIREMENTS = requirements()
REQUIRED = {item['id']: item for item in REQUIREMENTS['requirements']}
MODEL = REQUIRED['vision_model']['model']
MODEL_DIGESTS = REQUIRED['vision_model']['accepted_digests']


def platform_key():
    system = {'Darwin': 'macos', 'Windows': 'windows', 'Linux': 'linux'}.get(platform.system(), platform.system().lower())
    machine = platform.machine().lower()
    machine = {'amd64': 'x86_64', 'x64': 'x86_64', 'arm64': 'arm64' if system == 'macos' else 'aarch64', 'aarch64': 'aarch64'}.get(machine, machine)
    return '%s-%s' % (system, machine)


def check_platform():
    key = platform_key()
    supported = REQUIREMENTS['platforms']
    if key not in supported:
        raise ValueError('This computer (%s) is not supported. Supported: %s.' % (key, ', '.join(supported)))
    spec = supported[key]
    if key.startswith('macos') and int(platform.mac_ver()[0].split('.')[0]) < int(spec['minimum_os'].split('.')[0]):
        raise ValueError('Neptune Helper requires macOS %s or newer.' % spec['minimum_os'])
    if sys.version_info < tuple(int(x) for x in spec.get('minimum_python', '3.10').split('.')):
        raise ValueError('Neptune Helper needs Python %s or newer. Run the installer again.' % spec.get('minimum_python'))
    return key, spec


def app_home():
    """Per-user folder for tools installed by the Windows and Linux installers."""
    if os.name == 'nt':
        return Path(os.environ.get('LOCALAPPDATA', Path.home() / 'AppData/Local')) / 'Neptune Helper'
    if sys.platform == 'darwin':
        return Path.home() / 'Library/Application Support/Neptune Helper'
    return Path(os.environ.get('XDG_DATA_HOME', Path.home() / '.local/share')) / 'neptune-helper'


def logs_folder():
    if sys.platform == 'darwin':
        folder = Path.home() / 'Library/Logs/Neptune Helper'
    elif os.name == 'nt':
        folder = app_home() / 'Logs'
    else:
        folder = Path(os.environ.get('XDG_STATE_HOME', Path.home() / '.local/state')) / 'neptune-helper' / 'logs'
    folder.mkdir(parents=True, exist_ok=True)
    return folder


def open_folder(folder):
    if sys.platform == 'darwin':
        subprocess.Popen(['/usr/bin/open', str(folder)])
    elif os.name == 'nt':
        os.startfile(str(folder))   # noqa: only on Windows
    else:
        subprocess.Popen(['xdg-open', str(folder)])


def exe(name):
    return name + '.exe' if os.name == 'nt' else name


def installed_tool(name, arguments):
    # Launchers started from Finder/Explorer do not inherit shell PATH changes; check the known
    # install folders first, then PATH. Existing installations are preferred over bundled copies.
    folders = [app_home() / 'tools' / 'bin']
    if sys.platform == 'darwin':
        folders += [Path('/opt/homebrew/bin'), Path('/usr/local/bin')]
    found = [Path(folder) / exe(name) for folder in folders]
    which = shutil.which(name)
    if which:
        found.append(Path(which))
    for candidate in found:
        if not candidate.is_file():
            continue
        try:
            subprocess.run([str(candidate)] + arguments, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=True, timeout=10)
            return candidate
        except (OSError, subprocess.SubprocessError):
            pass
    return None


def video_js_runtime():
    # Reuse working installations before considering a newly downloaded executable.
    for name, engine in [('deno', 'deno'), ('node', 'node'), ('qjs', 'quickjs')]:
        path = installed_tool(name, ['--help'] if name == 'qjs' else ['--version'])
        if path:
            return {engine: {'path': str(path)}}
    path = resources() / 'vendor/bin/qjs'
    if not path.is_file():
        raise ValueError('The video runtime is missing. Run the Neptune Helper installer again.')
    if hasattr(os, 'getxattr'):
        try:
            quarantine = os.getxattr(path, 'com.apple.quarantine')
        except OSError:
            quarantine = b''
        if quarantine:
            raise ValueError('macOS has not approved the bundled video runtime. No compatible installed runtime was found. The helper needs an approved runtime before it can download YouTube videos.')
    subprocess.run([str(path), '--help'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
    return {'quickjs': {'path': str(path)}}


def configure_tools():
    bundled = resources() / 'vendor' / 'bin'
    selected = {}
    for name in ['ffmpeg', 'ffprobe']:
        selected[name] = installed_tool(name, ['-version']) or bundled / exe(name)
    directories = list(dict.fromkeys(str(path.parent) for path in selected.values()))
    current = os.environ.get('PATH', os.defpath).split(os.pathsep)
    os.environ['PATH'] = os.pathsep.join(directories + [p for p in current if p not in directories])
    return selected


def local_request(url, payload=None, timeout=10):
    data = None if payload is None else json.dumps(payload).encode()
    return urllib.request.build_opener(urllib.request.ProxyHandler({})).open(
        urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'}), timeout=timeout)


def wanted_pipeline():
    return 'ai' if os.environ.get('NEPTUNE_HELPER_PIPELINE') == 'ai' else 'facts'


class LocalSetup:
    def __init__(self):
        self.process = None
        self.base = None
        self.model = MODEL
        self.log = None
        self.pipeline = wanted_pipeline()
        self.ocr = None

    def check_tools(self, progress):
        key, spec = check_platform()
        video_tools = configure_tools()
        progress('Checking video tools…')
        for tool in ['ffmpeg', 'ffprobe']:
            selected = video_tools[tool]
            if not selected.is_file():
                raise ValueError('FFmpeg is missing. Run the Neptune Helper installer again.')
            subprocess.run([str(selected), '-version'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=10)
        progress('Checking YouTube video support…')
        video_js_runtime()
        return key

    def start(self, progress):
        key = self.check_tools(progress)
        if self.pipeline == 'facts':
            import neptune_ocr
            progress('Checking the text reader…')
            try:
                backend = neptune_ocr.select_backend()
                self.ocr = backend.describe()
            except neptune_ocr.OcrUnavailable as error:
                raise ValueError(str(error)) from None
            return
        if key != 'macos-arm64':
            raise ValueError('The local AI engine is only available on Apple Silicon Macs. Use the facts pipeline.')
        if self.process and self.process.poll() is None:
            return
        runtime = installed_tool('ollama', ['--version']) or resources() / 'vendor' / 'runtime' / 'ollama'
        progress('Starting the local AI engine…')
        if not runtime.is_file():
            raise ValueError('The included AI engine is missing. Reinstall Neptune Helper.')
        # A private ephemeral port avoids existing Ollama installations and their settings.
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0))
            port = s.getsockname()[1]
        self.base = f'http://127.0.0.1:{port}'
        self.log = (logs_folder() / 'local-engine.log').open('a')
        environment = dict(os.environ, OLLAMA_HOST=f'127.0.0.1:{port}', OLLAMA_NO_CLOUD='1', OLLAMA_CONTEXT_LENGTH='8192', OLLAMA_KEEP_ALIVE='5m', OLLAMA_NUM_PARALLEL='1')
        # Keep standard model storage so an existing copy can be reused.
        self.process = subprocess.Popen([str(runtime), 'serve'], env=environment, stdout=self.log, stderr=self.log)
        for _ in range(120):
            if self.process.poll() is not None:
                raise ValueError('The local AI engine could not start. See Help → local-engine.log.')
            try:
                with local_request(self.base + '/api/version') as response:
                    json.load(response)
                return
            except (OSError, ValueError):
                time.sleep(.25)
        raise ValueError('The local AI engine is taking too long to start. Try setup again.')

    def tags(self):
        with local_request(self.base + '/api/tags') as response:
            return json.load(response).get('models', [])

    def ready(self):
        return any(m.get('name') == MODEL and m.get('digest', '') in MODEL_DIGESTS for m in self.tags())

    def install(self, progress):
        self.start(progress)
        if self.pipeline == 'facts':
            progress('This computer is ready (text reader: %s). Connect your Neptune account to choose a match.' % self.ocr['backend'])
            return
        if self.ready():
            progress('This Mac is ready. Connect your Neptune account to choose a match.')
            return
        if shutil.disk_usage(Path.home()).free < REQUIREMENTS['platforms']['macos-arm64']['minimum_free_disk_before_download_bytes']:
            raise ValueError('Free at least 5 GB of disk space, then click Set up again.')
        progress('Downloading the local vision model (about 3.2 GB)…')
        with local_request(self.base + '/api/pull', {'model': MODEL, 'stream': True}, timeout=1800) as response:
            for line in response:
                event = json.loads(line)
                if event.get('error'):
                    raise ValueError('The model download could not finish. Check your internet connection and retry setup.')
                status = event.get('status', 'Downloading model')
                total, complete = event.get('total', 0), event.get('completed', 0)
                if total:
                    progress(f'Downloading local model: {complete / total:.0%}')
                else:
                    progress(status.capitalize())
        if not self.ready():
            raise ValueError('The downloaded model differs from the tested pilot version. Contact the Neptune owner for an updated installer.')
        progress('This Mac is ready. Connect your Neptune account to choose a match.')

    def close(self):
        if self.process and self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=2)
        if self.log:
            self.log.close()
