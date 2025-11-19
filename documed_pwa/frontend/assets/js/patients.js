// Fetch and display patient records for admin
let allPatients = [];
let dedupedPatients = [];
// Lightweight toast popup
function showToast(message, opts={}){
    const duration = opts.duration || 2000;
    // Remove any existing toast
    const existing = document.getElementById('dm-toast');
    if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
    const wrap = document.createElement('div');
    wrap.id = 'dm-toast';
    wrap.style.position = 'fixed';
    wrap.style.top = '20px';
    wrap.style.left = '50%';
    wrap.style.transform = 'translateX(-50%)';
    wrap.style.zIndex = '4000';
    wrap.style.background = '#111827';
    wrap.style.color = '#fff';
    wrap.style.padding = '10px 14px';
    wrap.style.borderRadius = '10px';
    wrap.style.boxShadow = '0 6px 20px rgba(0,0,0,0.2)';
    wrap.style.fontFamily = 'system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
    wrap.style.fontSize = '14px';
    wrap.style.opacity = '0';
    wrap.style.transition = 'opacity 160ms ease';
    wrap.textContent = message || '';
    document.body.appendChild(wrap);
    requestAnimationFrame(()=>{ wrap.style.opacity = '1'; });
    setTimeout(()=>{
        wrap.style.opacity = '0';
        setTimeout(()=>{ if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap); }, 180);
    }, duration);
}
// Compute BMI for follow-up form (WT in kg, HT in m or cm)
function computeFollowupBmi(){
    const form = document.getElementById('followupForm');
    if (!form) return;
    const f = form.elements || {};
    const wtEl = f['wt'];
    const htEl = f['ht'];
    const bmiEl = f['bmi'];
    const statusEl = document.getElementById('bmiStatus');
    if (!wtEl || !htEl || !bmiEl) return;
    const toNum = v => { const s=String(v??'').replace(',', '.').trim(); const n=parseFloat(s); return isFinite(n)?n:NaN; };
    let w = toNum(wtEl.value);
    let h = toNum(htEl.value);
    if (!isFinite(w) || !isFinite(h) || h<=0){ bmiEl.value=''; if(statusEl) { statusEl.textContent=''; statusEl.style.color='#374151'; } return; }
    if (h > 10) h = h/100; // treat as cm
    const bmi = w/(h*h);
    if (isFinite(bmi) && bmi>0) {
        const val = parseFloat(bmi.toFixed(1));
        bmiEl.value = val.toFixed(1);
        // WHO categories for adults
        let cat = 'Normal';
        let color = '#16a34a';
        if (val < 18.5) { cat = 'Underweight'; color = '#eab308'; }
        else if (val < 25) { cat = 'Normal'; color = '#16a34a'; }
        else if (val < 30) { cat = 'Overweight'; color = '#f97316'; }
        else { cat = 'Obese'; color = '#dc2626'; }
        if (statusEl) { statusEl.textContent = cat; statusEl.style.color = color; }
    } else {
        bmiEl.value = '';
        if (statusEl) { statusEl.textContent=''; statusEl.style.color='#374151'; }
    }
}
function isArchivedMode() {
    // Detect archive mode via hidden input or URL param
    try {
        const el = document.getElementById('archivedFilter');
        if (el) {
            const v = (el.value || '').toString().trim().toLowerCase();
            return v === '1' || v === 'true' || v === 'yes';
        }
        const qs = new URLSearchParams(window.location.search);
        const qv = (qs.get('archived') || '').toString().trim().toLowerCase();
        return qv === '1' || qv === 'true' || qv === 'yes';
    } catch(_) { return false; }
}

function renderPatients(patients) {
    const patientsTable = document.getElementById('patientsTable').getElementsByTagName('tbody')[0];
    const patientsMsg = document.getElementById('patientsMsg');
    patientsTable.innerHTML = '';
    const archived = isArchivedMode();
    if (patients.length > 0) {
        patients.forEach(p => {
            const row = document.createElement('tr');
            const createdAt = p.created_at ? new Date(p.created_at) : null;
            // Helper: render Next Check-Up state with hints
            const nextFollowUpLabel = formatNextFollowUp(p.follow_up_date);
            const actionsHtml = archived
                ? `<button class="btnView" data-id="${p.id}" style="background:#2563eb;color:#fff;border:none;cursor:pointer;">View</button>`
                : `<button class="btnView" data-id="${p.id}" style="background:#2563eb;color:#fff;border:none;cursor:pointer;">View</button>
                   <button class="btnArchive" data-id="${p.id}" style="background:#d97706;color:#fff;border:none;cursor:pointer;">Archive</button>`;
            row.innerHTML = `
                <td>${p.student_faculty_id || ''}</td>
                <td>${p.name}</td>
                <td>${p.address}</td>
                <td>${p.client_type || p.role || p.client_type_effective || p.role_effective || ''}</td>
                <td>${p.contact_number}</td>
                <td>${p.date_of_birth || ''}</td>
                <td>${p.assessment || ''}</td>
                <td>${createdAt ? createdAt.toLocaleDateString() : ''}</td>
                <td>${nextFollowUpLabel}</td>
                <td style="white-space:nowrap;">
                    <span class="actions-wrap">${actionsHtml}</span>
                </td>
            `;
            patientsTable.appendChild(row);
        });
        patientsMsg.textContent = '';
    } else {
        patientsMsg.textContent = 'No patient records found.';
    }
}

