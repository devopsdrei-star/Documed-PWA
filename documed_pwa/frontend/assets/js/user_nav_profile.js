// Shared user navigation profile + notification bell injection.
// Mirrors logic from user_dashboard.html without page-specific elements.
(function(){
  function buildLoggedInNav(user){
    var navLinks = document.getElementById('navLinks');
    if (navLinks){
      navLinks.innerHTML = ''+
        '<a href="user_dashboard.html">Home</a>'+
        '<a href="appointments.html">Appointment</a>'+
        '<a href="medical_history.html">Medical History</a>'+
        '<a href="profile.html">Profile</a>'+
        '<a href="user_dashboard.html#services" id="navServices">Services</a>'+
        '<a href="user_dashboard.html#about" id="navAbout">About Us</a>'+
        '<a href="user_dashboard.html#contact" id="navContact">Contact</a>'+
        '<a href="#" id="navHelp">Help</a>';
    }
    var navActions = document.getElementById('navActions');
    var photoUrl = (user && user.photo) ? user.photo : '../assets/images/user_photo.png';
    if (navActions){
      navActions.innerHTML = ''+
        '<div class="dropdown d-inline-block me-2 position-relative">'+
          '<button class="btn" id="notifBellBtn" style="background:none;color:#2563eb;font-size:1.7rem;" title="Notifications">'+
            '<i class="bi bi-bell"></i>'+
          '</button>'+
          '<button class="btn dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="background:none; padding:0;">'+
            '<img id="navProfileImg" src="'+photoUrl+'" alt="Profile" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #2563eb;background:#fff;" />'+
          '</button>'+
          '<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown" style="min-width:180px;">'+
            '<li><a class="dropdown-item" href="profile.html"><i class="bi bi-person"></i> Manage Account</a></li>'+
            '<li><a class="dropdown-item" href="#" id="viewQRBtn"><i class="bi bi-qr-code"></i> View QR</a></li>'+
            '<li><a class="dropdown-item" href="#" id="scanApproveBtn"><i class="bi bi-upc-scan"></i> Scan QR (Approve Login)</a></li>'+
            '<li><hr class="dropdown-divider"></li>'+
            '<li><a class="dropdown-item text-danger" href="#" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> Logout</a></li>'+
          '</ul>'+
        '</div>';
    }
  }
  function buildGuestNav(){
    var navLinks = document.getElementById('navLinks');
    if (navLinks){
      navLinks.innerHTML = ''+
        '<a href="user_dashboard.html">Home</a>'+
        '<a href="#" id="navServices">Services</a>'+
        '<a href="#" id="navAbout">About Us</a>'+
        '<a href="#" id="navContact">Contact</a>';
    }
    var navActions = document.getElementById('navActions');
    if (navActions){ navActions.innerHTML = ''; }
  }
  function rehydrateFromSession(){
    if (localStorage.getItem('documed_user')) return; // already present
    fetch('../../backend/api/auth.php?action=session_ping')
      .then(r=>r.json())
      .then(d=>{
        if (d && d.success && d.type === 'user' && d.user){
          try { localStorage.setItem('documed_user', JSON.stringify(d.user)); } catch(_){ }
          sessionStorage.setItem('documedLoggedIn','1');
          buildLoggedInNav(d.user);
        }
      })
      .catch(()=>{});
  }
  function init(){
    var user = null;
    try { user = JSON.parse(localStorage.getItem('documed_user')||'null'); } catch(_){ user=null; }
    if (sessionStorage.getItem('documedLoggedIn') === '1' && user){
      buildLoggedInNav(user);
    } else if (user){
      // Local user but session flag missing, treat as logged-in
      sessionStorage.setItem('documedLoggedIn','1');
      buildLoggedInNav(user);
    } else {
      buildGuestNav();
      rehydrateFromSession();
    }
    // Listen for loginSuccess events (fired by login modules)
    window.addEventListener('loginSuccess', function(){
      try { var u = JSON.parse(localStorage.getItem('documed_user')||'null'); buildLoggedInNav(u); } catch(_){ buildLoggedInNav(null); }
    });
    // Update avatar if photo changes
    window.addEventListener('storage', function(e){
      if (e.key !== 'documed_user') return;
      try {
        var u = JSON.parse(e.newValue||'null');
        var src = (u && u.photo) ? u.photo : '../assets/images/user_photo.png';
        var img = document.getElementById('navProfileImg');
        if (img) img.src = src + (src.includes('?')?'&':'?') + 't=' + Date.now();
      } catch(_){ }
    });
    window.addEventListener('profilePhotoUpdated', function(ev){
      var src = (ev && ev.detail && ev.detail.photo) ? ev.detail.photo : null;
      if (!src) return;
      var img = document.getElementById('navProfileImg');
      if (img) img.src = src + (src.includes('?')?'&':'?') + 't=' + Date.now();
    });
    // Logout handling (delegated)
    document.body.addEventListener('click', function(e){
      var t = e.target && e.target.closest && e.target.closest('#logoutBtn');
      if (!t) return;
      e.preventDefault();
      // Preserve notification read state keys
      var preserveKeys = Object.keys(localStorage).filter(function(k){ return k.startsWith('documed_notif_read_'); });
      var preserve = new Map(preserveKeys.map(function(k){ return [k, localStorage.getItem(k)]; }));
      localStorage.clear();
      preserve.forEach(function(v,k){ localStorage.setItem(k,v); });
      sessionStorage.clear();
      window.location.href = 'user_dashboard.html';
    });
  }
  document.addEventListener('DOMContentLoaded', init);
})();
