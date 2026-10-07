<?php
$trainingActive = $trainingActive ?? '';
$trainingIsAdmin = isset($u['role']) && in_array($u['role'], ['owner','admin'], true);
$trainingCssPath = dirname(__DIR__).'/assets/css/training-platform.css';
$trainingCssVersion = is_file($trainingCssPath) ? (string)filemtime($trainingCssPath) : '6';
?>
<link rel="stylesheet" href="<?=e(base_url('assets/css/training-platform.css'))?>?v=<?=e($trainingCssVersion)?>">
<nav class="ntp-nav" aria-label="Neptune training navigation">
  <div class="ntp-nav-brand"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span><b>Neptune Training</b><small>Learn · Practice · Certify</small></span></div>
  <div class="ntp-nav-links">
    <a class="<?=$trainingActive==='home'?'active':''?>" href="<?=e(base_url('help/training/'))?>"><i class="fa-solid fa-house"></i> Training Home</a>
    <a class="<?=$trainingActive==='guide'?'active':''?>" href="<?=e(base_url('help/'))?>"><i class="fa-solid fa-book-open"></i> User Guide</a>
    <a class="<?=$trainingActive==='manual'?'active':''?>" href="<?=e(base_url('help/event-training.php'))?>"><i class="fa-solid fa-clipboard-list"></i> Event Manual</a>
    <a class="<?=$trainingActive==='cbt'?'active':''?>" href="<?=e(base_url('help/training/#cbt-courses'))?>"><i class="fa-solid fa-laptop-file"></i> CBT</a>
    <?php if($trainingIsAdmin): ?><a class="<?=$trainingActive==='records'?'active':''?>" href="<?=e(base_url('help/training/records.php'))?>"><i class="fa-solid fa-chart-column"></i> Records</a><?php endif; ?>
  </div>
</nav>

<div class="ntp-image-modal" id="ntpTrainingImageModal" aria-hidden="true">
  <div class="ntp-image-backdrop" data-training-image-close></div>
  <section class="ntp-image-dialog" role="dialog" aria-modal="true" aria-labelledby="ntpTrainingImageTitle" tabindex="-1">
    <div class="ntp-image-head">
      <strong id="ntpTrainingImageTitle">Training screenshot</strong>
      <button type="button" class="ntp-image-close" data-training-image-close aria-label="Close screenshot"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="ntp-image-stage"><img src="" alt="" id="ntpTrainingImageFull"></div>
  </section>
</div>
<script>
(()=>{
  const modal=document.getElementById('ntpTrainingImageModal');
  if(!modal)return;
  const dialog=modal.querySelector('.ntp-image-dialog');
  const img=document.getElementById('ntpTrainingImageFull');
  const title=document.getElementById('ntpTrainingImageTitle');
  let returnFocus=null;
  function close(){
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('ntp-image-open');
    img.removeAttribute('src');
    if(returnFocus&&typeof returnFocus.focus==='function')returnFocus.focus({preventScroll:true});
  }
  document.addEventListener('click',e=>{
    const open=e.target.closest('[data-training-image]');
    if(open){
      e.preventDefault();
      returnFocus=open;
      img.src=open.getAttribute('data-training-image')||'';
      img.alt=open.querySelector('img')?.alt||'';
      title.textContent=open.getAttribute('data-training-title')||'Training screenshot';
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden','false');
      document.body.classList.add('ntp-image-open');
      requestAnimationFrame(()=>dialog?.focus({preventScroll:true}));
      return;
    }
    if(e.target.closest('[data-training-image-close]'))close();
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))close();});
})();
</script>
