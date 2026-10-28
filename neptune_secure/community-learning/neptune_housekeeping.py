"""Bounded cleanup of regenerable media only; durable learning files are retained."""
from pathlib import Path
import json,time,re
VERSION='idle-media-retention-v3'
CACHE_AGE_SECONDS=24*3600
VIDEO_AGE_SECONDS=14*24*3600   # shared match videos (cache/videos): downloaded from YouTube once, reused for two weeks
VIDEO_CAP_BYTES=5*1024**3      # and never more than 5 GB; the oldest go first
REVIEW_AGE_SECONDS=7*24*3600
WORK_AGE_SECONDS=6*3600
MEDIA={'.mp4','.webm','.mkv','.mov','.png','.jpg','.jpeg','.ppm'}
def sweep(home,now=None,progress=lambda text:None,dry_run=False):
 from neptune_cache import session
 home=Path(home);now=time.time() if now is None else now
 report={'version':VERSION,'removed_files':0,'removed_bytes':0,'skipped_active':0,'dry_run':dry_run}
 if home.is_symlink():raise ValueError('Refusing linked helper data directory')
 def discard(p,age):
  if p.is_symlink() or not p.is_file():return
  # Do not traverse symbolic link ancestors, even when the final file is regular.
  if any(parent.is_symlink() for parent in p.parents if parent!=home.parent):return
  stat=p.stat()
  if now-max(stat.st_mtime,stat.st_ctime)<age:return
  size=stat.st_size
  if not dry_run:p.unlink()
  report['removed_files']+=1;report['removed_bytes']+=size
 cache=home/'cache'
 if cache.is_dir() and not cache.is_symlink():
  for site in cache.iterdir():
   if site.is_symlink() or not site.is_dir() or not re.fullmatch('[a-f0-9]{24}',site.name):continue
   try:
    with session(site):
     for folder in ['videos','localizations','trials','evaluations']:
      root=site/folder
      if not root.is_dir() or root.is_symlink():continue
      for p in root.rglob('*'):
       if p.suffix.lower() in MEDIA or p.name.endswith(('.mp4.part','.webm.part','.mp4.ytdl')):discard(p,CACHE_AGE_SECONDS)
   except ValueError:report['skipped_active']+=1
 shared=cache/'videos'
 if shared.is_dir() and not shared.is_symlink():
  files=[p for p in shared.iterdir() if p.is_file() and not p.is_symlink() and (p.suffix.lower() in MEDIA or p.name.endswith(('.mp4.part','.webm.part','.mp4.ytdl')))]
  for p in files:discard(p,VIDEO_AGE_SECONDS if p.suffix.lower() in MEDIA else CACHE_AGE_SECONDS)
  kept=sorted([p for p in files if p.exists()],key=lambda p:p.stat().st_mtime,reverse=True);total=0
  for p in kept:
   total+=p.stat().st_size
   if total>VIDEO_CAP_BYTES:discard(p,0)
 reviews=home/'identity-reviews'
 if reviews.is_dir() and not reviews.is_symlink():
  for review in reviews.iterdir():
   media=review/'media'
   if review.is_symlink() or media.is_symlink() or not media.is_dir():continue
   # Corrections and source provenance must exist before review media may expire.
   if not (review/'identity-review.json').is_file() or not (review/'facts.json').is_file():continue
   try:
    with session(media):
     for p in media.iterdir():
      if re.fullmatch(r'[A-Za-z0-9_-]{11}\.(mp4|webm|mkv)',p.name):discard(p,REVIEW_AGE_SECONDS)
   except ValueError:report['skipped_active']+=1
 # Robot/field detector work folders: frames and training runs from finished requests. The weights that
 # matter were uploaded to Neptune or discarded; housekeeping only runs between jobs.
 for name in ('robot-detector-work','field-detector-work'):
  work=home/name
  if not work.is_dir() or work.is_symlink():continue
  for run in work.iterdir():
   if run.is_symlink() or not run.is_dir():continue
   for q in run.rglob('*'):discard(q,WORK_AGE_SECONDS)
   if not dry_run:
    for d in sorted([x for x in run.rglob('*') if x.is_dir() and not x.is_symlink()],reverse=True)+[run]:
     try:d.rmdir()
     except OSError:pass
 if report['removed_files']:progress('Automatic cleanup freed %.1f MB; labels, models, corrections and results kept.'%(report['removed_bytes']/1024**2))
 return report
