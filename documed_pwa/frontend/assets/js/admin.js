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

	// Settings: Manage Users
	const userManage = document.getElementById('userManage');
	if (userManage) {
		fetch('../../backend/api/auth.php?action=list')
			.then(res => res.json())
			.then(data => {
				if (data.users && data.users.length) {
					userManage.innerHTML = `<table style="width:100%;margin-bottom:12px;"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody>${data.users.map(u => `<tr><td>${u.name}</td><td>${u.email}</td><td>${u.role}</td><td><button class='btn' onclick='deleteUser(${u.id})' style='background:#e11d48;padding:4px 12px;'>Delete</button> <button class='btn' onclick='editUser(${u.id})' style='background:#2563eb;padding:4px 12px;'>Edit</button></td></tr>`).join('')}</tbody></table><button class='btn' id='addUserBtn' style='background:#22c55e;'>Add User</button>`;
				} else {
					userManage.textContent = 'No users found.';
				}
			});
	}

	// Settings: Audit Trail
	const auditTrail = document.getElementById('auditTrail');
	if (auditTrail) {
		fetch('../../backend/api/audit_trail.php?action=list')
			.then(res => res.json())
			.then(data => {
				if (data.logs && data.logs.length) {
					auditTrail.innerHTML = `<table style="width:100%;margin-bottom:12px;"><thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>Date & Time</th></tr></thead><tbody>${data.logs.map(log => `<tr><td>${log.admin_name || 'Admin #' + log.admin_id}</td><td>${log.action}</td><td>${log.details}</td><td>${new Date(log.timestamp).toLocaleString()}</td></tr>`).join('')}</tbody></table>`;
				} else {
					auditTrail.textContent = 'No audit logs found.';
				}
			});
	}

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

	// User management actions (delete/edit/add)
	window.deleteUser = function(id) {
		if (confirm('Delete this user?')) {
			fetch('../../backend/api/auth.php?action=delete', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: `id=${id}`
			}).then(() => location.reload());
		}
	};
	window.editUser = function(id) {
		alert('Edit user feature coming soon!');
	};
	const addUserBtn = document.getElementById('addUserBtn');
	if (addUserBtn) {
		addUserBtn.onclick = function() {
			alert('Add user feature coming soon!');
		};
	}
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
			// Prefer the top-level service-worker in repo root; compute relative URL from current page
			const swUrl = new URL('../../service-worker.js', window.location.href).toString();
			navigator.serviceWorker.register(swUrl).then(() => {
				console.debug('[PWA] service worker registered', swUrl);
			}).catch(()=>{
				// swallow errors -- other pages may register a different SW (public/service-worker.js)
			});
		}
	} catch (e) { console.debug('[PWA] init failed', e); }
})();