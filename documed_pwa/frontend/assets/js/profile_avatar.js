// Set dashboard small avatar image based on logged-in Doc/Nurse profile
// Looks for the image inside #dashboardProfileBtn
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    try {
      var btn = document.getElementById('dashboardProfileBtn');
      if (!btn) return;
      var img = btn.querySelector('img');
      if (!img) return;
      // Dentist login stores profile under 'documed_docnurse'
      var raw = localStorage.getItem('documed_docnurse') || localStorage.getItem('documed_doc_nurse');
  var fallback = '../assets/images/documed_logo.png';
      if (!raw) { img.src = fallback; return; }
      var user = {};
      try { user = JSON.parse(raw) || {}; } catch(_) { user = {}; }
      // prefer dn_photo; fallback to photo; else placeholder
      var src = user.dn_photo || user.photo || fallback;
      img.src = src || fallback;
    } catch(_) {}
  });
})();
