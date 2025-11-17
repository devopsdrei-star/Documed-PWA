// --- Unified Calendar and Appointment Booking Logic ---
const slots = [
  { time: '08:00', end: '10:00' },
  { time: '10:00', end: '12:00' },
  { time: '12:00', end: '14:00' },
  { time: '14:00', end: '16:00' },
  { time: '16:00', end: '18:00' }
];

// Initialize variables to null
let calendarContainer = null;
let appointmentDate = null;
let appointmentTime = null;
let formMsg = null;
let service = null;
let apptForm = null;

// Function to initialize DOM elements
function initializeElements() {
    calendarContainer = document.getElementById('calendarContainerModal');
    appointmentDate = document.getElementById('appointmentDate');
    appointmentTime = document.getElementById('appointmentTime');
    formMsg = document.getElementById('formMsg');
    service = document.getElementById('service');
    apptForm = document.getElementById('appointmentForm');
  // Only initialize this legacy calendar flow if ALL expected fields exist.
  // This prevents clobbering the new modal calendar on pages that don't use these fields.
  if (!calendarContainer || !appointmentDate || !appointmentTime || !apptForm) {
    return false;
  }
  return true;
}

let appointmentsData = {};

document.addEventListener('DOMContentLoaded', function() {
  // Initialize DOM elements first
  if (initializeElements()) {
    fetchAppointments();
  }

  // ✅ FORM SUBMIT HANDLER (corrected)
  if (apptForm) {
    apptForm.onsubmit = function(e) {
      e.preventDefault();
      formMsg.textContent = '';

      // Get user ID from localStorage
      let userObj = null;
      try {
        userObj = JSON.parse(localStorage.getItem('documed_user'));
      } catch (e) {}
      let userId = userObj && userObj.id ? userObj.id : localStorage.getItem('id');
      if (!userId) userId = localStorage.getItem('student_faculty_id');

      // Get form values
      const date = appointmentDate.value;
      const time = appointmentTime.value;
      const serviceVal = service.value;

      // Validate
      if (!date || !time || !serviceVal || !userId) {
        formMsg.textContent = 'Please fill all fields.';
        formMsg.className = 'error-msg';
        return;
      }

      // Send POST request
fetch('../../backend/api/appointment.php?action=add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `user_id=${encodeURIComponent(userId)}&date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}&service=${encodeURIComponent(serviceVal)}`
      })
      .then(res => res.json())
      .then(data => {
        formMsg.textContent = data.message || (data.success ? 'Appointment booked!' : 'Error booking appointment.');
        formMsg.className = data.success ? 'success-msg' : 'error-msg';
        if (data.success) {
          fetchAppointments();
          apptForm.reset();
        }
      })
      .catch(err => {
        console.error(err);
        formMsg.textContent = 'Network or script error. See console.';
        formMsg.className = 'error-msg';
      });
    };
  }
});

function formatDate(date) {
  return date.toISOString().split('T')[0];
}

function getMonthDates(year, month) {
  const dates = [];
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  for (let d = new Date(firstDay); d <= lastDay; d.setDate(d.getDate() + 1)) {
    dates.push(new Date(d));
  }
  return dates;
}

function renderCalendar() {
  const today = new Date();
  const year = today.getFullYear();
  const month = today.getMonth();
  const dates = getMonthDates(year, month);
  const daysOfWeek = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  let html = '<div class="calendar">';
  daysOfWeek.forEach(day => {
    html += `<div class="calendar-header">${day}</div>`;
  });
  dates.forEach(date => {
    const dateStr = formatDate(date);
    const dayOfWeek = date.getDay();
    let statusClass = 'available';
    let disabled = false;

    if (appointmentsData[dateStr] && appointmentsData[dateStr].length >= slots.length) {
      statusClass = 'full';
    }
    if (date < today || dayOfWeek === 0) {
      statusClass = 'disabled';
      disabled = true;
    }
    html += `<div class="calendar-day ${statusClass}" data-date="${dateStr}" ${disabled ? 'style="pointer-events:none;"' : ''}>${date.getDate()}</div>`;
  });
  html += '</div>';
  calendarContainer.innerHTML = html;

  // Make sure container exists before updating it
  if (!calendarContainer) {
    console.error('Calendar container not found');
    return;
  }
  document.querySelectorAll('.calendar-day.available, .calendar-day.full').forEach(day => {
    day.onclick = function() {
      document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
      this.classList.add('selected');
      if (appointmentDate) {
        appointmentDate.value = this.getAttribute('data-date');
        updateTimeSlots(this.getAttribute('data-date'));
      }
    };
  });
}

function updateTimeSlots(dateStr) {
  appointmentTime.innerHTML = '';
  if (!dateStr) return;
  const takenSlots = appointmentsData[dateStr] || [];
  slots.forEach(slot => {
    const isTaken = takenSlots.includes(slot.time);
    const option = document.createElement('option');
    option.value = slot.time;
    option.textContent = `${slot.time} - ${slot.end}` + (isTaken ? ' (Booked)' : '');
    option.disabled = isTaken;
    appointmentTime.appendChild(option);
  });
}

function fetchAppointments() {
  const today = new Date();
  const year = today.getFullYear();
  const month = today.getMonth() + 1;
  fetch(`../../backend/api/appointment.php?action=list&year=${year}&month=${month}`)
    .then(res => res.json())
    .then(data => {
      appointmentsData = {};
      if (data.appointments) {
        data.appointments.forEach(app => {
          if (!appointmentsData[app.date]) appointmentsData[app.date] = [];
          appointmentsData[app.date].push(app.time);
        });
      }
      renderCalendar();
    })
    .catch(err => console.error('Error fetching appointments:', err));
}