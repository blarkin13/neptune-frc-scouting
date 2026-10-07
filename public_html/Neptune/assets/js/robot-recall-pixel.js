(() => {
  'use strict';

  const root = document.documentElement;
  const mode = root.dataset.rrPixelMode || 'player';
  const processed = new WeakMap();
  let lastHero = null;

  function candidateInfo(img) {
    if (!(img instanceof HTMLImageElement)) return null;
    const raw = img.getAttribute('src') || '';
    const alt = img.getAttribute('alt') || '';
    if (!raw) return null;

    let u;
    try { u = new URL(raw, location.href); } catch (_) { return null; }

    const lowerPath = u.pathname.toLowerCase();
    const looksLikeLogo =
      lowerPath.includes('robot-recall-logo.php') ||
      lowerPath.includes('/uploads/team-logos/') ||
      /frc\d+\.(png|jpe?g|webp|avif)$/i.test(lowerPath) ||
      /team\s*\d+/i.test(alt);

    if (!looksLikeLogo) return null;
    if (lowerPath.includes('robot-recall-pixel-logo.php')) {
      // Already pixelized; still recover team for sizing/class work.
    }

    let team = 0;
    for (const key of ['team', 'team_number', 'frc_team_number', 'frc']) {
      const v = parseInt(u.searchParams.get(key) || '0', 10);
      if (Number.isInteger(v) && v > 0) { team = v; break; }
    }
    if (!team) {
      const pathMatch = u.pathname.match(/frc(\d{1,5})/i);
      if (pathMatch) team = parseInt(pathMatch[1], 10);
    }
    if (!team) {
      const altMatch = alt.match(/(?:team|frc)?\s*#?\s*(\d{2,5})/i);
      if (altMatch) team = parseInt(altMatch[1], 10);
    }
    if (!team) return null;

    let org = 0;
    for (const key of ['org', 'organization_id']) {
      const v = parseInt(u.searchParams.get(key) || '0', 10);
      if (Number.isInteger(v) && v > 0) { org = v; break; }
    }
    if (!org) {
      const orgMatch = u.pathname.match(/\/org-(\d+)\//i);
      if (orgMatch) org = parseInt(orgMatch[1], 10);
    }

    let prefix = '';
    if (lowerPath.includes('/api/robot-recall-logo.php')) {
      prefix = u.pathname.slice(0, lowerPath.indexOf('/api/robot-recall-logo.php'));
    } else {
      const uploadsAt = lowerPath.indexOf('/uploads/');
      if (uploadsAt >= 0) prefix = u.pathname.slice(0, uploadsAt);
    }

    const endpoint = `${prefix}/api/robot-recall-pixel-logo.php`;
    const q = new URLSearchParams({ team: String(team), size: '768', grid: '18' });
    if (org > 0) q.set('org', String(org));

    return { team, org, url: `${endpoint}?${q.toString()}` };
  }

  function markStage(img) {
    // Find a tight visual wrapper, without styling the whole page/card.
    let node = img.parentElement;
    for (let i = 0; node && i < 4; i++, node = node.parentElement) {
      const rect = node.getBoundingClientRect();
      if (rect.width >= img.getBoundingClientRect().width && rect.width <= Math.max(950, innerWidth * .95)) {
        if (node.childElementCount <= 6) {
          node.classList.add('rr-pixel-stage');
          return node;
        }
      }
    }
    img.parentElement?.classList.add('rr-pixel-stage');
    return img.parentElement;
  }

  function pixelize(img) {
    const info = candidateInfo(img);
    if (!info) return false;

    const previous = processed.get(img);
    if (previous === info.team && img.classList.contains('rr-pixel-logo')) return true;

    processed.set(img, info.team);
    img.classList.add('rr-pixel-logo');
    img.decoding = 'async';

    if (!img.src.includes('robot-recall-pixel-logo.php')) {
      const fallback = img.src;
      img.dataset.rrPixelFallback = fallback;
      img.onerror = () => {
        img.onerror = null;
        if (img.dataset.rrPixelFallback) img.src = img.dataset.rrPixelFallback;
      };
      img.src = info.url;
    }

    const stage = markStage(img);
    if (lastHero !== img) {
      img.classList.remove('rr-pixel-pop');
      // Force animation restart when question changes.
      void img.offsetWidth;
      img.classList.add('rr-pixel-pop');
      lastHero = img;
    }
    if (stage) stage.dataset.rrPixelTeam = String(info.team);
    return true;
  }

  function markAnswerChoices() {
    // The game has four text answers. This intentionally uses several safe
    // heuristics so the add-on works across the existing v1.x layouts.
    const selectors = [
      '[data-answer]', '[data-choice]', '.answer', '.answer-button',
      '.choice', '.choice-button', '.rr-answer', '.robot-recall-answer'
    ];
    const found = new Set();
    selectors.forEach(sel => document.querySelectorAll(sel).forEach(el => found.add(el)));

    if (found.size < 4) {
      // Fallback: visible buttons containing a team number / nickname-like text.
      [...document.querySelectorAll('button, a[role="button"]')].forEach(el => {
        const text = (el.textContent || '').trim();
        if (/^#?\d{2,5}\b/.test(text) || /#\d{2,5}\s*[·\-]/.test(text)) found.add(el);
      });
    }

    [...found].slice(0, 8).forEach(el => el.classList.add('rr-pixel-answer'));
  }

  function scan() {
    document.querySelectorAll('img').forEach(pixelize);
    markAnswerChoices();
  }

  let playerMirrorFrame = null;
  let playerMirrorObserver = null;
  let playerLogoTeam = 0;

  function findQuestionPrompt() {
    const phrase = 'whose logo is on the big screen';
    const nodes = [...document.querySelectorAll('h1,h2,h3,h4,p,div,span')]
      .filter(el => ((el.textContent || '').trim().toLowerCase().includes(phrase)))
      .sort((a, b) => (a.textContent || '').trim().length - (b.textContent || '').trim().length);
    return nodes[0] || null;
  }

  function ensurePlayerLogo(info) {
    if (mode !== 'player' || !info || !info.team) return;
    let slot = document.querySelector('.rr-player-logo-slot');
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'rr-player-logo-slot';
      slot.setAttribute('aria-live', 'polite');
      slot.innerHTML = '<img class="rr-player-logo" alt="Current robot logo">';

      const prompt = findQuestionPrompt();
      if (prompt && prompt.parentElement) {
        prompt.insertAdjacentElement('afterend', slot);
      } else {
        markAnswerChoices();
        const firstAnswer = document.querySelector('.rr-pixel-answer');
        if (firstAnswer && firstAnswer.parentElement) {
          firstAnswer.parentElement.insertBefore(slot, firstAnswer);
        } else {
          document.body.appendChild(slot);
        }
      }
    }

    const img = slot.querySelector('img');
    if (!(img instanceof HTMLImageElement)) return;
    if (playerLogoTeam === info.team && img.src.includes('robot-recall-pixel-logo.php')) return;

    playerLogoTeam = info.team;
    img.alt = `Team ${info.team} logo`;
    img.dataset.rrPixelFallback = '';
    img.src = info.url;
    img.classList.add('rr-pixel-logo', 'rr-pixel-pop');
    slot.dataset.team = String(info.team);
    markStage(img);
  }

  function mirrorFromScreenDocument(doc) {
    if (mode !== 'player' || !doc) return false;
    const candidates = [...doc.querySelectorAll('img')];
    for (const img of candidates) {
      const info = candidateInfo(img);
      if (!info) continue;
      ensurePlayerLogo(info);
      return true;
    }
    return false;
  }

  function startPlayerLogoMirror() {
    if (mode !== 'player' || playerMirrorFrame) return;
    const room = new URLSearchParams(location.search).get('room');
    if (!room) return;

    const frame = document.createElement('iframe');
    frame.className = 'rr-player-logo-mirror';
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.src = `screen.php?room=${encodeURIComponent(room)}&rrMirror=1`;
    document.body.appendChild(frame);
    playerMirrorFrame = frame;

    const connect = () => {
      let doc;
      try { doc = frame.contentDocument; } catch (_) { return; }
      if (!doc) return;
      mirrorFromScreenDocument(doc);
      if (playerMirrorObserver) playerMirrorObserver.disconnect();
      playerMirrorObserver = new MutationObserver(() => mirrorFromScreenDocument(doc));
      playerMirrorObserver.observe(doc.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['src', 'alt', 'class']
      });
      // The display polls live game state. A light fallback poll handles cases
      // where it replaces text/state without mutating the logo element itself.
      const poll = setInterval(() => {
        if (!document.body.contains(frame)) { clearInterval(poll); return; }
        mirrorFromScreenDocument(doc);
      }, 700);
    };
    frame.addEventListener('load', connect);
  }

  let queued = false;
  function queueScan() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      scan();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { scan(); startPlayerLogoMirror(); }, { once: true });
  } else {
    scan();
    startPlayerLogoMirror();
  }

  const observer = new MutationObserver(queueScan);
  observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['src', 'alt', 'class']
  });

})();
