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
  if (request.method !== 'GET') return; // ignore non-GET

  const url = new URL(request.url);

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

  // Default: try cache, then network
  event.respondWith(
    caches.match(request).then(cached => cached || fetch(request).then(resp => {
      const copy = resp.clone();
      caches.open(RUNTIME_CACHE).then(c => c.put(request, copy));
      return resp;
    }).catch(() => cached))
  );
});
