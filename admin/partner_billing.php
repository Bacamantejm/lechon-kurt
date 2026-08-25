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
    denyAdminAccess('Access denied: This billing page is only available to approved business partner shops.');
}
if (!$has_partner_billing_access) {
    denyAdminAccess('Access denied: Your assigned role does not include partner billing access.');
}

$monetizationService = new PlatformMonetizationService($conn);
$monetizationService->ensureReady($current_user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: partner_billing.php');
        exit;
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lechonsystem';

    if ($action === 'start_invoice_payment') {
        $result = $monetizationService->createInvoicePaymentSession(
            $invoice_id,
            (int)$seller_scope_id,
            $base_url,
            (string)($_POST['payment_method'] ?? 'gcash'),
            $current_user_id
        );
        if (!empty($result['success']) && !empty($result['checkout_url'])) {
            header('Location: ' . $result['checkout_url']);
            exit;
        }
        $_SESSION['error'] = (string)($result['message'] ?? 'Unable to start the invoice payment.');
    } elseif ($action === 'check_invoice_payment') {
        $result = $monetizationService->completeInvoicePayment($invoice_id, (int)$seller_scope_id, null, $current_user_id);
        if (!empty($result['success'])) {
            $_SESSION['success'] = (string)($result['message'] ?? 'Invoice payment confirmed successfully.');
        } else {
            $_SESSION['error'] = (string)($result['message'] ?? 'We could not confirm the invoice payment yet.');
        }
    } elseif ($action === 'submit_subscription_request') {
        $result = $monetizationService->submitSubscriptionRequest(
            (int)$seller_scope_id,
            (int)($_POST['plan_id'] ?? 0),
            (string)($_POST['billing_cycle'] ?? 'monthly'),
            (string)($_POST['request_type'] ?? 'change_plan'),
            (string)($_POST['partner_notes'] ?? ''),
            $current_user_id
        );
        if ($result) {
            $_SESSION['success'] = 'Your subscription request has been submitted to the platform owner.';
        } else {
            $_SESSION['error'] = 'Unable to submit the subscription request right now. You may already have an active subscription with these exact parameters.';
        }
    } elseif ($action === 'cancel_subscription_request') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $result = $monetizationService->cancelSubscriptionRequest($request_id, (int)$seller_scope_id, $current_user_id);
        if ($result) {
            $_SESSION['success'] = 'Subscription request has been cancelled.';
        } else {
            $_SESSION['error'] = 'Unable to cancel this request. It may have already been reviewed.';
        }
    }

    header('Location: partner_billing.php');
    exit;
}

$portal = $monetizationService->getPartnerBillingPortalData((int)$seller_scope_id);

if (empty($portal)) {
    $_SESSION['error'] = 'Billing data is not available for this partner account yet.';
    header('Location: index.php');
    exit;
}

$partner = $portal['partner'] ?? [];
$subscription = $portal['subscription'] ?? null;
$feeRule = $portal['fee_rule'] ?? ['fee_percent' => 0, 'fee_flat_per_order' => 0];
$monthSummary = $portal['month_summary'] ?? [];
$invoices = $portal['invoices'] ?? [];
$availablePlans = $portal['available_plans'] ?? [];
$subscriptionRequests = $portal['subscription_requests'] ?? [];
$metrics = $portal['metrics'] ?? [];
$paymongoStatus = $monetizationService->getPayMongoStatusSummary();
$paymongoAvailable = !empty($paymongoStatus['available']);
$billingReminders = $portal['reminders'] ?? [];
$billingTimeline = $portal['timeline'] ?? [];
$requested_plan_id = (int)($_GET['plan_id'] ?? 0);
$requested_billing_cycle = strtolower(trim((string)($_GET['billing_cycle'] ?? 'monthly')));
$requested_request_type = strtolower(trim((string)($_GET['request_type'] ?? 'change_plan')));
$valid_plan_ids = array_map(static function ($plan) {
    return (int)($plan['id'] ?? 0);
}, $availablePlans);
if (!in_array($requested_plan_id, $valid_plan_ids, true)) {
    $requested_plan_id = 0;
}
if (!in_array($requested_billing_cycle, ['monthly', 'annual'], true)) {
    $requested_billing_cycle = 'monthly';
}
if (!in_array($requested_request_type, ['new', 'renew', 'upgrade', 'downgrade', 'change_plan'], true)) {
    $requested_request_type = 'change_plan';
}
$has_prefill_request = $requested_plan_id > 0;

