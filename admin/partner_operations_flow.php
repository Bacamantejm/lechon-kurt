<?php
if (!function_exists('partnerOpsTableExists')) {
    function partnerOpsTableExists($conn, $table_name) {
        static $cache = [];
        $key = (string)$table_name;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
        );
        if (!$stmt) {
            return $cache[$key] = false;
        }
        mysqli_stmt_bind_param($stmt, "s", $table_name);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = $res && mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);
        return $cache[$key] = $ok;
    }
}

if (!function_exists('partnerOpsColumnExists')) {
    function partnerOpsColumnExists($conn, $table_name, $column_name) {
        static $cache = [];
        $key = (string)$table_name . '.' . (string)$column_name;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1"
        );
        if (!$stmt) {
            return $cache[$key] = false;
        }
        mysqli_stmt_bind_param($stmt, "ss", $table_name, $column_name);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = $res && mysqli_num_rows($res) > 0;
        mysqli_stmt_close($stmt);
        return $cache[$key] = $ok;
    }
}

if (!function_exists('partnerOpsCountQuery')) {
    function partnerOpsCountQuery($conn, $sql) {
        $res = mysqli_query($conn, $sql);
        if (!$res) {
            return 0;
        }
        $row = mysqli_fetch_assoc($res);
        return (int)($row['count'] ?? 0);
    }
}

if (!function_exists('partnerOpsOrderScopeExistsSql')) {
    function partnerOpsOrderScopeExistsSql($seller_scope_id, $order_id_expr = 'o.id') {
        $seller_scope_id = (int)$seller_scope_id;
        if ($seller_scope_id <= 0) {
            return '1=0';
        }
        return "EXISTS (
            SELECT 1
            FROM order_items oi_scope
            INNER JOIN products p_scope
                ON (oi_scope.product_id COLLATE utf8mb4_general_ci = p_scope.product_id COLLATE utf8mb4_general_ci OR oi_scope.product_id COLLATE utf8mb4_general_ci = CAST(p_scope.id AS CHAR) COLLATE utf8mb4_general_ci)
            WHERE oi_scope.order_id = {$order_id_expr}
              AND p_scope.seller_id = {$seller_scope_id}
        )";
    }
}

