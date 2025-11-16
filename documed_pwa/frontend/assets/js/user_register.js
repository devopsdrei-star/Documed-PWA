const userRegisterForm = document.getElementById('userRegisterForm');
const userRegisterMsg = document.getElementById('userRegisterMsg');

if (userRegisterForm) {
  userRegisterForm.addEventListener('submit', function(e) {
    e.preventDefault();
    // Block submission if Terms & Conditions not accepted
    const agreeTerms = document.getElementById('agree_terms');
    if (agreeTerms && !agreeTerms.checked) {
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'You must agree to the Terms & Conditions to continue.';
      agreeTerms.focus();
      return;
    }
    // Block submission if passwords don't match
    const pwd = document.getElementById('password')?.value || '';
    const cpwd = document.getElementById('confirm_password')?.value || '';
    if (pwd !== cpwd) {
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'Passwords do not match.';
      document.getElementById('confirm_password')?.focus();
      return;
    }
    // Client-side validations
    const emailEl = document.getElementById('email');
    const contactEl = document.getElementById('contact_number');
    const sidEl = document.getElementById('student_faculty_id');
    const emailVal = (emailEl?.value || '').trim();
    const contactVal = (contactEl?.value || '').trim();
    const sidVal = (sidEl?.value || '').trim();
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal);
    const contactOk = /^\d{11}$/.test(contactVal);
    const sidOk = /^[-A-Za-z0-9]{1,10}$/.test(sidVal);

    if (!emailOk) {
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'Please enter a valid email address (e.g., name@example.com).';
      emailEl?.focus();
      return;
    }
    if (!contactOk) {
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'Contact number must be exactly 11 digits.';
      contactEl?.focus();
      return;
    }
    if (!sidOk) {
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'School ID must be up to 10 characters (letters, numbers, or dash).';
      sidEl?.focus();
      return;
    }

    userRegisterMsg.style.color = 'black';
    userRegisterMsg.textContent = 'Registering...';

    const formData = new FormData(userRegisterForm);
    // reCAPTCHA removed — no token attached
    // Ensure age is taken from DOB computation (ignore any stale value)
    const dobVal = document.getElementById('date_of_birth')?.value || '';
    if (dobVal) {
      // Recompute age defensively in JS as well
      const today = new Date();
      const dob = new Date(dobVal + 'T00:00:00');
      let age = '';
      if (!isNaN(dob.getTime())) {
        let a = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) a--;
        if (a >= 0 && a <= 150) age = String(a);
      }
      formData.set('age', age);
    }

    fetch('../../backend/api/auth.php?action=user_register', {
      method: 'POST',
      body: formData // send FormData directly
      // Do NOT set Content-Type header!
    })
    .then(res => {
      if (!res.ok) throw new Error("Server error: " + res.status);
      return res.json();
    })
    .then(data => {
      if (data.success) {
        userRegisterMsg.style.color = 'green';
        userRegisterMsg.textContent = 'Registration successful! Redirecting...';

        // Store token or session data in localStorage or sessionStorage
        localStorage.setItem('authToken', data.token);
        localStorage.setItem('user', JSON.stringify(data.user));
        if (data.user) {
          localStorage.setItem('documed_user', JSON.stringify(data.user));
          sessionStorage.setItem('documedLoggedIn', '1');
        }

        setTimeout(() => {
          window.location.href = 'user_dashboard.html';
        }, 1500);
      } else {
        userRegisterMsg.style.color = 'red';
        userRegisterMsg.textContent = data.message || 'Registration failed.';
      }
    })
    .catch(err => {
      console.error(err);
      userRegisterMsg.style.color = 'red';
      userRegisterMsg.textContent = 'Error connecting to server.';
    });
  });
}

// Role-based visibility and required toggles
(function() {
  const roleEl = document.getElementById('client_type');
  const legacyEl = document.getElementById('client_legacy');
  if (!roleEl) return;
  const yearCourseGroup = document.getElementById('year_course').parentElement;
  const yearCourseEl = document.getElementById('year_course');
  const departmentGroup = document.getElementById('department-group');
  const departmentEl = document.getElementById('department');

  function applyRoleVisibility() {
    const role = roleEl.value;
    if (role === 'Student') {
      yearCourseGroup.style.display = 'block';
      yearCourseEl.required = true;
      departmentGroup.style.display = 'none';
      departmentEl.required = false;
      departmentEl.value = '';
    } else if (role === 'Teacher' || role === 'Non-Teaching') {
      yearCourseGroup.style.display = 'none';
      yearCourseEl.required = false;
      yearCourseEl.value = '';
      departmentGroup.style.display = 'block';
      departmentEl.required = true;
    } else {
      yearCourseGroup.style.display = 'none';
      yearCourseEl.required = false;
      yearCourseEl.value = '';
      departmentGroup.style.display = 'none';
      departmentEl.required = false;
      departmentEl.value = '';
    }
  }

  roleEl.addEventListener('change', function(){
    if (legacyEl) legacyEl.value = roleEl.value;
    applyRoleVisibility();
  });
  // Initialize on load
  if (legacyEl) legacyEl.value = roleEl.value;
  applyRoleVisibility();
})();