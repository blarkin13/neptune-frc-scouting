'use strict';
// Year -> event (by start date, searchable) -> match picker shared by the Auto Scout labelling pages.
// The page answers ?events=<year> and ?event_matches=<event> (CommunityCatalog), and opens ?match_id=<id>.
(()=>{
 const box=document.querySelector('[data-match-picker]');if(!box)return;
 const endpoint=box.dataset.endpoint,current=box.dataset.selected||'';
 const el=(tag,attrs={},text)=>{const e=document.createElement(tag);Object.assign(e,attrs);if(text!==undefined)e.textContent=text;return e};
 const year=el('select',{'ariaLabel':'Year'}),search=el('input',{type:'search',placeholder:'Search events…','ariaLabel':'Search events'}),
       event=el('select',{'ariaLabel':'Event'}),match=el('select',{'ariaLabel':'Match'}),note=el('small',{className:'mp-note'});
 const field=(label,control)=>{const l=el('label',{className:'mp-field'});l.append(el('span',{},label),control);return l};
 box.append(field('Year',year),field('Event',el('div',{className:'mp-event'})),field('Match',match),note);
 box.querySelector('.mp-event').append(search,event);
 let events=[];
 const now=new Date().getFullYear(),pick=current.match(/^(\d{4})([a-z0-9]+)_/);
 for(let y=now+1;y>=2025;y--)year.add(new Option(String(y),String(y)));
 year.value=pick?pick[1]:String(now);
 const fmt=d=>d?new Date(d+'T12:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric'}):'';
 async function json(q){const r=await fetch(endpoint+q,{headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok)throw Error(d.error||'Could not load');return d}
 function fillEvents(keep){const q=search.value.trim().toLowerCase();event.length=0;event.add(new Option(events.length?'Choose an event…':'No events',''));
  for(const e of events){const text=(fmt(e.start_date)?fmt(e.start_date)+' · ':'')+e.name+' ('+e.key+')';if(q&&!text.toLowerCase().includes(q))continue;event.add(new Option(text,e.key))}
  if(keep&&[...event.options].some(o=>o.value===keep))event.value=keep;note.textContent=q?`${event.length-1} of ${events.length} events`:''}
 async function loadEvents(keep){event.disabled=true;match.length=0;match.add(new Option('Choose an event first',''));match.disabled=true;
  try{events=(await json('?events='+year.value)).events||[];fillEvents(keep);if(keep&&event.value===keep)await loadMatches(current)}
  catch(e){note.textContent=e.message}finally{event.disabled=false}}
 async function loadMatches(keep){match.disabled=true;match.length=0;match.add(new Option('Loading matches…',''));
  try{const rows=(await json('?event_matches='+encodeURIComponent(event.value))).matches||[];match.length=0;
   const usable=rows.filter(m=>m.video_url);match.add(new Option(usable.length?'Choose a match…':'No matches with video yet',''));
   for(const m of usable){const o=new Option(m.title||m.match_key,String(m.id));o.dataset.key=m.match_key;match.add(o)}
   if(keep){const o=[...match.options].find(o=>o.dataset.key===keep);if(o)match.value=o.value}
   note.textContent=rows.length>usable.length?`${rows.length-usable.length} matches have no video yet`:''}
  catch(e){match.length=0;match.add(new Option(e.message,''))}finally{match.disabled=false}}
 year.addEventListener('change',()=>{search.value='';loadEvents()});
 search.addEventListener('input',()=>fillEvents(event.value));
 event.addEventListener('change',()=>event.value&&loadMatches());
 // data-target: open the match in that frame on this page (3 · Actions) instead of reloading the page.
 match.addEventListener('change',()=>{if(!match.value)return;const frame=box.dataset.target&&document.getElementById(box.dataset.target);
  if(frame){frame.src=frame.dataset.src.replace('{id}',match.value);frame.hidden=false;const key=match.selectedOptions[0]?.dataset.key||'';history.replaceState(null,'',`?tag=${match.value}&key=${encodeURIComponent(key)}`+location.hash);frame.dispatchEvent(new Event('match'))}
  else location.search='?match_id='+match.value});
 loadEvents(pick?pick[1]+pick[2]:'');
})();
