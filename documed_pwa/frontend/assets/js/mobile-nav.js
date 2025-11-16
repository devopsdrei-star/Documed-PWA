// Simple mobile nav toggle
(function(){
  function setNavOpen(nav, open){
    if (!nav) return;
    nav.classList.toggle('nav-open', !!open);
    document.body.classList.toggle('menu-open', !!open);
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Toggle via burger
    document.querySelectorAll('.nav-burger').forEach(function(btn){
      btn.addEventListener('click', function(){
        var nav = btn.closest('.top-nav');
        var isOpen = nav && nav.classList.contains('nav-open');
        setNavOpen(nav, !isOpen);
      });
    });

    // Close after clicking a nav link
    document.querySelectorAll('.top-nav .nav-links a').forEach(function(a){
      a.addEventListener('click', function(){
        var nav = a.closest('.top-nav');
        setNavOpen(nav, false);
      });
    });

    // Also close when tapping/clicking the background of the mobile menu panel
    // (useful when .nav-links is fixed and covers the page below 768px)
    document.querySelectorAll('.top-nav .nav-links').forEach(function(links){
      links.addEventListener('click', function(e){
        // If the click is NOT on or inside an anchor, treat it as backdrop click
        if (!e.target.closest('a')) {
          var nav = links.closest('.top-nav');
          setNavOpen(nav, false);
        }
      });
    });

    // Close on scroll
    let scrollCloseTimeout;
    window.addEventListener('scroll', function(){
      clearTimeout(scrollCloseTimeout);
      scrollCloseTimeout = setTimeout(function(){
        document.querySelectorAll('.top-nav.nav-open').forEach(function(nav){ setNavOpen(nav, false); });
      }, 50);
    }, { passive: true });

    // Close on outside click
    document.addEventListener('click', function(e){
      const anyOpen = document.querySelector('.top-nav.nav-open');
      if (!anyOpen) return;
      if (!e.target.closest('.top-nav')) {
        setNavOpen(anyOpen, false);
      }
    });

    // Close on ESC and on resize to desktop
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') {
        document.querySelectorAll('.top-nav.nav-open').forEach(function(nav){ setNavOpen(nav, false); });
      }
    });
    window.addEventListener('resize', function(){
      if (window.innerWidth > 768) {
        document.querySelectorAll('.top-nav.nav-open').forEach(function(nav){ setNavOpen(nav, false); });
      }
    });

    // If any Bootstrap modal opens, ensure the mobile nav is closed to avoid overlap
    document.addEventListener('show.bs.modal', function(){
      document.querySelectorAll('.top-nav.nav-open').forEach(function(nav){ setNavOpen(nav, false); });
    });
  });
})();