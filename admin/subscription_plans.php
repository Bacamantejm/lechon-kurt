<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/PlatformMonetizationService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_user_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
if ($is_super_admin_user && !$is_partner_scoped_admin) {
    header('Location: ../super_admin/platform_monetization.php');
    exit;
}

$is_partner_owner_admin = $is_partner_scoped_admin && (int)$seller_scope_id === $current_user_id;
$has_partner_billing_access = $is_partner_owner_admin
    || (function_exists('hasPermission') && (hasPermission($conn, $current_user_id, 'billing.view') || hasPermission($conn, $current_user_id, 'billing.manage')))
    || (function_exists('hasModuleAccess') && hasModuleAccess($conn, $current_user_id, 'billing'));
if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: This plans page is only available to approved business partner shops.');
}
if (!$has_partner_billing_access) {
    denyAdminAccess('Access denied: Your assigned role does not include subscription planning access.');
}

$monetizationService = new PlatformMonetizationService($conn);
$monetizationService->ensureReady($current_user_id);

// Handle Direct Instant PayMongo Subscription Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'instant_subscription_checkout') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: subscription_plans.php');
        exit;
    }

    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $billing_cycle = strtolower(trim((string)($_POST['billing_cycle'] ?? 'monthly')));
    $payment_method = strtolower(trim((string)($_POST['payment_method'] ?? 'gcash')));
    $agree_terms = !empty($_POST['agree_terms']);

    if (!$agree_terms) {
        $_SESSION['error'] = 'You must agree to the Subscription Terms & Cancellation Policy to proceed.';
        header('Location: subscription_plans.php');
        exit;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lechonsystem';

    $result = $monetizationService->createDirectSubscriptionPaymentSession(
        (int)$seller_scope_id,
        $plan_id,
        $billing_cycle,
        $base_url,
        $payment_method,
        $current_user_id
    );

    if (!empty($result['success']) && !empty($result['checkout_url'])) {
        header('Location: ' . $result['checkout_url']);
        exit;
    }

    $_SESSION['error'] = (string)($result['message'] ?? 'Unable to create PayMongo checkout session.');
    header('Location: subscription_plans.php');
    exit;
}

// Handle Subscription Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_active_subscription') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: subscription_plans.php');
        exit;
    }

    $cancelResult = $monetizationService->cancelActiveSubscription((int)$seller_scope_id, $current_user_id);
    if (!empty($cancelResult['success'])) {
        $_SESSION['success'] = (string)$cancelResult['message'];
    } else {
        $_SESSION['error'] = (string)($cancelResult['message'] ?? 'Unable to cancel subscription right now.');
    }
    header('Location: subscription_plans.php');
    exit;
}

