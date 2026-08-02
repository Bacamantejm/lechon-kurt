<?php
/**
 * RBAC Core Middleware
 * Handles role-based access control and permission checking
 * 
 * Usage:
 * 1. checkPermission($conn, $user_id, 'orders.view');  // Will die and show 403 if no permission
 * 2. hasPermission($conn, $user_id, 'orders.view');    // Returns true/false
 * 3. getUserRole($conn, $user_id);                     // Get user's role object
 * 4. getUserPermissions($conn, $user_id);              // Get array of permission names
 * 5. checkModuleAccess($conn, $user_id, 'orders');    // Check if user can access entire module
 */

// Get user's role information
function getUserRole($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return null;
    }

    static $role_cache = [];
    if (array_key_exists($user_id, $role_cache)) {
        return $role_cache[$user_id];
    }
    
    $query = "SELECT r.* FROM roles r 
              INNER JOIN users u ON u.role_id = r.id 
              WHERE u.id = ? AND r.is_active = 1";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in getUserRole: " . mysqli_error($conn));
        return null;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $role = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    $role_cache[$user_id] = $role ?: null;
    return $role_cache[$user_id];
}

// Get all permissions for a user
function getUserPermissions($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return [];
    }

    static $permission_cache = [];
    if (array_key_exists($user_id, $permission_cache)) {
        return $permission_cache[$user_id];
    }
    
    $query = "SELECT DISTINCT p.name FROM permissions p
              INNER JOIN role_permissions rp ON p.id = rp.permission_id
              INNER JOIN roles r ON rp.role_id = r.id
              INNER JOIN users u ON u.role_id = r.id
              WHERE u.id = ? AND r.is_active = 1";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in getUserPermissions: " . mysqli_error($conn));
        return [];
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $permissions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $permissions[] = $row['name'];
    }
    mysqli_stmt_close($stmt);
    
    $permission_cache[$user_id] = $permissions;
    return $permission_cache[$user_id];
}

if (!function_exists('rbacTableExists')) {
    function rbacTableExists($conn, $table_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        if ($table_name === '' || !$conn) {
            return false;
        }
        if (array_key_exists($table_name, $cache)) {
            return $cache[$table_name];
        }

        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safe_table}'");
        return $cache[$table_name] = ($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('rbacColumnExists')) {
    function rbacColumnExists($conn, $table_name, $column_name) {
        static $cache = [];
        $table_name = trim((string)$table_name);
        $column_name = trim((string)$column_name);
        $cache_key = $table_name . '.' . $column_name;

        if ($table_name === '' || $column_name === '' || !$conn) {
            return false;
        }
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }
        if (!rbacTableExists($conn, $table_name)) {
            return $cache[$cache_key] = false;
        }

        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        return $cache[$cache_key] = ($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('resolveTenantScopeOwnerIdForUser')) {
    /**
     * Resolve tenant scope owner for a user.
     * Returns null when no tenant scope should be applied.
     */
    function resolveTenantScopeOwnerIdForUser($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$conn) {
            return null;
        }

        static $cache = [];
        if (array_key_exists($user_id, $cache)) {
            return $cache[$user_id];
        }

        if (rbacTableExists($conn, 'users') && rbacTableExists($conn, 'franchise_applications')) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT u.id
                 FROM users u
                 WHERE u.id = ?
                   AND u.account_type = 'organization'
                   AND EXISTS (
                       SELECT 1
                       FROM franchise_applications fa
                       WHERE fa.user_id = u.id
                         AND fa.status = 'approved'
                   )
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if (!empty($row['id'])) {
                    return $cache[$user_id] = (int)$row['id'];
                }
            }
        }

        if (
            rbacTableExists($conn, 'partner_user_links')
            && rbacColumnExists($conn, 'partner_user_links', 'owner_user_id')
            && rbacColumnExists($conn, 'partner_user_links', 'managed_user_id')
            && rbacTableExists($conn, 'users')
            && rbacTableExists($conn, 'franchise_applications')
        ) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT pul.owner_user_id
                 FROM partner_user_links pul
                 INNER JOIN users owner_u ON owner_u.id = pul.owner_user_id
                 WHERE pul.managed_user_id = ?
                   AND owner_u.account_type = 'organization'
                   AND EXISTS (
                       SELECT 1
                       FROM franchise_applications fa
                       WHERE fa.user_id = pul.owner_user_id
                         AND fa.status = 'approved'
                   )
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if (!empty($row['owner_user_id'])) {
                    return $cache[$user_id] = (int)$row['owner_user_id'];
                }
            }
        }

        if (
            rbacColumnExists($conn, 'roles', 'owner_user_id')
            && rbacColumnExists($conn, 'users', 'role_id')
            && rbacTableExists($conn, 'users')
            && rbacTableExists($conn, 'franchise_applications')
        ) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT r.owner_user_id
                 FROM users managed_u
                 INNER JOIN roles r ON r.id = managed_u.role_id
                 INNER JOIN users owner_u ON owner_u.id = r.owner_user_id
                 WHERE managed_u.id = ?
                   AND r.owner_user_id IS NOT NULL
                   AND owner_u.account_type = 'organization'
                   AND EXISTS (
                       SELECT 1
                       FROM franchise_applications fa
                       WHERE fa.user_id = r.owner_user_id
                         AND fa.status = 'approved'
                   )
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                if (!empty($row['owner_user_id'])) {
                    return $cache[$user_id] = (int)$row['owner_user_id'];
                }
            }
        }

        return $cache[$user_id] = null;
    }
}

