(function(){
  const btn = document.getElementById('qrLoginChallengeBtn');
  const box = document.getElementById('qrSessionBox');
  const img = document.getElementById('qrSessionImg');
  const msg = document.getElementById('qrSessionMsg');
  const cancelBtn = document.getElementById('qrSessionCancel');
  const refreshBtn = document.getElementById('qrSessionRefresh');
  // Elements to hide/show when toggling QR mode
  const fldEmail = document.getElementById('loginFieldEmail');
  const fldPass = document.getElementById('loginFieldPassword');
  const btnSubmit = document.getElementById('loginSubmitBtn');
  const rowForgot = document.getElementById('loginForgotRow');
  const rowSignup = document.getElementById('loginSignupRow');
  const rowQrBtn = document.getElementById('loginQrBtnRow');
  const modalEl = document.getElementById('loginModal');

  if (!btn || !box || !img || !msg) return;

  let currentChallenge = null;
  let pollTimer = null;
  let expiresAt = 0;

  function clearPoll(){ if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
  function hideBox(){ box.style.display = 'none'; img.src=''; msg.textContent=''; clearPoll(); currentChallenge=null; }

  function setLoginFieldsVisible(visible){
    const disp = visible ? '' : 'none';
    if (fldEmail) fldEmail.style.display = disp;
    if (fldPass) fldPass.style.display = disp;
    if (btnSubmit) btnSubmit.style.display = disp;
    if (rowForgot) rowForgot.style.display = disp;
    else {
      // Fallback: hide/show forgot link directly if row wrapper is absent
      try {
        const forgotA = document.querySelector('#loginModal a[href="forgot_password.html"]');
        if (forgotA) forgotA.style.display = disp;
      } catch(_) {}
    }
    if (rowSignup) rowSignup.style.display = disp;
    if (rowQrBtn) rowQrBtn.style.display = disp;
  }

  function poll(){
    if (!currentChallenge) return;
    fetch(`../../backend/api/auth.php?action=qr_poll_challenge`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `challenge=${encodeURIComponent(currentChallenge)}`
    })
    .then(r=>r.json())
    .then(d=>{
      if (!d.success) return;
      if (d.status === 'approved' && d.user) {
        clearPoll();
        msg.style.color = 'green';
        msg.textContent = 'Approved! Signing you in...';
        // Persist and redirect
        localStorage.setItem('documed_user', JSON.stringify(d.user));
        sessionStorage.setItem('documedLoggedIn','1');
        setTimeout(()=>{ window.location.href = 'user_dashboard.html'; }, 600);
      } else if (d.status === 'expired') {
        clearPoll();
        msg.style.color = '#b91c1c';
        msg.textContent = 'QR expired. Click New QR to try again.';
        try { document.getElementById('qrWaitingBox').style.display='none'; } catch(_) {}
      } else if (d.status === 'cancelled') {
        clearPoll();
        msg.style.color = '#b91c1c';
        msg.textContent = 'Login request cancelled.';
        try { document.getElementById('qrWaitingBox').style.display='none'; } catch(_) {}
      } else {
        // pending or scanned: also show countdown
        const remain = Math.max(0, Math.floor(expiresAt - Date.now()/1000));
        msg.style.color = '#334155';
        if (d.status === 'scanned') {
          msg.textContent = `Waiting for confirmation on your phone… Expires in ${remain}s.`;
          try {
            document.getElementById('qrWaitingBox').style.display='block';
            const tip = document.getElementById('qrSessionTip');
            if (tip) tip.style.display = 'none';
            if (img) img.style.display = 'none';
          } catch(_) {}
        } else {
          msg.textContent = `Scan this QR using your phone to approve login. Expires in ${remain}s.`;
          try {
            document.getElementById('qrWaitingBox').style.display='none';
            const tip = document.getElementById('qrSessionTip');
            if (tip) tip.style.display = '';
            if (img) img.style.display = '';
          } catch(_) {}
        }
      }
    })
    .catch(()=>{});
  }

  function create(){
    fetch(`../../backend/api/auth.php?action=qr_create_challenge`)
      .then(r=>r.json())
      .then(d=>{
        if (!d.success) { msg.style.color='#b91c1c'; msg.textContent='Failed to create QR.'; return; }
        currentChallenge = d.challenge;
        if (d.qr_image) {
          img.src = d.qr_image + '?t=' + Date.now();
        } else {
          // Optional: render from d.qr_text via local qrcode lib (not included); for now show text
          img.alt = d.qr_text || 'Scan the challenge';
        }
        expiresAt = Math.floor(Date.now()/1000) + (d.expires_in || 120);
        box.style.display = 'block';
        // Hide traditional login fields while QR is active
        setLoginFieldsVisible(false);
        msg.style.color = '#334155';
        msg.textContent = 'Scan this QR using your phone to approve login.';
        try {
          const tip = document.getElementById('qrSessionTip');
          if (tip) tip.style.display = '';
          if (img) img.style.display = '';
          const w = document.getElementById('qrWaitingBox');
          if (w) w.style.display = 'none';
        } catch(_) {}
        clearPoll();
        pollTimer = setInterval(poll, 2000);
      })
      .catch(()=>{ msg.style.color='#b91c1c'; msg.textContent='Network error creating QR.'; });
  }

  btn.addEventListener('click', function(){
    if (box.style.display !== 'none') return; // already showing
    // Immediately switch UI to QR mode to prevent flicker
    setLoginFieldsVisible(false);
    box.style.display = 'block';
    if (msg) { msg.style.color = '#334155'; msg.textContent = 'Generating QR...'; }
    create();
  });

  cancelBtn && cancelBtn.addEventListener('click', function(){
    try {
      const tip = document.getElementById('qrSessionTip'); if (tip) tip.style.display='';
      if (img) img.style.display = '';
      const w = document.getElementById('qrWaitingBox'); if (w) w.style.display='none';
    } catch(_) {}
    hideBox();
    // Restore login fields when cancelling QR mode
    setLoginFieldsVisible(true);
  });
  refreshBtn && refreshBtn.addEventListener('click', function(){ clearPoll(); create(); });

  // Reset to default (non-QR) when modal closes
  if (modalEl) {
    modalEl.addEventListener('hidden.bs.modal', function(){
      hideBox();
      setLoginFieldsVisible(true);
    });
  }
})();
