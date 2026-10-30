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
 out=[]
 for p in facts.get('tracking',{}).get('localizations',[]):
  if p.get('identity_method') not in {'direct_vote_track','yolo_botsort_ocr','human_reviewed_track','roster_inferred','constant_identity'} or p.get('occluded') or p.get('box_source')=='optical_flow':continue
  choices=[(r,h) for r,h in calibrations if active(r,p['t'],p.get('camera'),facts)]
  if len(choices)!=1:continue
  r,h=choices[0];x,y,w,height=p['box'];q=project(h,[x+w/2,y+height])
  if q and all(-.02<=v<=1.02 for v in q):out.append({'team':p['team'],'t':p['t'],'field_x':round(q[0],6),'field_y':round(q[1],6),'camera':p['camera'],'scene':p.get('scene'),'identity_segment':p.get('identity_segment'),'calibration_id':r['id']})
 return {'status':'mapped' if calibrations else 'needs_reviewed_field_corners','version':VERSION,'units':'normalized_field','position_basis':'estimated_box_bottom_center','points':out,'note':'No metric speed or distance; camera cuts and unobserved positions are excluded.'}
