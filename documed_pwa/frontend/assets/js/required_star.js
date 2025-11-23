// Auto-append a red asterisk to labels whose associated input/select/textarea is required.
// Avoid duplicates and skip elements already marked or explicitly opted out.
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    const requiredFields = Array.from(document.querySelectorAll('input[required], select[required], textarea[required]'));
    requiredFields.forEach(el => {
      // Skip hidden or type=hidden elements
      if (el.type === 'hidden' || el.offsetParent === null) return; // not visible
      const id = el.id;
      if (!id) return; // need id to match label
      const label = document.querySelector('label[for="'+CSS.escape(id)+'"]');
      if (!label) return;
      // If label already contains an asterisk or req-star span, skip
      if (/\*/.test(label.textContent) || label.querySelector('.req-star')) return;
      // Append space + span
      const span = document.createElement('span');
      span.className = 'req-star';
      span.textContent = ' *';
      span.setAttribute('aria-hidden','true');
      label.appendChild(span);
    });
  });
})();
