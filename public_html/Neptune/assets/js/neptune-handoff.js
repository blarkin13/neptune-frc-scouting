/* Neptune scout hand-off (tablet side).
   Gathers this scout's unsent scouting from the three local queues, signs each entry, and
   shows it as QR codes (or a file) for a lead device to import. Nothing is removed from the
   tablet until the scout types the confirmation code the lead's import page shows, or the
   tablet syncs normally. Needs window.NeptuneHandoffConfig and window.NeptuneQRFull. */
(() => {
  'use strict';
  const cfg = window.NeptuneHandoffConfig;
  const root = document.getElementById('neptuneHandoff');
  if (!cfg || !root) return;

  const CHUNK = 600; // base45 characters per code: QR version ~16-M, easy for cameras and handheld scanners
  const B45 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
  const SOURCES = {
    guard: { db: 'neptune-scout-resilience', store: 'pending-actions' },
    matchTag: { db: 'neptune-impact-scouting', store: 'impact_states' },
    pit: { db: 'neptune-tag-pit-alliance-v1', store: 'submissions' },
    ranking: { db: 'neptune-robot-rankings', store: 'pending' }
  };

  // ---- SHA-256 / HMAC (pure JS: crypto.subtle is unavailable over plain-http LAN servers) ----
  const K = new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
  function sha256(bytes) {
    const l = bytes.length, withPad = ((l + 9 + 63) >> 6) << 6;
    const m = new Uint8Array(withPad); m.set(bytes); m[l] = 0x80;
    const dv = new DataView(m.buffer); dv.setUint32(withPad - 4, l * 8); dv.setUint32(withPad - 8, Math.floor(l / 0x20000000));
    const h = new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);
    const w = new Uint32Array(64);
    const r = (x, n) => (x >>> n) | (x << (32 - n));
    for (let o = 0; o < withPad; o += 64) {
      for (let i = 0; i < 16; i++) w[i] = dv.getUint32(o + i * 4);
      for (let i = 16; i < 64; i++) {
        const s0 = r(w[i-15], 7) ^ r(w[i-15], 18) ^ (w[i-15] >>> 3), s1 = r(w[i-2], 17) ^ r(w[i-2], 19) ^ (w[i-2] >>> 10);
        w[i] = (w[i-16] + s0 + w[i-7] + s1) | 0;
      }
      let [a, b, c, d, e, f, g, hh] = h;
      for (let i = 0; i < 64; i++) {
        const t1 = (hh + (r(e, 6) ^ r(e, 11) ^ r(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + w[i]) | 0;
        const t2 = ((r(a, 2) ^ r(a, 13) ^ r(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        hh = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h[0] += a; h[1] += b; h[2] += c; h[3] += d; h[4] += e; h[5] += f; h[6] += g; h[7] += hh;
    }
    const out = new Uint8Array(32); const odv = new DataView(out.buffer);
    h.forEach((v, i) => odv.setUint32(i * 4, v));
    return out;
  }
  const enc = new TextEncoder();
  const hex = b => Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
  function hmacHex(keyText, msg) {
    let key = enc.encode(keyText); if (key.length > 64) key = sha256(key);
    const k = new Uint8Array(64); k.set(key);
    const ipad = k.map(x => x ^ 0x36), opad = k.map(x => x ^ 0x5c);
    const m = enc.encode(msg);
    const inner = new Uint8Array(64 + m.length); inner.set(ipad); inner.set(m, 64);
    const outer = new Uint8Array(96); outer.set(opad); outer.set(sha256(inner), 64);
    return hex(sha256(outer));
  }
  const sign = (kind, id, data) => hmacHex(cfg.key, `${cfg.orgId}|${cfg.userId}|${kind}|${id}|${data}`).slice(0, 32);
  const confirmationFor = bundleId => hmacHex(cfg.key, 'confirm|' + bundleId).slice(0, 6).toUpperCase();

  function base45(bytes) {
    let s = '';
    for (let i = 0; i < bytes.length; i += 2) {
      if (i + 1 < bytes.length) { const n = bytes[i] * 256 + bytes[i + 1]; s += B45[n % 45] + B45[Math.floor(n / 45) % 45] + B45[Math.floor(n / 2025)]; }
      else { const n = bytes[i]; s += B45[n % 45] + B45[Math.floor(n / 45)]; }
    }
    return s;
  }
  async function pack(json) {
    const raw = enc.encode(json);
    if (typeof CompressionStream === 'function') {
      try {
        const z = new Uint8Array(await new Response(new Blob([raw]).stream().pipeThrough(new CompressionStream('deflate-raw'))).arrayBuffer());
        const out = new Uint8Array(z.length + 1); out[0] = 0x5A; out.set(z, 1); return out; // 'Z'
      } catch (_) { /* fall through to uncompressed */ }
    }
    const out = new Uint8Array(raw.length + 1); out[0] = 0x4A; out.set(raw, 1); return out; // 'J'
  }
  function bundleId() {
    const b = new Uint8Array(8); crypto.getRandomValues(b);
    return Array.from(b, x => '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'[x % 34]).join('');
  }

  // ---- Local queues (read without ever creating or upgrading another page's database) ----
  function openExisting(name) {
    return new Promise(resolve => {
      if (!window.indexedDB) return resolve(null);
      let req;
      try { req = indexedDB.open(name); } catch (_) { return resolve(null); }
      req.onupgradeneeded = () => { try { req.transaction.abort(); } catch (_) {} }; // it did not exist
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    });
  }
  async function readAll(src) {
    const db = await openExisting(src.db);
    if (!db) return [];
    try {
      if (!db.objectStoreNames.contains(src.store)) return [];
      return await new Promise(resolve => {
        const r = db.transaction(src.store, 'readonly').objectStore(src.store).getAll();
        r.onsuccess = () => resolve(r.result || []); r.onerror = () => resolve([]);
      });
    } finally { db.close(); }
  }
  async function writeEach(src, fn) {
    const db = await openExisting(src.db);
    if (!db) return;
    try {
      if (!db.objectStoreNames.contains(src.store)) return;
      await new Promise((resolve, reject) => {
        const tx = db.transaction(src.store, 'readwrite'); fn(tx.objectStore(src.store));
        tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); tx.onabort = () => reject(tx.error);
      });
    } finally { db.close(); }
  }
  const dec = new TextDecoder();
  async function collect() {
    const items = []; let media = 0;
    for (const it of await readAll(SOURCES.guard)) {
      if (it.blocked || !it.body) continue;
      let body; try { body = JSON.parse(dec.decode(it.body)); } catch (_) { continue; }
      const d = { session_id: body.session_id, action: { code: body.action && body.action.code }, result: body.result, match_time_sec: body.match_time_sec };
      items.push({ k: 'a', id: it.requestUuid, d: JSON.stringify(d), clear: { src: 'guard', key: it.requestUuid } });
    }
    for (const r of await readAll(SOURCES.matchTag)) {
      if (!r || !r.dirty || Number(r.orgId) !== Number(cfg.orgId) || Number(r.userId) !== Number(cfg.userId)) continue;
      const d = { event_id: r.eventId, match_id: r.matchId, team: r.team, scoring_contribution: r.scoring_contribution, tags: r.tags || [], tag_weights: r.tag_weights || {}, note: r.note || '' };
      items.push({ k: 'm', id: String(r.key), d: JSON.stringify(d), clear: { src: 'matchTag', key: r.key, updatedAt: r.updatedAt } });
    }
    const scope = cfg.orgId + ':' + cfg.userId;
    for (const x of await readAll(SOURCES.pit)) {
      if (!x || x.scope !== scope) continue;
      if (Array.isArray(x.files) && x.files.length) { media++; continue; } // photos/videos need a connection
      const d = { context: x.context, entry_mode: x.entry_mode, event_id: x.event_id, frc_team_number: x.frc_team_number, evidence_source: x.evidence_source, note: x.note || '', tag_ids: x.tag_ids || [] };
      items.push({ k: 'o', id: String(x.uuid), d: JSON.stringify(d), clear: { src: 'pit', key: x.key } });
    }
    for (const r of await readAll(SOURCES.ranking)) {
      if (!r || r.blocked || Number(r.orgId) !== Number(cfg.orgId) || Number(r.userId) !== Number(cfg.userId)) continue;
      const d = { uuid: r.uuid, match_id: r.matchId, alliance: r.alliance, quality: r.quality, groups: r.groups };
      items.push({ k: 'r', id: String(r.uuid), d: JSON.stringify(d), clear: { src: 'ranking', key: r.uuid } });
    }
    return { items, media };
  }

  async function buildBundle() {
    const { items, media } = await collect();
    const id = bundleId();
    const payload = { v: 1, b: id, o: cfg.orgId, u: cfg.userId, n: cfg.name, t: Math.floor(Date.now() / 1000), media,
      items: items.map(i => ({ k: i.k, id: i.id, d: i.d, s: sign(i.k, i.id, i.d) })) };
    const text = base45(await pack(JSON.stringify(payload)));
    const parts = Math.max(1, Math.ceil(text.length / CHUNK));
    const codes = [];
    for (let p = 0; p < parts; p++) codes.push(`NH1/${id}/${p + 1}/${parts}/${text.slice(p * CHUNK, (p + 1) * CHUNK)}`);
    return { id, codes, items, media };
  }

  // ---- Footer widget ----
  root.innerHTML = '<div class="nho-bar"><button type="button" class="secondary nho-open" disabled><i class="fa-solid fa-qrcode"></i> <span>Checking unsent data…</span></button>' +
    '<small class="nho-hint">Hand-off QR: if this tablet cannot reach Neptune, show these codes to your lead.</small></div>';
  const openBtn = root.querySelector('.nho-open');
  const label = openBtn.querySelector('span');
  async function refresh() {
    if (!cfg.key) { label.textContent = 'Hand-off QR unavailable: your account needs a Neptune password'; openBtn.disabled = true; return; }
    try {
      const { items, media } = await collect();
      openBtn.disabled = items.length === 0;
      label.textContent = items.length ? `Show hand-off QR (${items.length} unsent)` : (media ? `Nothing to hand off · ${media} with photos wait for a connection` : 'Nothing waiting · all sent');
    } catch (_) { label.textContent = 'Hand-off QR: local storage unavailable'; }
  }
  refresh(); setInterval(refresh, 5000);

  let modal = null, current = null, index = 0, timer = null;
  function el(tag, cls, html) { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function render() {
    const canvas = modal.querySelector('canvas');
    const side = Math.min(window.innerWidth - 40, window.innerHeight - 260, 720);
    window.NeptuneQRFull.draw(canvas, current.codes[index], { ecl: 'M', quiet: 4, maxPx: Math.max(240, side) });
    canvas.style.width = canvas.style.height = Math.max(240, side) + 'px';
    modal.querySelector('.nho-part').textContent = current.codes.length > 1 ? `Part ${index + 1} of ${current.codes.length}` : 'Single code';
    modal.querySelector('.nho-prev').disabled = modal.querySelector('.nho-next').disabled = current.codes.length < 2;
  }
  function setAuto(on) {
    clearInterval(timer); timer = null;
    const btn = modal.querySelector('.nho-auto');
    btn.innerHTML = on ? '<i class="fa-solid fa-pause"></i> Pause' : '<i class="fa-solid fa-play"></i> Cycle';
    if (on && current.codes.length > 1) timer = setInterval(() => { index = (index + 1) % current.codes.length; render(); }, 1600);
  }
  function close() { clearInterval(timer); timer = null; modal?.remove(); modal = null; document.body.style.overflow = ''; refresh(); }
  async function clearHandedOff() {
    const byKind = { guard: [], matchTag: [], pit: [], ranking: [] };
    current.items.forEach(i => byKind[i.clear.src].push(i.clear));
    if (byKind.guard.length) await writeEach(SOURCES.guard, s => byKind.guard.forEach(c => s.delete(c.key)));
    if (byKind.pit.length) await writeEach(SOURCES.pit, s => byKind.pit.forEach(c => s.delete(c.key)));
    if (byKind.ranking.length) await writeEach(SOURCES.ranking, s => byKind.ranking.forEach(c => s.delete(c.key)));
    if (byKind.matchTag.length) await writeEach(SOURCES.matchTag, s => byKind.matchTag.forEach(c => {
      const r = s.get(c.key);
      r.onsuccess = () => { const rec = r.result; if (rec && rec.dirty && Number(rec.updatedAt) === Number(c.updatedAt)) { rec.dirty = false; s.put(rec); } }; // a newer edit stays unsent
    }));
  }
  function saveFile() {
    const lines = [`# Neptune scout hand-off for ${cfg.name} · ${new Date().toLocaleString()} · ${current.items.length} entries`, ...current.codes];
    const a = el('a'); a.href = URL.createObjectURL(new Blob([lines.join('\n') + '\n'], { type: 'text/plain' }));
    a.download = `neptune-handoff-${current.id}.txt`; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  }
  async function open() {
    openBtn.disabled = true;
    try { current = await buildBundle(); } catch (e) { label.textContent = 'Could not build hand-off: ' + (e.message || e); openBtn.disabled = false; return; }
    if (!current.items.length) { refresh(); return; }
    index = 0;
    modal = el('div', 'nho-modal');
    modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', 'Hand-off QR codes');
    modal.innerHTML = `<div class="nho-sheet">
      <div class="nho-head"><div><b>Hand-off for ${cfg.name.replace(/[<>&"]/g, '')}</b><small>${current.items.length} unsent entr${current.items.length === 1 ? 'y' : 'ies'}${current.media ? ` · ${current.media} with photos stay on this tablet` : ''}</small></div>
        <button type="button" class="secondary compact nho-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="nho-qr"><canvas></canvas></div>
      <div class="nho-part"></div>
      <div class="nho-actions">
        <button type="button" class="secondary nho-prev"><i class="fa-solid fa-chevron-left"></i> Prev</button>
        <button type="button" class="secondary nho-auto"></button>
        <button type="button" class="secondary nho-next">Next <i class="fa-solid fa-chevron-right"></i></button>
      </div>
      <div class="nho-actions">
        <button type="button" class="secondary nho-file"><i class="fa-solid fa-file-arrow-down"></i> Save as file</button>
        <button type="button" class="good nho-confirm"><i class="fa-solid fa-check"></i> Lead confirmed received</button>
      </div>
      <form class="nho-confirm-form" hidden>
        <label>Confirmation code from the lead's screen <input type="text" maxlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false"></label>
        <button type="submit" class="good">Clear from this tablet</button>
        <small class="nho-msg"></small>
      </form>
      <p class="nho-note">The lead opens <b>Import hand-off</b> on a connected device and scans every part, in any order. Nothing leaves this tablet until you enter the lead's confirmation code.</p>
    </div>`;
    document.body.appendChild(modal); document.body.style.overflow = 'hidden';
    modal.querySelector('.nho-close').onclick = close;
    modal.querySelector('.nho-prev').onclick = () => { setAuto(false); index = (index - 1 + current.codes.length) % current.codes.length; render(); };
    modal.querySelector('.nho-next').onclick = () => { setAuto(false); index = (index + 1) % current.codes.length; render(); };
    modal.querySelector('.nho-auto').onclick = () => setAuto(!timer);
    modal.querySelector('.nho-file').onclick = saveFile;
    const form = modal.querySelector('.nho-confirm-form');
    modal.querySelector('.nho-confirm').onclick = () => { form.hidden = false; form.querySelector('input').focus(); };
    form.onsubmit = async ev => {
      ev.preventDefault();
      const msg = form.querySelector('.nho-msg');
      if (form.querySelector('input').value.trim().toUpperCase() !== confirmationFor(current.id)) { msg.textContent = 'That code does not match this hand-off. Check the lead\'s screen.'; return; }
      try { await clearHandedOff(); msg.textContent = 'Cleared. These entries are now on the server.'; setTimeout(close, 1200); }
      catch (e) { msg.textContent = 'Could not clear: ' + (e.message || e); }
    };
    render(); setAuto(current.codes.length > 1);
    openBtn.disabled = false;
  }
  openBtn.addEventListener('click', open);
  window.addEventListener('resize', () => { if (modal) render(); });
  document.addEventListener('keydown', e => { if (modal && e.key === 'Escape') close(); });
})();
