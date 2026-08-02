<?php
// Admin authentication check with RBAC support
// Include RBAC if not already included
if (!function_exists('hasPermission')) {
    require_once __DIR__ . '/../includes/rbac.php';
}

/**
 * Ensure session exists before reading/writing auth state.
 * @return void
 */
function ensureAdminSessionStarted() {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

if (!function_exists('adminAuthTableExists')) {
    function adminAuthTableExists($conn, $table_name) {
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

if (!function_exists('adminAuthColumnExists')) {
    function adminAuthColumnExists($conn, $table_name, $column_name) {
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
        if (!adminAuthTableExists($conn, $table_name)) {
            return $cache[$cache_key] = false;
        }

        $safe_table = mysqli_real_escape_string($conn, $table_name);
        $safe_column = mysqli_real_escape_string($conn, $column_name);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
        return $cache[$cache_key] = ($result && mysqli_num_rows($result) > 0);
    }
}

if (!function_exists('linkPartnerManagedUser')) {
    function linkPartnerManagedUser($conn, $owner_user_id, $managed_user_id) {
        $owner_user_id = intval($owner_user_id);
        $managed_user_id = intval($managed_user_id);
        if ($owner_user_id <= 0 || $managed_user_id <= 0 || !$conn) {
            return false;
        }
        if (!adminAuthTableExists($conn, 'partner_user_links')
            || !adminAuthColumnExists($conn, 'partner_user_links', 'owner_user_id')
            || !adminAuthColumnExists($conn, 'partner_user_links', 'managed_user_id')) {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO partner_user_links (owner_user_id, managed_user_id, created_at)
             VALUES (?, ?, NOW())"
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "ii", $owner_user_id, $managed_user_id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

/**
 * Checks if the current request is an AJAX request.
 * @return bool
 */

function isAjaxRequest() {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $expects_json = strpos($accept_header, 'application/json') !== false;
    $sends_json = stripos($content_type, 'application/json') !== false;

    return $is_ajax || $expects_json || $sends_json;
}

/**
 * Resolve a safe admin redirect target for denied page access.
 * Avoids redirect loops when the user does not have dashboard permissions.
 * @return string
 */
function resolveDeniedAdminRedirectTarget() {
    $current_script = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    if (strpos($current_script, '/super_admin/') !== false) {
        return '../admin/index.php';
    }

    $permissions = (isset($_SESSION['permissions']) && is_array($_SESSION['permissions']))
        ? $_SESSION['permissions']
        : [];

    $hasPrefix = static function(array $prefixes) use ($permissions): bool {
        foreach ($permissions as $permission_name) {
            if (!is_string($permission_name)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (strpos($permission_name, $prefix) === 0) {
                    return true;
                }
            }
        }
        return false;
    };

    $module_fallbacks = [
        [['dashboard.'], 'index.php'],
        [['orders.'], 'orders.php'],
        [['preorders.'], 'preorders.php'],
        [['logistics.'], 'logistics.php'],
        [['products.'], 'products.php'],
        [['inventory.'], 'inventory.php'],
        [['mrp.'], 'mrp.php'],
        [['hr.', 'employees.', 'attendance.', 'leave.', 'performance.'], 'hr.php'],
        [['payroll.', 'payslip.'], 'payroll.php'],
        [['finance.', 'expenses.'], 'finance.php'],
        [['billing.'], 'partner_billing.php'],
        [['forecasting.'], 'forecasting_dashboard.php'],
        [['reports.'], 'dss_reports.php'],
        [['roles.'], 'rbac_management.php'],
        [['admin.', 'audit.'], 'statistics.php']
    ];

    foreach ($module_fallbacks as $fallback) {
        [$prefixes, $target] = $fallback;
        if ($hasPrefix($prefixes)) {
            return $target;
        }
    }

    return '../index.php';
}

/**
 * Denies access to the page, handling both regular and AJAX requests appropriately.
 * @param string $message The error message to display or return.
 * @return void
 */
function denyAdminAccess($message = 'Access Denied: You do not have permission to access this resource.') {
    ensureAdminSessionStarted();

    if (isAjaxRequest()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }

    $_SESSION['error'] = $message;
    $redirect_target = resolveDeniedAdminRedirectTarget();
    header('Location: ' . $redirect_target);
    exit;
}

/**
 * Handle unauthorized admin access for both AJAX and regular requests.
 * @param string $message
 * @return void
 */
function denyAdminBackofficeEntry($message = 'Access Denied: Unauthorized access.') {
    if (isAjaxRequest()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'redirect' => '../index.php?error=unauthorized'
        ]);
        exit;
    }

    header("Location: ../index.php?error=unauthorized");
    exit;
}

/**
 * Lightweight fallback check using cached permission names.
 * @return bool
 */
function sessionHasBackofficePermissionHint() {
    $permissions = getSessionPermissions();
    if (empty($permissions)) {
        return false;
    }

    $allowed_modules = ['dashboard', 'orders', 'preorders', 'logistics', 'inventory', 'products', 'mrp', 'hr', 'payroll', 'finance', 'billing', 'operations', 'admin', 'roles', 'forecasting'];
    foreach ($permissions as $permission) {
        if (!is_string($permission) || strpos($permission, '.') === false) {
            continue;
        }
        [$module] = explode('.', $permission, 2);
        if (in_array($module, $allowed_modules, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Checks if the current user is an approved franchise partner account.
 * These accounts should be tenant-scoped inside admin modules.
 * @param mysqli $conn
 * @param int $user_id
 * @return bool
 */
function getFranchiseSellerScopeOwnerId($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0 || !$conn) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }

    $query = "SELECT u.id
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
        return $cache[$user_id] = null;
    }

    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!empty($row['id'])) {
        return $cache[$user_id] = (int)$row['id'];
    }

    // Partner-managed sub-users inherit the partner owner's seller scope.
    if (adminAuthTableExists($conn, 'partner_user_links')
        && adminAuthColumnExists($conn, 'partner_user_links', 'owner_user_id')
        && adminAuthColumnExists($conn, 'partner_user_links', 'managed_user_id')) {
        $link_query = "SELECT pul.owner_user_id
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
                       LIMIT 1";
        $link_stmt = mysqli_prepare($conn, $link_query);
        if ($link_stmt) {
            mysqli_stmt_bind_param($link_stmt, "i", $user_id);
            mysqli_stmt_execute($link_stmt);
            $link_result = mysqli_stmt_get_result($link_stmt);
            $link_row = $link_result ? mysqli_fetch_assoc($link_result) : null;
            mysqli_stmt_close($link_stmt);

            if (!empty($link_row['owner_user_id'])) {
                return $cache[$user_id] = (int)$link_row['owner_user_id'];
            }
        }
    }

    // Fallback: infer partner owner scope from role ownership (`roles.owner_user_id`).
    // This keeps tenant isolation working for partner-managed staff accounts
    // even when partner_user_links rows were not created historically.
    if (adminAuthColumnExists($conn, 'roles', 'owner_user_id') && adminAuthColumnExists($conn, 'users', 'role_id')) {
        $role_owner_query = "SELECT r.owner_user_id
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
                             LIMIT 1";
        $role_owner_stmt = mysqli_prepare($conn, $role_owner_query);
        if ($role_owner_stmt) {
            mysqli_stmt_bind_param($role_owner_stmt, "i", $user_id);
            mysqli_stmt_execute($role_owner_stmt);
            $role_owner_result = mysqli_stmt_get_result($role_owner_stmt);
            $role_owner_row = $role_owner_result ? mysqli_fetch_assoc($role_owner_result) : null;
            mysqli_stmt_close($role_owner_stmt);

            if (!empty($role_owner_row['owner_user_id'])) {
                $owner_user_id = (int)$role_owner_row['owner_user_id'];
                // Best effort: auto-heal missing explicit ownership link.
                linkPartnerManagedUser($conn, $owner_user_id, $user_id);
                return $cache[$user_id] = $owner_user_id;
            }
        }
    }

    return $cache[$user_id] = null;
}

if (!function_exists('getFranchiseSellerScopeUserIds')) {
    /**
     * Resolves the full set of seller user IDs under a partner owner scope.
     * Includes the owner plus managed sub-users.
     *
     * @param mysqli $conn
     * @param int $seller_scope_owner_id
     * @return array<int>
     */
    function getFranchiseSellerScopeUserIds($conn, $seller_scope_owner_id) {
        $seller_scope_owner_id = intval($seller_scope_owner_id);
        if ($seller_scope_owner_id <= 0 || !$conn) {
            return [];
        }

        static $cache = [];
        if (array_key_exists($seller_scope_owner_id, $cache)) {
            return $cache[$seller_scope_owner_id];
        }

        $scope_ids = [$seller_scope_owner_id => true];

        if (adminAuthTableExists($conn, 'partner_user_links')
            && adminAuthColumnExists($conn, 'partner_user_links', 'owner_user_id')
            && adminAuthColumnExists($conn, 'partner_user_links', 'managed_user_id')) {
            $stmt = mysqli_prepare($conn, "SELECT managed_user_id FROM partner_user_links WHERE owner_user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $seller_scope_owner_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $managed_user_id = intval($row['managed_user_id'] ?? 0);
                    if ($managed_user_id > 0) {
                        $scope_ids[$managed_user_id] = true;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        // Fallback/compatibility path: include users whose roles are owned by this partner owner.
        if (adminAuthColumnExists($conn, 'roles', 'owner_user_id') && adminAuthColumnExists($conn, 'users', 'role_id')) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT u.id
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.owner_user_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $seller_scope_owner_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $managed_user_id = intval($row['id'] ?? 0);
                    if ($managed_user_id > 0) {
                        $scope_ids[$managed_user_id] = true;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        $resolved_ids = array_map('intval', array_keys($scope_ids));
        sort($resolved_ids, SORT_NUMERIC);

        return $cache[$seller_scope_owner_id] = $resolved_ids;
    }
}

if (!function_exists('getFranchiseSellerScopeConditionSql')) {
    /**
     * Builds a SQL condition for scoped seller matching.
     * Example: "p_scope.seller_id IN (31,34)".
     *
     * @param mysqli $conn
     * @param string $column_expr
     * @param int $seller_scope_owner_id
     * @return string
     */
    function getFranchiseSellerScopeConditionSql($conn, $column_expr, $seller_scope_owner_id) {
        $column_expr = trim((string)$column_expr);
        if ($column_expr === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column_expr)) {
            return '1=0';
        }

        $scope_ids = getFranchiseSellerScopeUserIds($conn, $seller_scope_owner_id);
        if (empty($scope_ids)) {
            return '1=0';
        }

        $id_list = implode(',', array_map('intval', $scope_ids));
        return $column_expr . ' IN (' . $id_list . ')';
    }
}

if (!function_exists('getFranchiseScopedOrderExistsSql')) {
    /**
     * Builds an order-level EXISTS condition scoped to a partner seller scope.
     *
     * @param mysqli $conn
     * @param int $seller_scope_owner_id
     * @param string $order_id_expr
     * @return string
     */
    function getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, $order_id_expr = 'o.id') {
        $order_id_expr = trim((string)$order_id_expr);
        if ($order_id_expr === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $order_id_expr)) {
            return '1=0';
        }

        $seller_scope_condition = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', $seller_scope_owner_id);
        if ($seller_scope_condition === '1=0') {
            return '1=0';
        }

        return "EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (
                    oi_scope.product_id = p_scope.product_id
                    OR oi_scope.product_id = CAST(p_scope.id AS CHAR)
                    OR CAST(oi_scope.product_id AS UNSIGNED) = p_scope.id
                )
            WHERE oi_scope.order_id = {$order_id_expr}
              AND {$seller_scope_condition}
        )";
    }
}

if (!function_exists('getFranchiseScopedPreOrderExistsSql')) {
    /**
     * Builds a pre-order-level EXISTS condition scoped to a partner seller scope.
     *
     * @param mysqli $conn
     * @param int $seller_scope_owner_id
     * @param string $pre_order_id_expr
     * @return string
     */
    function getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_owner_id, $pre_order_id_expr = 'po.id') {
        $pre_order_id_expr = trim((string)$pre_order_id_expr);
        if ($pre_order_id_expr === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $pre_order_id_expr)) {
            return '1=0';
        }

        $seller_scope_condition = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', $seller_scope_owner_id);
        if ($seller_scope_condition === '1=0') {
            return '1=0';
        }

        return "EXISTS (
            SELECT 1
            FROM pre_orders po_scope
            INNER JOIN products p_scope
                ON (
                    po_scope.product_id = p_scope.id
                    OR CAST(po_scope.product_id AS CHAR) = p_scope.product_id
                    OR CAST(po_scope.product_id AS UNSIGNED) = p_scope.id
                )
            WHERE po_scope.id = {$pre_order_id_expr}
              AND {$seller_scope_condition}
        )";
    }
}

