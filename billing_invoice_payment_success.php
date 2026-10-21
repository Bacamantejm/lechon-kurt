<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
if ($invoice_id <= 0) {
    if (!empty($_SESSION['user_id'])) {
        header('Location: ' . appBaseUrl('admin/subscription_plans.php'));
    } else {
        header('Location: ' . appBaseUrl('login.php'));
    }
    exit;
}

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$service = new PlatformMonetizationService($conn);
$service->ensureReady($current_user_id);

$seller_scope_id = 0;
if ($current_user_id > 0) {
    $is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
    $seller_scope_id = $is_partner_scoped_admin ? (int)getFranchiseSellerScopeOwnerId($conn, $current_user_id) : 0;

    $user_role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    if ($user_role_name === '' && function_exists('getUserRole')) {
        $current_role = getUserRole($conn, $current_user_id);
        if ($current_role && !empty($current_role['name'])) {
            $user_role_name = strtolower(trim((string)$current_role['name']));
            $_SESSION['role_name'] = $user_role_name;
        }
    }

    $is_store_owner = false;
    $storeCheck = mysqli_query($conn, "SELECT store_id FROM store_locations WHERE owner_user_id = {$current_user_id} LIMIT 1");
    if ($storeCheck && mysqli_num_rows($storeCheck) > 0) {
        $is_store_owner = true;
    }

    $is_shop_owner_account = $is_store_owner || in_array($user_role_name, ['business_owner', 'partner_owner', 'store_owner', 'shop_owner'], true);
    if ($is_shop_owner_account && ($seller_scope_id <= 0 || !$is_partner_scoped_admin)) {
        $seller_scope_id = $current_user_id;
    }
}

// Complete the invoice payment with PayMongo verification
$result = $service->completeInvoicePayment($invoice_id, $seller_scope_id, null, $current_user_id);

if (!empty($result['success'])) {
    if ($current_user_id > 0) {
        $_SESSION['success'] = 'Subscription payment confirmed! Your plan is now active. Welcome to your dashboard.';
        header('Location: ' . appBaseUrl('admin/index.php'));
    } else {
        $_SESSION['success_message'] = 'Subscription payment confirmed! Your plan is now active. Please sign in to access your partner dashboard.';
        header('Location: ' . appBaseUrl('login.php?payment_success=1'));
    }
    exit;
}

if ($current_user_id > 0) {
    $_SESSION['error'] = (string)($result['message'] ?? 'We could not confirm the invoice payment yet.');
    header('Location: ' . appBaseUrl('admin/subscription_plans.php'));
} else {
    $_SESSION['error_message'] = (string)($result['message'] ?? 'Payment verification is pending or incomplete.');
    header('Location: ' . appBaseUrl('login.php'));
}
exit;
