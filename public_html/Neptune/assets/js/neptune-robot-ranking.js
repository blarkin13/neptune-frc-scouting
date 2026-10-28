/* Neptune robot rankings (any scout): order one alliance's robots on Driving and Defense.
   Saved on the device first (IndexedDB), then sent to api/robot-ranking.php; anything not
   yet sent is included in the hand-off QR. Needs window.NeptuneRankingConfig. */
(() => {
  'use strict';
  const cfg = window.NeptuneRankingConfig;
  const root = document.getElementById('neptuneRanking');
  if (!cfg || !root) return;
  const DB = 'neptune-robot-rankings', STORE = 'pending';
  const QUALITIES = [
    { key: 'driving', label: 'Driving', hint: 'Control, awareness, avoiding collisions and penalties.' },
    { key: 'defense', label: 'Defense', hint: 'Rank only robots that played defense. Leave the rest blank.' }
  ];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function uuid() {
    if (window.crypto && crypto.randomUUID) { try { return crypto.randomUUID(); } catch (_) {} }
    const b = new Uint8Array(16); crypto.getRandomValues(b); b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  // ---- Local queue ----
  function openDb() {
    return new Promise((resolve, reject) => {
      if (!window.indexedDB) return reject(new Error('This browser cannot save offline.'));
      const r = indexedDB.open(DB, 1);
      r.onupgradeneeded = () => { if (!r.result.objectStoreNames.contains(STORE)) r.result.createObjectStore(STORE, { keyPath: 'uuid' }); };
      r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error);
    });
  }
  async function tx(mode, fn) {
    const db = await openDb();
    try {
      return await new Promise((resolve, reject) => {
        const t = db.transaction(STORE, mode); let out; const s = t.objectStore(STORE);
        const req = fn(s); if (req) req.onsuccess = () => { out = req.result; };
        t.oncomplete = () => resolve(out); t.onerror = () => reject(t.error); t.onabort = () => reject(t.error);
      });
    } finally { db.close(); }
  }
  const allPending = async () => ((await tx('readonly', s => s.getAll())) || []).filter(x => Number(x.orgId) === Number(cfg.orgId) && Number(x.userId) === Number(cfg.userId));

  let syncing = false;
  async function sync() {
    if (syncing) return; syncing = true;
    try {
      for (const item of await allPending()) {
        if (item.blocked) continue;
        let res, data = {};
        try {
          res = await fetch(cfg.api, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'NeptuneRanking' },
            body: JSON.stringify({ uuid: item.uuid, match_id: item.matchId, alliance: item.alliance, quality: item.quality, groups: item.groups }) });
          data = await res.json().catch(() => ({}));
        } catch (_) { break; } // offline: keep everything for later or for the hand-off QR
        if (res.ok && data.ok) await tx('readwrite', s => s.delete(item.uuid));
        else if (res.status === 422) { item.blocked = true; item.error = data.message || 'Rejected'; await tx('readwrite', s => s.put(item)); }
        else break;
      }
    } finally { syncing = false; refresh(); }
  }

  // ---- Footer button ----
  root.innerHTML = `<div class="nrr-bar"><button type="button" class="secondary nrr-open"><i class="fa-solid fa-ranking-star"></i> Rank robots: Driving · Defense</button>
    <small class="nrr-status"></small></div>`;
  const status = root.querySelector('.nrr-status');
  async function refresh() {
    try {
      const mine = (await allPending()).filter(x => Number(x.matchId) === Number(cfg.matchId));
      const waiting = mine.filter(x => !x.blocked).length, blocked = mine.filter(x => x.blocked);
      const saved = Object.values(cfg.mine || {}).reduce((n, q) => n + Object.keys(q || {}).length, 0);
      status.textContent = blocked.length ? `Not accepted: ${blocked[0].error}` : waiting ? `${waiting} ranking(s) saved on this device, sending when connected` : (saved ? 'Your rankings for this match are saved' : 'Optional: compare the robots on one alliance');
    } catch (_) { status.textContent = ''; }
  }

  // ---- Dialog ----
  let modal = null, alliance = cfg.alliance || 'Red';
  const ranks = {}; // ranks[alliance][quality][team] = 1..3
  function teamsFor(a) { return cfg.teams.filter(t => t.alliance === a).sort((x, y) => x.station - y.station); }
  function groupsToRanks(groups) { const r = {}; (groups || []).forEach((g, i) => g.forEach(t => { r[t] = i + 1; })); return r; }
  function ranksToGroups(r) {
    const by = {}; Object.entries(r).forEach(([t, k]) => { if (k) (by[k] = by[k] || []).push(Number(t)); });
    return Object.keys(by).map(Number).sort((a, b) => a - b).map(k => by[k].sort((a, b) => a - b));
  }
  async function prefill() {
    for (const a of ['Red', 'Blue']) { ranks[a] = {}; for (const q of QUALITIES) ranks[a][q.key] = groupsToRanks(cfg.mine?.[a]?.[q.key]); }
    for (const p of (await allPending().catch(() => [])).filter(x => Number(x.matchId) === Number(cfg.matchId)).sort((x, y) => x.createdAt - y.createdAt))
      ranks[p.alliance][p.quality] = groupsToRanks(p.groups);
  }
  function render() {
    const body = modal.querySelector('.nrr-body');
    body.innerHTML = QUALITIES.map(q => `<section class="nrr-quality"><h3>${q.label}</h3><p class="nrr-hint">${q.hint}</p>
      ${teamsFor(alliance).map(t => `<div class="nrr-row"><div class="nrr-team"><b>${t.team}</b><small>${esc(t.nickname)}</small></div>
        <div class="nrr-picks">${[1, 2, 3].map(k => `<button type="button" data-q="${q.key}" data-t="${t.team}" data-k="${k}" aria-pressed="${ranks[alliance][q.key][t.team] === k}" class="${ranks[alliance][q.key][t.team] === k ? 'on' : ''}">${['1st', '2nd', '3rd'][k - 1]}</button>`).join('')}</div></div>`).join('')}
      </section>`).join('');
    modal.querySelectorAll('[data-alliance]').forEach(b => { b.setAttribute('aria-pressed', String(b.dataset.alliance === alliance)); b.classList.toggle('on', b.dataset.alliance === alliance); });
    body.querySelectorAll('.nrr-picks button').forEach(b => b.onclick = () => {
      const r = ranks[alliance][b.dataset.q], t = b.dataset.t, k = Number(b.dataset.k);
      r[t] = r[t] === k ? 0 : k; render();
    });
  }
  async function save() {
    const msg = modal.querySelector('.nrr-msg');
    const items = [];
    for (const a of ['Red', 'Blue']) for (const q of QUALITIES) { // both alliances, if the scout ranked both
      const groups = ranksToGroups(ranks[a][q.key]);
      const count = groups.reduce((n, g) => n + g.length, 0);
      if (count === 1) { msg.textContent = `${a} ${q.label}: rank at least two robots, or clear it.`; return; }
      if (count >= 2) items.push({ uuid: uuid(), orgId: cfg.orgId, userId: cfg.userId, matchId: cfg.matchId, alliance: a, quality: q.key, groups, createdAt: Date.now() });
    }
    if (!items.length) { msg.textContent = 'Nothing ranked yet.'; return; }
    try {
      const old = (await allPending()).filter(x => Number(x.matchId) === Number(cfg.matchId) && items.some(i => i.quality === x.quality && i.alliance === x.alliance));
      await tx('readwrite', s => { old.forEach(o => s.delete(o.uuid)); items.forEach(i => s.put(i)); });
      items.forEach(i => { cfg.mine = cfg.mine || {}; (cfg.mine[i.alliance] = cfg.mine[i.alliance] || {})[i.quality] = i.groups; });
      msg.textContent = 'Saved on this device.';
      close(); await sync();
    } catch (e) { msg.textContent = 'Could not save: ' + (e.message || e); }
  }
  function close() { modal?.remove(); modal = null; document.body.style.overflow = ''; }
  async function open() {
    await prefill();
    modal = document.createElement('div'); modal.className = 'nrr-modal';
    modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', 'Rank robots');
    modal.innerHTML = `<div class="nrr-sheet"><div class="nrr-head"><div><b>Rank robots${cfg.matchLabel ? ' · ' + esc(cfg.matchLabel) : ''}</b>
      <small>Same place for two robots = too close to call. Leave a robot blank if you didn't see it.</small></div>
      <button type="button" class="secondary compact nrr-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="nrr-alliances"><button type="button" class="secondary" data-alliance="Red">Red alliance</button><button type="button" class="secondary" data-alliance="Blue">Blue alliance</button></div>
      <div class="nrr-body"></div>
      <div class="nrr-foot"><small class="nrr-msg"></small><button type="button" class="good nrr-save"><i class="fa-solid fa-check"></i> Save ranking</button></div></div>`;
    document.body.appendChild(modal); document.body.style.overflow = 'hidden';
    modal.querySelector('.nrr-close').onclick = close;
    modal.querySelector('.nrr-save').onclick = save;
    modal.querySelectorAll('[data-alliance]').forEach(b => b.onclick = () => { alliance = b.dataset.alliance; render(); });
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    render();
  }
  root.querySelector('.nrr-open').addEventListener('click', open);
  document.addEventListener('keydown', e => { if (modal && e.key === 'Escape') close(); });
  window.addEventListener('online', sync);
  setInterval(sync, 15000);
  refresh(); sync();
})();
