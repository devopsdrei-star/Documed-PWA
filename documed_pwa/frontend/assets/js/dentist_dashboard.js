// Dentist dashboard data wiring: KPIs, upcoming list, status chart
(function(){
  const kToday = document.getElementById('kpiToday');
  const kScheduled = document.getElementById('kpiScheduled');
  const kAccepted = document.getElementById('kpiAccepted');
  const kCompleted = document.getElementById('kpiCompleted');
  const body = document.getElementById('upcomingBody');
  const chartRange = document.getElementById('chartRange');
  const chartFrom = document.getElementById('chartFrom');
  const chartTo = document.getElementById('chartTo');
  const chartAudience = document.getElementById('chartAudience');
  const chartDepartment = document.getElementById('chartDepartment');
  const chartYear = document.getElementById('chartYear');
  const chartCourse = document.getElementById('chartCourse');

  function parseDateLocal(s){
    if (!s) return null;
    const t = String(s).trim();
    // yyyy-mm-dd or yyyy/mm/dd
    let m = t.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
    if (m) { return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])); }
    // mm/dd/yyyy
    m = t.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/);
    if (m) { return new Date(Number(m[3]), Number(m[1]) - 1, Number(m[2])); }
    // fallback
    const d = new Date(t);
    return isNaN(d) ? null : d;
  }

  function isDental(appt){
    const p = String(appt.purpose||'').toLowerCase();
    // Broader matching for dental-related purposes
    const keywords = [
      'dental','tooth','teeth','oral','mouth','clean','cleaning','prophy','consult','consultation',
      'extraction','brace','ortho','cavity','caries','filling','root canal','denture','gum','periodont',
      'odont', 'enamel', 'crown', 'bridge'
    ];
    return keywords.some(k => p.includes(k));
  }

  function toTime(t){ return (t||'').slice(0,5); }

  function sameDay(a,b){ return a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate(); }
  function inSelectedRange(dateStr){
    try{
      const d = parseDateLocal(dateStr);
      if (!d) return false;
      const now = new Date();
      const mode = chartRange ? chartRange.value : 'all';
      if (mode === 'all') return true;
      if (mode === 'today') return sameDay(d, now);
      if (mode === 'week'){
        const start = new Date(now); const day = now.getDay(); // 0 Sun..6 Sat
        const diff = (day+6)%7; // Monday as start
        start.setDate(now.getDate()-diff); start.setHours(0,0,0,0);
        const end = new Date(start); end.setDate(start.getDate()+6); end.setHours(23,59,59,999);
        return d >= start && d <= end;
      }
      if (mode === 'month'){
        return d.getFullYear()===now.getFullYear() && d.getMonth()===now.getMonth();
      }
      if (mode === 'year'){
        return d.getFullYear()===now.getFullYear();
      }
      return true;
    }catch{ return true; }
  }

  function audienceMatches(a){
    if (!chartAudience) return true;
    const aud = chartAudience.value || 'All';
    if (aud === 'All') return true;
    const role = String(a.role||'').toLowerCase();
    const dept = String(a.department||'');
    const yc = String(a.year_course||'');
    const looksStudent = role.includes('student') || yc.length > 0;
    const looksFaculty = role.includes('faculty') || role.includes('teacher');
    const looksStaff = role.includes('staff');
    if (aud === 'Student') return looksStudent;
    if (aud === 'Faculty') return looksFaculty;
    if (aud === 'Staff') return looksStaff;
    return true;
  }

  function departmentMatches(a){
    if (!chartDepartment) return true;
    const val = chartDepartment.value || '';
    if (!val) return true;
    return String(a.department||'') === val;
  }

  function studentFiltersMatch(a){
    if (!chartAudience || chartAudience.value !== 'Student') return true;
    // Year and Course optional
    const yearVal = chartYear ? chartYear.value : '';
    const courseVal = chartCourse ? chartCourse.value.trim().toLowerCase() : '';
    const yc = String(a.year_course||'');
    let yearOk = true, courseOk = true;
    if (yearVal) yearOk = yc.startsWith(yearVal+" ") || yc.includes(`Year ${yearVal}`) || yc.startsWith(yearVal);
    if (courseVal) courseOk = yc.toLowerCase().includes(courseVal);
    return yearOk && courseOk;
  }

  function demographicsMatch(a){
    return audienceMatches(a) && departmentMatches(a) && studentFiltersMatch(a);
  }

  function inRange(dateStr){
    try{
      const d = new Date(dateStr);
      if (!chartRange) return true;
      const mode = chartRange.value;
      if (mode === 'all') return true;
      if (mode === '7' || mode === '30' || mode === '90'){
        const days = parseInt(mode,10);
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - days);
        return d >= start && d <= now;
      }
      // Custom date range if visible/used
      if (chartFrom && chartTo && chartFrom.value && chartTo.value){
        const from = new Date(chartFrom.value);
        const to = new Date(chartTo.value);
        return d >= from && d <= to;
      }
      return true;
    }catch{ return true; }
  }

  async function loadKPIs(){
    try{
      const todayRes = await fetch('../../backend/api/appointments_new.php?action=today');
      const todayJson = await todayRes.json();
      const todayList = Array.isArray(todayJson.appointments)? todayJson.appointments : [];
      const todayCount = todayList.filter(isDental).length;
      if (kToday) kToday.textContent = todayCount;
    }catch{}

    try{
      const res = await fetch('../../backend/api/appointments_new.php?action=list');
      const data = await res.json();
      const rows = Array.isArray(data.appointments)? data.appointments : [];
      const normStatus = (s)=> String(s||'').toLowerCase();
      const dentalAll = rows.filter(r => isDental(r) && demographicsMatch(r));
      // Apply selected time filter to KPIs and chart
      const dentalRange = dentalAll.filter(a => inSelectedRange(a.date));
      const scheduledAll = dentalRange.filter(a => normStatus(a.status)==='scheduled').length;
      const acceptedAll = dentalRange.filter(a => normStatus(a.status)==='accepted').length;
      const completedAll = dentalRange.filter(a => normStatus(a.status)==='completed').length;
      if (kScheduled) kScheduled.textContent = scheduledAll;
      if (kAccepted) kAccepted.textContent = acceptedAll;
      if (kCompleted) kCompleted.textContent = completedAll;
      renderUpcoming(dentalAll);
      renderChart({
        scheduled: dentalRange.filter(a=>normStatus(a.status)==='scheduled').length,
        accepted: dentalRange.filter(a=>normStatus(a.status)==='accepted').length,
        completed: dentalRange.filter(a=>normStatus(a.status)==='completed').length,
        declined: dentalRange.filter(a=>normStatus(a.status)==='declined').length,
        cancelled: dentalRange.filter(a=>normStatus(a.status)==='cancelled').length
      });
    }catch{
      if (body) body.innerHTML = '<tr><td colspan="5">Failed to load appointments.</td></tr>';
    }
  }

  function renderUpcoming(list){
    if (!body) return;
    const today = new Date(); today.setHours(0,0,0,0);
    const future = list.filter(a => {
        const d = parseDateLocal(a.date);
        return d && d >= today;
      })
      .sort((a,b)=> (a.date+b.time).localeCompare(b.date+b.time))
      .slice(0,8);
    if (future.length===0){ body.innerHTML = '<tr><td colspan="5">No upcoming dental appointments.</td></tr>'; return; }
    body.innerHTML = future.map(r => {
      const st = r.status || 'scheduled';
      const badge = st==='accepted'?'primary':st==='completed'?'success':st==='declined'?'danger':st==='cancelled'?'secondary':'warning';
      return `<tr>
        <td>${r.date||''}</td>
        <td>${toTime(r.time)}</td>
        <td>${r.name||''}</td>
        <td>${r.purpose||''}</td>
        <td><span class="badge bg-${badge}">${st}</span></td>
      </tr>`;
    }).join('');
  }

  let chart;
  function renderChart(counts){
    const ctx = document.getElementById('statusChart');
    if (!ctx || !window.Chart) return;
    const data = {
      labels: ['Scheduled','Accepted','Completed','Declined','Cancelled'],
      datasets: [{
        label: 'Appointments',
        data: [counts.scheduled, counts.accepted, counts.completed, counts.declined, counts.cancelled],
        backgroundColor: ['#fbbf24','#60a5fa','#34d399','#f87171','#94a3b8'],
        borderColor: '#fff',
        borderWidth: 2
      }]
    };
    if (chart) { chart.destroy(); }
    chart = new Chart(ctx, {
      type: 'pie',
      data,
      options: {
        responsive:true,
        maintainAspectRatio:true,
        aspectRatio:1,
        plugins:{
          legend:{ position:'bottom' },
          datalabels: {
            color: '#111827',
            formatter: (val, ctx)=>{
              const arr = ctx.chart.data.datasets[0].data;
              const total = arr.reduce((a,b)=>a+Number(b||0),0) || 1;
              const pct = Math.round((Number(val||0)/total)*100);
              return pct >= 8 ? pct + '%' : '';
            },
            font: { weight: '600' }
          }
        }
      },
      plugins: [window.ChartDataLabels ? ChartDataLabels : {}]
    });
  }

  // Personalize title/avatar from stored dentist session
  try {
    let dn = null;
    try { dn = JSON.parse(localStorage.getItem('documed_docnurse')||'null'); } catch(_) { dn = null; }
    if (!dn) { try { dn = JSON.parse(localStorage.getItem('documed_doc_nurse')||'null'); } catch(_) { dn = null; } }
    if (dn) {
      const title = document.getElementById('welcomeTitle');
      const nameEl = document.getElementById('dentistName');
      const av = document.getElementById('dentistAvatar');
      const nm = (dn.name || [dn.first_name, dn.last_name].filter(Boolean).join(' ') || dn.full_name || dn.username || dn.email || '').toString().trim();
      if (nameEl && nm) {
        nameEl.textContent = nm; // keep "Welcome, " prefix from HTML and fill the span
      } else if (title && nm) {
        title.textContent = `Welcome, ${nm}`; // fallback if span is missing
      }
      if (av && dn.photo) av.src = dn.photo;
    }
  } catch {}

  if (chartRange) {
    chartRange.addEventListener('change', () => { loadKPIs(); });
  }
  if (chartFrom) chartFrom.addEventListener('change', loadKPIs);
  if (chartTo) chartTo.addEventListener('change', loadKPIs);
  if (chartAudience) chartAudience.addEventListener('change', () => {
    // Toggle student-only filters
    const isStudent = chartAudience.value === 'Student';
    if (chartYear) chartYear.style.display = isStudent ? 'block' : 'none';
    if (chartCourse) chartCourse.style.display = isStudent ? 'block' : 'none';
    loadKPIs();
  });
  if (chartDepartment) chartDepartment.addEventListener('change', loadKPIs);
  if (chartYear) chartYear.addEventListener('change', loadKPIs);
  if (chartCourse) chartCourse.addEventListener('input', () => { /* debounce optional */ loadKPIs(); });
  loadKPIs();
})();
