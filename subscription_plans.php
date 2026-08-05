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

$featured_plan_code = 'growth';
$plan_count = count($plans);
$is_partner_ready_for_deeplink = $is_partner_admin;

include 'includes/header.php';
?>
<section class="premium-plans-section">
    <div class="container">
        <!-- Header: Two-column split layout -->
        <div class="plans-header-row">
            <div class="plans-header-left">
                <h1>Set up your shop,<br>pick a plan later</h1>
            </div>
            <div class="plans-header-right">
                <p>Simple plans. Simple prices. Only pay for what you really need. All plans come with 24/7 customer support. Change or cancel your plan at any time.</p>
                <a href="<?php echo htmlspecialchars($primary_cta_href); ?>" class="plans-get-started-btn">GET STARTED</a>
            </div>
        </div>

        <!-- Main Layout Split: Left toggle selector, Right comparison table -->
        <div class="plans-layout-grid">
            <aside class="plans-layout-sidebar">
                <div class="billing-cycle-selector">
                    <label class="cycle-option-label">
                        <input type="radio" name="billing_cycle_mode" value="annual" checked>
                        <span class="custom-radio-indicator"></span>
                        <span class="cycle-text">Pay Annually</span>
                    </label>
                    <label class="cycle-option-label">
                        <input type="radio" name="billing_cycle_mode" value="monthly">
                        <span class="custom-radio-indicator"></span>
                        <span class="cycle-text">Pay Monthly</span>
                    </label>
                </div>
            </aside>

            <main class="plans-layout-content">
                <div class="matrix-table-container">
                    <table class="plans-comparison-matrix">
                        <thead>
                            <tr>
                                <th class="col-feature-title"></th>
                                <?php foreach ($plans as $plan): 
                                    $planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
                                    $isFeatured = $planCode === $featured_plan_code;
                                    $monthlyPrice = (float)($plan['monthly_price'] ?? 0);
                                    $annualPrice = (float)($plan['annual_price'] ?? 0);
                                    $annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
                                    $annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
                                    
                                    // Savings percentage calculate
                                    $savingsPercent = 0;
                                    if ($monthlyPrice > 0) {
                                        $savingsPercent = round((($monthlyPrice * 12 - $annualPrice) / ($monthlyPrice * 12)) * 100);
                                    }
                                ?>
                                    <th class="col-plan-header <?php echo $isFeatured ? 'featured-column-head' : ''; ?>">
                                        <?php if ($isFeatured): ?>
                                            <div class="featured-badge">MOST POPULAR</div>
                                        <?php endif; ?>
                                        <div class="plan-name-title"><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></div>
                                        <div class="plan-price-block">
                                            <span class="plan-currency">PHP</span>
                                            <span class="plan-price-value" 
                                                  data-monthly-price="<?php echo number_format($monthlyPrice, 2); ?>"
                                                  data-annual-price="<?php echo number_format($annualEquivalent, 2); ?>">
                                                <?php echo number_format($annualEquivalent, 2); ?>
                                            </span>
                                        </div>
                                        <div class="plan-price-subtitle">per month</div>
                                        <div class="plan-savings-tag" data-annual-only>
                                            <?php echo $savingsPercent > 0 ? 'Save ' . $savingsPercent . '% annually' : 'Annual billing'; ?>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="group-header-row">
                                <td colspan="<?php echo count($plans) + 1; ?>">Core Limits</td>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Staff Accounts</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell"><?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Order Fee Rate</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell"><?php echo number_format((float)($plan['included_order_fee_percent'] ?? 0), 2); ?>%</td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Flat Fee per Order</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell">PHP <?php echo number_format((float)($plan['included_order_fee_flat'] ?? 0), 2); ?></td>
                                <?php endforeach; ?>
                            </tr>

                            <tr class="group-header-row">
                                <td colspan="<?php echo count($plans) + 1; ?>">Advanced Features</td>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">AI Support Automation</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell">
                                        <?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? '<span class="checkmark-icon">✓</span>' : '<span class="dash-icon">—</span>'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Priority Support</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell">
                                        <?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? '<span class="checkmark-icon">✓</span>' : '<span class="dash-icon">—</span>'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Featured Placement</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell">
                                        <?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? '<span class="checkmark-icon">✓</span>' : '<span class="dash-icon">—</span>'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td class="feature-name-cell">Custom Brand Styling</td>
                                <?php foreach ($plans as $plan): ?>
                                    <td class="feature-value-cell">
                                        <?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? '<span class="checkmark-icon">✓</span>' : '<span class="dash-icon">—</span>'; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <tr class="actions-row">
                                <td class="feature-name-cell"></td>
                                <?php foreach ($plans as $plan): 
                                    $planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
                                    $isFeatured = $planCode === $featured_plan_code;
                                    $requestType = $isFeatured ? 'upgrade' : 'change_plan';
                                    
                                    $monthlyHref = $is_partner_ready_for_deeplink
                                        ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=monthly&request_type=' . urlencode($requestType)
                                        : $primary_cta_href;
                                    $annualHref = $is_partner_ready_for_deeplink
                                        ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=annual&request_type=' . urlencode($requestType)
                                        : $primary_cta_href;
                                        
                                    $btnText = 'Buy Now';
                                ?>
                                    <td class="action-cell">
                                        <a href="<?php echo htmlspecialchars($annualHref); ?>" 
                                           class="plan-action-btn <?php echo $isFeatured ? 'btn-primary-solid' : 'btn-outline-minimal'; ?>"
                                           data-monthly-url="<?php echo htmlspecialchars($monthlyHref); ?>"
                                           data-annual-url="<?php echo htmlspecialchars($annualHref); ?>">
                                            <?php echo htmlspecialchars($btnText); ?>
                                        </a>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
