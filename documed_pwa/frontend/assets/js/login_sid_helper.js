// Enhance any login form labels/placeholders dynamically to reflect Email or School ID
(function(){
  // Support multiple login forms (standalone page, modal, admin/dentist/DN)
  const forms = [
    document.querySelector('#loginForm'),
    document.querySelector('#userLoginForm'),
    document.querySelector('#userLoginFormModal'),
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
  });
})();
