<?php
session_start();
include 'auth.php';
include '../includes/config.php';
require_once '../includes/PlatformMonetizationService.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
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

<section class="sp-minimal-grid">
<?php foreach ($plans as $plan): ?>
<?php
$planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
$isPro = $planCode === 'pro';
$isFeatured = $planCode === $featured_plan_code;
$monthlyPrice = (float)($plan['monthly_price'] ?? 0);
$annualPrice = (float)($plan['annual_price'] ?? 0);
$annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
$annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
$requestType = $isFeatured ? 'upgrade' : 'change_plan';
$monthlyRequestHref = 'partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=monthly&request_type=' . urlencode($requestType);
$staffAccounts = (int)($plan['max_staff_accounts'] ?? 1);
$feePercent = (float)($plan['included_order_fee_percent'] ?? 0);
$feeFlat = (float)($plan['included_order_fee_flat'] ?? 0);
$hasAi = (int)($plan['includes_ai_automation'] ?? 0) === 1;
$hasPriority = (int)($plan['includes_priority_support'] ?? 0) === 1;
$hasFeatured = (int)($plan['includes_featured_placement'] ?? 0) === 1;
$hasBranding = (int)($plan['includes_custom_branding'] ?? 0) === 1;
?>
<article class="sp-minimal-card <?php echo $isPro ? 'sp-pro-card' : ($isFeatured ? 'sp-featured-card' : ''); ?>">
    <div class="sp-badge-wrap">
        <?php if ($isPro): ?>
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
        <li><span class="bullet-dot">&bull;</span> <strong><?php echo $staffAccounts > 99 ? 'Unlimited' : $staffAccounts; ?></strong> staff accounts</li>
        <li><span class="bullet-dot">&bull;</span> Fee rate: <strong><?php echo number_format($feePercent, 2); ?>%</strong> per order</li>
        <li><span class="bullet-dot">&bull;</span> Flat fee per order: <strong>PHP <?php echo number_format($feeFlat, 2); ?></strong></li>
        <li><span class="bullet-dot">&bull;</span> AI support automation: <strong><?php echo $hasAi ? 'Included' : 'Not included'; ?></strong></li>
        <li><span class="bullet-dot">&bull;</span> Priority 24/7 support: <strong><?php echo $hasPriority ? 'Included' : 'Not included'; ?></strong></li>
        <li><span class="bullet-dot">&bull;</span> Featured placement: <strong><?php echo $hasFeatured ? 'Included' : 'Not included'; ?></strong></li>
        <li><span class="bullet-dot">&bull;</span> Custom branding: <strong><?php echo $hasBranding ? 'Included' : 'Not included'; ?></strong></li>
        <li><span class="bullet-dot">&bull;</span> Real-time POS & Inventory Dashboard</li>
        <li><span class="bullet-dot">&bull;</span> Cancel or switch plan anytime</li>
    </ul>

    <div class="sp-card-action">
        <a href="<?php echo htmlspecialchars($monthlyRequestHref); ?>" class="sp-pill-button <?php echo $isPro ? 'sp-pro-button' : ($isFeatured ? 'btn-highlight' : ''); ?>">
            Get This Plan
        </a>
        <p class="sp-fineprint">
            PHP <?php echo number_format($monthlyPrice, 0); ?> per month. Cancel or switch plans anytime in Partner Billing. Terms apply.
        </p>
    </div>
</article>
<?php endforeach; ?>
</section>

</div>
</div>
</div>
<style>
.plans-shell {
    padding: 40px 20px 80px 20px;
    background: #fff9f2;
    min-height: 100vh;
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
<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
