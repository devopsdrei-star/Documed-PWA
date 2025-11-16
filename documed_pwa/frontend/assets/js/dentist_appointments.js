/**
 * Deprecated: Not referenced by any dentist page script tags; archive for reference.
 */

if (false) {
// Dentist appointments listing and actions (accept/decline/complete)
(function(){
  const body = document.getElementById('apptBody');
  const search = document.getElementById('search');
  const statusFilter = document.getElementById('statusFilter');

  async function load() {
    if (!body) return;
    body.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
    try {
      const res = await fetch('../../backend/api/appointments_new.php?action=list');
      const data = await res.json();
      const rows = (data && data.appointments) ? data.appointments : [];
      const q = (search && search.value || '').toLowerCase();
      const s = statusFilter ? statusFilter.value : '';
      const filtered = rows.filter(r => {
        const dental = String(r.purpose||'').toLowerCase().includes('dental');
        if (!dental) return false;
        // Exclude final-state from main table (they go to archive)
        const stLow = String(r.status||'').toLowerCase();
        if (stLow==='declined' || stLow==='cancelled' || stLow==='completed') return false;
        const okSearch = !q || (String(r.name||'').toLowerCase().includes(q) || String(r.email||'').toLowerCase().includes(q));
        const okStatus = !s || String(r.status||'') === s;
        return okSearch && okStatus;
      });
      if (filtered.length === 0) { body.innerHTML = '<tr><td colspan="7">No appointments found.</td></tr>'; return; }
      body.innerHTML = filtered.map(r => {
        const dt = r.date || '';
        const tm = (r.time||'').slice(0,5);
        const st = r.status || 'scheduled';
        const id = r.id;
        return `<tr>
          <td>${dt}</td>
          <td>${tm}</td>
          <td>${r.name||''}</td>
          <td>${r.email||''}</td>
          <td>${r.purpose||''}</td>
          <td><span class="badge bg-${st==='accepted'?'primary':st==='declined'?'danger':st==='completed'?'success':st==='cancelled'?'secondary':'warning'}">${st}</span></td>
          <td class="actions">
            ${st==='scheduled'? `<button class="btn btn-sm btn-primary" data-act="accept" data-id="${id}">Accept</button>
              <button class="btn btn-sm btn-outline-danger" data-act="decline" data-id="${id}">Decline</button>`:''}
            ${st==='accepted'? `<button class="btn btn-sm btn-success" data-act="complete" data-id="${id}">Mark Completed</button>`:''}
          </td>
        </tr>`;
      }).join('');
    } catch (e) {
      body.innerHTML = '<tr><td colspan="7">Failed to load appointments.</td></tr>';
    }
  }

  async function act(id, status) {
    const fd = new FormData();
    fd.append('action','update_status');
    fd.append('id', id);
    fd.append('status', status);
    const res = await fetch('../../backend/api/appointments_new.php', { method:'POST', body: fd });
    const data = await res.json().catch(()=>({success:false}));
    if (!data.success) alert(data.message||'Action failed.');
    await load();
  }

  document.addEventListener('click', function(e){
    const t = e.target;
    if (t && t.dataset && t.dataset.act && t.dataset.id) {
      const { act:action, id } = t.dataset;
      if (action==='accept') act(id,'accepted');
      if (action==='decline') act(id,'declined');
      if (action==='complete') act(id,'completed');
    }
  });

  if (search) search.addEventListener('input', load);
  if (statusFilter) statusFilter.addEventListener('change', load);
  load();
})();
}
