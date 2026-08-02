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
        .plans-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;padding:11px 16px;font-weight:700;text-decoration:none}.plans-btn-primary{background:#b91c1c;color:#fff;border:1px solid #b91c1c}.plans-btn-secondary{background:#fff;color:#b91c1c;border:1px solid #fca5a5}.plans-btn:hover{color:inherit;text-decoration:none}
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
<section class="plans-card plans-hero">
    <span class="plans-kicker">Partner Subscription Plans</span>
    <h2 class="plans-title">Compare plans without leaving your partner dashboard.</h2>
    <p class="plans-sub">Your shop can now review pricing, fee structure, and support tiers inside admin, then jump straight into a prefilled billing request.</p>
    <div class="mode-toggle mt-3" aria-label="Billing mode toggle">
        <button type="button" class="mode-btn active" data-billing-mode="monthly">Compare Monthly</button>
        <button type="button" class="mode-btn" data-billing-mode="annual">Compare Annual</button>
    </div>
    <div class="hero-meta">
        <div class="hero-meta-box"><strong><?php echo htmlspecialchars($businessName); ?></strong><span>Current business dashboard</span></div>
        <div class="hero-meta-box"><strong><?php echo htmlspecialchars((string)($subscription['plan_name'] ?? 'Unassigned')); ?></strong><span>Current plan</span></div>
        <div class="hero-meta-box"><strong><?php echo number_format(count($plans)); ?></strong><span>Active pricing tiers</span></div>
    </div>
</section>

<section class="plans-card sticky-card">
    <div class="sticky-copy">
        <strong id="stickyBillingModeText">Monthly billing selected</strong>
        <span id="stickyRecommendationText">Recommended plan will update as you use the quick selector below.</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="sticky-savings" id="stickySavingsChip">Annual savings available: PHP 0.00</span>
        <div class="sticky-toggle">
            <button type="button" class="sticky-btn active" data-billing-mode="monthly">Monthly</button>
            <button type="button" class="sticky-btn" data-billing-mode="annual">Annual</button>
        </div>
        <a href="partner_billing.php" class="plans-btn plans-btn-primary" id="stickyRequestLink">Continue</a>
    </div>
</section>

<section class="plans-card">
    <h3 class="m-0" style="font-weight:800;color:#0f172a;">Quick Match</h3>
    <p class="plans-section-sub">Use this helper to see which plan best matches your staff size and support needs.</p>
    <div class="calc-grid">
        <div class="calc-pane">
            <label for="planStaffRange" class="form-label fw-bold">How many staff accounts does your shop need?</label>
            <div class="d-flex justify-content-between align-items-center gap-3">
                <strong id="planStaffValue">2 staff</strong>
                <span class="text-muted small">Adjust based on your current or near-term operations team.</span>
            </div>
            <input type="range" id="planStaffRange" class="form-range mt-3" min="1" max="20" step="1" value="2">
            <div class="calc-checks">
                <label class="calc-check"><input type="checkbox" id="needAiAutomation"> <span>We want AI support automation</span></label>
                <label class="calc-check"><input type="checkbox" id="needPrioritySupport"> <span>We need priority support</span></label>
                <label class="calc-check"><input type="checkbox" id="needFeaturedPlacement"> <span>We want featured placement / more visibility</span></label>
                <label class="calc-check"><input type="checkbox" id="needCustomBranding"> <span>We want custom branding / premium presentation</span></label>
            </div>
        </div>
        <div class="calc-result">
            <span class="plans-kicker" style="margin-bottom:0;">Recommended Plan</span>
            <h3 id="recommendedPlanName">Growth</h3>
            <p id="recommendedPlanReason">A plan recommendation will appear here based on your shop needs.</p>
            <div class="calc-meta">
                <div><span>Best for</span><strong id="recommendedPlanFit">Growing partner shops</strong></div>
                <div><span>Billing mode</span><strong id="recommendedPlanBilling">Monthly</strong></div>
                <div><span>Current price</span><strong id="recommendedPlanPrice">PHP 0.00</strong></div>
            </div>
            <div class="mt-3"><a href="partner_billing.php" class="plans-btn plans-btn-primary" id="recommendedPlanLink">Open This Plan</a></div>
            <div class="why-wrap">
                <button type="button" class="why-toggle" id="recommendedPlanWhyToggle" aria-expanded="false" aria-controls="recommendedPlanWhyBody"><span>Why this plan?</span><i class="fas fa-chevron-down"></i></button>
                <div class="why-body" id="recommendedPlanWhyBody" hidden>
                    <p id="recommendedPlanWhySummary">This recommendation updates as your staffing and support needs change.</p>
                    <ul>
                        <li id="recommendedPlanWhyReasonOne">Your selected inputs will shape the recommendation details here.</li>
                        <li id="recommendedPlanWhyReasonTwo">Feature needs like AI, visibility, and support can shift the best-fit tier.</li>
                        <li id="recommendedPlanWhyReasonThree">Billing mode changes will keep pricing and savings aligned with your choice.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="plans-grid">
<?php foreach ($plans as $plan): ?>
<?php
$planCode = strtolower(trim((string)($plan['plan_code'] ?? '')));
$isFeatured = $planCode === $featured_plan_code;
$monthlyPrice = (float)($plan['monthly_price'] ?? 0);
$annualPrice = (float)($plan['annual_price'] ?? 0);
$annualEquivalent = $annualPrice > 0 ? round($annualPrice / 12, 2) : 0;
$annualSavings = $annualPrice > 0 ? max(0, ($monthlyPrice * 12) - $annualPrice) : 0;
$requestType = $isFeatured ? 'upgrade' : 'change_plan';
$monthlyRequestHref = 'partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=monthly&request_type=' . urlencode($requestType);
$annualRequestHref = 'partner_billing.php?plan_id=' . (int)($plan['id'] ?? 0) . '&billing_cycle=annual&request_type=' . urlencode($requestType);
?>
<article class="plan-card <?php echo $isFeatured ? 'featured' : ''; ?>" data-plan-card data-plan-code="<?php echo htmlspecialchars($planCode); ?>" data-plan-name="<?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?>" data-monthly-price="<?php echo htmlspecialchars(number_format($monthlyPrice, 2, '.', '')); ?>" data-annual-price="<?php echo htmlspecialchars(number_format($annualPrice, 2, '.', '')); ?>" data-annual-equivalent="<?php echo htmlspecialchars(number_format($annualEquivalent, 2, '.', '')); ?>" data-annual-savings="<?php echo htmlspecialchars(number_format($annualSavings, 2, '.', '')); ?>" data-monthly-href="<?php echo htmlspecialchars($monthlyRequestHref); ?>" data-annual-href="<?php echo htmlspecialchars($annualRequestHref); ?>" data-max-staff="<?php echo (int)($plan['max_staff_accounts'] ?? 1); ?>" data-ai="<?php echo (int)($plan['includes_ai_automation'] ?? 0); ?>" data-priority="<?php echo (int)($plan['includes_priority_support'] ?? 0); ?>" data-featured-placement="<?php echo (int)($plan['includes_featured_placement'] ?? 0); ?>" data-custom-branding="<?php echo (int)($plan['includes_custom_branding'] ?? 0); ?>">
    <?php if ($isFeatured): ?><span class="plan-badge">Most Balanced</span><?php endif; ?>
    <span class="plan-live-badge" data-plan-live-badge hidden>Best Match</span>
    <h3><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></h3>
    <p><?php echo htmlspecialchars((string)($plan['description'] ?? '')); ?></p>
    <div class="plan-price" data-plan-price>PHP <?php echo number_format($monthlyPrice, 2); ?></div>
    <div class="plan-price-label" data-plan-price-label>per month</div>
    <div class="plan-note" data-plan-price-note><strong>Annual: PHP <?php echo number_format($annualPrice, 2); ?></strong><span><?php echo $annualSavings > 0 ? 'Save PHP ' . number_format($annualSavings, 2) . ' yearly' : 'PHP ' . number_format($annualEquivalent, 2) . ' monthly equivalent'; ?></span></div>
    <ul class="plan-features">
        <li><i class="fas fa-users"></i> Up to <?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?> staff accounts</li>
        <li><i class="fas fa-percent"></i> Included fee rate: <?php echo number_format((float)($plan['included_order_fee_percent'] ?? 0), 2); ?>%</li>
        <li><i class="fas fa-receipt"></i> Flat fee per order: PHP <?php echo number_format((float)($plan['included_order_fee_flat'] ?? 0), 2); ?></li>
        <li><i class="fas fa-robot"></i> AI automation <?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
        <li><i class="fas fa-headset"></i> Priority support <?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
        <li><i class="fas fa-bullhorn"></i> Featured placement <?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
        <li><i class="fas fa-palette"></i> Custom branding <?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'included' : 'not included'; ?></li>
    </ul>
    <div class="mt-3"><a href="<?php echo htmlspecialchars($monthlyRequestHref); ?>" class="plans-btn <?php echo $isFeatured ? 'plans-btn-primary' : 'plans-btn-secondary'; ?>" data-plan-request-link>Request This Plan</a></div>
</article>
<?php endforeach; ?>
</section>
<section class="plans-card">
    <h3 class="m-0" style="font-weight:800;color:#0f172a;">Feature Matrix</h3>
    <p class="plans-section-sub">Quick comparison to help your team choose the right subscription level before submitting a plan request.</p>
    <div class="matrix-wrap">
        <table class="matrix-table">
            <thead><tr><th>Feature</th><?php foreach ($plans as $plan): ?><th><?php echo htmlspecialchars((string)($plan['plan_name'] ?? 'Plan')); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <tr><td>Monthly price</td><?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['monthly_price'] ?? 0), 2); ?></td><?php endforeach; ?></tr>
                <tr><td>Annual price</td><?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['annual_price'] ?? 0), 2); ?></td><?php endforeach; ?></tr>
                <tr><td>Max staff accounts</td><?php foreach ($plans as $plan): ?><td><?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?></td><?php endforeach; ?></tr>
                <tr><td>Included fee %</td><?php foreach ($plans as $plan): ?><td><?php echo number_format((float)($plan['included_order_fee_percent'] ?? 0), 2); ?>%</td><?php endforeach; ?></tr>
                <tr><td>Flat fee per order</td><?php foreach ($plans as $plan): ?><td>PHP <?php echo number_format((float)($plan['included_order_fee_flat'] ?? 0), 2); ?></td><?php endforeach; ?></tr>
                <tr><td>AI automation</td><?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?></tr>
                <tr><td>Priority support</td><?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?></tr>
                <tr><td>Featured placement</td><?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?></tr>
                <tr><td>Custom branding</td><?php foreach ($plans as $plan): ?><td><?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td><?php endforeach; ?></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="plans-card">
    <h3 class="m-0" style="font-weight:800;color:#0f172a;">Partner Workflow</h3>
    <p class="plans-section-sub">Your team can now stay inside admin from comparison through subscription request.</p>
    <div class="workflow-grid">
        <div class="workflow-step"><strong>1. Compare plans</strong><span>Review the pricing and support tiers in this admin module.</span></div>
        <div class="workflow-step"><strong>2. Choose billing mode</strong><span>Switch between monthly and annual to see the live request route and savings.</span></div>
        <div class="workflow-step"><strong>3. Request a plan</strong><span>Click any plan card to prefill the billing request form in your partner billing center.</span></div>
        <div class="workflow-step"><strong>4. Platform owner reviews</strong><span>The system owner approves or rejects the request from the monetization queue.</span></div>
        <div class="workflow-step"><strong>5. Billing starts</strong><span>Once approved, invoices appear in your billing module for payment and tracking.</span></div>
    </div>
