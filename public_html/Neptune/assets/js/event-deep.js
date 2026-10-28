/* Explore the deep: rotates the feature chapters on large displays. Without JavaScript, on narrow screens
   and for reduced motion, every chapter is shown in full. */
(function () {
  'use strict';
  var DWELL = 9000;
  function init() {
    var root = document.querySelector('[data-deep-chapters]');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
    var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
    var narrow = window.matchMedia('(max-width: 760px)');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var current = 0, timer = null, paused = false;
    function stop() { if (timer) { clearTimeout(timer); timer = null; } root.classList.remove('is-running'); }
    function schedule() {
      stop();
      if (paused || reduce.matches || narrow.matches) return;
      void root.offsetWidth;   // restart the progress animation
      root.classList.add('is-running');
      timer = setTimeout(function () { show((current + 1) % tabs.length, false); }, DWELL);
    }
    function show(n, focus) {
      current = n;
      tabs.forEach(function (t, i) {
        var on = i === n;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        panels[i].hidden = !on;
      });
      if (focus) tabs[n].focus();
      schedule();
    }
    function layout() {
      if (narrow.matches) {   // stacked: every chapter visible, no tabs
        stop();
        root.classList.add('is-stacked');
        panels.forEach(function (p) { p.hidden = false; });
      } else {
        root.classList.remove('is-stacked');
        show(current, false);
      }
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { show(i, false); });
      t.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
          e.preventDefault();
          show((i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length, true);
        }
      });
    });
    root.addEventListener('mouseenter', function () { paused = true; stop(); });
    root.addEventListener('mouseleave', function () { paused = false; schedule(); });
    root.addEventListener('focusin', function () { paused = true; stop(); });
    root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) { paused = false; schedule(); } });
    root.style.setProperty('--deep-dwell', DWELL + 'ms');
    root.classList.add('is-enhanced');
    if (narrow.addEventListener) { narrow.addEventListener('change', layout); reduce.addEventListener('change', layout); }
    layout();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
