// Diagnostic checks and safer initialization
(function(){
    document.addEventListener('DOMContentLoaded', function() {
        console.log('booking.js: DOM loaded');
        if (typeof initializeCalendar !== 'function') {
            console.error('booking.js: initializeCalendar is NOT defined. appointment-modal.js may not be loaded or had errors.');
            return;
        }
        if (typeof flatpickr === 'undefined') {
            console.error('booking.js: flatpickr is not loaded. Make sure the flatpickr script is included before booking.js');
            return;
        }
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) {
            console.error('booking.js: calendar element #calendar not found');
            return;
        }
        console.log('booking.js: All dependencies present, initializing calendar...');
        // proceed with the normal init (existing code will run after this)
    });
})();

// Import or include the initializeCalendar function
// Assuming appointment-modal.js is included globally
// Ensure this script is loaded after appointment-modal.js

document.addEventListener('DOMContentLoaded', function() {
    // Compute API base URL in the same way the modal does so paths are consistent
    const baseUrl = window.location.pathname.split('/frontend/')[0];
    const apiBaseUrl = `${baseUrl}/backend/api/appointments_new.php`;

    // Initialize calendar in the booking page (container id = 'calendar')
    if (window.__calendarInitialized) {
        console.warn('booking.js: calendar already initialized, skipping duplicate init');
    }
    const calendarInstance = initializeCalendar('calendar', apiBaseUrl);
    if (calendarInstance) {
        // Ensure day states are painted
        updateCalendarAvailability(calendarInstance, apiBaseUrl).catch(err => console.warn('paint availability failed', err));
        // mark initialized to prevent fallback from re-initializing
        window.__calendarInitialized = true;
    }

    // Ensure the calendar area is visible and styled
    const calEl = document.getElementById('calendar');
    if (calEl) calEl.classList.add('modern-calendar');

    // Wire conditional fields (role select) on the booking page
    const roleSelect = document.getElementById('role');
    const studentFields = document.getElementById('studentFields');
    const staffFields = document.getElementById('staffFields');
    const yearCourseInput = document.getElementById('yearCourse');
    const departmentSelect = document.getElementById('department');

    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            if (studentFields) studentFields.style.display = 'none';
            if (staffFields) staffFields.style.display = 'none';
            if (yearCourseInput) yearCourseInput.value = '';
            if (departmentSelect) departmentSelect.value = '';

            switch (this.value) {
                case 'student':
                    if (studentFields) {
                        studentFields.style.display = 'block';
                        if (yearCourseInput) yearCourseInput.required = true;
                        if (departmentSelect) departmentSelect.required = false;
                    }
                    break;
                case 'teacher':
                case 'non-teaching':
                    if (staffFields) {
                        staffFields.style.display = 'block';
                        if (departmentSelect) departmentSelect.required = true;
                        if (yearCourseInput) yearCourseInput.required = false;
                    }
                    break;
                default:
                    break;
            }
        });
    }

    // Load today's time slots on initial page load
    const today = new Date().toISOString().split('T')[0];
    const selectedDateInput = document.getElementById('selectedDate');
    if (selectedDateInput && !selectedDateInput.value) selectedDateInput.value = today;
    updateTimeSlotsForDate(selectedDateInput ? selectedDateInput.value : today, apiBaseUrl);

    // Autofill user info to avoid email mismatch
    try {
        const user = JSON.parse(localStorage.getItem('documed_user') || 'null');
        if (user) {
            const nameEl = document.getElementById('name');
            const emailEl = document.getElementById('email');
            const roleEl = document.getElementById('role');
            const ycEl = document.getElementById('yearCourse');
            const deptEl = document.getElementById('department');

            if (nameEl && !nameEl.value) {
                const fullName = [user.first_name, user.middle_initial, user.last_name].filter(Boolean).join(' ').trim();
                nameEl.value = fullName || user.name || nameEl.value;
            }
            if (emailEl && !emailEl.value && user.email) {
                emailEl.value = user.email;
                emailEl.readOnly = true; // keep value posted
                emailEl.classList.add('bg-light');
                emailEl.title = 'Email comes from your profile';
            }
            if (roleEl && !roleEl.value && user.role) {
                const rv = String(user.role).toLowerCase();
                if ([ 'student','teacher','non-teaching' ].includes(rv)) roleEl.value = rv;
            }
            if (ycEl && !ycEl.value && user.year_course) ycEl.value = user.year_course;
            if (deptEl && !deptEl.value && user.department) deptEl.value = user.department;
        }
    } catch (_) {}

    // Handle form submission
    const appointmentForm = document.getElementById('appointmentForm');
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formMsg = document.getElementById('formMsg');
            if (formMsg) {
                formMsg.className = 'alert d-none';
                formMsg.textContent = '';
            }

            const formData = {
                action: 'add',
                name: document.getElementById('name').value.trim(),
                email: document.getElementById('email').value.trim(),
                role: document.getElementById('role').value,
                year_course: document.getElementById('yearCourse').value.trim(),
                department: document.getElementById('department').value.trim(),
                purpose: document.getElementById('purpose').value.trim(),
                appointment_date: document.getElementById('selectedDate').value,
                time_slot: document.getElementById('selectedTime').value,
                'g-recaptcha-response': sessionStorage.getItem('recaptchaToken') || ''
            };

            if (!formData.name || !formData.email || !formData.role || !formData.appointment_date || !formData.time_slot || !formData.purpose) {
                showMessage('Please complete all required fields.', 'danger');
                return;
            }

            try {
                const body = new URLSearchParams();
                Object.entries(formData).forEach(([key, value]) => {
                    body.append(key, value);
                });

                const res = await fetch(apiBaseUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });

                const data = await res.json();
                if (data.success) {
                    showMessage(data.message || 'Appointment booked successfully!', 'success');
                    setTimeout(() => {
                        window.location.href = 'appointments.html';
                    }, 1500);
                } else {
                    showMessage(data.message || 'Failed to book appointment.', 'danger');
                }
            } catch (err) {
                console.error('Booking error:', err);
                showMessage('Network or server error. Please try again.', 'danger');
            }
        });
    }

    function showMessage(message, type) {
        const formMsg = document.getElementById('formMsg');
        if (formMsg) {
            formMsg.className = `alert alert-${type}`;
            formMsg.textContent = message;
            formMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});

