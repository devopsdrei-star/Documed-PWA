/**
 * Deprecated: Unused script (as of 2025-10-13).
 * Not referenced by any HTML under documed_pwa/frontend. Kept for reference.
 * If you need to restore it, remove the guard below and re-include on the target page.
 */

if (false) {
document.addEventListener('DOMContentLoaded', function() {
    // Resolve user id safely from localStorage
    let currentUser = null;
    try { currentUser = JSON.parse(localStorage.getItem('documed_user')); } catch(_) { currentUser = null; }
    // Fetch user info from backend to get latest QR code and profile info
    fetch('../../backend/api/auth.php?action=get_user', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(currentUser && currentUser.id ? currentUser.id : '')}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.user) {
            // Example: display user info
            document.getElementById('profileName').textContent = data.user.name || '';
            document.getElementById('profileEmail').textContent = data.user.email || '';
            document.getElementById('profileID').textContent = data.user.student_faculty_id || '';
            document.getElementById('profileExpiry').textContent = data.user.qr_expiry || '';
            if (data.user.qr_code) {
                document.getElementById('user-qr').src = data.user.qr_code;
                document.getElementById('user-qr').style.display = 'block';
            }
        } else {
            document.getElementById('qrMsg').textContent = 'User info not found.';
        }
    })
    .catch(() => {
        document.getElementById('qrMsg').textContent = 'Error loading user info.';
    });

// Note: The below sample login-related code was commented/adjusted to avoid runtime errors
// and should be wired to actual login responses in your login flow.
// localStorage.setItem('documed_user', JSON.stringify(data.user));
// localStorage.setItem('id', data.user.id);
// sessionStorage.setItem('documedLoggedIn', '1');

    // Logout logic
    const logoutBtn = document.querySelector('.logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to logout?')) return;
            try {
                // Preserve notification read states before clearing
                const preserveKeys = Object.keys(localStorage).filter(k=>k.startsWith('documed_notif_read_'));
                const preserve = new Map(preserveKeys.map(k=>[k, localStorage.getItem(k)]));
                localStorage.clear();
                // Restore preserved notification read states
                preserve.forEach((v,k)=> localStorage.setItem(k,v));
                sessionStorage.clear();
                document.cookie.split(';').forEach(function(c){
                    document.cookie = c.replace(/^\s+/, '').replace(/=.*/, '=;expires=' + new Date(0).toUTCString() + ';path=/');
                });
            } catch(_) {}
            window.location.href = 'user_dashboard.html';
        });
    }
});
}