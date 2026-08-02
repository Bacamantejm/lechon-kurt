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
<section class="plans-hero">
    <div class="container plans-hero-grid">
        <div class="plans-hero-copy">
            <span class="plans-kicker">Partner Subscription Plans</span>
            <h1>Choose the billing plan that matches your shop's growth.</h1>
            <p><?php echo htmlspecialchars($hero_note); ?></p>
            <div class="plans-hero-actions">
                <a href="<?php echo htmlspecialchars($primary_cta_href); ?>" class="plans-btn plans-btn-primary"><?php echo htmlspecialchars($primary_cta_label); ?></a>
                <a href="<?php echo htmlspecialchars($secondary_cta_href); ?>" class="plans-btn plans-btn-secondary"><?php echo htmlspecialchars($secondary_cta_label); ?></a>
            </div>
            <div class="plans-billing-toggle" aria-label="Compare billing cycles">
                <button type="button" class="billing-toggle-btn active" data-billing-mode="monthly">Compare Monthly</button>
                <button type="button" class="billing-toggle-btn" data-billing-mode="annual">Compare Annual</button>
            </div>
            <div class="plans-hero-meta">
                <div class="plans-meta-card"><strong><?php echo number_format($plan_count); ?></strong><span>Active subscription tiers</span></div>
                <div class="plans-meta-card"><strong>PHP <?php echo number_format((float)($plans[0]['monthly_price'] ?? 0), 2); ?></strong><span>Starter entry point</span></div>
                <div class="plans-meta-card"><strong>Live</strong><span>Connected to your monetization module</span></div>
            </div>
        </div>
        <div class="plans-hero-panel">
            <div class="plans-hero-stack">
                <div class="plans-stack-card">
                    <span>For new partner shops</span>
                    <strong>Start with a plan, then request activation.</strong>
                    <p>Partners can compare pricing here before they open billing or submit a plan request.</p>
                </div>
                <div class="plans-stack-card accent">
                    <span>Operational workflow</span>
                    <strong>Owner approval keeps billing and access aligned.</strong>
                    <p>New subscriptions, renewals, upgrades, and downgrades now pass through a trackable request queue.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="plans-sticky-summary-shell">
    <div class="container">
        <div class="plans-sticky-summary" id="plansStickySummary">
            <div class="sticky-summary-copy">
                <span class="sticky-summary-label">Compare Plans</span>
                <strong id="stickyBillingModeText">Monthly billing selected</strong>
                <span id="stickyRecommendationText">Recommended plan will appear here as you use the quick selector below.</span>
            </div>
            <div class="sticky-summary-actions">
                <span class="sticky-savings-chip" id="stickySavingsChip">Annual savings available: PHP 0.00</span>
                <button type="button" class="sticky-mode-chip active" data-billing-mode="monthly">Monthly</button>
                <button type="button" class="sticky-mode-chip" data-billing-mode="annual">Annual</button>
                <a href="<?php echo htmlspecialchars($primary_cta_href); ?>" class="plans-btn plans-btn-primary" id="stickyRequestLink">Continue</a>
            </div>
        </div>
    </div>
</section>

