<?php
require_once __DIR__ . '/module_common.php';

$has_users = saTableExists($conn, 'users');
$has_roles = saTableExists($conn, 'roles');
$has_permissions = saTableExists($conn, 'permissions');
$has_role_permissions = saTableExists($conn, 'role_permissions');
$has_audit_logs = saTableExists($conn, 'audit_logs');
$has_activity_logs = saTableExists($conn, 'activity_logs');

$admin_accounts_total = 0;
$admin_accounts_active = 0;
$users_without_role = 0;
$recent_login_users = 0;

if ($has_users) {
    $admin_accounts_total = (int)saQueryScalar(
        $conn,
        "SELECT COUNT(*) FROM users
         WHERE user_type = 'admin' OR role_id IS NOT NULL",
        0
    );
    $admin_accounts_active = (int)saQueryScalar(
        $conn,
        "SELECT COUNT(*) FROM users
         WHERE (user_type = 'admin' OR role_id IS NOT NULL)
           AND is_active = 1",
        0
    );
    $users_without_role = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE role_id IS NULL", 0);
    $recent_login_users = (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)", 0);
}

$roles_matrix = [];
if ($has_roles) {
    $permission_count_sql = ($has_permissions && $has_role_permissions)
        ? "(SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id)"
        : "0";

    $assigned_count_sql = $has_users
        ? "(SELECT COUNT(*) FROM users u WHERE u.role_id = r.id)"
        : "0";

    $roles_matrix = saQueryRows(
        $conn,
        "SELECT r.id, r.name, r.description, r.level, r.is_active,
                {$assigned_count_sql} AS assigned_users,
                {$permission_count_sql} AS permission_count
         FROM roles r
         ORDER BY r.level DESC, r.name ASC"
    );
}

