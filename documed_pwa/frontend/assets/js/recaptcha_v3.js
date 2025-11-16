// reCAPTCHA fully removed; ensure no stale token persists
(function(){ try { sessionStorage.removeItem('recaptchaToken'); } catch(_) {} })();