if (!function_exists('adminPartnerScopeEntityExists')) {
    /**
     * Verifies whether a specific entity record belongs to the current partner tenant scope.
     *
     * @param mysqli $conn
     * @param int $seller_scope_owner_id
     * @param string $entity_type
     * @param int $entity_id
     * @return bool
     */
    function adminPartnerScopeEntityExists($conn, $seller_scope_owner_id, $entity_type, $entity_id) {
        $seller_scope_owner_id = (int)$seller_scope_owner_id;
        $entity_id = (int)$entity_id;
        $entity_type = strtolower(trim((string)$entity_type));

        if (!$conn || $seller_scope_owner_id <= 0 || $entity_id <= 0 || $entity_type === '') {
            return false;
        }

        $scope_ids = getFranchiseSellerScopeUserIds($conn, $seller_scope_owner_id);
        $scope_ids = array_values(array_unique(array_filter(array_map('intval', (array)$scope_ids), static function($id) {
            return $id > 0;
        })));
        if (empty($scope_ids)) {
            $scope_ids = [$seller_scope_owner_id];
        }
        $scope_list = implode(',', $scope_ids);

        if ($entity_type === 'seller_user') {
            return in_array($entity_id, $scope_ids, true);
        }

        $query = '';

        if ($entity_type === 'product') {
            $scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', $seller_scope_owner_id);
            if ($scope_sql === '1=0') {
                return false;
            }
            $query = "SELECT 1 FROM products p_scope WHERE p_scope.id = ? AND {$scope_sql} LIMIT 1";
        } elseif ($entity_type === 'product_review') {
            if (!adminAuthTableExists($conn, 'product_reviews') || !adminAuthColumnExists($conn, 'product_reviews', 'product_id')) {
                return false;
            }
            $scope_sql = getFranchiseSellerScopeConditionSql($conn, 'p_scope.seller_id', $seller_scope_owner_id);
            if ($scope_sql === '1=0') {
                return false;
            }
            $query = "SELECT 1
                      FROM product_reviews pr_scope
                      INNER JOIN products p_scope ON p_scope.id = pr_scope.product_id
                      WHERE pr_scope.id = ?
                        AND {$scope_sql}
                      LIMIT 1";
        } elseif ($entity_type === 'order') {
            $scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'o_scope.id');
            if ($scope_sql === '1=0') {
                return false;
            }
            $query = "SELECT 1 FROM orders o_scope WHERE o_scope.id = ? AND {$scope_sql} LIMIT 1";
        } elseif ($entity_type === 'pre_order') {
            $scope_sql = getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_owner_id, 'po_scope.id');
            if ($scope_sql === '1=0') {
                return false;
            }
            $query = "SELECT 1 FROM pre_orders po_scope WHERE po_scope.id = ? AND {$scope_sql} LIMIT 1";
        } elseif ($entity_type === 'expense') {
            if (!adminAuthTableExists($conn, 'expenses')) {
                return false;
            }

            $recorded_exists = adminAuthColumnExists($conn, 'expenses', 'recorded_by');
            $owner_exists = adminAuthColumnExists($conn, 'expenses', 'owner_user_id');
            if (!$recorded_exists && !$owner_exists) {
                return false;
            }

            if ($recorded_exists && $owner_exists) {
                $query = "SELECT 1
                          FROM expenses exp_scope
                          WHERE exp_scope.id = ?
                            AND COALESCE(exp_scope.owner_user_id, exp_scope.recorded_by) IN ({$scope_list})
                          LIMIT 1";
            } elseif ($owner_exists) {
                $query = "SELECT 1
                          FROM expenses exp_scope
                          WHERE exp_scope.id = ?
                            AND exp_scope.owner_user_id IN ({$scope_list})
                          LIMIT 1";
            } else {
                $query = "SELECT 1
                          FROM expenses exp_scope
                          WHERE exp_scope.id = ?
                            AND exp_scope.recorded_by IN ({$scope_list})
                          LIMIT 1";
            }
        } elseif ($entity_type === 'employee') {
            if (!adminAuthTableExists($conn, 'employees') || !adminAuthColumnExists($conn, 'employees', 'user_id')) {
                return false;
            }
            $query = "SELECT 1
                      FROM employees e_scope
                      WHERE e_scope.id = ?
                        AND e_scope.user_id IN ({$scope_list})
                      LIMIT 1";
        } elseif ($entity_type === 'leave') {
            if (!adminAuthTableExists($conn, 'leave_requests') || !adminAuthColumnExists($conn, 'leave_requests', 'employee_id')) {
                return false;
            }
            if (!adminAuthTableExists($conn, 'employees') || !adminAuthColumnExists($conn, 'employees', 'user_id')) {
                return false;
            }
            $query = "SELECT 1
                      FROM leave_requests lr_scope
                      INNER JOIN employees e_scope ON e_scope.id = lr_scope.employee_id
                      WHERE lr_scope.id = ?
                        AND e_scope.user_id IN ({$scope_list})
                      LIMIT 1";
        } elseif ($entity_type === 'performance') {
            if (!adminAuthTableExists($conn, 'performance_reviews') || !adminAuthColumnExists($conn, 'performance_reviews', 'employee_id')) {
                return false;
            }
            if (!adminAuthTableExists($conn, 'employees') || !adminAuthColumnExists($conn, 'employees', 'user_id')) {
                return false;
            }
            $query = "SELECT 1
                      FROM performance_reviews pr_scope
                      INNER JOIN employees e_scope ON e_scope.id = pr_scope.employee_id
                      WHERE pr_scope.id = ?
                        AND e_scope.user_id IN ({$scope_list})
                      LIMIT 1";
        } elseif ($entity_type === 'conversation') {
            if (!adminAuthTableExists($conn, 'chat_conversations')) {
                return false;
            }
            $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'cc_scope.order_id');
            if ($order_scope_sql === '1=0') {
                $order_scope_sql = '1=0';
            }
            $seller_column_exists = adminAuthColumnExists($conn, 'chat_conversations', 'seller_id');
            $order_column_exists = adminAuthColumnExists($conn, 'chat_conversations', 'order_id');
            if (!$seller_column_exists && !$order_column_exists) {
                return false;
            }
            $conversation_checks = [];
            if ($seller_column_exists) {
                $conversation_checks[] = "(cc_scope.seller_id IS NOT NULL AND cc_scope.seller_id IN ({$scope_list}))";
            }
            if ($order_column_exists) {
                $conversation_checks[] = "(cc_scope.order_id IS NOT NULL AND {$order_scope_sql})";
            }
            $query = "SELECT 1
                      FROM chat_conversations cc_scope
                      WHERE cc_scope.id = ?
                        AND (" . implode(' OR ', $conversation_checks) . ")
                      LIMIT 1";
        } elseif ($entity_type === 'tracking') {
            if (!adminAuthTableExists($conn, 'logistics_tracking') || !adminAuthColumnExists($conn, 'logistics_tracking', 'order_id')) {
                return false;
            }
            $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'lt_scope.order_id');
            if ($order_scope_sql === '1=0') {
                return false;
            }
            $query = "SELECT 1
                      FROM logistics_tracking lt_scope
                      WHERE lt_scope.id = ?
                        AND {$order_scope_sql}
                      LIMIT 1";
        } elseif ($entity_type === 'cancellation') {
            if (!adminAuthTableExists($conn, 'cancellations')) {
                return false;
            }
            $checks = [];
            if (adminAuthColumnExists($conn, 'cancellations', 'order_id')) {
                $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'c_scope.order_id');
                if ($order_scope_sql !== '1=0') {
                    $checks[] = "(c_scope.order_id IS NOT NULL AND {$order_scope_sql})";
                }
            }
            if (adminAuthColumnExists($conn, 'cancellations', 'reservation_id')) {
                $pre_order_scope_sql = getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_owner_id, 'c_scope.reservation_id');
                if ($pre_order_scope_sql !== '1=0') {
                    $checks[] = "(c_scope.reservation_id IS NOT NULL AND {$pre_order_scope_sql})";
                }
            }
            if (empty($checks)) {
                return false;
            }
            $query = "SELECT 1
                      FROM cancellations c_scope
                      WHERE c_scope.id = ?
                        AND (" . implode(' OR ', $checks) . ")
                      LIMIT 1";
        } elseif ($entity_type === 'refund') {
            if (
                !adminAuthTableExists($conn, 'refunds')
                || !adminAuthColumnExists($conn, 'refunds', 'cancellation_id')
                || !adminAuthTableExists($conn, 'cancellations')
            ) {
                return false;
            }
            $checks = [];
            if (adminAuthColumnExists($conn, 'cancellations', 'order_id')) {
                $order_scope_sql = getFranchiseScopedOrderExistsSql($conn, $seller_scope_owner_id, 'c_scope.order_id');
                if ($order_scope_sql !== '1=0') {
                    $checks[] = "(c_scope.order_id IS NOT NULL AND {$order_scope_sql})";
                }
            }
            if (adminAuthColumnExists($conn, 'cancellations', 'reservation_id')) {
                $pre_order_scope_sql = getFranchiseScopedPreOrderExistsSql($conn, $seller_scope_owner_id, 'c_scope.reservation_id');
                if ($pre_order_scope_sql !== '1=0') {
                    $checks[] = "(c_scope.reservation_id IS NOT NULL AND {$pre_order_scope_sql})";
                }
            }
            if (empty($checks)) {
                return false;
            }
            $query = "SELECT 1
                      FROM refunds r_scope
                      INNER JOIN cancellations c_scope ON c_scope.id = r_scope.cancellation_id
                      WHERE r_scope.id = ?
                        AND (" . implode(' OR ', $checks) . ")
                      LIMIT 1";
        } elseif ($entity_type === 'invoice') {
            if (!adminAuthTableExists($conn, 'partner_billing_invoices') || !adminAuthColumnExists($conn, 'partner_billing_invoices', 'partner_user_id')) {
                return false;
            }
            $query = "SELECT 1
                      FROM partner_billing_invoices pbi_scope
                      WHERE pbi_scope.id = ?
                        AND pbi_scope.partner_user_id IN ({$scope_list})
                      LIMIT 1";
        } elseif ($entity_type === 'store_location') {
            if (!adminAuthTableExists($conn, 'store_locations') || !adminAuthColumnExists($conn, 'store_locations', 'owner_user_id')) {
                return false;
            }
            $store_id_column = adminAuthColumnExists($conn, 'store_locations', 'store_id') ? 'store_id' : 'id';
            if (!adminAuthColumnExists($conn, 'store_locations', $store_id_column)) {
                return false;
            }
            $query = "SELECT 1
                      FROM store_locations sl_scope
                      WHERE sl_scope.{$store_id_column} = ?
                        AND sl_scope.owner_user_id IN ({$scope_list})
                      LIMIT 1";
        } elseif ($entity_type === 'purchase_order') {
            if (!adminAuthTableExists($conn, 'purchase_orders')) {
                return false;
            }
            if (adminAuthColumnExists($conn, 'purchase_orders', 'owner_user_id')) {
                $query = "SELECT 1
                          FROM purchase_orders po_scope
                          WHERE po_scope.id = ?
                            AND po_scope.owner_user_id IN ({$scope_list})
                          LIMIT 1";
            } elseif (adminAuthColumnExists($conn, 'purchase_orders', 'created_by')) {
                $query = "SELECT 1
                          FROM purchase_orders po_scope
                          WHERE po_scope.id = ?
                            AND po_scope.created_by IN ({$scope_list})
                          LIMIT 1";
            } else {
                return false;
            }
        } else {
            return false;
        }

        if ($query === '') {
            return false;
        }

        $stmt = mysqli_prepare($conn, $query);
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, "i", $entity_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($stmt);
        return $exists;
    }
}

