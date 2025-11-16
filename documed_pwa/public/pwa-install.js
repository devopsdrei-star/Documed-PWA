// Handles custom PWA install prompt UI
let deferredPrompt;

function setupInstallButton() {
  const btn = document.getElementById('pwa-install-btn');
  if (!btn) return;
  btn.style.display = 'none';

  window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent automatic mini-infobar
    e.preventDefault();
    deferredPrompt = e;
    btn.style.display = 'inline-flex';
  });

  btn.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    btn.disabled = true;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    console.log('[PWA] userChoice:', outcome);
    deferredPrompt = null; // reset
    setTimeout(()=>{ btn.disabled = false; btn.style.display='none'; }, 800);
  });

  window.addEventListener('appinstalled', () => {
    console.log('[PWA] App installed');
    if (btn) btn.style.display = 'none';
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupInstallButton);
} else {
  setupInstallButton();
}
