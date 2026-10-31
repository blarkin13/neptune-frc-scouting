"""Four-corner planar mapping; normalized field units, never invented positions."""
import math
VERSION='reviewed-four-corner-field-map-v1'
def solve(a,b):
 m=[list(row)+[v] for row,v in zip(a,b)];n=len(b)
 for c in range(n):
  p=max(range(c,n),key=lambda r:abs(m[r][c]));m[c],m[p]=m[p],m[c]
  if abs(m[c][c])<1e-10:raise ValueError('Field corners are degenerate')
  scale=m[c][c];m[c]=[v/scale for v in m[c]]
  for r in range(n):
   if r!=c:
    factor=m[r][c];m[r]=[x-factor*y for x,y in zip(m[r],m[c])]
 return [row[-1] for row in m]
def calibrate(points):
 if len(points)!=4:raise ValueError('Mark four corners in field order: top-left, top-right, bottom-right, bottom-left')
 # Convex, noncrossing quadrilateral required; order defines map orientation.
 crosses=[]
 for i in range(4):
  a,b,c=points[i],points[(i+1)%4],points[(i+2)%4]
  crosses.append((b[0]-a[0])*(c[1]-b[1])-(b[1]-a[1])*(c[0]-b[0]))
 if any(abs(x)<1e-8 for x in crosses) or not (all(x>0 for x in crosses) or all(x<0 for x in crosses)):raise ValueError('Corners must form a convex field quadrilateral')
 a=[];b=[]
 for (x,y),(u,v) in zip(points,[(0,0),(1,0),(1,1),(0,1)]):
  if not all(type(q) in (int,float) and math.isfinite(q) for q in (x,y)):raise ValueError('Invalid corner')
  a.extend([[x,y,1,0,0,0,-u*x,-u*y],[0,0,0,x,y,1,-v*x,-v*y]]);b.extend([u,v])
 return solve(a,b)+[1.]
def project(h,p):
 x,y=p;d=h[6]*x+h[7]*y+h[8]
 if abs(d)<1e-9:return None
 return [(h[0]*x+h[1]*y+h[2])/d,(h[3]*x+h[4]*y+h[5])/d]
def field_references(game_key):
 """This game's field references (sizes and where each marked point or line sits on the field), from the game pack."""
 from pathlib import Path
 import json
 seasons=json.loads(Path(__file__).with_name('season_rules.json').read_text())['seasons'].values()
 return next((s.get('field_references') for s in seasons if s.get('game_key')==game_key),None)
def reference_map(marks,refs,tolerance=.04):
 """Picture -> normalized field mapping from marked field references: points, lines, and lines through a known point
 or along a known direction. Corners hidden by an overlay are never needed. None unless the marks pin the field plane
 down, agree with each other, and put the field in front of the camera."""
 import numpy as np
 known=(refs or {}).get('references',{});size=(refs or {}).get('size')
 if not size or not known:return None
 s=float(size[0]);rows=[];checks=[]
 for r in marks:
  ref=known.get(r.get('reference'));ps=r.get('points',[])
  if not ref:continue
  if 'point' in ref and len(ps)==1:
   P=[ref['point'][0]/s,ref['point'][1]/s,1.];u,v=ps[0]
   rows+=[[0,0,0]+[-c for c in P]+[v*c for c in P],P+[0,0,0]+[-u*c for c in P]];checks.append(('point',P,ps[0]))
  elif len(ps)==2 and any(k in ref for k in ('line','through','parallel')):
   l=np.cross([*ps[0],1.],[*ps[1],1.]);n=float(np.linalg.norm(l[:2]))
   if n<1e-6:continue
   l=l/n
   field=[[x/s,y/s,1.] for x,y in ref['line']] if 'line' in ref else [[ref['through'][0]/s,ref['through'][1]/s,1.]] if 'through' in ref else [[*map(float,ref['parallel']),0.]]
   for P in field:rows.append([l[0]*c for c in P]+[l[1]*c for c in P]+[l[2]*c for c in P]);checks.append(('line',P,l) if P[2] else ('direction',P,ps))
 if len(rows)<8:return None
 a=np.array(rows);_,sv,vt=np.linalg.svd(a)
 if len(sv)<8 or sv[7]<1e-7*sv[0]:return None          # the marks leave the field plane undetermined
 h=vt[-1].reshape(3,3)
 corners=[h@[x/s,y/s,1.] for x,y in ((0,0),(size[0],0),(size[0],size[1]),(0,size[1]))]
 if not (all(c[2]>0 for c in corners) or all(c[2]<0 for c in corners)):return None   # the field must lie in front of the camera
 if corners[0][0]/corners[0][2]>=corners[1][0]/corners[1][2]:return None   # far-left corner left of far-right
 for kind,P,target in checks:
  q=h@P
  if kind=='point' and math.dist(q[:2]/q[2],target)>tolerance:return None
  if kind=='line' and abs(q[2])>1e-9 and abs(float(np.dot(target,q/q[2])))>tolerance:return None
  if kind=='direction':
   # A vanishing point can sit far off the picture; compare the marked line's direction on the field instead.
   f=[np.linalg.solve(h,[*p,1.]) for p in target];d=f[1][:2]/f[1][2]-f[0][:2]/f[0][2]
   if abs(d[0]*P[1]-d[1]*P[0])>3*tolerance*float(np.linalg.norm(d))*math.hypot(P[0],P[1]):return None
 m=np.diag([1.,s/float(size[1]),1.])@np.linalg.inv(h)   # to the unit square: x along the guardrails, y from the far one
 return (m/m[2,2]).flatten().tolist()
