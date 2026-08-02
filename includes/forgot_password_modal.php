<?php
/**
 * Shared Forgot Password Modal
 * Lechon Delights System
 */
?>
<!-- Forgot Password Modal -->
<style>
.forgot-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(30, 20, 16, 0.45);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    z-index: 2100;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.22s ease;
}

.forgot-modal-overlay.active {
    display: flex;
    opacity: 1;
}

.forgot-modal-card {
    background: #ffffff;
    border-radius: 20px;
    width: min(420px, 94vw);
    padding: 30px 26px 22px;
    box-shadow: 0 16px 44px rgba(42, 33, 29, 0.14);
    border: 1px solid #ebd7c5;
    position: relative;
    transform: translateY(16px) scale(0.97);
    transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.forgot-modal-overlay.active .forgot-modal-card {
    transform: translateY(0) scale(1);
}

.forgot-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 34px;
    height: 34px;
    border: none;
    background: #fdf6f0;
    border-radius: 50%;
    color: #7b6d64;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}

.forgot-modal-close:hover {
    background: #b3261e;
    color: #ffffff;
    transform: rotate(90deg);
}

.forgot-modal-header {
    text-align: center;
    margin-bottom: 22px;
}

.forgot-modal-badge {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: rgba(239, 107, 46, 0.12);
    border: 1px solid rgba(239, 107, 46, 0.2);
    color: #ef6b2e;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    margin-bottom: 12px;
}

.forgot-modal-header h3 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.45rem;
    font-weight: 800;
    color: #2a211d;
    margin: 0 0 6px 0;
    letter-spacing: -0.01em;
}

.forgot-modal-header p {
    font-size: 0.86rem;
    color: #7b6d64;
    line-height: 1.45;
    margin: 0;
}

.forgot-modal-alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 18px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    line-height: 1.4;
}

