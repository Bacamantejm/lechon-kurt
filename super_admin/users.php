<?php
require_once __DIR__ . '/module_common.php';

$page_file = 'users.php';
$has_users = saTableExists($conn, 'users');
$has_orders = saTableExists($conn, 'orders');

if (!function_exists('saGetUserPastelAvatar')) {
    function saGetUserPastelAvatar($name) {
        $palettes = [
            ['bg' => '#f4ebff', 'color' => '#7f56d9'], // purple (TH in mockup)
            ['bg' => '#ecfdf3', 'color' => '#039855'], // green (AF in mockup)
            ['bg' => '#eff8ff', 'color' => '#175cd3'], // blue (GA in mockup)
            ['bg' => '#fef6ee', 'color' => '#b93815'], // orange / amber
            ['bg' => '#fdf2fa', 'color' => '#c11574'], // rose / pink
            ['bg' => '#f0fdf9', 'color' => '#0e9384'], // teal
            ['bg' => '#f8f9fa', 'color' => '#344054'], // slate gray
        ];
        $idx = abs(crc32((string)$name)) % count($palettes);
        return $palettes[$idx];
    }
}

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
        $where_clauses[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.business_name LIKE ?)";
        $search_like = '%' . $search . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $param_types .= 'sss';
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
<style>
/* ==========================================================================
   Super Admin Users Management — Horizontal Reference Card Directory Styles
   (Matching Reference Mockup: 4-Col Grid, Round Avatar, Name, Handle, Role, ⋮)
   ========================================================================== */
.user-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

@media (min-width: 1400px) {
    .user-cards-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.user-card-item {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 14px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    min-height: 84px;
}

.user-card-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px -3px rgba(16, 24, 40, 0.08);
    border-color: #d0d5dd;
}

body.dark-mode .user-card-item {
    background: #181d26 !important;
    border-color: #27303f !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

body.dark-mode .user-card-item:hover {
    border-color: #3b4758 !important;
    box-shadow: 0 12px 24px -4px rgba(0, 0, 0, 0.4);
}

.user-card-main {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    flex: 1 1 auto;
}

.user-avatar-wrap {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(16, 24, 40, 0.06);
}

.user-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-info-wrap {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
}

.user-name-row {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
}

.user-card-name {
    font-size: 14px;
    font-weight: 700;
    color: #101828;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.dark-mode .user-card-name {
    color: #f8fafc;
}

.user-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}

.user-status-dot.active {
    background: #12b76a;
}

.user-status-dot.inactive {
    background: #f04438;
}

.user-card-handle {
    font-size: 11.5px;
    color: #98a2b3;
    font-weight: 400;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.dark-mode .user-card-handle {
    color: #64748b;
}

.user-card-title {
    font-size: 12px;
    color: #344054;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.dark-mode .user-card-title {
    color: #94a3b8;
}

.user-card-item .dropdown {
    position: relative;
    flex-shrink: 0;
}

.user-action-dots {
    background: transparent;
    border: none;
    color: #98a2b3;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
    padding: 0;
    margin-left: 6px;
}

.user-action-dots:hover {
    background: #f2f4f7;
    color: #344054;
}

body.dark-mode .user-action-dots:hover {
    background: #27303f;
    color: #f8fafc;
}

.user-card-item .dropdown-menu {
    border-radius: 10px;
    border: 1px solid #eaecf0;
    font-size: 13px;
    min-width: 165px;
    box-shadow: 0 10px 25px rgba(16, 24, 40, 0.1);
    z-index: 1050;
    margin-top: 4px !important;
}

.user-card-item .dropdown-menu.dropdown-menu-end {
    right: 0 !important;
    left: auto !important;
}

.user-cards-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 48px 20px;
    background: var(--sa-card-bg);
    border: 1px dashed var(--sa-border-muted);
    border-radius: var(--sa-radius-md);
    color: var(--sa-muted-2);
}
</style>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h2>User Management Overview</h2>
            <p class="module-subtext">Track customer accounts, status, and activity at a platform level.</p>
        </div>
    </div>
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-icon"><i class="fas fa-users"></i></div>
            <span class="metric-label">Total Customers</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['total'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-icon success"><i class="fas fa-user-check"></i></div>
            <span class="metric-label">Active Customers</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['active'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-icon info"><i class="fas fa-user"></i></div>
            <span class="metric-label">Individual Accounts</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['individual'] ?? 0)); ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-icon warning"><i class="fas fa-building"></i></div>
            <span class="metric-label">Organization Accounts</span>
            <div class="metric-value"><?php echo number_format((int)($user_stats['organization'] ?? 0)); ?></div>
        </div>
    </div>
</div>