if (!function_exists('enforcePartnerTenantScopedRequestAccess')) {
    /**
     * Central request-level tenant guard for partner-scoped admins.
     * Blocks direct access to cross-tenant entity IDs even if an endpoint misses local SQL filtering.
     *
     * @param mysqli $conn
     * @param int $user_id
     * @param string $current_page
     * @return void
     */
    function enforcePartnerTenantScopedRequestAccess($conn, $user_id, $current_page) {
        $user_id = (int)$user_id;
        $current_page = basename((string)$current_page);
        if ($user_id <= 0 || !$conn || $current_page === '') {
            return;
        }
        if (function_exists('sessionIsSuperAdmin') && sessionIsSuperAdmin($conn, $user_id)) {
            return;
        }
        $current_script = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
        if (strpos($current_script, '/super_admin/') !== false) {
            return;
        }
        if (!function_exists('getFranchiseSellerScopeOwnerId') || !isApprovedFranchiseSellerAccount($conn, $user_id)) {
            return;
        }

        $seller_scope_owner_id = (int)(getFranchiseSellerScopeOwnerId($conn, $user_id) ?? 0);
        if ($seller_scope_owner_id <= 0) {
            return;
        }

        $request_int = static function ($key) {
            if (isset($_POST[$key])) {
                return (int)$_POST[$key];
            }
            if (isset($_GET[$key])) {
                return (int)$_GET[$key];
            }
            return 0;
        };

        $checks = [];
        $seen = [];
        $add_check = static function (&$checks, &$seen, $entity_type, $entity_id, $source_key) {
            $entity_type = strtolower(trim((string)$entity_type));
            $entity_id = (int)$entity_id;
            $source_key = trim((string)$source_key);
            if ($entity_type === '' || $entity_id <= 0) {
                return;
            }
            $dedupe_key = $entity_type . ':' . $entity_id;
            if (isset($seen[$dedupe_key])) {
                return;
            }
            $seen[$dedupe_key] = true;
            $checks[] = ['entity' => $entity_type, 'id' => $entity_id, 'source' => $source_key];
        };

        $generic_param_entity_map = [
            'order_id' => 'order',
            'pre_order_id' => 'pre_order',
            'preorder_id' => 'pre_order',
            'reservation_id' => 'pre_order',
            'product_id' => 'product',
            'seller_id' => 'seller_user',
            'owner_user_id' => 'seller_user',
            'conversation_id' => 'conversation',
            'tracking_id' => 'tracking',
            'cancellation_id' => 'cancellation',
            'refund_id' => 'refund',
            'invoice_id' => 'invoice',
            'expense_id' => 'expense',
            'employee_id' => 'employee',
            'driver_id' => 'employee',
            'leave_id' => 'leave',
            'review_id' => 'product_review',
            'performance_id' => 'performance',
            'po_id' => 'purchase_order',
            'purchase_order_id' => 'purchase_order',
            'store_id' => 'store_location'
        ];
        foreach ($generic_param_entity_map as $param_key => $entity_type) {
            $add_check($checks, $seen, $entity_type, $request_int($param_key), $param_key);
        }

        $page_specific_id_entity_map = [
            'get_order_details.php' => 'order',
            'print_order_receipt.php' => 'order',
            'get_preorder_details.php' => 'pre_order',
            'print_preorder_receipt.php' => 'pre_order',
            'ajax_update_preorder.php' => 'pre_order',
            'get_inventory_details.php' => 'product',
            'get_inventory_history.php' => 'product',
            'get_po_details.php' => 'purchase_order',
            'get_employee_details.php' => 'employee',
            'get_leave_details.php' => 'leave',
            'get_performance_details.php' => 'performance',
            'chat.php' => 'conversation',
            'expenses.php' => 'expense'
        ];
        if (isset($page_specific_id_entity_map[$current_page])) {
            $add_check($checks, $seen, $page_specific_id_entity_map[$current_page], $request_int('id'), 'id');
        }

        foreach ($checks as $check) {
            if (!adminPartnerScopeEntityExists($conn, $seller_scope_owner_id, $check['entity'], (int)$check['id'])) {
                denyAdminAccess('Access denied: Cross-tenant data access is not allowed for partner accounts.');
            }
        }
    }
}

