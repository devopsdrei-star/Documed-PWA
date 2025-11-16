// Admin/Staff appointments table: show patient name, accept/decline/reschedule
// Ensure shared state is available across modules
window.apptById = window.apptById || {};
window.applyFilters = window.applyFilters || function(){};

document.addEventListener('DOMContentLoaded', function() {
	const tableBody = document.querySelector('#appointmentsTable tbody');
	const msg = document.getElementById('appointmentsMsg');
	const statusFilter = document.getElementById('statusFilter');
	const orderFilter = document.getElementById('orderFilter');
	const apptSearch = document.getElementById('apptSearch');
	const resetBtn = document.getElementById('resetFiltersBtn');
	if (!tableBody) return;

	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;

	function setMsg(text) { if (msg) msg.textContent = text || ''; }

		let allAppointments = [];
	let apptById = {};

		function applyFilters() {
			let rows = allAppointments;
			const statusVal = statusFilter ? statusFilter.value : 'all';
			const q = (apptSearch ? apptSearch.value : '').toLowerCase().trim();

			if (statusVal && statusVal !== 'all') {
				rows = rows.filter(a => (a.status||'').toLowerCase() === statusVal);
			}
			if (q) {
				rows = rows.filter(a => {
					const name = (a.name || a.patient_name || a.email || a.patient_id || '').toLowerCase();
					const date = (a.date || '').toLowerCase();
					const purpose = (a.purpose || a.service || '').toLowerCase();
					return name.includes(q) || date.includes(q) || purpose.includes(q);
				});
			}

			// Sort so that final-state (declined/cancelled/completed) go to bottom
			const rank = (s)=>{
				s = (s||'').toLowerCase();
				if (s==='declined' || s==='cancelled' || s==='completed') return 2;
				if (s==='accepted') return 0; // accepted near top
				return 0; // scheduled/default on top
			};
			const order = orderFilter ? (orderFilter.value||'newest') : 'newest';
			rows = rows.slice().sort((a,b)=>{
				const ra = rank(a.status), rb = rank(b.status);
				if (ra !== rb) return ra - rb;
				// Primary sort: booking timestamp (created_at), newest first by default
				const ca = a.created_at ? new Date(String(a.created_at).replace(' ','T')).getTime() : 0;
				const cb = b.created_at ? new Date(String(b.created_at).replace(' ','T')).getTime() : 0;
				if (ca !== cb) return order==='newest' ? cb - ca : ca - cb;
				// Fallback: by date then time if created_at is equal/missing
				const da = String(a.date||'');
				const db = String(b.date||'');
				if (da !== db) return order==='newest' ? db.localeCompare(da) : da.localeCompare(db);
				const ta = String(a.time||'');
				const tb = String(b.time||'');
				return order==='newest' ? tb.localeCompare(ta) : ta.localeCompare(tb);
			});

			tableBody.innerHTML = '';
			if (rows.length === 0) {
				setMsg('No appointments match your filters.');
				return;
			}
						rows.forEach(app => {
				const tr = document.createElement('tr');
				const name = app.name || app.patient_name || app.email || app.patient_id || '';
					// Display time in 12-hour format with AM/PM, keep raw for values
					const rawTime = app.time && app.time.length > 5 ? app.time.slice(0,5) : (app.time || '');
					const time = (function(t){ if(!t) return ''; const parts=t.split(':'); let h=parseInt(parts[0]||'0',10); const mm=(parts[1]||'00'); const am=h<12; if(h===0)h=12; if(h>12)h-=12; return `${h}:${mm} ${am?'AM':'PM'}`; })(rawTime);
								// Format created_at (booking timestamp) to readable 12-hour
								const bookedAt = (function(v){
									if (!v) return '';
									try {
										// Expect formats like '2025-10-04 14:23:00' or ISO
										const d = new Date(v.replace(' ','T'));
										if (isNaN(d.getTime())) return String(v);
										const yyyy = d.getFullYear();
										const mm = String(d.getMonth()+1).padStart(2,'0');
										const dd = String(d.getDate()).padStart(2,'0');
										let h = d.getHours();
										const min = String(d.getMinutes()).padStart(2,'0');
										const am = h < 12;
										if (h === 0) h = 12; else if (h > 12) h -= 12;
										return `${yyyy}-${mm}-${dd} ${h}:${min} ${am?'AM':'PM'}`;
									} catch { return String(v); }
								})(app.created_at);
				const purpose = app.purpose || app.service || '';
				const status = (app.status || '').toLowerCase();
				// Build actions with sizing similar to dn_patient view/delete (padding ~4px 12px, radius 6px)
				let actionsHtml = '';
				if (status === 'completed' || status === 'cancelled' || status === 'declined') {
					// No actions once completed
					actionsHtml = '';
				} else if (status === 'accepted') {
					// On accepted, allow marking completed and rescheduling
					actionsHtml = `
						<button class="btn btn-success btn-sm" style="padding:4px 12px;border-radius:6px;" onclick="completeAppointment(${app.id})">Mark Completed</button>
						<button class="btn btn-primary btn-sm resched-btn" style="padding:4px 12px;border-radius:6px;margin-left:4px;" data-appt-id="${app.id}" data-bs-toggle="modal" data-bs-target="#rescheduleModal">Reschedule</button>
					`;
				} else if (status === 'pending') {
					// Pending reschedule request: hide Accept/Decline/Reschedule; allow Cancel (to cancel the request)
					actionsHtml = `
						<button class="btn btn-sm" style="background:#dc2626;color:#fff;padding:4px 12px;border:none;border-radius:6px;" onclick="cancelReschedule(${app.id})">Cancel reschedule</button>
					`;
				} else if (status === 'rescheduled') {
					// After user selects time, only allow marking as completed
					actionsHtml = `
						<button class="btn btn-success btn-sm" style="padding:4px 12px;border-radius:6px;" onclick="completeAppointment(${app.id})">Mark Completed</button>
					`;
				} else {
					// Show full set before acceptance
					actionsHtml = `
						<button class="btn btn-primary btn-sm" style="padding:4px 12px;border-radius:6px;" onclick="acceptAppointment(${app.id})">Accept</button>
						<button class="btn btn-sm" style="background:#f59e0b;color:#fff;padding:4px 12px;border:none;border-radius:6px;margin-left:4px;" onclick="declineAppointment(${app.id})">Decline</button>
						<button class="btn btn-primary btn-sm resched-btn" style="padding:4px 12px;border-radius:6px;margin-left:4px;" data-appt-id="${app.id}" data-bs-toggle="modal" data-bs-target="#rescheduleModal">Reschedule</button>
						<button class="btn btn-sm" style="background:#dc2626;color:#fff;padding:4px 12px;border:none;border-radius:6px;margin-left:4px;" onclick="cancelAppointment(${app.id})">Cancel</button>
					`;
				}

				tr.innerHTML = `
					<td>${bookedAt}</td>
					<td>${name}</td>
					<td>${app.date || ''}</td>
					<td>${time}</td>
					<td>${purpose}</td>
					<td>${(status==='pending'?'Pending':(app.status||''))}</td>
					<td>${actionsHtml}</td>
				`;
				tableBody.appendChild(tr);
			});
			setMsg('');
		}

		async function loadAppointments() {
		setMsg('');
		tableBody.innerHTML = '';
		try {
			const body = new URLSearchParams();
			body.append('action', 'list');
			const res = await fetch(apiUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			});
			const data = await res.json();
			if (!data.success) {
				setMsg(data.message || 'Failed to load appointments.');
				return;
			}
					const src = data.appointments || [];
					// Optional pre-filter hook (e.g., limit to subset per page)
					const baseList = (typeof window.APPT_PRE_FILTER === 'function') ? window.APPT_PRE_FILTER(src) : src;
					// Exclude final-state records from main table (shown in Archive instead)
					allAppointments = baseList.filter(a => !['declined','cancelled','completed'].includes(String(a.status||'').toLowerCase()));
					apptById = {};
					allAppointments.forEach(a => { if (a && a.id != null) apptById[a.id] = a; });
					// expose globally for other handlers
					window.apptById = apptById;
					if (allAppointments.length === 0) {
				setMsg('No appointments found.');
				return;
			}
					applyFilters();
		} catch (e) {
			console.error('Error fetching appointments:', e);
			setMsg('Error fetching appointments.');
		}
	}

	loadAppointments();

			if (statusFilter) statusFilter.addEventListener('change', applyFilters);
			if (orderFilter) orderFilter.addEventListener('change', applyFilters);
			if (apptSearch) apptSearch.addEventListener('input', applyFilters);
			if (resetBtn) resetBtn.addEventListener('click', () => {
				if (statusFilter) statusFilter.value = 'all';
				if (orderFilter) orderFilter.value = 'newest';
				if (apptSearch) apptSearch.value = '';
				applyFilters();
			});
			// publish filter function globally so external helpers can re-render
			window.applyFilters = applyFilters;
});

