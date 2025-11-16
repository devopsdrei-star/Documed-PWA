// Fetch and display real dashboard data

document.addEventListener('DOMContentLoaded', function() {
  // Stat cards (only on dashboard)
  if (document.getElementById('statPatients')) {
    fetch('../../backend/api/patient.php?action=count')
      .then(res => res.json())
      .then(data => {
        document.getElementById('statPatients').textContent = data.count || '0';
      })
      .catch(() => {
        document.getElementById('statPatients').textContent = 'Err';
      });
  }
  if (document.getElementById('statAppointments')) {
    fetch('../../backend/api/appointment.php?action=count')
      .then(res => res.json())
      .then(data => {
        document.getElementById('statAppointments').textContent = data.count || '0';
      })
      .catch(() => {
        document.getElementById('statAppointments').textContent = 'Err';
      });
  }
  if (document.getElementById('statReports')) {
    fetch('../../backend/api/report.php?action=count')
      .then(res => res.json())
      .then(data => {
        document.getElementById('statReports').textContent = data.count || '0';
      })
      .catch(() => {
        document.getElementById('statReports').textContent = 'Err';
      });
  }
  if (document.getElementById('statCheckups')) {
    fetch('../../backend/api/checkup_count_today.php')
      .then(res => res.json())
      .then(data => {
        document.getElementById('statCheckups').textContent = data.count || '0';
      })
      .catch(() => {
        document.getElementById('statCheckups').textContent = 'Err';
      });
  }

  // Patient Data Analytics (mirror doc_nurse Activity chart, but single Patients dataset)
  let allCheckupsCache = null;
  async function loadAllCheckups() {
    if (allCheckupsCache) return allCheckupsCache;
    try {
      const res = await fetch('../../backend/api/checkup.php?action=list');
      const data = await res.json();
      allCheckupsCache = (data && Array.isArray(data.checkups)) ? data.checkups : [];
    } catch (_) {
      allCheckupsCache = [];
    }
    return allCheckupsCache;
  }

  async function renderAnalytics(filter) {
    const all = await loadAllCheckups();
    // Update male/female quick stats if elements exist (using gender_effective fallback)
    try {
      const maleEl = document.getElementById('statMaleCheckups');
      const femaleEl = document.getElementById('statFemaleCheckups');
      if (maleEl || femaleEl) {
        let m=0,f=0;
        for (const c of all) {
          const g = (c.gender_effective || c.gender || '').toLowerCase();
          if (g === 'male') m++; else if (g === 'female') f++;
        }
        if (maleEl) maleEl.textContent = m;
        if (femaleEl) femaleEl.textContent = f;
      }
    } catch(_) { /* ignore */ }
    const today = new Date();
    let filtered = all;
    const f = (filter === 'day') ? 'today' : filter; // map day -> today to match doc_nurse logic
    if (f === 'today') {
      const todayStr = today.toISOString().slice(0,10);
      filtered = all.filter(c => (c.created_at||'').slice(0,10) === todayStr);
    } else if (f === 'week') {
      const weekAgo = new Date(today.getTime() - 6*24*60*60*1000);
      filtered = all.filter(c => {
        const d = new Date(c.created_at);
        return !isNaN(d) && d >= weekAgo && d <= today;
      });
    } else if (f === 'month') {
      const monthStr = today.toISOString().slice(0,7);
      filtered = all.filter(c => (c.created_at||'').slice(0,7) === monthStr);
    } else if (f === 'year') {
      const yearStr = today.toISOString().slice(0,4);
      filtered = all.filter(c => (c.created_at||'').slice(0,4) === yearStr);
    }

    // Group by date (YYYY-MM-DD) and gender
    const byDate = {};
    for (const c of filtered) {
      const d = (c.created_at || '').slice(0,10);
      if (!d) continue;
      if (!byDate[d]) byDate[d] = { male: 0, female: 0, total: 0 };
      const g = (c.gender_effective || c.gender || '').toLowerCase();
      if (g === 'male') byDate[d].male++;
      else if (g === 'female') byDate[d].female++;
      byDate[d].total++;
    }
    const labels = Object.keys(byDate).sort();
    const totalCounts = labels.map(d => byDate[d].total);
    const maleCounts = labels.map(d => byDate[d].male);
    const femaleCounts = labels.map(d => byDate[d].female);

    // If no data, still show a single point for today with 0
    if (labels.length === 0) {
      const todayStr = today.toISOString().slice(0,10);
      labels.push(todayStr);
      counts.push(0);
    }

    const ctxEl = document.getElementById('patientLineGraph');
    if (!ctxEl || !window.Chart) return;
    const ctx = ctxEl.getContext('2d');
    if (window.analyticsChart) window.analyticsChart.destroy();

    // Dynamic Y max similar to doc_nurse but adaptive
  const maxVal = totalCounts.length ? Math.max.apply(null, totalCounts) : 0;
    const yTicks = { stepSize: (maxVal <= 10 ? 1 : undefined), precision: 0 };
    const yMax = (maxVal <= 5) ? Math.max(3, maxVal + 1) : undefined;

    window.analyticsChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Total', data: totalCounts, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)', fill: true },
          { label: 'Male', data: maleCounts, borderColor: '#1d4ed8', backgroundColor: 'rgba(29,78,216,0.12)', fill: false },
          { label: 'Female', data: femaleCounts, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.12)', fill: false }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true } },
        scales: {
          y: {
            min: 0,
            max: yMax,
            ticks: yTicks,
            grid: { color: 'rgba(37,99,235,0.08)' }
          },
          x: {
            ticks: { autoSkip: true, maxRotation: 0, autoSkipPadding: 8 },
            grid: { display: false }
          }
        }
      }
    });
  }

  renderAnalytics('day');
  document.getElementById('analyticsSorter').addEventListener('change', e => {
    renderAnalytics(e.target.value);
  });

  // Recent Appointments
  function renderRecentAppointments(filter) {
    const tbody = document.getElementById('recentAppointmentsTable').querySelector('tbody');
    tbody.innerHTML = '';
    // Fetch real appointments and filter client-side by period
    fetch(`../../backend/api/appointments_new.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=list'
    })
      .then(res => res.json())
      .then(data => {
        if (!data || !data.success) {
          const tr = document.createElement('tr');
          tr.innerHTML = `<td colspan="4" style="padding:10px 6px;color:#6b7280;">Failed to load appointments.</td>`;
          tbody.appendChild(tr);
          return;
        }
        const appts = Array.isArray(data.appointments) ? data.appointments : [];
        // Compute date range based on filter
        const now = new Date();
        const start = new Date(now);
        const fval = (filter || 'all');
        if (fval === 'today' || fval === 'day') {
          start.setHours(0,0,0,0);
        } else if (fval === 'week') {
          start.setDate(start.getDate() - 7);
        } else if (fval === 'month') {
          start.setDate(start.getDate() - 30);
        }
        function toDate(dStr){
          // Handle YYYY-MM-DD
          if (!dStr) return null;
          const d = new Date(dStr);
          if (isNaN(d.getTime())) return null;
          return d;
        }
        function toTimeNum(tStr){
          if (!tStr) return 0;
          const t = tStr.slice(0,5);
          const [h,m] = t.split(':').map(n=>parseInt(n,10)||0);
          return h*60+m;
        }
        // Filter by date window (skip filtering if 'all')
        let filtered = appts;
        if (fval !== 'all') {
          filtered = appts.filter(a => {
            const d = toDate(a.date);
            if (!d) return false;
            return d >= start && d <= now;
          });
        }
        // Sort by date desc, then time desc
        filtered.sort((a,b) => {
          const da = toDate(a.date) || new Date(0);
          const db = toDate(b.date) || new Date(0);
          if (db - da !== 0) return db - da;
          return toTimeNum(b.time) - toTimeNum(a.time);
        });
  // Limit to top 5 rows
  filtered = filtered.slice(0, 5);
        if (filtered.length === 0) {
          const tr = document.createElement('tr');
          tr.innerHTML = `<td colspan="4" style="padding:10px 6px;color:#6b7280;">No recent appointments.</td>`;
          tbody.appendChild(tr);
          return;
        }
        filtered.forEach(app => {
          const name = app.name || app.patient_name || app.email || app.patient_id || '';
          const service = app.purpose || app.service || '';
          const date = app.date || '';
          const status = app.status || '';
          const tr = document.createElement('tr');
          tr.innerHTML = `<td style="padding:10px 6px;">${name}</td><td style="padding:10px 6px;">${service}</td><td style="padding:10px 6px;">${date}</td><td style="padding:10px 6px;">${status}</td>`;
          tbody.appendChild(tr);
        });
      })
      .catch(() => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td colspan="4" style="padding:10px 6px;color:#6b7280;">Network error while loading appointments.</td>`;
        tbody.appendChild(tr);
      });
  }
  renderRecentAppointments('all');
  document.getElementById('recentSorter').addEventListener('change', e => {
    renderRecentAppointments(e.target.value);
  });
});
