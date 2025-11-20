/* DocuMed PWA Service Worker (admin, doc_nurse, dentist, user)
 * Scope: /documed_pwa/
 * - Robust install (skip failing assets)
 * - NetworkFirst for HTML navigations
 * - CacheFirst for static assets
 * - API: POST -> network only + broadcast invalidate; GET -> network-first (no-store)
 */
const DM_APP_VERSION = 'pwa-v2025-11-20-1';
const DM_CORE = `documed-core-${DM_APP_VERSION}`;
const DM_RUNTIME = `documed-runtime-${DM_APP_VERSION}`;
// Derive base directory (e.g., /DocMed/documed_pwa)
const BASE = new URL('./', self.location).pathname.replace(/\/$/, '');

const CORE_ASSETS = [
  `${BASE}/frontend/user/user_dashboard.html`,
  `${BASE}/frontend/assets/css/style.css`,
  `${BASE}/frontend/assets/images/Logo.png`,
  `${BASE}/manifest-landing.json`,
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil((async () => {
    const cache = await caches.open(DM_CORE);
    await Promise.allSettled(
      CORE_ASSETS.map(async (url) => {
        try {
          const resp = await fetch(url, { cache: 'reload' });
          if (resp && resp.ok) await cache.put(url, resp);
        } catch (_) { /* ignore missing assets */ }
      })
    );
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((k) => (k === DM_CORE || k === DM_RUNTIME) ? Promise.resolve() : caches.delete(k)));
    await self.clients.claim();
  })());
});

function isApiPath(pathname) {
  // Handle both /documed_pwa/backend/api/* and /backend/api/* within scope
  return /\/backend\/api\//.test(pathname);
}

async function broadcastInvalidate(detail) {
  const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  for (const client of all) {
    client.postMessage({ type: 'invalidate', ...(detail || {}) });
  }
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // API handling
  if (isApiPath(url.pathname)) {
    if (req.method !== 'GET') {
      // Mutations: network only, then broadcast invalidate
      event.respondWith((async () => {
        try {
          const resp = await fetch(req, { cache: 'no-store' });
          // fire and forget broadcast
          broadcastInvalidate({ api: url.pathname, method: req.method }).catch(()=>{});
          return resp;
        } catch (e) {
          return new Response(JSON.stringify({ success: false, message: 'Network error' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
        }
      })());
      return;
    }
    // API GET: network-first, no-store, do NOT cache
    event.respondWith((async () => {
      try {
        return await fetch(new Request(req, { cache: 'no-store' }));
      } catch (_) {
        // optional: fall back to cache if any exists (unlikely, as we do not cache API)
        const cached = await caches.match(req);
        if (cached) return cached;
        return new Response(JSON.stringify({ success: false, message: 'Offline' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
      }
    })());
    return;
  }

  // Navigation requests (HTML): NetworkFirst
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const r = await fetch(req);
        const copy = r.clone();
        caches.open(DM_RUNTIME).then((c) => c.put(req, copy));
        return r;
      } catch (_) {
        const cached = await caches.match(req);
        if (cached) return cached;
        return caches.match(`${BASE}/frontend/user/user_dashboard.html`);
      }
    })());
    return;
  }

  // Static assets: CacheFirst
  if (/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf)$/i.test(url.pathname)) {
    event.respondWith((async () => {
      const cached = await caches.match(req);
      if (cached) return cached;
      const r = await fetch(req);
      const copy = r.clone();
      caches.open(DM_RUNTIME).then((c) => c.put(req, copy));
      return r;
    })());
    return;
  }

  // Default: try cache then network
  event.respondWith((async () => {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
      const r = await fetch(req);
      const copy = r.clone();
      caches.open(DM_RUNTIME).then((c) => c.put(req, copy));
      return r;
    } catch (_) {
      return cached || Response.error();
    }
  })());
});
