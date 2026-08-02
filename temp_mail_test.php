<?php
require_once 'includes/config.php';
require_once 'email_service.php';

$mailer = new EmailService($conn, true);
$sent = $mailer->sendNotificationEmail('debug@localhost.localdomain', 'Debug Test', 'This is a test.');
echo 'sent=' . ($sent ? 'true' : 'false') . "\n";
echo 'error=' . $mailer->getLastError() . "\n";
