// Calendar and booking helper functions (top-level so other scripts can call them)
(function(global){

    // Helper: format Date -> YYYY-MM-DD
    function formatYMD(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    // Fetch fully booked dates for a month
    async function fetchFullyBooked(year, month, apiBaseUrl) {
        try {
            const body = new URLSearchParams();
            body.append('action', 'check_month_availability');
            body.append('year', year);
            body.append('month', month);
            
            const res = await fetch(apiBaseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            
            const data = await res.json();
            if (data.success && data.fully_booked) return data.fully_booked;
        } catch (e) {
            console.error('Error fetching fully booked dates:', e);
        }
        return [];
    }

    // Fetch booked slots for a specific date
    async function fetchBookedSlotsForDate(dateStr, apiBaseUrl) {
        try {
            const body = new URLSearchParams();
            body.append('action', 'check_slots');
            body.append('date', dateStr);
            
            let res = await fetch(apiBaseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            
            // Check if response is JSON; if not, try legacy endpoint once
            let contentType = res.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                console.warn('Non-JSON response from server for check_slots. Retrying with legacy endpoint...');
                try {
                    const legacyUrl = apiBaseUrl.replace('appointments_new.php', 'appointment.php');
                    res = await fetch(legacyUrl + '?action=check_slots&date=' + encodeURIComponent(dateStr));
                    contentType = res.headers.get('content-type') || '';
                } catch (retryErr) {
                    console.error('Legacy retry failed', retryErr);
                    return {};
                }
                if (!contentType.includes('application/json')) {
                    console.error('Legacy endpoint also returned non-JSON. Giving up.');
                    return {};
                }
            }

            const data = await res.json();
            if (!data.success) {
                console.warn('Slot check failed:', data.message);
                return {};
            }
            // augment with daily meta if provided
            const out = data.booked_slots || {};
            out.__day_total = typeof data.day_total === 'number' ? data.day_total : undefined;
            out.__day_limit = typeof data.day_limit === 'number' ? data.day_limit : 10;
            return out;
        } catch (e) {
            console.error('Error fetching booked slots for date', dateStr, e);
            return {};
        }
    }

    // Hide prev/next month days helper
    function hideNonCurrentMonthDays(instance) {
        if (!instance) return;
        const container = instance.calendarContainer;
        if (!container) return;
        container.querySelectorAll('.flatpickr-day').forEach(day => {
            if (day.classList.contains('prevMonthDay') || day.classList.contains('nextMonthDay')) {
                day.style.visibility = 'hidden';
            } else {
                day.style.visibility = 'visible';
            }
        });
    }

    // Update calendar availability classes (has-slots / no-slots)
    async function updateCalendarAvailability(instance, apiBaseUrl) {
        if (!instance) {
            console.warn('updateCalendarAvailability: no instance provided');
            return;
        }
        const container = instance.calendarContainer;
        if (!container) {
            console.warn('updateCalendarAvailability: instance.calendarContainer is undefined, skipping availability paint');
            return;
        }

        // Build list of visible date strings to check
        const dayEls = Array.from(container.querySelectorAll('.flatpickr-day'))
            .filter(d => d.dateObj && !d.classList.contains('prevMonthDay') && !d.classList.contains('nextMonthDay') && !d.classList.contains('disabled'));
        const dateStrs = dayEls.map(d => formatYMD(d.dateObj));

        // Parallel fetch counts for each visible date
        const promises = dateStrs.map(ds => fetchBookedSlotsForDate(ds, apiBaseUrl).then(counts => ({ date: ds, counts })).catch(() => ({ date: ds, counts: {} })));
    const results = await Promise.all(promises);
    const map = {};
    results.forEach(r => map[r.date] = r.counts || {});

        // For each day element, determine its state
        const availClasses = ['has-slots', 'no-slots', 'partial-slots', 'past-day'];
        dayEls.forEach(dayEl => {
            const dateStr = formatYMD(dayEl.dateObj);
            const todayStr = formatYMD(new Date());

            // Determine desired state
            let desired = '';
            if (dateStr < todayStr || dayEl.classList.contains('flatpickr-disabled') || dayEl.classList.contains('disabled')) {
                desired = 'past-day';
            } else {
                const counts = map[dateStr] || {};
                const dayTotal = typeof counts.__day_total === 'number' ? counts.__day_total : undefined;
                const dayLimit = typeof counts.__day_limit === 'number' ? counts.__day_limit : 10;
                // If daily cap reached, mark day as no-slots and disable
                if (typeof dayTotal === 'number' && dayTotal >= dayLimit) {
                    desired = 'no-slots';
                    dayEl.classList.add('flatpickr-disabled','disabled');
                } else {
                let fullSlots = 0, partialSlots = 0, totalSlots = 6;
                Object.values(counts).forEach(v => {
                    const n = parseInt(v, 10) || 0;
                    if (n >= 2) fullSlots++;
                    else if (n === 1) partialSlots++;
                });

                if (fullSlots >= totalSlots) desired = 'no-slots';
                else if (partialSlots > 0) desired = 'partial-slots';
                else desired = 'has-slots';
                }
            }

            // Apply classes only when different to avoid DOM flicker
            availClasses.forEach(c => {
                const has = dayEl.classList.contains(c);
                const shouldHave = (c === desired);
                if (has && !shouldHave) dayEl.classList.remove(c);
                if (!has && shouldHave) dayEl.classList.add(c);
            });
        });

        // Reapply selected visual if needed
        reapplySelectedVisual(instance);
    }

    // Track which dates have which availability state to avoid repaints
    const dateAvailabilityCache = new Map();

    // Ensure the selected date cell gets the selected class visually while preserving availability
    function reapplySelectedVisual(instance) {
        if (!instance?.calendarContainer) return;
        
        const selDates = instance.selectedDates || [];
        const selStr = selDates.length ? formatYMD(selDates[0]) : 
            (document.getElementById('selectedDate')?.value || document.getElementById('selectedAppointmentDate')?.value);
        
        instance.calendarContainer.querySelectorAll('.flatpickr-day').forEach(dayEl => {
            if (!dayEl.dateObj) return;
            const dstr = formatYMD(dayEl.dateObj);
            
            // Remember original availability class if we haven't seen this date
            if (!dateAvailabilityCache.has(dstr)) {
                const cls = ['has-slots', 'no-slots', 'partial-slots'].find(c => dayEl.classList.contains(c));
                if (cls) dateAvailabilityCache.set(dstr, cls);
            }
            
            // Toggle selected without disturbing availability
            if (selStr && dstr === selStr) {
                dayEl.classList.add('selected');
            } else {
                dayEl.classList.remove('selected');
            }
            
            // Restore availability class from cache if needed
            const availClass = dateAvailabilityCache.get(dstr);
            if (availClass && !dayEl.classList.contains(availClass)) {
                dayEl.classList.add(availClass);
            }
        });
    }

    // Initialize calendar on a container element
    function initializeCalendar(containerId, apiBaseUrl) {
        const calendarContainer = document.getElementById(containerId);
        if (!calendarContainer) return null;

        calendarContainer.style.maxWidth = '460px';

        // If a flatpickr instance already exists, destroy it
        if (calendarContainer._flatpickr) {
            try { calendarContainer._flatpickr.destroy(); } catch (e) { }
        }
        
        // Function to submit appointment booking
        async function submitBooking(formData) {
            try {
                const params = new URLSearchParams({ action: 'add', ...formData });
                const res = await fetch(apiBaseUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: params.toString()
                });
                
                const data = await res.json();
                return data;
                
            } catch (e) {
                console.error('Booking error:', e);
                return { success: false, message: 'Network error while booking appointment' };
            }
        }

        const calendarInstance = flatpickr(calendarContainer, {
            inline: true,
            minDate: "today",
            defaultDate: "today",
            dateFormat: "Y-m-d",
            monthSelectorType: "static",
            showMonths: 1,
            altInput: false,
            allowInput: false,
            disable: [function(date) { return date.getDay() === 0 || date.getDay() === 6; }],
            onReady: function(selectedDates, dateStr, instance) {
                updateCalendarAvailability(instance, apiBaseUrl);
                hideNonCurrentMonthDays(instance);
            },
            onMonthChange: function(selectedDates, dateStr, instance) {
                updateCalendarAvailability(instance, apiBaseUrl);
                hideNonCurrentMonthDays(instance);
            },
            onChange: async function(selectedDates, dateStr, instance) {
                if (!selectedDates || selectedDates.length === 0) return;
                
                const selectedDateInput = document.getElementById('selectedDate') || document.getElementById('selectedAppointmentDate');
                if (selectedDateInput) selectedDateInput.value = dateStr;

                // Do visual updates immediately to avoid flicker
                hideNonCurrentMonthDays(instance);
                reapplySelectedVisual(instance);
                
                // Then do heavy operations asynchronously
                Promise.resolve().then(async () => {
                    try {
                        updateTimeSlotsForDate(dateStr, apiBaseUrl);
                        await updateCalendarAvailability(instance, apiBaseUrl);
                    } catch (e) {
                        console.warn('availability update failed', e);
                    }
                });
            }
        });

        return calendarInstance;
    }

    // Update time slots in a container with id 'timeSlots' (used by both modal and booking page)
    async function updateTimeSlotsForDate(dateStr, apiBaseUrl) {
        const timeSlotsContainer = document.getElementById('timeSlots');
        if (!timeSlotsContainer) return;
    const bookedCounts = await fetchBookedSlotsForDate(dateStr, apiBaseUrl); // returns { '09:00': count, ..., __day_total, __day_limit }
        // Build hourly slots 08:00–16:00; mark 12:00pm–1:00pm as lunch break
        const availableSlots = [];
        const fmt12 = (t)=>{ const [H,M]=t.split(':'); let x=parseInt(H,10); const am=x<12; if(x===0)x=12; if(x>12)x-=12; return `${x}:${M} ${am?'AM':'PM'}`; };
        for (let h=8; h<=16; h++) {
            const start = `${String(h).padStart(2,'0')}:00`;
            const end = `${String(h+1).padStart(2,'0')}:00`;
            const label = `${fmt12(start)} - ${fmt12(end)}`;
            const lunch = (h === 12);
            availableSlots.push({ start, label, lunch });
        }

    timeSlotsContainer.innerHTML = '';
    const dayTotal = typeof bookedCounts.__day_total === 'number' ? bookedCounts.__day_total : undefined;
    const dayLimit = typeof bookedCounts.__day_limit === 'number' ? bookedCounts.__day_limit : 10;
    const dayFull = typeof dayTotal === 'number' && dayTotal >= dayLimit;
        availableSlots.forEach(slot => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'time-slot-btn';
            btn.textContent = slot.lunch ? `${slot.label} (lunch break)` : slot.label;
            btn.dataset.slot = slot.start;

            const count = bookedCounts && bookedCounts[slot.start] ? parseInt(bookedCounts[slot.start], 10) : 0;
            if (slot.lunch) {
                btn.classList.add('disabled');
                btn.disabled = true;
                btn.title = 'Lunch break';
                btn.dataset.state = 'lunch';
            } else if (dayFull) {
                btn.classList.add('disabled');
                btn.disabled = true;
                btn.title = 'This day is fully booked';
                btn.dataset.state = 'day-full';
            } else if (count >= 2) {
                btn.classList.add('disabled');
                btn.disabled = true;
                btn.title = 'Fully booked';
                btn.dataset.state = 'full';
            } else if (count === 1) {
                // partially booked: show warning state (allow 1 more booking)
                btn.classList.add('partial');
                btn.addEventListener('click', function() { if (!dayFull) selectTimeSlot(slot.label, slot.start); });
                btn.dataset.state = 'partial';
                btn.title = '1 slot remaining';
            } else {
                // available
                btn.classList.add('available');
                btn.addEventListener('click', function() { if (!dayFull) selectTimeSlot(slot.label, slot.start); });
                btn.dataset.state = 'available';
                btn.title = 'Available';
            }

            timeSlotsContainer.appendChild(btn);
        });
    }

    // Select time slot and populate hidden inputs
    function selectTimeSlot(label, slotStart) {
        const selectedTimeInput = document.getElementById('selectedTime');
        const selectedTimeDisplay = document.getElementById('selectedTimeDisplay');

        document.querySelectorAll('.time-slot-btn').forEach(btn => btn.classList.remove('selected'));

        if (selectedTimeInput) selectedTimeInput.value = slotStart || label;
        if (selectedTimeDisplay) selectedTimeDisplay.value = label;

        document.querySelectorAll('.time-slot-btn').forEach(btn => {
            if (btn.textContent === label) btn.classList.add('selected');
        });
    }

    // Expose functions to global scope for other scripts
    global.formatYMD = formatYMD;
    global.fetchFullyBooked = fetchFullyBooked;
    global.fetchBookedSlotsForDate = fetchBookedSlotsForDate;
    global.hideNonCurrentMonthDays = hideNonCurrentMonthDays;
    global.updateCalendarAvailability = updateCalendarAvailability;
    global.initializeCalendar = initializeCalendar;
    global.updateTimeSlotsForDate = updateTimeSlotsForDate;
    global.selectTimeSlot = selectTimeSlot;

})(window);

