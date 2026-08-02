<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/PlatformMonetizationService.php';

$monetizationService = new PlatformMonetizationService($conn);
$monetizationService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'platform_monetization.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'save_plan') {
        if ($monetizationService->savePlan($_POST, $current_admin_id)) {
            saSetFlash('success', 'Subscription plan saved successfully.');
        } else {
            saSetFlash('danger', 'Unable to save the subscription plan.');
        }
    } elseif ($action === 'assign_partner_plan') {
        if ($monetizationService->assignPartnerSubscription($_POST, $current_admin_id)) {
            saSetFlash('success', 'Partner subscription updated successfully.');
        } else {
            saSetFlash('danger', 'Unable to update partner subscription.');
        }
    } elseif ($action === 'save_fee_rule') {
        if ($monetizationService->saveFeeRule($_POST, $current_admin_id)) {
            saSetFlash('success', 'Platform fee rule saved successfully.');
        } else {
            saSetFlash('danger', 'Unable to save platform fee rule.');
        }
    } elseif ($action === 'generate_invoice') {
        if ($monetizationService->generateInvoiceForPartner((int)($_POST['partner_user_id'] ?? 0), $current_admin_id, (string)($_POST['billing_month'] ?? ''))) {
            saSetFlash('success', 'Billing invoice generated successfully.');
        } else {
            saSetFlash('danger', 'Unable to generate billing invoice. It may already exist for that period.');
        }
    } elseif ($action === 'update_invoice_status') {
        if ($monetizationService->updateInvoiceStatus(
            (int)($_POST['invoice_id'] ?? 0),
            (string)($_POST['invoice_status'] ?? 'issued'),
            (string)($_POST['payment_reference'] ?? ''),
            (string)($_POST['payment_channel'] ?? ''),
            (string)($_POST['notes'] ?? ''),
            $current_admin_id
        )) {
            saSetFlash('success', 'Invoice status updated successfully.');
        } else {
            saSetFlash('danger', 'Unable to update invoice status.');
        }
    } elseif ($action === 'verify_invoice_payment') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $invoice = $monetizationService->getInvoiceById($invoice_id);
        $result = $invoice
            ? $monetizationService->completeInvoicePayment($invoice_id, (int)($invoice['partner_user_id'] ?? 0), null, $current_admin_id)
            : ['success' => false, 'message' => 'Invoice not found.'];
        if (!empty($result['success'])) {
            saSetFlash('success', (string)($result['message'] ?? 'Invoice payment verified successfully.'));
        } else {
            saSetFlash('warning', (string)($result['message'] ?? 'Unable to verify invoice payment yet.'));
        }
    } elseif ($action === 'auto_generate_invoices') {
        $result = $monetizationService->autoGenerateMonthlyInvoices($current_admin_id, (string)($_POST['automation_run_date'] ?? ''));
        saSetFlash(
            'success',
            'Auto-generation checked ' . number_format(count($result['details'] ?? [])) . ' partners for ' .
            date('M Y', strtotime((string)($result['target_month'] ?? date('Y-m-01')))) . '. Generated ' .
            number_format((int)($result['generated'] ?? 0)) . ', skipped ' . number_format((int)($result['skipped'] ?? 0)) .
            ', failed ' . number_format((int)($result['failed'] ?? 0)) . '.'
        );
    } elseif ($action === 'send_invoice_reminders') {
        $result = $monetizationService->sendAutomaticInvoiceReminders($current_admin_id, (string)($_POST['reminder_run_date'] ?? ''));
        saSetFlash(
            'success',
            'Automatic reminders sent to ' . number_format((int)($result['reminded'] ?? 0)) . ' invoices and skipped ' . number_format((int)($result['skipped'] ?? 0)) . '.'
        );
    } elseif ($action === 'send_single_invoice_reminder') {
        $reminderType = (string)($_POST['reminder_type'] ?? 'manual');
        if ($monetizationService->sendInvoiceReminder((int)($_POST['invoice_id'] ?? 0), $reminderType, $current_admin_id, true)) {
            saSetFlash('success', 'Invoice reminder sent successfully.');
        } else {
            saSetFlash('warning', 'Unable to send the invoice reminder.');
        }
    } elseif ($action === 'review_subscription_request') {
        if ($monetizationService->reviewSubscriptionRequest(
            (int)($_POST['request_id'] ?? 0),
            (string)($_POST['decision'] ?? 'rejected'),
            (string)($_POST['review_notes'] ?? ''),
            $current_admin_id
        )) {
            saSetFlash('success', 'Subscription request reviewed successfully.');
        } else {
            saSetFlash('danger', 'Unable to review the subscription request.');
        }
    } else {
        saSetFlash('warning', 'Unsupported monetization action.');
    }

    header('Location: platform_monetization.php');
    exit;
}

