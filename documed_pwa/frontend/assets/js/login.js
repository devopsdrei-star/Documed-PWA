/**
 * Deprecated: Legacy login handler not referenced by current pages.
 * Kept for reference; use user_login.js instead.
 */

if (false) {
// login.js
const loginForm = document.getElementById('loginForm');
const loginMsg = document.getElementById('loginMsg');

if (loginForm) {
  loginForm.addEventListener('submit', function(e) {
    e.preventDefault();
    loginMsg.textContent = 'Logging in...';

  const email = loginForm.email.value; // can be email or SID
    const password = loginForm.password.value;

    fetch('../backend/api/auth.php?action=user_login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.user) {
        const user = data.user;
        // Persist user
        try { localStorage.setItem('user', JSON.stringify(user)); } catch(_) {}

        // Save user info to localStorage
        if (user && user.id) localStorage.setItem('id', user.id);
        if (user && user.student_faculty_id) {
          localStorage.setItem('student_faculty_id', user.student_faculty_id);
        }
        const fullName = (user && (user.name || ((user.first_name||'') + ' ' + (user.last_name||''))).trim()) || '';
        if (fullName) localStorage.setItem('name', fullName);
        if (user && user.email) localStorage.setItem('email', user.email);

        loginMsg.style.color = 'green';
        loginMsg.textContent = 'Login successful! Redirecting...';
        setTimeout(() => {
          window.location.href = 'user_dashboard.html';
        }, 1000);
      } else {
        loginMsg.style.color = 'red';
        loginMsg.textContent = data.message || data.error || 'Login failed.';
      }
    })
    .catch(() => {
      loginMsg.style.color = 'red';
      loginMsg.textContent = 'Error connecting to server.';
    });
  });
}
}
