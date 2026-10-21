<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/includes/config.php';

$_SESSION['error'] = 'Subscription checkout was cancelled before completion.';
header('Location: ' . appBaseUrl('admin/subscription_plans.php'));
exit;
