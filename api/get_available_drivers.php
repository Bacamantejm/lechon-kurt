<?php
/**
 * API Endpoint: Get Available Drivers
 */
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
require_once '../admin/hr_module_common.php';

checkAdminAccess();
requirePermission('logistics.view');

header('Content-Type: application/json');

$order_id = intval($_GET['order_id'] ?? 0);
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$is_partner_scoped_admin = isApprovedFranchiseSellerAccount($conn, $current_user_id);
$seller_scope_id = $is_partner_scoped_admin ? getFranchiseSellerScopeOwnerId($conn, $current_user_id) : null;

if (!$order_id) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Order ID required']));
}

try {
    // Get order details (tenant-scoped for partner admins)
    $partner_order_scope_sql = '';
    if ($seller_scope_id !== null) {
        $partner_order_scope_sql = " AND " . getFranchiseScopedOrderExistsSql($conn, (int)$seller_scope_id, 'o.id');
    }

    $order_has_customer_coordinates = function_exists('adminAuthColumnExists')
        ? adminAuthColumnExists($conn, 'orders', 'customer_coordinates')
        : false;

    $order_query = "SELECT o.delivery_address, o.delivery_date, o.latitude, o.longitude"
        . ($order_has_customer_coordinates ? ", o.customer_coordinates" : "")
        . " FROM orders o WHERE o.id = ? {$partner_order_scope_sql} LIMIT 1";
    $order_stmt = mysqli_prepare($conn, $order_query);
    mysqli_stmt_bind_param($order_stmt, "i", $order_id);
    mysqli_stmt_execute($order_stmt);
    $order_result = mysqli_stmt_get_result($order_stmt);
    $order = mysqli_fetch_assoc($order_result);
    mysqli_stmt_close($order_stmt);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $assignment_date = date('Y-m-d');
    if (!empty($order['delivery_date'])) {
        $delivery_date = substr((string)$order['delivery_date'], 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            $assignment_date = $delivery_date;
        }
    }
    
    $latitude = null;
    $longitude = null;
    
    if (!empty($order['customer_coordinates'] ?? null)) {
        $coords = json_decode($order['customer_coordinates'], true);
        if ($coords && isset($coords['latitude'], $coords['longitude'])) {
            $latitude = $coords['latitude'];
            $longitude = $coords['longitude'];
        }
    }

    if (($latitude === null || $longitude === null)
        && isset($order['latitude'], $order['longitude'])
        && is_numeric($order['latitude']) && is_numeric($order['longitude'])) {
        $latitude = (float)$order['latitude'];
        $longitude = (float)$order['longitude'];
    }

    $driver_scope_sql = function_exists('hrEmployeeScopeSql')
        ? hrEmployeeScopeSql($conn, 'e', 'user_id')
        : '1=1';
    $driver_role_sql = function_exists('hrLogisticsEmployeeSqlCondition')
        ? hrLogisticsEmployeeSqlCondition('e', 'd', $conn)
        : "1=0";
    
    // Get available drivers
    $query = "SELECT 
        e.id,
        e.first_name,
        e.last_name,
        e.phone,
        dav.current_deliveries_count,
        dav.max_deliveries_per_day,
        dds.avg_rating,
        dds.success_rate,
        gt.current_latitude,
        gt.current_longitude";
    
    if ($latitude && $longitude) {
        $query .= ", (6371 * acos(cos(radians($latitude)) * cos(radians(gt.current_latitude)) * 
                 cos(radians(gt.current_longitude) - radians($longitude)) + 
                 sin(radians($latitude)) * sin(radians(gt.current_latitude)))) AS distance_km";
    } else {
        $query .= ", 0 AS distance_km";
    }
    
    $query .= " FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN driver_availability dav ON e.id = dav.driver_id AND dav.date = ?
        LEFT JOIN driver_delivery_stats dds ON e.id = dds.driver_id
        LEFT JOIN employees_geo_tracking gt ON e.id = gt.employee_id
        LEFT JOIN attendance att ON e.id = att.employee_id
            AND att.attendance_date = ?
            AND att.status IN ('present', 'late', 'half_day')
            AND (att.hr_status IS NULL OR att.hr_status <> 'rejected')
        WHERE {$driver_role_sql}
        AND e.status = 'active'
        AND {$driver_scope_sql}
        AND att.id IS NOT NULL
        AND (dav.current_deliveries_count < dav.max_deliveries_per_day OR dav.id IS NULL)
        AND e.id NOT IN (
            SELECT lt.driver_id
            FROM logistics_tracking lt
            INNER JOIN orders o ON o.id = lt.order_id
            WHERE lt.driver_id IS NOT NULL
            AND lt.current_status IN ('assigned', 'picked_up', 'on_the_way', 'arriving')
            AND DATE(o.delivery_date) = ?
        )
        ORDER BY distance_km ASC, dds.avg_rating DESC";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception(mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "sss", $assignment_date, $assignment_date, $assignment_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }
    
    $drivers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $drivers[] = [
            'id' => (int)$row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'phone' => $row['phone'],
            'current_deliveries_count' => (int)($row['current_deliveries_count'] ?? 0),
            'max_deliveries_per_day' => (int)($row['max_deliveries_per_day'] ?? 10),
            'avg_rating' => floatval($row['avg_rating'] ?? 5.0),
            'success_rate' => floatval($row['success_rate'] ?? 100.0),
            'distance_km' => floatval($row['distance_km'] ?? 0)
        ];
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode($drivers);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
