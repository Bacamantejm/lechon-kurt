<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'user_business_management.php';
$has_users = saTableExists($conn, 'users');
$has_roles = saTableExists($conn, 'roles');
$has_apps = saTableExists($conn, 'franchise_applications');

if (!function_exists('saUserBizChipClass')) {
    function saUserBizChipClass($status) {
        $status = strtolower(trim((string)$status));
        if (in_array($status, ['approved', 'active', 'enabled'], true)) {
            return 'chip-success';
        }
        if (in_array($status, ['pending', 'in_progress'], true)) {
            return 'chip-warning';
        }
        if (in_array($status, ['rejected', 'inactive', 'disabled', 'banned', 'suspended'], true)) {
            return 'chip-danger';
        }
        if (in_array($status, ['unverified', 'none'], true)) {
            return 'chip-muted';
        }
        return 'chip-info';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);

    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'toggle_user_status' && $has_users) {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $next_status = (int)($_POST['next_status'] ?? 0) === 1 ? 1 : 0;

        if ($target_user_id <= 0) {
            saSetFlash('danger', 'Invalid user selection.');
        } elseif ($target_user_id === $current_admin_id && $next_status === 0) {
            saSetFlash('warning', 'You cannot deactivate your own super admin account.');
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE users SET is_active = ? WHERE id = ? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ii", $next_status, $target_user_id);
                $ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                if ($ok) {
                    $status_text = $next_status === 1 ? 'activated' : 'deactivated';
                    saSetFlash('success', "User account {$status_text} successfully.");
                    saLogAudit(
                        $conn,
                        $current_admin_id,
                        'USER_STATUS_UPDATED',
                        'super_admin_user_business',
                        "Super admin set user #{$target_user_id} status to {$status_text}."
                    );
                } else {
                    saSetFlash('danger', 'Unable to update user status right now.');
                }
            } else {
                saSetFlash('danger', 'Unable to prepare user status update.');
            }
        }
    } elseif ($action === 'update_user_role' && $has_users && $has_roles) {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $role_id_raw = trim((string)($_POST['role_id'] ?? ''));
        $new_role_id = ctype_digit($role_id_raw) ? (int)$role_id_raw : null;

        if ($target_user_id <= 0) {
            saSetFlash('danger', 'Invalid user selection for role update.');
        } elseif ($target_user_id === $current_admin_id && $new_role_id === null) {
            saSetFlash('warning', 'You cannot remove your own role assignment.');
        } else {
            $valid_role = true;
            if ($new_role_id !== null) {
                $check_stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE id = ? LIMIT 1");
                if ($check_stmt) {
                    mysqli_stmt_bind_param($check_stmt, "i", $new_role_id);
                    mysqli_stmt_execute($check_stmt);
                    $role_result = mysqli_stmt_get_result($check_stmt);
                    $valid_role = $role_result && mysqli_fetch_assoc($role_result);
                    mysqli_stmt_close($check_stmt);
                } else {
                    $valid_role = false;
                }
            }

            if (!$valid_role) {
                saSetFlash('danger', 'Selected role does not exist.');
            } else {
                if ($new_role_id === null) {
                    $stmt = mysqli_prepare($conn, "UPDATE users SET role_id = NULL WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $target_user_id);
                    }
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE users SET role_id = ? WHERE id = ? LIMIT 1");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "ii", $new_role_id, $target_user_id);
                    }
                }

                if (!isset($stmt) || !$stmt) {
                    saSetFlash('danger', 'Unable to prepare role update.');
                } else {
                    $ok = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    if ($ok) {
                        saSetFlash('success', 'User role updated successfully.');
                        saLogAudit(
                            $conn,
                            $current_admin_id,
                            'USER_ROLE_UPDATED',
                            'super_admin_user_business',
                            "Super admin updated role of user #{$target_user_id} to " . ($new_role_id === null ? 'NULL' : (string)$new_role_id) . '.'
                        );
                    } else {
                        saSetFlash('danger', 'Unable to update user role.');
                    }
                }
            }
        }
    } elseif ($action === 'review_business_application' && $has_apps) {
        $application_id = (int)($_POST['application_id'] ?? 0);
        $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
        $admin_notes = trim((string)($_POST['admin_notes'] ?? ''));

        if ($application_id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            saSetFlash('danger', 'Invalid business application review request.');
        } else {
            $app_stmt = mysqli_prepare($conn, "SELECT id, user_id, business_name FROM franchise_applications WHERE id = ? LIMIT 1");
            if (!$app_stmt) {
                saSetFlash('danger', 'Unable to read application details.');
            } else {
                mysqli_stmt_bind_param($app_stmt, "i", $application_id);
                mysqli_stmt_execute($app_stmt);
                $app_result = mysqli_stmt_get_result($app_stmt);
                $app_row = $app_result ? mysqli_fetch_assoc($app_result) : null;
                mysqli_stmt_close($app_stmt);

                if (!$app_row) {
                    saSetFlash('danger', 'Business application was not found.');
                } else {
                    $update_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE franchise_applications
                         SET status = ?, admin_notes = ?, admin_id = ?, reviewed_at = NOW()
                         WHERE id = ? LIMIT 1"
                    );

                    if (!$update_stmt) {
                        saSetFlash('danger', 'Unable to prepare application review update.');
                    } else {
                        mysqli_stmt_bind_param($update_stmt, "ssii", $decision, $admin_notes, $current_admin_id, $application_id);
                        $updated = mysqli_stmt_execute($update_stmt);
                        mysqli_stmt_close($update_stmt);

                        if ($updated) {
                            if ($decision === 'approved' && $has_users && $has_roles) {
                                $role_stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE name = 'business_owner' AND is_active = 1 LIMIT 1");
                                if ($role_stmt) {
                                    mysqli_stmt_execute($role_stmt);
                                    $role_result = mysqli_stmt_get_result($role_stmt);
                                    $role_row = $role_result ? mysqli_fetch_assoc($role_result) : null;
                                    mysqli_stmt_close($role_stmt);

                                    if (!empty($role_row['id'])) {
                                        $owner_role_id = (int)$role_row['id'];
                                        $target_user_id = (int)$app_row['user_id'];
                                        $assign_stmt = mysqli_prepare($conn, "UPDATE users SET role_id = ? WHERE id = ? LIMIT 1");
                                        if ($assign_stmt) {
                                            mysqli_stmt_bind_param($assign_stmt, "ii", $owner_role_id, $target_user_id);
                                            mysqli_stmt_execute($assign_stmt);
                                            mysqli_stmt_close($assign_stmt);
                                        }
                                    }
                                }
                            }

                            saSetFlash('success', 'Business application reviewed successfully.');
                            saLogAudit(
                                $conn,
                                $current_admin_id,
                                'BUSINESS_APPLICATION_REVIEWED',
                                'super_admin_user_business',
                                "Super admin marked application #{$application_id} as {$decision}."
                            );
                        } else {
                            saSetFlash('danger', 'Unable to update application status.');
                        }
                    }
                }
            }
        }
    } else {
        saSetFlash('warning', 'Unsupported action request.');
    }

    header('Location: ' . $page_file);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$account_filter = strtolower(trim((string)($_GET['account_type'] ?? 'all')));