$invoiceLabels = [];
$invoiceTotals = [];
$paidTotals = [];
$statusCounts = ['Paid' => 0, 'Issued' => 0, 'Overdue' => 0, 'Draft' => 0, 'Void' => 0];
$invoiceMonthMap = [];
foreach ($invoices as $invoice) {
    $statusKey = ucfirst((string)($invoice['invoice_status'] ?? 'issued'));
    if (!isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey] = 0;
    }
    $statusCounts[$statusKey]++;
    $monthKey = date('Y-m', strtotime((string)($invoice['period_start'] ?? 'now')));
    if (!isset($invoiceMonthMap[$monthKey])) {
        $invoiceMonthMap[$monthKey] = ['label' => date('M Y', strtotime($monthKey . '-01')), 'total' => 0.0, 'paid' => 0.0];
    }
    $invoiceMonthMap[$monthKey]['total'] += (float)($invoice['total_amount'] ?? 0);
    if (($invoice['invoice_status'] ?? '') === 'paid') {
        $invoiceMonthMap[$monthKey]['paid'] += (float)($invoice['total_amount'] ?? 0);
    }
}
ksort($invoiceMonthMap);
foreach ($invoiceMonthMap as $monthRow) {
    $invoiceLabels[] = $monthRow['label'];
    $invoiceTotals[] = round((float)$monthRow['total'], 2);
    $paidTotals[] = round((float)$monthRow['paid'], 2);
}
$openInvoices = array_values(array_filter($invoices, static function ($invoice) {
    $status = strtolower(trim((string)($invoice['invoice_status'] ?? 'issued')));
    return in_array($status, ['issued', 'overdue', 'draft'], true);
}));
usort($openInvoices, static function ($left, $right) {
    $leftDue = strtotime((string)($left['due_at'] ?? '9999-12-31'));
    $rightDue = strtotime((string)($right['due_at'] ?? '9999-12-31'));
    return $leftDue <=> $rightDue;
});
$priorityInvoice = $openInvoices[0] ?? null;
$paymentMethodLabels = [
    'gcash' => 'GCash',
    'paymaya' => 'PayMaya',
    'card' => 'Card'
];

$todayTs = strtotime(date('Y-m-d'));
$overdueInvoices = [];
$dueSoonInvoices = [];
$issuedInvoices = [];
foreach ($openInvoices as $invoice) {
    $status = strtolower(trim((string)($invoice['invoice_status'] ?? 'issued')));
    $dueTs = !empty($invoice['due_at']) ? strtotime((string)$invoice['due_at']) : null;
    if ($status === 'overdue') {
        $overdueInvoices[] = $invoice;
        continue;
    }
    if ($dueTs !== null) {
        $daysUntilDue = (int)floor(($dueTs - $todayTs) / 86400);
        if ($daysUntilDue <= 3) {
            $dueSoonInvoices[] = $invoice;
            continue;
        }
    }
    $issuedInvoices[] = $invoice;
}

$renewalLabel = 'Renewal not scheduled yet';
$renewalHeadline = 'No renewal date available';
$renewalDetail = 'The platform owner still needs to assign or confirm the active subscription renewal date for this shop.';
$renewalClass = 'muted';
$renewalDays = null;
if (!empty($subscription['renews_at'])) {
    $renewalDate = (string)$subscription['renews_at'];
    $renewalTs = strtotime($renewalDate . ' 23:59:59');
    $renewalDays = (int)floor(($renewalTs - time()) / 86400);
    $renewalLabel = ((string)($subscription['subscription_status'] ?? '') === 'trial') ? 'Trial countdown' : 'Next renewal countdown';
    if ($renewalDays > 1) {
        $renewalHeadline = $renewalDays . ' days remaining';
        $renewalDetail = 'Your current subscription renews on ' . date('M d, Y', strtotime($renewalDate)) . '.';
        $renewalClass = $renewalDays <= 7 ? 'warning' : 'success';
    } elseif ($renewalDays === 1) {
        $renewalHeadline = '1 day remaining';
        $renewalDetail = 'Your current subscription renews tomorrow, ' . date('M d, Y', strtotime($renewalDate)) . '.';
        $renewalClass = 'warning';
    } elseif ($renewalDays === 0) {
        $renewalHeadline = 'Renews today';
        $renewalDetail = 'Your current subscription renewal date is today, ' . date('M d, Y', strtotime($renewalDate)) . '.';
        $renewalClass = 'danger';
    } else {
        $renewalHeadline = 'Renewal date passed';
        $renewalDetail = 'The recorded renewal date was ' . date('M d, Y', strtotime($renewalDate)) . '. Review the invoice status or contact the platform owner.';
        $renewalClass = 'danger';
    }
}