if (!function_exists('partnerOpsScopedUserIds')) {
    function partnerOpsScopedUserIds($conn, $seller_scope_id) {
        $seller_scope_id = (int)$seller_scope_id;
        if ($seller_scope_id <= 0) {
            return [];
        }

        $ids = [$seller_scope_id => true];
        if (partnerOpsTableExists($conn, 'partner_user_links')
            && partnerOpsColumnExists($conn, 'partner_user_links', 'owner_user_id')
            && partnerOpsColumnExists($conn, 'partner_user_links', 'managed_user_id')) {
            $stmt = mysqli_prepare($conn, "SELECT managed_user_id FROM partner_user_links WHERE owner_user_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $seller_scope_id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                while ($res && ($row = mysqli_fetch_assoc($res))) {
                    $managed_user_id = (int)($row['managed_user_id'] ?? 0);
                    if ($managed_user_id > 0) {
                        $ids[$managed_user_id] = true;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        return array_keys($ids);
    }
}

if (!function_exists('partnerOpsGetSummary')) {
    function partnerOpsGetSummary($conn, $seller_scope_id) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }

        $seller_scope_id = (int)$seller_scope_id;
        if ($seller_scope_id <= 0) {
            return null;
        }

        $cache_key = 'partner_ops_flow_' . $seller_scope_id;
        $cache_ttl_seconds = 45;
        $now = time();
        if (isset($_SESSION[$cache_key]['ts'], $_SESSION[$cache_key]['data'])) {
            $age = $now - (int)$_SESSION[$cache_key]['ts'];
            if ($age >= 0 && $age <= $cache_ttl_seconds) {
                return $_SESSION[$cache_key]['data'];
            }
        }

        $counts = [
            'pending_orders' => 0,
            'cancellation_requests' => 0,
            'pending_preorders' => 0,
            'active_deliveries' => 0,
            'pending_refunds' => 0,
            'low_stock_items' => 0,
            'pending_expenses' => 0,
            'pending_attendance' => 0,
            'pending_leaves' => 0,
            'pending_payroll' => 0
        ];

        $order_scope_sql = partnerOpsOrderScopeExistsSql($seller_scope_id, 'o.id');

        if (partnerOpsTableExists($conn, 'orders')
            && partnerOpsColumnExists($conn, 'orders', 'status')
            && partnerOpsColumnExists($conn, 'orders', 'is_archived')) {
            $counts['pending_orders'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM orders o
                 WHERE o.is_archived = 0
                   AND o.status = 'pending'
                   AND {$order_scope_sql}"
            );
            $counts['cancellation_requests'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM orders o
                 WHERE o.is_archived = 0
                   AND o.status = 'cancellation_requested'
                   AND {$order_scope_sql}"
            );
        }

        if (partnerOpsTableExists($conn, 'pre_orders')
            && partnerOpsColumnExists($conn, 'pre_orders', 'reservation_status')
            && partnerOpsTableExists($conn, 'products')
            && partnerOpsColumnExists($conn, 'products', 'seller_id')) {
            $counts['pending_preorders'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM pre_orders po
                 INNER JOIN products p ON p.id = po.product_id
                 WHERE po.reservation_status = 'pending'
                   AND p.seller_id = {$seller_scope_id}"
            );
        }

        if (partnerOpsTableExists($conn, 'logistics_tracking')
            && partnerOpsColumnExists($conn, 'logistics_tracking', 'current_status')
            && partnerOpsColumnExists($conn, 'logistics_tracking', 'order_id')) {
            $logistics_scope = partnerOpsOrderScopeExistsSql($seller_scope_id, 'lt.order_id');
            $counts['active_deliveries'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM logistics_tracking lt
                 WHERE lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
                   AND {$logistics_scope}"
            );
        }

        if (partnerOpsTableExists($conn, 'refunds')
            && partnerOpsTableExists($conn, 'cancellations')
            && partnerOpsTableExists($conn, 'orders')
            && partnerOpsColumnExists($conn, 'refunds', 'refund_status')
            && partnerOpsColumnExists($conn, 'refunds', 'cancellation_id')
            && partnerOpsColumnExists($conn, 'cancellations', 'order_id')) {
            $counts['pending_refunds'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM refunds r
                 INNER JOIN cancellations c ON c.id = r.cancellation_id
                 INNER JOIN orders o ON o.id = c.order_id
                 WHERE r.refund_status IN ('Refund Pending', 'pending', 'requested')
                   AND {$order_scope_sql}"
            );
        }

        if (partnerOpsTableExists($conn, 'inventory')
            && partnerOpsTableExists($conn, 'products')
            && partnerOpsColumnExists($conn, 'inventory', 'product_id')
            && partnerOpsColumnExists($conn, 'inventory', 'current_stock')
            && partnerOpsColumnExists($conn, 'inventory', 'min_stock_level')
            && partnerOpsColumnExists($conn, 'products', 'id')
            && partnerOpsColumnExists($conn, 'products', 'seller_id')) {
            $date_filter = '';
            if (partnerOpsColumnExists($conn, 'inventory', 'inventory_date')) {
                $date_filter = " AND i.inventory_date = CURDATE()";
            }
            $archive_filter = partnerOpsColumnExists($conn, 'inventory', 'is_archived') ? " AND i.is_archived = 0" : '';
            $product_archive_filter = partnerOpsColumnExists($conn, 'products', 'is_archived') ? " AND p.is_archived = 0" : '';
            $counts['low_stock_items'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM inventory i
                 INNER JOIN products p ON p.id = i.product_id
                 WHERE p.seller_id = {$seller_scope_id}
                   AND i.current_stock <= i.min_stock_level
                   {$date_filter}
                   {$archive_filter}
                   {$product_archive_filter}"
            );
        }

        if (partnerOpsTableExists($conn, 'expenses')
            && partnerOpsColumnExists($conn, 'expenses', 'recorded_by')
            && partnerOpsColumnExists($conn, 'expenses', 'status')) {
            $counts['pending_expenses'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM expenses
                 WHERE recorded_by = {$seller_scope_id}
                   AND status IN ('pending', 'for_approval', 'for approval')"
            );
        }

        $scope_user_ids = partnerOpsScopedUserIds($conn, $seller_scope_id);
        $scope_user_csv = implode(',', array_map('intval', $scope_user_ids));
        $employee_scope_sql = $scope_user_csv !== '' ? "e.user_id IN ({$scope_user_csv})" : "1=0";

        if (partnerOpsTableExists($conn, 'attendance')
            && partnerOpsTableExists($conn, 'employees')
            && partnerOpsColumnExists($conn, 'attendance', 'employee_id')
            && partnerOpsColumnExists($conn, 'attendance', 'hr_status')
            && partnerOpsColumnExists($conn, 'employees', 'user_id')) {
            $counts['pending_attendance'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM attendance a
                 INNER JOIN employees e ON e.id = a.employee_id
                 WHERE a.hr_status = 'pending'
                   AND {$employee_scope_sql}"
            );
        }

        if (partnerOpsTableExists($conn, 'leave_requests')
            && partnerOpsTableExists($conn, 'employees')
            && partnerOpsColumnExists($conn, 'leave_requests', 'employee_id')
            && partnerOpsColumnExists($conn, 'leave_requests', 'status')
            && partnerOpsColumnExists($conn, 'employees', 'user_id')) {
            $counts['pending_leaves'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM leave_requests lr
                 INNER JOIN employees e ON e.id = lr.employee_id
                 WHERE lr.status = 'pending'
                   AND {$employee_scope_sql}"
            );
        }

        if (partnerOpsTableExists($conn, 'payroll')
            && partnerOpsTableExists($conn, 'employees')
            && partnerOpsColumnExists($conn, 'payroll', 'employee_id')
            && partnerOpsColumnExists($conn, 'payroll', 'status')
            && partnerOpsColumnExists($conn, 'employees', 'user_id')) {
            $counts['pending_payroll'] = partnerOpsCountQuery(
                $conn,
                "SELECT COUNT(*) AS count
                 FROM payroll p
                 INNER JOIN employees e ON e.id = p.employee_id
                 WHERE p.status = 'pending'
                   AND {$employee_scope_sql}"
            );
        }

        $steps = [];
        if ($counts['pending_orders'] > 0) {
            $steps[] = ['label' => 'Confirm pending orders', 'url' => 'orders.php?status=pending'];
        }
        if ($counts['active_deliveries'] > 0) {
            $steps[] = ['label' => 'Monitor active deliveries', 'url' => 'logistics.php'];
        }
        if ($counts['pending_refunds'] > 0 || $counts['cancellation_requests'] > 0) {
            $steps[] = ['label' => 'Resolve refunds and cancellations', 'url' => 'finance.php?tab=refunds'];
        }
        if ($counts['low_stock_items'] > 0) {
            $steps[] = ['label' => 'Replenish low-stock items', 'url' => 'inventory.php'];
        }
        if ($counts['pending_attendance'] > 0 || $counts['pending_leaves'] > 0 || $counts['pending_payroll'] > 0) {
            $steps[] = ['label' => 'Complete HR and payroll queue', 'url' => 'hr.php'];
        }
        if (empty($steps)) {
            $steps[] = ['label' => 'Review today\'s operations health', 'url' => 'index.php'];
        }

        $modules = [
            ['label' => 'Orders', 'url' => 'orders.php?status=pending', 'count' => $counts['pending_orders']],
            ['label' => 'Pre-Orders', 'url' => 'preorders.php?status=pending', 'count' => $counts['pending_preorders']],
            ['label' => 'Logistics', 'url' => 'logistics.php', 'count' => $counts['active_deliveries']],
            ['label' => 'Refunds', 'url' => 'finance.php?tab=refunds', 'count' => ($counts['pending_refunds'] + $counts['cancellation_requests'])],
            ['label' => 'Inventory', 'url' => 'inventory.php', 'count' => $counts['low_stock_items']],
            ['label' => 'HR', 'url' => 'hr.php', 'count' => ($counts['pending_attendance'] + $counts['pending_leaves'])],
            ['label' => 'Payroll', 'url' => 'payroll.php', 'count' => $counts['pending_payroll']]
        ];

        $data = [
            'counts' => $counts,
            'modules' => $modules,
            'steps' => array_slice($steps, 0, 3),
            'updated_at' => date('H:i')
        ];

        $_SESSION[$cache_key] = ['ts' => $now, 'data' => $data];
        return $data;
    }
}