// Format "Next Check-Up" column with relative hints
function formatNextFollowUp(dateStr) {
    if (!dateStr) return '<span style="color:#6b7280;">Completed</span>';
    // Parse as local date to avoid TZ off-by-one
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
        // Grace period: show as Overdue for up to 5 days past due
        return '<span title="Past due ' + labelDate + '" style="color:#dc2626;font-weight:600;">Overdue (' + over + ' day' + (over === 1 ? '' : 's') + ')</span>';
    }
    // Beyond 5 days, mark as Lapsed for stronger emphasis
    return '<span title="Exceeded 5-day grace (due ' + labelDate + ')" style="color:#991b1b;font-weight:700;">Lapsed (' + over + ' days)</span>';    
}

function pickNewer(a, b) {
    const da = a && a.created_at ? new Date(a.created_at) : null;
    const db = b && b.created_at ? new Date(b.created_at) : null;
    if (da && db && !isNaN(da) && !isNaN(db)) return da > db ? a : b;
    // Fallback to higher numeric id if dates are missing/invalid
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

function fetchPatients() {
    // Determine archive filter to pass to backend
    let archivedParam = '0';
    try {
        const el = document.getElementById('archivedFilter');
        if (el) archivedParam = String(el.value || '0');
        else {
            const qs = new URLSearchParams(window.location.search);
            const qv = qs.get('archived');
            if (qv != null) archivedParam = String(qv);
        }
    } catch(_) { archivedParam = '0'; }

    const url = `../../backend/api/checkup.php?action=list&archived=${encodeURIComponent(archivedParam)}`;
    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) {
                const patientsMsg = document.getElementById('patientsMsg');
                if (patientsMsg) patientsMsg.textContent = (data && data.message) ? data.message : 'Error fetching patient records.';
                console.warn('[patients] fetchPatients API error/unsuccessful response', data);
            } else {
                console.debug('[patients] fetchPatients received', Array.isArray(data.checkups)?data.checkups.length:0, 'records');
            }
            // Normalize records: map effective fields to canonical keys
            const raw = Array.isArray(data.checkups) ? data.checkups : [];
            if (!raw.length) {
                console.info('[patients] No checkup records returned from API. Possible causes: table empty, SQL error suppressed, wrong DB, or PHP fatal before output.');
            }
            allPatients = raw.map(r => {
                const clientType = r.client_type_effective || r.client_type || r.role_effective || r.role || '';
                const gender = r.gender_effective || r.gender || '';
                return {
                    ...r,
                    client_type: clientType,
                    role: clientType, // backward compatibility
                    gender: gender
                };
            });
            dedupedPatients = dedupeBySid(allPatients);
            console.debug('[patients] After dedupe', dedupedPatients.length, 'unique SIDs');

            // If on doc_nurse view, show only patients checked up by the logged-in doc/nurse
            const isDocNursePage = /\/doc_nurse\//.test(window.location.pathname);
            if (isDocNursePage) {
                let dn = null;
                try { dn = JSON.parse(localStorage.getItem('documed_doc_nurse') || 'null'); } catch(_) { dn = null; }
                if (dn && dn.name) {
                    const name = (dn.name || '').trim();
                    const role = (dn.role || '').trim();
                    const exact1 = name; // legacy records might store just the name
                    const exact2 = role ? `${name} (${role})` : name; // current format
                    const nameLower = name.toLowerCase();
                    // Build a set of SIDs where any record was performed by this DN
                    const sidSet = new Set();
                    for (const rec of allPatients) {
                        const sid = (rec && rec.student_faculty_id ? String(rec.student_faculty_id) : '').trim();
                        if (!sid) continue;
                        // Prefer exact match by doc_nurse_id if present
                        const recDnId = (rec && rec.doc_nurse_id != null) ? String(rec.doc_nurse_id).trim() : '';
                        if (recDnId && String(dn.id) === recDnId) { sidSet.add(sid); continue; }
                        const performer = (rec && rec.doctor_nurse ? String(rec.doctor_nurse) : '').trim();
                        if (!performer) continue;
                        const perfLower = performer.toLowerCase();
                        if (
                            performer === exact2 ||
                            performer === exact1 ||
                            perfLower.includes(nameLower) // permissive match for legacy variations
                        ) {
                            sidSet.add(sid);
                        }
                    }
                    if (sidSet.size > 0) {
                        dedupedPatients = dedupedPatients.filter(p => sidSet.has(String(p.student_faculty_id || '').trim()));
                    } else {
                        // No matches: show empty list to avoid leaking other patients
                        dedupedPatients = [];
                    }
                } else {
                    // Not logged in as doc/nurse properly; show empty list for safety
                    dedupedPatients = [];
                }
            }

            renderPatients(dedupedPatients);
            // Update gender stats if elements present (doc_nurse dashboard or similar reuse)
            try {
                const maleEl = document.getElementById('statMaleCheckups');
                const femaleEl = document.getElementById('statFemaleCheckups');
                if (maleEl || femaleEl) {
                    let m=0,f=0;
                    for (const rec of allPatients) {
                        const g = (rec.gender||'').toLowerCase();
                        if (g === 'male') m++; else if (g === 'female') f++;
                    }
                    if (maleEl) maleEl.textContent = m;
                    if (femaleEl) femaleEl.textContent = f;
                }
            } catch(_) { /* ignore */ }
        })
        .catch(() => {
            const patientsMsg = document.getElementById('patientsMsg');
            patientsMsg.textContent = 'Error fetching patient records.';
        });
}
// View, Update, Delete button logic
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('btnView')) {
        const id = e.target.getAttribute('data-id');
        window.location.href = `patient_view.html?id=${id}`;
        return;
    }
    if (e.target.classList.contains('btnArchive')) {
        const id = e.target.getAttribute('data-id');
        // Build a clearer confirmation message with patient name and ID, if available
        let name = '';
        let sid = '';
        const tr = e.target.closest('tr');
        if (tr && tr.children && tr.children.length >= 2) {
            sid = (tr.children[0].textContent || '').trim();
            name = (tr.children[1].textContent || '').trim();
        }
        const msg = `Archive this patient record${name ? ` for ${name}` : ''}${sid ? ` (ID: ${sid})` : ''}? It will be moved to Archived Patients and can be restored.`;
        if (!confirm(msg)) return;
        // Archive all records for this student's SID so the patient won't reappear due to other checkups
        const sidVal = sid || (tr && tr.children && tr.children[0] ? (tr.children[0].textContent||'').trim() : '');
        fetch(`../../backend/api/checkup.php?action=archive`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `student_faculty_id=${encodeURIComponent(sidVal)}`
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    try { showToast('Patient record archived. Redirecting to Archived Patients...'); } catch(_) { /* no-op */ }
                    window.location.href = 'patients_archive.html';
                } else alert(data.message || 'Archive failed.');
            })
            .catch(() => alert('Network error while archiving.'));
    }
});

