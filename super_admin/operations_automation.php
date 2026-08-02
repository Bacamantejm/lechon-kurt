<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_automation.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'toggle_rule') {
        if ($opsService->toggleRule((int)($_POST['rule_id'] ?? 0), ((int)($_POST['is_active'] ?? 0) === 1))) {
            saSetFlash('success', 'Automation rule updated.');
        } else {
            saSetFlash('danger', 'Unable to update rule.');
        }
    } elseif ($action === 'queue_job') {
        $job_name = trim((string)($_POST['job_name'] ?? 'Operational Job'));
        $job_type = trim((string)($_POST['job_type'] ?? 'digest'));
        if ($opsService->enqueueJob($job_name, $job_type, ['source' => 'manual', 'requested_at' => date('c')], $current_admin_id)) {
            saSetFlash('success', 'Automation job queued.');
        } else {
            saSetFlash('danger', 'Unable to queue automation job.');
        }
    }

    header('Location: operations_automation.php');
    exit;
}

$rules = $opsService->getRules();
$jobs = $opsService->getJobs(25);

saRenderModuleHeader('Operations Automation', 'Operations Automation', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Queue Manual Job</h2>
            <p class="module-subtext">Trigger repeatable operational work like digest generation or verification checks.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="queue_job">
        <input type="text" name="job_name" class="form-control" placeholder="Job name" required>
        <select name="job_type" class="form-select">
            <option value="digest">Digest</option><option value="backup_check">Backup Check</option><option value="alert_review">Alert Review</option><option value="approval_followup">Approval Follow-up</option>
        </select>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-play-circle"></i> Queue Job</button>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Automation Rules</h3>
            <p class="module-subtext">Thresholds and decision logic that power operational alerts and reminders.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Rule</th><th>Type</th><th>Last Run</th><th>Status</th><th>Toggle</th></tr></thead>
            <tbody>
            <?php if (empty($rules)): ?>
                <tr><td colspan="5" class="text-center text-muted">No rules configured.</td></tr>
            <?php else: foreach ($rules as $rule): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$rule['rule_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$rule['rule_type'])); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($rule['last_run_at'] ?? null, 'M d, Y h:i A', 'Never')); ?></td>
                    <td><?php echo (int)($rule['is_active'] ?? 0) === 1 ? 'Active' : 'Paused'; ?></td>
                    <td>
                        <form method="post" class="module-inline-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="toggle_rule">
                            <input type="hidden" name="rule_id" value="<?php echo (int)$rule['id']; ?>">
                            <input type="hidden" name="is_active" value="<?php echo (int)($rule['is_active'] ?? 0) === 1 ? '0' : '1'; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><?php echo (int)($rule['is_active'] ?? 0) === 1 ? 'Pause' : 'Activate'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Automation Jobs</h3>
            <p class="module-subtext">Queued and historical tasks for operations workload automation.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Job</th><th>Type</th><th>Status</th><th>Owner</th><th>Created</th></tr></thead>
            <tbody>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="5" class="text-center text-muted">No jobs in the automation queue.</td></tr>
            <?php else: foreach ($jobs as $job): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$job['job_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$job['job_type'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$job['status'])); ?></td>
                    <td><?php echo htmlspecialchars((string)($job['created_name'] ?? 'System')); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($job['created_at'] ?? null)); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

