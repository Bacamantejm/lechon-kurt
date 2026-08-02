<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_incidents.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'create_incident') {
        if ($opsService->createIncident($_POST, $current_admin_id)) {
            saSetFlash('success', 'Operational incident created.');
        } else {
            saSetFlash('danger', 'Unable to create incident.');
        }
    } elseif ($action === 'ack_alert') {
        if ($opsService->acknowledgeAlert((int)($_POST['alert_id'] ?? 0), $current_admin_id)) {
            saSetFlash('success', 'Alert acknowledged.');
        } else {
            saSetFlash('danger', 'Unable to acknowledge alert.');
        }
    } elseif ($action === 'update_incident') {
        if ($opsService->updateIncidentStatus((int)($_POST['incident_id'] ?? 0), (string)($_POST['status'] ?? 'open'), $current_admin_id, (string)($_POST['note'] ?? ''))) {
            saSetFlash('success', 'Incident updated.');
        } else {
            saSetFlash('danger', 'Unable to update incident.');
        }
    }

    header('Location: operations_incidents.php');
    exit;
}

$alerts = $opsService->getAlerts(20);
$incidents = $opsService->getIncidents(30);

saRenderModuleHeader('Operations Incidents', 'Operations Incidents & Alerts', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Create Incident</h2>
            <p class="module-subtext">Log a new operational issue for investigation and accountability.</p>
        </div>
    </div>
    <form method="post" class="module-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="create_incident">
        <input type="text" name="title" class="form-control" placeholder="Incident title" required>
        <select name="category" class="form-select">
            <option value="system">System</option><option value="security">Security</option><option value="business">Business</option><option value="user">User</option><option value="content">Content</option><option value="data">Data</option>
        </select>
        <select name="severity" class="form-select">
            <option value="medium">Medium</option><option value="low">Low</option><option value="high">High</option><option value="critical">Critical</option>
        </select>
        <input type="text" name="source_module" class="form-control" placeholder="Source module (optional)">
        <textarea name="description" class="form-control" style="grid-column:1/-1;" rows="3" placeholder="Describe the issue, impact, and what needs attention."></textarea>
        <div class="module-inline-actions" style="grid-column:1/-1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Incident</button>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Operational Alerts</h3>
            <p class="module-subtext">Acknowledge threshold-based alerts after review.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Severity</th><th>Title</th><th>Message</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (empty($alerts)): ?>
                <tr><td colspan="5" class="text-center text-muted">No alerts found.</td></tr>
            <?php else: foreach ($alerts as $alert): ?>
                <tr>
                    <td><span class="status-chip <?php echo in_array($alert['severity'], ['critical','high'], true) ? 'chip-danger' : 'chip-warning'; ?>"><?php echo htmlspecialchars(ucfirst((string)$alert['severity'])); ?></span></td>
                    <td><strong><?php echo htmlspecialchars((string)$alert['title']); ?></strong></td>
                    <td><?php echo htmlspecialchars((string)$alert['message']); ?></td>
                    <td><?php echo (int)($alert['is_acknowledged'] ?? 0) === 1 ? 'Acknowledged' : 'Open'; ?></td>
                    <td>
                        <?php if ((int)($alert['is_acknowledged'] ?? 0) !== 1): ?>
                            <form method="post" class="module-inline-actions">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="ack_alert">
                                <input type="hidden" name="alert_id" value="<?php echo (int)$alert['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Acknowledge</button>
                            </form>
                        <?php else: ?>
                            <?php echo htmlspecialchars((string)($alert['acknowledged_name'] ?? 'Reviewed')); ?>
                        <?php endif; ?>
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
            <h3>Incident Queue</h3>
            <p class="module-subtext">Track investigation status, severity, and operations notes.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>Code</th><th>Category</th><th>Severity</th><th>Title</th><th>Status</th><th>Detected</th><th>Update</th></tr></thead>
            <tbody>
            <?php if (empty($incidents)): ?>
                <tr><td colspan="7" class="text-center text-muted">No incidents logged.</td></tr>
            <?php else: foreach ($incidents as $incident): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars((string)$incident['incident_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars(ucfirst((string)$incident['category'])); ?></td>
                    <td><span class="status-chip <?php echo in_array($incident['severity'], ['critical','high'], true) ? 'chip-danger' : 'chip-info'; ?>"><?php echo htmlspecialchars(ucfirst((string)$incident['severity'])); ?></span></td>
                    <td><?php echo htmlspecialchars((string)$incident['title']); ?><br><span class="compact-text"><?php echo htmlspecialchars((string)($incident['description'] ?? '')); ?></span></td>
                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$incident['status']))); ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($incident['detected_at'] ?? null)); ?></td>
                    <td>
                        <form method="post" class="module-inline-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_incident">
                            <input type="hidden" name="incident_id" value="<?php echo (int)$incident['id']; ?>">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (['open','investigating','resolved','closed'] as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $status === (string)$incident['status'] ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Ops note">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

