<?php
/** Lead page: import scout hand-off QR codes by handheld scanner, camera, paste or file. */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/neptune_secure/bootstrap.php';
$u = require_role(['owner', 'admin', 'strategy']);
$pageTitle = 'Import Hand-off';
$moduleName = 'SATURN';
include dirname(__DIR__) . '/partials_header.php';
?>
<style>
.nhi-page{max-width:980px;margin:0 auto}
.nhi-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
.nhi-tabs button[aria-selected="true"]{outline:2px solid var(--accent)}
.nhi-panel{padding:14px 16px}
.nhi-panel textarea{width:100%;min-height:120px;font-family:ui-monospace,monospace;font-size:.78rem}
.nhi-scan-target{width:100%;font-size:1rem;padding:12px;font-family:ui-monospace,monospace}
.nhi-video{width:100%;max-width:520px;border-radius:10px;background:#000;display:block;margin:8px auto}
.nhi-note{color:var(--muted);font-size:.78rem;margin:6px 0 0}
.nhi-bundles{display:grid;gap:10px;margin-top:14px}
.nhi-bundle{padding:12px 14px}
.nhi-bundle h3{margin:0 0 4px;font-size:1rem;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.nhi-progress{height:8px;border-radius:99px;background:var(--panel2);overflow:hidden;margin:8px 0}
.nhi-progress span{display:block;height:100%;background:var(--accent)}
.nhi-confirm{font-size:2rem;font-weight:900;letter-spacing:.25em;text-align:center;padding:10px;border:2px solid var(--accent);border-radius:12px;margin:10px 0}
.nhi-results{width:100%;border-collapse:collapse;font-size:.78rem;margin-top:8px}
.nhi-results td{padding:5px 6px;border-bottom:1px solid var(--line);vertical-align:top}
.nhi-status-added,.nhi-status-updated{color:#5fc48a;font-weight:800}.nhi-status-already{color:var(--muted);font-weight:800}.nhi-status-rejected{color:#e07a7a;font-weight:800}
</style>
<section class="module-page nhi-page">
<header class="module-page-header">
  <div><div class="module-code">SATURN · HAND-OFF</div><h1>Import Hand-off</h1>
  <p>Bring in scouting from a tablet that could not reach Neptune. On the tablet, the scout taps <b>Show hand-off QR</b> at the bottom of the scouting page. Scan every part, in any order. Entries are saved under the scout who made them; scanning twice never duplicates anything.</p></div>
</header>

<div class="nhi-tabs" role="tablist">
  <button type="button" class="secondary" role="tab" data-tab="scanner" aria-selected="true"><i class="fa-solid fa-barcode"></i> Handheld scanner</button>
  <button type="button" class="secondary" role="tab" data-tab="camera" aria-selected="false"><i class="fa-solid fa-camera"></i> Camera</button>
  <button type="button" class="secondary" role="tab" data-tab="paste" aria-selected="false"><i class="fa-solid fa-paste"></i> Paste or file</button>
</div>

<div class="card nhi-panel" data-panel="scanner">
  <label for="nhiScan"><b>Click here, then scan.</b></label>
  <input id="nhiScan" class="nhi-scan-target" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Waiting for a scan…">
  <p class="nhi-note">USB and Bluetooth QR scanners type the code and press Enter. Keep this box selected while scanning. Works in any browser.</p>
</div>

<div class="card nhi-panel" data-panel="camera" hidden>
  <div id="nhiCamUnsupported" hidden>
    <p><b>Camera scanning isn't available on this page here.</b> Browsers only allow it on secure pages: open this page as <code>http://localhost/…</code> on the server laptop itself, or use HTTPS.</p>
    <p class="nhi-note">Alternatives: a handheld scanner, or read the code with a phone's camera app (Google Lens), copy the text, and use <b>Paste or file</b>.</p>
  </div>
  <div id="nhiCamReady" hidden>
    <button type="button" id="nhiCamStart"><i class="fa-solid fa-video"></i> Start camera</button>
    <video id="nhiVideo" class="nhi-video" playsinline muted hidden></video>
    <p class="nhi-note">Point the camera at the tablet. Codes are read automatically; cycling codes are collected as they appear.</p>
  </div>
</div>

<div class="card nhi-panel" data-panel="paste" hidden>
  <label for="nhiPaste"><b>Paste codes</b> (one per line) or choose a saved hand-off file.</label>
  <textarea id="nhiPaste" spellcheck="false" placeholder="NH1/…"></textarea>
  <div class="toolbar" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" id="nhiPasteGo"><i class="fa-solid fa-file-import"></i> Add pasted codes</button>
    <label class="btn secondary" style="cursor:pointer"><i class="fa-solid fa-file-lines"></i> Choose file<input type="file" id="nhiFile" accept=".txt,text/plain" hidden multiple></label>
  </div>
</div>

<div class="nhi-bundles" id="nhiBundles" aria-live="polite"></div>
</section>
<script>
(() => {
  const API = <?=json_encode(base_url('api/handoff-import.php'))?>;
  const CSRF = <?=json_encode(csrf_token())?>;
  const bundles = new Map(); // id -> {parts, got: Map(part -> line), state, result}
  const box = document.getElementById('nhiBundles');
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function parse(line) {
    const i = line.toUpperCase().indexOf('NH1/');
    if (i < 0) return null;
    const m = line.slice(i).trim().toUpperCase().match(/^NH1\/([0-9A-Z]{8})\/(\d{1,3})\/(\d{1,3})\/(.+)$/s);
    if (!m) return null;
    const part = +m[2], parts = +m[3];
    return part >= 1 && part <= parts ? { id: m[1], part, parts, line: m[0] } : null;
  }
  function add(text) {
    let added = 0;
    for (const raw of String(text).split(/\r?\n/)) {
      const c = parse(raw); if (!c) continue;
      let b = bundles.get(c.id);
      if (!b) { b = { parts: c.parts, got: new Map(), state: 'collecting', result: null }; bundles.set(c.id, b); }
      if (b.state !== 'collecting' || c.parts !== b.parts || b.got.has(c.part)) continue;
      b.got.set(c.part, c.line); added++;
      if (b.got.size === b.parts) submit(c.id, b);
    }
    render();
    return added;
  }
  async function submit(id, b) {
    b.state = 'importing'; render();
    const fd = new FormData(); fd.append('csrf', CSRF); fd.append('codes', [...b.got.values()].join('\n'));
    try {
      const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || d.status !== 'success') { b.state = 'error'; b.result = { message: d.message || ('HTTP ' + r.status) }; }
      else { b.state = 'done'; b.result = d; }
    } catch (e) { b.state = 'error'; b.result = { message: 'Could not reach Neptune: ' + (e.message || e) }; }
    render();
  }
  function render() {
    box.innerHTML = [...bundles.entries()].reverse().map(([id, b]) => {
      const pct = Math.round(b.got.size / b.parts * 100);
      if (b.state === 'done') {
        const r = b.result, c = r.counts;
        const rows = r.results.map(x => `<tr><td>${esc(x.kind)}</td><td class="nhi-status-${esc(x.status)}">${esc(x.status)}</td><td>${esc(x.detail)}</td></tr>`).join('');
        return `<div class="card nhi-bundle"><h3><span><i class="fa-solid fa-circle-check"></i> ${esc(r.scout)}</span><small>Hand-off ${esc(id)}</small></h3>
          <div>${c.added} added · ${c.updated} updated · ${c.already} already here${c.rejected ? ` · <b class="nhi-status-rejected">${c.rejected} rejected</b>` : ''}${r.media_waiting ? ` · ${r.media_waiting} with photos still on the tablet` : ''}</div>
          <p class="nhi-note">Show or read this code to the scout. They enter it on the tablet to clear these entries${c.rejected ? '. Rejected entries cannot be imported; the scout can skip the code to keep them on the tablet' : ''}.</p>
          <div class="nhi-confirm">${esc(r.confirmation)}</div>
          <details><summary>Entries (${r.results.length})</summary><table class="nhi-results">${rows}</table></details></div>`;
      }
      const status = b.state === 'importing' ? 'Importing…' : b.state === 'error' ? `<b class="nhi-status-rejected">${esc(b.result.message)}</b> <button type="button" class="secondary compact" data-retry="${esc(id)}">Retry</button>` : `${b.got.size} of ${b.parts} parts scanned`;
      const missing = b.state === 'collecting' ? [...Array(b.parts).keys()].map(i => i + 1).filter(p => !b.got.has(p)) : [];
      return `<div class="card nhi-bundle"><h3><span>Hand-off ${esc(id)}</span><small>${status}</small></h3>
        <div class="nhi-progress"><span style="width:${pct}%"></span></div>
        ${missing.length && missing.length <= 12 ? `<p class="nhi-note">Still needed: part ${missing.join(', ')}</p>` : ''}</div>`;
    }).join('');
    box.querySelectorAll('[data-retry]').forEach(btn => btn.onclick = () => { const b = bundles.get(btn.dataset.retry); if (b) submit(btn.dataset.retry, b); });
  }

  // Tabs
  document.querySelectorAll('[data-tab]').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('[data-tab]').forEach(x => x.setAttribute('aria-selected', String(x === t)));
    document.querySelectorAll('[data-panel]').forEach(p => p.hidden = p.dataset.panel !== t.dataset.tab);
    if (t.dataset.tab === 'scanner') scan.focus();
    if (t.dataset.tab !== 'camera') stopCamera();
  }));

  // Handheld scanner (keyboard wedge): each Enter submits one code.
  const scan = document.getElementById('nhiScan');
  scan.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); add(scan.value); scan.value = ''; } });
  scan.addEventListener('paste', () => setTimeout(() => { if (add(scan.value)) scan.value = ''; }, 0));
  scan.focus();

  // Paste / file
  document.getElementById('nhiPasteGo').onclick = () => { const t = document.getElementById('nhiPaste'); if (add(t.value)) t.value = ''; };
  document.getElementById('nhiFile').onchange = async e => { for (const f of e.target.files) add(await f.text()); e.target.value = ''; };

  // Camera: needs a secure page (localhost/HTTPS) and the browser's built-in QR reader.
  const camOk = window.isSecureContext && 'BarcodeDetector' in window && navigator.mediaDevices?.getUserMedia;
  document.getElementById(camOk ? 'nhiCamReady' : 'nhiCamUnsupported').hidden = false;
  let stream = null, loop = null;
  const video = document.getElementById('nhiVideo');
  function stopCamera() { clearInterval(loop); loop = null; stream?.getTracks().forEach(t => t.stop()); stream = null; video.hidden = true; }
  document.getElementById('nhiCamStart').onclick = async () => {
    if (stream) return stopCamera();
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      video.srcObject = stream; video.hidden = false; await video.play();
      const det = new BarcodeDetector({ formats: ['qr_code'] });
      loop = setInterval(async () => { try { for (const c of await det.detect(video)) add(c.rawValue); } catch (_) {} }, 250);
    } catch (e) { stopCamera(); alert('Camera could not start: ' + (e.message || e)); }
  };
})();
</script>
<?php include dirname(__DIR__) . '/partials_footer.php';