if (!function_exists('isTenantScopedSuperAdmin')) {
    function isTenantScopedSuperAdmin($conn, $user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0 || !$conn) {
            return false;
        }

        $role = getUserRole($conn, $user_id);
        if (!$role || ($role['name'] ?? '') !== 'super_admin') {
            return false;
        }

        return resolveTenantScopeOwnerIdForUser($conn, $user_id) !== null;
    }
}

// Check if user has a specific permission (returns boolean)
function hasPermission($conn, $user_id, $permission_name) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !is_string($permission_name) || $permission_name === '') {
        return false;
    }

    static $has_permission_cache = [];
    $cache_key = $user_id . '|' . $permission_name;
    if (array_key_exists($cache_key, $has_permission_cache)) {
        return $has_permission_cache[$cache_key];
    }
    
    // Super admin always has all permissions.
    $user_role = getUserRole($conn, $user_id);
    if ($user_role && $user_role['name'] === 'super_admin') {
        return $has_permission_cache[$cache_key] = true;
    }

    $permissions = getUserPermissions($conn, $user_id);
    $result = in_array($permission_name, $permissions, true);
    return $has_permission_cache[$cache_key] = $result;
}

// Check permission and die if not allowed (for endpoints/pages)
function checkPermission($conn, $user_id, $permission_name) {
    if (!hasPermission($conn, $user_id, $permission_name)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'message' => 'Access Denied: You do not have permission to access this resource.',
            'permission_required' => $permission_name
        ]));
    }
}

// Check if user can access a module (at least one permission in that module)
function hasModuleAccess($conn, $user_id, $module_name) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !is_string($module_name) || $module_name === '') {
        return false;
    }

    static $module_access_cache = [];
    $cache_key = $user_id . '|' . $module_name;
    if (array_key_exists($cache_key, $module_access_cache)) {
        return $module_access_cache[$cache_key];
    }
    
    // Super admin always has access.
    $user_role = getUserRole($conn, $user_id);
    if ($user_role && $user_role['name'] === 'super_admin') {
        return $module_access_cache[$cache_key] = true;
    }

    $permissions = getUserPermissions($conn, $user_id);
    foreach ($permissions as $permission_name) {
        if (is_string($permission_name) && strpos($permission_name, $module_name . '.') === 0) {
            return $module_access_cache[$cache_key] = true;
        }
    }

    return $module_access_cache[$cache_key] = false;
}

// Check module access and die if not allowed
function checkModuleAccess($conn, $user_id, $module_name) {
    if (!hasModuleAccess($conn, $user_id, $module_name)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'message' => 'Access Denied: You do not have permission to access this module.',
            'module' => $module_name
        ]));
    }
}

