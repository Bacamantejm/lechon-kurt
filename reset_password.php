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

    // Re-verify token validity for POST processing
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

<!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
/* Main Layout Styles */
.login-page-container {
    background: #ffffff !important;
    display: flex;
    align-items: stretch;
    justify-content: stretch;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    overflow: hidden !important;
    padding: 0 !important;
}

.login-wrapper {
    max-width: 100% !important;
    width: 100%;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    background-color: white;
    border-radius: 0 !important;
    border: none;
    box-shadow: none !important;
    display: flex;
    flex-direction: row;
    margin: 0 !important;
    animation: fadeIn 0.6s ease-out;
    overflow: hidden;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Left Side - Brand/Info */
.login-left {
    width: 50%;
    background: linear-gradient(135deg, #b3261e 0%, #8f261a 100%) !important;
    display: flex !important;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px !important;
    text-align: center;
    position: relative;
    overflow: hidden;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
}

.brand-title {
    font-family: 'Outfit', sans-serif;
    font-size: 3.8rem;
    font-weight: 900;
    letter-spacing: -1.5px;
    margin: 0;
    color: #ffffff !important;
    text-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
}

.brand-subtitle {
    font-size: 1.25rem;
    color: rgba(255, 255, 255, 0.9) !important;
    margin-top: 15px;
    max-width: 340px;
    font-weight: 600;
    line-height: 1.6;
}

.floating-pigs-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}

.floating-pig {
    position: absolute;
    font-size: 3.5rem;
    opacity: 0.16;
    animation: floatPig 8s ease-in-out infinite alternate;
}

.pig-1 { top: 10%; left: 15%; animation-duration: 9s; font-size: 4rem; }
.pig-2 { top: 25%; right: 15%; animation-duration: 11s; animation-delay: 1s; font-size: 3.5rem; }
.pig-3 { bottom: 20%; left: 20%; animation-duration: 10s; animation-delay: 2s; font-size: 4.5rem; }
.pig-4 { bottom: 15%; right: 25%; animation-duration: 8s; animation-delay: 0.5s; font-size: 3rem; }
.pig-5 { top: 50%; left: 40%; animation-duration: 12s; animation-delay: 1.5s; font-size: 3.8rem; }

@keyframes floatPig {
    0% { transform: translateY(0) rotate(0deg) scale(1); }
    50% { transform: translateY(-20px) rotate(8deg) scale(1.05); }
    100% { transform: translateY(10px) rotate(-8deg) scale(0.95); }
}

/* Right Side - Forms */
.login-right {
    width: 50%;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    padding: 44px 24px 36px !important;
    height: calc(100vh - var(--site-header-offset, 64px)) !important;
    max-height: calc(100vh - var(--site-header-offset, 64px)) !important;
    overflow-y: auto !important;
    box-sizing: border-box;
}

.login-header {
    margin-bottom: 24px;
    text-align: center;
}

.login-header h2 {
    color: #171922;
    font-size: 1.8rem;
    margin-bottom: 10px;
    font-weight: 700;
}

.login-header p {
    color: #7b6d64;
    font-size: 1rem;
    line-height: 1.5;
}

/* Form Styles */
.login-form {
    animation: slideUp 0.5s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #2a211d;
    font-weight: 700;
    font-size: 0.95rem;
}

.form-control {
    width: 100%;
    padding: 15px 48px 15px 50px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
    background-color: #ffffff;
    color: #171922;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #b3261e;
    background-color: #ffffff;
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.12);
}

.form-control.is-error {
    border-color: #b3261e;
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.15);
}

.input-with-icon {
    position: relative;
}

.input-with-icon .input-icon {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 1.1rem;
    pointer-events: none;
    transition: color 0.3s;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    padding: 8px;
    font-size: 1.1rem;
    transition: color 0.3s;
}

.toggle-password:hover {
    color: #b3261e;
}

/* Password strength meter */
.pw-strength-wrap {
    margin-top: 10px;
}

.pw-strength-bars {
    display: flex;
    gap: 6px;
    height: 4px;
    margin-bottom: 6px;
}

.pw-bar {
    flex: 1;
    border-radius: 4px;
    background: #e8d4c3;
    transition: background 0.3s;
}

