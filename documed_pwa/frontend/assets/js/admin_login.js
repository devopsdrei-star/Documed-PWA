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
  adminLoginMsg.style.color = 'green';
  adminLoginMsg.textContent = 'Login successful! Redirecting...';
  setTimeout(() => {
  window.location.href = '../admin/dashboard.html';
  }, 1000);
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
