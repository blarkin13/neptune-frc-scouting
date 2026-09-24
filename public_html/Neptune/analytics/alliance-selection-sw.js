/* Neptune Alliance Selection offline fallback.
 * Scope: /analytics/
 * Data is populated explicitly by the page's Prepare Offline action.
 */
const ALLIANCE_CACHE_PREFIX = 'neptune-alliance-event-v2:';

self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

async function clearAllianceCaches() {
  const names = await caches.keys();
  await Promise.all(names.filter(name => name.startsWith(ALLIANCE_CACHE_PREFIX)).map(name => caches.delete(name)));
}

self.addEventListener('message', event => {
  if (event.data?.type === 'CLEAR_ALLIANCE_OFFLINE') {
    event.waitUntil(clearAllianceCaches());
  }
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin === self.location.origin && url.pathname.endsWith('/logout.php')) {
    event.respondWith((async () => {
      await clearAllianceCaches();
      return fetch(request);
    })());
    return;
  }

  event.respondWith((async () => {
    try {
      return await fetch(request);
    } catch (error) {
      const cached = await caches.match(request);
      if (cached) return cached;

      throw error;
    }
  })());
});
