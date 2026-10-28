'use strict';
// Watch & tag: where the scoreboard went up in this match, so people know where to look. A hint, never a tag:
// the person still decides which robot scored and tags it. Data: score-moments.php (sent by a helper).
(()=>{
 const config=JSON.parse(document.getElementById('tagConfig')?.textContent||'null');const host=document.getElementById('tagPlayer');
 if(!config?.video_id||!host)return;
 const el=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e};
 const clock=s=>Math.floor(s/60)+':'+String(Math.floor(s%60)).padStart(2,'0');
 const panel=el('section','score-moments');panel.setAttribute('aria-label','Scoreboard score changes');
 const anchor=host.closest('.tag-video, .video-frame, .player, figure')||host.parentElement;anchor.after(panel);
 function seek(t){const p=window.YT?.get?.('tagPlayer');if(p?.seekTo){p.seekTo(Math.max(0,t-2),true);p.pauseVideo?.()}}
 fetch('score-moments.php?video='+encodeURIComponent(config.video_id),{headers:{Accept:'application/json'}}).then(r=>r.json()).then(d=>{
  const m=d.moments;
  if(!m||!m.bursts?.length){panel.append(el('p','sm-note','Scoreboard moments appear here after a helper has prepared this match.'));return}
  panel.append(el('h3','','Where the scoreboard went up'),el('p','sm-note','A hint for where to look, not a tag. Jump to a moment, watch which robot scored, then tag that robot as usual.'));
  const end=Math.max(...m.bursts.map(b=>b.end))+10,bar=el('div','sm-bar');
  for(const b of m.bursts){const s=el('button','sm-seg sm-'+b.alliance);s.type='button';s.style.left=(100*b.start/end)+'%';s.style.width=Math.max(.6,100*(b.end-b.start+1)/end)+'%';
   s.title=`${clock(b.start)}–${clock(b.end)} · ${b.alliance} +${b.points}`;s.setAttribute('aria-label',s.title);s.onclick=()=>seek(b.start);bar.append(s)}
  const list=el('div','sm-list');
  for(const b of m.bursts){const c=el('button','sm-chip sm-'+b.alliance,`${clock(b.start)}${b.end>b.start?'–'+clock(b.end):''} · ${b.alliance==='red'?'Red':'Blue'} +${b.points}${b.phase==='auto'?' · auto':''}`);c.type='button';c.onclick=()=>seek(b.start);list.append(c)}
  panel.append(bar,list);
 }).catch(()=>{});
 const css=el('style');css.textContent=`.score-moments{margin:14px 0;padding:12px 14px;border:1px solid var(--line,#25333e);border-radius:12px;background:var(--panel,#111c26)}
.score-moments h3{margin:0 0 4px;font-size:1rem}.score-moments .sm-note{margin:0 0 10px;color:var(--muted,#93a5b3);font-size:.9rem}
.sm-bar{position:relative;height:16px;border-radius:8px;background:var(--panel2,#182630);margin-bottom:10px}
.sm-seg{position:absolute;top:2px;height:12px;border:0!important;border-radius:6px;padding:0!important;min-height:0!important;cursor:pointer}
.sm-list{display:flex;flex-wrap:wrap;gap:6px}.sm-chip{border-radius:999px!important;padding:5px 11px!important;min-height:0!important;font-size:.85rem;border:1px solid var(--line,#25333e)!important;background:var(--panel2,#182630)!important;color:var(--text,#edf5fa)!important}
.sm-seg.sm-red{background:#e5484d}.sm-seg.sm-blue{background:#3e8ed0}.sm-chip.sm-red{border-color:#e5484d!important}.sm-chip.sm-blue{border-color:#3e8ed0!important}`;
 document.head.append(css);
})();