$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$role_filter = trim((string)($_GET['role_id'] ?? 'all'));

$total_users = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users", 0) : 0;
$active_users = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE is_active = 1", 0) : 0;
$business_accounts = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE account_type = 'organization'", 0) : 0;
$pending_applications = $has_apps ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM franchise_applications WHERE status = 'pending'", 0) : 0;

$roles = [];
if ($has_roles) {
    $roles = saQueryRows(
        $conn,
        "SELECT r.id, r.name, r.description, r.level, r.is_active,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS assigned_users
         FROM roles r
         ORDER BY r.level DESC, r.name ASC"
    );
}

$users = [];
if ($has_users) {
    $where = ["1=1"];
    if ($search !== '') {
        $safe_search = saEscapeLike($conn, $search);
        $where[] = "(u.full_name LIKE '%{$safe_search}%' ESCAPE '\\' OR u.email LIKE '%{$safe_search}%' ESCAPE '\\' OR u.business_name LIKE '%{$safe_search}%' ESCAPE '\\')";
    }
    if (in_array($account_filter, ['individual', 'organization'], true)) {
        $safe_account = mysqli_real_escape_string($conn, $account_filter);
        $where[] = "u.account_type = '{$safe_account}'";
    }
    if (in_array($status_filter, ['active', 'inactive'], true)) {
        $where[] = $status_filter === 'active' ? "u.is_active = 1" : "u.is_active = 0";
    }
    if ($role_filter !== 'all' && ctype_digit($role_filter)) {
        $where[] = "u.role_id = " . (int)$role_filter;
    }

    $where_sql = implode(' AND ', $where);
    $verification_sql = $has_apps
        ? "(SELECT fa.status
            FROM franchise_applications fa
            WHERE fa.user_id = u.id
            ORDER BY fa.created_at DESC, fa.id DESC
            LIMIT 1) AS verification_status"
        : "'unverified' AS verification_status";

    $users_query = "SELECT u.id, u.full_name, u.email, u.phone, u.user_type, u.account_type, u.business_name,
                           u.role_id, u.is_active, u.created_at, u.last_login,
                           r.name AS role_name,
                           {$verification_sql}
                    FROM users u
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE {$where_sql}
                    ORDER BY u.created_at DESC
                    LIMIT 300";
    $users = saQueryRows($conn, $users_query);
}

