// User login logic
const userLoginForm = document.getElementById('userLoginForm');
const userLoginMsg = document.getElementById('userLoginMsg');
if (userLoginForm) {
  userLoginForm.addEventListener('submit', function(e) {
    e.preventDefault();
    userLoginMsg.textContent = 'Logging in...';
  const email = userLoginForm.email.value; // email or School ID
    const password = userLoginForm.password.value;
  fetch('../../backend/api/auth.php?action=user_login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.user) {
        userLoginMsg.style.color = 'green';
        userLoginMsg.textContent = 'Login successful! Redirecting...';
        // Save user info to localStorage for dashboard
        localStorage.setItem('documed_user', JSON.stringify(data.user));
        sessionStorage.setItem('documedLoggedIn', '1');
        setTimeout(() => {
          window.location.href = 'user_dashboard.html';
        }, 1000);
      } else {
        userLoginMsg.style.color = 'red';
        userLoginMsg.textContent = data.message || data.error || 'Login failed.';
      }
    })
    .catch(() => {
      userLoginMsg.style.color = 'red';
      userLoginMsg.textContent = 'Error connecting to server.';
    });
  });
}
