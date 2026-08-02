<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';
require_once '../includes/partner_receipt_settings_helper.php';
require_once '../includes/sales_receipt_helper.php';

checkAdminAccess();
$admin_info = getAdminInfo($conn);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$csrf_token = generateCSRFToken();

$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$is_partner_owner_admin = $seller_scope_id !== null && (int)$seller_scope_id === $current_user_id;
$can_manage_receipt_settings = $is_partner_owner_admin
    || (function_exists('hasPermission') && hasPermission($conn, $current_user_id, 'billing.manage'));

if (!$is_partner_scoped_admin || $seller_scope_id === null) {
    denyAdminAccess('Access denied: Receipt settings are only available to approved business partner shops.');
}
if (!$can_manage_receipt_settings) {
    denyAdminAccess('Access denied: Your assigned role does not include receipt settings access.');
}

prsEnsureReceiptSettingsSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: receipt_settings.php');
        exit;
    }

    $payload = [
        'store_display_name' => trim((string)($_POST['store_display_name'] ?? '')),
        'branch_name' => trim((string)($_POST['branch_name'] ?? '')),
        'vat_tin' => trim((string)($_POST['vat_tin'] ?? '')),
        'business_style' => trim((string)($_POST['business_style'] ?? '')),
        'permit_no' => trim((string)($_POST['permit_no'] ?? '')),
        'ptu_no' => trim((string)($_POST['ptu_no'] ?? '')),
        'accreditation_no' => trim((string)($_POST['accreditation_no'] ?? '')),
        'serial_no' => trim((string)($_POST['serial_no'] ?? '')),
        'footer_text' => trim((string)($_POST['footer_text'] ?? '')),
    ];

    $saved = prsSaveReceiptSettings($conn, (int)$seller_scope_id, $payload);
    if ($saved) {
        $_SESSION['success'] = 'Receipt settings saved. New receipts will use these details.';
    } else {
        $_SESSION['error'] = 'Unable to save receipt settings right now.';
    }

    header('Location: receipt_settings.php');
    exit;
}