$applications = [];
if ($has_apps) {
    $applications = saQueryRows(
        $conn,
        "SELECT fa.id, fa.application_number, fa.business_name, fa.status, fa.created_at, fa.reviewed_at, fa.admin_notes,
                u.id AS owner_id, u.full_name AS owner_name, u.email AS owner_email
         FROM franchise_applications fa
         LEFT JOIN users u ON u.id = fa.user_id
         ORDER BY FIELD(fa.status, 'pending', 'approved', 'rejected'), fa.created_at DESC
         LIMIT 150"
    );
}

saRenderModuleHeader('User & Business Management', 'User & Business Management', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>Platform Entity Overview</h2>
            <p class="module-subtext">Manage all registered users, business owners, and application approvals in one place.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Users</span>
            <div class="metric-value"><?php echo number_format($total_users); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Active Accounts</span>
            <div class="metric-value"><?php echo number_format($active_users); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Business Accounts</span>
            <div class="metric-value"><?php echo number_format($business_accounts); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Pending Applications</span>
            <div class="metric-value"><?php echo number_format($pending_applications); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Users and Account Controls</h3>
            <p class="module-subtext">View users, update role assignments, and activate/deactivate accounts.</p>
        </div>
    </div>
    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <input type="text" class="form-control" name="search" placeholder="Search by name, email, business..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="account_type" class="form-select">
            <option value="all" <?php echo $account_filter === 'all' ? 'selected' : ''; ?>>All Account Types</option>
            <option value="individual" <?php echo $account_filter === 'individual' ? 'selected' : ''; ?>>Individual</option>
            <option value="organization" <?php echo $account_filter === 'organization' ? 'selected' : ''; ?>>Organization</option>
        </select>
        <select name="status" class="form-select">
            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <select name="role_id" class="form-select">
            <option value="all">All Roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo (int)$role['id']; ?>" <?php echo ($role_filter === (string)$role['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string)$role['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
    </form>

    <?php if (!$has_users): ?>
        <div class="note-box">`users` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role Management</th>
                        <th>Verification</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No users found for current filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $verification = strtolower(trim((string)($user['verification_status'] ?? '')));
                                if ($verification === '') {
                                    $verification = 'unverified';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)($user['full_name'] ?? 'Unknown')); ?></strong><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($user['email'] ?? '')); ?></span><br>
                                    <span class="compact-text">
                                        <?php echo ucfirst((string)($user['user_type'] ?? 'user')); ?> /
                                        <?php echo ucfirst((string)($user['account_type'] ?? 'individual')); ?>
                                        <?php if (trim((string)($user['business_name'] ?? '')) !== ''): ?>
                                            · <?php echo htmlspecialchars((string)$user['business_name']); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($has_roles): ?>
                                        <form method="POST" class="module-inline-actions">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="update_user_role">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                            <select name="role_id" class="form-select form-select-sm" style="min-width: 160px;">
                                                <option value="">No Role</option>
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?php echo (int)$role['id']; ?>" <?php echo ((int)$user['role_id'] === (int)$role['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars((string)$role['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="compact-text">Roles not available.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-chip <?php echo saUserBizChipClass($verification); ?>">
                                        <?php echo htmlspecialchars(ucfirst($verification)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                                        <span class="status-chip chip-success">Active</span>
                                    <?php else: ?>
                                        <span class="status-chip chip-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($user['last_login'] ?? null, 'M d, Y h:i A', 'Never')); ?></td>
                                <td>
                                    <?php
                                        $next_is_active = ((int)$user['is_active'] !== 1);
                                        $status_confirm_title = $next_is_active ? 'Activate User Account?' : 'Deactivate User Account?';
                                        $status_confirm_text = $next_is_active
                                            ? 'This user will regain access to platform modules.'
                                            : 'This user will be blocked from logging in until reactivated.';
                                        $status_confirm_button = $next_is_active ? 'Yes, Activate' : 'Yes, Deactivate';
                                        $status_confirm_color = $next_is_active ? '#166534' : '#b91c1c';
                                    ?>
                                    <form method="POST" class="module-inline-actions">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_user_status">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <input type="hidden" name="next_status" value="<?php echo ((int)$user['is_active'] === 1) ? 0 : 1; ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?php echo ((int)$user['is_active'] === 1) ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                data-sa-confirm="1"
                                                data-sa-confirm-title="<?php echo htmlspecialchars($status_confirm_title, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-text="<?php echo htmlspecialchars($status_confirm_text, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-confirm-text="<?php echo htmlspecialchars($status_confirm_button, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-confirm-color="<?php echo htmlspecialchars($status_confirm_color, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo ((int)$user['is_active'] === 1) ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Business Registration Approvals</h3>
            <p class="module-subtext">Approve/reject franchise registrations and assign business owner access.</p>
        </div>
    </div>

    <?php if (!$has_apps): ?>
        <div class="note-box">`franchise_applications` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Application</th>
                        <th>Business</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Review</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No business applications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applications as $application): ?>
                            <?php $app_status = strtolower(trim((string)($application['status'] ?? 'pending'))); ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars((string)$application['application_number']); ?></strong><br>
                                    <span class="compact-text">#<?php echo (int)$application['id']; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string)$application['business_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars((string)($application['owner_name'] ?? 'Unknown')); ?><br>
                                    <span class="compact-text"><?php echo htmlspecialchars((string)($application['owner_email'] ?? '')); ?></span>
                                </td>
                                <td><span class="status-chip <?php echo saUserBizChipClass($app_status); ?>"><?php echo htmlspecialchars(ucfirst($app_status)); ?></span></td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($application['created_at'] ?? null, 'M d, Y h:i A', '-')); ?></td>
                                <td>
                                    <?php if ($app_status === 'pending'): ?>
                                        <form method="POST" class="module-inline-actions">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="review_business_application">
                                            <input type="hidden" name="application_id" value="<?php echo (int)$application['id']; ?>">
                                            <select name="decision" class="form-select form-select-sm" style="min-width: 120px;">
                                                <option value="approved">Approve</option>
                                                <option value="rejected">Reject</option>
                                            </select>
                                            <input type="text" name="admin_notes" class="form-control form-control-sm" placeholder="Optional notes" style="min-width: 180px;">
                                            <button type="submit"
                                                    class="btn btn-sm btn-primary"
                                                    data-sa-confirm="1"
                                                    data-sa-confirm-title-template="Confirm {field_label:decision} Decision?"
                                                    data-sa-confirm-text-template="This will mark this business application as {field_label:decision}."
                                                    data-sa-confirm-confirm-text-template="Yes, {field_label:decision}"
                                                    data-sa-confirm-confirm-color="#9f1239">
                                                Submit
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="compact-text">
                                            Reviewed: <?php echo htmlspecialchars(saFormatDateTime($application['reviewed_at'] ?? null, 'M d, Y h:i A', '-')); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Role Management Snapshot</h3>
            <p class="module-subtext">Quick overview of role distribution across platform users.</p>
        </div>
    </div>
    <?php if (!$has_roles): ?>
        <div class="note-box">`roles` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table" style="min-width: 620px;">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Description</th>
                        <th>Level</th>
                        <th>Assigned Users</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string)$role['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)($role['description'] ?? '')); ?></td>
                            <td><?php echo (int)($role['level'] ?? 0); ?></td>
                            <td><?php echo number_format((int)($role['assigned_users'] ?? 0)); ?></td>
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
<?php
saRenderModuleFooter();
