"""Authenticated cloud backup for full identity reviews; video stays on the helper."""
import base64,gzip,hashlib,json,re,zlib
from pathlib import Path

class ReviewCloud:
    def __init__(self,connection,transport=None):
        self.connection=connection
        self.transport=transport

    def call(self,action,payload):
        if self.transport:return self.transport(action,payload)
        from neptune_trainer import api
        return api(self.connection,action,payload)

    @staticmethod
    def validate(review,source=None,match=None):
        from neptune_identity import roster_check
        if not isinstance(review,dict) or review.get('schema_version')!=1:raise ValueError('Invalid cloud review schema')
        fingerprint=review.get('source_sha256')
        if not isinstance(fingerprint,str) or not re.fullmatch('[a-f0-9]{64}',fingerprint) or source and fingerprint!=source:raise ValueError('Cloud review video fingerprint mismatch')
        key=review.get('match_key')
        if not isinstance(key,str) or not re.fullmatch(r'\d{4}[A-Za-z0-9_-]{1,95}',key) or match and key!=match:raise ValueError('Cloud review match mismatch')
        if not isinstance(review.get('video_id'),str) or not re.fullmatch('[A-Za-z0-9_-]{11}',review['video_id']):raise ValueError('Cloud review video ID missing')
        roster_check(review.get('roster'))
        if not isinstance(review.get('points'),list) or not isinstance(review.get('corrections'),list):raise ValueError('Invalid cloud review content')
        return review

    def listing(self):
        rows=self.call('review_list',{}).get('reviews',[])
        if not isinstance(rows,list):raise ValueError('Invalid cloud review list')
        return [r for r in rows if isinstance(r,dict) and isinstance(r.get('source_sha256'),str) and re.fullmatch('[a-f0-9]{64}',r['source_sha256']) and isinstance(r.get('match_key'),str) and re.fullmatch(r'\d{4}[A-Za-z0-9_-]{1,95}',r['match_key']) and isinstance(r.get('video_id'),str) and re.fullmatch('[A-Za-z0-9_-]{11}',r['video_id'])]

    def fetch(self,source,match=None):
        result=self.call('review_get',{'source_sha256':source})
        if not result.get('found'):return None,None
        data=base64.b64decode(result['review_gzip_b64'],validate=True)
        if len(data)>2*1024*1024:raise ValueError('Cloud review is too large')
        decoder=zlib.decompressobj(wbits=31)
        raw=decoder.decompress(data,16*1024*1024+1)
        if len(raw)>16*1024*1024 or not decoder.eof or decoder.unconsumed_tail or hashlib.sha256(raw).hexdigest()!=result['revision']:raise ValueError('Cloud review checksum mismatch')
        review=self.validate(json.loads(raw),source,match)
        return review,result['revision']

    def save(self,review,expected_revision=None):
        self.validate(review)
        raw=json.dumps(review,allow_nan=False,separators=(',',':')).encode()
        if len(raw)>16*1024*1024:raise ValueError('Review is too large for cloud backup')
        packed=gzip.compress(raw,compresslevel=6)
        if len(packed)>2*1024*1024:raise ValueError('Compressed review is too large for cloud backup')
        answer=self.call('review_put',{'source_sha256':review['source_sha256'],'expected_revision':expected_revision,'review_gzip_b64':base64.b64encode(packed).decode('ascii')})
        revision=hashlib.sha256(raw).hexdigest()
        if answer.get('revision')!=revision:raise ValueError('Cloud backup confirmation did not match this review')
        return revision

    def backfill(self,folder,video_ids=None):
        """Backup old local reviews only if the cloud has no different revision."""
        backed_up=0;conflicts=[]
        for path in sorted(Path(folder).glob('*/identity-review.json')):
            if path.is_symlink() or path.stat().st_size>16*1024*1024:continue
            try:
                review=json.loads(path.read_text())
                if 'video_id' not in review and video_ids and review.get('match_key') in video_ids:review['video_id']=video_ids[review['match_key']]
                review=self.validate(review)
                if not review['corrections']:continue
                cloud,revision=self.fetch(review['source_sha256'],review['match_key'])
                if cloud is None:self.save(review);backed_up+=1
                elif cloud.get('corrections')!=review.get('corrections'):conflicts.append(review['match_key'])
            except Exception as exc:conflicts.append(path.parent.name+': '+str(exc)[:100])
        return {'backed_up':backed_up,'conflicts':conflicts}