.pw-bar.active-weak   { background: #dc2626; }
.pw-bar.active-fair   { background: #f59e0b; }
.pw-bar.active-good   { background: #16a34a; }
.pw-bar.active-strong { background: #15803d; }

.pw-strength-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #7b6d64;
    transition: color 0.2s;
}

/* Requirements checklist */
.pw-reqs {
    margin: 12px 0 24px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.pw-req-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: #7b6d64;
    transition: color 0.2s;
}

.pw-req-item i {
    width: 16px;
    text-align: center;
    color: #d0c0b6;
    transition: color 0.2s;
}

.pw-req-item.met {
    color: #15803d;
    font-weight: 600;
}

.pw-req-item.met i {
    color: #15803d;
}

/* Button Styles */
.btn-primary {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    position: relative;
    overflow: hidden;
    letter-spacing: 0.5px;
    margin-top: 10px;
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 15px 34px rgba(179, 38, 30, 0.34);
}

.btn-primary:active:not(:disabled) {
    transform: translateY(-1px);
}

.btn-primary:disabled {
    background: #cccccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-primary.loading {
    color: transparent;
}

.btn-primary.loading::after {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Auth Link */
.auth-link {
    text-align: center;
    margin-top: 25px;
    color: #7b6d64;
    font-size: 0.95rem;
    padding-top: 20px;
    border-top: 1px solid #efddcd;
}

.auth-link a {
    color: #b3261e !important;
    text-decoration: none !important;
    font-weight: 700 !important;
    transition: all 0.3s;
}

.auth-link a:hover {
    color: #8f261a !important;
    text-decoration: underline !important;
}

/* Status State Box */
.rp-state-box {
    text-align: center;
    padding: 10px 0 20px;
}

.rp-state-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 28px;
}

.rp-state-icon.success { background: #dcfce7; color: #15803d; }
.rp-state-icon.error   { background: #fee2e2; color: #b3261e; }

.rp-state-box h2 { margin: 0 0 10px; color: #171922; font-size: 1.5rem; font-weight: 800; }
.rp-state-box p  { margin: 0; color: #7b6d64; font-size: 0.95rem; line-height: 1.6; }

/* Responsive Design */
@media (max-width: 850px) {
    .login-wrapper {
        flex-direction: column;
    }
    .login-right {
        width: 100%;
        height: auto;
        min-height: calc(100vh - 64px);
        padding: 40px 20px !important;
    }
    .login-left {
        display: none !important;
    }
    input, select, textarea, .form-control {
        font-size: 16px !important;
    }
}
</style>

<div class="login-page-container">
    <div class="login-wrapper">
        <!-- Left Side: Branding Panel with Floating Mascot Pigs -->
        <div class="login-left">
            <div class="floating-pigs-container">
                <div class="floating-pig pig-1">🐷</div>
                <div class="floating-pig pig-2">🐷</div>
                <div class="floating-pig pig-3">🐷</div>
                <div class="floating-pig pig-4">🐷</div>
                <div class="floating-pig pig-5">🐷</div>
            </div>
            <div class="brand-content" style="position: relative; z-index: 10;">
                <h1 class="brand-title">Lechon Delights</h1>
                <p class="brand-subtitle">Cavite's Finest Lechon at Your Doorsteps</p>
            </div>
        </div>

        <!-- Reset Password Form Section -->
        <div class="login-right">
            <div style="max-width: 440px; width: 100%; margin: auto 0; display: flex; flex-direction: column;">
                <div class="login-header">
                    <div style="display: inline-flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 12px;">
                        <img src="assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 48px; height: 48px; object-fit: cover; border-radius: 12px; display: block; border: 1px solid #efddcd; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                        <span style="font-size: 1.6rem; font-weight: 800; color: #171922; font-family: 'Outfit', sans-serif;">Lechon Delights</span>
                    </div>
                    <h2><?php echo $success ? 'Password Updated' : ($is_valid_token ? 'Create New Password' : 'Link Expired'); ?></h2>
                    <p><?php echo $success ? 'Your account is secured.' : ($is_valid_token ? 'Choose a strong password for your account.' : 'Request a new password reset link.'); ?></p>
                </div>

                <?php if ($success): ?>
                <!-- Success state -->
                <div class="rp-state-box">
                    <div class="rp-state-icon success">
                        <i class="fas fa-check"></i>
                    </div>
                    <h2>All Done!</h2>
                    <p><?php echo htmlspecialchars($success); ?></p>
                    <a href="login.php" class="btn-primary" style="text-decoration:none;margin-top:24px;">
                        <i class="fas fa-sign-in-alt"></i> <span>Sign In Now</span>
                    </a>
                </div>

                <?php elseif (!$is_valid_token): ?>
                <!-- Invalid / expired token state -->
                <div class="rp-state-box">
                    <div class="rp-state-icon error">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h2>Link Expired</h2>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <a href="reset_password_request.php" class="btn-primary" style="text-decoration:none;margin-top:24px;">
                        <i class="fas fa-redo"></i> <span>Request a New Link</span>
                    </a>
                </div>

                <?php else: ?>
                <!-- Password reset form -->
                <form method="POST" id="resetPasswordForm" class="login-form" autocomplete="off">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="reset_password_submit" value="1">

                    <!-- New password -->
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

                        <!-- Strength indicator -->
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

                    <!-- Requirements checklist -->
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

                    <!-- Confirm password -->
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   class="form-control"
                                   placeholder="Repeat your new password"
                                   required
                                   autocomplete="new-password">
                            <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password visibility">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" name="reset_password_submit" id="resetSubmitBtn">
                        <i class="fas fa-key"></i> <span>Update Password</span>
                    </button>
                </form>
                <?php endif; ?>

                <div class="auth-link">
                    Remembered your password? <a href="login.php">Sign in here</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });

    // Password strength meter
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
            icon.className = met ? 'fas fa-check-circle' : 'fas fa-circle-dot';
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
    const labelColors = ['', '#dc2626', '#f59e0b', '#16a34a', '#15803d'];

    if (newPwInput) {
        newPwInput.addEventListener('input', function () {
            const pw = this.value;
            if (pw.length === 0) {
                if (strengthWrap) strengthWrap.style.display = 'none';
                bars.forEach(b => { if (b) b.className = 'pw-bar'; });
                return;
            }
            if (strengthWrap) strengthWrap.style.display = 'block';
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

    // Form submit validation
    const form = document.getElementById('resetPasswordForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const pw      = newPwInput ? newPwInput.value : '';
            const confirm = confirmInput ? confirmInput.value : '';
            const btn     = document.getElementById('resetSubmitBtn');

            if (pw.length < 8) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Too Short', text: 'Password must be at least 8 characters.', confirmButtonColor: '#b3261e' });
                return false;
            }
            if (!/[A-Z]/.test(pw)) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing Uppercase', text: 'Add at least one uppercase letter (A–Z).', confirmButtonColor: '#b3261e' });
                return false;
            }
            if (!/[0-9]/.test(pw)) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing Number', text: 'Add at least one number (0–9).', confirmButtonColor: '#b3261e' });
                return false;
            }
            if (pw !== confirm) {
                e.preventDefault();
                if (confirmInput) confirmInput.classList.add('is-error');
                Swal.fire({ icon: 'warning', title: 'Passwords Don\'t Match', text: 'Both fields must contain the same password.', confirmButtonColor: '#b3261e' });
                return false;
            }

            if (btn) {
                btn.classList.add('loading');
                const span = btn.querySelector('span');
                if (span) span.textContent = 'Updating Password...';
            }
        });
    }

    // Confirm match live feedback
    if (confirmInput && newPwInput) {
        confirmInput.addEventListener('input', function () {
            const match = this.value === newPwInput.value;
            this.classList.toggle('is-error', this.value.length > 0 && !match);
        });
    }

    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Password Updated!',
        text: '<?php echo addslashes($success); ?>',
        confirmButtonColor: '#b3261e',
        confirmButtonText: 'Sign In Now',
        allowOutsideClick: false,
        allowEscapeKey: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'login.php';
        }
    });
    <?php endif; ?>

    <?php if ($error && $is_valid_token): ?>
    Swal.fire({
        icon: 'error',
        title: 'Unable to Reset',
        text: '<?php echo addslashes($error); ?>',
        confirmButtonColor: '#b3261e'
    });
    <?php endif; ?>
});
</script>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
include 'includes/footer.php';
?>
