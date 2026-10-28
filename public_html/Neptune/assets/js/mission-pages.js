/* Mission atmosphere fallback for Admin/Community pages without a recognized
 * hero. Server-generated heroes install their own identical bubble layer.
 * The main hub installs its own nd-ocean; never stack another ocean on it. */
(() => {
  'use strict';
  function init() {
    if (!document.querySelector('main.wrap')) return;
    if (document.querySelector('body > .nd-depth-field, .nd-ocean, .neptune-ocean')) return;
    const root = document.createElement('div');
    root.className = 'nd-depth-field';
    root.setAttribute('aria-hidden', 'true');
    const rays = document.createElement('div');
    rays.className = 'nd-depth-rays';
    root.appendChild(rays);
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) {
      const mobile = window.matchMedia && window.matchMedia('(max-width:640px)').matches;
      const count = mobile ? 15 : 32;
      const fragment = document.createDocumentFragment();
      for (let i = 0; i < count; i++) {
        const bubble = document.createElement('span');
        bubble.className = 'nd-depth-bubble';
        bubble.style.setProperty('--nd-x', (Math.random() * 100).toFixed(1) + '%');
        bubble.style.setProperty('--nd-size', (4 + Math.random() * 13).toFixed(1) + 'px');
        bubble.style.setProperty('--nd-time', (12 + Math.random() * 19).toFixed(1) + 's');
        bubble.style.setProperty('--nd-delay', (-Math.random() * 31).toFixed(1) + 's');
        bubble.style.setProperty('--nd-drift', ((Math.random() - .5) * 125).toFixed(1) + 'px');
        bubble.style.setProperty('--nd-alpha', (.32 + Math.random() * .29).toFixed(2));
        fragment.appendChild(bubble);
      }
      root.appendChild(fragment);
    }
    document.body.prepend(root);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
