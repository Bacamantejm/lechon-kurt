<?php
session_start();
include 'auth.php';
include '../includes/config.php';
include '../includes/security.php';

checkAdminAccess();
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$partner_scope_owner_id = $is_partner_scoped_admin ? (int)(getFranchiseSellerScopeOwnerId($conn, $current_user_id) ?? 0) : 0;
$is_partner_owner_admin = $is_partner_scoped_admin && $partner_scope_owner_id === $current_user_id;
if ($is_partner_scoped_admin && $partner_scope_owner_id <= 0) {
    $_SESSION['error'] = 'Unable to resolve partner ownership scope.';
    header('Location: products.php');
    exit();
}
if (!$is_partner_scoped_admin) {
    requirePermission('roles.manage');
}

$admin_info = getAdminInfo($conn);
$is_super_admin_user = $current_user_id > 0 && function_exists('isSuperAdmin') ? isSuperAdmin($conn, $current_user_id) : false;

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = function_exists('generateCSRFToken') ? generateCSRFToken() : bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function rbacTableExists($conn, $table_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
    return $res && mysqli_num_rows($res) > 0;
}

function rbacColumnExists($conn, $table_name, $column_name) {
    $safe_table = mysqli_real_escape_string($conn, $table_name);
    $safe_col = mysqli_real_escape_string($conn, $column_name);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_col}'");
    return $res && mysqli_num_rows($res) > 0;
}

