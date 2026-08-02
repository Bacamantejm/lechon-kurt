<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_decision_support.php');
    if (($action = strtolower(trim((string)($_POST['action'] ?? '')))) === 'capture_snapshot') {
        if ($opsService->captureMetricSnapshot()) {
            saSetFlash('success', 'Snapshot captured for decision support trends.');
        } else {
            saSetFlash('danger', 'Unable to capture snapshot.');
        }
    }
    header('Location: operations_decision_support.php');
    exit;
}

$decision = $opsService->getDecisionSupportSummary();

saRenderModuleHeader('Operations Decision Support', 'Operations Decision Support', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Decision Support Summary</h2>
            <p class="module-subtext">Review current load, trend snapshots, and recommended next actions.</p>
        </div>
        <form method="post" class="module-inline-actions">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="capture_snapshot">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-camera-retro"></i> Capture Trend Point</button>
        </form>
    </div>
    <div class="metrics-grid">
        <div class="metric-card"><span class="metric-label">Active Users</span><div class="metric-value"><?php echo number_format((int)$decision['metrics']['active_users_24h']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Transactions</span><div class="metric-value"><?php echo number_format((int)$decision['metrics']['transactions_today']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Open Complaints</span><div class="metric-value"><?php echo number_format((int)$decision['metrics']['open_complaints']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Pending Businesses</span><div class="metric-value"><?php echo number_format((int)$decision['metrics']['pending_businesses']); ?></div></div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Recommended Actions</h3>
            <p class="module-subtext">Data-informed guidance for operations prioritization.</p>
        </div>
    </div>
    <?php foreach ($decision['recommendations'] as $recommendation): ?>
        <div class="note-box" style="margin-bottom:10px;"><?php echo htmlspecialchars((string)$recommendation); ?></div>
    <?php endforeach; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Trend Snapshots</h3>
            <p class="module-subtext">Hourly/daily points for usage, complaints, and approval backlog.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Date</th><th>Hour</th><th>Active Users</th><th>Transactions</th><th>Revenue</th><th>Complaints</th><th>Pending Businesses</th></tr></thead>
            <tbody>
            <?php if (empty($decision['snapshots'])): ?>
                <tr><td colspan="7" class="text-center text-muted">No snapshots captured yet.</td></tr>
            <?php else: foreach ($decision['snapshots'] as $snapshot): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$snapshot['snapshot_date']); ?></td>
                    <td><?php echo number_format((int)$snapshot['snapshot_hour']); ?>:00</td>
                    <td><?php echo number_format((int)$snapshot['active_users']); ?></td>
                    <td><?php echo number_format((int)$snapshot['transactions_count']); ?></td>
                    <td><?php echo saFormatCurrency($snapshot['gross_revenue']); ?></td>
                    <td><?php echo number_format((int)$snapshot['open_complaints']); ?></td>
                    <td><?php echo number_format((int)$snapshot['pending_businesses']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

