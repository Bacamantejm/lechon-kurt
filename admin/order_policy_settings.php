<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/partner_order_policy_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;
$can_manage_policy = $is_partner_owner_admin
    || (function_exists('hasPermission') && hasPermission($conn, $current_user_id, 'orders.edit'));

if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: Order policy settings are only available to approved business partner shops.');
}
if (!$can_manage_policy) {
    denyAdminAccess('Access denied: Your assigned role does not include order policy settings access.');
}

popEnsurePolicySchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: order_policy_settings.php');
        exit;
    }

    $payload = [
        'allow_customer_cancel_pending' => !empty($_POST['allow_customer_cancel_pending']) ? 1 : 0,
        'allow_customer_cancel_confirmed' => !empty($_POST['allow_customer_cancel_confirmed']) ? 1 : 0,
        'allow_customer_cancel_preparing' => !empty($_POST['allow_customer_cancel_preparing']) ? 1 : 0,
        'downpayment_refundable' => !empty($_POST['downpayment_refundable']) ? 1 : 0,
        'require_refund_photo_for_damage' => !empty($_POST['require_refund_photo_for_damage']) ? 1 : 0,
        'cancellation_terms' => trim((string)($_POST['cancellation_terms'] ?? '')),
        'refund_terms' => trim((string)($_POST['refund_terms'] ?? '')),
    ];

    if ($payload['cancellation_terms'] === '') {
        $payload['cancellation_terms'] = popDefaultPolicy()['cancellation_terms'];
    }
    if ($payload['refund_terms'] === '') {
        $payload['refund_terms'] = popDefaultPolicy()['refund_terms'];
    }

    if (popSavePolicy($conn, (int)$seller_scope_id, $payload)) {
        $_SESSION['success'] = 'Order and refund policy saved successfully.';
    } else {
        $_SESSION['error'] = 'Unable to save order policy settings right now.';
    }

    header('Location: order_policy_settings.php');
    exit;
}

