<?php
session_start();
require_once '../includes/config.php';
require_once 'auth.php';
checkAdminAccess();
require_once 'hr_module_common.php';

header('Content-Type: application/json');
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;
$driver_scope_sql = hrEmployeeScopeSql($conn, 'e', 'user_id');
$driver_role_sql = hrLogisticsEmployeeSqlCondition('e', 'd', $conn);
$partner_order_scope_sql = '';
if ($seller_scope_id !== null) {
    $partner_order_scope_sql = "
    AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
}

// On-Delivery Drivers
$on_delivery_drivers_query = "
    SELECT 
        e.id as employee_id,
        e.first_name,
        e.last_name,
        lt.current_status,
        o.order_number
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    JOIN logistics_tracking lt ON e.id = lt.driver_id
    JOIN orders o ON lt.order_id = o.id
    WHERE {$driver_role_sql}
    AND {$driver_scope_sql}
    AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
    {$partner_order_scope_sql}
    ORDER BY e.first_name, lt.created_at DESC;
";
$on_delivery_result = mysqli_query($conn, $on_delivery_drivers_query);
$on_delivery_drivers = [];
if ($on_delivery_result) {
    while ($row = mysqli_fetch_assoc($on_delivery_result)) {
        $on_delivery_drivers[] = $row;
    }
}

// Available Drivers
$available_drivers_query = "
    SELECT e.id, e.first_name, e.last_name
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status = 'active' 
    AND {$driver_role_sql}
    AND {$driver_scope_sql}
    AND e.id NOT IN (
        SELECT DISTINCT lt.driver_id 
        FROM logistics_tracking lt 
        WHERE lt.driver_id IS NOT NULL 
        AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
    )
    ORDER BY e.first_name ASC;
";
$available_drivers_result = mysqli_query($conn, $available_drivers_query);
$available_drivers = [];
if ($available_drivers_result) {
    while ($row = mysqli_fetch_assoc($available_drivers_result)) {
        $available_drivers[] = $row;
    }
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'on_delivery' => $on_delivery_drivers,
    'available' => $available_drivers
]);
?>
