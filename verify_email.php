<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/email_verification_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$verification_result = verifyUserEmailToken($conn, $token);

if (!empty($verification_result['success'])) {
    $_SESSION['email_verification_success'] = (string)($verification_result['message'] ?? 'Your email address has been verified.');
} else {
    $_SESSION['email_verification_error'] = (string)($verification_result['message'] ?? 'Unable to verify your email address.');
}

$page_title = 'Email Verification | Lechon Delights';
include 'includes/header.php';
?>

<style>
.verification-page {
    min-height: calc(100vh - 180px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
}

.verification-card {
    width: 100%;
    max-width: 640px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 22px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
    padding: 36px;
    text-align: center;
}

.verification-icon {
    width: 78px;
    height: 78px;
    margin: 0 auto 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #fff;
}

.verification-icon.success { background: linear-gradient(135deg, #15803d 0%, #22c55e 100%); }
.verification-icon.error { background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%); }

.verification-card h1 {
    margin: 0 0 10px;
    font-size: 1.9rem;
    color: #0f172a;
}

.verification-card p {
    margin: 0 auto 24px;
    max-width: 520px;
    color: #475569;
    line-height: 1.65;
}

.verification-actions {
    display: flex;
    gap: 14px;
    justify-content: center;
    flex-wrap: wrap;
}

.verification-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 20px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid transparent;
}

.verification-btn.primary {
    background: #b91c1c;
    color: #fff;
}

.verification-btn.secondary {
    background: #fff;
    color: #334155;
    border-color: #cbd5e1;
}
</style>

<div class="verification-page">
    <div class="verification-card">
        <div class="verification-icon <?php echo !empty($verification_result['success']) ? 'success' : 'error'; ?>">
            <i class="fas <?php echo !empty($verification_result['success']) ? 'fa-check' : 'fa-envelope-open-text'; ?>"></i>
        </div>
        <h1><?php echo !empty($verification_result['success']) ? 'Email Verified' : 'Verification Needed'; ?></h1>
        <p><?php echo htmlspecialchars((string)($verification_result['message'] ?? 'Unable to process this verification request.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="verification-actions">
            <a class="verification-btn primary" href="login.php">Go to Login</a>
            <a class="verification-btn secondary" href="register.php">Back to Register</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
