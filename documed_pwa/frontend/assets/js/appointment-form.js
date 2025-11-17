// Compatibility shim for legacy appointment form behaviors
// Ensures the Book Dental Appointment control routes to recaptcha.html
// and exposes minimal no-op hooks to avoid JS errors on older pages.
(function(){
  try { console.log('[appointment-form.js] loaded'); } catch(_) {}
  document.addEventListener('DOMContentLoaded', function(){
    // Normalize Book button behavior on appointments page
    var btn = document.getElementById('bookAppointmentBtn');
    if (btn) {
      // Prefer anchor href; if it's a button, force-assignment
      try { btn.setAttribute('href', 'recaptcha.html'); } catch(_) {}
      btn.addEventListener('click', function(e){
        // If element is not an anchor, or any stale handler tries to change it, force navigate
        if (btn.tagName !== 'A') { e.preventDefault(); }
        window.location.assign('recaptcha.html');
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
