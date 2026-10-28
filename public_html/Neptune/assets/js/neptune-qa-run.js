(()=>{
  'use strict';
  const cfg=window.NeptuneQaConfig;
  if(!cfg)return;
  const find=id=>document.getElementById(id);
  const steps=find('qaSteps'), result=find('qaResult'), evidence=find('qaEvidence');
  const socket=find('qaSocket'), page=find('qaPage'), last=find('qaLast'), winNotice=find('qaWindow');
  let opening=null,busy=false,queued=false,lastCheck=0,closed=false,pokeTimer=null,stepsSeen='';
  const esc=str=>String(str??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const post=async(url,fields)=>{
    const body=new URLSearchParams();
    for(const [k,v] of Object.entries(fields)){
      if(v&&typeof v==='object'){for(const [k2,v2] of Object.entries(v))body.append(`${k}[${k2}]`,v2);}
      else body.append(k,v);
    }
    const r=await fetch(url,{method:'POST',body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}});
    let d=null;try{d=await r.json();}catch(_){}
    if(!r.ok||!d||d.status!=='success')throw new Error((d&&d.message)||`HTTP ${r.status}`);
    return d;
  };
  function syncStepSelectors(list){
    const key=JSON.stringify((list||[]).map(x=>[x.key,x.label]));
    if(key===stepsSeen)return;stepsSeen=key;
    const opts=(list||[]).map(x=>`<option value="${esc(x.key)}">${esc(x.label)}</option>`).join('');
    const fb=find('qaFeedbackStep');
    if(fb){const chosen=fb.value;fb.innerHTML='<option value="">Overall / no specific step</option>'+opts;if([...fb.options].some(o=>o.value===chosen))fb.value=chosen;}
    document.querySelectorAll('select[data-step-select]').forEach(sel=>{
      const chosen=sel.value||sel.dataset.saved||'';
      sel.innerHTML='<option value="">Choose…</option><option value="none">No step was a problem</option>'+opts;
      if([...sel.options].some(o=>o.value===chosen))sel.value=chosen;
    });
  }
  /* ---------- progress panel: what to do next, and when Finish unlocks ---------- */
  let lastState='in_progress';
  function answeredCount(){
    const f=find('qaDebriefForm');if(!f)return 0;let n=0;
    f.querySelectorAll('.qa-q').forEach(box=>{
      const radio=box.querySelector('input[type="radio"]:checked');
      const field=box.querySelector('select,textarea');
      const v=radio?radio.value:(field?field.value:'');
      if(String(v).trim()!=='')n++;
    });
    return n;
  }
  function updateProgress(r,state){
    lastState=state;
    const panel=find('qaProgress');if(!panel)return;
    const list=r.steps||[],total=list.length,done=list.filter(x=>x.status==='passed').length;
    const pending=list.find(x=>x.status!=='passed');
    const bad=list.find(x=>x.status==='failed');
    const terminal=state==='passed'||state==='failed'||state==='abandoned';
    const set=(id,cls,txt)=>{const el=find(id);if(!el)return;if(cls!==null)el.className=cls;};
    const txt=(id,t)=>{const el=find(id);if(el)el.textContent=t;};
    set('qaStage1',state==='passed'?'done':state==='failed'?'bad':state==='abandoned'?'bad':'active');
    txt('qaStage1Text',state==='abandoned'?'You exited this test':state==='failed'?'A check did not pass':`${done} of ${total||'?'} tasks done`);
    const answered=answeredCount();
    set('qaStage2',terminal?'active':'');
    txt('qaStage2Text',`${answered} of ${cfg.totalQuestions||'?'} answered · optional but very helpful`);
    set('qaStage3',terminal?'active':'');
    txt('qaStage3Text',terminal?'Ready — press Finish test':'Unlocks when the tasks have a result');
    let msg;
    if(state==='passed')msg='<b>All checks passed.</b> Next: answer the questions below (about 2 minutes), then press <b>Finish test</b>.';
    else if(state==='failed')msg=`<b>A check did not pass${bad?': '+esc(bad.label):''}.</b> ${bad?esc(bad.detail)+' ':''}Use the problem report form to tell us what happened, answer the questions below, then press <b>Finish test</b>.`;
    else if(state==='abandoned')msg='<b>You exited this test.</b> If you can, answer the questions below, then press <b>Finish test</b>.';
    else if(pending&&/automatic/i.test(pending.label))msg=`<b>Neptune is checking this one itself:</b> ${esc(pending.label)}<br><span class="muted">Nothing for you to do. It updates by itself within a few seconds; if it stays stuck for a minute, use the problem report form.</span>`;
    else if(pending)msg=`<b>Next task (${done+1} of ${total}):</b> ${esc(pending.label)}<br><span class="muted">${esc(pending.detail)}</span>`;
    else msg='Checking the database for your first task…';
    const nx=find('qaNext');if(nx)nx.innerHTML=msg;
    const fin=find('qaFinish');if(fin&&!fin.dataset.busy)fin.disabled=!terminal;
  }
  function render(payload){
    const r=payload.result;
    const state=r.status;
    result.textContent=state==='passed'?'PASS':state==='failed'?'FAIL':state==='abandoned'?'EXITED':'IN PROGRESS';
    result.className='qa-status '+(state==='passed'?'passed':state==='failed'?'failed':state==='abandoned'?'exited':'');
    steps.innerHTML=(r.steps||[]).map((s,i)=>`<li class="qa-test-step ${esc(s.status)}"><span class="qa-step-mark">${s.status==='passed'?'✓':s.status==='failed'?'!':i+1}</span><div><strong>${esc(s.label)}</strong><small>${esc(s.detail)}</small></div></li>`).join('')||'<li class="qa-test-step"><span class="qa-step-mark">–</span><div>No assertions recorded for this test.</div></li>';
    const nav=r.evidence?.page_tracking||{};
    page.textContent=nav.expected==null
      ? 'Page tracking is not used for this module'
      : nav.correct_qa_target
        ? `Tester page: correct ${nav.observed==='traditional'?'Traditional':'Tag'} QA screen · ${nav.path||'confirmed'}`
        : `Tester page: ${nav.observed||'awaiting navigation'} · expected ${nav.expected||'QA screen'}`;
    syncStepSelectors(r.steps);
    const warn=find('qaWarnings');
    if(warn)warn.innerHTML=(r.evidence?.warnings||[]).map(w=>`<div>⚠ ${esc(w)}</div>`).join('');
    evidence.textContent=JSON.stringify(r.evidence,null,2);
    find('qaHistory').innerHTML=(payload.checkpoints||[]).map(c=>`<div>${esc(c.checked_at)} UTC · <b>${esc(c.result_status)}</b></div>`).join('')||'No checkpoints yet';
    const passedCount=(r.steps||[]).filter(s=>s.status==='passed').length;
    last.textContent=state==='abandoned'?'This test was exited. No further verification runs.':`Database verified ${new Date(payload.checked_at).toLocaleTimeString()} · ${passedCount}/${(r.steps||[]).length} assertions passed`;
    updateProgress(r,state);
    const cta=find('qaDoneCta');if(cta&&(state==='passed'||state==='failed'||state==='abandoned'))cta.style.display='block';
    if(state==='abandoned')closed=true; // nothing left to poll
    lastCheck=Date.now();
  }
  async function check(force=false){
    if(busy){queued=queued||force;return;}
    if(closed||(!force&&Date.now()-lastCheck<1200))return;
    busy=true;
    try{
      const data=await post(cfg.stateUrl,{run_id:String(cfg.runId),csrf:cfg.csrf});
      render(data);
    }catch(err){
      last.textContent='QA checker unavailable: '+err.message+' · Retrying. Do not mark as passed.';
      result.textContent='CHECK UNAVAILABLE';result.className='qa-status failed';
    }finally{busy=false;if(queued){queued=false;setTimeout(()=>check(true),150);}}
  }

  /* ---------- structured bug reports ---------- */
  const fbForm=find('qaFeedbackForm'),fbStatus=find('qaFeedbackStatus'),fbList=find('qaFeedbackList');
  const label=(map,k)=>map[k]||k||'';
  const IMPACT={blocked:'Could not continue',workaround:'Found a workaround',slowed:'Slowed / annoyed',cosmetic:'Minor'};
  const REPEAT={always:'Every time',sometimes:'Sometimes',once:'Once',unknown:'Not sure'};
  function deviceContext(){
    return JSON.stringify({ua:navigator.userAgent||'',screen:`${screen.width}x${screen.height}`,viewport:`${innerWidth}x${innerHeight}`,
      lang:navigator.language||'',online:navigator.onLine,page:location.pathname,touch:('ontouchstart' in window)||navigator.maxTouchPoints>0,
      tz:(Intl.DateTimeFormat().resolvedOptions().timeZone)||''});
  }
  function showFeedback(items){
    if(!fbList)return;
    fbList.innerHTML=items.length?items.map(x=>`<div class="qa-feedback-entry">
      <strong>${esc(x.summary)}</strong> · ${esc(x.category)} · ${esc(label(IMPACT,x.impact)||x.severity)} · ${esc(x.review_status)}
      <div class="muted">${esc(x.reporter_name)} · ${esc(x.created_at)} UTC${x.step_key?' · step: '+esc(x.step_key):''}${x.repeatable?' · '+esc(label(REPEAT,x.repeatable)):''}</div>
      ${x.trying_text?`<p style="margin:.4rem 0 0"><b>Trying to:</b> ${esc(x.trying_text)}</p>`:''}
      ${x.expected_text?`<p style="margin:.2rem 0 0"><b>Expected:</b> ${esc(x.expected_text)}</p>`:''}
      ${x.details?`<p style="white-space:pre-wrap;margin:.4rem 0 0">${esc(x.details)}</p>`:''}</div>`).join(''):'No reports have been submitted for this run.';
  }
  async function loadFeedback(){try{showFeedback((await post(cfg.feedbackUrl,{csrf:cfg.csrf,run_id:String(cfg.runId),action:'list'})).feedback||[]);}catch(e){if(fbList)fbList.textContent='Feedback unavailable: '+e.message;}}
  fbForm?.addEventListener('submit',async ev=>{
    ev.preventDefault();
    if(!fbForm.reportValidity())return;
    const b=fbForm.querySelector('button[type="submit"]');if(b)b.disabled=true;
    if(fbStatus)fbStatus.textContent='Sending…';
    try{
      const payload={csrf:cfg.csrf,run_id:String(cfg.runId),action:'submit',client_context:deviceContext()};
      for(const [key,value] of new FormData(fbForm))payload[key]=String(value);
      showFeedback((await post(cfg.feedbackUrl,payload)).feedback||[]);
      fbForm.reset();if(fbStatus)fbStatus.textContent='Report sent. Thank you — the QA team will see it with your device details.';
    }catch(e){if(fbStatus)fbStatus.textContent='Could not send: '+e.message;}
    finally{if(b)b.disabled=false;}
  });

  /* ---------- debrief questionnaire and database checks ---------- */
  const debrief=find('qaDebriefForm'),dbStatus=find('qaDebriefStatus');
  const refreshAnswered=()=>{const el=find('qaStage2Text');if(el&&find('qaProgress'))el.textContent=`${answeredCount()} of ${cfg.totalQuestions||'?'} answered · optional but very helpful`;};
  debrief?.addEventListener('input',refreshAnswered);debrief?.addEventListener('change',refreshAnswered);
  function collectAnswers(){
    const answers={};
    debrief?.querySelectorAll('.qa-q').forEach(box=>{
      const key=box.dataset.q;if(!key)return;
      const radio=box.querySelector('input[type="radio"]:checked');
      const field=box.querySelector('select,textarea');
      const v=radio?radio.value:(field?field.value:'');
      if(String(v).trim()!=='')answers[key]=String(v).trim();
    });
    return answers;
  }
  debrief?.addEventListener('submit',async ev=>{
    ev.preventDefault();
    const answers=collectAnswers();
    if(!Object.keys(answers).length){if(dbStatus)dbStatus.textContent='Answer at least one question first.';return;}
    const b=debrief.querySelector('button[type="submit"]');if(b)b.disabled=true;if(dbStatus)dbStatus.textContent='Saving…';
    try{await post(cfg.feedbackUrl,{csrf:cfg.csrf,run_id:String(cfg.runId),action:'answers',a:answers});
      if(dbStatus)dbStatus.textContent=`Saved ${Object.keys(answers).length} answer(s). You can change them any time.`;}
    catch(e){if(dbStatus)dbStatus.textContent='Could not save: '+e.message;}
    finally{if(b)b.disabled=false;}
  });
  find('qaSaveChecks')?.addEventListener('click',async()=>{
    const out=find('qaChecksStatus');const answers={};
    document.querySelectorAll('#qaChecks input[data-check]:checked').forEach(i=>{answers[i.dataset.check]=i.value;});
    if(!Object.keys(answers).length){if(out)out.textContent='Choose an answer first.';return;}
    if(out)out.textContent='Submitting…';
    try{await post(cfg.feedbackUrl,{csrf:cfg.csrf,run_id:String(cfg.runId),action:'answers',a:answers});if(out)out.textContent='Answer saved. Checking…';check(true);}
    catch(e){if(out)out.textContent='Could not save: '+e.message;}
  });

  find('qaFinish')?.addEventListener('click',async()=>{
    const btn=find('qaFinish'),out=find('qaFinishStatus');
    btn.disabled=true;btn.dataset.busy='1';if(out)out.textContent='Saving your answers…';
    try{
      const answers=collectAnswers();
      if(Object.keys(answers).length)await post(cfg.feedbackUrl,{csrf:cfg.csrf,run_id:String(cfg.runId),action:'answers',a:answers});
      if(out)out.textContent='Finishing…';
      await post(cfg.pageUrl,{csrf:cfg.csrf,qa_ajax:'1',qa_action:'finish'});
      location.replace(cfg.pageUrl+'&finished=1#qaFinished');
    }catch(err){if(out)out.textContent='Could not finish: '+err.message;delete btn.dataset.busy;btn.disabled=!(lastState==='passed'||lastState==='failed'||lastState==='abandoned');}
  });
  /* ---------- windows, start-and-go, exit ---------- */
  find('qaRefresh')?.addEventListener('click',()=>{check(true);loadFeedback();});
  document.querySelectorAll('.qa-open').forEach(btn=>btn.addEventListener('click',()=>{
    const w=window.open(btn.dataset.url,btn.dataset.name||'NeptuneQaWindow','popup=yes,width=1140,height=830');
    if(btn.id==='qaOpenScout'){opening=w;
      winNotice.textContent=w?'Window opened; this tab can track its route while it stays on the same Neptune origin.':'Pop-up blocked. Allow pop-ups for Neptune and click again, or open the link manually.';}
    else if(!w){winNotice.textContent='Pop-up blocked. Allow pop-ups for Neptune and click again.';}
  }));
  find('qaStartAndGo')?.addEventListener('click',async ev=>{
    const btn=ev.currentTarget;if(btn.disabled)return;btn.disabled=true;
    // Open the window synchronously inside the click so the browser's pop-up blocker allows it.
    opening=window.open('about:blank','NeptuneQaScout'+cfg.runId,'popup=yes,width=1140,height=830');
    winNotice.textContent='Starting QA match…';
    try{
      await post(cfg.pageUrl,{csrf:cfg.csrf,qa_ajax:'1',qa_match_op:'start'});
      if(opening){opening.location.href=cfg.scoutUrl;winNotice.textContent='QA match started; scouting window opened.';
        setTimeout(()=>{location.replace(cfg.pageUrl+'&qa_control=start');},1200);}
      else{location.href=cfg.scoutUrl;} // pop-up blocked: continue in this tab
    }catch(err){
      if(opening&&!opening.closed)opening.close();opening=null;
      winNotice.textContent='Could not start the QA match: '+err.message;btn.disabled=false;
    }
  });
  const exitPanel=find('qaExitPanel');
  find('qaExitOpen')?.addEventListener('click',()=>{exitPanel?.classList.toggle('open');find('qaExitReason')?.focus();});
  find('qaExitCancel')?.addEventListener('click',()=>exitPanel?.classList.remove('open'));
  find('qaExitConfirm')?.addEventListener('click',async()=>{
    const out=find('qaExitStatus'),reason=find('qaExitReason')?.value||'',note=find('qaExitNote')?.value||'';
    if(!reason){if(out)out.textContent='Choose why you are leaving first.';return;}
    const btn=find('qaExitConfirm');btn.disabled=true;if(out)out.textContent='Exiting…';
    try{await post(cfg.pageUrl,{csrf:cfg.csrf,qa_ajax:'1',qa_action:'exit',reason,note});
      if(out)out.textContent='Test exited.';
      if(opening&&!opening.closed){try{opening.close();}catch(_){}}
      location.replace(cfg.pageUrl+'&exited=1#qaDebrief');}
    catch(err){if(out)out.textContent='Could not exit: '+err.message;btn.disabled=false;}
  });
  function windowPosition(){
    if(!opening)return;
    if(opening.closed){opening=null;winNotice.textContent='Window closed';return;}
    try{
      const u=new URL(opening.location.href);
      if(u.origin!==location.origin){winNotice.textContent='Window navigated to another origin; exact page tracking unavailable';return;}
      winNotice.textContent=`Open window: ${u.pathname}${u.search} · ${opening.document.visibilityState==='visible'?'visible':'background'}`;
    }catch(_){winNotice.textContent='Other window cannot be inspected due to browser origin restrictions';}
  }
  setInterval(()=>{check();windowPosition();},5000);
  window.addEventListener('focus',()=>check(true));
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')check(true);});
  window.addEventListener('pagehide',()=>{closed=true;realtime?.close?.();});
  let realtime=null;
  if(window.NeptuneRealtime?.create&&!cfg.exited){
    realtime=window.NeptuneRealtime.create({url:cfg.realtimeUrl,authUrl:cfg.realtimeTokenUrl,
      onStatus:status=>{socket.textContent=status==='socket'?'MERCURY connected':'MERCURY '+(status==='polling'?'polling fallback':'connecting');},
      onPoke:data=>{
        if(String(data?.type||'')==='hello')return;
        const pokedMatch=Number(data?.match_id||0);
        if(pokedMatch&&pokedMatch!==Number(cfg.matchId))return; // other matches' traffic never triggers a database check
        clearTimeout(pokeTimer);pokeTimer=setTimeout(()=>check(true),700); // debounce bursts of actions
      }
    });
  }else socket.textContent=cfg.exited?'Test exited':'MERCURY unavailable · HTTP polling active';
  check(true);
  loadFeedback();
})();
