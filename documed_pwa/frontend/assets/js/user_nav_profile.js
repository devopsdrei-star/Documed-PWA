 // Show profile/settings icons after successful login
    function showProfileNav() {
      const navActions = document.getElementById('navActions');
      // Read user to get profile photo
      let userObj = null;
      try { userObj = JSON.parse(localStorage.getItem('documed_user')); } catch(_){}
  const photoUrl = (userObj && userObj.photo) ? userObj.photo : '../assets/images/user_photo.png';
      if (navActions) {
        navActions.innerHTML = `
          <div class="dropdown d-inline-block me-2 position-relative">
            <button class="btn" id="notifBellBtn" style="background:none;color:#2563eb;font-size:1.7rem;" title="Notifications">
              <i class="bi bi-bell"></i>
            </button>
            <button class="btn dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="background:none; padding:0;">
              <img id="navProfileImg" src="${photoUrl}" alt="Profile" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid #2563eb;background:#fff;" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown" style="min-width:180px;">
              <li><a class="dropdown-item" href="profile.html"><i class="bi bi-person"></i> Manage Account</a></li>
              <li><a class="dropdown-item" href="#" id="viewQRBtn"><i class="bi bi-qr-code"></i> View Patient QR Code</a></li>
              <li><a class="dropdown-item" href="#" id="scanApproveBtn"><i class="bi bi-upc-scan"></i> Scan QR (Approve Login)</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="#" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
          </div>
        `;
      }
      // Update nav links for logged-in user
      const navLinks = document.getElementById('navLinks');
      if (navLinks) {
        navLinks.innerHTML = `
         <a href="user_dashboard.html">Home</a>
          <a href="appointments.html">Appointment</a>
          <a href="medical_history.html">Medical History</a>
          <a href="profile.html">Profile</a>
          <a href="#services" id="navServices">Services</a>
          <a href="#about" id="navAbout">About Us</a>
          <a href="#contact" id="navContact">Contact</a>
          <a href="#" id="navHelp">Help</a>
        `;
      }
    }
    // Listen for login success from modal JS
    window.addEventListener('loginSuccess', showProfileNav);
    // Also check localStorage/sessionStorage for login state
    if (sessionStorage.getItem('documedLoggedIn') === '1') {
      showProfileNav();
    }
    // Update nav avatar if localStorage documed_user changes in this tab
    window.addEventListener('storage', function(e){
      if (e.key !== 'documed_user') return;
      try {
        const u = JSON.parse(e.newValue || 'null');
        const src = (u && u.photo) ? u.photo : '../assets/images/user_photo.png';
        const img = document.getElementById('navProfileImg');
        if (img) img.src = src + (src.includes('?') ? '&' : '?') + 't=' + Date.now();
      } catch(_) {}
    });
    // Also react immediately within the page to profile updates
    window.addEventListener('profilePhotoUpdated', function(ev){
      const src = (ev && ev.detail && ev.detail.photo) ? ev.detail.photo : null;
      if (!src) return;
      const img = document.getElementById('navProfileImg');
      if (img) img.src = src + (src.includes('?') ? '&' : '?') + 't=' + Date.now();
    });
    // Reset nav links for guests
    function showGuestNav() {
      const navLinks = document.getElementById('navLinks');
      if (navLinks) {
        navLinks.innerHTML = `
          <a href="user_dashboard.html">Home</a>
          <a href="#" id="navServices">Services</a>
          <a href="#" id="navAbout">About Us</a>
          <a href="#" id="navContact">Contact</a>
        `;
      }
      const navActions = document.getElementById('navActions');
      if (navActions) { navActions.innerHTML = ''; }
    }
    document.addEventListener('DOMContentLoaded', function() {
      // Rehydrate localStorage user from server session if missing
      if (!localStorage.getItem('documed_user')) {
        fetch('../../backend/api/auth.php?action=session_ping')
          .then(r=>r.json())
          .then(d=>{
            if (d && d.success && d.type === 'user' && d.user) {
              try { localStorage.setItem('documed_user', JSON.stringify(d.user)); } catch(_) {}
              sessionStorage.setItem('documedLoggedIn','1');
              window.dispatchEvent(new Event('loginSuccess'));
            }
          })
          .catch(()=>{});
      }
      // Ensure nav avatar shows latest stored photo when already logged in
      if (sessionStorage.getItem('documedLoggedIn') === '1') {
        try {
          const u = JSON.parse(localStorage.getItem('documed_user')||'null');
          const src = (u && u.photo) ? u.photo : '../assets/images/user_photo.png';
          const img = document.getElementById('navProfileImg');
          if (img) img.src = src + (src.includes('?') ? '&' : '?') + 't=' + Date.now();
        } catch(_) {}
      }
      // Use event delegation since logout button may be added dynamically
      document.body.addEventListener('click', function(e) {
        if (e.target && e.target.id === 'logoutBtn') {
          e.preventDefault();
          // Clear auth/session but preserve notification read state
          const preserveKeys = Object.keys(localStorage).filter(k=>k.startsWith('documed_notif_read_'));
          const preserve = new Map(preserveKeys.map(k=>[k, localStorage.getItem(k)]));
          localStorage.clear();
          // Restore preserved notification read states
          preserve.forEach((v,k)=> localStorage.setItem(k,v));
          sessionStorage.clear();
          // Redirect to dashboard
          window.location.href = 'user_dashboard.html';
        }
      });
    });