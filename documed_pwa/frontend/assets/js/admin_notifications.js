// admin_notifications.js
(function(){
  // Separate admin read state from user; key per admin identifier
  function currentAdminKeys(){
    try {
      const admin = JSON.parse(localStorage.getItem('documed_admin')||'null') || {};
      const email = (admin.email||'').toLowerCase();
      const id = (admin.id||localStorage.getItem('admin_id')||'').toString();
      const keys = [];
      if (email) keys.push(`documed_admin_notif_read_${email}`);
      if (id) keys.push(`documed_admin_notif_read_${id}`);
      if (!keys.length) keys.push('documed_admin_notif_read');
      return Array.from(new Set(keys));
    } catch { return ['documed_admin_notif_read']; }
  }
  function loadRead(){
    const s = new Set();
    currentAdminKeys().forEach(k=>{ try { const arr = JSON.parse(localStorage.getItem(k)||'[]'); if (Array.isArray(arr)) arr.forEach(x=>s.add(x)); } catch {} });
    return s;
  }
  function saveRead(set){
    const json = JSON.stringify(Array.from(set));
    currentAdminKeys().forEach(k=>{ try { localStorage.setItem(k, json); } catch {} });
  }
  function keyFor(type, id, stamp){ return [type||'', String(id||''), String(stamp||'')].join('|'); }
  function markRead(type, id, stamp){ const s=loadRead(); s.add(keyFor(type,id,stamp)); saveRead(s); }

  function ensureDropdown(){
    const holder = document.getElementById('adminNotifBellHolder');
    const bell = document.getElementById('adminNotifBell');
    const dd = document.getElementById('adminNotifDropdown');
    const list = document.getElementById('adminNotifList');
    if (!holder || !bell || !dd || !list) return null;
    // Position dropdown relative to holder
    holder.style.position = 'relative';
    return { holder, bell, dd, list };
  }
  function toggleDropdown(open){
    const dd = document.getElementById('adminNotifDropdown'); if (!dd) return;
    dd.style.display = open ? 'block' : 'none';
  }

  async function fetchSuggestions(){
    try {
      const r = await fetch('../../backend/api/medicine.php?action=suggestions&days=30');
      const j = await r.json(); return j && j.success ? j : null;
    } catch { return null; }
  }
  async function fetchAlerts(){
    try {
      const r = await fetch('../../backend/api/medicine.php?action=alerts&campus=Lingayen&expiring_within_days=60');
      const j = await r.json(); return j && j.success ? j : null;
    } catch { return null; }
  }

  function htmlItem(opts){
    const unread = opts.unread;
    const bg = unread ? (opts.bg || '#eef2ff') : '#fff';
    return `<div class="p-3 border-bottom admin-notif-item ${unread?'unread':''}" data-type="${opts.type}" data-id="${opts.id}" data-created="${opts.created}" style="background:${bg};cursor:pointer;">
      <div style="display:flex;gap:10px;align-items:flex-start;">
        <div style="color:${opts.iconColor||'#2563eb'};font-size:1.2rem"><i class="${opts.icon}"></i></div>
        <div style="flex:1;">
          <div style="font-weight:600;color:#111">${opts.title}</div>
          ${opts.subtitle ? `<div class="small text-muted">${opts.subtitle}</div>` : ''}
          ${opts.body ? `<div class="small" style="margin-top:4px;">${opts.body}</div>` : ''}
          ${unread ? `<div class="small" style="color:${opts.unreadColor||'#1d4ed8'};margin-top:6px;">Unread</div>`:''}
        </div>
      </div>
    </div>`;
  }

  function render(sug, alerts){
    const list = document.getElementById('adminNotifList'); if (!list) return;
    const read = loadRead();
    const items = [];
    // Narrative
    if (sug && sug.narrative){
      const id = 'narr'; const created = sug.week_since || 'sug';
      items.push(htmlItem({
        type:'adm_narr', id, created, unread: !read.has(keyFor('adm_narr', id, created)),
        icon:'bi bi-graph-up', iconColor:'#0ea5e9', title:'Health trend update',
        subtitle:'Last 30 days; growth based on last 7 days', body: String(sug.narrative).replace(/</g,'&lt;'), bg:'#f0f9ff', unreadColor:'#0369a1'
      }));
    }
    // Orientation
    (sug?.actions?.orientation||[]).forEach(o=>{
      const created = `${o.condition}|${sug.week_since||''}`;
      items.push(htmlItem({
        type:'adm_orient', id:o.condition, created, unread: !read.has(keyFor('adm_orient', o.condition, created)),
        icon:'bi bi-exclamation-circle', title:`Orientation suggested: ${o.condition}`,
        subtitle:`30d: ${o.month_cases} • 7d: ${o.week_cases}`, body:`Reason: ${o.reason}`, bg:'#dbeafe'
      }));
    });
    // Restock candidates
    (sug?.actions?.restock||[]).forEach(r=>{
      const created = r.name;
      const inv = r.in_inventory ? 'in inventory' : 'missing';
      const status = r.low_stock ? 'low stock' : inv;
      items.push(htmlItem({
        type:'adm_restock', id:r.name, created, unread: !read.has(keyFor('adm_restock', r.name, created)),
        icon:'bi bi-box-seam', iconColor:'#166534', title:`Restock: ${r.name}`,
        subtitle:`Linked condition: ${r.linked_condition}`, body:`Status: ${status}`, bg:'#ecfdf5', unreadColor:'#166534'
      }));
    });
    // Expiring soon
    (alerts?.expiring||[]).forEach(m=>{
      if (!m.nearest_expiry) return;
      const created = m.nearest_expiry;
      items.push(htmlItem({
        type:'adm_exp', id:m.id, created, unread: !read.has(keyFor('adm_exp', m.id, created)),
        icon:'bi bi-capsule', iconColor:'#a16207', title:`Expiring soon: ${m.name}`,
        subtitle:`Expiry: ${m.nearest_expiry}`, body: typeof m.days_to_expiry==='number'? `In ${m.days_to_expiry} day${m.days_to_expiry===1?'':'s'}`:'', bg:'#fef9c3', unreadColor:'#92400e'
      }));
    });
    // Low stock
    (alerts?.low_stock||[]).forEach(m=>{
      const created = `low|${m.id}|${m.quantity}|${m.threshold_qty||''}`;
      items.push(htmlItem({
        type:'adm_low', id:m.id, created, unread: !read.has(keyFor('adm_low', m.id, created)),
        icon:'bi bi-activity', iconColor:'#dc2626', title:`Low stock: ${m.name}`,
        subtitle:`Qty: ${m.quantity}/${m.threshold_qty||'?'} • Campus: ${m.campus||''}`, body:'', bg:'#fee2e2', unreadColor:'#991b1b'
      }));
    });

    list.innerHTML = items.length ? items.join('') : '<div class="p-3 text-center text-muted">No notifications</div>';
    updateBadgeCount();
  }

  function updateBadgeCount(){
    const badge = document.getElementById('adminNotifBadge'); if (!badge) return;
    const read = loadRead();
    const dd = document.getElementById('adminNotifDropdown');
    const count = dd ? dd.querySelectorAll('.admin-notif-item.unread').length : 0;
    if (count>0){ badge.textContent = String(count); badge.style.display = 'inline-block'; }
    else { badge.style.display = 'none'; }
  }

  async function refresh(){
    const refs = ensureDropdown(); if (!refs) return;
    const [sug, alerts] = await Promise.all([fetchSuggestions(), fetchAlerts()]);
    render(sug, alerts);
  }

  function handleClicks(){
    document.body.addEventListener('click', function(e){
      const holder = document.getElementById('adminNotifBellHolder');
      const dd = document.getElementById('adminNotifDropdown');
      const bell = document.getElementById('adminNotifBell');
      if (bell && (e.target===bell || (e.target.closest && e.target.closest('#adminNotifBell')))){
        e.preventDefault();
        const isOpen = dd && dd.style.display === 'block';
        if (!isOpen) { const list = document.getElementById('adminNotifList'); if (list) list.innerHTML = '<div class="p-3 text-muted">Loading...</div>'; refresh().then(()=>toggleDropdown(true)); }
        else toggleDropdown(false);
        return;
      }
      // Mark all read
      if (e.target && e.target.id === 'adminNotifMarkAll'){ e.preventDefault();
        const dd = document.getElementById('adminNotifDropdown'); if (!dd) return;
        const items = dd.querySelectorAll('.admin-notif-item');
        const set = loadRead();
        items.forEach(el=>{ const t=el.getAttribute('data-type'); const id=el.getAttribute('data-id'); const c=el.getAttribute('data-created'); set.add(keyFor(t,id,c)); el.classList.remove('unread'); el.style.background='#fff'; });
        saveRead(set); updateBadgeCount(); return; }
      // Item click -> mark read
      const it = e.target && e.target.closest && e.target.closest('.admin-notif-item');
      if (it){
        const t = it.getAttribute('data-type'); const id = it.getAttribute('data-id'); const c = it.getAttribute('data-created');
        markRead(t, id, c); it.classList.remove('unread'); it.style.background = '#fff'; updateBadgeCount();
      }
      // Click outside -> close
      if (dd && dd.style.display==='block' && !(e.target.closest && (e.target.closest('#adminNotifDropdown')||e.target.closest('#adminNotifBellHolder')))){
        toggleDropdown(false);
      }
    });
  }

  // Background polling every 60s to update badge without opening
  async function poll(){
    try {
      const [sug, alerts] = await Promise.all([fetchSuggestions(), fetchAlerts()]);
      // build virtual items and count unread
      const read = loadRead();
      let count = 0;
      const add = (t,id,c)=>{ if (!read.has(keyFor(t,id,c))) count++; };
      if (sug){
        if (sug.narrative) add('adm_narr','narr', sug.week_since||'sug');
        (sug.actions?.orientation||[]).forEach(o=> add('adm_orient', o.condition, `${o.condition}|${sug.week_since||''}`));
        (sug.actions?.restock||[]).forEach(r=> add('adm_restock', r.name, r.name));
      }
      if (alerts){
        (alerts.expiring||[]).forEach(m=>{ if (m.nearest_expiry) add('adm_exp', m.id, m.nearest_expiry); });
        (alerts.low_stock||[]).forEach(m=> add('adm_low', m.id, `low|${m.id}|${m.quantity}|${m.threshold_qty||''}`));
      }
      const badge = document.getElementById('adminNotifBadge');
      if (badge){ if (count>0){ badge.textContent=String(count); badge.style.display='inline-block'; } else { badge.style.display='none'; } }
    } catch {}
  }

  document.addEventListener('DOMContentLoaded', function(){
    const refs = ensureDropdown(); if (!refs) return;
    handleClicks();
    poll();
    setInterval(poll, 60000);
  });
})();
