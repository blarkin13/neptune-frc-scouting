/* Neptune ocean background for display pages: inserts the shared layer and rising bubbles (same
   recipe as the landing page). Decorative only; hidden from assistive technology. */
(function () {
  'use strict';
  function start() {
    if (!document.body || !document.body.classList.contains('has-ocean') || document.querySelector('.neptune-ocean')) return;
    var ocean = document.createElement('div');
    ocean.className = 'neptune-ocean';
    ocean.setAttribute('aria-hidden', 'true');
    ocean.innerHTML = '<div class="neptune-ocean__depth"></div><div class="neptune-ocean__rays"></div><div class="neptune-ocean__caustics"></div><div class="neptune-ocean__floor"></div>';
    var count = window.innerWidth < 700 ? 22 : 48;
    for (var n = 0; n < count; n++) {
      var b = document.createElement('i');
      b.className = 'neptune-ocean__bubble';
      b.style.setProperty('--x', (Math.random() * 100).toFixed(2) + '%');
      b.style.setProperty('--duration', (10 + Math.random() * 21).toFixed(2) + 's');
      b.style.setProperty('--delay', (-Math.random() * 32).toFixed(2) + 's');
      b.style.setProperty('--size', (3 + Math.pow(Math.random(), 1.7) * 16).toFixed(1) + 'px');
      b.style.setProperty('--alpha', (.22 + Math.random() * .52).toFixed(2));
      b.style.setProperty('--drift', (Math.random() * 180 - 90).toFixed(1) + 'px');
      ocean.appendChild(b);
    }
    ocean.style.setProperty('--ocean-ray-tilt', (Math.random() * 8 - 4).toFixed(1) + 'deg');
    document.body.insertBefore(ocean, document.body.firstChild);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