</section>

</div>
</div>
</div>
<script src="../js/bootstrap.bundle.min.js"></script>
<script>
(function(){
const cards=[...document.querySelectorAll('[data-plan-card]')];const buttons=[...document.querySelectorAll('.mode-btn')];const stickyButtons=[...document.querySelectorAll('.sticky-btn')];const staffRange=document.getElementById('planStaffRange');const staffValue=document.getElementById('planStaffValue');const needAi=document.getElementById('needAiAutomation');const needPriority=document.getElementById('needPrioritySupport');const needFeatured=document.getElementById('needFeaturedPlacement');const needBranding=document.getElementById('needCustomBranding');const stickyBillingModeText=document.getElementById('stickyBillingModeText');const stickyRecommendationText=document.getElementById('stickyRecommendationText');const stickySavingsChip=document.getElementById('stickySavingsChip');const stickyRequestLink=document.getElementById('stickyRequestLink');const recommendedPlanName=document.getElementById('recommendedPlanName');const recommendedPlanReason=document.getElementById('recommendedPlanReason');const recommendedPlanFit=document.getElementById('recommendedPlanFit');const recommendedPlanBilling=document.getElementById('recommendedPlanBilling');const recommendedPlanPrice=document.getElementById('recommendedPlanPrice');const recommendedPlanLink=document.getElementById('recommendedPlanLink');const whyToggle=document.getElementById('recommendedPlanWhyToggle');const whyBody=document.getElementById('recommendedPlanWhyBody');const whySummary=document.getElementById('recommendedPlanWhySummary');const whyOne=document.getElementById('recommendedPlanWhyReasonOne');const whyTwo=document.getElementById('recommendedPlanWhyReasonTwo');const whyThree=document.getElementById('recommendedPlanWhyReasonThree');let activeMode='monthly';
function peso(v){return 'PHP '+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function list(items){if(items.length<=1)return items[0]||'';if(items.length===2)return items[0]+' and '+items[1];return items.slice(0,-1).join(', ')+', and '+items[items.length-1];}
function meta(card){return{code:String(card.dataset.planCode||'').toLowerCase(),name:String(card.dataset.planName||'Plan'),monthlyPrice:Number(card.dataset.monthlyPrice||0),annualPrice:Number(card.dataset.annualPrice||0),monthlyHref:String(card.dataset.monthlyHref||'#'),annualHref:String(card.dataset.annualHref||'#'),maxStaff:Number(card.dataset.maxStaff||1)}}
function recommend(){const staff=Number(staffRange?staffRange.value:2);const byCode={};cards.forEach(card=>{byCode[String(card.dataset.planCode||'').toLowerCase()]=card;});if((staff>=10||needFeatured.checked||needBranding.checked)&&byCode.pro)return byCode.pro;if((staff>=3||needAi.checked||needPriority.checked)&&byCode.growth)return byCode.growth;if(byCode.starter)return byCode.starter;return cards[0]||null;}
function whyPlan(m,staff){const wants=[];if(needAi.checked)wants.push('AI automation');if(needPriority.checked)wants.push('priority support');if(needFeatured.checked)wants.push('featured placement');if(needBranding.checked)wants.push('custom branding');return{summary:wants.length?m.name+' fits your current setup because it covers a '+staff+'-staff team while staying aligned with your need for '+list(wants)+'.':m.name+' fits your current setup because it matches a '+staff+'-staff shop without forcing you to pay for extras you are not asking for yet.',reasons:[staff<=m.maxStaff?'Your selected team size of '+staff+' staff fits inside this plan\'s '+m.maxStaff+' staff-account allowance.':'You selected '+staff+' staff accounts, so the recommendation shifts upward to give your team more breathing room.',wants.length?'You turned on '+list(wants)+', and this plan is the first sensible tier that supports those priorities without overcomplicating the choice.':'Because you left premium needs turned off, the recommendation stays focused on the most efficient fit for your current stage.',m.code==='pro'?'Pro becomes the stronger match once visibility, branding control, and heavier support start affecting daily execution and partner revenue.':(m.code==='growth'?'Growth is usually the best middle ground when a shop needs stronger support and flexibility without jumping straight into the highest-priced tier.':'Starter keeps the system simple for newer or smaller partner shops while still leaving room to upgrade later.')]};}
function update(){const card=recommend();if(!card)return;const staff=Number(staffRange?staffRange.value:2);const m=meta(card);const price=activeMode==='annual'?m.annualPrice:m.monthlyPrice;const href=activeMode==='annual'?m.annualHref:m.monthlyHref;const savings=Math.max(0,m.monthlyPrice*12-m.annualPrice);if(staffValue)staffValue.textContent=staff+(staff===1?' staff':' staff');recommendedPlanName.textContent=m.name;recommendedPlanReason.textContent=m.code==='pro'?'This plan fits larger partner operations that want visibility boosts, branding control, and heavier day-to-day support.':(m.code==='growth'?'This is the balanced choice for teams that need more staff access, stronger support, or AI assistance as they scale.':'This plan keeps subscription costs light while covering the essentials for a smaller shop team.');recommendedPlanFit.textContent=m.code==='pro'?'High-volume stores':(m.code==='growth'?'Growing partner shops':'Lean partner shops');recommendedPlanBilling.textContent=activeMode==='annual'?'Annual':'Monthly';recommendedPlanPrice.textContent=peso(price);recommendedPlanLink.href=href;stickyRequestLink.href=href;stickyRequestLink.textContent=m.code==='starter'?'Start with '+m.name:'Request '+m.name;stickyRecommendationText.textContent=m.name+' is the current best match for a shop with '+staff+' staff and your selected support needs.';stickySavingsChip.textContent=(activeMode==='annual'?'Estimated yearly savings: ':'Annual savings available: ')+peso(savings);const why=whyPlan(m,staff);whySummary.textContent=why.summary;whyOne.textContent=why.reasons[0]||'';whyTwo.textContent=why.reasons[1]||'';whyThree.textContent=why.reasons[2]||'';cards.forEach(c=>{const active=c===card;c.classList.toggle('is-recommended',active);const badge=c.querySelector('[data-plan-live-badge]');if(badge)badge.hidden=!active;});}
function apply(mode){activeMode=mode==='annual'?'annual':'monthly';buttons.forEach(b=>b.classList.toggle('active',b.dataset.billingMode===activeMode));stickyButtons.forEach(b=>b.classList.toggle('active',b.dataset.billingMode===activeMode));stickyBillingModeText.textContent=activeMode==='annual'?'Annual billing selected':'Monthly billing selected';cards.forEach(card=>{const price=card.querySelector('[data-plan-price]');const label=card.querySelector('[data-plan-price-label]');const note=card.querySelector('[data-plan-price-note]');const link=card.querySelector('[data-plan-request-link]');const monthlyPrice=Number(card.dataset.monthlyPrice||0);const annualPrice=Number(card.dataset.annualPrice||0);const annualEquivalent=Number(card.dataset.annualEquivalent||0);const annualSavings=Number(card.dataset.annualSavings||0);if(activeMode==='annual'){if(price)price.textContent=peso(annualPrice);if(label)label.textContent='per year';if(note)note.innerHTML='<strong>Monthly equivalent: '+peso(annualEquivalent)+'</strong><span>'+(annualSavings>0?'Save '+peso(annualSavings)+' yearly':'Annual billing active')+'</span>';if(link)link.href=card.dataset.annualHref||'#';}else{if(price)price.textContent=peso(monthlyPrice);if(label)label.textContent='per month';if(note)note.innerHTML='<strong>Annual: '+peso(annualPrice)+'</strong><span>'+(annualSavings>0?'Save '+peso(annualSavings)+' yearly':peso(annualEquivalent)+' monthly equivalent')+'</span>';if(link)link.href=card.dataset.monthlyHref||'#';}});update();}
buttons.concat(stickyButtons).forEach(btn=>btn.addEventListener('click',function(){apply(this.dataset.billingMode||'monthly');}));[staffRange,needAi,needPriority,needFeatured,needBranding].forEach(control=>{if(!control)return;control.addEventListener('input',update);control.addEventListener('change',update);});if(whyToggle&&whyBody){whyToggle.addEventListener('click',function(){const expanded=this.getAttribute('aria-expanded')==='true';this.setAttribute('aria-expanded',expanded?'false':'true');whyBody.hidden=expanded;});}apply('monthly');})();
</script>
</body>
</html>
