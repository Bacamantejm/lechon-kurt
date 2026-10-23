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

if (!function_exists('saGetUserAvatarGradient')) {
    function saGetUserAvatarGradient($name) {
        $gradients = [
            'linear-gradient(135deg, #f43f5e, #fb7185)',
            'linear-gradient(135deg, #6366f1, #818cf8)',
            'linear-gradient(135deg, #0ea5e9, #38bdf8)',
            'linear-gradient(135deg, #10b981, #34d399)',
            'linear-gradient(135deg, #f59e0b, #fbbf24)',
            'linear-gradient(135deg, #8b5cf6, #a78bfa)',
            'linear-gradient(135deg, #ec4899, #f472b6)',
            'linear-gradient(135deg, #14b8a6, #2dd4bf)',
            'linear-gradient(135deg, #3b82f6, #60a5fa)',
            'linear-gradient(135deg, #d97706, #f59e0b)'
        ];
        $idx = abs(crc32((string)$name)) % count($gradients);
        return $gradients[$idx];
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
                        require_once __DIR__ . '/../includes/rider_helper.php';
                        isDeliveryDriverUser($conn, $target_user_id);
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
        } elseif ($decision === 'rejected' && $admin_notes === '') {
            saSetFlash('danger', 'A reason is required when rejecting a business application.');
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

    $return_folder = trim((string)($_POST['return_folder'] ?? ''));
    $redirect_url = $page_file;
    if ($return_folder !== '' && $return_folder !== 'all_folders') {
        $redirect_url .= '?folder=' . urlencode($return_folder);
    }
    header('Location: ' . $redirect_url);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$account_filter = strtolower(trim((string)($_GET['account_type'] ?? 'all')));
$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$role_filter = trim((string)($_GET['role_id'] ?? 'all'));
$active_folder = trim((string)($_GET['folder'] ?? ''));

$total_users = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users", 0) : 0;
$active_users = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE is_active = 1", 0) : 0;
$business_accounts = $has_users ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM users WHERE account_type = 'organization'", 0) : 0;
$pending_applications = $has_apps ? (int)saQueryScalar($conn, "SELECT COUNT(*) FROM franchise_applications WHERE status = 'pending'", 0) : 0;

$roles = [];
if ($has_roles) {
    $roles = saQueryRows(
        $conn,
        "SELECT r.id, r.name, r.description, r.is_active, r.owner_user_id,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS assigned_users
         FROM roles r
         ORDER BY r.name ASC"
    );
}

// --------------------------------------------------------------------------
// Dynamic Approved Stores & Franchise Directories
// Automatically registers a new folder whenever an application is approved
// or a store location is added.
// --------------------------------------------------------------------------
$approved_stores = [];
if ($has_apps) {
    $apps_q = saQueryRows(
        $conn,
        "SELECT fa.id, fa.user_id, fa.business_name, fa.city_name, fa.business_address, fa.contact_person, fa.contact_phone, fa.contact_email,
                u.full_name AS owner_name, u.profile_image AS owner_profile_image
         FROM franchise_applications fa
         LEFT JOIN users u ON u.id = fa.user_id
         WHERE fa.status = 'approved'
         ORDER BY fa.id ASC"
    );
    foreach ($apps_q as $app) {
        $owner_id = (int)$app['user_id'];
        if ($owner_id > 0) {
            $approved_stores[$owner_id] = [
                'folder_key' => 'store_' . $owner_id,
                'owner_user_id' => $owner_id,
                'owner_name' => trim((string)($app['owner_name'] ?? 'Store Owner')),
                'store_name' => trim((string)$app['business_name']),
                'city' => trim((string)($app['city_name'] ?? '')),
                'address' => trim((string)($app['business_address'] ?? '')),
                'email' => trim((string)($app['contact_email'] ?? '')),
                'phone' => trim((string)($app['contact_phone'] ?? '')),
                'source' => 'franchise_applications'
            ];
        }
    }
}

if (saTableExists($conn, 'store_locations')) {
    $locs_q = saQueryRows(
        $conn,
        "SELECT sl.store_id, sl.owner_user_id, sl.store_name, sl.address, sl.city, sl.phone, sl.email,
                u.full_name AS owner_name
         FROM store_locations sl
         LEFT JOIN users u ON u.id = sl.owner_user_id
         WHERE sl.is_active = 1 OR sl.is_active IS NULL
         ORDER BY sl.store_id ASC"
    );
    foreach ($locs_q as $loc) {
        $owner_id = (int)$loc['owner_user_id'];
        if ($owner_id > 0) {
            if (!isset($approved_stores[$owner_id])) {
                $approved_stores[$owner_id] = [
                    'folder_key' => 'store_' . $owner_id,
                    'owner_user_id' => $owner_id,
                    'owner_name' => trim((string)($loc['owner_name'] ?? 'Store Owner')),
                    'store_name' => trim((string)$loc['store_name']),
                    'city' => trim((string)($loc['city'] ?? '')),
                    'address' => trim((string)($loc['address'] ?? '')),
                    'email' => trim((string)($loc['email'] ?? '')),
                    'phone' => trim((string)($loc['phone'] ?? '')),
                    'source' => 'store_locations'
                ];
            } elseif (empty($approved_stores[$owner_id]['city']) && !empty($loc['city'])) {
                $approved_stores[$owner_id]['city'] = trim((string)$loc['city']);
            }
        }
    }
}

// Map employees and staff to their respective stores
$user_to_store_map = [];

// 1. Partner User Links (e.g. store owner delegating managed employee users)
if (saTableExists($conn, 'partner_user_links')) {
    $pul_rows = saQueryRows($conn, "SELECT owner_user_id, managed_user_id FROM partner_user_links");
    foreach ($pul_rows as $pul) {
        $o_id = (int)$pul['owner_user_id'];
        $m_id = (int)$pul['managed_user_id'];
        if ($o_id > 0 && $m_id > 0 && isset($approved_stores[$o_id])) {
            $user_to_store_map[$m_id] = $o_id;
        }
    }
}

// 2. Department & Employee records (departments.manager_id -> employees.user_id)
if (saTableExists($conn, 'employees') && saTableExists($conn, 'departments')) {
    $dept_emp_rows = saQueryRows(
        $conn,
        "SELECT e.user_id, d.manager_id, e.position
         FROM employees e
         JOIN departments d ON d.id = e.department_id
         WHERE e.user_id IS NOT NULL AND e.user_id > 0 AND d.manager_id > 0"
    );
    foreach ($dept_emp_rows as $de) {
        $e_uid = (int)$de['user_id'];
        $mgr_id = (int)$de['manager_id'];
        if (isset($approved_stores[$mgr_id])) {
            $user_to_store_map[$e_uid] = $mgr_id;
        }
    }
}

// 3. Store-specific custom roles (roles.owner_user_id)
$role_owner_map = [];
if ($has_roles) {
    foreach ($roles as $r_item) {
        $r_owner = (int)($r_item['owner_user_id'] ?? 0);
        if ($r_owner > 0 && isset($approved_stores[$r_owner])) {
            $role_owner_map[(int)$r_item['id']] = $r_owner;
        }
    }
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

    $users_query = "SELECT u.id, u.full_name, u.email, u.phone, u.profile_image, u.user_type, u.account_type, u.business_name,
                           u.role_id, u.is_active, u.created_at, u.last_login,
                           r.name AS role_name,
                           {$verification_sql}
                    FROM users u
                    LEFT JOIN roles r ON r.id = u.role_id
                    WHERE {$where_sql}
                    ORDER BY u.created_at DESC
                    LIMIT 300";
    $users = saQueryRows($conn, $users_query);

    // Categorize each user into their appropriate directory folder
    foreach ($users as &$user_item) {
        $uid = (int)$user_item['id'];
        $role_id = (int)($user_item['role_id'] ?? 0);
        $role_name = (string)($user_item['role_name'] ?? '');
        $user_type = strtolower((string)($user_item['user_type'] ?? ''));

        $folder_key = 'customers';
        $is_store_owner = false;
        $assigned_store_name = '';

        if (isset($approved_stores[$uid])) {
            // User is the store owner
            $folder_key = 'store_' . $uid;
            $is_store_owner = true;
            $assigned_store_name = $approved_stores[$uid]['store_name'];
        } elseif (isset($user_to_store_map[$uid]) && isset($approved_stores[$user_to_store_map[$uid]])) {
            // User is a linked employee/rider/staff member of this store
            $s_id = $user_to_store_map[$uid];
            $folder_key = 'store_' . $s_id;
            $assigned_store_name = $approved_stores[$s_id]['store_name'];
        } elseif (isset($role_owner_map[$role_id]) && isset($approved_stores[$role_owner_map[$role_id]])) {
            // User is assigned a store-specific role
            $s_id = $role_owner_map[$role_id];
            $folder_key = 'store_' . $s_id;
            $assigned_store_name = $approved_stores[$s_id]['store_name'];
        } else {
            // Match by business_name if specified
            $matched_store = false;
            if (!empty($user_item['business_name'])) {
                foreach ($approved_stores as $s_id => $s_data) {
                    if (strcasecmp($user_item['business_name'], $s_data['store_name']) === 0) {
                        $folder_key = 'store_' . $s_id;
                        $assigned_store_name = $s_data['store_name'];
                        $matched_store = true;
                        break;
                    }
                }
            }
            if (!$matched_store) {
                if ($user_type === 'admin' || in_array($role_name, ['super_admin', 'admin', 'operations_manager', 'hr_manager', 'viewer', 'driver'], true)) {
                    $folder_key = 'platform_admins';
                } else {
                    $folder_key = 'customers';
                }
            }
        }

        $user_item['folder_key'] = $folder_key;
        $user_item['is_store_owner'] = $is_store_owner;
        $user_item['assigned_store_name'] = $assigned_store_name;
    }
    unset($user_item);
}

// Build Folder Catalog with live counts
$folder_catalog = [
    'customers' => [
        'key' => 'customers',
        'type' => 'core',
        'title' => 'Customers',
        'subtitle' => 'Individual customer buyers and retail consumer accounts.',
        'icon' => 'fas fa-users',
        'icon_class' => 'folder-customers',
        'total_count' => 0,
        'active_count' => 0,
        'owner_count' => 0,
        'employee_count' => 0,
    ],
    'platform_admins' => [
        'key' => 'platform_admins',
        'type' => 'core',
        'title' => 'Platform Administrators',
        'subtitle' => 'System administrators, platform operators, and core staff.',
        'icon' => 'fas fa-shield-halved',
        'icon_class' => 'folder-admins',
        'total_count' => 0,
        'active_count' => 0,
        'owner_count' => 0,
        'employee_count' => 0,
    ],
];

foreach ($approved_stores as $s_owner_id => $store) {
    $fkey = $store['folder_key'];
    $folder_catalog[$fkey] = [
        'key' => $fkey,
        'type' => 'store',
        'store_id' => $s_owner_id,
        'title' => $store['store_name'],
        'subtitle' => 'Authorized franchise store and branch personnel.',
        'icon' => 'fas fa-store',
        'icon_class' => 'folder-store',
        'owner_name' => $store['owner_name'],
        'city' => $store['city'],
        'total_count' => 0,
        'active_count' => 0,
        'owner_count' => 0,
        'employee_count' => 0,
    ];
}

foreach ($users as $u) {
    $fk = $u['folder_key'] ?? 'customers';
    if (isset($folder_catalog[$fk])) {
        $folder_catalog[$fk]['total_count']++;
        if ((int)($u['is_active'] ?? 0) === 1) {
            $folder_catalog[$fk]['active_count']++;
        }
        if (!empty($u['is_store_owner'])) {
            $folder_catalog[$fk]['owner_count']++;
        } else {
            $folder_catalog[$fk]['employee_count']++;
        }
    }
}

$is_folder_active = ($active_folder !== '' && (isset($folder_catalog[$active_folder]) || $active_folder === 'all_users'));

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
<style>
/* ==========================================================================
   Super Admin User & Business Management — Card Directory Styles
   (Self-contained style block immune to browser CSS cache latency)
   ========================================================================== */
.user-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

@media (min-width: 1200px) {
    .user-card-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.user-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 18px 16px 14px 16px;
    position: relative;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04), 0 1px 2px rgba(16, 24, 40, 0.02);
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

.user-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px -4px rgba(16, 24, 40, 0.08), 0 4px 6px -2px rgba(16, 24, 40, 0.03);
    border-color: #d0d5dd;
}

body.dark-mode .user-card {
    background: #181d26 !important;
    border-color: #27303f !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

body.dark-mode .user-card:hover {
    border-color: #3b4758 !important;
    box-shadow: 0 12px 24px -4px rgba(0, 0, 0, 0.4);
}

.user-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    position: relative;
}

.user-card-header-left {
    display: flex;
    align-items: center;
    gap: 6px;
}

.user-status-pill {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    line-height: 1.2;
}

.user-status-pill.active {
    background: #eefcf4;
    color: #12b76a;
    border: 1px solid #d1fadf;
}

.user-status-pill.inactive {
    background: #fef3f2;
    color: #f04438;
    border: 1px solid #fee4e2;
}

.user-id-tag {
    font-size: 11px;
    font-weight: 700;
    color: #667085;
    background: #f8f9fa;
    padding: 2px 7px;
    border-radius: 6px;
    border: 1px solid #eaecf0;
}

body.dark-mode .user-id-tag {
    background: #1e2430;
    border-color: #3b4758;
    color: #94a3b8;
}

.user-card .dropdown {
    position: relative;
}

.user-more-btn {
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
}

.user-more-btn:hover {
    background: #f2f4f7;
    color: #344054;
}

body.dark-mode .user-more-btn:hover {
    background: #27303f;
    color: #f8fafc;
}

.user-card .dropdown-menu {
    border-radius: 10px;
    border: 1px solid #eaecf0;
    font-size: 13px;
    min-width: 175px;
    box-shadow: 0 10px 25px rgba(16, 24, 40, 0.1);
    z-index: 1050;
    margin-top: 4px !important;
}

.user-card .dropdown-menu.dropdown-menu-end {
    right: 0 !important;
    left: auto !important;
}

.user-avatar-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    margin-bottom: 10px;
}