function isApprovedFranchiseSellerAccount($conn, $user_id) {
    return getFranchiseSellerScopeOwnerId($conn, $user_id) !== null;
}

/**
 * Maps a PHP page filename to its corresponding module.
 * @deprecated This function is brittle. It's better to declare permissions directly on each page using requirePermission().
 * @param string $page_name The basename of the PHP file.
 * @return string|null The module name or null if not found.
 */
function getAdminModuleByPage($page_name) {
    $module_map = [
        'index.php' => 'dashboard',
        'super_admin_dashboard.php' => 'dashboard',
        'orders.php' => 'orders',
        'get_order_details.php' => 'orders',
        'reviews.php' => 'orders',
        'preorders.php' => 'preorders',
        'get_preorder_details.php' => 'preorders',
        'logistics.php' => 'logistics',
        'logistics_settings.php' => 'logistics',
        'assign_driver.php' => 'logistics',
        'cancel_delivery.php' => 'logistics',
        'update_delivery_status.php' => 'logistics',
        'ajax_get_driver_status.php' => 'logistics',
        'products.php' => 'products',
        'vouchers.php' => 'products',
        'inventory.php' => 'inventory',
        'get_inventory_details.php' => 'inventory',
        'get_inventory_history.php' => 'inventory',
        'mrp.php' => 'mrp',
        'purchase_order.php' => 'mrp',
        'get_po_details.php' => 'mrp',
        'materials.php' => 'mrp',
        'bom.php' => 'mrp',
        'hr.php' => 'hr',
        'employees.php' => 'hr',
        'departments.php' => 'hr',
        'attendance.php' => 'hr',
        'schedules.php' => 'hr',
        'leave_requests.php' => 'hr',
        'leave_balance.php' => 'hr',
        'performance.php' => 'hr',
        'recruitment.php' => 'hr',
        'candidates.php' => 'hr',
        'turnover.php' => 'hr',
        'hr_reports.php' => 'hr',
        'hr_migration_checker.php' => 'hr',
        'get_employee_details.php' => 'hr',
        'get_leave_details.php' => 'hr',
        'get_performance_details.php' => 'hr',
        'payroll.php' => 'payroll',
        'deductions.php' => 'payroll',
        'ajax_payroll.php' => 'payroll',
        'payslip_generation.php' => 'payroll',
        'view_payslip.php' => 'payroll',
        'send_payslip.php' => 'payroll',
        'get_payroll_details.php' => 'payroll',
        'get_payroll_details_for_approval.php' => 'payroll',
        'finance.php' => 'finance',
        'expenses.php' => 'finance',
        'order_policy_settings.php' => 'orders',
        'store_availability.php' => 'orders',
        'partner_billing.php' => 'billing',
        'subscription_plans.php' => 'billing',
        'receipt_settings.php' => 'billing',
        'partner_banking.php' => 'billing',
        'business_account.php' => 'billing',
        'operations_dashboard.php' => 'operations',
        'operations_dashboard_feed.php' => 'operations',
        'operations_incidents.php' => 'operations',
        'operations_user_business_control.php' => 'operations',
        'operations_content_moderation.php' => 'operations',
        'operations_decision_support.php' => 'operations',
        'operations_notifications.php' => 'operations',
        'operations_automation.php' => 'operations',
        'operations_logs_backups.php' => 'operations',
        'operations_team.php' => 'operations',
        'forecasting_dashboard.php' => 'forecasting',
        'events.php' => 'forecasting',
        'dss_reports.php' => 'reports',
        'update_recommendation_status.php' => 'forecasting',
        'users.php' => 'admin',
        'get_user_details.php' => 'admin',
        'rbac_management.php' => 'roles',
        'franchise_applications.php' => 'admin',
        'get_application_details.php' => 'admin',
        'statistics.php' => 'admin',
        'chat.php' => 'admin',
        'tenant_scope_migration.php' => 'admin',
        'get_notifications.php' => 'dashboard'
    ];

    return $module_map[$page_name] ?? null;
}

