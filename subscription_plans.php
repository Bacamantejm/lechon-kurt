<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';
require_once __DIR__ . '/admin/auth.php';

$current_page = 'subscription_plans';
$page_title = 'Subscription Plans';

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$normalized_user_type = strtolower(trim((string)($_SESSION['user_type'] ?? '')));
$account_type = strtolower(trim((string)($_SESSION['account_type'] ?? '')));
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_user_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
$is_partner_admin = false;
if ($current_user_id > 0 && function_exists('isApprovedFranchiseSellerAccount')) {
    $is_partner_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
}

$monetizationService = new PlatformMonetizationService($conn);
$monetizationService->ensureReady($current_user_id);
$plans = array_values(array_filter($monetizationService->getPlans(), static function ($plan) {
    return (int)($plan['is_active'] ?? 0) === 1;
}));

$primary_cta_href = 'franchise_application.php';
$primary_cta_label = 'Apply as a Business Partner';
$secondary_cta_href = 'help_center.php';
$secondary_cta_label = 'Talk to Support';
$hero_note = 'Browse subscription options and choose the plan that fits your shop growth stage.';

if ($is_super_admin_user) {
    $primary_cta_href = 'super_admin/platform_monetization.php';
    $primary_cta_label = 'Open Monetization Dashboard';
    $secondary_cta_href = 'super_admin/platform_monetization.php';
    $secondary_cta_label = 'Review Subscription Requests';
    $hero_note = 'You are viewing the live partner-facing plans page while signed in as system owner.';
} elseif ($is_partner_admin) {
    $primary_cta_href = 'admin/partner_billing.php';
    $primary_cta_label = 'Open Partner Billing';
    $secondary_cta_href = 'admin/partner_billing.php';
    $secondary_cta_label = 'Request a Plan Change';
    $hero_note = 'Your shop can compare plans here, then request upgrades, renewals, or billing-cycle changes from Partner Billing.';
} elseif ($normalized_user_type === 'admin') {
    $primary_cta_href = 'admin/index.php';
    $primary_cta_label = 'Open Admin Panel';
    $secondary_cta_href = 'franchise_application.php';
    $secondary_cta_label = 'View Partnership Requirements';
} elseif ($current_user_id > 0 && $account_type === 'organization') {
    $primary_cta_href = 'franchise_application.php';
    $primary_cta_label = 'Complete Partner Setup';
    $secondary_cta_href = 'help_center.php';
    $secondary_cta_label = 'Ask About Shop Subscription';
}

$featured_plan_code = 'pro';
$plan_count = count($plans);
$is_partner_ready_for_deeplink = $is_partner_admin;

