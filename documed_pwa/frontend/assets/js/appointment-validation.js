// Lightweight client-side validation for booking forms (page and modal)
(function(){
  function setError(el, msg){
    if (!el) return;
    if (typeof el.setCustomValidity === 'function') el.setCustomValidity(msg||'');
    if (msg) el.classList.add('is-invalid'); else el.classList.remove('is-invalid');
  }
  function clearAll(form){
    if (!form) return;
    Array.from(form.querySelectorAll('.is-invalid')).forEach(function(n){ n.classList.remove('is-invalid'); });
    var fm = form.querySelector('#formMsg, #formMsgModal');
    if (fm) { fm.className = 'alert d-none'; fm.textContent = ''; }
  }
  function validate(form){
    if (!form) return true;
    clearAll(form);
    var scope = function(id){ return form.querySelector('#'+id); };
    var name = scope('name') || scope('nameModal');
    var email = scope('email') || scope('emailModal');
    var role = scope('role') || scope('roleModal');
    var purpose = scope('purpose') || scope('purposeModal');
    var date = scope('selectedDate') || scope('selectedAppointmentDate');
    var time = scope('selectedTime') || scope('timeModal');

    var ok = true;
    if (!name || !name.value.trim()) { setError(name, 'Required'); ok = false; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) { setError(email, 'Valid email required'); ok = false; }
    if (!role || !role.value) { setError(role, 'Required'); ok = false; }
    if (!purpose || !purpose.value.trim()) { setError(purpose, 'Required'); ok = false; }
    if (!date || !date.value) { setError(date, 'Required'); ok = false; }
    if (!time || !time.value) { setError(time, 'Required'); ok = false; }

    var msg = form.querySelector('#formMsg, #formMsgModal');
    if (!ok && msg) { msg.className = 'alert alert-danger'; msg.textContent = 'Please complete all required fields.'; }
    return ok;
  }

  document.addEventListener('DOMContentLoaded', function(){
    var pageForm = document.getElementById('appointmentForm');
    if (pageForm) {
      pageForm.addEventListener('submit', function(e){
        if (!validate(pageForm)) { e.preventDefault(); e.stopImmediatePropagation(); }
      }, true);
    }
    var modalForm = document.getElementById('appointmentFormModal');
    if (modalForm) {
      modalForm.addEventListener('submit', function(e){
        if (!validate(modalForm)) { e.preventDefault(); e.stopImmediatePropagation(); }
      }, true);
    }
  });
})();
