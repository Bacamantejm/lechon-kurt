<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/includes/PlatformMonetizationService.php';

checkAdminAccess();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin')
    ? isSuperAdmin($conn, $current_user_id)
    : (strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'super_admin');
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

if (!$is_super_admin_user && $seller_scope_id === null) {
    denyAdminAccess('Access denied: You do not have permission to view billing invoices.');
}

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$service = new PlatformMonetizationService($conn);
$service->ensureReady($current_user_id);
$document = $service->getInvoiceDocumentData($invoice_id, $is_super_admin_user ? null : (int)$seller_scope_id);

if ($invoice_id <= 0 || $document === []) {
    denyAdminAccess('Billing invoice not found or inaccessible.');
}

$invoice = $document['invoice'] ?? [];
$line_items = $document['line_items'] ?? [];
$payment_sessions = $document['payment_sessions'] ?? [];
$back_url = $is_super_admin_user ? 'super_admin/platform_monetization.php' : 'admin/partner_billing.php';
$status = strtolower(trim((string)($invoice['invoice_status'] ?? 'issued')));
$status_class = 'warning';
if ($status === 'paid') {
    $status_class = 'success';
} elseif ($status === 'overdue') {
    $status_class = 'danger';
} elseif (in_array($status, ['draft', 'void'], true)) {
    $status_class = 'muted';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars((string)($invoice['invoice_number'] ?? ('#' . $invoice_id))); ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="font_awesome/css/all.css">
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
            color: #0f172a;
        }
        .invoice-shell {
            max-width: 980px;
            margin: 32px auto;
            padding: 0 18px 32px;
        }
        .invoice-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .invoice-panel {
            background: #ffffff;
            border: 1px solid #dbe4ee;
            border-radius: 24px;
            box-shadow: 0 22px 40px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .invoice-topbar {
            padding: 28px 30px 18px;
            background: radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 38%), linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
        }
        .invoice-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 24px;
        }
        .invoice-brand {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: .02em;
        }
        .invoice-subtitle {
            margin-top: 8px;
            color: rgba(248, 250, 252, 0.82);
        }
        .invoice-chip {
            display: inline-flex;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: .8rem;
            font-weight: 700;
        }
        .invoice-chip.success { background: #dcfce7; color: #166534; }
        .invoice-chip.warning { background: #fef3c7; color: #92400e; }
        .invoice-chip.danger { background: #fee2e2; color: #991b1b; }
        .invoice-chip.muted { background: #e2e8f0; color: #475569; }
        .invoice-body {
            padding: 28px 30px 32px;
        }
        .invoice-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 26px;
        }
        .meta-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 16px;
        }
        .meta-card span {
            display: block;
            color: #64748b;
            font-size: .8rem;
            margin-bottom: 5px;
        }
        .meta-card strong {
            font-size: 1rem;
            color: #0f172a;
        }
        .invoice-section-title {
            margin: 0 0 14px;
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .invoice-table th, .invoice-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .invoice-table th {
            text-align: left;
            color: #475569;
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .invoice-totals {
            max-width: 360px;
            margin-left: auto;
        }
        .invoice-total-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .invoice-total-row.final {
            font-size: 1.08rem;
            font-weight: 800;
            border-bottom-width: 2px;
            border-bottom-color: #cbd5e1;
        }
        .invoice-note {
            white-space: pre-wrap;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 16px;
            color: #334155;
        }
        .session-list {
            display: grid;
            gap: 10px;
        }
        .session-card {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 16px;
            background: #ffffff;
        }
        .session-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            color: #64748b;
            font-size: .88rem;
        }
        @media (max-width: 768px) {
            .invoice-grid { grid-template-columns: 1fr; }
            .invoice-body, .invoice-topbar { padding: 22px 20px; }
        }
        @media print {
            body { background: #ffffff; }
            .invoice-actions { display: none !important; }
            .invoice-shell { max-width: 100%; margin: 0; padding: 0; }
            .invoice-panel { box-shadow: none; border: none; border-radius: 0; }
            .invoice-topbar { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="invoice-shell">
        <div class="invoice-actions">
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="billing_invoice_pdf.php?invoice_id=<?php echo (int)$invoice_id; ?>" class="btn btn-outline-primary"><i class="fas fa-file-pdf"></i> Download PDF</a>
            </div>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Invoice</button>
        </div>

        <div class="invoice-panel">
            <div class="invoice-topbar">
                <div class="invoice-grid">
                    <div>
                        <div class="invoice-brand">Platform Billing Invoice</div>
                        <div class="invoice-subtitle">Monthly monetization statement for partner subscriptions and platform order fees.</div>
                    </div>
                    <div class="text-md-end">
                        <div style="font-size:.92rem;opacity:.85;">Invoice Number</div>
                        <div style="font-size:1.25rem;font-weight:800;"><?php echo htmlspecialchars((string)($invoice['invoice_number'] ?? ('#' . $invoice_id))); ?></div>
                        <div style="margin-top:10px;"><span class="invoice-chip <?php echo htmlspecialchars($status_class); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); ?></span></div>
                    </div>
                </div>
            </div>

            <div class="invoice-body">
                <div class="invoice-meta-grid">
                    <div class="meta-card"><span>Business</span><strong><?php echo htmlspecialchars((string)($invoice['business_name'] ?? 'Partner Shop')); ?></strong></div>
                    <div class="meta-card"><span>Contact Email</span><strong><?php echo htmlspecialchars((string)($invoice['email'] ?? '-')); ?></strong></div>
                    <div class="meta-card"><span>Billing Period</span><strong><?php echo htmlspecialchars(date('M d, Y', strtotime((string)($invoice['period_start'] ?? 'now')))); ?> to <?php echo htmlspecialchars(date('M d, Y', strtotime((string)($invoice['period_end'] ?? 'now')))); ?></strong></div>
                    <div class="meta-card"><span>Issued At</span><strong><?php echo htmlspecialchars(!empty($invoice['issued_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['issued_at'])) : '-'); ?></strong></div>
                    <div class="meta-card"><span>Due At</span><strong><?php echo htmlspecialchars(!empty($invoice['due_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['due_at'])) : '-'); ?></strong></div>
                    <div class="meta-card"><span>Plan</span><strong><?php echo htmlspecialchars((string)($invoice['plan_name'] ?? 'Custom Billing')); ?></strong></div>
                </div>

                <h2 class="invoice-section-title">Line Items</h2>
                <table class="invoice-table">
                    <thead>
                        <tr><th>Description</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($line_items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($item['label'] ?? 'Line item')); ?></td>
                                <td class="text-end"><?php echo 'PHP ' . number_format((float)($item['amount'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="invoice-totals">
                    <div class="invoice-total-row"><span>Subtotal</span><strong><?php echo 'PHP ' . number_format((float)($invoice['subtotal_amount'] ?? 0), 2); ?></strong></div>
                    <div class="invoice-total-row"><span>Tax</span><strong><?php echo 'PHP ' . number_format((float)($invoice['tax_amount'] ?? 0), 2); ?></strong></div>
                    <div class="invoice-total-row final"><span>Total Amount</span><strong><?php echo 'PHP ' . number_format((float)($invoice['total_amount'] ?? 0), 2); ?></strong></div>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-12 col-lg-6">
                        <h3 class="invoice-section-title">Payment Details</h3>
                        <div class="invoice-note"><?php echo htmlspecialchars(
                            'Reference: ' . ((string)($invoice['payment_reference'] ?? '') !== '' ? (string)$invoice['payment_reference'] : '-') . "\n" .
                            'Channel: ' . ((string)($invoice['payment_channel'] ?? '') !== '' ? (string)$invoice['payment_channel'] : '-') . "\n" .
                            'Paid At: ' . (!empty($invoice['paid_at']) ? date('M d, Y h:i A', strtotime((string)$invoice['paid_at'])) : '-')
                        ); ?></div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <h3 class="invoice-section-title">Notes</h3>
                        <div class="invoice-note"><?php echo htmlspecialchars(trim((string)($invoice['notes'] ?? '')) !== '' ? (string)$invoice['notes'] : 'No additional invoice notes.'); ?></div>
                    </div>
                </div>

                <?php if (!empty($payment_sessions)): ?>
                    <div class="mt-4">
                        <h3 class="invoice-section-title">Recent Payment Attempts</h3>
                        <div class="session-list">
                            <?php foreach ($payment_sessions as $session): ?>
                                <div class="session-card">
                                    <div style="font-weight:700;color:#0f172a;"><?php echo htmlspecialchars(strtoupper((string)($session['provider'] ?? 'paymongo'))); ?> session <?php echo htmlspecialchars((string)($session['session_id'] ?? '')); ?></div>
                                    <div class="session-meta mt-2">
                                        <span>Status: <?php echo htmlspecialchars(ucfirst((string)($session['payment_status'] ?? 'pending'))); ?></span>
                                        <span>Method: <?php echo htmlspecialchars((string)($session['payment_method'] ?? '-')); ?></span>
                                        <span>Created: <?php echo htmlspecialchars(!empty($session['created_at']) ? date('M d, Y h:i A', strtotime((string)$session['created_at'])) : '-'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