$security_events = [];
if ($has_audit_logs || $has_activity_logs) {
    $parts = [];
    if ($has_audit_logs) {
        $parts[] = "SELECT CONVERT('audit' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                           a.id,
                           a.user_id,
                           CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                           CONVERT(a.module USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                           CONVERT(a.description USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                           CONVERT(a.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                           a.created_at
                    FROM audit_logs a
                    WHERE LOWER(a.action) LIKE '%login%'
                       OR LOWER(a.action) LIKE '%logout%'
                       OR LOWER(a.action) LIKE '%auth%'
                       OR LOWER(a.action) LIKE '%password%'
                       OR LOWER(a.action) LIKE '%role%'";
    }
    if ($has_activity_logs) {
        $parts[] = "SELECT CONVERT('activity' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
                           al.id,
                           al.user_id,
                           CONVERT(al.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                           CONVERT(al.entity_type USING utf8mb4) COLLATE utf8mb4_unicode_ci AS context,
                           CONVERT(CAST(al.details AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                           CONVERT(al.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                           al.created_at
                    FROM activity_logs al
                    WHERE LOWER(al.action) LIKE '%login%'
                       OR LOWER(al.action) LIKE '%logout%'
                       OR LOWER(al.action) LIKE '%auth%'
                       OR LOWER(al.action) LIKE '%password%'
                       OR LOWER(al.action) LIKE '%role%'";
    }

    $security_events = saQueryRows(
        $conn,
        "SELECT e.*, COALESCE(u.full_name, CONCAT('User #', e.user_id)) AS actor_name
         FROM (" . implode(' UNION ALL ', $parts) . ") e
         LEFT JOIN users u ON u.id = e.user_id
         ORDER BY e.created_at DESC
         LIMIT 120"
    );
}

$csrf_enabled = function_exists('generateCSRFToken') && function_exists('validateCSRFToken');
$rbac_enabled = $has_roles && $has_permissions && $has_role_permissions;
$security_notes = [
    [
        'label' => 'Login Authentication',
        'status' => $has_users ? 'enabled' : 'limited',
        'description' => $has_users
            ? 'User session and account access checks are active through admin authentication middleware.'
            : 'User table missing: authentication metrics are limited.'
    ],
    [
        'label' => 'Role-Based Access Control (RBAC)',
        'status' => $rbac_enabled ? 'enabled' : 'partial',
        'description' => $rbac_enabled
            ? 'Roles and permission mapping tables are available.'
            : 'Some RBAC tables are unavailable; role/permission insights may be incomplete.'
    ],
    [
        'label' => 'CSRF Protection',
        'status' => $csrf_enabled ? 'enabled' : 'partial',
        'description' => $csrf_enabled
            ? 'CSRF token generation and validation are available across super admin forms.'
            : 'CSRF helper functions are unavailable in this environment.'
    ],
    [
        'label' => 'Audit Logging',
        'status' => ($has_audit_logs || $has_activity_logs) ? 'enabled' : 'disabled',
        'description' => ($has_audit_logs || $has_activity_logs)
            ? 'Security-relevant actions are traceable via log tables.'
            : 'No audit/activity logs table found.'
    ]
];

saRenderModuleHeader('Security & Access Control', 'Security & Access Control', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Security Control Center</h2>
            <p class="module-subtext">Monitor authentication, RBAC coverage, and platform access integrity.</p>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Admin / Role Accounts</span>
            <div class="metric-value"><?php echo number_format($admin_accounts_total); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Active Privileged Accounts</span>
            <div class="metric-value"><?php echo number_format($admin_accounts_active); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Users Without Role</span>
            <div class="metric-value"><?php echo number_format($users_without_role); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Recent Logins (30 days)</span>
            <div class="metric-value"><?php echo number_format($recent_login_users); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Security Capability Checklist</h3>
            <p class="module-subtext">Current implementation status for core security controls.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="module-table" style="min-width: 620px;">
            <thead>
                <tr>
                    <th>Control</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($security_notes as $note): ?>
                    <?php
                        $status = strtolower((string)$note['status']);
                        $chip_class = 'chip-muted';
                        if ($status === 'enabled') {
                            $chip_class = 'chip-success';
                        } elseif ($status === 'partial' || $status === 'limited') {
                            $chip_class = 'chip-warning';
                        } elseif ($status === 'disabled') {
                            $chip_class = 'chip-danger';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$note['label']); ?></strong></td>
                        <td><span class="status-chip <?php echo $chip_class; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                        <td><?php echo htmlspecialchars((string)$note['description']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Role and Permission Matrix</h3>
            <p class="module-subtext">Role assignments and permission coverage for access governance.</p>
        </div>
        <a href="rbac_management.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-user-shield"></i> Open RBAC Management
        </a>
    </div>
    <?php if (!$has_roles): ?>
        <div class="note-box">`roles` table is unavailable.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Description</th>
                        <th>Level</th>
                        <th>Assigned Users</th>
                        <th>Permissions</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles_matrix as $role): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string)$role['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)($role['description'] ?? '')); ?></td>
                            <td><?php echo (int)($role['level'] ?? 0); ?></td>
                            <td><?php echo number_format((int)($role['assigned_users'] ?? 0)); ?></td>
                            <td><?php echo number_format((int)($role['permission_count'] ?? 0)); ?></td>
                            <td>
                                <?php if ((int)($role['is_active'] ?? 0) === 1): ?>
                                    <span class="status-chip chip-success">Active</span>
                                <?php else: ?>
                                    <span class="status-chip chip-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Authentication and Access Events</h3>
            <p class="module-subtext">Login/logout, password, and role-related events for audit and forensics.</p>
        </div>
    </div>
    <?php if (!$has_audit_logs && !$has_activity_logs): ?>
        <div class="note-box">No security events available because log tables are missing.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Source</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Context</th>
                        <th>IP</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($security_events)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No authentication/access events found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($security_events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(saFormatDateTime($event['created_at'] ?? null)); ?></td>
                                <td><span class="status-chip chip-info"><?php echo htmlspecialchars(ucfirst((string)$event['source'])); ?></span></td>
                                <td><?php echo htmlspecialchars((string)($event['actor_name'] ?? 'Unknown')); ?></td>
                                <td><?php echo htmlspecialchars((string)($event['action'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($event['context'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($event['ip_address'] ?? '-')); ?></td>
                                <td class="text-truncate-2"><?php echo htmlspecialchars((string)($event['details'] ?? '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
saRenderModuleFooter();