// Add a new checkup record
function addCheckupRecord(formData, checkupMsg, checkupModal, checkupForm) {
    // Submission guard: avoid double POSTs when multiple handlers fire
    try { if (checkupForm && checkupForm.dataset.submitting === '1') { return; } } catch(_) {}
    try { if (checkupForm) checkupForm.dataset.submitting = '1'; } catch(_) {}
    // Attach performer (doctor/nurse) if available
    try {
        const dn = JSON.parse(localStorage.getItem('documed_doc_nurse') || 'null');
        if (dn && dn.name) {
            const performer = dn.role ? `${dn.name} (${dn.role})` : dn.name;
            formData.set('doctor_nurse', performer);
            if (dn.id) formData.set('doc_nurse_id', dn.id);
        }
    } catch(_) { /* ignore */ }
    // Ensure client_type is included (legacy 'role' now renamed)
    try {
        const rs = document.getElementById('roleSelect'); // now client_type
        const ctVal = (formData.get('client_type') || (rs && rs.value) || formData.get('role') || '').toString().trim();
        if (ctVal) {
            formData.delete('role');
            formData.set('client_type', ctVal);
        }
    } catch(_) {}
    // Gender ensure
    try {
        const gs = document.getElementById('genderSelect');
        const gVal = (formData.get('gender') || (gs && gs.value) || '').toString().trim();
        if (gVal) formData.set('gender', gVal);
    } catch(_) {}
    if (!formData.has('follow_up')) { formData.set('follow_up', '0'); }
    const fup = formData.get('follow_up');
    const fdate = formData.get('follow_up_date');
    if (String(fup) !== '1') {
        formData.delete('follow_up_date');
    }
        fetch('../../backend/api/checkup.php?action=add', { method: 'POST', body: formData })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
            checkupMsg.textContent = 'Patient record added successfully!';
            // Show popup confirmation
            showToast('Patient record added successfully!');
            // Refresh the table and close the modal so the new record is visible in the Patients list
            fetchPatients();
            setTimeout(() => {
                if (checkupModal) checkupModal.style.display = 'none';
                if (checkupForm) checkupForm.reset();
                if (checkupMsg) checkupMsg.textContent = '';
                                try { if (checkupForm) checkupForm.dataset.submitting = '0'; } catch(_) {}
            }, 600);
        } else {
            checkupMsg.textContent = data.message || 'Error adding patient record.';
                        try { if (checkupForm) checkupForm.dataset.submitting = '0'; } catch(_) {}
        }
      })
            .catch(() => { checkupMsg.textContent = 'Network error while adding checkup record.'; try { if (checkupForm) checkupForm.dataset.submitting = '0'; } catch(_) {} });
}