.forgot-modal-alert.error {
    background-color: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.forgot-modal-alert.success {
    background-color: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.forgot-form-group {
    margin-bottom: 18px;
}

.forgot-form-group label {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: #2a211d;
    margin-bottom: 7px;
}

.forgot-input-icon-wrap {
    position: relative;
}

.forgot-input-icon-wrap i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #ef6b2e;
    font-size: 0.95rem;
    transition: color 0.2s ease;
}

.forgot-input {
    width: 100%;
    height: 46px;
    padding: 0 16px 0 44px;
    border: 1px solid #ebd7c5;
    border-radius: 12px;
    font-size: 0.92rem;
    color: #2a211d;
    background: #fffcf9;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    font-family: inherit;
}

.forgot-input:focus {
    border-color: #ef6b2e;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.12);
}

.forgot-input:focus + i {
    color: #ef6b2e;
}

.forgot-submit-btn {
    width: 100%;
    height: 48px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    color: #ffffff;
    font-family: 'Outfit', sans-serif;
    font-size: 0.98rem;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 10px 24px rgba(179, 38, 30, 0.22);
    transition: all 0.2s ease;
}

.forgot-submit-btn:hover:not(:disabled) {
    box-shadow: 0 14px 28px rgba(179, 38, 30, 0.32);
    transform: translateY(-1px);
}

.forgot-submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.forgot-modal-footer {
    text-align: center;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f5eae0;
}

.forgot-back-btn {
    background: transparent;
    border: none;
    color: #7b6d64;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: color 0.2s ease;
}

.forgot-back-btn:hover {
    color: #b3261e;
}
</style>

<div class="forgot-modal-overlay" id="forgotPasswordModal" aria-hidden="true">
    <div class="forgot-modal-card" role="dialog" aria-modal="true" aria-labelledby="forgotModalTitle">
        <button type="button" class="forgot-modal-close" id="forgotModalCloseBtn" aria-label="Close modal">
            <i class="fas fa-xmark"></i>
        </button>

        <div class="forgot-modal-header">
            <div class="forgot-modal-badge">
                <i class="fas fa-key"></i>
            </div>
            <h3 id="forgotModalTitle">Forgot Password?</h3>
            <p>Enter your registered account email and we'll send you instructions to reset your password.</p>
        </div>

        <div id="forgotModalAlert" class="forgot-modal-alert" style="display: none;"></div>

        <form id="forgotPasswordModalForm" method="POST" action="reset_password_request.php">
            <input type="hidden" name="ajax" value="true">
            <div class="forgot-form-group">
                <label for="modalForgotEmail">Email Address</label>
                <div class="forgot-input-icon-wrap">
                    <input type="email" id="modalForgotEmail" name="email" class="forgot-input" placeholder="name@example.com" required autocomplete="email">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <button type="submit" class="forgot-submit-btn" id="modalForgotSubmitBtn">
                <i class="fas fa-paper-plane"></i>
                <span>Send Reset Link</span>
            </button>
        </form>

        <div class="forgot-modal-footer">
            <button type="button" class="forgot-back-btn" id="modalForgotBackBtn">
                <i class="fas fa-arrow-left"></i> Back to Sign In
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalOverlay = document.getElementById('forgotPasswordModal');
    const closeBtn = document.getElementById('forgotModalCloseBtn');
    const backBtn = document.getElementById('modalForgotBackBtn');
    const form = document.getElementById('forgotPasswordModalForm');
    const emailInput = document.getElementById('modalForgotEmail');
    const submitBtn = document.getElementById('modalForgotSubmitBtn');
    const alertBox = document.getElementById('forgotModalAlert');

    function openForgotModal(prefillEmail = '') {
        if (!modalOverlay) return;
        if (alertBox) {
            alertBox.style.display = 'none';
            alertBox.className = 'forgot-modal-alert';
            alertBox.innerHTML = '';
        }
        if (emailInput) {
            if (prefillEmail) emailInput.value = prefillEmail;
        }
        modalOverlay.classList.add('active');
        modalOverlay.setAttribute('aria-hidden', 'false');
        setTimeout(() => { if (emailInput) emailInput.focus(); }, 150);
    }

    function closeForgotModal() {
        if (!modalOverlay) return;
        modalOverlay.classList.remove('active');
        modalOverlay.setAttribute('aria-hidden', 'true');
    }

    window.openForgotModal = openForgotModal;
    window.closeForgotModal = closeForgotModal;

    if (closeBtn) closeBtn.addEventListener('click', closeForgotModal);
    if (backBtn) backBtn.addEventListener('click', closeForgotModal);

    if (modalOverlay) {
        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) closeForgotModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalOverlay && modalOverlay.classList.contains('active')) {
            closeForgotModal();
        }
    });

    document.body.addEventListener('click', function (e) {
        const link = e.target.closest('.forgot-link, a[href*="reset_password_request.php"]');
        if (link) {
            e.preventDefault();
            let emailVal = '';
            const pageEmailInput = document.getElementById('email');
            if (pageEmailInput && pageEmailInput.value) {
                emailVal = pageEmailInput.value.trim();
            }
            openForgotModal(emailVal);
        }
    });

    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const email = emailInput ? emailInput.value.trim() : '';

            if (!email) {
                showAlert('Please enter your email address.', 'error');
                return;
            }

            setLoading(true);
            hideAlert();

            try {
                const formData = new FormData(form);
                const response = await fetch('reset_password_request.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('<i class="fas fa-circle-check"></i> ' + (data.message || 'Password reset link sent! Please check your email inbox.'), 'success');
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Reset Link Sent',
                            text: data.message || 'Please check your email inbox for password reset instructions.',
                            confirmButtonColor: '#b3261e'
                        });
                    }
                } else {
                    showAlert('<i class="fas fa-circle-exclamation"></i> ' + (data.message || 'Unable to process request. Please try again.'), 'error');
                }
            } catch (err) {
                showAlert('<i class="fas fa-circle-exclamation"></i> An unexpected error occurred. Please try again.', 'error');
            } finally {
                setLoading(false);
            }
        });
    }

    function showAlert(msg, type) {
        if (!alertBox) return;
        alertBox.className = 'forgot-modal-alert ' + type;
        alertBox.innerHTML = msg;
        alertBox.style.display = 'flex';
    }

    function hideAlert() {
        if (alertBox) alertBox.style.display = 'none';
    }

    function setLoading(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        submitBtn.innerHTML = isLoading
            ? '<i class="fas fa-circle-notch fa-spin"></i> <span>Sending Link...</span>'
            : '<i class="fas fa-paper-plane"></i> <span>Send Reset Link</span>';
    }
});
</script>
