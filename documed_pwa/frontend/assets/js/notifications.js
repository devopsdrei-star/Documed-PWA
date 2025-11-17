// notifications.js
(function(){
  // Sound setup (enabled after first user interaction anywhere)
  let soundEnabled = false;
  let lastUnreadCount = 0;
  let badgeInit = false;
  function playDing(){
    try{
      if (!soundEnabled) return;
      const Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return;
      const ctx = playDing._ctx || (playDing._ctx = new Ctx());
      if (ctx.state === 'suspended') { ctx.resume().catch(()=>{}); }
      const osc = ctx.createOscillator(); const gain = ctx.createGain();
      osc.type = 'sine'; osc.frequency.value = 880; // A5
      osc.connect(gain); gain.connect(ctx.destination);
      gain.gain.setValueAtTime(0.0001, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
      osc.start(); osc.stop(ctx.currentTime + 0.26);
    } catch {}
  }
  // Unread tracking persisted per user so read items don't reappear after logout/login
  function currentUserIds(){
    try {
      const u = JSON.parse(localStorage.getItem('documed_user')||'null') || {};
      const sid = (u.student_faculty_id || u.student_facultyId || u.sid || u.school_id || '').toString().trim();
      const email = (u.email || '').toString().trim().toLowerCase();
      const id = (u.id || '').toString().trim();
      return { sid, email, id };
    } catch { return { sid:'', email:'', id:'' }; }
  }
  function candidateReadKeys(){
    const { sid, email, id } = currentUserIds();
    const keys = [];
    if (sid) keys.push(`documed_notif_read_${sid}`);
    if (email) keys.push(`documed_notif_read_${email}`);
    if (id) keys.push(`documed_notif_read_${id}`);
    if (!keys.length) keys.push('documed_notif_read_guest');
    return Array.from(new Set(keys));
  }
  function readKey(){
    // Prefer SID, then email, then numeric id, else guest
    return candidateReadKeys()[0];
  }
  // Time helpers: convert 24h to 12h with AM/PM
  function fmt12FromHHMM(s){
    if (!s || typeof s !== 'string') return s || '';
    const m = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if (!m) return s;
    let h = parseInt(m[1],10);
    const mm = m[2];
    const am = h < 12;
    if (h === 0) h = 12;
    if (h > 12) h -= 12;
    return `${h}:${mm} ${am ? 'AM' : 'PM'}`;
  }
  function fmtDateTimeLabel(s){
    if (!s || typeof s !== 'string') return s || '';
    const m = s.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2}(?::\d{2})?)/);
    if (m) {
      return `${m[1]} ${fmt12FromHHMM(m[2])}`;
    }
    return s;
  }
  function loadReadSet(){
    // Union reads from all candidate keys for the current user (helps when identifier choice changes)
    const keys = candidateReadKeys();
    const union = new Set();
    keys.forEach(k => {
      try {
        const arr = JSON.parse(localStorage.getItem(k) || '[]');
        if (Array.isArray(arr)) arr.forEach(x => union.add(x));
      } catch {}
    });
    return union;
  }
  function saveReadSet(set){
    const arr = JSON.stringify(Array.from(set));
    candidateReadKeys().forEach(k => { try { localStorage.setItem(k, arr); } catch(_){} });
  }
  function keyFor(type, id, created){ return [type||'', String(id||''), String(created||'')].join('|'); }
  function markRead(type, id, created){ const s = loadReadSet(); s.add(keyFor(type,id,created)); saveReadSet(s); }
  function stableThirdForAppt(a){
    try { return ((a && a.status) ? String(a.status).toLowerCase() : 'state'); } catch(_) { return 'state'; }
  }
  function renderBell(container){
    if (!container) return;
      // Try to reuse existing bell
      let bellBtn = container.querySelector('#notifBellBtn');
      if (!bellBtn) {
        const existingBell = container.querySelector('button[title="Notifications"], .bi-bell');
        if (existingBell) {
          bellBtn = existingBell.closest('button') || existingBell;
        }
      }
      if (!bellBtn) {
        bellBtn = document.createElement('button');
        bellBtn.className = 'btn';
        bellBtn.title = 'Notifications';
        bellBtn.innerHTML = '<i class="bi bi-bell"></i>';
        bellBtn.style.background = 'none';
        bellBtn.style.color = '#2563eb';
        bellBtn.style.fontSize = '1.7rem';
        container.prepend(bellBtn);
      }
      bellBtn.id = 'notifBellBtn';
      // Add unread badge if not present
      if (!bellBtn.querySelector('.notif-badge')){
        const b = document.createElement('span');
        b.className = 'notif-badge';
        b.textContent = '0';
        bellBtn.style.position = 'relative';
        bellBtn.appendChild(b);
      }
      // Create dropdown container if not present
      if (!container.querySelector('#notifDropdown')){
        const dd = document.createElement('div');
        dd.id = 'notifDropdown';
        dd.className = 'dropdown-menu dropdown-menu-end p-0';
        dd.style.minWidth = '320px';
        dd.style.maxWidth = '420px';
        dd.innerHTML = '<div style="padding:12px 14px; border-bottom:1px solid #eef2ff; font-weight:600; color:#2563eb;">Notifications</div>' +
          '<div id="notifList" style="max-height:360px; overflow:auto;"></div>' +
          '<div class="text-center small text-muted py-2">End • <a href="#" id="markAllReadBtn" class="text-decoration-none">Mark all as read</a></div>';
        // Positioning container
        const holder = document.createElement('div');
        holder.className = 'd-inline-block me-2 position-relative';
        bellBtn.parentNode.insertBefore(holder, bellBtn);
        holder.appendChild(bellBtn);
        holder.appendChild(dd);
      }
  }

  function formatAppt(ap){
    const status = (ap.status||'').toLowerCase();
    let pillColor = '#6b7280';
    if (status==='accepted') pillColor = '#22c55e';
    else if (status==='declined' || status==='cancelled') pillColor = '#ef4444';
    else if (status==='completed' || status==='rescheduled') pillColor = '#0ea5e9';
    const when = [ap.date, fmt12FromHHMM(ap.time||'')].filter(Boolean).join(' ');
    const createdDisplay = ap.created_at || '';
    const createdKey = stableThirdForAppt(ap);
    const unread = !loadReadSet().has(keyFor('appt', ap.id, createdKey));
    return `<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="appt" data-id="${ap.id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-purpose="${(ap.purpose||'Appointment').replace(/"/g,'&quot;')}" data-date="${ap.date||''}" data-time="${ap.time||''}" data-status="${status}" style="background:${unread?'#eef2ff':'#fff'}; cursor:pointer;">
      <div style="display:flex; gap:10px; align-items:flex-start;">
        <div style="color:#2563eb; font-size:1.2rem"><i class="bi bi-calendar-check"></i></div>
        <div style="flex:1;">
          <div style="font-weight:600; color:#111">Dental booking ${ap.id ? '#'+ap.id : ''}</div>
          <div class="small text-muted">${ap.purpose || 'Appointment'} • ${when}</div>
          <div class="small" style="margin-top:6px;">
            <span style="display:inline-block; padding:2px 8px; border-radius:999px; color:#fff; background:${pillColor}; text-transform:capitalize">${status||'scheduled'}</span>
          </div>
          ${unread? '<div class="small" style="color:#1d4ed8;margin-top:6px;">Unread</div>':''}
        </div>
      </div>
    </div>`;
  }

  function formatAnn(a){
    const createdDisplay = a.created_at || '';
    const createdKey = 'ann';
    const unread = !loadReadSet().has(keyFor('ann', a.id, createdKey));
    return `<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="ann" data-id="${a.id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-message="${(a.message||'').replace(/"/g,'&quot;')}" style="background:${unread?'#eef2ff':'#f8fafc'}; cursor:pointer;">
      <div style="display:flex; gap:10px; align-items:flex-start;">
        <div style="color:#2563eb; font-size:1.2rem"><i class="bi bi-megaphone"></i></div>
        <div style="flex:1;">
          <div style="font-weight:600; color:#111">Announcement</div>
          <div class="small">${(a.message||'').replace(/</g,'&lt;')}</div>
          <div class="small text-muted mt-1">${fmtDateTimeLabel(createdDisplay)}</div>
          ${unread? '<div class="small" style="color:#1d4ed8;margin-top:6px;">Unread</div>':''}
        </div>
      </div>
    </div>`;
  }

  async function loadNotifications(){
    // require user email
    let email = '';
    try { const u = JSON.parse(localStorage.getItem('documed_user')); email = u && u.email || ''; } catch(_) {}
    if (!email) return {appointments:[], announcements:[]};
    try {
      const res = await fetch(`../../backend/api/appointments_new.php?action=notifications&email=${encodeURIComponent(email)}&limit=20`);
      const data = await res.json();
      if (!data.success) return {appointments:[], announcements:[], events:[]};
      return {appointments: data.appointments||[], announcements: data.announcements||[], events: data.events||[]};
    } catch(err){
      return {appointments:[], announcements:[], events:[]};
    }
  }

  async function loadFollowUpsForUser(){
    try {
      const u = JSON.parse(localStorage.getItem('documed_user')||'null');
      const sid = u && (u.student_faculty_id || u.school_id || u.sid) || '';
      if (!sid) return [];
      const res = await fetch(`../../backend/api/checkup.php?action=list&student_faculty_id=${encodeURIComponent(sid)}`);
      const dj = await res.json();
      const list = Array.isArray(dj.checkups)? dj.checkups : [];
      return list;
    } catch { return []; }
  }

  // Build reminder items for upcoming follow-up (check-up) and upcoming appointments
  function buildReminders(raw, followupsList){
    const items = [];
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const inDays = (d)=> Math.round((new Date(d).getTime() - today.getTime())/86400000);
    // Appointment reminders: show if scheduled/accepted within next 3 days
    (raw.appointments||[]).forEach(ap=>{
      const st=(ap.status||'').toLowerCase();
      if (st==='declined' || st==='cancelled' || st==='completed') return;
      const date=(ap.date||''); if(!date) return; const dd=inDays(date);
      if (dd>=0 && dd<=3){
        const created = `${date}T00:00:00`;
        const unread = !loadReadSet().has(keyFor('rem_appt', ap.id, created));
        items.push({
          kind:'html',
          created,
          html:`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="rem_appt" data-id="${ap.id||''}" data-created="${created}" style="background:${unread?'#ecfeff':'#fff'}; cursor:pointer;">
            <div style="display:flex; gap:10px; align-items:flex-start;">
              <div style="color:#0891b2; font-size:1.2rem"><i class="bi bi-bell"></i></div>
              <div style="flex:1;">
                <div style="font-weight:600; color:#111">Upcoming appointment</div>
                <div class="small text-muted">${ap.purpose||'Appointment'} • ${ap.date} ${fmt12FromHHMM(ap.time||'')}</div>
                <div class="small">${dd===0?'Today':`In ${dd} day${dd===1?'':'s'}`}</div>
                ${unread? '<div class="small" style="color:#0e7490;margin-top:6px;">Unread</div>':''}
              </div>
            </div>
          </div>`
        });
      }
    });
    // Follow-up reminders: compute from medical history (followupsList) and also accept backend events.
    (followupsList||[]).forEach(p=>{
      const due = (p.follow_up && String(p.follow_up) !== '0');
      const d = p.follow_up_date || '';
      if (!due || !d) return; const dd = inDays(d); if (dd<0 || dd>3) return;
      const created = `${d}T00:00:00`;
      const unread = !loadReadSet().has(keyFor('followup_due', p.id, created));
      items.push({ kind:'html', created, html:`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="followup_due" data-id="${p.id||''}" data-created="${created}" style="background:${unread?'#ecfccb':'#fff'}; cursor:pointer;">
        <div style="display:flex; gap:10px; align-items:flex-start;">
          <div style="color:#65a30d; font-size:1.2rem"><i class="bi bi-clipboard2-pulse"></i></div>
          <div style="flex:1;">
            <div style="font-weight:600; color:#111">Follow-up check-up ${dd===0?'today':`in ${dd} day${dd===1?'':'s'}`}</div>
            <div class="small text-muted">${d}</div>
            ${unread? '<div class="small" style="color:#3f6212;margin-top:6px;">Unread</div>':''}
          </div>
        </div>
      </div>`});
    });
    // Also include backend-sent followup_due events, if any
    (raw.events||[]).forEach(ev=>{
      if (ev.type!=='followup_due') return; const created=ev.created_at||''; const unread = !loadReadSet().has(keyFor('followup_due', ev.id||ev.appointment_id||'', created));
      items.push({ kind:'html', created, html:`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="followup_due" data-id="${ev.id||ev.appointment_id||''}" data-created="${created}" style="background:${unread?'#ecfccb':'#fff'}; cursor:pointer;">
        <div style="display:flex; gap:10px; align-items:flex-start;">
          <div style="color:#65a30d; font-size:1.2rem"><i class="bi bi-clipboard2-pulse"></i></div>
          <div style="flex:1;">
            <div style="font-weight:600; color:#111">Follow-up check-up due soon</div>
            <div class="small text-muted">${ev.date||''} ${ev.time?fmt12FromHHMM(ev.time):''}</div>
            ${unread? '<div class="small" style="color:#3f6212;margin-top:6px;">Unread</div>':''}
          </div>
        </div>
      </div>` });
    });
    return items;
  }

  function toggleDropdown(open){
    const dd = document.getElementById('notifDropdown');
    if (!dd) return;
    dd.classList.toggle('show', !!open);
    if (open) {
      // position relative to button
      const btn = document.getElementById('notifBellBtn');
      if (btn) {
        const r = btn.getBoundingClientRect();
        dd.style.position = 'absolute';
        dd.style.top = (btn.offsetTop + btn.offsetHeight + 6) + 'px';
        dd.style.right = '0px';
      }
    }
  }

  // Enable sound after any user gesture to satisfy autoplay policies
  function enableSound(){
    if (soundEnabled) return;
    soundEnabled = true;
    try { if (playDing._ctx && playDing._ctx.state==='suspended') playDing._ctx.resume(); } catch {}
    document.removeEventListener('click', enableSound, true);
    document.removeEventListener('touchstart', enableSound, true);
  }
  document.addEventListener('click', enableSound, true);
  document.addEventListener('touchstart', enableSound, true);

  document.addEventListener('DOMContentLoaded', async function(){
    // Ensure Bootstrap Icons
    if (!document.querySelector('link[href*="bootstrap-icons"]')){
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css';
      document.head.appendChild(link);
    }
    const navActions = document.getElementById('navActions');
    // Only show bell if logged in
    const isLoggedIn = sessionStorage.getItem('documedLoggedIn') === '1';
    if (isLoggedIn) {
      renderBell(navActions);
    }
    document.body.addEventListener('click', async function(e){
      // Gate bell interactions when logged out
      if ((e.target && (e.target.id==='notifBellBtn' || e.target.closest('#notifBellBtn')))){
        const loggedIn = sessionStorage.getItem('documedLoggedIn') === '1';
        if (!loggedIn) return;
        // Ensure sounds are enabled due to this interaction
        enableSound();
        e.preventDefault();
        const list = document.getElementById('notifList');
        if (list) { list.innerHTML = '<div class="p-3 text-muted">Loading...</div>'; }
        toggleDropdown(true);
  const data = await loadNotifications();
  const followups = await loadFollowUpsForUser();
  const items = [];
  // Newest first: sort by created_at desc when available
  const appts = (data.appointments||[]).slice().sort((a,b)=> new Date(b.created_at||0)-new Date(a.created_at||0));
  const anns  = (data.announcements||[]).slice().sort((a,b)=> new Date(b.created_at||0)-new Date(a.created_at||0));
  appts.forEach(a => items.push(formatAppt(a)));
  anns.forEach(a => items.push(formatAnn(a)));
  buildReminders(data, followups).forEach(o=> items.push(o.html));
        (data.events||[]).forEach(ev => {
          if (ev.type === 'reschedule_window') {
            const createdDisplay = ev.created_at || '';
            const createdKey = 'reschedule_window';
            const unread = !loadReadSet().has(keyFor('reschedule_window', ev.appointment_id, createdKey));
            const start = (Array.isArray(ev.range) && ev.range[0]) || '';
            const end = (Array.isArray(ev.range) && ev.range[1]) || '';
            items.push(`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="reschedule_window" data-id="${ev.appointment_id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-purpose="${(ev.purpose||'Appointment').replace(/"/g,'&quot;')}" data-start="${start}" data-end="${end}" style="background:${unread?'#fff7ed':'#fff'}; cursor:pointer;">
              <div style="display:flex; gap:10px; align-items:flex-start;">
                <div style="color:#c2410c; font-size:1.2rem"><i class="bi bi-calendar-range"></i></div>
                <div style="flex:1;">
                  <div style="font-weight:600; color:#111">Pick your new schedule</div>
                  <div class="small text-muted">${(ev.purpose||'Appointment')}</div>
                  <div class="small">Range: ${start} to ${end}</div>
                  <div class="small text-muted mt-1">${fmtDateTimeLabel(createdDisplay)}</div>
                  ${unread? '<div class="small" style="color:#b45309;margin-top:6px;">Unread</div>':''}
                </div>
              </div>
            </div>`);
            return;
          }
          if (ev.type === 'rescheduled') {
            const createdDisplay = ev.created_at || '';
            const createdKey = 'rescheduled';
            const unread = !loadReadSet().has(keyFor('rescheduled', ev.appointment_id, createdKey));
            items.push(`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="rescheduled" data-id="${ev.appointment_id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-purpose="${(ev.purpose||'Appointment').replace(/"/g,'&quot;')}" data-old="${[ev.old_date, ev.old_time].filter(Boolean).join(' ')}" data-new="${ev.new_when||''}" style="background:${unread?'#eef2ff':'#fff'}; cursor:pointer;">
              <div style="display:flex; gap:10px; align-items:flex-start;">
                <div style="color:#2563eb; font-size:1.2rem"><i class="bi bi-arrow-repeat"></i></div>
                <div style="flex:1;">
                  <div style="font-weight:600; color:#111">Appointment rescheduled #${ev.appointment_id||''}</div>
                  <div class="small text-muted">${ev.purpose||'Appointment'}</div>
                  <div class="small">Old: ${[ev.old_date, ev.old_time ? fmt12FromHHMM(ev.old_time) : ''].filter(Boolean).join(' ')}</div>
                  <div class="small">New: ${ev.new_when? fmtDateTimeLabel(ev.new_when):'-'}</div>
                  <div class="small text-muted mt-1">${fmtDateTimeLabel(createdDisplay)}</div>
                  ${unread? '<div class="small" style="color:#1d4ed8;margin-top:6px;">Unread</div>':''}
                </div>
              </div>
            </div>`);
          } else if (ev.type === 'cancelled' || ev.type === 'declined') {
            const icon = ev.type==='cancelled' ? 'bi-x-circle' : 'bi-hand-thumbs-down';
            const createdDisplay = ev.created_at || '';
            const createdKey = ev.type;
            const unread = !loadReadSet().has(keyFor(ev.type, ev.appointment_id, createdKey));
            items.push(`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="${ev.type}" data-id="${ev.appointment_id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-reason="${(ev.reason||'').replace(/"/g,'&quot;')}" data-purpose="${(ev.purpose||'Appointment').replace(/"/g,'&quot;')}" style="background:${unread?'#fee2e2':'#fff'}; cursor:pointer;">
              <div style="display:flex; gap:10px; align-items:flex-start;">
                <div style="color:#ef4444; font-size:1.2rem"><i class="bi ${icon}"></i></div>
                <div style="flex:1;">
                  <div style="font-weight:600; color:#111">Appointment ${ev.type} #${ev.appointment_id||''}</div>
                  <div class="small text-muted">${(ev.purpose||'Appointment')}</div>
                  <div class="small text-muted mt-1">${fmtDateTimeLabel(createdDisplay)}</div>
                  ${unread? '<div class="small" style="color:#b91c1c;margin-top:6px;">Unread</div>':''}
                </div>
              </div>
            </div>`);
          } else if (ev.type === 'accepted') {
            const createdDisplay = ev.created_at || '';
            const createdKey = 'accepted';
            const unread = !loadReadSet().has(keyFor('accepted', ev.appointment_id, createdKey));
            items.push(`<div class="p-3 border-bottom notif-item ${unread?'unread':''}" data-type="accepted" data-id="${ev.appointment_id||''}" data-created="${createdDisplay}" data-keycreated="${createdKey}" data-purpose="${(ev.purpose||'Appointment').replace(/"/g,'&quot;')}" style="background:${unread?'#dcfce7':'#fff'}; cursor:pointer;">
              <div style="display:flex; gap:10px; align-items:flex-start;">
                <div style="color:#16a34a; font-size:1.2rem"><i class="bi bi-check-circle"></i></div>
                <div style="flex:1;">
                  <div style="font-weight:600; color:#111">Appointment accepted #${ev.appointment_id||''}</div>
                  <div class="small text-muted">${(ev.purpose||'Appointment')}</div>
                  <div class="small text-muted mt-1">${fmtDateTimeLabel(createdDisplay)}</div>
                  ${unread? '<div class="small" style="color:#166534;margin-top:6px;">Unread</div>':''}
                </div>
              </div>
            </div>`);
          }
        });
        if (list) {
          list.innerHTML = items.length ? items.join('') : '<div class="p-3 text-center text-muted">No notifications</div>';
        }
        // Update unread badge
        const badge = document.querySelector('#notifBellBtn .notif-badge');
        if (badge){
          const currentRead = loadReadSet();
          const container = document.getElementById('notifDropdown');
          const count = (container ? container.querySelectorAll('.notif-item.unread').length : 0) || 0;
          if (count > 0) { badge.textContent = String(count); badge.style.display = 'inline-block'; }
          else { badge.style.display = 'none'; }
        }
      } else {
        // Mark all as read handler
        if (e.target && e.target.id === 'markAllReadBtn'){
          e.preventDefault();
          const dd = document.getElementById('notifDropdown'); if (!dd) return;
          const items = dd.querySelectorAll('.notif-item');
          items.forEach(el=>{
            const t = el.getAttribute('data-type');
            const id = el.getAttribute('data-id');
            const keyc = el.getAttribute('data-keycreated') || el.getAttribute('data-created') || '';
            if (t && id) {
              markRead(t, id, keyc);
            }
            el.classList.remove('unread');
            el.style.background = (t==='ann') ? '#f8fafc' : '#fff';
          });
          const badge=document.querySelector('#notifBellBtn .notif-badge'); if (badge){ badge.style.display='none'; }
          lastUnreadCount = 0; // reset counter
          return;
        }
        // Close when clicking outside dropdown
        const dd = document.getElementById('notifDropdown');
        if (dd && dd.classList.contains('show') && !e.target.closest('#notifDropdown')){
          // Don't close the notification panel when a modal is open or when clicking inside a modal
          if (document.body.classList.contains('modal-open') || e.target.closest('.modal') || e.target.classList.contains('modal-backdrop')) {
            return;
          }
          if (!e.target.closest('#notifBellBtn')) toggleDropdown(false);
        }
      }
    });
  });

  // Simple modal for showing reasons/details
  function ensureReasonModal(){
    if (document.getElementById('notifReasonModal')) return;
    const el = document.createElement('div');
    el.innerHTML = `
    <div class="modal" tabindex="-1" id="notifReasonModal">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title" id="notifReasonTitle">Notification</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="small text-muted" id="notifReasonWhen"></div>
            <div id="notifReasonBody" class="mt-2"></div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
      </div>
    </div>`;
    document.body.appendChild(el.firstElementChild);
  }

  // Modal that lets the user pick a new schedule within 3-day window
  function ensureReschedPickModal(){
    if (document.getElementById('notifReschedPickModal')) return;
    const el = document.createElement('div');
    el.innerHTML = `
    <div class="modal" tabindex="-1" id="notifReschedPickModal">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Pick your new schedule</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="small text-muted" id="reschedWindowRange"></div>
            <div id="reschedWindowDays" class="mt-2"></div>
            <div class="row g-2 mt-2">
              <div class="col-6">
                <label class="form-label">Date</label>
                <select id="reschedPickDate" class="form-select"></select>
              </div>
              <div class="col-6">
                <label class="form-label">Time</label>
                <select id="reschedPickTime" class="form-select"></select>
              </div>
            </div>
            <div id="reschedPickError" class="small text-danger mt-2"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="reschedPickConfirm">Confirm</button>
          </div>
        </div>
      </div>
    </div>`;
    document.body.appendChild(el.firstElementChild);
  }

  document.addEventListener('click', function(e){
    const item = e.target.closest('.notif-item');
    if (!item) return;
    const type = item.getAttribute('data-type');
  const createdDisplay = item.getAttribute('data-created') || '';
  const keyCreated = item.getAttribute('data-keycreated') || createdDisplay;
    const reason = item.getAttribute('data-reason') || '';
    if (type==='cancelled' || type==='declined'){
      ensureReasonModal();
      const title = document.getElementById('notifReasonTitle');
      const when = document.getElementById('notifReasonWhen');
      const body = document.getElementById('notifReasonBody');
      const purpose = item.getAttribute('data-purpose') || '';
  if (title) title.textContent = `Appointment ${type}`;
  if (when) when.textContent = fmtDateTimeLabel(createdDisplay);
      body.innerHTML = `<div><strong>${type==='cancelled'?'Cancellation':'Decline'} reason</strong></div>
        <div class="small text-muted">${purpose||''}</div>
        <div style="margin-top:6px;">${reason ? reason.replace(/</g,'&lt;') : 'No reason provided.'}</div>`;
      if (window.bootstrap && bootstrap.Modal){
        const m = new bootstrap.Modal(document.getElementById('notifReasonModal'));
        m.show();
      } else {
        // Fallback if Bootstrap JS not loaded on this page
        alert((type==='cancelled'?'Cancellation':'Decline')+" reason:\n\n" + (reason||'No reason provided.'));
      }
      // mark read
  markRead(type, item.getAttribute('data-id'), keyCreated);
  item.classList.remove('unread');
  item.style.background = '#fff';
  const badge = document.querySelector('#notifBellBtn .notif-badge');
  if (badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if (n>0) badge.textContent = String(n); }
    } else if (type==='reschedule_window'){
      // Open 3-day selection modal
      ensureReschedPickModal();
      const baseUrl = window.location.pathname.split('/frontend/')[0];
      const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
      const apptId = item.getAttribute('data-id');
      const start = item.getAttribute('data-start')||'';
      const end = item.getAttribute('data-end')||'';
      const range = document.getElementById('reschedWindowRange');
      const days = document.getElementById('reschedWindowDays');
      const dateSel = document.getElementById('reschedPickDate');
      const timeSel = document.getElementById('reschedPickTime');
      const err = document.getElementById('reschedPickError');
      // Build dates
      const dates = [];
      if (start){
        const d0 = new Date(start);
        for (let i=0;i<3;i++){ const d = new Date(d0); d.setDate(d0.getDate()+i); dates.push(d.toISOString().slice(0,10)); }
      }
      if (range) range.textContent = start && end ? `You can pick between ${start} and ${end}.` : '';
      if (dateSel) dateSel.innerHTML = dates.map(d=>`<option value="${d}">${d}</option>`).join('');
      // Helper to format 12hr
      function fmt12(t){ const [hh,mm]=t.split(':'); let h=parseInt(hh,10); const am=h<12; if(h===0)h=12; if(h>12)h-=12; return `${h}:${mm} ${am?'AM':'PM'}`; }
      async function loadDay(date){
        try{
          const f = new URLSearchParams(); f.set('action','check_slots'); f.set('date', date);
          const r = await fetch(apiUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: f.toString()});
          const dj = await r.json();
          const booked = dj.success ? (dj.booked_slots||{}) : {};
          const tags=[];
          for (let h=8; h<=17; h++){
            for (let m of [0,30]){
              const hh=String(h).padStart(2,'0'); const mm=String(m).padStart(2,'0'); const t=`${hh}:${mm}`;
              const full=(booked[t]||0)>=2; tags.push(`<span class=\"badge ${full?'bg-secondary':'bg-success'}\" style=\"margin:2px;\">${fmt12(t)}${full?' (full)':''}</span>`);
            }
          }
          const block = document.createElement('div');
          block.className = 'small';
          block.innerHTML = `<div><strong>${date}</strong></div><div class=\"mt-1\">${tags.join(' ')}</div>`;
          days.appendChild(block);
        } catch {}
      }
      async function loadTimes(){
        if (!timeSel) return; const d = (dateSel?.value||'').trim(); timeSel.innerHTML='<option value="">-- select time --</option>'; if (!d) return;
        try{
          const f = new URLSearchParams(); f.set('action','check_slots'); f.set('date', d);
          const r = await fetch(apiUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: f.toString()});
          const dj = await r.json(); const booked = dj.success?(dj.booked_slots||{}):{};
          for (let h=8; h<=17; h++){
            for (let m of [0,30]){
              const hh=String(h).padStart(2,'0'); const mm=String(m).padStart(2,'0'); const t=`${hh}:${mm}`;
              const full=(booked[t]||0)>=2; const opt=document.createElement('option'); opt.value=t; opt.textContent = `${fmt12(t)}${full?' (full)':''}`; if (full) opt.disabled = true; timeSel.appendChild(opt);
            }
          }
        } catch {}
      }
      // Reset and load previews
      if (days) days.innerHTML = '';
      (async()=>{ for (const d of dates){ await loadDay(d);} })();
      loadTimes();
      if (dateSel) dateSel.addEventListener('change', loadTimes, { once:false });
      if (document.getElementById('reschedPickConfirm')){
        const confirmBtn = document.getElementById('reschedPickConfirm');
        confirmBtn.onclick = async ()=>{
          err.textContent=''; const d=(dateSel?.value||'').trim(); const t=(timeSel?.value||'').trim(); if (!d||!t){ err.textContent='Select a date and time.'; return; }
          try{
            const form = new URLSearchParams(); form.set('action','user_select_reschedule'); form.set('id', String(apptId||'')); form.set('date', d); form.set('time', t);
            const resp = await fetch(apiUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString()});
            const dj = await resp.json(); if (!dj.success){ err.textContent = dj.message||'Failed to select time'; return; }
            // Close modal
            const modalEl = document.getElementById('notifReschedPickModal');
            try {
              if (window.bootstrap && bootstrap.Modal) {
                const inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                if (inst && typeof inst.hide === 'function') inst.hide();
              } else if (modalEl) {
                modalEl.classList.remove('show'); modalEl.setAttribute('aria-hidden','true'); modalEl.style.display='none';
                const bd = document.querySelector('.modal-backdrop'); if (bd) bd.remove();
              }
            } catch {}
            // Mark read
            markRead(type, apptId, created); item.classList.remove('unread'); item.style.background='#fff';
            // Navigate or refresh appointments page
            const userApptUrl = `${baseUrl}/frontend/user/appointments.html`;
            const path = window.location.pathname || '';
            if (path.endsWith('/frontend/user/appointments.html')) {
              window.location.reload();
            } else {
              window.location.href = userApptUrl;
            }
          } catch { err.textContent='Network error'; }
        };
      }
      if (window.bootstrap && bootstrap.Modal){ const m = new bootstrap.Modal(document.getElementById('notifReschedPickModal')); m.show(); }
      else { alert(`Pick a new schedule between ${start} and ${end} on your Appointments page.`); }
    } else if (type==='rescheduled'){
      ensureReasonModal();
      const title = document.getElementById('notifReasonTitle');
      const when = document.getElementById('notifReasonWhen');
      const body = document.getElementById('notifReasonBody');
      if (title) title.textContent = 'Appointment rescheduled';
  if (when) when.textContent = fmtDateTimeLabel(createdDisplay);
      const oldTxt = item.getAttribute('data-old')||'';
      const newTxt = item.getAttribute('data-new')||'';
      const oldTxt12 = fmtDateTimeLabel(oldTxt);
      const newTxt12 = fmtDateTimeLabel(newTxt);
      const pur = item.getAttribute('data-purpose')||'';
      body.innerHTML = `<div><strong>${pur||'Appointment'}</strong></div>
        <div class="small">Old: ${oldTxt12}</div>
        <div class="small">New: ${newTxt12||'-'}</div>`;
      if (window.bootstrap && bootstrap.Modal){
        const m = new bootstrap.Modal(document.getElementById('notifReasonModal'));
        m.show();
      } else {
        alert(`Rescheduled\nOld: ${oldTxt12}\nNew: ${newTxt12}`);
      }
  markRead(type, item.getAttribute('data-id'), keyCreated);
  item.classList.remove('unread');
  item.style.background = '#fff';
  { const badge=document.querySelector('#notifBellBtn .notif-badge'); if(badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if(n>0) badge.textContent=String(n);} }
  } else if (type==='appt'){
      ensureReasonModal();
      const title = document.getElementById('notifReasonTitle');
      const when = document.getElementById('notifReasonWhen');
      const body = document.getElementById('notifReasonBody');
      if (title) title.textContent = 'Appointment Update';
  if (when) when.textContent = fmtDateTimeLabel(createdDisplay);
      const st = item.getAttribute('data-status')||'';
      const pur = item.getAttribute('data-purpose')||'';
      const date = item.getAttribute('data-date')||'';
      const time = item.getAttribute('data-time')||'';
      const time12 = fmt12FromHHMM(time)||time;
      body.innerHTML = `<div><strong>${pur||'Appointment'}</strong></div>
        <div class="small text-muted">${date} ${time12}</div>
        <div class="small">Status: <span style="text-transform:capitalize;">${st}</span></div>`;
      if (window.bootstrap && bootstrap.Modal){
        const m = new bootstrap.Modal(document.getElementById('notifReasonModal'));
        m.show();
      } else {
        alert(`${pur||'Appointment'}\n${date} ${time12}\nStatus: ${st}`);
      }
      markRead(type, item.getAttribute('data-id'), created);
      item.classList.remove('unread');
      item.style.background = '#fff';
      { const badge=document.querySelector('#notifBellBtn .notif-badge'); if(badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if(n>0) badge.textContent=String(n);} }
    } else if (type==='accepted'){
      ensureReasonModal();
      const title = document.getElementById('notifReasonTitle');
      const when = document.getElementById('notifReasonWhen');
      const body = document.getElementById('notifReasonBody');
      if (title) title.textContent = 'Appointment accepted';
      if (when) when.textContent = fmtDateTimeLabel(created);
      const pur = item.getAttribute('data-purpose')||'';
      body.innerHTML = `<div><strong>${pur||'Appointment'}</strong></div><div class="small text-muted">Your booking has been accepted.</div>`;
      if (window.bootstrap && bootstrap.Modal){ const m = new bootstrap.Modal(document.getElementById('notifReasonModal')); m.show(); } else { alert('Appointment accepted'); }
  markRead('accepted', item.getAttribute('data-id'), keyCreated);
      item.classList.remove('unread'); item.style.background='#fff';
      { const badge=document.querySelector('#notifBellBtn .notif-badge'); if(badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if(n>0) badge.textContent=String(n);} }
    } else if (type==='ann'){
      // Show announcement details and mark as read
      ensureReasonModal();
      const title = document.getElementById('notifReasonTitle');
      const when = document.getElementById('notifReasonWhen');
      const body = document.getElementById('notifReasonBody');
      if (title) title.textContent = 'Announcement';
  if (when) when.textContent = fmtDateTimeLabel(createdDisplay);
      const msg = item.getAttribute('data-message') || '';
      body.innerHTML = `<div>${msg}</div>`;
      if (window.bootstrap && bootstrap.Modal){
        const m = new bootstrap.Modal(document.getElementById('notifReasonModal'));
        m.show();
      } else {
        alert(`Announcement\n\n${msg}`);
      }
  markRead('ann', item.getAttribute('data-id'), keyCreated);
      item.classList.remove('unread');
      item.style.background = '#f8fafc';
      { const badge=document.querySelector('#notifBellBtn .notif-badge'); if(badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if(n>0) badge.textContent=String(n);} }
    } else if (type==='rem_appt' || type==='followup_due'){
      // For reminder cards, just mark read on click and update badge
  markRead(type, item.getAttribute('data-id'), keyCreated);
      item.classList.remove('unread'); item.style.background='#fff';
      const badge=document.querySelector('#notifBellBtn .notif-badge'); if(badge){ const dd=document.getElementById('notifDropdown'); const n = dd? dd.querySelectorAll('.notif-item.unread').length : 0; badge.style.display = n>0?'inline-block':'none'; if(n>0) badge.textContent=String(n);}        
    }
  });

  // Background polling to refresh unread badge without opening dropdown
  async function refreshUnreadBadge(){
    try{
      const loggedIn = sessionStorage.getItem('documedLoggedIn') === '1';
      if (!loggedIn) return;
      const data = await loadNotifications();
      const followups = await loadFollowUpsForUser();
      const read = loadReadSet();
      let count = 0;
      const addIfUnread = (type, id, createdKey)=>{ const k = keyFor(type,id,createdKey); if (!read.has(k)) count++; };
      (data.appointments||[]).forEach(a=> addIfUnread('appt', a.id, stableThirdForAppt(a)));
      (data.announcements||[]).forEach(a=> addIfUnread('ann', a.id, 'ann'));
      (data.events||[]).forEach(ev=>{
        if (!ev) return; const id = ev.appointment_id||ev.id||''; const t=ev.type||''; if(!t) return; addIfUnread(t, id, t);
      });
      // Appointment reminders (next 3 days)
      const today = new Date(); const t0 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const inDays = (d)=>{ const dt=new Date(d); return Math.round((dt - t0)/86400000); };
      (data.appointments||[]).forEach(ap=>{ const st=(ap.status||'').toLowerCase(); if(['declined','cancelled','completed'].includes(st)) return; const date=ap.date||''; if(!date) return; const dd=inDays(date); if(dd>=0 && dd<=3){ addIfUnread('rem_appt', ap.id, `${date}T00:00:00`); } });
      // Follow-up reminders (next 3 days)
      (followups||[]).forEach(p=>{ const fu = p.follow_up && String(p.follow_up) !== '0'; const d = p.follow_up_date||''; if(!fu||!d) return; const dd=inDays(d); if(dd>=0 && dd<=3){ addIfUnread('followup_due', p.id, `${d}T00:00:00`); } });
  const badge = document.querySelector('#notifBellBtn .notif-badge');
  if (badge){ if (count>0){ badge.textContent=String(count); badge.style.display='inline-block'; } else { badge.style.display='none'; } }
  // Play sound if new unread items arrived since last check (after initialization)
  if (badgeInit && count > lastUnreadCount) { playDing(); }
  lastUnreadCount = count; badgeInit = true;
    } catch {}
  }
  // Poll every 60s and on tab focus
  setInterval(refreshUnreadBadge, 60000);
  document.addEventListener('visibilitychange', ()=>{ if (!document.hidden) refreshUnreadBadge(); });
  // Initial kick to show badge quickly after load
  refreshUnreadBadge();
})();
