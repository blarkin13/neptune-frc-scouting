"""Original match sources shared privately through Neptune. No transcoding or timestamp changes."""
import base64
import hashlib
import os
import time
from pathlib import Path

MAX_BYTES = 512 * 1024 * 1024
CHUNK_BYTES = 8 * 1024 * 1024
_last_youtube = 0.0


def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as f:
        for block in iter(lambda: f.read(1024*1024), b''):
            h.update(block)
    return h.hexdigest()


def _api(config, action, data):
    from neptune_robot_jobs import api
    return api(config, action, data, timeout=180)


def fetch(config, video_id, path, progress=print):
    info = _api(config, 'video_cache_info', {'video_id': video_id})
    if not info.get('available'):
        return False
    meta = info['video']; size = meta['bytes']; sha = meta['sha256']
    if not isinstance(size, int) or not 16 <= size <= MAX_BYTES or not isinstance(sha, str) or len(sha) != 64:
        raise ValueError('Invalid shared match source metadata')
    path = Path(path); path.parent.mkdir(parents=True, exist_ok=True)
    # Per-call temporary files: a cancelled transfer can never look like a completed local source.
    import tempfile
    fd, temp = tempfile.mkstemp(prefix=video_id+'-', suffix='.pending', dir=path.parent)
    pending = Path(temp)
    try:
        h = hashlib.sha256()
        with os.fdopen(fd, 'wb') as f:
            for offset in range(0, size, CHUNK_BYTES):
                row = _api(config, 'video_cache_chunk', {'video_id': video_id, 'sha256': sha, 'offset': offset})
                raw = base64.b64decode(row['data_b64'], validate=True)
                if row['offset'] != offset or len(raw) != min(CHUNK_BYTES, size-offset) or hashlib.sha256(raw).hexdigest() != row['sha256']:
                    raise ValueError('Shared match source chunk failed verification')
                f.write(raw); h.update(raw)
                progress('Reading cached match video · %d%%' % min(100, (offset+len(raw))*100//size))
        if h.hexdigest() != sha:
            raise ValueError('Shared match source failed its SHA-256 check')
        pending.replace(path)
        return True
    finally:
        pending.unlink(missing_ok=True)


def upload(config, video_id, path, progress=print):
    """Resume an interrupted upload; the server publishes only after verifying the whole source."""
    path = Path(path); size = path.stat().st_size
    if not 16 <= size <= MAX_BYTES:
        raise ValueError('Individual match source exceeds cache limits')
    sha = digest(path)
    reply = _api(config, 'video_cache_begin', {'video_id': video_id, 'sha256': sha, 'bytes': size})
    if reply.get('available'):
        return reply
    offset = reply['offset']
    if not isinstance(offset, int) or not 0 <= offset < size or offset % CHUNK_BYTES:
        raise ValueError('Invalid shared-cache resume offset')
    lease = reply
    with path.open('rb') as f:
        f.seek(offset)
        while offset < size:
            raw = f.read(CHUNK_BYTES)
            reply = _api(config, 'video_cache_put', {'upload_id': lease['upload_id'], 'offset': offset,
                         'data_b64': base64.b64encode(raw).decode()})
            offset += len(raw)
            if reply.get('offset') != offset:
                raise ValueError('Shared cache did not acknowledge this video chunk')
            progress('Saving match video to Neptune · %d%%' % (offset*100//size))
    if not reply.get('available') or reply['video']['sha256'] != sha:
        raise ValueError('Shared match source was not verified')
    return reply


def acquire(config, video_id, path=None, progress=print):
    """Local source, shared original, then one YouTube download. Cache outages never block local work."""
    global _last_youtube
    from neptune_robot_labels import local_video, youtube_download, VIDEOS
    local = local_video(video_id)
    if local is None:
        local = Path(path) if path else VIDEOS/(video_id+'.mp4')
        local.parent.mkdir(parents=True, exist_ok=True)
        if not local.is_file():
            if config:
                try:
                    if fetch(config, video_id, local, progress):
                        return local
                except Exception:
                    progress('Shared video cache unavailable; trying the original match source')
            delay = 8 - (time.monotonic()-_last_youtube)
            if delay > 0:
                time.sleep(delay)
            _last_youtube = time.monotonic()
            youtube_download(video_id, local)
    if config:
        try:
            upload(config, video_id, local, progress)
        except Exception:
            progress('Using local match video; shared cache upload deferred')
    return local
