<?php
session_start();
require_once 'includes/config.php';
require_once 'email_service.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $mailer = new EmailService($conn);
            $sent = $mailer->sendNotificationEmail(
                $to,
                'SMTP Test - Lechon Delights',
                "Hello,\n\nThis is a test email from your Lechon Delights local project.\n\nIf you received this message, your SMTP configuration is working."
            );

            if ($sent) {
                $success = 'Test email sent successfully.';
            } else {
                $detail = $mailer->getLastError();
                $error = 'SMTP test failed.';
                if ($detail !== '') {
                    $error .= ' Mailer reported: ' . $detail;
                } else {
                    $error .= ' Check the application logs or the configured SMTP settings.';
                }

                if (stripos($detail, 'could not authenticate') !== false || stripos($detail, 'authentication') !== false) {
                    $error .= ' This usually means the SMTP username or app password is invalid. Generate a fresh app password for the email account and update the SMTP credentials in the config files.';
                }
            }
        } catch (Throwable $e) {
            $error = 'SMTP test failed: ' . $e->getMessage();
        }
    }
}

$page_title = 'SMTP Test | Lechon Delights';
include 'includes/header.php';
?>

<style>
body { background: #f8fafc; }
.smtp-test-shell { max-width: 640px; margin: 40px auto; padding: 30px; background: #fff; border-radius: 16px; box-shadow: 0 10px 35px rgba(0,0,0,.08); }
.smtp-test-shell h1 { margin-top: 0; color: #8b0000; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
.form-control { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid #d1d5db; }
.btn-primary { background: #8b0000; color: #fff; border: 0; padding: 12px 18px; border-radius: 10px; cursor: pointer; }
.alert { padding: 12px 14px; border-radius: 10px; margin-bottom: 16px; }
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
.small { color: #64748b; font-size: 0.9rem; }
</style>

<div class="smtp-test-shell">
    <h1>SMTP Test</h1>
    <p class="small">Use this page to verify that your project can send mail with the current SMTP settings.</p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label for="email">Send a test email to</label>
            <input type="email" id="email" name="email" class="form-control" required placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <button type="submit" class="btn-primary">Send Test Email</button>
    </form>

    <div class="small" style="margin-top: 18px;">
        <strong>What to do next</strong>
        <ul>
            <li>If you are using Gmail, make sure you are using a fresh Google app password, not your normal account password.</li>
            <li>Ensure 2-step verification is enabled for the Gmail account that owns the address.</li>
            <li>If the account still rejects the login, switch to a different SMTP provider such as Outlook, Zoho, or a dedicated transactional mail service.</li>
        </ul>
    </div>
</div>
