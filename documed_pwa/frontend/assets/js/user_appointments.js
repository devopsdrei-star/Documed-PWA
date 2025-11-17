// Slimmed down to ensure the "Book Dental Appointment" control always routes directly
// to book_appointment.html without invoking the old reCAPTCHA flow.
(function(){
  const BOOK_URL = 'book_appointment.html';
  let captureBound = false;
  let observer = null;

  function hardNavigate(ev){
    if (ev) {
      try { ev.preventDefault(); ev.stopImmediatePropagation(); } catch(_) {}
    }
    try {
      window.location.assign(BOOK_URL);
    } catch (_) {
      window.location.href = BOOK_URL;
    }
  }

  function scrubButtonAttributes(btn){
    if (!btn) return;
    ['onclick','onmousedown','onmouseup','data-bs-toggle','data-bs-target','data-recaptcha','data-action'].forEach(attr => {
      try {
        if (btn.hasAttribute && btn.hasAttribute(attr)) btn.removeAttribute(attr);
      } catch(_) {}
    });
    try { btn.setAttribute('href', BOOK_URL); } catch(_) {}
    if (btn.tagName && btn.tagName.toLowerCase() !== 'a') {
      try {
        btn.setAttribute('role', 'link');
        btn.setAttribute('tabindex', '0');
      } catch(_) {}
    }
  }

  function bindButton(btn){
    if (!btn) return;
    scrubButtonAttributes(btn);
    if (!btn.dataset.bookNavBound) {
      try { btn.dataset.bookNavBound = '1'; } catch(_) {}
      try { btn.addEventListener('click', hardNavigate, false); } catch(_) {}
      try { btn.addEventListener('keydown', function(ev){ if (ev.key === 'Enter' || ev.key === ' ') hardNavigate(ev); }); } catch(_) {}
    }
  }

  function attachGlobalInterceptor(){
    if (captureBound) return;
    captureBound = true;
    document.addEventListener('click', function(ev){
      const target = ev.target;
      const btn = target && (target.id === 'bookAppointmentBtn' ? target : (target.closest ? target.closest('#bookAppointmentBtn') : null));
      if (!btn) return;
      bindButton(btn);
      hardNavigate(ev);
    }, true);
  }

  function ensureButtonReady(){
    const btn = document.getElementById('bookAppointmentBtn');
    if (!btn) return;
    bindButton(btn);
    if (!observer) {
      try {
        observer = new MutationObserver(() => bindButton(document.getElementById('bookAppointmentBtn')));
        observer.observe(btn, { attributes: true });
      } catch(_) {}
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    console.log('[user_appointments.js] init');
    ensureButtonReady();
    attachGlobalInterceptor();
  });

  document.addEventListener('visibilitychange', function(){
    if (!document.hidden) ensureButtonReady();
  });

  window.addEventListener('focus', ensureButtonReady);
})();