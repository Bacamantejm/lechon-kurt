<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';

checkAdminAccess();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
if ($seller_scope_id === null) {
    denyAdminAccess('Access denied: Only approved partner shops can complete invoice payments.');
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$service = new PlatformMonetizationService($conn);
$service->ensureReady($current_user_id);
$result = $service->completeInvoicePayment($invoice_id, (int)$seller_scope_id, null, $current_user_id);

if (!empty($result['success'])) {
    $_SESSION['success'] = 'Subscription payment confirmed! Your plan is now active. Welcome to your dashboard.';
    header('Location: admin/index.php');
    exit;
}

$_SESSION['error'] = (string)($result['message'] ?? 'We could not confirm the invoice payment yet.');
header('Location: admin/subscription_plans.php');
exit;
