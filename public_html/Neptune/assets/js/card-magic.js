(()=>{
'use strict';
const cfg=window.NEPTUNE_CARD_MAGIC||{};
const pool=Array.isArray(cfg.pool)?cfg.pool:[];
const trickKeys=Array.isArray(cfg.tricks)&&cfg.tricks.length?cfg.tricks:['vanish','force','1089','37'];
const cycle=!!cfg.cycle;
const $=id=>document.getElementById(id);
const el={
  stage:$('magicStage'),name:$('magicTrickName'),kicker:$('magicKicker'),headline:$('magicHeadline'),
  instruction:$('magicInstruction'),board:$('magicBoard'),note:$('magicNote'),pos:$('magicPosition'),
  progress:$('magicProgress'),pause:$('magicPause'),nextStep:$('magicNextStep'),nextTrick:$('magicNextTrick'),fullscreen:$('magicFullscreen')
};
let trickIndex=0,stageIndex=0,timer=0,raf=0,deadline=0,duration=0,paused=false,remaining=0,current=null;

function shuffle(a){a=[...a];for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a}
function take(n,exclude=[]){const blocked=new Set(exclude.map(x=>x.team));return shuffle(pool.filter(x=>!blocked.has(x.team))).slice(0,n)}
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function cardHtml(c,index,opts={}){
  const logo=c.logo?`<img src="${esc(c.logo)}" alt="Team ${c.team} logo">`:`<span class="magic-card-fallback">#${c.team}</span>`;
  return `<div class="magic-card${opts.reveal?' reveal':''}${opts.dim?' dim':''}">${opts.numbered?`<span class="magic-card-index">${index+1}</span>`:''}${logo}<strong>#${c.team}</strong><small>${esc(c.nickname||'FRC Team')}</small></div>`;
}
function grid(cards,numbered=false,revealIndex=-1,dimOthers=false){
  const count=cards.length;const cols=count<=6?count:(count<=9?3:(count<=12?4:6));
  return `<div class="magic-card-grid" style="--cols:${cols};--cols-mobile:${count<=6?2:3}">${cards.map((c,i)=>cardHtml(c,i,{numbered,reveal:i===revealIndex,dim:dimOthers&&i!==revealIndex})).join('')}</div>`;
}
function prediction(card,locked=true){
  if(locked)return `<div class="magic-prediction"><i class="fa-solid fa-lock"></i><small>NEPTUNE'S PREDICTION IS LOCKED</small><strong>Do not change your mind.</strong></div>`;
  return `<div class="magic-prediction"><i class="fa-solid fa-wand-magic-sparkles"></i><small>NEPTUNE PREDICTED</small>${cardHtml(card,0,{reveal:true})}</div>`;
}
function equation(html){return `<div class="magic-equation">${html}</div>`}
function setText(s){el.kicker.textContent=s.kicker||'NEPTUNE MAGIC';el.headline.textContent=s.headline||'';el.instruction.textContent=s.instruction||'';el.note.textContent=s.note||'';el.board.innerHTML=typeof s.board==='function'?s.board():s.board||''}
function buildTrick(key){
  if(key==='vanish'){
    const first=take(6),second=take(5,first);
    return {name:'Vanishing Team',stages:[
      {ms:11000,kicker:'VANISHING TEAM',headline:'Choose one team.',instruction:'Remember it. Do not point to it, touch it, or say it out loud.',board:()=>grid(first,false),note:'Keep your eyes on your team.'},
      {ms:5000,kicker:'LOCK IT IN',headline:'Picture the logo in your head.',instruction:'Neptune is going to remove exactly one team.',board:()=>grid(first,false)},
      {ms:9000,kicker:'LOOK AGAIN',headline:'Your team is gone.',instruction:'Find the team you chose. Neptune removed it.',board:()=>grid(second,false),note:'No answer was entered into the screen.'},
      {ms:6500,kicker:'HOW?',headline:'Every card changed.',instruction:'The classic disappearing-card illusion — rebuilt with FRC teams.',board:()=>grid(second,false)}
    ]};
  }
  if(key==='force'){
    const cards=take(9),force=Math.floor(Math.random()*9)+1,add=force*2,pred=cards[force-1];
    return {name:'Neptune Number Force',stages:[
      {ms:8000,kicker:'NUMBER FORCE',headline:'Choose any number from 1 to 9.',instruction:'Keep your starting number secret.',board:()=>grid(cards,true)},
      {ms:6500,kicker:'MENTAL MATH',headline:'Double your number.',instruction:'Do it in your head. Keep the result.',board:()=>prediction(pred,true)},
      {ms:6500,kicker:'MENTAL MATH',headline:`Add ${add}.`,instruction:'Now add that number to your doubled result.',board:()=>equation(`× 2 &nbsp; <span class="accent">+ ${add}</span>`)},
      {ms:6500,kicker:'MENTAL MATH',headline:'Divide by 2.',instruction:'Halve the number you have now.',board:()=>equation(`÷ <span class="accent">2</span>`)},
      {ms:6500,kicker:'FINAL MOVE',headline:'Subtract your original number.',instruction:'The number you first chose — subtract it now.',board:()=>prediction(pred,true)},
      {ms:9500,kicker:'THE FORCE',headline:`You landed on ${force}.`,instruction:`Look at card ${force}. Neptune locked it before you started.`,board:()=>grid(cards,true,force-1,true)},
      {ms:8500,kicker:'PREDICTION',headline:`Team ${pred.team}.`,instruction:pred.nickname||'Neptune knew where the math would take you.',board:()=>prediction(pred,false)}
    ]};
  }
  if(key==='1089'){
    const cards=take(9),pred=cards[8];
    return {name:'1089 Prediction',stages:[
      {ms:9000,kicker:'1089 PREDICTION',headline:'Create any 3-digit number.',instruction:'The first and last digits must differ by at least 2. Example: 731 works.',board:()=>grid(cards,true)},
      {ms:7500,kicker:'STEP 1',headline:'Reverse the number.',instruction:'731 becomes 137. Keep both numbers in your head or use your phone calculator.',board:()=>prediction(pred,true)},
      {ms:7500,kicker:'STEP 2',headline:'Subtract smaller from larger.',instruction:'Use the two numbers you now have.',board:()=>equation(`LARGER <span class="accent">−</span> SMALLER`)},
      {ms:7500,kicker:'STEP 3',headline:'Reverse that answer.',instruction:'If the answer has only two digits, put a zero in front before reversing it.',board:()=>prediction(pred,true)},
      {ms:7500,kicker:'STEP 4',headline:'Add those last two numbers.',instruction:'Your subtraction answer plus its reverse.',board:()=>equation(`ANSWER <span class="accent">+</span> REVERSE`)},
      {ms:8500,kicker:'NEPTUNE KNOWS',headline:'You got 1089.',instruction:'Add the digits: 1 + 0 + 8 + 9 = 18. Add 1 + 8 = 9.',board:()=>equation(`1089 → 18 → <span class="accent">9</span>`)},
      {ms:9000,kicker:'PREDICTION',headline:`Card 9 is Team ${pred.team}.`,instruction:pred.nickname||'The starting number felt free. The ending was not.',board:()=>grid(cards,true,8,true)}
    ]};
  }
  const cards=take(12),pred=cards[9];
  return {name:'The 37 Engine',stages:[
    {ms:8500,kicker:'THE 37 ENGINE',headline:'Choose any digit from 1 to 9.',instruction:'Keep it secret. Now write it three times to make a 3-digit number.',board:()=>grid(cards,true)},
    {ms:7500,kicker:'STEP 1',headline:'Make the repeated number.',instruction:'If you chose 7, your number is 777. If you chose 3, it is 333.',board:()=>prediction(pred,true)},
    {ms:7500,kicker:'STEP 2',headline:'Add your three digits.',instruction:'For 777, that would be 7 + 7 + 7 = 21.',board:()=>equation(`DIGIT + DIGIT + DIGIT`)},
    {ms:8000,kicker:'STEP 3',headline:'Divide the 3-digit number by that sum.',instruction:'Example: 777 ÷ 21. Use a calculator if you want.',board:()=>equation(`3-DIGIT NUMBER <span class="accent">÷</span> DIGIT SUM`)},
    {ms:8000,kicker:'NEPTUNE KNOWS',headline:'Your answer is 37.',instruction:'Add 3 + 7. That gives you card number 10.',board:()=>equation(`37 → <span class="accent">10</span>`)},
    {ms:9500,kicker:'PREDICTION',headline:`Card 10 is Team ${pred.team}.`,instruction:pred.nickname||'Any starting digit. The same final card.',board:()=>grid(cards,true,9,true)}
  ]};
}
function clearClock(){clearTimeout(timer);cancelAnimationFrame(raf);timer=0;raf=0}
function progressLoop(){
  cancelAnimationFrame(raf);
  const run=()=>{if(paused)return;const left=Math.max(0,deadline-performance.now());const done=duration?1-left/duration:1;el.progress.style.width=(done*100)+'%';if(left>0)raf=requestAnimationFrame(run)};run();
}
function schedule(ms){clearClock();duration=ms;remaining=ms;deadline=performance.now()+ms;el.progress.style.width='0%';timer=setTimeout(nextStage,ms);progressLoop()}
function renderStage(){
  const s=current.stages[stageIndex];setText(s);el.name.textContent=current.name;el.pos.textContent=(trickIndex+1)+' / '+trickKeys.length;schedule(s.ms||7000)
}
function startTrick(index){
  clearClock();paused=false;el.stage.classList.remove('magic-paused');el.pause.innerHTML='<i class="fa-solid fa-pause"></i>';
  trickIndex=(index+trickKeys.length)%trickKeys.length;stageIndex=0;current=buildTrick(trickKeys[trickIndex]);renderStage()
}
function nextStage(){
  clearClock();stageIndex++;
  if(stageIndex<current.stages.length){renderStage();return}
  if(cycle){startTrick((trickIndex+1)%trickKeys.length);return}
  if(trickIndex<trickKeys.length-1){startTrick(trickIndex+1);return}
  stageIndex=current.stages.length-1;
  remaining=0;paused=true;el.progress.style.width='100%';
  el.stage.classList.add('magic-paused');el.pause.innerHTML='<i class="fa-solid fa-rotate-right"></i>';
}
function togglePause(forcePause){
  if(paused&&remaining<=0){startTrick(0);return}
  if(forcePause===true&&!paused){remaining=Math.max(0,deadline-performance.now());clearClock();paused=true}
  else if(forcePause===false&&paused){paused=false;schedule(remaining||duration)}
  else if(paused){paused=false;schedule(remaining||duration)}
  else{remaining=Math.max(0,deadline-performance.now());clearClock();paused=true}
  el.stage.classList.toggle('magic-paused',paused);el.pause.innerHTML=paused?'<i class="fa-solid fa-play"></i>':'<i class="fa-solid fa-pause"></i>'
}
el.pause?.addEventListener('click',()=>togglePause());
el.nextStep?.addEventListener('click',()=>{clearClock();nextStage()});
el.nextTrick?.addEventListener('click',()=>startTrick((trickIndex+1)%trickKeys.length));
el.fullscreen?.addEventListener('click',async()=>{try{if(!document.fullscreenElement)await document.documentElement.requestFullscreen();else await document.exitFullscreen()}catch(e){}});
document.addEventListener('keydown',e=>{if(e.code==='Space'){e.preventDefault();togglePause()}else if(e.key==='ArrowRight')nextStage();else if(e.key.toLowerCase()==='n')startTrick((trickIndex+1)%trickKeys.length);else if(e.key.toLowerCase()==='f')el.fullscreen?.click()});
if(pool.length>=12)startTrick(0);
else{el.headline.textContent='Not enough teams';el.instruction.textContent='Card Magic needs at least 12 teams in the selected deck.'}
})();