// Modal-specific wiring remains inside DOMContentLoaded to access modal DOM nodes
document.addEventListener('DOMContentLoaded', function() {
    // Get modal elements
    const modal = document.getElementById('bookAppointmentModal');
    const modalOpenBtn = document.getElementById('openBookingBtn');
    const nameInput = document.getElementById('nameModal');
    const emailInput = document.getElementById('emailModal');
    const roleSelect = document.getElementById('roleModal');
    const studentFields = document.getElementById('studentFieldsModal');
    const staffFields = document.getElementById('staffFieldsModal');
    const yearCourseInput = document.getElementById('yearCourseModal');
    const departmentSelect = document.getElementById('departmentModal');
    const appointmentForm = document.getElementById('appointmentFormModal');

    if (!modal) return;

    const bootstrapModal = new bootstrap.Modal(modal);

    if (modalOpenBtn) modalOpenBtn.addEventListener('click', () => bootstrapModal.show());

    const selectedDateInput = document.createElement('input');
    selectedDateInput.type = 'hidden';
    selectedDateInput.id = 'selectedAppointmentDate';
    const calendarContainer = document.getElementById('calendarContainerModal');
    if (calendarContainer) calendarContainer.appendChild(selectedDateInput);

    const baseUrl = window.location.pathname.split('/frontend/')[0];
    const apiUrl = `${baseUrl}/backend/api/appointments_new.php`;

    // Initialize calendar inside modal
    const calendarInstance = initializeCalendar('calendarContainerModal', apiUrl);
    if (calendarContainer) calendarContainer.classList.add('modern-calendar');

    // When the booking modal is shown, reset form and calendar selection
    modal.addEventListener('show.bs.modal', function() {
        if (appointmentForm) appointmentForm.reset();
        if (nameInput) { nameInput.removeAttribute('readonly'); nameInput.value = ''; }
        if (emailInput) { emailInput.removeAttribute('readonly'); emailInput.value = ''; }
        if (studentFields) studentFields.style.display = 'none';
        if (staffFields) staffFields.style.display = 'none';
        if (yearCourseInput) yearCourseInput.value = '';
        if (departmentSelect) departmentSelect.value = '';
        if (roleSelect) roleSelect.value = '';

        if (selectedDateInput) { selectedDateInput.value = ''; }
        if (calendarInstance) {
            calendarInstance.clear();
            calendarInstance.setDate(new Date(), true);
            updateCalendarAvailability(calendarInstance, apiUrl);
        }
    });
        // Auto-fill user info from localStorage to avoid email mismatches
        try {
            const user = JSON.parse(localStorage.getItem('documed_user') || 'null');
            if (user) {
                if (nameInput && !nameInput.value) {
                    const fullName = [user.first_name, user.middle_initial, user.last_name].filter(Boolean).join(' ').trim();
                    if (fullName) nameInput.value = fullName;
                    else if (user.name) nameInput.value = user.name;
                }
                if (emailInput && !emailInput.value && user.email) {
                    emailInput.value = user.email;
                    emailInput.readOnly = true; // don't disable so value posts
                    emailInput.classList.add('bg-light');
                    emailInput.title = 'Email comes from your profile';
                }
                const roleSelect = document.getElementById('roleModal');
                if (roleSelect && !roleSelect.value && user.role) {
                    // Normalize role to expected options
                    const rv = String(user.role).toLowerCase();
                    if (['student','teacher','non-teaching'].includes(rv)) roleSelect.value = rv;
                }
                const yc = document.getElementById('yearCourseModal');
                if (yc && !yc.value && user.year_course) yc.value = user.year_course;
                const dept = document.getElementById('departmentModal');
                if (dept && !dept.value && user.department) dept.value = user.department;
            }
        } catch(_) {}

    // Handle modal role selection (conditional fields)
    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            if (studentFields) studentFields.style.display = 'none';
            if (staffFields) staffFields.style.display = 'none';
            if (yearCourseInput) yearCourseInput.value = '';
            if (departmentSelect) departmentSelect.value = '';

            switch(this.value) {
                case 'student':
                    if (studentFields) { studentFields.style.display = 'block'; yearCourseInput.required = true; if (departmentSelect) departmentSelect.required = false; }
                    break;
                case 'teacher':
                case 'non-teaching':
                    if (staffFields) { staffFields.style.display = 'block'; if (departmentSelect) departmentSelect.required = true; if (yearCourseInput) yearCourseInput.required = false; }
                    break;
                default:
                    break;
            }
        });
    }

    // Modal form submit handling (keeps existing behavior)
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formMsg = document.getElementById('formMsgModal');
            if (formMsg) { formMsg.className = 'alert d-none'; formMsg.textContent = ''; }

            const name = nameInput ? nameInput.value.trim() : '';
            const email = emailInput ? emailInput.value.trim() : '';
            const role = roleSelect ? roleSelect.value : '';
            const year_course = yearCourseInput ? yearCourseInput.value : '';
            const department = departmentSelect ? departmentSelect.value : '';
            const purpose = document.getElementById('purposeModal') ? document.getElementById('purposeModal').value.trim() : '';
            const date = (document.getElementById('selectedAppointmentDate') || {}).value || '';
            const time_slot = (document.getElementById('timeModal') || {}).value || '';

            if (!name || !email || !date || !time_slot || !role) {
                if (formMsg) { formMsg.className = 'alert alert-danger'; formMsg.textContent = 'Please complete all required fields.'; }
                return;
            }
            // No reCAPTCHA required

            try {
                const body = new URLSearchParams();
                body.append('action', 'add');
                body.append('appointment_date', date);
                body.append('time_slot', time_slot);
                body.append('name', name);
                body.append('email', email);
                body.append('role', role);
                body.append('year_course', year_course);
                body.append('department', department);
                body.append('purpose', purpose);
                // reCAPTCHA removed
                const res = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                });

                if (!res.ok) throw new Error('Server returned ' + res.status);
                const data = await res.json();
                if (data.success) {
                    if (formMsg) { formMsg.className = 'alert alert-success'; formMsg.textContent = data.message || 'Appointment booked.'; }
                    setTimeout(() => { try { bootstrap.Modal.getInstance(modal).hide(); } catch (e) {} }, 600);
                } else {
                    if (formMsg) { formMsg.className = 'alert alert-danger'; formMsg.textContent = data.message || 'Booking failed'; }
                }
            } catch (err) {
                console.error('Booking error:', err);
                if (formMsg) { formMsg.className = 'alert alert-danger'; formMsg.textContent = 'Server error: ' + (err.message || 'Failed to book appointment'); }
            }
        });
    }

});