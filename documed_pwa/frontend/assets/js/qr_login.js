(function(){
  // Elements for standalone login page
  const btnStandalone = document.getElementById('qrLoginBtn');
  const msgStandalone = document.getElementById('userLoginMsg');
  // Elements for modal on dashboard
  const btnModal = document.getElementById('qrLoginBtnModal');
  const msgModal = document.getElementById('userLoginMsgModal');
  const inlineWrap = document.getElementById('qrLoginInline');
  const modalEl = document.getElementById('loginModal');
  const closeInlineBtn = document.getElementById('qrCloseInlineBtn');

  // Do not early-return here; we still want to expose the loader globally
  // so other modules (e.g., approve modal) can use it even if this page has no buttons.

  let scannerStarted = false;
  let overlayContainer = null; // for standalone overlay
  let html5QrInstance = null;  // for inline scanner
  let activeMsgEl = null;

  // Lazy loader for html5-qrcode if not present
  let html5QrLoadPromise = null;
  let _qrLoadAttempts = [];
  try { window._qrLoadAttempts = _qrLoadAttempts; } catch(_) {}
  function tryLoadScript(url, timeoutMs){
    return new Promise((resolve, reject) => {
      _qrLoadAttempts.push(url);
      const s = document.createElement('script');
      s.src = url;
      s.async = true;
      let done = false;
      const finish = (ok) => { if (done) return; done = true; ok ? resolve() : reject(new Error('load failed')); };
      s.onload = () => finish(true);
      s.onerror = () => finish(false);
      document.head.appendChild(s);
      setTimeout(() => finish(false), timeoutMs || 10000);
    });
  }
  function loadHtml5Qr(){
    if (window.Html5Qrcode) return Promise.resolve();
    if (html5QrLoadPromise) return html5QrLoadPromise;
    // Prefer local vendor copy if available to avoid CDN/network issues
  const localPath = '../assets/js/vendor/html5-qrcode.min.js';
  // Try both lowercase and capitalized base paths to match local Apache/VHost setups
  const absoluteLocalLower = '/documed_pwa/frontend/assets/js/vendor/html5-qrcode.min.js';
  const absoluteLocalUpper = '/DocMed/documed_pwa/frontend/assets/js/vendor/html5-qrcode.min.js';
    const urls = [
  localPath,
  absoluteLocalLower,
  absoluteLocalUpper,
      // Known-good CDN roots (package ships html5-qrcode.min.js at package root)
      'https://cdn.jsdelivr.net/npm/html5-qrcode/html5-qrcode.min.js',
      'https://unpkg.com/html5-qrcode/html5-qrcode.min.js'
    ];
    // Chain tries sequentially
    html5QrLoadPromise = urls.reduce((p, url) => p.catch(() => tryLoadScript(url, 8000)), Promise.reject())
      .then(() => {
        if (!window.Html5Qrcode) throw new Error('Html5Qrcode unavailable after load');
      });
    return html5QrLoadPromise;
  }
  // Expose loader globally so other modules (approve modal) can reuse it
  try { window.loadHtml5Qr = loadHtml5Qr; } catch(_) {}

  function setMsg(el, color, text){
    if (!el) return;
    el.style.color = color;
    el.textContent = text;
  }

  function cleanupInlineScanner(){
    if (html5QrInstance) {
      try { html5QrInstance.stop().catch(()=>{}); } catch(_) {}
      try { html5QrInstance.clear().catch(()=>{}); } catch(_) {}
    }
    html5QrInstance = null;
    if (inlineWrap) inlineWrap.style.display = 'none';
    scannerStarted = false;
  }

  function closeOverlay(){
    if (overlayContainer) {
      overlayContainer.remove();
      overlayContainer = null;
    }
    scannerStarted = false;
  }

  function postQr(qrText){
    setMsg(activeMsgEl, '#111', 'Signing in with QR...');
    fetch('../../backend/api/auth.php?action=qr_login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'qr=' + encodeURIComponent(qrText)
    }).then(r=>r.json()).then(data=>{
      if (data && data.success && data.user) {
        setMsg(activeMsgEl, 'green', 'Login successful! Redirecting...');
        localStorage.setItem('documed_user', JSON.stringify(data.user));
        sessionStorage.setItem('documedLoggedIn','1');
        setTimeout(()=>{ window.location.href = 'user_dashboard.html'; }, 600);
      } else {
        setMsg(activeMsgEl, 'red', (data && (data.message || data.error)) || 'Invalid QR.');
      }
    }).catch(()=>{
      setMsg(activeMsgEl, 'red', 'Error connecting to server.');
    });
  }

  function startOverlayScanner(){
    const wrap = document.createElement('div');
    wrap.style.position = 'fixed';
    wrap.style.inset = '0';
    wrap.style.background = 'rgba(0,0,0,0.7)';
    wrap.style.display = 'flex';
    wrap.style.alignItems = 'center';
    wrap.style.justifyContent = 'center';
    wrap.style.zIndex = '9999';
    wrap.innerHTML = `
      <div style="background:#fff;border-radius:12px;max-width:520px;width:92%;padding:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
          <h3 style="margin:0">Scan your QR</h3>
          <button id="qrCloseBtn" class="btn" style="background:#ef4444;color:#fff">Close</button>
        </div>
        <div id="qrReader" style="width:100%;"></div>
        <div style="font-size:12px;color:#555;margin-top:6px;">Tip: Center the QR in the box. Allow camera permission.</div>
      </div>`;
    document.body.appendChild(wrap);
    overlayContainer = wrap;
    document.getElementById('qrCloseBtn').addEventListener('click', closeOverlay);

    const config = { fps: 10, qrbox: { width: 280, height: 280 } };
    const html5QrCode = new Html5Qrcode('qrReader');
    html5QrCode.start({ facingMode: 'environment' }, config, (decodedText)=>{
      html5QrCode.stop().then(()=>{
        closeOverlay();
        postQr(decodedText);
      }).catch(()=>{});
    }, ()=>{}).catch(err=>{
      setMsg(activeMsgEl, 'red', 'Camera failed: ' + err);
    });
  }

  function startInlineScanner(){
    if (!inlineWrap) return;
    inlineWrap.style.display = 'block';
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    html5QrInstance = new Html5Qrcode('qrReaderModal');
    html5QrInstance.start({ facingMode: 'environment' }, config, (decodedText)=>{
      html5QrInstance.stop().then(()=>{
        cleanupInlineScanner();
        postQr(decodedText);
      }).catch(()=>{});
    }, ()=>{}).catch(err=>{
      setMsg(activeMsgEl, 'red', 'Camera failed: ' + err);
      cleanupInlineScanner();
    });
  }

  // Wire up standalone button
  if (btnStandalone) {
    btnStandalone.addEventListener('click', function(){
      if (scannerStarted) return;
      activeMsgEl = msgStandalone || msgModal;
      setMsg(activeMsgEl, '#6b7280', 'Loading QR engine...');
      loadHtml5Qr().then(() => {
        scannerStarted = true;
        startOverlayScanner();
      }).catch(() => {
        scannerStarted = false;
        setMsg(activeMsgEl, 'red', 'QR engine failed to load. If you are offline or CDNs are blocked, place html5-qrcode.min.js at frontend/assets/js/vendor/ and try again. Tried: ' + _qrLoadAttempts.join(', '));
      });
    });
  }

  // Wire up modal button
  if (btnModal) {
    btnModal.addEventListener('click', function(){
      if (scannerStarted) return;
      activeMsgEl = msgModal || msgStandalone;
      setMsg(activeMsgEl, '#6b7280', 'Loading QR engine...');
      loadHtml5Qr().then(() => {
        scannerStarted = true;
        startInlineScanner();
      }).catch(() => {
        scannerStarted = false;
        setMsg(activeMsgEl, 'red', 'QR engine failed to load. If you are offline or CDNs are blocked, place html5-qrcode.min.js at frontend/assets/js/vendor/ and try again. Tried: ' + _qrLoadAttempts.join(', '));
      });
    });
  }

  // Ensure cleanup when modal closes
  if (modalEl) {
    modalEl.addEventListener('hidden.bs.modal', function(){
      cleanupInlineScanner();
    });
  }

  if (closeInlineBtn) {
    closeInlineBtn.addEventListener('click', function(){
      cleanupInlineScanner();
    });
  }
})();
