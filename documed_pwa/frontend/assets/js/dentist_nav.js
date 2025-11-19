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
      navigator.serviceWorker.register(swUrl).then(()=>{
        console.debug('[PWA] dentist SW registered', swUrl);
      }).catch(()=>{});
    }
  } catch (e) { console.debug('[PWA] dentist init failed', e); }

})();
