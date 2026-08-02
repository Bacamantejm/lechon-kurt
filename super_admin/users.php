<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'users.php';
$has_users = saTableExists($conn, 'users');
$has_orders = saTableExists($conn, 'orders');

$user_stats = [
    'total' => 0,
    'individual' => 0,
    'organization' => 0,
    'active' => 0
];

if ($has_users) {
    $stats_rows = saQueryRows(
        $conn,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN account_type = 'individual' THEN 1 ELSE 0 END) AS individual,
            SUM(CASE WHEN account_type = 'organization' THEN 1 ELSE 0 END) AS organization,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active
         FROM users
         WHERE user_type = 'customer'"
    );
    if (!empty($stats_rows)) {
        $user_stats = array_merge($user_stats, $stats_rows[0]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    saRequireValidCsrf($_POST['csrf_token'] ?? '', $page_file);

    if (!$has_users) {
        saSetFlash('danger', 'Users table is unavailable in this environment.');
        header('Location: ' . $page_file);
        exit;
    }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($user_id <= 0 || !in_array($action, ['activate', 'deactivate'], true)) {
        saSetFlash('warning', 'Invalid user account action request.');
        header('Location: ' . $page_file);
        exit;
    }

    if ($action === 'deactivate') {
        $update_query = "UPDATE users SET is_active = 0 WHERE id = ? AND id != ?";
        $stmt = mysqli_prepare($conn, $update_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $user_id, $current_admin_id);
        }
    } else {
        $update_query = "UPDATE users SET is_active = 1 WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
        }
    }

    if (!$stmt) {
        saSetFlash('danger', 'Unable to update user status right now.');
        header('Location: ' . $page_file);
        exit;
    }

    $ok = mysqli_stmt_execute($stmt);
    $affected = $ok ? (int)mysqli_stmt_affected_rows($stmt) : 0;
    $stmt_error = trim((string)mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    if (!$ok) {
        saSetFlash('danger', 'Failed to update user status: ' . ($stmt_error !== '' ? $stmt_error : 'Database error.'));
    } elseif ($affected <= 0) {
        saSetFlash('warning', 'No changes were applied to the selected account.');
    } else {
        saSetFlash('success', 'User status updated successfully.');
    }

    header('Location: ' . $page_file);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$user_type = strtolower(trim((string)($_GET['user_type'] ?? '')));
if (!in_array($user_type, ['', 'individual', 'organization'], true)) {
    $user_type = '';
}

$users = [];
if ($has_users) {
    $where_clauses = ["u.user_type = 'customer'"];
    $params = [];
    $param_types = '';

    if ($user_type !== '') {
        $where_clauses[] = "u.account_type = ?";
        $params[] = $user_type;
        $param_types .= 's';
    }
    if ($search !== '') {
        $where_clauses[] = "(u.full_name LIKE ? OR u.email LIKE ?)";
        $search_like = '%' . $search . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $param_types .= 'ss';
    }

    $where_clause = "WHERE " . implode(' AND ', $where_clauses);
    $users_query = "SELECT u.*,
                           " . ($has_orders ? "(SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id)" : "0") . " AS order_count
                    FROM users u
                    {$where_clause}
                    ORDER BY u.created_at DESC";

    $stmt = mysqli_prepare($conn, $users_query);
    if ($stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $users_result = mysqli_stmt_get_result($stmt);
        if ($users_result) {
            while ($row = mysqli_fetch_assoc($users_result)) {
                $users[] = $row;
            }
            mysqli_free_result($users_result);
        }
        mysqli_stmt_close($stmt);
    } else {
        saSetFlash('danger', 'Unable to load user records right now.');
    }
}

saRenderModuleHeader('Users Management', 'Users Management', $admin_info);
?>
<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>User Management Overview</h2>
            <p class="module-subtext">Track customer accounts, status, and activity at a platform level.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Total Customers</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['total'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Active Customers</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['active'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Individual Accounts</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['individual'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <span class="metric-label">Organization Accounts</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['organization'] ?? 0)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>Customer Directory</h3>
            <p class="module-subtext">Search customers and manage account activation state.</p>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 12px;">
        <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="user_type" class="form-select">
            <option value="" <?php echo $user_type === '' ? 'selected' : ''; ?>>All Types</option>
            <option value="individual" <?php echo $user_type === 'individual' ? 'selected' : ''; ?>>Individual</option>
            <option value="organization" <?php echo $user_type === 'organization' ? 'selected' : ''; ?>>Organization</option>
        </select>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    </form>

    <?php if (!$has_users): ?>
        <div class="note-box">`users` table is unavailable in this environment.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="module-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Orders</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $is_active = (int)($user['is_active'] ?? 0) === 1;
                                $status_chip = $is_active ? 'chip-success' : 'chip-danger';
                                $status_label = $is_active ? 'Active' : 'Inactive';
                                $toggle_action = $is_active ? 'deactivate' : 'activate';
                                $toggle_icon = $is_active ? 'fa-ban' : 'fa-check';
                                $toggle_button_class = $is_active ? 'btn-outline-danger' : 'btn-outline-success';
                                $toggle_confirm_title = $is_active ? 'Deactivate Customer Account?' : 'Activate Customer Account?';
                                $toggle_confirm_text = $is_active
                                    ? 'This customer will lose access to the platform until reactivated.'
                                    : 'This customer account will be re-enabled immediately.';
                                $toggle_confirm_text_btn = $is_active ? 'Yes, Deactivate' : 'Yes, Activate';
                                $toggle_confirm_color = $is_active ? '#b91c1c' : '#166534';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string)($user['full_name'] ?? 'Unknown')); ?></strong></td>
                                <td><?php echo htmlspecialchars((string)($user['email'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst((string)($user['account_type'] ?? 'unknown'))); ?></td>
                                <td><?php echo htmlspecialchars((string)($user['phone'] ?? '-')); ?></td>
                                <td><span class="status-chip <?php echo $status_chip; ?>"><?php echo $status_label; ?></span></td>
                                <td><?php echo number_format((int)($user['order_count'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars(saFormatDateTime($user['created_at'] ?? null, 'M d, Y')); ?></td>
                                <td>
                                    <div class="module-inline-actions">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#userModal"
                                                onclick="loadUserDetails(<?php echo (int)$user['id']; ?>)"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST"
                                              style="display: inline-flex;"
                                              data-sa-confirm="1"
                                              data-sa-confirm-title="<?php echo htmlspecialchars($toggle_confirm_title, ENT_QUOTES, 'UTF-8'); ?>"
                                              data-sa-confirm-text="<?php echo htmlspecialchars($toggle_confirm_text, ENT_QUOTES, 'UTF-8'); ?>"
                                              data-sa-confirm-confirm-text="<?php echo htmlspecialchars($toggle_confirm_text_btn, ENT_QUOTES, 'UTF-8'); ?>"
                                              data-sa-confirm-confirm-color="<?php echo htmlspecialchars($toggle_confirm_color, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($toggle_action, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $toggle_button_class; ?>" title="<?php echo ucfirst($toggle_action); ?>">
                                                <i class="fas <?php echo $toggle_icon; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">User Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="userDetails">
                <div class="text-muted">Loading user details...</div>
            </div>
        </div>
    </div>
</div>
<?php
$extra_scripts = <<<'HTML'
<script>
    function loadUserDetails(userId) {
        $.ajax({
            url: '../super_admin/get_user_details.php',
            type: 'GET',
            data: { id: userId },
            success: function(response) {
                $('#userDetails').html(response);
            },
            error: function() {
                $('#userDetails').html('<div class="alert alert-danger">Unable to load user details.</div>');
            }
        });
    }
</script>
HTML;

saRenderModuleFooter($extra_scripts);
