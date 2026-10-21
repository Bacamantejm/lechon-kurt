<?php
session_start();
require_once 'includes/config.php';
require_once 'email_service.php';

$error   = '';
$success = '';

// ── Rate limiting ────────────────────────────────────────────────────────────
// Allow a maximum of 3 reset requests per 5-minute sliding window per session.
define('RESET_RATE_LIMIT',   3);
define('RESET_RATE_WINDOW',  300); // seconds (5 minutes)

function checkResetRateLimit(): bool {
    $now = time();
    $window_start = $now - RESET_RATE_WINDOW;

    if (!isset($_SESSION['pw_reset_attempts']) || !is_array($_SESSION['pw_reset_attempts'])) {
        $_SESSION['pw_reset_attempts'] = [];
    }

    $_SESSION['pw_reset_attempts'] = array_values(
        array_filter($_SESSION['pw_reset_attempts'], fn($t) => $t > $window_start)
    );

    return count($_SESSION['pw_reset_attempts']) < RESET_RATE_LIMIT;
}

function recordResetAttempt(): void {
    if (!isset($_SESSION['pw_reset_attempts']) || !is_array($_SESSION['pw_reset_attempts'])) {
        $_SESSION['pw_reset_attempts'] = [];
    }
    $_SESSION['pw_reset_attempts'][] = time();
}

function getResetCooldownSeconds(): int {
    if (empty($_SESSION['pw_reset_attempts'])) {
        return 0;
    }
    $window_start = time() - RESET_RATE_WINDOW;
    $valid = array_filter($_SESSION['pw_reset_attempts'], fn($t) => $t > $window_start);
    if (count($valid) < RESET_RATE_LIMIT) {
        return 0;
    }
    $oldest = min($valid);
    return max(0, RESET_RATE_WINDOW - (time() - $oldest));
}
// ────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!checkResetRateLimit()) {
        $cooldown = getResetCooldownSeconds();
        $minutes  = ceil($cooldown / 60);
        $error    = "Too many reset requests. Please wait {$minutes} minute(s) before trying again.";

        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $is_ajax = $is_ajax || (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'true');
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error, 'rate_limited' => true, 'retry_after' => $cooldown]);
            exit;
        }
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $check_stmt = mysqli_prepare($conn, 'SELECT id, full_name FROM users WHERE email = ? AND is_active = 1');
        if (!$check_stmt) {
            $error = 'Unable to process your request right now. Please try again shortly.';
        } else {
            mysqli_stmt_bind_param($check_stmt, 's', $email);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                mysqli_stmt_bind_result($check_stmt, $user_id, $full_name);
                mysqli_stmt_fetch($check_stmt);
                mysqli_stmt_close($check_stmt);

                recordResetAttempt();

                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $upd_stmt = mysqli_prepare($conn, 'UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
                if (!$upd_stmt) {
                    $error = 'Unable to process your request right now. Please try again shortly.';
                } else {
                    mysqli_stmt_bind_param($upd_stmt, 'ssi', $token, $expires, $user_id);
                    if (!mysqli_stmt_execute($upd_stmt)) {
                        $error = 'Unable to process your request right now. Please try again shortly.';
                    } else {
                        if (sendPasswordResetEmail($conn, $email, $full_name, $token)) {
                            $success = 'Password reset link has been sent to your email. Please check your inbox and spam folder.';
                        } else {
                            $error = 'Failed to send the reset email. Please try again later or contact support.';
                            if (!empty($_SESSION['mail_error'])) {
                                error_log('Mail error: ' . $_SESSION['mail_error']);
                                unset($_SESSION['mail_error']);
                            }
                        }
                    }
                    mysqli_stmt_close($upd_stmt);
                }
            } else {
                mysqli_stmt_close($check_stmt);
                $error = 'No account found with this email address. Please verify or register a new account.';
            }
        }
    }

    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $is_ajax = $is_ajax || (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'true');
    if ($is_ajax) {
        header('Content-Type: application/json');
        if (!empty($error)) {
            echo json_encode(['success' => false, 'message' => $error]);
        } else {
            echo json_encode(['success' => true, 'message' => $success]);
        }
        exit;
    }
}