/**
 * Pages that should only be accessible by the super admin (system owner).
 * @return array
 */
function getSuperAdminOnlyAdminPages() {
    return [
        'super_admin_dashboard.php',
        'users.php',
        'get_user_details.php',
        'franchise_applications.php',
        'get_application_details.php',
        'user_business_management.php',
        'business_monitoring.php',
        'analytics_reports.php',
        'reports_complaints.php',
        'security_access_control.php',
        'activity_logs.php',
        'system_monitoring.php',
        'notification_center.php',
        'transactions_financial.php'
    ];
}

/**
 * Determines if the current admin page is restricted to super admin only.
 * @return bool
 */
function isCurrentPageSuperAdminOnly() {
    $current_page = basename($_SERVER['PHP_SELF'] ?? '');
    return in_array($current_page, getSuperAdminOnlyAdminPages(), true);
}

/**
 * Returns whether the current session user is super admin.
 * @param mysqli|null $conn
 * @param int $user_id
 * @return bool
 */
function sessionIsSuperAdmin($conn, $user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return false;
    }

    if ($conn && function_exists('isSuperAdmin')) {
        return isSuperAdmin($conn, $user_id);
    }

    $role_name = strtolower(trim((string)($_SESSION['role_name'] ?? '')));
    return $role_name === 'super_admin';
}

/**
 * Enforces super-admin-only module access.
 * @param mysqli|null $conn
 * @param int $user_id
 * @return void
 */
function enforceSuperAdminOnlyPageAccess($conn, $user_id) {
    if (!isCurrentPageSuperAdminOnly()) {
        return;
    }

    if (!sessionIsSuperAdmin($conn, $user_id)) {
        denyAdminAccess('Access Denied: Only the system owner (super admin) can access this module.');
    }
}

/**
 * Gets the user's permissions from the session cache.
 * @return array
 */
function getSessionPermissions() {
    return (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) ? $_SESSION['permissions'] : [];
}

function adminHasAnyPermission($conn, $user_id, array $permission_names) {
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$conn || !function_exists('hasPermission')) {
        return false;
    }

    foreach ($permission_names as $permission_name) {
        $permission_name = trim((string)$permission_name);
        if ($permission_name !== '' && hasPermission($conn, $user_id, $permission_name)) {
            return true;
        }
    }

    return false;
}

function enforcePartnerRoleScopedPageAccess($conn, $user_id, $current_page) {
    $user_id = (int)$user_id;
    $current_page = basename((string)$current_page);
    if ($user_id <= 0 || !$conn || $current_page === '' || !function_exists('getFranchiseSellerScopeOwnerId')) {
        return;
    }

    $partner_scope_owner_id = (int)(getFranchiseSellerScopeOwnerId($conn, $user_id) ?? 0);
    $is_partner_owner_admin = $partner_scope_owner_id > 0 && $partner_scope_owner_id === $user_id;
    if ($is_partner_owner_admin) {
        return;
    }

    $permission_rules = [
        'finance.php' => ['finance.view', 'finance.manage'],
        'expenses.php' => ['expenses.view', 'expenses.manage', 'finance.manage'],
        'order_policy_settings.php' => ['orders.edit'],
        'store_availability.php' => ['orders.edit', 'products.edit'],
        'chat.php' => ['orders.view', 'orders.edit', 'logistics.view', 'logistics.manage'],
        'partner_billing.php' => ['billing.view', 'billing.manage'],
        'subscription_plans.php' => ['billing.view', 'billing.manage'],
        'receipt_settings.php' => ['billing.manage'],
        'partner_banking.php' => ['billing.manage'],
        'business_account.php' => ['billing.manage'],
        'rbac_management.php' => ['roles.manage'],
        'forecasting_dashboard.php' => ['forecasting.view'],
        'events.php' => ['forecasting.view'],
        'dss_reports.php' => ['reports.view'],
        'statistics.php' => ['dashboard.analytics', 'audit.view'],
        'operations_dashboard.php' => ['operations.view', 'operations.incidents', 'operations.monitoring', 'operations.users_business', 'operations.content', 'operations.decision_support', 'operations.notifications', 'operations.automation', 'operations.logs'],
        'operations_dashboard_feed.php' => ['operations.view', 'operations.incidents', 'operations.monitoring', 'operations.users_business', 'operations.content', 'operations.decision_support', 'operations.notifications', 'operations.automation', 'operations.logs'],
        'operations_incidents.php' => ['operations.incidents', 'operations.view'],
        'operations_user_business_control.php' => ['operations.users_business', 'operations.view'],
        'operations_content_moderation.php' => ['operations.content', 'operations.view'],
        'operations_decision_support.php' => ['operations.decision_support', 'operations.view'],
        'operations_notifications.php' => ['operations.notifications', 'operations.view'],
        'operations_automation.php' => ['operations.automation', 'operations.view']
    ];

    if (!isset($permission_rules[$current_page]) || adminHasAnyPermission($conn, $user_id, $permission_rules[$current_page])) {
        return;
    }

    $message = 'Access denied: Your assigned role does not include permission for this department module.';
    if (isAjaxRequest()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'redirect' => 'index.php'
        ]);
        exit;
    }

    $_SESSION['error'] = $message;
    header('Location: index.php');
    exit;
}

