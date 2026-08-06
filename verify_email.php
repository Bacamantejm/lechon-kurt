<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/email_verification_helper.php';

// Handle AJAX verification or resend requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action   = trim((string)($_POST['action'] ?? 'verify_otp'));
    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $otp_code = trim((string)($_POST['otp_code'] ?? ''));

    if ($action === 'resend_otp') {
        $result = resendUserEmailVerificationByEmail($conn, $email);
        echo json_encode($result);
        exit;
    }

    // Default action: verify_otp
    $result = verifyUserEmailOtp($conn, $email, $otp_code);
    echo json_encode($result);
    exit;
}

// Handle GET token link clicks
$token = trim((string)($_GET['token'] ?? ''));
$email_param = strtolower(trim((string)($_GET['email'] ?? $_SESSION['register_email'] ?? '')));
$token_result = null;

if ($token !== '') {
    $token_result = verifyUserEmailToken($conn, $token);
    if (!empty($token_result['success'])) {
        $_SESSION['email_verification_success'] = (string)($token_result['message'] ?? 'Your email address has been verified successfully!');
    } else {
        $_SESSION['email_verification_error'] = (string)($token_result['message'] ?? 'Unable to verify your email address.');
    }
}

$page_title = 'Verify Your Email | Lechon Delights';
include 'includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
:root {
    --otp-red:    #b3261e;
    --otp-orange: #ef6b2e;
    --otp-cream:  #fff8ef;
    --otp-ink:    #2a211d;
    --otp-muted:  #7b6d64;
    --otp-border: #efddcd;
}

.otp-page {
    min-height: calc(100vh - 120px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background:
        radial-gradient(circle at 10% 10%, rgba(239,107,46,.12), transparent 35%),
        radial-gradient(circle at 90% 10%, rgba(179,38,30,.09), transparent 32%),
        var(--otp-cream);
}

.otp-card {
    background: #ffffff;
    border: 1px solid var(--otp-border);
    border-radius: 24px;
    box-shadow: 0 20px 48px rgba(74,32,20,.13);
    max-width: 520px;
    width: 100%;
    overflow: hidden;
    animation: otpFadeUp .45s cubic-bezier(.22,1,.36,1) both;
}

@keyframes otpFadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

.otp-header-bar {
    background: linear-gradient(135deg, var(--otp-red) 0%, var(--otp-orange) 100%);
    padding: 32px 36px 26px;
    text-align: center;
    color: #ffffff;
}

.otp-icon-wrap {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    border: 2px solid rgba(255,255,255,.30);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    font-size: 24px;
    color: #ffffff;
}

.otp-header-bar h1 {
    margin: 0 0 6px;
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff;
}

.otp-header-bar p {
    margin: 0;
    font-size: .88rem;
    color: rgba(255,255,255,.88);
    line-height: 1.5;
}

.otp-body {
    padding: 32px 36px 36px;
    background: linear-gradient(180deg, #fffaf4 0%, #ffffff 100%);
}

.otp-email-badge {
    background: #fff5ea;
    border: 1px solid var(--otp-border);
    border-radius: 12px;
    padding: 12px 16px;
    text-align: center;
    font-size: .9rem;
    color: var(--otp-ink);
    margin-bottom: 24px;
    word-break: break-all;
}

.otp-email-badge strong {
    color: var(--otp-red);
}

/* 6-Digit Box Layout */
.otp-boxes {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 24px;
}

.otp-box {
    width: 52px;
    height: 62px;
    border: 2px solid var(--otp-border);
    border-radius: 12px;
    font-size: 1.7rem;
    font-weight: 800;
    text-align: center;
    color: var(--otp-ink);
    background: #fffefc;
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    font-family: Courier, monospace;
}

.otp-box:focus {
    outline: none;
    border-color: var(--otp-orange);
    box-shadow: 0 0 0 4px rgba(239,107,46,.15);
    transform: scale(1.04);
}

.otp-box.filled {
    border-color: var(--otp-red);
    background: #fffaf3;
}

/* Submit button */
.otp-btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--otp-red), var(--otp-orange));
    color: #ffffff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 26px rgba(179,38,30,.26);
    transition: box-shadow .2s, transform .15s, opacity .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.otp-btn:hover:not(:disabled) {
    box-shadow: 0 14px 32px rgba(179,38,30,.34);
    transform: translateY(-2px);
}