// Get all permissions for a role
function getRolePermissions($conn, $role_id) {
    $query = "SELECT p.* FROM permissions p
              INNER JOIN role_permissions rp ON p.id = rp.permission_id
              WHERE rp.role_id = ?
              ORDER BY p.module, p.action";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in getRolePermissions: " . mysqli_error($conn));
        return [];
    }
    
    mysqli_stmt_bind_param($stmt, "i", $role_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $permissions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        if (!isset($permissions[$row['module']])) {
            $permissions[$row['module']] = [];
        }
        $permissions[$row['module']][] = $row;
    }
    mysqli_stmt_close($stmt);
    
    return $permissions;
}

// Assign permission to role
function assignPermissionToRole($conn, $role_id, $permission_id) {
    // Check if already assigned
    $check_query = "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    if (!$check_stmt) {
        error_log("RBAC Error in assignPermissionToRole (check): " . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($check_stmt, "ii", $role_id, $permission_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        mysqli_stmt_close($check_stmt);
        return true; // Already assigned
    }
    mysqli_stmt_close($check_stmt);
    
    // Assign
    $query = "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in assignPermissionToRole: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $role_id, $permission_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    return $result;
}

// Remove permission from role
function removePermissionFromRole($conn, $role_id, $permission_id) {
    $query = "DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in removePermissionFromRole: " . mysqli_error($conn));
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "ii", $role_id, $permission_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    return $result;
}

// Get all available roles
function getAllRoles($conn, $active_only = true) {
    $query = "SELECT * FROM roles";
    if ($active_only) {
        $query .= " WHERE is_active = 1";
    }
    $query .= " ORDER BY level DESC";
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        error_log("RBAC Error in getAllRoles: " . mysqli_error($conn));
        return [];
    }
    
    $roles = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $roles[] = $row;
    }
    
    return $roles;
}

// Get role by ID
function getRoleById($conn, $role_id) {
    $query = "SELECT * FROM roles WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in getRoleById: " . mysqli_error($conn));
        return null;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $role_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $role = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    return $role;
}

// Get role by name
function getRoleByName($conn, $role_name) {
    $query = "SELECT * FROM roles WHERE name = ?";
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in getRoleByName: " . mysqli_error($conn));
        return null;
    }
    
    mysqli_stmt_bind_param($stmt, "s", $role_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $role = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    return $role;
}

// Get all permissions
function getAllPermissions($conn) {
    $query = "SELECT * FROM permissions ORDER BY module, action";
    $result = mysqli_query($conn, $query);
    if (!$result) {
        error_log("RBAC Error in getAllPermissions: " . mysqli_error($conn));
        return [];
    }
    
    $permissions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $permissions[] = $row;
    }
    
    return $permissions;
}

// Get permissions grouped by module
function getPermissionsByModule($conn) {
    $all_permissions = getAllPermissions($conn);
    
    $grouped = [];
    foreach ($all_permissions as $perm) {
        if (!isset($grouped[$perm['module']])) {
            $grouped[$perm['module']] = [];
        }
        $grouped[$perm['module']][] = $perm;
    }
    
    return $grouped;
}

// Log RBAC actions (audit trail)
function logRBACAction($conn, $user_id, $action, $module, $description = '') {
    $query = "INSERT INTO audit_logs (user_id, action, module, description, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in logRBACAction: " . mysqli_error($conn));
        return false;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    mysqli_stmt_bind_param($stmt, "isssss", $user_id, $action, $module, $description, $ip_address, $user_agent);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    return $result;
}

// Sync old user_type to new role system
// Run this ONCE after migration to set roles based on user_type
function syncUserTypeToRoles($conn) {
    // Set customer default role (if needed)
    $query = "UPDATE users u 
              SET role_id = (SELECT id FROM roles WHERE name = 'viewer')
              WHERE u.user_type = 'customer' AND u.role_id IS NULL";
    
    mysqli_query($conn, $query);
    
    // Admin users already have super_admin role from migration
    return true;
}

// Check if user is super admin
function isSuperAdmin($conn, $user_id) {
    $role = getUserRole($conn, $user_id);
    return $role && $role['name'] === 'super_admin';
}

// Check if user is business owner
function isBusinessOwner($conn, $user_id) {
    $role = getUserRole($conn, $user_id);
    return $role && $role['name'] === 'business_owner';
}

