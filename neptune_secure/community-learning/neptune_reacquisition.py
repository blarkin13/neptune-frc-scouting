"""Camera-local reacquisition proposals. Proximity never confirms identity."""
import math
VERSION='confirmed-first-provisional-recovery-v1'
LOST_RADIUS=200.0
UNCONFIRMED_RADIUS=60.0
OCR_EVERY_FRAMES=3
MAX_LOST_SECONDS=5.0
class Reacquisition:
 def __init__(self):self.scene=None;self.memory={}
 def propose(self,scene,t,detections,states,reads,width=960):
  if scene!=self.scene:self.memory={};self.scene=scene
  for tid,state in states.items():
   if state.get('team') is not None and state.get('box') and state.get('last',t)<=t:
    if t-state['last']<=MAX_LOST_SECONDS:self.memory[state['team']]={'id':tid,'box':state['box'],'t':state['last'],'alliance':state['alliance']}
  self.memory={team:row for team,row in self.memory.items() if 0<=t-row['t']<=MAX_LOST_SECONDS}
  direct={}
  for r in reads:
   if r.get('kind')=='exact':direct.setdefault(r['track'],set()).add(r['team'])
  used=set();out=[];scale=width/960
  for team,last in sorted(self.memory.items()):
   if any(d['id']==last['id'] for d in detections) and t-last['t']<.5:continue
   def distance(d):return math.hypot((d['box'][0]+d['box'][2]-last['box'][0]-last['box'][2])/2,(d['box'][1]+d['box'][3]-last['box'][1]-last['box'][3])/2)
   choices=[]
   for d in detections:
    tid=d['id'];known=states.get(tid,{}).get('team');numbers=direct.get(tid,set())
    if tid in used or d.get('occluded') or d.get('alliance')!=last['alliance']:continue
    if (known is not None and known!=team) or any(n!=team for n in numbers):continue
    # Even a previously confirmed track becomes provisional after a gap;
    # the independent OCR resolver supplies actual confirmed assignments.
    confirmed=known==team and t-states[tid].get('last',t)<=1.5
    limit=(LOST_RADIUS if confirmed else UNCONFIRMED_RADIUS)*scale
    dist=distance(d)
    if dist<=limit:choices.append((0 if confirmed else 1,dist,d))
   choices.sort(key=lambda v:(v[0],v[1]))
   if not choices:continue
   if len(choices)>1 and choices[0][0]==choices[1][0] and abs(choices[0][1]-choices[1][1])<10*scale:continue
   priority,dist,d=choices[0];used.add(d['id'])
   out.append({'track_id':d['id'],'candidate_team':team,'priority':priority+1,'distance_px':round(dist,2),'status':'confirmed_track_match' if priority==0 else 'provisional_requires_ocr','t':t,'scene':scene,'can_supply_action_identity':False})
  return out
