(() => {
  if (window.NeptuneUI) return;

  const icons = {
    good: 'fa-circle-check',
    bad: 'fa-circle-exclamation',
    warn: 'fa-triangle-exclamation',
    info: 'fa-circle-info'
  };

  function host() {
    let el = document.querySelector('.neptune-toast-host');
    if (!el) {
      el = document.createElement('div');
      el.className = 'neptune-toast-host';
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'false');
      document.body.appendChild(el);
    }
    return el;
  }

  function toast(message, type = 'info', options = {}) {
    const kind = ['good', 'bad', 'warn', 'info'].includes(type) ? type : 'info';
    const box = document.createElement('div');
    box.className = `neptune-toast ${kind}`;
    box.setAttribute('role', kind === 'bad' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'neptune-toast-icon';
    icon.innerHTML = `<i class="fa-solid ${icons[kind]}"></i>`;

    const copy = document.createElement('div');
    copy.className = 'neptune-toast-copy';
    if (options.title) {
      const title = document.createElement('b');
      title.textContent = options.title;
      copy.appendChild(title);
    }
    const text = document.createElement('div');
    text.textContent = String(message ?? '');
    copy.appendChild(text);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'neptune-toast-close';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    close.addEventListener('click', () => box.remove());

    box.append(icon, copy, close);
    host().appendChild(box);

    if (!options.persistent) {
      const ms = Number(options.duration || (kind === 'bad' ? 6500 : 4200));
      window.setTimeout(() => box.remove(), Math.max(1500, ms));
    }
    return box;
  }

  function confirmAction(message, options = {}) {
    return new Promise(resolve => {
      const box = toast(message, options.type || 'warn', {
        title: options.title || 'Confirm action',
        persistent: true
      });
      const actions = document.createElement('div');
      actions.className = 'neptune-toast-actions';

      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'secondary';
      cancel.textContent = options.cancelText || 'Cancel';

      const ok = document.createElement('button');
      ok.type = 'button';
      ok.className = options.danger ? 'danger' : '';
      ok.textContent = options.confirmText || 'Continue';

      let settled = false;
      const done = value => {
        if (settled) return;
        settled = true;
        box.remove();
        resolve(value);
      };
      cancel.addEventListener('click', () => done(false));
      ok.addEventListener('click', () => done(true));
      box.querySelector('.neptune-toast-close')?.addEventListener('click', () => done(false), { once: true });
      actions.append(cancel, ok);
      box.appendChild(actions);
      ok.focus({ preventScroll: true });
    });
  }

  function promptAction(message, options = {}) {
    return new Promise(resolve => {
      const box = toast(message, 'info', { title: options.title || 'Enter a value', persistent: true });
      const input = document.createElement('input');
      input.className = 'neptune-toast-input';
      input.value = options.value || '';
      input.placeholder = options.placeholder || '';
      box.appendChild(input);

      const actions = document.createElement('div');
      actions.className = 'neptune-toast-actions';
      const cancel = document.createElement('button');
      cancel.type = 'button'; cancel.className = 'secondary'; cancel.textContent = 'Cancel';
      const ok = document.createElement('button');
      ok.type = 'button'; ok.textContent = options.confirmText || 'Save';
      const done = value => { box.remove(); resolve(value); };
      cancel.addEventListener('click', () => done(null));
      ok.addEventListener('click', () => done(input.value));
      input.addEventListener('keydown', e => { if (e.key === 'Enter') done(input.value); if (e.key === 'Escape') done(null); });
      actions.append(cancel, ok); box.appendChild(actions); input.focus({ preventScroll: true });
    });
  }

  window.NeptuneUI = { toast, confirm: confirmAction, prompt: promptAction };

  // Any legacy alert that survives future page edits becomes a Neptune toast.
  window.alert = message => toast(message, 'bad', { title: 'Neptune' });

  // Pit photo delete: intercept the legacy page handler before it can open browser confirm().
  document.addEventListener('click', async event => {
    const button = event.target.closest?.('[data-delete-pit-photo]');
    if (!button) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const id = Number(button.dataset.deletePitPhoto || 0);
    if (!id) return;
    const ok = await confirmAction('Delete this saved robot photo?', {
      title: 'Delete robot photo', confirmText: 'Delete', danger: true
    });
    if (!ok) return;
    button.disabled = true;
    try {
      const csrf = document.querySelector('input[name="csrf"]')?.value || '';
      const response = await fetch('../api/delete-pit-photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ photo_id: id, csrf })
      });
      const data = await response.json();
      if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Could not delete the photo.');
      button.closest('[data-saved-photo-id]')?.remove();
      const grid = document.querySelector('[data-saved-photo-grid]');
      if (grid && !grid.querySelector('[data-saved-photo-id]')) {
        grid.remove();
        document.querySelector('[data-saved-photo-title]')?.remove();
      }
      toast('Robot photo deleted.', 'good');
    } catch (error) {
      toast(error?.message || 'Could not delete the photo.', 'bad', { title: 'Delete failed' });
      button.disabled = false;
    }
  }, true);

  // Declarative confirmation for forms/buttons without browser confirm() dialogs.
  document.addEventListener('submit', async event => {
    const form = event.target.closest?.('form[data-confirm]');
    if (!form || form.dataset.confirmed === '1') return;
    event.preventDefault();
    const ok = await confirmAction(form.dataset.confirm || 'Continue?', {
      title: form.dataset.confirmTitle || 'Confirm action',
      confirmText: form.dataset.confirmButton || 'Continue',
      danger: form.dataset.confirmDanger === '1'
    });
    if (!ok) return;
    form.dataset.confirmed = '1';
    if (event.submitter?.name) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden'; hidden.name = event.submitter.name; hidden.value = event.submitter.value || '';
      form.appendChild(hidden);
    }
    form.submit();
  }, true);
})();