function rbacRoleBelongsToPartner($conn, $role_id, $owner_user_id) {
    $role_id = (int)$role_id;
    $owner_user_id = (int)$owner_user_id;
    if ($role_id <= 0 || $owner_user_id <= 0 || !rbacColumnExists($conn, 'roles', 'owner_user_id')) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE id = ? AND owner_user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "ii", $role_id, $owner_user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $ok = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

function rbacUserBelongsToPartner($conn, $user_id, $owner_user_id) {
    $user_id = (int)$user_id;
    $owner_user_id = (int)$owner_user_id;
    if ($user_id <= 0 || $owner_user_id <= 0 || !rbacTableExists($conn, 'partner_user_links')) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT managed_user_id FROM partner_user_links WHERE owner_user_id = ? AND managed_user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, "ii", $owner_user_id, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $ok = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

function rbacRoleDisplayName($role_name, $partner_owner_id = 0) {
    $role_name = (string)$role_name;
    $partner_owner_id = (int)$partner_owner_id;

    if ($partner_owner_id > 0) {
        $expected_prefix = 'partner_' . $partner_owner_id . '_';
        if (strpos($role_name, $expected_prefix) === 0) {
            $clean_name = substr($role_name, strlen($expected_prefix));
            return ucwords(str_replace('_', ' ', $clean_name));
        }
    }

    if (preg_match('/^partner_[0-9]+_(.+)$/', $role_name, $m)) {
        return ucwords(str_replace('_', ' ', (string)$m[1]));
    }

    return getRoleDisplayName($role_name);
}

function rbacNormalizeRoleSlug($role_name) {
    $role_name = strtolower(trim((string)$role_name));
    $role_name = preg_replace('/[^a-z0-9]+/', '_', $role_name);
    $role_name = trim((string)$role_name, '_');
    $role_name = preg_replace('/_+/', '_', $role_name);
    return (string)$role_name;
}

function rbacIsReservedRoleName($role_name) {
    $role_name = strtolower(trim((string)$role_name));
    if ($role_name === '') {
        return true;
    }
    if (in_array($role_name, ['super_admin'], true)) {
        return true;
    }
    return preg_match('/^partner_[0-9]+_.+$/', $role_name) === 1;
}

$partner_roles_owner_column_exists = rbacColumnExists($conn, 'roles', 'owner_user_id');
$partner_user_links_table_exists = rbacTableExists($conn, 'partner_user_links');
$partner_scope_schema_ready = $partner_roles_owner_column_exists && $partner_user_links_table_exists;

// Determine active tab
$allowed_tabs = ['permissions', 'users', 'create'];
if ($is_partner_scoped_admin && !$is_partner_owner_admin) {
    $allowed_tabs = ['users'];
}
$active_tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : ($is_partner_scoped_admin ? 'users' : 'permissions');
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = $allowed_tabs[0] ?? 'users';
}

function tabSafe($tab, $allowed_tabs) {
    $tab = trim((string)$tab);
    if (in_array($tab, $allowed_tabs, true)) {
        return $tab;
    }
    return $allowed_tabs[0] ?? 'users';
}

$partner_permission_modules = ['dashboard', 'orders', 'preorders', 'logistics', 'inventory', 'products', 'mrp', 'finance', 'billing', 'forecasting', 'hr', 'payroll', 'deductions'];
$partner_permission_ids_allowed = [];
if ($is_partner_scoped_admin) {
    $perm_stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM permissions WHERE module IN ('dashboard','orders','preorders','logistics','inventory','products','mrp','finance','billing','forecasting','hr','payroll','deductions')"
    );
    if ($perm_stmt) {
        mysqli_stmt_execute($perm_stmt);
        $perm_res = mysqli_stmt_get_result($perm_stmt);
        while ($perm_row = mysqli_fetch_assoc($perm_res)) {
            $partner_permission_ids_allowed[(int)$perm_row['id']] = true;
        }
        mysqli_stmt_close($perm_stmt);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $posted_token = $_POST['csrf_token'] ?? '';
        $csrf_ok = function_exists('validateCSRFToken')
            ? validateCSRFToken($posted_token)
            : (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && is_string($posted_token) && hash_equals($_SESSION['csrf_token'], $posted_token));
        if (!$csrf_ok) {
            $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
            $return_tab = tabSafe($_POST['return_tab'] ?? $active_tab, $allowed_tabs);
            header("Location: rbac_management.php?tab={$return_tab}");
            exit();
        }
        $action = $_POST['action'];
        if ($is_partner_scoped_admin && !$is_partner_owner_admin) {
            $_SESSION['error'] = 'RBAC updates are restricted to the partner owner account.';
            header("Location: rbac_management.php?tab=users");
            exit();
        }

        // Update role permissions
        if ($action === 'update_permissions') {
            $role_id = intval($_POST['role_id'] ?? 0);
            
            if ($role_id <= 0) {
                $_SESSION['error'] = 'Invalid role selected';
            } elseif ($is_partner_owner_admin && !rbacRoleBelongsToPartner($conn, $role_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'You can only update permissions for your own partner roles.';
            } else {
                $target_role = getRoleById($conn, $role_id);
                if (!$target_role) {
                    $_SESSION['error'] = 'Selected role no longer exists.';
                } elseif (($target_role['name'] ?? '') === 'super_admin' && !$is_super_admin_user) {
                    $_SESSION['error'] = 'Only Super Administrator can modify Super Administrator permissions.';
                } else {
                    $permission_ids = [];
                    if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                        foreach ($_POST['permissions'] as $permission_id) {
                            $permission_id = (int)$permission_id;
                            if ($permission_id > 0) {
                                if ($is_partner_owner_admin && !isset($partner_permission_ids_allowed[$permission_id])) {
                                    continue;
                                }
                                $permission_ids[$permission_id] = true;
                            }
                        }
                    }
                    $permission_ids = array_keys($permission_ids);

                    mysqli_begin_transaction($conn);
                    try {
                        $delete_query = "DELETE FROM role_permissions WHERE role_id = ?";
                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                        if (!$delete_stmt) {
                            throw new Exception('Failed to prepare delete permissions query.');
                        }
                        mysqli_stmt_bind_param($delete_stmt, "i", $role_id);
                        if (!mysqli_stmt_execute($delete_stmt)) {
                            throw new Exception('Failed to clear existing permissions.');
                        }
                        mysqli_stmt_close($delete_stmt);

                        $permissions_added = 0;
                        if (!empty($permission_ids)) {
                            $insert_query = "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)";
                            $insert_stmt = mysqli_prepare($conn, $insert_query);
                            if (!$insert_stmt) {
                                throw new Exception('Failed to prepare permission assignment query.');
                            }
                            foreach ($permission_ids as $permission_id) {
                                mysqli_stmt_bind_param($insert_stmt, "ii", $role_id, $permission_id);
                                if (!mysqli_stmt_execute($insert_stmt)) {
                                    throw new Exception('Failed while assigning permissions.');
                                }
                                $permissions_added++;
                            }
                            mysqli_stmt_close($insert_stmt);
                        }

                        mysqli_commit($conn);
                        $_SESSION['success'] = "Role permissions updated successfully. ($permissions_added permissions assigned)";
                        logRBACAction($conn, $current_user_id, 'ROLE_UPDATED', 'roles', "Updated role {$target_role['name']} (ID {$role_id}) with {$permissions_added} permissions");
                    } catch (Throwable $e) {
                        mysqli_rollback($conn);
                        $_SESSION['error'] = 'Failed to update role permissions safely.';
                    }
                }
            }
            header("Location: rbac_management.php?tab=permissions&role_id=$role_id");
            exit();
        }
        
        // Create new role
        if ($action === 'create_role') {
            $role_name_input = trim((string)($_POST['role_name'] ?? ''));
            $role_name = rbacNormalizeRoleSlug($role_name_input);
            $role_description = trim($_POST['role_description'] ?? '');
            
            // Validation
            if (empty($role_name)) {
                $_SESSION['error'] = 'Role name is required';
            } elseif (empty($role_description)) {
                $_SESSION['error'] = 'Role description is required';
            } elseif (strlen($role_name) < 3 || strlen($role_name) > 50) {
                $_SESSION['error'] = 'Role name must be 3 to 50 characters.';
            } elseif (strlen($role_description) > 1000) {
                $_SESSION['error'] = 'Role description is too long.';
            } elseif (!preg_match('/^[a-z0-9_]+$/', $role_name)) {
                $_SESSION['error'] = 'Role name can only contain letters, numbers, and underscores.';
            } elseif (rbacIsReservedRoleName($role_name)) {
                $_SESSION['error'] = 'This role name is reserved. Please use a different name.';
            } else {
                if ($is_partner_owner_admin && !rbacColumnExists($conn, 'roles', 'owner_user_id')) {
                    $_SESSION['error'] = 'Role ownership schema is missing. Run tenant scope migration first.';
                    header("Location: rbac_management.php?tab=create");
                    exit();
                }

                $stored_role_name = $role_name;
                if ($is_partner_owner_admin) {
                    $stored_role_name = 'partner_' . (int)$partner_scope_owner_id . '_' . $role_name;
                }

                // Check if role exists
                $check_query = "SELECT id FROM roles WHERE name = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "s", $stored_role_name);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $_SESSION['error'] = 'Role name already exists';
                } else {
                    // Insert new role
                    $insert_query = $is_partner_owner_admin
                        ? "INSERT INTO roles (name, description, is_active, owner_user_id) VALUES (?, ?, 1, ?)"
                        : "INSERT INTO roles (name, description, is_active) VALUES (?, ?, 1)";
                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    if ($is_partner_owner_admin) {
                        mysqli_stmt_bind_param($insert_stmt, "ssi", $stored_role_name, $role_description, $partner_scope_owner_id);
                    } else {
                        mysqli_stmt_bind_param($insert_stmt, "ss", $stored_role_name, $role_description);
                    }
                    
                    if (mysqli_stmt_execute($insert_stmt)) {
                        $_SESSION['success'] = "Role '" . rbacRoleDisplayName($stored_role_name, $is_partner_owner_admin ? $partner_scope_owner_id : 0) . "' created successfully.";
                        logRBACAction($conn, $current_user_id, 'ROLE_CREATED', 'roles', "Created new role: $stored_role_name");
                    } else {
                        $_SESSION['error'] = 'Failed to create role: ' . mysqli_error($conn);
                    }
                    mysqli_stmt_close($insert_stmt);
                }
                mysqli_stmt_close($check_stmt);
            }
            header("Location: rbac_management.php?tab=create");
            exit();
        }

        // Clone existing role and copy permissions
        if ($action === 'clone_role') {
            $source_role_id = (int)($_POST['source_role_id'] ?? 0);
            $clone_role_input = trim((string)($_POST['clone_role_name'] ?? ''));
            $clone_role_name = rbacNormalizeRoleSlug($clone_role_input);
            $clone_role_description = trim((string)($_POST['clone_role_description'] ?? ''));

            if ($source_role_id <= 0) {
                $_SESSION['error'] = 'Please select a source role to clone.';
                header('Location: rbac_management.php?tab=permissions');
                exit();
            }

            $source_role = getRoleById($conn, $source_role_id);
            if (!$source_role) {
                $_SESSION['error'] = 'Source role no longer exists.';
                header('Location: rbac_management.php?tab=permissions');
                exit();
            }
            if ($is_partner_owner_admin && !rbacRoleBelongsToPartner($conn, $source_role_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'You can only clone roles within your partner scope.';
                header('Location: rbac_management.php?tab=permissions');
                exit();
            }
            if (($source_role['name'] ?? '') === 'super_admin' && !$is_super_admin_user) {
                $_SESSION['error'] = 'Only Super Administrator can clone Super Administrator role.';
                header('Location: rbac_management.php?tab=permissions');
                exit();
            }

            if ($clone_role_name === '') {
                $_SESSION['error'] = 'New role name is required.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }
            if (!preg_match('/^[a-z0-9_]+$/', $clone_role_name)) {
                $_SESSION['error'] = 'New role name can only contain letters, numbers, and underscores.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }
            if (strlen($clone_role_name) < 3 || strlen($clone_role_name) > 50) {
                $_SESSION['error'] = 'New role name must be 3 to 50 characters.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }
            if (rbacIsReservedRoleName($clone_role_name)) {
                $_SESSION['error'] = 'New role name is reserved. Please choose another.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }

            if ($clone_role_description === '') {
                $source_desc = trim((string)($source_role['description'] ?? ''));
                $clone_role_description = ($source_desc !== '' ? $source_desc . ' ' : '') . '(Cloned from role ID ' . $source_role_id . ')';
            }
            if (strlen($clone_role_description) > 1000) {
                $_SESSION['error'] = 'Role description is too long.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }

            $stored_clone_name = $clone_role_name;
            if ($is_partner_owner_admin) {
                if (!rbacColumnExists($conn, 'roles', 'owner_user_id')) {
                    $_SESSION['error'] = 'Role ownership schema is missing. Run tenant scope migration first.';
                    header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                    exit();
                }
                $stored_clone_name = 'partner_' . (int)$partner_scope_owner_id . '_' . $clone_role_name;
            }

            $check_stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE name = ? LIMIT 1");
            if (!$check_stmt) {
                $_SESSION['error'] = 'Unable to validate role uniqueness.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }
            mysqli_stmt_bind_param($check_stmt, "s", $stored_clone_name);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            $role_exists = mysqli_stmt_num_rows($check_stmt) > 0;
            mysqli_stmt_close($check_stmt);
            if ($role_exists) {
                $_SESSION['error'] = 'Role name already exists.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }

            mysqli_begin_transaction($conn);
            try {
                $insert_role_sql = $is_partner_owner_admin
                    ? "INSERT INTO roles (name, description, is_active, owner_user_id) VALUES (?, ?, 1, ?)"
                    : "INSERT INTO roles (name, description, is_active) VALUES (?, ?, 1)";
                $insert_role_stmt = mysqli_prepare($conn, $insert_role_sql);
                if (!$insert_role_stmt) {
                    throw new Exception('Unable to prepare clone role insert.');
                }
                if ($is_partner_owner_admin) {
                    mysqli_stmt_bind_param($insert_role_stmt, "ssi", $stored_clone_name, $clone_role_description, $partner_scope_owner_id);
                } else {
                    mysqli_stmt_bind_param($insert_role_stmt, "ss", $stored_clone_name, $clone_role_description);
                }
                if (!mysqli_stmt_execute($insert_role_stmt)) {
                    throw new Exception('Unable to create cloned role.');
                }
                $new_role_id = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($insert_role_stmt);

                $source_perm_ids = [];
                $perm_stmt = mysqli_prepare($conn, "SELECT permission_id FROM role_permissions WHERE role_id = ?");
                if (!$perm_stmt) {
                    throw new Exception('Unable to read source role permissions.');
                }
                mysqli_stmt_bind_param($perm_stmt, "i", $source_role_id);
                mysqli_stmt_execute($perm_stmt);
                $perm_res = mysqli_stmt_get_result($perm_stmt);
                while ($perm_res && ($perm_row = mysqli_fetch_assoc($perm_res))) {
                    $permission_id = (int)($perm_row['permission_id'] ?? 0);
                    if ($permission_id <= 0) {
                        continue;
                    }
                    if ($is_partner_owner_admin && !isset($partner_permission_ids_allowed[$permission_id])) {
                        continue;
                    }
                    $source_perm_ids[$permission_id] = true;
                }
                mysqli_stmt_close($perm_stmt);

                $copied_count = 0;
                if (!empty($source_perm_ids)) {
                    $insert_perm_stmt = mysqli_prepare($conn, "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    if (!$insert_perm_stmt) {
                        throw new Exception('Unable to prepare permission copy query.');
                    }
                    foreach (array_keys($source_perm_ids) as $permission_id) {
                        mysqli_stmt_bind_param($insert_perm_stmt, "ii", $new_role_id, $permission_id);
                        if (!mysqli_stmt_execute($insert_perm_stmt)) {
                            throw new Exception('Failed to copy permissions.');
                        }
                        $copied_count++;
                    }
                    mysqli_stmt_close($insert_perm_stmt);
                }

                mysqli_commit($conn);
                $_SESSION['success'] = 'Role cloned successfully with ' . $copied_count . ' permissions copied.';
                logRBACAction($conn, $current_user_id, 'ROLE_CLONED', 'roles', "Cloned role {$source_role_id} to {$new_role_id} ({$stored_clone_name})");
                header('Location: rbac_management.php?tab=permissions&role_id=' . $new_role_id);
                exit();
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = 'Failed to clone role safely.';
                header('Location: rbac_management.php?tab=permissions&role_id=' . $source_role_id);
                exit();
            }
        }
        
        // Assign user to role
        if ($action === 'assign_user_role') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $role_id = intval($_POST['role_id'] ?? 0);
            
            if ($user_id <= 0 || $role_id <= 0) {
                $_SESSION['error'] = 'Invalid user or role selected';
            } elseif ($is_partner_owner_admin && !rbacRoleBelongsToPartner($conn, $role_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'Selected role is not part of your partner scope.';
            } elseif ($is_partner_owner_admin && !rbacUserBelongsToPartner($conn, $user_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'Selected user is not part of your partner team.';
            } else {
                $target_role = getRoleById($conn, $role_id);
                if (!$target_role || !(int)($target_role['is_active'] ?? 0)) {
                    $_SESSION['error'] = 'Selected role is invalid or inactive.';
                } elseif (($target_role['name'] ?? '') === 'super_admin' && !$is_super_admin_user) {
                    $_SESSION['error'] = 'Only Super Administrator can assign Super Administrator role.';
                } else {
                    $check_user_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ?");
                    mysqli_stmt_bind_param($check_user_stmt, "i", $user_id);
                    mysqli_stmt_execute($check_user_stmt);
                    mysqli_stmt_store_result($check_user_stmt);
                    $user_exists = mysqli_stmt_num_rows($check_user_stmt) > 0;
                    mysqli_stmt_close($check_user_stmt);

                    if (!$user_exists) {
                        $_SESSION['error'] = 'Selected user no longer exists.';
                    } else {
                        $update_query = "UPDATE users SET role_id = ? WHERE id = ?";
                        $update_stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($update_stmt, "ii", $role_id, $user_id);
                        
                        if (mysqli_stmt_execute($update_stmt)) {
                            $_SESSION['success'] = "User role assigned successfully";
                            logRBACAction($conn, $current_user_id, 'USER_ROLE_ASSIGNED', 'users', "Assigned role {$target_role['name']} to user ID $user_id");
                        } else {
                            $_SESSION['error'] = 'Failed to assign role: ' . mysqli_error($conn);
                        }
                        mysqli_stmt_close($update_stmt);
                    }
                }
            }
            header("Location: rbac_management.php?tab=users");
            exit();
        }

        // Partner owner: create sub-user under this tenant scope
        if ($action === 'create_partner_user') {
            if (!$is_partner_owner_admin) {
                $_SESSION['error'] = 'Only the partner owner can create sub-users.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            if (!rbacTableExists($conn, 'partner_user_links')) {
                $_SESSION['error'] = 'Partner user ownership schema is missing. Run tenant scope migration first.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }

            $full_name = trim((string)($_POST['sub_full_name'] ?? ''));
            $email_raw = trim((string)($_POST['sub_email'] ?? ''));
            $phone = trim((string)($_POST['sub_phone'] ?? ''));
            $password_plain = (string)($_POST['sub_password'] ?? '');
            $sub_user_type = strtolower(trim((string)($_POST['sub_user_type'] ?? 'employee')));
            $sub_role_id = (int)($_POST['sub_role_id'] ?? 0);

            if (!in_array($sub_user_type, ['admin', 'employee'], true)) {
                $sub_user_type = 'employee';
            }
            if ($full_name === '' || strlen($full_name) < 3) {
                $_SESSION['error'] = 'Sub-user full name must be at least 3 characters.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            $email_suggestion = null;
            if (!function_exists('validateEmail') || !validateEmail($email_raw, $email_suggestion)) {
                $_SESSION['error'] = $email_suggestion ? ('Invalid email. Did you mean ' . $email_suggestion . '?') : 'Invalid email address.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            if (!function_exists('validatePasswordStrength')) {
                $_SESSION['error'] = 'Password validation helper is unavailable.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            $password_errors = [];
            if (!validatePasswordStrength($password_plain, $password_errors)) {
                $_SESSION['error'] = 'Password is weak: ' . implode(' ', $password_errors);
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            if ($phone !== '' && function_exists('validatePhoneNumber') && !validatePhoneNumber($phone)) {
                $_SESSION['error'] = 'Invalid phone number format.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            if ($sub_role_id <= 0 || !rbacRoleBelongsToPartner($conn, $sub_role_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'Please select a valid partner role.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }

            $email = function_exists('sanitizeEmail') ? sanitizeEmail($email_raw) : strtolower($email_raw);
            $phone_clean = $phone !== '' && function_exists('sanitizePhone') ? sanitizePhone($phone) : $phone;
            $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);

            $exists_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($exists_stmt, "s", $email);
            mysqli_stmt_execute($exists_stmt);
            mysqli_stmt_store_result($exists_stmt);
            $email_exists = mysqli_stmt_num_rows($exists_stmt) > 0;
            mysqli_stmt_close($exists_stmt);
            if ($email_exists) {
                $_SESSION['error'] = 'Email is already registered.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }

            mysqli_begin_transaction($conn);
            try {
                $create_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO users (email, password, full_name, phone, user_type, role_id, account_type, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'individual', 1, NOW())"
                );
                if (!$create_stmt) {
                    throw new Exception('Unable to prepare sub-user creation.');
                }
                mysqli_stmt_bind_param($create_stmt, "sssssi", $email, $password_hash, $full_name, $phone_clean, $sub_user_type, $sub_role_id);
                if (!mysqli_stmt_execute($create_stmt)) {
                    throw new Exception('Failed to create sub-user account.');
                }
                $new_user_id = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($create_stmt);

                $link_stmt = mysqli_prepare(
                    $conn,
                    "INSERT INTO partner_user_links (owner_user_id, managed_user_id, created_at)
                     VALUES (?, ?, NOW())"
                );
                if (!$link_stmt) {
                    throw new Exception('Unable to prepare partner ownership link.');
                }
                mysqli_stmt_bind_param($link_stmt, "ii", $partner_scope_owner_id, $new_user_id);
                if (!mysqli_stmt_execute($link_stmt)) {
                    throw new Exception('Failed to link sub-user to partner owner.');
                }
                mysqli_stmt_close($link_stmt);

                mysqli_commit($conn);
                $_SESSION['success'] = 'Sub-user created and linked successfully.';
                logRBACAction($conn, $current_user_id, 'PARTNER_SUBUSER_CREATED', 'users', "Created partner sub-user ID {$new_user_id}");
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = 'Sub-user creation failed.';
            }

            header("Location: rbac_management.php?tab=users");
            exit();
        }

        if ($action === 'toggle_partner_user_status') {
            if (!$is_partner_owner_admin) {
                $_SESSION['error'] = 'Only the partner owner can update sub-user status.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            $target_user_id = (int)($_POST['target_user_id'] ?? 0);
            $target_active = (int)($_POST['target_active'] ?? 0) === 1 ? 1 : 0;
            if ($target_user_id <= 0 || !rbacUserBelongsToPartner($conn, $target_user_id, $partner_scope_owner_id)) {
                $_SESSION['error'] = 'Selected user is not in your partner scope.';
                header("Location: rbac_management.php?tab=users");
                exit();
            }
            $status_stmt = mysqli_prepare($conn, "UPDATE users SET is_active = ? WHERE id = ?");
            if ($status_stmt) {
                mysqli_stmt_bind_param($status_stmt, "ii", $target_active, $target_user_id);
                if (mysqli_stmt_execute($status_stmt)) {
                    $_SESSION['success'] = $target_active ? 'Sub-user activated.' : 'Sub-user deactivated.';
                    logRBACAction($conn, $current_user_id, 'PARTNER_SUBUSER_STATUS', 'users', "Set user {$target_user_id} active={$target_active}");
                } else {
                    $_SESSION['error'] = 'Failed to update sub-user status.';
                }
                mysqli_stmt_close($status_stmt);
            } else {
                $_SESSION['error'] = 'Failed to prepare status update.';
            }
            header("Location: rbac_management.php?tab=users");
            exit();
        }
    }
}

// Tenant-aware data loading
$partner_role_schema_notice = null;
$roles = [];
$permissions_by_module = [];
$admin_users = [];
$selected_role = null;
$selected_role_permissions = [];
$all_permissions = [];
$selected_role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;

if ($is_partner_owner_admin) {
    if (!$partner_roles_owner_column_exists) {
        $partner_role_schema_notice = 'RBAC role ownership schema is missing (`roles.owner_user_id`). Run Tenant Scope Migration.';
    } elseif (!$partner_user_links_table_exists) {
        $partner_role_schema_notice = 'Partner user ownership schema is missing (`partner_user_links`). Run Tenant Scope Migration.';
    }

    if ($partner_roles_owner_column_exists) {
        $roles_query = "SELECT * FROM roles WHERE is_active = 1 AND owner_user_id = ? ORDER BY name ASC";
        $roles_stmt = mysqli_prepare($conn, $roles_query);
        if ($roles_stmt) {
            mysqli_stmt_bind_param($roles_stmt, "i", $partner_scope_owner_id);
            mysqli_stmt_execute($roles_stmt);
            $roles_result = mysqli_stmt_get_result($roles_stmt);
            while ($roles_result && ($role_row = mysqli_fetch_assoc($roles_result))) {
                $roles[] = $role_row;
            }
            mysqli_stmt_close($roles_stmt);
        }
    }

    $permissions_by_module_all = getPermissionsByModule($conn);
    foreach ($permissions_by_module_all as $module => $module_perms) {
        if (!in_array($module, $partner_permission_modules, true)) {
            continue;
        }
        $permissions_by_module[$module] = $module_perms;
        foreach ($module_perms as $perm) {
            $all_permissions[] = $perm;
        }
    }

    if ($selected_role_id <= 0 || !rbacRoleBelongsToPartner($conn, $selected_role_id, $partner_scope_owner_id)) {
        $selected_role_id = (int)($roles[0]['id'] ?? 0);
    }
    if ($selected_role_id > 0 && rbacRoleBelongsToPartner($conn, $selected_role_id, $partner_scope_owner_id)) {
        $selected_role = getRoleById($conn, $selected_role_id);
        $selected_role_permissions = $selected_role ? getRolePermissions($conn, $selected_role_id) : [];
    }

    if ($partner_user_links_table_exists) {
        $admin_query = "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, r.name as role_name, r.id as role_id,
                               CASE WHEN u.id = ? THEN 1 ELSE 0 END AS is_owner
                        FROM users u
                        LEFT JOIN roles r ON u.role_id = r.id
                        LEFT JOIN partner_user_links pul ON pul.managed_user_id = u.id AND pul.owner_user_id = ?
                        WHERE u.id = ? OR pul.owner_user_id = ?
                        ORDER BY is_owner DESC, u.full_name ASC";
        $admin_stmt = mysqli_prepare($conn, $admin_query);
        if ($admin_stmt) {
            mysqli_stmt_bind_param($admin_stmt, "iiii", $partner_scope_owner_id, $partner_scope_owner_id, $partner_scope_owner_id, $partner_scope_owner_id);
            mysqli_stmt_execute($admin_stmt);
            $admin_result = mysqli_stmt_get_result($admin_stmt);
            while ($admin_result && ($row = mysqli_fetch_assoc($admin_result))) {
                $admin_users[] = $row;
            }
            mysqli_stmt_close($admin_stmt);
        }
    } else {
        $owner_stmt = mysqli_prepare(
            $conn,
            "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, r.name as role_name, r.id as role_id, 1 AS is_owner
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?"
        );
        if ($owner_stmt) {
            mysqli_stmt_bind_param($owner_stmt, "i", $partner_scope_owner_id);
            mysqli_stmt_execute($owner_stmt);
            $owner_result = mysqli_stmt_get_result($owner_stmt);
            while ($owner_result && ($row = mysqli_fetch_assoc($owner_result))) {
                $admin_users[] = $row;
            }
            mysqli_stmt_close($owner_stmt);
        }
    }
} elseif ($is_partner_scoped_admin) {
    $partner_role = $current_user_id > 0 ? getUserRole($conn, $current_user_id) : null;
    $roles = $partner_role ? [$partner_role] : [];

    $permissions_by_module = [];
    $selected_role_id = (int)($roles[0]['id'] ?? 0);
    $selected_role = $selected_role_id > 0 ? getRoleById($conn, $selected_role_id) : null;
    $selected_role_permissions = $selected_role ? getRolePermissions($conn, $selected_role_id) : [];

    $admin_query = "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, r.name as role_name, r.id as role_id, 0 AS is_owner
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.id = ?";
    $admin_stmt = mysqli_prepare($conn, $admin_query);
    if ($admin_stmt) {
        mysqli_stmt_bind_param($admin_stmt, "i", $current_user_id);
        mysqli_stmt_execute($admin_stmt);
        $admin_result = mysqli_stmt_get_result($admin_stmt);
        while ($admin_result && ($row = mysqli_fetch_assoc($admin_result))) {
            $admin_users[] = $row;
        }
        mysqli_stmt_close($admin_stmt);
    }

    foreach ($selected_role_permissions as $module_perms) {
        foreach ((array)$module_perms as $perm) {
            $all_permissions[] = $perm;
        }
    }
} else {
    $roles = getAllRoles($conn);
    $permissions_by_module = getPermissionsByModule($conn);
    $all_permissions = getAllPermissions($conn);
    if ($selected_role_id <= 0) {
        $selected_role_id = (count($roles) > 0) ? (int)$roles[0]['id'] : 0;
    }
    $selected_role = $selected_role_id > 0 ? getRoleById($conn, $selected_role_id) : null;
    $selected_role_permissions = $selected_role ? getRolePermissions($conn, $selected_role_id) : [];

    $admin_query = "SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, r.name as role_name, r.id as role_id, 0 AS is_owner
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.user_type = 'admin' OR u.user_type = 'employee'
                    ORDER BY u.full_name";
    $admin_stmt = mysqli_prepare($conn, $admin_query);
    if ($admin_stmt) {
        mysqli_stmt_execute($admin_stmt);
        $admin_result = mysqli_stmt_get_result($admin_stmt);
        while ($admin_result && ($row = mysqli_fetch_assoc($admin_result))) {
            $admin_users[] = $row;
        }
        mysqli_stmt_close($admin_stmt);
    }
}

$role_perm_names = [];
if ($selected_role) {
    foreach ($selected_role_permissions as $module => $perms) {
        foreach ($perms as $perm) {
            $role_perm_names[] = $perm['name'];
        }
    }
}
$assignable_users = $admin_users;
if ($is_partner_owner_admin) {
    $assignable_users = array_values(array_filter($admin_users, function ($u) use ($partner_scope_owner_id) {
        return (int)($u['id'] ?? 0) !== (int)$partner_scope_owner_id;
    }));
}
$role_count = count($roles);
$permission_count = count($all_permissions);
$admin_user_count = count($admin_users);
$unassigned_user_count = 0;
foreach ($admin_users as $u) {
    if (empty($u['role_id'])) $unassigned_user_count++;
}
$selected_role_permission_count = count($role_perm_names);
$flash_success = $_SESSION['success'] ?? null;
$flash_error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RBAC Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../font_awesome/css/all.css">
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Dark Mode Styles */
        :root {
            --bg-color-dark: #1a1a1a;
            --text-color-dark: #e0e0e0;
            --card-bg-dark: #2d2d2d;
            --border-color-dark: #404040;
        }

        body.dark-mode {
            background-color: var(--bg-color-dark) !important;
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .admin-content,
        body.dark-mode .admin-container {
            background-color: var(--bg-color-dark) !important;
        }

        body.dark-mode .admin-topbar,
        body.dark-mode .stat-card,
        body.dark-mode .card,
        body.dark-mode .card-header,
        body.dark-mode .card-body,
        body.dark-mode .admin-table,
        body.dark-mode .admin-table th,
        body.dark-mode .admin-table td,
        body.dark-mode .modal-content,
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer,
        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body.dark-mode .role-card,
        body.dark-mode .permissions-matrix,
        body.dark-mode .permission-item,
        body.dark-mode .create-role-form,
        body.dark-mode .assign-user-form {
            background-color: var(--card-bg-dark) !important;
            color: var(--text-color-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, 
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode strong, body.dark-mode b,
        body.dark-mode .admin-topbar h1,
        body.dark-mode label,
        body.dark-mode .modal-title,
        body.dark-mode .nav-link,
        body.dark-mode .permission-name {
            color: var(--text-color-dark) !important;
        }

        body.dark-mode .text-muted,
        body.dark-mode .small,
        body.dark-mode .permission-desc {
            color: #b0b0b0 !important;
        }
        
        body.dark-mode .table-responsive {
             background-color: var(--card-bg-dark) !important;
             border-color: var(--border-color-dark) !important;
        }
        
        body.dark-mode .nav-tabs .nav-link.active {
            background-color: var(--card-bg-dark);
            border-color: var(--border-color-dark) var(--border-color-dark) var(--card-bg-dark);
            color: #c62828 !important;
        }
        
        body.dark-mode .permission-group-title {
            background-color: #3d3d3d !important;
            color: var(--text-color-dark) !important;
        }
        
        body.dark-mode .role-card:hover {
            border-color: #c62828 !important;
            background-color: #3d3d3d !important;
        }
        
        body.dark-mode .role-card.active {
            background-color: #3a2a2a !important;
        }

        .theme-toggler {
            background: none;
            border: none;
            color: #666;
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 15px;
            padding: 5px;
            transition: color 0.3s;
        }

        body.dark-mode .theme-toggler {
            color: #ffc107;
        }
        
        
        .role-selector {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .role-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .role-card:hover {
            border-color: #c62828;
            box-shadow: 0 4px 12px rgba(198, 40, 40, 0.1);
            transform: translateY(-2px);
        }
        
        .role-card.active {
            border-color: #c62828;
            background-color: #fff5f5;
            box-shadow: 0 6px 16px rgba(198, 40, 40, 0.2);
        }
        
        .role-card.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #c62828 0%, #b71c1c 100%);
        }
        
        .role-card h3 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 16px;
        }
        
        .role-card p {
            margin: 0;
            color: #999;
            font-size: 13px;
        }
        
        .permissions-matrix {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }
        
        .permission-group {
            margin-bottom: 30px;
        }
        
        .permission-group-title {
            font-weight: 600;
            color: #333;
            padding: 12px;
            background-color: #f5f5f5;
            border-radius: 4px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .permission-group-title:hover {
            background-color: #eeeeee;
        }
        
        .permission-group-title input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        .permission-group-title label {
            cursor: pointer;
            flex: 1;
            margin: 0;
        }
        
        .module-count-badge {
            background: #667eea;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .permissions-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 12px;
            margin-left: 20px;
        }
        
        .permission-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px;
            border-radius: 4px;
            background: white;
            border: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        
        .permission-item:hover {
            background-color: #fafafa;
        }
        
        .permission-item input[type="checkbox"] {
            margin-top: 3px;
            cursor: pointer;
        }
        
        .permission-item label {
            cursor: pointer;
            flex: 1;
            margin: 0;
        }
        
        .permission-name {
            font-weight: 500;
            color: #333;
        }
        
        .permission-desc {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }
        
        .save-button {
            background-color: #c62828;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .save-button:hover {
            background-color: #b71c1c;
        }
        
        .save-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .users-table th {
            background-color: #f5f5f5;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .users-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .users-table tr:hover {
            background-color: #fafafa;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            color: #2e7d32;
        }
        
        .alert-error {
            background-color: #ffebee;
            border-left: 4px solid #f44336;
            color: #c62828;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .create-role-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            max-width: 500px;
            margin-bottom: 30px;
        }
        
        .assign-user-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            max-width: 500px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .assign-user-form .form-group {
            margin-bottom: 0;
        }

        .rbac-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .rbac-stat {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            padding: 14px;
        }

        .rbac-stat-label {
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .rbac-stat-value {
            color: #111827;
            font-weight: 700;
            font-size: 24px;
            line-height: 1;
        }

        .rbac-tools {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 14px;
        }

        .rbac-tools .form-control {
            max-width: 360px;
        }

        .permission-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .tier-badge {
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
        }

        body.dark-mode .rbac-stat {
            background-color: var(--card-bg-dark) !important;
            border-color: var(--border-color-dark) !important;
        }

        body.dark-mode .rbac-stat-label {
            color: #c0c0c0 !important;
        }

        body.dark-mode .rbac-stat-value {
            color: var(--text-color-dark) !important;
        }

        @media (max-width: 768px) {
            .permissions-list {
                grid-template-columns: 1fr;
                margin-left: 0;
            }

            .role-selector {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="rbac-theme">
    <div class="page-loader">
        <div class="spinner"></div>
    </div>
    <div class="admin-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="admin-content">
            <div class="admin-topbar">
                <div class="topbar-content">
                    <button class="sidebar-toggler" id="sidebarToggler"><i class="fas fa-bars"></i></button>
                    <h1>RBAC Management</h1>
                    <button class="theme-toggler" id="themeToggler" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="topbar-right">
                        <div class="date-display" id="currentDate"></div>
                        <div class="admin-profile">
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin_info['full_name']); ?>" alt="Profile" class="profile-img">
                            <div class="profile-info">
                                <div class="profile-name"><?php echo htmlspecialchars($admin_info['full_name']); ?></div>
                                <div class="profile-role"><?php echo rbacRoleDisplayName($admin_info['role_name'] ?? 'admin', $is_partner_scoped_admin ? $partner_scope_owner_id : 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="admin-main">
                <div class="section-header">
                    <h2>Role-Based Access Control</h2>
                    <p class="text-muted">Manage roles, permissions, and user access levels</p>
                </div>
                <?php if (!empty($partner_role_schema_notice)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($partner_role_schema_notice); ?>
                    </div>
                <?php endif; ?>

                <div class="rbac-stats-grid">
                    <div class="rbac-stat">
                        <div class="rbac-stat-label">Active Roles</div>
                        <div class="rbac-stat-value"><?php echo number_format($role_count); ?></div>
                    </div>
                    <div class="rbac-stat">
                        <div class="rbac-stat-label">Permission Rules</div>
                        <div class="rbac-stat-value"><?php echo number_format($permission_count); ?></div>
                    </div>
                    <div class="rbac-stat">
                        <div class="rbac-stat-label">Managed Users</div>
                        <div class="rbac-stat-value"><?php echo number_format($admin_user_count); ?></div>
                    </div>
                    <div class="rbac-stat">
                        <div class="rbac-stat-label">Unassigned Users</div>
                        <div class="rbac-stat-value"><?php echo number_format($unassigned_user_count); ?></div>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-4" id="rbacTabs" role="tablist">
                    <?php if (in_array('permissions', $allowed_tabs, true)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'permissions' ? 'active' : ''; ?>" id="permissions-tab" href="?tab=permissions" role="tab"><i class="fas fa-shield-alt me-2"></i>Role Permissions</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'users' ? 'active' : ''; ?>" id="users-tab" href="?tab=users" role="tab"><i class="fas fa-users me-2"></i>User Roles</a>
                    </li>
                    <?php if (in_array('create', $allowed_tabs, true)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'create' ? 'active' : ''; ?>" id="create-tab" href="?tab=create" role="tab"><i class="fas fa-plus-circle me-2"></i>Create Role</a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <!-- PERMISSIONS TAB -->
                <div class="tab-content">
                    <div class="tab-pane fade <?php echo $active_tab === 'permissions' ? 'show active' : ''; ?>" id="permissions" role="tabpanel">
                    <div class="card mb-4"><div class="card-body">
                    <h2>Manage Role Permissions</h2>
                    <p class="text-muted mb-4">Select a role to configure its permissions.</p>
                    <?php if ($is_partner_owner_admin): ?>
                        <div class="alert alert-info">
                            Partner roles are scoped to your own store operations modules only.
                        </div>
                    <?php endif; ?>
                    <div class="rbac-tools">
                        <input type="text" id="roleSearch" class="form-control" placeholder="Search role by name or description...">
                    </div>
                    
                    <div class="role-selector">
                        <?php 
                        // Sort roles alphabetically by display name
                        usort($roles, function($a, $b) use ($is_partner_owner_admin, $partner_scope_owner_id) {
                            $name_a = rbacRoleDisplayName($a['name'] ?? '', $is_partner_owner_admin ? $partner_scope_owner_id : 0);
                            $name_b = rbacRoleDisplayName($b['name'] ?? '', $is_partner_owner_admin ? $partner_scope_owner_id : 0);
                            return strcasecmp($name_a, $name_b);
                        });
                        
                        foreach ($roles as $role): 
                        ?>
                            <div class="role-card <?php echo $role['id'] === $selected_role_id ? 'active' : ''; ?>" 
                                 data-role-search="<?php echo htmlspecialchars(strtolower(rbacRoleDisplayName($role['name'] ?? '', $is_partner_owner_admin ? $partner_scope_owner_id : 0) . ' ' . ($role['description'] ?? ''))); ?>"
                                 onclick="selectRole(<?php echo $role['id']; ?>)" style="cursor: pointer;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <h3 style="margin: 0; flex: 1;"><?php echo rbacRoleDisplayName($role['name'], $is_partner_owner_admin ? $partner_scope_owner_id : 0); ?></h3>
                                    <span style="font-size: 15px; color: #6b7280;"><i class="fas fa-shield-alt"></i></span>
                                </div>
                                <p style="margin: 0 0 8px 0;"><?php echo htmlspecialchars($role['description'] ?? ''); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($selected_role): ?>
                        <!-- Role Summary -->
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h3 style="margin: 0 0 8px 0; font-size: 20px;">
                                        <i class="fas fa-shield-alt"></i> <?php echo rbacRoleDisplayName($selected_role['name'], $is_partner_owner_admin ? $partner_scope_owner_id : 0); ?>
                                    </h3>
                                    <p style="margin: 0; opacity: 0.9;"><?php echo htmlspecialchars($selected_role['description']); ?></p>
                                </div>
                            </div>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                                <div style="display: flex; gap: 20px;">
                                    <div>
                                        <i class="fas fa-key"></i> 
                                        <strong><?php echo count($role_perm_names); ?></strong> permissions assigned
                                    </div>
                                    <div>
                                        <i class="fas fa-calendar-alt"></i> 
                                        Created: <?php echo !empty($selected_role['created_at']) ? date('M d, Y', strtotime($selected_role['created_at'])) : 'N/A'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="mb-3"><i class="fas fa-clone me-2"></i>Clone Selected Role</h5>
                                <p class="text-muted mb-3">Create a new role by copying this role's permissions, then adjust as needed.</p>
                                <form method="POST" class="row g-2 align-items-end">
                                    <input type="hidden" name="action" value="clone_role">
                                    <input type="hidden" name="source_role_id" value="<?php echo (int)$selected_role['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="return_tab" value="permissions">

                                    <div class="col-md-5">
                                        <label class="form-label mb-1">New Role Name *</label>
                                        <input type="text" name="clone_role_name" class="form-control" required
                                               maxlength="50" pattern="^[a-zA-Z0-9 _-]+$"
                                               placeholder="e.g., senior_dispatcher">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label mb-1">Description (optional)</label>
                                        <input type="text" name="clone_role_description" class="form-control" maxlength="1000"
                                               placeholder="Leave blank to reuse source description">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-outline-primary w-100">
                                            <i class="fas fa-copy"></i> Clone
                                        </button>
                                    </div>
                                </form>
                                <small class="text-muted d-block mt-2">Role names are normalized automatically to lowercase with underscores.</small>
                            </div>
                        </div>
                        
                        <form method="POST" class="permissions-form" id="permissionsForm">
                            <input type="hidden" name="action" value="update_permissions">
                            <input type="hidden" name="role_id" value="<?php echo $selected_role['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="return_tab" value="permissions">

                            <div class="rbac-tools">
                                <input type="text" id="permissionSearch" class="form-control" placeholder="Search permission name or description...">
                            </div>

                            <div class="permission-actions">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPermissions()"><i class="fas fa-check-double"></i> Select All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPermissions()"><i class="fas fa-eraser"></i> Clear All</button>
                            </div>
                             
                            <div class="permissions-matrix">
                                <?php foreach ($permissions_by_module as $module => $module_perms): ?>
                                    <div class="permission-group">
                                        <div class="permission-group-title">
                                            <input type="checkbox" id="check_<?php echo $module; ?>" 
                                                   onchange="toggleModulePermissions('<?php echo $module; ?>')">
                                            <label for="check_<?php echo $module; ?>">
                                                <i class="fas fa-folder-open"></i>
                                                <?php echo getModuleDisplayName($module); ?>
                                            </label>
                                            <span class="module-count-badge">
                                                <span id="count_<?php echo $module; ?>">0</span> / <?php echo count($module_perms); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="permissions-list" data-module="<?php echo $module; ?>">
                                            <?php foreach ($module_perms as $perm): ?>
                                                <div class="permission-item">
                                                    <input type="checkbox" name="permissions[]" 
                                                           value="<?php echo $perm['id']; ?>"
                                                           id="perm_<?php echo $perm['id']; ?>"
                                                           data-module="<?php echo $module; ?>"
                                                           class="module-<?php echo $module; ?>"
                                                           <?php echo in_array($perm['name'], $role_perm_names) ? 'checked' : ''; ?>>
                                                    <label for="perm_<?php echo $perm['id']; ?>">
                                                        <div class="permission-name"><?php echo str_replace('.', ' - ', $perm['name']); ?></div>
                                                        <?php if ($perm['description']): ?>
                                                            <div class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></div>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="display: flex; gap: 15px; align-items: center; margin-top: 20px;">
                                <button type="button" class="btn btn-primary" onclick="confirmSavePermissions()">
                                    <i class="fas fa-save"></i> Save Permissions
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                    <i class="fas fa-undo"></i> Reset Changes
                                </button>
                                <div style="color: #999; font-size: 13px; margin-left: auto;">
                                    <i class="fas fa-info-circle"></i> Changes apply immediately after saving
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #999;">
                            <i class="fas fa-exclamation-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>No role selected. Please select a role from above to manage its permissions.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
                </div>
                
                <!-- USERS TAB -->
                <div class="tab-pane fade <?php echo $active_tab === 'users' ? 'show active' : ''; ?>" id="users" role="tabpanel">
                    <div class="card mb-4"><div class="card-body">
                    <h3 class="mb-4">
                        <?php
                        if ($is_partner_owner_admin) {
                            echo 'Manage Partner Team Roles';
                        } elseif ($is_partner_scoped_admin) {
                            echo 'My Role Access';
                        } else {
                            echo 'Assign Roles to Users';
                        }
                        ?>
                    </h3>

                    <?php if ($is_partner_scoped_admin && !$is_partner_owner_admin): ?>
                        <div class="alert alert-info">
                            This sub-user account can view role details. RBAC updates are handled by your partner owner account.
                        </div>
                    <?php elseif ($is_partner_owner_admin): ?>
                        <div class="alert alert-info">
                            You can manage your own partner roles and linked team users here without affecting other stores.
                        </div>
                        <?php if (!$partner_scope_schema_ready): ?>
                            <div class="alert alert-error">
                                Partner RBAC ownership schema is incomplete. Run Tenant Scope Migration before creating or assigning users.
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mb-4">
                                <div class="col-lg-6">
                                    <form method="POST" class="create-role-form" style="max-width: none;">
                                        <input type="hidden" name="action" value="create_partner_user">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="return_tab" value="users">
                                        <h5 class="mb-3"><i class="fas fa-user-plus"></i> Create Sub-User</h5>

                                        <div class="form-group">
                                            <label for="sub_full_name">Full Name *</label>
                                            <input type="text" id="sub_full_name" name="sub_full_name" class="form-control" minlength="3" maxlength="100" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="sub_email">Email *</label>
                                            <input type="email" id="sub_email" name="sub_email" class="form-control" maxlength="255" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="sub_phone">Phone</label>
                                            <input type="text" id="sub_phone" name="sub_phone" class="form-control" maxlength="20" placeholder="09xxxxxxxxx">
                                        </div>
                                        <div class="form-group">
                                            <label for="sub_password">Temporary Password *</label>
                                            <input type="password" id="sub_password" name="sub_password" class="form-control" minlength="8" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="sub_user_type">User Type *</label>
                                            <select id="sub_user_type" name="sub_user_type" class="form-select" required>
                                                <option value="employee" selected>Employee</option>
                                                <option value="admin">Admin</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="sub_role_id">Initial Role *</label>
                                            <select id="sub_role_id" name="sub_role_id" class="form-select" required>
                                                <option value="">-- Choose Partner Role --</option>
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?php echo (int)$role['id']; ?>">
                                                        <?php echo htmlspecialchars(rbacRoleDisplayName($role['name'], $partner_scope_owner_id)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (empty($roles)): ?>
                                                <small style="color: #c62828;">Create a partner role first in the Create Role tab.</small>
                                            <?php endif; ?>
                                        </div>
                                        <button type="submit" class="btn btn-success mt-2" <?php echo empty($roles) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-plus"></i> Create Sub-User
                                        </button>
                                    </form>
                                </div>
                                <div class="col-lg-6">
                                    <?php if (empty($roles)): ?>
                                        <div class="alert alert-info mb-0">
                                            Create at least one partner role first before assigning roles to team members.
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="assign-user-form assign-role-form" style="max-width: none;">
                                            <input type="hidden" name="action" value="assign_user_role">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="return_tab" value="users">
                                            <h5 class="mb-3" style="grid-column: 1 / -1;"><i class="fas fa-user-check"></i> Assign Role to Sub-User</h5>

                                            <div class="form-group">
                                                <label for="user_id">Select Sub-User</label>
                                                <select name="user_id" id="user_id" class="form-select" required>
                                                    <option value="">-- Choose Sub-User --</option>
                                                    <?php foreach ($assignable_users as $user): ?>
                                                        <option value="<?php echo (int)$user['id']; ?>">
                                                            <?php echo htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['email']) . ')'; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <?php if (empty($assignable_users)): ?>
                                                    <small style="color: #c62828;">Create a sub-user first.</small>
                                                <?php endif; ?>
                                            </div>

                                            <div class="form-group">
                                                <label for="role_id_assign">Select Partner Role</label>
                                                <select name="role_id" id="role_id_assign" class="form-select" required>
                                                    <option value="">-- Choose Role --</option>
                                                    <?php foreach ($roles as $role): ?>
                                                        <option value="<?php echo (int)$role['id']; ?>">
                                                            <?php echo htmlspecialchars(rbacRoleDisplayName($role['name'], $partner_scope_owner_id)); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <button type="submit" class="btn btn-primary mt-2" style="grid-column: 1/-1;" <?php echo empty($assignable_users) ? 'disabled' : ''; ?>>
                                                <i class="fas fa-save"></i> Assign Role
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="assign-user-form assign-role-form">
                            <form method="POST" style="grid-column: 1/-1;">
                                <input type="hidden" name="action" value="assign_user_role">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="return_tab" value="users">
                                
                                <div class="form-group">
                                    <label for="user_id">Select User</label>
                                    <select name="user_id" id="user_id" class="form-select" required>
                                        <option value="">-- Choose User --</option>
                                        <?php foreach ($admin_users as $user): ?>
                                            <option value="<?php echo (int)$user['id']; ?>">
                                                <?php echo htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['email']) . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="role_id_assign">Select Role</label>
                                    <select name="role_id" id="role_id_assign" class="form-select" required>
                                        <option value="">-- Choose Role --</option>
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?php echo (int)$role['id']; ?>">
                                                <?php echo htmlspecialchars(rbacRoleDisplayName($role['name'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <button type="submit" class="btn btn-primary mt-3" style="grid-column: 1/-1;">
                                    <i class="fas fa-user-check"></i> Assign Role
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <h4 class="mt-4 mb-3">
                        <?php
                        if ($is_partner_owner_admin) {
                            echo 'Partner Team Users';
                        } elseif ($is_partner_scoped_admin) {
                            echo 'Current Account Role';
                        } else {
                            echo 'Current User Roles';
                        }
                        ?>
                    </h4>
                    <div class="table-responsive">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>User Name</th>
                                <th>Email</th>
                                <th>Current Role</th>
                                <th>Type</th>
                                <?php if ($is_partner_owner_admin): ?>
                                    <th>Status</th>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($admin_users)): ?>
                                <tr>
                                    <td colspan="<?php echo $is_partner_owner_admin ? '6' : '4'; ?>" class="text-muted">No users available in this scope.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($admin_users as $user): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($user['full_name']); ?>
                                            <?php if ($is_partner_owner_admin && (int)($user['is_owner'] ?? 0) === 1): ?>
                                                <span class="badge bg-primary ms-2">Owner</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <?php if (!empty($user['role_name'])): ?>
                                                <span class="role-badge"><?php echo htmlspecialchars(rbacRoleDisplayName($user['role_name'], $is_partner_owner_admin ? $partner_scope_owner_id : 0)); ?></span>
                                            <?php else: ?>
                                                <span class="role-badge" style="background-color: #ffebee; color: #c62828;">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)($user['user_type'] ?? 'n/a'))); ?></td>
                                        <?php if ($is_partner_owner_admin): ?>
                                            <td>
                                                <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)($user['is_owner'] ?? 0) === 1): ?>
                                                    <span class="text-muted">Owner account</span>
                                                <?php else: ?>
                                                    <form method="POST" class="toggle-user-form" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_partner_user_status">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="return_tab" value="users">
                                                        <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id']; ?>">
                                                        <input type="hidden" name="target_active" value="<?php echo ((int)($user['is_active'] ?? 0) === 1) ? '0' : '1'; ?>">
                                                        <button type="submit" class="btn btn-sm <?php echo ((int)($user['is_active'] ?? 0) === 1) ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                            <?php echo ((int)($user['is_active'] ?? 0) === 1) ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div></div>
                </div>
                
                <!-- CREATE ROLE TAB -->
                <div class="tab-pane fade <?php echo $active_tab === 'create' ? 'show active' : ''; ?>" id="create" role="tabpanel">
                    <div class="card mb-4"><div class="card-body">
                    <h2><?php echo $is_partner_owner_admin ? 'Create New Partner Role' : 'Create New Role'; ?></h2>
                    
                    <?php if ($is_partner_owner_admin && !$partner_roles_owner_column_exists): ?>
                        <div class="alert alert-error">
                            Cannot create partner roles until <code>roles.owner_user_id</code> exists. Run Tenant Scope Migration first.
                        </div>
                    <?php else: ?>
                    <form method="POST" class="create-role-form">
                        <input type="hidden" name="action" value="create_role">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="return_tab" value="create">
                        
                        <div class="form-group">
                            <label for="role_name">Role Name *</label>
                            <input type="text" name="role_name" id="role_name" class="form-control" 
                                   placeholder="e.g., content_manager, warehouse_supervisor" maxlength="50" pattern="^[a-zA-Z0-9 _-]+$" required>
                            <small style="color: #999;">Letters/numbers/spaces/hyphens are accepted and saved as lowercase_with_underscores</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="role_description">Description *</label>
                            <textarea name="role_description" id="role_description" class="form-control" 
                                      placeholder="Explain what this role can do and its responsibilities..." maxlength="1000" required></textarea>
                            <small style="color: #999;">Be specific about the role's purpose and scope</small>
                        </div>
                        
                        <button type="submit" class="btn btn-success mt-3">
                            <i class="fas fa-plus"></i> Create Role
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    
    <script src="../js/jquery-3.7.1.min.js"></script>
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Theme Toggler
        const themeToggler = document.getElementById('themeToggler');
        const body = document.body;
        const icon = themeToggler.querySelector('i');

        // Check local storage
        if (localStorage.getItem('theme') === 'dark') {
            body.classList.add('dark-mode');
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        }

        themeToggler.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        });

        // Session Messages
        const flashSuccess = <?php echo json_encode($flash_success); ?>;
        const flashError = <?php echo json_encode($flash_error); ?>;
        if (flashSuccess) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: flashSuccess,
                timer: 2200,
                showConfirmButton: false
            });
        }
        if (flashError) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: flashError
            });
        }
        
        function selectRole(roleId) {
            window.location.href = '?tab=permissions&role_id=' + roleId;
        }
        
        function toggleModulePermissions(module) {
            const checkbox = document.getElementById('check_' + module);
            const moduleCheckboxes = document.querySelectorAll('.module-' + module);
            
            moduleCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            
            updateModuleCount(module);
        }
        
        function updateModuleCount(module) {
            const total = document.querySelectorAll('.module-' + module).length;
            const checked = document.querySelectorAll('.module-' + module + ':checked').length;
            const countSpan = document.getElementById('count_' + module);
            const moduleCheckbox = document.getElementById('check_' + module);
            
            countSpan.textContent = checked;
            
            // Update module checkbox state
            if (checked === 0) {
                moduleCheckbox.checked = false;
                moduleCheckbox.indeterminate = false;
            } else if (checked === total) {
                moduleCheckbox.checked = true;
                moduleCheckbox.indeterminate = false;
            } else {
                moduleCheckbox.checked = false;
                moduleCheckbox.indeterminate = true;
            }
            
            // Update badge color based on selection
            const badge = countSpan.parentElement;
            if (checked === total && total > 0) {
                badge.style.background = '#4caf50';
            } else if (checked > 0) {
                badge.style.background = '#ff9800';
            } else {
                badge.style.background = '#757575';
            }
        }
        
        // Initial count update
        document.querySelectorAll('[data-module]').forEach(group => {
            const module = group.getAttribute('data-module');
            updateModuleCount(module);
        });
        
        // Update counts when checkboxes change
        document.querySelectorAll('input[name="permissions[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateModuleCount(this.getAttribute('data-module'));
            });
        });

        function selectAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(cb => cb.checked = true);
            document.querySelectorAll('[data-module]').forEach(group => {
                updateModuleCount(group.getAttribute('data-module'));
            });
        }

        function clearAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(cb => cb.checked = false);
            document.querySelectorAll('[data-module]').forEach(group => {
                updateModuleCount(group.getAttribute('data-module'));
            });
        }

        const roleSearch = document.getElementById('roleSearch');
        if (roleSearch) {
            roleSearch.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('.role-card').forEach(card => {
                    const text = (card.getAttribute('data-role-search') || '').toLowerCase();
                    card.style.display = text.includes(q) ? '' : 'none';
                });
            });
        }

        const permissionSearch = document.getElementById('permissionSearch');
        if (permissionSearch) {
            permissionSearch.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('.permission-item').forEach(item => {
                    const text = item.innerText.toLowerCase();
                    item.style.display = text.includes(q) ? '' : 'none';
                });
            });
        }

        const assignRoleForm = document.querySelector('form.assign-role-form, .assign-role-form form');
        if (assignRoleForm) {
            assignRoleForm.addEventListener('submit', function (e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Assign this role?',
                    text: 'This will update the user access rights immediately.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Assign Role',
                    confirmButtonColor: '#0d6efd'
                }).then((result) => {
                    if (result.isConfirmed) this.submit();
                });
            });
        }

        document.querySelectorAll('.toggle-user-form').forEach((form) => {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Update account status?',
                    text: 'This will immediately change the sub-user access.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, continue'
                }).then((result) => {
                    if (result.isConfirmed) this.submit();
                });
            });
        });
        
        // Update date display
        function updateDate() {
            const now = new Date();
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', options);
        }
        updateDate();

        function confirmSavePermissions() {
            Swal.fire({
                title: 'Save Permissions?',
                text: "Are you sure you want to update permissions for this role?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, save it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('permissionsForm').submit();
                }
            })
        }
    </script>
</body>
</html>

