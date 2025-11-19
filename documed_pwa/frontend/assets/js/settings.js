// JS for settings/profile info page
// Fetch profile info and populate form
// Save changes to profile info and password

document.addEventListener('DOMContentLoaded', function() {
  let profile = null;
  try { profile = JSON.parse(localStorage.getItem('documed_doc_nurse')); } catch(e) {}
  if (profile) {
    // Fetch latest profile from backend for up-to-date photo
    fetch(`../../backend/api/manage_user.php?action=get_doc_nurse_profile&id=${encodeURIComponent(profile.id)}`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.profile) {
          document.getElementById('profilePhoto').src = data.profile.dn_photo || '../assets/images/documed_logo.png';
        } else {
          document.getElementById('profilePhoto').src = profile.dn_photo || '../assets/images/documed_logo.png';
        }
      });
    document.getElementById('editName').value = profile.name || '';
    document.getElementById('editEmail').value = profile.email || '';
    // Normalize and show role
    if (profile.role) {
      const rl = String(profile.role).toLowerCase();
      if (rl.includes('doctor')) document.getElementById('editRole').value = 'Doctor';
      else if (rl.includes('dentist')) document.getElementById('editRole').value = 'Dentist';
      else if (rl.includes('nurse')) document.getElementById('editRole').value = 'Nurse';
      else document.getElementById('editRole').value = profile.role;
    }
  }

  // Photo upload preview
  const photoInput = document.getElementById('photoInput');
  const photoImg = document.getElementById('profilePhoto');
  const photoWrap = document.getElementById('profilePhotoWrap');
  // clicking on the image opens file chooser
  function openPicker(){ photoInput && photoInput.click(); }
  if (photoImg && photoInput) {
    photoImg.addEventListener('click', openPicker);
    photoImg.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
  }
  if (photoWrap && photoInput) {
    photoWrap.addEventListener('click', openPicker);
    photoWrap.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
  }
  photoInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function(ev) {
        document.getElementById('profilePhoto').src = ev.target.result;
      };
      reader.readAsDataURL(file);
    }
  });

  // Save profile info
  document.getElementById('profileEditForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const name = document.getElementById('editName').value;
    const email = document.getElementById('editEmail').value;
    const role = document.getElementById('editRole').value;
    const photoFile = document.getElementById('photoInput').files[0];
    const formData = new FormData();
    formData.append('action', 'update_doc_nurse');
    formData.append('id', profile?.id || '');
    formData.append('name', name);
    formData.append('email', email);
    formData.append('role', role);
    if (photoFile) formData.append('photo', photoFile);
    fetch('../../backend/api/manage_user.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      const msg = document.getElementById('profileMsg');
      if (data.success) {
        msg.textContent = 'Profile updated!';
        msg.className = 'success-msg';
        alert('Profile updated successfully');
        // Update localStorage and refresh avatar immediately
        if (data.profile) {
          localStorage.setItem('documed_doc_nurse', JSON.stringify(data.profile));
          try { if (window.updateDashboardAvatar) window.updateDashboardAvatar(); } catch(_){}
        }
        // Reload after user acknowledges the alert
        window.location.reload();
      } else {
        msg.textContent = data.message || 'Update failed.';
        msg.className = 'error-msg';
        alert(msg.textContent || 'Update failed.');
      }
    });
  });

  // Change password
  document.getElementById('passwordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const oldPassword = document.getElementById('oldPassword').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    if (newPassword !== confirmPassword) {
      document.getElementById('passwordMsg').textContent = 'Passwords do not match.';
      document.getElementById('passwordMsg').className = 'error-msg';
      return;
    }
    if (!oldPassword || !newPassword) {
      const msg = document.getElementById('passwordMsg');
      msg.textContent = 'Please fill in all fields.';
      msg.className = 'error-msg';
      return;
    }
    const body = new URLSearchParams();
    body.set('action','change_password');
    body.set('id', String(profile?.id || ''));
    body.set('oldPassword', oldPassword);
    body.set('newPassword', newPassword);
    fetch('../../backend/api/manage_user.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
    .then(res => res.json())
    .then(data => {
      const msg = document.getElementById('passwordMsg');
      if (data.success) {
        msg.textContent = 'Password changed!';
        msg.className = 'success-msg';
        alert('Password changed successfully');
      } else {
        msg.textContent = data.message || data.error || 'Password change failed.';
        msg.className = 'error-msg';
        alert(msg.textContent || 'Password change failed.');
      }
    });
  });
});
