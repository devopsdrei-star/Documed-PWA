// verify_user.js - Handles verification code submission and resend

document.addEventListener('DOMContentLoaded', function () {
    const verifyForm = document.getElementById('verifyForm');
    const verificationCodeInput = document.getElementById('verificationCode');
    const verifyMessage = document.getElementById('verifyMessage');
    const resendCodeBtn = document.getElementById('resendCodeBtn');
    const verifyEmailDisplay = document.getElementById('verifyEmailDisplay');

    // Helper: get user email from localStorage/session (adjust as needed)
    const userEmail = localStorage.getItem('pendingVerifyEmail');
    if (verifyEmailDisplay && userEmail) {
        verifyEmailDisplay.textContent = userEmail;
    }

    verifyForm.addEventListener('submit', function (e) {
        e.preventDefault();
        verifyMessage.textContent = '';
        const code = verificationCodeInput.value.trim();
        if (!code) {
            verifyMessage.textContent = 'Please enter the verification code.';
            return;
        }
        fetch('../../backend/api/auth.php?action=verify_code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: userEmail, code: code })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                verifyMessage.style.color = '#27ae60';
                verifyMessage.textContent = 'Account verified! Redirecting...';
                setTimeout(() => {
                    window.location.href = 'user_dashboard.html';
                }, 1200);
            } else {
                verifyMessage.style.color = '#e74c3c';
                verifyMessage.textContent = data.message || 'Invalid code.';
            }
        })
        .catch(() => {
            verifyMessage.style.color = '#e74c3c';
            verifyMessage.textContent = 'Server error. Please try again.';
        });
    });

    resendCodeBtn.addEventListener('click', function () {
        verifyMessage.textContent = '';
        fetch('../../backend/api/send_verification_code.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: userEmail })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                verifyMessage.style.color = '#2d6cdf';
                verifyMessage.textContent = 'Verification code resent! Check your email.';
            } else {
                verifyMessage.style.color = '#e74c3c';
                verifyMessage.textContent = data.message || 'Failed to resend code.';
            }
        })
        .catch(() => {
            verifyMessage.style.color = '#e74c3c';
            verifyMessage.textContent = 'Server error. Please try again.';
        });
    });
});
