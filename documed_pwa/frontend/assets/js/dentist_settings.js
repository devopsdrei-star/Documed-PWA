(function(){
  const profileMsg = document.getElementById('dnProfileMsg');
  const pwdMsg = document.getElementById('dnPwdMsg');
  const prefsMsg = document.getElementById('dnPrefsMsg');
  const nameEl = document.getElementById('dnName');
  const emailEl = document.getElementById('dnEmail');
  const roleEl = document.getElementById('dnRole');
  const photoEl = document.getElementById('dnPhoto');
  const saveProfileBtn = document.getElementById('dnSaveProfile');
  const pwdOld = document.getElementById('dnPwdOld');
  const pwdNew = document.getElementById('dnPwdNew');
  const pwdNew2 = document.getElementById('dnPwdNew2');
  const pwdBtn = document.getElementById('dnPwdBtn');
  const slotsEl = document.getElementById('dnSlots');
  const notifyEl = document.getElementById('dnNotifyEmail');
  const hoursTableRoot = document.getElementById('dnHoursTable');
  const hoursTbody = hoursTableRoot ? hoursTableRoot.querySelector('tbody') : null;
  const savePrefsBtn = document.getElementById('dnSavePrefs');
  // Closures
  const closuresTbody = document.querySelector('#dnClosuresTable tbody');
  const closureDateEl = document.getElementById('dnClosureDate');
  const closureReasonEl = document.getElementById('dnClosureReason');
  const addClosureBtn = document.getElementById('dnAddClosure');
  const closuresMsg = document.getElementById('dnClosuresMsg');
  // Announcements
  const annMsgEl = document.getElementById('dnAnnMessage');
  const annExpEl = document.getElementById('dnAnnExpires');
  const annPostBtn = document.getElementById('dnAnnPost');
  const annMsgOut = document.getElementById('dnAnnMsg');

  function getMe(){ try { return JSON.parse(localStorage.getItem('documed_docnurse')||'{}'); } catch(_){ return {}; } }
  const me = getMe();
  if (!me || !me.id) { /* Not logged in */ }

  function setMsg(el, ok, text){ if (!el) return; el.className = ok? 'text-success' : 'text-danger'; el.textContent = text; }

  async function loadProfile(){
    try {
      const res = await fetch(`../../backend/api/manage_user.php?action=get_doc_nurse_profile&id=${encodeURIComponent(me.id)}`);
      const data = await res.json();
      if (data && data.success && data.profile){
        nameEl && (nameEl.value = data.profile.name||'');
        emailEl && (emailEl.value = data.profile.email||'');
        roleEl && (roleEl.value = data.profile.role||'');
        // set preview/image if present
        const prev = document.getElementById('dnPhotoPreview');
        if (prev && (data.profile.dn_photo || data.profile.photo)) { prev.src = data.profile.dn_photo || data.profile.photo; }
      }
    } catch {}
  }

  async function saveProfile(){
    const form = new FormData();
    form.append('action','update_doc_nurse');
    form.append('id', String(me.id||''));
    form.append('name', nameEl.value.trim());
    form.append('email', emailEl.value.trim());
    form.append('role', roleEl.value.trim()||'Dentist');
    if (photoEl.files && photoEl.files[0]) form.append('photo', photoEl.files[0]);
    try {
      const res = await fetch('../../backend/api/manage_user.php', { method:'POST', body: form });
      const data = await res.json();
      if (data && data.success) {
        setMsg(profileMsg, true, 'Profile saved.');
        // Update local cache
        const u = getMe() || {};
        u.name = (nameEl && nameEl.value.trim()) || u.name;
        u.email = (emailEl && emailEl.value.trim()) || u.email;
        if (data.profile && (data.profile.dn_photo || data.profile.photo)) {
          u.dn_photo = data.profile.dn_photo || data.profile.photo;
          u.photo = u.dn_photo;
        }
        localStorage.setItem('documed_docnurse', JSON.stringify(u));
        // Update UI images if present
        const prev = document.getElementById('dnPhotoPreview');
        if (prev && u.dn_photo) { prev.src = u.dn_photo; }
        const btn = document.getElementById('dashboardProfileBtn');
        const img = btn ? btn.querySelector('img') : null;
        if (img && u.dn_photo) { img.src = u.dn_photo; }
      } else { setMsg(profileMsg, false, data.message||'Save failed'); }
    } catch { setMsg(profileMsg, false, 'Network error'); }
  }

  async function changePassword(){
    if (pwdNew.value !== pwdNew2.value) { setMsg(pwdMsg, false, 'Passwords do not match'); return; }
    const form = new URLSearchParams();
    form.set('action','change_password'); form.set('id', String(me.id||'')); form.set('oldPassword', pwdOld.value); form.set('newPassword', pwdNew.value);
    try {
      const res = await fetch('../../backend/api/manage_user.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()});
      const data = await res.json();
      setMsg(pwdMsg, data && data.success, (data && data.success) ? 'Password updated' : (data.message||'Update failed'));
      if (data && data.success) { pwdOld.value=''; pwdNew.value=''; pwdNew2.value=''; }
    } catch { setMsg(pwdMsg, false, 'Network error'); }
  }

  const days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  function renderHours(hours){
    if (!hoursTbody) return; // Not on settings page
    hoursTbody.innerHTML = '';
    days.forEach(d => {
      const h = (hours && hours[d]) || { start: '08:00', end: '17:00', closed: false };
      const tr = document.createElement('tr');
      tr.innerHTML = `<td style="width:110px;">${d}</td>
        <td><input class="form-control" type="time" value="${h.start}" data-day="${d}" data-k="start"></td>
        <td><input class="form-control" type="time" value="${h.end}" data-day="${d}" data-k="end"></td>
        <td class="text-center"><input class="form-check-input" type="checkbox" ${h.closed? 'checked':''} data-day="${d}" data-k="closed"></td>`;
      hoursTbody.appendChild(tr);
    });
  }

  async function loadPrefs(){
    try {
      const res = await fetch(`../../backend/api/dentist_prefs.php?action=get&id=${encodeURIComponent(me.id)}`);
      const data = await res.json();
      if (data && data.success) {
        if (slotsEl) slotsEl.value = data.prefs && data.prefs.slot_per_hour ? data.prefs.slot_per_hour : 2;
        if (notifyEl) notifyEl.checked = !!(data.prefs && Number(data.prefs.notify_email));
        let h = {}; try { h = data.prefs && data.prefs.hours ? JSON.parse(data.prefs.hours) : {}; } catch(_){ h = {}; }
        renderHours(h);
      } else { renderHours({}); }
    } catch { renderHours({}); }
  }

  async function savePrefs(){
    // Build hours object from table
    if (!hoursTbody) { return; }
    const inputs = hoursTbody.querySelectorAll('input');
    const h = {}; inputs.forEach(inp => { const d=inp.getAttribute('data-day'); const k=inp.getAttribute('data-k'); if(!h[d]) h[d]={start:'08:00',end:'17:00',closed:false}; if(k==='closed'){ h[d][k] = inp.checked; } else { h[d][k] = inp.value; } });
    const form = new URLSearchParams();
    form.set('action','save'); form.set('id', String(me.id||''));
    form.set('hours', JSON.stringify(h)); form.set('slot_per_hour', String(parseInt((slotsEl && slotsEl.value)||'2',10)||2));
    form.set('notify_email', (notifyEl && notifyEl.checked)? '1':'0');
    try {
      const res = await fetch('../../backend/api/dentist_prefs.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()});
      const data = await res.json();
      setMsg(prefsMsg, data && data.success, (data && data.success) ? 'Preferences saved.' : (data.message||'Save failed'));
    } catch { setMsg(prefsMsg, false, 'Network error'); }
  }

  // wire
  document.getElementById('dnSaveProfile')?.addEventListener('click', saveProfile);
  document.getElementById('dnPwdBtn')?.addEventListener('click', changePassword);
  if (savePrefsBtn) { savePrefsBtn.addEventListener('click', savePrefs); }

  // Closures helpers
  function renderClosures(rows){
    if (!closuresTbody) return;
    closuresTbody.innerHTML = '';
    (rows||[]).forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${r.date}</td><td>${(r.reason||'')}</td>
        <td><button class="btn btn-sm btn-outline-danger" data-id="${r.id}">Delete</button></td>`;
      closuresTbody.appendChild(tr);
    });
  }
  async function loadClosures(){
    if (!closuresTbody) return;
    try {
      const res = await fetch(`../../backend/api/dentist_prefs.php?action=closures_list&id=${encodeURIComponent(me.id)}`);
      const data = await res.json();
      renderClosures(data && data.success ? (data.rows||[]) : []);
    } catch { renderClosures([]); }
  }
  async function addClosure(){
    if (!closureDateEl || !closureReasonEl) return;
    const d = (closureDateEl.value||'').trim();
    const r = (closureReasonEl.value||'').trim();
    if (!d) { setMsg(closuresMsg, false, 'Pick a date'); return; }
    const form = new URLSearchParams();
    form.set('action','closures_add'); form.set('id', String(me.id||'')); form.set('date', d); form.set('reason', r);
    try {
      const res = await fetch('../../backend/api/dentist_prefs.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()});
      const data = await res.json();
      setMsg(closuresMsg, data && data.success, data && data.success ? 'Added.' : (data.message||'Failed'));
      if (data && data.success) { closureDateEl.value=''; closureReasonEl.value=''; loadClosures(); }
    } catch { setMsg(closuresMsg, false, 'Network error'); }
  }
  closuresTbody?.addEventListener('click', async (e)=>{
    const btn = e.target.closest('button[data-id]'); if (!btn) return;
    const id = btn.getAttribute('data-id');
    const form = new URLSearchParams(); form.set('action','closures_delete'); form.set('id', String(me.id||'')); form.set('closure_id', id);
    try { const res = await fetch('../../backend/api/dentist_prefs.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()}); const data = await res.json(); if (data && data.success) loadClosures(); else setMsg(closuresMsg,false,data.message||'Delete failed'); } catch { setMsg(closuresMsg,false,'Network error'); }
  });
  addClosureBtn?.addEventListener('click', addClosure);

  // Dentist announcement (all roles) with expiration
  async function postAnnouncement(){
    const msg = (annMsgEl && annMsgEl.value.trim()) || '';
    const exp = (annExpEl && annExpEl.value) || '';
    if (!msg) { setMsg(annMsgOut, false, 'Message required'); return; }
    const form = new URLSearchParams();
    form.set('action','announcements_post');
    form.set('audience','All');
    form.set('message', msg);
    if (exp) form.set('expires_at', exp);
    try {
      const res = await fetch('../../backend/api/admin_tools.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()});
      const data = await res.json();
      setMsg(annMsgOut, data && data.success, data && data.success ? 'Announcement posted.' : (data.message||'Failed'));
      if (data && data.success) { annMsgEl.value=''; annExpEl.value=''; }
    } catch { setMsg(annMsgOut, false, 'Network error'); }
  }
  annPostBtn?.addEventListener('click', postAnnouncement);

  // init
  loadProfile();
  if (hoursTbody) { loadPrefs(); }
  loadClosures();
})();
