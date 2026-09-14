<?php
session_start();
require_once 'includes/config.php';

$error   = '';
$success = '';
$token   = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$is_valid_token = false;

if ($token === '') {
    $error = 'Invalid or expired reset link. Please request a new password reset.';
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid reset token format. Please request a new password reset.';
} else {
    $user_id = validateResetToken($conn, $token);
    if (!$user_id) {
        $error = 'This reset link has expired or already been used. Please request a new one.';
    } else {
        $is_valid_token = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token            = trim((string)($_POST['token'] ?? $token));
    $new_password     = $_POST['new_password']     ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $user_id = validateResetToken($conn, $token);

    if (!$user_id) {
        $error = 'Invalid or expired reset link. Please request a new password reset.';
        $is_valid_token = false;
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error = 'Password must contain at least one number.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $reset_ok = resetPassword($conn, $token, $new_password);
        if ($reset_ok) {
            $success = 'Your password has been updated successfully. You can now sign in with your new password.';
            $is_valid_token = false;
        } else {
            $error = 'Unable to reset your password right now. The link may have expired. Please request a new one.';
        }
    }
}

$page_title = 'Create New Password | Lechon Delights';
include 'includes/header.php';
?>

<style>
/* Reset Password Page Layout */
.login-page-container {
    background: #f8f9fa !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    min-height: calc(100vh - var(--site-header-offset, 64px));
    padding: 0 !important;
}

.login-wrapper {
    max-width: 100% !important;
    width: 100%;
    min-height: calc(100vh - var(--site-header-offset, 64px));
    background-color: #ffffff;
    display: flex;
    flex-direction: row;
    margin: 0 !important;
    overflow: hidden;
}

/* Left Brand Showcase */
.login-left {
    width: 48%;
    background: linear-gradient(135deg, #182234 0%, #1e293b 60%, #0f172a 100%) !important;
    display: flex !important;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 40px !important;
    text-align: center;
    position: relative;
    overflow: hidden;
    color: #ffffff !important;
    border-right: 1px solid #334155;
}

.brand-showcase-card {
    position: relative;
    z-index: 10;
    max-width: 420px;
}

.brand-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    background: rgba(179, 38, 30, 0.25);
    border: 1px solid rgba(179, 38, 30, 0.4);
    border-radius: 999px;
    color: #f87171;
    font-size: 0.82rem;
    font-weight: 700;
    margin-bottom: 20px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.brand-title {
    font-family: 'Outfit', sans-serif;
    font-size: 2.8rem;
    font-weight: 900;
    letter-spacing: -0.03em;
    margin: 0 0 12px 0;
    color: #ffffff !important;
    line-height: 1.15;
}

.brand-subtitle {
    font-size: 1.05rem;
    color: #94a3b8 !important;
    margin: 0 0 32px 0;
    font-weight: 500;
    line-height: 1.6;
}

.brand-feature-list {
    display: grid;
    gap: 14px;
    text-align: left;
    margin-top: 10px;
}

.brand-feature-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: rgba(30, 41, 59, 0.6);
    border: 1px solid rgba(51, 65, 85, 0.6);
    border-radius: 12px;
}

.brand-feature-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(179, 38, 30, 0.2);
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.brand-feature-text h4 {
    margin: 0 0 2px 0;
    font-size: 0.92rem;
    font-weight: 700;
    color: #f8fafc;
}

.brand-feature-text p {
    margin: 0;
    font-size: 0.8rem;
    color: #94a3b8;
}

/* Right Side - Form Section */
.login-right {
    width: 52%;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 32px !important;
    overflow-y: auto;
    box-sizing: border-box;
}

.auth-form-card {
    max-width: 440px;
    width: 100%;
    margin: auto 0;
    display: flex;
    flex-direction: column;
}

.login-header {
    margin-bottom: 24px;
    text-align: center;
}

.brand-logo-row {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 16px;
    text-decoration: none;
}

.brand-logo-row img {
    width: 44px;
    height: 44px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid #eaecf0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.brand-logo-row span {
    font-size: 1.45rem;
    font-weight: 800;
    color: #101828;
    font-family: 'Outfit', sans-serif;
}

.login-header h2 {
    color: #101828;
    font-size: 1.75rem;
    font-weight: 800;
    margin: 0 0 8px 0;
    font-family: 'Outfit', sans-serif;
}

.login-header p {
    color: #475467;
    font-size: 0.92rem;
    line-height: 1.5;
    margin: 0;
}

/* Inline Alert Banners */
.alert {
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 22px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.9rem;
    line-height: 1.45;
}

.alert-error {
    background-color: #fff1f0;
    border: 1px solid #fee4e2;
    color: #b3261e;
}

.alert-success {
    background-color: #ecfdf3;
    border: 1px solid #abefc6;
    color: #027a48;
}

.alert i {
    font-size: 1.15rem;
    margin-top: 2px;
    flex-shrink: 0;
}

/* Form Controls */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #344054;
    font-weight: 700;
    font-size: 0.9rem;
}