// Helpers to mutate local cache and re-render without full reload
function updateApptStatus(id, newStatus){
	const appt = window.apptById[id];
	if (appt){
		appt.status = newStatus;
	}
	if (typeof window.applyFilters === 'function') window.applyFilters();
}
function updateApptDateTime(id, newDate, newTime){
	const appt = window.apptById[id];
	if (appt){
		appt.date = newDate;
		appt.time = newTime;
	}
	if (typeof window.applyFilters === 'function') window.applyFilters();
}

// Cancel a pending reschedule request
window.cancelReschedule = function(id) {
	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
	const msgEl = document.getElementById('appointmentsMsg');
	const adminId = localStorage.getItem('admin_id') || '';
	if (msgEl) msgEl.textContent = 'Cancelling reschedule request...';
	fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: `action=cancel_reschedule_request&id=${encodeURIComponent(id)}&admin_id=${encodeURIComponent(adminId)}`
	})
	.then(r => r.json())
	.then(d => {
		if (d.success) updateApptStatus(id, 'accepted');
		else if (msgEl) msgEl.textContent = d.message || 'Failed to cancel reschedule request.';
	})
	.catch(() => { if (msgEl) msgEl.textContent = 'Network error'; });
}

// Accept
window.acceptAppointment = function(id) {
	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
	const msgEl = document.getElementById('appointmentsMsg');
	const adminId = localStorage.getItem('admin_id') || '';
	if (msgEl) msgEl.textContent = 'Accepting appointment...';
	fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: `action=update_status&id=${encodeURIComponent(id)}&status=accepted&admin_id=${encodeURIComponent(adminId)}`
	})
	.then(r => r.json())
	.then(d => {
		if (d.success) updateApptStatus(id, 'accepted');
		else if (msgEl) msgEl.textContent = d.message || 'Failed to accept appointment.';
	})
	.catch(() => { if (msgEl) msgEl.textContent = 'Network error'; });
}

