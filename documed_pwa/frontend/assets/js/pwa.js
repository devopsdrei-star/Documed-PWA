(function(){
  let deferredPrompt = null;
  const insecureButDev = !window.isSecureContext && location.hostname !== 'localhost';
  function ensureInstallButton(){
    let btn = document.getElementById('pwaInstallBtn');
    if (!btn) {
      btn = document.createElement('button');
      btn.id = 'pwaInstallBtn';
      btn.type = 'button';
      btn.textContent = 'Install App';
      document.body.appendChild(btn);
    }
    return btn;
  }

  function wireInstall(){
    const btn = ensureInstallButton();
    btn.style.display = 'none';
    // If already running as an installed app, keep hidden
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
      btn.style.display = 'none';
    }
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      btn.style.display = 'inline-flex';
    });
    btn.addEventListener('click', async () => {
      if (!deferredPrompt) {
        // Helpful feedback on non-secure LAN where the prompt cannot appear
        if (insecureButDev) {
          alert('Install requires HTTPS or localhost. On Android, you can use your browser menu > "Add to Home screen" for now, or access the app via HTTPS.');
        }
        return;
      }
      btn.disabled = true;
      try {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        console.log('[PWA] userChoice', outcome);
      } catch(err) {
        console.warn('[PWA] prompt failed', err);
      }
      deferredPrompt = null;
      setTimeout(()=>{ btn.style.display='none'; btn.disabled=false; }, 500);
    });
    window.addEventListener('appinstalled', ()=> {
      console.log('[PWA] Installed');
      btn.style.display = 'none';
    });
    // On insecure LAN origins, show a guidance CTA
    if (insecureButDev) {
      btn.style.display = 'inline-flex';
      btn.title = 'Install requires HTTPS or localhost';
    }
  }

  function registerSW(){
    if (!('serviceWorker' in navigator)) return;
    // Register relative to user pages location (frontend/user/* -> ../../service-worker.js)
    const swUrl = new URL('../../service-worker.js', window.location.href).toString();
    navigator.serviceWorker.register(swUrl)
      .then(reg => console.log('[SW] registered', reg.scope))
      .catch(err => console.error('[SW] failed', err));
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Ensure manifest link exists (optional: only if missing)
    const hasManifest = !!document.querySelector('link[rel="manifest"]');
    if (!hasManifest) {
      const link = document.createElement('link');
      link.rel = 'manifest';
      link.href = '../../manifest-landing.json';
      document.head.appendChild(link);
    }
    registerSW();
    wireInstall();
  });
})();