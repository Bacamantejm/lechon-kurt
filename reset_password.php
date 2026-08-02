<?php
session_start();
require_once 'includes/config.php';

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$is_valid_token = false;

if ($token === '') {
    $error = 'Invalid or expired reset link. Please request a new password reset.';
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid reset token format. Please request a new password reset.';
} else {
    $user_id = validateResetToken($conn, $token);
    if (!$user_id) {
        $error = 'Invalid or expired reset link. The link may have expired after 10 minutes. Please request a new password reset.';
    } else {
        $is_valid_token = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password_submit'])) {
    $new_password = trim((string)($_POST['new_password'] ?? ''));
    $confirm_password = trim((string)($_POST['confirm_password'] ?? ''));

    if (!$is_valid_token) {
        $error = 'Invalid or expired reset link. Please request a new password reset.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $reset_ok = resetPassword($conn, $token, $new_password);
        if ($reset_ok) {
            $success = 'Your password has been reset successfully. You can now sign in using your new password.';
            $is_valid_token = false;
        } else {
            $error = 'Unable to reset password right now. Please try again.';
        }
    }
}

$page_title = "Create New Password | Lechon Delights";
include 'includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

<style>
.reset-page-container {
    min-height: calc(100vh - 70px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background:
        radial-gradient(circle at 0% 0%, rgba(239, 107, 46, 0.12), transparent 34%),
        radial-gradient(circle at 100% 12%, rgba(179, 38, 30, 0.1), transparent 30%),
        #fff8ef;
}

.reset-wrapper {
    background-color: #fff;
    border: 1px solid #efddcc;
    border-radius: 22px;
    box-shadow: 0 20px 40px rgba(74, 32, 20, 0.14);
    max-width: 520px;
    width: 100%;
    overflow: hidden;
}

.reset-content {
    padding: 42px 34px;
    background: linear-gradient(180deg, #fffaf4 0%, #fff 100%);
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #8f2d1e;
    text-decoration: none;
    font-weight: 600;
    margin-bottom: 18px;
}

.reset-header h2 {
    margin: 0 0 8px;
    color: #2a211d;
    font-size: 1.8rem;
}

.reset-header p {
    margin: 0 0 24px;
    color: #7b6d64;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #2a211d;
    font-weight: 600;
}

.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #9a8b81;
}

.form-control {
    width: 100%;
    padding: 13px 14px 13px 42px;
    border: 1px solid #e8d4c3;
    border-radius: 10px;
    font-size: 1rem;
    background: #fffefc;
}

.form-control:focus {
    outline: none;
    border-color: #d06d44;
    box-shadow: 0 0 0 3px rgba(239, 107, 46, 0.15);
}

.btn-primary {
    width: 100%;
    border: none;
    border-radius: 10px;
    padding: 14px 16px;
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    background: linear-gradient(135deg, #b3261e, #ef6b2e);
    box-shadow: 0 12px 28px rgba(179, 38, 30, 0.26);
}

.btn-primary:hover:not(:disabled) {
    box-shadow: 0 15px 34px rgba(179, 38, 30, 0.34);
}

.btn-primary:disabled {
    opacity: 0.75;
    cursor: not-allowed;
}

.inline-links {
    margin-top: 18px;
    text-align: center;
    color: #7b6d64;
    font-size: 0.95rem;
}

.inline-links a {
    color: #8f2d1e;
    font-weight: 600;
    text-decoration: none;
}

.password-note {
    margin: -4px 0 16px;
    font-size: 0.86rem;
    color: #7f7066;
}

@media (max-width: 576px) {
    .reset-content {
        padding: 32px 22px;
    }
}
</style>

<div class="reset-page-container">
    <div class="reset-wrapper">
        <div class="reset-content">
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                <span>Back to login</span>
            </a>

            <div class="reset-header">
                <h2>Create New Password</h2>
                <p>Set a new password for your account.</p>
            </div>

            <?php if ($is_valid_token && !$success): ?>
            <form method="POST" id="resetPasswordForm">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <p class="password-note">Use at least 8 characters.</p>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn-primary" name="reset_password_submit" id="resetSubmitBtn">
                    Update Password
                </button>
            </form>
            <?php else: ?>
            <div class="inline-links">
                <a href="reset_password_request.php">Request a new reset link</a>
            </div>
            <?php endif; ?>

            <div class="inline-links">
                Remembered your password? <a href="login.php">Sign in</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('resetPasswordForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword.length < 8) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Too Short',
                    text: 'Your new password must be at least 8 characters.',
                    confirmButtonColor: '#c62828'
                });
                return;
            }

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Passwords Do Not Match',
                    text: 'Please make sure both password fields match.',
                    confirmButtonColor: '#c62828'
                });
                return;
            }
        });
    }

    <?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Unable to reset password',
        text: '<?php echo addslashes($error); ?>',
        confirmButtonColor: '#c62828'
    });
    <?php endif; ?>

    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Password Updated',
        text: '<?php echo addslashes($success); ?>',
        confirmButtonColor: '#c62828'
    }).then(() => {
        window.location.href = 'login.php';
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
