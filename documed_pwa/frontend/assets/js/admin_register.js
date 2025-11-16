// Admin registration logic
const adminRegisterForm = document.getElementById('adminRegisterForm');
const adminRegisterMsg = document.getElementById('adminRegisterMsg');
if (adminRegisterForm) {
  adminRegisterForm.addEventListener('submit', function(e) {
    e.preventDefault();
    adminRegisterMsg.textContent = 'Registering...';
    const school_id = adminRegisterForm.school_id ? adminRegisterForm.school_id.value : '';
    const name = adminRegisterForm.name.value;
    const email = adminRegisterForm.email.value;
    const password = adminRegisterForm.password.value;
  fetch('../../backend/api/auth.php?action=admin_register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `school_id=${encodeURIComponent(school_id)}&name=${encodeURIComponent(name)}&email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        adminRegisterMsg.style.color = 'green';
        adminRegisterMsg.textContent = 'Registration successful! You can now login.';
        setTimeout(() => {
          window.location.href = 'admin_login.html';
        }, 1200);
      } else {
        adminRegisterMsg.style.color = 'red';
        adminRegisterMsg.textContent = data.error || 'Registration failed.';
      }
    })
    .catch(() => {
      adminRegisterMsg.style.color = 'red';
      adminRegisterMsg.textContent = 'Error connecting to server.';
    });
  });
}
