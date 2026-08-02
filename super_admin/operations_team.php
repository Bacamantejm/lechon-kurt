<?php
require_once __DIR__ . '/module_common.php';
require_once __DIR__ . '/../includes/OperationalManagerService.php';

if (!$is_super_admin_user) {
    denyAdminAccess('Access Denied: Only the system owner can manage operations team roles.');
}

$opsService = new OperationalManagerService($conn, $operations_scope_owner_id);
$opsService->ensureReady($current_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', 'operations_team.php');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $target_user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'assign_operational_role') {
        $role_name = trim((string)($_POST['role_name'] ?? ''));
        if ($opsService->assignOperationalRoleToUser($target_user_id, $role_name)) {
            saSetFlash('success', 'Operational role assigned successfully.');
            saLogAudit(
                $conn,
                $current_admin_id,
                'OPERATIONS_ROLE_ASSIGNED',
                'operations_team',
                "Assigned {$role_name} to user #{$target_user_id}."
            );
        } else {
            saSetFlash('danger', 'Unable to assign the requested operational role.');
        }
    } elseif ($action === 'clear_operational_role') {
        if ($opsService->clearOperationalRoleFromUser($target_user_id)) {
            saSetFlash('success', 'Operational role removed successfully.');
            saLogAudit(
                $conn,
                $current_admin_id,
                'OPERATIONS_ROLE_REMOVED',
                'operations_team',
                "Removed operational access from user #{$target_user_id}."
            );
        } else {
            saSetFlash('danger', 'Unable to remove operational access from that user.');
        }
    } else {
        saSetFlash('warning', 'Unsupported operations team action.');
    }

    header('Location: operations_team.php');
    exit;
}

$summary = $opsService->getOperationalRoleSummary();
$team = $opsService->getOperationalTeam(120);
$candidates = $opsService->getOperationalRoleCandidates(250);
$roles = $opsService->getOperationalRoles();
$search = trim((string)($_GET['search'] ?? ''));

$filtered_candidates = [];
foreach ($candidates as $candidate) {
    $haystack = strtolower(
        trim(
            (string)($candidate['full_name'] ?? '') . ' ' .
            (string)($candidate['email'] ?? '') . ' ' .
            (string)($candidate['role_name'] ?? '') . ' ' .
            (string)($candidate['user_type'] ?? '')
        )
    );
    if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
        continue;
    }
    $filtered_candidates[] = $candidate;
}

