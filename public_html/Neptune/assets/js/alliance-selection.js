(() => {
  const cfg = window.NeptuneAllianceConfig;
  if (!cfg) return;

  const $ = sel => document.querySelector(sel);
  const $$ = sel => [...document.querySelectorAll(sel)];
  const app = $('#allianceSelectionApp');
  if (!app) return;

  const ui = window.NeptuneUI || {
    toast: msg => window.alert(msg),
    confirm: async () => true,
    prompt: async (_msg, opts = {}) => window.prompt(_msg, opts.value || '')
  };

  const els = {
    draftTabs: $('#draftTabs'), board: $('#allianceBoard'), teamList: $('#teamList'), teamSearch: $('#teamSearch'),
    teamPoolSummary: $('#teamPoolSummary'), teamActionBar: $('#teamActionBar'), selectedTeamLabel: $('#selectedTeamLabel'),
    selectedTeamStatus: $('#selectedTeamStatus'), selectedTeamDecision: $('#selectedTeamDecision'), selectedTeamTags: $('#selectedTeamTagsPanel'), selectedTeamMatches: $('#selectedTeamMatchesPanel'), selectedTeamDetail: $('#selectedTeamDetailPanel'), selectedTeamModalTabs: $('#selectedTeamModalTabs'), selectedTeamClose: $('#selectedTeamCloseBtn'), selectedTeamHint: $('#selectedTeamHint'),
    makeSelection: $('#makeSelectionBtn'), favorite: $('#favoriteBtn'),
    decline: $('#declineBtn'), dnp: $('#dnpBtn'), broken: $('#brokenBtn'), potential: $('#alliancePotentialPane'), potentialDialog: $('#alliancePotentialDialog'),
    newDraft: $('#newDraftBtn'), cloneDraft: $('#cloneDraftBtn'), draftMenu: $('#draftMenuBtn'), seedCaptains: $('#seedCaptainsBtn'),
    undo: $('#undoDraftBtn'), redo: $('#redoDraftBtn'), mode: $('#modeBtn'),
    modePill: $('#draftModePill'), onlinePill: $('#allianceOnlinePill'),
    prepareOffline: $('#prepareOfflineBtn'), offlineStatus: $('#allianceOfflineStatus'), offlineDialog: $('#allianceOfflineDialog'),
    offlineClose: $('#allianceOfflineCloseBtn'), offlinePhotos: $('#allianceOfflinePhotos'), offlineDownload: $('#allianceOfflineDownloadBtn'),
    offlineClear: $('#allianceOfflineClearBtn'), offlineProgress: $('#allianceOfflineProgress'), offlineProgressLabel: $('#allianceOfflineProgressLabel'),
    offlineProgressCount: $('#allianceOfflineProgressCount'), offlineProgressBar: $('#allianceOfflineProgressBar'), offlineReady: $('#allianceOfflineReady'),
    offlineSize: $('#allianceOfflineSize'), offlineSizeNote: $('#allianceOfflineSizeNote'), offlineSizeBtn: $('#allianceOfflineSizeBtn'),
    workspaceShell: $('#allianceWorkspaceShell'), fullscreen: $('#allianceFullscreenBtn'), fullscreenLabel: $('#allianceFullscreenLabel'),
    captainPickToggle: $('#captainPickToggle'), captainPickToggleLabel: $('#captainPickToggleLabel'),
    pickDrawerBtn: $('#alliancePickDrawerBtn'), pickDrawer: $('#alliancePickDrawer'), pickDrawerClose: $('#alliancePickDrawerCloseBtn'), pickLists: $('#alliancePickLists'),
    matchupDialog: $('#matchupDialog'), matchupBody: $('#matchupBody'), matchupTitle: $('#matchupDialogTitle'), matchupClose: $('#matchupCloseBtn'),
    matchupSettings: $('#matchupSettingsDialog'), matchupSettingsBtn: $('#matchupSettingsBtn'), matchupSettingsClose: $('#matchupSettingsCloseBtn'), matchupSettingsForm: $('#matchupSettingsForm'), matchupSettingsSave: $('#matchupSettingsSaveBtn'), matchupSettingsReset: $('#matchupSettingsResetBtn')
  };

  const slotOrder = ['captain', 'pick1', 'pick2', 'backup'];
  const pickSlotOrder = ['pick1', 'pick2', 'backup'];
  const slotLabels = { captain: 'Captain', pick1: 'Pick 1', pick2: 'Pick 2', backup: 'Backup' };
  const tabLabels = [
    ['event', 'Event Stats'], ['statbotics', 'Public EPA'], ['offense', 'Offense'], ['defense', 'Defense'],
    ['game', 'Game Data'], ['pit', 'Pit Scouting'], ['spot', 'Spot Scouting'], ['photos', 'Robot Photos'], ['history', 'Alliance History']
  ];

  const allianceTagDefinitions = [
    { id: 'first_pick', label: '1st Pick', icon: 'fa-solid fa-medal', hint: 'Primary first-round target' },
    { id: 'second_pick', label: '2nd Pick', icon: 'fa-solid fa-award', hint: 'Primary second-round target' },
    { id: 'third_pick', label: '3rd Pick', icon: 'fa-solid fa-ranking-star', hint: 'Third-tier alliance target' },
    { id: 'fourth_pick', label: '4th Pick', icon: 'fa-solid fa-list-ol', hint: 'Fourth-tier alliance target' },
    { id: 'defense_pick', label: 'Defense Pick', icon: 'fa-solid fa-shield-halved', hint: 'Strong defensive option' },
    { id: 'best_defense', label: 'Best Defense', icon: 'fa-solid fa-shield', hint: 'Top defensive target' },
    { id: 'high_ceiling', label: 'High Ceiling', icon: 'fa-solid fa-rocket', hint: 'High upside if everything works' },
    { id: 'reliable', label: 'Reliable', icon: 'fa-solid fa-circle-check', hint: 'Consistent and dependable' },
    { id: 'sleeper', label: 'Sleeper', icon: 'fa-solid fa-moon', hint: 'Undervalued target' },
    { id: 'versatile', label: 'Versatile', icon: 'fa-solid fa-arrows-rotate', hint: 'Can fill multiple alliance roles' }
  ];
  const allianceTagById = new Map(allianceTagDefinitions.map(tag => [tag.id, tag]));
  const pickListTiers = ['1', '2', '3', '4'];

  let state = null;
  let loading = false;
  let selectedTeam = null;
  let selectedModalTab = 'overview';
  let activeSlot = { alliance: 1, slot: 'pick1' };
  let focusedAlliance = 1;
  let filter = 'available';
  const paneState = [{ team: null, data: null, tab: 'event' }];
  const detailCache = new Map();
  const detailPromises = new Map();
  let potentialRenderToken = 0;
  let matchupSelected = [];
  let matchupRequestToken = 0;
  const matchupDefaults = {
    event_offense_weight: 25, recent_offense_weight: 40, ceiling_weight: 15, epa_weight: 35,
    trend_strength: 1.5, defense_suppression_weight: 75, defense_activity_weight: 25,
    defense_action_points: 1.5, max_defense_adjustment_pct: 20, tba_form_strength: 5
  };
  const queueKey = `neptune-alliance-queue:${cfg.eventId}`;
  const offlineMetaKey = `neptune-alliance-offline-meta:${cfg.eventId}`;
  const offlineCacheName = `neptune-alliance-event-v2:${cfg.eventId}`;
  let offlinePreparing = false;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
  const num = (value, decimals = 1) => value === null || value === undefined || Number.isNaN(Number(value)) ? '—' : Number(value).toFixed(decimals).replace(/\.0+$/, '');
  const teamByNumber = n => state?.teams?.find(t => Number(t.team) === Number(n)) || null;
  const neptuneEpa = row => row?.neptune_epa ?? row?.augur_epa ?? null;
  const currentDraft = () => state?.draft || null;
  const editable = () => !!cfg.canEdit && currentDraft() && currentDraft().status !== 'finalized';

  function allianceNeptuneEpa(row) {
    const fieldTeams = ['captain', 'pick1', 'pick2']
      .map(slot => Number(row?.[slot] || 0))
      .filter(team => team > 0);
    const rated = fieldTeams
      .map(team => neptuneEpa(teamByNumber(team)))
      .filter(value => value !== null && value !== undefined && Number.isFinite(Number(value)))
      .map(Number);
    return {
      members: fieldTeams.length,
      rated: rated.length,
      subtotal: rated.reduce((sum, value) => sum + value, 0)
    };
  }

  function readQueue() {
    try { const q = JSON.parse(localStorage.getItem(queueKey) || '[]'); return Array.isArray(q) ? q : []; } catch (_) { return []; }
  }
  function writeQueue(q) {
    try { localStorage.setItem(queueKey, JSON.stringify(q)); } catch (_) {}
    updateOnlinePill();
  }
  const offlinePrefetch = new Map();
  let offlineEstimate = null;
  let offlineEstimating = false;

  function formatBytes(bytes) {
    const value = Math.max(0, Number(bytes || 0));
    if (value < 1024) return `${Math.round(value)} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(value < 10 * 1024 ? 1 : 0)} KB`;
    if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(value < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`;
  }

  function offlineStaticUrls() {
    return new Set([
      window.location.href,
      new URL(`?event_id=${encodeURIComponent(cfg.eventId)}`, window.location.href).href,
      ...(Array.from(document.querySelectorAll('link[rel="stylesheet"][href],link[rel="manifest"][href],script[src],img[src]'))
        .map(node => node.href || node.src).filter(Boolean)),
      ...(() => {
        const faCss = document.querySelector('link[href*="assets/vendor/fontawesome/css/all.min.css"]')?.href;
        if (!faCss) return [];
        const fontBase = new URL('../webfonts/', faCss);
        return [
          new URL('fa-solid-900.woff2', fontBase).href,
          new URL('fa-regular-400.woff2', fontBase).href,
          new URL('fa-brands-400.woff2', fontBase).href,
          new URL('fa-v4compatibility.woff2', fontBase).href
        ];
      })()
    ]);
  }

  function offlineStateUrls() {
    const drafts = Array.isArray(state?.drafts) ? state.drafts : [];
    const ids = drafts.length ? drafts.map(row => Number(row.id || 0)).filter(Boolean) : [Number(state?.draft?.id || 0)].filter(Boolean);
    if (!ids.length) ids.push(0);
    const urls = ids.map(draftId => {
      const url = new URL(cfg.stateEndpoint, window.location.href);
      url.searchParams.set('event_id', cfg.eventId);
      if (draftId) url.searchParams.set('draft_id', draftId);
      return url.href;
    });
    const current = new URL(cfg.stateEndpoint, window.location.href);
    current.searchParams.set('event_id', cfg.eventId);
    urls.push(current.href);
    return [...new Set(urls)];
  }

  function offlineTeamUrls() {
    return (state?.teams || []).map(row => {
      const url = new URL(cfg.detailEndpoint, window.location.href);
      url.searchParams.set('event_id', cfg.eventId);
      url.searchParams.set('team', Number(row.team || 0));
      return { team: Number(row.team || 0), url: url.href };
    }).filter(row => row.team > 0);
  }

  async function fetchJsonForOffline(url) {
    if (offlinePrefetch.has(url)) return offlinePrefetch.get(url);
    const request = offlineRequest(url);
    const response = await fetch(request);
    if (!response.ok) throw new Error(`Could not read offline data (${response.status}).`);
    const text = await response.text();
    const bytes = new TextEncoder().encode(text).byteLength;
    let data = null;
    try { data = JSON.parse(text); } catch (_) {}
    const row = { text, bytes, data };
    offlinePrefetch.set(url, row);
    return row;
  }

  async function headSize(url) {
    try {
      const absolute = new URL(url, window.location.href);
      const sameOrigin = absolute.origin === window.location.origin;
      const response = await fetch(absolute.href, { method: 'HEAD', credentials: sameOrigin ? 'same-origin' : 'omit', mode: sameOrigin ? 'same-origin' : 'cors', cache: 'no-store' });
      const length = Number(response.headers.get('content-length') || 0);
      return Number.isFinite(length) && length > 0 ? length : 0;
    } catch (_) { return 0; }
  }

  async function measureOfflineCache(cache) {
    let bytes = 0;
    let unknown = 0;
    try {
      const requests = await cache.keys();
      for (const request of requests) {
        const response = await cache.match(request);
        if (!response) continue;
        const headerSize = Number(response.headers.get('content-length') || 0);
        if (headerSize > 0) { bytes += headerSize; continue; }
        if (response.type === 'opaque') { unknown++; continue; }
        try { bytes += (await response.clone().arrayBuffer()).byteLength; }
        catch (_) { unknown++; }
      }
    } catch (_) { unknown++; }
    return { bytes, unknown };
  }

  function showOfflineEstimate(bytes, unknown = 0, includePhotos = false) {
    if (!els.offlineSize) return;
    const card = els.offlineSize.closest('.alliance-offline-size-card');
    card?.classList.remove('is-calculating');
    card?.classList.add('is-ready');
    els.offlineSize.textContent = `${unknown ? '~' : ''}${formatBytes(bytes)}`;
    if (els.offlineSizeNote) {
      const mediaText = includePhotos ? ' including selected media' : ' without robot/Spot media';
      els.offlineSizeNote.textContent = unknown
        ? `Estimated package${mediaText}. ${unknown} resource${unknown === 1 ? '' : 's'} did not report a size, so the final download can be larger.`
        : `Estimated package${mediaText}.`;
    }
  }

  async function estimateOfflineSize() {
    if (offlineEstimating || !state?.teams?.length) return;
    if (!navigator.onLine) { ui.toast('Connect to Neptune to calculate the offline package size.', 'warn'); return; }
    offlineEstimating = true;
    if (els.offlineSizeBtn) els.offlineSizeBtn.disabled = true;
    const includePhotos = !!els.offlinePhotos?.checked;
    const card = els.offlineSize?.closest('.alliance-offline-size-card');
    card?.classList.remove('is-ready');
    card?.classList.add('is-calculating');
    if (els.offlineSize) els.offlineSize.textContent = 'Calculating…';
    if (els.offlineSizeNote) els.offlineSizeNote.textContent = `Checking ${state.teams.length} teams and interface files…`;

    let bytes = 0;
    let unknown = 0;
    const media = new Set();
    try {
      const jsonUrls = [...offlineStateUrls(), ...offlineTeamUrls().map(row => row.url)];
      let cursor = 0;
      const workers = Array.from({ length: Math.min(4, jsonUrls.length) }, async () => {
        while (true) {
          const i = cursor++;
          if (i >= jsonUrls.length) break;
          const row = await fetchJsonForOffline(jsonUrls[i]);
          bytes += row.bytes;
          if (includePhotos && row.data) collectOfflineMedia(row.data).forEach(url => media.add(url));
        }
      });
      await Promise.all(workers);

      const staticUrls = [...offlineStaticUrls()];
      const staticSizes = await Promise.all(staticUrls.map(headSize));
      staticSizes.forEach(size => { if (size > 0) bytes += size; else unknown++; });

      if (includePhotos && media.size) {
        const mediaSizes = await Promise.all([...media].map(headSize));
        mediaSizes.forEach(size => { if (size > 0) bytes += size; else unknown++; });
      }

      offlineEstimate = { bytes, unknown, includePhotos, at: Date.now() };
      showOfflineEstimate(bytes, unknown, includePhotos);
    } catch (error) {
      if (els.offlineSize) els.offlineSize.textContent = 'Could not calculate';
      if (els.offlineSizeNote) els.offlineSizeNote.textContent = error.message || 'Size calculation failed.';
      card?.classList.remove('is-calculating');
    } finally {
      offlineEstimating = false;
      if (els.offlineSizeBtn) els.offlineSizeBtn.disabled = false;
    }
  }

  function updateOnlinePill() {
    if (!els.onlinePill) return;
    const online = navigator.onLine;
    const queued = readQueue().length;
    els.onlinePill.className = `alliance-online-pill ${online ? 'online' : 'offline'}`;
    els.onlinePill.innerHTML = `<i class="fa-solid ${online ? 'fa-wifi' : 'fa-cloud-arrow-up'}"></i> ${online ? 'Online' : 'Offline'}${queued ? ` · ${queued} queued` : ''}`;
  }

  function readOfflineMeta() {
    try {
      const value = JSON.parse(localStorage.getItem(offlineMetaKey) || 'null');
      return value && typeof value === 'object' ? value : null;
    } catch (_) { return null; }
  }

  function writeOfflineMeta(meta) {
    try {
      if (meta) localStorage.setItem(offlineMetaKey, JSON.stringify(meta));
      else localStorage.removeItem(offlineMetaKey);
    } catch (_) {}
    renderOfflineStatus();
  }

  function formatOfflineTime(value) {
    const stamp = Number(value || 0);
    if (!stamp) return '';
    try { return new Date(stamp).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
    catch (_) { return new Date(stamp).toLocaleString(); }
  }

  function renderOfflineStatus() {
    const meta = readOfflineMeta();
    if (els.offlineStatus) {
      if (!meta) {
        els.offlineStatus.hidden = true;
        els.offlineStatus.textContent = '';
      } else {
        els.offlineStatus.hidden = false;
        els.offlineStatus.innerHTML = `<i class="fa-solid fa-circle-check"></i> Offline ready · ${Number(meta.teamCount || 0)} teams`;
        els.offlineStatus.title = `Prepared ${formatOfflineTime(meta.preparedAt)}${meta.includePhotos ? ' · media included' : ''}`;
      }
    }
    if (els.offlineReady) {
      els.offlineReady.innerHTML = meta
        ? `<div class="alliance-offline-ready-card"><i class="fa-solid fa-circle-check"></i><div><b>Offline copy ready</b><span>${Number(meta.teamCount || 0)} teams${meta.estimatedBytes ? ` · ${esc(formatBytes(meta.estimatedBytes))}` : ''} · prepared ${esc(formatOfflineTime(meta.preparedAt))}${meta.includePhotos ? ' · media included' : ''}</span></div></div>`
        : '<div class="alliance-offline-ready-card muted"><i class="fa-solid fa-cloud-arrow-down"></i><div><b>No offline event copy yet</b><span>Download event data before leaving a reliable connection.</span></div></div>';
    }
    if (meta?.estimatedBytes && !offlineEstimate && els.offlineSize) {
      els.offlineSize.textContent = formatBytes(meta.estimatedBytes);
      if (els.offlineSizeNote) els.offlineSizeNote.textContent = `Size of the last prepared offline copy${meta.includePhotos ? ' with media' : ' without media'}. Recalculate before updating if the event data has changed.`;
      els.offlineSize.closest('.alliance-offline-size-card')?.classList.add('is-ready');
    }
  }

  async function registerOfflineWorker() {
    if (!('serviceWorker' in navigator) || !cfg.offlineWorker) return null;
    try {
      const registration = await navigator.serviceWorker.register(cfg.offlineWorker);
      await navigator.serviceWorker.ready;
      return registration;
    } catch (error) {
      console.warn('Alliance Selection offline worker could not be registered.', error);
      return null;
    }
  }

  function offlineRequest(url) {
    return new Request(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
  }

  async function cacheResponse(cache, request, response) {
    if (!response || !response.ok) return;
    try { await cache.put(request, response.clone()); } catch (_) {}
  }

  async function fetchWithOfflineFallback(url, options = {}) {
    const request = url instanceof Request ? url : new Request(url, options);
    try {
      const response = await fetch(request);
      if (response.ok && request.method === 'GET' && readOfflineMeta() && 'caches' in window) {
        try {
          const cache = await caches.open(offlineCacheName);
          await cache.put(request, response.clone());
        } catch (_) {}
      }
      return response;
    } catch (error) {
      if (request.method === 'GET' && 'caches' in window) {
        const cached = await caches.match(request);
        if (cached) return cached.clone();
      }
      error.network = true;
      throw error;
    }
  }

  async function cacheUrl(cache, url, options = {}) {
    try {
      const absolute = new URL(url, window.location.href);
      const sameOrigin = absolute.origin === window.location.origin;
      const request = new Request(absolute.href, {
        credentials: sameOrigin ? 'same-origin' : 'omit',
        mode: sameOrigin ? 'same-origin' : 'no-cors',
        cache: 'reload'
      });
      const response = await fetch(request);
      if (response && (response.ok || response.type === 'opaque')) {
        await cache.put(request, response.clone());
        return true;
      }
    } catch (_) {}
    return false;
  }

  function collectOfflineMedia(detail) {
    const urls = new Set();
    (detail?.photos || []).forEach(row => { if (row?.url) urls.add(String(row.url)); });
    const observations = Array.isArray(detail?.spot?.observations) ? detail.spot.observations : [];
    observations.forEach(obs => (Array.isArray(obs?.media) ? obs.media : []).forEach(item => {
      const url = item?.url || item?.media_url || item?.src;
      if (url) urls.add(String(url));
    }));
    return [...urls];
  }

  function setOfflineProgress(done, total, label) {
    const safeTotal = Math.max(1, Number(total || 1));
    const pct = Math.max(0, Math.min(100, Math.round(Number(done || 0) * 100 / safeTotal)));
    if (els.offlineProgress) els.offlineProgress.hidden = false;
    if (els.offlineProgressLabel) els.offlineProgressLabel.textContent = label || 'Preparing…';
    if (els.offlineProgressCount) els.offlineProgressCount.textContent = `${pct}%`;
    if (els.offlineProgressBar) els.offlineProgressBar.style.width = `${pct}%`;
  }

  async function prepareOfflineData() {
    if (offlinePreparing || !state?.teams?.length) return;
    if (!navigator.onLine) {
      ui.toast('Connect to Neptune before preparing an offline copy.', 'warn', { title: 'Offline download' });
      return;
    }
    if (!('caches' in window)) {
      ui.toast('This browser does not support the offline cache required by Alliance Selection.', 'bad', { title: 'Offline unavailable' });
      return;
    }

    offlinePreparing = true;
    if (els.offlineDownload) els.offlineDownload.disabled = true;
    if (els.offlineClear) els.offlineClear.disabled = true;
    const includePhotos = !!els.offlinePhotos?.checked;
    const teams = [...state.teams];
    const drafts = Array.isArray(state.drafts) ? state.drafts : [];
    const mediaUrls = new Set();

    try {
      await registerOfflineWorker();
      const cache = await caches.open(offlineCacheName);
      const staticUrls = offlineStaticUrls();

      let total = staticUrls.size + Math.max(1, drafts.length) + teams.length + 1;
      let done = 0;

      setOfflineProgress(done, total, 'Caching Alliance Selection page…');
      for (const url of staticUrls) {
        await cacheUrl(cache, url);
        done++;
        setOfflineProgress(done, total, 'Caching page and interface files…');
      }

      const draftIds = drafts.length ? drafts.map(row => Number(row.id || 0)).filter(Boolean) : [Number(state?.draft?.id || 0)].filter(Boolean);
      if (!draftIds.length) draftIds.push(0);
      for (const draftId of draftIds) {
        const url = new URL(cfg.stateEndpoint, window.location.href);
        url.searchParams.set('event_id', cfg.eventId);
        if (draftId) url.searchParams.set('draft_id', draftId);
        const request = offlineRequest(url.href);
        const prefetched = offlinePrefetch.get(url.href);
        const response = prefetched
          ? new Response(prefetched.text, { status: 200, headers: { 'Content-Type': 'application/json' } })
          : await fetch(request);
        if (!response.ok) throw new Error(`Could not cache scenario ${draftId || ''}.`);
        await cacheResponse(cache, request, response);
        done++;
        setOfflineProgress(done, total, `Caching scenario data…`);
      }

      // Also cache the default state URL because initial page load does not pass
      // a draft id.
      {
        const url = new URL(cfg.stateEndpoint, window.location.href);
        url.searchParams.set('event_id', cfg.eventId);
        const request = offlineRequest(url.href);
        const prefetched = offlinePrefetch.get(url.href);
        const response = prefetched
          ? new Response(prefetched.text, { status: 200, headers: { 'Content-Type': 'application/json' } })
          : await fetch(request);
        if (!response.ok) throw new Error('Could not cache Alliance Selection state.');
        await cacheResponse(cache, request, response);
        done++;
        setOfflineProgress(done, total, 'Caching current Alliance Selection state…');
      }

      let cursor = 0;
      const concurrency = Math.min(4, teams.length);
      const workers = Array.from({ length: concurrency }, async () => {
        while (true) {
          const index = cursor++;
          if (index >= teams.length) break;
          const team = Number(teams[index].team || 0);
          if (!team) { done++; continue; }
          const url = new URL(cfg.detailEndpoint, window.location.href);
          url.searchParams.set('event_id', cfg.eventId);
          url.searchParams.set('team', team);
          const request = offlineRequest(url.href);
          const prefetched = offlinePrefetch.get(url.href);
          let response;
          let data;
          if (prefetched) {
            response = new Response(prefetched.text, { status: 200, headers: { 'Content-Type': 'application/json' } });
            data = prefetched.data;
          } else {
            response = await fetch(request);
            if (!response.ok) throw new Error(`Could not download Team ${team} intelligence.`);
            const text = await response.clone().text();
            try { data = JSON.parse(text); } catch (_) { data = null; }
          }
          if (!response.ok) throw new Error(`Could not download Team ${team} intelligence.`);
          await cacheResponse(cache, request, response);
          detailCache.set(`${cfg.eventId}:${team}`, { data, at: Date.now() });
          if (includePhotos) collectOfflineMedia(data).forEach(url => mediaUrls.add(url));
          done++;
          setOfflineProgress(done, total, `Downloading Team ${team} intelligence…`);
        }
      });
      await Promise.all(workers);

      if (includePhotos && mediaUrls.size) {
        total += mediaUrls.size;
        let mediaDone = 0;
        for (const url of mediaUrls) {
          await cacheUrl(cache, url);
          done++; mediaDone++;
          setOfflineProgress(done, total, `Caching media ${mediaDone} of ${mediaUrls.size}…`);
        }
      }

      const measured = await measureOfflineCache(cache);
      const measuredBytes = Number(measured.bytes || 0);
      const fallbackBytes = offlineEstimate && offlineEstimate.includePhotos === includePhotos ? Number(offlineEstimate.bytes || 0) : 0;
      const storedBytes = measuredBytes || fallbackBytes;
      writeOfflineMeta({
        preparedAt: Date.now(),
        teamCount: teams.length,
        includePhotos,
        eventId: Number(cfg.eventId),
        eventName: String(state?.event?.name || ''),
        estimatedBytes: storedBytes
      });
      if (storedBytes) {
        showOfflineEstimate(storedBytes, measured.unknown || 0, includePhotos);
        if (els.offlineSizeNote) els.offlineSizeNote.textContent = `${measured.unknown ? 'Approximate' : 'Stored'} offline package size${includePhotos ? ' including media' : ' without media'}.`;
      }
      setOfflineProgress(total, total, 'Offline copy ready');
      ui.toast(`Alliance Selection is ready offline for ${teams.length} teams.`, 'good', { title: 'Offline ready' });
    } catch (error) {
      ui.toast(error.message || 'Could not finish the offline download.', 'bad', { title: 'Offline download failed' });
    } finally {
      offlinePreparing = false;
      if (els.offlineDownload) els.offlineDownload.disabled = false;
      if (els.offlineClear) els.offlineClear.disabled = false;
      renderOfflineStatus();
    }
  }

  async function clearOfflineData() {
    if (offlinePreparing) return;
    try {
      if ('caches' in window) await caches.delete(offlineCacheName);
      writeOfflineMeta(null);
      if (els.offlineProgress) els.offlineProgress.hidden = true;
      ui.toast('This event’s offline Alliance Selection data was removed from this device.', 'good');
    } catch (error) {
      ui.toast(error.message || 'Could not remove offline data.', 'bad');
    }
  }

  async function clearAllAllianceOfflineData() {
    try {
      if ('caches' in window) {
        const names = await caches.keys();
        await Promise.all(names.filter(name => name.startsWith('neptune-alliance-event-v2:')).map(name => caches.delete(name)));
      }
      for (let i = localStorage.length - 1; i >= 0; i--) {
        const key = localStorage.key(i);
        if (key && key.startsWith('neptune-alliance-offline-meta:')) localStorage.removeItem(key);
      }
    } catch (_) {}
  }

  async function rawPost(op, payload = {}) {
    const body = new URLSearchParams({ op, event_id: String(cfg.eventId), csrf: cfg.csrf, ...Object.fromEntries(Object.entries(payload).map(([k, v]) => [k, v == null ? '' : String(v)])) });
    let res;
    try {
      res = await fetch(cfg.stateEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
    } catch (error) {
      error.network = true;
      throw error;
    }
    let data = {};
    try { data = await res.json(); } catch (_) {}
    if (!res.ok || !data.ok) {
      const error = new Error(data.message || `Request failed (${res.status}).`);
      error.network = false;
      error.status = res.status;
      throw error;
    }
    return data;
  }

  function localRefreshSelected() {
    if (!state?.draft) return;
    // A captain is still eligible to accept an invitation from a higher-seeded
    // alliance. Only teams occupying an actual pick/backup slot are Selected.
    const selected = new Set();
    for (const a of Object.values(state.slots || {})) {
      for (const slot of pickSlotOrder) {
        if (Number(a?.[slot]) > 0) selected.add(Number(a[slot]));
      }
    }
    state.statuses ||= {};
    for (const [team, row] of Object.entries(state.statuses)) {
      if (row.state === 'selected' && !selected.has(Number(team))) row.state = 'available';
    }
    selected.forEach(team => {
      state.statuses[team] ||= { state: 'available', favorite: false };
      state.statuses[team].state = 'selected';
    });
  }

  function captainAllianceFor(team) {
    const n = Number(team);
    for (let alliance = 1; alliance <= 8; alliance++) {
      const row = state?.slots?.[alliance] || state?.slots?.[String(alliance)] || {};
      if (Number(row.captain || 0) === n) return alliance;
    }
    return 0;
  }


  function captainHasMadeSelection(alliance) {
    const row = state?.slots?.[alliance] || state?.slots?.[String(alliance)] || {};
    return pickSlotOrder.some(slot => Number(row?.[slot] || 0) > 0);
  }

  function captainPicksAllowed() {
    return state?.settings?.captain_picks_allowed !== false;
  }

  function captainLockInfo(team) {
    const alliance = captainAllianceFor(team);
    if (!alliance) return { locked: false, alliance: 0, reason: '' };
    if (alliance === 1) {
      return { locked: true, alliance, reason: 'Alliance 1 captain is never available to be selected.' };
    }
    if (!captainPicksAllowed()) {
      return { locked: true, alliance, reason: 'Captain-to-captain selections are disabled for this event.' };
    }
    if (captainHasMadeSelection(alliance)) {
      return { locked: true, alliance, reason: `Alliance ${alliance} captain is no longer available after that alliance has made a selection.` };
    }
    return { locked: false, alliance, reason: '' };
  }

  function captainSelectionLockInfo(team) {
    const lock = captainLockInfo(team);
    if (lock.locked) return lock;

    const captainAlliance = Number(lock.alliance || captainAllianceFor(team) || 0);
    const targetAlliance = Number(activeSlot?.alliance || 0);
    if (captainAlliance > 0 && targetAlliance > 0 && targetAlliance >= captainAlliance) {
      return {
        locked: true,
        alliance: captainAlliance,
        reason: `Alliance ${targetAlliance} cannot select the Alliance ${captainAlliance} captain. Only a higher-seeded alliance can invite that captain.`,
      };
    }

    return lock;
  }

  function effectiveStatusFor(team) {
    const raw = statusFor(team);
    if (raw.state !== 'available') return raw;
    const lock = captainSelectionLockInfo(team);
    return lock.locked ? { ...raw, state: 'captain_locked', captain_alliance: lock.alliance } : raw;
  }

  function promoteCaptainsLocally(fromAlliance, excludeTeam = 0) {
    if (!state?.slots || fromAlliance < 1 || fromAlliance > 8) return;
    for (let alliance = fromAlliance; alliance < 8; alliance++) {
      state.slots[alliance] ||= { captain: null, pick1: null, pick2: null, backup: null };
      const next = state.slots[alliance + 1] || state.slots[String(alliance + 1)] || {};
      state.slots[alliance].captain = Number(next.captain || 0) || null;
    }

    const used = new Set();
    if (Number(excludeTeam) > 0) used.add(Number(excludeTeam));
    for (let alliance = 1; alliance <= 8; alliance++) {
      const row = state.slots[alliance] || state.slots[String(alliance)] || {};
      for (const slot of slotOrder) if (Number(row[slot] || 0) > 0) used.add(Number(row[slot]));
    }

    const ranked = [...(state.teams || [])]
      .filter(team => Number(team.rank || 0) > 0)
      .sort((a, b) => Number(a.rank) - Number(b.rank));
    const replacement = ranked.find(team => {
      const n = Number(team.team);
      const st = statusFor(n).state;
      return !used.has(n) && !['declined', 'broken', 'do_not_pick'].includes(st);
    });
    state.slots[8] ||= { captain: null, pick1: null, pick2: null, backup: null };
    state.slots[8].captain = replacement ? Number(replacement.team) : null;
  }

  function applyLocal(op, payload, render = true) {
    if (!state?.draft) return;
    if (op === 'set_slot') {
      const team = Number(payload.team || 0);
      state.slots ||= {};
      const targetAlliance = Number(payload.alliance_number);
      const captainAlliance = team > 0 ? captainAllianceFor(team) : 0;

      for (let a = 1; a <= 8; a++) {
        state.slots[a] ||= { captain: null, pick1: null, pick2: null, backup: null };
        if (team > 0) {
          for (const s of pickSlotOrder) if (Number(state.slots[a][s]) === team) state.slots[a][s] = null;
        }
      }

      if (captainAlliance > 0 && targetAlliance < captainAlliance) {
        state.slots[captainAlliance].captain = null;
        promoteCaptainsLocally(captainAlliance, team);
      }

      state.slots[targetAlliance] ||= { captain: null, pick1: null, pick2: null, backup: null };
      state.slots[targetAlliance][payload.slot_type] = team > 0 ? team : null;
      localRefreshSelected();
    } else if (op === 'set_status') {
      const team = Number(payload.team);
      state.statuses ||= {};
      state.statuses[team] ||= { state: 'available', favorite: false };
      state.statuses[team].state = payload.state;
    } else if (op === 'toggle_favorite') {
      const team = Number(payload.team);
      state.statuses ||= {};
      state.statuses[team] ||= { state: 'available', favorite: false };
      state.statuses[team].favorite = !state.statuses[team].favorite;
    } else if (op === 'set_captain_picks') {
      state.settings ||= {};
      state.settings.captain_picks_allowed = Number(payload.allowed || 0) === 1;
    } else if (op === 'set_matchup_settings') {
      state.settings ||= {};
      try { state.settings.matchup_model = { ...matchupDefaults, ...JSON.parse(payload.settings_json || '{}') }; } catch (_) {}
    } else if (op === 'set_team_tags') {
      const team = Number(payload.team || 0);
      state.alliance_tags ||= {};
      let tags = [];
      try { tags = JSON.parse(payload.tags_json || '[]'); } catch (_) {}
      tags = Array.isArray(tags) ? tags.filter(id => allianceTagById.has(String(id))).map(String) : [];
      if (tags.length) state.alliance_tags[team] = tags;
      else delete state.alliance_tags[team];
    } else if (op === 'set_pick_list_slot') {
      const tier = String(payload.tier || '');
      const index = Number(payload.slot_index ?? -1);
      const team = Number(payload.team || 0);
      state.pick_lists ||= { '1': Array(6).fill(null), '2': Array(6).fill(null), '3': Array(6).fill(null), '4': Array(6).fill(null) };
      for (const listTier of pickListTiers) {
        state.pick_lists[listTier] ||= Array(6).fill(null);
        if (team > 0) state.pick_lists[listTier] = state.pick_lists[listTier].map(value => Number(value || 0) === team ? null : value);
      }
      if (pickListTiers.includes(tier) && index >= 0 && index < 6) state.pick_lists[tier][index] = team > 0 ? team : null;
    }
    if (render) renderAll();
  }

  async function mutate(op, payload = {}, options = {}) {
    if (loading) return null;
    try {
      const result = await rawPost(op, payload);
      if (options.toast !== false && result.message) ui.toast(result.message, 'good');
      if (options.reload !== false) await loadState(result.draft_id || payload.draft_id || state?.draft?.id || 0);
      return result;
    } catch (error) {
      if (error.network && options.queueable) {
        const q = readQueue();
        q.push({ op, payload: { ...payload, draft_id: payload.draft_id || state?.draft?.id || 0 }, queuedAt: Date.now() });
        writeQueue(q);
        applyLocal(op, payload);
        ui.toast('Connection lost. This change is saved on this device and will sync when Neptune is online.', 'warn', { title: 'Offline queue' });
        return { ok: true, queued: true };
      }
      ui.toast(error.message || 'Alliance Selection could not save that change.', 'bad', { title: 'Save failed' });
      return null;
    }
  }

  async function replayQueue() {
    if (!navigator.onLine) return;
    const q = readQueue();
    if (!q.length) { updateOnlinePill(); return; }
    const remaining = [...q];
    let synced = 0;
    while (remaining.length) {
      const item = remaining[0];
      try {
        await rawPost(item.op, item.payload || {});
        remaining.shift(); synced++;
        writeQueue(remaining);
      } catch (error) {
        if (!error.network) ui.toast(`A queued change could not be synced: ${error.message}`, 'bad', { title: 'Sync stopped' });
        break;
      }
    }
    if (synced) {
      ui.toast(`${synced} offline change${synced === 1 ? '' : 's'} synced.`, 'good');
      await loadState(state?.draft?.id || 0);
    }
    updateOnlinePill();
  }

  async function loadState(draftId = 0) {
    if (loading) return;
    loading = true;
    app.classList.add('is-loading');
    try {
      const url = new URL(cfg.stateEndpoint, window.location.href);
      url.searchParams.set('event_id', cfg.eventId);
      if (draftId) url.searchParams.set('draft_id', draftId);
      const res = await fetchWithOfflineFallback(url.href, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not load Alliance Selection.');
      state = data;
      // If the browser reloaded while offline, re-apply queued mutations on top
      // of the last downloaded server snapshot so the board reflects this
      // device's unsynced picks/status changes.
      if (!navigator.onLine) {
        readQueue().forEach(item => applyLocal(item.op, item.payload || {}, false));
      }
      if (!state.draft && cfg.canEdit) {
        loading = false;
        const created = await mutate('create_draft', { name: 'Scenario 1' }, { reload: false, toast: false });
        if (created?.draft_id) return loadState(created.draft_id);
        return;
      }
      localRefreshSelected();
      renderAll();
    } catch (error) {
      ui.toast(error.message || 'Could not load Alliance Selection.', 'bad', { title: 'Load failed' });
      if (!state) {
        els.board.innerHTML = `<div class="alliance-empty-small">${esc(error.message || 'Could not load the alliance board.')}</div>`;
        els.teamList.innerHTML = '';
      }
    } finally {
      loading = false;
      app.classList.remove('is-loading');
    }
  }

  function statusFor(team) {
    return state?.statuses?.[team] || state?.statuses?.[String(team)] || { state: 'available', favorite: false };
  }
  function stateLabel(value) {
    return ({ available: 'Available', captain_locked: 'Captain Locked', selected: 'Selected', declined: 'Declined', broken: 'Broken', do_not_pick: 'Do Not Pick' })[value] || 'Available';
  }
  function stateIcon(value) {
    return ({ captain_locked: 'fa-lock', selected: 'fa-circle-check', declined: 'fa-circle-xmark', broken: 'fa-screwdriver-wrench', do_not_pick: 'fa-ban', available: 'fa-circle' })[value] || 'fa-circle';
  }

  function renderDrafts() {
    if (!els.draftTabs) return;
    const drafts = state?.drafts || [];
    if (!drafts.length) {
      els.draftTabs.innerHTML = '<span class="muted">No scenarios yet.</span>';
    } else {
      els.draftTabs.innerHTML = drafts.map((d, i) => `<button type="button" class="alliance-draft-tab ${state?.draft?.id === d.id ? 'active' : ''}" data-draft-id="${d.id}">${d.mode === 'live' ? '<span class="draft-live-dot"></span>' : ''}${esc(d.name || `S${i + 1}`)}${d.status === 'finalized' ? ' <i class="fa-solid fa-lock"></i>' : ''}</button>`).join('');
      els.draftTabs.querySelectorAll('[data-draft-id]').forEach(btn => btn.addEventListener('click', () => loadState(btn.dataset.draftId)));
    }
    const d = currentDraft();
    if (els.modePill) {
      const finalized = d?.status === 'finalized';
      els.modePill.className = `alliance-mode-pill ${finalized ? 'finalized' : d?.mode === 'live' ? 'live' : ''}`;
      els.modePill.textContent = finalized ? 'Finalized' : d?.mode === 'live' ? 'Live' : 'Scenario';
    }
    if (els.undo) els.undo.disabled = !editable() || !state?.can_undo;
    if (els.redo) els.redo.disabled = !editable() || !state?.can_redo;
    if (els.cloneDraft) els.cloneDraft.disabled = !d;
    if (els.draftMenu) els.draftMenu.disabled = !d || !cfg.canEdit;
    if (els.seedCaptains) els.seedCaptains.disabled = !editable() || !state?.event?.tba_event_key;
    if (els.mode) {
      els.mode.disabled = !editable();
      els.mode.innerHTML = d?.mode === 'live' ? '<i class="fa-solid fa-flask"></i> Make Scenario' : '<i class="fa-solid fa-tower-broadcast"></i> Make Live';
    }
  }

  function renderBoard() {
    if (!els.board) return;
    const slots = state?.slots || {};
    const can = editable();
    let html = '';
    for (let a = 1; a <= 8; a++) {
      const row = slots[a] || slots[String(a)] || { captain: null, pick1: null, pick2: null, backup: null };
      const activeAlliance = focusedAlliance === a;
      const matchupIndex = matchupSelected.indexOf(a);
      const epa = allianceNeptuneEpa(row);
      const epaText = epa.rated ? num(epa.subtotal, 1) : '—';
      const epaTitle = epa.members
        ? `Sum of ${epa.rated} of ${epa.members} selected field robot${epa.members === 1 ? '' : 's'} with Neptune EPA. Backup is excluded.`
        : 'Add field robots to build the alliance Neptune EPA subtotal.';
      html += `<article class="alliance-alliance ${activeAlliance ? 'active' : ''} ${matchupIndex >= 0 ? 'matchup-selected' : ''}" data-alliance="${a}">
        <div class="alliance-alliance-head">
          <span class="alliance-alliance-title">Alliance ${a}</span>
          <div class="alliance-alliance-head-actions">
            <span class="alliance-card-epa" title="${esc(epaTitle)}"><small>Nep. EPA</small><b>${esc(epaText)}</b></span>
            <button type="button" class="alliance-potential-button" data-potential-alliance="${a}" title="View Alliance ${a} offensive potential"><i class="fa-solid fa-chart-line"></i><span>Potential</span></button>
            <button type="button" class="alliance-matchup-selector ${matchupIndex >= 0 ? 'active' : ''}" data-matchup-alliance="${a}" aria-pressed="${matchupIndex >= 0 ? 'true' : 'false'}" title="${matchupIndex >= 0 ? 'Remove from matchup' : 'Add to matchup'}">${matchupIndex >= 0 ? matchupIndex + 1 : 'VS'}</button>
            <span class="alliance-alliance-number">${a}</span>
          </div>
        </div>
        <div class="alliance-slot-grid">`;
      for (const slot of slotOrder) {
        const team = Number(row[slot] || 0);
        const info = teamByNumber(team);
        const isCaptain = slot === 'captain';
        const active = !isCaptain && activeSlot?.alliance === a && activeSlot?.slot === slot;
        html += `<div role="button" tabindex="0" draggable="${team && can ? 'true' : 'false'}" class="alliance-slot ${isCaptain ? 'captain' : ''} ${team ? 'filled' : ''} ${active ? 'active' : ''}" data-alliance="${a}" data-slot="${slot}" data-team="${team || ''}" ${isCaptain ? 'title="Captain from TBA qualification rank"' : ''}>
          <small>${esc(slotLabels[slot])}</small>
          <strong>${team ? `#${team}` : '—'}</strong>
          <span>${team ? esc(info?.nickname || `Team ${team}`) : (isCaptain ? 'Waiting for TBA rankings' : 'Select a team')}</span>
          ${team && can && !isCaptain ? `<button type="button" class="alliance-slot-clear" aria-label="Remove team ${team} from Alliance ${a} ${esc(slotLabels[slot])}" title="Remove from alliance"><i class="fa-solid fa-xmark"></i></button>` : ''}
          ${team ? `<button type="button" class="alliance-slot-expand" aria-label="Open team ${team} intelligence" title="Open team intelligence"><i class="fa-solid fa-up-right-from-square"></i></button>` : ''}
        </div>`;
      }
      html += '</div></article>';
    }
    els.board.innerHTML = html;
    els.board.querySelectorAll('.alliance-matchup-selector').forEach(button => button.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      toggleMatchupAlliance(Number(button.dataset.matchupAlliance || 0));
    }));
    els.board.querySelectorAll('[data-potential-alliance]').forEach(button => button.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      openPotentialDialog(Number(button.dataset.potentialAlliance || 1));
    }));
    els.board.querySelectorAll('.alliance-alliance').forEach(card => card.addEventListener('click', event => {
      if (event.target.closest('button,.alliance-slot')) return;
      focusedAlliance = Number(card.dataset.alliance || 1);
      renderBoard();
      renderPotential();
    }));
    els.board.querySelectorAll('.alliance-slot').forEach(slot => {
      const isCaptain = slot.dataset.slot === 'captain';
      slot.addEventListener('click', event => {
        if (event.target.closest('.alliance-slot-clear, .alliance-slot-expand')) return;
        const alliance = Number(slot.dataset.alliance);
        const team = Number(slot.dataset.team || 0);
        focusedAlliance = alliance;
        if (!isCaptain) activeSlot = { alliance, slot: slot.dataset.slot };
        if (team) selectTeam(team, 0, true);
        renderBoard(); renderTeamList(); renderActionBar(); renderPotential();
      });
      slot.addEventListener('dragstart', event => {
        const team = Number(slot.dataset.team || 0);
        if (!team || !editable()) { event.preventDefault(); return; }
        setTeamDragData(event, team);
      });
      slot.addEventListener('keydown', event => {
        if (event.target !== slot || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        const alliance = Number(slot.dataset.alliance);
        const team = Number(slot.dataset.team || 0);
        focusedAlliance = alliance;
        if (!isCaptain) activeSlot = { alliance, slot: slot.dataset.slot };
        if (team) selectTeam(team, 0, true);
        renderBoard();
        renderTeamList();
        renderActionBar();
        renderPotential();
      });

      slot.addEventListener('dblclick', event => {
        if (event.target.closest('.alliance-slot-clear, .alliance-slot-expand')) return;
        const team = Number(slot.dataset.team || 0);
        if (!team) return;
        focusedAlliance = Number(slot.dataset.alliance || focusedAlliance);
        selectTeam(team, 0, true);
        renderBoard(); renderPotential();
      });
      if (!isCaptain) {
        slot.addEventListener('dragover', event => { if (editable()) event.preventDefault(); });
        slot.addEventListener('drop', event => {
          if (!editable()) return;
          event.preventDefault();
          const team = Number(event.dataTransfer.getData('text/plain') || 0);
          if (!team) return;
          activeSlot = { alliance: Number(slot.dataset.alliance), slot: slot.dataset.slot };
          focusedAlliance = activeSlot.alliance;
          setSelection(team);
        });
      }
    });
    els.board.querySelectorAll('.alliance-slot-expand').forEach(expand => {
      const open = event => {
        event.preventDefault(); event.stopPropagation();
        const slot = expand.closest('.alliance-slot');
        const team = Number(slot?.dataset.team || 0);
        if (!slot || !team) return;
        focusedAlliance = Number(slot.dataset.alliance || focusedAlliance);
        selectTeam(team, 0, true);
        renderBoard(); renderPotential();
      };
      expand.addEventListener('click', open);
      expand.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') open(event); });
    });
    els.board.querySelectorAll('.alliance-slot-clear').forEach(clear => clear.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      const slot = clear.closest('.alliance-slot');
      if (!slot || slot.dataset.slot === 'captain') return;
      mutate('set_slot', { draft_id: state.draft.id, alliance_number: slot.dataset.alliance, slot_type: slot.dataset.slot, team: 0 }, { queueable: true });
    }));
  }


  function renderCaptainPickToggle() {
    if (!els.captainPickToggle) return;
    const allowed = captainPicksAllowed();
    els.captainPickToggle.setAttribute('aria-checked', allowed ? 'true' : 'false');
    els.captainPickToggle.classList.toggle('active', allowed);
    if (els.captainPickToggleLabel) els.captainPickToggleLabel.textContent = allowed ? 'On' : 'Off';
    els.captainPickToggle.title = allowed
      ? 'Eligible lower-seeded alliance captains may be selected by higher-seeded alliances until their alliance makes a pick.'
      : 'Alliance captains cannot be selected by another alliance.';
  }

  function allianceTagsForTeam(team) {
    const raw = state?.alliance_tags?.[team] ?? state?.alliance_tags?.[String(team)] ?? [];
    return Array.isArray(raw) ? raw.filter(id => allianceTagById.has(String(id))).map(String) : [];
  }

  function allianceTagChips(team, compact = false) {
    const ids = allianceTagsForTeam(team);
    if (!ids.length) return '';
    const visible = compact ? ids.slice(0, 3) : ids;
    const chips = visible.map(id => {
      const tag = allianceTagById.get(id);
      return `<span class="alliance-strategy-tag ${compact ? 'compact' : ''}" title="${esc(tag.hint)}"><i class="${esc(tag.icon)}"></i><span>${esc(tag.label)}</span></span>`;
    }).join('');
    return chips + (compact && ids.length > visible.length ? `<span class="alliance-strategy-tag compact more" title="${esc(ids.slice(visible.length).map(id => allianceTagById.get(id)?.label || id).join(', '))}">+${ids.length - visible.length}</span>` : '');
  }

  function setTeamDragData(event, team) {
    const value = String(Number(team || 0));
    if (!value || value === '0') return;
    event.dataTransfer.effectAllowed = 'copy';
    event.dataTransfer.setData('text/plain', value);
    try { event.dataTransfer.setData('application/x-neptune-team', value); } catch (_) {}
  }

  function readDraggedTeam(event) {
    return Number(event.dataTransfer.getData('application/x-neptune-team') || event.dataTransfer.getData('text/plain') || 0);
  }

  function renderAllianceTags() {
    if (!els.selectedTeamTags || !selectedTeam) return;
    const chosen = new Set(allianceTagsForTeam(selectedTeam));
    els.selectedTeamTags.innerHTML = `<div class="alliance-tags-tab-head"><div><h3>Alliance Tags</h3><p>Mark how this robot fits your alliance-selection plan. Tags appear directly on its Team Pool card.</p></div><span class="pill"><i class="fa-solid fa-robot"></i> #${selectedTeam}</span></div><div class="alliance-tag-choice-grid">${allianceTagDefinitions.map(tag => `<button type="button" class="alliance-tag-choice ${chosen.has(tag.id) ? 'active' : ''}" data-alliance-tag="${esc(tag.id)}" aria-pressed="${chosen.has(tag.id) ? 'true' : 'false'}" ${editable() ? '' : 'disabled'}><i class="${esc(tag.icon)}"></i><span><b>${esc(tag.label)}</b><small>${esc(tag.hint)}</small></span><i class="fa-solid fa-check alliance-tag-choice-check"></i></button>`).join('')}</div>${editable() ? '<p class="alliance-tags-tab-note"><i class="fa-solid fa-circle-info"></i> Select as many tags as are useful. Changes save immediately to this scenario.</p>' : '<p class="alliance-tags-tab-note">Alliance tags are read-only for your account.</p>'}`;
    els.selectedTeamTags.querySelectorAll('[data-alliance-tag]').forEach(button => button.addEventListener('click', async () => {
      if (!editable() || !state?.draft) return;
      const tagId = String(button.dataset.allianceTag || '');
      const next = new Set(allianceTagsForTeam(selectedTeam));
      if (next.has(tagId)) next.delete(tagId); else next.add(tagId);
      await mutate('set_team_tags', { draft_id: state.draft.id, team: selectedTeam, tags_json: JSON.stringify([...next]) }, { queueable: true, toast: false });
    }));
  }

  function pickListLabel(tier) { return `${tier}${tier === '1' ? 'st' : tier === '2' ? 'nd' : tier === '3' ? 'rd' : 'th'} Picks`; }

  function renderPickDrawer() {
    if (!els.pickLists) return;
    // Each pick tier owns its own scroll position so all six slots stay reachable
    // without forcing the entire drawer to move. Preserve positions when state
    // rerenders after a drop/clear so strategists do not jump back to slot 1.
    const tierScroll = {};
    els.pickLists.querySelectorAll('.alliance-pick-list-tier').forEach(section => {
      const tier = String(section.className.match(/tier-(\d)/)?.[1] || '');
      const scroller = section.querySelector('.alliance-pick-list-slots');
      if (tier && scroller) tierScroll[tier] = scroller.scrollTop;
    });
    const lists = state?.pick_lists || {};
    els.pickLists.innerHTML = pickListTiers.map(tier => {
      const rows = Array.isArray(lists[tier]) ? lists[tier] : [];
      return `<section class="alliance-pick-list-tier tier-${tier}"><div class="alliance-pick-list-tier-head"><span>${esc(pickListLabel(tier))}</span><small>6 slots</small></div><div class="alliance-pick-list-slots">${Array.from({ length: 6 }, (_, index) => {
        const team = Number(rows[index] || 0);
        const info = team ? teamByNumber(team) : null;
        const selectedAdd = !team && selectedTeam ? `<button type="button" class="alliance-pick-use-selected" data-pick-use-selected="1" title="Add selected team #${selectedTeam}"><i class="fa-solid fa-plus"></i> #${selectedTeam}</button>` : '';
        return `<div class="alliance-pick-list-slot ${team ? 'filled' : ''}" data-pick-tier="${tier}" data-pick-index="${index}" data-pick-team="${team || ''}"><span class="alliance-pick-list-position">${index + 1}</span>${team ? `<button type="button" class="alliance-pick-list-team" data-open-pick-team="${team}" draggable="${editable() ? 'true' : 'false'}"><b>#${team}</b><span>${esc(info?.nickname || 'FRC Team')}</span><small>Nep. EPA ${neptuneEpa(info) == null ? '—' : num(neptuneEpa(info), 1)}</small></button><button type="button" class="alliance-pick-list-clear" data-pick-clear aria-label="Clear ${esc(pickListLabel(tier))} slot ${index + 1}" ${editable() ? '' : 'disabled'}><i class="fa-solid fa-xmark"></i></button>` : `<span class="alliance-pick-list-empty"><i class="fa-solid fa-arrow-down"></i> Drop robot here</span>${selectedAdd}`}</div>`;
      }).join('')}</div></section>`;
    }).join('');

    els.pickLists.querySelectorAll('.alliance-pick-list-tier').forEach(section => {
      const tier = String(section.className.match(/tier-(\d)/)?.[1] || '');
      const scroller = section.querySelector('.alliance-pick-list-slots');
      if (tier && scroller && Number.isFinite(tierScroll[tier])) scroller.scrollTop = tierScroll[tier];
      if (!scroller) return;
      scroller.addEventListener('dragover', event => {
        if (!editable()) return;
        const rect = scroller.getBoundingClientRect();
        const edge = Math.min(46, rect.height * 0.3);
        if (event.clientY < rect.top + edge) scroller.scrollTop -= 13;
        else if (event.clientY > rect.bottom - edge) scroller.scrollTop += 13;
      });
    });

    els.pickLists.querySelectorAll('.alliance-pick-list-slot').forEach(slot => {
      slot.addEventListener('dragover', event => { if (editable()) { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; slot.classList.add('drag-over'); } });
      slot.addEventListener('dragleave', () => slot.classList.remove('drag-over'));
      slot.addEventListener('drop', async event => {
        slot.classList.remove('drag-over');
        if (!editable()) return;
        event.preventDefault();
        const team = readDraggedTeam(event);
        if (!team) return;
        await mutate('set_pick_list_slot', { draft_id: state.draft.id, tier: slot.dataset.pickTier, slot_index: slot.dataset.pickIndex, team }, { queueable: true, toast: false });
      });
    });
    els.pickLists.querySelectorAll('[data-pick-clear]').forEach(button => button.addEventListener('click', async event => {
      event.stopPropagation();
      if (!editable()) return;
      const slot = button.closest('.alliance-pick-list-slot');
      await mutate('set_pick_list_slot', { draft_id: state.draft.id, tier: slot.dataset.pickTier, slot_index: slot.dataset.pickIndex, team: 0 }, { queueable: true, toast: false });
    }));
    els.pickLists.querySelectorAll('[data-pick-use-selected]').forEach(button => button.addEventListener('click', async () => {
      if (!editable() || !selectedTeam) return;
      const slot = button.closest('.alliance-pick-list-slot');
      await mutate('set_pick_list_slot', { draft_id: state.draft.id, tier: slot.dataset.pickTier, slot_index: slot.dataset.pickIndex, team: selectedTeam }, { queueable: true, toast: false });
    }));
    els.pickLists.querySelectorAll('[data-open-pick-team]').forEach(button => {
      button.addEventListener('click', () => selectTeam(Number(button.dataset.openPickTeam), 0, true));
      button.addEventListener('dragstart', event => setTeamDragData(event, Number(button.dataset.openPickTeam)));
    });
  }

  function setPickDrawerOpen(open) {
    const active = !!open;
    els.pickDrawer?.classList.toggle('open', active);
    els.pickDrawer?.setAttribute('aria-hidden', active ? 'false' : 'true');
    els.pickDrawerBtn?.setAttribute('aria-expanded', active ? 'true' : 'false');
    app.classList.toggle('pick-drawer-open', active);
    if (active) renderPickDrawer();
  }

  function renderTeamList() {
    if (!els.teamList) return;
    const query = (els.teamSearch?.value || '').trim().toLowerCase();
    const all = [...(state?.teams || [])];
    const filtered = all.filter(team => {
      const st = effectiveStatusFor(team.team);
      if (filter === 'favorite' && !st.favorite) return false;
      if (filter === 'available' && st.state !== 'available') return false;
      if (query && !String(team.team).includes(query) && !String(team.nickname || '').toLowerCase().includes(query)) return false;
      return true;
    });
    const label = state?.ranking?.label || 'Points';
    els.teamPoolSummary.textContent = `${filtered.length} of ${all.length} teams · sorted by TBA ${label}`;
    if (!filtered.length) {
      els.teamList.innerHTML = '<div class="alliance-empty-small" style="grid-column:1/-1">No teams match this filter.</div>';
      return;
    }
    els.teamList.innerHTML = filtered.map(team => {
      const st = effectiveStatusFor(team.team);
      const isSelected = Number(selectedTeam) === Number(team.team);
      const rank = Number(team.rank || 0);
      const captainAlliance = captainAllianceFor(team.team);
      const captain = captainAlliance > 0;
      const record = `${Number(team.wins || 0)}-${Number(team.losses || 0)}-${Number(team.ties || 0)}`;
      return `<div draggable="${editable() ? 'true' : 'false'}" class="alliance-team-row state-${esc(st.state)} ${isSelected ? 'selected' : ''}" data-team="${team.team}">
        <span class="alliance-team-rank">${rank ? `#${rank}` : '—'}</span>
        <span class="alliance-team-copy">
          <b>#${team.team} ${captain ? `<i class="fa-solid fa-anchor alliance-team-captain" title="Alliance ${captainAlliance} captain"></i>` : ''} ${st.favorite ? '<i class="fa-solid fa-star alliance-team-favorite"></i>' : ''}</b>
          <span class="alliance-team-name">${esc(team.nickname || 'FRC Team')}</span>
          <span class="alliance-team-strategy-tags">${allianceTagChips(team.team, true)}</span>
        </span>
        <span class="alliance-team-tail">
          <span class="alliance-team-metrics">
            <span class="alliance-team-record" title="Qualification record">${record}</span>
            <span class="alliance-team-epa" title="Neptune EPA"><span>Nep. EPA</span><b>${neptuneEpa(team) == null ? '—' : num(neptuneEpa(team), 1)}</b></span>
            <i class="fa-solid ${stateIcon(st.state)} alliance-team-state-icon" title="${esc(stateLabel(st.state))}"></i>
          </span>
        </span>
        <button type="button" class="alliance-team-expand" data-expand-team="${team.team}" aria-label="Open team intelligence for team ${team.team}" title="Open team intelligence"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
      </div>`;
    }).join('');
    els.teamList.querySelectorAll('[data-team]').forEach(row => {
      row.addEventListener('dragstart', event => setTeamDragData(event, Number(row.dataset.team)));
    });
    els.teamList.querySelectorAll('[data-expand-team]').forEach(button => button.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      selectTeam(Number(button.dataset.expandTeam), 0, true);
    }));
  }

  function decisionTrend(value, unit) {
    const n = Number(value || 0);
    const flat = Math.abs(n) < 0.1;
    const cls = flat ? 'flat' : n > 0 ? 'up' : 'down';
    const icon = flat ? 'fa-minus' : n > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    const text = flat ? 'Flat' : `${n > 0 ? '+' : ''}${num(n, 1)} ${unit}/match`;
    return `<span class="alliance-decision-trend ${cls}"><i class="fa-solid ${icon}"></i>${esc(text)}</span>`;
  }

  function spotTagChip(tag, count = null) {
    const severity = ['positive','info','warning','critical'].includes(String(tag?.severity || '')) ? String(tag.severity) : 'info';
    const icon = String(tag?.icon || 'fa-solid fa-tag');
    const label = String(tag?.label || 'Tag');
    return `<span class="alliance-spot-tag severity-${esc(severity)}"><i class="${esc(icon)}"></i>${esc(label)}${count == null ? '' : `<b>×${Number(count || 0)}</b>`}</span>`;
  }

  function spotContextLabel(obs) {
    if (obs?.match_label) return String(obs.match_label);
    const context = String(obs?.context || 'general');
    if (context === 'pit') return 'Pit';
    if (context === 'match') return 'Match';
    return 'General';
  }

  function spotMediaHtml(media, compact = false) {
    const rows = Array.isArray(media) ? media : [];
    if (!rows.length) return '';
    const max = compact ? 4 : 24;
    return `<div class="alliance-spot-media ${compact ? 'compact' : ''}">${rows.slice(0, max).map(item => {
      const url = esc(item.url || '');
      if (!url) return '';
      if (String(item.media_type || '') === 'video') {
        return compact
          ? `<a class="alliance-spot-media-item video" href="${url}" target="_blank" rel="noopener" title="${esc(item.original_filename || 'Spot scouting video')}"><i class="fa-solid fa-circle-play"></i><span>Video</span></a>`
          : `<div class="alliance-spot-media-item video"><video controls preload="metadata" src="${url}"></video><span>${esc(item.original_filename || 'Spot scouting video')}</span></div>`;
      }
      return `<a class="alliance-spot-media-item photo" href="${url}" target="_blank" rel="noopener"><img src="${url}" loading="lazy" alt="${esc(item.original_filename || 'Spot scouting photo')}"><span>${esc(item.original_filename || 'Photo')}</span></a>`;
    }).join('')}</div>`;
  }

  function spotObservationHtml(obs, compact = false) {
    const tags = Array.isArray(obs?.tags) ? obs.tags : [];
    const media = Array.isArray(obs?.media) ? obs.media : [];
    const context = spotContextLabel(obs);
    const status = String(obs?.status || 'open');
    const severity = ['positive','info','warning','critical'].includes(String(obs?.severity || '')) ? String(obs.severity) : 'info';
    const note = String(obs?.note || '').trim();
    const scout = String(obs?.scout_name || '').trim();
    const created = String(obs?.created_at || '').trim();
    const tagHtml = tags.length ? `<div class="alliance-spot-tags">${tags.map(t => spotTagChip(t)).join('')}</div>` : '';
    const mediaHtml = spotMediaHtml(media, compact);
    return `<article class="alliance-spot-observation severity-${esc(severity)} ${status === 'resolved' ? 'resolved' : ''}">
      <div class="alliance-spot-observation-head">
        <div><b>${esc(context)}</b>${obs?.event_name ? `<span>${esc(obs.event_name)}</span>` : ''}</div>
        <span class="alliance-spot-status ${status === 'resolved' ? 'resolved' : 'open'}">${status === 'resolved' ? 'Resolved' : 'Open'}</span>
      </div>
      ${tagHtml}
      ${note ? `<p>${esc(note)}</p>` : ''}
      ${mediaHtml}
      <div class="alliance-spot-observation-meta">${scout ? `<span><i class="fa-solid fa-user"></i> ${esc(scout)}</span>` : ''}${created ? `<span><i class="fa-regular fa-clock"></i> ${esc(created)}</span>` : ''}${obs?.resolution_note ? `<span><i class="fa-solid fa-check"></i> ${esc(obs.resolution_note)}</span>` : ''}</div>
    </article>`;
  }

  function spotSummaryHtml(detail) {
    const spot = detail?.spot || {};
    if (!spot.available) {
      return `<section class="alliance-modal-spot-summary"><div class="alliance-modal-spot-head"><div><span>SPOT SCOUTING</span><h3>Spot observations</h3></div></div><div class="alliance-empty-small">Spot Scouting is not installed or available.</div></section>`;
    }
    const observations = Array.isArray(spot.observations) ? spot.observations : [];
    const tags = Array.isArray(spot.tag_counts) ? spot.tag_counts : [];
    const media = observations.flatMap(o => Array.isArray(o.media) ? o.media : []);
    const warning = Number(spot.open_warning_count || 0);
    const spotUrl = cfg.spotPage ? `${cfg.spotPage}${cfg.spotPage.includes('?') ? '&' : '?'}event_id=${encodeURIComponent(cfg.eventId)}&team=${encodeURIComponent(detail?.team?.number || '')}` : '';
    return `<section class="alliance-modal-spot-summary">
      <div class="alliance-modal-spot-head">
        <div><span>SPOT SCOUTING</span><h3>Spot observations</h3></div>
        <div class="alliance-modal-spot-counts">${warning ? `<span class="warning"><i class="fa-solid fa-triangle-exclamation"></i>${warning} open warning${warning === 1 ? '' : 's'}</span>` : '<span><i class="fa-solid fa-check"></i>No open warnings</span>'}${spotUrl ? `<a href="${esc(spotUrl)}"><i class="fa-solid fa-binoculars"></i> Open Spot Scouting</a>` : ''}</div>
      </div>
      ${tags.length ? `<div class="alliance-spot-tags summary">${tags.slice(0, 8).map(t => spotTagChip(t, t.count)).join('')}</div>` : ''}
      ${observations.length ? `<div class="alliance-modal-spot-recent">${observations.slice(0, 3).map(o => spotObservationHtml(o, true)).join('')}</div>` : '<div class="alliance-empty-small">No spot observations for this team at this event yet.</div>'}
      ${media.length ? spotMediaHtml(media, true) : ''}
    </section>`;
  }

  function spotTabHtml(detail) {
    const spot = detail?.spot || {};
    if (!spot.available) return '<div class="alliance-empty-small">Spot Scouting is not installed or available.</div>';
    const observations = Array.isArray(spot.observations) ? spot.observations : [];
    const tags = Array.isArray(spot.tag_counts) ? spot.tag_counts : [];
    const warning = Number(spot.open_warning_count || 0);
    const photoCount = Number(spot.photo_count || 0);
    const videoCount = Number(spot.video_count || 0);
    const summary = `<div class="alliance-kpis">
      ${kpi(observations.length, 'Observations')}
      ${kpi(tags.length, 'Unique tags')}
      ${kpi(warning, 'Open warnings')}
      ${kpi(photoCount, 'Photos')}
      ${kpi(videoCount, 'Videos')}
      ${kpi(spot.latest_at || '—', 'Latest observation')}
    </div>`;
    const tagCloud = tags.length
      ? `<section class="alliance-detail-section"><h3>Tag summary</h3><div class="alliance-spot-tags">${tags.map(t => spotTagChip(t, t.count)).join('')}</div></section>`
      : '';
    const feed = observations.length
      ? `<section class="alliance-detail-section"><h3>Spot scouting feed</h3><div class="alliance-spot-feed">${observations.map(o => spotObservationHtml(o, false)).join('')}</div></section>`
      : '<div class="alliance-empty-small">No spot observations for this team at this event yet.</div>';
    return summary + tagCloud + feed;
  }

  function tbaProfileHtml(detail) {
    const profile = detail?.tba_profile || {};
    const team = detail?.team || {};
    const number = Number(team.number || selectedTeam || 0);
    const nickname = String(profile.nickname || team.nickname || '').trim();
    const location = String(profile.location || [team.city, team.state_prov, team.country].filter(Boolean).join(', ')).trim();
    const fullName = String(profile.full_name || '').trim();
    const district = profile.district && typeof profile.district === 'object' ? profile.district : null;
    const avatar = String(profile.avatar || '').trim();
    const mapsUrl = String(profile.maps_url || '').trim();
    const website = String(profile.website || '').trim();
    const tbaUrl = String(profile.tba_url || (number ? `https://www.thebluealliance.com/team/${number}` : '')).trim();
    const firstUrl = String(profile.first_url || (number ? `https://frc-events.firstinspires.org/team/${number}` : '')).trim();
    const rookieYear = Number(profile.rookie_year || 0);
    const lastCompeted = Number(profile.last_competed || 0);

    const districtLine = district?.name
      ? `<div class="alliance-tba-profile-line"><i class="fa-solid fa-map"></i><span>Part of the ${district.url ? `<a href="${esc(district.url)}" target="_blank" rel="noopener">${esc(district.name)}</a>` : `<b>${esc(district.name)}</b>`}</span></div>`
      : '';
    const locationLine = location
      ? `<div class="alliance-tba-profile-line"><i class="fa-solid fa-location-dot"></i><span>From ${mapsUrl ? `<a href="${esc(mapsUrl)}" target="_blank" rel="noopener">${esc(location)}</a>` : `<b>${esc(location)}</b>`}</span></div>`
      : '';
    const akaLine = fullName
      ? `<div class="alliance-tba-profile-line alliance-tba-profile-aka"><i class="fa-solid fa-users"></i><span>aka <em>${esc(fullName)}</em></span></div>`
      : '';
    const facts = [
      rookieYear ? `<span><b>Rookie Year:</b> ${rookieYear}</span>` : '',
      lastCompeted ? `<span><b>Last competed in:</b> ${lastCompeted}</span>` : ''
    ].filter(Boolean).join('');
    const links = [
      tbaUrl ? `<a class="secondary compact" href="${esc(tbaUrl)}" target="_blank" rel="noopener"><i class="fa-solid fa-bolt"></i> TBA</a>` : '',
      firstUrl ? `<a class="secondary compact" href="${esc(firstUrl)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> FIRST Details</a>` : '',
      website ? `<a class="secondary compact" href="${esc(website)}" target="_blank" rel="noopener"><i class="fa-solid fa-globe"></i> Team Website</a>` : ''
    ].filter(Boolean).join('');

    return `<section class="alliance-tba-profile">
      <div class="alliance-tba-profile-avatar">${avatar ? `<img src="${esc(avatar)}" alt="Team ${number} logo">` : '<i class="fa-solid fa-robot"></i>'}</div>
      <div class="alliance-tba-profile-copy">
        <div class="alliance-tba-profile-heading"><span>THE BLUE ALLIANCE</span><h3>#${number}${nickname ? ` · ${esc(nickname)}` : ''}</h3></div>
        <div class="alliance-tba-profile-lines">${districtLine}${locationLine}${akaLine}</div>
        ${facts ? `<div class="alliance-tba-profile-facts">${facts}</div>` : ''}
        ${links ? `<div class="alliance-tba-profile-links">${links}</div>` : ''}
      </div>
    </section>`;
  }

  function renderDecisionSummary() {
    if (!els.selectedTeamDecision || !selectedTeam) return;
    const roster = teamByNumber(selectedTeam);
    const detail = Number(paneState[0]?.team) === Number(selectedTeam) ? paneState[0]?.data : null;
    if (!detail) {
      els.selectedTeamDecision.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading decision data…</div>';
      return;
    }

    const rows = qualificationTrendRows(detail);
    const offense = rows.map(row => Number(row.scout_points || 0));
    const defense = rows.map(row => Number(row.defense || 0));
    const offSlope = trendSlope(offense.slice(-6));
    const defSlope = trendSlope(defense.slice(-6));
    const recentOff = offense.slice(-3);
    const recentDef = defense.slice(-3);
    const recentPpm = recentOff.length ? mean(recentOff) : Number(detail.event_stats?.ppm || 0);
    const recentDefense = recentDef.length ? mean(recentDef) : Number(detail.event_stats?.defense_per_match || 0);
    const record = roster ? `${Number(roster.wins || 0)}-${Number(roster.losses || 0)}-${Number(roster.ties || 0)}` : '—';
    const rank = Number(roster?.rank || 0);
    const rankingLabel = state?.ranking?.label || 'Ranking Score';
    const rankingPoints = roster ? num(roster.points, Number(state?.ranking?.precision ?? 2)) : '—';
    const success = Number(detail.event_stats?.success_rate || 0);

    els.selectedTeamDecision.innerHTML = `
      ${tbaProfileHtml(detail)}
      <div class="alliance-team-decision-grid">
        <div class="alliance-decision-kpi"><span>TBA rank</span><b>${rank ? `#${rank}` : '—'}</b><small>${esc(rankingPoints)} ${esc(rankingLabel)}</small></div>
        <div class="alliance-decision-kpi"><span>Qualification record</span><b>${esc(record)}</b><small>${rows.length} scouted qual${rows.length === 1 ? '' : 's'}</small></div>
        <div class="alliance-decision-kpi"><span>Event PPM</span><b>${num(detail.event_stats?.ppm, 1)}</b><small>Last 3: ${num(recentPpm, 1)}</small></div>
        <div class="alliance-decision-kpi"><span>Defense / match</span><b>${num(detail.event_stats?.defense_per_match, 1)}</b><small>Last 3: ${num(recentDefense, 1)}</small></div>
        <div class="alliance-decision-kpi"><span>Offense trend</span><b>${decisionTrend(offSlope, 'pts')}</b><small>Last 6 scouted quals</small></div>
        <div class="alliance-decision-kpi"><span>Defense trend</span><b>${decisionTrend(defSlope, 'actions')}</b><small>Last 6 scouted quals</small></div>
        <div class="alliance-decision-kpi"><span>Offense success</span><b>${num(success, 0)}%</b><small>${Number(detail.event_stats?.actions || 0)} recorded actions</small></div>
        <div class="alliance-decision-kpi"><span>Public EPA</span><b>${detail.epa_rating?.epa == null ? '—' : num(detail.epa_rating.epa, 1)}</b><small>${detail.epa_rating?.confidence == null ? 'EPA not built' : `${num(detail.epa_rating.confidence, 0)}% public-data confidence`}</small></div>
        <div class="alliance-decision-kpi"><span>Neptune EPA</span><b>${neptuneEpa(detail.epa_rating) == null ? '—' : num(neptuneEpa(detail.epa_rating), 1)}</b><small>${Number(detail.epa_rating?.scouting_matches || 0)} Neptune match${Number(detail.epa_rating?.scouting_matches || 0) === 1 ? '' : 'es'} blended</small></div>
        <div class="alliance-decision-kpi"><span>EPA trend</span><b>${detail.epa_rating?.trend == null ? '—' : decisionTrend(Number(detail.epa_rating.trend), 'pts')}</b><small>TBA-derived rating trend</small></div>
        <div class="alliance-decision-kpi"><span>Scouting sample</span><b>${rows.length}</b><small>qualification matches</small></div>
      </div>`;
  }

  function setSelectedModalTab(tab) {
    const detailTabs = new Set(tabLabels.map(([id]) => id));
    selectedModalTab = tab === 'matches' || tab === 'tags' || detailTabs.has(tab) ? tab : 'overview';
    if (detailTabs.has(selectedModalTab)) paneState[0].tab = selectedModalTab;

    els.selectedTeamModalTabs?.querySelectorAll('[data-selected-modal-tab]').forEach(button => {
      const active = button.dataset.selectedModalTab === selectedModalTab;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    const panelMode = selectedModalTab === 'overview' ? 'overview' : selectedModalTab === 'tags' ? 'tags' : selectedModalTab === 'matches' ? 'matches' : 'detail';
    document.querySelectorAll('[data-selected-modal-panel]').forEach(panel => {
      const active = panel.dataset.selectedModalPanel === panelMode;
      panel.hidden = !active;
      panel.classList.toggle('active', active);
    });

    if (panelMode === 'tags') renderAllianceTags();
    if (panelMode === 'matches') renderSelectedTeamMatches();
    if (panelMode === 'detail') renderDetail(0);
    els.teamActionBar?.querySelector('.alliance-team-intelligence-body')?.scrollTo({ top: 0, behavior: 'auto' });
  }


  function renderSelectedTeamMatches() {
    if (!els.selectedTeamMatches || !selectedTeam) return;
    const detail = Number(paneState[0]?.team) === Number(selectedTeam) ? paneState[0]?.data : null;
    if (!detail) {
      els.selectedTeamMatches.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading match history…</div>';
      return;
    }
    const matches = Array.isArray(detail.tba_matches) ? detail.tba_matches : [];
    if (!matches.length) {
      els.selectedTeamMatches.innerHTML = `<div class="alliance-modal-matches-empty"><i class="fa-solid fa-film"></i><b>No TBA match videos yet</b><span>${esc(detail.tba_matches_error || 'No matches with this team are available from The Blue Alliance for this event yet.')}</span></div>`;
      return;
    }
    els.selectedTeamMatches.innerHTML = `<div class="alliance-modal-match-list">${matches.map(match => {
      const alliance = String(match.alliance || '').toLowerCase();
      const ownScore = alliance === 'red' ? match.red_score : match.blue_score;
      const opponentScore = alliance === 'red' ? match.blue_score : match.red_score;
      const result = String(match.result || '').toUpperCase();
      const resultLabel = result || (ownScore == null || opponentScore == null ? 'Upcoming' : 'T');
      const score = ownScore == null || opponentScore == null ? '—' : `${ownScore}–${opponentScore}`;
      const youtubeId = String(match.youtube_id || '').trim();
      const video = youtubeId
        ? `<a class="alliance-match-video" href="https://www.youtube.com/watch?v=${encodeURIComponent(youtubeId)}" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-youtube"></i> Watch</a>`
        : '<span class="alliance-match-video unavailable"><i class="fa-regular fa-circle-play"></i> No video</span>';
      const ownTeams = alliance === 'red' ? (match.red_teams || []) : (match.blue_teams || []);
      const opponentTeams = alliance === 'red' ? (match.blue_teams || []) : (match.red_teams || []);
      return `<article class="alliance-modal-match-row">
        <div class="alliance-modal-match-main"><b>${esc(match.label || 'Match')}</b><span class="alliance-match-result result-${esc(result.toLowerCase() || 'pending')}">${esc(resultLabel)}</span><span class="alliance-match-alliance ${esc(alliance)}">${esc(alliance ? alliance[0].toUpperCase() + alliance.slice(1) : '—')}</span></div>
        <div class="alliance-modal-match-score"><strong>${esc(score)}</strong><span>team score – opponent</span></div>
        <div class="alliance-modal-match-teams"><span><b>With:</b> ${esc(ownTeams.map(t => `#${t}`).join(' · ') || '—')}</span><span><b>Vs:</b> ${esc(opponentTeams.map(t => `#${t}`).join(' · ') || '—')}</span></div>
        <div class="alliance-modal-match-watch">${video}</div>
      </article>`;
    }).join('')}</div><p class="alliance-modal-match-note"><i class="fa-solid fa-database"></i> Scores and video availability come from The Blue Alliance. Videos may appear after match results are posted.</p>`;
  }

  function renderActionBar() {
    if (!els.teamActionBar) return;
    if (!selectedTeam || !teamByNumber(selectedTeam)) {
      if (els.teamActionBar.open) els.teamActionBar.close();
      return;
    }
    const info = teamByNumber(selectedTeam);
    const rawStatus = statusFor(selectedTeam);
    const st = effectiveStatusFor(selectedTeam);
    els.selectedTeamLabel.textContent = `#${selectedTeam} ${info?.nickname || ''}`.trim();
    els.selectedTeamStatus.textContent = stateLabel(st.state);
    renderDecisionSummary();
    renderAllianceTags();
    renderSelectedTeamMatches();
    renderDetail(0);
    setSelectedModalTab(selectedModalTab);
    if (els.selectedTeamHint) {
      const captainLock = captainSelectionLockInfo(selectedTeam);
      els.selectedTeamHint.textContent = captainLock.locked
        ? captainLock.reason
        : (activeSlot
          ? `Target: Alliance ${activeSlot.alliance} · ${slotLabels[activeSlot.slot]}.`
          : 'Choose a slot on the Alliance Board, then select this team.');
    }
    if (els.makeSelection) {
      const hasSlot = !!activeSlot;
      els.makeSelection.disabled = !editable() || !hasSlot || st.state !== 'available' || activeSlot?.slot === 'captain';
      els.makeSelection.innerHTML = `<i class="fa-solid fa-user-plus"></i> ${activeSlot && activeSlot.slot !== 'captain' ? `Select for Alliance ${activeSlot.alliance} · ${slotLabels[activeSlot.slot]}` : 'Choose a pick slot'}`;
    }
    if (els.favorite) {
      els.favorite.classList.toggle('active', !!rawStatus.favorite);
      els.favorite.innerHTML = `<i class="${rawStatus.favorite ? 'fa-solid' : 'fa-regular'} fa-star"></i> Favorite`;
      els.favorite.disabled = !editable();
    }
    const selected = st.state === 'selected';
    for (const [button, value] of [[els.decline, 'declined'], [els.dnp, 'do_not_pick'], [els.broken, 'broken']]) {
      if (!button) continue;
      button.classList.toggle('active', st.state === value);
      button.disabled = !editable() || selected;
    }
  }

  function openSelectedTeamModal() {
    if (!els.teamActionBar || !selectedTeam) return;
    renderActionBar();
    if (typeof els.teamActionBar.showModal === 'function') {
      if (!els.teamActionBar.open) els.teamActionBar.showModal();
    } else {
      els.teamActionBar.setAttribute('open', '');
    }
  }

  function closeSelectedTeamModal() {
    if (!els.teamActionBar) return;
    if (typeof els.teamActionBar.close === 'function' && els.teamActionBar.open) els.teamActionBar.close();
    else els.teamActionBar.removeAttribute('open');
  }

  function renderAll() {
    renderDrafts();
    renderBoard();
    renderCaptainPickToggle();
    renderTeamList();
    renderActionBar();
    renderPotential();
    renderPickDrawer();
    updateOnlinePill();
  }

  function selectTeam(team, pane = 0, openModal = false) {
    selectedTeam = Number(team);
    if (openModal) selectedModalTab = 'overview';
    renderTeamList();
    renderActionBar();
    loadDetail(pane, selectedTeam);
    if (openModal) openSelectedTeamModal();
  }

  async function setSelection(team) {
    if (!editable() || !activeSlot || activeSlot.slot === 'captain' || !state?.draft) return;
    const lock = captainSelectionLockInfo(team);
    if (lock.locked) {
      ui.toast(lock.reason, 'warn');
      return;
    }
    const st = effectiveStatusFor(team);
    if (st.state !== 'available') {
      ui.toast(`Team ${team} is marked ${stateLabel(st.state)}. Change its status to Available before selecting it.`, 'warn');
      return;
    }
    const result = await mutate('set_slot', { draft_id: state.draft.id, alliance_number: activeSlot.alliance, slot_type: activeSlot.slot, team }, { queueable: true });
    if (result && state?.draft) { advanceSlot(); closeSelectedTeamModal(); }
  }

  function advanceSlot() {
    if (!activeSlot) return;

    // Follow the alliance-selection draft order instead of walking every slot
    // on one alliance before moving to the next. This lets a strategist stay
    // in the Team Pool (especially on mobile) while Neptune advances the
    // highlighted target in the background after each successful selection.
    //
    // First selections:  A1 -> A2 -> ... -> A8
    // Second selections: A8 -> A7 -> ... -> A1 (serpentine return)
    // Backup slots:      A1 -> A2 -> ... -> A8
    const draftOrder = [
      ...Array.from({ length: 8 }, (_, i) => ({ alliance: i + 1, slot: 'pick1' })),
      ...Array.from({ length: 8 }, (_, i) => ({ alliance: 8 - i, slot: 'pick2' })),
      ...Array.from({ length: 8 }, (_, i) => ({ alliance: i + 1, slot: 'backup' })),
    ];

    const currentIndex = draftOrder.findIndex(item =>
      Number(item.alliance) === Number(activeSlot.alliance) && item.slot === activeSlot.slot
    );
    if (currentIndex < 0) return;

    for (let i = currentIndex + 1; i < draftOrder.length; i++) {
      const next = draftOrder[i];
      const row = state?.slots?.[next.alliance] || state?.slots?.[String(next.alliance)] || {};
      if (!Number(row?.[next.slot] || 0)) {
        activeSlot = { alliance: next.alliance, slot: next.slot };
        focusedAlliance = next.alliance;
        renderBoard();
        renderTeamList();
        renderActionBar();
        renderPotential();
        return;
      }
    }
  }

  async function setTeamState(value) {
    if (!selectedTeam || !state?.draft || !editable()) return;
    const current = statusFor(selectedTeam).state;
    const next = current === value ? 'available' : value;
    await mutate('set_status', { draft_id: state.draft.id, team: selectedTeam, state: next }, { queueable: true });
  }

  async function fetchDetailData(team, force = false) {
    const key = `${cfg.eventId}:${Number(team)}`;
    const cached = detailCache.get(key);
    if (!force && cached && Date.now() - cached.at < 45000) return cached.data;
    if (!force && detailPromises.has(key)) return detailPromises.get(key);

    const request = (async () => {
      const url = new URL(cfg.detailEndpoint, window.location.href);
      url.searchParams.set('event_id', cfg.eventId);
      url.searchParams.set('team', team);
      const res = await fetchWithOfflineFallback(url.href, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not load team intelligence.');
      detailCache.set(key, { data, at: Date.now() });
      return data;
    })();

    detailPromises.set(key, request);
    try { return await request; }
    finally { detailPromises.delete(key); }
  }

  async function loadDetail(pane, team, force = false) {
    if (!team || !paneState[pane]) return;
    paneState[pane].team = Number(team);
    paneState[pane].data = null;

    if (Number(selectedTeam) === Number(team)) {
      if (els.selectedTeamDecision) els.selectedTeamDecision.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading decision data…</div>';
      if (els.selectedTeamMatches) els.selectedTeamMatches.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading match history…</div>';
      if (els.selectedTeamDetail) els.selectedTeamDetail.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot intelligence…</div>';
    }

    try {
      const data = await fetchDetailData(team, force);
      if (Number(paneState[pane].team) !== Number(team)) return;
      paneState[pane].data = data;
      if (Number(selectedTeam) === Number(team)) renderActionBar();
    } catch (error) {
      if (Number(paneState[pane].team) !== Number(team)) return;
      const message = esc(error.message || 'Could not load team intelligence.');
      if (els.selectedTeamDecision) els.selectedTeamDecision.innerHTML = `<div class="notice bad">${message}</div>`;
      if (els.selectedTeamMatches) els.selectedTeamMatches.innerHTML = `<div class="notice bad">${message}</div>`;
      if (els.selectedTeamDetail) els.selectedTeamDetail.innerHTML = `<div class="notice bad">${message}</div>`;
    }
  }


  function toggleMatchupAlliance(alliance) {
    if (!alliance || alliance < 1 || alliance > 8) return;
    const current = matchupSelected.indexOf(alliance);
    if (current >= 0) {
      matchupSelected.splice(current, 1);
      renderBoard();
      return;
    }
    if (matchupSelected.length >= 2) matchupSelected.shift();
    matchupSelected.push(alliance);
    renderBoard();
    if (matchupSelected.length === 2) openMatchup(matchupSelected[0], matchupSelected[1]);
  }

  function matchupAllianceName(number) {
    return `Alliance ${Number(number)}`;
  }

  function matchupTrendBadge(value, unit = 'pts/match') {
    const n = Number(value || 0);
    if (Math.abs(n) < 0.15) return `<span class="matchup-trend flat"><i class="fa-solid fa-minus"></i> Flat</span>`;
    return `<span class="matchup-trend ${n > 0 ? 'up' : 'down'}"><i class="fa-solid ${n > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'}"></i> ${n > 0 ? '+' : ''}${num(n, 1)} ${esc(unit)}</span>`;
  }

  function matchupTeamRows(alliance) {
    const teams = alliance?.teams || [];
    if (!teams.length) return '<div class="alliance-empty-small">No field teams selected.</div>';
    return teams.map(row => {
      const m = row.metrics || {};
      const suppression = Number(m.opponent_suppression || 0);
      const suppressionText = Math.abs(suppression) < 0.2 ? 'neutral' : suppression > 0 ? `${num(suppression, 1)} pts below expected` : `${num(Math.abs(suppression), 1)} pts above expected`;
      return `<div class="matchup-team-row">
        <div class="matchup-team-id"><b>#${row.team} ${esc(row.nickname || '')}</b><span>${m.matches || 0} scouted quals · ${m.wins || 0}-${m.losses || 0}${Number(m.ties || 0) ? `-${m.ties}` : ''}</span></div>
        <div class="matchup-team-kpi"><span>Public EPA</span><b>${num(m.epa, 1)}</b></div>
        <div class="matchup-team-kpi" title="Neptune EPA"><span>Nep. EPA</span><b>${num(neptuneEpa(m), 1)}</b></div>
        <div class="matchup-team-kpi"><span>Slope</span><b>${matchupTrendBadge(m.slope)}</b></div>
        <div class="matchup-team-kpi"><span>Opponent impact</span><b class="${suppression > .2 ? 'good-text' : suppression < -.2 ? 'bad-text' : ''}">${esc(suppressionText)}</b></div>
        <div class="matchup-team-kpi"><span>Defense / match</span><b>${num(m.defense_actions_per_match, 1)}</b></div>
      </div>`;
    }).join('');
  }

  function renderMatchupResult(data) {
    const a = data.alliance_a, b = data.alliance_b;
    if (!a || !b) return;
    if (els.matchupTitle) els.matchupTitle.textContent = `${matchupAllianceName(a.number)} vs ${matchupAllianceName(b.number)}`;
    const leader = Number(a.win_probability) >= Number(b.win_probability) ? a : b;
    const diff = Math.abs(Number(a.predicted_score || 0) - Number(b.predicted_score || 0));
    const factorHtml = (data.factors || []).length ? `<div class="matchup-factors">${data.factors.map(f => `<div><span>Alliance ${f.alliance}</span><b>${esc(f.text)}</b><small>${num(f.value, 1)}</small></div>`).join('')}</div>` : '<div class="alliance-empty-small">The available data does not show a strong matchup edge yet.</div>';
    els.matchupBody.innerHTML = `<div class="matchup-scoreboard">
      <section class="matchup-side ${leader.number === a.number ? 'favored' : ''}"><small>Alliance ${a.number}</small><strong>${num(a.predicted_score, 1)}</strong><span>Likely ${num(a.low, 0)}–${num(a.high, 0)}</span><b>${num(a.win_probability, 0)}% win estimate</b></section>
      <div class="matchup-vs"><span>VS</span><small>${num(data.confidence, 0)}% model confidence</small></div>
      <section class="matchup-side ${leader.number === b.number ? 'favored' : ''}"><small>Alliance ${b.number}</small><strong>${num(b.predicted_score, 1)}</strong><span>Likely ${num(b.low, 0)}–${num(b.high, 0)}</span><b>${num(b.win_probability, 0)}% win estimate</b></section>
    </div>
    <div class="matchup-summary-grid">
      <article><span>Public EPA</span><b>${num(a.epa, 1)} <small>vs</small> ${num(b.epa, 1)}</b><small>Independent TBA-derived contribution estimate</small></article>
      <article><span>Neptune EPA</span><b>${num(neptuneEpa(a), 1)} <small>vs</small> ${num(neptuneEpa(b), 1)}</b><small>Public EPA blended with Neptune scouting and trend</small></article>
      <article><span>Recent slope</span><b>${matchupTrendBadge(a.slope)} <small>vs</small> ${matchupTrendBadge(b.slope)}</b><small>Combined robot scoring trend</small></article>
      <article><span>Defense estimate</span><b>${num(a.defense_estimate, 1)} <small>vs</small> ${num(b.defense_estimate, 1)}</b><small>Estimated points suppressed</small></article>
    </div>
    <section class="matchup-section"><div class="matchup-section-head"><h3>Alliance ${a.number}</h3><span>${a.scouted_matches || 0} robot-match samples</span></div>${matchupTeamRows(a)}</section>
    <section class="matchup-section"><div class="matchup-section-head"><h3>Alliance ${b.number}</h3><span>${b.scouted_matches || 0} robot-match samples</span></div>${matchupTeamRows(b)}</section>
    <section class="matchup-section"><div class="matchup-section-head"><h3>What is driving the prediction?</h3><span>${diff < 2 ? 'Very close matchup' : `${num(diff, 1)} point projected gap`}</span></div>${factorHtml}</section>
    <div class="matchup-method"><i class="fa-solid fa-brain"></i><div><b>${esc(data.method?.name || 'Neptune matchup model')}</b><span>${esc(data.method?.description || '')}</span><small>${esc(data.method?.defense_note || '')}</small></div></div>`;
  }

  async function openMatchup(a, b) {
    if (!els.matchupDialog || !els.matchupBody || !state?.draft) return;
    const token = ++matchupRequestToken;
    els.matchupBody.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Calculating matchup from scouting history…</div>';
    if (els.matchupTitle) els.matchupTitle.textContent = `${matchupAllianceName(a)} vs ${matchupAllianceName(b)}`;
    if (!els.matchupDialog.open) els.matchupDialog.showModal();
    try {
      if (!navigator.onLine) throw new Error('AUGUR matchup prediction requires a connection to the Neptune server. Downloaded team intelligence and alliance Potential remain available offline.');
      const url = new URL(cfg.matchupEndpoint, window.location.href);
      url.searchParams.set('event_id', cfg.eventId);
      url.searchParams.set('draft_id', state.draft.id);
      url.searchParams.set('alliance_a', a);
      url.searchParams.set('alliance_b', b);
      const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (token !== matchupRequestToken) return;
      if (!res.ok || !data.ok) throw new Error(data.message || 'Could not calculate matchup.');
      renderMatchupResult(data);
    } catch (error) {
      if (token !== matchupRequestToken) return;
      els.matchupBody.innerHTML = `<div class="alliance-intel-empty"><i class="fa-solid fa-triangle-exclamation"></i><b>Matchup unavailable</b><span>${esc(error.message || 'Could not calculate this matchup.')}</span></div>`;
    }
  }

  function currentMatchupSettings() {
    const saved = { ...(state?.settings?.matchup_model || {}) };
    if (saved.epa_weight == null) saved.epa_weight = saved.local_epa_weight ?? saved.statbotics_weight ?? matchupDefaults.epa_weight;
    return { ...matchupDefaults, ...saved };
  }

  function settingsValueLabel(name, value) {
    const n = Number(value);
    if (['event_offense_weight','recent_offense_weight','ceiling_weight','epa_weight','defense_suppression_weight','defense_activity_weight','tba_form_strength','max_defense_adjustment_pct'].includes(name)) return `${num(n, 0)}%`;
    if (name === 'trend_strength') return `${num(n, 1)}×`;
    if (name === 'defense_action_points') return `${num(n, 1)} pts/action`;
    return num(n, 1);
  }

  function fillMatchupSettings(values = currentMatchupSettings()) {
    if (!els.matchupSettingsForm) return;
    for (const input of els.matchupSettingsForm.querySelectorAll('input[name]')) {
      if (values[input.name] !== undefined) input.value = values[input.name];
      const output = els.matchupSettingsForm.querySelector(`[data-setting-output="${input.name}"]`);
      if (output) output.textContent = settingsValueLabel(input.name, input.value);
    }
  }

  function openMatchupSettings() {
    if (!els.matchupSettings) return;
    fillMatchupSettings();
    if (els.matchupDialog?.open) els.matchupDialog.close();
    if (!els.matchupSettings.open) els.matchupSettings.showModal();
  }

  function closeMatchupSettings(reopen = true) {
    if (els.matchupSettings?.open) els.matchupSettings.close();
    if (reopen && matchupSelected.length === 2) openMatchup(matchupSelected[0], matchupSelected[1]);
  }

  async function saveMatchupSettings() {
    if (!editable() || !els.matchupSettingsForm) return;
    const values = {};
    for (const input of els.matchupSettingsForm.querySelectorAll('input[name]')) values[input.name] = Number(input.value);
    await mutate('set_matchup_settings', { draft_id: state?.draft?.id || '', settings_json: JSON.stringify(values) }, { queueable: true });
    if (els.matchupSettings?.open) els.matchupSettings.close();
    if (matchupSelected.length === 2) openMatchup(matchupSelected[0], matchupSelected[1]);
  }

  const mean = values => values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
  const clamp = (value, low, high) => Math.min(high, Math.max(low, value));
  function stddev(values) {
    if (values.length < 2) return 0;
    const m = mean(values);
    return Math.sqrt(values.reduce((sum, value) => sum + Math.pow(value - m, 2), 0) / values.length);
  }
  function percentile(values, q) {
    if (!values.length) return 0;
    const sorted = [...values].sort((a, b) => a - b);
    const pos = (sorted.length - 1) * q;
    const lo = Math.floor(pos), hi = Math.ceil(pos);
    if (lo === hi) return sorted[lo];
    return sorted[lo] + (sorted[hi] - sorted[lo]) * (pos - lo);
  }
  function regressionSlope(values) {
    if (values.length < 2) return 0;
    const xMean = (values.length - 1) / 2;
    const yMean = mean(values);
    let nume = 0, deno = 0;
    values.forEach((value, i) => {
      const dx = i - xMean;
      nume += dx * (value - yMean);
      deno += dx * dx;
    });
    return deno ? nume / deno : 0;
  }
  function teamProjection(detail) {
    const trendRows = Array.isArray(detail?.trends?.qualification) ? detail.trends.qualification : [];
    const points = trendRows.length
      ? trendRows.map(match => Number(match.points_per_match)).filter(Number.isFinite)
      : (detail.matches || []).filter(match => {
          const qualification = !match.comp_level || match.comp_level === 'qm';
          const wasScouted = match.scouted === true || Number(match.actions || 0) > 0;
          return qualification && wasScouted && Number.isFinite(Number(match.scout_points));
        }).map(match => Number(match.scout_points));
    if (!points.length) return null;

    const recent = points.slice(-6);
    const weights = recent.map((_, i) => i + 1);
    const weightedRecent = recent.reduce((sum, value, i) => sum + value * weights[i], 0) / weights.reduce((a, b) => a + b, 0);
    const eventAverage = mean(points);
    const upperQuartile = percentile(points, 0.75);
    const slope = regressionSlope(recent);
    const sampleFactor = clamp(points.length / 6, 0, 1);
    const volatility = stddev(recent);
    const success = clamp(Number(detail.event_stats?.success_rate || 0) / 100, 0, 1);
    const consistency = 1 - clamp(volatility / Math.max(weightedRecent + 10, 10), 0, 1);

    const base = (weightedRecent * 0.50) + (eventAverage * 0.30) + (upperQuartile * 0.20);
    const trendCap = Math.max(base, 10) * 0.25;
    const trendAdjustment = clamp(slope * 1.5, -trendCap, trendCap) * sampleFactor;
    const projected = Math.max(0, base + trendAdjustment);
    const uncertainty = (volatility * 0.75) + ((1 - sampleFactor) * Math.max(projected, 10) * 0.20);
    const confidence = clamp((0.60 * sampleFactor + 0.25 * consistency + 0.15 * success) * 100, 0, 100);

    return {
      matches: points.length,
      eventAverage,
      weightedRecent,
      upperQuartile,
      slope,
      volatility,
      projected,
      low: Math.max(0, projected - uncertainty),
      high: projected + uncertainty,
      confidence,
      recent
    };
  }
  function trendMarkup(slope) {
    if (!Number.isFinite(slope) || Math.abs(slope) < 0.15) return '<span class="potential-trend flat"><i class="fa-solid fa-minus"></i> Flat</span>';
    if (slope > 0) return `<span class="potential-trend up"><i class="fa-solid fa-arrow-trend-up"></i> +${num(slope, 1)} pts/match</span>`;
    return `<span class="potential-trend down"><i class="fa-solid fa-arrow-trend-down"></i> ${num(slope, 1)} pts/match</span>`;
  }
  function confidenceText(value) {
    if (value >= 80) return 'High';
    if (value >= 60) return 'Medium';
    return 'Low';
  }
  function sparkBars(values) {
    if (!values?.length) return '';
    const max = Math.max(...values, 1);
    return `<span class="potential-spark" aria-label="Recent match scoring trend">${values.map(value => `<i style="height:${Math.max(8, Math.round((value / max) * 100))}%" title="${esc(num(value, 0))} pts"></i>`).join('')}</span>`;
  }

  function potentialHeader(alliance) {
    return `<div class="alliance-potential-head"><div><span>Alliance ${alliance}</span><h2>Offensive Potential</h2></div><div class="toolbar" style="margin:0"><button type="button" class="secondary compact" data-potential-model-settings title="View or update the shared AUGUR model"><i class="fa-solid fa-sliders"></i> Model Settings</button><span class="pill" title="Neptune EPA"><i class="fa-solid fa-chart-line"></i> Nep. EPA</span><button type="button" class="secondary compact alliance-potential-close" data-potential-close aria-label="Close offensive potential"><i class="fa-solid fa-xmark"></i></button></div></div>`;
  }

  function renderPotential() {
    const holder = els.potential;
    if (!holder || !state) return;
    ++potentialRenderToken;
    const alliance = clamp(Number(focusedAlliance || 1), 1, 8);
    const row = state?.slots?.[alliance] || state?.slots?.[String(alliance)] || {};
    const fieldSlots = [['captain', 'Captain'], ['pick1', 'Pick 1'], ['pick2', 'Pick 2']];
    const members = fieldSlots.map(([slot, label]) => ({ slot, label, team: Number(row[slot] || 0) })).filter(item => item.team > 0);
    const backup = Number(row.backup || 0);

    if (!members.length) {
      holder.innerHTML = `${potentialHeader(alliance)}<div class="alliance-intel-empty alliance-potential-empty"><i class="fa-solid fa-chart-line"></i><b>No field teams yet</b><span>Add a captain or picks to Alliance ${alliance}. The Current Subtotal will be the sum of their Neptune EPA values.</span></div>`;
      return;
    }

    const rows = members.map(member => ({ ...member, info: teamByNumber(member.team) }));
    const usable = rows.filter(item => neptuneEpa(item.info) !== null && neptuneEpa(item.info) !== undefined && Number.isFinite(Number(neptuneEpa(item.info))));
    const subtotal = usable.reduce((sum, item) => sum + Number(neptuneEpa(item.info)), 0);
    const slopeValues = usable.map(item => Number(item.info?.augur_trend)).filter(Number.isFinite);
    const slope = slopeValues.reduce((sum, value) => sum + value, 0);
    const confidenceValues = usable.map(item => Number(item.info?.augur_confidence)).filter(Number.isFinite);
    const confidence = confidenceValues.length ? mean(confidenceValues) : 0;
    const backupInfo = backup ? `Backup #${backup} is not included in the subtotal.` : 'Backup is excluded from the subtotal.';
    const modelError = state?.augur_model?.error ? ` Model note: ${state.augur_model.error}` : '';

    const teamRows = rows.map(item => {
      const info = item.info;
      const augur = neptuneEpa(info);
      if (augur === null || augur === undefined || !Number.isFinite(Number(augur))) {
        return `<div class="potential-team-row">
          <div class="potential-team-main"><b>${esc(item.label)} · #${item.team} ${esc(info?.nickname || '')}</b><span>Neptune EPA is not available for this robot yet.</span></div>
          <span class="potential-spark" aria-hidden="true"></span>
          <div class="potential-team-trend"><small>No model data</small></div>
          <strong>—</strong>
        </div>`;
      }
      const matches = Number(info?.augur_matches || 0);
      const eventAvg = info?.augur_event_avg == null ? '—' : num(info.augur_event_avg, 1);
      const recentAvg = info?.augur_recent_avg == null ? '—' : num(info.augur_recent_avg, 1);
      const trend = Number(info?.augur_trend);
      const confidenceValue = Number(info?.augur_confidence);
      return `<div class="potential-team-row">
        <div class="potential-team-main"><b>${esc(item.label)} · #${item.team} ${esc(info?.nickname || '')}</b><span>${matches} scouted qual${matches === 1 ? '' : 's'} · avg ${eventAvg} · recent ${recentAvg}</span></div>
        <span class="potential-spark" aria-hidden="true"></span>
        <div class="potential-team-trend">${trendMarkup(trend)}<small>${Number.isFinite(confidenceValue) ? confidenceText(confidenceValue) : 'No'} confidence</small></div>
        <strong>${num(augur, 1)}</strong>
      </div>`;
    }).join('');

    holder.innerHTML = `${potentialHeader(alliance)}
      <div class="alliance-potential-body">
        <div class="potential-hero">
          <div><span>Current subtotal</span><strong>${usable.length ? num(subtotal, 1) : '—'}</strong><small>${usable.length ? `Sum of ${usable.length} selected field robot${usable.length === 1 ? '' : 's'}' Neptune EPA` : 'Waiting for Neptune EPA data'}</small></div>
          <div class="potential-hero-meta">${slopeValues.length ? trendMarkup(slope) : ''}<span><b>${confidenceValues.length ? Math.round(confidence) + '%' : '—'}</b> model confidence</span><span><b>${members.length}/3</b> field robots selected</span></div>
        </div>
        <div class="potential-team-list">${teamRows}</div>
        <div class="potential-method"><i class="fa-solid fa-circle-info"></i><span><b>How this is calculated:</b> Current Subtotal is the straight sum of the selected field robots' Neptune EPA values—the same Neptune EPA shown on the Team Pool cards. Neptune EPA blends Public EPA with Neptune full-event offense, recent offense, high-end output, and trend using the shared model weights. ${esc(backupInfo)} Defense and qualification-form settings are used for head-to-head matchup predictions, not this offensive subtotal.${esc(modelError)}</span></div>
      </div>`;
  }

  function openPotentialDialog(alliance) {
    focusedAlliance = Math.max(1, Math.min(8, Number(alliance || 1)));
    renderBoard();
    renderPotential();
    if (!els.potentialDialog) return;
    if (typeof els.potentialDialog.showModal === 'function') {
      if (!els.potentialDialog.open) els.potentialDialog.showModal();
    } else {
      els.potentialDialog.setAttribute('open', '');
    }
  }

  function closePotentialDialog() {
    if (!els.potentialDialog) return;
    if (typeof els.potentialDialog.close === 'function' && els.potentialDialog.open) els.potentialDialog.close();
    else els.potentialDialog.removeAttribute('open');
  }

  function renderDetail(pane) {
    const p = paneState[pane];
    const d = p?.data;
    if (!els.selectedTeamDetail) return;
    if (!d || Number(p?.team || 0) !== Number(selectedTeam || 0)) {
      els.selectedTeamDetail.innerHTML = '<div class="alliance-team-decision-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading robot intelligence…</div>';
      return;
    }
    els.selectedTeamDetail.innerHTML = detailTabHtml(d, p.tab, pane);
    bindDetailActions(els.teamActionBar, pane);
  }


  function kpi(value, label) { return `<div class="alliance-kpi"><b>${esc(value)}</b><span>${esc(label)}</span></div>`; }
  function groupsHtml(groups) {
    const entries = Object.entries(groups || {});
    if (!entries.length) return '<div class="alliance-empty-small">No structured scouting answers are available.</div>';
    return entries.map(([group, items]) => `<section class="alliance-detail-section"><h3>${esc(group)}</h3><div class="alliance-detail-grid">${items.map(item => `<div class="alliance-detail-field"><span>${esc(item.label)}</span><b>${esc(item.value)}</b></div>`).join('')}</div></section>`).join('');
  }

  function qualificationTrendRows(d) {
    const serverRows = Array.isArray(d?.trends?.qualification) ? d.trends.qualification : [];
    if (serverRows.length) {
      return serverRows.map(row => ({
        label: row.label,
        match_number: row.match_number,
        scout_points: Number(row.points_per_match || 0),
        defense: Number(row.defense_actions || 0),
        offense_rate: row.offense_success_rate,
        points_rolling_3: Number(row.points_rolling_3 || 0),
        defense_rolling_3: Number(row.defense_rolling_3 || 0),
        comp_level: 'qm',
        scouted: true
      }));
    }
    return (d.matches || []).filter(m => String(m.comp_level || '') === 'qm' && (m.scouted === true || Number(m.actions || 0) > 0));
  }

  function trendSlope(values) {
    const ys = values.map(Number).filter(Number.isFinite);
    const n = ys.length;
    if (n < 2) return 0;
    const xMean = (n - 1) / 2;
    const yMean = ys.reduce((a, b) => a + b, 0) / n;
    let nume = 0, den = 0;
    ys.forEach((y, x) => { nume += (x - xMean) * (y - yMean); den += (x - xMean) ** 2; });
    return den ? nume / den : 0;
  }

  function rollingAverage(values, span = 3) {
    return values.map((_, i) => {
      const start = Math.max(0, i - span + 1);
      const slice = values.slice(start, i + 1).map(Number).filter(Number.isFinite);
      return slice.length ? slice.reduce((a, b) => a + b, 0) / slice.length : 0;
    });
  }

  function lineTrendChart(rows, valueKey, opts = {}) {
    if (!rows.length) return '<div class="alliance-empty-small">No scouted qualification matches are available for this chart.</div>';
    const labels = rows.map(m => m.label || `Q${m.match_number || ''}`);
    const values = rows.map(m => Number(m[valueKey] || 0));
    const rolling = rollingAverage(values, 3);
    const width = 720, height = 290, left = 44, right = 16, top = 18, bottom = 42;
    const plotW = width - left - right, plotH = height - top - bottom;
    const rawMax = Math.max(1, ...values, ...rolling);
    const maxY = Math.max(1, Math.ceil(rawMax * 1.12));
    const x = i => rows.length === 1 ? left + plotW / 2 : left + (i / (rows.length - 1)) * plotW;
    const y = v => top + plotH - (Math.max(0, Number(v) || 0) / maxY) * plotH;
    const points = values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
    const avgPoints = rolling.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
    const grid = [0, .25, .5, .75, 1].map(frac => {
      const gy = top + plotH - frac * plotH;
      const label = num(maxY * frac, opts.decimals ?? 0);
      return `<line x1="${left}" y1="${gy}" x2="${width-right}" y2="${gy}" class="trend-grid-line"/><text x="${left-8}" y="${gy+4}" text-anchor="end" class="trend-axis-label">${esc(label)}</text>`;
    }).join('');
    const labelEvery = Math.max(1, Math.ceil(labels.length / 8));
    const xLabels = labels.map((label, i) => (i % labelEvery === 0 || i === labels.length - 1) ? `<text x="${x(i)}" y="${height-14}" text-anchor="middle" class="trend-axis-label">${esc(label)}</text>` : '').join('');
    const circles = values.map((v, i) => `<circle cx="${x(i)}" cy="${y(v)}" r="4" class="trend-point"><title>${esc(labels[i])}: ${esc(num(v, opts.decimals ?? 1))}</title></circle>`).join('');
    return `<div class="alliance-trend-chart-wrap">
      <svg class="alliance-trend-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(opts.aria || 'Team trend by qualification match')}">
        ${grid}${xLabels}
        <polyline points="${avgPoints}" class="trend-line trend-line-average"/>
        <polyline points="${points}" class="trend-line trend-line-primary"/>
        ${circles}
      </svg>
      <div class="trend-legend"><span><i class="trend-legend-primary"></i>${esc(opts.primaryLabel || 'Per match')}</span><span><i class="trend-legend-average"></i>3-match average</span></div>
    </div>`;
  }

  function trendPanelHtml(d, kind) {
    const rows = qualificationTrendRows(d);
    if (!rows.length) return '<div class="alliance-empty-small">No scouted qualification matches are available yet.</div>';
    const offense = kind === 'offense';
    const values = rows.map(m => Number(offense ? m.scout_points : m.defense) || 0);
    const avg = values.reduce((a, b) => a + b, 0) / values.length;
    const last = values[values.length - 1] || 0;
    const peak = Math.max(...values);
    const slope = trendSlope(values.slice(-6));
    const trend = Math.abs(slope) < 0.1 ? 'Flat' : slope > 0 ? `+${num(slope, 1)} / match` : `${num(slope, 1)} / match`;
    const title = offense ? 'Offensive output by qualification match' : 'Defensive actions by qualification match';
    const explanation = offense
      ? 'Points are Neptune scout points credited to this robot in each scouted qualification match. The second line is the rolling 3-match PPM average.'
      : 'Defense is the number of successful defensive actions recorded by Neptune scouts in each qualification match. The second line is the rolling 3-match average.';
    return `<div class="alliance-trend-panel">
      <div class="alliance-trend-kpis">
        ${kpi(num(avg, 1), offense ? 'Event PPM' : 'Defense / match')}
        ${kpi(num(last, offense ? 1 : 0), 'Last scouted match')}
        ${kpi(num(peak, offense ? 1 : 0), 'Best match')}
        ${kpi(trend, 'Last-6 slope')}
      </div>
      <section class="alliance-detail-section alliance-trend-section"><h3>${esc(title)}</h3>
        ${lineTrendChart(rows, offense ? 'scout_points' : 'defense', { decimals: offense ? 1 : 0, primaryLabel: offense ? 'Scouted points' : 'Defensive actions', aria: title })}
        <p class="alliance-trend-note">${esc(explanation)}</p>
      </section>
    </div>`;
  }

  function scoutingEventStatsHtml(d) {
    const s = d?.event_stats || {};
    const matches = Math.max(0, Number(s.matches || 0));
    const actions = Array.isArray(d?.actions) ? d.actions : [];
    const scoutedMatches = (d?.matches || []).filter(row => row?.scouted === true || Number(row?.actions || 0) > 0);
    const byAction = new Map();
    const phaseOrder = ['auton', 'teleop', 'endgame', 'post_match', 'unknown'];

    actions.forEach(row => {
      const phase = String(row?.phase || 'unknown').toLowerCase();
      const label = String(row?.label || 'Action');
      const type = String(row?.type || '');
      const key = `${phase}\u0000${type}\u0000${label}`;
      if (!byAction.has(key)) byAction.set(key, { phase, label, type, success: 0, failure: 0, other: 0, attempts: 0, points: 0 });
      const out = byAction.get(key);
      const count = Number(row?.attempts || 0);
      const result = String(row?.result || '').toLowerCase();
      out.attempts += count;
      out.points += Number(row?.points || 0);
      if (result === 'success') out.success += count;
      else if (result === 'failure') out.failure += count;
      else out.other += count;
    });

    const grouped = {};
    [...byAction.values()].forEach(row => {
      grouped[row.phase] ||= [];
      grouped[row.phase].push(row);
    });
    Object.values(grouped).forEach(rows => rows.sort((a, b) => a.label.localeCompare(b.label)));

    const phaseLabel = phase => ({
      auton: 'Auton',
      teleop: 'Teleop',
      endgame: 'Endgame',
      post_match: 'Post Match',
      unknown: 'Other'
    })[phase] || phase.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

    const sections = phaseOrder
      .filter(phase => grouped[phase]?.length)
      .map(phase => {
        const rows = grouped[phase];
        const body = rows.map(row => {
          const decided = row.success + row.failure;
          const rate = decided ? row.success * 100 / decided : null;
          const ppm = matches ? row.points / matches : 0;
          const apm = matches ? row.attempts / matches : 0;
          return `<tr>
            <td><b>${esc(row.label)}</b>${row.type ? `<small>${esc(row.type)}</small>` : ''}</td>
            <td>${row.success}</td>
            <td>${row.failure}</td>
            <td>${row.other || '—'}</td>
            <td>${row.attempts}</td>
            <td>${rate == null ? '—' : `${num(rate, 0)}%`}</td>
            <td>${num(apm, 1)}</td>
            <td>${num(row.points, 1)}</td>
            <td>${num(ppm, 1)}</td>
          </tr>`;
        }).join('');
        return `<section class="alliance-detail-section alliance-scouting-stat-section">
          <h3>${esc(phaseLabel(phase))}</h3>
          <div class="table-wrap"><table class="alliance-scouting-stats-table">
            <thead><tr><th>Action</th><th>Success</th><th>Fail</th><th>Other</th><th>Attempts</th><th>Success %</th><th>Att / Match</th><th>Total Pts</th><th>Pts / Match</th></tr></thead>
            <tbody>${body}</tbody>
          </table></div>
        </section>`;
      }).join('');

    const matchTable = scoutedMatches.length ? `<section class="alliance-detail-section alliance-scouting-stat-section">
      <h3>Scouted Match Breakdown</h3>
      <div class="table-wrap"><table class="alliance-scouting-stats-table">
        <thead><tr><th>Match</th><th>Alliance</th><th>Scout Pts</th><th>Actions</th><th>Offense Success</th><th>Defense</th><th>Final Score</th></tr></thead>
        <tbody>${scoutedMatches.map(m => `<tr>
          <td><b>${esc(m.label)}</b></td>
          <td>${esc(m.alliance || '—')} ${Number(m.station || 0) || ''}</td>
          <td>${num(m.scout_points, 1)}</td>
          <td>${Number(m.actions || 0)}</td>
          <td>${m.offense_rate == null ? '—' : `${num(m.offense_rate, 0)}%`}</td>
          <td>${Number(m.defense || 0)}</td>
          <td>${m.red_score == null || m.blue_score == null ? '—' : `${Number(m.red_score)}–${Number(m.blue_score)}`}</td>
        </tr>`).join('')}</tbody>
      </table></div>
    </section>` : '';

    return `<div class="alliance-detail-source">Source: Neptune scouting · current event</div>
      <div class="alliance-kpis alliance-scouting-summary-kpis">
        ${kpi(matches, 'Matches scouted')}
        ${kpi(num(s.points, 1), 'Total scout points')}
        ${kpi(num(s.ppm, 1), 'Points / match')}
        ${kpi(Number(s.actions || 0), 'Recorded actions')}
        ${kpi(Number(s.successes || 0), 'Successful actions')}
        ${kpi(Number(s.failures || 0), 'Failed actions')}
        ${kpi(`${num(s.success_rate, 0)}%`, 'Offense success')}
        ${kpi(s.cycle_time == null ? '—' : `${num(s.cycle_time, 1)}s`, 'Cycle time')}
        ${kpi(num(s.defense_per_match, 1), 'Defense / match')}
      </div>
      ${sections || '<div class="alliance-empty-small">No scouting actions have been recorded for this team at this event.</div>'}
      ${matchTable}`;
  }

  function allianceHistoryHtml(d) {
    const rows = Array.isArray(d?.history) ? d.history : [];
    if (!rows.length) return `<div class="alliance-empty-small">${esc(d?.history_error || 'No TBA alliance-selection history is available for this team.')}</div>`;

    const rosterHtml = row => {
      const members = Array.isArray(row?.alliance_teams) ? row.alliance_teams : [];
      if (!members.length) return '<span class="muted">Alliance roster unavailable from TBA.</span>';
      return `<div class="alliance-history-roster">${members.map(member => `<span class="${member.is_team ? 'current-team' : ''}"><small>${esc(member.role || '')}</small><b>#${Number(member.team || 0)}</b></span>`).join('')}</div>`;
    };

    return `<div class="alliance-detail-source">Source: The Blue Alliance · recent alliance-selection events</div>
      <div class="alliance-history-cards">${rows.map(row => {
        const qualRank = Number(row.qual_rank || 0);
        const qualTeams = Number(row.qual_teams || 0);
        const eventTitle = row.event_url
          ? `<a href="${esc(row.event_url)}" target="_blank" rel="noopener">${esc(row.event)}</a>`
          : esc(row.event);
        const backups = Array.isArray(row.backups) && row.backups.length
          ? `<div class="alliance-history-backups">${row.backups.map(b => `<span>Backup: ${b.out ? `#${Number(b.out)} out` : ''}${b.in && b.out ? ' · ' : ''}${b.in ? `#${Number(b.in)} in` : ''}</span>`).join('')}</div>`
          : '';
        return `<article class="alliance-history-card">
          <div class="alliance-history-card-head">
            <div><small>${Number(row.year || 0) || ''}</small><h3>${eventTitle}</h3></div>
            <span class="pill">Alliance ${Number(row.alliance || 0)} · ${esc(row.role || 'Alliance Member')}</span>
          </div>
          ${rosterHtml(row)}
          <div class="alliance-history-facts">
            <span><small>Qualification rank</small><b>${qualRank ? `${qualRank}${qualTeams ? ` / ${qualTeams}` : ''}` : '—'}</b></span>
            <span><small>Qualification record</small><b>${esc(row.qual_record || '—')}</b></span>
            <span><small>Playoff record</small><b>${esc(row.playoff_record || '—')}</b></span>
          </div>
          ${backups}
          ${row.status ? `<p class="alliance-history-status">${esc(row.status)}</p>` : ''}
        </article>`;
      }).join('')}</div>`;
  }

  function detailTabHtml(d, tab, pane) {
    if (tab === 'event') return scoutingEventStatsHtml(d);
    if (tab === 'offense') return trendPanelHtml(d, 'offense');
    if (tab === 'defense') return trendPanelHtml(d, 'defense');
    if (tab === 'statbotics') {
      if (!d.epa_rating || !Object.keys(d.epa_rating).length) return `<div class="alliance-empty-small">${esc(d.epa_rating_error || 'No Public EPA rating is available yet. Rebuild ratings from the Public EPA Ratings page.')}</div>`;
      const s = d.epa_rating;
      const trend = s.trend == null ? '—' : (Math.abs(Number(s.trend)) < 0.05 ? 'Flat' : `${Number(s.trend) > 0 ? '+' : ''}${num(s.trend, 1)} / match`);
      return `<div class="alliance-detail-source">Source: Public EPA · TBA public match results · ${esc(s.model_version || '')}</div><div class="alliance-kpis">${kpi(num(s.epa, 1), 'Public EPA')}${kpi(s.auto == null ? '—' : num(s.auto, 1), 'Auto EPA')}${kpi(s.teleop == null ? '—' : num(s.teleop, 1), 'Teleop EPA')}${kpi(s.endgame == null ? '—' : num(s.endgame, 1), 'Endgame EPA')}${kpi(neptuneEpa(s) == null ? '—' : num(neptuneEpa(s), 1), 'Neptune EPA')}${kpi(s.record || '—', 'Record')}${kpi(s.rank ? `#${s.rank}` : '—', 'Event rating rank')}${kpi(trend, 'EPA trend')}${kpi(s.confidence == null ? '—' : `${num(s.confidence, 0)}%`, 'EPA confidence')}</div><p class="alliance-trend-note">Public EPA uses only public TBA results. Neptune EPA blends that baseline with ${Number(s.scouting_matches || 0)} Neptune-scout${Number(s.scouting_matches || 0) === 1 ? 'ed match' : 'ed matches'} when available.</p>`;
    }
    if (tab === 'game') {
      const matches = d.matches || [];
      const actions = d.actions || [];
      const table = matches.length ? `<div class="table-wrap"><table class="alliance-match-table"><thead><tr><th>Match</th><th>Alliance</th><th>Scout pts</th><th>Actions</th><th>Offense</th><th>Defense</th><th>Score</th></tr></thead><tbody>${matches.map(m => `<tr><td>${esc(m.label)}</td><td>${esc(m.alliance)} ${m.station}</td><td>${esc(num(m.scout_points, 0))}</td><td>${m.actions}</td><td>${m.offense_rate == null ? '—' : `${num(m.offense_rate, 0)}%`}</td><td>${m.defense}</td><td>${m.red_score == null ? '—' : `${m.red_score}–${m.blue_score}`}</td></tr>`).join('')}</tbody></table></div>` : '<div class="alliance-empty-small">No matches are loaded for this team at this event.</div>';
      const actionHtml = actions.length ? `<section class="alliance-detail-section"><h3>Scouting action breakdown</h3><div class="alliance-detail-grid">${actions.slice(0, 30).map(a => `<div class="alliance-detail-field"><span>${esc(a.phase)} · ${esc(a.result)}</span><b>${esc(a.label)} · ${a.attempts} · ${num(a.points, 0)} pts</b></div>`).join('')}</div></section>` : '';
      return table + actionHtml;
    }
    if (tab === 'pit') {
      return `<section class="alliance-detail-section"><h3>Pit Scouting · ${esc(d.pit.status)}</h3>${groupsHtml(d.pit.groups)}${d.pit.notes ? `<div class="robot-notes"><span class="muted">Pit notes</span><p>${esc(d.pit.notes)}</p></div>` : ''}</section><section class="alliance-detail-section"><h3>Pre-Scout · ${esc(d.pre_scout.status)}</h3>${groupsHtml(d.pre_scout.groups)}${d.pre_scout.notes ? `<div class="robot-notes"><span class="muted">Pre-scout notes</span><p>${esc(d.pre_scout.notes)}</p></div>` : ''}</section>`;
    }
    if (tab === 'spot') return spotTabHtml(d);
    if (tab === 'photos') {
      return d.photos?.length ? `<div class="alliance-photo-grid">${d.photos.map(p => `<a href="${esc(p.url)}" target="_blank" rel="noopener"><img src="${esc(p.url)}" alt="${esc(p.category)} view of team ${d.team.number}"><span>${esc(p.caption || p.category)}</span></a>`).join('')}</div>` : '<div class="alliance-empty-small">No robot photos have been saved for this event.</div>';
    }
    if (tab === 'history') return allianceHistoryHtml(d);
    return '';
  }

  function bindDetailActions() {}

  function closeDraftMenu() { document.querySelector('.alliance-draft-popover')?.remove(); }
  function openDraftMenu() {
    closeDraftMenu();
    if (!state?.draft || !cfg.canEdit) return;
    const box = document.createElement('div');
    box.className = 'alliance-draft-popover card';
    box.innerHTML = '<button type="button" data-draft-action="rename"><i class="fa-solid fa-pen"></i> Rename</button><button type="button" data-draft-action="archive" class="danger"><i class="fa-solid fa-box-archive"></i> Archive</button>';
    const rect = els.draftMenu.getBoundingClientRect();
    box.style.position = 'fixed'; box.style.zIndex = '1000'; box.style.top = `${rect.bottom + 5}px`; box.style.right = `${Math.max(8, window.innerWidth - rect.right)}px`; box.style.padding = '6px'; box.style.display = 'grid'; box.style.gap = '4px';
    document.body.appendChild(box);
    box.querySelector('[data-draft-action="rename"]').addEventListener('click', async () => {
      closeDraftMenu();
      const name = await ui.prompt('Enter a new name for this scenario.', { title: 'Rename scenario', value: state.draft.name, confirmText: 'Rename' });
      if (name !== null && String(name).trim()) await mutate('rename_draft', { draft_id: state.draft.id, name: String(name).trim() });
    });
    box.querySelector('[data-draft-action="archive"]').addEventListener('click', async () => {
      closeDraftMenu();
      const ok = await ui.confirm(`Archive “${state.draft.name}”?`, { title: 'Archive scenario', confirmText: 'Archive', danger: true });
      if (ok) await mutate('archive_draft', { draft_id: state.draft.id });
    });
    setTimeout(() => document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== els.draftMenu) closeDraftMenu(); }, { once: true }), 0);
  }

  els.potential?.addEventListener('click', event => {
    const settingsButton = event.target.closest('[data-potential-model-settings]');
    if (settingsButton) {
      openMatchupSettings();
      return;
    }
    if (event.target.closest('[data-potential-close]')) closePotentialDialog();
  });
  els.potentialDialog?.addEventListener('click', event => { if (event.target === els.potentialDialog) closePotentialDialog(); });
  els.potentialDialog?.addEventListener('cancel', event => { event.preventDefault(); closePotentialDialog(); });

  els.teamSearch?.addEventListener('input', renderTeamList);
  els.captainPickToggle?.addEventListener('click', async () => {
    if (!editable()) return;
    const allowed = captainPicksAllowed();
    await mutate('set_captain_picks', { draft_id: state?.draft?.id || '', allowed: allowed ? 0 : 1 }, { queueable: true });
  });
  $$('.alliance-filter-tabs [data-filter]').forEach(btn => btn.addEventListener('click', () => {
    filter = btn.dataset.filter;
    $$('.alliance-filter-tabs [data-filter]').forEach(b => b.classList.toggle('active', b === btn));
    renderTeamList();
  }));
  els.pickDrawerBtn?.addEventListener('click', () => setPickDrawerOpen(!els.pickDrawer?.classList.contains('open')));
  els.pickDrawerClose?.addEventListener('click', () => setPickDrawerOpen(false));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && els.pickDrawer?.classList.contains('open')) setPickDrawerOpen(false); });

  els.selectedTeamModalTabs?.addEventListener('click', event => {
    const button = event.target.closest('[data-selected-modal-tab]');
    if (!button) return;
    setSelectedModalTab(button.dataset.selectedModalTab);
  });
  els.selectedTeamClose?.addEventListener('click', closeSelectedTeamModal);
  els.teamActionBar?.addEventListener('click', event => { if (event.target === els.teamActionBar) closeSelectedTeamModal(); });
  els.teamActionBar?.addEventListener('cancel', event => { event.preventDefault(); closeSelectedTeamModal(); });
  els.makeSelection?.addEventListener('click', () => selectedTeam && setSelection(selectedTeam));
  els.favorite?.addEventListener('click', () => selectedTeam && state?.draft && mutate('toggle_favorite', { draft_id: state.draft.id, team: selectedTeam }, { queueable: true }));
  els.decline?.addEventListener('click', () => setTeamState('declined'));
  els.dnp?.addEventListener('click', () => setTeamState('do_not_pick'));
  els.broken?.addEventListener('click', () => setTeamState('broken'));

  els.newDraft?.addEventListener('click', async () => {
    const name = await ui.prompt('Name this pick-list scenario.', { title: 'New scenario', value: `Scenario ${(state?.drafts?.length || 0) + 1}`, confirmText: 'Create' });
    if (name !== null && String(name).trim()) await mutate('create_draft', { name: String(name).trim() });
  });
  els.cloneDraft?.addEventListener('click', async () => {
    if (!state?.draft) return;
    const name = await ui.prompt('Create a copy of the current board.', { title: 'Clone scenario', value: `${state.draft.name} Copy`, confirmText: 'Clone' });
    if (name !== null && String(name).trim()) await mutate('clone_draft', { draft_id: state.draft.id, name: String(name).trim() });
  });
  els.draftMenu?.addEventListener('click', event => { event.stopPropagation(); openDraftMenu(); });
  els.undo?.addEventListener('click', () => state?.draft && mutate('undo', { draft_id: state.draft.id }, { toast: false }));
  els.redo?.addEventListener('click', () => state?.draft && mutate('redo', { draft_id: state.draft.id }, { toast: false }));
  els.seedCaptains?.addEventListener('click', async () => {
    if (!state?.draft) return;
    const ok = await ui.confirm('Refresh Alliance 1–8 captains from the current TBA qualification rankings? This is intended for use before picks begin.', { title: 'Refresh TBA rankings', confirmText: 'Refresh Rankings' });
    if (ok) await mutate('refresh_rankings', { draft_id: state.draft.id });
  });
  els.mode?.addEventListener('click', async () => {
    if (!state?.draft) return;
    const live = state.draft.mode !== 'live';
    if (live) {
      const ok = await ui.confirm('Make this the live alliance-selection board? Any other live board for this event will return to Scenario mode.', { title: 'Start live board', confirmText: 'Make Live' });
      if (!ok) return;
    }
    await mutate('set_mode', { draft_id: state.draft.id, mode: live ? 'live' : 'scenario' });
  });



  document.querySelector('.logout-link')?.addEventListener('click', async event => {
    const href = event.currentTarget?.href;
    if (!href) return;
    event.preventDefault();
    await clearAllAllianceOfflineData();
    window.location.href = href;
  });

  els.prepareOffline?.addEventListener('click', () => {
    renderOfflineStatus();
    if (els.offlineProgress) els.offlineProgress.hidden = true;
    els.offlineDialog?.showModal();
  });
  els.offlineClose?.addEventListener('click', () => els.offlineDialog?.close());
  els.offlineDialog?.addEventListener('click', event => { if (event.target === els.offlineDialog) els.offlineDialog.close(); });
  els.offlineDialog?.addEventListener('cancel', event => { event.preventDefault(); els.offlineDialog.close(); });
  els.offlineSizeBtn?.addEventListener('click', estimateOfflineSize);
  els.offlinePhotos?.addEventListener('change', () => {
    offlineEstimate = null;
    if (els.offlineSize) els.offlineSize.textContent = 'Not calculated';
    if (els.offlineSizeNote) els.offlineSizeNote.textContent = 'Photo/media selection changed. Calculate again for the updated package.';
    els.offlineSize?.closest('.alliance-offline-size-card')?.classList.remove('is-ready','is-calculating');
  });
  els.offlineDownload?.addEventListener('click', prepareOfflineData);
  els.offlineClear?.addEventListener('click', async () => {
    const ok = await ui.confirm('Remove the downloaded Alliance Selection event data from this browser?', { title: 'Remove offline data', confirmText: 'Remove', danger: true });
    if (ok) await clearOfflineData();
  });

  els.matchupClose?.addEventListener('click', () => els.matchupDialog?.close());
  els.matchupDialog?.addEventListener('click', event => { if (event.target === els.matchupDialog) els.matchupDialog.close(); });
  els.matchupSettingsBtn?.addEventListener('click', openMatchupSettings);
  els.matchupSettingsClose?.addEventListener('click', () => closeMatchupSettings(true));
  els.matchupSettings?.addEventListener('click', event => { if (event.target === els.matchupSettings) closeMatchupSettings(true); });
  els.matchupSettingsForm?.addEventListener('input', event => {
    const input = event.target.closest('input[name]'); if (!input) return;
    const output = els.matchupSettingsForm.querySelector(`[data-setting-output="${input.name}"]`);
    if (output) output.textContent = settingsValueLabel(input.name, input.value);
  });
  els.matchupSettingsReset?.addEventListener('click', () => fillMatchupSettings(matchupDefaults));
  els.matchupSettingsSave?.addEventListener('click', saveMatchupSettings);

  function updateFullscreenButton() {
    const active = document.fullscreenElement === els.workspaceShell || document.webkitFullscreenElement === els.workspaceShell;
    if (els.fullscreenLabel) els.fullscreenLabel.textContent = active ? 'Exit Full Screen' : 'Full Screen';
    const icon = els.fullscreen?.querySelector('i');
    if (icon) {
      icon.classList.toggle('fa-expand', !active);
      icon.classList.toggle('fa-compress', active);
    }
  }

  els.fullscreen?.addEventListener('click', async () => {
    if (!els.workspaceShell) return;
    try {
      const active = document.fullscreenElement || document.webkitFullscreenElement;
      if (active) {
        if (document.exitFullscreen) await document.exitFullscreen();
        else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      } else if (els.workspaceShell.requestFullscreen) {
        await els.workspaceShell.requestFullscreen();
      } else if (els.workspaceShell.webkitRequestFullscreen) {
        els.workspaceShell.webkitRequestFullscreen();
      } else {
        ui.toast('Full-screen view is not supported by this browser.', 'warn');
      }
    } catch (error) {
      ui.toast(error.message || 'Could not enter full-screen view.', 'bad');
    }
  });
  document.addEventListener('fullscreenchange', updateFullscreenButton);
  document.addEventListener('webkitfullscreenchange', updateFullscreenButton);

  window.addEventListener('online', () => { updateOnlinePill(); replayQueue(); });
  window.addEventListener('offline', updateOnlinePill);
  updateOnlinePill();
  renderOfflineStatus();
  updateFullscreenButton();
  registerOfflineWorker();
  loadState();
  if (navigator.onLine) replayQueue();

  setInterval(() => {
    if (!navigator.onLine || loading || readQueue().length) return;
    loadState(state?.draft?.id || 0);
  }, 5000);
})();