$dashboard = $monetizationService->getDashboardData();
$plans = $dashboard['plans'] ?? [];
$subscriptions = $dashboard['subscriptions'] ?? [];
$invoices = $dashboard['invoices'] ?? [];
$feeRules = $dashboard['fee_rules'] ?? [];
$partnerRows = $dashboard['partner_rows'] ?? [];
$metrics = $dashboard['metrics'] ?? [];
$monthlySeries = $dashboard['monthly_series'] ?? ['labels' => [], 'order_fee_revenue' => [], 'subscription_revenue' => []];
$partners = $monetizationService->getApprovedPartners();
$subscriptionRequests = $monetizationService->getSubscriptionRequests(null, 80);

$editPlanId = (int)($_GET['edit_plan'] ?? 0);
$selectedPlan = null;
foreach ($plans as $plan) {
    if ((int)($plan['id'] ?? 0) === $editPlanId) {
        $selectedPlan = $plan;
        break;
    }
}

$globalRule = null;
$partnerRuleRows = [];
foreach ($feeRules as $rule) {
    if (($rule['rule_scope'] ?? '') === 'global' && $globalRule === null) {
        $globalRule = $rule;
    }
    if (($rule['rule_scope'] ?? '') === 'partner') {
        $partnerRuleRows[] = $rule;
    }
}

