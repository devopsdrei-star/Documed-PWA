// Reports page JS: fetch and display reports from backend
document.addEventListener('DOMContentLoaded', function() {
	// Load Chart.js CDN if not present
	if (!window.Chart) {
		const script = document.createElement('script');
		script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
		script.onload = () => {};
		document.head.appendChild(script);
	}
	// Load datalabels plugin for Chart.js (for percentages on pie)
	if (!window.ChartDataLabels) {
		const script2 = document.createElement('script');
		script2.src = 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2';
		document.head.appendChild(script2);
	}
	const reportType = document.getElementById('reportType');
	const dailyDate = document.getElementById('dailyDate');
	const weekStart = document.getElementById('weekStart');
	const weekEnd = document.getElementById('weekEnd');
	const monthInput = document.getElementById('monthInput');
	const rangeStart = document.getElementById('rangeStart');
	const rangeEnd = document.getElementById('rangeEnd');
	const illnessDateModeContainer = document.getElementById('illnessDateModeContainer');
	const illnessDateMode = document.getElementById('illnessDateMode');
	const reportForm = document.getElementById('reportForm');
	const reportResult = document.getElementById('reportResult');
	const reportsLoading = document.getElementById('reportsLoading');
	const audienceFilter = document.getElementById('audienceFilter');
	const deptFilter = document.getElementById('deptFilter');
	const yearFilter = document.getElementById('yearFilter');
	const courseFilter = document.getElementById('courseFilter');
	const clinicOverview = document.getElementById('clinicOverview');
	const overviewNav = document.getElementById('overviewSectionsNav');
	const showAllSectionsBtn = document.getElementById('showAllSectionsBtn');
	const printBtn = document.getElementById('printReportBtn');
	const analyticsRow = document.getElementById('analyticsRow');
	const pieChartContainer = document.getElementById('pieChartContainer');
	const reportSubtitle = document.getElementById('reportSubtitle');

	// Course catalog per department
	const COURSE_CATALOG = {
		CASL: [
			'Bachelor of Arts in English Language',
			'Bachelor of Arts in Economics',
			'Bachelor of Science in Biology',
			'Bachelor of Science in Nutrition and Dietetics',
			'Bachelor of Science in Social Work'
		],
		CBPA: [
			'Bachelor of Public Administration',
			'Bachelor of Science in Business Administration - Major in Financial Management',
			'Bachelor of Science in Business Administration - Major in Operations Management'
		],
		CCS: [
			'Bachelor of Science in Computer Science',
			'Bachelor of Science in Information Technology',
			'Bachelor of Science in Mathematics - Major in Pure Math',
			'Bachelor of Science in Mathematics - Major in Statistics',
			'Bachelor of Science in Mathematics - Major in CIT'
		],
		CHTM: [
			'Bachelor of Science Hospitality Management'
		],
		CTE: [
			'Bachelor of Secondary Education',
			'Bachelor of Technical - Vocational Teacher Education',
			'Bachelor of Technology and Livelihood Education'
		],
		CIT: [
			'Bachelor Industrial Technology',
			'Major in Automotive Technology',
			'Major in Ceramics Technology',
			'Major in Civil Technology',
			'Major in Drafting Technology',
			'Major in Electrical Technology',
			'Major in Electronics Technology',
			'Major in Food Service Management',
			'Major in Garments, Fashion and Design',
			'Major in Mechanical Technology'
		]
	};

	// Populate courses based on department
	function syncCourses() {
		if (!courseFilter) return;
		const dept = (deptFilter && deptFilter.value) || 'All';
		const list = COURSE_CATALOG[dept] || [];
		courseFilter.innerHTML = '';
		const optAll = document.createElement('option'); optAll.value = ''; optAll.textContent = 'All'; courseFilter.appendChild(optAll);
		list.forEach(name => { const o = document.createElement('option'); o.value = name; o.textContent = name; courseFilter.appendChild(o); });
		// Make dropdown scrollable if long
		try { courseFilter.size = 0; courseFilter.style.maxHeight = '220px'; courseFilter.style.overflowY = 'auto'; } catch(_) {}
	}

	if (deptFilter) {
		deptFilter.addEventListener('change', () => { syncCourses(); updateSubtitle(); triggerRender(); });
	}
	if (yearFilter) {
		yearFilter.addEventListener('change', () => { updateSubtitle(); triggerRender(); });
	}
	if (courseFilter) {
		courseFilter.addEventListener('change', () => { updateSubtitle(); triggerRender(); });
	}

	// Remember last pie data for responsive resizing
	window.lastPieData = null;
	window.pieResizeBound = false;

	function fmtRange(start, end) {
		if (!start && !end) return '';
		const fmt = (d)=>{
			const date = new Date(d);
			if (Number.isNaN(date.getTime())) return d;
			return date.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'2-digit' });
		};
		if (start && end) return `${fmt(start)} - ${fmt(end)}`;
		return fmt(start || end);
	}

	function updateSubtitle() {
		if (!reportSubtitle) return;
		let sub = '';
		if (reportType.value === 'clinic_overview') {
			sub = `Overview • ${fmtRange(rangeStart.value, rangeEnd.value) || 'Select a date range'}`;
		} else {
			const mode = illnessDateMode ? illnessDateMode.value : 'all';
			let period = '';
			if (mode === 'daily') period = fmtRange(dailyDate.value);
			else if (mode === 'weekly') period = fmtRange(weekStart.value, weekEnd.value);
			else if (mode === 'monthly' && monthInput.value) {
				const [y,m] = monthInput.value.split('-');
				if (y && m) {
					const dt = new Date(Number(y), Number(m)-1, 1);
					period = dt.toLocaleDateString(undefined, { year:'numeric', month:'long' });
				}
			} else period = 'All time';
			const aud = audienceFilter ? audienceFilter.value : 'Student';
			sub = `Illness Statistics • ${aud} • ${period || 'All time'}`;
		}
		reportSubtitle.textContent = sub;
	}

	function setActiveOverviewSection(targetId) {
		const sections = clinicOverview ? clinicOverview.querySelectorAll('.report-section') : [];
		sections.forEach(sec => {
			if (!targetId || targetId === 'ALL') {
				sec.style.display = 'block';
			} else {
				sec.style.display = (sec.id === targetId) ? 'block' : 'none';
			}
		});
		if (overviewNav) {
			overviewNav.querySelectorAll('.ov-btn').forEach(btn => {
				if (!btn.dataset.target) return;
				btn.classList.toggle('active', !!targetId && btn.dataset.target === targetId);
			});
			if (!targetId || targetId === 'ALL') {
				overviewNav.querySelectorAll('.ov-btn').forEach(btn => btn.classList.remove('active'));
			}
		}
	}

	function updateInputs() {
		// hide all first
		if (dailyDate) dailyDate.style.display = 'none';
		if (weekStart) weekStart.style.display = 'none';
		if (weekEnd) weekEnd.style.display = 'none';
		if (monthInput) monthInput.style.display = 'none';
		if (rangeStart) rangeStart.style.display = 'none';
		if (rangeEnd) rangeEnd.style.display = 'none';
		if (illnessDateModeContainer) illnessDateModeContainer.style.display = 'none';
		// show Audience by default (Illness Stats); hide for Clinic Overview
		const audienceContainer = document.getElementById('audienceContainer');
		if (audienceContainer) audienceContainer.style.display = 'block';
		// also hide result areas until Generate
		if (clinicOverview) clinicOverview.style.display = 'none';
		if (analyticsRow) analyticsRow.style.display = 'none';
		reportResult.innerHTML = '';

		// toggle Print PDF button visibility per report type (guard if sorter removed)
		if (printBtn) {
			printBtn.style.display = (reportType && reportType.value === 'clinic_overview') ? 'inline-block' : 'none';
		}
		const isIllness = !reportType || reportType.value === 'illness_stats';
		const isOverview = reportType && reportType.value === 'clinic_overview';

		if (isIllness) {
			// show illness date mode dropdown
			if (illnessDateModeContainer) illnessDateModeContainer.style.display = 'block';
			const mode = illnessDateMode ? illnessDateMode.value : 'all';
			if (mode === 'daily') {
				if (dailyDate) dailyDate.style.display = 'inline-block';
			} else if (mode === 'weekly') {
				if (weekStart) weekStart.style.display = 'inline-block';
				if (weekEnd) weekEnd.style.display = 'inline-block';
			} else if (mode === 'monthly') {
				if (monthInput) monthInput.style.display = 'inline-block';
			}
			// Show filters per audience: Students -> Year/Course; Faculty/Staff -> Department
			const aud = audienceFilter ? audienceFilter.value : 'All';
			const deptC = document.getElementById('deptContainer');
			const yearC = document.getElementById('yearContainer');
			const courseC = document.getElementById('courseContainer');
			if (aud === 'Student') {
				// Show department too for students (college), plus year & course
				if (deptC) deptC.style.display = 'flex';
				if (yearC) yearC.style.display = 'flex';
				if (courseC) courseC.style.display = 'flex';
			} else if (aud === 'Faculty' || aud === 'Staff') {
				if (deptC) deptC.style.display = 'flex';
				if (yearC) yearC.style.display = 'none';
				if (courseC) courseC.style.display = 'none';
			} else {
				if (deptC) deptC.style.display = 'none';
				if (yearC) yearC.style.display = 'none';
				if (courseC) courseC.style.display = 'none';
			}
		} else if (isOverview) {
			// use custom range for overview
			if (dailyDate) dailyDate.style.display = 'none';
			if (weekStart) weekStart.style.display = 'none';
			if (weekEnd) weekEnd.style.display = 'none';
			if (monthInput) monthInput.style.display = 'none';
			if (rangeStart) rangeStart.style.display = 'inline-block';
			if (rangeEnd) rangeEnd.style.display = 'inline-block';
			if (audienceContainer) audienceContainer.style.display = 'none';
			if (rangeStart && !rangeStart.value) { const d = new Date(); d.setDate(1); rangeStart.value = d.toISOString().slice(0,10); }
			if (rangeEnd && !rangeEnd.value) { const d = new Date(); d.setMonth(d.getMonth()+1,0); rangeEnd.value = d.toISOString().slice(0,10); }
		}
		updateSubtitle();
	}
	if (reportType) reportType.addEventListener('change', updateInputs);
	if (illnessDateMode) illnessDateMode.addEventListener('change', updateInputs);
	['change','input'].forEach(evt=>{
		[dailyDate, weekStart, weekEnd, monthInput, rangeStart, rangeEnd, audienceFilter].forEach(el=>{
			if (el) el.addEventListener(evt, updateSubtitle);
		});
	});
	updateInputs();

	function tableFromRows(title, rows, showTotals=true, totals=null) {
		if (!rows) return '';
		let html = `<div style="overflow-x:auto;">`;
		if (title) html += `<div style=\"font-weight:700;margin:8px 0 6px;\">${title}</div>`;
		html += `<table class="report"><thead><tr><th style="min-width:280px;">Particulars</th><th>Students</th><th>Faculty</th><th>Staff</th></tr></thead><tbody>`;
		rows.forEach(r=>{
			html += `<tr><td>${r.label}</td><td>${r.Student||0}</td><td>${r.Faculty||0}</td><td>${r.Staff||0}</td></tr>`;
		});
		if (showTotals && totals) {
			html += `<tr><th>Total</th><th>${totals.Student||0}</th><th>${totals.Faculty||0}</th><th>${totals.Staff||0}</th></tr>`;
		}
		html += `</tbody></table></div>`;
		return html;
	}

	// Export a section's first table to CSV
	function exportSectionCSV(sectionId) {
		const sec = document.getElementById(sectionId);
		if (!sec) return;
		const table = sec.querySelector('table.report');
		if (!table) return;
		let csv = [];
		for (const row of table.querySelectorAll('tr')) {
			const cols = [...row.querySelectorAll('th,td')].map(td => {
				let text = (td.textContent || '').trim();
				if (text.includes('"') || text.includes(',') || text.includes('\n')) text = '"' + text.replace(/"/g, '""') + '"';
				return text;
			});
			csv.push(cols.join(','));
		}
		const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
		const link = document.createElement('a');
		const name = sectionId.replace(/Container$/,'').replace(/([a-z])([A-Z])/g,'$1_$2').toLowerCase();
		link.download = `${name}_${new Date().toISOString().slice(0,10)}.csv`;
		link.href = URL.createObjectURL(blob);
		link.click();
		setTimeout(()=>URL.revokeObjectURL(link.href), 1000);
	}

	// Print a specific section only
	function printSection(sectionId) {
		const sec = document.getElementById(sectionId);
		if (!sec) return;
		const doc = window.document;
		const prevHTML = doc.body.innerHTML;
		doc.body.innerHTML = sec.outerHTML;
		window.print();
		doc.body.innerHTML = prevHTML;
		// Re-bind events after DOM swap
		setTimeout(() => { updateInputs(); }, 50);
	}

	function getPalette(n) {
		const base = ['#2563eb','#22c55e','#eab308','#e11d48','#a21caf','#06b6d4','#f97316','#16a34a','#9333ea','#0ea5e9','#f59e0b'];
		if (n <= base.length) return base.slice(0,n);
		const out = [];
		for (let i=0;i<n;i++) {
			out.push(`hsl(${Math.round((360/n)*i)}, 75%, 50%)`);
		}
		return out;
	}

	function renderChartTo(canvasId, labels, values, title, legendId) {
		const canvas = document.getElementById(canvasId);
		if (!canvas) return;
		const ctx = canvas.getContext('2d');
		const colors = getPalette(labels.length || 1);
		const card = canvas.parentElement;
		const width = card.clientWidth;
		const h = Math.min(420, Math.max(280, Math.floor(width*0.66)));
		canvas.width = width; canvas.height = h;
		if (window[canvasId+"Instance"]) { try { window[canvasId+"Instance"].destroy(); } catch(e){} }
		window[canvasId+"Instance"] = new Chart(ctx, {
			type: 'pie',
			data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
			options: {
				responsive: false,
				plugins: {
					legend: { position: 'bottom', labels: { boxWidth: 14, boxHeight: 14, font: { size: 12 } } },
					title: { display: !!title, text: title, color: '#2563eb', font: { size: 16, weight: '600' } },
					tooltip: {
						callbacks: {
							label: (ctx) => {
								const total = ctx.dataset.data.reduce((a,b)=>a+Number(b||0),0) || 1;
								const val = Number(ctx.parsed || 0);
								const pct = (val/total*100).toFixed(1)+'%';
								return `${ctx.label}: ${val} (${pct})`;
							}
						}
					},
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
		if (legendId) {
			const total = values.reduce((a,b)=>a+Number(b||0),0) || 1;
			document.getElementById(legendId).innerHTML = labels.map((l,i)=>{
				const v = Number(values[i]||0);
				const pct = ((v/total)*100).toFixed(1);
				return `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin:2px 0;">
					<span style="display:flex;align-items:center;gap:6px;"><span style="width:12px;height:12px;background:${getPalette(labels.length)[i]};display:inline-block;border-radius:3px;border:1px solid #e5e7eb;"></span>${l}</span>
					<span><strong>${v}</strong> (${pct}%)</span>
				</div>`;
			}).join('');
		}
	}

	function renderStackedBar(canvasId, labels, roleSeries, title, legendId) {
		const canvas = document.getElementById(canvasId);
		if (!canvas) return;
		const ctx = canvas.getContext('2d');
		const card = canvas.parentElement;
		const width = card.clientWidth;
		const h = Math.max(240, Math.min(800, labels.length * 32 + 80));
		canvas.width = width; canvas.height = h;
		const ds = [
			{ label: 'Students', data: roleSeries.Student || [], backgroundColor: '#2563eb' },
			{ label: 'Faculty', data: roleSeries.Faculty || [], backgroundColor: '#22c55e' },
			{ label: 'Staff', data: roleSeries.Staff || [], backgroundColor: '#eab308' }
		];
		// no data overlay handling
		const totalSum = [...(roleSeries.Student||[]), ...(roleSeries.Faculty||[]), ...(roleSeries.Staff||[])]
			.reduce((a,b)=>a+Number(b||0),0);
		card.style.position = 'relative';
		let overlay = card.querySelector(`#${canvasId}NoData`);
		if (overlay) overlay.remove();
		if (legendId) { const el = document.getElementById(legendId); if (el) el.textContent = ''; }
		if (window[canvasId+"Instance"]) { try { window[canvasId+"Instance"].destroy(); } catch(e){} }
		window[canvasId+"Instance"] = new Chart(ctx, {
			type: 'bar',
			data: { labels, datasets: ds },
			options: {
				indexAxis: 'y',
				responsive: false,
				scales: {
					x: { stacked: true, ticks: { precision:0, color:'#334155', font:{ size:12 } }, grid: { color: '#e5e7eb' } },
					y: { stacked: true, ticks: { color:'#334155', font:{ size:12 } }, grid: { display:false } }
				},
				plugins: {
					legend: { position: 'bottom' },
					title: { display: !!title, text: title, color: '#2563eb', font: { size: 16, weight: '600' } },
					datalabels: {
						anchor: 'end', align: 'right', color: '#111827',
						formatter: (value, ctx) => {
							const idx = ctx.dataIndex;
							if (ctx.datasetIndex !== ds.length-1) return '';
							let total = 0; ds.forEach(d => total += Number(d.data[idx]||0));
							return total ? total : '';
						},
						font: { weight: '600' }
					}
				}
			},
			plugins: [window.ChartDataLabels ? ChartDataLabels : {}]
		});
		if (!totalSum) {
			overlay = document.createElement('div');
			overlay.id = `${canvasId}NoData`;
			overlay.style.position = 'absolute';
			overlay.style.inset = '0';
			overlay.style.display = 'flex';
			overlay.style.alignItems = 'center';
			overlay.style.justifyContent = 'center';
			overlay.style.color = '#64748b';
			overlay.style.fontWeight = '600';
			overlay.textContent = 'No data for selected period';
			card.appendChild(overlay);
			if (legendId) {
				const el = document.getElementById(legendId);
				if (el) el.textContent = 'No data for selected period';
			}
		}
	}

	async function renderClinicOverview() {
		if (reportsLoading) reportsLoading.style.display = 'block';
		// Derive date range even if inputs are missing (doc_nurse minimal layout)
		const deriveRange = () => {
			if (rangeStart && rangeEnd && rangeStart.value && rangeEnd.value) {
				return { start: rangeStart.value, end: rangeEnd.value };
			}
			const d1 = new Date(); d1.setDate(1);
			const d2 = new Date(); d2.setMonth(d2.getMonth()+1, 0);
			return { start: d1.toISOString().slice(0,10), end: d2.toISOString().slice(0,10) };
		};
		const { start, end } = deriveRange();
		const url = `../../backend/api/report.php?action=clinic_overview&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
		reportResult.innerHTML = '';
		if (analyticsRow) analyticsRow.style.display = 'none';
		const res = await fetch(url);
		// Robust JSON parsing with fallback display
		let dataText; let data; try {
			dataText = await res.text();
			data = JSON.parse(dataText);
		} catch (e) {
			console.error('Clinic overview JSON parse failed', e);
			if (clinicOverview) clinicOverview.style.display = 'none';
			reportResult.innerHTML = '<div style="color:#dc2626;font-weight:600;">Clinic overview error: invalid JSON response</div>' +
				'<pre style="white-space:pre-wrap;font-size:11px;background:#f8fafc;border:1px solid #e2e8f0;padding:6px;max-height:240px;overflow:auto;">' +
				(dataText ? dataText.replace(/</g,'&lt;') : '(empty response)') + '</pre>';
			if (reportsLoading) reportsLoading.style.display = 'none';
			return;
		}
		if (!data || !data.success) {
			if (clinicOverview) clinicOverview.style.display = 'none';
			reportResult.innerHTML = '<span style="color:red">No data</span>';
			if (reportsLoading) reportsLoading.style.display = 'none';
			return;
		}
		if (clinicOverview) clinicOverview.style.display = 'block';

		// PSU print header (print-only) with values from localStorage if present
		const campusRaw = (localStorage.getItem('psu_campus') || 'Lingayen').trim();
		const campus = campusRaw.toUpperCase();
		const locationRaw = (localStorage.getItem('psu_location') || `${campusRaw}, Pangasinan`).trim();
		const locationLine = locationRaw.toUpperCase();
		const quarterRaw = (localStorage.getItem('psu_quarter') || '').trim(); // e.g., Q2 2025 or 2nd Quarter 2025
		const quarterTitle = (function(q){
			if (!q) return '';
			const m = q.match(/q([1-4])\s*(\d{4})/i);
			if (m) {
				const n = Number(m[1]); const y = m[2];
				const ord = n===1?'1ST':n===2?'2ND':n===3?'3RD':'4TH';
				return `${ord} QUARTER ${y}`;
			}
			// Try matching like "2nd Quarter 2025"
			const m2 = q.match(/(1st|2nd|3rd|4th)\s+quarter\s+(\d{4})/i);
			if (m2) { return `${m2[1].toUpperCase()} QUARTER ${m2[2]}`; }
			return q.toUpperCase();
		})(quarterRaw);
		const printHeaderHTML = `
			<div class="psu-print-header print-only">
				<img class="psu-logo-right" src="../assets/images/documed_logo.png" alt="DocuMed Logo" />
				<div class="header-center">
					<div class="line-1">PANGASINAN STATE UNIVERSITY</div>
					<div class="line-2">${campus} CAMPUS</div>
					<div class="line-3">${locationLine}</div>
					<div class="line-2" style="margin-top:2px;">MEDICAL-DENTAL UNIT</div>
				</div>
			</div>`;
		// Cases seen (header box)
		const cs = data.casesSeen || { withIllness:{}, otherServices:{}, grandTotal:{} };
		const csTable = `
			<table class="report"><thead><tr><th>Cases Seen</th><th>No. of Students</th><th>No. of Faculty</th><th>No. of Staff</th></tr></thead>
			<tbody>
			<tr><td>With Illness</td><td>${cs.withIllness.Student||0}</td><td>${cs.withIllness.Faculty||0}</td><td>${cs.withIllness.Staff||0}</td></tr>
			<tr><td>Other Services except consultation and treatment of diseases</td><td>${cs.otherServices.Student||0}</td><td>${cs.otherServices.Faculty||0}</td><td>${cs.otherServices.Staff||0}</td></tr>
			<tr><td>Pre Assessment Health Form</td><td><strong>Total:</strong></td><td></td><td><strong>${(cs.grandTotal.Student||0)+(cs.grandTotal.Faculty||0)+(cs.grandTotal.Staff||0)}</strong></td></tr>
			</tbody></table>`;
		const tableTitleBlock = quarterTitle ? `<div class="print-only" style="text-align:center; font-weight:700; margin:8px 0;">TABLE 38 MEDICAL SERVICES</div>
		<div class="print-only" style="text-align:center; font-weight:700; margin-bottom:8px;">${quarterTitle}</div>` : `<div class="print-only" style="text-align:center; font-weight:700; margin:8px 0;">TABLE 38 MEDICAL SERVICES</div>`;
		const casesSeenEl = document.getElementById('casesSeenContainer');
		if (!casesSeenEl) {
			// Minimal fallback rendering when printable containers are not present
			reportResult.innerHTML = '<h3>Clinic Overview (Summary)</h3>' +
				`<div><strong>Period:</strong> ${data.period.start} to ${data.period.end}</div>` +
				`<div style="margin-top:8px;">Use the Admin Reports page for the full printable layout.</div>`;
			if (reportsLoading) reportsLoading.style.display = 'none';
			return;
		}
		casesSeenEl.innerHTML = `
			${printHeaderHTML}
			${tableTitleBlock}
			<div class="section-header"><span>Cases Seen + Top Illnesses</span></div>
			${csTable}
			<hr style="margin:14px 0; border:0; border-top:1px solid #000;">
			<div id="combinedTopIllness">Loading...</div>`;
		// Top illnesses per role (1..5 rows)
		const tb = data.illnesses?.topByRole || {};
		const rows = [1,2,3,4,5].map(i=>({
			label: i,
			Student: tb.Student?.[i-1] || '',
			Faculty: tb.Faculty?.[i-1] || '',
			Staff: tb.Staff?.[i-1] || ''
		}));
		const topIllTable = `
			<table class="report"><thead><tr><th style="width:60px;"></th><th>Among Students</th><th>Among Faculty</th><th>Among Staff</th></tr></thead>
			<tbody>${rows.map(r=>`<tr><td>${r.label}</td><td>${r.Student}</td><td>${r.Faculty}</td><td>${r.Staff}</td></tr>`).join('')}</tbody></table>`;
		document.getElementById('combinedTopIllness').innerHTML = topIllTable;

		// Medications Given to: summary block from totals
		const medTotals = data.medicines?.totals || { Student:0, Faculty:0, Staff:0 };
		const medGrand = (data.medicines?.grand ?? ((medTotals.Student||0)+(medTotals.Faculty||0)+(medTotals.Staff||0)));
		const medsSummary = `
			<div class="print-only" style="margin-top:14px;"><strong>Medications Given to:</strong></div>
			<div class="print-only" style="display:grid; grid-template-columns:180px 1fr; max-width:420px; gap:4px 8px;">
				<div>Students&nbsp;:</div><div>${medTotals.Student||0} tabs/caps</div>
				<div>Faculty&nbsp;:</div><div>${medTotals.Faculty||0} tabs/caps</div>
				<div>Staff&nbsp;:</div><div>${medTotals.Staff||0} tabs/caps</div>
				<div><strong>Total&nbsp;:</strong></div><div><strong>${medGrand||0} tabs/caps</strong></div>
			</div>`;
		casesSeenEl.insertAdjacentHTML('beforeend', medsSummary);
		// Medicines table + pie
		const medRows = data.medicines?.rows || [];
		const medTable = tableFromRows('', medRows, true, data.medicines?.totals || {});
		const medsTableEl = document.getElementById('medicinesTableContainer');
		if (medsTableEl) medsTableEl.innerHTML = `
			${printHeaderHTML}
			${tableTitleBlock}
			<div class="section-header"><span>Medicines Given/Distributed</span></div>
			${medTable}`;
		// Services table + pie
		const svcRows = data.services?.rows || [];
		const svcTable = tableFromRows('', svcRows, true, data.services?.totals || {});
		const servicesTableEl = document.getElementById('servicesTableContainer');
		if (servicesTableEl) servicesTableEl.innerHTML = `
			${printHeaderHTML}
			${tableTitleBlock}
			<div class="section-header"><span>Medical Services Rendered</span></div>
			${svcTable}`;
		// Illnesses detailed counts + pie
		const disRows = data.illnesses?.rows || [];
		const disTable = tableFromRows('', disRows, true, data.illnesses?.totals || {});
		const illnessesTableEl = document.getElementById('illnessesTableContainer');
		if (illnessesTableEl) illnessesTableEl.innerHTML = `
			${printHeaderHTML}
			${tableTitleBlock}
			<div class="section-header"><span>Medical Illness/Disease</span></div>
			${disTable}`;

		// Add signatories at the end of the combined page (below cases/illnesses summary)
		const sigHTML = `
			<div class="print-signatories">
				<div class="sig-grid">
					<div class="sig">
						<div class="sig-name">PAZ CERI ANN V. SORIANO, RN, MAN</div>
						<div class="sig-title">Coordinator, Medical and Dental Unit</div>
					</div>
					<div class="sig">
						<div class="sig-name">RHEGINA F. TUBERA</div>
						<div class="sig-title">Dean, Students and Alumni Affairs</div>
					</div>
					<div class="sig">
						<div class="sig-name">FLORIJEAN F. TAPIA, RN, MAN</div>
						<div class="sig-title">Campus Nurse III</div>
					</div>
					<div class="sig">
						<div class="sig-name">RENATO E. SALCEDO, Ph.D.</div>
						<div class="sig-title">Campus Executive Director</div>
					</div>
					<div class="sig">
						<div class="sig-name">CHRISTIA MARIE P. FLORES, M.D.</div>
						<div class="sig-title">University Physician</div>
					</div>
				</div>
			</div>`;
		if (casesSeenEl) casesSeenEl.insertAdjacentHTML('beforeend', sigHTML);

			// Add a toggle to show raw checkups (individual assessments) for debugging / verification
			let rawBtn = document.getElementById('showRawCheckupsBtn');
			if (!rawBtn && casesSeenEl) {
				rawBtn = document.createElement('button');
				rawBtn.id = 'showRawCheckupsBtn';
				rawBtn.textContent = 'Show raw checkups';
				rawBtn.style.marginTop = '12px';
				rawBtn.className = 'btn btn-sm';
				casesSeenEl.appendChild(rawBtn);
				const rawContainer = document.createElement('div');
				rawContainer.id = 'rawCheckupsContainer';
				rawContainer.style.marginTop = '10px';
				casesSeenEl.appendChild(rawContainer);
				rawBtn.addEventListener('click', async () => {
					if (rawContainer.dataset.shown === '1') {
						rawContainer.innerHTML = '';
						rawContainer.dataset.shown = '0';
						rawBtn.textContent = 'Show raw checkups';
						return;
					}
					rawBtn.textContent = 'Loading...';
					const url2 = `../../backend/api/report.php?action=debug_checkups&start=${encodeURIComponent(data.period.start)}&end=${encodeURIComponent(data.period.end)}`;
					try {
						const r2 = await fetch(url2);
						const j2 = await r2.json();
						if (!j2 || !j2.success) {
							rawContainer.innerHTML = `<div style="color:#dc2626">Failed to fetch raw checkups</div><pre>${(j2 && j2.error) ? j2.error : JSON.stringify(j2)}</pre>`;
							rawBtn.textContent = 'Show raw checkups';
							return;
						}
						// build a simple table
						let html = '<div style="overflow:auto; max-height:300px; border:1px solid #e5e7eb; padding:8px; background:#fff"><table class="report" style="width:100%;"><thead><tr><th>ID</th><th>Name</th><th>Date</th><th>Assessment</th><th>Present illness</th><th>Remarks</th></tr></thead><tbody>';
						j2.rows.forEach(rw => {
							html += `<tr><td>${(rw.id||'')}</td><td>${(rw.name||'')}</td><td>${(rw.created_at||'')}</td><td>${(rw.assessment||'')}</td><td>${(rw.present_illness||'')}</td><td>${(rw.remarks||'')}</td></tr>`;
						});
						html += '</tbody></table></div>';
						rawContainer.innerHTML = html;
						rawContainer.dataset.shown = '1';
						rawBtn.textContent = 'Hide raw checkups';
					} catch (err) {
						rawContainer.innerHTML = `<div style="color:#dc2626">Error fetching raw checkups</div><pre>${err}</pre>`;
						rawBtn.textContent = 'Show raw checkups';
					}
				});
			}
		if (medsTableEl) medsTableEl.insertAdjacentHTML('beforeend', sigHTML);
		if (servicesTableEl) servicesTableEl.insertAdjacentHTML('beforeend', sigHTML);
		if (illnessesTableEl) illnessesTableEl.insertAdjacentHTML('beforeend', sigHTML);

		// no charts for printable overview
		if (reportsLoading) reportsLoading.style.display = 'none';

		// Initialize section nav (default to Cases Seen)
		setActiveOverviewSection('casesSeenContainer');
		if (overviewNav && !overviewNav.dataset.bound) {
			overviewNav.addEventListener('click', (e) => {
				const btn = e.target.closest('.ov-btn');
				if (!btn) return;
				const tgt = btn.dataset.target;
				if (tgt) { setActiveOverviewSection(tgt); }
			});
			overviewNav.dataset.bound = '1';
		}

		// Per-section action buttons removed per request
		if (showAllSectionsBtn && !showAllSectionsBtn.dataset.bound) {
			showAllSectionsBtn.addEventListener('click', () => setActiveOverviewSection('ALL'));
			showAllSectionsBtn.dataset.bound = '1';
		}
	}

	if (printBtn) {
		printBtn.addEventListener('click', () => { window.print(); });
	}

	// Removed top Generate button; only the form Generate remains

	// Static illness lists based on the provided image
	async function fetchIllnessStats(audience) {
		const role = audience;
		// date params when illness stats selected
		let url = `../../backend/api/report.php?action=illness_stats&role=${encodeURIComponent(role)}`;
		const mode = illnessDateMode ? illnessDateMode.value : 'all';
		if (mode && mode !== 'all') {
			url += `&dateMode=${encodeURIComponent(mode)}`;
			if (mode === 'daily' && dailyDate && dailyDate.value) {
				url += `&date=${encodeURIComponent(dailyDate.value)}`;
			} else if (mode === 'weekly' && weekStart && weekEnd && weekStart.value && weekEnd.value) {
				url += `&start=${encodeURIComponent(weekStart.value)}&end=${encodeURIComponent(weekEnd.value)}`;
			} else if (mode === 'monthly' && monthInput && monthInput.value) {
				const [year, month] = monthInput.value.split('-');
				if (year && month) url += `&month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`;
			}
		}
		// role-specific detail filters
		if (audience === 'Student') {
			const dept = deptFilter ? deptFilter.value : 'All';
			const year = yearFilter ? (yearFilter.value || '') : '';
			const course = courseFilter ? (courseFilter.value || '') : '';
			if (dept && dept !== 'All') url += `&department=${encodeURIComponent(dept)}`;
			if (year) url += `&year=${encodeURIComponent(year)}`;
			if (course) url += `&course=${encodeURIComponent(course)}`;
		} else if (audience === 'Faculty' || audience === 'Staff') {
			const dept = deptFilter ? deptFilter.value : 'All';
			if (dept && dept !== 'All') url += `&department=${encodeURIComponent(dept)}`;
		}
		const res = await fetch(url);
		const data = await res.json();
		if (data && data.labels && data.values) return data;
		return { labels: [], values: [] };
	}

	function renderPie(labels, values, title = 'Illness Statistics') {
		if (reportsLoading) reportsLoading.style.display = 'block';
		if (clinicOverview) clinicOverview.style.display = 'none';
		if (reportResult) reportResult.innerHTML = '';
		if (analyticsRow) analyticsRow.style.display = 'grid';
		if (pieChartContainer) pieChartContainer.style.display = 'flex';
		const ensure = () => {
			const canvas = document.getElementById('pieChart');
			const ctx = canvas.getContext('2d');
			// Ensure perfect circle: square canvas sized to container
			const card = canvas.parentElement;
			// Center the pie within its container
			try {
				card.style.display = 'flex';
				card.style.justifyContent = 'center';
				card.style.alignItems = 'center';
			} catch (_) {}
			let side = Math.min(card.clientWidth || 320, 520);
			side = Math.max(280, side);
			canvas.style.width = side + 'px';
			canvas.style.height = side + 'px';
			canvas.width = side;
			canvas.height = side;
			if (window.pieChartInstance) window.pieChartInstance.destroy();
			window.pieChartInstance = new Chart(ctx, {
				type: 'pie',
				data: { labels, datasets: [{ data: values, backgroundColor: getPalette(labels.length||6), borderWidth:2, borderColor:'#fff' }] },
				options: {
					responsive:false,
					plugins:{
						legend:{ position:'bottom', labels:{ color:'#374151', font:{ size:13 }, boxWidth:14, boxHeight:14 } },
						title:{ display:true, text:title, color:'#2563eb', font:{ size:18, weight:'600' } },
						tooltip:{
							callbacks:{
								label: (ctx)=>{
									const total = ctx.dataset.data.reduce((a,b)=>a+Number(b||0),0) || 1;
									const val = Number(ctx.parsed || 0);
									const pct = (val/total*100).toFixed(1)+'%';
									return `${ctx.label}: ${val} (${pct})`;
								}
							}
						},
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
			// No data overlay for pie
			const total = (values||[]).reduce((a,b)=>a+Number(b||0),0);
			const pieCard = canvas.parentElement;
			pieCard.style.position = 'relative';
			const existing = pieCard.querySelector('#pieNoDataOverlay'); if (existing) existing.remove();
			if (!total) {
				const overlay = document.createElement('div');
				overlay.id = 'pieNoDataOverlay';
				overlay.style.position = 'absolute';
				overlay.style.inset = '0';
				overlay.style.display = 'flex';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.color = '#64748b';
				overlay.style.fontWeight = '600';
				overlay.textContent = 'No data for selected period';
				pieCard.appendChild(overlay);
			}
			if (reportsLoading) reportsLoading.style.display = 'none';
			// Save last data and bind a debounced resize to keep circle aspect
			window.lastPieData = { labels: Array.from(labels), values: Array.from(values), title };
			if (!window.pieResizeBound) {
				window.pieResizeBound = true;
				let _pieRTO = null;
				window.addEventListener('resize', () => {
					if (!_pieRTO) {
						_pieRTO = setTimeout(() => {
							_pieRTO = null;
							if (window.lastPieData) {
								renderPie(window.lastPieData.labels, window.lastPieData.values, window.lastPieData.title);
							}
						}, 150);
					}
				});
			}
		};
		if (!window.Chart) {
			const check = setInterval(()=>{ if (window.Chart) { clearInterval(check); ensure(); } }, 100);
		} else {
			// If Chart is present but plugin may still be loading, small delay
			setTimeout(ensure, 50);
		}
	}

		async function renderIllnessPie() {
		const audience = audienceFilter ? (audienceFilter.value || 'Student') : 'Student';
		reportResult.innerHTML = '';
				const data = await fetchIllnessStats(audience);
					const pluralMap = { 'Student':'Students', 'Faculty':'Faculty', 'Staff':'Staff' };
					const audLabel = audience === 'All' ? 'All Roles' : (pluralMap[audience] || audience);
					const title = `Top Illnesses Among ${audLabel}`;
						renderPie(data.labels, data.values, title);
						// Summary card beside the chart
						const total = data.total ?? data.values.reduce((a,b)=>a+Number(b||0),0);
						const body = data.labels.map((l,i)=>{
							const val = Number(data.values[i]||0);
							const pct = total ? ((val/total)*100).toFixed(1) : '0.0';
							return `<div style="display:flex;justify-content:space-between;gap:8px;"><span>${l}</span><span><strong>${val}</strong> (${pct}%)</span></div>`;
						}).join('');
						const summaryBody = document.getElementById('summaryBody');
						const summaryTotal = document.getElementById('summaryTotal');
						if (summaryBody && summaryTotal) {
								summaryBody.innerHTML = body + (data.roleCounts ? `<hr style="margin:8px 0;">` +
									`<div style="font-weight:600;margin-bottom:4px;">Role Totals</div>` +
									`<div style=\"display:flex;justify-content:space-between;gap:8px;\"><span>Students</span><span><strong>${data.roleCounts.Student||0}</strong></span></div>` +
									`<div style=\"display:flex;justify-content:space-between;gap:8px;\"><span>Faculty</span><span><strong>${data.roleCounts.Faculty||0}</strong></span></div>` +
									`<div style=\"display:flex;justify-content:space-between;gap:8px;\"><span>Staff</span><span><strong>${data.roleCounts.Staff||0}</strong></span></div>`
								: '');
								summaryTotal.textContent = `Total: ${total}`;
						}
	}

	// Initial pie on load for doc_nurse page: show illness_stats by default
	if (audienceFilter) {
		audienceFilter.addEventListener('change', () => { updateInputs(); renderIllnessPie(); });
	}

	// Auto-generate behavior: render on any form change; keep submit as no-op
	reportForm.addEventListener('submit', function(e) {
		e.preventDefault();
		triggerRender();
	});

	function triggerRender() {
		// Default to illness stats if sorter is removed
		if (!reportType || reportType.value === 'illness_stats') {
			return renderIllnessPie();
		}
		if (reportType.value === 'clinic_overview') {
			return renderClinicOverview();
		}
	}

	// Initial render: show illness stats by default
	// Auto render once when arriving on the page if Illness Stats is selected
	// Default to Illness Stats on doc_nurse page for immediate visual output
	setTimeout(() => {
		try { if (reportType) reportType.value = 'illness_stats'; } catch(e) {}
		updateInputs();
		try { syncCourses(); } catch(_) {}
		triggerRender();
	}, 150);

	// Auto-generate on filter changes
	const autoEls = [reportType, illnessDateMode, dailyDate, weekStart, weekEnd, monthInput, rangeStart, rangeEnd, audienceFilter];
	autoEls.forEach(el => {
		if (!el) return;
		['change','input'].forEach(evt => el.addEventListener(evt, () => {
			updateSubtitle();
			// After inputs update, ensure conditional visibility then render
			if (el === reportType || el === illnessDateMode) updateInputs();
			triggerRender();
		}));
	});
});
