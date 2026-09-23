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

$user_role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
if ($user_role_name === '' && $current_user_id > 0 && function_exists('getUserRole')) {
    $current_role = getUserRole($conn, $current_user_id);
    if ($current_role && !empty($current_role['name'])) {
        $user_role_name = strtolower(trim((string)$current_role['name']));
        $_SESSION['role_name'] = $user_role_name;
    }
}

$is_store_owner = false;
if ($current_user_id > 0) {
    $storeCheck = mysqli_query($conn, "SELECT store_id FROM store_locations WHERE owner_user_id = {$current_user_id} LIMIT 1");
    if ($storeCheck && mysqli_num_rows($storeCheck) > 0) {
        $is_store_owner = true;
    }
}

$is_shop_owner_account = $is_store_owner || in_array($user_role_name, ['business_owner', 'partner_owner', 'store_owner', 'shop_owner'], true);
if ($is_shop_owner_account && ($seller_scope_id === null || !$is_partner_scoped_admin)) {
    $seller_scope_id = $current_user_id;
    $is_partner_scoped_admin = true;
}

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
