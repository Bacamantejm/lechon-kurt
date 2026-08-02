<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/partner_dashboard_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;
$can_manage_partner_banking = $is_partner_owner_admin
    || (function_exists('hasPermission') && hasPermission($conn, $current_user_id, 'billing.manage'));

if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: Banking setup is only available to approved business partner shops.');
}
if (!$can_manage_partner_banking) {
    denyAdminAccess('Access denied: Your assigned role does not include banking setup access.');
}
if (!pdhEnsurePartnerPayoutAccountsSchema($conn)) {
    $_SESSION['error'] = 'Banking setup is temporarily unavailable. Please try again shortly.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: partner_banking.php');
        exit;
    }

    $payload = [
        'payout_method' => trim((string)($_POST['payout_method'] ?? 'bank_transfer')),
        'account_holder' => trim((string)($_POST['account_holder'] ?? '')),
        'financial_institution' => trim((string)($_POST['financial_institution'] ?? '')),
        'account_type' => trim((string)($_POST['account_type'] ?? '')),
        'account_number' => trim((string)($_POST['account_number'] ?? '')),
        'branch_name' => trim((string)($_POST['branch_name'] ?? '')),
        'routing_reference' => trim((string)($_POST['routing_reference'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    if ($payload['account_holder'] === '' || $payload['account_number'] === '') {
        $_SESSION['error'] = 'Account holder and account number are required.';
        header('Location: partner_banking.php');
        exit;
    }

    $saved = pdhSavePartnerPayoutAccount($conn, (int)$seller_scope_id, $payload, $current_user_id);
    if ($saved) {
        $_SESSION['success'] = 'Banking setup saved. Payout records can now reference this account.';
    } else {
        $_SESSION['error'] = 'Unable to save banking setup right now. Please try again.';
    }

    header('Location: partner_banking.php');
    exit;
}

$banking_account = pdhFetchPartnerPayoutAccount($conn, (int)$seller_scope_id);
$masked_account_preview = trim((string)($banking_account['account_number_masked'] ?? ''));
if ($masked_account_preview === '' && !empty($banking_account['account_number'])) {
    $masked_account_preview = pdhMaskPayoutAccountNumber((string)$banking_account['account_number']);
}
$payout_method = strtolower(trim((string)($banking_account['payout_method'] ?? 'bank_transfer')));
$payout_method_labels = [
    'bank_transfer' => 'Bank Transfer',
    'gcash' => 'GCash',
    'paymaya' => 'PayMaya',
    'other' => 'Other Payout Method'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banking Setup - Lechon Delights</title>
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .banking-shell { padding: 24px; }
        .banking-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(290px, 0.9fr);
            gap: 18px;
            align-items: start;
        }
        .banking-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
        }
        .banking-title {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .banking-subtitle {
            margin: 0 0 16px;
            color: #64748b;
            font-size: .9rem;
        }
        .banking-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .banking-form-grid .full { grid-column: 1 / -1; }
        .banking-status {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: .9rem;
        }
        .banking-status.success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        .banking-status.error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .banking-summary-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 12px;
        }
        .banking-summary-list li {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 12px;
            background: #f8fafc;
        }
        .banking-summary-list span {
            display: block;
            font-size: .78rem;
            color: #64748b;
            margin-bottom: 3px;
        }
        .banking-summary-list strong {
            color: #0f172a;
            font-size: .95rem;
        }
        @media (max-width: 980px) {
            .banking-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .banking-shell {
                padding: 16px;
            }
            .banking-form-grid {
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
                <h1>Partner Banking Setup</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="banking-shell">
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="banking-status success"><?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="banking-status error"><?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="banking-grid">
                <section class="banking-card">
                    <h2 class="banking-title">Payout Account Details</h2>
                    <p class="banking-subtitle">Keep your active payout destination updated so billing and finance records can reference the correct account.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="banking-form-grid">
                            <div>
                                <label class="form-label fw-semibold">Payout Method</label>
                                <select class="form-select" name="payout_method" required>
                                    <?php foreach ($payout_method_labels as $method_key => $method_label): ?>
                                        <option value="<?php echo htmlspecialchars($method_key); ?>" <?php echo $payout_method === $method_key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($method_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label fw-semibold">Account Holder</label>
                                <input type="text" class="form-control" name="account_holder" maxlength="180" value="<?php echo htmlspecialchars((string)($banking_account['account_holder'] ?? '')); ?>" required>
                            </div>
                            <div>
                                <label class="form-label fw-semibold">Bank or Wallet Name</label>
                                <input type="text" class="form-control" name="financial_institution" maxlength="180" value="<?php echo htmlspecialchars((string)($banking_account['financial_institution'] ?? '')); ?>" placeholder="Example: BDO, BPI, GCash">
                            </div>
                            <div>
                                <label class="form-label fw-semibold">Account Type</label>
                                <input type="text" class="form-control" name="account_type" maxlength="80" value="<?php echo htmlspecialchars((string)($banking_account['account_type'] ?? '')); ?>" placeholder="Savings, Current, Wallet">
                            </div>
                            <div>
                                <label class="form-label fw-semibold">Account Number</label>
                                <input type="text" class="form-control" name="account_number" maxlength="140" value="<?php echo htmlspecialchars((string)($banking_account['account_number'] ?? '')); ?>" required>
                            </div>
                            <div>
                                <label class="form-label fw-semibold">Branch Name (Optional)</label>
                                <input type="text" class="form-control" name="branch_name" maxlength="120" value="<?php echo htmlspecialchars((string)($banking_account['branch_name'] ?? '')); ?>">
                            </div>
                            <div class="full">
                                <label class="form-label fw-semibold">Routing Reference (Optional)</label>
                                <input type="text" class="form-control" name="routing_reference" maxlength="120" value="<?php echo htmlspecialchars((string)($banking_account['routing_reference'] ?? '')); ?>" placeholder="SWIFT / routing number / transfer note">
                            </div>
                            <div class="full">
                                <label class="form-label fw-semibold">Internal Notes</label>
                                <textarea class="form-control" name="notes" rows="3" maxlength="800" placeholder="Add payout handling notes for your team"><?php echo htmlspecialchars((string)($banking_account['notes'] ?? '')); ?></textarea>
                            </div>
                            <div class="full form-check mt-1">
                                <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" <?php echo (int)($banking_account['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Mark this account as active payout destination
                                </label>
                            </div>
                            <div class="full mt-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save Banking Setup
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <aside class="banking-card">
                    <h3 class="banking-title">Current Account Snapshot</h3>
                    <p class="banking-subtitle">Quick reference for your currently stored payout account.</p>
                    <ul class="banking-summary-list">
                        <li>
                            <span>Payout Method</span>
                            <strong><?php echo htmlspecialchars($payout_method_labels[$payout_method] ?? 'Bank Transfer'); ?></strong>
                        </li>
                        <li>
                            <span>Account Holder</span>
                            <strong><?php echo htmlspecialchars((string)($banking_account['account_holder'] ?? 'Not set')); ?></strong>
                        </li>
                        <li>
                            <span>Institution</span>
                            <strong><?php echo htmlspecialchars((string)($banking_account['financial_institution'] ?? 'Not set')); ?></strong>
                        </li>
                        <li>
                            <span>Masked Account Number</span>
                            <strong><?php echo htmlspecialchars($masked_account_preview !== '' ? $masked_account_preview : 'Not set'); ?></strong>
                        </li>
                        <li>
                            <span>Status</span>
                            <strong><?php echo (int)($banking_account['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></strong>
                        </li>
                    </ul>
                </aside>
            </div>
        </div>
    </div>
</div>

<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
