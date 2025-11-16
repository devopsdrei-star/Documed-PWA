function clearAllCookies() {
  try {
    document.cookie.split(';').forEach(function(c) {
      document.cookie = c
        .replace(/^\s+/, '')
        .replace(/=.*/, '=;expires=' + new Date(0).toUTCString() + ';path=/');
    });
  } catch(_) {}
}

async function handleDocNurseLogout(e) {
  e && e.preventDefault && e.preventDefault();
  if (!confirm('Are you sure you want to logout?')) return;
  try {
    localStorage.clear();
    sessionStorage.clear();
    clearAllCookies();
    window.location.replace('doc_nurse_login.html');
    setTimeout(() => {
      window.location.href = 'doc_nurse_login.html';
      window.location.reload(true);
    }, 100);
  } catch (error) {
    console.error('Logout error:', error);
    window.location.href = 'doc_nurse_login.html';
  }
}

(function(){
  const sidebarLogoutBtn = document.querySelector('.sidebar .logout');
  if (sidebarLogoutBtn) {
    sidebarLogoutBtn.addEventListener('click', handleDocNurseLogout);
  }
  const dropdownLogout = document.getElementById('logoutMenu');
  if (dropdownLogout) {
    dropdownLogout.addEventListener('click', handleDocNurseLogout);
  }
})();