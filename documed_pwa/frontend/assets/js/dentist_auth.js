// Dentist auth: login/register via manage_user.php
(function(){
  const loginForm = document.getElementById('dentistLoginForm');
  const registerForm = document.getElementById('dentistRegisterForm');

  if (loginForm) {
    loginForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const msg = document.getElementById('dentistLoginMsg');
      msg.textContent='';
      const form = new FormData(loginForm);
      form.append('action','login_doc_nurse');
      try {
        const res = await fetch('../../backend/api/manage_user.php', { method:'POST', body: form });
        const data = await res.json();
        if (data && data.success && data.user) {
          // enforce Dentist role
          const rl = String(data.user.role||'').toLowerCase();
          if (rl.includes('dentist')) {
            localStorage.setItem('documed_docnurse', JSON.stringify(data.user));
            location.href = 'dashboard.html';
          } else {
            msg.textContent = 'Only dentist accounts can login here.';
          }
        } else {
          msg.textContent = (data && data.message) ? data.message : 'Login failed.';
        }
      } catch (err) {
        msg.textContent = 'Network error. Please try again.';
      }
    });
  }

  if (registerForm) {
    registerForm.addEventListener('submit', async function(e){
      e.preventDefault();
      const msg = document.getElementById('dentistRegisterMsg');
      msg.textContent='';
      const btn = document.getElementById('registerBtn');
      btn.disabled = true;
      const form = new FormData(registerForm);
      form.append('action', 'register_doc_nurse');
      // Force role to Dentist, normalize on backend too
      form.set('role', 'Dentist');
      const photo = registerForm.querySelector('#photo').files[0];
      if (!photo) { msg.textContent = 'Photo is required.'; btn.disabled=false; return; }
      if (!/^image\//.test(photo.type)) { msg.textContent = 'Invalid photo type.'; btn.disabled=false; return; }
      if (photo.size > 2*1024*1024) { msg.textContent = 'Photo too large (max 2MB).'; btn.disabled=false; return; }
      try {
        const res = await fetch('../../backend/api/manage_user.php', { method:'POST', body: form });
        const text = await res.text();
        let data = {};
        try { data = JSON.parse(text); } catch(_) { data = { success:false, message: text || 'Server error' }; }
        if (data && data.success) {
          msg.textContent = 'Registered successfully. Redirecting to login...';
          setTimeout(()=> location.href = 'dentist_login.html', 1000);
        } else {
          msg.textContent = (data && data.message) ? data.message : 'Registration failed.';
        }
      } catch (err) {
        msg.textContent = 'Network error. Please try again.';
      } finally { btn.disabled = false; }
    });
  }
})();