// Page init for modal wiring, deep-link, and search
document.addEventListener('DOMContentLoaded', function() {
    const checkupBtn = document.getElementById('checkupBtn');
    const checkupModal = document.getElementById('checkupModal');
    const closeCheckupX = document.getElementById('closeCheckupX');
    const closeCheckup = document.getElementById('closeCheckup');
    const checkupForm = document.getElementById('addCheckupForm');
    const checkupMsg = document.getElementById('checkupMsg');
    const roleSelect = document.getElementById('roleSelect');
    const yearCourseGroup = document.getElementById('yearCourseGroup');
    const departmentGroup = document.getElementById('departmentGroup');

    // Expose helper to lock/unlock demographics after autofill
    window.setAutofilledReadOnly = function(isReadOnly) {
        if (!checkupForm) return;
        const f = checkupForm.elements;
        const set = (name, ro=true) => { if (f[name]) f[name].readOnly = !!ro; };
        set('student_faculty_id', isReadOnly);
        set('name', isReadOnly);
        set('age', isReadOnly);
        set('address', isReadOnly);
        set('civil_status', isReadOnly);
        set('nationality', isReadOnly);
        set('religion', isReadOnly);
        set('date_of_birth', isReadOnly);
        set('place_of_birth', isReadOnly);
        set('year_and_course', isReadOnly);
        set('contact_person', isReadOnly);
        set('contact_number', isReadOnly);
        const deptInput = document.getElementById('departmentInput');
        if (deptInput) deptInput.readOnly = !!isReadOnly;
        if (roleSelect) roleSelect.disabled = !!isReadOnly;
    };

    function resetCheckupFormUI() {
        // make fields editable again and clear role-dependent fields
        window.setAutofilledReadOnly(false);
        if (roleSelect) { roleSelect.disabled = false; roleSelect.value = ''; }
        if (yearCourseGroup) yearCourseGroup.style.display = 'none';
        if (departmentGroup) departmentGroup.style.display = 'none';
        const yearIn = document.getElementById('yearAndCourseInput'); if (yearIn) yearIn.value = '';
        const deptIn = document.getElementById('departmentInput'); if (deptIn) deptIn.value = '';
    }

    if (checkupBtn && checkupModal) {
        checkupBtn.onclick = () => { resetCheckupFormUI(); checkupModal.style.display = 'flex'; };
    }
    if (closeCheckupX) closeCheckupX.onclick = () => { checkupModal.style.display = 'none'; resetCheckupFormUI(); };
    if (closeCheckup) closeCheckup.onclick = () => { checkupModal.style.display = 'none'; resetCheckupFormUI(); };
    if (checkupForm) {
        checkupForm.onsubmit = function(e) {
            e.preventDefault();
            // Guard double submit
            if (checkupForm.dataset.submitting === '1') { return; }
            checkupMsg.textContent = 'Saving...';
            const formData = new FormData(checkupForm);
            addCheckupRecord(formData, checkupMsg, checkupModal, checkupForm);
        };
    }

    // Deep-link: only supports action=checkup now (no SID in URL; use sessionStorage)
    (function handleActionParams(){
        try {
            const qs = new URLSearchParams(window.location.search);
            const action = (qs.get('action') || '').toLowerCase();
            let sid = '';
            try { sid = sessionStorage.getItem('documed_checkup_sid') || ''; } catch(_) { sid = ''; }
            // Back-compat: allow sid in URL if sessionStorage missing
            if (!sid) sid = qs.get('sid') || '';
            if (action !== 'checkup' || !sid) return;
            // Clean up URL by removing sid parameter if present
            if (qs.has('sid')) {
                const newQs = new URLSearchParams(window.location.search);
                newQs.delete('sid');
                const newUrl = `${location.pathname}?${newQs.toString()}`.replace(/[?&]$/,'');
                history.replaceState(null, '', newUrl);
            }
            // Prefill checkup form from user by SID and open modal
            fetch('../../backend/api/auth.php?action=get_user_by_sid', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ sid })
            }).then(res=>res.json()).then(data=>{
                const user = (data && data.success) ? data.user : null;
                if (!user || !checkupForm) return;
                const f = checkupForm.elements;
                const fullName = user.name || [user.first_name, user.middle_initial, user.last_name].filter(Boolean).join(' ').replace(/\s+/g,' ').trim();
                if (f['student_faculty_id']) f['student_faculty_id'].value = user.student_faculty_id || '';
                if (f['name']) f['name'].value = fullName || '';
                if (f['age']) f['age'].value = user.age || '';
                if (f['address']) f['address'].value = user.address || '';
                if (f['civil_status']) f['civil_status'].value = user.civil_status || '';
                if (f['nationality']) f['nationality'].value = user.nationality || '';
                if (f['religion']) f['religion'].value = user.religion || '';
                if (f['date_of_birth']) {
                    const dob = user.date_of_birth || user.birthdate || '';
                    if (dob) {
                        const d = new Date(dob);
                        if (!isNaN(d.getTime())) {
                            const y = d.getFullYear(); const m = String(d.getMonth()+1).padStart(2,'0'); const da = String(d.getDate()).padStart(2,'0');
                            f['date_of_birth'].value = `${y}-${m}-${da}`;
                        } else { f['date_of_birth'].value = dob; }
                    }
                }
                if (f['place_of_birth']) f['place_of_birth'].value = user.place_of_birth || '';
                if (f['year_and_course']) f['year_and_course'].value = user.year_course || user.year_and_course || '';
                if (f['contact_person']) f['contact_person'].value = user.contact_person || '';
                if (f['contact_number']) f['contact_number'].value = user.contact_number || '';
                if (roleSelect) { roleSelect.value = user.role || ''; const deptInput = document.getElementById('departmentInput'); if (deptInput) deptInput.value = user.department || ''; roleSelect.dispatchEvent(new Event('change',{bubbles:true})); }
                if (typeof window.setAutofilledReadOnly === 'function') window.setAutofilledReadOnly(true);
                if (checkupModal) checkupModal.style.display = 'flex';
                // Clear stored SID after use for privacy
                try { sessionStorage.removeItem('documed_checkup_sid'); } catch(_) {}
            }).catch(()=>{ if (checkupModal) checkupModal.style.display = 'flex'; });
        } catch(_) {}
    })();

    // Fetch initial list and wire search
    fetchPatients();
    const searchInput = document.getElementById('searchPatient');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const filtered = (dedupedPatients.length ? dedupedPatients : allPatients).filter(p => {
                return (p.name && p.name.toLowerCase().includes(filter)) ||
                       (p.student_faculty_id && p.student_faculty_id.toLowerCase().includes(filter));
            });
            renderPatients(filtered);
        });
    }
});

