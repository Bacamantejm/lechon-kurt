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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password_submit'])) {
    $new_password     = $_POST['new_password']     ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$is_valid_token) {
        $error = 'Invalid or expired reset link. Please request a new password reset.';
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

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
:root {
    --rp-red:    #b3261e;
    --rp-orange: #ef6b2e;
    --rp-cream:  #fff8ef;
    --rp-ink:    #2a211d;
    --rp-muted:  #7b6d64;
    --rp-border: #efddcd;
}

.rp-page {
    min-height: calc(100vh - 70px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background:
        radial-gradient(circle at 5% 5%,  rgba(239,107,46,.13), transparent 35%),
        radial-gradient(circle at 95% 10%, rgba(179,38,30,.10), transparent 32%),
        var(--rp-cream);
}

.rp-card {
    background: #fff;
    border: 1px solid var(--rp-border);
    border-radius: 22px;
    box-shadow: 0 20px 48px rgba(74,32,20,.13);
    max-width: 500px;
    width: 100%;
    overflow: hidden;
    animation: rpFadeUp .5s ease both;
}

@keyframes rpFadeUp {
    from { opacity:0; transform:translateY(24px); }
    to   { opacity:1; transform:translateY(0);    }
}

/* ---- Card header bar ---- */
.rp-header-bar {
    background: linear-gradient(135deg, var(--rp-red), var(--rp-orange));
    padding: 28px 36px 24px;
    text-align: center;
}

.rp-header-bar .rp-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    border: 2px solid rgba(255,255,255,.30);
}

.rp-header-bar .rp-icon-wrap i {
    font-size: 22px;
    color: #fff;
}

.rp-header-bar h1 {
    margin: 0 0 6px;
    font-size: 1.45rem;
    font-weight: 700;
    color: #fff;
}

.rp-header-bar p {
    margin: 0;
    font-size: .875rem;
    color: rgba(255,255,255,.85);
}

/* ---- Body ---- */
.rp-body {
    padding: 32px 36px 36px;
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.rp-back {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--rp-red);
    font-size: .875rem;
    font-weight: 600;
    text-decoration: none;
    margin-bottom: 22px;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #e9d2bf;
    background: #fff5ea;
    transition: background .2s, border-color .2s;
}

.rp-back:hover {
    background: #feecd8;
    border-color: var(--rp-orange);
}

/* Form groups */
.rp-form-group {
    margin-bottom: 18px;
}

.rp-form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: .875rem;
    font-weight: 600;
    color: var(--rp-ink);
}

.rp-input-wrap {
    position: relative;
}

.rp-input-wrap .rp-input-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #b0a098;
    font-size: .95rem;
    pointer-events: none;
    transition: color .2s;
}

.rp-input-wrap input:focus ~ .rp-input-icon,
.rp-input-wrap .rp-input-icon.focused {
    color: var(--rp-orange);
}

.rp-toggle-pw {
    position: absolute;
    right: 13px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #b0a098;
    font-size: .9rem;
    padding: 4px;
    transition: color .2s;
}

.rp-toggle-pw:hover { color: var(--rp-red); }

.rp-form-control {
    width: 100%;
    padding: 12px 42px 12px 40px;
    border: 1.5px solid var(--rp-border);
    border-radius: 11px;
    font-size: .95rem;
    background: #fffefc;
    color: var(--rp-ink);
    box-sizing: border-box;
    transition: border-color .2s, box-shadow .2s;
}

.rp-form-control:focus {
    outline: none;
    border-color: #d06d44;
    box-shadow: 0 0 0 3px rgba(239,107,46,.14);
}

.rp-form-control.is-error {
    border-color: var(--rp-red);
    box-shadow: 0 0 0 3px rgba(179,38,30,.12);
}

/* ---- Password strength ---- */
.pw-strength-wrap {
    margin-top: 9px;
}

.pw-strength-bars {
    display: flex;
    gap: 5px;
    height: 4px;
    margin-bottom: 5px;
}