.otp-btn:disabled {
    opacity: .65;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.otp-btn.loading { color: transparent; }
.otp-btn.loading::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.35);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: otpSpin .8s linear infinite;
}

@keyframes otpSpin { to { transform: rotate(360deg); } }

.otp-footer-links {
    margin-top: 22px;
    text-align: center;
    font-size: .88rem;
    color: var(--otp-muted);
    border-top: 1px solid #f0e5da;
    padding-top: 20px;
}

.otp-resend-btn {
    background: none;
    border: none;
    color: var(--otp-red);
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    font-size: .88rem;
    text-decoration: underline;
    transition: color .2s;
}

.otp-resend-btn:disabled {
    color: #b0a098;
    text-decoration: none;
    cursor: not-allowed;
}

.otp-resend-btn:hover:not(:disabled) {
    color: var(--otp-orange);
}

/* Link Result Banner */
.token-result-banner {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    text-align: center;
    font-size: .9rem;
    font-weight: 600;
}
.token-result-banner.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.token-result-banner.error   { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }

@media (max-width: 480px) {
    .otp-boxes { gap: 6px; }
    .otp-box { width: 44px; height: 54px; font-size: 1.4rem; }
    .otp-body { padding: 24px 20px 28px; }
    .otp-header-bar { padding: 24px 20px 20px; }
}
</style>