function adminEnsureBillingPermissions($conn) {
    static $bootstrapped = false;
    if ($bootstrapped || !$conn || !adminAuthTableExists($conn, 'roles') || !adminAuthTableExists($conn, 'permissions') || !adminAuthTableExists($conn, 'role_permissions')) {
        return;
    }

    $bootstrapped = true;
    $permissions = [
        ['billing.view', 'billing', 'view', 'View partner billing pages and invoices'],
        ['billing.manage', 'billing', 'manage', 'Manage partner billing workflows and invoice actions']
    ];

    $permission_ids = [];
    foreach ($permissions as $permission_meta) {
        [$name, $module, $action, $description] = $permission_meta;
        $stmt = mysqli_prepare($conn, "SELECT id FROM permissions WHERE name = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $name);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
            if (!empty($row['id'])) {
                $permission_ids[$name] = (int)$row['id'];
                continue;
            }
        }

        $insert_stmt = mysqli_prepare($conn, "INSERT INTO permissions (name, module, action, description, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($insert_stmt) {
            mysqli_stmt_bind_param($insert_stmt, "ssss", $name, $module, $action, $description);
            mysqli_stmt_execute($insert_stmt);
            $permission_ids[$name] = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($insert_stmt);
        }
    }

    $grant_permission_to_role = static function ($role_id, $permission_id) use ($conn) {
        $check_stmt = mysqli_prepare($conn, "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1");
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "ii", $role_id, $permission_id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            $exists = mysqli_stmt_num_rows($check_stmt) > 0;
            mysqli_stmt_close($check_stmt);
            if ($exists) {
                return;
            }
        }

        $insert_stmt = mysqli_prepare($conn, "INSERT INTO role_permissions (role_id, permission_id, assigned_at) VALUES (?, ?, NOW())");
        if ($insert_stmt) {
            mysqli_stmt_bind_param($insert_stmt, "ii", $role_id, $permission_id);
            mysqli_stmt_execute($insert_stmt);
            mysqli_stmt_close($insert_stmt);
        }
    };

    foreach (['business_owner', 'finance_manager'] as $role_name) {
        $role_stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE name = ? LIMIT 1");
        if (!$role_stmt) {
            continue;
        }
        mysqli_stmt_bind_param($role_stmt, "s", $role_name);
        mysqli_stmt_execute($role_stmt);
        $result = mysqli_stmt_get_result($role_stmt);
        $role_row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($role_stmt);
        $role_id = (int)($role_row['id'] ?? 0);
        if ($role_id <= 0) {
            continue;
        }

        foreach ($permission_ids as $permission_id) {
            if ($permission_id > 0) {
                $grant_permission_to_role($role_id, $permission_id);
            }
        }
    }

    $finance_view_id = 0;
    $finance_manage_id = 0;
    foreach (['finance.view', 'finance.manage'] as $finance_permission_name) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM permissions WHERE name = ? LIMIT 1");
        if (!$stmt) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, "s", $finance_permission_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($finance_permission_name === 'finance.view') {
            $finance_view_id = (int)($row['id'] ?? 0);
        } else {
            $finance_manage_id = (int)($row['id'] ?? 0);
        }
    }

    $billing_view_id = (int)($permission_ids['billing.view'] ?? 0);
    $billing_manage_id = (int)($permission_ids['billing.manage'] ?? 0);

    if ($finance_view_id > 0 && $billing_view_id > 0) {
        $sql = "SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = " . $finance_view_id;
        $roles_result = mysqli_query($conn, $sql);
        if ($roles_result) {
            while ($role_row = mysqli_fetch_assoc($roles_result)) {
                $role_id = (int)($role_row['role_id'] ?? 0);
                if ($role_id > 0) {
                    $grant_permission_to_role($role_id, $billing_view_id);
                }
            }
            mysqli_free_result($roles_result);
        }
    }

    if ($finance_manage_id > 0 && $billing_manage_id > 0) {
        $sql = "SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = " . $finance_manage_id;
        $roles_result = mysqli_query($conn, $sql);
        if ($roles_result) {
            while ($role_row = mysqli_fetch_assoc($roles_result)) {
                $role_id = (int)($role_row['role_id'] ?? 0);
                if ($role_id > 0) {
                    $grant_permission_to_role($role_id, $billing_manage_id);
                }
            }
            mysqli_free_result($roles_result);
        }
    }
}

function adminEnsureBusinessOwnerFullPermissions($conn) {
    static $bootstrapped = false;
    if ($bootstrapped || !$conn || !adminAuthTableExists($conn, 'roles') || !adminAuthTableExists($conn, 'permissions') || !adminAuthTableExists($conn, 'role_permissions')) {
        return;
    }
    $bootstrapped = true;

    $role_stmt = mysqli_prepare($conn, "SELECT id FROM roles WHERE name = 'business_owner' AND is_active = 1 LIMIT 1");
    if (!$role_stmt) {
        return;
    }
    mysqli_stmt_execute($role_stmt);
    $role_result = mysqli_stmt_get_result($role_stmt);
    $role_row = $role_result ? mysqli_fetch_assoc($role_result) : null;
    mysqli_stmt_close($role_stmt);

    $business_owner_role_id = (int)($role_row['id'] ?? 0);
    if ($business_owner_role_id <= 0) {
        return;
    }

    $insert_sql = "INSERT INTO role_permissions (role_id, permission_id)
                   SELECT ?, p.id
                   FROM permissions p
                   WHERE NOT EXISTS (
                       SELECT 1
                       FROM role_permissions rp
                       WHERE rp.role_id = ?
                         AND rp.permission_id = p.id
                   )";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    if (!$insert_stmt) {
        return;
    }
    mysqli_stmt_bind_param($insert_stmt, "ii", $business_owner_role_id, $business_owner_role_id);
    mysqli_stmt_execute($insert_stmt);
    mysqli_stmt_close($insert_stmt);
}

/**
 * Checks if the user has any permission within a given module.
 * @param string $module_name The name of the module (e.g., 'orders').
 * @return bool
 */