<section class="plans-calculator-section">
    <div class="container">
        <div class="section-heading">
            <span>Quick Match</span>
            <h2>Find the best-fit plan for your shop in a few seconds.</h2>
            <p>Use this lightweight recommendation helper to match your current team size and support needs to the most sensible plan.</p>
        </div>
        <div class="plans-calculator-grid">
            <div class="plans-calculator-card">
                <div class="calculator-field">
                    <label for="planStaffRange">How many staff accounts does your shop need?</label>
                    <div class="calculator-range-head">
                        <strong id="planStaffValue">2 staff</strong>
                        <span>Adjust based on your current or near-term team size.</span>
                    </div>
                    <input type="range" id="planStaffRange" min="1" max="20" step="1" value="2">
                </div>
                <div class="calculator-checks">
                    <label class="calculator-check"><input type="checkbox" id="needAiAutomation"> <span>We want AI support automation</span></label>
                    <label class="calculator-check"><input type="checkbox" id="needPrioritySupport"> <span>We need priority support</span></label>
                    <label class="calculator-check"><input type="checkbox" id="needFeaturedPlacement"> <span>We want featured placement / more visibility</span></label>
                    <label class="calculator-check"><input type="checkbox" id="needCustomBranding"> <span>We want custom branding / premium presentation</span></label>
                </div>
            </div>
            <div class="plans-calculator-result" id="plansCalculatorResult">
                <span class="calculator-result-kicker">Recommended Plan</span>
                <h3 id="recommendedPlanName">Growth</h3>
                <p id="recommendedPlanReason">A plan recommendation will appear here based on your shop needs.</p>
                <div class="calculator-result-meta">
                    <div><span>Best for</span><strong id="recommendedPlanFit">Growing partner shops</strong></div>
                    <div><span>Billing mode</span><strong id="recommendedPlanBilling">Monthly</strong></div>
                    <div><span>Current price</span><strong id="recommendedPlanPrice">PHP 0.00</strong></div>
                </div>
                <div class="plans-hero-actions" style="margin-top:18px;">
                    <a href="<?php echo htmlspecialchars($primary_cta_href); ?>" class="plans-btn plans-btn-primary" id="recommendedPlanLink">Open This Plan</a>
                </div>
                <div class="calculator-why-wrap">
                    <button type="button" class="calculator-why-toggle" id="recommendedPlanWhyToggle" aria-expanded="false" aria-controls="recommendedPlanWhyBody">
                        <span>Why this plan?</span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="calculator-why-body" id="recommendedPlanWhyBody" hidden>
                        <p id="recommendedPlanWhySummary">This recommendation updates as your staffing and support needs change.</p>
                        <ul class="calculator-why-list">
                            <li id="recommendedPlanWhyReasonOne">Your selected inputs will shape the recommendation details here.</li>
                            <li id="recommendedPlanWhyReasonTwo">Feature needs like AI, visibility, and support can shift the best-fit tier.</li>
                            <li id="recommendedPlanWhyReasonThree">Billing mode changes will keep pricing and savings aligned with your choice.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="plans-cards-section">
    <div class="container">
        <div class="section-heading">
            <span>Plan Comparison</span>
            <h2>Simple pricing with room to scale.</h2>
            <p>Each plan combines recurring subscription value with platform support, billing visibility, and operational tools for partner stores.</p>
        </div>
        <div class="plans-grid">
            <?php foreach ($plans as $plan): ?>
                <?php
                    $planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
                    $isFeatured = $planCode === $featured_plan_code;
                    $monthlyPrice = (float)($plan['monthly_price'] ?? 0);
                    $annualPrice = (float)($plan['annual_price'] ?? 0);
                    $annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
                    $annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
                    $requestType = $isFeatured ? 'upgrade' : 'change_plan';
                    $monthlyRequestHref = $is_partner_ready_for_deeplink
                        ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=monthly&request_type=' . urlencode($requestType)
                        : $primary_cta_href;
                    $annualRequestHref = $is_partner_ready_for_deeplink
                        ? 'admin/partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=annual&request_type=' . urlencode($requestType)
                        : $primary_cta_href;
                    $cardButtonText = $is_partner_ready_for_deeplink
                        ? 'Request This Plan'
                        : ($current_user_id > 0 ? 'Continue Setup' : 'Start Partnership');
                ?>
                <article class="plan-card <?php echo $isFeatured ? 'featured' : ''; ?>" data-plan-card data-plan-id="<?php echo (int)($plan['id'] ?? 0); ?>" data-plan-code="<?php echo htmlspecialchars($planCode); ?>" data-plan-name="<?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?>" data-monthly-price="<?php echo htmlspecialchars(number_format($monthlyPrice, 2, '.', '')); ?>" data-annual-price="<?php echo htmlspecialchars(number_format($annualPrice, 2, '.', '')); ?>" data-annual-equivalent="<?php echo htmlspecialchars(number_format($annualEquivalent, 2, '.', '')); ?>" data-annual-savings="<?php echo htmlspecialchars(number_format($annualSavings, 2, '.', '')); ?>" data-monthly-href="<?php echo htmlspecialchars($monthlyRequestHref); ?>" data-annual-href="<?php echo htmlspecialchars($annualRequestHref); ?>" data-max-staff="<?php echo (int)($plan['max_staff_accounts'] ?? 1); ?>" data-ai="<?php echo (int)($plan['includes_ai_automation'] ?? 0); ?>" data-priority="<?php echo (int)($plan['includes_priority_support'] ?? 0); ?>" data-featured-placement="<?php echo (int)($plan['includes_featured_placement'] ?? 0); ?>" data-custom-branding="<?php echo (int)($plan['includes_custom_branding'] ?? 0); ?>">
                    <?php if ($isFeatured): ?><span class="plan-badge">Most Balanced</span><?php endif; ?>
                    <span class="plan-live-badge" data-plan-live-badge hidden>Best Match</span>
                    <div class="plan-card-top">
                        <h3><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></h3>
                        <p><?php echo htmlspecialchars((string)($plan['description'] ?? '')); ?></p>
                    </div>
                    <div class="plan-price-wrap">
                        <div class="plan-price" data-plan-price>PHP <?php echo number_format($monthlyPrice, 2); ?></div>
                        <span data-plan-price-label>per month</span>
                    </div>
                    <div class="plan-annual-note" data-plan-price-note>
                        <strong>Annual:</strong> PHP <?php echo number_format($annualPrice, 2); ?>
                        <span><?php echo $annualSavings > 0 ? 'Save PHP ' . number_format($annualSavings, 2) . ' yearly' : 'PHP ' . number_format($annualEquivalent, 2) . ' monthly equivalent'; ?></span>
                    </div>
                    <ul class="plan-feature-list">
                        <li><i class="fas fa-users"></i> Up to <?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?> staff accounts</li>
                        <li><i class="fas fa-percent"></i> Included fee rate: <?php echo number_format((float)($plan['included_order_fee_percent'] ?? 0), 2); ?>%</li>
                        <li><i class="fas fa-receipt"></i> Flat fee per order: PHP <?php echo number_format((float)($plan['included_order_fee_flat'] ?? 0), 2); ?></li>
                        <li class="<?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'on' : 'off'; ?>"><i class="fas fa-robot"></i> AI automation <?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
                        <li class="<?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'on' : 'off'; ?>"><i class="fas fa-headset"></i> Priority support <?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
                        <li class="<?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'on' : 'off'; ?>"><i class="fas fa-bullhorn"></i> Featured placement <?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
                        <li class="<?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'on' : 'off'; ?>"><i class="fas fa-palette"></i> Custom branding <?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
                    </ul>
                    <div class="plan-card-actions">
                        <a href="<?php echo htmlspecialchars($monthlyRequestHref); ?>" class="plans-btn <?php echo $isFeatured ? 'plans-btn-primary' : 'plans-btn-secondary'; ?>" data-plan-request-link><?php echo htmlspecialchars($cardButtonText); ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="plans-matrix-section">
    <div class="container">
        <div class="section-heading left">
            <span>At-a-Glance</span>
            <h2>Feature matrix for quick partner decisions.</h2>
        </div>
        <div class="plans-table-wrap">
            <table class="plans-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <?php foreach ($plans as $plan): ?>
                            <th><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Monthly price</td>
                        <?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['monthly_price'] ?? 0), 2); ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Annual price</td>
                        <?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['annual_price'] ?? 0), 2); ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Max staff accounts</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Included order fee %</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo number_format((float)($plan['included_order_fee_percent'] ?? 0), 2); ?>%</td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Flat fee per order</td>
                        <?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['included_order_fee_flat'] ?? 0), 2); ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>AI automation</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Priority support</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Featured placement</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?>
                    </tr>
                    <tr>
                        <td>Custom branding</td>
                        <?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="plans-workflow-section">
    <div class="container plans-workflow-grid">
        <div>
            <div class="section-heading left">
                <span>Workflow</span>
                <h2>How a business partner subscribes to your system.</h2>
                <p>This is the clear journey your partner shops now follow.</p>
            </div>
            <div class="workflow-list">
                <div class="workflow-step"><strong>1. Compare plans</strong><span>Partner reviews pricing, support features, and fee structure on this page.</span></div>
                <div class="workflow-step"><strong>2. Submit request</strong><span>Partner opens billing and sends a new, renewal, upgrade, downgrade, or change-plan request.</span></div>
                <div class="workflow-step"><strong>3. Owner reviews</strong><span>Super admin checks the subscription request queue and approves or rejects it.</span></div>
                <div class="workflow-step"><strong>4. Plan activates</strong><span>The approved plan is applied to the partner account and billing dates are set.</span></div>
                <div class="workflow-step"><strong>5. Billing begins</strong><span>Invoices are generated, emailed, and shown in partner billing with payment actions.</span></div>
                <div class="workflow-step"><strong>6. Reminder safety net</strong><span>The system sends due-soon and overdue reminders until billing is settled.</span></div>
            </div>
        </div>
        <div class="plans-side-panel">
            <h3>Where partners go next</h3>
            <div class="side-panel-card">
                <strong>Not yet a partner?</strong>
                <p>Complete your business onboarding and approval first.</p>
                <a href="franchise_application.php" class="plans-btn plans-btn-secondary">Become a Partner</a>
            </div>
            <div class="side-panel-card">
                <strong>Already a partner shop?</strong>
                <p>Open your billing page to submit a plan request or pay invoices.</p>
                <a href="<?php echo htmlspecialchars($is_partner_admin ? 'admin/partner_billing.php' : $primary_cta_href); ?>" class="plans-btn plans-btn-primary">Open Billing</a>
            </div>
            <div class="side-panel-card">
                <strong>Need a custom deal?</strong>
                <p>Use Help Center if you want enterprise onboarding or manual owner assistance.</p>
                <a href="help_center.php" class="plans-btn plans-btn-secondary">Contact Support</a>
            </div>
        </div>
    </div>
