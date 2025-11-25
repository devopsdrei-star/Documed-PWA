// Enhance any login form labels/placeholders dynamically to reflect Email or School ID
document.addEventListener('DOMContentLoaded', function(){
  // Support multiple login forms (standalone page, modal, admin/dentist/DN)
  const forms = [
    document.querySelector('#loginForm'),
    document.querySelector('#userLoginForm'),
    document.querySelector('#patientLoginFormModal'),
    document.querySelector('#adminLoginForm'),
    document.querySelector('#dentistLoginForm'),
    document.querySelector('#docNurseLoginForm')
  ].filter(Boolean);
  if (!forms.length) return;

  forms.forEach(fm => {
    const emailInput = fm.querySelector('input[name="email"]');
    if (emailInput) {
      // Relax type to text to avoid HTML email validation blocking SID
      try { emailInput.setAttribute('type','text'); } catch(_) {}
      if (!emailInput.getAttribute('placeholder') || /email/i.test(emailInput.getAttribute('placeholder'))) {
        emailInput.setAttribute('placeholder','Enter email or School ID');
      }
      // Also remove any pattern that enforces '@'
      try { emailInput.removeAttribute('pattern'); emailInput.removeAttribute('oninvalid'); } catch(_) {}
    }
    // Update corresponding label if present
    const lblByFor = fm.querySelector('label[for="email"]');
    if (lblByFor) lblByFor.textContent = 'Email or School ID';
    // Or nearest preceding label element
    const parent = emailInput ? emailInput.closest('.input-group, .mb-3, form') : null;
    if (parent) {
      const anyLabel = parent.querySelector('label');
      if (anyLabel && /email/i.test(anyLabel.textContent)) {
        anyLabel.textContent = 'Email or School ID';
      }
    }

    // Attach AJAX login handler for patient/user forms so credentials don't go in URL
    const formId = fm.id || '';
    if (formId === 'loginForm' || formId === 'userLoginForm' || formId === 'patientLoginFormModal') {
      fm.addEventListener('submit', function(e){
        e.preventDefault();

        const emailField = fm.querySelector('input[name="email"]');
        const passField = fm.querySelector('input[name="password"]');
        if (!emailField || !passField) return fm.submit();

        const identifier = (emailField.value || '').trim();
        const password = (passField.value || '').trim();
        const msgBox = document.getElementById('userLoginMsgModal') || fm.querySelector('.login-message');

        if (!identifier || !password) {
          if (msgBox) msgBox.innerHTML = '<span class="text-danger">Please enter Email/School ID and Password.</span>';
          return;
        }

        if (msgBox) msgBox.innerHTML = '<span class="text-muted">Logging in…</span>';

        const body = new URLSearchParams();
        body.set('email', identifier);
        body.set('password', password);

        fetch('../../backend/api/auth.php?action=user_login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        })
          .then(r => r.text())
          .then(text => {
            let data;
            try { data = JSON.parse(text); } catch(e) { console.error('login raw response', text); throw e; }

            if (data && data.success && data.user) {
              try { localStorage.setItem('documed_user', JSON.stringify(data.user)); } catch(_) {}
              try { sessionStorage.setItem('documedLoggedIn', '1'); } catch(_) {}
              window.dispatchEvent(new Event('loginSuccess'));
              if (msgBox) msgBox.innerHTML = '<span class="text-success">Login successful. Redirecting…</span>';

              // Close modal if this is the modal form
              if (formId === 'patientLoginFormModal' && window.bootstrap && window.bootstrap.Modal) {
                const modalEl = document.getElementById('loginModal');
                if (modalEl) {
                  const inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                  inst.hide();
                }
              }

              // Always go to user dashboard after login
              setTimeout(() => { window.location.href = 'user_dashboard.html'; }, 400);
            } else {
              const msg = (data && (data.message || data.error)) || 'Invalid credentials';
              if (msgBox) msgBox.innerHTML = '<span class="text-danger">' + msg + '</span>';
            }
          })
          .catch(err => {
            console.error('login error', err);
            if (msgBox) msgBox.innerHTML = '<span class="text-danger">Network error. Please try again.</span>';
          });
      });
    }
  });
});