/**
 * Send password reset email using the dedicated branded mailer method.
 */
function sendPasswordResetEmail($conn, $email, $full_name, $token) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script_dir === '/' || $script_dir === '\\' || $script_dir === '.') {
        $script_dir = '';
    }
    $reset_link = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $script_dir . '/reset_password.php?token=' . urlencode($token);

    $mailer = new EmailService($conn);
    $sent = $mailer->sendPasswordResetEmail($email, $full_name, $reset_link);

    if (!$sent) {
        $_SESSION['mail_error'] = $mailer->getLastError();
    }

    return $sent;
}

$page_title = "Reset Password | Lechon Delights";
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
    margin-bottom: 22px;
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
    padding: 14px 16px 14px 44px;
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

.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #667085;
    font-size: 1rem;
    pointer-events: none;
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
body.dark-mode .login-header h2 {
    color: #f8fafc !important;
}

body.dark-mode .login-header p {
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

body.dark-mode .input-with-icon i {
    color: #94a3b8 !important;
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
                    <i class="fas fa-shield-alt"></i> Account Security
                </div>
                <h1 class="brand-title">Lechon Delights</h1>
                <p class="brand-subtitle">Reset your account password quickly and securely to get back to ordering Cavite's finest lechon.</p>
                
                <div class="brand-feature-list">
                    <div class="brand-feature-item">
                        <div class="brand-feature-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="brand-feature-text">
                            <h4>Encrypted &amp; Secure</h4>
                            <p>One-time secure tokens expire in 10 minutes</p>
                        </div>
                    </div>
                    <div class="brand-feature-item">
                        <div class="brand-feature-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <div class="brand-feature-text">
                            <h4>Direct Email Delivery</h4>
                            <p>Instant password reset instructions delivered to you</p>
                        </div>
                    </div>
                    <div class="brand-feature-item">
                        <div class="brand-feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <div class="brand-feature-text">
                            <h4>Customer Support</h4>
                            <p>Available 24/7 if you need assistance recovering access</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Reset Form Section -->
        <div class="login-right">
            <div class="auth-form-card">
                <div class="login-header">
                    <a href="index.php" class="brand-logo-row">
                        <img src="assets/images/logo.jpg" alt="Lechon Delights Logo">
                        <span>Lechon Delights</span>
                    </a>
                    <h2>Reset Your Password</h2>
                    <p>Enter your registered email address and we will send you a secure link to reset your password.</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-circle-check"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="login-form" id="resetRequestForm">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" class="form-control" required 
                                placeholder="name@example.com"
                                autocomplete="email"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" name="reset_request" class="btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send Reset Link</span>
                    </button>

                    <div class="auth-link">
                        Remember your password? 
                        <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const resetForm = document.getElementById('resetRequestForm');
    const emailInput = document.getElementById('email');
    const submitBtn = document.getElementById('submitBtn');

    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            const email = emailInput ? emailInput.value.trim() : '';
            
            if (!email) {
                e.preventDefault();
                if (window.showPopupAlert) {
                    window.showPopupAlert('Please enter your email address.', 'alert');
                }
                return false;
            }
            
            if (!isValidEmail(email)) {
                e.preventDefault();
                if (window.showPopupAlert) {
                    window.showPopupAlert('Please enter a valid email address (e.g. name@example.com).', 'alert');
                }
                return false;
            }
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Sending Reset Link...</span>';
            }
        });
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    setTimeout(() => {
        if (emailInput) {
            emailInput.focus();
        }
    }, 250);

    <?php if ($error): ?>
    if (window.showPopupAlert) {
        window.showPopupAlert(<?php echo json_encode($error); ?>, 'error', 5000);
    }
    <?php endif; ?>

    <?php if ($success): ?>
    if (window.showPopupAlert) {
        window.showPopupAlert(<?php echo json_encode($success); ?>, 'success', 5000);
    }
    if (emailInput) {
        emailInput.value = '';
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