.form-control {
    width: 100%;
    padding: 14px 44px 14px 44px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 0.95rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    font-family: inherit;
    background-color: #ffffff;
    color: #101828;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.15);
}

.form-control.is-error {
    border-color: #b3261e;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.15);
}

.input-with-icon {
    position: relative;
}

.input-with-icon .input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #667085;
    font-size: 1rem;
    pointer-events: none;
}

.input-with-icon .toggle-password {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #667085;
    cursor: pointer;
    font-size: 1rem;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.input-with-icon .toggle-password:hover {
    color: #101828;
}

/* Password Strength Meter */
.pw-strength-wrap {
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.pw-strength-bars {
    display: flex;
    gap: 4px;
    flex: 1;
}

.pw-bar {
    height: 4px;
    flex: 1;
    background: #eaecf0;
    border-radius: 999px;
    transition: background-color 0.3s ease;
}

.pw-bar.active-weak { background: #ef4444; }
.pw-bar.active-fair { background: #f59e0b; }
.pw-bar.active-good { background: #10b981; }
.pw-bar.active-strong { background: #059669; }

.pw-strength-label {
    font-size: 0.78rem;
    font-weight: 700;
    min-width: 45px;
    text-align: right;
}

/* Requirements Checklist */
.pw-reqs {
    display: grid;
    gap: 6px;
    margin: 12px 0 20px 0;
    padding: 12px 14px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    border-radius: 10px;
}

.pw-req-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: #667085;
    transition: color 0.2s ease;
}

.pw-req-item i {
    font-size: 0.75rem;
    color: #98a2b3;
}

.pw-req-item.met {
    color: #027a48;
    font-weight: 600;
}

.pw-req-item.met i {
    color: #12b76a;
}

/* Action Buttons */
.btn-primary {
    width: 100%;
    padding: 14px;
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.15s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 6px;
    text-decoration: none;
}

.btn-primary:hover:not(:disabled) {
    background: #981b15;
    transform: translateY(-1px);
}

.btn-primary:active:not(:disabled) {
    transform: translateY(0);
}

.btn-primary:disabled {
    background: #94a3b8;
    cursor: not-allowed;
    transform: none;
}

/* Auth Links */
.auth-link {
    text-align: center;
    margin-top: 24px;
    color: #475467;
    font-size: 0.92rem;
    padding-top: 20px;
    border-top: 1px solid #eaecf0;
}

.auth-link a {
    color: #b3261e !important;
    text-decoration: none !important;
    font-weight: 700 !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.auth-link a:hover {
    color: #981b15 !important;
    text-decoration: underline !important;
}

/* State Box for Expired Link */
.rp-state-box {
    text-align: center;
    padding: 24px 16px;
}

.rp-state-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin-bottom: 16px;
}

.rp-state-icon.error {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}

.rp-state-box h2 {
    font-size: 1.4rem;
    font-weight: 800;
    color: #101828;
    margin: 0 0 8px 0;
}

.rp-state-box p {
    color: #475467;
    font-size: 0.92rem;
    line-height: 1.5;
    margin: 0;
}

/* ==========================================================================
   RESET PASSWORD DARK MODE ENGINE
   ========================================================================== */
body.dark-mode,
body.dark-mode .login-page-container {
    background: #0f172a !important;
    color: #f8fafc !important;
}

body.dark-mode .login-wrapper {
    background: #0f172a !important;
}

body.dark-mode .login-left {
    background: linear-gradient(135deg, #090d16 0%, #111827 60%, #0f172a 100%) !important;
    border-color: #334155 !important;
}

body.dark-mode .brand-feature-item {
    background: rgba(15, 23, 42, 0.7) !important;
    border-color: #334155 !important;
}

body.dark-mode .login-right {
    background: #0f172a !important;
}

body.dark-mode .brand-logo-row span,
body.dark-mode .login-header h2,
body.dark-mode .rp-state-box h2 {
    color: #f8fafc !important;
}

body.dark-mode .login-header p,
body.dark-mode .rp-state-box p {
    color: #94a3b8 !important;
}

body.dark-mode .form-group label {
    color: #cbd5e1 !important;
}

body.dark-mode .form-control {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
}

body.dark-mode .form-control:focus {
    background-color: #0b1120 !important;
    border-color: #b3261e !important;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.3) !important;
}

body.dark-mode .input-with-icon .input-icon,
body.dark-mode .input-with-icon .toggle-password {
    color: #94a3b8 !important;
}

body.dark-mode .input-with-icon .toggle-password:hover {
    color: #ffffff !important;
}

body.dark-mode .pw-reqs {
    background: #1e293b !important;
    border-color: #334155 !important;
}

body.dark-mode .pw-req-item {
    color: #94a3b8 !important;
}

body.dark-mode .pw-req-item.met {
    color: #4ade80 !important;
}

body.dark-mode .pw-req-item.met i {
    color: #4ade80 !important;
}

body.dark-mode .pw-bar {
    background: #334155 !important;
}

body.dark-mode .auth-link {
    color: #94a3b8 !important;
    border-color: #334155 !important;
}

body.dark-mode .auth-link a {
    color: #f87171 !important;
}

body.dark-mode .auth-link a:hover {
    color: #ef4444 !important;
}

body.dark-mode .alert-error {
    background-color: rgba(179, 38, 30, 0.15) !important;
    border-color: rgba(239, 68, 68, 0.3) !important;
    color: #f87171 !important;
}

body.dark-mode .alert-success {
    background-color: rgba(2, 122, 72, 0.15) !important;
    border-color: rgba(74, 222, 128, 0.3) !important;
    color: #4ade80 !important;
}

body.dark-mode .rp-state-icon.error {
    background: rgba(179, 38, 30, 0.15) !important;
    border-color: rgba(239, 68, 68, 0.3) !important;
    color: #f87171 !important;
}

/* Responsive */
@media (max-width: 850px) {
    .login-wrapper {
        flex-direction: column;
    }
    .login-left {
        display: none !important;
    }
    .login-right {
        width: 100%;
        padding: 40px 20px !important;
        min-height: calc(100vh - 64px);
    }
}
</style>

<div class="login-page-container">
    <div class="login-wrapper">
        <!-- Left Side: Brand Showcase Panel -->
        <div class="login-left">
            <div class="brand-showcase-card">
                <div class="brand-badge">
                    <i class="fas fa-shield-alt"></i> Security Center
                </div>
                <h1 class="brand-title">Lechon Delights</h1>
                <p class="brand-subtitle">Set your new strong password to keep your account safe and continue ordering your favorite dishes.</p>
                
                <div class="brand-feature-list">
                    <div class="brand-feature-item">
                        <div class="brand-feature-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="brand-feature-text">
                            <h4>Password Requirements</h4>
                            <p>Minimum 8 characters with numbers and uppercase</p>
                        </div>
                    </div>
                    <div class="brand-feature-item">
                        <div class="brand-feature-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="brand-feature-text">
                            <h4>Encrypted Storage</h4>
                            <p>Protected by modern cryptographic standards</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Reset Password Form -->
        <div class="login-right">
            <div class="auth-form-card">
                <div class="login-header">
                    <a href="index.php" class="brand-logo-row">
                        <img src="assets/images/logo.jpg" alt="Lechon Delights Logo">
                        <span>Lechon Delights</span>
                    </a>
                    <h2>Create New Password</h2>
                    <p>Enter your new password below to regain full access to your account.</p>
                </div>

                <?php if ($error && $is_valid_token): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-circle-check"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <div style="margin-top: 14px;">
                    <a href="login.php" class="btn-primary">
                        <i class="fas fa-arrow-right-to-bracket"></i> Sign In Now
                    </a>
                </div>
                <?php elseif (!$is_valid_token): ?>
                <!-- Invalid / Expired Token State -->
                <div class="rp-state-box">
                    <div class="rp-state-icon error">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <h2>Reset Link Expired</h2>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <a href="reset_password_request.php" class="btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-rotate-right"></i> <span>Request New Reset Link</span>
                    </a>
                </div>
                <?php else: ?>
                <!-- Password Reset Form -->
                <form method="POST" id="resetPasswordForm" class="login-form" autocomplete="off">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="reset_password_submit" value="1">

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   class="form-control"
                                   placeholder="Create a strong password"
                                   required
                                   autocomplete="new-password">
                            <button type="button" class="toggle-password" data-target="new_password" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <!-- Strength meter -->
                        <div class="pw-strength-wrap" id="strengthWrap" style="display:none;">
                            <div class="pw-strength-bars">
                                <div class="pw-bar" id="bar1"></div>
                                <div class="pw-bar" id="bar2"></div>
                                <div class="pw-bar" id="bar3"></div>
                                <div class="pw-bar" id="bar4"></div>
                            </div>
                            <span class="pw-strength-label" id="strengthLabel">Weak</span>
                        </div>
                    </div>

                    <!-- Requirements Checklist -->
                    <div class="pw-reqs" id="pwReqs">
                        <div class="pw-req-item" id="req-len">
                            <i class="fas fa-circle-dot"></i>
                            <span>At least 8 characters</span>
                        </div>
                        <div class="pw-req-item" id="req-upper">
                            <i class="fas fa-circle-dot"></i>
                            <span>One uppercase letter (A–Z)</span>
                        </div>
                        <div class="pw-req-item" id="req-num">
                            <i class="fas fa-circle-dot"></i>
                            <span>One number (0–9)</span>
                        </div>
                        <div class="pw-req-item" id="req-special">
                            <i class="fas fa-circle-dot"></i>
                            <span>One special character recommended</span>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   class="form-control"
                                   placeholder="Confirm new password"
                                   required
                                   autocomplete="new-password">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" name="reset_password_submit" id="resetSubmitBtn">
                        <i class="fas fa-key"></i> <span>Update Password</span>
                    </button>
                </form>
                <?php endif; ?>

                <div class="auth-link">
                    Remember your password? <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Toggle password visibility
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const icon  = this.querySelector('i');
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    });

    const newPwInput   = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthWrap = document.getElementById('strengthWrap');
    const strengthLabel = document.getElementById('strengthLabel');
    const bars = [
        document.getElementById('bar1'),
        document.getElementById('bar2'),
        document.getElementById('bar3'),
        document.getElementById('bar4'),
    ];

    const reqLen     = document.getElementById('req-len');
    const reqUpper   = document.getElementById('req-upper');
    const reqNum     = document.getElementById('req-num');
    const reqSpecial = document.getElementById('req-special');

    function markReq(el, met) {
        if (!el) return;
        el.classList.toggle('met', met);
        const icon = el.querySelector('i');
        if (icon) {
            icon.className = met ? 'fas fa-circle-check' : 'fas fa-circle-dot';
        }
    }

    function evaluateStrength(pw) {
        let score = 0;
        const hasLen     = pw.length >= 8;
        const hasUpper   = /[A-Z]/.test(pw);
        const hasNum     = /[0-9]/.test(pw);
        const hasSpecial = /[^A-Za-z0-9]/.test(pw);

        markReq(reqLen,     hasLen);
        markReq(reqUpper,   hasUpper);
        markReq(reqNum,     hasNum);
        markReq(reqSpecial, hasSpecial);

        if (hasLen)     score++;
        if (hasUpper)   score++;
        if (hasNum)     score++;
        if (hasSpecial) score++;
        if (pw.length >= 12) score = Math.min(4, score + 1);

        return Math.min(4, score);
    }

    const levelNames  = ['', 'Weak', 'Fair', 'Good', 'Strong'];
    const levelClass  = ['', 'active-weak', 'active-fair', 'active-good', 'active-strong'];
    const labelColors = ['', '#ef4444', '#f59e0b', '#10b981', '#059669'];

    if (newPwInput) {
        newPwInput.addEventListener('input', function () {
            const pw = this.value;
            if (pw.length === 0) {
                if (strengthWrap) strengthWrap.style.display = 'none';
                bars.forEach(b => { if (b) b.className = 'pw-bar'; });
                return;
            }
            if (strengthWrap) strengthWrap.style.display = 'flex';
            const score = evaluateStrength(pw);
            bars.forEach(function (bar, i) {
                if (bar) bar.className = 'pw-bar' + (i < score ? ' ' + levelClass[score] : '');
            });
            if (strengthLabel) {
                strengthLabel.textContent = levelNames[score];
                strengthLabel.style.color = labelColors[score];
            }
        });
    }

    const form = document.getElementById('resetPasswordForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const pw      = newPwInput ? newPwInput.value : '';
            const confirm = confirmInput ? confirmInput.value : '';
            const btn     = document.getElementById('resetSubmitBtn');

            if (pw.length < 8) {
                e.preventDefault();
                if (window.showPopupAlert) window.showPopupAlert('Password must be at least 8 characters long.', 'alert');
                return false;
            }
            if (!/[A-Z]/.test(pw)) {
                e.preventDefault();
                if (window.showPopupAlert) window.showPopupAlert('Please include at least one uppercase letter (A–Z).', 'alert');
                return false;
            }
            if (!/[0-9]/.test(pw)) {
                e.preventDefault();
                if (window.showPopupAlert) window.showPopupAlert('Please include at least one number (0–9).', 'alert');
                return false;
            }
            if (pw !== confirm) {
                e.preventDefault();
                if (confirmInput) confirmInput.classList.add('is-error');
                if (window.showPopupAlert) window.showPopupAlert('Passwords do not match.', 'error');
                return false;
            }

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Updating Password...</span>';
            }
        });
    }

    if (confirmInput && newPwInput) {
        confirmInput.addEventListener('input', function () {
            const match = this.value === newPwInput.value;
            this.classList.toggle('is-error', this.value.length > 0 && !match);
        });
    }

    <?php if ($success): ?>
    if (window.showPopupAlert) {
        window.showPopupAlert(<?php echo json_encode($success); ?>, 'success', 6000);
    }
    <?php endif; ?>

    <?php if ($error && $is_valid_token): ?>
    if (window.showPopupAlert) {
        window.showPopupAlert(<?php echo json_encode($error); ?>, 'error', 5000);
    }
    <?php endif; ?>
});
</script>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
include 'includes/footer.php';
?>
