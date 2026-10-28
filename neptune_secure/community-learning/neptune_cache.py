"""Disposable downloaded media; labels, features, models and reports are durable."""
from contextlib import contextmanager
from pathlib import Path
import json
import re
import shutil

POLICY='keep-match-videos-14d-v1'
MEDIA={'.mp4','.webm','.mkv','.mov','.jpg','.jpeg','.png','.ppm'}
RAW={'raw-overlay.jsonl','raw-bumpers.jsonl','raw-results.jsonl','neptune-botsort.yaml'}


@contextmanager
def session(cache):
    """Only one helper/server job may consume and clean a shared cache at a time."""
    cache=Path(cache);cache.mkdir(parents=True,exist_ok=True)
    path=cache/'.processing.lock'
    if path.is_symlink():raise ValueError('Training cache lock cannot be a symbolic link.')
    with path.open('a+b') as handle:
        handle.seek(0);handle.write(b'0');handle.flush();handle.seek(0)
        try:
            import os
            if os.name=='nt':
                import msvcrt
                msvcrt.locking(handle.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl
                fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except OSError as error:raise ValueError('Another Neptune process is using this training cache. Let it finish before starting another helper.') from error
        try:yield
        finally:
            handle.seek(0)
            if os.name=='nt':msvcrt.locking(handle.fileno(),msvcrt.LK_UNLCK,1)
            else:fcntl.flock(handle,fcntl.LOCK_UN)


def cleanup(cache, video_id, source_sha=None, work=None, progress=lambda s:None):
    """Delete only known generated files in the caller's processing workspace.

    Never delete a whole trial/evaluation folder or any result/model/label JSON.
    Compact per-match provenance is saved by the caller before this is invoked.
    Symlinks and paths outside the owned media cache are refused.
    """
    cache=Path(cache).resolve();removed=files=0
    if not re.fullmatch(r'[A-Za-z0-9_-]{11}',str(video_id)):raise ValueError('Invalid cached video identifier.')
    candidates=[]
    # The match video itself stays in the shared video cache (14 days, 5 GB cap; neptune_housekeeping) so later runs
    # and detector jobs reuse it instead of downloading it from YouTube again. Only unfinished downloads go now.
    for videos in (cache/'videos', cache.parent/'videos'):
        if videos.is_symlink():raise ValueError('Refusing a linked video cache.')
        if videos.is_dir():
            candidates.extend(p for p in videos.glob(video_id+'.*') if p.name.endswith(('.mp4.part','.webm.part','.mp4.ytdl')))
    if work is not None:
        work=Path(work)
        relative=work.resolve().relative_to(cache)
        if len(relative.parts)<2 or relative.parts[0] not in ('localizations','trials','evaluations') or not re.fullmatch(r'[a-f0-9]{64}',str(source_sha)) or relative.parts[1]!=source_sha:
            raise ValueError('Processing media must belong to this verified source cache.')
        cursor=work.absolute()
        while cursor.resolve()!=cache:
            if cursor.is_symlink():raise ValueError('Refusing a linked processing cache.')
            if cursor.parent==cursor:raise ValueError('Processing cache is outside the owned cache.')
            cursor=cursor.parent
        if work.is_dir():
            for p in work.rglob('*'):
                if not p.is_file() or p.is_symlink():continue
                if p.suffix.lower() in MEDIA or p.name in RAW:candidates.append(p)
                elif p.name=='facts.json' or p.name.startswith('facts-before-') and p.suffix=='.json':
                    try:record=json.loads(p.read_text())
                    except (OSError,ValueError):continue
                    if isinstance(record,dict) and isinstance(record.get('pipeline'),str) and record.get('source',{}).get('sha256')==source_sha:candidates.append(p)
    for p in candidates:
        if not p.is_symlink() and p.is_file():
            size=p.stat().st_size;p.unlink();removed+=size;files+=1
    if files:progress('Removed used video, images and tracking evidence · %.1f MB released; saved examples and results kept.'%(removed/1024**2))
    return {'policy':POLICY,'removed_files':files,'removed_bytes':removed}


def require_space(cache,minimum=2*1024**3):
    cache=Path(cache);cache.mkdir(parents=True,exist_ok=True)
    if shutil.disk_usage(cache).free<minimum:
        raise ValueError('Neptune needs at least 2 GB free before processing another match. Your labels and saved results are kept.')
