<?php
/**
 * API Endpoint: Get Drivers List
 */
session_start();
require_once '../includes/config.php';
require_once '../admin/auth.php';
require_once '../admin/hr_module_common.php';

checkAdminAccess();

header('Content-Type: application/json');

try {
    $driver_role_sql = function_exists('hrLogisticsEmployeeSqlCondition')
        ? hrLogisticsEmployeeSqlCondition('e', 'd', $conn)
        : "1=0";

    $query = "SELECT 
        e.id,
        e.first_name,
        e.last_name,
        dav.is_available,
        dav.current_deliveries_count,
        dav.max_deliveries_per_day,
        dds.avg_rating,
        dds.success_rate
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN driver_availability dav ON e.id = dav.driver_id AND dav.date = CURDATE()
    LEFT JOIN driver_delivery_stats dds ON e.id = dds.driver_id
    WHERE {$driver_role_sql}
    AND e.status = 'active'
    ORDER BY COALESCE(dav.is_available, 0) DESC, dds.avg_rating DESC";
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }
    
    $drivers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $drivers[] = [
            'id' => (int)$row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'is_available' => (bool)($row['is_available'] ?? true),
            'current_deliveries_count' => (int)($row['current_deliveries_count'] ?? 0),
            'max_deliveries_per_day' => (int)($row['max_deliveries_per_day'] ?? 10),
            'avg_rating' => floatval($row['avg_rating'] ?? 5.0),
            'success_rate' => floatval($row['success_rate'] ?? 100.0)
        ];
    }
    
    echo json_encode($drivers);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