$policy = popFetchPolicy($conn, (int)$seller_scope_id);
$partner_info = function_exists('getUserInfo') ? getUserInfo($conn, (int)$seller_scope_id) : null;
$shop_name = trim((string)($partner_info['business_name'] ?? $partner_info['full_name'] ?? 'Your shop'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Policy Settings - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .policy-shell { padding: 24px; }
        .policy-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(300px, .9fr); gap: 18px; align-items: start; }
        .policy-card, .policy-summary {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
        }
        .policy-title { margin: 0; font-size: 1.2rem; font-weight: 700; color: #0f172a; }
        .policy-subtitle { margin-top: 8px; color: #64748b; font-size: .94rem; }
        .status-banner { border-radius: 14px; padding: 12px 14px; margin-bottom: 18px; font-size: .92rem; }
        .status-banner.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-banner.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .policy-note {
            background: linear-gradient(135deg, #eff6ff, #f8fafc);
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            border-radius: 14px;
            padding: 14px 16px;
            font-size: .9rem;
            margin-bottom: 18px;
        }
        .toggle-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .toggle-card {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            background: #f8fafc;
        }
        .toggle-card label { display: flex; gap: 10px; align-items: flex-start; margin: 0; cursor: pointer; }
        .toggle-card input { margin-top: 3px; }
        .toggle-card strong { display: block; color: #0f172a; }
        .toggle-card span { font-size: .88rem; color: #64748b; display: block; margin-top: 4px; }
        .policy-textareas { display: grid; gap: 14px; }
        .summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: .84rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .summary-block {
            border-top: 1px solid #e5e7eb;
            padding-top: 14px;
            margin-top: 14px;
        }
        .summary-block h4 { font-size: .96rem; margin-bottom: 8px; color: #0f172a; }
        .summary-block p { color: #475569; font-size: .9rem; margin-bottom: 0; white-space: pre-line; }
        @media (max-width: 980px) {
            .policy-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .policy-shell { padding: 16px; }
            .toggle-grid { grid-template-columns: 1fr; }
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
                <h1>Order Policy Settings</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="policy-shell">
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="status-banner success"><?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="status-banner error"><?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="policy-grid">
                <div class="policy-card">
                    <h2 class="policy-title">Customer Cancellation and Refund Rules</h2>
                    <p class="policy-subtitle">Set the rules that your customers will see before they request a cancellation or refund for <strong><?php echo htmlspecialchars($shop_name); ?></strong>.</p>

                    <div class="policy-note">
                        Customers can only cancel based on the status rules you set here. Refund requests for damaged or broken items can require proof, and downpayment refunds stay blocked by default.
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="toggle-grid">
                            <div class="toggle-card">
                                <label>
                                    <input type="checkbox" name="allow_customer_cancel_pending" value="1" <?php echo !empty($policy['allow_customer_cancel_pending']) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong>Allow cancellation while pending</strong>
                                        <span>Customers can cancel before the store confirms the order.</span>
                                    </div>
                                </label>
                            </div>

                            <div class="toggle-card">
                                <label>
                                    <input type="checkbox" name="allow_customer_cancel_confirmed" value="1" <?php echo !empty($policy['allow_customer_cancel_confirmed']) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong>Allow cancellation while confirmed</strong>
                                        <span>Use this if your team can still stop the order after confirmation.</span>
                                    </div>
                                </label>
                            </div>

                            <div class="toggle-card">
                                <label>
                                    <input type="checkbox" name="allow_customer_cancel_preparing" value="1" <?php echo !empty($policy['allow_customer_cancel_preparing']) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong>Allow cancellation while preparing</strong>
                                        <span>Turn this on only if your terms allow prepared orders to be cancelled.</span>
                                    </div>
                                </label>
                            </div>

                            <div class="toggle-card">
                                <label>
                                    <input type="checkbox" name="require_refund_photo_for_damage" value="1" <?php echo !empty($policy['require_refund_photo_for_damage']) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong>Require proof photo for damaged or broken item refunds</strong>
                                        <span>Customers must upload a photo before submitting damage-related refund requests.</span>
                                    </div>
                                </label>
                            </div>

                            <div class="toggle-card">
                                <label>
                                    <input type="checkbox" name="downpayment_refundable" value="1" <?php echo !empty($policy['downpayment_refundable']) ? 'checked' : ''; ?>>
                                    <div>
                                        <strong>Allow downpayment refund</strong>
                                        <span>Leave this off if downpayment should stay non-refundable under your store terms.</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="policy-textareas">
                            <div>
                                <label class="form-label">Cancellation Terms</label>
                                <textarea name="cancellation_terms" class="form-control" rows="5" placeholder="Explain when customers can cancel orders."><?php echo htmlspecialchars((string)($policy['cancellation_terms'] ?? '')); ?></textarea>
                            </div>
                            <div>
                                <label class="form-label">Refund Terms</label>
                                <textarea name="refund_terms" class="form-control" rows="5" placeholder="Explain when refunds are reviewed or denied."><?php echo htmlspecialchars((string)($policy['refund_terms'] ?? '')); ?></textarea>
                            </div>
                        </div>

                        <div class="mt-4 d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Policy
                            </button>
                            <a href="cancellations.php" class="btn btn-outline-secondary">
                                <i class="fas fa-ban"></i> Review Cancellations
                            </a>
                            <a href="finance.php#decision-center" class="btn btn-outline-warning">
                                <i class="fas fa-wallet"></i> Open Refund Queue
                            </a>
                        </div>
                    </form>
                </div>

                <aside class="policy-summary">
                    <span class="summary-chip"><i class="fas fa-store"></i> Live customer-facing summary</span>
                    <h3 class="policy-title" style="font-size:1.05rem;"><?php echo htmlspecialchars($shop_name); ?> Policy Preview</h3>
                    <p class="policy-subtitle">This is the kind of message your customers will see before they act.</p>

                    <div class="summary-block">
                        <h4>Cancellation Access</h4>
                        <p>
Pending: <?php echo !empty($policy['allow_customer_cancel_pending']) ? 'Allowed' : 'Blocked'; ?>
Confirmed: <?php echo !empty($policy['allow_customer_cancel_confirmed']) ? 'Allowed' : 'Blocked'; ?>
Preparing: <?php echo !empty($policy['allow_customer_cancel_preparing']) ? 'Allowed' : 'Blocked'; ?>
                        </p>
                    </div>

                    <div class="summary-block">
                        <h4>Refund Evidence Rule</h4>
                        <p><?php echo !empty($policy['require_refund_photo_for_damage']) ? 'Customers must upload proof for damaged or broken product refunds.' : 'Damage refunds can be submitted without mandatory photo proof.'; ?></p>
                    </div>

                    <div class="summary-block">
                        <h4>Downpayment Rule</h4>
                        <p><?php echo !empty($policy['downpayment_refundable']) ? 'Downpayment can be refunded if your finance review approves it.' : 'Downpayment is non-refundable under your current store rule.'; ?></p>
                    </div>

                    <div class="summary-block">
                        <h4>Cancellation Terms Text</h4>
                        <p><?php echo htmlspecialchars((string)($policy['cancellation_terms'] ?? '')); ?></p>
                    </div>

                    <div class="summary-block">
                        <h4>Refund Terms Text</h4>
                        <p><?php echo htmlspecialchars((string)($policy['refund_terms'] ?? '')); ?></p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</div>
</body>
</html>