// Make sure QR scanner logic runs after Html5Qrcode is loaded
window.addEventListener('load', function() {
    if (typeof Html5Qrcode !== 'undefined') {
        const scanBtn = document.getElementById('scanBtn');
        const scanModal = document.getElementById('scanModal');
        const closeScan = document.getElementById('closeScan');
        const scanMsg = document.getElementById('scanMsg');
    const checkupModal = document.getElementById('checkupModal');
        const checkupForm = document.getElementById('addCheckupForm');
    const roleSelect = document.getElementById('roleSelect'); // now client_type
    const genderSelect = document.getElementById('genderSelect');
    const cameraSelect = document.getElementById('cameraSelect');
    let html5QrCode;
    let isScanning = false;
    let scanProfileIndex = 0;
    let scanWatchdogTimer = null;

        function fillCheckupFormFromUser(user) {
            if (!checkupForm) return;
            const f = checkupForm.elements;
            const fullName = user.name
                || [user.first_name, user.middle_initial, user.last_name].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();

            if (f['student_faculty_id']) f['student_faculty_id'].value = user.student_faculty_id || '';
            if (f['name']) f['name'].value = fullName || '';
            if (f['age']) f['age'].value = user.age || '';
            if (f['address']) f['address'].value = user.address || '';
            if (f['civil_status']) f['civil_status'].value = user.civil_status || '';
            if (f['nationality']) f['nationality'].value = user.nationality || '';
            if (f['religion']) f['religion'].value = user.religion || '';
            if (f['date_of_birth']) {
                // Ensure YYYY-MM-DD if possible
                const dob = user.date_of_birth || user.birthdate || '';
                if (dob) {
                    const d = new Date(dob);
                    if (!isNaN(d.getTime())) {
                        const y = d.getFullYear();
                        const m = String(d.getMonth() + 1).padStart(2, '0');
                        const da = String(d.getDate()).padStart(2, '0');
                        f['date_of_birth'].value = `${y}-${m}-${da}`;
                    } else {
                        // fallback to raw if already formatted
                        f['date_of_birth'].value = dob;
                    }
                }
            }
            if (f['place_of_birth']) f['place_of_birth'].value = user.place_of_birth || '';
            if (f['year_and_course']) f['year_and_course'].value = user.year_course || user.year_and_course || '';
            if (f['contact_person']) f['contact_person'].value = user.contact_person || '';
            if (f['contact_number']) f['contact_number'].value = user.contact_number || '';
            // client_type (legacy alias role) + show/hide Year/Course vs Department
            if (roleSelect) {
                roleSelect.value = user.client_type || user.role || '';
                // Prefill Department if applicable
                const deptInput = document.getElementById('departmentInput');
                if (deptInput) deptInput.value = user.department || '';
                // Trigger change to toggle year/course vs department visibility
                const evt = new Event('change', { bubbles: true });
                roleSelect.dispatchEvent(evt);
            }
            // Gender
            if (genderSelect) {
                genderSelect.value = user.gender || '';
            }
            // Lock autofilled demographics to read-only
            if (typeof setAutofilledReadOnly === 'function') setAutofilledReadOnly(true);
            // Also prefill Follow Up modal School ID if present
            const fu = document.getElementById('followupUserId');
            if (fu) fu.value = user.student_faculty_id || '';
        }

        // Simple inline prompt for redirecting to existing patient record
        function showRedirectPrompt(name, sid) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.setAttribute('role', 'dialog');
                overlay.style.position = 'fixed';
                overlay.style.inset = '0';
                overlay.style.background = 'rgba(0,0,0,0.45)';
                overlay.style.display = 'flex';
                overlay.style.alignItems = 'center';
                overlay.style.justifyContent = 'center';
                overlay.style.zIndex = '3000';

                const dialog = document.createElement('div');
                dialog.style.width = '100%';
                dialog.style.maxWidth = '520px';
                dialog.style.background = '#fff';
                dialog.style.border = '1px solid #e5e7eb';
                dialog.style.borderRadius = '12px';
                dialog.style.boxShadow = '0 10px 30px rgba(0,0,0,0.15)';
                dialog.style.overflow = 'hidden';

                const header = document.createElement('div');
                header.style.display = 'flex';
                header.style.alignItems = 'center';
                header.style.justifyContent = 'space-between';
                header.style.padding = '12px 16px';
                header.style.borderBottom = '1px solid #f3f4f6';
                const title = document.createElement('h3');
                title.textContent = 'Existing Patient Record';
                title.style.margin = '0';
                title.style.fontSize = '1.05rem';
                title.style.color = '#111827';
                title.style.fontWeight = '700';
                const xbtn = document.createElement('button');
                xbtn.textContent = 'Close';
                xbtn.style.border = 'none';
                xbtn.style.background = '#f3f4f6';
                xbtn.style.color = '#111827';
                xbtn.style.borderRadius = '8px';
                xbtn.style.padding = '6px 10px';
                xbtn.style.cursor = 'pointer';
                header.appendChild(title);
                header.appendChild(xbtn);

                const body = document.createElement('div');
                body.style.padding = '12px 16px';
                const msg = document.createElement('p');
                msg.style.margin = '0 0 8px 0';
                msg.style.color = '#374151';
                msg.innerHTML = `Patient <strong>${(name||'').toString()}</strong> with SID <strong>${(sid||'').toString()}</strong> already has a record. Redirect to this patient record?`;
                body.appendChild(msg);

                const actions = document.createElement('div');
                actions.style.display = 'flex';
                actions.style.gap = '10px';
                actions.style.justifyContent = 'flex-end';
                actions.style.padding = '12px 16px';
                actions.style.borderTop = '1px solid #f3f4f6';
                const cancel = document.createElement('button');
                cancel.textContent = 'No';
                cancel.style.border = 'none';
                cancel.style.background = '#6b7280';
                cancel.style.color = '#fff';
                cancel.style.borderRadius = '8px';
                cancel.style.padding = '8px 12px';
                cancel.style.cursor = 'pointer';
                const ok = document.createElement('button');
                ok.textContent = 'Yes';
                ok.style.border = 'none';
                ok.style.background = '#2563eb';
                ok.style.color = '#fff';
                ok.style.borderRadius = '8px';
                ok.style.padding = '8px 12px';
                ok.style.cursor = 'pointer';
                actions.appendChild(cancel);
                actions.appendChild(ok);

                dialog.appendChild(header);
                dialog.appendChild(body);
                dialog.appendChild(actions);
                overlay.appendChild(dialog);
                document.body.appendChild(overlay);

                function cleanup() { if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay); }
                cancel.onclick = () => { cleanup(); resolve(false); };
                xbtn.onclick = () => { cleanup(); resolve(false); };
                ok.onclick = () => { cleanup(); resolve(true); };
                overlay.addEventListener('click', (e)=>{ if (e.target === overlay) { cleanup(); resolve(false); } });
                document.addEventListener('keydown', function esc(e){ if (e.key === 'Escape') { document.removeEventListener('keydown', esc); cleanup(); resolve(false); } });
            });
        }

        async function handleQrPayload(qrCodeMessage) {
            scanMsg.textContent = 'Processing QR...';
            const raw = (qrCodeMessage || '').toString().trim();

            // Back-compat: try JSON payloads (old QR codes)
            let payload = null;
            try { payload = JSON.parse(raw); } catch (_) { payload = null; }

            // Extract possible candidates
            let user = null;
            let idCandidate = null;           // numeric database id
            let sidCandidate = null;          // student_faculty_id

            if (payload && typeof payload === 'object') {
                if (payload.id || payload.user_id) idCandidate = String(payload.id || payload.user_id).trim();
                if (payload.student_faculty_id || payload.sid) sidCandidate = String(payload.student_faculty_id || payload.sid).trim();
            }

            // URL-like patterns e.g., documed://user?id=123 or ...?sid=22-LN-4067
            if (!idCandidate || !sidCandidate) {
                const idMatch = raw.match(/[?&#](?:id|user_id)=([^&\s#]+)/i);
                const sidMatch = raw.match(/[?&#](?:student_faculty_id|sid)=([^&\s#]+)/i);
                if (!idCandidate && idMatch && idMatch[1]) idCandidate = decodeURIComponent(idMatch[1]).trim();
                if (!sidCandidate && sidMatch && sidMatch[1]) sidCandidate = decodeURIComponent(sidMatch[1]).trim();
            }

            // New policy: QR holds plain SID or numeric ID
            if (!idCandidate && /^\d+$/.test(raw)) idCandidate = raw;
            if (!sidCandidate && /^[A-Za-z0-9\-]+$/.test(raw) && raw.length >= 3) sidCandidate = raw;

            // 1) Try fetching by numeric ID (if looks like one)
            if (idCandidate) {
                try {
                    const res = await fetch('../../backend/api/auth.php?action=get_user_full', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ id: idCandidate })
                    });
                    const data = await res.json();
                    if (data && data.success && data.user) user = data.user;
                } catch (_) { /* ignore */ }
            }

            // 2) If not found via ID, try by SID (covers numeric-only SIDs too)
            if (!user && sidCandidate) {
                try {
                    const resSid = await fetch('../../backend/api/auth.php?action=get_user_by_sid', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ sid: sidCandidate })
                    });
                    const dataSid = await resSid.json();
                    if (dataSid && dataSid.success && dataSid.user) user = dataSid.user;
                } catch (_) { /* ignore */ }
            }

            // 3) Last resort: if QR contained a full object
            if (!user && payload && typeof payload === 'object') user = payload;

            if (!user) { scanMsg.textContent = 'Could not extract user info from QR.'; return; }

            // If user already has checkup records, offer redirect to latest record
            let redirected = false;
            let hadExisting = false;
            try {
                const sid = (user.student_faculty_id || user.sid || '').toString();
                if (sid) {
                    const r = await fetch(`../../backend/api/checkup.php?action=list&student_faculty_id=${encodeURIComponent(sid)}`);
                    const l = await r.json();
                    const arr = Array.isArray(l.checkups) ? l.checkups : [];
                    if (arr.length) {
                        hadExisting = true;
                        // pick newest by created_at desc then id desc
                        arr.sort((a,b)=>{
                            const da=a&&a.created_at?new Date(a.created_at):null; const db=b&&b.created_at?new Date(b.created_at):null;
                            if(da && db && !isNaN(da) && !isNaN(db)) return db-da;
                            const ia=Number(a&&a.id); const ib=Number(b&&b.id); if(isFinite(ia)&&isFinite(ib)) return ib-ia; return 0;
                        });
                        const latest = arr[0];
                        const wants = await showRedirectPrompt(user.name || sid, sid);
                        if (wants) {
                            // Optional: ensure scan modal is closed before redirect
                            if (scanModal) scanModal.style.display = 'none';
                            window.location.href = `patient_view.html?id=${encodeURIComponent(latest.id)}`;
                            redirected = true;
                        } else {
                            // User declined: just close the Scan QR modal and finish (do not open new checkup)
                            if (scanModal) scanModal.style.display = 'none';
                            scanMsg.textContent = '';
                            return;
                        }
                    }
                }
            } catch(_) { /* ignore */ }

            if (redirected) return;

            // Otherwise, open and fill the checkup modal to add a new record
            // Only do this if no existing records were found
            if (hadExisting) return;
            fillCheckupFormFromUser(user);
            if (checkupModal) checkupModal.style.display = 'flex';
            if (scanModal) scanModal.style.display = 'none';
            scanMsg.textContent = '';
        }

        async function populateCamerasAndStart(defaultStart = true) {
            scanMsg.textContent = '';
            try {
                const devices = await Html5Qrcode.getCameras();
                if (!devices || !devices.length) {
                    scanMsg.textContent = 'No camera found. Ensure permissions are granted and try a different browser.';
                    return;
                }
                if (cameraSelect) {
                    cameraSelect.innerHTML = '';
                    devices.forEach((d, idx) => {
                        const opt = document.createElement('option');
                        opt.value = d.id;
                        opt.textContent = d.label || `Camera ${idx + 1}`;
                        cameraSelect.appendChild(opt);
                    });
                }
                if (!html5QrCode) {
                    html5QrCode = new Html5Qrcode('qr-reader');
                }
                if (defaultStart && cameraSelect && cameraSelect.value) {
                    await startScannerWithDevice(cameraSelect.value);
                }
            } catch (err) {
                scanMsg.textContent = 'Unable to enumerate cameras. Site must be served over https or http://localhost.';
            }
        }

        function insecureOriginHint() {
            const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
            return isSecure ? '' : ' Note: Camera access requires https or http://localhost.';
        }

        // Simple scanning overlay controls
        function showScanOverlay() {
            const qrReader = document.getElementById('qr-reader');
            if (!qrReader) return;
            let overlay = qrReader.querySelector('.qr-scan-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'qr-scan-overlay';
                overlay.innerHTML = '<div class="scan-line"></div>';
                qrReader.appendChild(overlay);
            }
            overlay.style.display = 'block';
        }
        function hideScanOverlay() {
            const overlay = document.querySelector('#qr-reader .qr-scan-overlay');
            if (overlay) overlay.style.display = 'none';
        }

        function clearScanWatchdog() {
            if (scanWatchdogTimer) { clearTimeout(scanWatchdogTimer); scanWatchdogTimer = null; }
        }

        function buildScanConfig(profileIdx) {
            const common = {
                rememberLastUsedCamera: true,
                aspectRatio: 1.7777777778,
                videoConstraints: { width: { ideal: 1280 }, height: { ideal: 720 } },
                experimentalFeatures: { useBarCodeDetectorIfSupported: true }
            };
            const profiles = [
                { label: 'A', fps: 18, disableFlip: false, qrbox: (w,h)=>{const s=Math.min(w,h);return {width:Math.floor(s*0.8),height:Math.floor(s*0.8)}} },
                { label: 'B', fps: 24, disableFlip: false, qrbox: (w,h)=>{const s=Math.min(w,h);return {width:Math.floor(s*0.9),height:Math.floor(s*0.9)}} },
                { label: 'C', fps: 24, disableFlip: true,  qrbox: (w,h)=>{const s=Math.min(w,h);return {width:Math.floor(s*0.7),height:Math.floor(s*0.7)}} },
                { label: 'D', fps: 30, disableFlip: false } // let library choose qrbox
            ];
            const chosen = profiles[profileIdx % profiles.length];
            const cfg = { ...common, ...chosen };
            if (window.Html5QrcodeSupportedFormats) {
                const F = Html5QrcodeSupportedFormats;
                cfg.formatsToSupport = [
                    F.QR_CODE, F.DATA_MATRIX, F.PDF_417, F.AZTEC,
                    F.CODE_128, F.CODE_39, F.CODE_93, F.EAN_13,
                    F.EAN_8, F.UPC_A, F.UPC_E, F.ITF
                ].filter(Boolean);
            }
            if (window.Html5QrcodeScanType) {
                cfg.supportedScanTypes = [Html5QrcodeScanType.SCAN_TYPE_CAMERA];
            }
            return cfg;
        }

        async function startScannerWithDevice(deviceId) {
            if (!html5QrCode) html5QrCode = new Html5Qrcode('qr-reader');
            const baseConfig = buildScanConfig(scanProfileIndex);

            try {
                if (isScanning) { await html5QrCode.stop().catch(() => {}); isScanning = false; }
                let lastErrTs = 0;
                await html5QrCode.start(
                    deviceId,
                    baseConfig,
                    async (qrCodeMessage) => {
                        if (isScanning) { isScanning = false; try { await html5QrCode.stop(); } catch (_) {} }
                        hideScanOverlay();
                        if (scanMsg) { scanMsg.style.color = '#16a34a'; scanMsg.textContent = 'QR code scanned successfully'; }
                        setTimeout(() => { handleQrPayload(qrCodeMessage); }, 300);
                    },
                    (err) => {
                        // Throttle noisy per-frame errors
                        const now = Date.now();
                        if (now - lastErrTs > 2000) { lastErrTs = now; console.debug('scan frame error:', err); }
                    }
                );
                isScanning = true;
                showScanOverlay();
                if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                // If nothing decoded within 7s, retune and restart automatically
                clearScanWatchdog();
                scanWatchdogTimer = setTimeout(async () => {
                    if (!isScanning) return;
                    try { await html5QrCode.stop(); } catch(_) {}
                    isScanning = false;
                    scanProfileIndex = (scanProfileIndex + 1) % 4;
                    if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                    await startScannerWithDevice(deviceId);
                }, 7000);
            } catch (e) {
                console.error('QR start error (deviceId string):', e);
                const msg = (e && (e.message || e.name)) ? `${e.name || 'Error'}: ${e.message}` : 'Unknown error.';
                if (scanMsg) scanMsg.textContent = `Failed to start selected camera. ${msg}${insecureOriginHint()}`;
                // Fallback attempts: try facingMode user then environment
                try {
                    if (isScanning) { await html5QrCode.stop().catch(() => {}); isScanning = false; }
                    await html5QrCode.start(
                        { facingMode: 'user' },
                        buildScanConfig(scanProfileIndex),
                        async (qrCodeMessage) => {
                            if (isScanning) { isScanning = false; try { await html5QrCode.stop(); } catch (_) {} }
                            hideScanOverlay();
                            if (scanMsg) { scanMsg.style.color = '#16a34a'; scanMsg.textContent = 'QR code scanned successfully'; }
                            setTimeout(() => { handleQrPayload(qrCodeMessage); }, 300);
                        },
                        (err) => { /* ignore */ }
                    );
                    isScanning = true;
                    showScanOverlay();
                    if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                    clearScanWatchdog();
                    scanWatchdogTimer = setTimeout(async () => {
                        if (!isScanning) return;
                        try { await html5QrCode.stop(); } catch(_) {}
                        isScanning = false;
                        scanProfileIndex = (scanProfileIndex + 1) % 4;
                        if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                        await startScannerWithDevice(deviceId);
                    }, 7000);
                    return;
                } catch (eUser) { console.error('QR start fallback (user) failed:', eUser); }
                try {
                    if (isScanning) { await html5QrCode.stop().catch(() => {}); isScanning = false; }
                    await html5QrCode.start(
                        { facingMode: 'environment' },
                        buildScanConfig(scanProfileIndex),
                        async (qrCodeMessage) => {
                            if (isScanning) { isScanning = false; try { await html5QrCode.stop(); } catch (_) {} }
                            hideScanOverlay();
                            if (scanMsg) { scanMsg.style.color = '#16a34a'; scanMsg.textContent = 'QR code scanned successfully'; }
                            setTimeout(() => { handleQrPayload(qrCodeMessage); }, 300);
                        },
                        (err) => { /* ignore */ }
                    );
                    isScanning = true;
                    showScanOverlay();
                    if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                    clearScanWatchdog();
                    scanWatchdogTimer = setTimeout(async () => {
                        if (!isScanning) return;
                        try { await html5QrCode.stop(); } catch(_) {}
                        isScanning = false;
                        scanProfileIndex = (scanProfileIndex + 1) % 4;
                        if (scanMsg) { scanMsg.style.color = '#2563eb'; scanMsg.textContent = 'Scanning... Align QR within the box'; }
                        await startScannerWithDevice(deviceId);
                    }, 7000);
                } catch (e2) {
                    console.error('QR start fallback (environment) failed:', e2);
                    const msg2 = (e2 && (e2.message || e2.name)) ? `${e2.name || 'Error'}: ${e2.message}` : '';
                    if (scanMsg) scanMsg.textContent = `Camera start failed on all modes. ${msg2}${insecureOriginHint()} Make sure no other app is using the camera and permissions are allowed.`;
                    hideScanOverlay();
                }
            }
        }

        // Camera control helpers using applyVideoConstraints (if supported by device & browser)
        async function applyConstraintsSafe(constraints) {
            if (!html5QrCode || typeof html5QrCode.applyVideoConstraints !== 'function') {
                console.warn('applyVideoConstraints not supported by this html5-qrcode version or browser track.');
                return;
            }
            try {
                await html5QrCode.applyVideoConstraints(constraints);
            } catch (err) {
                console.error('applyVideoConstraints failed:', err, constraints);
                if (scanMsg) scanMsg.textContent = 'Camera control not supported on this device.';
            }
        }

        if (scanBtn && scanModal && closeScan) {
            scanBtn.onclick = async function() {
                scanModal.style.display = 'flex';
                scanMsg.textContent = '';
                await populateCamerasAndStart(true);
            };

            if (cameraSelect) {
                cameraSelect.addEventListener('change', async function() {
                    if (this.value) {
                        await startScannerWithDevice(this.value);
                    }
                });
            }

            closeScan.onclick = function() {
                scanModal.style.display = 'none';
                if (html5QrCode && isScanning) {
                    html5QrCode.stop().catch(() => {});
                    isScanning = false;
                }
                hideScanOverlay();
                scanMsg.textContent = '';
                clearScanWatchdog();
            };

            // No scan-from-image button per request
        }
    } else {
        console.error('Html5Qrcode library not loaded.');
    }
});

