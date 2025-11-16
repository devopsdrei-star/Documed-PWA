// Render user's appointments table by fetching from new appointments API
(function(){
  document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('appointmentsBody');
    if (!tbody) return;

    const baseUrl = window.location.pathname.split('/frontend/')[0];
    const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;

    function setRowMessage(msg) {
      tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#6b7280;">${msg}</td></tr>`;
    }

    function getStoredUser() {
      try {
        return JSON.parse(localStorage.getItem('documed_user')) || null;
      } catch(e){ return null; }
    }

    async function fetchUserAppointments(email, userId, studentFacultyId) {
      const body = new URLSearchParams();
      body.append('action','my_appointments');
      if (email) body.append('email', email);
      if (userId) body.append('user_id', userId);
      if (studentFacultyId) body.append('student_faculty_id', studentFacultyId);
      const res = await fetch(apiUrl, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
      });
      return res.json();
    }

    async function fetchAllAppointments() {
      const body = new URLSearchParams();
      body.append('action','list');
      const res = await fetch(apiUrl, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
      });
      return res.json();
    }

    async function loadAppointments() {
      setRowMessage('Loading...');
      const user = getStoredUser();
      if (!user || (!user.email && !user.id)) {
        setRowMessage('Please log in to view your appointments.');
        return;
      }
      try {
        const email = user.email || '';
        const userId = user.id || '';
        const sid = user.student_faculty_id || user.student_facultyId || '';
        console.log('Loading appointments with identifiers:', { email, userId, sid });
        let data = await fetchUserAppointments(email, userId, sid);
        if (!data.success) {
          console.warn('my_appointments failed; falling back to list', data.message);
          data = await fetchAllAppointments();
          if (data.success) {
            // manual filter fallback
            data.appointments = (data.appointments||[]).filter(a => {
              const ae = (a.email||'').toLowerCase();
              return ae === (email||'').toLowerCase();
            });
          }
        }
        if (!data.success) {
          setRowMessage(data.message || 'Failed to load appointments.');
          return;
        }
        console.log('Appointments API response:', data);
        const apps = Array.isArray(data.appointments) ? data.appointments : [];
        if (apps.length === 0) {
          setRowMessage('You have no appointments yet. Book one!');
          return;
        }
        tbody.innerHTML='';
        apps.forEach(app => {
          const tr = document.createElement('tr');
          const date = app.date || '';
          const time = app.time ? (app.time.length>5? app.time.slice(0,5): app.time) : '';
          const service = app.purpose || app.service || '';
          const status = app.status || '';
          tr.innerHTML = `
            <td>${date}</td>
            <td>${time}</td>
            <td>${service}</td>
            <td>${status ? status.charAt(0).toUpperCase()+status.slice(1) : ''}</td>`;
          tbody.appendChild(tr);
        });
      } catch (e) {
        console.error('Error loading appointments', e);
        setRowMessage('Network error while loading appointments.');
      }
    }

    loadAppointments();
  });
})();