<div class="otp-page">
    <div class="otp-card">

        <!-- Header -->
        <div class="otp-header-bar">
            <div class="otp-icon-wrap">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>Email Verification</h1>
            <p>Verify that you created this account to start ordering.</p>
        </div>

        <!-- Body -->
        <div class="otp-body">

            <?php if ($token_result !== null): ?>
                <div class="token-result-banner <?php echo !empty($token_result['success']) ? 'success' : 'error'; ?>">
                    <i class="fas <?php echo !empty($token_result['success']) ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($token_result['message']); ?>
                </div>
                <?php if (!empty($token_result['success'])): ?>
                    <a href="login.php" class="otp-btn" style="text-decoration:none;">
                        <i class="fas fa-sign-in-alt"></i> Proceed to Sign In
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($token_result === null || empty($token_result['success'])): ?>

            <!-- Email Display / Input -->
            <div class="otp-email-badge">
                We sent a 6-digit code to:<br>
                <strong id="displayEmail"><?php echo htmlspecialchars($email_param ?: 'your registered email'); ?></strong>
            </div>

            <form id="otpVerifyForm" autocomplete="off">
                <input type="hidden" id="userEmail" value="<?php echo htmlspecialchars($email_param); ?>">

                <!-- 6 Digit Boxes -->
                <div class="otp-boxes" id="otpBoxContainer">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*" autofocus autocomplete="one-time-code">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]*">
                </div>

                <button type="submit" class="otp-btn" id="verifyOtpBtn">
                    <i class="fas fa-check-circle"></i> Verify Code
                </button>
            </form>

            <div class="otp-footer-links">
                Didn't receive the code?
                <button type="button" class="otp-resend-btn" id="resendOtpBtn" disabled>
                    Resend Code (<span id="resendTimer">60</span>s)
                </button>
                <br><br>
                Wrong email? <a href="register.php" style="color:var(--otp-red);font-weight:600;text-decoration:none;">Create another account</a>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const boxes = Array.from(document.querySelectorAll('.otp-box'));
    const form  = document.getElementById('otpVerifyForm');
    const btn   = document.getElementById('verifyOtpBtn');
    const resendBtn = document.getElementById('resendOtpBtn');
    const resendTimerEl = document.getElementById('resendTimer');
    const emailInput = document.getElementById('userEmail');

    // Focus first box
    if (boxes.length > 0) {
        boxes[0].focus();
    }

    // Input behavior (Auto-advance & Backspace)
    boxes.forEach((box, idx) => {
        box.addEventListener('input', function(e) {
            const val = this.value.replace(/[^0-9]/g, '');
            this.value = val;
            this.classList.toggle('filled', val.length > 0);

            if (val.length > 0 && idx < boxes.length - 1) {
                boxes[idx + 1].focus();
            }

            // Auto submit when all 6 filled
            const fullCode = getEnteredOtp();
            if (fullCode.length === 6) {
                form.requestSubmit();
            }
        });

        box.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                boxes[idx - 1].focus();
            }
        });

        // Handle Paste event
        box.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
            if (pasted) {
                pasted.split('').forEach((char, i) => {
                    if (boxes[i]) {
                        boxes[i].value = char;
                        boxes[i].classList.add('filled');
                    }
                });
                const nextFocus = Math.min(pasted.length, boxes.length - 1);
                boxes[nextFocus].focus();
                if (pasted.length === 6) {
                    form.requestSubmit();
                }
            }
        });
    });

    function getEnteredOtp() {
        return boxes.map(b => b.value).join('');
    }

    // Submit Handler
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const otpCode = getEnteredOtp();
            const email = emailInput ? emailInput.value.trim() : '';

            if (otpCode.length < 6) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Code',
                    text: 'Please enter the complete 6-digit verification code.',
                    confirmButtonColor: '#b3261e'
                });
                return;
            }

            if (!email) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Email Required',
                    text: 'Verification email address is missing. Please re-register or click your email link.',
                    confirmButtonColor: '#b3261e'
                });
                return;
            }

            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'verify_otp');
                formData.append('email', email);
                formData.append('otp_code', otpCode);

                const res = await fetch('verify_email.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Account Verified!',
                        text: data.message || 'Your email address has been verified successfully.',
                        confirmButtonColor: '#b3261e'
                    }).then(() => {
                        window.location.href = 'login.php';
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Verification Failed',
                        text: data.message || 'Invalid verification code. Please check your email and try again.',
                        confirmButtonColor: '#b3261e'
                    });
                }
            } catch (err) {
                console.error('OTP Verification Error:', err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while verifying your code. Please try again.',
                    confirmButtonColor: '#b3261e'
                });
            } finally {
                if (btn) {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                }
            }
        });
    }

    // Resend Timer
    let cooldown = 60;
    function startResendTimer() {
        cooldown = 60;
        if (resendBtn) resendBtn.disabled = true;
        const interval = setInterval(function() {
            cooldown--;
            if (resendTimerEl) resendTimerEl.textContent = cooldown;
            if (cooldown <= 0) {
                clearInterval(interval);
                if (resendBtn) {
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                }
            }
        }, 1000);
    }
    startResendTimer();

    // Resend Handler
    if (resendBtn) {
        resendBtn.addEventListener('click', async function() {
            const email = emailInput ? emailInput.value.trim() : '';
            if (!email) {
                Swal.fire({ icon: 'warning', title: 'Email Missing', text: 'Please enter a valid email address.', confirmButtonColor: '#b3261e' });
                return;
            }

            resendBtn.disabled = true;
            resendBtn.textContent = 'Sending...';

            try {
                const formData = new FormData();
                formData.append('action', 'resend_otp');
                formData.append('email', email);

                const res = await fetch('verify_email.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Code Sent!',
                        text: data.message || 'A new 6-digit verification code has been sent to your email.',
                        confirmButtonColor: '#b3261e'
                    });
                    startResendTimer();
                } else {
                    Swal.fire({ icon: 'error', title: 'Resend Failed', text: data.message || 'Could not send verification code.', confirmButtonColor: '#b3261e' });
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                }
            } catch (err) {
                console.error('Resend OTP Error:', err);
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend Code';
            }
        });
    }
});
</script>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
include 'includes/footer.php';
?>