saRenderModuleHeader('Platform Monetization', 'Platform Monetization & Revenue', $admin_info);
?>
<div class="module-section ops-highlight-card">
    <div class="module-section-header">
        <div>
            <h2>Platform Monetization Dashboard</h2>
            <p class="module-subtext">Turn partner shops into recurring revenue with subscription plans, platform fees, and projected owner-side earnings.</p>
        </div>
        <div class="module-inline-actions">
            <a href="../super_admin/transactions_financial.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-coins"></i> Transactions</a>
            <a href="../super_admin/business_monitoring.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-store"></i> Business Monitoring</a>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card"><span class="metric-label">Approved Partners</span><div class="metric-value"><?php echo number_format((int)($metrics['approved_partners'] ?? 0)); ?></div></div>
        <div class="metric-card"><span class="metric-label">Active Subscribers</span><div class="metric-value"><?php echo number_format((int)($metrics['active_subscribers'] ?? 0)); ?></div></div>
        <div class="metric-card"><span class="metric-label">Unassigned Partners</span><div class="metric-value"><?php echo number_format((int)($metrics['unassigned_partners'] ?? 0)); ?></div></div>
        <div class="metric-card"><span class="metric-label">Projected MRR</span><div class="metric-value"><?php echo saFormatCurrency($metrics['mrr'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Projected ARR</span><div class="metric-value"><?php echo saFormatCurrency($metrics['arr'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Order Fee Revenue This Month</span><div class="metric-value"><?php echo saFormatCurrency($metrics['estimated_order_fee_month'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Projected Platform Revenue</span><div class="metric-value"><?php echo saFormatCurrency($metrics['projected_platform_revenue_month'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Avg Revenue per Partner</span><div class="metric-value"><?php echo saFormatCurrency($metrics['average_platform_revenue_per_partner'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Collected Revenue</span><div class="metric-value"><?php echo saFormatCurrency($metrics['actual_collected_revenue'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Outstanding Invoices</span><div class="metric-value"><?php echo saFormatCurrency($metrics['outstanding_invoice_total'] ?? 0); ?></div></div>
        <div class="metric-card"><span class="metric-label">Overdue Invoices</span><div class="metric-value"><?php echo saFormatCurrency($metrics['overdue_invoice_total'] ?? 0); ?></div></div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Revenue Mix Projection</h3>
            <p class="module-subtext">Subscription baseline and marketplace fee revenue from partner sales over the last six months.</p>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="metric-card" style="height: 320px;">
                <canvas id="monetizationRevenueChart"></canvas>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="metric-card" style="height: 320px;">
                <canvas id="monetizationMixChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Generate Billing Invoice</h3>
                    <p class="module-subtext">Create a real invoice record for a partner so recurring revenue can be tracked as issued, paid, or overdue.</p>
                </div>
            </div>
            <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Generate billing invoice?" data-sa-confirm-text="This will create a real billing record for the selected partner and month." data-sa-confirm-confirm-text="Generate Invoice">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="generate_invoice">
                <select name="partner_user_id" class="form-select" required>
                    <option value="">Select approved partner</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?php echo (int)$partner['partner_user_id']; ?>"><?php echo htmlspecialchars((string)$partner['business_name'] . ' - ' . (string)$partner['email']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="month" name="billing_month" class="form-control" value="<?php echo htmlspecialchars(date('Y-m')); ?>" required>
                <div class="module-inline-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-invoice-dollar"></i> Generate Invoice</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Invoice Status Control</h3>
                    <p class="module-subtext">Update billing records as issued, paid, overdue, or void with reference details for reconciliation.</p>
                </div>
            </div>
            <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title-template="Set invoice to {field_label:invoice_status}?" data-sa-confirm-text="This changes the live billing state used in owner and partner billing views." data-sa-confirm-confirm-text="Update Invoice">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="update_invoice_status">
                <select name="invoice_id" class="form-select" required>
                    <option value="">Select invoice</option>
                    <?php foreach ($invoices as $invoice): ?>
                        <option value="<?php echo (int)$invoice['id']; ?>">
                            <?php echo htmlspecialchars((string)$invoice['invoice_number'] . ' - ' . (string)$invoice['business_name'] . ' - ' . saFormatCurrency($invoice['total_amount'] ?? 0)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="invoice_status" class="form-select" required>
                    <option value="issued">Issued</option>
                    <option value="paid">Paid</option>
                    <option value="overdue">Overdue</option>
                    <option value="draft">Draft</option>
                    <option value="void">Void</option>
                </select>
                <input type="text" name="payment_reference" class="form-control" placeholder="Payment reference / receipt no.">
                <input type="text" name="payment_channel" class="form-control" placeholder="Payment channel">
                <textarea name="notes" class="form-control" style="grid-column:1/-1;" rows="2" placeholder="Optional invoice note"></textarea>
                <div class="module-inline-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle"></i> Save Invoice Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Automatic Invoice Generation</h3>
            <p class="module-subtext">Generate the previous month billing cycle for all approved partners in one pass, then schedule the same script in cron or Windows Task Scheduler.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Run automatic invoice generation?" data-sa-confirm-text="This checks all approved partners and generates missing invoices for the previous billing month only." data-sa-confirm-confirm-text="Run Automation">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="auto_generate_invoices">
        <input type="date" name="automation_run_date" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-rotate"></i> Generate Previous Month</button>
            <span class="compact-text">CLI schedule: <code>php C:\xampp\htdocs\lechonsystem\cron_generate_partner_invoices.php <?php echo htmlspecialchars(date('Y-m-d')); ?></code></span>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Automatic Reminder Dispatch</h3>
            <p class="module-subtext">Send due-soon and overdue reminders to partner shops by in-app notification and email.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Send automatic invoice reminders?" data-sa-confirm-text="This sends due-soon and overdue notices for partner invoices that need follow-up today." data-sa-confirm-confirm-text="Send Reminders">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="send_invoice_reminders">
        <input type="date" name="reminder_run_date" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-bell"></i> Run Reminder Dispatch</button>
            <span class="compact-text">Recommended schedule: once daily in the morning.</span>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3><?php echo $selectedPlan ? 'Edit Subscription Plan' : 'Create Subscription Plan'; ?></h3>
            <p class="module-subtext">Build pricing tiers for partner shops and decide what each plan includes.</p>
        </div>
        <?php if ($selectedPlan): ?>
            <a href="../super_admin/platform_monetization.php" class="btn btn-sm btn-outline-secondary">Clear Edit</a>
        <?php endif; ?>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="plan_id" value="<?php echo (int)($selectedPlan['id'] ?? 0); ?>">
        <input type="text" name="plan_name" class="form-control" placeholder="Plan name" value="<?php echo htmlspecialchars((string)($selectedPlan['plan_name'] ?? '')); ?>" required>
        <input type="text" name="plan_code" class="form-control" placeholder="Plan code" value="<?php echo htmlspecialchars((string)($selectedPlan['plan_code'] ?? '')); ?>">
        <input type="number" step="0.01" min="0" name="monthly_price" class="form-control" placeholder="Monthly price" value="<?php echo htmlspecialchars((string)($selectedPlan['monthly_price'] ?? '0.00')); ?>" required>
        <input type="number" step="0.01" min="0" name="annual_price" class="form-control" placeholder="Annual price" value="<?php echo htmlspecialchars((string)($selectedPlan['annual_price'] ?? '0.00')); ?>" required>
        <input type="number" step="0.01" min="0" name="included_order_fee_percent" class="form-control" placeholder="Included fee %" value="<?php echo htmlspecialchars((string)($selectedPlan['included_order_fee_percent'] ?? '0.00')); ?>">
        <input type="number" step="0.01" min="0" name="included_order_fee_flat" class="form-control" placeholder="Flat fee per order" value="<?php echo htmlspecialchars((string)($selectedPlan['included_order_fee_flat'] ?? '0.00')); ?>">
        <input type="number" step="1" min="1" name="max_staff_accounts" class="form-control" placeholder="Max staff accounts" value="<?php echo htmlspecialchars((string)($selectedPlan['max_staff_accounts'] ?? '1')); ?>">
        <select name="is_active" class="form-select">
            <option value="1" <?php echo ((int)($selectedPlan['is_active'] ?? 1) === 1) ? 'selected' : ''; ?>>Active</option>
            <option value="0" <?php echo isset($selectedPlan['is_active']) && (int)$selectedPlan['is_active'] === 0 ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <textarea name="description" class="form-control" style="grid-column:1/-1;" rows="3" placeholder="What makes this plan valuable for partner shops?"><?php echo htmlspecialchars((string)($selectedPlan['description'] ?? '')); ?></textarea>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <label><input type="checkbox" name="includes_ai_automation" value="1" <?php echo ((int)($selectedPlan['includes_ai_automation'] ?? 0) === 1) ? 'checked' : ''; ?>> AI automation</label>
            <label><input type="checkbox" name="includes_priority_support" value="1" <?php echo ((int)($selectedPlan['includes_priority_support'] ?? 0) === 1) ? 'checked' : ''; ?>> Priority support</label>
            <label><input type="checkbox" name="includes_featured_placement" value="1" <?php echo ((int)($selectedPlan['includes_featured_placement'] ?? 0) === 1) ? 'checked' : ''; ?>> Featured placement</label>
            <label><input type="checkbox" name="includes_custom_branding" value="1" <?php echo ((int)($selectedPlan['includes_custom_branding'] ?? 0) === 1) ? 'checked' : ''; ?>> Custom branding</label>
        </div>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-layer-group"></i> Save Plan</button>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Partner Subscription Assignment</h3>
            <p class="module-subtext">Attach approved business partners to a plan, billing cycle, and subscription status.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Apply subscription to partner?" data-sa-confirm-text="This will update the partner's plan and expected recurring billing." data-sa-confirm-confirm-text="Save Subscription">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="assign_partner_plan">
        <select name="partner_user_id" class="form-select" required>
            <option value="">Select approved partner</option>
            <?php foreach ($partners as $partner): ?>
                <option value="<?php echo (int)$partner['partner_user_id']; ?>"><?php echo htmlspecialchars((string)$partner['business_name'] . ' - ' . (string)$partner['email']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="plan_id" class="form-select" required>
            <option value="">Select plan</option>
            <?php foreach ($plans as $plan): ?>
                <option value="<?php echo (int)$plan['id']; ?>"><?php echo htmlspecialchars((string)$plan['plan_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="billing_cycle" class="form-select">
            <option value="monthly">Monthly</option>
            <option value="annual">Annual</option>
        </select>
        <select name="subscription_status" class="form-select">
            <option value="active">Active</option>
            <option value="trial">Trial</option>
            <option value="past_due">Past Due</option>
            <option value="paused">Paused</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <input type="number" step="0.01" min="0" name="price_override" class="form-control" placeholder="Monthly override (optional)">
        <input type="date" name="started_at" class="form-control">
        <input type="date" name="renews_at" class="form-control">
        <textarea name="notes" class="form-control" style="grid-column:1/-1;" rows="2" placeholder="Internal notes about this pricing arrangement"></textarea>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-user-check"></i> Save Partner Subscription</button>
        </div>
    </form>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Default Platform Fee</h3>
                    <p class="module-subtext">Set the baseline percentage and flat fee charged to all partner stores.</p>
                </div>
            </div>
            <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Update default fee rule?" data-sa-confirm-text="This changes the fallback revenue rule for all partners without a custom fee." data-sa-confirm-confirm-text="Save Default Fee">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_fee_rule">
                <input type="hidden" name="rule_scope" value="global">
                <input type="text" name="rule_name" class="form-control" placeholder="Rule name" value="<?php echo htmlspecialchars((string)($globalRule['rule_name'] ?? 'Default platform fee')); ?>">
                <input type="number" step="0.01" min="0" name="fee_percent" class="form-control" placeholder="Fee percent" value="<?php echo htmlspecialchars((string)($globalRule['fee_percent'] ?? '6.00')); ?>" required>
                <input type="number" step="0.01" min="0" name="fee_flat_per_order" class="form-control" placeholder="Flat fee per order" value="<?php echo htmlspecialchars((string)($globalRule['fee_flat_per_order'] ?? '2.00')); ?>" required>
                <input type="date" name="effective_from" class="form-control" value="<?php echo htmlspecialchars((string)($globalRule['effective_from'] ?? date('Y-m-d'))); ?>">
                <input type="date" name="effective_to" class="form-control" value="<?php echo htmlspecialchars((string)($globalRule['effective_to'] ?? '')); ?>">
                <textarea name="notes" class="form-control" style="grid-column:1/-1;" rows="3" placeholder="Explain why this rate exists"><?php echo htmlspecialchars((string)($globalRule['notes'] ?? '')); ?></textarea>
                <div class="module-inline-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-percent"></i> Save Default Fee</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Custom Partner Fee</h3>
                    <p class="module-subtext">Override the default fee for a specific store if you want a premium or negotiated rate.</p>
                </div>
            </div>
            <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title="Save custom fee rule?" data-sa-confirm-text="This partner will use a custom monetization rate instead of the global default." data-sa-confirm-confirm-text="Save Custom Fee">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_fee_rule">
                <input type="hidden" name="rule_scope" value="partner">
                <select name="partner_user_id" class="form-select" required>
                    <option value="">Select approved partner</option>
                    <?php foreach ($partners as $partner): ?>
                        <option value="<?php echo (int)$partner['partner_user_id']; ?>"><?php echo htmlspecialchars((string)$partner['business_name'] . ' - ' . (string)$partner['email']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="rule_name" class="form-control" placeholder="Rule name" value="Partner custom fee">
                <input type="number" step="0.01" min="0" name="fee_percent" class="form-control" placeholder="Fee percent" required>
                <input type="number" step="0.01" min="0" name="fee_flat_per_order" class="form-control" placeholder="Flat fee per order" required>
                <input type="date" name="effective_from" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                <input type="date" name="effective_to" class="form-control">
                <textarea name="notes" class="form-control" style="grid-column:1/-1;" rows="3" placeholder="Reason for the special rate"></textarea>
                <div class="module-inline-actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-sliders"></i> Save Custom Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Plan Catalog</h3>
            <p class="module-subtext">See how each subscription tier is priced and how many partner stores are currently attached.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Plan</th><th>Monthly</th><th>Annual</th><th>Included Fee</th><th>Staff</th><th>Features</th><th>Subscribers</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (empty($plans)): ?>
                <tr><td colspan="9" class="text-center text-muted">No monetization plans configured yet.</td></tr>
            <?php else: foreach ($plans as $plan): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$plan['plan_name']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)($plan['description'] ?? '')); ?></span></td>
                    <td><?php echo saFormatCurrency($plan['monthly_price'] ?? 0); ?></td>
                    <td><?php echo saFormatCurrency($plan['annual_price'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars(number_format((float)($plan['included_order_fee_percent'] ?? 0), 2) . '% + ' . number_format((float)($plan['included_order_fee_flat'] ?? 0), 2)); ?></td>
                    <td><?php echo number_format((int)($plan['max_staff_accounts'] ?? 1)); ?></td>
                    <td class="compact-text">
                        <?php echo (int)($plan['includes_ai_automation'] ?? 0) === 1 ? 'AI, ' : ''; ?>
                        <?php echo (int)($plan['includes_priority_support'] ?? 0) === 1 ? 'Priority, ' : ''; ?>
                        <?php echo (int)($plan['includes_featured_placement'] ?? 0) === 1 ? 'Featured, ' : ''; ?>
                        <?php echo (int)($plan['includes_custom_branding'] ?? 0) === 1 ? 'Branding' : 'Core tools'; ?>
                    </td>
                    <td><?php echo number_format((int)($plan['active_subscribers'] ?? 0)); ?></td>
                    <td><span class="status-chip <?php echo (int)($plan['is_active'] ?? 0) === 1 ? 'chip-success' : 'chip-muted'; ?>"><?php echo (int)($plan['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="../super_admin/platform_monetization.php?edit_plan=<?php echo (int)$plan['id']; ?>">Edit</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Partner Revenue Desk</h3>
            <p class="module-subtext">Projected monthly earnings per shop using current plan assignments and current platform fee rules.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Partner</th><th>Plan</th><th>Subscription</th><th>Orders This Month</th><th>Gross Sales</th><th>Fee Rule</th><th>Order Fee Revenue</th><th>Total Platform Revenue</th></tr></thead>
            <tbody>
            <?php if (empty($partnerRows)): ?>
                <tr><td colspan="8" class="text-center text-muted">No approved partners available for monetization analysis.</td></tr>
            <?php else: foreach ($partnerRows as $row): ?>
                <?php
                    $subChip = 'chip-muted';
                    if (in_array((string)$row['subscription_status'], ['active', 'trial'], true)) {
                        $subChip = 'chip-success';
                    } elseif (in_array((string)$row['subscription_status'], ['past_due', 'paused'], true)) {
                        $subChip = 'chip-warning';
                    } elseif ((string)$row['subscription_status'] === 'cancelled') {
                        $subChip = 'chip-danger';
                    }
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$row['business_name']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)$row['email']); ?></span></td>
                    <td><?php echo htmlspecialchars((string)$row['subscription_plan']); ?></td>
                    <td><span class="status-chip <?php echo $subChip; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$row['subscription_status']))); ?></span><br><span class="compact-text"><?php echo htmlspecialchars(ucfirst((string)$row['billing_cycle'])); ?></span></td>
                    <td><?php echo number_format((int)$row['monthly_order_count']); ?></td>
                    <td><?php echo saFormatCurrency($row['monthly_gross_sales'] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars(number_format((float)$row['fee_percent'], 2) . '% + ' . number_format((float)$row['fee_flat_per_order'], 2)); ?></td>
                    <td><?php echo saFormatCurrency($row['estimated_order_fee_revenue'] ?? 0); ?><br><span class="compact-text">Sub: <?php echo saFormatCurrency($row['monthly_subscription_revenue'] ?? 0); ?></span></td>
                    <td><strong><?php echo saFormatCurrency($row['projected_platform_revenue'] ?? 0); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars(saFormatDateTime($row['last_order_at'] ?? null, 'M d, Y h:i A', 'No recent order')); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Active Subscriptions</h3>
                    <p class="module-subtext">Current partner plan assignments and renewal timing.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="module-table">
                    <thead><tr><th>Partner</th><th>Plan</th><th>Status</th><th>Renews</th><th>Override</th></tr></thead>
                    <tbody>
                    <?php if (empty($subscriptions)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No subscription assignments yet.</td></tr>
                    <?php else: foreach ($subscriptions as $subscription): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string)$subscription['business_name']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)$subscription['email']); ?></span></td>
                            <td><?php echo htmlspecialchars((string)$subscription['plan_name']); ?><br><span class="compact-text"><?php echo htmlspecialchars(ucfirst((string)$subscription['billing_cycle'])); ?></span></td>
                            <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$subscription['subscription_status']))); ?></td>
                            <td><?php echo htmlspecialchars(saFormatDateTime($subscription['renews_at'] ?? null, 'M d, Y', '-')); ?></td>
                            <td><?php echo ($subscription['price_override'] ?? null) !== null ? saFormatCurrency($subscription['price_override']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="module-section" style="height: 100%;">
            <div class="module-section-header">
                <div>
                    <h3>Active Fee Rules</h3>
                    <p class="module-subtext">Global and custom partner take-rate settings currently affecting revenue calculations.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="module-table">
                    <thead><tr><th>Scope</th><th>Partner</th><th>Rate</th><th>Effective</th></tr></thead>
                    <tbody>
                    <?php if (empty($feeRules)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No platform fee rules configured.</td></tr>
                    <?php else: foreach ($feeRules as $rule): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(ucfirst((string)$rule['rule_scope'])); ?></td>
                            <td><?php echo htmlspecialchars((string)$rule['business_name']); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)($rule['fee_percent'] ?? 0), 2) . '% + ' . number_format((float)($rule['fee_flat_per_order'] ?? 0), 2)); ?></td>
                            <td><?php echo htmlspecialchars(saFormatDateTime($rule['effective_from'] ?? null, 'M d, Y', '-')); ?><br><span class="compact-text"><?php echo htmlspecialchars(saFormatDateTime($rule['effective_to'] ?? null, 'M d, Y', 'Open-ended')); ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Billing Ledger</h3>
            <p class="module-subtext">Actual invoice records that convert projected revenue into collected, due, and overdue billing history.</p>
        </div>
    </div>
    <?php if (!empty($subscriptionRequests)): ?>
        <div class="module-section-header" style="margin-top:8px;">
            <div>
                <h4>Partner Subscription Request Queue</h4>
                <p class="module-subtext">Review partner shop requests for new subscriptions, renewals, upgrades, and plan changes.</p>
            </div>
        </div>
        <div class="table-wrap mb-4">
            <table class="module-table">
                <thead><tr><th>Business</th><th>Requested Plan</th><th>Type</th><th>Cycle</th><th>Status</th><th>Requested</th><th>Review</th></tr></thead>
                <tbody>
                <?php foreach ($subscriptionRequests as $request): ?>
                    <?php
                        $requestStatus = (string)($request['request_status'] ?? 'pending');
                        $requestChip = 'chip-warning';
                        if ($requestStatus === 'approved') {
                            $requestChip = 'chip-success';
                        } elseif ($requestStatus === 'rejected') {
                            $requestChip = 'chip-danger';
                        } elseif ($requestStatus === 'cancelled') {
                            $requestChip = 'chip-muted';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)($request['business_name'] ?? 'Partner Shop')); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)($request['email'] ?? '')); ?></span></td>
                        <td><?php echo htmlspecialchars((string)($request['requested_plan_name'] ?? 'Selected Plan')); ?><br><span class="compact-text"><?php echo htmlspecialchars((string)($request['current_plan_name'] ?? 'Current: Unassigned')); ?></span></td>
                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($request['request_type'] ?? 'change_plan')))); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst((string)($request['requested_billing_cycle'] ?? 'monthly'))); ?></td>
                        <td><span class="status-chip <?php echo $requestChip; ?>"><?php echo htmlspecialchars(ucfirst($requestStatus)); ?></span></td>
                        <td><?php echo htmlspecialchars(saFormatDateTime($request['created_at'] ?? null, 'M d, Y h:i A', '-')); ?></td>
                        <td>
                            <?php if ($requestStatus === 'pending'): ?>
                                <form method="post" class="module-form-grid" style="grid-template-columns:1fr;min-width:260px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="review_subscription_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <select name="decision" class="form-select" required>
                                        <option value="approved">Approve</option>
                                        <option value="rejected">Reject</option>
                                        <option value="cancelled">Cancel</option>
                                    </select>
                                    <textarea name="review_notes" rows="2" class="form-control" placeholder="Optional review note"></textarea>
                                    <button type="submit" class="btn btn-sm btn-primary">Submit Decision</button>
                                </form>
                            <?php else: ?>
                                <span class="compact-text"><?php echo htmlspecialchars((string)($request['review_notes'] ?? '-')); ?></span><br>
                                <span class="compact-text">Reviewed by <?php echo htmlspecialchars((string)($request['reviewed_by_name'] ?? 'Owner')); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Invoice</th><th>Partner</th><th>Period</th><th>Subscription</th><th>Order Fees</th><th>Total</th><th>Status</th><th>Due</th><th>Payment</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($invoices)): ?>
                <tr><td colspan="10" class="text-center text-muted">No billing invoices generated yet.</td></tr>
            <?php else: foreach ($invoices as $invoice): ?>
                <?php
                    $invoiceStatus = (string)($invoice['invoice_status'] ?? 'issued');
                    $invoiceChip = 'chip-warning';
                    if ($invoiceStatus === 'paid') {
                        $invoiceChip = 'chip-success';
                    } elseif ($invoiceStatus === 'overdue') {
                        $invoiceChip = 'chip-danger';
                    } elseif (in_array($invoiceStatus, ['draft', 'void'], true)) {
                        $invoiceChip = 'chip-muted';
                    }
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$invoice['invoice_number']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars(ucfirst((string)($invoice['invoice_type'] ?? 'combined'))); ?></span></td>
                    <td><strong><?php echo htmlspecialchars((string)$invoice['business_name']); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)($invoice['email'] ?? '')); ?></span></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($invoice['period_start'] ?? null, 'M d, Y', '-')); ?><br><span class="compact-text">to <?php echo htmlspecialchars(saFormatDateTime($invoice['period_end'] ?? null, 'M d, Y', '-')); ?></span></td>
                    <td><?php echo saFormatCurrency($invoice['subscription_amount'] ?? 0); ?></td>
                    <td><?php echo saFormatCurrency($invoice['order_fee_amount'] ?? 0); ?></td>
                    <td><strong><?php echo saFormatCurrency($invoice['total_amount'] ?? 0); ?></strong></td>
                    <td><span class="status-chip <?php echo $invoiceChip; ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $invoiceStatus))); ?></span></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($invoice['due_at'] ?? null, 'M d, Y h:i A', '-')); ?></td>
                    <td><?php echo htmlspecialchars((string)($invoice['payment_reference'] ?? '')); ?><br><span class="compact-text"><?php echo htmlspecialchars((string)($invoice['payment_channel'] ?? '')); ?></span></td>
                    <td>
                        <div class="module-inline-actions">
                            <a href="../billing_invoice_view.php?invoice_id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="../billing_invoice_pdf.php?invoice_id=<?php echo (int)$invoice['id']; ?>" class="btn btn-sm btn-outline-primary">PDF</a>
                            <?php if (!in_array($invoiceStatus, ['paid', 'void'], true)): ?>
                                <form method="post" data-sa-confirm="1" data-sa-confirm-title="Verify invoice payment?" data-sa-confirm-text="This checks the latest PayMongo session for this invoice and updates the live payment state if it has been paid." data-sa-confirm-confirm-text="Verify Payment">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="verify_invoice_payment">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark">Verify</button>
                                </form>
                                <form method="post" data-sa-confirm="1" data-sa-confirm-title="Send invoice reminder?" data-sa-confirm-text="This sends an in-app and email reminder to the partner for this specific invoice." data-sa-confirm-confirm-text="Send Reminder">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="send_single_invoice_reminder">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>">
                                    <input type="hidden" name="reminder_type" value="<?php echo htmlspecialchars($invoiceStatus === 'overdue' ? 'overdue' : 'manual'); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Remind</button>
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
<?php
$extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extra_scripts .= '<script>';
$extra_scripts .= 'const monetizationLabels = ' . json_encode($monthlySeries['labels'] ?? []) . ';';
$extra_scripts .= 'const monetizationOrderFees = ' . json_encode($monthlySeries['order_fee_revenue'] ?? []) . ';';
$extra_scripts .= 'const monetizationSubscriptions = ' . json_encode($monthlySeries['subscription_revenue'] ?? []) . ';';
$extra_scripts .= 'const monetizationMrr = ' . json_encode((float)($metrics['mrr'] ?? 0)) . ';';
$extra_scripts .= 'const monetizationOrderFeeMonth = ' . json_encode((float)($metrics['estimated_order_fee_month'] ?? 0)) . ';';
$extra_scripts .= <<<JS
const monetizationCurrency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const monetizationRevenueChart = document.getElementById('monetizationRevenueChart');
if (monetizationRevenueChart) {
    new Chart(monetizationRevenueChart, {
        type: 'bar',
        data: {
            labels: monetizationLabels,
            datasets: [
                {
                    label: 'Order Fee Revenue',
                    data: monetizationOrderFees,
                    backgroundColor: 'rgba(14, 165, 233, 0.75)',
                    borderRadius: 8
                },
                {
                    label: 'Subscription Revenue Baseline',
                    data: monetizationSubscriptions,
                    backgroundColor: 'rgba(22, 163, 74, 0.75)',
                    borderRadius: 8
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => monetizationCurrency.format(Number(value || 0))
                    }
                }
            }
        }
    });
}

const monetizationMixChart = document.getElementById('monetizationMixChart');
if (monetizationMixChart) {
    new Chart(monetizationMixChart, {
        type: 'doughnut',
        data: {
            labels: ['MRR Baseline', 'Order Fee Revenue'],
            datasets: [{
                data: [monetizationMrr, monetizationOrderFeeMonth],
                backgroundColor: ['#16a34a', '#0ea5e9']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}
JS;
$extra_scripts .= '</script>';

saRenderModuleFooter($extra_scripts);
?>