// Check if user is an approved franchise partner seller account.
function isApprovedFranchisePartner($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return false;
    }

    static $franchise_partner_cache = [];
    if (array_key_exists($user_id, $franchise_partner_cache)) {
        return $franchise_partner_cache[$user_id];
    }

    $query = "SELECT 1
              FROM users u
              WHERE u.id = ?
                AND u.account_type = 'organization'
                AND EXISTS (
                    SELECT 1
                    FROM franchise_applications fa
                    WHERE fa.user_id = u.id
                      AND fa.status = 'approved'
                )
              LIMIT 1";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in isApprovedFranchisePartner: " . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $is_partner = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    $franchise_partner_cache[$user_id] = $is_partner;
    return $is_partner;
}

// Check if user should access backoffice/admin portal
function hasBackofficeAccess($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return false;
    }

    static $backoffice_cache = [];
    if (array_key_exists($user_id, $backoffice_cache)) {
        return $backoffice_cache[$user_id];
    }

    // Super admin always has backoffice access.
    if (isSuperAdmin($conn, $user_id)) {
        return $backoffice_cache[$user_id] = true;
    }

    $query = "SELECT 1 FROM permissions p
              INNER JOIN role_permissions rp ON p.id = rp.permission_id
              INNER JOIN roles r ON rp.role_id = r.id
              INNER JOIN users u ON u.role_id = r.id
              WHERE u.id = ?
              AND r.is_active = 1
              AND p.module IN ('dashboard', 'orders', 'preorders', 'logistics', 'inventory', 'products', 'mrp', 'hr', 'payroll', 'finance', 'billing', 'admin', 'roles')
              LIMIT 1";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("RBAC Error in hasBackofficeAccess: " . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $result = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    $backoffice_cache[$user_id] = $result;
    return $result;
}

// Resolve post-login dashboard route based on RBAC role/permissions
function resolveBackofficeDashboardRoute($conn = null, $user_id = 0) {
    $user_id = (int)$user_id;
    $super_admin_dashboard = __DIR__ . '/../super_admin/super_admin_dashboard.php';
    if ($conn && $user_id > 0 && isSuperAdmin($conn, $user_id) && file_exists($super_admin_dashboard)) {
        return 'super_admin/super_admin_dashboard.php';
    }

    $admin_dashboard = __DIR__ . '/../admin/index.php';
    if (file_exists($admin_dashboard)) {
        return 'admin/index.php';
    }

    $employee_dashboard = __DIR__ . '/../employee/dashboard.php';
    if (file_exists($employee_dashboard)) {
        return 'employee/dashboard.php';
    }

    return 'index.php';
}

function getUserDashboardRoute($conn, $user_id, $user_type = '') {
    if (hasBackofficeAccess($conn, $user_id)) {
        return resolveBackofficeDashboardRoute($conn, $user_id);
    }

    $normalized_user_type = strtolower(trim((string)$user_type));
    if ($normalized_user_type === 'employee') {
        return 'employee/dashboard.php';
    }

    return 'index.php';
}

// Get role display name (human-readable)
function getRoleDisplayName($role_name) {
    $display_names = [
        'super_admin' => 'Super Administrator (System Owner)',
        'business_owner' => 'Business Owner (Shop Owner)',
        'hr_manager' => 'HR Manager',
        'operations_manager' => 'Operations Manager',
        'finance_manager' => 'Finance Manager',
        'inventory_manager' => 'Inventory Manager',
        'driver' => 'Delivery Driver',
        'viewer' => 'View-Only Access'
    ];
    
    return $display_names[$role_name] ?? ucfirst(str_replace('_', ' ', $role_name));
}

// Get module display name
function getModuleDisplayName($module) {
    $display_names = [
        'dashboard' => 'Dashboard',
        'orders' => 'Orders',
        'preorders' => 'Pre-Orders',
        'logistics' => 'Logistics',
        'inventory' => 'Inventory',
        'products' => 'Products',
        'mrp' => 'MRP System',
        'hr' => 'Human Resources',
        'payroll' => 'Payroll',
        'finance' => 'Finance',
        'admin' => 'Administration'
    ];
    
    return $display_names[$module] ?? ucfirst($module);
}
?>
