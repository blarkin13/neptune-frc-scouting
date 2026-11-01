"""Keep this computer from sleeping while the helper works (set per computer on Neptune → My computers).

Modes: 'jobs' (default) keeps it awake only while a job runs, 'always' while the helper is open, 'off' never.
The last mode Neptune sent is remembered, so it holds from launch and while the helper is offline or signed out.
The display may still turn off; only system sleep is held off. Uses what each system ships with:
  Mac      caffeinate -i (ends with the helper)
  Windows  SetThreadExecutionState(ES_SYSTEM_REQUIRED), renewed every 30 s from one thread
  Linux    systemd-inhibit --what=idle:sleep (when systemd is there)
"""
import os
import shutil
import subprocess
import sys
import threading
from pathlib import Path

MODES = ('jobs', 'always', 'off')
SAVED = Path.home()/'.neptune-trainer'/'keep-awake.txt'


class Awake:
    def __init__(self, mode='jobs', saved=None):
        self.saved = saved
        if saved is not None:
            try:
                mode = saved.read_text().strip()
            except OSError:
                pass
        self.mode = mode if mode in MODES else 'jobs'
        self.busy = False
        self._held = False
        self._child = None
        self._lock = threading.Lock()
        self._wake = threading.Event()
        self._closing = False
        if sys.platform == 'win32':
            threading.Thread(target=self._windows, daemon=True).start()

    @property
    def wanted(self):
        return self.mode == 'always' or (self.mode == 'jobs' and self.busy)

    @property
    def active(self):
        """Whether sleep is being held off right now."""
        if sys.platform == 'win32':
            return self._held
        return self._child is not None and self._child.poll() is None

    def set_mode(self, mode):
        if mode in MODES and mode != self.mode:
            self.mode = mode
            if self.saved is not None:
                try:
                    self.saved.parent.mkdir(parents=True, exist_ok=True); self.saved.write_text(mode)
                except OSError:
                    pass
            self._apply()

    def set_busy(self, busy):
        if bool(busy) != self.busy:
            self.busy = bool(busy)
            self._apply()

    def close(self):
        self._closing = True
        self.busy = False; self.mode = 'off'    # not saved: closing is not a new setting
        self._apply()

    def _apply(self):
        if sys.platform == 'win32':
            self._wake.set()
            return
        with self._lock:
            running = self._child is not None and self._child.poll() is None
            if self.wanted and not running:
                self._child = self._start()
            elif not self.wanted and running:
                self._child.terminate()
                try:
                    self._child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    self._child.kill()
                self._child = None

    @staticmethod
    def _start():
        why = 'Neptune Training Helper is working'
        if sys.platform == 'darwin' and shutil.which('caffeinate'):
            command = ['caffeinate', '-i', '-w', str(os.getpid())]
        elif shutil.which('systemd-inhibit'):
            command = ['systemd-inhibit', '--what=idle:sleep', '--who=Neptune Training Helper', '--why='+why, '--mode=block', 'sleep', 'infinity']
        else:
            return None
        try:
            return subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        except OSError:
            return None

    @staticmethod
    def _windows_call():
        import ctypes
        call = ctypes.windll.kernel32.SetThreadExecutionState
        # EXECUTION_STATE is an unsigned DWORD; without this, ES_CONTINUOUS (0x80000000) does not fit a C int.
        call.argtypes = [ctypes.c_uint]; call.restype = ctypes.c_uint
        return call

    def _windows(self):
        ES_CONTINUOUS, ES_SYSTEM_REQUIRED = 0x80000000, 0x00000001
        try:
            call = self._windows_call()
        except Exception:
            return
        while not self._closing:
            # Held by this thread: ES_CONTINUOUS keeps it until changed, and re-asserting covers a missed call.
            # A failed call must not end the thread, or sleep would never be held off again.
            try:
                wanted = self.wanted
                self._held = bool(call(ES_CONTINUOUS | ES_SYSTEM_REQUIRED if wanted else ES_CONTINUOUS)) and wanted
            except Exception:
                self._held = False
            self._wake.wait(30); self._wake.clear()
        try:
            call(ES_CONTINUOUS)
        except Exception:
            pass
        self._held = False


_shared = None


def shared():
    """One holder per helper process, released when the helper exits."""
    global _shared
    if _shared is None:
        import atexit
        _shared = Awake(saved=SAVED); atexit.register(_shared.close)
    return _shared