// Fallback init: if initializeCalendar is missing, create a minimal flatpickr instance directly
(function(){
    document.addEventListener('DOMContentLoaded', function() {
        if (window.__calendarInitialized) {
            // main init succeeded; do not run fallback
            return;
        }
        const baseUrl = window.location.pathname.split('/frontend/')[0];
        // Fallback to legacy endpoint but prefer the new one if possible
        const apiBaseUrl = `${baseUrl}/backend/api/appointments_new.php`;
        const calendarEl = document.getElementById('calendar');

        if (!calendarEl) return;

        if (typeof initializeCalendar === 'function') {
            // Use shared initializer
            try {
                initializeCalendar('calendar', apiBaseUrl);
                console.log('booking.js: initialized calendar via shared initializeCalendar');
                return;
            } catch (e) {
                console.warn('booking.js: shared initializeCalendar failed, falling back:', e);
            }
        }

        if (typeof flatpickr === 'undefined') {
            console.error('booking.js fallback: flatpickr not available, cannot initialize calendar');
            return;
        }

        // Minimal fallback initialization
        try {
            const inst = flatpickr(calendarEl, {
                inline: true,
                minDate: 'today',
                dateFormat: 'Y-m-d',
                disable: [function(date) { return date.getDay() === 0 || date.getDay() === 6; }],
                onChange: function(selectedDates, dateStr) {
                    const selectedDateInput = document.getElementById('selectedDate');
                    if (selectedDateInput) selectedDateInput.value = dateStr;
                    // update time slots if function available
                    if (typeof updateTimeSlotsForDate === 'function') updateTimeSlotsForDate(dateStr, apiBaseUrl);
                }
            });
            console.log('booking.js fallback: flatpickr initialized');
            // hide prev/next month days
            inst.calendarContainer.querySelectorAll('.flatpickr-day').forEach(day => {
                if (day.classList.contains('prevMonthDay') || day.classList.contains('nextMonthDay')) day.style.visibility='hidden';
            });
            // load today's slots
            const today = new Date().toISOString().split('T')[0];
            if (typeof updateTimeSlotsForDate === 'function') updateTimeSlotsForDate(today, apiBaseUrl);
        } catch (e) {
            console.error('booking.js fallback init failed', e);
        }
    });
})();