.user-avatar-circle {
    width: 72px;
    height: 72px;
    border-radius: 18px; /* NodoHR squircle shape */
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    overflow: hidden;
    flex-shrink: 0;
}

body.dark-mode .user-avatar-circle {
    border-color: #27303f;
}

.user-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-avatar-initials {
    font-size: 24px;
    font-weight: 700;
    color: #ffffff;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    user-select: none;
}

.user-new-hire-tag {
    background: #0f172a;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    margin-top: -8px;
    z-index: 2;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    display: inline-flex;
    align-items: center;
    gap: 3px;
    max-width: 85%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.dark-mode .user-new-hire-tag {
    background: #334155;
    color: #f8fafc;
}

.user-card-name {
    font-size: 15px;
    font-weight: 700;
    color: #101828;
    text-align: center;
    margin: 4px 0 2px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

body.dark-mode .user-card-name {
    color: #f8fafc;
}

.user-card-role {
    font-size: 12px;
    color: #175cd3; /* NodoHR blue title */
    text-align: center;
    font-weight: 600;
    margin-bottom: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}

body.dark-mode .user-card-role {
    color: #60a5fa;
}

.user-meta-container {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-top: 1px solid #f2f4f7;
    border-bottom: 1px solid #f2f4f7;
    margin-bottom: 12px;
}

body.dark-mode .user-meta-container {
    border-color: #27303f !important;
}

.user-meta-col {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.user-meta-label {
    font-size: 11px;
    color: #98a2b3;
    margin-bottom: 2px;
    text-transform: capitalize;
    font-weight: 500;
}

body.dark-mode .user-meta-label {
    color: #64748b;
}

.user-meta-val {
    font-size: 12.5px;
    font-weight: 600;
    color: #344054;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.dark-mode .user-meta-val {
    color: #cbd5e1;
}

.user-contact-list {
    margin-bottom: 10px;
    flex-grow: 1;
}

.user-contact-item {
    display: flex;
    align-items: center;
    font-size: 12px;
    color: #475467;
    margin-bottom: 6px;
    overflow: hidden;
}

body.dark-mode .user-contact-item {
    color: #94a3b8;
}

.user-contact-item i {
    width: 14px;
    margin-right: 8px;
    color: #98a2b3;
    flex-shrink: 0;
    text-align: center;
}

.user-contact-item span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-account-strip {
    margin-top: auto;
    padding-top: 10px;
    border-top: 1px solid #f2f4f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

body.dark-mode .user-account-strip {
    border-top-color: #27303f;
}

.user-role-tag {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    max-width: 65%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-role-tag.linked {
    background: #eff8ff;
    color: #175cd3;
    border: 1px solid #b2ddff;
    cursor: pointer;
}

body.dark-mode .user-role-tag.linked {
    background: #172554;
    color: #93c5fd;
    border-color: #1e3a8a;
}

.user-role-tag.unlinked {
    background: #f8f9fa;
    color: #667085;
    border: 1px dashed #d0d5dd;
    cursor: pointer;
}

body.dark-mode .user-role-tag.unlinked {
    background: #1e2430;
    color: #94a3b8;
    border-color: #3b4758;
}

.user-role-tag.unlinked:hover {
    color: #b3261e;
    border-color: #b3261e;
}

/* ==========================================================================
   Super Admin Folder Directory System Styles
   ========================================================================== */
.sa-folders-section {
    margin-bottom: 24px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

#saStoresGridContainer {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

.sa-folder-category-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #eaecf0;
}

body.dark-mode .sa-folder-category-header {
    border-color: #27303f;
}

.sa-folder-category-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #101828;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

body.dark-mode .sa-folder-category-title {
    color: #f8fafc;
}

.sa-folder-category-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 10px;
    background: #f2f4f7;
    color: #475467;
}

body.dark-mode .sa-folder-category-badge {
    background: #1e2430;
    color: #94a3b8;
}

.sa-folder-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 215px), 1fr));
    gap: 10px;
    margin-bottom: 14px;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

.sa-folder-card {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 12px;
    padding: 8px 10px;
    cursor: pointer;
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    position: relative;
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.03);
    min-width: 0;
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

.sa-folder-card:hover {
    transform: translateY(-2px);
    border-color: #b3261e;
    box-shadow: 0 6px 14px -2px rgba(16, 24, 40, 0.08);
}

body.dark-mode .sa-folder-card {
    background: #181d26 !important;
    border-color: #27303f !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

body.dark-mode .sa-folder-card:hover {
    border-color: #b3261e !important;
    box-shadow: 0 8px 16px -2px rgba(0, 0, 0, 0.35);
}

.sa-folder-card-main {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1 1 auto;
    overflow: hidden;
}

.sa-folder-icon-box {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

.sa-folder-icon-box.folder-customers {
    background: #eff8ff;
    color: #175cd3;
}

.sa-folder-icon-box.folder-admins {
    background: #fff1f0;
    color: #b3261e;
}

.sa-folder-icon-box.folder-store {
    background: #ecfdf3;
    color: #027a48;
}

.sa-folder-text-wrap {
    min-width: 0;
    flex: 1 1 auto;
    overflow: hidden;
}

.sa-folder-title {
    font-size: 13px;
    font-weight: 700;
    color: #101828;
    margin: 0;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    width: 100%;
}

body.dark-mode .sa-folder-title {
    color: #f8fafc;
}

.sa-folder-sub {
    font-size: 11px;
    color: #667085;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
    display: block;
    width: 100%;
}

body.dark-mode .sa-folder-sub {
    color: #94a3b8;
}

.sa-folder-side-info {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-shrink: 0;
}

.sa-folder-count-pill {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    color: #344054;
    white-space: nowrap;
}

body.dark-mode .sa-folder-count-pill {
    background: #1e2430;
    border-color: #3b4758;
    color: #cbd5e1;
}

.sa-folder-chevron {
    font-size: 10.5px;
    color: #98a2b3;
    transition: transform 0.15s ease, color 0.15s ease;
}

.sa-folder-card:hover .sa-folder-chevron {
    color: #b3261e;
    transform: translateX(2px);
}

/* Active Folder Banner */
#saFoldersView, #saActiveFolderView {
    min-height: 420px;
}

.sa-active-folder-header {
    background: #ffffff;
    border: 1px solid #eaecf0;
    border-radius: 16px;
    padding: 18px 22px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04);
}

body.dark-mode .sa-active-folder-header {
    background: #181d26 !important;
    border-color: #27303f !important;
}

.sa-folder-nav-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}

