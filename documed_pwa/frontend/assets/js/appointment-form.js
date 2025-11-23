// Compatibility shim for legacy appointment form behaviors
// Ensures the Book Dental Appointment control opens the booking modal (no reCAPTCHA)
// and exposes minimal no-op hooks to avoid JS errors on older pages.
(function(){
  try { console.log('[appointment-form.js] loaded'); } catch(_) {}
  document.addEventListener('DOMContentLoaded', function(){
    // Normalize Book button behavior on appointments page
    var btn = document.getElementById('bookAppointmentBtn');
    if (btn) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        try {
          var modalEl = document.getElementById('bookAppointmentModal');
          if (modalEl && window.bootstrap && bootstrap.Modal) {
            var m = bootstrap.Modal.getOrCreateInstance(modalEl);
            m.show();
            return;
          }
        } catch(_) {}
        // Fallback: navigate to booking page if modal unavailable
        window.location.assign('book_appointment.html');
      });
    }

    // Provide minimal API surface used by old code (no-ops)
    if (!window.validateAppointmentForm) {
      window.validateAppointmentForm = function(){ return true; };
    }
    if (!window.submitAppointmentForm) {
      window.submitAppointmentForm = function(){ /* handled by booking.js / appointment-modal.js */ };
    }
  });
})();
