// User Dashboard Logic for DocuMed
// Shows QR code, user info, appointments, and medical records

document.addEventListener('DOMContentLoaded', function() {
  // Initialize QR Modal
  const qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
  
  // Handle QR button click
  document.addEventListener('click', function(e) {
    if (e.target.closest('#viewQRBtn')) {
      e.preventDefault();
      loadAndShowQRCode();
    }
  });

  // Function to load and show QR code
  function loadAndShowQRCode() {
    // Get user info from both localStorage and sessionStorage
    let userObj = null;
    try {
      userObj = JSON.parse(localStorage.getItem('documed_user'));
      console.log("DEBUG: Loaded user from localStorage:", userObj);
    } catch (e) {
      console.error("DEBUG: Failed to parse user from localStorage", e);
    }

  let userId = userObj && userObj.id ? userObj.id : localStorage.getItem('id');
  if (!userId) userId = userObj && userObj.student_faculty_id ? userObj.student_faculty_id : localStorage.getItem('student_faculty_id');

  if (!userId) {
    console.warn("DEBUG: No user ID found, redirecting to login...");
    window.location.href = 'user_login.html';
    return;
  }

  // --- QR Code & User Info ---
  fetch('../../backend/api/auth.php?action=get_user_full', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${encodeURIComponent(userId)}`
  })
  .then(res => res.text())
  .then(text => {
    try {
      return JSON.parse(text);
    } catch (e) {
      console.error('Server response:', text);
      throw new Error('Invalid JSON response from server');
    }
  })
  .then(data => {
    if (data && data.success && data.user) {
      const user = data.user;
      console.log("DEBUG: User fetched from backend:", user);

      // Update dashboard info
      if (document.getElementById('dashName')) {
        document.getElementById('dashName').textContent = `${user.first_name} ${user.last_name}`;
      }
      if (document.getElementById('dashEmail')) {
        document.getElementById('dashEmail').textContent = user.email || '';
      }
      if (document.getElementById('dashID')) {
        document.getElementById('dashID').textContent = user.student_faculty_id || '';
      }

      // Update QR code with cache-busting
      const qrImg = document.getElementById('userQRImg');
      const qrLabel = document.getElementById('qrLabel');
      
      if (qrImg) {
        if (user.qr_code) {
          // Add timestamp to prevent caching
          qrImg.src = user.qr_code + '?t=' + new Date().getTime();
          if (qrLabel) {
            qrLabel.textContent = `${user.first_name} ${user.last_name}'s QR Code`;
          }
        } else {
          qrImg.src = '../assets/images/documed_logo.png'; // fallback image
          if (qrLabel) {
            qrLabel.textContent = 'QR Code not available';
          }
        }
      }
    } else {
      const qrLabel = document.getElementById('qrLabel');
      if (qrLabel) {
        qrLabel.textContent = 'User info not found.';
      }
    }
  })
  .catch(err => {
    console.error("DEBUG: Error fetching user info:", err);
    document.getElementById('qrMsg').textContent = 'Error loading user info.';
  });

  // --- Appointments ---
  (function(){
    const apptList = document.getElementById('apptList');
    const apptMsg = document.getElementById('apptMsg');
    // If the page doesn't have appointment widgets, skip gracefully
    if (!apptList && !apptMsg) return;
    fetch(`../../backend/api/appointment.php?action=list&user_id=${encodeURIComponent(userId)}`)
      .then(res => res.text())
      .then(text => {
        let data; try { data = JSON.parse(text); } catch (e) { console.error('Appointments raw response:', text); throw e; }
        if (apptList) apptList.innerHTML = '';
        if (data.appointments && data.appointments.length) {
          const now = new Date();
          data.appointments.forEach(app => {
            if (!apptList) return;
            const appDate = new Date(`${app.date||''}T${app.time||'00:00'}`);
            const li = document.createElement('li');
            const purpose = app.purpose || app.service || '';
            li.textContent = `${app.date||''} ${app.time||''}${purpose ? ' (' + purpose + ')' : ''}`.trim();
            li.className = (appDate.toString() !== 'Invalid Date' && appDate > now) ? 'upcoming' : 'past';
            apptList.appendChild(li);
          });
          if (apptMsg) apptMsg.style.display = 'none';
        } else {
          if (apptMsg) apptMsg.textContent = 'No appointments found.';
        }
      })
      .catch(err => {
        console.error('DEBUG: Error fetching appointments:', err);
        if (apptMsg) apptMsg.textContent = 'Error loading appointments.';
      });
  })();

  // --- Medical Records ---
  (function(){
    const medList = document.getElementById('medList');
    const medMsg = document.getElementById('medMsg');
    // If the page doesn't have medical record widgets, skip gracefully
    if (!medList && !medMsg) return;
    fetch(`../../backend/api/patient.php?action=list&user_id=${encodeURIComponent(userId)}`)
      .then(res => res.text())
      .then(text => {
        let data; try { data = JSON.parse(text); } catch (e) { console.error('Medical records raw response:', text); throw e; }
        if (medList) medList.innerHTML = '';
        if (data.patients && data.patients.length) {
          data.patients.forEach(rec => {
            if (!medList) return;
            const li = document.createElement('li');
            li.textContent = `${rec.date_of_examination || ''} - ${rec.chief_complaint || ''}`.trim();
            medList.appendChild(li);
          });
          if (medMsg) medMsg.style.display = 'none';
        } else {
          if (medMsg) medMsg.textContent = 'No medical records found.';
        }
      })
      .catch(err => {
        console.error('DEBUG: Error fetching records:', err);
        if (medMsg) medMsg.textContent = 'Error loading records.';
      });
  })();

    // Show the QR modal
    qrModal.show();
  }
});