saRenderModuleHeader('Operations Team & Roles', 'Operations Team & Roles', $admin_info);
?>
<div class="module-section ops-highlight-card">
    <div class="module-section-header">
        <div>
            <h2>Seed and Manage Operations Access</h2>
            <p class="module-subtext">Assign operational managers and staff so the new live operations dashboard can be tested right away.</p>
        </div>
        <span class="ops-tag"><i class="fas fa-user-shield"></i> Super Admin Only</span>
    </div>
    <div class="metrics-grid">
        <div class="metric-card"><span class="metric-label">Operational Managers</span><div class="metric-value"><?php echo number_format((int)$summary['operational_manager']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Operations Staff</span><div class="metric-value"><?php echo number_format((int)$summary['operations_staff']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Total Assigned</span><div class="metric-value"><?php echo number_format((int)$summary['total_assigned']); ?></div></div>
        <div class="metric-card"><span class="metric-label">Search Results</span><div class="metric-value"><?php echo number_format(count($filtered_candidates)); ?></div></div>
    </div>
    <form method="post" class="module-form-grid" data-sa-confirm="1" data-sa-confirm-title-template="Assign {field_label:role_name}?" data-sa-confirm-text-template="This will update the selected user's back-office access to {field_label:role_name}." data-sa-confirm-confirm-text="Assign Role">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="assign_operational_role">
        <select name="user_id" class="form-select" required>
            <option value="">Select user account</option>
            <?php foreach ($filtered_candidates as $candidate): ?>
                <option value="<?php echo (int)$candidate['id']; ?>">
                    <?php echo htmlspecialchars((string)$candidate['full_name'] . ' - ' . (string)$candidate['email'] . (!empty($candidate['role_name']) ? ' [' . (string)$candidate['role_name'] . ']' : ' [no role]')); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="role_name" class="form-select" required>
            <option value="">Select operational role</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo htmlspecialchars((string)$role['name']); ?>">
                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$role['name']))); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="module-inline-actions">
            <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Assign Operational Role</button>
            <a href="../super_admin/operations_dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
        </div>
    </form>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Current Operations Team</h3>
            <p class="module-subtext">Adjust assigned operations users without opening the main RBAC module.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>User</th><th>Current Role</th><th>Status</th><th>Last Login</th><th>Change Role</th><th>Remove</th></tr></thead>
            <tbody>
            <?php if (empty($team)): ?>
                <tr><td colspan="6" class="text-center text-muted">No operations users assigned yet.</td></tr>
            <?php else: foreach ($team as $member): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars((string)$member['full_name']); ?></strong><br>
                        <span class="compact-text"><?php echo htmlspecialchars((string)$member['email']); ?> | <?php echo htmlspecialchars(ucfirst((string)($member['user_type'] ?? 'user'))); ?></span>
                    </td>
                    <td><span class="status-chip <?php echo (string)$member['role_name'] === 'operational_manager' ? 'chip-info' : 'chip-warning'; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$member['role_name']))); ?></span></td>
                    <td><?php echo (int)($member['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($member['last_login'] ?? null, 'M d, Y h:i A', 'Never')); ?></td>
                    <td>
                        <form method="post" class="module-inline-actions" data-sa-confirm="1" data-sa-confirm-title-template="Switch to {field_label:role_name}?" data-sa-confirm-text="This will update the selected operations account." data-sa-confirm-confirm-text="Save Role">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="assign_operational_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$member['id']; ?>">
                            <select name="role_name" class="form-select form-select-sm">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars((string)$role['name']); ?>" <?php echo (string)$role['name'] === (string)$member['role_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$role['name']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" class="module-inline-actions" data-sa-confirm="1" data-sa-confirm-title="Remove operations access?" data-sa-confirm-text="This will clear the user's operational role assignment." data-sa-confirm-confirm-text="Remove Access">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="clear_operational_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$member['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
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
            <h3>Candidate Accounts</h3>
            <p class="module-subtext">Search for a user account to promote into operational access for testing or production support.</p>
        </div>
    </div>
    <form method="get" class="module-inline-actions" style="margin-bottom: 14px;">
        <input type="text" name="search" class="form-control" placeholder="Search by name, email, role, or user type" value="<?php echo htmlspecialchars($search); ?>" style="max-width: 360px;">
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <?php if ($search !== ''): ?>
            <a href="../super_admin/operations_team.php" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </form>
    <div class="table-wrap">
        <table class="module-table">
            <thead><tr><th>User</th><th>Current Role</th><th>Status</th><th>Last Login</th><th>Quick Assign</th></tr></thead>
            <tbody>
            <?php if (empty($filtered_candidates)): ?>
                <tr><td colspan="5" class="text-center text-muted">No matching user accounts found.</td></tr>
            <?php else: foreach ($filtered_candidates as $candidate): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars((string)$candidate['full_name']); ?></strong><br>
                        <span class="compact-text"><?php echo htmlspecialchars((string)$candidate['email']); ?> | <?php echo htmlspecialchars(ucfirst((string)($candidate['user_type'] ?? 'user'))); ?></span>
                    </td>
                    <td><?php echo !empty($candidate['role_name']) ? htmlspecialchars(ucwords(str_replace('_', ' ', (string)$candidate['role_name']))) : 'Not assigned'; ?></td>
                    <td><?php echo (int)($candidate['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></td>
                    <td><?php echo htmlspecialchars(saFormatDateTime($candidate['last_login'] ?? null, 'M d, Y h:i A', 'Never')); ?></td>
                    <td>
                        <form method="post" class="module-inline-actions" data-sa-confirm="1" data-sa-confirm-title-template="Assign {field_label:role_name}?" data-sa-confirm-text-template="This will update the selected account to {field_label:role_name}." data-sa-confirm-confirm-text="Assign">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="assign_operational_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$candidate['id']; ?>">
                            <select name="role_name" class="form-select form-select-sm">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars((string)$role['name']); ?>" <?php echo (string)$role['name'] === 'operations_staff' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$role['name']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Assign</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php saRenderModuleFooter(); ?>

