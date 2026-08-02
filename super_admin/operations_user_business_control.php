<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);
$insights = $opsService->getUserBusinessInsights();

saRenderModuleHeader('Operations User & Business Control', 'Operations User & Business Control', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>User and Business Oversight</h2>
            <p class="module-subtext">See partner risk, watchlisted users, and operational workload across marketplace accounts.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card"><span class="metric-label">Total Users</span><div class="metric-value"><?php echo number_format((int)$insights['metrics']['total_users']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Active Businesses</span><div class="metric-value"><?php echo number_format((int)$insights['metrics']['active_businesses']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Pending Businesses</span><div class="metric-value"><?php echo number_format((int)$insights['metrics']['pending_businesses']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Watchlist Entries</span><div class="metric-value"><?php echo number_format((int)$insights['metrics']['watchlist_entries']); ?></div></div>
    </div>
    <div class="module-inline-actions">
        <?php if ($is_super_admin_user): ?>
            <a href="../super_admin/user_business_management.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-users-cog"></i> Owner User & Business Module</a>
            <a href="../super_admin/reports_complaints.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-triangle-exclamation"></i> Complaints Desk</a>
            <a href="../super_admin/franchise_applications.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-contract"></i> Business Applications</a>
        <?php else: ?>
            <a href="../super_admin/operations_dashboard.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-gauge-high"></i> Operations Dashboard</a>
            <a href="../super_admin/operations_incidents.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-triangle-exclamation"></i> Incidents & Alerts</a>
        <?php endif; ?>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Business Risk Watch</h3>
            <p class="module-subtext">Monitor approved partners with active warnings or restrictive account status.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Business</th><th>Email</th><th>Warnings</th><th>Account Control</th></tr></thead>
            <tbody>
            <?php if (empty($insights['business_risk'])): ?>
                <tr><td colspan="4" class="text-center text-muted">No partner risk records available.</td></tr>
            <?php else: foreach ($insights['business_risk'] as $business): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$business['business_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars((string)$business['email']); ?></td>
                    <td><?php echo number_format((int)($business['active_warnings'] ?? 0)); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)($business['account_control_status'] ?? 'active'))); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>User Watchlist</h3>
            <p class="module-subtext">Accounts or entities that need closer operational review.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Entity</th><th>Reason</th><th>Risk</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php if (empty($insights['watchlist'])): ?>
                <tr><td colspan="5" class="text-center text-muted">No active watchlist entries.</td></tr>
            <?php else: foreach ($insights['watchlist'] as $entry): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)($entry['full_name'] ?? ('Entity #' . (int)$entry['entity_id']))); ?></strong><br><span class="compact-text"><?php echo htmlspecialchars((string)($entry['email'] ?? strtoupper((string)$entry['entity_type']))); ?></span></td>
                    <td><?php echo htmlspecialchars((string)$entry['reason']); ?></td>
                    <td><span class="status-chip <?php echo in_array($entry['risk_level'], ['critical','high'], true) ? 'chip-danger' : 'chip-warning'; ?>"><?php echo htmlspecialchars(ucfirst((string)$entry['risk_level'])); ?></span></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$entry['watch_status'])); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($entry['created_at'] ?? null)); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