include 'includes/header.php';
?>
<section class="zen-plans-wrapper">
    <div class="zen-plans-container">
        
        <!-- Header Title Section -->
        <div class="zen-plans-header">
            <h1 class="zen-plans-title">Simple, transparent pricing for growing food businesses</h1>
            <p class="zen-plans-subtitle">Select your subscription term and store count below to customize your plan. All plans include 24/7 customer support and full marketplace access.</p>
        </div>

        <!-- Top Configuration Control Bar -->
        <div class="zen-config-bar">
            <div class="config-item term-item">
                <span class="config-label">Subscription term</span>
                <div class="term-toggle-container">
                    <span class="term-option" id="termMonthlyLabel">Monthly</span>
                    <label class="switch-control">
                        <input type="checkbox" id="billingCycleToggle" checked>
                        <span class="slider-round"></span>
                    </label>
                    <span class="term-option active" id="termAnnualLabel">Annual</span>
                    <span class="savings-pill">Save with annual</span>
                </div>
            </div>
            <div class="config-separator"></div>
            <div class="config-item store-item">
                <span class="config-label">Number of stores</span>
                <div class="store-input-box">
                    <input type="number" id="branchCountInput" value="1" min="1" max="50">
                </div>
            </div>
        </div>

        <!-- Minimal Standalone Plan Cards Grid -->
        <div class="sp-minimal-grid">
            <?php foreach ($plans as $index => $plan): 
                $planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
                $isPro = $planCode === 'pro';
                $isFeatured = $planCode === $featured_plan_code;
                $monthlyPrice = (float)($plan['monthly_price'] ?? 0);
                $annualPrice = (float)($plan['annual_price'] ?? 0);
                $annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
                $annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
                
                $requestType = $isFeatured ? 'upgrade' : 'change_plan';
                $monthlyHref = $is_partner_ready_for_deeplink
                    ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=monthly&request_type=' . urlencode($requestType)
                    : $primary_cta_href;
                $annualHref = $is_partner_ready_for_deeplink
                    ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=annual&request_type=' . urlencode($requestType)
                    : $primary_cta_href;

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
                        <span class="sp-top-pill sp-pro-badge" id="badgePill_<?php echo $index; ?>"><i class="fas fa-fire"></i> MOST POPULAR &amp; BEST VALUE</span>
                    <?php else: ?>
                        <span class="sp-top-pill <?php echo $isFeatured ? 'sp-pill-featured' : ''; ?>" id="badgePill_<?php echo $index; ?>">
                            <?php echo $annualSavings > 0 ? 'Save PHP ' . number_format($annualSavings, 0) . ' yearly' : 'PHP ' . number_format($annualPrice, 0) . ' / year'; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="sp-card-header">
                    <div class="sp-brand-sub"><i class="fas fa-layer-group"></i> Partner Tier <?php echo $isPro ? '&bull; Recommended' : ''; ?></div>
                    <h2 class="sp-plan-title"><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></h2>
                    
                    <div class="sp-price-main">
                        <span>PHP</span>
                        <span class="price-amount" 
                              data-monthly-base="<?php echo $monthlyPrice; ?>" 
                              data-annual-base="<?php echo $annualEquivalent; ?>"
                              data-annual-full="<?php echo $annualPrice; ?>"
                              id="priceAmount_<?php echo $index; ?>">
                            <?php echo number_format($annualEquivalent, 0); ?>
                        </span>
                    </div>
                    <div class="sp-price-sub">per month</div>
                    <div class="sp-price-annual" id="priceSub_<?php echo $index; ?>">Annual: PHP <?php echo number_format($annualPrice, 0); ?></div>
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
                    <a href="<?php echo htmlspecialchars($annualHref); ?>" 
                       class="sp-pill-button <?php echo $isPro ? 'sp-pro-button' : ($isFeatured ? 'btn-highlight' : ''); ?>"
                       data-monthly-url="<?php echo htmlspecialchars($monthlyHref); ?>"
                       data-annual-url="<?php echo htmlspecialchars($annualHref); ?>"
                       id="ctaMain_<?php echo $index; ?>">
                        Get This Plan
                    </a>
                    <p class="sp-fineprint">
                        Cancel or switch plans anytime in Partner Billing. Terms apply.
                    </p>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<style>
.zen-plans-wrapper {
    padding: 60px 0 90px 0;
    background-color: #fff9f2;
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #2a211d;
}

.zen-plans-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.zen-plans-header {
    text-align: center;
    max-width: 760px;
    margin: 0 auto 40px auto;
}

.zen-plans-title {
    font-size: clamp(2rem, 3vw, 2.5rem);
    font-weight: 800;
    color: #2a211d;
    line-height: 1.2;
    margin-bottom: 12px;
    letter-spacing: -0.5px;
}

.zen-plans-subtitle {
    font-size: 1rem;
    color: #7b6d64;
    line-height: 1.6;
    margin: 0;
}

/* Top Control Bar */
.zen-config-bar {
    background: #ffffff;
    border: 1px solid #efddcd;
    border-radius: 12px;
    padding: 16px 28px;
    max-width: 620px;
    margin: 0 auto 48px auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 16px rgba(42, 33, 29, 0.05);
}

.config-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.config-label {
    font-size: 0.95rem;
    font-weight: 700;
    color: #2a211d;
}

.term-toggle-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.term-option {
    font-size: 0.9rem;
    font-weight: 600;
    color: #7b6d64;
    transition: color 0.2s ease;
}

.term-option.active {
    color: #2a211d;
    font-weight: 800;
}

.switch-control {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}

.switch-control input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider-round {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #2a211d;
    transition: .3s;
    border-radius: 34px;
}

.slider-round:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.switch-control input:checked + .slider-round {
    background-color: #b3261e;
}

.switch-control input:checked + .slider-round:before {
    transform: translateX(20px);
}

.savings-pill {
    background-color: #fff8ef;
    color: #ef6b2e;
    border: 1px solid #efddcd;
    font-size: 0.75rem;
    font-weight: 800;
    padding: 3px 10px;
    border-radius: 12px;
    display: inline-block;
    white-space: nowrap;
}

.config-separator {
    width: 1px;
    height: 48px;
    background-color: #efddcd;
}

.store-input-box input {
    width: 65px;
    padding: 4px 8px;
    border: none;
    border-bottom: 2px solid #b3261e;
    border-radius: 4px;
    font-size: 1.05rem;
    font-weight: 800;
    text-align: center;
    color: #2a211d;
    background-color: #fff8ef;
    outline: none;
}

