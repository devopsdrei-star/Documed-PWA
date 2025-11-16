/* DocuMed Landing (user_dashboard) Service Worker
 * Scope: /documed_pwa/
 * Caches landing assets + runtime images/css/js
 */
const DM_APP_VERSION = 'landing-v2';
const DM_CORE = `documed-landing-core-${DM_APP_VERSION}`;
const DM_RUNTIME = `documed-landing-runtime-${DM_APP_VERSION}`;
// Derive base directory (e.g., /DocMed/documed_pwa)
const BASE = new URL('./', self.location).pathname.replace(/\/$/, '');

const CORE_ASSETS = [
  `${BASE}/frontend/user/user_dashboard.html`,
  `${BASE}/frontend/assets/css/style.css`,
  `${BASE}/frontend/assets/images/Logo.png`,
  `${BASE}/manifest-landing.json`
];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(DM_CORE).then(c => c.addAll(CORE_ASSETS)));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => ![DM_CORE, DM_RUNTIME].includes(k)).map(k => caches.delete(k))
    )).then(()=> self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Navigation requests: network first
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req).then(r => {
        const copy = r.clone();
        caches.open(DM_RUNTIME).then(c => c.put(req, copy));
        return r;
      }).catch(()=> caches.match(req).then(r=> r || caches.match(`${BASE}/frontend/user/user_dashboard.html`)))
    );
    return;
  }

  // Static assets: cache first
  if (/\.(?:css|js|png|jpg|jpeg|gif|svg|webp|ico)$/i.test(url.pathname)) {
    e.respondWith(
      caches.match(req).then(cached => {
        if (cached) return cached;
        return fetch(req).then(r => {
          const copy = r.clone();
          caches.open(DM_RUNTIME).then(c => c.put(req, copy));
          return r;
        });
      })
    );
    return;
  }

  // Default: try cache then network
  e.respondWith(
    caches.match(req).then(cached => cached || fetch(req).then(r => {
      const copy = r.clone();
      caches.open(DM_RUNTIME).then(c => c.put(req, copy));
      return r;
    }).catch(()=> cached))
  );
});