$latestReminder = $billingReminders[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Billing - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .billing-shell { padding: 24px; }
        .billing-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
            margin-bottom: 18px;
        }
        .billing-title { margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a; }
        .billing-sub { margin-top: 6px; color: #64748b; font-size: .92rem; }
        .billing-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        .billing-metric {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }
        .billing-label { display: block; color: #64748b; font-size: .82rem; margin-bottom: 4px; }
        .billing-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }
        .billing-table-wrap { overflow-x: auto; }
        .billing-table { width: 100%; border-collapse: collapse; min-width: 880px; }
        .billing-table th, .billing-table td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; font-size: .9rem; vertical-align: top; }
        .billing-table th { background: #f8fafc; color: #334155; font-weight: 700; }
        .billing-chip { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
        .billing-chip.success { background: #dcfce7; color: #166534; }
        .billing-chip.warning { background: #fef3c7; color: #92400e; }
        .billing-chip.danger { background: #fee2e2; color: #991b1b; }
        .billing-chip.muted { background: #e5e7eb; color: #374151; }
        .billing-workflow { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .billing-step { border: 1px solid #e2e8f0; border-radius: 16px; padding: 14px; background: linear-gradient(135deg, #fff 0%, #f8fafc 100%); }
        .billing-step strong { display: block; margin-bottom: 8px; color: #0f172a; }
        .billing-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .billing-actions form { display: inline-flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0; }
        .billing-actions .form-select { min-width: 120px; }
        .billing-hero {
            background:
                radial-gradient(circle at top right, rgba(22, 163, 74, 0.12), transparent 30%),
                radial-gradient(circle at left bottom, rgba(59, 130, 246, 0.10), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }
        .billing-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(280px, .9fr);
            gap: 16px;
            align-items: start;
        }
        .billing-kicker {
            display: inline-flex;
            font-size: .78rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #b91c1c;
            font-weight: 800;
            margin-bottom: 10px;
        }
        .billing-hero-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 800;
            color: #0f172a;
        }
        .billing-hero-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .billing-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 700;
        }
        .billing-pill.success { background: #dcfce7; color: #166534; }
        .billing-pill.warning { background: #fef3c7; color: #92400e; }
        .billing-pill.danger { background: #fee2e2; color: #991b1b; }
        .billing-side-stack { display: grid; gap: 12px; }
        .billing-side-box {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            background: rgba(255,255,255,.92);
        }
        .billing-side-box strong { display: block; color: #0f172a; margin-bottom: 6px; }
        .billing-side-box span { color: #64748b; font-size: .9rem; }
        .billing-focus-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(260px, .9fr);
            gap: 16px;
        }
        .billing-focus-box {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
        }
        .billing-focus-amount {
            font-size: 1.6rem;
            font-weight: 800;
            color: #0f172a;
            margin: 6px 0 10px;
        }
        .billing-step-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
        }
        .billing-pay-step {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px;
            background: #fff;
        }
        .billing-pay-step strong { display: block; margin-bottom: 8px; color: #0f172a; }
        .billing-method-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .billing-method-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .82rem;
            font-weight: 700;
        }
        .billing-signal-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr);
            gap: 16px;
        }
        .billing-reminder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .billing-reminder-box,
        .billing-renewal-box {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
        }
        .billing-reminder-box strong,
        .billing-renewal-box strong {
            display: block;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .billing-reminder-count {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .billing-renewal-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #0f172a;
            margin: 6px 0 10px;
        }
        .billing-reminder-note {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            color: #475569;
            font-size: .9rem;
        }
        .billing-timeline {
            position: relative;
            margin-top: 8px;
        }
        .billing-timeline::before {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            left: 16px;
            width: 2px;
            background: linear-gradient(180deg, #e2e8f0 0%, #cbd5e1 100%);
        }
        .billing-timeline-item {
            position: relative;
            padding-left: 48px;
            margin-bottom: 18px;
        }
        .billing-timeline-dot {
            position: absolute;
            left: 8px;
            top: 6px;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            border: 3px solid #fff;
            box-shadow: 0 0 0 1px #cbd5e1;
            background: #94a3b8;
        }
        .billing-timeline-dot.success { background: #16a34a; }
        .billing-timeline-dot.warning { background: #f59e0b; }
        .billing-timeline-dot.danger { background: #dc2626; }
        .billing-timeline-dot.muted { background: #64748b; }
        .billing-timeline-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 16px;
            background: #fff;
        }
        .billing-timeline-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }
        .billing-timeline-title {
            font-weight: 700;
            color: #0f172a;
        }
        .billing-timeline-time {
            color: #64748b;
            font-size: .84rem;
        }
        @media (max-width: 992px) {
            .billing-hero-grid,
            .billing-focus-grid,
            .billing-signal-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>Partner Billing</h1>
                    <div class="topbar-right">
                        <div class="admin-profile">
                            <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="billing-shell">
                <div class="billing-card billing-hero">
                    <div class="billing-hero-grid">
                        <div>
                            <span class="billing-kicker">Partner Subscription Billing</span>
                            <h2 class="billing-hero-title">Pay your monthly platform subscription from the same dashboard you use to run your shop.</h2>
                            <p class="billing-sub">Your business partner subscription is billed through invoices issued by the platform owner. Once an invoice is available, you can pay it online through PayMongo and the payment will be reflected back to the platform owner billing records.</p>
                            <div class="billing-hero-actions">
                                <a href="subscription_plans.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-layer-group"></i> Compare Plans
                                </a>
                                <a href="receipt_settings.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-receipt"></i> Receipt Settings
                                </a>
                            </div>
                            <div class="billing-method-chips">
                                <?php foreach ($paymentMethodLabels as $methodCode => $methodLabel): ?>
                                    <span class="billing-method-chip"><i class="fas fa-wallet"></i> <?php echo htmlspecialchars($methodLabel); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="billing-side-stack">
                            <div class="billing-side-box">
                                <strong>Payment routing</strong>
                                <span>Partner shop -> PayMongo checkout -> payment verification -> invoice marked paid -> visible to super admin monetization records.</span>
                            </div>
                            <div class="billing-side-box">
                                <strong>Online payment status</strong>
                                <?php
                                    $modeClass = 'warning';
                                    if (($paymongoStatus['mode'] ?? '') === 'live') {
                                        $modeClass = 'success';
                                    } elseif (($paymongoStatus['mode'] ?? '') === 'unavailable') {
                                        $modeClass = 'danger';
                                    }
                                ?>
                                <span class="billing-pill <?php echo $modeClass; ?>">
                                    <i class="fas fa-bolt"></i>
                                    <?php echo htmlspecialchars(strtoupper((string)($paymongoStatus['mode'] ?? 'unavailable'))); ?> PAYMONGO
                                </span>
                                <div class="billing-sub" style="margin-top:10px;">
                                    <?php if (!empty($paymongoStatus['using_fallback_test_keys'])): ?>
                                        Sandbox or fallback test keys are active. This is safe for testing but should be switched to live keys before production collection.
                                    <?php elseif (($paymongoStatus['mode'] ?? '') === 'live'): ?>
                                        Live credentials are active, so partner payments can be collected as real online subscription payments.
                                    <?php else: ?>
                                        Online payment is not fully configured yet. Ask the platform owner to complete PayMongo setup before collecting real payments.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">How Monthly Subscription Payment Works</h3>
                    <p class="billing-sub">This is the clearest partner payment workflow for your system owner and your business partners.</p>
                    <div class="billing-step-list">
                        <div class="billing-pay-step"><strong>1. Invoice is issued</strong><span>The platform owner generates your monthly or annual billing invoice inside the monetization module.</span></div>
                        <div class="billing-pay-step"><strong>2. Partner opens billing</strong><span>Your shop sees the issued invoice here together with due date, amount, and payment actions.</span></div>
                        <div class="billing-pay-step"><strong>3. Pay via PayMongo</strong><span>Choose GCash, PayMaya, or Card, then continue to the secure PayMongo checkout page.</span></div>
                        <div class="billing-pay-step"><strong>4. System verifies payment</strong><span>After checkout, the platform verifies the payment session and updates your invoice status.</span></div>
                        <div class="billing-pay-step"><strong>5. Super admin receives it</strong><span>The paid invoice becomes part of the owner's collected revenue and billing records automatically.</span></div>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Current Billing Action</h3>
                    <p class="billing-sub">This panel helps partners know exactly what to pay next instead of searching through the whole ledger.</p>
                    <div class="billing-focus-grid">
                        <div class="billing-focus-box">
                            <?php if ($priorityInvoice): ?>
                                <?php
                                    $priorityStatus = strtolower(trim((string)($priorityInvoice['invoice_status'] ?? 'issued')));
                                    $priorityClass = $priorityStatus === 'overdue' ? 'danger' : ($priorityStatus === 'draft' ? 'warning' : 'success');
                                ?>
                                <span class="billing-pill <?php echo $priorityClass; ?>">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <?php echo htmlspecialchars(strtoupper((string)($priorityInvoice['invoice_status'] ?? 'issued'))); ?>
                                </span>
                                <div class="billing-focus-amount"><?php echo 'PHP ' . number_format((float)($priorityInvoice['total_amount'] ?? 0), 2); ?></div>
                                <div class="billing-sub">Invoice: <?php echo htmlspecialchars((string)($priorityInvoice['invoice_number'] ?? '')); ?></div>
                                <div class="billing-sub">Due: <?php echo !empty($priorityInvoice['due_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$priorityInvoice['due_at']))) : '-'; ?></div>
                                <div class="billing-sub">This invoice combines your subscription fee and platform order-fee charges for the covered period.</div>
                            <?php else: ?>
                                <span class="billing-pill success"><i class="fas fa-check-circle"></i> NOTHING DUE RIGHT NOW</span>
                                <div class="billing-focus-amount">All clear</div>
                                <div class="billing-sub">There is no currently payable invoice in your billing ledger. Your next monthly subscription invoice will appear here once generated by the platform owner.</div>
                            <?php endif; ?>
                        </div>
                        <div class="billing-focus-box">
                            <?php if ($priorityInvoice): ?>
                                <strong style="display:block;margin-bottom:12px;color:#0f172a;">Pay this invoice online</strong>
                                <form method="post" class="d-grid gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="start_invoice_payment">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$priorityInvoice['id']; ?>">
                                    <label class="form-label mb-0">Choose payment channel</label>
                                    <select name="payment_method" class="form-select" <?php echo $paymongoAvailable ? '' : 'disabled'; ?>>
                                        <option value="gcash">GCash</option>
                                        <option value="paymaya">PayMaya</option>
                                        <option value="card">Card</option>
                                    </select>
                                    <button type="submit" class="btn btn-success" <?php echo $paymongoAvailable ? '' : 'disabled'; ?>>
                                        <i class="fas fa-credit-card"></i> Continue to PayMongo Checkout
                                    </button>
                                </form>
                                <?php if (!$paymongoAvailable): ?>
                                    <div class="billing-sub mt-2">PayMongo is not configured yet for this server, so online collection is temporarily unavailable. Ask the platform owner to finish PayMongo setup before paying this invoice online.</div>
                                <?php endif; ?>
                                <form method="post" class="d-grid gap-2 mt-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="check_invoice_payment">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$priorityInvoice['id']; ?>">
                                    <button type="submit" class="btn btn-outline-dark">
                                        <i class="fas fa-rotate"></i> Verify PayMongo Payment
                                    </button>
                                </form>
                            <?php else: ?>
                                <strong style="display:block;margin-bottom:12px;color:#0f172a;">No payment action needed</strong>
                                <div class="billing-sub">Your partner billing page will still keep tracking plan requests, payment history, and invoice status even when there is no active balance due.</div>
                                <div class="mt-3">
                                    <a href="subscription_plans.php" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-layer-group"></i> Review Plans
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="billing-signal-grid">
                    <div class="billing-card">
                        <h3 class="billing-title">Billing Reminder Center</h3>
                        <p class="billing-sub">This section automatically highlights what needs attention on your monthly billing before it turns into a bigger problem.</p>
                        <div class="billing-reminder-grid">
                            <div class="billing-reminder-box">
                                <strong>Overdue invoices</strong>
                                <div class="billing-reminder-count"><?php echo count($overdueInvoices); ?></div>
                                <div class="billing-sub">These should be settled first because they are already past the due date.</div>
                            </div>
                            <div class="billing-reminder-box">
                                <strong>Due within 3 days</strong>
                                <div class="billing-reminder-count"><?php echo count($dueSoonInvoices); ?></div>
                                <div class="billing-sub">These are approaching deadline and should be paid before they become overdue.</div>
                            </div>
                            <div class="billing-reminder-box">
                                <strong>Open billing items</strong>
                                <div class="billing-reminder-count"><?php echo count($issuedInvoices) + count($dueSoonInvoices) + count($overdueInvoices); ?></div>
                                <div class="billing-sub">This includes issued, draft, due soon, and overdue invoices visible in your ledger.</div>
                            </div>
                        </div>
                        <div class="billing-reminder-note">
                            <?php if ($latestReminder): ?>
                                <strong style="display:block;margin-bottom:4px;color:#0f172a;">Latest reminder sent</strong>
                                <?php echo htmlspecialchars((string)($latestReminder['subject'] ?? 'Billing reminder')); ?> on <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string)($latestReminder['sent_at'] ?? 'now')))); ?>.
                            <?php elseif (!empty($overdueInvoices)): ?>
                                No reminder log is visible yet, but your shop already has overdue billing and should settle it as soon as possible.
                            <?php elseif (!empty($dueSoonInvoices)): ?>
                                Your next billing reminder is effectively the due date itself, so paying early will keep the account clean.
                            <?php else: ?>
                                Your reminder center is clear right now. Keep checking this page each billing cycle for due and overdue signals.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="billing-card">
                        <h3 class="billing-title"><?php echo htmlspecialchars($renewalLabel); ?></h3>
                        <p class="billing-sub">This helps partners know how close the current plan is to renewal or trial end.</p>
                        <div class="billing-renewal-box">
                            <span class="billing-pill <?php echo htmlspecialchars($renewalClass); ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo !empty($subscription['subscription_status']) ? htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$subscription['subscription_status']))) : 'UNASSIGNED'; ?>
                            </span>
                            <div class="billing-renewal-value"><?php echo htmlspecialchars($renewalHeadline); ?></div>
                            <div class="billing-sub"><?php echo htmlspecialchars($renewalDetail); ?></div>
                            <?php if (!empty($subscription['plan_name'])): ?>
                                <div class="billing-reminder-note">
                                    Current plan: <strong><?php echo htmlspecialchars((string)$subscription['plan_name']); ?></strong><br>
                                    Billing cycle: <?php echo htmlspecialchars(ucfirst((string)($subscription['billing_cycle'] ?? 'monthly'))); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="billing-card">
                    <h2 class="billing-title">Plan and Billing Overview</h2>
                    <p class="billing-sub">See your current subscription, fee rates, next renewal timing, and what your shop contributes to the platform.</p>
                    <div class="billing-actions" style="margin-bottom: 14px;">
                        <a href="subscription_plans.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-layer-group"></i> Compare Plans
                        </a>
                        <a href="receipt_settings.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-receipt"></i> Receipt Settings
                        </a>
                    </div>
                    <div class="billing-metrics">
                        <div class="billing-metric"><span class="billing-label">Business</span><div class="billing-value"><?php echo htmlspecialchars((string)($partner['business_name'] ?? 'Partner Shop')); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Current Plan</span><div class="billing-value"><?php echo htmlspecialchars((string)($subscription['plan_name'] ?? 'Unassigned')); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Subscription Status</span><div class="billing-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($subscription['subscription_status'] ?? 'unassigned')))); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Next Renewal</span><div class="billing-value"><?php echo htmlspecialchars(!empty($subscription['renews_at']) ? date('M d, Y', strtotime((string)$subscription['renews_at'])) : '-'); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Platform Fee Rate</span><div class="billing-value"><?php echo htmlspecialchars(number_format((float)($feeRule['fee_percent'] ?? 0), 2) . '%'); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Flat Fee / Order</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($feeRule['fee_flat_per_order'] ?? 0), 2); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">This Month Gross Sales</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($monthSummary['gross_sales'] ?? 0), 2); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">This Month Platform Contribution</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($monthSummary['total_amount'] ?? 0), 2); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Paid Invoices</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($metrics['paid_total'] ?? 0), 2); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Current Due</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($metrics['due_total'] ?? 0), 2); ?></div></div>
                        <div class="billing-metric"><span class="billing-label">Overdue</span><div class="billing-value"><?php echo 'PHP ' . number_format((float)($metrics['overdue_total'] ?? 0), 2); ?></div></div>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">How Subscription Works</h3>
                    <p class="billing-sub">This is the partner workflow for joining and staying subscribed to your platform plan.</p>
                    <div class="billing-workflow">
                        <div class="billing-step"><strong>1. Choose a plan</strong><span>Pick the monthly or annual plan that fits your shop's size and support needs.</span></div>
                        <div class="billing-step"><strong>2. Submit request</strong><span>Send your subscription, upgrade, downgrade, or renewal request from this page.</span></div>
                        <div class="billing-step"><strong>3. Owner review</strong><span>The platform owner reviews and approves or rejects the request.</span></div>
                        <div class="billing-step"><strong>4. Billing starts</strong><span>Once approved, your plan activates and billing invoices appear in your invoice history.</span></div>
                        <div class="billing-step"><strong>5. Pay invoices</strong><span>Use the invoice actions below to pay online, download a PDF, or print your records.</span></div>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Subscription Request Center</h3>
                    <p class="billing-sub">Apply as a new subscriber, renew your current plan, or request a plan change directly from your shop billing dashboard.</p>
                    <?php if ($has_prefill_request): ?>
                        <div class="alert alert-info" style="border-radius:14px;">
                            The plan request form was prefilled from the Subscription Plans page. Review it, adjust anything you want, then submit.
                        </div>
                    <?php endif; ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="submit_subscription_request">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Request Type</label>
                            <select name="request_type" class="form-select" required>
                                <option value="new" <?php echo $requested_request_type === 'new' ? 'selected' : ''; ?>>New Subscription</option>
                                <option value="renew" <?php echo $requested_request_type === 'renew' ? 'selected' : ''; ?>>Renew Current Plan</option>
                                <option value="upgrade" <?php echo $requested_request_type === 'upgrade' ? 'selected' : ''; ?>>Upgrade Plan</option>
                                <option value="downgrade" <?php echo $requested_request_type === 'downgrade' ? 'selected' : ''; ?>>Downgrade Plan</option>
                                <option value="change_plan" <?php echo $requested_request_type === 'change_plan' ? 'selected' : ''; ?>>Change Plan</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Plan</label>
                            <select name="plan_id" class="form-select" required>
                                <option value="">Select plan</option>
                                <?php foreach ($availablePlans as $plan): ?>
                                    <option value="<?php echo (int)$plan['id']; ?>" <?php echo $requested_plan_id === (int)$plan['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$plan['plan_name'] . ' - PHP ' . number_format((float)($plan['monthly_price'] ?? 0), 2) . '/mo'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Billing Cycle</label>
                            <select name="billing_cycle" class="form-select" required>
                                <option value="monthly" <?php echo $requested_billing_cycle === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="annual" <?php echo $requested_billing_cycle === 'annual' ? 'selected' : ''; ?>>Annual</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="partner_notes" class="form-control" rows="3" placeholder="Optional note for the platform owner, such as why you want to upgrade or renew."></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Subscription Request</button>
                        </div>
                    </form>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Subscription Request History</h3>
                    <p class="billing-sub">Track plan requests you have sent to the platform owner and see their latest status.</p>
                    <div class="billing-table-wrap">
                        <table class="billing-table">
                            <thead><tr><th>Requested Plan</th><th>Type</th><th>Cycle</th><th>Status</th><th>Requested</th><th>Review Notes</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php if (empty($subscriptionRequests)): ?>
                                <tr><td colspan="7" style="text-align:center;color:#64748b;">No subscription requests submitted yet.</td></tr>
                            <?php else: foreach ($subscriptionRequests as $request): ?>
                                <?php
                                    $requestStatus = (string)($request['request_status'] ?? 'pending');
                                    $requestClass = 'warning';
                                    if ($requestStatus === 'approved') $requestClass = 'success';
                                    elseif ($requestStatus === 'rejected') $requestClass = 'danger';
                                    elseif ($requestStatus === 'cancelled') $requestClass = 'muted';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string)($request['requested_plan_name'] ?? 'Selected Plan')); ?></strong><br><span style="color:#64748b;font-size:.82rem;"><?php echo htmlspecialchars((string)($request['current_plan_name'] ?? 'Current: Unassigned')); ?></span></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($request['request_type'] ?? 'change_plan')))); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst((string)($request['requested_billing_cycle'] ?? 'monthly'))); ?></td>
                                    <td><span class="billing-chip <?php echo $requestClass; ?>"><?php echo htmlspecialchars(ucfirst($requestStatus)); ?></span></td>
                                    <td><?php echo !empty($request['created_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$request['created_at']))) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars(trim((string)($request['review_notes'] ?? '')) !== '' ? (string)$request['review_notes'] : ((string)($request['partner_notes'] ?? '') !== '' ? 'Partner note: ' . (string)$request['partner_notes'] : '-')); ?></td>
                                    <td>
                                        <?php if ($requestStatus === 'pending'): ?>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to cancel this plan request?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="cancel_subscription_request">
                                            <input type="hidden" name="request_id" value="<?php echo (int)($request['id'] ?? 0); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="padding: 2px 8px; font-size: 0.78rem; border-radius: 6px;">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-size:.82rem;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Billing Performance</h3>
                    <p class="billing-sub">Monthly invoice totals and what has already been marked paid by the platform owner.</p>
                    <div class="row g-3">
                        <div class="col-12 col-xl-8">
                            <div style="height:320px;"><canvas id="partnerBillingTrendChart"></canvas></div>
                        </div>
                        <div class="col-12 col-xl-4">
                            <div style="height:320px;"><canvas id="partnerBillingStatusChart"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Subscription Payment Timeline</h3>
                    <p class="billing-sub">Follow your shop's billing story in order, including issued invoices, reminders, checkout activity, confirmed payments, and upcoming renewal dates.</p>
                    <div class="billing-timeline">
                        <?php if (empty($billingTimeline)): ?>
                            <div class="billing-timeline-item">
                                <span class="billing-timeline-dot muted"></span>
                                <div class="billing-timeline-card">
                                    <div class="billing-timeline-title">No payment history yet</div>
                                    <div class="billing-sub">Timeline events will appear here once your shop has invoices, reminders, payment attempts, or subscription renewals.</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($billingTimeline as $timelineItem): ?>
                                <?php
                                    $timelineStatus = strtolower(trim((string)($timelineItem['status'] ?? 'muted')));
                                    $timelineClass = 'muted';
                                    if (in_array($timelineStatus, ['paid', 'active', 'trial', 'sent', 'success'], true)) {
                                        $timelineClass = 'success';
                                    } elseif (in_array($timelineStatus, ['issued', 'draft', 'pending', 'partial', 'past_due', 'warning'], true)) {
                                        $timelineClass = 'warning';
                                    } elseif (in_array($timelineStatus, ['overdue', 'failed', 'cancelled', 'expired', 'danger'], true)) {
                                        $timelineClass = 'danger';
                                    }
                                ?>
                                <div class="billing-timeline-item">
                                    <span class="billing-timeline-dot <?php echo $timelineClass; ?>"></span>
                                    <div class="billing-timeline-card">
                                        <div class="billing-timeline-top">
                                            <div class="billing-timeline-title"><?php echo htmlspecialchars((string)($timelineItem['title'] ?? 'Billing event')); ?></div>
                                            <div class="billing-timeline-time"><?php echo !empty($timelineItem['event_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$timelineItem['event_at']))) : '-'; ?></div>
                                        </div>
                                        <div class="billing-sub"><?php echo htmlspecialchars((string)($timelineItem['detail'] ?? '')); ?></div>
                                        <div class="billing-actions" style="margin-top:10px;">
                                            <?php if (!empty($timelineItem['invoice_number'])): ?>
                                                <span class="billing-chip <?php echo $timelineClass; ?>"><?php echo htmlspecialchars((string)$timelineItem['invoice_number']); ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($timelineItem['amount']) && $timelineItem['amount'] !== null): ?>
                                                <span class="billing-chip muted"><?php echo 'PHP ' . number_format((float)$timelineItem['amount'], 2); ?></span>
                                            <?php endif; ?>
                                            <span class="billing-chip <?php echo $timelineClass; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($timelineItem['status'] ?? 'recorded')))); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="billing-card">
                    <h3 class="billing-title">Invoice History</h3>
                    <p class="billing-sub">Review each invoice, open a printable view, download a PDF copy, or complete payment for outstanding bills.</p>
                    <div class="billing-table-wrap">
                        <table class="billing-table">
                            <thead><tr><th>Invoice</th><th>Period</th><th>Subscription</th><th>Order Fees</th><th>Total</th><th>Status</th><th>Due</th><th>Payment Reference</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr><td colspan="9" style="text-align:center;color:#64748b;">No billing invoices have been generated yet.</td></tr>
                            <?php else: foreach ($invoices as $invoice): ?>
                                <?php
                                    $status = (string)($invoice['invoice_status'] ?? 'issued');
                                    $statusClass = 'warning';
                                    if ($status === 'paid') $statusClass = 'success';
                                    elseif ($status === 'overdue') $statusClass = 'danger';
                                    elseif (in_array($status, ['draft', 'void'], true)) $statusClass = 'muted';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string)$invoice['invoice_number']); ?></strong><br><span style="color:#64748b;font-size:.82rem;"><?php echo htmlspecialchars(ucfirst((string)($invoice['invoice_type'] ?? 'combined'))); ?></span></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$invoice['period_start']))); ?><br><span style="color:#64748b;font-size:.82rem;">to <?php echo htmlspecialchars(date('M d, Y', strtotime((string)$invoice['period_end']))); ?></span></td>
                                    <td><?php echo 'PHP ' . number_format((float)($invoice['subscription_amount'] ?? 0), 2); ?></td>
                                    <td><?php echo 'PHP ' . number_format((float)($invoice['order_fee_amount'] ?? 0), 2); ?></td>
                                    <td><strong><?php echo 'PHP ' . number_format((float)($invoice['total_amount'] ?? 0), 2); ?></strong></td>
                                    <td><span class="billing-chip <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); ?></span></td>
                                    <td><?php echo !empty($invoice['due_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string)$invoice['due_at']))) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars((string)($invoice['payment_reference'] ?? '-')); ?><br><span style="color:#64748b;font-size:.82rem;"><?php echo htmlspecialchars((string)($invoice['payment_channel'] ?? '')); ?></span></td>
                                    <td>
                                        <div class="billing-actions">
                                            <a href="../billing_invoice_view.php?invoice_id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                            <a href="../billing_invoice_pdf.php?invoice_id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-primary">PDF</a>
                                            <?php if (!in_array($status, ['paid', 'void'], true)): ?>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="start_invoice_payment">
                                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <select name="payment_method" class="form-select form-select-sm" <?php echo $paymongoAvailable ? '' : 'disabled'; ?>>
                                                        <option value="gcash">GCash</option>
                                                        <option value="paymaya">PayMaya</option>
                                                        <option value="card">Card</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-success" <?php echo $paymongoAvailable ? '' : 'disabled'; ?>>Pay via PayMongo</button>
                                                </form>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="check_invoice_payment">
                                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-dark">Verify PayMongo</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="admin.js"></script>
    <script>
    const partnerBillingLabels = <?php echo json_encode($invoiceLabels); ?>;
    const partnerBillingTotals = <?php echo json_encode($invoiceTotals); ?>;
    const partnerBillingPaid = <?php echo json_encode($paidTotals); ?>;
    const partnerBillingStatusLabels = <?php echo json_encode(array_keys($statusCounts)); ?>;
    const partnerBillingStatusCounts = <?php echo json_encode(array_values($statusCounts)); ?>;
    const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    const trendChart = document.getElementById('partnerBillingTrendChart');
    if (trendChart) {
        new Chart(trendChart, {
            type: 'bar',
            data: {
                labels: partnerBillingLabels,
                datasets: [
                    { label: 'Invoiced', data: partnerBillingTotals, backgroundColor: 'rgba(14,165,233,.78)', borderRadius: 8 },
                    { label: 'Paid', data: partnerBillingPaid, backgroundColor: 'rgba(22,163,74,.78)', borderRadius: 8 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: (value) => peso.format(Number(value || 0)) }
                    }
                }
            }
        });
    }

    const statusChart = document.getElementById('partnerBillingStatusChart');
    if (statusChart) {
        new Chart(statusChart, {
            type: 'doughnut',
            data: {
                labels: partnerBillingStatusLabels,
                datasets: [{
                    data: partnerBillingStatusCounts,
                    backgroundColor: ['#16a34a', '#f59e0b', '#dc2626', '#64748b', '#94a3b8']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const billingSuccess = <?php echo json_encode($_SESSION['success'] ?? ''); ?>;
    const billingError = <?php echo json_encode($_SESSION['error'] ?? ''); ?>;
    <?php unset($_SESSION['success'], $_SESSION['error']); ?>
    if (billingSuccess) {
        Swal.fire({ icon: 'success', title: 'Success', text: billingSuccess, timer: 2400, showConfirmButton: false });
    }
    if (billingError) {
        Swal.fire({ icon: 'error', title: 'Billing Update', text: billingError });
    }
    </script>
</body>
</html>