.store-input-box input:focus {
    border-bottom-color: #ef6b2e;
    background-color: #ffffff;
}

/* Minimal Grid & Cards */
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

.zen-plans-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.zen-plans-header {
    text-align: center;
    max-width: 760px;
    margin: 0 auto 40px auto;
}

.zen-plans-title {
    font-size: clamp(2rem, 3vw, 2.5rem);
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    margin-bottom: 12px;
    letter-spacing: -0.5px;
}

.zen-plans-subtitle {
    font-size: 1rem;
    color: #64748b;
    line-height: 1.6;
    margin: 0;
}

/* Top Control Bar */
.zen-config-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px 28px;
    max-width: 620px;
    margin: 0 auto 48px auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
}

.config-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.config-label {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}

.term-toggle-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.term-option {
    font-size: 0.9rem;
    font-weight: 600;
    color: #64748b;
    transition: color 0.2s ease;
}

.term-option.active {
    color: #0f172a;
    font-weight: 700;
}

.switch-control {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}

.switch-control input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider-round {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #0f172a;
    transition: .3s;
    border-radius: 34px;
}

.slider-round:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.switch-control input:checked + .slider-round {
    background-color: #059669;
}

.switch-control input:checked + .slider-round:before {
    transform: translateX(20px);
}

.savings-pill {
    background-color: #e6f4ea;
    color: #137333;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 12px;
    display: inline-block;
    white-space: nowrap;
}

.config-separator {
    width: 1px;
    height: 48px;
    background-color: #e2e8f0;
}

.store-input-box input {
    width: 65px;
    padding: 4px 8px;
    border: none;
    border-bottom: 2px solid #0f172a;
    border-radius: 4px;
    font-size: 1.05rem;
    font-weight: 800;
    text-align: center;
    color: #0f172a;
    background-color: #f8fafc;
    outline: none;
}

.store-input-box input:focus {
    border-bottom-color: #b3261e;
    background-color: #ffffff;
}

/* 4-Column Matrix Container */
.zen-matrix-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03);
    position: relative;
    overflow: visible;
}

.zen-plans-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    position: relative;
}

.zen-plan-col {
    padding: 36px 20px 28px 20px;
    border-right: 1px solid #e2e8f0;
    position: relative;
    display: flex;
    flex-direction: column;
    background: #ffffff;
    transition: background-color 0.2s ease;
}

.zen-plan-col:last-child {
    border-right: none;
}

.zen-plan-col.is-popular {
    background: #ffffff;
}

.zen-popular-tag {
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background-color: #64243b;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: capitalize;
    padding: 3px 12px;
    border-radius: 4px;
    white-space: nowrap;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    z-index: 10;
}

.plan-col-title {
    font-size: 1.3rem;
    font-weight: 800;
    color: #0f172a;
    text-align: center;
    margin: 8px 0 16px 0;
}

/* Price Block */
.plan-col-price-block {
    text-align: center;
    margin-bottom: 20px;
}

.price-value-line {
    display: inline-flex;
    align-items: baseline;
    justify-content: center;
    gap: 4px;
}

.price-currency {
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
}

.price-amount {
    font-size: 2.75rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
    letter-spacing: -1px;
}

.price-unit-sub {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 4px;
    font-weight: 500;
}

.price-calculated-sub {
    font-size: 0.78rem;
    color: #0f172a;
    margin-top: 4px;
    font-weight: 700;
}

/* CTAs */
.plan-col-cta {
    text-align: center;
    margin-bottom: 24px;
}

.cta-button-main {
    display: block;
    width: 100%;
    padding: 12px 14px;
    font-weight: 700;
    font-size: 0.9rem;
    border-radius: 4px;
    text-decoration: none;
    text-align: center;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.btn-primary-dark {
    background-color: #0f2b28;
    color: #ffffff !important;
    border: none;
}

.btn-primary-dark:hover {
    background-color: #b3261e;
    box-shadow: 0 4px 12px rgba(179,38,30,0.2);
}

.btn-outline-dark {
    background-color: #0f2b28;
    color: #ffffff !important;
    border: none;
}

.btn-outline-dark:hover {
    background-color: #b3261e;
}

.cta-link-sub {
    display: inline-block;
    margin-top: 10px;
    font-size: 0.85rem;
    font-weight: 700;
    color: #0f2b28;
    text-decoration: underline;
    transition: color 0.2s ease;
}

.cta-link-sub:hover {
    color: #b3261e;
}

/* Features List */
.plan-col-features {
    list-style: none;
    padding: 0;
    margin: 0;
    border-top: 1px solid #e2e8f0;
    padding-top: 20px;
}

.plan-col-features li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 0.85rem;
    color: #334155;
    line-height: 1.45;
    padding: 7px 0;
}

