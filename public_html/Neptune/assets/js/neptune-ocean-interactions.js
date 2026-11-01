/* Neptune Ocean Interaction — ambient bubble wake, 2026-10-31.
   Responds to the hover of real navigation and content cards; bubbles are
   painted into a shared, noninteractive *background* layer and never into the
   card or its link. Doesn't touch scoring controls or page data. */
(() => {
  'use strict';
  if (!document.body || !document.body.classList.contains('neptune-console')) return;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (motion.matches) return;
  const selectors = [
    '.neptune-hub-card', '.quick-link', '.module-launch-card',
    '.module-card', '.theme-card', '.ops-panel', '.card', '.account-panel'
  ].join(',');
  const container = document.querySelector('main.wrap');
  if (!container) return;
  let layer = null;
  let activeCard = null;
  let emitter = null;
  const maxBubbles = 130;
  const bubbles = new Set();
  const rand = (min, max) => min + Math.random() * (max - min);
  function getLayer() {
    if (layer && layer.isConnected) return layer;
    layer = document.querySelector('body > .nd-depth-field, body > .nc-hover-ocean-field');
    if (!layer) {
      layer = document.createElement('div');
      layer.className = 'nc-hover-ocean-field';
      layer.setAttribute('aria-hidden', 'true');
      document.body.prepend(layer);
    }
    return layer;
  }
  function eligibleCard(target) {
    if (!(target instanceof Element)) return null;
    const card = target.closest(selectors);
    if (!card || !container.contains(card)) return null;
    if (card.closest('.neptune-modal,dialog,[role="dialog"],.nc-standard-hero')) return null;
    if (card.classList.contains('is-locked') || card.matches('[aria-disabled="true"]')) return null;
    return card;
  }
  function bubble(rect) {
    if (bubbles.size >= maxBubbles) return;
    const el = document.createElement('span');
    el.className = 'nc-hover-bubble';
    const margin = Math.min(rect.width * .12, 28);
    // Start across the lower 55% of the card, with a few bubbles adjacent to it.
    // Some bubbles emerge beside the card so the response is visible even
    // when the card's own glass material obscures bubbles behind it.
    const edge = Math.random();
    const xRaw = edge < .25 ? rand(rect.left - 46, rect.left - 9)
      : edge < .50 ? rand(rect.right + 9, rect.right + 46)
      : rand(rect.left - margin, rect.right + margin);
    const x = Math.max(0, Math.min(window.innerWidth, xRaw));
    const y = Math.max(0, Math.min(window.innerHeight, rand(rect.top + rect.height * .12, rect.bottom + 18)));
    const size = rand(4, 17);
    const duration = rand(3.1, 6.2);
    el.style.setProperty('--nc-x', x.toFixed(1) + 'px');
    el.style.setProperty('--nc-y', y.toFixed(1) + 'px');
    el.style.setProperty('--nc-size', size.toFixed(1) + 'px');
    el.style.setProperty('--nc-duration', duration.toFixed(2) + 's');
    el.style.setProperty('--nc-rise', '-' + rand(130, 350).toFixed(1) + 'px');
    el.style.setProperty('--nc-drift', rand(-85, 85).toFixed(1) + 'px');
    el.style.setProperty('--nc-alpha', rand(.40, .72).toFixed(2));
    el.addEventListener('animationend', () => {el.remove(); bubbles.delete(el)}, {once:true});
    bubbles.add(el);
    getLayer().appendChild(el);
  }
  function emit(count) {
    if (!activeCard || !activeCard.isConnected || motion.matches) return;
    const rect = activeCard.getBoundingClientRect();
    if (rect.right <= 0 || rect.left >= window.innerWidth || rect.bottom <= 0 || rect.top >= window.innerHeight) return;
    for (let i = 0; i < count; i++) bubble(rect);
  }
  function stop() {
    if (emitter !== null) {clearInterval(emitter); emitter = null}
    activeCard = null;
    // Bubbles already released finish rising naturally in the background.
  }
  function start(card) {
    if (card === activeCard || motion.matches) return;
    stop();
    activeCard = card;
    emit(window.innerWidth < 700 ? 18 : 38); // dense burst on first hover frame
    emitter = window.setInterval(() => emit(window.innerWidth < 700 ? 3 : 6), 150);
  }
  container.addEventListener('pointerover', event => {
    if (event.pointerType === 'touch') return;
    const next = eligibleCard(event.target);
    if (!next) return;
    if (next.contains(event.relatedTarget)) return;
    start(next);
  });
  container.addEventListener('pointerout', event => {
    if (!activeCard || !activeCard.contains(event.target)) return;
    if (activeCard.contains(event.relatedTarget)) return;
    stop();
  });
  container.addEventListener('focusin', event => {
    const card = eligibleCard(event.target);
    if (card) start(card);
  });
  container.addEventListener('focusout', event => {
    if (activeCard && !activeCard.contains(event.relatedTarget)) stop();
  });
  document.addEventListener('visibilitychange', () => {if (document.hidden) stop()});
  motion.addEventListener?.('change', () => {if (motion.matches) {stop();for(const el of bubbles)el.remove();bubbles.clear()}});
})();