function sessionHasModuleAccess($module_name) {
    $permissions = getSessionPermissions();
    foreach ($permissions as $permission) {
        if (strpos($permission, $module_name . '.') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Checks if the user has any write-level permission for a module.
 * @deprecated This is an insecure, broad check. Use specific permission checks like hasPermission($conn, $user_id, 'orders.create') instead.
 * @param string $module_name The name of the module.
 * @return bool
 */
function sessionHasModuleWriteAccess($module_name) {
    $permissions = getSessionPermissions();
    $write_actions = ['create', 'edit', 'delete', 'manage', 'assign', 'approve', 'update', 'generate'];

    foreach ($permissions as $permission) {
        if (strpos($permission, $module_name . '.') !== 0) {
            continue;
        }

        $parts = explode('.', $permission, 2);
        $action = $parts[1] ?? '';
        if (in_array($action, $write_actions, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Enforces module-level access based on the current page.
 * @deprecated This function uses a brittle page-to-module mapping and broad permission checks. Use requirePermission() instead.
 * @return void
 */
function enforceCurrentPageModuleAccess() {
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn']) ? $GLOBALS['conn'] : null;
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'super_admin') {
        return;
    }
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'business_owner') {
        return;
    }

    $permissions = getSessionPermissions();
    if (empty($permissions)) {
        // Prevent bypass for old sessions created before permission caching.
        if (isset($_SESSION['role_id']) && intval($_SESSION['role_id']) > 0) {
            denyAdminAccess('Session permissions are outdated. Please log out and log in again.');
        }
        return;
    }

    $page_name = basename($_SERVER['PHP_SELF'] ?? '');
    $module_name = getAdminModuleByPage($page_name);
    if (!$module_name) {
        return;
    }

    $fallback_page_permissions = [
        'rbac_management.php' => ['roles.manage'],

        // HR pages may use granular permission namespaces (employees.*, attendance.*, leave.*, performance.*)
        // while legacy module mapping resolves to "hr". Allow these permission aliases as fallback.
        'hr.php' => ['hr.view', 'employees.view', 'employees.edit', 'attendance.view', 'leave.view', 'performance.view'],
        'employees.php' => ['employees.view', 'employees.create', 'employees.edit', 'hr.view'],
        'departments.php' => ['departments.manage', 'hr.view'],
        'attendance.php' => ['attendance.view', 'attendance.manage', 'hr.view'],
        'schedules.php' => ['attendance.manage', 'hr.view'],
        'leave_requests.php' => ['leave.view', 'leave.approve', 'hr.view'],
        'leave_balance.php' => ['leave.view', 'leave.approve', 'hr.view'],
        'performance.php' => ['performance.view', 'performance.manage', 'hr.view'],
        'recruitment.php' => ['hr.view', 'employees.view'],
        'candidates.php' => ['hr.view', 'employees.view'],
        'turnover.php' => ['employees.view', 'employees.edit', 'hr.view'],
        'hr_reports.php' => ['dashboard.analytics', 'hr.view'],
        'get_employee_details.php' => ['employees.view', 'employees.edit', 'hr.view'],
        'get_leave_details.php' => ['leave.view', 'leave.approve', 'hr.view'],
        'get_performance_details.php' => ['performance.view', 'performance.manage', 'hr.view'],

        // Payroll pages can use payslip.* permissions while module mapping resolves to "payroll".
        'payroll.php' => ['payroll.view', 'payroll.manage'],
        'deductions.php' => ['payroll.manage'],
        'ajax_payroll.php' => ['payroll.view', 'payroll.manage', 'payslip.view', 'payslip.generate'],
        'payslip_generation.php' => ['payslip.generate', 'payslip.view', 'payroll.manage'],
        'view_payslip.php' => ['payslip.view', 'payslip.generate', 'payroll.view'],
        'send_payslip.php' => ['payslip.generate', 'payroll.manage'],
        'get_payroll_details.php' => ['payroll.view', 'payroll.manage'],
        'get_payroll_details_for_approval.php' => ['payroll.manage']
    ];
    $fallback_permissions = $fallback_page_permissions[$page_name] ?? [];
    $request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $is_write_request = $request_method !== 'GET';

    if ($is_write_request) {
        if (!sessionHasModuleWriteAccess($module_name)) {
            if (!empty($fallback_permissions)) {
                foreach ($fallback_permissions as $permission_name) {
                    if (is_string($permission_name) && in_array($permission_name, getSessionPermissions(), true)) {
                        return;
                    }
                }
                if ($conn && $user_id > 0 && adminHasAnyPermission($conn, $user_id, $fallback_permissions)) {
                    if (function_exists('getUserPermissions')) {
                        $_SESSION['permissions'] = getUserPermissions($conn, $user_id);
                    }
                    $_SESSION['rbac_synced_at'] = time();
                    return;
                }
            }
            denyAdminAccess('Access Denied: You only have view permissions for this module.');
        }
        return;
    }

    if (!sessionHasModuleAccess($module_name)) {
        if (!empty($fallback_permissions)) {
            foreach ($fallback_permissions as $permission_name) {
                if (is_string($permission_name) && in_array($permission_name, getSessionPermissions(), true)) {
                    return;
                }
            }
            if ($conn && $user_id > 0 && adminHasAnyPermission($conn, $user_id, $fallback_permissions)) {
                if (function_exists('getUserPermissions')) {
                    $_SESSION['permissions'] = getUserPermissions($conn, $user_id);
                }
                $_SESSION['rbac_synced_at'] = time();
                return;
            }
        }
        denyAdminAccess('Access Denied: You do not have permission to access this module.');
    }
}

/**
 * The main function to check if a user is authenticated and authorized for the admin area.
 * It populates session with role and permission data.
 * @return void
 */
function checkAdminAccess() {
    ensureAdminSessionStarted();

    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    if ($user_id <= 0) {
        if (isAjaxRequest()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.', 'redirect' => '../login.php']);
            exit;
        }
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/admin/';
        header("Location: ../login.php?redirect=" . urlencode($request_uri));
        exit;
    }

    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn']) ? $GLOBALS['conn'] : null;
    if ($conn) {
        adminEnsureBillingPermissions($conn);
        adminEnsureBusinessOwnerFullPermissions($conn);
    }
    enforceSuperAdminOnlyPageAccess($conn, $user_id);

    $account_control_state = ($conn && function_exists('getUserAccountControlState'))
        ? getUserAccountControlState($conn, $user_id)
        : ['status' => 'active'];
    $account_control_status = strtolower(trim((string)($account_control_state['status'] ?? 'active')));
    if (in_array($account_control_status, ['suspended', 'banned'], true)) {
        $blocked_message = function_exists('getUserAccountControlMessage')
            ? getUserAccountControlMessage($account_control_state)
            : 'Your account cannot access the admin portal right now.';
        denyAdminBackofficeEntry($blocked_message);
    }

    if ($conn && isApprovedFranchiseSellerAccount($conn, $user_id)) {
        $is_super_admin_session_user = sessionIsSuperAdmin($conn, $user_id);
        $current_page = basename($_SERVER['PHP_SELF'] ?? '');
        $partner_allowed_pages = [
            'index.php',
            'products.php',
            'vouchers.php',
            'orders.php',
            'get_order_details.php',
            'print_order_receipt.php',
            'chat.php',
            'cancellations.php',
            'reviews.php',
            'preorders.php',
            'get_preorder_details.php',
            'print_preorder_receipt.php',
            'ajax_update_preorder.php',
            'logistics.php',
            'assign_driver.php',
            'cancel_delivery.php',
            'update_delivery_status.php',
            'get_logistics_details.php',
            'ajax_get_driver_status.php',
            'get_driver_locations.php',
            'get_available_drivers.php',
            'kiosk.php',
            'inventory.php',
            'get_inventory_details.php',
            'get_inventory_history.php',
            'mrp.php',
            'purchase_order.php',
            'get_po_details.php',
            'finance.php',
            'expenses.php',
            'order_policy_settings.php',
            'store_availability.php',
            'partner_billing.php',
            'subscription_plans.php',
            'receipt_settings.php',
            'partner_banking.php',
            'business_account.php',
            'forecasting_dashboard.php',
            'dss_reports.php',
            'statistics.php',
            'events.php',
            'rbac_management.php',
            'hr.php',
            'employees.php',
            'departments.php',
            'attendance.php',
            'schedules.php',
            'leave_requests.php',
            'leave_balance.php',
            'payroll.php',
            'deductions.php',
            'payslip_generation.php',
            'ajax_payroll.php',
            'get_payroll_details.php',
            'get_payroll_details_for_approval.php',
            'view_payslip.php',
            'send_payslip.php',
            'performance.php',
            'recruitment.php',
            'candidates.php',
            'turnover.php',
            'hr_reports.php',
            'get_employee_details.php',
            'get_leave_details.php',
            'get_performance_details.php',
            'get_po_details.php',
            'get_products_for_kiosk.php',
            'create_walkin_order.php',
            'logout.php',
            'get_notifications.php'
        ];
        if (!$is_super_admin_session_user && !in_array($current_page, $partner_allowed_pages, true)) {
            if (isAjaxRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'This module is not available for partner admin accounts.',
                    'redirect' => 'products.php'
                ]);
                exit;
            }

            $_SESSION['error'] = 'This module is not available for partner admin accounts.';
            header("Location: products.php");
            exit;
        }

        if (!$is_super_admin_session_user) {
            enforcePartnerRoleScopedPageAccess($conn, $user_id, $current_page);
            enforcePartnerTenantScopedRequestAccess($conn, $user_id, $current_page);
        }

        if (!$is_super_admin_session_user && $account_control_status === 'restricted') {
            $restricted_pages = [
                'index.php',
                'orders.php',
                'get_order_details.php',
                'cancellations.php',
                'reviews.php',
                'finance.php',
                'partner_billing.php',
                'statistics.php',
                'logout.php',
                'get_notifications.php'
            ];
            $request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $is_safe_read_request = in_array($request_method, ['GET', 'HEAD'], true);

            if (!in_array($current_page, $restricted_pages, true) || !$is_safe_read_request) {
                $restricted_message = function_exists('getUserAccountControlMessage')
                    ? getUserAccountControlMessage($account_control_state)
                    : 'Your partner account is currently under restricted access.';

                if (isAjaxRequest()) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => $restricted_message,
                        'redirect' => 'index.php'
                    ]);
                    exit;
                }

                $_SESSION['error'] = $restricted_message;
                header("Location: index.php");
                exit;
            }
        }
    }

    $is_legacy_admin = isset($_SESSION['user_type']) && strtolower((string)$_SESSION['user_type']) === 'admin';
    $has_assigned_role = isset($_SESSION['role_id']) && intval($_SESSION['role_id']) > 0;
    $is_business_owner_role = strtolower(trim((string)($_SESSION['role_name'] ?? ''))) === 'business_owner';
    $rbac_sync_interval = 300; // seconds
    $last_synced = isset($_SESSION['rbac_synced_at']) ? intval($_SESSION['rbac_synced_at']) : 0;
    $must_refresh_rbac = !isset($_SESSION['permissions'], $_SESSION['role_name'], $_SESSION['has_backoffice_access']) ||
        $is_business_owner_role ||
        $last_synced <= 0 ||
        (time() - $last_synced) > $rbac_sync_interval;
    $has_rbac_backoffice_access = false;

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] && $must_refresh_rbac) {
        $has_rbac_backoffice_access = hasBackofficeAccess($GLOBALS['conn'], $user_id);
        $_SESSION['has_backoffice_access'] = $has_rbac_backoffice_access;

        $current_role = getUserRole($GLOBALS['conn'], $user_id);
        $_SESSION['role_name'] = $current_role['name'] ?? null;
        $_SESSION['permissions'] = getUserPermissions($GLOBALS['conn'], $user_id);
        $_SESSION['rbac_synced_at'] = time();
    } else {
        $has_rbac_backoffice_access = isset($_SESSION['has_backoffice_access']) ? (bool)$_SESSION['has_backoffice_access'] : false;
    }

    // Safe fallback if DB is temporarily unavailable but session has known RBAC context.
    if (!$has_rbac_backoffice_access) {
        $role_name = $_SESSION['role_name'] ?? '';
        if ($role_name === 'super_admin' || sessionHasBackofficePermissionHint()) {
            $has_rbac_backoffice_access = true;
            $_SESSION['has_backoffice_access'] = true;
        }
    }

    // If role is assigned, RBAC decides access strictly.
    if ($has_assigned_role) {
        // Guard against stale permission cache for role-based users.
        if (empty(getSessionPermissions()) && ($_SESSION['role_name'] ?? '') !== 'super_admin') {
            denyAdminAccess('Session permissions are outdated. Please log out and log in again.');
        }

        if (!$has_rbac_backoffice_access) {
            denyAdminBackofficeEntry('Access denied: Your account does not have backoffice access.');
        }
        // Safety net: enforce module guard for pages that still do not declare requirePermission().
        enforceCurrentPageModuleAccess();
        return;
    }

    // Legacy fallback only for old admin accounts without role assignment.
    if (!$is_legacy_admin && !$has_rbac_backoffice_access) {
        denyAdminBackofficeEntry('Access denied: Your account is not authorized for the admin portal.');
    }

    // Safety net for legacy accounts/pages without explicit permission checks.
    enforceCurrentPageModuleAccess();
}

