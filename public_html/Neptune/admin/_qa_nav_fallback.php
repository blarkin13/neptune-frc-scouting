<?php
/** Safety net for QA pages: if the shared footer script did not wire the header menu button, wire it here.
 *  Self-test: click once; if the menu reacted the shared script is working and the click is undone. */
?>
<script>
(function(){
  function wire(){
    var t=document.getElementById('mobileMenuToggle'),n=document.getElementById('mainNav');
    if(!t||!n)return;
    var before=n.classList.contains('mobile-open');
    t.click();
    if(n.classList.contains('mobile-open')!==before){t.click();return;}
    function set(o){n.classList.toggle('mobile-open',o);t.setAttribute('aria-expanded',o?'true':'false');t.setAttribute('aria-label',o?'Close navigation menu':'Open navigation menu');}
    t.addEventListener('click',function(){set(!n.classList.contains('mobile-open'));});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')set(false);});
    document.addEventListener('click',function(e){if(n.classList.contains('mobile-open')&&!n.contains(e.target)&&!t.contains(e.target))set(false);});
  }
  if(document.readyState==='complete')setTimeout(wire,300);else window.addEventListener('load',function(){setTimeout(wire,300);},{once:true});
})();
</script>
