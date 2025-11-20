// Admin login logic
const adminLoginForm = document.getElementById('adminLoginForm');
const adminLoginMsg = document.getElementById('adminLoginMsg');
if (adminLoginForm) {
  adminLoginForm.addEventListener('submit', function(e) {
    e.preventDefault();
    adminLoginMsg.textContent = 'Logging in...';
  const email = adminLoginForm.email.value; // email or School ID
    const password = adminLoginForm.password.value;
  fetch('../../backend/api/auth.php?action=admin_login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.admin) {
        try {
          // Persist admin session info for audit logging and UI
          localStorage.setItem('admin_id', data.admin.id);
          localStorage.setItem('admin_name', data.admin.name || data.admin.email || 'Admin');
        } catch(_) { /* ignore storage errors */ }
        // Write audit trail login event (non-blocking)
        try {
          const aid = encodeURIComponent(data.admin.id);
          fetch('../../backend/api/audit_trail.php?action=add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `admin_id=${aid}&action_txt=Admin login&details=Identifier: ${encodeURIComponent(data.admin.email || data.admin.school_id || '')}`
          }).catch(()=>{});
        } catch(_) { /* ignore */ }
        adminLoginMsg.style.color = 'green';
        adminLoginMsg.textContent = 'Login successful! Redirecting...';
        setTimeout(() => { window.location.href = '../admin/dashboard.html'; }, 650);
} else {
  adminLoginMsg.style.color = 'red';
  adminLoginMsg.textContent = data.error || 'Login failed.';
}
    })
    .catch(() => {
      adminLoginMsg.style.color = 'red';
      adminLoginMsg.textContent = 'Error connecting to server.';
    });
  });
}