// search patient records
// ...existing code...

// When navigating back to this page via the browser's back/forward cache,
// clear any open modals and form data so the checkup modal doesn't auto-show.
window.addEventListener('pageshow', function (e) {
    try {
        // If coming from bfcache, force a clean reload to reset all UI state
        if (e && e.persisted) {
            // Also strip action parameter to avoid deep-link triggers on reload
            try {
                const qs = new URLSearchParams(window.location.search);
                if (qs.has('action')) {
                    qs.delete('action');
                    const newUrl = `${location.pathname}?${qs.toString()}`.replace(/[?&]$/,'');
                    history.replaceState(null, '', newUrl);
                }
            } catch(_) {}
            window.location.reload();
            return;
        }
        // Not from bfcache: ensure modal is closed and form cleared
        const checkupModal = document.getElementById('checkupModal');
        const checkupForm = document.getElementById('addCheckupForm');
        const checkupMsg = document.getElementById('checkupMsg');
        if (checkupModal) checkupModal.style.display = 'none';
        if (checkupForm) checkupForm.reset();
        if (checkupMsg) checkupMsg.textContent = '';
        // Also remove action param to prevent any future auto-open triggers
        try {
            const qs = new URLSearchParams(window.location.search);
            if (qs.has('action')) {
                qs.delete('action');
                const newUrl = `${location.pathname}?${qs.toString()}`.replace(/[?&]$/,'');
                history.replaceState(null, '', newUrl);
            }
        } catch(_) {}
    } catch(_) {}
});