// Decline
window.declineAppointment = function(id) {
	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
	const msgEl = document.getElementById('appointmentsMsg');
	const reason = window.prompt('Reason for decline (optional):','');
	const adminId = localStorage.getItem('admin_id') || '';
	if (msgEl) msgEl.textContent = 'Declining appointment...';
	fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: `action=update_status&id=${encodeURIComponent(id)}&status=declined&reason=${encodeURIComponent(reason||'')}&admin_id=${encodeURIComponent(adminId)}`
	})
	.then(r => r.json())
	.then(d => {
		if (d.success) updateApptStatus(id, 'declined');
		else if (msgEl) msgEl.textContent = d.message || 'Failed to decline appointment.';
	})
	.catch(() => { if (msgEl) msgEl.textContent = 'Network error'; });
}

// Mark Completed
window.completeAppointment = function(id) {
	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
	const msgEl = document.getElementById('appointmentsMsg');
	const adminId = localStorage.getItem('admin_id') || '';
	if (msgEl) msgEl.textContent = 'Marking as completed...';
	fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: `action=update_status&id=${encodeURIComponent(id)}&status=completed&admin_id=${encodeURIComponent(adminId)}`
	})
	.then(r => r.json())
	.then(d => {
		if (d.success) updateApptStatus(id, 'completed');
		else if (msgEl) msgEl.textContent = d.message || 'Failed to mark as completed.';
	})
	.catch(() => { if (msgEl) msgEl.textContent = 'Network error'; });
}

// Reschedule modal now uses only the 3-day window flow. When a resched-btn is clicked,
// we capture the current appointment id so the Propose Window action can use it.
let currentReschedId = null;
document.addEventListener('click', (ev) => {
	const btn = ev.target.closest('.resched-btn');
	if (!btn) return;
	const id = btn.getAttribute('data-appt-id');
	currentReschedId = id ? parseInt(id, 10) : null;
	const errEl = document.getElementById('reschedError');
	if (errEl) errEl.textContent = '';
});

window.cancelAppointment = function(id) {
	const baseUrl = window.location.pathname.split('/frontend/')[0];
	const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;
	const msgEl = document.getElementById('appointmentsMsg');
	const reason = window.prompt('Reason for cancellation (optional):','');
	const adminId = localStorage.getItem('admin_id') || '';
	if (msgEl) msgEl.textContent = 'Cancelling appointment...';
	fetch(apiUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: `action=admin_cancel&id=${encodeURIComponent(id)}&reason=${encodeURIComponent(reason||'')}&admin_id=${encodeURIComponent(adminId)}`
	})
	.then(r => r.json())
	.then(d => {
		if (d.success) updateApptStatus(id, 'cancelled');
		else if (msgEl) msgEl.textContent = d.message || 'Failed to cancel appointment.';
	})
	.catch(() => { if (msgEl) msgEl.textContent = 'Network error'; });
}