/* 2026-09-28 EPA terminology -------------------------------------------
   "Public EPA" is an implementation/source distinction, not the UI name.
   Normalize visible UI copy to EPA while leaving database/API identifiers
   such as public_epa untouched. Also handles dynamically-rendered modals. */
(() => {
  const replacements = [
    [/\bPublic EPA Archive\b/g, 'EPA Archive'],
    [/\bPublic EPA Ratings\b/g, 'EPA Ratings'],
    [/\bPublic EPA\b/g, 'EPA'],
    [/\bpublic EPA archive\b/g, 'EPA archive'],
    [/\bpublic EPA ratings\b/g, 'EPA ratings'],
    [/\bpublic EPA\b/g, 'EPA']
  ];

  const normalizeText = value => {
    let out = String(value ?? '');
    for (const [pattern, replacement] of replacements) out = out.replace(pattern, replacement);
    return out;
  };

  const skipTextParent = parent => !parent || ['SCRIPT','STYLE','CODE','PRE','TEXTAREA'].includes(parent.nodeName);

  const normalizeElement = root => {
    if (!root) return;
    if (root.nodeType === Node.TEXT_NODE) {
      if (skipTextParent(root.parentElement)) return;
      const next = normalizeText(root.nodeValue);
      if (next !== root.nodeValue) root.nodeValue = next;
      return;
    }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) return;

    if (root.nodeType === Node.ELEMENT_NODE) {
      for (const attr of ['title','aria-label','placeholder','data-label']) {
        if (!root.hasAttribute?.(attr)) continue;
        const current = root.getAttribute(attr);
        const next = normalizeText(current);
        if (next !== current) root.setAttribute(attr, next);
      }
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      if (skipTextParent(node.parentElement)) continue;
      const next = normalizeText(node.nodeValue);
      if (next !== node.nodeValue) node.nodeValue = next;
    }

    root.querySelectorAll?.('[title],[aria-label],[placeholder],[data-label]').forEach(el => {
      for (const attr of ['title','aria-label','placeholder','data-label']) {
        if (!el.hasAttribute(attr)) continue;
        const current = el.getAttribute(attr);
        const next = normalizeText(current);
        if (next !== current) el.setAttribute(attr, next);
      }
    });
  };

  const run = () => {
    document.title = normalizeText(document.title);
    document.querySelectorAll('meta[name="description"],meta[property^="og:"],meta[name^="twitter:"]').forEach(meta => {
      const current = meta.getAttribute('content');
      if (current) meta.setAttribute('content', normalizeText(current));
    });
    normalizeElement(document.body);

    const observer = new MutationObserver(records => {
      for (const record of records) {
        if (record.type === 'characterData') normalizeElement(record.target);
        for (const node of record.addedNodes) normalizeElement(node);
      }
    });
    observer.observe(document.body, {subtree:true, childList:true, characterData:true});
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run, {once:true});
  else run();
})();

/* Page heroes are composed server-side by partials_hero.php before first paint. */

