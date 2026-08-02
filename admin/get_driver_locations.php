<?php
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
checkAdminAccess();
require_once 'hr_module_common.php';
requirePermission('logistics.view');

header('Content-Type: application/json');
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

// This query gets the single most recent location for each active driver.
// It avoids plotting multiple old locations for the same driver.
$partner_scope_sql_latest = '';
$partner_scope_sql_outer = '';
$partner_driver_scope_sql_latest = '';
$partner_driver_scope_sql_outer = '';
if ($seller_scope_id !== null) {
    $partner_scope_sql_latest = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt_scope.order_id');
    $partner_scope_sql_outer = " WHERE " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id');

    $driver_scope_latest = hrEmployeeScopeSql($conn, 'e_scope', 'user_id');
    $driver_scope_outer = hrEmployeeScopeSql($conn, 'e_outer', 'user_id');
    $partner_driver_scope_sql_latest = "
        AND EXISTS (
            SELECT 1
            FROM employees e_scope
            WHERE e_scope.id = lt_scope.driver_id
              AND {$driver_scope_latest}
        )";
    $partner_driver_scope_sql_outer = "
        AND EXISTS (
            SELECT 1
            FROM employees e_outer
            WHERE e_outer.id = lt.driver_id
              AND {$driver_scope_outer}
        )";
}

$query = "
    SELECT 
        lt.driver_id,
        lt.driver_name,
        lt.current_latitude,
        lt.current_longitude,
        lt.current_status,
        lt.estimated_delivery,
        lt.last_location_update,
        o.order_number,
        o.delivery_address,
        o.latitude AS customer_latitude,
        o.longitude AS customer_longitude,
        o.delivery_date,
        o.delivery_time
    FROM logistics_tracking lt
    INNER JOIN (
        SELECT driver_id, MAX(last_location_update) as max_update
        FROM logistics_tracking lt_scope
        WHERE lt_scope.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
        AND lt_scope.driver_id IS NOT NULL AND lt_scope.current_latitude IS NOT NULL AND lt_scope.current_longitude IS NOT NULL
        {$partner_scope_sql_latest}
        {$partner_driver_scope_sql_latest}
        GROUP BY driver_id
    ) as latest ON lt.driver_id = latest.driver_id AND lt.last_location_update = latest.max_update
    JOIN orders o ON lt.order_id = o.id
    {$partner_scope_sql_outer}{$partner_driver_scope_sql_outer}
";

$result = mysqli_query($conn, $query);
if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load driver locations right now.'
    ]);
    mysqli_close($conn);
    exit;
}
$locations = mysqli_fetch_all($result, MYSQLI_ASSOC);

echo json_encode(['success' => true, 'locations' => $locations]);
mysqli_close($conn);
