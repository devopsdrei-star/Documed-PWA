// Doc/Nurse PWA bootstrap & invalidate listener
(function(){
  try {
    if (!document.querySelector('link[rel="manifest"]')) {
      const link = document.createElement('link'); link.rel='manifest'; link.href='../../manifest-landing.json'; document.head.appendChild(link);
    }
    if (!document.querySelector('meta[name="theme-color"]')) { const m=document.createElement('meta'); m.name='theme-color'; m.content='#0a6ecb'; document.head.appendChild(m); }
    if ('serviceWorker' in navigator) {
      const swUrl = new URL('../../service-worker.js', window.location.href).toString();
      navigator.serviceWorker.register(swUrl).then(reg => {
        console.debug('[PWA] doc_nurse SW registered', reg.scope);
        navigator.serviceWorker.addEventListener('message', (evt) => {
          const msg = evt.data || {};
          if (msg.type === 'invalidate') {
            if (window.__dnurseInvalidateTimer) return; window.__dnurseInvalidateTimer = setTimeout(()=>{ window.__dnurseInvalidateTimer = null; }, 1000);
            let updated = false;
            // Refresh patients list if present
            const patTable = document.getElementById('patientsTable');
            if (patTable) {
              fetch('../../backend/api/patient.php?action=list&cacheBust=' + Date.now())
                .then(r=>r.json()).then(d=>{
                  if (d.patients) {
                    patTable.innerHTML = d.patients.map(p => `<tr><td>${p.id||''}</td><td>${p.name||''}</td><td>${p.gender||''}</td></tr>`).join('');
                  }
                });
              updated = true;
            }
            // Refresh checkup stats if element present
            const statsEl = document.getElementById('checkupStats');
            if (statsEl) {
              fetch('../../backend/api/checkup_analytics.php?action=summary&cacheBust=' + Date.now())
                .then(r=>r.json()).then(d=>{
                  if (d.success && d.summary) {
                    statsEl.textContent = 'Today: ' + (d.summary.today||0) + ' | Week: ' + (d.summary.week||0);
                  }
                });
              updated = true;
            }
            // If no targeted component updated, do nothing (never reload)
            // Optionally, show a toast or log for debugging
            if (!updated) {
              if (window.showToast) {
                showToast('info', 'Data updated in background.');
              } else {
                console.info('[DocMed] Data updated in background (no reload).');
              }
            }
          }
        });
      }).catch(()=>{});
    }
  } catch(e){ console.debug('[PWA] doc_nurse init failed', e); }
})();