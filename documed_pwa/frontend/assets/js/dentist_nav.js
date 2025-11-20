// Sidebar active state and logout confirmation for Dentist pages
(function(){
  const links = document.querySelectorAll('.sidebar-nav a');
  links.forEach(a => {
    if (a.getAttribute('href') && location.pathname.endsWith(a.getAttribute('href'))) {
      a.classList.add('active');
    }
  });
  const logout = document.querySelector('.sidebar .logout');
  if (logout) {
    logout.addEventListener('click', function(e){
      const ok = confirm('Are you sure you want to logout?');
      if (!ok) { e.preventDefault(); }
      else {
        try { localStorage.removeItem('documed_docnurse'); } catch(_){}
      }
    });
  }
  // PWA bootstrap for Dentist pages (inject manifest/meta and register SW)
  try {
    if (!document.querySelector('link[rel="manifest"]')) {
      const link = document.createElement('link');
      link.rel = 'manifest';
      link.href = '../../manifest-landing.json';
      document.head.appendChild(link);
    }
    if (!document.querySelector('meta[name="theme-color"]')) {
      const m = document.createElement('meta'); m.name = 'theme-color'; m.content = '#0a6ecb'; document.head.appendChild(m);
    }
    if ('serviceWorker' in navigator) {
      const swUrl = new URL('../../service-worker.js', window.location.href).toString();
      navigator.serviceWorker.register(swUrl).then(reg=>{
        console.debug('[PWA] dentist SW registered', swUrl);
        // Listen for backend mutation invalidation broadcasts
        navigator.serviceWorker.addEventListener('message', (evt) => {
          const msg = evt.data || {};
          if (msg.type === 'invalidate') {
            if (window.__dnInvalidateTimer) return; // throttle
            window.__dnInvalidateTimer = setTimeout(()=>{ window.__dnInvalidateTimer = null; }, 1000);
            let updated = false;
            // Example dynamic components that rely on API data
            const dashPatients = document.getElementById('patientsTable');
            if (dashPatients) {
              fetch('../../backend/api/patient.php?action=list&cacheBust=' + Date.now())
                .then(r=>r.json()).then(d=>{
                  if (d.patients && Array.isArray(d.patients)) {
                    dashPatients.innerHTML = d.patients.map(p => `<tr><td>${p.id||''}</td><td>${p.name||''}</td><td>${p.gender||''}</td></tr>`).join('');
                  }
                });
              updated = true;
            }
            const apptTable = document.getElementById('appointmentsTable');
            if (apptTable) {
              fetch('../../backend/api/appointment.php?action=list&cacheBust=' + Date.now())
                .then(r=>r.json()).then(d=>{
                  if (d.appointments && Array.isArray(d.appointments)) {
                    apptTable.innerHTML = d.appointments.map(a => `<tr><td>${a.id}</td><td>${a.purpose||''}</td><td>${a.status||''}</td></tr>`).join('');
                  }
                });
              updated = true;
            }
            // Fallback: soft reload to reflect other changes
            if (!updated) {
              location.reload();
            }
          }
        });
      }).catch(()=>{});
    }
  } catch (e) { console.debug('[PWA] dentist init failed', e); }

})();