</section>

<style>
.plans-hero {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 18%, rgba(255, 195, 113, 0.48), transparent 28%),
        radial-gradient(circle at 88% 20%, rgba(179, 38, 30, 0.16), transparent 24%),
        linear-gradient(135deg, #fff6eb 0%, #f6fbff 56%, #ffffff 100%);
    padding: 72px 0 54px;
}
.plans-hero-grid,
.plans-workflow-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(300px, .9fr);
    gap: 28px;
    align-items: start;
}
.plans-kicker,
.section-heading span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(179, 38, 30, 0.1);
    color: #9f1d17;
    font-size: .78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .12em;
}
.plans-hero-copy h1,
.section-heading h2 {
    margin: 16px 0 12px;
    font-size: clamp(2rem, 4vw, 3.55rem);
    line-height: 1;
    color: #111827;
}
.plans-hero-copy p,
.section-heading p {
    margin: 0;
    font-size: 1rem;
    line-height: 1.7;
    color: #5b6473;
    max-width: 670px;
}
.plans-hero-actions,
.plan-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 26px;
}
.plans-billing-toggle {
    margin-top: 22px;
    display: inline-flex;
    gap: 8px;
    padding: 8px;
    border-radius: 999px;
    background: rgba(255,255,255,.86);
    border: 1px solid #eadcc9;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
}
.billing-toggle-btn {
    min-height: 42px;
    padding: 0 16px;
    border-radius: 999px;
    border: none;
    background: transparent;
    color: #5a6475;
    font-weight: 800;
    cursor: pointer;
    transition: all .22s cubic-bezier(.22,1,.36,1);
}
.billing-toggle-btn.active {
    background: #111827;
    color: #fff;
    box-shadow: 0 10px 22px rgba(17, 24, 39, 0.18);
}
.plans-btn {
    min-height: 48px;
    padding: 0 18px;
    border-radius: 999px;
    border: 1px solid transparent;
    text-decoration: none;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .22s cubic-bezier(.22,1,.36,1), box-shadow .22s cubic-bezier(.22,1,.36,1), background-color .22s cubic-bezier(.22,1,.36,1), color .22s cubic-bezier(.22,1,.36,1);
}
.plans-btn:hover { transform: translateY(-2px); }
.plans-btn-primary { background: linear-gradient(135deg, #b3261e 0%, #ef6b2e 100%); color: #fff; box-shadow: 0 18px 36px rgba(179, 38, 30, 0.18); }
.plans-btn-secondary { background: #fff; color: #18202f; border-color: #d9dee6; }
.plans-hero-meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 28px;
}
.plans-meta-card,
.plans-stack-card,
.side-panel-card,
.workflow-step,
.plan-card {
    border: 1px solid #e6dfd4;
    border-radius: 24px;
    background: rgba(255,255,255,0.88);
    box-shadow: 0 20px 38px rgba(15, 23, 42, 0.07);
}
.plans-meta-card { padding: 16px; }
.plans-meta-card strong {
    display: block;
    margin-bottom: 6px;
    font-size: 1.25rem;
    color: #111827;
}
.plans-meta-card span { color: #677489; font-size: .9rem; }
.plans-hero-stack { display: grid; gap: 14px; }
.plans-stack-card { padding: 22px; }
.plans-stack-card span,
.side-panel-card strong { display: block; color: #9f1d17; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; font-size: .76rem; }
.plans-stack-card strong { display: block; margin: 10px 0 8px; font-size: 1.26rem; color: #0f172a; }
.plans-stack-card p,
.side-panel-card p,
.workflow-step span { margin: 0; color: #5d6677; line-height: 1.65; }
.plans-stack-card.accent { background: linear-gradient(160deg, #1f2937 0%, #0f172a 100%); border-color: #0f172a; }
.plans-stack-card.accent span,
.plans-stack-card.accent strong { color: #fff; }
.plans-stack-card.accent p { color: rgba(255,255,255,0.78); }
.plans-cards-section,
.plans-matrix-section,
.plans-workflow-section { padding: 66px 0; }
.plans-sticky-summary-shell {
    position: sticky;
    top: 84px;
    z-index: 70;
    margin-top: -18px;
}
.plans-sticky-summary {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    padding: 14px 18px;
    border: 1px solid #eadfce;
    border-radius: 18px;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 34px rgba(15, 23, 42, 0.08);
}
.sticky-summary-copy {
    display: grid;
    gap: 4px;
}
.sticky-summary-label {
    color: #9f1d17;
    font-size: .76rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.sticky-summary-copy strong {
    color: #111827;
    font-size: 1rem;
}
.sticky-summary-copy span:last-child {
    color: #667085;
    font-size: .9rem;
}
.sticky-summary-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
.sticky-savings-chip {
    display: inline-flex;
    align-items: center;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    background: linear-gradient(135deg, #ecfccb 0%, #dcfce7 100%);
    color: #166534;
    border: 1px solid #bbf7d0;
    font-weight: 800;
    font-size: .86rem;
}
.sticky-mode-chip {
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid #d9dee6;
    background: #fff;
    color: #324054;
    font-weight: 800;
    cursor: pointer;
    transition: all .22s cubic-bezier(.22,1,.36,1);
}
.sticky-mode-chip.active {
    background: #111827;
    color: #fff;
    border-color: #111827;
}
.plans-calculator-section {
    padding: 34px 0 12px;
}
.plans-calculator-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(300px, .9fr);
    gap: 18px;
    align-items: stretch;
}
.plans-calculator-card,
.plans-calculator-result {
    border: 1px solid #e6dfd4;
    border-radius: 24px;
    background: rgba(255,255,255,0.9);
    box-shadow: 0 20px 38px rgba(15, 23, 42, 0.06);
    padding: 22px;
}
.calculator-field label {
    display: block;
    margin-bottom: 12px;
    color: #111827;
    font-weight: 800;
}
.calculator-range-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
}
.calculator-range-head strong {
    font-size: 1.1rem;
    color: #9f1d17;
}
.calculator-range-head span {
    color: #667085;
    font-size: .9rem;
}
#planStaffRange {
    width: 100%;
    accent-color: #b3261e;
}
.calculator-checks {
    display: grid;
    gap: 12px;
    margin-top: 20px;
}
.calculator-check {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #f8fafc;
    color: #334155;
    font-weight: 600;
}
.calculator-check input {
    accent-color: #b3261e;
}
.calculator-result-kicker {
    display: inline-flex;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(179, 38, 30, 0.1);
    color: #9f1d17;
    font-size: .75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.plans-calculator-result h3 {
    margin: 14px 0 10px;
    font-size: 2rem;
    color: #111827;
}
.plans-calculator-result p {
    margin: 0;
    color: #667085;
    line-height: 1.7;
}
.plans-calculator-result.is-refreshing {
    animation: calculatorPanelRefresh .45s ease;
}
.calculator-result-meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 18px;
}
.calculator-result-meta div {
    padding: 14px;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.calculator-result-meta span {
    display: block;
    margin-bottom: 6px;
    color: #667085;
    font-size: .8rem;
}
.calculator-result-meta strong {
    color: #111827;
    font-size: .98rem;
}
.calculator-why-wrap {
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid #e5e7eb;
}
.calculator-why-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #f8fafc;
    color: #0f172a;
    font: inherit;
    font-weight: 700;
    padding: 14px 16px;
    cursor: pointer;
    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
}
.calculator-why-toggle:hover,
.calculator-why-toggle:focus-visible {
    border-color: #f1c38c;
    box-shadow: 0 14px 28px rgba(159, 29, 23, 0.08);
    transform: translateY(-1px);
    outline: none;
}
.calculator-why-toggle i {
    transition: transform .2s ease;
}
.calculator-why-toggle[aria-expanded="true"] i {
    transform: rotate(180deg);
}
.calculator-why-body {
    margin-top: 12px;
    padding: 16px 18px;
    border-radius: 18px;
    background: linear-gradient(180deg, #fffaf6 0%, #ffffff 100%);
    border: 1px solid #f3e1d4;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
}
.calculator-why-body p {
    margin: 0 0 12px;
    color: #4b5563;
}
.calculator-why-list {
    margin: 0;
    padding-left: 18px;
    display: grid;
    gap: 10px;
    color: #1f2937;
}
.calculator-why-list li {
    line-height: 1.65;
}
.section-heading { text-align: center; margin-bottom: 28px; }
.section-heading.left { text-align: left; margin-bottom: 20px; }
.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 18px;
}
.plan-card {
    position: relative;
    padding: 22px;
    overflow: hidden;
    transition: transform .22s cubic-bezier(.22,1,.36,1), box-shadow .22s cubic-bezier(.22,1,.36,1), border-color .22s cubic-bezier(.22,1,.36,1);
}
.plan-card.featured {
    background: linear-gradient(180deg, #fffaf2 0%, #ffffff 100%);
    border-color: #f1c38c;
    transform: translateY(-8px);
}
.plan-card.is-recommended {
    border-color: #16a34a;
    box-shadow: 0 24px 42px rgba(22, 163, 74, 0.18);
    transform: translateY(-10px);
}
.plan-card.recommend-animate {
    animation: recommendedCardPulse .68s cubic-bezier(.22,1,.36,1);
}
.plan-badge {
    position: absolute;
    top: 18px;
    right: 18px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #9f1d17;
    color: #fff;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.plan-live-badge {
    position: absolute;
    top: 18px;
    left: 18px;
    padding: 6px 10px;
    border-radius: 999px;
    background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
    color: #fff;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    box-shadow: 0 10px 22px rgba(22, 163, 74, 0.2);
}
.plan-card-top h3 {
    margin: 0 0 10px;
    font-size: 1.45rem;
    color: #111827;
}
.plan-card-top p {
    margin: 0;
    color: #5d6677;
    line-height: 1.65;
    min-height: 78px;
}
.plan-price-wrap { margin: 22px 0 12px; }
.plan-price {
    font-family: "Outfit","Plus Jakarta Sans",sans-serif;
    font-size: 2.25rem;
    font-weight: 800;
    color: #9f1d17;
}
.plan-price-wrap span,
.plan-annual-note span { color: #64748b; font-size: .9rem; }
.plan-annual-note {
    display: grid;
    gap: 3px;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
}
.plan-feature-list {
    list-style: none;
    padding: 0;
    margin: 18px 0 0;
    display: grid;
    gap: 11px;
}
.plan-feature-list li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    color: #263143;
}
.plan-feature-list li i { margin-top: 3px; color: #9f1d17; width: 16px; text-align: center; }
.plan-feature-list li.off { color: #778192; }
.plan-feature-list li.off i { color: #b8c0cb; }
.plans-table-wrap {
    overflow-x: auto;
    border: 1px solid #e5ddd0;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 20px 36px rgba(15, 23, 42, 0.06);
}
.plans-table { width: 100%; min-width: 860px; border-collapse: collapse; }
.plans-table th,
.plans-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #ece6dd;
    text-align: left;
}
.plans-table thead th {
    background: #fff7ef;
    color: #5c200d;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.plans-side-panel { display: grid; gap: 14px; }
.side-panel-card { padding: 20px; }
.side-panel-card strong { margin-bottom: 10px; }
.workflow-list { display: grid; gap: 12px; }
.workflow-step { padding: 18px 20px; }
.workflow-step strong {
    display: block;
    margin-bottom: 6px;
    font-size: 1rem;
    color: #0f172a;
}
@keyframes recommendedCardPulse {
    0% {
        transform: translateY(0) scale(1);
        box-shadow: 0 14px 24px rgba(15, 23, 42, 0.08);
    }
    45% {
        transform: translateY(-14px) scale(1.015);
        box-shadow: 0 30px 48px rgba(22, 163, 74, 0.24);
    }
    100% {
        transform: translateY(-10px) scale(1);
        box-shadow: 0 24px 42px rgba(22, 163, 74, 0.18);
    }
}
@keyframes calculatorPanelRefresh {
    0% {
        transform: translateY(0);
        box-shadow: 0 22px 42px rgba(15, 23, 42, 0.08);
    }
    50% {
        transform: translateY(-4px);
        box-shadow: 0 28px 48px rgba(159, 29, 23, 0.12);
    }
    100% {
        transform: translateY(0);
        box-shadow: 0 22px 42px rgba(15, 23, 42, 0.08);
    }
}
@media (max-width: 980px) {
    .plans-hero-grid,
    .plans-workflow-grid { grid-template-columns: 1fr; }
    .plans-hero-meta { grid-template-columns: 1fr; }
    .plans-calculator-grid { grid-template-columns: 1fr; }
    .calculator-result-meta { grid-template-columns: 1fr; }
    .plans-sticky-summary {
        flex-direction: column;
        align-items: stretch;
    }
    .sticky-summary-actions {
        justify-content: flex-start;
    }
}
</style>
<script>
(function () {
    const buttons = Array.from(document.querySelectorAll('[data-billing-mode]'));
    const stickyButtons = Array.from(document.querySelectorAll('.sticky-mode-chip'));
    const cards = Array.from(document.querySelectorAll('[data-plan-card]'));
    const staffRange = document.getElementById('planStaffRange');
    const staffValue = document.getElementById('planStaffValue');
    const needAiAutomation = document.getElementById('needAiAutomation');
    const needPrioritySupport = document.getElementById('needPrioritySupport');
    const needFeaturedPlacement = document.getElementById('needFeaturedPlacement');
    const needCustomBranding = document.getElementById('needCustomBranding');
    const recommendedPlanName = document.getElementById('recommendedPlanName');
    const recommendedPlanReason = document.getElementById('recommendedPlanReason');
    const recommendedPlanFit = document.getElementById('recommendedPlanFit');
    const recommendedPlanBilling = document.getElementById('recommendedPlanBilling');
    const recommendedPlanPrice = document.getElementById('recommendedPlanPrice');
    const recommendedPlanLink = document.getElementById('recommendedPlanLink');
    const recommendedPlanWhyToggle = document.getElementById('recommendedPlanWhyToggle');
    const recommendedPlanWhyBody = document.getElementById('recommendedPlanWhyBody');
    const recommendedPlanWhySummary = document.getElementById('recommendedPlanWhySummary');
    const recommendedPlanWhyReasonOne = document.getElementById('recommendedPlanWhyReasonOne');
    const recommendedPlanWhyReasonTwo = document.getElementById('recommendedPlanWhyReasonTwo');
    const recommendedPlanWhyReasonThree = document.getElementById('recommendedPlanWhyReasonThree');
    const plansCalculatorResult = document.getElementById('plansCalculatorResult');
    const stickyBillingModeText = document.getElementById('stickyBillingModeText');
    const stickyRecommendationText = document.getElementById('stickyRecommendationText');
    const stickySavingsChip = document.getElementById('stickySavingsChip');
    const stickyRequestLink = document.getElementById('stickyRequestLink');
    let activeMode = 'monthly';
    let lastRecommendedCard = null;

    if (!buttons.length || !cards.length) {
        return;
    }

    function formatPeso(value) {
        return 'PHP ' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function joinList(items) {
        if (!items.length) {
            return '';
        }
        if (items.length === 1) {
            return items[0];
        }
        if (items.length === 2) {
            return items[0] + ' and ' + items[1];
        }
        return items.slice(0, -1).join(', ') + ', and ' + items[items.length - 1];
    }

    function triggerPulse(node, className) {
        if (!node) {
            return;
        }
        node.classList.remove(className);
        void node.offsetWidth;
        node.classList.add(className);
        window.setTimeout(function () {
            node.classList.remove(className);
        }, 720);
    }

    function getPlanMeta(card) {
        return {
            code: String(card.getAttribute('data-plan-code') || '').toLowerCase(),
            name: String(card.getAttribute('data-plan-name') || 'Plan'),
            monthlyPrice: Number(card.getAttribute('data-monthly-price') || 0),
            annualPrice: Number(card.getAttribute('data-annual-price') || 0),
            monthlyHref: String(card.getAttribute('data-monthly-href') || '#'),
            annualHref: String(card.getAttribute('data-annual-href') || '#'),
            maxStaff: Number(card.getAttribute('data-max-staff') || 1),
            ai: Number(card.getAttribute('data-ai') || 0) === 1,
            priority: Number(card.getAttribute('data-priority') || 0) === 1,
            featuredPlacement: Number(card.getAttribute('data-featured-placement') || 0) === 1,
            customBranding: Number(card.getAttribute('data-custom-branding') || 0) === 1
        };
    }

    function getRecommendedCard() {
        const staffCount = Number(staffRange ? staffRange.value : 2);
        const wantsAi = !!(needAiAutomation && needAiAutomation.checked);
        const wantsPriority = !!(needPrioritySupport && needPrioritySupport.checked);
        const wantsFeatured = !!(needFeaturedPlacement && needFeaturedPlacement.checked);
        const wantsBranding = !!(needCustomBranding && needCustomBranding.checked);

        const byCode = {};
        cards.forEach((card) => {
            byCode[String(card.getAttribute('data-plan-code') || '').toLowerCase()] = card;
        });

        if ((staffCount >= 10 || wantsFeatured || wantsBranding) && byCode.pro) {
            return byCode.pro;
        }
        if ((staffCount >= 3 || wantsAi || wantsPriority) && byCode.growth) {
            return byCode.growth;
        }
        if (byCode.starter) {
            return byCode.starter;
        }

        const sorted = cards.slice().sort((left, right) => {
            return Number(left.getAttribute('data-monthly-price') || 0) - Number(right.getAttribute('data-monthly-price') || 0);
        });
        if (staffCount >= 10 || wantsFeatured || wantsBranding) {
            return sorted[sorted.length - 1] || sorted[0];
        }
        if (staffCount >= 3 || wantsAi || wantsPriority) {
            return sorted[Math.min(1, sorted.length - 1)] || sorted[0];
        }
        return sorted[0] || null;
    }

    function buildWhyPlanExplanation(meta, staffCount, wantsAi, wantsPriority, wantsFeatured, wantsBranding) {
        const requestedFeatures = [];
        if (wantsAi) requestedFeatures.push('AI automation');
        if (wantsPriority) requestedFeatures.push('priority support');
        if (wantsFeatured) requestedFeatures.push('featured placement');
        if (wantsBranding) requestedFeatures.push('custom branding');

        const summary = requestedFeatures.length
            ? meta.name + ' fits your current setup because it covers a ' + staffCount + '-staff team while staying aligned with your need for ' + joinList(requestedFeatures) + '.'
            : meta.name + ' fits your current setup because it matches a ' + staffCount + '-staff shop without forcing you to pay for extras you are not asking for yet.';

        const reasons = [];
        if (staffCount <= 2) {
            reasons.push('Your team size is still lean, so the recommendation keeps capacity practical instead of pushing you into overhead too early.');
        } else if (staffCount <= meta.maxStaff) {
            reasons.push('Your selected team size of ' + staffCount + ' staff fits comfortably inside this plan\'s ' + meta.maxStaff + ' staff-account allowance.');
        } else {
            reasons.push('You selected ' + staffCount + ' staff accounts, so the recommendation shifts upward to give your team more breathing room than smaller tiers.');
        }

        if (requestedFeatures.length) {
            reasons.push('You turned on ' + joinList(requestedFeatures) + ', and this plan is the first sensible tier that supports those priorities without overcomplicating the choice.');
        } else if (meta.code === 'starter') {
            reasons.push('Because you left premium needs turned off, the essentials-first option stays the most cost-efficient starting point for your shop.');
        } else {
            reasons.push('Even without every premium option selected, this tier gives you extra operational headroom if you expect to grow soon.');
        }

        if (meta.code === 'pro') {
            reasons.push('Pro becomes the stronger match once visibility, branding control, and heavier support start affecting daily execution and partner revenue.');
        } else if (meta.code === 'growth') {
            reasons.push('Growth is usually the best middle ground when a shop needs stronger support and flexibility without jumping straight into the highest-priced tier.');
        } else {
            reasons.push('Starter keeps the system simple for newer or smaller partner shops while still giving you room to move up later when demand grows.');
        }

        return {
            summary: summary,
            reasons: reasons
        };
    }

    function updateRecommendation() {
        const recommendedCard = getRecommendedCard();
        const staffCount = Number(staffRange ? staffRange.value : 2);
        const wantsAi = !!(needAiAutomation && needAiAutomation.checked);
        const wantsPriority = !!(needPrioritySupport && needPrioritySupport.checked);
        const wantsFeatured = !!(needFeaturedPlacement && needFeaturedPlacement.checked);
        const wantsBranding = !!(needCustomBranding && needCustomBranding.checked);
        if (staffValue) {
            staffValue.textContent = staffCount + (staffCount === 1 ? ' staff' : ' staff');
        }
        if (!recommendedCard) {
            return;
        }

        const meta = getPlanMeta(recommendedCard);
        const recommendedPrice = activeMode === 'annual' ? meta.annualPrice : meta.monthlyPrice;
        let fitText = 'Best for lean partner shops';
        let reasonText = 'This plan keeps subscription costs light while covering the essentials for a smaller shop team.';

        if (meta.code === 'growth') {
            fitText = 'Best for growing shops';
            reasonText = 'This is the balanced choice for teams that need more staff access, stronger support, or AI assistance as they scale.';
        } else if (meta.code === 'pro') {
            fitText = 'Best for high-volume stores';
            reasonText = 'This plan fits larger partner operations that want visibility boosts, branding control, and heavier day-to-day support.';
        }

        if (recommendedPlanName) recommendedPlanName.textContent = meta.name;
        if (recommendedPlanReason) recommendedPlanReason.textContent = reasonText;
        if (recommendedPlanFit) recommendedPlanFit.textContent = fitText;
        if (recommendedPlanBilling) recommendedPlanBilling.textContent = activeMode === 'annual' ? 'Annual' : 'Monthly';
        if (recommendedPlanPrice) recommendedPlanPrice.textContent = formatPeso(recommendedPrice);
        const recommendedHref = activeMode === 'annual' ? meta.annualHref : meta.monthlyHref;
        if (recommendedPlanLink) recommendedPlanLink.setAttribute('href', recommendedHref);
        const whyPlan = buildWhyPlanExplanation(meta, staffCount, wantsAi, wantsPriority, wantsFeatured, wantsBranding);
        if (recommendedPlanWhySummary) recommendedPlanWhySummary.textContent = whyPlan.summary;
        if (recommendedPlanWhyReasonOne) recommendedPlanWhyReasonOne.textContent = whyPlan.reasons[0] || '';
        if (recommendedPlanWhyReasonTwo) recommendedPlanWhyReasonTwo.textContent = whyPlan.reasons[1] || '';
        if (recommendedPlanWhyReasonThree) recommendedPlanWhyReasonThree.textContent = whyPlan.reasons[2] || '';
        if (stickyRecommendationText) {
            stickyRecommendationText.textContent = meta.name + ' is the current best match for a shop with ' + staffCount + ' staff and your selected support needs.';
        }
        if (stickySavingsChip) {
            if (activeMode === 'annual') {
                stickySavingsChip.textContent = 'Estimated yearly savings: ' + formatPeso(meta.monthlyPrice * 12 - meta.annualPrice > 0 ? meta.monthlyPrice * 12 - meta.annualPrice : 0);
            } else {
                stickySavingsChip.textContent = 'Annual savings available: ' + formatPeso(meta.monthlyPrice * 12 - meta.annualPrice > 0 ? meta.monthlyPrice * 12 - meta.annualPrice : 0);
            }
        }
        if (stickyRequestLink) {
            stickyRequestLink.setAttribute('href', recommendedHref);
            stickyRequestLink.textContent = meta.code === 'starter' ? 'Start with ' + meta.name : 'Request ' + meta.name;
        }

        cards.forEach((card) => {
            const badge = card.querySelector('[data-plan-live-badge]');
            const isRecommended = card === recommendedCard;
            card.classList.toggle('is-recommended', isRecommended);
            if (badge) {
                badge.hidden = !isRecommended;
            }
        });

        if (lastRecommendedCard !== recommendedCard) {
            triggerPulse(recommendedCard, 'recommend-animate');
            triggerPulse(plansCalculatorResult, 'is-refreshing');
            lastRecommendedCard = recommendedCard;
        }
    }

    function applyMode(mode) {
        activeMode = mode === 'annual' ? 'annual' : 'monthly';
        buttons.forEach((button) => {
            button.classList.toggle('active', button.getAttribute('data-billing-mode') === activeMode);
        });
        stickyButtons.forEach((button) => {
            button.classList.toggle('active', button.getAttribute('data-billing-mode') === activeMode);
        });
        if (stickyBillingModeText) {
            stickyBillingModeText.textContent = activeMode === 'annual' ? 'Annual billing selected' : 'Monthly billing selected';
        }

        cards.forEach((card) => {
            const priceNode = card.querySelector('[data-plan-price]');
            const labelNode = card.querySelector('[data-plan-price-label]');
            const noteNode = card.querySelector('[data-plan-price-note]');
            const linkNode = card.querySelector('[data-plan-request-link]');
            const monthlyPrice = Number(card.getAttribute('data-monthly-price') || 0);
            const annualPrice = Number(card.getAttribute('data-annual-price') || 0);
            const annualEquivalent = Number(card.getAttribute('data-annual-equivalent') || 0);
            const annualSavings = Number(card.getAttribute('data-annual-savings') || 0);

            if (activeMode === 'annual') {
                if (priceNode) priceNode.textContent = formatPeso(annualPrice);
                if (labelNode) labelNode.textContent = 'per year';
                if (noteNode) {
                    noteNode.innerHTML = '<strong>Monthly equivalent:</strong> ' + formatPeso(annualEquivalent) + '<span>' +
                        (annualSavings > 0 ? 'Save ' + formatPeso(annualSavings) + ' yearly' : 'Annual billing active') +
                        '</span>';
                }
                if (linkNode && card.getAttribute('data-annual-href')) {
                    linkNode.setAttribute('href', card.getAttribute('data-annual-href'));
                }
            } else {
                if (priceNode) priceNode.textContent = formatPeso(monthlyPrice);
                if (labelNode) labelNode.textContent = 'per month';
                if (noteNode) {
                    noteNode.innerHTML = '<strong>Annual:</strong> ' + formatPeso(annualPrice) + '<span>' +
                        (annualSavings > 0 ? 'Save ' + formatPeso(annualSavings) + ' yearly' : formatPeso(annualEquivalent) + ' monthly equivalent') +
                        '</span>';
                }
                if (linkNode && card.getAttribute('data-monthly-href')) {
                    linkNode.setAttribute('href', card.getAttribute('data-monthly-href'));
                }
            }
        });

        updateRecommendation();
    }

    buttons.concat(stickyButtons).forEach((button) => {
        button.addEventListener('click', function () {
            applyMode(this.getAttribute('data-billing-mode') || 'monthly');
        });
    });

    [staffRange, needAiAutomation, needPrioritySupport, needFeaturedPlacement, needCustomBranding].forEach((control) => {
        if (!control) {
            return;
        }
        control.addEventListener('input', updateRecommendation);
        control.addEventListener('change', updateRecommendation);
    });

    if (recommendedPlanWhyToggle && recommendedPlanWhyBody) {
        recommendedPlanWhyToggle.addEventListener('click', function () {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            recommendedPlanWhyBody.hidden = expanded;
        });
    }

    applyMode('monthly');
})();
</script>
<?php include 'includes/footer.php'; ?>
