// profile.js

document.addEventListener('DOMContentLoaded', function() {
  // Only run on profile page where the form exists
  const profileForm = document.getElementById('profileEditForm');
  if (!profileForm) return;

  // Handle photo preview (guard if input exists)
  const photoInput = document.getElementById('photoInput');
  if (photoInput) {
    photoInput.addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const img = document.getElementById('profilePhoto');
          if (img) img.src = e.target.result;
        }
        reader.readAsDataURL(file);
      }
    });
  }

  const userObj = JSON.parse(localStorage.getItem('documed_user'));
  
  if (!userObj || !userObj.id) {
    profileForm.innerHTML = '<div class="error-msg">Please log in to view your profile.</div>';
    window.location.href = 'user_login.html';
    return;
  }
  
  let userId = userObj.id;

  // Ensure user ID is retrieved correctly from localStorage
  if (!userId && userObj) {
    userId = userObj.id;
    localStorage.setItem('id', userId); // Store user ID for consistency
  }

  if (!userId) {
    profileForm.innerHTML = '<div class="error-msg">Please log in to view your profile. (No user ID found)</div>';
    return;
  }

  // Fetch user info from backend
  fetch('../../backend/api/auth.php?action=get_user_full', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${userId}`
  })
  .then(res => res.text())  // Get raw response text first
  .then(text => {
    try {
      return JSON.parse(text);  // Try to parse as JSON
    } catch (e) {
      console.error('Server response:', text);  // Log any problematic response
      throw new Error('Invalid JSON response from server');
    }
  })
  .then(data => {
    if (!data.success || !data.user) {
      document.getElementById('profileEditForm').innerHTML = '<div class="error-msg">User not found. (ID: ' + userId + ')</div>';
      return;
    }
    const user = data.user;
    document.getElementById('editEmail').value = user.email || '';
    document.getElementById('editFirstName').value = user.first_name || '';
    document.getElementById('editLastName').value = user.last_name || '';
    document.getElementById('editMiddleInitial').value = user.middle_initial || '';
    document.getElementById('editAge').value = user.age || '';
    document.getElementById('editAddress').value = user.address || '';
    // Set civil status select; match case-insensitively to existing values
    (function() {
      const sel = document.getElementById('editCivilStatus');
      const val = (user.civil_status || '').toString().trim().toLowerCase();
      if (!sel) return;
      const opts = Array.from(sel.options).map(o => o.value.toLowerCase());
      const idx = opts.indexOf(val);
      if (idx >= 0) sel.selectedIndex = idx; else sel.value = '';
    })();
    document.getElementById('editNationality').value = user.nationality || '';
    document.getElementById('editReligion').value = user.religion || '';
    document.getElementById('editDOB').value = user.date_of_birth || '';
    document.getElementById('editPOB').value = user.place_of_birth || '';
    document.getElementById('editYearCourse').value = user.year_course || '';
    document.getElementById('editID').value = user.student_faculty_id || '';
    document.getElementById('editContactPerson').value = user.contact_person || '';
    document.getElementById('editContact').value = user.contact_number || '';
  document.getElementById('editRole').value = user.role || '';
    if (user.photo) {
      document.getElementById('profilePhoto').src = user.photo;
    }

    // Initialize QR Login status display (best-effort based on presence of personal QR rotation timestamp in DB, if ever exposed)
    (function initQrLoginUI(){
      const statusEl = document.getElementById('qrLoginStatus');
      if (!statusEl) return;
      // We don't have explicit qr_enabled in user payload; treat as unknown until user enables.
      statusEl.style.display = 'none';
    })();

    // Toggle Year & Course and Department fields based on role
    const yearCourseGroup = document.getElementById('editYearCourse').parentElement;
    const departmentGroup = document.getElementById('departmentGroup');
    if (user.role === 'Teacher' || user.role === 'Non-Teaching') {
      yearCourseGroup.style.display = 'none';
      departmentGroup.style.display = 'block';
      document.getElementById('editDepartment').value = user.department || '';
    } else {
      yearCourseGroup.style.display = 'block';
      departmentGroup.style.display = 'none';
    }
  });

  // Save profile changes
  profileForm.onsubmit = function(e) {
    e.preventDefault();
    const msg = document.getElementById('profileMsg');
    let formData = new FormData();
    formData.append('id', userId);
    formData.append('email', document.getElementById('editEmail').value);
    formData.append('first_name', document.getElementById('editFirstName').value);
    formData.append('last_name', document.getElementById('editLastName').value);
    formData.append('middle_initial', document.getElementById('editMiddleInitial').value);
    formData.append('age', document.getElementById('editAge').value);
    formData.append('address', document.getElementById('editAddress').value);
  formData.append('civil_status', document.getElementById('editCivilStatus').value);
    formData.append('nationality', document.getElementById('editNationality').value);
    formData.append('religion', document.getElementById('editReligion').value);
    formData.append('date_of_birth', document.getElementById('editDOB').value);
    formData.append('place_of_birth', document.getElementById('editPOB').value);
    formData.append('year_course', document.getElementById('editYearCourse').value);
    formData.append('student_faculty_id', document.getElementById('editID').value);
    formData.append('contact_person', document.getElementById('editContactPerson').value);
    formData.append('contact_number', document.getElementById('editContact').value);
  // Role is read-only; backend enforces current DB value, but keep sending for completeness
  formData.append('role', document.getElementById('editRole').value);
    formData.append('department', document.getElementById('editDepartment').value);
    // Photo upload
    const photoInput = document.getElementById('photoInput');
    if (photoInput.files.length > 0) {
      formData.append('photo', photoInput.files[0]);
    }
    fetch('../../backend/api/auth.php?action=update_user', {
      method: 'POST',
      body: formData
    })
    .then(res => res.text())  // First get the raw response
    .then(text => {
      try {
        return JSON.parse(text);  // Try to parse it as JSON
      } catch (e) {
        console.error('Server response:', text);  // Log the problematic response
        throw new Error('Invalid JSON response from server');
      }
    })
    .then(data => {
      if (data.success) {
        msg.textContent = 'Profile updated!';
        msg.className = 'success-msg';
        
        // Update photo if provided
        let updatedPhoto = null;
        if (data.photo) {
          updatedPhoto = data.photo;
          document.getElementById('profilePhoto').src = data.photo + (data.photo.includes('?') ? '&' : '?') + 't=' + Date.now();
        }
        
        // Update QR code if provided
        if (data.qr_code) {
          const userQRImg = document.getElementById('userQRImg');
          if (userQRImg) {
            userQRImg.src = data.qr_code + '?t=' + new Date().getTime();
          }
        }

        // Persist updated user payload to localStorage so other pages (e.g., dashboard nav) see the new photo
        try {
          if (data.user && typeof data.user === 'object') {
            // Prefer server-returned user
            localStorage.setItem('documed_user', JSON.stringify(data.user));
            if (!updatedPhoto && data.user.photo) { updatedPhoto = data.user.photo; }
          } else if (updatedPhoto) {
            const cur = JSON.parse(localStorage.getItem('documed_user') || 'null') || {};
            cur.photo = updatedPhoto;
            localStorage.setItem('documed_user', JSON.stringify(cur));
          }
        } catch (_) {}

        // Also update any nav profile icon on this page if present
        (function(){
          const navImg = document.querySelector('#profileDropdown img, #navActions img[alt="Profile"]');
          if (navImg && updatedPhoto) {
            navImg.src = updatedPhoto + (updatedPhoto.includes('?') ? '&' : '?') + 't=' + Date.now();
          }
        })();

        // Broadcast an event for any listeners to update their avatar immediately
        try { window.dispatchEvent(new CustomEvent('profilePhotoUpdated', { detail: { photo: updatedPhoto, user: data.user || null } })); } catch(_) {}
      } else {
        msg.textContent = data.error || 'Error updating profile';
        msg.className = 'error-msg';
      }
    })
    .catch(error => {
      console.error('Profile update error:', error);
      msg.textContent = 'Error: ' + error.message;
      msg.className = 'error-msg';
    });
      }
    });

  // Change Password handling
  const passwordForm = document.getElementById('passwordForm');
  if (passwordForm) {
    passwordForm.addEventListener('submit', async function(ev) {
      ev.preventDefault();
      const msg = document.getElementById('passwordMsg');
      // Re-derive userId to avoid scope issues
      let uid = null;
      try { const u = JSON.parse(localStorage.getItem('documed_user')||'null'); uid = u && u.id; } catch(_) {}
      if (!uid) { msg.textContent = 'Not logged in.'; msg.className='error-msg'; return; }
      const oldP = (document.getElementById('oldPassword')?.value || '').trim();
      const newP = (document.getElementById('newPassword')?.value || '').trim();
      const newP2 = (document.getElementById('confirmPassword')?.value || '').trim();
      if (!oldP || !newP || !newP2) { msg.textContent='Please fill out all fields.'; msg.className='error-msg'; return; }
      if (newP.length < 6) { msg.textContent='New password must be at least 6 characters.'; msg.className='error-msg'; return; }
      if (newP !== newP2) { msg.textContent='New passwords do not match.'; msg.className='error-msg'; return; }

      msg.textContent = 'Updating password...';
      msg.className = '';
      const submitBtn = passwordForm.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        const body = new URLSearchParams({ id: String(uid), oldPassword: oldP, newPassword: newP }).toString();
        const res = await fetch('../../backend/api/auth.php?action=change_password', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body
        });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch (e) { console.error('Server response:', text); throw new Error('Invalid server response'); }
        if (data && data.success) {
          msg.textContent = data.message || 'Password updated successfully.';
          msg.className = 'success-msg';
          document.getElementById('oldPassword').value = '';
          document.getElementById('newPassword').value = '';
          document.getElementById('confirmPassword').value = '';
          // Persist any returned user updates (defensive; password is never stored)
          if (data.user) {
            try { localStorage.setItem('documed_user', JSON.stringify(data.user)); } catch(_) {}
          }
        } else {
          msg.textContent = (data && (data.error || data.message)) || 'Password change failed.';
          msg.className = 'error-msg';
        }
      } catch (err) {
        console.error('Change password error:', err);
        msg.textContent = 'Network or server error while changing password.';
        msg.className = 'error-msg';
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // QR Login: Enable/Disable handlers
  (function setupQrLoginSection(){
    const btnEnable = document.getElementById('btnEnableQr');
    const btnDisable = document.getElementById('btnDisableQr');
    const qrMsg = document.getElementById('qrMsg');
    const qrPwd = document.getElementById('qrPassword');
    const qrImg = document.getElementById('personalQrImg');
    const statusEl = document.getElementById('qrLoginStatus');
    const helpEl = document.getElementById('qrHelp');
    const user = JSON.parse(localStorage.getItem('documed_user')||'null');
    const identifier = user && (user.email || user.student_faculty_id || '');

    function setQrMsg(color, text){ if (qrMsg) { qrMsg.style.color = color; qrMsg.textContent = text; } }
    function setStatusEnabled(enabled){
      if (!statusEl) return;
      statusEl.textContent = enabled ? 'Enabled' : 'Disabled';
      statusEl.style.color = enabled ? '#059669' : '#ef4444';
      statusEl.style.display = 'inline-block';
    }

    async function enableQr(){
      const pwd = (qrPwd?.value || '').trim();
      if (!identifier) { setQrMsg('red','Missing user email or ID.'); return; }
      if (!pwd) { setQrMsg('red','Please enter your password.'); return; }
      setQrMsg('#6b7280','Enabling QR Login...');
      try {
        const body = new URLSearchParams({ identifier: identifier, password: pwd }).toString();
        const res = await fetch('../../backend/api/auth.php?action=qr_generate_token', {
          method: 'POST', headers: { 'Content-Type':'application/x-www-form-urlencoded' }, body
        });
        const text = await res.text();
        let data; try { data = JSON.parse(text); } catch (e) { console.error('QR enable response:', text); throw new Error('Invalid server response'); }
        if (data && data.success) {
          setQrMsg('green','QR Login enabled.');
          setStatusEnabled(true);
          // Show QR image if provided; otherwise hide
          if (data.qr_image) { if (qrImg) { qrImg.src = data.qr_image + '?t=' + Date.now(); qrImg.style.display = 'block'; } }
          else { if (qrImg) { qrImg.style.display = 'none'; } }
          // Persist QR text locally for approver use if needed
          if (data.qr_text) {
            try { localStorage.setItem('documed_personal_qr', data.qr_text); } catch(_) {}
          }
          // Optional: clear password field
          if (qrPwd) qrPwd.value = '';
          if (helpEl) helpEl.textContent = 'You can now scan this on the login page to sign in instantly.';
        } else {
          setQrMsg('red', (data && (data.message||data.error)) || 'Failed to enable QR Login.');
        }
      } catch(err) {
        console.error('Enable QR error:', err);
        setQrMsg('red','Network or server error while enabling QR Login.');
      }
    }

    async function disableQr(){
      const pwd = (qrPwd?.value || '').trim();
      if (!identifier) { setQrMsg('red','Missing user email or ID.'); return; }
      if (!pwd) { setQrMsg('red','Please enter your password.'); return; }
      setQrMsg('#6b7280','Disabling QR Login...');
      try {
        const body = new URLSearchParams({ identifier: identifier, password: pwd }).toString();
        const res = await fetch('../../backend/api/auth.php?action=qr_disable', {
          method: 'POST', headers: { 'Content-Type':'application/x-www-form-urlencoded' }, body
        });
        const text = await res.text();
        let data; try { data = JSON.parse(text); } catch (e) { console.error('QR disable response:', text); throw new Error('Invalid server response'); }
        if (data && data.success) {
          setQrMsg('green','QR Login disabled.');
          setStatusEnabled(false);
          if (qrImg) { qrImg.style.display = 'none'; }
          try { localStorage.removeItem('documed_personal_qr'); } catch(_) {}
          if (qrPwd) qrPwd.value = '';
        } else {
          setQrMsg('red', (data && (data.message||data.error)) || 'Failed to disable QR Login.');
        }
      } catch(err) {
        console.error('Disable QR error:', err);
        setQrMsg('red','Network or server error while disabling QR Login.');
      }
    }

    if (btnEnable) btnEnable.addEventListener('click', enableQr);
    if (btnDisable) btnDisable.addEventListener('click', disableQr);
  })();
