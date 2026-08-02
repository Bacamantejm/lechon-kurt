<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_logs_backups.php');
    if (($action = strtolower(trim((string)($_POST['action'] ?? '')))) === 'log_backup') {
        if ($opsService->logBackupCheck($_POST, $current_admin_id)) {
            saSetFlash('success', 'Backup verification log saved.');
        } else {
            saSetFlash('danger', 'Unable to save backup verification log.');
        }
    }
    header('Location: operations_logs_backups.php');
    exit;
}

$auditEvents = $opsService->getAuditEvents(25);
$backupLog = $opsService->getBackupLog(20);
$logFiles = $opsService->getLogFiles(12);

saRenderModuleHeader('Operations Logs & Backups', 'Operations Logs & Backups', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Log Backup Verification</h2>
            <p class="module-subtext">Record manual or scheduled backup checks for audit and recovery readiness.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="log_backup">
        <select name="backup_type" class="form-select">
            <option value="database">Database</option><option value="uploads">Uploads</option><option value="full_system">Full System</option>
        </select>
        <select name="backup_status" class="form-select">
            <option value="success">Success</option><option value="failed">Failed</option><option value="pending">Pending</option>
        </select>
        <input type="text" name="file_name" class="form-control" placeholder="Backup file name or batch label">
        <input type="text" name="storage_path" class="form-control" placeholder="Storage path / location">
        <textarea name="notes" class="form-control" style="grid-column:1/-1;" rows="3" placeholder="Verification notes"></textarea>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-database"></i> Save Backup Log</button>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Recent Audit Events</h3>
            <p class="module-subtext">Operationally relevant activity trail from the existing audit log.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>User</th><th>Action</th><th>Module</th><th>Description</th><th>When</th></tr></thead>
            <tbody>
            <?php if (empty($auditEvents)): ?>
                <tr><td colspan="5" class="text-center text-muted">No audit log events found.</td></tr>
            <?php else: foreach ($auditEvents as $event): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($event['full_name'] ?? ('User #' . (int)$event['user_id']))); ?></td>
                    <td><?php echo htmlspecialchars((string)$event['action']); ?></td>
                    <td><?php echo htmlspecialchars((string)$event['module']); ?></td>
                    <td><?php echo htmlspecialchars((string)$event['description']); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($event['created_at'] ?? null)); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Backup Records & Log Files</h3>
            <p class="module-subtext">Recent backup verifications and the newest files from the logs directory.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card"><span class="metric-label">Backup Entries</span><div class="metric-value"><?php echo number_format(count($backupLog)); ?></div></div>
        <div class="metric-card"><span class="metric-label">Log Files Found</span><div class="metric-value"><?php echo number_format(count($logFiles)); ?></div></div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Type</th><th>Name</th><th>Status / Size</th><th>Details</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($backupLog as $backup): ?>
                <tr>
                    <td>Backup</td>
                    <td><?php echo htmlspecialchars((string)$backup['file_name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$backup['backup_status'])); ?></td>
                    <td><?php echo htmlspecialchars((string)$backup['storage_path']); ?><br><span class="compact-text"><?php echo htmlspecialchars((string)$backup['notes']); ?></span></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($backup['created_at'] ?? null)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($logFiles as $file): ?>
                <tr>
                    <td>Log File</td>
                    <td><?php echo htmlspecialchars((string)$file['name']); ?></td>
                    <td><?php echo number_format((float)$file['size']); ?> bytes</td>
                    <td>Last modified</td>
                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', (int)$file['modified_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($backupLog) && empty($logFiles)): ?>
                <tr><td colspan="5" class="text-center text-muted">No backup or log records available.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

