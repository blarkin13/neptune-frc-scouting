"""Keep this computer from sleeping while the helper works (set per computer on Neptune → My computers).

Modes: 'jobs' (default) keeps it awake only while a job runs, 'always' while the helper is open, 'off' never.
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

MODES = ('jobs', 'always', 'off')


class Awake:
    def __init__(self, mode='jobs'):
        self.mode = mode if mode in MODES else 'jobs'
        self.busy = False
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
            return self.wanted
        return self._child is not None and self._child.poll() is None

    def set_mode(self, mode):
        if mode in MODES and mode != self.mode:
            self.mode = mode
            self._apply()

    def set_busy(self, busy):
        if bool(busy) != self.busy:
            self.busy = bool(busy)
            self._apply()

    def close(self):
        self._closing = True
        self.busy = False; self.mode = 'off'
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

    def _windows(self):
        import ctypes
        ES_CONTINUOUS, ES_SYSTEM_REQUIRED = 0x80000000, 0x00000001
        call = ctypes.windll.kernel32.SetThreadExecutionState
        while not self._closing:
            # Held by this thread: ES_CONTINUOUS keeps it until changed, and re-asserting covers a missed call.
            call(ES_CONTINUOUS | ES_SYSTEM_REQUIRED if self.wanted else ES_CONTINUOUS)
            self._wake.wait(30); self._wake.clear()
        call(ES_CONTINUOUS)


_shared = None


def shared():
    """One holder per helper process, released when the helper exits."""
    global _shared
    if _shared is None:
        import atexit
        _shared = Awake(); atexit.register(_shared.close)
    return _shared