<div class="module-section">
    <div class="module-section-header">
        <div>
            <h3>User Management</h3>
            <p class="module-subtext">Search customers and manage account activation state.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="view-mode-toggle" role="group" aria-label="View toggle">
                <button type="button" class="view-mode-btn active" id="saUsersViewCards" onclick="switchUsersView('cards')" title="Card Grid View">
                    <i class="fas fa-th-large"></i>
                </button>
                <button type="button" class="view-mode-btn" id="saUsersViewTable" onclick="switchUsersView('table')" title="Table Spreadsheet View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>
    </div>

    <form method="GET" class="module-form-grid" style="margin-bottom: 16px;">
        <div class="form-input-wrap">
            <i class="form-input-icon fas fa-search"></i>
            <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <select name="user_type" class="form-select">
            <option value="" <?php echo $user_type === '' ? 'selected' : ''; ?>>All Types</option>
            <option value="individual" <?php echo $user_type === 'individual' ? 'selected' : ''; ?>>Individual</option>
            <option value="organization" <?php echo $user_type === 'organization' ? 'selected' : ''; ?>>Organization</option>
        </select>
        <button type="submit" class="btn-modal-primary"><i class="fas fa-search"></i> Search</button>
    </form>

    <?php if (!$has_users): ?>
        <div class="note-box">`users` table is unavailable in this environment.</div>
    <?php else: ?>
        <!-- Modern Cards Grid (Matching Reference Mockup) -->
        <div class="user-cards-grid" id="saUsersCardsGrid">
            <?php if (empty($users)): ?>
                <div class="user-cards-empty">
                    <i class="fas fa-users-slash fa-2x mb-2 text-muted"></i>
                    <p class="mb-0">No users found matching your search criteria.</p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php
                        $user_id = (int)$user['id'];
                        $is_active = (int)($user['is_active'] ?? 0) === 1;
                        $toggle_action = $is_active ? 'deactivate' : 'activate';
                        $toggle_icon = $is_active ? 'fa-user-slash' : 'fa-user-check';
                        $toggle_confirm_title = $is_active ? 'Deactivate Customer Account?' : 'Activate Customer Account?';
                        $toggle_confirm_text = $is_active
                            ? 'This customer will lose access to the platform until reactivated.'
                            : 'This customer account will be re-enabled immediately.';
                        $toggle_confirm_text_btn = $is_active ? 'Yes, Deactivate' : 'Yes, Activate';
                        $toggle_confirm_color = $is_active ? '#b3261e' : '#166534';

                        $full_name = trim((string)($user['full_name'] ?? 'Unknown Customer'));
                        $email = trim((string)($user['email'] ?? ''));
                        $handle = $email !== '' ? explode('@', $email)[0] : 'user';

                        // Title / Role matching reference mockup (e.g. CEO, Support, Client)
                        $account_type = strtolower(trim((string)($user['account_type'] ?? 'individual')));
                        $business_name = trim((string)($user['business_name'] ?? ''));
                        if ($business_name !== '') {
                            $user_title = $business_name;
                        } elseif ($account_type === 'organization') {
                            $user_title = 'Organization Client';
                        } else {
                            $user_title = 'Individual Customer';
                        }

                        // Photo or Pastel Initials circle
                        $profile_img = trim((string)($user['profile_image'] ?? ''));
                        $photo_path = '';
                        $has_photo = false;
                        if ($profile_img !== '') {
                            $clean_img = ltrim($profile_img, '/');
                            if (file_exists(dirname(__DIR__) . '/' . $clean_img)) {
                                $photo_path = '../' . $clean_img;
                                $has_photo = true;
                            }
                        }

                        $initials = '';
                        $words = preg_split('/\s+/', $full_name);
                        if (!empty($words[0])) {
                            $initials .= strtoupper(substr($words[0], 0, 1));
                        }
                        if (isset($words[1]) && !empty($words[1])) {
                            $initials .= strtoupper(substr($words[1], 0, 1));
                        }
                        if ($initials === '') {
                            $initials = 'CU';
                        }

                        $pastel = saGetUserPastelAvatar($full_name);
                    ?>
                    <div class="user-card-item" id="userCard_<?php echo $user_id; ?>">
                        <div class="user-card-main" onclick="loadUserDetails(<?php echo $user_id; ?>)" data-bs-toggle="modal" data-bs-target="#userModal" style="cursor: pointer;">
                            <div class="user-avatar-wrap" style="<?php echo !$has_photo ? 'background: ' . $pastel['bg'] . '; color: ' . $pastel['color'] . ';' : ''; ?>">
                                <?php if ($has_photo): ?>
                                    <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="<?php echo htmlspecialchars($full_name); ?>" class="user-avatar-img">
                                <?php else: ?>
                                    <span><?php echo htmlspecialchars($initials); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="user-info-wrap">
                                <div class="user-name-row">
                                    <h4 class="user-card-name" title="<?php echo htmlspecialchars($full_name); ?>"><?php echo htmlspecialchars($full_name); ?></h4>
                                    <span class="user-status-dot <?php echo $is_active ? 'active' : 'inactive'; ?>" title="<?php echo $is_active ? 'Active' : 'Inactive'; ?>"></span>
                                </div>
                                <span class="user-card-handle" title="<?php echo htmlspecialchars($email); ?>">@<?php echo htmlspecialchars($handle); ?></span>
                                <span class="user-card-title" title="<?php echo htmlspecialchars($user_title); ?>"><?php echo htmlspecialchars($user_title); ?></span>
                            </div>
                        </div>

                        <div class="dropdown">
                            <button type="button" class="user-action-dots" data-bs-toggle="dropdown" aria-expanded="false" title="More Options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="loadUserDetails(<?php echo $user_id; ?>)" data-bs-toggle="modal" data-bs-target="#userModal">
                                        <i class="fas fa-eye text-muted me-2"></i> View Profile
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                        <input type="hidden" name="action" value="<?php echo $toggle_action; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit"
                                                class="dropdown-item py-2 <?php echo $is_active ? 'text-danger' : 'text-success'; ?>"
                                                data-sa-confirm="1"
                                                data-sa-confirm-title="<?php echo htmlspecialchars($toggle_confirm_title, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-text="<?php echo htmlspecialchars($toggle_confirm_text, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-confirm-text="<?php echo htmlspecialchars($toggle_confirm_text_btn, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-sa-confirm-confirm-color="<?php echo htmlspecialchars($toggle_confirm_color, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas <?php echo $toggle_icon; ?> me-2"></i>
                                            <?php echo $is_active ? 'Deactivate Account' : 'Activate Account'; ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Table View (Preserved as alternative spreadsheet view) -->
        <div class="table-wrap d-none" id="saUsersTableView">
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
                                $user_id = (int)$user['id'];
                                $is_active = (int)($user['is_active'] ?? 0) === 1;
                                $status_chip = $is_active ? 'chip-success' : 'chip-danger';
                                $status_label = $is_active ? 'Active' : 'Inactive';
                                $toggle_action = $is_active ? 'deactivate' : 'activate';
                                $toggle_icon = $is_active ? 'fa-ban' : 'fa-check';
                                $toggle_confirm_title = $is_active ? 'Deactivate Customer Account?' : 'Activate Customer Account?';
                                $toggle_confirm_text = $is_active
                                    ? 'This customer will lose access to the platform until reactivated.'
                                    : 'This customer account will be re-enabled immediately.';
                                $toggle_confirm_text_btn = $is_active ? 'Yes, Deactivate' : 'Yes, Activate';
                                $toggle_confirm_color = $is_active ? '#b3261e' : '#166534';
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
                                                class="btn-modal-cancel" style="padding:6px 12px;font-size:12px;"
                                                data-bs-toggle="modal"
                                                data-bs-target="#userModal"
                                                onclick="loadUserDetails(<?php echo $user_id; ?>)"
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
                                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($toggle_action, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="status-chip <?php echo $is_active ? 'chip-danger' : 'chip-success'; ?>" style="cursor:pointer;border:none;" title="<?php echo ucfirst($toggle_action); ?>">
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

<div class="modal fade modern-form-modal" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modern-header">
                <div class="modal-header-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <h5 class="modal-title modern-title">Customer Profile Details</h5>
                    <p class="modal-subtitle">Account credentials, verification status, and order history.</p>
                </div>
                <button type="button" class="btn-close modern-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body modern-body" id="userDetails">
                <div class="text-muted">Loading user details...</div>
            </div>
            <div class="modal-footer modern-footer">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Close</button>
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

    function switchUsersView(mode) {
        if (mode === 'cards') {
            $('#saUsersCardsGrid').removeClass('d-none');
            $('#saUsersTableView').addClass('d-none');
            $('#saUsersViewCards').addClass('active');
            $('#saUsersViewTable').removeClass('active');
            try { localStorage.setItem('sa_users_view_mode', 'cards'); } catch(e){}
        } else {
            $('#saUsersCardsGrid').addClass('d-none');
            $('#saUsersTableView').removeClass('d-none');
            $('#saUsersViewCards').removeClass('active');
            $('#saUsersViewTable').addClass('active');
            try { localStorage.setItem('sa_users_view_mode', 'table'); } catch(e){}
        }
    }

    $(function() {
        var saved = 'cards';
        try { saved = localStorage.getItem('sa_users_view_mode') || 'cards'; } catch(e){}
        switchUsersView(saved);
    });
</script>
HTML;

saRenderModuleFooter($extra_scripts);