$settings = prsFetchReceiptSettings($conn, (int)$seller_scope_id);
$businessProfile = srFetchBusinessProfile($conn, (int)$seller_scope_id);
$previewReceipt = [
    'invoice_heading' => 'Sales Invoice',
    'service_label' => 'PREVIEW',
    'receipt_no' => 'RCPT-PREVIEW',
    'transaction_no' => '000001',
    'timestamp' => date('Y-m-d H:i:s'),
    'business' => $businessProfile,
    'items' => [
        ['name' => 'Sample Order Item', 'quantity' => 1, 'price' => 350.00, 'total' => 350.00],
        ['name' => 'Delivery / Service Sample', 'quantity' => 1, 'price' => 40.00, 'total' => 40.00],
    ],
    'totals' => [
        'subtotal' => 390.00,
        'delivery_fee' => 0.00,
        'voucher_discount' => 0.00,
        'vatable_sales' => 390.00,
        'vat_exempt_sales' => 0.00,
        'zero_rated_sales' => 0.00,
        'vat_amount' => 46.80,
        'total_amount' => 436.80,
    ],
    'customer_name' => 'Sample Customer',
    'customer_address' => 'Customer address preview',
    'customer_tin' => '',
    'customer_business_style' => '',
    'operator_label' => 'Payment',
    'operator_name' => 'Cash',
    'secondary_label' => 'Status',
    'secondary_value' => 'PAID',
    'notes' => 'Preview only. Enter your official BIR-aligned receipt details before using this in production.',
    'claim_code' => 'PREVIEW',
];
$previewHtml = srRenderReceiptPage($previewReceipt, false);
$previewBodyStart = strpos($previewHtml, '<body>');
$previewBodyEnd = strrpos($previewHtml, '</body>');
$previewBody = '';
if ($previewBodyStart !== false && $previewBodyEnd !== false && $previewBodyEnd > $previewBodyStart) {
    $previewBody = trim(substr($previewHtml, $previewBodyStart + 6, $previewBodyEnd - ($previewBodyStart + 6)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Settings - Lechon Delights</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .receipt-settings-shell { padding: 24px; }
        .settings-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr); gap: 18px; align-items: start; }
        .settings-card, .preview-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.08);
        }
        .settings-title { margin: 0; font-size: 1.18rem; font-weight: 700; color: #0f172a; }
        .settings-sub { margin-top: 8px; color: #64748b; font-size: .92rem; }
        .settings-note {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            border-radius: 14px;
            padding: 14px 16px;
            font-size: .9rem;
            margin-bottom: 18px;
        }
        .settings-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .settings-form-grid .full-span { grid-column: 1 / -1; }
        .form-label { font-weight: 600; color: #334155; }
        .form-text-muted { color: #64748b; font-size: .82rem; }
        .preview-card .screen-actions { display: none; }
        .preview-card .receipt-shell { padding: 0; }
        .preview-card .receipt-paper { box-shadow: none; width: 100%; max-width: 360px; margin: 0 auto; }
        .preview-card .receipt-shell {
            width: 100%;
            display: flex;
            justify-content: center;
            box-sizing: border-box;
        }
        .preview-card .receipt-paper {
            background: #fff;
            color: #111;
            padding: 18px 18px 22px;
            font-family: "Courier New", Courier, monospace;
            font-size: 12px;
            line-height: 1.32;
        }
        .preview-card .receipt-center { text-align: center; }
        .preview-card .receipt-business { font-size: 24px; line-height: 1.1; margin: 0 0 4px; font-weight: 700; }
        .preview-card .receipt-muted { color: #4b5563; }
        .preview-card .receipt-rule { border-top: 1px dashed #6b7280; margin: 10px 0; }
        .preview-card .receipt-heading { text-transform: uppercase; letter-spacing: 0.08em; font-size: 13px; margin: 6px 0 0; font-weight: 700; }
        .preview-card .receipt-service { text-align: center; font-size: 12px; font-weight: 700; margin: 10px 0 6px; letter-spacing: 0.12em; }
        .preview-card .receipt-meta-row,
        .preview-card .receipt-total-row,
        .preview-card .receipt-customer-row { display: flex; justify-content: space-between; gap: 12px; }
        .preview-card .receipt-meta-row span:first-child,
        .preview-card .receipt-total-row span:first-child,
        .preview-card .receipt-customer-row span:first-child { min-width: 112px; }
        .preview-card .receipt-items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .preview-card .receipt-items th,
        .preview-card .receipt-items td { padding: 3px 0; vertical-align: top; }
        .preview-card .receipt-items th { border-bottom: 1px solid #111; font-weight: 700; }
        .preview-card .receipt-items th:nth-child(2),
        .preview-card .receipt-items td:nth-child(2) { text-align: center; width: 46px; }
        .preview-card .receipt-items th:nth-child(3),
        .preview-card .receipt-items th:nth-child(4),
        .preview-card .receipt-items td:nth-child(3),
        .preview-card .receipt-items td:nth-child(4) { text-align: right; width: 74px; }
        .preview-card .receipt-items td:first-child { padding-right: 8px; }
        .preview-card .receipt-total-row { margin: 3px 0; }
        .preview-card .receipt-total-row strong { font-size: 13px; }
        .preview-card .receipt-bottom-note { text-align: center; margin-top: 12px; }
        .preview-card .receipt-claim { text-align: center; margin-top: 16px; font-size: 18px; font-weight: 700; letter-spacing: 0.08em; }
        .status-banner {
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: .92rem;
        }
        .status-banner.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-banner.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        @media (max-width: 1100px) {
            .settings-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .receipt-settings-shell { padding: 16px; }
            .settings-form-grid { grid-template-columns: 1fr; }
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
                <h1>Receipt Settings</h1>
                <div class="topbar-right">
                    <div class="admin-profile">
                        <span><?php echo htmlspecialchars((string)($admin_info['full_name'] ?? 'Partner')); ?></span>
                        <i class="fas fa-user-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="receipt-settings-shell">
            <?php if (!empty($_SESSION['success'])): ?>
                <div class="status-banner success"><?php echo htmlspecialchars((string)$_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="status-banner error"><?php echo htmlspecialchars((string)$_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="settings-grid">
                <div class="settings-card">
                    <h2 class="settings-title">Official Receipt / BIR Fields</h2>
                    <p class="settings-sub">These details appear on walk-in, order, and pre-order sales receipts for your shop.</p>
                    <div class="settings-note">
                        Enter only your real registered tax and permit details. These fields affect invoices and should match your official BIR or business registration documents.
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="settings-form-grid">
                            <div>
                                <label class="form-label" for="store_display_name">Store / Branch Display Name</label>
                                <input type="text" class="form-control" id="store_display_name" name="store_display_name" maxlength="180" value="<?php echo htmlspecialchars((string)($settings['store_display_name'] ?? '')); ?>" placeholder="Example: Lechon Delights - Pacita Branch">
                            </div>
                            <div>
                                <label class="form-label" for="branch_name">Branch Name</label>
                                <input type="text" class="form-control" id="branch_name" name="branch_name" maxlength="180" value="<?php echo htmlspecialchars((string)($settings['branch_name'] ?? '')); ?>" placeholder="Example: Pacita Branch">
                            </div>
                            <div>
                                <label class="form-label" for="vat_tin">VAT TIN</label>
                                <input type="text" class="form-control" id="vat_tin" name="vat_tin" maxlength="80" value="<?php echo htmlspecialchars((string)($settings['vat_tin'] ?? '')); ?>" placeholder="Example: 123-456-789-00000">
                            </div>
                            <div>
                                <label class="form-label" for="business_style">Business Style</label>
                                <input type="text" class="form-control" id="business_style" name="business_style" maxlength="180" value="<?php echo htmlspecialchars((string)($settings['business_style'] ?? '')); ?>" placeholder="Example: Restaurant / Food Service">
                            </div>
                            <div>
                                <label class="form-label" for="permit_no">Permit / Permit to Operate No.</label>
                                <input type="text" class="form-control" id="permit_no" name="permit_no" maxlength="120" value="<?php echo htmlspecialchars((string)($settings['permit_no'] ?? '')); ?>" placeholder="Example: PERMIT-2026-001">
                            </div>
                            <div>
                                <label class="form-label" for="ptu_no">PTU No.</label>
                                <input type="text" class="form-control" id="ptu_no" name="ptu_no" maxlength="120" value="<?php echo htmlspecialchars((string)($settings['ptu_no'] ?? '')); ?>" placeholder="Example: PTU-123456-2026">
                            </div>
                            <div>
                                <label class="form-label" for="accreditation_no">Accreditation No.</label>
                                <input type="text" class="form-control" id="accreditation_no" name="accreditation_no" maxlength="120" value="<?php echo htmlspecialchars((string)($settings['accreditation_no'] ?? '')); ?>" placeholder="Example: ACCR-2026-001">
                            </div>
                            <div>
                                <label class="form-label" for="serial_no">Serial / Terminal No.</label>
                                <input type="text" class="form-control" id="serial_no" name="serial_no" maxlength="120" value="<?php echo htmlspecialchars((string)($settings['serial_no'] ?? '')); ?>" placeholder="Example: SN-STORE-0001">
                            </div>
                            <div class="full-span">
                                <label class="form-label" for="footer_text">Receipt Footer Text</label>
                                <textarea class="form-control" id="footer_text" name="footer_text" rows="5" placeholder="Example: Thank you for dining with us.&#10;Please keep this receipt for your records."><?php echo htmlspecialchars((string)($settings['footer_text'] ?? '')); ?></textarea>
                                <div class="form-text-muted">Each line you enter here will appear at the bottom of your receipts.</div>
                            </div>
                            <div class="full-span d-flex gap-2 flex-wrap pt-2">
                                <button type="submit" class="btn btn-danger"><i class="fas fa-save"></i> Save Receipt Settings</button>
                                <a href="partner_billing.php" class="btn btn-outline-secondary">Back to Billing</a>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="preview-card">
                    <h2 class="settings-title">Live Receipt Preview</h2>
                    <p class="settings-sub">This uses your current saved values and the same layout customers and staff print.</p>
                    <?php echo $previewBody; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="../js/jquery.min.js"></script>
<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>
