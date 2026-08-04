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
                        mysqli_stmt_close($upd_stmt);
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
                    if ($upd_stmt) {
                        @mysqli_stmt_close($upd_stmt);
                    }
                }
            } else {
                mysqli_stmt_close($check_stmt);
                // Tell the user explicitly — their email is not in our system.
                $error = 'No account was found with that email address. Please check your email or create a new account.';
            }
        }
    }

    // Handle AJAX requests (Forgot Password modal in login.php)
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

<!-- Add SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
.login-page-container {
    min-height: calc(100vh - 70px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.login-wrapper {
    background-color: white;
    border-radius: 16px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    max-width: 500px;
    width: 100%;
    min-height: 500px;
    animation: fadeIn 0.6s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.login-right {
    padding: 50px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.back-link {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #c62828;
    text-decoration: none;
    font-weight: 600;
    margin-bottom: 20px;
    transition: all 0.3s;
    padding: 8px 12px;
    border-radius: 8px;
    width: fit-content;
}

.back-link:hover {
    color: #8B0000;
    background-color: rgba(198, 40, 40, 0.05);
}

.login-header {
    margin-bottom: 30px;
    text-align: center;
}

.login-header h2 {
    color: #333;
    font-size: 1.8rem;
    margin-bottom: 10px;
    font-weight: 700;
}

.login-header p {
    color: #666;
    font-size: 1rem;
    line-height: 1.5;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
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
    color: #C62828;
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
    color: #333;
    font-weight: 600;
    font-size: 0.95rem;
}

.form-control {
    width: 100%;
    padding: 15px 18px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.3s;
    font-family: inherit;
    background-color: #fafafa;
}

.form-control:focus {
    outline: none;
    border-color: #c62828;
    background-color: white;
    box-shadow: 0 0 0 4px rgba(198, 40, 40, 0.1);
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

.btn-primary {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #c62828 0%, #8B0000 100%);
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
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(198, 40, 40, 0.3);
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

.auth-link {
    text-align: center;
    margin-top: 25px;
    color: #666;
    font-size: 0.95rem;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.auth-link a {
    color: #c62828;
    text-decoration: none;
    font-weight: 600;
    margin-left: 5px;
    transition: all 0.3s;
}

.auth-link a:hover {
    color: #8B0000;
    text-decoration: underline;
}

@media (max-width: 576px) {
    .login-page-container {
        padding: 20px;
    }
    
    .login-wrapper {
        min-height: auto;
    }
    
    .login-right {
        padding: 40px 25px;
    }
    
    .login-header h2 {
        font-size: 1.5rem;
    }
}

/* Modern Food Reset Refresh */
:root {
    --reset-red: #b3261e;
    --reset-orange: #ef6b2e;
    --reset-cream: #fff8ef;
    --reset-ink: #2a211d;
    --reset-muted: #7b6d64;
    --reset-border: #efddcc;
}

body {
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        var(--reset-cream);
}

.login-page-container {
    background: transparent;
}

.login-wrapper {
    border: 1px solid var(--reset-border);
    border-radius: 22px;
    box-shadow: 0 20px 40px rgba(74, 32, 20, 0.14);
}

.login-right {
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.back-link {
    border: 1px solid #e9d2bf;
    background: #fff5ea;
}

.login-header h2,
.form-group label {
    color: var(--reset-ink);
}

.login-header p,
.auth-link,
.auth-link a {
    color: var(--reset-muted);
}

.form-control {
    border: 1px solid #e8d4c3;
    background: #fffefc;
}

.form-control:focus {
    border-color: #d06d44;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, var(--reset-red), var(--reset-orange));
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover:not(:disabled) {
    box-shadow: 0 15px 34px rgba(179, 38, 30, 0.34);
}
</style>

<div class="login-page-container">
    <div class="login-wrapper">
        <div class="login-right">
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                <span>Back to login</span>
            </a>
            
            <div class="login-header">
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
                
                <div class="auth-link">
                    Remember your password? 
                    <a href="login.php">Sign in here</a>
                </div>

                <div class="auth-link" style="margin-top: 12px;">
                    <a href="smtp_test.php">Test SMTP settings</a>
                </div>
            </form>
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
                    confirmButtonColor: '#c62828'
                });
                return false;
            }
            
            if (!isValidEmail(email)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address (e.g., user@example.com).',
                    confirmButtonColor: '#c62828'
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
        confirmButtonColor: '#c62828',
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
