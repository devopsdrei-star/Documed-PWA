// Admin Patients page: read-only clinical; view, delete/archive, export
(function(){
  const table = document.getElementById('patientsTable');
  const tbody = table ? table.querySelector('tbody') : null;
  const msg = document.getElementById('patientsMsg');
  const search = document.getElementById('searchPatient');
  const roleFilter = document.getElementById('roleFilter');
  const archivedFilter = document.getElementById('archivedFilter');
  if (!tbody) return;

  let all = [];
  let filtered = [];
  let currentPage = 1;
  const pageSize = 6; // fixed size per new design (updated)
  let checkedByMap = new Map(); // sid -> latest non-empty doctor_nurse
  // Pick newer of two records (by created_at, fallback id)
  function pickNewer(a, b) {
    const da = a && a.created_at ? new Date(a.created_at) : null;
    const db = b && b.created_at ? new Date(b.created_at) : null;
    if (da && db && !isNaN(da) && !isNaN(db)) return da > db ? a : b;
    const ia = Number(a && a.id);
    const ib = Number(b && b.id);
    if (isFinite(ia) && isFinite(ib)) return ia > ib ? a : b;
    return a || b;
  }
  function dedupeBySid(list) {
    const map = new Map();
    for (const p of (list || [])) {
      const sid = (p.student_faculty_id || '').toString().trim();
      if (!sid) continue;
      const prev = map.get(sid);
      map.set(sid, prev ? pickNewer(prev, p) : p);
    }
    return Array.from(map.values());
  }
  function setMsg(t){ if (msg) msg.textContent = t || ''; }

  // Format "Next Check-Up" similarly to doc_nurse list: Completed/Today/relative/Overdue/Lapsed
  function formatNextFollowUp(dateStr) {
    if (!dateStr) return '<span style="color:#6b7280;">Completed</span>';
    const parts = String(dateStr).split('-');
    let due = null;
    if (parts.length >= 3) {
      const y = parseInt(parts[0], 10), m = parseInt(parts[1], 10), d = parseInt(parts[2], 10);
      if (!isNaN(y) && !isNaN(m) && !isNaN(d)) due = new Date(y, m - 1, d);
    }
    if (!due || isNaN(due)) return '<span style="color:#6b7280;">Completed</span>';
    const today = new Date();
    const t0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const d0 = new Date(due.getFullYear(), due.getMonth(), due.getDate());
    const diffDays = Math.round((d0 - t0) / 86400000);
    const labelDate = d0.toLocaleDateString();
    if (diffDays === 0) {
      return '<span title="Due ' + labelDate + '" style="color:#2563eb;font-weight:600;">Today</span>';
    }
    if (diffDays > 0) {
      const hint = 'in ' + diffDays + ' day' + (diffDays === 1 ? '' : 's');
      const color = diffDays <= 7 ? '#d97706' : '#374151';
      return '<span title="Due ' + labelDate + '" style="color:' + color + ';">' + labelDate + ' (' + hint + ')</span>';
    }
    const over = Math.abs(diffDays);
    if (over <= 5) {
      return '<span title="Past due ' + labelDate + '" style="color:#dc2626;font-weight:600;">Overdue (' + over + ' day' + (over === 1 ? '' : 's') + ')</span>';
    }
    return '<span title="Exceeded 5-day grace (due ' + labelDate + ')" style="color:#991b1b;font-weight:700;">Lapsed (' + over + ' days)</span>';
  }

  function render(rows){
    tbody.innerHTML = '';
    if (!rows || rows.length === 0){ setMsg('No patient records found.'); return; }
    rows.forEach(p => {
  const createdAt = p.created_at ? new Date(p.created_at) : null;
  const nextFollowUpLabel = formatNextFollowUp(p.follow_up_date);
      const isArchived = (Number(p.archived) === 1);
  const sid = (p.student_faculty_id || '').toString().trim();
  const checkedBy = p.doctor_nurse_effective || p.doctor_nurse || (sid && checkedByMap.get(sid)) || '-';
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${p.student_faculty_id || ''}</td>
        <td>${p.name || [p.last_name, p.first_name, p.middle_initial].filter(Boolean).join(' ')}</td>
        <td>${p.role || p.role_effective || ''}</td>
        <td>${p.assessment || ''}</td>
        <td>${createdAt ? createdAt.toLocaleDateString() : ''}</td>
        <td>${checkedBy}</td>
        <td>${nextFollowUpLabel}</td>
        <td style="white-space:nowrap;">
          <span class="actions-wrap">
            <button class="btnView" data-id="${p.id}" style="background:#2563eb;color:#fff;border:none;cursor:pointer;padding:6px 12px;border-radius:6px;">View</button>
            ${isArchived
              ? `<button class="btnUnarchive" data-id="${p.id}" style="background:#10b981;color:#fff;border:none;cursor:pointer;padding:6px 12px;border-radius:6px;">Unarchive</button>`
              : `<button class="btnArchive" data-id="${p.id}" style="background:#d97706;color:#fff;border:none;cursor:pointer;padding:6px 12px;border-radius:6px;">Archive</button>`}
          </span>
        </td>`;
      tbody.appendChild(tr);
    });
    setMsg('');
  }

  function renderPage(){
    const total = filtered.length;
    const totalPages = Math.ceil(total / pageSize) || 1;
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;
    const start = (currentPage - 1) * pageSize;
    const pageRows = filtered.slice(start, start + pageSize);
    render(pageRows);
        const pagWrap = document.getElementById('patientsPagination');
    const prevBtn = document.getElementById('patientsPrev');
    const nextBtn = document.getElementById('patientsNext');
    const pageInfo = document.getElementById('patientsPageInfo');
      if (pagWrap){
          if (total <= pageSize){ pagWrap.style.display='none'; }
          else {
              pagWrap.style.display='flex';
              if (pageInfo) pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
                          if (prevBtn){
                              const disabled = currentPage <= 1;
                              prevBtn.disabled = disabled;
                              prevBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                              prevBtn.style.background = disabled ? '#9ca3af' : '#2563eb';
                              prevBtn.style.cursor = disabled ? 'not-allowed' : 'pointer';
                              prevBtn.style.opacity = disabled ? '0.6' : '1';
                          }
                          if (nextBtn){
                              const disabled = currentPage >= totalPages;
                              nextBtn.disabled = disabled;
                              nextBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                              nextBtn.style.background = disabled ? '#9ca3af' : '#2563eb';
                              nextBtn.style.cursor = disabled ? 'not-allowed' : 'pointer';
                              nextBtn.style.opacity = disabled ? '0.6' : '1';
                          }
          }
        }
    }

  function load(){
    setMsg('');
    const archived = archivedFilter ? archivedFilter.value : '0';
    fetch(`../../backend/api/checkup.php?action=list&archived=${encodeURIComponent(archived)}`)
      .then(r=>r.json())
      .then(d=>{
        const allRaw = d.checkups || [];
  // Build a map of SID -> most recent non-empty doctor (effective)
        checkedByMap = new Map();
        // Sort descending by created_at/id so first seen per SID with non-empty doc is latest
        const sorted = [...allRaw].sort((a,b)=>{
          const da=a&&a.created_at?new Date(a.created_at):null; const db=b&&b.created_at?new Date(b.created_at):null;
          if(da && db && !isNaN(da) && !isNaN(db)) return db-da;
          const ia=Number(a&&a.id); const ib=Number(b&&b.id); if(isFinite(ia)&&isFinite(ib)) return ib-ia; return 0;
        });
        for (const rec of sorted) {
          const sid = (rec.student_faculty_id || '').toString().trim();
          const dn = (rec.doctor_nurse_effective || rec.doctor_nurse || '').toString().trim();
          if (!sid || !dn) continue;
          if (!checkedByMap.has(sid)) checkedByMap.set(sid, dn);
        }
        // Deduplicate by SID so Next Check-Up reflects latest record per person
        all = dedupeBySid(allRaw);
        // Deduplicate by SID so Next Check-Up reflects latest record per person
        applyFilters();
      })
      .catch(()=> setMsg('Error fetching patient records.'));
  }

  function applyFilters(){
    const q = (search && search.value ? search.value.toLowerCase() : '');
    const role = (roleFilter && roleFilter.value ? roleFilter.value : '');
    let rows = all;
    if (q){
      rows = rows.filter(p =>
        (p.name && p.name.toLowerCase().includes(q)) ||
        (p.student_faculty_id && String(p.student_faculty_id).toLowerCase().includes(q))
      );
    }
    if (role){
      const wantedRaw = (role || '').toString();
      const norm = (s) => (s||'').toString().toLowerCase()
        .replace(/[()_\-]/g,' ') // unify separators
        .replace(/[^a-z0-9\s]/g,' ') // drop other punctuation
        .replace(/\s+/g,' ') // collapse spaces
        .trim();
      const wanted = norm(wantedRaw);
      rows = rows.filter(p => {
        const normalized = norm(p.role || p.role_effective || '');
        if (!normalized) return false;
        const tokens = normalized.split(' ');
        const has = (t)=> tokens.includes(t);
        const contains = (s)=> normalized.includes(s);
        const isNonTeaching = contains('non teaching') || contains('nonteaching') || (has('non') && has('teaching'));
        const isStudent = has('student');
        const isFaculty = has('faculty') || has('teacher') || has('instructor') || has('professor') || (has('teaching') && !isNonTeaching);
        const isStaff = has('staff') || has('employee') || has('personnel');
        const isStaffNonTeaching = isNonTeaching && (isStaff || true) // treat plain 'non teaching' as non-teaching staff
          || contains('staff non teaching') || contains('staffnonteaching') || contains('non teaching staff');

        if (wanted === 'student') return isStudent;
        if (wanted === 'faculty') return isFaculty;
        // Match "Staff (Non-Teaching)" and similar labels
        if (wanted.includes('non teaching') || wanted.includes('nonteaching') || wanted.startsWith('staff')) return isStaffNonTeaching;
        // Fallback exact match on normalized string
        return normalized === wanted;
      });
    }
    filtered = rows;
    currentPage = 1;
    renderPage();
  }

  document.addEventListener('click', e => {
    const t = e.target;
    if (t.classList.contains('btnView')){
      const id = t.getAttribute('data-id');
      window.location.href = `patient_view.html?id=${id}`;
    }
    if (t.classList.contains('btnArchive')){
      const id = t.getAttribute('data-id');
      const tr = t.closest('tr');
      const sidVal = (tr && tr.children && tr.children[0]) ? (tr.children[0].textContent || '').trim() : '';
      if (confirm('Archive this patient record?')){
        const adminId = localStorage.getItem('admin_id') || '';
        // Archive by student_faculty_id to cover all checkups for the patient
        fetch(`../../backend/api/checkup.php?action=archive`, {
          method:'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `student_faculty_id=${encodeURIComponent(sidVal)}&admin_id=${encodeURIComponent(adminId)}`
        })
          .then(r=>r.json())
          .then(res=>{
            if (res.success) {
              // Redirect to the Archived Patients view after successful archive
              window.location.href = 'patients_archive.html';
            } else {
              alert(res.message || 'Archive failed.');
            }
          })
          .catch(()=> alert('Network error while archiving.'));
      }
    }
    if (t.classList.contains('btnUnarchive')){
      const id = t.getAttribute('data-id');
      if (confirm('Unarchive this patient record?')){
        const adminId = localStorage.getItem('admin_id') || '';
        fetch(`../../backend/api/checkup.php?action=unarchive&id=${encodeURIComponent(id)}`, {
          method:'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `admin_id=${encodeURIComponent(adminId)}`
        })
          .then(r=>r.json())
          .then(res=>{
            if (res.success) load(); else alert(res.message || 'Unarchive failed.');
          })
          .catch(()=> alert('Network error while unarchiving.'));
      }
    }
    if (t.classList.contains('btnDelete')){
      const id = t.getAttribute('data-id');
      const isArchived = t.getAttribute('data-archived') === '1';
      const confirmMsg = isArchived
        ? 'Permanently delete this archived record? This cannot be undone.'
        : 'Delete this record? Note: You may need to archive first.';
      if (!confirm(confirmMsg)) return;
      const adminId = localStorage.getItem('admin_id') || '';
      fetch(`../../backend/api/checkup.php?action=delete&id=${encodeURIComponent(id)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `admin_id=${encodeURIComponent(adminId)}`
      })
        .then(r=>r.json())
        .then(res=>{
          if (res.success) load();
          else alert(res.message || 'Delete failed.');
        })
        .catch(()=> alert('Network error while deleting.'));
    }
  });

  if (search){ search.addEventListener('input', applyFilters); }
  if (roleFilter){ roleFilter.addEventListener('change', applyFilters); }
  if (archivedFilter){ archivedFilter.addEventListener('change', load); }

  document.addEventListener('click', e => {
    if (e.target && e.target.id === 'patientsPrev'){
      currentPage -= 1;
      renderPage();
    }
    if (e.target && e.target.id === 'patientsNext'){
      currentPage += 1;
      renderPage();
    }
  });

  // Removed page size selector logic

  load();
})();
