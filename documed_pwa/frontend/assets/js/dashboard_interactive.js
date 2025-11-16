// Dashboard interactivity: profile/settings dropdown, calendar, recent appointments, analytics

document.addEventListener('DOMContentLoaded', function() {
  // Profile dropdown
  const profileBtn = document.getElementById('dashboardProfileBtn');
  const profileMenu = document.getElementById('dashboardProfileMenu');
  if (profileBtn && profileMenu) {
    profileBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      profileMenu.style.display = profileMenu.style.display === 'block' ? 'none' : 'block';
    });
    document.addEventListener('click', function() {
      profileMenu.style.display = 'none';
    });
  }

  // Settings button (open settings section)
  const settingsBtn = document.getElementById('dashboardSettingsBtn');
  if (settingsBtn) {
    settingsBtn.addEventListener('click', function() {
      document.getElementById('settingsSection').style.display = 'block';
    });
  }

  // Clear admin session info on logout links
  const logout = document.getElementById('logoutMenu');
  if (logout) {
    logout.addEventListener('click', function() {
      try {
        localStorage.removeItem('admin_id');
        localStorage.removeItem('admin_name');
      } catch(_) {}
    });
  }

  // Calendar (simple month view, click date shows checkup count)
  const calendarContainer = document.getElementById('calendarContainer');
  const calendarStats = document.getElementById('calendarStats');
  if (calendarContainer) {
    // Generate calendar for current month
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    let html = '<table style="width:100%;text-align:center;font-size:1rem;">';
    html += '<tr>';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => html += `<th style="padding:4px;color:#2563eb;">${d}</th>`);
    html += '</tr><tr>';
    let firstDay = new Date(year, month, 1).getDay();
    for (let i = 0; i < firstDay; i++) html += '<td></td>';
    for (let d = 1; d <= daysInMonth; d++) {
      let dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      html += `<td style="padding:6px;cursor:pointer;border-radius:6px;" class="calendar-date" data-date="${dateStr}">${d}</td>`;
      if ((firstDay + d) % 7 === 0) html += '</tr><tr>';
    }
    html += '</tr></table>';
    calendarContainer.innerHTML = html;
    // Click date
    calendarContainer.querySelectorAll('.calendar-date').forEach(cell => {
      cell.addEventListener('click', function() {
        const date = this.getAttribute('data-date');
        // Simulate fetch: show random count
        const count = Math.floor(Math.random()*10)+1;
        calendarStats.textContent = `Checkups on ${date}: ${count}`;
      });
    });
  }

  // Recent Appointments (simulate data)
  const recentTable = document.getElementById('recentAppointmentsTable');
  if (recentTable) {
    const tbody = recentTable.querySelector('tbody');
    function renderAppointments(filter) {
      tbody.innerHTML = '';
      // Simulate 8 appointments
      for (let i = 0; i < 8; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>Juan Dela Cruz</td><td>Dental</td><td>2025-08-${String(20+i).padStart(2,'0')}</td><td>Completed</td>`;
        tbody.appendChild(tr);
      }
    }
    renderAppointments('today');
    document.getElementById('recentSorter').addEventListener('change', e => {
      renderAppointments(e.target.value);
    });
  }

  // Patient Data Analytics (line graph, simulated)
  if (window.Chart) {
    const ctx = document.getElementById('patientLineGraph').getContext('2d');
    let chart;
    function renderGraph(period) {
      // Build URL with period param; default to 'all' which maps to backend 'week' for now
      const p = (period||'all').toLowerCase();
      const backendPeriod = (p==='all') ? 'week' : p; // map 'all' -> 'week' if backend doesn't support 'all'
      fetch(`../../backend/api/checkup_analytics.php?period=${encodeURIComponent(backendPeriod)}`)
        .then(res => res.json())
        .then(data => {
          const labels = data.labels;
          const counts = data.counts;
          if (chart) chart.destroy();
          chart = new Chart(ctx, {
            type: 'line',
            data: {
              labels,
              datasets: [{
                label: 'Checkups',
                data: counts,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.08)',
                fill: true,
                tension: 0.3
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { legend: { display: false } },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: { stepSize: 1 },
                  min: 0
                },
                x: {
                  ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 12
                  }
                }
              }
            }
          });
        })
        .catch(() => {
          // fallback: show empty chart
          if (chart) chart.destroy();
          chart = new Chart(ctx, {
            type: 'line',
            data: {
              labels: [],
              datasets: [{ label: 'Checkups', data: [] }]
            },
            options: { responsive: true, maintainAspectRatio: false }
          });
        });
    }
    // Default to 'all'
    renderGraph('all');
    document.getElementById('analyticsSorter').addEventListener('change', e => {
      renderGraph(e.target.value);
    });
  }
});
