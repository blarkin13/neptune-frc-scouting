#!/usr/bin/env python3
"""Private Unix-socket root console for the authenticated Release Center."""
from __future__ import annotations
import datetime,hashlib,json,os,pwd,selectors,shlex,signal,socket,socketserver,stat,struct,subprocess,threading,time,tempfile
from pathlib import Path
SOCKET=Path('/run/neptune-root-console/console.sock')
AUDIT=Path('/var/log/neptune-root-console/audit.jsonl')
MAX_REQUEST=4*1024*1024;MAX_OUTPUT=128*1024;TIMEOUT=60
CAPACITY=threading.BoundedSemaphore(2);AUDIT_LOCK=threading.Lock()

def validate(body):
 if not isinstance(body,dict) or set(body)-{'action','command','cwd'}:raise ValueError('Invalid console request')
 if body.get('action') not in ('status','run'):raise ValueError('Unknown console action')
 cwd=body.get('cwd','/var/www/neptune')
 if not isinstance(cwd,str) or not cwd.startswith('/') or '\x00' in cwd or len(cwd)>4096:raise ValueError('Choose an absolute working directory')
 p=Path(cwd).resolve(strict=True)
 if not p.is_dir():raise ValueError('Working directory is not a folder')
 command=body.get('command','')
 if body['action']=='run' and (not isinstance(command,str) or not command.strip() or '\x00' in command or len(command.encode())>16000):raise ValueError('Enter a command up to 16 KB')
 return command,str(p)

def audit(record):
 with AUDIT_LOCK:
  AUDIT.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
  if AUDIT.exists() and AUDIT.stat().st_size>2*1024*1024:
   for i in (2,1):
    old=AUDIT.with_suffix('.jsonl.'+str(i));new=AUDIT.with_suffix('.jsonl.'+str(i+1))
    if old.exists():os.replace(old,new)
   os.replace(AUDIT,AUDIT.with_suffix('.jsonl.1'))
  fd=os.open(AUDIT,os.O_WRONLY|os.O_CREAT|os.O_APPEND|os.O_NOFOLLOW,0o600)
  with os.fdopen(fd,'a') as f:f.write(json.dumps(record)+'\n')

def execute(command,cwd,timeout=TIMEOUT):
 # A standalone cd persists the directory in the browser; other commands run in a fresh root shell.
 try:words=shlex.split(command)
 except ValueError:words=[]
 if len(words) in (1,2) and words[0]=='cd' and not any(c in command for c in ';|&\n`$><'):
  dest=Path(words[1] if len(words)==2 else '/root').expanduser()
  if not dest.is_absolute():dest=Path(cwd)/dest
  dest=dest.resolve(strict=True)
  if not dest.is_dir():raise ValueError('Directory does not exist')
  return {'output':'','exit_code':0,'cwd':str(dest),'truncated':False,'timed_out':False}
 env={'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','HOME':'/root','USER':'root','LOGNAME':'root','LANG':'C.UTF-8','TERM':'dumb'}
 metadata=tempfile.TemporaryFile()
 fd=metadata.fileno()
 wrapped="trap '__rc_exit=$?; pwd -P >&"+str(fd)+"; trap - EXIT; exit \"$__rc_exit\"' EXIT\n"+command
 p=subprocess.Popen(['/bin/bash','--noprofile','--norc','-c',wrapped],cwd=cwd,env=env,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,start_new_session=True,pass_fds=(fd,))
 out=bytearray();truncated=False;timed_out=False;sel=selectors.DefaultSelector();sel.register(p.stdout,selectors.EVENT_READ);deadline=time.monotonic()+timeout
 try:
  while sel.get_map():
   if time.monotonic()>deadline:
    timed_out=True
    try:os.killpg(p.pid,signal.SIGKILL)
    except ProcessLookupError:pass
    break
   for key,_ in sel.select(.1):
    data=os.read(key.fileobj.fileno(),8192)
    if not data:sel.unregister(key.fileobj);continue
    remaining=MAX_OUTPUT-len(out);out.extend(data[:remaining]);truncated|=len(data)>remaining
  try:p.wait(timeout=1)
  except subprocess.TimeoutExpired:
   os.killpg(p.pid,signal.SIGKILL);p.wait(timeout=2)
 finally:
  sel.close();p.stdout.close()
  if p.poll() is None:
   try:os.killpg(p.pid,signal.SIGKILL)
   except ProcessLookupError:pass
   p.wait()
 metadata.seek(0);actual=metadata.read(4097).decode('utf-8','replace').strip();metadata.close()
 if actual.startswith('/') and len(actual)<=4096 and Path(actual).is_dir():cwd=actual
 return {'output':out.decode('utf-8','replace'),'exit_code':p.returncode,'cwd':cwd,'truncated':truncated,'timed_out':timed_out}

class Handler(socketserver.StreamRequestHandler):
 def handle(self):
  self.connection.settimeout(5)
  pid,uid,gid=struct.unpack('3i',self.connection.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))
  if uid not in (0,pwd.getpwnam('neptunerelease').pw_uid):return
  result={};command='';acquired=False
  try:
   raw=self.rfile.readline(MAX_REQUEST+1)
   if len(raw)>MAX_REQUEST or not raw.endswith(b'\n'):raise ValueError('Console request too large')
   body=json.loads(raw)
   if isinstance(body,dict) and body.get('action')=='system':
    if set(body)!={'action','op','args'}:raise ValueError('Invalid system request')
    acquired=CAPACITY.acquire(blocking=False)
    if not acquired:raise ValueError('Two operations are already running')
    import system_ops
    result=system_ops.handle(body['op'],body['args'])
    audit({'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'peer_uid':uid,'operation':body['op'],'args_sha256':hashlib.sha256(json.dumps(body['args'],sort_keys=True).encode()).hexdigest()})
   else:
    command,cwd=validate(body)
    if body['action']=='status':result={'uid':os.geteuid(),'user':'root','hostname':socket.gethostname(),'cwd':cwd,'timeout_seconds':TIMEOUT}
    else:
     acquired=CAPACITY.acquire(blocking=False)
     if not acquired:raise ValueError('Two console commands are already running')
     result=execute(command,cwd)
     audit({'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'peer_uid':uid,'peer_pid':pid,'command_sha256':hashlib.sha256(command.encode()).hexdigest(),'exit_code':result['exit_code'],'timed_out':result['timed_out'],'truncated':result['truncated']})
  except (ValueError,OSError,TypeError) as e:result={'error':str(e)[:300]}
  except Exception:result={'error':'Console operation failed'}
  finally:
   if acquired:CAPACITY.release()
  self.wfile.write(json.dumps(result).encode()+b'\n')
class Server(socketserver.ThreadingUnixStreamServer):
 daemon_threads=True

def main():
 if os.geteuid()!=0:raise RuntimeError('Console broker must run as root')
 SOCKET.parent.mkdir(mode=0o750,parents=True,exist_ok=True)
 if SOCKET.exists():
  if not stat.S_ISSOCK(SOCKET.lstat().st_mode):raise RuntimeError('Unsafe socket path')
  SOCKET.unlink()
 with Server(str(SOCKET),Handler) as server:
  os.chown(SOCKET,0,pwd.getpwnam('neptunerelease').pw_gid);os.chmod(SOCKET,0o660);server.serve_forever()
if __name__=='__main__':main()