</section>

<style>
.premium-plans-section {
    padding: 80px 0;
    background-color: #ffffff;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #171922;
}

.plans-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 40px;
    margin-bottom: 64px;
    border-bottom: 1px solid #efddcd;
    padding-bottom: 40px;
}

.plans-header-left {
    flex: 1 1 50%;
}

.plans-header-left h1 {
    font-size: clamp(2.2rem, 3.5vw, 3.2rem);
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: -1px;
    color: #171922;
    margin: 0;
}

.plans-header-right {
    flex: 1 1 50%;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 20px;
}

.plans-header-right p {
    font-size: 1.05rem;
    color: #667085;
    line-height: 1.6;
    margin: 0;
}

.plans-get-started-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #171922;
    color: #ffffff !important;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.95rem;
    padding: 14px 28px;
    border-radius: 6px;
    transition: background-color 0.2s ease;
}

.plans-get-started-btn:hover {
    background-color: #b3261e;
}

/* Layout Grid */
.plans-layout-grid {
    display: flex;
    gap: 40px;
    align-items: flex-start;
}

.plans-layout-sidebar {
    width: 220px;
    flex-shrink: 0;
    position: sticky;
    top: 100px;
}

.billing-cycle-selector {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.cycle-option-label {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1rem;
    color: #667085;
    user-select: none;
    transition: color 0.2s ease;
}

.cycle-option-label input[type="radio"] {
    display: none;
}

.custom-radio-indicator {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid #efddcd;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: relative;
    transition: border-color 0.2s ease;
}

.custom-radio-indicator::after {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #b3261e;
    opacity: 0;
    transform: scale(0.6);
    transition: transform 0.2s ease, opacity 0.2s ease;
}

.cycle-option-label input[type="radio"]:checked + .custom-radio-indicator {
    border-color: #b3261e;
}

.cycle-option-label input[type="radio"]:checked + .custom-radio-indicator::after {
    opacity: 1;
    transform: scale(1);
}

.cycle-option-label input[type="radio"]:checked ~ .cycle-text {
    color: #171922;
}

.plans-layout-content {
    flex-grow: 1;
}

/* Matrix Table Styling */
.matrix-table-container {
    width: 100%;
    overflow-x: auto;
}

.plans-comparison-matrix {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.plans-comparison-matrix th,
.plans-comparison-matrix td {
    padding: 16px 20px;
    vertical-align: middle;
}

.col-feature-title {
    width: 30%;
    border: none;
}

.col-plan-header {
    width: 23%;
    text-align: center;
    position: relative;
    border-bottom: 1px solid #efddcd;
    background-color: #ffffff;
    padding-top: 32px;
    padding-bottom: 24px;
}

.featured-column-head {
    background-color: #fffaf5;
}

.featured-badge {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    background-color: #0284c7;
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 800;
    padding: 4px 0;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.plan-name-title {
    font-size: 1.25rem;
    font-weight: 800;
    color: #171922;
    margin-bottom: 8px;
}

.plan-price-block {
    display: inline-flex;
    align-items: baseline;
    justify-content: center;
    gap: 4px;
}

.plan-currency {
    font-size: 0.9rem;
    font-weight: 700;
    color: #667085;
}

.plan-price-value {
    font-size: 2.2rem;
    font-weight: 900;
    color: #171922;
    letter-spacing: -1px;
}

.plan-price-subtitle {
    font-size: 0.8rem;
    color: #667085;
    margin-top: 2px;
}

.plan-savings-tag {
    margin-top: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #0284c7;
    background-color: rgba(2, 132, 199, 0.08);
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
}

/* Rows styling */
.group-header-row td {
    background-color: #fff9f2;
    font-weight: 800;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #b3261e;
    padding: 12px 20px;
    border-bottom: 1px solid #efddcd;
}

.plans-comparison-matrix tbody tr:not(.group-header-row):not(.actions-row) {
    border-bottom: 1px solid #eef0f3;
}

.plans-comparison-matrix tbody tr:not(.group-header-row):not(.actions-row):hover {
    background-color: #fafbfb;
}

.feature-name-cell {
    font-weight: 600;
    font-size: 0.95rem;
    color: #171922;
}

.feature-value-cell {
    text-align: center;
    font-size: 0.95rem;
    color: #171922;
}

.checkmark-icon {
    color: #10b981;
    font-weight: 900;
    font-size: 1.15rem;
}

.dash-icon {
    color: #d1d5db;
}

/* Action Cell / Buttons */
.action-cell {
    text-align: center;
    padding-top: 24px;
    padding-bottom: 32px;
}

.plan-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    max-width: 160px;
    min-height: 40px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.88rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.btn-primary-solid {
    background-color: #b3261e;
    color: #ffffff !important;
}

.btn-primary-solid:hover {
    background-color: #931e18;
}

.btn-outline-minimal {
    border: 1px solid #efddcd;
    background-color: transparent;
    color: #171922 !important;
}

.btn-outline-minimal:hover {
    border-color: #171922;
    background-color: #171922;
    color: #ffffff !important;
}

@media (max-width: 991px) {
    .plans-header-row {
        flex-direction: column;
        gap: 20px;
        margin-bottom: 40px;
    }
    
    .plans-layout-grid {
        flex-direction: column;
        gap: 32px;
    }
    
    .plans-layout-sidebar {
        width: 100%;
        position: static;
    }
    
    .billing-cycle-selector {
        flex-direction: row;
        gap: 24px;
    }
}
</style>

<script>
(function () {
    const radioButtons = document.querySelectorAll('input[name="billing_cycle_mode"]');
    const priceLabels = document.querySelectorAll('.plan-price-value');
    const savingsTags = document.querySelectorAll('.plan-savings-tag');
    const actionButtons = document.querySelectorAll('.plan-action-btn');

    function updateBillingView(mode) {
        priceLabels.forEach(label => {
            const price = mode === 'annual' ? label.getAttribute('data-annual-price') : label.getAttribute('data-monthly-price');
            label.textContent = price;
        });

        savingsTags.forEach(tag => {
            if (mode === 'annual') {
                tag.style.display = 'inline-block';
            } else {
                tag.style.display = 'none';
            }
        });

        actionButtons.forEach(btn => {
            const url = mode === 'annual' ? btn.getAttribute('data-annual-url') : btn.getAttribute('data-monthly-url');
            btn.setAttribute('href', url);
        });
    }

    radioButtons.forEach(radio => {
        radio.addEventListener('change', function () {
            updateBillingView(this.value);
        });
    });

    // Default initialization
    updateBillingView('annual');
})();
</script>
<?php include 'includes/footer.php'; ?>
