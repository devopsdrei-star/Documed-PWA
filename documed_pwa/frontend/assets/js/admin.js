// Sidebar navigation active state
document.addEventListener('DOMContentLoaded', function() {
	// --- Sidebar Active ---
	const links = document.querySelectorAll('.sidebar-link');
	links.forEach(link => {
		if (window.location.pathname.endsWith(link.getAttribute('href'))) {
			link.classList.add('active');
		} else {
			link.classList.remove('active');
		}
		if (link.classList.contains('logout')) {
			link.addEventListener('click', function(e) {
				e.preventDefault();
				if (confirm('Are you sure you want to logout?')) {
					try {
						localStorage.clear();
						sessionStorage.clear();
						document.cookie.split(';').forEach(function(c){
							document.cookie = c.replace(/^\s+/, '').replace(/=.*/, '=;expires=' + new Date(0).toUTCString() + ';path=/');
						});
						window.location.replace('admin_login.html');
						setTimeout(() => { window.location.href = 'admin_login.html'; }, 100);
					} catch(_) {
						window.location.href = 'admin_login.html';
					}
				}
			});
		}
	});

	// --- Quick Stats ---
	(function(){
		// Consolidated quick stats (patients, appointments, reports) in one request
		const elPatients = document.getElementById('statCheckups');
		const elAppointments = document.getElementById('statAppointments');
		const elReports = document.getElementById('statReports');
		if (!elPatients || !elAppointments || !elReports) return;
		fetch('../../backend/api/dashboard_stats.php')
			.then(r => r.json())
			.then(d => {
				elPatients.textContent = (d.patientCount ?? 0);
				elAppointments.textContent = (d.appointmentCount ?? 0);
				elReports.textContent = (d.reportCount ?? 0);
			})
			.catch(() => {
				elPatients.textContent = '0';
				elAppointments.textContent = '0';
				elReports.textContent = '0';
			});
	})();

	// --- Admin: mobile (Android) sidebar -> hamburger + drawer behavior ---
	(function(){
		function isAndroid() {
			try { return /android/i.test(navigator.userAgent || ''); } catch(e) { return false; }
		}
		if (!isAndroid()) return; // only apply on Android devices as requested
		document.addEventListener('DOMContentLoaded', function(){
			try {
				document.body.classList.add('mobile-admin');
				const topbar = document.querySelector('.dashboard-topbar');
				if (!topbar) return;
				// Create hamburger button if not present
				if (!document.getElementById('adminMenuBtn')) {
					const btn = document.createElement('button');
					btn.id = 'adminMenuBtn';
					btn.type = 'button';
					btn.title = 'Menu';
					btn.style.cssText = 'background:none;border:none;color:#2563eb;font-size:1.6rem;cursor:pointer;padding:6px 8px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-right:auto;';
					btn.innerHTML = '\u2630'; // simple hamburger
					// Insert at the start of topbar
					topbar.insertBefore(btn, topbar.firstChild);

					// Build drawer overlay
					const overlay = document.createElement('div');
					overlay.id = 'adminDrawerOverlay';
					overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.35);display:none;z-index:1200;';
					document.body.appendChild(overlay);

					const drawer = document.createElement('nav');
					drawer.id = 'adminDrawer';
					drawer.setAttribute('aria-hidden','true');
					drawer.style.cssText = 'position:fixed;left:0;top:0;height:100vh;width:78%;max-width:320px;background:#fff;box-shadow:0 16px 48px rgba(2,6,23,0.2);z-index:1250;transform:translateX(-110%);transition:transform 260ms ease;padding:20px 14px;overflow:auto;';
					// Clone sidebar content if available
					const sidebar = document.querySelector('.sidebar');
					if (sidebar) {
						// clone without id collisions
						const clone = sidebar.cloneNode(true);
						// Remove large logo image size to fit drawer
						clone.querySelectorAll('img').forEach(img => { img.style.width='54px'; img.style.height='54px'; img.style.borderRadius='8px'; });
						// Remove margin on clone
						clone.style.margin = '0';
						// Append a close button
						const close = document.createElement('button'); close.type='button'; close.id='adminDrawerClose'; close.innerHTML='\u00d7'; close.title='Close menu';
						close.style.cssText='position:absolute;right:10px;top:10px;background:none;border:none;font-size:22px;cursor:pointer;color:#374151;';
						drawer.appendChild(close);
						drawer.appendChild(clone);
					} else {
						drawer.innerHTML = '<div style="padding:12px;color:#374151;font-weight:700;">Menu</div>';
					}
					document.body.appendChild(drawer);

					function openDrawer(){ overlay.style.display='block'; drawer.style.transform='translateX(0%)'; drawer.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
					function closeDrawer(){ drawer.style.transform='translateX(-110%)'; overlay.style.display='none'; drawer.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }

					btn.addEventListener('click', openDrawer);
					overlay.addEventListener('click', closeDrawer);
					const closeBtn = document.getElementById('adminDrawerClose');
					if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

					// Accessibility: close on Escape
					document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeDrawer(); });
				}
			} catch (e) { console.debug('[admin mobile menu] init failed', e); }
		});
	})();
	// --- Patient Check-Up Popup ---
	const checkupBtn = document.getElementById('checkupBtn');
	const checkupModal = document.getElementById('checkupModal');
	const closeCheckup = document.getElementById('closeCheckup');
	const closeCheckupX = document.getElementById('closeCheckupX');
	const submitCheckupBtn = document.getElementById('submitCheckup');
	const checkupForm = document.getElementById('checkupForm');
	if (checkupForm) checkupForm.onsubmit = async function(e) {
		e.preventDefault();
		const fd = new FormData(checkupForm);
		const userId = fd.get('user_id');
		// Check if patient already exists
		let exists = false;
		await fetch(`../../backend/api/patient.php?action=get&user_id=${encodeURIComponent(userId)}`)
			.then(res => res.json())
			.then(data => {
				if (data.success && data.patient) exists = true;
			});
		const msgElem = document.getElementById('checkupMsg');
		if (exists) {
			msgElem.textContent = 'Patient already exists.';
			msgElem.style.color = '#e11d48';
			return;
		}
		// Proceed to add patient
		const params = new URLSearchParams();
		fd.forEach((value, key) => params.append(key, value));
		fetch('../../backend/api/patient.php?action=add', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params
		})
		.then(res => res.json())
		.then(data => {
			if (data.success) {
				msgElem.textContent = data.message;
				msgElem.style.color = '#16a34a';
				checkupForm.reset();
				// Log audit trail (add patient)
				const adminId = localStorage.getItem('admin_id');
				fetch('/DocMed/documed_pwa/backend/api/audit_trail.php?action=add', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: `admin_id=${encodeURIComponent(adminId)}&action_txt=Add Patient&details=Added patient ID: ${encodeURIComponent(userId)}`
				});
			} else {
				msgElem.textContent = data.message || 'Error adding patient.';
				msgElem.style.color = '#e11d48';
			}
		})
		.catch(() => {
			msgElem.textContent = 'Error connecting to server.';
		});
	}

	if (checkupBtn && checkupModal && closeCheckup && checkupForm) {
		checkupBtn.onclick = () => { checkupModal.style.display = 'flex'; };
		closeCheckup.onclick = () => { checkupModal.style.display = 'none'; };
		if (closeCheckupX) closeCheckupX.onclick = () => { checkupModal.style.display = 'none'; };
		if (submitCheckupBtn) {
			submitCheckupBtn.onclick = function() {
				checkupForm.requestSubmit();
			};
		}
	}

	// --- QR Scan Popup ---
	const scanBtn = document.getElementById('scanBtn');
	const scanModal = document.getElementById('scanModal');
	const closeScan = document.getElementById('closeScan');
	const qrVideo = document.getElementById('qrVideo');
	const qrCanvas = document.getElementById('qrCanvas');
	const scanMsg = document.getElementById('scanMsg');
	const userIdInput = document.getElementById('user_id');
	let stream = null;
	let scanInterval = null;

	(scanBtn && scanModal && closeScan && qrVideo && qrCanvas) && (scanBtn.onclick = () => {
		scanModal.style.display = 'flex';
		scanMsg.style.color = 'black';
		scanMsg.textContent = 'Starting camera...';
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
			.then(s => {
				stream = s;
				qrVideo.srcObject = stream;
				qrVideo.setAttribute('playsinline', true);
				qrVideo.play();
				scanInterval = setInterval(() => {
					if (qrVideo.readyState === qrVideo.HAVE_ENOUGH_DATA) {
						qrCanvas.width = qrVideo.videoWidth;
						qrCanvas.height = qrVideo.videoHeight;
						const ctx = qrCanvas.getContext('2d');
						ctx.drawImage(qrVideo, 0, 0, qrCanvas.width, qrCanvas.height);
						const imageData = ctx.getImageData(0, 0, qrCanvas.width, qrCanvas.height);
						const code = jsQR(imageData.data, qrCanvas.width, qrCanvas.height);
									if (code) {
										scanMsg.style.color = 'green';
										scanMsg.textContent = 'QR Code: ' + code.data;
										if (userIdInput) userIdInput.value = code.data;
										// Fetch patient info and display below QR result
										fetch(`/DocMed/documed_pwa/backend/api/patient.php?action=get&user_id=${encodeURIComponent(code.data)}`)
											.then(res => res.json())
											.then(data => {
												if (data.success && data.patient) {
													scanMsg.textContent += `\nPatient: ${data.patient.name}`;
												} else {
													scanMsg.textContent += '\nNo patient found.';
												}
											});
										stopScan();
										// Log audit trail (scan QR)
										const adminId = localStorage.getItem('admin_id');
										fetch('/DocMed/documed_pwa/backend/api/audit_trail.php?action=add', {
											method: 'POST',
											headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
											body: `admin_id=${encodeURIComponent(adminId)}&action_txt=Scan QR&details=Scanned QR: ${encodeURIComponent(code.data)}`
										});
									}
					}
				}, 500);
			})
			.catch(err => {
				scanMsg.style.color = 'red';
				scanMsg.textContent = 'Unable to access camera. Check permissions.';
			});
	});

	function stopScan() {
		if (stream) {
			stream.getTracks().forEach(track => track.stop());
			stream = null;
		}
		clearInterval(scanInterval);
	}

	closeScan && (closeScan.onclick = () => {
		stopScan();
		scanModal.style.display = 'none';
	});

	function showError(msg) {
		const errorMsg = document.getElementById('errorMsg');
		if (errorMsg) errorMsg.textContent = msg;
	}

	// Print PDF
	const printBtn = document.getElementById('printReportBtn');
	if (printBtn) {
		printBtn.onclick = function() {
			window.scrollTo(0,0);
			window.print();
		};
	}

	// Settings: Manage Users (enhanced with status + loading overlay)
	const userManage = document.getElementById('userManage');
    // Toast utilities
    function ensureToastContainer() {
        let c = document.getElementById('toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toast-container';
            c.style.cssText = 'position:fixed;right:16px;bottom:16px;display:flex;flex-direction:column;gap:8px;z-index:4000;';
            document.body.appendChild(c);
        }
        return c;
    }
	function showToast(type, message) {
        const c = ensureToastContainer();
        const t = document.createElement('div');
        const bg = type === 'success' ? '#16a34a' : (type === 'error' ? '#dc2626' : '#2563eb');
        t.style.cssText = 'min-width:220px;max-width:360px;color:#fff;padding:10px 12px;border-radius:8px;box-shadow:0 8px 24px rgba(2,6,23,0.18);display:flex;align-items:flex-start;gap:8px;';
        t.style.background = bg;
        t.innerHTML = `<span style="font-weight:700;">${type.toUpperCase()}</span><span style="flex:1;">${message}</span>`;
        c.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 240ms ease'; setTimeout(()=> t.remove(), 260); }, 2600);
    }

	// Branded confirm dialog (avoids native browser 'localhost says')
	const SITE_NAME = 'DocuMed';
	function ensureConfirmRoot() {
		let root = document.getElementById('documed-confirm-root');
		if (!root) {
			root = document.createElement('div');
			root.id = 'documed-confirm-root';
			root.style.cssText = 'position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,0.45);z-index:4500;';
			root.innerHTML = `
				<div role="dialog" aria-modal="true" aria-labelledby="documedConfirmTitle" style="background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(2,6,23,0.25);width:min(92vw,420px);max-width:92vw;padding:18px 16px 14px;">
				  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
					<img src="../../frontend/assets/images/Logo.png" alt="${SITE_NAME}" style="width:28px;height:28px;border-radius:6px;"/>
					<h3 id="documedConfirmTitle" style="margin:0;font-size:1rem;color:#111827;">${SITE_NAME}</h3>
				  </div>
				  <div id="documedConfirmMessage" style="color:#374151;padding:8px 2px 14px;white-space:pre-wrap;"></div>
				  <div style="display:flex;justify-content:flex-end;gap:8px;">
					<button type="button" id="documedConfirmCancel" class="btn btn-light" style="padding:6px 12px;">Cancel</button>
					<button type="button" id="documedConfirmOk" class="btn btn-primary" style="padding:6px 12px;">OK</button>
				  </div>
				</div>`;
			document.body.appendChild(root);
		}
		return root;
	}
	function confirmDialog(message, { okText = 'OK', cancelText = 'Cancel' } = {}) {
		return new Promise((resolve) => {
			const root = ensureConfirmRoot();
			const msgEl = root.querySelector('#documedConfirmMessage');
			const okBtn = root.querySelector('#documedConfirmOk');
			const cancelBtn = root.querySelector('#documedConfirmCancel');
			msgEl.textContent = message;
			okBtn.textContent = okText; cancelBtn.textContent = cancelText;
			root.style.display = 'flex';
			function cleanup(v){
				root.style.display = 'none';
				okBtn.removeEventListener('click', onOk);
				cancelBtn.removeEventListener('click', onCancel);
				document.removeEventListener('keydown', onKey);
				resolve(v);
			}
			function onOk(){ cleanup(true); }
			function onCancel(){ cleanup(false); }
			function onKey(e){ if (e.key === 'Escape') cleanup(false); if (e.key === 'Enter') cleanup(true); }
			okBtn.addEventListener('click', onOk);
			cancelBtn.addEventListener('click', onCancel);
			document.addEventListener('keydown', onKey);
		});
	}

	function ensureLoadingOverlay(){
		let ov = document.getElementById('usersLoadingOverlay');
		if (!ov) {
			ov = document.createElement('div');
			ov.id = 'usersLoadingOverlay';
			ov.style.cssText = 'position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,0.6);backdrop-filter:blur(3px);z-index:3000;font-size:1.2rem;font-weight:600;color:#2563eb;';
			ov.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:10px;"><div class="spinner-border text-primary" role="status" style="width:48px;height:48px;"><span class="visually-hidden">Loading...</span></div><span>Processing...</span></div>';
			document.body.appendChild(ov);
		}
		return ov;
	}
	function showOverlay(){ ensureLoadingOverlay().style.display='flex'; }
	function hideOverlay(){ const ov=ensureLoadingOverlay(); setTimeout(()=>{ ov.style.display='none'; }, 150); }
	async function loadUsers(){
		if (!userManage) return;
		showOverlay();
		try {
			const res = await fetch('../../backend/api/auth.php?action=list&cacheBust=' + Date.now());
			const data = await res.json();
			if (data.users && data.users.length) {
				userManage.innerHTML = `<table style="width:100%;margin-bottom:12px;" id="usersTable"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>${data.users.map(u => `<tr data-id="${u.id}"><td>${u.name}</td><td>${u.email}</td><td>${u.role}</td><td><span class="badge ${u.status==='inactive'?'text-bg-danger':'text-bg-success'}" id="status-${u.id}">${u.status||'active'}</span></td><td><button class='btn btn-sm btn-outline-${u.status==='inactive'?'success':'warning'}' data-action='toggle' data-id='${u.id}' style='margin-right:4px;min-width:92px;'>${u.status==='inactive'?'Activate':'Deactivate'}</button><button class='btn btn-sm btn-danger' data-action='delete' data-id='${u.id}' style='margin-right:4px;'>Delete</button><button class='btn btn-sm btn-primary' data-action='edit' data-id='${u.id}'>Edit</button></td></tr>`).join('')}</tbody></table><button class='btn btn-success' id='addUserBtn' style='padding:6px 14px;'>Add User</button>`;
			} else {
				userManage.textContent = 'No users found.';
			}
		} catch(e){ userManage.textContent = 'Error loading users.'; }
		finally { hideOverlay(); }
	}
	if (userManage) {
		loadUsers();
		// expose for PWA message handler to reuse
		window.__docmedLoadUsers = loadUsers;
		userManage.addEventListener('click', async (e) => {
			const btn = e.target.closest('button[data-action]');
			if (!btn) return;
			const id = btn.getAttribute('data-id');
			const action = btn.getAttribute('data-action');
			if (!id) return;
			const adminId = (localStorage.getItem('admin_id') || '').toString();
			function setBtnBusy(b, busy){
				if (!b) return;
				if (busy) {
					b.disabled = true;
					b.dataset.oldText = b.innerHTML;
					b.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (b.textContent.trim() || 'Working...');
				} else {
					b.disabled = false;
					if (b.dataset.oldText) b.innerHTML = b.dataset.oldText;
				}
			}
			if (action === 'delete') {
				const ok = await confirmDialog('Delete this user?');
				if (!ok) return;
				showOverlay(); setBtnBusy(btn, true);
				try {
					const res = await fetch('../../backend/api/manage_user.php?action=delete', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(id)}&admin_id=${encodeURIComponent(adminId)}` });
					let ok = res.ok; let json = null;
					try { json = await res.json(); } catch(_) { /* ignore */ }
					if (json && json.success === false) ok = false;
					if (ok) {
						// Remove row instantly
						const row = userManage.querySelector(`tr[data-id='${id}']`); if (row) row.remove();
						showToast('success', 'User deleted.');
					} else {
						showToast('error', (json && json.message) ? json.message : 'Failed to delete user.');
					}
				} finally { hideOverlay(); }
				return;
			}
			if (action === 'toggle') {
				// Compute next status
				const badge = document.getElementById('status-'+id);
				const current = badge ? badge.textContent.trim() : 'active';
				const next = current === 'inactive' ? 'active' : 'inactive';
				// Confirm only when deactivating
				if (next === 'inactive') {
					const ok = await confirmDialog('Deactivate this user account?', { okText: 'Deactivate', cancelText: 'Cancel' });
					if (!ok) return;
				}
				// Faster UX: just button spinner, no global overlay
				setBtnBusy(btn, true);
				try {
					const res = await fetch('../../backend/api/manage_user.php?action=toggle_status', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`id=${encodeURIComponent(id)}&status=${encodeURIComponent(next)}&admin_id=${encodeURIComponent(adminId)}` });
					let ok = res.ok; let json = null; try { json = await res.json(); } catch(_) {}
					if (json && json.success === false) ok = false;
					if (ok) {
						// Update badge immediately
						if (badge) {
							badge.textContent = next;
							badge.className = 'badge ' + (next==='inactive'?'text-bg-danger':'text-bg-success');
						}
						// Update toggle button label/style
						btn.className = 'btn btn-sm btn-outline-' + (next==='inactive'?'success':'warning');
						btn.textContent = next==='inactive'?'Activate':'Deactivate';
						showToast('success', `User ${next==='inactive'?'deactivated':'activated'}.`);
						if (next === 'inactive') {
							// Quick reload to reflect broader state
							setTimeout(() => { location.reload(); }, 200);
						}
					} else {
						showToast('error', (json && json.message) ? json.message : 'Failed to update status.');
					}
				} finally { /* no overlay to hide */ }
				setBtnBusy(btn, false);
				return;
			}
			if (action === 'edit') {
				showToast('info', 'Edit user feature coming soon!');
			}
		});
	}

	// Settings: Audit Trail (supports both settings section and full activity log page)
	(function initAuditTrail(){
		const auditContainer = document.getElementById('auditTrail') || document.getElementById('auditTrailTable');
		if (!auditContainer) return;
		async function loadAuditBasic(){
			try {
				const res = await fetch('../../backend/api/audit_trail.php?action=list&limit=100&cacheBust=' + Date.now());
				const data = await res.json();
				const logs = (data.logs||[]);
				// If container is a table (activity log page), inject rows; else build a table
				if (auditContainer.tagName === 'TABLE') {
					const tbody = auditContainer.querySelector('tbody'); if (!tbody) return;
					tbody.innerHTML = logs.length ? logs.map(log => `<tr><td>${new Date(log.timestamp).toLocaleString()}</td><td>${log.admin_name || ('Admin #' + log.admin_id)}</td><td>${log.action}</td><td>${log.details||''}</td></tr>`).join('') : '<tr><td colspan="4" style="text-align:center;color:#6b7280;padding:14px;">No audit logs found.</td></tr>';
				} else {
					auditContainer.innerHTML = logs.length ? `<table style="width:100%;margin-bottom:12px;"><thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>Date & Time</th></tr></thead><tbody>${logs.map(log => `<tr><td>${log.admin_name || 'Admin #' + log.admin_id}</td><td>${log.action}</td><td>${log.details}</td><td>${new Date(log.timestamp).toLocaleString()}</td></tr>`).join('')}</tbody></table>` : 'No audit logs found.';
				}
			} catch(e){ /* ignore */ }
		}
		loadAuditBasic();
		// Expose for SW invalidate refresh
		window.__docmedLoadAuditTrail = loadAuditBasic;
	})();

	// --- Settings Dropdown ---
	const settingsDropdown = document.getElementById('settingsDropdown');
	const settingsMenu = document.getElementById('settingsMenu');
	const settingsArrow = document.getElementById('settingsArrow');
	if (settingsDropdown && settingsMenu && settingsArrow) {
		settingsDropdown.onclick = function(e) {
			e.stopPropagation();
			const isOpen = settingsMenu.style.display === 'block';
			settingsMenu.style.display = isOpen ? 'none' : 'block';
			settingsArrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
		};
		document.body.onclick = function() {
			settingsMenu.style.display = 'none';
			settingsArrow.style.transform = 'rotate(0deg)';
		};
		// Add your section show/hide logic here for Manage Users, Audit Trail, Profile Info
		// Example:
		// document.getElementById('manageUsersLink').onclick = function(e) { ... }
		// document.getElementById('auditTrailLink').onclick = function(e) { ... }
		// document.getElementById('profileInfoLink').onclick = function(e) { ... }
	}

	// Legacy global functions removed (now inline handlers)
});

// --- PWA bootstrap (enable manifest + service worker for Admin & Doc/Nurse pages) ---
(function(){
	try {
		// Add manifest link if missing
		if (!document.querySelector('link[rel="manifest"]')) {
			const link = document.createElement('link');
			link.rel = 'manifest';
			// manifest located at repo root: ../../manifest-landing.json from frontend/* pages
			link.href = '../../manifest-landing.json';
			document.head.appendChild(link);
		}
		// Add theme-color meta if missing
		if (!document.querySelector('meta[name="theme-color"]')) {
			const m = document.createElement('meta');
			m.name = 'theme-color';
			m.content = '#0a6ecb';
			document.head.appendChild(m);
		}
		if ('serviceWorker' in navigator) {
			const swUrl = new URL('../../service-worker.js', window.location.href).toString();
			navigator.serviceWorker.register(swUrl).then(reg => {
				console.debug('[PWA] service worker registered', swUrl);
				// Listen for invalidation broadcasts from SW to refresh dynamic data
				navigator.serviceWorker.addEventListener('message', (evt) => {
					const msg = evt.data || {};
					if (msg.type === 'invalidate') {
						// Throttle multiple rapid invalidate events
						if (window.__docmedInvalidateTimer) return;
						window.__docmedInvalidateTimer = setTimeout(() => { window.__docmedInvalidateTimer = null; }, 1200);
						// Prefer soft refresh: re-fetch active tables if present instead of full reload
						const hasUserTable = document.getElementById('userManage');
						const auditTrailTable = document.getElementById('auditTrail') || document.querySelector('#auditTrailTable');
						let updated = false;
						if (hasUserTable) {
							// Re-fetch users list using same renderer
							if (window.__docmedLoadUsers) window.__docmedLoadUsers();
							updated = true;
						}
						if (auditTrailTable && auditTrailTable.tagName !== 'TABLE') {
							// Re-fetch audit trail summary (settings section variant)
							fetch('../../backend/api/audit_trail.php?action=list&limit=50&cacheBust=' + Date.now())
								.then(r => r.json()).then(d => {
									if (d.logs && d.logs.length) {
										auditTrailTable.innerHTML = `<table style="width:100%;margin-bottom:12px;"><thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>Date & Time</th></tr></thead><tbody>${d.logs.map(log => `<tr><td>${log.admin_name || 'Admin #' + log.admin_id}</td><td>${log.action}</td><td>${log.details}</td><td>${new Date(log.timestamp).toLocaleString()}</td></tr>`).join('')}</tbody></table>`;
									}
								});
							updated = true;
						}
						// If no targeted component updated, fallback to a mild reload (avoid full hard reload) to reflect changes
						if (!updated) {
							// Use location.reload(false) to leverage HTTP cache but our SW network-first ensures fresh API
							location.reload();
						}
					}
				});
			}).catch(()=>{/* ignore */});
		}
	} catch (e) { console.debug('[PWA] init failed', e); }
})();