.pw-bar {
    flex: 1;
    border-radius: 4px;
    background: #e8d4c3;
    transition: background .3s;
}

.pw-bar.active-weak   { background: #dc2626; }
.pw-bar.active-fair   { background: #f59e0b; }
.pw-bar.active-good   { background: #16a34a; }
.pw-bar.active-strong { background: #15803d; }

.pw-strength-label {
    font-size: .78rem;
    font-weight: 600;
    color: var(--rp-muted);
    transition: color .2s;
}

/* ---- Requirements checklist ---- */
.pw-reqs {
    margin: 10px 0 20px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.pw-req-item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: .82rem;
    color: #9e8e86;
    transition: color .2s;
}

.pw-req-item i {
    width: 14px;
    text-align: center;
    color: #cfc2ba;
    transition: color .2s;
}

.pw-req-item.met {
    color: #15803d;
}

.pw-req-item.met i {
    color: #15803d;
}

/* ---- Submit button ---- */
.rp-btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 11px;
    background: linear-gradient(135deg, var(--rp-red), var(--rp-orange));
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 26px rgba(179,38,30,.26);
    transition: box-shadow .2s, transform .15s, opacity .2s;
    position: relative;
    overflow: hidden;
    letter-spacing: .3px;
    margin-top: 4px;
}

.rp-btn:hover:not(:disabled) {
    box-shadow: 0 14px 32px rgba(179,38,30,.34);
    transform: translateY(-2px);
}

.rp-btn:active:not(:disabled) {
    transform: translateY(0);
}

.rp-btn:disabled {
    opacity: .65;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.rp-btn.loading { color: transparent; }

.rp-btn.loading::after {
    content: '';
    position: absolute;
    inset: 0;
    margin: auto;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.35);
    border-top-color: #fff;
    border-radius: 50%;
    animation: rpSpin .8s linear infinite;
}

@keyframes rpSpin { to { transform: rotate(360deg); } }

/* ---- Footer links ---- */
.rp-links {
    margin-top: 20px;
    text-align: center;
    font-size: .88rem;
    color: var(--rp-muted);
    border-top: 1px solid #f0e5da;
    padding-top: 20px;
}

.rp-links a {
    color: var(--rp-red);
    font-weight: 600;
    text-decoration: none;
    margin: 0 4px;
    transition: color .2s;
}

.rp-links a:hover { color: var(--rp-orange); }

/* ---- Success / invalid state ---- */
.rp-state-box {
    text-align: center;
    padding: 8px 0 16px;
}

.rp-state-icon {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    font-size: 26px;
}

.rp-state-icon.success { background: #dcfce7; color: #15803d; }
.rp-state-icon.error   { background: #fee2e2; color: #dc2626; }

.rp-state-box h2 { margin: 0 0 10px; color: var(--rp-ink); font-size: 1.3rem; }
.rp-state-box p  { margin: 0; color: var(--rp-muted); font-size: .9rem; line-height: 1.7; }

.rp-state-box .rp-btn { max-width: 260px; margin: 24px auto 0; display: block; }

@media (max-width: 576px) {
    .rp-body { padding: 26px 22px 30px; }
    .rp-header-bar { padding: 24px 22px 20px; }
}
</style>

<div class="rp-page">
    <div class="rp-card">

        <!-- Header bar -->
        <div class="rp-header-bar">
            <div class="rp-icon-wrap">
                <i class="fas <?php echo $success ? 'fa-check' : 'fa-lock-open'; ?>"></i>
            </div>
            <h1><?php echo $success ? 'Password Updated' : 'Create New Password'; ?></h1>
            <p><?php echo $success ? 'Your account is secured.' : 'Choose a strong password for your account.'; ?></p>
        </div>

        <!-- Body -->
        <div class="rp-body">

            <?php if ($success): ?>
            <!-- Success state -->
            <div class="rp-state-box">
                <div class="rp-state-icon success">
                    <i class="fas fa-check"></i>
                </div>
                <h2>All Done!</h2>
                <p><?php echo htmlspecialchars($success); ?></p>
                <a href="login.php" class="rp-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fas fa-sign-in-alt"></i> Sign In Now
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
                <a href="reset_password_request.php" class="rp-btn" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fas fa-redo"></i> Request a New Link
                </a>
            </div>

            <?php else: ?>
            <!-- Password reset form -->
            <a href="login.php" class="rp-back">
                <i class="fas fa-arrow-left"></i> Back to login
            </a>

            <form method="POST" id="resetPasswordForm" autocomplete="off">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <!-- New password -->
                <div class="rp-form-group">
                    <label for="new_password">New Password</label>
                    <div class="rp-input-wrap">
                        <i class="fas fa-lock rp-input-icon" id="newPwIcon"></i>
                        <input type="password"
                               id="new_password"
                               name="new_password"
                               class="rp-form-control"
                               placeholder="Create a strong password"
                               required
                               autocomplete="new-password">
                        <button type="button" class="rp-toggle-pw" data-target="new_password" aria-label="Toggle visibility">
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
                <div class="rp-form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="rp-input-wrap">
                        <i class="fas fa-lock rp-input-icon" id="confirmPwIcon"></i>
                        <input type="password"
                               id="confirm_password"
                               name="confirm_password"
                               class="rp-form-control"
                               placeholder="Repeat your new password"
                               required
                               autocomplete="new-password">
                        <button type="button" class="rp-toggle-pw" data-target="confirm_password" aria-label="Toggle visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="rp-btn" name="reset_password_submit" id="resetSubmitBtn">
                    <i class="fas fa-key"></i>&nbsp; Update Password
                </button>
            </form>
            <?php endif; ?>

            <div class="rp-links">
                Remembered your password? <a href="login.php">Sign in</a>
                &nbsp;&bull;&nbsp;
                <a href="reset_password_request.php">Request new link</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Toggle password visibility ──────────────────────────────────
    document.querySelectorAll('.rp-toggle-pw').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const icon  = this.querySelector('i');
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    });

    // ── Password strength meter ─────────────────────────────────────
    const newPwInput  = document.getElementById('new_password');
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
        el.classList.toggle('met', met);
        el.querySelector('i').className = met ? 'fas fa-check-circle' : 'fas fa-circle-dot';
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
                strengthWrap.style.display = 'none';
                bars.forEach(b => { b.className = 'pw-bar'; });
                return;
            }
            strengthWrap.style.display = 'block';
            const score = evaluateStrength(pw);
            bars.forEach(function (bar, i) {
                bar.className = 'pw-bar' + (i < score ? ' ' + levelClass[score] : '');
            });
            strengthLabel.textContent = levelNames[score];
            strengthLabel.style.color = labelColors[score];
        });
    }

    // ── Form submit validation ──────────────────────────────────────
    const form = document.getElementById('resetPasswordForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const pw      = newPwInput ? newPwInput.value : '';
            const confirm = confirmInput ? confirmInput.value : '';
            const btn     = document.getElementById('resetSubmitBtn');

            if (pw.length < 8) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Too Short', text: 'Password must be at least 8 characters.', confirmButtonColor: '#b3261e' });
                return;
            }
            if (!/[A-Z]/.test(pw)) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing Uppercase', text: 'Add at least one uppercase letter (A–Z).', confirmButtonColor: '#b3261e' });
                return;
            }
            if (!/[0-9]/.test(pw)) {
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Missing Number', text: 'Add at least one number (0–9).', confirmButtonColor: '#b3261e' });
                return;
            }
            if (pw !== confirm) {
                e.preventDefault();
                if (confirmInput) confirmInput.classList.add('is-error');
                Swal.fire({ icon: 'warning', title: 'Passwords Don\'t Match', text: 'Both fields must contain the same password.', confirmButtonColor: '#b3261e' });
                return;
            }

            if (btn) {
                btn.classList.add('loading');
                btn.disabled = true;
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
