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
})();
