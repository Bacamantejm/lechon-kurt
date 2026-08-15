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

    // Initialise or migrate the session key
    if (!isset($_SESSION['pw_reset_attempts']) || !is_array($_SESSION['pw_reset_attempts'])) {
        $_SESSION['pw_reset_attempts'] = [];
    }

    // Prune timestamps that are outside the current window
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

    // ── Check rate limit first ───────────────────────────────────────────────
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

                // Only count as a rate-limit attempt when the email is actually registered.
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
                // Tell the user explicitly — their email is not in our system.
            }
        }
    }
    error_log("=== End Password Reset Request ===\n");

    // Handle AJAX requests (Forgot Password modal)
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

/* Alert Messages */
.alert {
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    animation: slideDown 0.3s ease;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

@keyframes slideDown {
    from { transform: translateY(-10px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.alert-error {
    background-color: #FFEBEE;
    border-left: 4px solid #F44336;
    color: #b3261e;
}

.alert-success {
    background-color: #E8F5E9;
    border-left: 4px solid #4CAF50;
    color: #2E7D32;
}

.alert i {
    font-size: 1.2rem;
    margin-top: 2px;
    flex-shrink: 0;
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
    margin-bottom: 25px;
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
    padding: 15px 18px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
    background-color: #ffffff;
}

.form-control:focus {
    outline: none;
    border-color: #b3261e;
    background-color: #ffffff;
    box-shadow: 0 0 0 4px rgba(179, 38, 30, 0.12);
}

.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
    font-size: 1.1rem;
}

.input-with-icon .form-control {
    padding-left: 50px;
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

        <!-- Reset Request Form Section -->
        <div class="login-right">
            <div style="max-width: 440px; width: 100%; margin: auto 0; display: flex; flex-direction: column;">
                <div class="login-header">
                    <div style="display: inline-flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 12px;">
                        <img src="assets/images/logo.jpg" alt="Lechon Delights Logo" style="width: 48px; height: 48px; object-fit: cover; border-radius: 12px; display: block; border: 1px solid #efddcd; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                        <span style="font-size: 1.6rem; font-weight: 800; color: #171922; font-family: 'Outfit', sans-serif;">Lechon Delights</span>
                    </div>
                    <h2>Reset Your Password</h2>
                    <p>Enter your email address and we'll send you a link to reset your password.</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="login-form" id="resetRequestForm">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email" class="form-control" required 
                                placeholder="Enter your registered email address"
                                autocomplete="email"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" name="reset_request" class="btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send Reset Link</span>
                    </button>

                    <div class="auth-link" style="text-align: center;">
                        Remember your password? 
                        <a href="login.php">Sign in here</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const resetForm = document.getElementById('resetRequestForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value.trim();
            const btn = document.getElementById('submitBtn');
            
            if (!email) {
                Swal.fire({
                    icon: 'info',
                    title: 'Email Required',
                    text: 'Please enter your email address to continue.',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
            
            if (!isValidEmail(email)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address (e.g., user@example.com).',
                    confirmButtonColor: '#b3261e'
                });
                return false;
            }
            
            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
                const span = btn.querySelector('span');
                if (span) {
                    span.textContent = 'Sending Reset Link...';
                }
            }
            
            this.submit();
        });
    }

    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    setTimeout(() => {
        const emailInput = document.getElementById('email');
        if (emailInput) {
            emailInput.focus();
        }
    }, 300);
    
    <?php if ($error): ?>
    <?php
        // Detect rate-limit errors to show a distinct dialog with countdown
        $is_rate_limited = str_contains($error, 'Too many reset requests');
        $cooldown_secs   = $is_rate_limited ? getResetCooldownSeconds() : 0;
    ?>
    Swal.fire({
        icon: '<?php echo $is_rate_limited ? 'warning' : 'error'; ?>',
        title: '<?php echo $is_rate_limited ? 'Too Many Requests' : 'Oops!'; ?>',
        html: '<?php echo addslashes($error); ?>'
            <?php if ($is_rate_limited && $cooldown_secs > 0): ?>
            + '<br><small id="swalCountdown" style="color:#7b6d64;">Retry available in <strong><?php echo $cooldown_secs; ?></strong>s</small>',
            <?php else: ?>
            ,
            <?php endif; ?>
        confirmButtonColor: '#b3261e',
        confirmButtonText: '<?php echo $is_rate_limited ? 'OK' : 'Try Again'; ?>',
        backdrop: 'rgba(0, 0, 0, 0.4)',
        didOpen: function() {
            Swal.getConfirmButton().focus();
            <?php if ($is_rate_limited && $cooldown_secs > 0): ?>
            let remaining = <?php echo $cooldown_secs; ?>;
            const cd = document.getElementById('swalCountdown');
            const timer = setInterval(function() {
                remaining--;
                if (cd) cd.innerHTML = 'Retry available in <strong>' + remaining + '</strong>s';
                if (remaining <= 0) {
                    clearInterval(timer);
                    if (cd) cd.innerHTML = 'You can try again now.';
                    Swal.getConfirmButton().textContent = 'Try Again';
                }
            }, 1000);
            <?php endif; ?>
        }
    });
    <?php endif; ?>

    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        html: '<p><?php echo addslashes($success); ?></p><p style="font-size: 0.85rem; color: #666; margin-top: 10px;">Check your inbox and spam folder for the reset link.</p>',
        confirmButtonColor: '#b3261e',
        confirmButtonText: 'Done',
        backdrop: 'rgba(0, 0, 0, 0.4)',
        didOpen: function() {
            const button = Swal.getConfirmButton();
            button.focus();
        }
    }).then(() => {
        document.getElementById('email').value = '';
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
