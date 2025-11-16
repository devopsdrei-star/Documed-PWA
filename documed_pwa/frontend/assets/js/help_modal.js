// Inject a reusable Help modal and wire up the top-nav Help link across user pages.
// Works with or without Bootstrap JS; falls back to simple show/hide if bootstrap not present.
(function(){
  function ensureHelpModal(){
    if (document.getElementById('helpModal')) return;
    const modal = document.createElement('div');
    modal.id = 'helpModal';
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'helpModalLabel');
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;">
          <div class="modal-header" style="background:#2563eb;color:#fff;border-radius:12px 12px 0 0;">
            <h5 class="modal-title" id="helpModalLabel">Help • How DocuMed Works</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" style="max-height:70vh;overflow:auto;">
            <div style="line-height:1.6;color:#111;">
              <p><strong>DocuMed</strong> lets you book clinic and dental appointments, view medical history, and manage your profile.</p>
              <ol>
                <li><strong>Sign up and log in</strong> using your email or School ID. Keep your School ID handy for faster lookups.</li>
                <li><strong>Book an appointment</strong> via the Appointments page. Pick an available date and an hourly time slot from 8:00am-5:00pm. Lunch break (12:00pm-1:00pm) is disabled.</li>
                <li><strong>Status flow</strong>:
                  <ul>
                    <li>Scheduled: your request is placed and awaiting clinic action.</li>
                    <li>Accepted: your booking is confirmed for the chosen date/time.</li>
                    <li>Pending (Reschedule): staff proposed a new 3-day window; choose your preferred hour within that window.</li>
                    <li>Rescheduled: you picked a new time from the window; attend at the updated schedule.</li>
                    <li>Completed/Declined/Cancelled: finished or closed appointments.</li>
                  </ul>
                </li>
                <li><strong>Rescheduling</strong>: Staff may propose a 3-day window with hourly options (8:00am-5:00pm, excluding lunch). Select one to finalize.</li>
                <li><strong>Limits</strong>: If you already have a scheduled/pending/rescheduled appointment, booking a new one is blocked until it’s resolved.</li>
                <li><strong>Notifications</strong>: Check the bell icon for updates (acceptance, reschedule windows, reminders). Unread items are highlighted.</li>
                <li><strong>Medical history</strong>: View past checkups and visit details on the Medical History page.</li>
                <li><strong>QR Code</strong>: Access your QR from the profile menu; it can be used for quick verification at the clinic.</li>
              </ol>
              <p class="small text-muted">Tip: For best results, keep your profile information up to date.</p>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);

    // Fallback styling if Bootstrap CSS/JS are not fully present
    const style = document.createElement('style');
    style.textContent = `
      #helpModal.fallback-open { position: fixed; inset: 0; display:flex; align-items:center; justify-content:center; background: rgba(0,0,0,.5); z-index: 1050; }
      #helpModal.fallback-open .modal-dialog { max-width: 880px; width: calc(100vw - 32px); }
      #helpModal.fallback-open .modal-content { display:block; }
    `;
    document.head.appendChild(style);
  }

  function openHelpModal(){
    ensureHelpModal();
    const el = document.getElementById('helpModal');
    if (!el) return;
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(el, {backdrop:true,keyboard:true}).show();
    } else {
      el.classList.add('fallback-open');
      // Wire up close button in header/footer for fallback
      el.querySelectorAll('[data-bs-dismiss="modal"], .btn-close').forEach(btn => {
        btn.addEventListener('click', ()=> el.classList.remove('fallback-open'), { once: true });
      });
      // Close on backdrop click (outside dialog)
      el.addEventListener('click', function onBackdrop(e){
        if (!e.target.closest('.modal-dialog')) {
          el.classList.remove('fallback-open');
          el.removeEventListener('click', onBackdrop);
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Attach click handler to any Help link with id="navHelp"
    document.body.addEventListener('click', function(e){
      const t = e.target;
      if (t && t.id === 'navHelp') {
        e.preventDefault();
        openHelpModal();
      }
    });
  });
})();
