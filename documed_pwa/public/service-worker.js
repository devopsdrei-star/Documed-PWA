/*
  Basic service worker for DocMed PWA (subdirectory-aware)
  - Precaches core shell assets at install
  - Cleans up old caches at activate
  - Runtime caching for same-origin GET requests (NetworkFirst for HTML, CacheFirst for static assets)
*/

const APP_VERSION = 'v3';
const CORE_CACHE = `docmed-core-${APP_VERSION}`;
const RUNTIME_CACHE = `docmed-runtime-${APP_VERSION}`;

// Determine base path if hosted in subdirectory (e.g. /documed_pwa/)
const BASE_PATH = (() => {
  // service worker scope ends with '/'; we can infer from location.pathname (e.g. /documed_pwa/)
  const path = self.location.pathname; // typically /documed_pwa/service-worker.js OR /service-worker.js
  if (path.includes('/documed_pwa/')) return '/documed_pwa';
  // try to strip filename; if only one segment, root
  return '';
})();

// Helper to prefix base
const p = (url) => `${BASE_PATH}${url}`;

// List of core assets to precache (add more as needed)
const CORE_ASSETS = [
  p('/'),
  p('/index.html'),
  p('/manifest.json'),
  p('/favicon.ico'),
  p('/frontend/assets/images/documed_logo.png')
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  // Robust precache: fetch each asset and only add successful responses to cache.
  event.waitUntil((async () => {
    const cache = await caches.open(CORE_CACHE);
    for (const asset of CORE_ASSETS) {
      try {
        const resp = await fetch(asset, { cache: 'no-cache' });
        if (resp && resp.ok) {
          await cache.put(asset, resp.clone());
        } else {
          // Log missing assets but do not fail installation
          console.warn('[SW] precache skip (not ok):', asset, resp && resp.status);
        }
      } catch (err) {
        console.warn('[SW] precache error for', asset, err);
      }
    }
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => ![CORE_CACHE, RUNTIME_CACHE].includes(k)).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Mutation POST requests to backend API: always network, then broadcast invalidate
  if (request.method === 'POST' && /\/backend\/api\//.test(url.pathname)) {
    event.respondWith((async () => {
      try {
        const resp = await fetch(request.clone());
        // On success, broadcast invalidation so clients can refresh affected views
        if (resp.ok) {
          const clientsArr = await self.clients.matchAll({ includeUncontrolled: true });
          clientsArr.forEach(c => c.postMessage({ type: 'invalidate', api: url.pathname, status: resp.status }));
        }
        return resp;
      } catch (e) {
        return new Response(JSON.stringify({ success: false, message: 'Network error', error: String(e) }), { status: 503, headers: { 'Content-Type': 'application/json' } });
      }
    })());
    return;
  }

  // For GET API requests: network-first, no caching (fresh data)
  if (request.method === 'GET' && /\/backend\/api\//.test(url.pathname)) {
    event.respondWith((async () => {
      try {
        const resp = await fetch(request, { cache: 'no-store' });
        // Optional: could broadcast if payload contains success mutation markers
        return resp;
      } catch (e) {
        // As a fallback we do NOT serve stale cached API JSON (avoid outdated state)
        return new Response(JSON.stringify({ success: false, offline: true, message: 'Offline – API unavailable' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }
    })());
    return;
  }

  if (request.method !== 'GET') return; // ignore other non-GET (e.g., PUT/DELETE)

  // Only handle same-origin for now
  if (url.origin !== self.location.origin) {
    return;
  }

  // HTML navigation requests: NetworkFirst -> fallback to cache -> offline page later
  if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(request)
        .then(resp => {
          const copy = resp.clone();
          caches.open(RUNTIME_CACHE).then(c => c.put(request, copy));
          return resp;
        })
        .catch(() => caches.match(request).then(r => r || caches.match(p('/index.html'))))
    );
    return;
  }

  // For static assets (css/js/png/svg etc) use CacheFirst
  if (/\.(?:js|css|png|jpg|jpeg|gif|svg|webp|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(cached => {
        if (cached) return cached;
        return fetch(request).then(resp => {
          const copy = resp.clone();
            caches.open(RUNTIME_CACHE).then(c => c.put(request, copy));
          return resp;
        });
      })
    );
    return;
  }

  // Default: NetworkFirst for remaining GET (e.g. JSON not in /backend/api/) with fallback to cache
  event.respondWith((async () => {
    try {
      const resp = await fetch(request);
      // Cache successful opaque or ok responses
      if (resp && (resp.ok || resp.type === 'opaque')) {
        const copy = resp.clone();
        caches.open(RUNTIME_CACHE).then(c => c.put(request, copy));
      }
      return resp;
    } catch (e) {
      return (await caches.match(request)) || new Response('Offline', { status: 503 });
    }
  })());
});
