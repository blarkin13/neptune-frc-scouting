/* Connection Guard QA module, tester side.
   Turns on the Connection Guard ledger for this browser, then every few seconds reads the
   ledger (localStorage) and the device queue (IndexedDB) that the scouting window writes,
   and reports them to api/qa-guard-report.php. Same origin, so both windows share storage. */
(() => {
  const cfg = window.NeptuneQaGuardConfig;
  if (!cfg) return;
  const LEDGER_KEY = 'neptune-guard-ledger-v1';
  const LEDGER_ON = 'neptune-guard-ledger-on';
  const statusEl = document.getElementById('qaGuardStatus');
  const say = text => { if (statusEl) statusEl.textContent = text; };

  let ledgerOn = false;
  try { localStorage.setItem(LEDGER_ON, '1'); ledgerOn = localStorage.getItem(LEDGER_ON) === '1'; } catch (_) {}

  // Same name, version and upgrade schema as Connection Guard, so opening it here can never
  // create the database differently from the scouting window.
  function readQueue() {
    return new Promise(resolve => {
      if (!window.indexedDB) return resolve({ ok: false, items: [] });
      let req;
      try { req = indexedDB.open('neptune-scout-resilience', 1); } catch (_) { return resolve({ ok: false, items: [] }); }
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('pending-actions')) {
          const store = db.createObjectStore('pending-actions', { keyPath: 'requestUuid' });
          store.createIndex('createdAt', 'createdAt', { unique: false });
        }
      };
      req.onerror = () => resolve({ ok: false, items: [] });
      req.onsuccess = () => {
        const db = req.result;
        try {
          const all = db.transaction('pending-actions', 'readonly').objectStore('pending-actions').getAll();
          all.onsuccess = () => { db.close(); resolve({ ok: true, items: all.result || [] }); };
          all.onerror = () => { db.close(); resolve({ ok: false, items: [] }); };
        } catch (_) { db.close(); resolve({ ok: false, items: [] }); }
      };
    });
  }

  function readLedger() {
    try { return JSON.parse(localStorage.getItem(LEDGER_KEY) || '[]'); } catch (_) { return []; }
  }

  async function report() {
    if (!navigator.onLine) { say('This device is offline. The ledger is kept and will be reported when the connection returns.'); return; }
    const queue = await readQueue();
    const since = Date.parse(cfg.runCreatedUtc) - 3600 * 1000;
    const ledger = readLedger().filter(e => !since || Date.parse(e.created_at) >= since).slice(-1000);
    const body = new FormData();
    body.append('run_id', String(cfg.runId));
    body.append('csrf', cfg.csrf);
    body.append('report', JSON.stringify({
      device: navigator.userAgent,
      ledger_on: ledgerOn,
      indexeddb: queue.ok,
      ledger,
      pending: queue.items.map(i => ({ request_uuid: i.requestUuid, blocked: !!i.blocked, needs_auth: !!i.needsAuth, last_error: i.lastError || '' })),
    }));
    try {
      const res = await fetch(cfg.reportUrl, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.status === 'success') {
        say(`Ledger reported: ${data.ledger ?? ledger.length} action(s) sent, ${data.pending ?? queue.items.length} waiting on this device.` +
          (queue.ok ? '' : ' Warning: this browser has no IndexedDB (private window?), so Connection Guard cannot protect actions.'));
      } else {
        say(data.message || 'The ledger could not be reported.');
      }
    } catch (_) {
      say('The ledger could not be reported yet; retrying.');
    }
  }

  if (!ledgerOn) say('This browser blocked local storage, so the Connection Guard ledger cannot run. Use a normal (not private) window.');
  report();
  setInterval(report, 5000);
  window.addEventListener('online', report);
})();