def trajectories(facts,game_key,root=None):
 from neptune_field_regions import load,active
 d=load(facts,game_key,root)
 if not d:return {'status':'needs_reviewed_field_corners','version':VERSION,'points':[]}
 calibrations=[(r,calibrate(r['points'])) for r in d['regions'] if r['kind']=='field_calibration']
 if not calibrations:
  # A four-point field boundary is the same field outline; order its corners from the image.
  from neptune_field_detector import ordered_quad
  for r in d['regions']:
   if r['kind']=='field_boundary' and r['camera_view']=='wide' and len(r['points'])==4 and r.get('corner_indices',list(range(4)))==list(range(4)) and not r.get('inferred_corner_indices'):
    try:calibrations.append((r,calibrate(ordered_quad(r['points']))))
    except ValueError:pass
 if not any(r['camera_view']=='wide' for r,_ in calibrations):
  # No four visible corners: build the map from the marked field references of each wide shot.
  shots={}
  for r in d['regions']:
   if r['kind']=='field_reference' and r['camera_view']=='wide':shots.setdefault(r.get('shot_id','legacy'),[]).append(r)
  refs=field_references(game_key) if shots else None
  for shot,marks in shots.items():
   h=reference_map(marks,refs)
   if h:calibrations.append((dict(marks[0],id='field-references:'+shot,reference_marks=[m['id'] for m in marks]),h))
 out=[]
 for p in facts.get('tracking',{}).get('localizations',[]):
  if p.get('identity_method') not in {'direct_vote_track','yolo_botsort_ocr','human_reviewed_track','roster_inferred','constant_identity'} or p.get('occluded') or p.get('box_source')=='optical_flow':continue
  choices=[(r,h) for r,h in calibrations if active(r,p['t'],p.get('camera'),facts)]
  if len(choices)!=1:continue
  r,h=choices[0];x,y,w,height=p['box'];q=project(h,[x+w/2,y+height])
  if q and all(-.02<=v<=1.02 for v in q):out.append({'team':p['team'],'t':p['t'],'field_x':round(q[0],6),'field_y':round(q[1],6),'camera':p['camera'],'scene':p.get('scene'),'identity_segment':p.get('identity_segment'),'calibration_id':r['id']})
 return {'status':'mapped' if calibrations else 'needs_reviewed_field_corners','version':VERSION,'units':'normalized_field','position_basis':'estimated_box_bottom_center','points':out,'note':'No metric speed or distance; camera cuts and unobserved positions are excluded.'}