.sa-folder-breadcrumb {
    font-size: 13px;
    color: #667085;
    display: flex;
    align-items: center;
    gap: 6px;
}

body.dark-mode .sa-folder-breadcrumb {
    color: #94a3b8;
}

.sa-folder-breadcrumb a {
    color: #b3261e;
    text-decoration: none;
    font-weight: 600;
}

.sa-folder-breadcrumb a:hover {
    text-decoration: underline;
}

.sa-folder-banner-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}

.sa-folder-banner-info {
    display: flex;
    align-items: center;
    gap: 14px;
}

.sa-folder-banner-title {
    font-size: 20px;
    font-weight: 800;
    color: #101828;
    margin: 0 0 4px 0;
}

body.dark-mode .sa-folder-banner-title {
    color: #f8fafc;
}

.sa-folder-banner-meta {
    font-size: 13px;
    color: #475467;
    margin: 0;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

body.dark-mode .sa-folder-banner-meta {
    color: #94a3b8;
}

.sa-folder-banner-meta span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.sa-folder-badge-stat {
    font-size: 11.5px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 12px;
    background: #f8f9fa;
    border: 1px solid #eaecf0;
    color: #344054;
}

body.dark-mode .sa-folder-badge-stat {
    background: #1e2430;
    border-color: #3b4758;
    color: #cbd5e1;
}

.sa-back-folders-btn {
    font-size: 12.5px;
    font-weight: 600;
    padding: 6px 14px;
    border-radius: 8px;
    border: 1px solid #d0d5dd;
    background: #ffffff;
    color: #344054;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
    cursor: pointer;
}

.sa-back-folders-btn:hover {
    background: #f8f9fa;
    color: #b3261e;
    border-color: #b3261e;
}

body.dark-mode .sa-back-folders-btn {
    background: #1e2430;
    border-color: #3b4758;
    color: #cbd5e1;
}

body.dark-mode .sa-back-folders-btn:hover {
    border-color: #b3261e;
    color: #f87171;
}

.sa-view-all-pill {
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #475467;
    background: #ffffff;
    border: 1px solid #d0d5dd;
    padding: 6px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}

.sa-view-all-pill:hover {
    color: #b3261e;
    border-color: #b3261e;
}

body.dark-mode .sa-view-all-pill {
    background: #1e2430;
    border-color: #3b4758;
    color: #cbd5e1;
}
</style>
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
    <!-- FOLDERS VIEW (Default Top-Level Directory View) -->
    <div id="saFoldersView" style="<?php echo $is_folder_active ? 'display: none;' : 'display: block;'; ?>">
        <div class="module-section-header" style="margin-bottom: 18px;">
            <div>
                <h3 class="mb-1">Platform Directories & User Folders</h3>
                <p class="module-subtext mb-0">Browse platform users, store employees, and assigned roles organized by department and business store.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="sa-view-all-pill" onclick="openFolder('all_users')" title="View all users in a flat list">
                    <i class="fas fa-users-viewfinder"></i> Flat List (All <?php echo count($users); ?> Users)
                </button>
            </div>
        </div>

        <!-- Global Search & Filters (Affects folders and user counts) -->
        <form method="GET" class="module-form-grid" style="margin-bottom: 22px;">
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

        <!-- Category 1: Platform & System Directories -->
        <div class="sa-folders-section">
            <div class="sa-folder-category-header">
                <h4 class="sa-folder-category-title">
                    <i class="fas fa-layer-group text-primary"></i> Platform Directories
                </h4>
                <span class="sa-folder-category-badge">2 Directories</span>
            </div>
            <div class="sa-folder-grid">
                <!-- Customers Folder Card -->
                <div class="sa-folder-card" onclick="openFolder('customers')" data-folder-key="customers" title="Open Customers Folder">
                    <div class="sa-folder-card-main">
                        <div class="sa-folder-icon-box folder-customers">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="sa-folder-text-wrap">
                            <h4 class="sa-folder-title">Customers</h4>
                            <div class="sa-folder-sub">
                                <i class="fas fa-check-circle text-success me-1"></i><?php echo $folder_catalog['customers']['active_count']; ?> Active Buyers
                            </div>
                        </div>
                    </div>
                    <div class="sa-folder-side-info">
                        <span class="sa-folder-count-pill"><?php echo $folder_catalog['customers']['total_count']; ?> Users</span>
                        <i class="fas fa-chevron-right sa-folder-chevron"></i>
                    </div>
                </div>

                <!-- Platform Administrators Folder Card -->
                <div class="sa-folder-card" onclick="openFolder('platform_admins')" data-folder-key="platform_admins" title="Open Administrators Folder">
                    <div class="sa-folder-card-main">
                        <div class="sa-folder-icon-box folder-admins">
                            <i class="fas fa-shield-halved"></i>
                        </div>
                        <div class="sa-folder-text-wrap">
                            <h4 class="sa-folder-title">Platform Administrators</h4>
                            <div class="sa-folder-sub">
                                <i class="fas fa-user-shield text-danger me-1"></i><?php echo $folder_catalog['platform_admins']['active_count']; ?> Active Staff
                            </div>
                        </div>
                    </div>
                    <div class="sa-folder-side-info">
                        <span class="sa-folder-count-pill"><?php echo $folder_catalog['platform_admins']['total_count']; ?> Staff</span>
                        <i class="fas fa-chevron-right sa-folder-chevron"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category 2: Approved Stores & Franchises (Dynamic) -->
        <div class="sa-folders-section">
            <div class="sa-folder-category-header">
                <h4 class="sa-folder-category-title">
                    <i class="fas fa-store text-danger"></i> Approved Stores & Franchises
                </h4>
                <div class="d-flex align-items-center gap-2">
                    <span class="sa-folder-category-badge"><?php echo count($approved_stores); ?> Approved Stores</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="toggleStoresCollapseBtn" onclick="toggleStoresCollapse()" style="font-size: 11px; border-radius: 6px;" title="Minimize / Expand Store Folders">
                        <i class="fas fa-chevron-up" id="storesCollapseIcon"></i> <span id="storesCollapseText">Minimize</span>
                    </button>
                </div>
            </div>

            <div id="saStoresGridContainer">
                <?php if (empty($approved_stores)): ?>
                    <div class="user-cards-empty" style="padding: 20px;">
                        <i class="fas fa-store-slash fa-2x mb-2 text-muted" style="opacity: 0.5;"></i>
                        <h6 class="fw-bold">No approved stores yet</h6>
                        <p class="text-muted small mb-0">Approve business applications below to automatically create store folders.</p>
                    </div>
                <?php else: ?>
                    <div class="sa-folder-grid">
                        <?php foreach ($approved_stores as $s_owner_id => $store): ?>
                            <?php
                                $fkey = $store['folder_key'];
                                $cat = $folder_catalog[$fkey] ?? null;
                                $total_members = $cat ? $cat['total_count'] : 0;
                                $owner_count = $cat ? $cat['owner_count'] : 1;
                                $emp_count = $cat ? $cat['employee_count'] : 0;
                                $store_city = !empty($store['city']) ? $store['city'] : '';
                            ?>
                            <div class="sa-folder-card" onclick="openFolder('<?php echo htmlspecialchars($fkey); ?>')" data-folder-key="<?php echo htmlspecialchars($fkey); ?>" title="<?php echo htmlspecialchars($store['store_name']); ?>">
                                <div class="sa-folder-card-main">
                                    <div class="sa-folder-icon-box folder-store">
                                        <i class="fas fa-store"></i>
                                    </div>
                                    <div class="sa-folder-text-wrap">
                                        <h4 class="sa-folder-title"><?php echo htmlspecialchars($store['store_name']); ?></h4>
                                        <div class="sa-folder-sub">
                                            <span><i class="fas fa-user-tie text-muted me-1"></i><?php echo htmlspecialchars($store['owner_name']); ?></span>
                                            <?php if ($store_city !== ''): ?>
                                                <span class="ms-1">· <?php echo htmlspecialchars($store_city); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="sa-folder-side-info">
                                    <span class="sa-folder-count-pill" title="<?php echo $owner_count; ?> Owner, <?php echo $emp_count; ?> Staff">
                                        <?php echo $total_members; ?> <?php echo $total_members === 1 ? 'member' : 'members'; ?>
                                    </span>
                                    <i class="fas fa-chevron-right sa-folder-chevron"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ACTIVE FOLDER VIEW (Shown when a folder is selected or via URL) -->
    <div id="saActiveFolderView" style="<?php echo $is_folder_active ? 'display: block;' : 'display: none;'; ?>">
        <?php
            $current_cat = $folder_catalog[$active_folder] ?? null;
            $active_title = $current_cat['title'] ?? ($active_folder === 'all_users' ? 'All Platform Users' : 'Folder Directory');
            $active_icon = $current_cat['icon'] ?? 'fas fa-folder';
            $active_icon_class = $current_cat['icon_class'] ?? 'folder-customers';
            $active_total = $current_cat['total_count'] ?? count($users);
            $active_active = $current_cat['active_count'] ?? $active_users;
        ?>
        <!-- Active Folder Header Banner & Navigation -->
        <div class="sa-active-folder-header">
            <div class="sa-folder-nav-bar">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="sa-back-folders-btn" onclick="showAllFolders()">
                        <i class="fas fa-arrow-left"></i> Back to All Folders
                    </button>
                    <div class="sa-folder-breadcrumb">
                        <a href="javascript:void(0)" onclick="showAllFolders()">Directories</a>
                        <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
                        <span id="activeFolderBreadcrumbName" class="fw-bold text-dark">
                            <?php echo htmlspecialchars($active_title); ?>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="view-mode-toggle" role="group" aria-label="View toggle">
                        <button type="button" class="view-mode-btn active" id="saViewModeCards" onclick="switchSaUserView('cards')" title="Card Grid View">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button type="button" class="view-mode-btn" id="saViewModeTable" onclick="switchSaUserView('table')" title="Table Spreadsheet View">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="sa-folder-banner-main">
                <div class="sa-folder-banner-info">
                    <div class="sa-folder-icon-box <?php echo htmlspecialchars($active_icon_class); ?>" id="activeFolderIconBox" style="width: 52px; height: 52px; font-size: 24px;">
                        <i id="activeFolderIcon" class="<?php echo htmlspecialchars($active_icon); ?>"></i>
                    </div>
                    <div>
                        <h3 id="activeFolderTitle" class="sa-folder-banner-title">
                            <?php echo htmlspecialchars($active_title); ?>
                        </h3>
                        <p id="activeFolderMeta" class="sa-folder-banner-meta">
                            <?php if ($current_cat && $current_cat['type'] === 'store'): ?>
                                <span><i class="fas fa-user-tie me-1"></i> Owner: <strong><?php echo htmlspecialchars($current_cat['owner_name'] ?? 'Store Owner'); ?></strong></span>
                                <?php if (!empty($current_cat['city'])): ?>
                                    <span><i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($current_cat['city']); ?></span>
                                <?php endif; ?>
                                <span><i class="fas fa-users-cog me-1"></i> <?php echo (int)($current_cat['owner_count'] ?? 1); ?> Owner, <?php echo (int)($current_cat['employee_count'] ?? 0); ?> Staff</span>
                            <?php elseif ($active_folder === 'customers'): ?>
                                <span><i class="fas fa-shopping-bag me-1"></i> Individual retail customer accounts</span>
                            <?php elseif ($active_folder === 'platform_admins'): ?>
                                <span><i class="fas fa-user-shield me-1"></i> Platform administrators and system personnel</span>
                            <?php else: ?>
                                <span><i class="fas fa-users-viewfinder me-1"></i> All registered platform accounts</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap" id="activeFolderBadges">
                    <span class="sa-folder-badge-stat">
                        <i class="fas fa-users text-primary me-1"></i> <strong id="activeFolderMemberCount"><?php echo $active_total; ?></strong> Members
                    </span>
                    <span class="sa-folder-badge-stat">
                        <i class="fas fa-circle-check text-success me-1"></i> <strong id="activeFolderActiveCount"><?php echo $active_active; ?></strong> Active
                    </span>
                </div>
            </div>
        </div>

        <!-- Live Quick Filter / Search Bar inside Active Folder -->
        <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
            <div style="flex-grow: 1; max-width: 460px; position: relative;">
                <input type="text" id="saFolderLiveSearch" class="form-control" placeholder="Quick search members by name, email, role, or ID..." style="padding-left: 36px; border-radius: 8px;">
                <i class="fas fa-search position-absolute text-muted" style="left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px;"></i>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small" id="saVisibleUserCount">Showing all <?php echo $active_total; ?> members</span>
            </div>
        </div>

        <?php if (!$has_users): ?>
            <div class="note-box">`users` table is unavailable in this environment.</div>
        <?php else: ?>
            <!-- Modern Card Grid View (Maintained Exact Card Design) -->
            <div class="user-card-grid" id="saUsersCardGrid">
                <?php if (empty($users)): ?>
                    <div class="user-cards-empty">
                        <i class="fas fa-users-slash fa-3x mb-3 text-muted" style="opacity: 0.5;"></i>
                        <h5 class="fw-bold">No users found</h5>
                        <p class="text-muted mb-0">No registered users matched the selected filter criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                            $user_id = (int)$user['id'];
                            $full_name = trim((string)($user['full_name'] ?? 'Unknown User'));
                            $full_name_safe = htmlspecialchars($full_name !== '' ? $full_name : 'Unknown User');
                            $email_clean = htmlspecialchars((string)($user['email'] ?? ''));
                            $phone_clean = htmlspecialchars((string)($user['phone'] ?? ''));
                            $user_type = ucfirst((string)($user['user_type'] ?? 'user'));
                            $account_type = ucfirst((string)($user['account_type'] ?? 'individual'));
                            $business_name = trim((string)($user['business_name'] ?? ''));
                            $role_name = !empty($user['role_name']) ? (string)$user['role_name'] : '';
                            $is_active = (int)($user['is_active'] ?? 0) === 1;
                            $folder_key = $user['folder_key'] ?? 'customers';
                            $is_store_owner = !empty($user['is_store_owner']);
                            $assigned_store = $user['assigned_store_name'] ?? '';

                            // Determine role title display
                            $display_role = '';
                            if ($is_store_owner) {
                                $display_role = 'Store Owner';
                            } elseif ($role_name !== '') {
                                $display_role = $role_name;
                            } elseif ($assigned_store !== '') {
                                $display_role = 'Store Staff';
                            } elseif ($folder_key === 'customers') {
                                $display_role = 'Customer';
                            } elseif ($folder_key === 'platform_admins') {
                                $display_role = 'Platform Administrator';
                            } else {
                                $display_role = ucfirst((string)($user['user_type'] ?? 'User'));
                            }

                            // Avatar photo or initials gradient
                            $photo_path = '';
                            $has_real_photo = false;
                            if (!empty($user['profile_image'])) {
                                $raw_img = trim((string)$user['profile_image']);
                                if (preg_match('/^https?:\/\//i', $raw_img)) {
                                    $photo_path = $raw_img;
                                    $has_real_photo = true;
                                } else {
                                    $clean_img = ltrim($raw_img, '/');
                                    if (file_exists(dirname(__DIR__) . '/' . $clean_img)) {
                                        $photo_path = '../' . $clean_img;
                                        $has_real_photo = true;
                                    }
                                }
                            }

                            $avatar_gradient = saGetUserAvatarGradient($full_name);
                            $name_parts = preg_split('/\s+/', $full_name);
                            $initials = '';
                            if (!empty($name_parts[0])) {
                                $initials .= mb_strtoupper(mb_substr($name_parts[0], 0, 1));
                            }
                            if (count($name_parts) > 1 && !empty($name_parts[count($name_parts) - 1])) {
                                $initials .= mb_strtoupper(mb_substr($name_parts[count($name_parts) - 1], 0, 1));
                            }
                            if ($initials === '') {
                                $initials = 'U';
                            }

                            // Status toggle SweetAlert confirmation data
                            $next_is_active = !$is_active;
                            $status_confirm_title = $next_is_active ? 'Activate User Account?' : 'Deactivate User Account?';
                            $status_confirm_text = $next_is_active
                                ? 'This user will regain access to platform modules.'
                                : 'This user will be blocked from logging in until reactivated.';
                            $status_confirm_button = $next_is_active ? 'Yes, Activate' : 'Yes, Deactivate';
                            $status_confirm_color = $next_is_active ? '#166534' : '#b3261e';

                            // Initial display based on active_folder
                            $initial_card_style = '';
                            if ($is_folder_active && $active_folder !== 'all_users' && $folder_key !== $active_folder) {
                                $initial_card_style = 'display: none;';
                            }
                        ?>
                        <div class="user-card" id="userCard_<?php echo $user_id; ?>"
                             data-folder="<?php echo htmlspecialchars($folder_key); ?>"
                             data-user-name="<?php echo strtolower(htmlspecialchars($full_name_safe)); ?>"
                             data-user-email="<?php echo strtolower(htmlspecialchars($email_clean)); ?>"
                             data-user-role="<?php echo strtolower(htmlspecialchars($role_name)); ?>"
                             data-user-id="<?php echo $user_id; ?>"
                             data-is-active="<?php echo $is_active ? '1' : '0'; ?>"
                             style="<?php echo $initial_card_style; ?>">
                            <!-- Top Header: Status pill & ID on left, 3-dots on right (NodoHR style) -->
                            <div class="user-card-header">
                                <div class="user-card-header-left">
                                    <span class="user-status-pill <?php echo $is_active ? 'active' : 'inactive'; ?>" id="cardStatusPill_<?php echo $user_id; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <span class="user-id-tag">#<?php echo $user_id; ?></span>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="user-more-btn" data-bs-toggle="dropdown" aria-expanded="false" title="More Actions">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <?php if ($has_roles): ?>
                                            <li>
                                                <a class="dropdown-item py-2 text-primary" href="javascript:void(0)" onclick="openAssignRoleModal(<?php echo $user_id; ?>, <?php echo htmlspecialchars(json_encode($full_name), ENT_QUOTES); ?>, <?php echo (int)($user['role_id'] ?? 0); ?>)">
                                                    <i class="fas fa-user-shield me-2"></i> Reassign Role
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider my-1"></li>
                                        <?php endif; ?>
                                        <li>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="toggle_user_status">
                                                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                                <input type="hidden" name="next_status" value="<?php echo $is_active ? 0 : 1; ?>">
                                                <input type="hidden" name="return_folder" class="sa-current-folder-input" value="<?php echo htmlspecialchars($active_folder); ?>">
                                                <button type="submit"
                                                        class="dropdown-item py-2 <?php echo $is_active ? 'text-danger' : 'text-success'; ?>"
                                                        data-sa-confirm="1"
                                                        data-sa-confirm-title="<?php echo htmlspecialchars($status_confirm_title, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-sa-confirm-text="<?php echo htmlspecialchars($status_confirm_text, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-sa-confirm-confirm-text="<?php echo htmlspecialchars($status_confirm_button, ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-sa-confirm-confirm-color="<?php echo htmlspecialchars($status_confirm_color, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="fas <?php echo $is_active ? 'fa-user-slash' : 'fa-user-check'; ?> me-2"></i>
                                                    <?php echo $is_active ? 'Deactivate Account' : 'Activate Account'; ?>
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Centered Squircle Avatar with optional badge tag below (NodoHR style) -->
                            <div class="user-avatar-section">
                                <div class="user-avatar-circle" style="<?php echo !$has_real_photo ? 'background: ' . $avatar_gradient . ';' : ''; ?>">
                                    <?php if ($has_real_photo): ?>
                                        <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="<?php echo $full_name_safe; ?>" class="user-avatar-img">
                                    <?php else: ?>
                                        <span class="user-avatar-initials"><?php echo htmlspecialchars($initials); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($is_store_owner): ?>
                                    <span class="user-new-hire-tag" title="Store Owner: <?php echo htmlspecialchars($assigned_store); ?>" style="background: #b3261e;">
                                        <i class="fas fa-crown"></i> Store Owner
                                    </span>
                                <?php elseif ($assigned_store !== ''): ?>
                                    <span class="user-new-hire-tag" title="Store Staff: <?php echo htmlspecialchars($assigned_store); ?>" style="background: #175cd3;">
                                        <i class="fas fa-id-badge"></i> Staff
                                    </span>
                                <?php elseif ($business_name !== ''): ?>
                                    <span class="user-new-hire-tag" title="<?php echo htmlspecialchars($business_name); ?>">
                                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($business_name); ?>
                                    </span>
                                <?php elseif ($verification !== 'unverified'): ?>
                                    <span class="user-new-hire-tag">
                                        <i class="fas fa-shield-alt"></i> <?php echo ucfirst($verification); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Full Name & Blue Role Title -->
                            <h3 class="user-card-name" title="<?php echo $full_name_safe; ?>">
                                <?php echo $full_name_safe; ?>
                            </h3>
                            <div class="user-card-role" title="<?php echo htmlspecialchars($display_role); ?>">
                                <?php echo htmlspecialchars($display_role); ?>
                            </div>

                            <!-- 2-Column Metadata Row (NodoHR flat divider layout) -->
                            <div class="user-meta-container">
                                <div class="user-meta-col">
                                    <span class="user-meta-label">Account Type</span>
                                    <span class="user-meta-val" title="<?php echo $account_type; ?>">
                                        <?php echo $account_type; ?>
                                    </span>
                                </div>
                                <div class="user-meta-col" style="text-align: right;">
                                    <span class="user-meta-label">Joined Date</span>
                                    <span class="user-meta-val"><?php echo htmlspecialchars(saFormatDateTime($user['created_at'] ?? null, 'd M Y', 'N/A')); ?></span>
                                </div>
                            </div>

                            <!-- Contact Info Rows -->
                            <div class="user-contact-list">
                                <div class="user-contact-item" title="<?php echo $email_clean; ?>">
                                    <i class="far fa-envelope"></i>
                                    <span><?php echo $email_clean !== '' ? $email_clean : 'No email'; ?></span>
                                </div>
                                <div class="user-contact-item" title="<?php echo $phone_clean; ?>">
                                    <i class="fas fa-phone-alt"></i>
                                    <span><?php echo $phone_clean !== '' ? $phone_clean : 'No phone'; ?></span>
                                </div>
                            </div>

                            <!-- User Account Role Strip -->
                            <div class="user-account-strip">
                                <?php if ($role_name !== ''): ?>
                                    <span class="user-role-tag linked" title="Assigned Role: <?php echo htmlspecialchars($role_name); ?> (Click to reassign)" onclick="openAssignRoleModal(<?php echo $user_id; ?>, <?php echo htmlspecialchars(json_encode($full_name), ENT_QUOTES); ?>, <?php echo (int)($user['role_id'] ?? 0); ?>)">
                                        <i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars($role_name); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="user-role-tag unlinked" title="Click to assign system role" onclick="openAssignRoleModal(<?php echo $user_id; ?>, <?php echo htmlspecialchars(json_encode($full_name), ENT_QUOTES); ?>, <?php echo (int)($user['role_id'] ?? 0); ?>)">
                                        <i class="fas fa-plus-circle"></i> Assign Role
                                    </span>
                                <?php endif; ?>

                                <form method="POST" style="display:inline; margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="toggle_user_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                    <input type="hidden" name="next_status" value="<?php echo $is_active ? 0 : 1; ?>">
                                    <input type="hidden" name="return_folder" class="sa-current-folder-input" value="<?php echo htmlspecialchars($active_folder); ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $is_active ? 'btn-outline-danger' : 'btn-outline-success'; ?>" style="font-size: 11px; padding: 2px 8px; border-radius: 6px; font-weight: 600;" data-sa-confirm="1" data-sa-confirm-title="<?php echo htmlspecialchars($status_confirm_title, ENT_QUOTES, 'UTF-8'); ?>" data-sa-confirm-text="<?php echo htmlspecialchars($status_confirm_text, ENT_QUOTES, 'UTF-8'); ?>" data-sa-confirm-confirm-text="<?php echo htmlspecialchars($status_confirm_button, ENT_QUOTES, 'UTF-8'); ?>" data-sa-confirm-confirm-color="<?php echo htmlspecialchars($status_confirm_color, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Empty Notice for Active Folder / Search Filter -->
            <div class="user-cards-empty d-none" id="saNoFolderUsersNotice">
                <i class="fas fa-users-slash fa-3x mb-3 text-muted" style="opacity: 0.5;"></i>
                <h5 class="fw-bold">No members found</h5>
                <p class="text-muted mb-0">No users or employees matched this folder or search query.</p>
            </div>

            <!-- Table View (Preserved as alternative spreadsheet view) -->
            <div class="table-wrap d-none" id="saUsersTableView">
                <table class="module-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Folder / Store</th>
                            <th>Role Management</th>
                            <th>Verification</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No users found for current filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $user_id = (int)$user['id'];
                                    $verification = strtolower(trim((string)($user['verification_status'] ?? '')));
                                    if ($verification === '') {
                                        $verification = 'unverified';
                                    }
                                    $folder_key = $user['folder_key'] ?? 'customers';
                                    $is_store_owner = !empty($user['is_store_owner']);
                                    $assigned_store = $user['assigned_store_name'] ?? '';
                                    $folder_name_title = $folder_catalog[$folder_key]['title'] ?? 'Folder';

                                    $initial_row_style = '';
                                    if ($is_folder_active && $active_folder !== 'all_users' && $folder_key !== $active_folder) {
                                        $initial_row_style = 'display: none;';
                                    }
                                ?>
                                <tr data-folder="<?php echo htmlspecialchars($folder_key); ?>"
                                    data-user-name="<?php echo strtolower(htmlspecialchars((string)($user['full_name'] ?? ''))); ?>"
                                    data-user-email="<?php echo strtolower(htmlspecialchars((string)($user['email'] ?? ''))); ?>"
                                    data-user-role="<?php echo strtolower(htmlspecialchars((string)($user['role_name'] ?? ''))); ?>"
                                    data-user-id="<?php echo $user_id; ?>"
                                    data-is-active="<?php echo ((int)($user['is_active'] ?? 0) === 1) ? '1' : '0'; ?>"
                                    style="<?php echo $initial_row_style; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars((string)($user['full_name'] ?? 'Unknown')); ?></strong><br>
                                        <span class="compact-text"><?php echo htmlspecialchars((string)($user['email'] ?? '')); ?></span><br>
                                        <span class="compact-text">
                                            #<?php echo $user_id; ?> · <?php echo ucfirst((string)($user['user_type'] ?? 'user')); ?> /
                                            <?php echo ucfirst((string)($user['account_type'] ?? 'individual')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <i class="fas fa-folder me-1 text-muted"></i><?php echo htmlspecialchars($folder_name_title); ?>
                                        </span>
                                        <?php if ($is_store_owner): ?>
                                            <span class="badge bg-danger ms-1">Owner</span>
                                        <?php elseif ($assigned_store !== ''): ?>
                                            <span class="badge bg-primary ms-1">Staff</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_roles): ?>
                                            <form method="POST" class="module-inline-actions">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="update_user_role">
                                                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                                <input type="hidden" name="return_folder" class="sa-current-folder-input" value="<?php echo htmlspecialchars($active_folder); ?>">
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
                                            $status_confirm_color = $next_is_active ? '#166534' : '#b3261e';
                                        ?>
                                        <form method="POST" class="module-inline-actions">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                                            <input type="hidden" name="next_status" value="<?php echo ((int)$user['is_active'] === 1) ? 0 : 1; ?>">
                                            <input type="hidden" name="return_folder" class="sa-current-folder-input" value="<?php echo htmlspecialchars($active_folder); ?>">
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
                                            <input type="text" name="admin_notes" class="form-control form-control-sm" placeholder="Reason required for rejection" style="min-width: 180px;">
                                            <button type="submit"
                                                    class="btn btn-sm btn-primary"
                                                    data-sa-confirm="1"
                                                    data-sa-confirm-title-template="Confirm {field_label:decision} Decision?"
                                                    data-sa-confirm-text-template="This will mark this business application as {field_label:decision}."
                                                    data-sa-confirm-confirm-text-template="Yes, {field_label:decision}"
                                                    data-sa-confirm-confirm-color="#b3261e">
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
                        <th>Assigned Users</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars((string)$role['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)($role['description'] ?? '')); ?></td>
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

<!-- Modal for Reassigning User Role -->
<div class="modal fade modern-form-modal" id="assignRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-icon"><i class="fas fa-user-shield"></i></div>
                <div>
                    <h5 class="modal-title">Assign User Role</h5>
                    <p class="modal-subtitle">Update system role permissions for this user.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="update_user_role">
                    <input type="hidden" name="user_id" id="modal_assign_user_id">
                    <input type="hidden" name="return_folder" id="modal_assign_return_folder" value="<?php echo htmlspecialchars($active_folder); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label" style="font-size: 12px; font-weight: 600; color: var(--sa-muted);">User Account</label>
                        <input type="text" id="modal_assign_user_name" class="form-control" readonly style="background: #f8f9fa; font-weight: 600;">
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label" style="font-size: 12px; font-weight: 600; color: var(--sa-muted);">System Role</label>
                        <select name="role_id" id="modal_assign_role_id" class="form-select">
                            <option value="">No Role (Customer / Default)</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo (int)$role['id']; ?>">
                                    <?php echo htmlspecialchars((string)$role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 14px 24px; border-top: 1px solid var(--sa-border); background: var(--sa-card-bg); display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background: var(--sa-primary); border-color: var(--sa-primary);">Save Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const saFolderCatalog = <?php echo json_encode($folder_catalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let currentFolderKey = <?php echo json_encode($active_folder); ?> || '';

function openAssignRoleModal(userId, userName, currentRoleId) {
    document.getElementById('modal_assign_user_id').value = userId;
    document.getElementById('modal_assign_user_name').value = userName;
    const returnFolderInput = document.getElementById('modal_assign_return_folder');
    if (returnFolderInput) {
        returnFolderInput.value = currentFolderKey || '';
    }
    const roleSelect = document.getElementById('modal_assign_role_id');
    if (roleSelect) {
        roleSelect.value = currentRoleId || '';
    }
    const modalEl = document.getElementById('assignRoleModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
}

function openFolder(folderKey, updateUrl = true) {
    currentFolderKey = folderKey;
    const foldersView = document.getElementById('saFoldersView');
    const activeView = document.getElementById('saActiveFolderView');
    const breadcrumbName = document.getElementById('activeFolderBreadcrumbName');
    const folderTitle = document.getElementById('activeFolderTitle');
    const folderMeta = document.getElementById('activeFolderMeta');
    const iconEl = document.getElementById('activeFolderIcon');
    const iconBox = document.getElementById('activeFolderIconBox');
    const liveSearchInput = document.getElementById('saFolderLiveSearch');

    if (!foldersView || !activeView) return;

    if (liveSearchInput) {
        liveSearchInput.value = '';
    }

    // Determine target anchor scroll position at the top of the directory section
    const section = activeView.closest('.module-section') || foldersView.closest('.module-section');
    const targetScrollY = section ? (section.getBoundingClientRect().top + window.pageYOffset - 16) : window.pageYOffset;

    if (folderKey === 'all_users') {
        if (breadcrumbName) breadcrumbName.textContent = 'All Platform Users';
        if (folderTitle) folderTitle.textContent = 'All Platform Users';
        if (folderMeta) folderMeta.innerHTML = '<span><i class="fas fa-users-viewfinder me-1"></i> Flat unfiltered directory across all registered users and staff</span>';
        if (iconEl) iconEl.className = 'fas fa-users-viewfinder';
        if (iconBox) iconBox.className = 'sa-folder-icon-box folder-customers';
    } else {
        const cat = saFolderCatalog[folderKey];
        if (cat) {
            if (breadcrumbName) breadcrumbName.textContent = cat.title;
            if (folderTitle) folderTitle.textContent = cat.title;
            
            let metaHtml = '';
            if (cat.type === 'store') {
                metaHtml = '<span><i class="fas fa-user-tie me-1"></i> Owner: <strong>' + (cat.owner_name || 'Store Owner') + '</strong></span>';
                if (cat.city) {
                    metaHtml += '<span><i class="fas fa-map-marker-alt me-1"></i> ' + cat.city + '</span>';
                }
                metaHtml += '<span><i class="fas fa-users-cog me-1"></i> ' + (cat.owner_count || 1) + ' Owner, ' + (cat.employee_count || 0) + ' Staff</span>';
            } else if (cat.key === 'customers') {
                metaHtml = '<span><i class="fas fa-shopping-bag me-1"></i> Registered customer accounts</span>';
            } else if (cat.key === 'platform_admins') {
                metaHtml = '<span><i class="fas fa-user-shield me-1"></i> Platform administrators and system personnel</span>';
            }
            if (folderMeta) folderMeta.innerHTML = metaHtml;

            if (iconEl) {
                iconEl.className = cat.icon || 'fas fa-folder';
            }
            if (iconBox) {
                iconBox.className = 'sa-folder-icon-box ' + (cat.icon_class || 'folder-customers');
            }
        }
    }

    // Toggle container views
    foldersView.style.display = 'none';
    activeView.style.display = 'block';

    // Filter user elements
    filterUserElements(folderKey, '');

    // Keep hidden inputs in sync for POST redirects
    document.querySelectorAll('.sa-current-folder-input').forEach(input => {
        input.value = folderKey;
    });
    const modalFolderInput = document.getElementById('modal_assign_return_folder');
    if (modalFolderInput) {
        modalFolderInput.value = folderKey;
    }

    // Push URL state without reloading
    if (updateUrl) {
        const newUrl = (folderKey && folderKey !== 'all_folders')
            ? ('?folder=' + encodeURIComponent(folderKey))
            : window.location.pathname;
        history.pushState({ folder: folderKey }, '', newUrl);
    }

    // Keep page firmly anchored at the top of the folder view so it never scrolls down
    window.scrollTo({
        top: Math.max(0, targetScrollY),
        behavior: 'instant'
    });
}

function showAllFolders(updateUrl = true) {
    currentFolderKey = '';
    const foldersView = document.getElementById('saFoldersView');
    const activeView = document.getElementById('saActiveFolderView');

    const section = (foldersView ? foldersView.closest('.module-section') : null) || (activeView ? activeView.closest('.module-section') : null);
    const targetScrollY = section ? (section.getBoundingClientRect().top + window.pageYOffset - 16) : window.pageYOffset;

    if (foldersView) foldersView.style.display = 'block';
    if (activeView) activeView.style.display = 'none';

    document.querySelectorAll('.sa-current-folder-input').forEach(input => {
        input.value = '';
    });
    const modalFolderInput = document.getElementById('modal_assign_return_folder');
    if (modalFolderInput) {
        modalFolderInput.value = '';
    }

    if (updateUrl) {
        history.pushState({}, '', window.location.pathname);
    }

    // Keep page firmly anchored at the top of the directories so it never scrolls down
    window.scrollTo({
        top: Math.max(0, targetScrollY),
        behavior: 'instant'
    });
}

function filterUserElements(folderKey, searchQuery) {
    const query = (searchQuery || '').toLowerCase().trim();
    const cards = document.querySelectorAll('#saUsersCardGrid .user-card');
    const rows = document.querySelectorAll('#saUsersTableView tbody tr[data-folder]');
    const emptyNotice = document.getElementById('saNoFolderUsersNotice');
    const visibleCountEl = document.getElementById('saVisibleUserCount');
    const memberCountEl = document.getElementById('activeFolderMemberCount');
    const activeCountEl = document.getElementById('activeFolderActiveCount');

    let visibleCount = 0;
    let activeCount = 0;

    cards.forEach(card => {
        const cardFolder = card.dataset.folder || '';
        const name = card.dataset.userName || '';
        const email = card.dataset.userEmail || '';
        const role = card.dataset.userRole || '';
        const id = card.dataset.userId || '';
        const isActive = card.dataset.isActive === '1';

        const matchesFolder = (folderKey === 'all_users' || cardFolder === folderKey);
        const matchesQuery = !query || name.includes(query) || email.includes(query) || role.includes(query) || id.includes(query);

        if (matchesFolder && matchesQuery) {
            card.style.display = '';
            visibleCount++;
            if (isActive) activeCount++;
        } else {
            card.style.display = 'none';
        }
    });

    rows.forEach(row => {
        const rowFolder = row.dataset.folder || '';
        const name = row.dataset.userName || '';
        const email = row.dataset.userEmail || '';
        const role = row.dataset.userRole || '';
        const id = row.dataset.userId || '';

        const matchesFolder = (folderKey === 'all_users' || rowFolder === folderKey);
        const matchesQuery = !query || name.includes(query) || email.includes(query) || role.includes(query) || id.includes(query);

        if (matchesFolder && matchesQuery) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    if (memberCountEl) memberCountEl.textContent = visibleCount;
    if (activeCountEl) activeCountEl.textContent = activeCount;
    if (visibleCountEl) {
        visibleCountEl.textContent = query
            ? ('Found ' + visibleCount + ' matching member' + (visibleCount === 1 ? '' : 's'))
            : ('Showing all ' + visibleCount + ' member' + (visibleCount === 1 ? '' : 's'));
    }

    if (emptyNotice) {
        if (visibleCount === 0) {
            emptyNotice.classList.remove('d-none');
        } else {
            emptyNotice.classList.add('d-none');
        }
    }
}

function switchSaUserView(mode) {
    const cardGrid = document.getElementById('saUsersCardGrid');
    const tableView = document.getElementById('saUsersTableView');
    const btnCards = document.getElementById('saViewModeCards');
    const btnTable = document.getElementById('saViewModeTable');

    if (!cardGrid || !tableView) return;

    if (mode === 'table') {
        cardGrid.classList.add('d-none');
        tableView.classList.remove('d-none');
        if (btnCards) btnCards.classList.remove('active');
        if (btnTable) btnTable.classList.add('active');
        localStorage.setItem('sa_user_biz_view_mode', 'table');
    } else {
        cardGrid.classList.remove('d-none');
        tableView.classList.add('d-none');
        if (btnCards) btnCards.classList.add('active');
        if (btnTable) btnTable.classList.remove('active');
        localStorage.setItem('sa_user_biz_view_mode', 'cards');
    }
}

function toggleStoresCollapse() {
    const container = document.getElementById('saStoresGridContainer');
    const icon = document.getElementById('storesCollapseIcon');
    const text = document.getElementById('storesCollapseText');
    if (!container) return;

    if (container.style.display === 'none') {
        container.style.display = '';
        if (icon) icon.className = 'fas fa-chevron-up';
        if (text) text.textContent = 'Minimize';
        localStorage.setItem('sa_stores_collapsed', '0');
    } else {
        container.style.display = 'none';
        if (icon) icon.className = 'fas fa-chevron-down';
        if (text) text.textContent = 'Expand';
        localStorage.setItem('sa_stores_collapsed', '1');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const savedView = localStorage.getItem('sa_user_biz_view_mode');
    if (savedView === 'table') {
        switchSaUserView('table');
    } else {
        switchSaUserView('cards');
    }

    // Restore saved stores collapse state
    const savedStoresCollapse = localStorage.getItem('sa_stores_collapsed');
    if (savedStoresCollapse === '1') {
        const container = document.getElementById('saStoresGridContainer');
        const icon = document.getElementById('storesCollapseIcon');
        const text = document.getElementById('storesCollapseText');
        if (container) container.style.display = 'none';
        if (icon) icon.className = 'fas fa-chevron-down';
        if (text) text.textContent = 'Expand';
    }

    const liveSearchInput = document.getElementById('saFolderLiveSearch');
    if (liveSearchInput) {
        liveSearchInput.addEventListener('input', function(e) {
            filterUserElements(currentFolderKey, e.target.value);
        });
    }

    // Check URL parameter for initial folder
    const urlParams = new URLSearchParams(window.location.search);
    const initialFolder = urlParams.get('folder');
    if (initialFolder && (saFolderCatalog[initialFolder] || initialFolder === 'all_users')) {
        openFolder(initialFolder, false);
    }
});

// Handle browser Back / Forward buttons
window.addEventListener('popstate', function(e) {
    const urlParams = new URLSearchParams(window.location.search);
    const folderParam = urlParams.get('folder');
    if (folderParam && (saFolderCatalog[folderParam] || folderParam === 'all_users')) {
        openFolder(folderParam, false);
    } else {
        showAllFolders(false);
    }
});
</script>

<?php
saRenderModuleFooter();
