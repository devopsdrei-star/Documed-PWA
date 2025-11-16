

  // Login modal
  const loginForm = document.getElementById('userLoginFormModal');
  const loginMsg = document.getElementById('userLoginMsgModal');
  if (loginForm) {
    loginForm.addEventListener('submit', function(e) {
      e.preventDefault();
      loginMsg.textContent = 'Logging in...';
      loginMsg.style.color = 'black';
      const formData = new FormData(loginForm);
      fetch('../../backend/api/auth.php?action=user_login', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success && data.user) {
          loginMsg.textContent = 'Login successful!';
          loginMsg.style.color = 'green';
          // Store user data properly
          localStorage.setItem('documed_user', JSON.stringify(data.user));
          localStorage.setItem('id', data.user.id);
          sessionStorage.setItem('documedLoggedIn', '1');
          setTimeout(() => {
            loginMsg.textContent = '';
            window.dispatchEvent(new Event('loginSuccess'));
            window.location.href = 'user_dashboard.html';
          }, 1200);
        } else {
          loginMsg.textContent = data.message || 'Login failed.';
        }
      })
      .catch(() => {
        loginMsg.textContent = 'Error connecting to server.';
        loginMsg.style.color = 'red';
      });
// Show login modal if redirected after registration or Book Now
document.addEventListener('DOMContentLoaded', function() {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('showLogin') === '1') {
    setTimeout(() => {
      var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
      loginModal.show();
    }, 400);
  }
  // Book Now button opens login modal
  const bookBtn = document.querySelector('.btn.btn-primary');
  if (bookBtn) {
    bookBtn.addEventListener('click', function(e) {
      e.preventDefault();
      var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
      loginModal.show();
    });
  }
});
    });
  }