/**
 * Fetches detailed information about the currently logged-in admin user.
 * @param mysqli $conn The database connection object.
 * @return array|null An associative array of user info or null if not found.
 */

function getAdminInfo($conn) {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $query = "SELECT u.id, u.email, u.full_name, u.user_type, r.name as role_name, r.id as role_id 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.id
              WHERE u.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    
    if (!$stmt) {
        error_log("Admin Auth Error: " . mysqli_error($conn));
        return null;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $data;
}

/**
 * Checks if the current user has a specific permission. If not, denies access.
 * This is the recommended way to protect pages and actions.
 *
 * Example Usage:
 * At the top of `orders.php`: requirePermission('orders.view');
 * Inside a POST block for creating an order: requirePermission('orders.create');
 *
 * @param string $permission_name The permission string to check (e.g., 'orders.view').
 * @return void
 */
function requirePermission($permission_name) {
    checkAdminAccess();

    $user_id = intval($_SESSION['user_id'] ?? 0);
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn']) ? $GLOBALS['conn'] : null;

    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'super_admin') {
        return; // Super admin has all permissions.
    }
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'business_owner') {
        return; // Business owner has full admin dashboard access.
    }

    if (!is_string($permission_name) || $permission_name === '') {
        denyAdminAccess('Access Denied: Invalid permission requirement.');
    }

    $session_permissions = getSessionPermissions();
    if (in_array($permission_name, $session_permissions, true)) {
        return;
    }

    // Session permission cache can be stale for a few minutes.
    // Re-check against DB before denying to avoid false 403s on AJAX actions.
    if ($conn && $user_id > 0 && function_exists('hasPermission') && hasPermission($conn, $user_id, $permission_name)) {
        if (function_exists('getUserPermissions')) {
            $_SESSION['permissions'] = getUserPermissions($conn, $user_id);
        }
        $_SESSION['rbac_synced_at'] = time();
        return;
    }

    denyAdminAccess('Access Denied: You do not have permission to perform this action.');
}

/**
 * Allow access if user has at least one permission from a list.
 * @param array $permission_names
 * @return void
 */
function requireAnyPermission(array $permission_names) {
    checkAdminAccess();

    $user_id = intval($_SESSION['user_id'] ?? 0);
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn']) ? $GLOBALS['conn'] : null;

    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'super_admin') {
        return;
    }
    if (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'business_owner') {
        return;
    }

    $session_permissions = getSessionPermissions();
    foreach ($permission_names as $permission_name) {
        if (is_string($permission_name) && in_array($permission_name, $session_permissions, true)) {
            return;
        }
    }

    // Re-check with DB to avoid false denials from stale session cache.
    if ($conn && $user_id > 0 && function_exists('hasPermission')) {
        foreach ($permission_names as $permission_name) {
            if (!is_string($permission_name) || $permission_name === '') {
                continue;
            }
            if (hasPermission($conn, $user_id, $permission_name)) {
                if (function_exists('getUserPermissions')) {
                    $_SESSION['permissions'] = getUserPermissions($conn, $user_id);
                }
                $_SESSION['rbac_synced_at'] = time();
                return;
            }
        }
    }

    denyAdminAccess('Access Denied: You do not have permission to perform this action.');
}

function logoutAdmin() {
    ensureAdminSessionStarted();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
    header("Location: ../index.php");
    exit;
}
?>
