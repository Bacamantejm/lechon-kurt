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

$partner_scope_sql_outer = '';
// Outer scope condition ensures orders belong to this shop/partner
if ($seller_scope_id !== null) {
    $partner_scope_sql_outer = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'lt.order_id');
    $partner_driver_scope_sql_outer = '';
}

// Fetch active delivery orders with driver tracking coordinates
// Uses COALESCE to prioritize active logistics_tracking coordinates, falling back to rider/employee real-time geo-tracking
$query = "
    SELECT 
        lt.id AS tracking_id,
        lt.driver_id,
        COALESCE(NULLIF(TRIM(lt.driver_name), ''), NULLIF(TRIM(r.rider_name), ''), CONCAT(e.first_name, ' ', e.last_name), 'Driver') AS driver_name,
        COALESCE(NULLIF(TRIM(lt.driver_phone), ''), r.user_phone, e.phone, '') AS driver_phone,
        COALESCE(NULLIF(TRIM(lt.driver_vehicle), ''), r.vehicle_type, e.vehicle_details, 'Motorcycle') AS driver_vehicle,
        COALESCE(lt.current_latitude, r.current_latitude, egt.current_latitude) AS current_latitude,
        COALESCE(lt.current_longitude, r.current_longitude, egt.current_longitude) AS current_longitude,
        COALESCE(lt.last_location_update, r.last_location_update, egt.last_update, lt.updated_at, lt.created_at) AS last_location_update,
        lt.current_status,
        lt.estimated_delivery,
        o.id AS order_id,
        o.order_number,
        COALESCE(NULLIF(TRIM(o.customer_name), ''), u.full_name, 'Customer') AS customer_name,
        COALESCE(NULLIF(TRIM(o.customer_phone), ''), u.phone, '') AS customer_phone,
        o.delivery_address,
        o.latitude AS customer_latitude,
        o.longitude AS customer_longitude,
        o.delivery_date,
        o.delivery_time,
        o.special_instructions
    FROM logistics_tracking lt
    JOIN orders o ON lt.order_id = o.id AND o.delivery_option = 'delivery'
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN riders r ON r.id = lt.driver_id
    LEFT JOIN employees e ON e.id = lt.driver_id
    LEFT JOIN employees_geo_tracking egt ON egt.employee_id = lt.driver_id
    WHERE lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
      AND (lt.current_latitude IS NOT NULL OR r.current_latitude IS NOT NULL OR egt.current_latitude IS NOT NULL)
      {$partner_scope_sql_outer}
      {$partner_driver_scope_sql_outer}
    ORDER BY COALESCE(lt.last_location_update, r.last_location_update, egt.last_update, lt.updated_at) DESC
";

$result = mysqli_query($conn, $query);
if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load driver locations: ' . mysqli_error($conn),
        'locations' => []
    ]);
    mysqli_close($conn);
    exit;
}

$locations = [];
$seen_drivers = [];

while ($row = mysqli_fetch_assoc($result)) {
    // If a driver has multiple deliveries, send them all so the map can plot the driver and each customer destination
    $locations[] = [
        'tracking_id' => (int)$row['tracking_id'],
        'driver_id' => (int)$row['driver_id'],
        'driver_name' => $row['driver_name'],
        'driver_phone' => $row['driver_phone'],
        'driver_vehicle' => $row['driver_vehicle'],
        'current_latitude' => $row['current_latitude'] !== null ? (float)$row['current_latitude'] : null,
        'current_longitude' => $row['current_longitude'] !== null ? (float)$row['current_longitude'] : null,
        'last_location_update' => $row['last_location_update'],
        'current_status' => $row['current_status'],
        'estimated_delivery' => $row['estimated_delivery'],
        'order_id' => (int)$row['order_id'],
        'order_number' => $row['order_number'],
        'customer_name' => $row['customer_name'],
        'customer_phone' => $row['customer_phone'],
        'delivery_address' => $row['delivery_address'],
        'customer_latitude' => $row['customer_latitude'] !== null ? (float)$row['customer_latitude'] : null,
        'customer_longitude' => $row['customer_longitude'] !== null ? (float)$row['customer_longitude'] : null,
        'delivery_date' => $row['delivery_date'],
        'delivery_time' => $row['delivery_time'],
        'special_instructions' => $row['special_instructions']
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($locations),
    'locations' => $locations
]);
mysqli_close($conn);