.plan-col-features li span:last-child {
    border-bottom: 1px dotted #cbd5e1;
}

.icon-check {
    color: #059669;
    font-weight: 800;
    font-size: 0.95rem;
    flex-shrink: 0;
    margin-top: 1px;
}

.icon-dash {
    color: #cbd5e1;
    font-weight: 700;
    flex-shrink: 0;
    margin-top: 1px;
}

.text-muted {
    color: #94a3b8;
    text-decoration: line-through;
}

/* Responsive */
@media (max-width: 991px) {
    .zen-plans-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .zen-plan-col {
        border-bottom: 1px solid #e2e8f0;
    }
    
    .zen-plan-col:nth-child(2n) {
        border-right: none;
    }
}

@media (max-width: 768px) {
    .sp-minimal-grid {
        grid-template-columns: 1fr;
        gap: 16px;
        padding: 0 10px;
    }
    .sp-minimal-card {
        padding: 22px 18px;
    }
    .sp-pro-card {
        transform: none;
    }
}

@media (max-width: 640px) {
    .zen-plans-grid {
        grid-template-columns: 1fr;
    }
    
    .zen-plan-col {
        border-right: none;
    }

    .zen-config-bar {
        flex-direction: column;
        gap: 16px;
        align-items: center;
        padding: 20px;
    }

    .config-separator {
        width: 100%;
        height: 1px;
    }
}
</style>

<script>
(function () {
    const cycleToggle = document.getElementById('billingCycleToggle');
    const branchInput = document.getElementById('branchCountInput');
    const termMonthlyLabel = document.getElementById('termMonthlyLabel');
    const termAnnualLabel = document.getElementById('termAnnualLabel');
    const planColumns = document.querySelectorAll('.zen-plan-col');

    function updateMatrixPricing() {
        const isAnnual = cycleToggle ? cycleToggle.checked : true;
        const branchCount = Math.max(1, parseInt(branchInput?.value || 1, 10));

        if (termMonthlyLabel && termAnnualLabel) {
            termMonthlyLabel.classList.toggle('active', !isAnnual);
            termAnnualLabel.classList.toggle('active', isAnnual);
        }

        planColumns.forEach((col, idx) => {
            const priceEl = document.getElementById('priceAmount_' + idx);
            const subEl = document.getElementById('priceSub_' + idx);
            const ctaMain = document.getElementById('ctaMain_' + idx);
            const ctaSub = document.getElementById('ctaSub_' + idx);

            if (!priceEl) return;

            const monthlyBase = parseFloat(priceEl.getAttribute('data-monthly-base') || 0);
            const annualBase = parseFloat(priceEl.getAttribute('data-annual-base') || 0);
            const annualFull = parseFloat(priceEl.getAttribute('data-annual-full') || 0);

            const activePricePerBranch = isAnnual ? annualBase : monthlyBase;
            const totalMonthly = activePricePerBranch * branchCount;
            const totalAnnualFull = (isAnnual ? annualFull : monthlyBase * 12) * branchCount;

            priceEl.textContent = new Intl.NumberFormat('en-PH').format(totalMonthly);

            if (subEl) {
                if (isAnnual) {
                    subEl.textContent = `PHP ${new Intl.NumberFormat('en-PH').format(totalAnnualFull)}/year for ${branchCount} ${branchCount === 1 ? 'store' : 'stores'}`;
                } else {
                    subEl.textContent = `Billed monthly for ${branchCount} ${branchCount === 1 ? 'store' : 'stores'}`;
                }
            }

            const urlAttr = isAnnual ? 'data-annual-url' : 'data-monthly-url';
            if (ctaMain) {
                const targetUrl = ctaMain.getAttribute(urlAttr);
                if (targetUrl) ctaMain.setAttribute('href', targetUrl);
            }
            if (ctaSub) {
                const targetUrl = ctaSub.getAttribute(urlAttr);
                if (targetUrl) ctaSub.setAttribute('href', targetUrl);
            }
        });
    }

    if (cycleToggle) {
        cycleToggle.addEventListener('change', updateMatrixPricing);
    }
    if (branchInput) {
        branchInput.addEventListener('input', updateMatrixPricing);
        branchInput.addEventListener('change', updateMatrixPricing);
    }

    updateMatrixPricing();
})();
</script>

<?php include 'includes/footer.php'; ?>