$plans = array_values(array_filter($monetizationService->getPlans(), static function ($plan) {
    return (int)($plan['is_active'] ?? 0) === 1;
}));
$partnerPortal = $monetizationService->getPartnerBillingPortalData((int)$seller_scope_id);
$subscription = $partnerPortal['subscription'] ?? null;
$businessName = (string)(($partnerPortal['partner']['business_name'] ?? '') ?: ($admin_info['business_name'] ?? 'Partner Shop'));
$featured_plan_code = 'growth';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Plans - Lechon Delights</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .plans-shell{padding:24px}.plans-card{background:#fff;border:1px solid #e5e7eb;border-radius:22px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:20px;margin-bottom:18px}.plans-hero{background:radial-gradient(circle at top right,rgba(198,40,40,.1),transparent 34%),linear-gradient(135deg,#ffffff 0%,#fff7f7 100%)}
        .plans-kicker{display:inline-flex;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#b91c1c;font-weight:800;margin-bottom:10px}.plans-title{margin:0;font-size:2rem;font-weight:800;color:#0f172a}.plans-sub{margin:10px 0 0;color:#64748b;max-width:780px}
        .plans-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}.plan-card{position:relative;background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(15,23,42,.06);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}.plan-card:hover{transform:translateY(-4px);box-shadow:0 18px 36px rgba(15,23,42,.1)}.plan-card.featured{border-color:rgba(185,28,28,.35);background:linear-gradient(180deg,#fffdfd 0%,#fff7f7 100%)}.plan-card.is-recommended{border-color:#b91c1c;box-shadow:0 20px 42px rgba(185,28,28,.16)}
        .plan-badge,.plan-live-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:.73rem;font-weight:800;margin-bottom:12px}.plan-badge{background:#fee2e2;color:#991b1b}.plan-live-badge{position:absolute;top:14px;right:14px;background:#b91c1c;color:#fff}.plan-card h3{margin:0;font-size:1.2rem;font-weight:800;color:#0f172a}.plan-card p{margin:8px 0 0;color:#64748b;font-size:.92rem;min-height:48px}
        .plan-price{margin-top:16px;font-size:2rem;font-weight:800;color:#0f172a}.plan-price-label{color:#64748b;font-size:.86rem}.plan-note{margin-top:12px;padding:12px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:.84rem}.plan-note strong,.plan-note span{display:block}.plan-features{list-style:none;padding:0;margin:16px 0 0;display:grid;gap:10px}.plan-features li{display:flex;gap:10px;align-items:flex-start;color:#334155;font-size:.9rem}.plan-features li i{width:18px;color:#b91c1c;margin-top:3px}
        .plans-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;padding:10px 24px;font-weight:700;font-size:.9rem;text-decoration:none;transition:all .2s ease;width:100%}.plans-btn-primary{background:#b3261e;color:#fff !important;border:1px solid #b3261e}.plans-btn-primary:hover{background:#931e18;border-color:#931e18;box-shadow:0 4px 12px rgba(179,38,30,.25)}.plans-btn-secondary{background:#fff;color:#b3261e !important;border:1.5px solid #efddcd}.plans-btn-secondary:hover{background:#fff8ef;border-color:#b3261e;color:#b3261e !important}
        .mode-toggle,.sticky-toggle{display:inline-flex;gap:8px;padding:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px}.mode-btn,.sticky-btn{border:none;background:transparent;color:#475569;padding:10px 16px;border-radius:999px;font-size:.92rem;font-weight:700}.mode-btn.active,.sticky-btn.active{background:#b91c1c;color:#fff}
        .hero-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:18px}.hero-meta-box{border:1px solid #e2e8f0;border-radius:16px;padding:14px;background:rgba(255,255,255,.88)}.hero-meta-box strong{display:block;font-size:1.05rem;color:#0f172a}.hero-meta-box span{display:block;margin-top:4px;color:#64748b;font-size:.84rem}
        .sticky-card{position:sticky;top:82px;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:16px;background:rgba(255,255,255,.94);backdrop-filter:blur(10px)}.sticky-copy strong{display:block;color:#0f172a}.sticky-copy span{color:#64748b;font-size:.88rem}.sticky-savings{display:inline-flex;align-items:center;gap:6px;padding:9px 12px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:700;font-size:.84rem}
        .calc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.9fr);gap:16px;margin-top:18px}.calc-pane,.calc-result{border:1px solid #e2e8f0;border-radius:18px;padding:18px;background:#f8fafc}.calc-checks{display:grid;gap:10px;margin-top:16px}.calc-check{display:flex;gap:10px;align-items:center;color:#334155;font-size:.92rem}.calc-result h3{margin:8px 0;font-size:1.5rem;font-weight:800;color:#0f172a}.calc-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.calc-meta div{border:1px solid #e2e8f0;border-radius:14px;padding:10px;background:#fff}.calc-meta span{display:block;color:#64748b;font-size:.74rem}.calc-meta strong{display:block;margin-top:4px;color:#0f172a;font-size:.95rem}
        .why-wrap{margin-top:14px;border-top:1px solid #e2e8f0;padding-top:14px}.why-toggle{width:100%;display:flex;justify-content:space-between;align-items:center;border:none;background:transparent;padding:0;font-weight:800;color:#0f172a}.why-body{margin-top:12px;color:#475569;font-size:.9rem}.why-body ul{margin:10px 0 0 18px;padding:0}
        .matrix-wrap{overflow-x:auto;margin-top:16px}.matrix-table{width:100%;border-collapse:collapse;min-width:760px}.matrix-table th,.matrix-table td{padding:12px 10px;border-bottom:1px solid #e2e8f0;font-size:.9rem}.matrix-table th{background:#f8fafc;color:#334155;font-weight:800}.workflow-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:18px}.workflow-step{border:1px solid #e2e8f0;border-radius:18px;padding:14px;background:linear-gradient(135deg,#fff 0%,#f8fafc 100%)}.workflow-step strong{display:block;margin-bottom:8px;color:#0f172a}
        @media (max-width:992px){.calc-grid{grid-template-columns:1fr}.sticky-card{position:static;flex-direction:column;align-items:stretch}}
    </style>
</head>
<body>
<div class="admin-container">
<?php include 'sidebar.php'; ?>
<div class="admin-content">
<div class="admin-topbar"><div class="topbar-content"><button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button><h1>Subscription Plans</h1><div class="topbar-right"><div class="admin-profile"><span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span><i class="fas fa-user-circle"></i></div></div></div></div>
<div class="plans-shell">

<?php if ($subscription && !empty($subscription['plan_name'])): ?>
<?php
    $activeStatus = strtolower(trim((string)($subscription['subscription_status'] ?? 'active')));
    $activePlanName = (string)($subscription['plan_name'] ?? 'Partner Plan');
    $activeCycle = ucfirst((string)($subscription['billing_cycle'] ?? 'monthly'));
    $startedAtFormatted = !empty($subscription['started_at']) ? date('M d, Y', strtotime((string)$subscription['started_at'])) : 'Recently';
    $renewsAtRaw = (string)($subscription['renews_at'] ?? '');
    $renewsAtFormatted = !empty($renewsAtRaw) ? date('M d, Y', strtotime($renewsAtRaw)) : 'N/A';
    $daysRemaining = !empty($renewsAtRaw) ? (int)ceil((strtotime($renewsAtRaw) - time()) / 86400) : 0;
    $statusClass = ($activeStatus === 'active' || $activeStatus === 'trial') ? 'status-active' : ($activeStatus === 'past_due' ? 'status-overdue' : 'status-pending');
?>
<div class="active-subscription-hero-card">
    <div class="hero-card-left">
        <div class="hero-crown-mark">
            <i class="fas fa-crown"></i>
        </div>
        <div>
            <div class="hero-plan-title-row">
                <h2 class="hero-plan-title"><?php echo htmlspecialchars($activePlanName); ?> Plan</h2>
                <span class="hero-status-pill <?php echo $statusClass; ?>">
                    <i class="fas fa-check-circle"></i> <?php echo strtoupper($activeStatus); ?>
                </span>
                <span class="hero-cycle-pill"><?php echo htmlspecialchars($activeCycle); ?> Billing</span>
            </div>
            <div class="hero-plan-dates-row">
                <span><i class="fas fa-calendar-check"></i> Subscribed: <strong><?php echo htmlspecialchars($startedAtFormatted); ?></strong></span>
                <span class="dates-sep">&bull;</span>
                <span><i class="fas fa-credit-card"></i> Next Renewal / Due Date: <strong class="text-accent-danger"><?php echo htmlspecialchars($renewsAtFormatted); ?></strong></span>
            </div>
        </div>
    </div>
    <div class="hero-card-right">
        <?php if ($daysRemaining >= 0): ?>
            <div class="hero-countdown-box">
                <i class="fas fa-hourglass-half"></i>
                <span><strong><?php echo $daysRemaining; ?></strong> days left in billing cycle</span>
            </div>
        <?php endif; ?>
        <a href="partner_billing.php" class="hero-billing-btn">
            <i class="fas fa-file-invoice-dollar"></i> Manage Billing &amp; Invoices
        </a>
        <form method="POST" action="subscription_plans.php" style="display:inline-block;" onsubmit="return confirmSubscriptionCancellation('<?php echo htmlspecialchars($activePlanName, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($renewsAtFormatted, ENT_QUOTES, 'UTF-8'); ?>');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="cancel_active_subscription">
            <button type="submit" class="hero-billing-btn" style="background:#fff1f0; border-color:#fee4e2; color:#b3261e; cursor:pointer;">
                <i class="fas fa-ban"></i> Cancel Subscription
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<section class="sp-minimal-grid">
<?php 
$activeSubPlanId = $subscription && in_array(strtolower((string)($subscription['subscription_status'] ?? '')), ['active', 'trial'], true)
    ? (int)($subscription['plan_id'] ?? 0)
    : 0;
?>
<?php foreach ($plans as $plan): ?>
<?php
$planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
$isPro = $planCode === 'pro';
$isFeatured = $planCode === $featured_plan_code;
$isCurrentActivePlan = ($activeSubPlanId > 0 && (int)$plan['id'] === $activeSubPlanId);
$monthlyPrice = (float)($plan['monthly_price'] ?? 0);
$annualPrice = (float)($plan['annual_price'] ?? 0);
$annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
$annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
$staffAccounts = (int)($plan['max_staff_accounts'] ?? 1);
$feePercent = (float)($plan['included_order_fee_percent'] ?? 0);
$feeFlat = (float)($plan['included_order_fee_flat'] ?? 0);
$hasAi = (int)($plan['includes_ai_automation'] ?? 0) === 1;
$hasPriority = (int)($plan['includes_priority_support'] ?? 0) === 1;
$hasFeatured = (int)($plan['includes_featured_placement'] ?? 0) === 1;
$hasBranding = (int)($plan['includes_custom_branding'] ?? 0) === 1;
?>
<article class="sp-minimal-card <?php echo $isCurrentActivePlan ? 'is-current-active-plan' : ($isPro ? 'sp-pro-card' : ($isFeatured ? 'sp-featured-card' : '')); ?>">
    <div class="sp-badge-wrap">
        <?php if ($isCurrentActivePlan): ?>
            <span class="sp-top-pill sp-current-plan-badge"><i class="fas fa-check-circle"></i> YOUR CURRENT ACTIVE PLAN</span>
        <?php elseif ($isPro): ?>
            <span class="sp-top-pill sp-pro-badge"><i class="fas fa-fire"></i> MOST POPULAR &amp; BEST VALUE</span>
        <?php else: ?>
            <span class="sp-top-pill <?php echo $isFeatured ? 'sp-pill-featured' : ''; ?>">
                <?php echo $annualSavings > 0 ? 'Save PHP ' . number_format($annualSavings, 0) . ' yearly' : 'PHP ' . number_format($annualPrice, 0) . ' / year'; ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="sp-card-header">
        <div class="sp-brand-sub"><i class="fas fa-layer-group"></i> Partner Tier <?php echo $isPro ? '&bull; Recommended' : ''; ?></div>
        <h2 class="sp-plan-title"><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></h2>
        
        <div class="sp-price-main">PHP <?php echo number_format($monthlyPrice, 0); ?></div>
        <div class="sp-price-sub">per month</div>
        <div class="sp-price-annual">Annual: PHP <?php echo number_format($annualPrice, 0); ?></div>
    </div>

    <div class="sp-divider"></div>

    <ul class="sp-bullet-list">
        <?php if ($planCode === 'starter'): ?>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Marketplace Storefront</strong> &amp; Online Orders</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Real-time POS</strong> &amp; Delivery Tracking</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Product Catalog</strong> &amp; Stock Alerts</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Customer Live Chat</strong> Messaging</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Up to <?php echo $staffAccounts; ?> Staff</strong> / Cashier Accounts</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> Fee Rate: <strong><?php echo number_format($feePercent, 2); ?>% + ₱<?php echo number_format($feeFlat, 2); ?></strong> / order</li>
            <li style="color:#94a3b8;"><span class="bullet-dot" style="color:#cbd5e1;"><i class="fas fa-times"></i></span> MRP Batch Yield Calculator</li>
            <li style="color:#94a3b8;"><span class="bullet-dot" style="color:#cbd5e1;"><i class="fas fa-times"></i></span> AI Demand Forecasting</li>
        <?php elseif ($planCode === 'growth'): ?>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Everything in Starter</strong>, plus:</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Up to <?php echo $staffAccounts; ?> Staff</strong> &amp; Kitchen Accounts</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>MRP Batch Roasting</strong> &amp; Yield Calculator</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>AI Demand Forecasting</strong> &amp; Trends</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Automated Chatbot</strong> FAQ Replies</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>DSS Financial</strong> &amp; Sales Reports</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> Lower Fee: <strong><?php echo number_format($feePercent, 2); ?>% + ₱<?php echo number_format($feeFlat, 2); ?></strong> / order</li>
            <li style="color:#94a3b8;"><span class="bullet-dot" style="color:#cbd5e1;"><i class="fas fa-times"></i></span> HR &amp; Automated Payroll</li>
        <?php else: ?>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Everything in Growth</strong>, plus:</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Up to <?php echo $staffAccounts; ?> Staff</strong> &amp; Admin Accounts</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Full HR &amp; Automated Payroll</strong> Module</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Featured Homepage</strong> Marketplace Placement</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Custom Receipt Logo</strong> &amp; Branding</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> <strong>Priority 24/7 Hotline</strong> Support</li>
            <li><span class="bullet-dot" style="color:#027a48;"><i class="fas fa-check"></i></span> Lowest Fee: <strong><?php echo number_format($feePercent, 2); ?>% + ₱<?php echo number_format($feeFlat, 2); ?></strong> / order</li>
        <?php endif; ?>
    </ul>

    <div class="sp-card-action">
        <?php if ($isCurrentActivePlan): ?>
            <div style="display:flex; flex-direction:column; gap:8px; width:100%;">
                <button type="button" class="sp-pill-button sp-btn-current-active" disabled>
                    <i class="fas fa-check-circle"></i> Current Active Plan
                </button>
                <form method="POST" action="subscription_plans.php" style="width:100%; text-align:center;" onsubmit="return confirmSubscriptionCancellation('<?php echo htmlspecialchars((string)$plan['plan_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($renewsAtFormatted, ENT_QUOTES, 'UTF-8'); ?>');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="cancel_active_subscription">
                    <button type="submit" style="background:none; border:none; color:#b3261e; font-size:0.82rem; font-weight:700; text-decoration:underline; cursor:pointer; padding:4px;">
                        <i class="fas fa-times-circle"></i> Cancel Subscription
                    </button>
                </form>
            </div>
        <?php else: ?>
            <button type="button" 
                    class="sp-pill-button <?php echo $isPro ? 'sp-pro-button' : ($isFeatured ? 'btn-highlight' : ''); ?>"
                    onclick="openSubscriptionCheckoutModal(<?php echo (int)($plan['id'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode((string)($plan['plan_name'] ?? 'Plan')), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $monthlyPrice; ?>, <?php echo $annualPrice; ?>, '<?php echo $planCode; ?>')">
                <?php echo $activeSubPlanId > 0 ? 'Switch to this Plan' : 'Get This Plan'; ?>
            </button>
        <?php endif; ?>
        <p class="sp-fineprint">
            PHP <?php echo number_format($monthlyPrice, 0); ?> per month. Subscription payments are non-refundable. Upon cancellation, you retain full plan access until your paid 1-month cycle ends.
        </p>
    </div>
</article>
<?php endforeach; ?>
</section>

<!-- Subscription & Cancellation Agreement Box -->
<div style="margin-top: 40px; background: #ffffff; border: 1px solid #eaecf0; border-radius: 16px; padding: 24px 28px; box-shadow: 0 4px 16px rgba(16, 24, 40, 0.04);">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
        <i class="fas fa-shield-alt" style="font-size: 1.3rem; color: #b3261e;"></i>
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #101828; margin: 0;">Subscription Terms &amp; Cancellation Policy</h3>
    </div>
    <p style="color: #475467; font-size: 0.9rem; margin-bottom: 16px;">Please review the platform subscription terms before submitting plan change or upgrade requests:</p>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
        <div style="padding: 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-size: 0.86rem;">
            <strong style="display:block; color:#101828; margin-bottom: 4px;"><i class="fas fa-ban text-danger"></i> Non-Refundable Fees</strong>
            <span style="color:#475467;">All subscription payments are final and non-refundable once billed or settled. No cash or prorated refunds are issued upon cancellation.</span>
        </div>
        <div style="padding: 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-size: 0.86rem;">
            <strong style="display:block; color:#101828; margin-bottom: 4px;"><i class="fas fa-clock text-primary"></i> 1-Month Continued Access</strong>
            <span style="color:#475467;">If you cancel your subscription, your shop retains 100% active access to all plan features and discount rates until the end of your current 1-month paid billing cycle.</span>
        </div>
        <div style="padding: 14px; background: #f8f9fa; border: 1px solid #eaecf0; border-radius: 10px; font-size: 0.86rem;">
            <strong style="display:block; color:#101828; margin-bottom: 4px;"><i class="fas fa-check-circle text-success"></i> Auto-Termination</strong>
            <span style="color:#475467;">After your 1-month paid cycle expires, recurring billing ends and the subscription terminates automatically without further charges.</span>
        </div>
    </div>
</div>

</div>
</div>
</div>
<style>
.plans-shell {
    padding: 40px 20px 80px 20px;
    background: #fff9f2;
    min-height: 100vh;
}

/* Active Subscription Hero Card */
.active-subscription-hero-card {
    max-width: 1140px;
    margin: 0 auto 32px auto;
    background: #ffffff;
    border: 1.5px solid #abefc6;
    border-radius: 18px;
    padding: 24px 28px;
    box-shadow: 0 4px 20px rgba(2, 122, 72, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    background: linear-gradient(135deg, #ffffff 0%, #f6fef9 100%);
}
.hero-card-left {
    display: flex;
    align-items: center;
    gap: 20px;
}
.hero-crown-mark {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: #ecfdf3;
    color: #027a48;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    border: 1px solid #abefc6;
    flex-shrink: 0;
}
.hero-plan-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}
.hero-plan-title {
    margin: 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #101828;
}
.hero-status-pill {
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}
.hero-status-pill.status-active {
    background: #ecfdf3;
    color: #027a48;
    border: 1px solid #abefc6;
}
.hero-status-pill.status-overdue {
    background: #fff1f0;
    color: #b3261e;
    border: 1px solid #fee4e2;
}
.hero-status-pill.status-pending {
    background: #fffaeb;
    color: #b54708;
    border: 1px solid #fedf89;
}
.hero-cycle-pill {
    background: #f2f4f7;
    color: #344054;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid #eaecf0;
}
.hero-plan-dates-row {
    font-size: 0.88rem;
    color: #475467;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.hero-plan-dates-row strong {
    color: #101828;
}
.text-accent-danger {
    color: #b3261e !important;
    font-weight: 800 !important;
}
.dates-sep {
    color: #d0d5dd;
}
.hero-card-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.hero-countdown-box {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: #ecfdf3;
    border: 1px solid #abefc6;
    border-radius: 10px;
    font-size: 0.84rem;
    color: #027a48;
}
.hero-billing-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: #ffffff;
    border: 1.5px solid #d0d5dd;
    border-radius: 10px;
    color: #344054;
    font-weight: 700;
    font-size: 0.86rem;
    text-decoration: none;
    transition: all 0.2s ease;
}
.hero-billing-btn:hover {
    background: #f8f9fa;
    border-color: #101828;
    color: #101828;
    text-decoration: none;
}

/* Current Active Plan Card Highlight */
.is-current-active-plan {
    border: 2.5px solid #027a48 !important;
    background: linear-gradient(180deg, #ffffff 0%, #f6fef9 100%) !important;
    box-shadow: 0 10px 30px rgba(2, 122, 72, 0.14) !important;
}
.sp-current-plan-badge {
    background: #027a48 !important;
    color: #ffffff !important;
    font-size: 0.78rem !important;
    font-weight: 800 !important;
    padding: 6px 12px !important;
    border-radius: 6px !important;
    box-shadow: 0 2px 8px rgba(2, 122, 72, 0.25);
}
.sp-btn-current-active {
    background: #ecfdf3 !important;
    color: #027a48 !important;
    border: 1.5px solid #abefc6 !important;
    cursor: default !important;
    box-shadow: none !important;
    transform: none !important;
}

.sp-minimal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    max-width: 1140px;
    margin: 0 auto;
}

.sp-minimal-card {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 18px;
    padding: 28px 24px;
    color: #2a211d;
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: 0 4px 16px rgba(42, 33, 29, 0.05);
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.sp-minimal-card:hover {
    transform: translateY(-5px);
    border-color: #ef6b2e;
    box-shadow: 0 12px 30px rgba(179, 38, 30, 0.12);
}

.sp-featured-card {
    border: 2px solid #b3261e;
    background: #fffdfa;
    box-shadow: 0 6px 20px rgba(179, 38, 30, 0.1);
}

.sp-badge-wrap {
    margin-bottom: 16px;
}

.sp-top-pill {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 800;
    background: #fff8ef;
    color: #ef6b2e;
    border: 1px solid #efddcd;
}

.sp-pill-featured {
    background: #b3261e;
    color: #ffffff;
    border: 1px solid #b3261e;
}

.sp-brand-sub {
    font-size: 0.85rem;
    font-weight: 700;
    color: #7b6d64;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.sp-plan-title {
    font-size: 2.2rem;
    font-weight: 800;
    color: #2a211d;
    margin: 0 0 16px 0;
    letter-spacing: -0.5px;
}

.sp-price-main {
    font-size: 1.8rem;
    font-weight: 800;
    color: #2a211d;
    line-height: 1.1;
}

.sp-price-sub {
    font-size: 0.85rem;
    color: #7b6d64;
    margin-top: 2px;
}

.sp-price-annual {
    font-size: 0.85rem;
    color: #2a211d;
    font-weight: 700;
    margin-top: 4px;
}

.sp-divider {
    height: 1px;
    background: #efddcd;
    margin: 20px 0;
}

.sp-bullet-list {
    list-style: none;
    padding: 0;
    margin: 0 0 28px 0;
    color: #2a211d;
    font-size: 0.9rem;
    line-height: 1.95;
    flex-grow: 1;
}

.sp-bullet-list li {
    padding: 3px 0;
}

.bullet-dot {
    color: #b3261e;
    font-weight: 800;
    margin-right: 4px;
}

.sp-card-action {
    text-align: center;
}

.sp-pill-button {
    display: block;
    width: 100%;
    padding: 13px 20px;
    border-radius: 50px;
    background-color: #b3261e;
    color: #ffffff !important;
    border: 2px solid #b3261e;
    font-weight: 800;
    font-size: 0.95rem;
    text-decoration: none;
    text-align: center;
    transition: all 0.25s ease;
    box-sizing: border-box;
}

.sp-pill-button:hover,
.sp-pill-button.btn-highlight:hover {
    background-color: #ef6b2e;
    border-color: #ef6b2e;
    color: #ffffff !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(239, 107, 46, 0.35);
    text-decoration: none;
}

.sp-pro-card {
    border: 2.5px solid #b3261e !important;
    background: linear-gradient(180deg, #ffffff 0%, #fff4eb 100%) !important;
    box-shadow: 0 12px 36px rgba(179, 38, 30, 0.2) !important;
    transform: translateY(-6px);
    position: relative;
    z-index: 2;
}

.sp-pro-card:hover {
    transform: translateY(-8px) scale(1.01);
    box-shadow: 0 16px 40px rgba(179, 38, 30, 0.28) !important;
    border-color: #ef6b2e !important;
}

.sp-pro-badge {
    background: #b3261e !important;
    color: #ffffff !important;
    font-size: 0.82rem !important;
    font-weight: 800 !important;
    padding: 6px 14px !important;
    border-radius: 6px !important;
    box-shadow: 0 4px 12px rgba(179, 38, 30, 0.3);
}

.sp-pro-button {
    background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%) !important;
    border: none !important;
    color: #ffffff !important;
    box-shadow: 0 6px 18px rgba(179, 38, 30, 0.35);
}

.sp-pro-button:hover {
    background: linear-gradient(135deg, #931e18 0%, #d85317 100%) !important;
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 10px 25px rgba(239, 107, 46, 0.45) !important;
}

.sp-fineprint {
    font-size: 0.74rem;
    color: #7b6d64;
    margin-top: 14px;
    margin-bottom: 0;
    line-height: 1.4;
    text-align: center;
}

@media (max-width: 768px) {
    .plans-shell {
        padding: 16px 12px 90px 12px;
    }
    .sp-minimal-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .sp-minimal-card {
        padding: 22px 18px;
    }
    .sp-pro-card {
        transform: none;
    }
}
</style>
<!-- Instant Subscription Checkout Modal -->
<div id="subscriptionCheckoutModal" class="sub-modal-backdrop" style="display: none;">
    <div class="sub-modal-card">
        <div class="sub-modal-header">
            <div class="sub-modal-title-wrap">
                <span class="sub-modal-kicker"><i class="fas fa-bolt"></i> Instant Subscription Checkout</span>
                <h2 id="modalPlanTitle" class="sub-modal-title">Upgrade Plan</h2>
            </div>
            <button type="button" class="sub-modal-close" onclick="closeSubscriptionCheckoutModal()">&times;</button>
        </div>

        <form method="POST" action="subscription_plans.php" id="subscriptionCheckoutForm" onsubmit="return submitSubscriptionCheckout(event);">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="instant_subscription_checkout">
            <input type="hidden" name="plan_id" id="modalPlanId" value="0">

            <div class="sub-modal-body">
                <!-- Billing Cycle Selector -->
                <div class="sub-term-group">
                    <label class="sub-field-label">Select Billing Term</label>
                    <div class="sub-cycle-toggle-grid">
                        <label class="sub-cycle-option" id="cycleOptionMonthly">
                            <input type="radio" name="billing_cycle" value="monthly" checked onchange="updateModalCycle('monthly')">
                            <div class="sub-cycle-content">
                                <div class="sub-cycle-name">Monthly Billing</div>
                                <div class="sub-cycle-price" id="modalMonthlyPriceLabel">PHP 0 / month</div>
                                <span class="sub-cycle-desc">Billed every month. Cancel anytime.</span>
                            </div>
                        </label>
                        <label class="sub-cycle-option" id="cycleOptionAnnual">
                            <input type="radio" name="billing_cycle" value="annual" onchange="updateModalCycle('annual')">
                            <div class="sub-cycle-content">
                                <div class="sub-cycle-name">Annual Billing <span class="sub-badge-save">SAVE 2 MONTHS</span></div>
                                <div class="sub-cycle-price" id="modalAnnualPriceLabel">PHP 0 / year</div>
                                <span class="sub-cycle-desc" id="modalAnnualEquivalentLabel">Equivalent to PHP 0 / mo</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Payment Method (PayMongo) -->
                <div class="sub-pay-group">
                    <label class="sub-field-label">Payment Method (via PayMongo)</label>
                    <div class="sub-pay-grid">
                        <label class="sub-pay-option">
                            <input type="radio" name="payment_method" value="gcash" checked>
                            <div class="sub-pay-card">
                                <i class="fas fa-wallet" style="color: #007dfe;"></i>
                                <span>GCash</span>
                            </div>
                        </label>
                        <label class="sub-pay-option">
                            <input type="radio" name="payment_method" value="paymaya">
                            <div class="sub-pay-card">
                                <i class="fas fa-mobile-alt" style="color: #00d632;"></i>
                                <span>Maya</span>
                            </div>
                        </label>
                        <label class="sub-pay-option">
                            <input type="radio" name="payment_method" value="card">
                            <div class="sub-pay-card">
                                <i class="fas fa-credit-card" style="color: #1a1f71;"></i>
                                <span>Card (Visa/MC)</span>
                            </div>
                        </label>
                        <label class="sub-pay-option">
                            <input type="radio" name="payment_method" value="grab_pay">
                            <div class="sub-pay-card">
                                <i class="fas fa-car" style="color: #00b14f;"></i>
                                <span>GrabPay</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Terms and Cancellation Agreement Box -->
                <div class="sub-terms-card">
                    <div class="sub-terms-top">
                        <i class="fas fa-file-contract sub-terms-top-icon"></i>
                        <strong>Terms &amp; Cancellation Agreement</strong>
                    </div>
                    <ul class="sub-terms-list">
                        <li><strong>Non-Refundable Policy:</strong> All subscription fee payments are strictly non-refundable once billed or processed. No cash refunds or prorated amounts are provided upon cancellation.</li>
                        <li><strong>1-Month Continued Access:</strong> If you cancel your subscription at any time, your shop continues to have <strong>100% full active access</strong> to all plan features, tools, and discounts until the end of your paid 1-month billing cycle.</li>
                        <li><strong>Auto-Termination:</strong> After your 1-month paid period concludes, recurring billing ends and the subscription terminates automatically.</li>
                    </ul>
                    <label class="sub-agree-checkbox-wrap">
                        <input type="checkbox" name="agree_terms" id="agreeTermsCheckbox" required>
                        <span>I have read, understand, and agree to the <strong>Subscription Terms &amp; Cancellation Policy</strong>.</span>
                    </label>
                </div>

                <!-- Total Summary Bar -->
                <div class="sub-total-bar">
                    <div class="sub-total-copy">
                        <span>Total Due Today:</span>
                        <strong id="modalTotalAmount">PHP 0.00</strong>
                    </div>
                    <button type="submit" class="sub-btn-pay" id="subSubmitBtn">
                        <i class="fas fa-lock"></i> <span>Proceed to PayMongo</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* Checkout Modal Styles */
.sub-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.sub-modal-card {
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
    max-width: 580px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid #eaecf0;
    animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.sub-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 24px 28px 16px 28px;
    border-bottom: 1px solid #eaecf0;
}
.sub-modal-kicker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
    color: #b3261e;
    letter-spacing: 0.06em;
}
.sub-modal-title {
    margin: 4px 0 0 0;
    font-size: 1.45rem;
    font-weight: 800;
    color: #101828;
}
.sub-modal-close {
    background: transparent;
    border: none;
    font-size: 1.6rem;
    color: #98a2b3;
    cursor: pointer;
    line-height: 1;
    padding: 0;
    transition: color 0.15s ease;
}
.sub-modal-close:hover {
    color: #101828;
}
.sub-modal-body {
    padding: 24px 28px;
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.sub-field-label {
    display: block;
    font-size: 0.86rem;
    font-weight: 700;
    color: #344054;
    margin-bottom: 8px;
}
.sub-cycle-toggle-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.sub-cycle-option {
    position: relative;
    cursor: pointer;
    margin: 0;
}
.sub-cycle-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}
.sub-cycle-content {
    border: 2px solid #eaecf0;
    border-radius: 12px;
    padding: 14px;
    background: #ffffff;
    transition: all 0.2s ease;
}
.sub-cycle-option input[type="radio"]:checked + .sub-cycle-content {
    border-color: #b3261e;
    background: #fff8f7;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
}
.sub-cycle-name {
    font-weight: 700;
    font-size: 0.92rem;
    color: #101828;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.sub-cycle-price {
    font-size: 1.15rem;
    font-weight: 800;
    color: #b3261e;
    margin-bottom: 2px;
}
.sub-cycle-desc {
    font-size: 0.75rem;
    color: #667085;
    display: block;
}
.sub-badge-save {
    background: #027a48;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 2px 6px;
    border-radius: 4px;
}
.sub-pay-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}
.sub-pay-option {
    position: relative;
    cursor: pointer;
    margin: 0;
}
.sub-pay-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}
.sub-pay-card {
    border: 1.5px solid #eaecf0;
    border-radius: 10px;
    padding: 12px 6px;
    text-align: center;
    background: #ffffff;
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
.sub-pay-card i {
    font-size: 1.3rem;
}
.sub-pay-card span {
    font-size: 0.75rem;
    font-weight: 700;
    color: #344054;
}
.sub-pay-option input[type="radio"]:checked + .sub-pay-card {
    border-color: #b3261e;
    background: #fff8f7;
    box-shadow: 0 0 0 3px rgba(179, 38, 30, 0.12);
}
.sub-terms-card {
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    border-radius: 12px;
    padding: 16px;
}
.sub-terms-top {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #101828;
    font-size: 0.88rem;
    margin-bottom: 8px;
}
.sub-terms-top-icon {
    color: #b3261e;
}
.sub-terms-list {
    margin: 0 0 12px 0;
    padding-left: 18px;
    font-size: 0.8rem;
    color: #475467;
    line-height: 1.55;
}
.sub-agree-checkbox-wrap {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
    font-size: 0.82rem;
    color: #101828;
    margin: 0;
    padding-top: 8px;
    border-top: 1px solid #eaecf0;
}
.sub-agree-checkbox-wrap input[type="checkbox"] {
    margin-top: 3px;
    accent-color: #b3261e;
}
.sub-total-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 14px;
    border-top: 1px solid #eaecf0;
}
.sub-total-copy span {
    display: block;
    font-size: 0.78rem;
    color: #667085;
}
.sub-total-copy strong {
    display: block;
    font-size: 1.35rem;
    font-weight: 800;
    color: #101828;
}
.sub-btn-pay {
    background: #b3261e;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    padding: 12px 24px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.sub-btn-pay:hover {
    background: #931e18;
    box-shadow: 0 6px 18px rgba(179, 38, 30, 0.3);
}

@media (max-width: 576px) {
    .sub-cycle-toggle-grid { grid-template-columns: 1fr; }
    .sub-pay-grid { grid-template-columns: 1fr 1fr; }
    .sub-total-bar { flex-direction: column; gap: 12px; align-items: stretch; text-align: center; }
    .sub-btn-pay { width: 100%; justify-content: center; }
}
</style>

<script src="../js/bootstrap.bundle.min.js"></script>
<script>
function confirmSubscriptionCancellation(planName, renewsDate) {
    return confirm(
        "Are you sure you want to cancel your " + planName + " subscription?\n\n" +
        "• Non-Refundable: In accordance with our Terms of Agreement, subscription payments are non-refundable.\n" +
        "• Continued Access: Your shop will retain 100% full active access to all " + planName + " features and discounts until " + renewsDate + ".\n" +
        "• Auto-Termination: After " + renewsDate + ", recurring charges will stop and your plan will conclude.\n\n" +
        "Click OK to confirm cancellation."
    );
}

let currentModalPlan = {
    id: 0,
    name: '',
    monthlyPrice: 0,
    annualPrice: 0,
    planCode: '',
    cycle: 'monthly'
};

function openSubscriptionCheckoutModal(planId, planName, monthlyPrice, annualPrice, planCode) {
    currentModalPlan = {
        id: planId,
        name: planName,
        monthlyPrice: parseFloat(monthlyPrice) || 0,
        annualPrice: parseFloat(annualPrice) || 0,
        planCode: planCode,
        cycle: 'monthly'
    };

    document.getElementById('modalPlanId').value = planId;
    document.getElementById('modalPlanTitle').textContent = 'Subscribe to ' + planName + ' Plan';
    document.getElementById('modalMonthlyPriceLabel').textContent = 'PHP ' + currentModalPlan.monthlyPrice.toLocaleString('en-US') + ' / month';
    document.getElementById('modalAnnualPriceLabel').textContent = 'PHP ' + currentModalPlan.annualPrice.toLocaleString('en-US') + ' / year';
    
    const annualEq = currentModalPlan.annualPrice > 0 ? Math.round(currentModalPlan.annualPrice / 12) : 0;
    document.getElementById('modalAnnualEquivalentLabel').textContent = 'Equivalent to PHP ' + annualEq.toLocaleString('en-US') + ' / mo';

    // Default to monthly radio
    const monthlyRadio = document.querySelector('input[name="billing_cycle"][value="monthly"]');
    if (monthlyRadio) monthlyRadio.checked = true;

    updateModalCycle('monthly');

    // Uncheck agreement to ensure fresh confirmation
    const agreeCheck = document.getElementById('agreeTermsCheckbox');
    if (agreeCheck) agreeCheck.checked = false;

    // Reset button state
    const submitBtn = document.getElementById('subSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-lock"></i> <span>Proceed to PayMongo</span>';
    }

    const modal = document.getElementById('subscriptionCheckoutModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function updateModalCycle(cycle) {
    currentModalPlan.cycle = cycle;
    const isAnnual = cycle === 'annual';
    const amount = isAnnual ? currentModalPlan.annualPrice : currentModalPlan.monthlyPrice;
    document.getElementById('modalTotalAmount').textContent = 'PHP ' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function closeSubscriptionCheckoutModal() {
    const modal = document.getElementById('subscriptionCheckoutModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function submitSubscriptionCheckout(e) {
    const agreeCheck = document.getElementById('agreeTermsCheckbox');
    if (!agreeCheck || !agreeCheck.checked) {
        alert('Please check and agree to the Subscription Terms & Cancellation Policy to continue.');
        return false;
    }

    const submitBtn = document.getElementById('subSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <span>Redirecting to PayMongo...</span>';
    }
    return true;
}

// Close modal when clicking backdrop & handle pre-fill from query params
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('subscriptionCheckoutModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeSubscriptionCheckoutModal();
            }
        });
    }

    <?php if (!empty($_GET['plan_id'])): ?>
    <?php
        $prefillPlanId = (int)$_GET['plan_id'];
        $prefillCycle = strtolower(trim((string)($_GET['billing_cycle'] ?? 'monthly')));
        $targetPlan = null;
        foreach ($plans as $p) {
            if ((int)$p['id'] === $prefillPlanId) {
                $targetPlan = $p;
                break;
            }
        }
        if ($targetPlan):
    ?>
    openSubscriptionCheckoutModal(
        <?php echo (int)$targetPlan['id']; ?>,
        <?php echo json_encode((string)$targetPlan['plan_name']); ?>,
        <?php echo (float)$targetPlan['monthly_price']; ?>,
        <?php echo (float)$targetPlan['annual_price']; ?>,
        <?php echo json_encode((string)$targetPlan['plan_code']); ?>
    );
    <?php if ($prefillCycle === 'annual'): ?>
    const annualRadio = document.querySelector('input[name="billing_cycle"][value="annual"]');
    if (annualRadio) {
        annualRadio.checked = true;
        updateModalCycle('annual');
    }
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
});
</script>
</body>
</html>
